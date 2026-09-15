<?php

namespace App\Services\Envelopes\Steps;

use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Models\Delegation;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Models\SigningStep;
use App\Models\User;
use App\Services\Envelopes\Delegation\DelegationPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * JSON de `GET envelopes.flow.show` — o editor de etapas/delegação do wizard e o painel do
 * detalhe leem daqui (docs/fase-3/etapas-e-delegacao.md §5). `null` (404) quando as duas flags
 * estão desligadas e o envelope não tem etapas nem delegações: a tela é a de antes.
 *
 * A autorização de LEITURA é `view` (no controller); `can_edit` exige `update` e envelope em
 * preparo; `can_decide` exige `send` e coleta em andamento.
 */
final class FlowState
{
    /**
     * @return array<string, mixed>|null
     */
    public function forEnvelope(Envelope $envelope, ?User $user): ?array
    {
        $organization = $envelope->organization;
        $stepsFeature = FlowFeatures::conditionalSteps($organization);
        $delegationFeature = FlowFeatures::delegation($organization);
        $hasDelegations = Delegation::withoutOrganizationScope()->where('envelope_id', $envelope->getKey())->exists();

        if (! $stepsFeature && ! $delegationFeature && ! $envelope->usesSigningSteps() && ! $hasDelegations) {
            return null;
        }

        $editable = $envelope->status->isDraftLike();
        $gate = Gate::forUser($user);
        $canEdit = $editable && $user !== null && $gate->allows('update', $envelope);
        $canDecide = $delegationFeature
            && $user !== null
            && $envelope->status === EnvelopeStatus::InProgress
            && $gate->allows('send', $envelope);

        /** @var Collection<int, Recipient> $recipients */
        $recipients = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $ulidById = $recipients->mapWithKeys(fn (Recipient $recipient): array => [(int) $recipient->getKey() => $recipient->ulid])->all();

        return [
            'envelope' => [
                'id' => $envelope->ulid,
                'status' => $envelope->status->value,
                'editable' => $editable,
                'signing_order' => $envelope->signing_order->value,
                'current_order' => (int) $envelope->current_order,
            ],
            'features' => [
                'conditional_steps' => $stepsFeature,
                'delegation' => $delegationFeature,
            ],
            'participants' => $recipients
                ->filter(fn (Recipient $recipient): bool => $recipient->participates())
                ->map(function (Recipient $recipient) use ($ulidById): array {
                    $step = $recipient->getAttribute('signing_step_index');
                    $from = $recipient->getAttribute('delegated_from_recipient_id');

                    return [
                        'id' => $recipient->ulid,
                        'name' => $recipient->name,
                        'email' => $recipient->email,
                        'role' => $recipient->role->value,
                        'role_label' => $recipient->role->label(),
                        'step' => $step === null ? null : (int) $step,
                        'order' => (int) $recipient->order_index,
                        'status' => $recipient->status->value,
                        'status_label' => $recipient->status->label(),
                        'status_reason' => $recipient->getAttribute('status_reason'),
                        'delegated_from' => $from === null ? null : ($ulidById[(int) $from] ?? null),
                    ];
                })
                ->values()
                ->all(),
            'steps' => $stepsFeature || $envelope->usesSigningSteps()
                ? $this->steps($envelope, $stepsFeature && $canEdit, $ulidById)
                : null,
            'delegation' => $delegationFeature || $hasDelegations
                ? $this->delegation($envelope, $delegationFeature && $canEdit, $canDecide, $recipients)
                : null,
        ];
    }

    /**
     * @param  array<int, string>  $ulidById
     * @return array<string, mixed>
     */
    private function steps(Envelope $envelope, bool $canEdit, array $ulidById): array
    {
        $fields = SigningField::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereIn('type', array_map(static fn (FieldType $type): string => $type->value, StepDefinitionValidator::CONDITION_FIELD_TYPES))
            ->orderBy('page')
            ->orderBy('sort_order')
            ->get();

        return [
            'enabled' => $envelope->usesSigningSteps(),
            'can_edit' => $canEdit,
            'items' => SigningStep::withoutOrganizationScope()
                ->where('envelope_id', $envelope->getKey())
                ->orderBy('step_index')
                ->get()
                ->map(fn (SigningStep $step): array => [
                    'id' => $step->ulid,
                    'index' => $step->step_index,
                    'name' => $step->name,
                    'condition' => $step->condition,
                    'status' => $step->status,
                    'status_label' => $step->statusLabel(),
                    'evaluated_at' => $step->evaluated_at?->toIso8601String(),
                    'evaluation' => $step->evaluation,
                    'summary' => FlowEvidence::describeEvaluation($step->evaluation),
                ])
                ->values()
                ->all(),
            'fields' => $fields->map(fn (SigningField $field): array => [
                'id' => $field->ulid,
                'label' => $field->label ?: $field->type->label(),
                'type' => $field->type->value,
                'recipient_id' => $ulidById[(int) $field->recipient_id] ?? null,
            ])->values()->all(),
            'limits' => [
                'max_steps' => FlowFeatures::maxSteps(),
                'max_rules' => FlowFeatures::maxRules(),
                'max_literal_length' => FlowFeatures::maxLiteralLength(),
            ],
        ];
    }

    /**
     * @param  Collection<int, Recipient>  $recipients
     * @return array<string, mixed>
     */
    private function delegation(Envelope $envelope, bool $canEdit, bool $canDecide, Collection $recipients): array
    {
        $byId = $recipients->keyBy(fn (Recipient $recipient): int => (int) $recipient->getKey());

        return [
            'can_edit' => $canEdit,
            'can_decide' => $canDecide,
            'policy' => [
                'allow' => DelegationPolicy::allows($envelope),
                'requires_confirmation' => DelegationPolicy::requiresConfirmation($envelope),
                'personal' => DelegationPolicy::personal($envelope),
            ],
            'limits' => [
                'max_chain_depth' => DelegationPolicy::maxChainDepth(),
                'max_requests_per_recipient' => DelegationPolicy::maxRequestsPerRecipient(),
                'max_per_organization_per_day' => DelegationPolicy::maxPerOrganizationPerDay(),
            ],
            'requests' => Delegation::withoutOrganizationScope()
                ->where('envelope_id', $envelope->getKey())
                ->orderByDesc('id')
                ->get()
                ->map(function (Delegation $delegation) use ($byId, $canDecide): array {
                    $from = $byId->get((int) $delegation->from_recipient_id);
                    $to = $delegation->to_recipient_id === null ? null : $byId->get((int) $delegation->to_recipient_id);

                    return [
                        'id' => $delegation->ulid,
                        'status' => $delegation->status,
                        'status_label' => $delegation->statusLabel(),
                        'from' => ['id' => $from?->ulid, 'name' => $from->name ?? '—'],
                        'to' => ['id' => $to?->ulid, 'name' => $delegation->to_name, 'email' => $delegation->to_email],
                        'reason' => $delegation->reason,
                        'chain_depth' => $delegation->chain_depth,
                        'requested_at' => $delegation->requested_at->toIso8601String(),
                        'delegated_at' => $delegation->delegated_at?->toIso8601String(),
                        'approved_by_sender_at' => $delegation->approved_by_sender_at?->toIso8601String(),
                        'rejected_at' => $delegation->rejected_at?->toIso8601String(),
                        'decision_note' => $delegation->decision_note,
                        'can_decide' => $canDecide
                            && $delegation->isPending()
                            && $from !== null
                            && $from->status->canBeDelegated(),
                    ];
                })
                ->values()
                ->all(),
        ];
    }
}
