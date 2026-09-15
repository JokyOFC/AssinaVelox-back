<?php

namespace App\Services\Envelopes\Steps;

use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use Illuminate\Support\Collection;

/**
 * Os FATOS que uma condição pode ler, lidos do banco no instante da avaliação (sob o lock do
 * envelope): a decisão de cada aprovador referenciado e o valor gravado de cada campo
 * referenciado. Nada vem do navegador.
 *
 * Decisão de aprovador que DELEGOU: vale a decisão do delegado (a cadeia `delegated_from` é
 * seguida até quem de fato decidiu). O aceite do delegado é dele; a condição olha o que foi
 * decidido naquela posição do fluxo.
 */
final class EvaluationFacts
{
    /**
     * @param  array<string, string|null>  $decisions  ULID referenciado => approved | refused | null
     * @param  array<string, array{type: string, text: string|null, bool: bool|null, label: string|null}>  $fields
     * @param  array<string, string>  $names  ULID referenciado => nome de quem decidiu (para a evidência)
     */
    public function __construct(
        private readonly array $decisions,
        private readonly array $fields,
        private readonly array $names = [],
    ) {}

    public static function for(Envelope $envelope, StepCondition $condition): self
    {
        /** @var Collection<int, Recipient> $recipients */
        $recipients = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->get();

        $byUlid = $recipients->keyBy('ulid');
        $byDelegatedFrom = $recipients
            ->filter(fn (Recipient $recipient): bool => $recipient->getAttribute('delegated_from_recipient_id') !== null)
            ->keyBy(fn (Recipient $recipient): int => (int) $recipient->getAttribute('delegated_from_recipient_id'));

        $decisions = [];
        $names = [];

        foreach ($condition->recipientUlids() as $ulid) {
            $recipient = $byUlid->get($ulid);

            for ($hops = 0; $recipient instanceof Recipient && $recipient->status === RecipientStatus::Delegated && $hops < 10; $hops++) {
                $recipient = $byDelegatedFrom->get((int) $recipient->getKey());
            }

            $decisions[$ulid] = match (true) {
                ! $recipient instanceof Recipient => null,
                $recipient->status === RecipientStatus::Signed => StepCondition::DECISION_APPROVED,
                $recipient->status === RecipientStatus::Refused => StepCondition::DECISION_REFUSED,
                default => null,
            };

            if ($recipient instanceof Recipient) {
                $names[$ulid] = $recipient->name;
            }
        }

        $fieldUlids = $condition->fieldUlids();
        $fields = [];

        if ($fieldUlids !== []) {
            $models = SigningField::withoutOrganizationScope()
                ->where('envelope_id', $envelope->getKey())
                ->whereIn('ulid', $fieldUlids)
                ->get();

            $values = SigningFieldValue::withoutOrganizationScope()
                ->whereIn('signing_field_id', $models->modelKeys())
                ->get()
                ->keyBy(fn (SigningFieldValue $value): int => (int) $value->signing_field_id);

            foreach ($models as $field) {
                $value = $values->get((int) $field->getKey());

                $fields[$field->ulid] = [
                    'type' => $field->type->value,
                    'text' => $value?->value_text,
                    'bool' => $value?->value_bool === null ? null : (bool) $value->value_bool,
                    'label' => $field->label,
                ];
            }
        }

        return new self($decisions, $fields, $names);
    }

    public function decisionOf(string $recipientUlid): ?string
    {
        return $this->decisions[$recipientUlid] ?? null;
    }

    public function deciderName(string $recipientUlid): ?string
    {
        return $this->names[$recipientUlid] ?? null;
    }

    /**
     * @return array{type: string, text: string|null, bool: bool|null, label: string|null}|null
     */
    public function fieldOf(string $fieldUlid): ?array
    {
        return $this->fields[$fieldUlid] ?? null;
    }
}
