<?php

namespace App\Services\Envelopes\Steps;

use App\Enums\AuditEventType;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Models\SigningStep;
use App\Services\Signing\SignerAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Progressão das etapas condicionais (Fase 3 §3.3, F-FLOW — docs/fase-3/etapas-e-delegacao.md §2.4).
 *
 * Chamada DENTRO da transação que já travou o envelope (`SELECT ... FOR UPDATE`) em
 * App\Services\Signing\RecordAcceptance (depois de cada aceite) e App\Services\Signing\RecordRefusal
 * (recusa de aprovador cuja decisão alguma etapa lê). Nunca fora do lock.
 *
 * Quando a próxima vez cai numa etapa ainda não alcançada, a condição é avaliada com os fatos
 * do banco naquele instante:
 *
 * - verdadeira (ou sem condição): a etapa vira `active` e a trilha recebe `envelope.step_started`
 *   com a regra e os valores avaliados;
 * - falsa: a etapa vira `skipped`, os participantes dela ficam `canceled` com o motivo
 *   `step_skipped` (nunca receberam convite e não recebem aviso nenhum), a trilha recebe
 *   `envelope.step_skipped` — e a avaliação segue para a etapa seguinte.
 *
 * Idempotência: a mudança de estado da etapa é um UPDATE condicional (`status = pending`).
 * Duas execuções concorrentes — mesmo num banco em que o lock não serialize — produzem uma
 * única transição e um único evento; a perdedora relê o estado e não notifica ninguém.
 * O envelope conclui quando não resta participante pendente em etapa aplicável (a lógica de
 * conclusão continua a do RecordAcceptance).
 */
final class StepProgression
{
    public const REASON_STEP_SKIPPED = 'step_skipped';

    /** `envelopes.settings`: ULID do aprovador cuja recusa aguarda o fim da própria etapa. */
    public const SETTING_DEFERRED_REFUSAL = 'refusal_pending_close';

    public function __construct(private readonly ConditionEvaluator $evaluator) {}

    /**
     * @param  Collection<int, Recipient>  $recipients  destinatários do envelope, por (order_index, id)
     * @return Collection<int, Recipient> a mesma coleção, com os cancelados por etapa pulada já atualizados
     */
    public function prepare(Envelope $locked, Collection $recipients, string $correlationId): Collection
    {
        if (! $locked->usesSigningSteps()) {
            return $recipients;
        }

        $limit = FlowFeatures::maxSteps() * 2 + 2;

        for ($guard = 0; $guard < $limit; $guard++) {
            $pending = $recipients->filter(fn (Recipient $recipient): bool => $recipient->isPendingParticipant());

            if ($pending->isEmpty()) {
                break;
            }

            $stepIndex = (int) $pending->min(fn (Recipient $recipient): int => (int) ($recipient->getAttribute('signing_step_index') ?? 0));

            /** @var SigningStep|null $step */
            $step = SigningStep::withoutOrganizationScope()
                ->where('envelope_id', $locked->getKey())
                ->where('step_index', $stepIndex)
                ->first();

            // Etapa já alcançada (a vez anda dentro dela) ou participante sem etapa: nada a avaliar.
            if ($step === null || $step->status !== SigningStep::STATUS_PENDING) {
                break;
            }

            $evaluation = $this->evaluate($locked, $step);

            if ($evaluation['result']) {
                $this->activate($locked, $step, $evaluation, $correlationId);

                break;
            }

            if (! $this->skip($locked, $step, $evaluation, $recipients, $correlationId)) {
                // Outra execução decidiu esta etapa primeiro: relê o estado e segue sem notificar.
                $recipients = $this->reload($locked);
            }
        }

        return $recipients;
    }

    /**
     * Recusa de APROVADOR num envelope com etapas: se alguma etapa posterior lê a decisão dele,
     * o fluxo é recalculado sob o mesmo lock em vez de encerrar o envelope.
     *
     * @return array{close: bool, invite: list<Recipient>}|null null = política normal (encerrar)
     */
    public function afterRefusal(Envelope $locked, Recipient $refusedBy, string $correlationId): ?array
    {
        if (! $locked->usesSigningSteps()
            || $refusedBy->role !== RecipientRole::Approver
            || ! $this->decisionIsReferenced($locked, $refusedBy)) {
            return null;
        }

        $recipients = $this->reload($locked);
        $recipients = $this->prepare($locked, $recipients, $correlationId);

        $pending = $recipients->filter(fn (Recipient $recipient): bool => $recipient->isPendingParticipant());

        // Nenhuma etapa aplicável depois da recusa: a recusa encerra o envelope, como sempre.
        if ($pending->isEmpty()) {
            return ['close' => true, 'invite' => []];
        }

        // Ainda há alguém pendente na etapa de quem recusou (ou antes dela): as etapas que leem a
        // decisão só serão avaliadas quando essa etapa terminar. A recusa fica "em suspenso" e é
        // decidida no fim da etapa (deferredRefusal), para que o resultado não dependa da ordem
        // dos cliques dentro da etapa (revisão adversarial da onda F).
        $refuserStep = (int) ($refusedBy->getAttribute('signing_step_index') ?? 0);
        $pendingStep = (int) $pending->min(fn (Recipient $recipient): int => (int) ($recipient->getAttribute('signing_step_index') ?? 0));

        if ($pendingStep <= $refuserStep) {
            $locked->settings = array_replace($locked->settings ?? [], [self::SETTING_DEFERRED_REFUSAL => $refusedBy->ulid]);
            $locked->save();
        }

        $nextOrder = (int) $pending->min('order_index');

        if ($nextOrder <= (int) $locked->current_order) {
            return ['close' => false, 'invite' => []];
        }

        $locked->forceFill(['current_order' => $nextOrder])->save();

        /** @var list<Recipient> $invite */
        $invite = $pending
            ->filter(fn (Recipient $recipient): bool => (int) $recipient->order_index === $nextOrder && $recipient->status === RecipientStatus::Pending)
            ->values()
            ->all();

        return ['close' => false, 'invite' => $invite];
    }

    /**
     * Recusa de aprovador em suspenso (marcada por afterRefusal) — chamada sob o lock, logo
     * depois de prepare(), no fim de cada aceite (RecordAcceptance::advance).
     *
     * - não resta participante pendente: devolve quem recusou — a recusa encerra o envelope,
     *   exatamente como teria encerrado se tivesse sido a última decisão da etapa;
     * - a vez já passou para uma etapa POSTERIOR à de quem recusou (alguma etapa se aplicou):
     *   a marca sai e o fluxo segue;
     * - ainda há pendentes na etapa de quem recusou: nada muda.
     *
     * @param  Collection<int, Recipient>  $recipients
     */
    public function deferredRefusal(Envelope $locked, Collection $recipients): ?Recipient
    {
        $ulid = $locked->setting(self::SETTING_DEFERRED_REFUSAL);

        if (! $locked->usesSigningSteps() || ! is_string($ulid) || $ulid === '') {
            return null;
        }

        /** @var Recipient|null $refuser */
        $refuser = $recipients->first(fn (Recipient $recipient): bool => $recipient->ulid === $ulid);

        if ($refuser === null || $refuser->status !== RecipientStatus::Refused) {
            $this->clearDeferredRefusal($locked);

            return null;
        }

        $pending = $recipients->filter(fn (Recipient $recipient): bool => $recipient->isPendingParticipant());

        if ($pending->isEmpty()) {
            return $refuser;
        }

        $pendingStep = (int) $pending->min(fn (Recipient $recipient): int => (int) ($recipient->getAttribute('signing_step_index') ?? 0));

        if ($pendingStep > (int) ($refuser->getAttribute('signing_step_index') ?? 0)) {
            $this->clearDeferredRefusal($locked);
        }

        return null;
    }

    private function clearDeferredRefusal(Envelope $locked): void
    {
        $settings = $locked->settings ?? [];
        unset($settings[self::SETTING_DEFERRED_REFUSAL]);
        $locked->settings = $settings;
        $locked->save();
    }

    /**
     * Alguma etapa ainda não alcançada lê a decisão deste aprovador (ou de quem delegou a ele)?
     */
    public function decisionIsReferenced(Envelope $envelope, Recipient $approver): bool
    {
        $ulids = [$approver->ulid];
        $current = $approver;

        for ($hops = 0; $hops < 10 && $current->getAttribute('delegated_from_recipient_id') !== null; $hops++) {
            $current = Recipient::withoutOrganizationScope()
                ->whereKey((int) $current->getAttribute('delegated_from_recipient_id'))
                ->first();

            if ($current === null) {
                break;
            }

            $ulids[] = $current->ulid;
        }

        $steps = SigningStep::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('step_index', '>', (int) ($approver->getAttribute('signing_step_index') ?? 0))
            ->where('status', SigningStep::STATUS_PENDING)
            ->get();

        foreach ($steps as $step) {
            foreach ((array) ($step->condition['rules'] ?? []) as $rule) {
                if (is_array($rule)
                    && ($rule['type'] ?? null) === StepCondition::RULE_APPROVER_DECISION
                    && in_array($rule['recipient'] ?? null, $ulids, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{result: bool, match: string|null, rules: list<array<string, mixed>>, invalid?: bool}
     */
    private function evaluate(Envelope $envelope, SigningStep $step): array
    {
        if ($step->condition === null) {
            return ['result' => true, 'match' => null, 'rules' => []];
        }

        try {
            $condition = StepCondition::fromArray($step->condition);
        } catch (InvalidStepDefinition) {
            // Não acontece pelo caminho normal (o envio revalida a definição). Se acontecer, a
            // etapa é mantida: pular calaria participantes; incluí-los é o erro reversível.
            return ['result' => true, 'match' => null, 'rules' => [], 'invalid' => true];
        }

        return $this->evaluator->evaluate($condition, EvaluationFacts::for($envelope, $condition));
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    private function activate(Envelope $envelope, SigningStep $step, array $evaluation, string $correlationId): void
    {
        if (! $this->transition($step, SigningStep::STATUS_ACTIVE, $evaluation)) {
            return;
        }

        SignerAudit::system($envelope, AuditEventType::EnvelopeStepStarted, self::payload($step, $evaluation), null, $correlationId);
    }

    /**
     * @param  array<string, mixed>  $evaluation
     * @param  Collection<int, Recipient>  $recipients
     */
    private function skip(Envelope $envelope, SigningStep $step, array $evaluation, Collection $recipients, string $correlationId): bool
    {
        if (! $this->transition($step, SigningStep::STATUS_SKIPPED, $evaluation)) {
            return false;
        }

        $canceled = [];

        foreach ($recipients as $recipient) {
            if ((int) ($recipient->getAttribute('signing_step_index') ?? 0) !== $step->step_index || ! $recipient->isPendingParticipant()) {
                continue;
            }

            $recipient->transitionTo(RecipientStatus::Canceled);
            $recipient->forceFill(['status_reason' => self::REASON_STEP_SKIPPED])->save();
            $canceled[] = $recipient;
        }

        if ($canceled !== []) {
            // Nunca foram convidados; se algum link existir por outro caminho, deixa de abrir.
            RecipientAccessLink::withoutOrganizationScope()
                ->whereIn('recipient_id', array_map(static fn (Recipient $recipient): int => (int) $recipient->getKey(), $canceled))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => Carbon::now()]);
        }

        SignerAudit::system($envelope, AuditEventType::EnvelopeStepSkipped, self::payload($step, $evaluation) + [
            'canceled_recipients' => array_map(static fn (Recipient $recipient): string => $recipient->ulid, $canceled),
        ], null, $correlationId);

        return true;
    }

    /**
     * UPDATE condicional: só a primeira execução muda a etapa de `pending`.
     *
     * @param  array<string, mixed>  $evaluation
     */
    private function transition(SigningStep $step, string $status, array $evaluation): bool
    {
        $now = Carbon::now();

        $affected = SigningStep::withoutOrganizationScope()
            ->whereKey($step->getKey())
            ->where('status', SigningStep::STATUS_PENDING)
            ->update([
                'status' => $status,
                'evaluated_at' => $now,
                'evaluation' => json_encode($evaluation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);

        return $affected === 1;
    }

    /**
     * @return Collection<int, Recipient>
     */
    private function reload(Envelope $envelope): Collection
    {
        return Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();
    }

    /**
     * Payload da trilha: a regra e os valores avaliados — dado do documento, nunca instrução.
     *
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    private static function payload(SigningStep $step, array $evaluation): array
    {
        return array_filter([
            'step' => $step->ulid,
            'step_index' => $step->step_index,
            'name' => $step->name,
            'match' => $evaluation['match'] ?? null,
            'result' => (bool) ($evaluation['result'] ?? false),
            'rules' => $evaluation['rules'] ?? [],
            'invalid_definition' => ($evaluation['invalid'] ?? false) === true ? true : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
