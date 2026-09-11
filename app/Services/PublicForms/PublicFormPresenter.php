<?php

namespace App\Services\PublicForms;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\PublicForm;
use App\Models\PublicFormSubmission;
use App\Models\Recipient;
use App\Models\Template;
use App\Models\TemplateRole;
use App\Models\TemplateVariable;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\Templates\TemplateStatus;
use Illuminate\Support\Facades\Gate;

/**
 * Props das telas do formulário público (contrato em docs/fase-2/formulario-publico.md §8;
 * tipos TS em resources/js/components/public-forms/types.ts).
 */
final class PublicFormPresenter
{
    public function __construct(
        private readonly PublicFormSchema $schema,
        private readonly PublicFormReview $review,
    ) {}

    // -- Telas internas ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function index(Organization $organization, User $user): array
    {
        $forms = PublicForm::query()
            ->with(['template', 'templateVersion.variables', 'templateVersion.roles', 'templateVersion.fields', 'responsibleUser', 'organization'])
            ->withCount([
                'submissions as pending_review_count' => fn ($query) => $query->where('status', SubmissionStatus::PendingReview->value),
                'submissions as sent_count' => fn ($query) => $query->where('status', SubmissionStatus::Sent->value),
            ])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 WHEN 'paused' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at')
            ->get();

        $pending = PublicFormSubmission::query()
            ->with(['form', 'envelope.recipients'])
            ->where('status', SubmissionStatus::PendingReview->value)
            ->orderBy('confirmed_at')
            ->limit(100)
            ->get();

        $this->review->reconcile($pending);

        $templates = Template::query()
            ->where('status', TemplateStatus::Active->value)
            ->whereNotNull('current_version_id')
            ->with(['currentVersion' => fn ($query) => $query->withCount(['roles', 'variables'])])
            ->orderBy('name')
            ->get();

        return [
            'forms' => $forms->map(fn (PublicForm $form): array => $this->row($form))->values()->all(),
            'queue' => $pending
                ->filter(fn (PublicFormSubmission $submission): bool => $submission->status === SubmissionStatus::PendingReview)
                ->map(fn (PublicFormSubmission $submission): array => $this->submission($submission))
                ->values()
                ->all(),
            'templates' => $templates->map(fn (Template $template): array => [
                'id' => $template->ulid,
                'name' => $template->name,
                'source_label' => $template->source_type->label(),
                'roles_count' => (int) ($template->currentVersion->roles_count ?? 0),
                'variables_count' => (int) ($template->currentVersion->variables_count ?? 0),
            ])->values()->all(),
            'can' => [
                'create' => Gate::forUser($user)->allows('create', PublicForm::class),
                'approve' => $this->canApprove($user, $organization),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function edit(PublicForm $form, User $user): array
    {
        $form->loadMissing(['template', 'templateVersion', 'organization', 'responsibleUser']);

        $template = $form->template;
        /** @var TemplateVersion|null $current */
        $current = $template->currentVersion()->with(['variables', 'roles', 'fields'])->first();
        $version = $current ?? $form->templateVersion;
        $version->loadMissing(['variables', 'roles', 'fields']);

        $submissions = PublicFormSubmission::query()
            ->with(['form', 'envelope.recipients'])
            ->where('public_form_id', $form->getKey())
            ->where('status', '!=', SubmissionStatus::PendingConfirmation->value)
            ->latest('id')
            ->limit(30)
            ->get();

        $this->review->reconcile($submissions);

        $autoSendBlocker = $this->schema->autoSendBlocker($version);

        return [
            'form' => [
                'id' => $form->ulid,
                'title' => $form->title,
                'instructions' => $form->instructions,
                'status' => $form->status->value,
                'status_label' => $form->status->label(),
                'destination' => $form->destination->value,
                'public_url' => $form->isRevoked() ? null : $form->publicUrl(),
                'expires_at' => $form->expires_at?->setTimezone($form->organization->timezone ?: 'UTC')->format('Y-m-d'),
                'published_at' => $form->published_at?->toIso8601String(),
                'envelope_title' => $form->settings['envelope_title'] ?? null,
                'submissions_limit' => $form->submissionsLimit(),
                'submissions_period' => $form->submissionsPeriod()->value,
                'public_variables' => $form->publicVariables(),
                'fixed_values' => (object) $form->fixedValues(),
                'filler_role' => $form->fillerRole(),
                'fixed_participants' => (object) $form->fixedParticipants(),
                'responsible' => $form->responsibleUser?->name,
                'pending_confirmation_count' => PublicFormSubmission::query()
                    ->where('public_form_id', $form->getKey())
                    ->where('status', SubmissionStatus::PendingConfirmation->value)
                    ->count(),
            ],
            'template' => [
                'id' => $template->ulid,
                'name' => $template->name,
                'source_type' => $template->source_type->value,
                'source_label' => $template->source_type->label(),
                'usable' => $template->isUsable(),
                'current_version' => $current?->version_number,
                'form_version' => $form->templateVersion->version_number,
                'outdated' => $current !== null && (int) $current->getKey() !== (int) $form->template_version_id,
            ],
            'variables' => $this->variables($version),
            'roles' => $version->roles->map(fn (TemplateRole $role): array => [
                'id' => $role->ulid,
                'name' => $role->name,
                'participant_role' => $role->participant_role->value,
                'participant_role_label' => $role->participant_role->label(),
            ])->values()->all(),
            'issues' => $this->schema->issues($form),
            'options' => [
                'destinations' => array_map(fn (PublicFormDestination $destination): array => [
                    'value' => $destination->value,
                    'label' => $destination->label(),
                    'description' => $destination->description(),
                    'available' => $destination === PublicFormDestination::Review || $autoSendBlocker === null,
                    'reason' => $destination === PublicFormDestination::AutoSend ? $autoSendBlocker : null,
                ], PublicFormDestination::cases()),
                'periods' => array_map(fn (SubmissionPeriod $period): array => [
                    'value' => $period->value,
                    'label' => $period->label(),
                ], SubmissionPeriod::cases()),
            ],
            'submissions' => $submissions->map(fn (PublicFormSubmission $submission): array => $this->submission($submission))->values()->all(),
            'can' => [
                'update' => Gate::forUser($user)->allows('update', $form) && ! $form->isRevoked(),
                'approve' => Gate::forUser($user)->allows('approve', $form),
            ],
        ];
    }

    // -- Telas públicas ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function fill(PublicForm $form, string $timer): array
    {
        $version = $form->templateVersion()->with(['variables', 'roles'])->firstOrFail();
        $public = $form->publicVariables();
        $filler = $version->roles->firstWhere('ulid', $form->fillerRole());

        return [
            'form' => [
                'title' => $form->title,
                'instructions' => $form->instructions,
                'organization_name' => $form->organization->name,
                'role_name' => $filler instanceof TemplateRole ? $filler->name : null,
                'fields' => array_values(array_filter(
                    $this->variables($version),
                    fn (array $variable): bool => in_array($variable['key'], $public, true),
                )),
                'max_length' => PublicFormsConfig::maxFieldLength(),
                'confirmation_ttl_minutes' => PublicFormsConfig::confirmationTtlMinutes(),
            ],
            'antiabuse' => [
                'timer_field' => PublicFormIntake::TIMER,
                'timer' => $timer,
                'honeypot_field' => PublicFormIntake::HONEYPOT,
            ],
            'privacy' => $this->privacy($form->organization->name),
        ];
    }

    /**
     * @return array{version: string, summary: string, sections: list<array{title: string, body: string}>}
     */
    public function privacy(string $organization): array
    {
        return [
            'version' => PrivacyNotice::VERSION,
            'summary' => PrivacyNotice::summary($organization),
            'sections' => PrivacyNotice::sections($organization),
        ];
    }

    // -- Partes ------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function row(PublicForm $form): array
    {
        return [
            'id' => $form->ulid,
            'title' => $form->title,
            'template' => ['id' => $form->template->ulid, 'name' => $form->template->name],
            'status' => $form->status->value,
            'status_label' => $form->status->label(),
            'destination' => $form->destination->value,
            'destination_label' => $form->destination->label(),
            'public_url' => $form->status === PublicFormStatus::Active || $form->status === PublicFormStatus::Paused ? $form->publicUrl() : null,
            'expires_at' => $form->expires_at?->toIso8601String(),
            'expired' => $form->isExpired(),
            'updated_at' => $form->updated_at?->toIso8601String(),
            'pending_review_count' => (int) ($form->getAttribute('pending_review_count') ?? 0),
            'sent_count' => (int) ($form->getAttribute('sent_count') ?? 0),
            'issues_count' => $form->isRevoked() ? 0 : count($this->schema->issues($form)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function submission(PublicFormSubmission $submission): array
    {
        $envelope = $submission->envelope;
        $fixed = array_column($submission->form->fixedParticipants(), 'email');

        /** @var Recipient|null $filler */
        $filler = $envelope?->recipients->first(fn (Recipient $recipient): bool => ! in_array($recipient->email, $fixed, true));

        return [
            'id' => $submission->ulid,
            'form' => ['id' => $submission->form->ulid, 'title' => $submission->form->title],
            'status' => $submission->status->value,
            'status_label' => $submission->status->label(),
            'failure_reason' => $submission->failure_reason,
            'failure_message' => self::reasonMessage($submission->failure_reason),
            'confirmed_at' => $submission->confirmed_at?->toIso8601String(),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'filler' => $filler !== null ? ['name' => $filler->name, 'email' => $filler->email] : null,
            'envelope' => $envelope !== null && ! $envelope->trashed() ? [
                'id' => $envelope->ulid,
                'title' => $envelope->title,
                'status' => $envelope->status->value,
                'status_label' => $envelope->status->label(),
                'draft' => $envelope->status->isDraftLike(),
            ] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function variables(TemplateVersion $version): array
    {
        return array_values($version->variables->map(fn (TemplateVariable $variable): array => [
            'key' => $variable->key,
            'label' => $variable->label,
            'type' => $variable->type->value,
            'required' => $variable->required,
            'help_text' => $variable->help_text,
            'default_value' => $variable->default_value,
            'options' => $variable->options ?? (object) [],
        ])->all());
    }

    private function canApprove(User $user, Organization $organization): bool
    {
        $membership = $user->membershipFor($organization);

        return $membership !== null
            && $membership->isActive()
            && $membership->hasPermission(Permission::ManageTemplates)
            && $membership->hasPermission(Permission::SendEnvelopes);
    }

    public static function reasonMessage(?string $reason): ?string
    {
        return match ($reason) {
            null => null,
            'quota_exhausted' => 'A cota do plano estava esgotada na hora do envio automático.',
            'incomplete' => 'O documento precisa de ajustes no editor antes do envio.',
            'subscription_past_due', 'subscription_inactive', 'no_subscription' => 'O plano da organização não permitia enviar na hora da confirmação.',
            'generation_rejected' => 'O modelo recusou os dados enviados (a configuração pode ter mudado).',
            'generation_failed' => 'Falha técnica ao gerar o documento.',
            'envelope_deleted' => 'O rascunho foi excluído.',
            'envelope_closed' => 'O documento foi encerrado sem envio.',
            default => 'O envio automático não pôde ser concluído.',
        };
    }
}
