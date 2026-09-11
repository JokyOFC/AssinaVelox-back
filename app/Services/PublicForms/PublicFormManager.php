<?php

namespace App\Services\PublicForms;

use App\Enums\AuditEventType;
use App\Enums\RecipientRole;
use App\Models\Organization;
use App\Models\PublicForm;
use App\Models\PublicFormSubmission;
use App\Models\Template;
use App\Models\TemplateRole;
use App\Models\TemplateVersion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestão do formulário pela organização (docs/fase-2/formulario-publico.md §3 e §4):
 * criar (rascunho), salvar configuração, publicar, pausar e revogar.
 *
 * Quem salva vira o RESPONSÁVEL: os documentos gerados são criados em nome dele (a coluna
 * `envelopes.created_by_user_id` é obrigatória), e por isso ele precisa continuar podendo
 * criar — e, no envio automático, enviar — documentos (`PublicFormSchema::issues`).
 */
final class PublicFormManager
{
    public function __construct(
        private readonly PublicFormSchema $schema,
        private readonly SubmissionPurge $purge,
    ) {}

    /**
     * Rascunho com a configuração inicial: todas as variáveis preenchidas pelo público,
     * quem preenche no primeiro papel de signatário, fila de revisão e 50 envios por dia.
     *
     * @throws ValidationException
     */
    public function create(Organization $organization, User $user, Template $template, ?string $title = null): PublicForm
    {
        $version = $template->currentVersion()->with(['variables', 'roles'])->first();

        if (! $template->isUsable() || ! $version instanceof TemplateVersion) {
            throw ValidationException::withMessages(['template' => 'Este modelo não está disponível para uso.']);
        }

        $filler = $version->roles->first(fn (TemplateRole $role): bool => $role->participant_role === RecipientRole::Signer);

        if (! $filler instanceof TemplateRole) {
            throw ValidationException::withMessages(['template' => 'O modelo precisa de pelo menos um participante signatário para ser usado num formulário público.']);
        }

        $title = trim((string) $title) !== '' ? mb_substr(trim((string) $title), 0, 160) : mb_substr($template->name, 0, 160);

        $form = PublicForm::query()->create([
            'organization_id' => $organization->getKey(),
            'template_id' => $template->getKey(),
            'template_version_id' => $version->getKey(),
            'responsible_user_id' => $user->getKey(),
            'created_by_user_id' => $user->getKey(),
            'title' => $title,
            'status' => PublicFormStatus::Draft,
            'destination' => PublicFormDestination::Review,
            'schema' => [
                'public_variables' => $version->variables->pluck('key')->values()->all(),
                'fixed_values' => [],
                'filler_role' => $filler->ulid,
                'fixed_participants' => [],
            ],
            'settings' => [
                'submissions_limit' => PublicForm::DEFAULT_SUBMISSIONS_LIMIT,
                'submissions_period' => SubmissionPeriod::Day->value,
                'envelope_title' => null,
            ],
        ]);

        PublicFormAudit::record($form, AuditEventType::PublicFormCreated, [
            'template' => $template->ulid,
            'template_version' => $version->ulid,
        ]);

        return $form;
    }

    /**
     * Salva a configuração contra a versão ATUAL do modelo (e passa a usá-la).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(PublicForm $form, User $user, array $input): PublicForm
    {
        if ($form->isRevoked()) {
            throw ValidationException::withMessages(['form' => 'Formulário revogado não pode ser alterado.']);
        }

        $template = $form->template;
        $version = $template->currentVersion()->with(['variables', 'roles', 'fields'])->first();

        if (! $template->isUsable() || ! $version instanceof TemplateVersion) {
            throw ValidationException::withMessages(['template' => 'O modelo deste formulário foi arquivado. Restaure o modelo para alterar o formulário.']);
        }

        $built = $this->schema->build($form, $version, $input);

        $before = [
            'title' => $form->title,
            'instructions' => $form->instructions,
            'destination' => $form->destination->value,
            'expires_at' => $form->expires_at?->toIso8601String(),
            'schema' => $form->schema,
            'settings' => $form->settings,
            'template_version' => $form->template_version_id,
            'responsible' => $form->responsible_user_id,
        ];

        $form->forceFill([
            'title' => $built['title'],
            'instructions' => $built['instructions'],
            'destination' => $built['destination'],
            'expires_at' => $built['expires_at'],
            'schema' => $built['schema'],
            'settings' => $built['settings'],
            'template_version_id' => $version->getKey(),
            'responsible_user_id' => $user->getKey(),
        ])->save();

        $after = [
            'title' => $form->title,
            'instructions' => $form->instructions,
            'destination' => $form->destination->value,
            'expires_at' => $form->expires_at?->toIso8601String(),
            'schema' => $form->schema,
            'settings' => $form->settings,
            'template_version' => $form->template_version_id,
            'responsible' => $form->responsible_user_id,
        ];

        $changed = array_keys(array_filter($after, fn ($value, $key) => $value != $before[$key], ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            PublicFormAudit::record($form, AuditEventType::PublicFormUpdated, [
                'changed' => $changed,
                'template_version' => $version->ulid,
                'destination' => $form->destination->value,
            ]);
        }

        return $form->refresh();
    }

    /**
     * Publicar (rascunho) ou retomar (pausado). Exige zero pendências.
     *
     * @throws ValidationException
     */
    public function activate(PublicForm $form): PublicForm
    {
        if (! in_array($form->status, [PublicFormStatus::Draft, PublicFormStatus::Paused], true)) {
            throw ValidationException::withMessages(['form' => 'Só um formulário em rascunho ou pausado pode ser publicado.']);
        }

        if ($form->isExpired()) {
            throw ValidationException::withMessages(['expires_at' => 'A data de encerramento já passou. Altere a data antes de publicar.']);
        }

        $issues = $this->schema->issues($form->fresh() ?? $form);

        if ($issues !== []) {
            throw ValidationException::withMessages(['form' => $issues[0]['message']]);
        }

        $resumed = $form->status === PublicFormStatus::Paused;

        $form->forceFill([
            'status' => PublicFormStatus::Active,
            'published_at' => $form->published_at ?? Carbon::now(),
            'paused_at' => null,
        ])->save();

        PublicFormAudit::record($form, AuditEventType::PublicFormActivated, ['resumed' => $resumed]);

        return $form;
    }

    public function pause(PublicForm $form): PublicForm
    {
        if ($form->status !== PublicFormStatus::Active) {
            throw ValidationException::withMessages(['form' => 'Só um formulário publicado pode ser pausado.']);
        }

        $form->forceFill(['status' => PublicFormStatus::Paused, 'paused_at' => Carbon::now()])->save();

        PublicFormAudit::record($form, AuditEventType::PublicFormPaused);

        return $form;
    }

    /**
     * Revogar é definitivo: o link passa a responder como inexistente e os envios que
     * aguardavam confirmação são apagados (não podem mais virar documento).
     */
    public function revoke(PublicForm $form): PublicForm
    {
        if ($form->isRevoked()) {
            return $form;
        }

        $discarded = DB::transaction(function () use ($form): int {
            $form->forceFill(['status' => PublicFormStatus::Revoked, 'revoked_at' => Carbon::now()])->save();

            return PublicFormSubmission::withoutOrganizationScope()
                ->where('public_form_id', $form->getKey())
                ->where('status', SubmissionStatus::PendingConfirmation->value)
                ->delete();
        });

        $this->purge->run($form);

        PublicFormAudit::record($form, AuditEventType::PublicFormRevoked, ['discarded_unconfirmed' => $discarded]);

        return $form;
    }
}
