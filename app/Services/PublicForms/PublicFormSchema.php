<?php

namespace App\Services\PublicForms;

use App\Enums\FieldType;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Enums\RecipientRole;
use App\Models\PublicForm;
use App\Models\TemplateField;
use App\Models\TemplateRole;
use App\Models\TemplateVariable;
use App\Models\TemplateVersion;
use App\Services\Envelopes\DomainFeatures;
use App\Services\Templates\TemplateSourceType;
use App\Services\Templates\VariableValues;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Configuração do formulário contra a versão do modelo (docs/fase-2/formulario-publico.md §4).
 *
 * `build()` valida o que a tela manda e devolve os atributos a gravar. `issues()` diz, a
 * qualquer momento, por que um formulário não pode receber envios (modelo mudou, participante
 * fixo faltando, responsável sem permissão…). Publicar exige zero pendências; a página
 * pública trata qualquer pendência como "temporariamente indisponível".
 *
 * Os valores fixos passam pela MESMA validação tipada dos modelos ({@see VariableValues}),
 * e o preenchimento continua sendo feito pelo motor restrito do modelo — nada aqui monta
 * texto de documento.
 */
final class PublicFormSchema
{
    public function __construct(private readonly VariableValues $values) {}

    /**
     * @param  array<string, mixed>  $input  forma já conferida por UpdatePublicFormRequest
     * @return array{title: string, instructions: string|null, destination: PublicFormDestination, expires_at: Carbon|null, schema: array<string, mixed>, settings: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function build(PublicForm $form, TemplateVersion $version, array $input, bool $checkExpiry = true): array
    {
        $version->loadMissing(['variables', 'roles', 'fields']);

        $errors = [];

        // -- Variáveis: quem preenche cada uma --------------------------------------------
        $known = $version->variables->keyBy('key');
        $public = array_values(array_unique(array_filter(
            is_array($input['public_variables'] ?? null) ? $input['public_variables'] : [],
            'is_string',
        )));

        $unknown = array_diff($public, $known->keys()->all());

        if ($unknown !== []) {
            $errors['public_variables'] = 'Estas variáveis não existem na versão atual do modelo: '.implode(', ', $unknown).'.';
        }

        $fixedInput = is_array($input['fixed_values'] ?? null) ? $input['fixed_values'] : [];
        $fixed = [];

        /** @var list<TemplateVariable> $nonPublic */
        $nonPublic = $version->variables->reject(fn (TemplateVariable $variable): bool => in_array($variable->key, $public, true))->values()->all();

        foreach ($nonPublic as $variable) {
            $raw = $fixedInput[$variable->key] ?? null;

            if (is_scalar($raw) && trim((string) $raw) !== '') {
                $fixed[$variable->key] = trim((string) $raw);
            }
        }

        try {
            // Mesma validação tipada do "Usar modelo"; obrigatória sem valor e sem padrão
            // bloqueia (ou o público preenche, ou quem publica fixa um valor).
            $this->values->validate($nonPublic, $fixed, 'fixed_values');
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $errors[$key] = $messages[0];
            }
        }

        // -- Participantes: quem preenche ocupa um papel; os demais são fixos -------------
        $roles = $version->roles->keyBy('ulid');
        $filler = is_string($input['filler_role'] ?? null) ? $input['filler_role'] : '';
        $fillerRole = $roles->get($filler);

        if (! $fillerRole instanceof TemplateRole) {
            $errors['filler_role'] = 'Escolha o papel que a pessoa que preenche o formulário vai ocupar.';
        } elseif ($fillerRole->participant_role !== RecipientRole::Signer) {
            $errors['filler_role'] = 'Quem preenche o formulário precisa ocupar um papel de signatário.';
        }

        $participantsInput = is_array($input['fixed_participants'] ?? null) ? $input['fixed_participants'] : [];
        $participants = [];
        $rules = [];
        $attributes = [];

        foreach ($version->roles as $role) {
            /** @var TemplateRole $role */
            if ($role->ulid === $filler) {
                continue;
            }

            $row = is_array($participantsInput[$role->ulid] ?? null) ? $participantsInput[$role->ulid] : [];
            $participants[$role->ulid] = [
                'name' => is_scalar($row['name'] ?? null) ? trim((string) $row['name']) : '',
                'email' => is_scalar($row['email'] ?? null) ? mb_strtolower(trim((string) $row['email'])) : '',
            ];

            $rules["fixed_participants.{$role->ulid}.name"] = ['required', 'string', 'min:2', 'max:120'];
            $rules["fixed_participants.{$role->ulid}.email"] = ['required', 'string', 'email:rfc', 'max:255'];
            $attributes["fixed_participants.{$role->ulid}.name"] = 'nome de '.$role->name;
            $attributes["fixed_participants.{$role->ulid}.email"] = 'e-mail de '.$role->name;
        }

        $validator = Validator::make(['fixed_participants' => $participants], $rules, [], $attributes);

        foreach ($validator->errors()->messages() as $key => $messages) {
            $errors[$key] = $messages[0];
        }

        $seen = [];

        foreach ($participants as $ulid => $row) {
            if ($row['email'] !== '' && isset($seen[$row['email']])) {
                $errors["fixed_participants.{$ulid}.email"] = 'Este e-mail já está em outro papel deste formulário.';
            }

            $seen[$row['email']] = true;
        }

        // -- Destino ----------------------------------------------------------------------
        $destination = PublicFormDestination::tryFrom((string) ($input['destination'] ?? '')) ?? PublicFormDestination::Review;

        if ($destination === PublicFormDestination::AutoSend && ($blocker = $this->autoSendBlocker($version)) !== null) {
            $errors['destination'] = $blocker;
        }

        // -- Prazo ------------------------------------------------------------------------
        $expiresAt = null;
        $rawExpiry = $input['expires_at'] ?? null;

        if (is_string($rawExpiry) && $rawExpiry !== '') {
            $timezone = $form->organization->timezone ?: (string) config('app.timezone', 'UTC');

            try {
                $expiresAt = Carbon::createFromFormat('Y-m-d', substr($rawExpiry, 0, 10), $timezone)?->endOfDay()->setTimezone('UTC');
            } catch (\Throwable) {
                $expiresAt = null;
            }

            if ($expiresAt === null) {
                $errors['expires_at'] = 'Informe a data de encerramento no formato dd/mm/aaaa.';
            } elseif ($checkExpiry && $expiresAt->isPast()) {
                $errors['expires_at'] = 'A data de encerramento precisa ser hoje ou depois.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $period = SubmissionPeriod::tryFrom((string) ($input['submissions_period'] ?? '')) ?? SubmissionPeriod::Day;

        return [
            'title' => trim((string) ($input['title'] ?? $form->title)),
            'instructions' => ($instructions = trim((string) ($input['instructions'] ?? ''))) !== '' ? $instructions : null,
            'destination' => $destination,
            'expires_at' => $expiresAt,
            'schema' => [
                'public_variables' => array_values(array_intersect($known->keys()->all(), $public)),
                'fixed_values' => $fixed,
                'filler_role' => $filler,
                'fixed_participants' => $participants,
            ],
            'settings' => [
                'submissions_limit' => max(1, min(10_000, (int) ($input['submissions_limit'] ?? PublicForm::DEFAULT_SUBMISSIONS_LIMIT))),
                'submissions_period' => $period->value,
                'envelope_title' => ($envelopeTitle = trim((string) ($input['envelope_title'] ?? ''))) !== '' ? $envelopeTitle : null,
            ],
        ];
    }

    /**
     * Por que o envio automático não é possível com esta versão (null = possível).
     *
     * O envelope só sai sozinho se nascer PRONTO: documento processado na hora e um campo de
     * assinatura obrigatório para cada papel que assina. Isso só acontece no modelo em PDF
     * fixo — em Word/HTML os campos são posicionados no editor, depois de gerar.
     */
    public function autoSendBlocker(TemplateVersion $version): ?string
    {
        $version->loadMissing(['roles', 'fields']);

        if ($version->source_type !== TemplateSourceType::Pdf) {
            return 'O envio automático exige um modelo em PDF fixo com os campos já posicionados. Em modelos Word ou texto os campos são posicionados no editor depois de gerar o documento: use a fila de revisão.';
        }

        foreach ($version->roles as $role) {
            /** @var TemplateRole $role */
            if (! $role->participant_role->requiresSignatureField()) {
                continue;
            }

            $hasSignature = $version->fields->contains(
                fn (TemplateField $field): bool => $field->template_role_id === $role->getKey()
                    && $field->type === FieldType::Signature
                    && $field->required,
            );

            if (! $hasSignature) {
                return 'O envio automático exige um campo de assinatura obrigatório para cada participante que assina. Falta para "'.$role->name.'" no modelo.';
            }
        }

        return null;
    }

    /**
     * Pendências que impedem o formulário de receber envios agora.
     *
     * @return list<array{code: string, message: string}>
     */
    public function issues(PublicForm $form): array
    {
        $form->loadMissing(['template', 'templateVersion.variables', 'templateVersion.roles', 'templateVersion.fields', 'organization']);

        $template = $form->template;
        $issues = [];

        if (! $template->isUsable()) {
            return [['code' => 'template_unavailable', 'message' => 'O modelo deste formulário foi arquivado. Restaure o modelo ou revogue o formulário.']];
        }

        if ((int) $template->current_version_id !== (int) $form->template_version_id) {
            $issues[] = ['code' => 'template_changed', 'message' => 'O modelo ganhou uma versão nova. Revise a configuração e salve o formulário para usá-la.'];
        }

        $version = $form->templateVersion;

        try {
            $this->build($form, $version, $this->configInput($form), checkExpiry: false);
        } catch (ValidationException $exception) {
            $first = collect($exception->errors())->flatten()->first();
            $issues[] = ['code' => 'incomplete', 'message' => 'A configuração está incompleta: '.(is_string($first) ? $first : 'revise os campos.')];
        }

        if ($version->usesNonSignerRoles() && ! DomainFeatures::participantRoles($form->organization)) {
            $issues[] = ['code' => 'participant_roles_off', 'message' => 'O modelo tem testemunha, aprovador ou visualizador, e esses papéis não estão disponíveis para esta organização.'];
        }

        if (! $this->responsibleCanGenerate($form)) {
            $issues[] = ['code' => 'responsible', 'message' => 'A pessoa responsável pelo formulário não tem mais permissão para criar e enviar documentos. Salve o formulário com um usuário que tenha.'];
        }

        return $issues;
    }

    /**
     * O responsável (em nome de quem os documentos são criados) continua membro ativo com
     * permissão para criar — e, no envio automático, para enviar.
     */
    public function responsibleCanGenerate(PublicForm $form): bool
    {
        $user = $form->responsibleUser;

        if ($user === null) {
            return false;
        }

        $membership = $user->membershipFor((int) $form->organization_id);

        if ($membership === null || $membership->status !== MembershipStatus::Active) {
            return false;
        }

        if (! $membership->hasPermission(Permission::CreateEnvelopes)) {
            return false;
        }

        return $form->destination !== PublicFormDestination::AutoSend || $membership->hasPermission(Permission::SendEnvelopes);
    }

    /**
     * A configuração gravada, no formato que `build()` recebe.
     *
     * @return array<string, mixed>
     */
    public function configInput(PublicForm $form): array
    {
        return [
            'title' => $form->title,
            'instructions' => $form->instructions,
            'envelope_title' => $form->settings['envelope_title'] ?? null,
            'destination' => $form->destination->value,
            'submissions_limit' => $form->submissionsLimit(),
            'submissions_period' => $form->submissionsPeriod()->value,
            'expires_at' => null,
            'public_variables' => $form->publicVariables(),
            'fixed_values' => $form->fixedValues(),
            'filler_role' => $form->fillerRole(),
            'fixed_participants' => $form->fixedParticipants(),
        ];
    }
}
