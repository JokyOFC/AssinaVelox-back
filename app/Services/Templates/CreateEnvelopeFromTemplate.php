<?php

namespace App\Services\Templates;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentSourceType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientRole;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\Template;
use App\Models\TemplateField;
use App\Models\TemplateRole;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\Anchors\TemplateAnchorRules;
use App\Services\Documents\DocumentAuditTrail;
use App\Services\Documents\DocumentIntake;
use App\Services\Documents\DocumentStorage;
use App\Services\Documents\Exceptions\UploadRejectedException;
use App\Services\Documents\UploadInspector;
use App\Services\Envelopes\DomainFeatures;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Envelopes\EnvelopeReadiness;
use App\Services\Envelopes\FieldSync;
use App\Services\Envelopes\RecipientSync;
use App\Services\Pdf\Support\TemporaryDirectory;
use App\Support\OrganizationSettings;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Usar modelo" (docs/fase-2/modelos.md §5): cria um envelope em RASCUNHO a partir da
 * versão atual do modelo, com os valores das variáveis e os participantes de cada papel.
 *
 * O envelope é uma CÓPIA: documento gerado agora, destinatários e campos gravados como os
 * de qualquer rascunho. Editar o modelo depois não altera nada aqui; o vínculo com a versão
 * usada fica em `template_usages` e na trilha (`template.used`).
 *
 * Tudo que o navegador manda é revalidado antes de criar qualquer coisa. O preparo reusa os
 * serviços do wizard — `DocumentIntake` (inspeção e conversão), `RecipientSync` (papéis e
 * flag `participant_roles`) e `FieldSync` (geometria contra `pages_meta`) —, então um
 * envelope gerado obedece exatamente às mesmas regras de um montado à mão.
 *
 * Documento por fonte:
 *  - PDF fixo: os bytes do modelo (conferidos pelo sha256) viram a versão original e
 *    exibível — já inspecionados no cadastro do modelo, como na duplicação de envelope.
 *    Assim os campos são gravados na mesma hora, nas posições do modelo;
 *  - HTML: PDF gerado pelo DOMPDF → `DocumentIntake` (pipeline normal do PDF);
 *  - DOCX: DOCX preenchido → `DocumentIntake` → conversão pelo LibreOffice. Sem LibreOffice
 *    configurado o documento fica `failed` com a mensagem honesta do pipeline.
 */
final class CreateEnvelopeFromTemplate
{
    public function __construct(
        private readonly VariableValues $values,
        private readonly TemplateDocumentRenderer $renderer,
        private readonly TemplateStorage $templateStorage,
        private readonly DocumentStorage $documents,
        private readonly DocumentIntake $intake,
        private readonly DocumentAuditTrail $documentAudit,
        private readonly UploadInspector $inspector,
    ) {}

    /**
     * @param  array{title?: string|null, values?: array<string, mixed>|null, participants?: array<string, mixed>|null}  $input
     * @param  TemplateVersion|null  $pinnedVersion  Fase 3 §3.1 (geração em lote): versão FIXADA no lote em vez da atual
     * @param  array<string, scalar>  $auditContext  Fase 3 §3.1: acrescentado ao payload de `envelope.created` e `template.used`
     *
     * @throws ValidationException
     */
    public function handle(Template $template, User $user, array $input, ?Request $request = null, ?TemplateVersion $pinnedVersion = null, array $auditContext = []): Envelope
    {
        if ($pinnedVersion !== null && (int) $pinnedVersion->template_id !== (int) $template->getKey()) {
            throw ValidationException::withMessages(['template' => 'Esta versão não pertence ao modelo.']);
        }

        $version = $pinnedVersion !== null
            ? $pinnedVersion->loadMissing(['variables', 'roles', 'fields.role'])
            : $template->currentVersion()->with(['variables', 'roles', 'fields.role'])->first();

        if (! $template->isUsable() || ! $version instanceof TemplateVersion) {
            throw ValidationException::withMessages(['template' => 'Este modelo não está disponível para uso.']);
        }

        $organization = $template->organization;

        if ($version->usesNonSignerRoles() && ! DomainFeatures::participantRoles($organization)) {
            throw ValidationException::withMessages([
                'template' => 'Este modelo tem testemunha, aprovador ou visualizador, e esses papéis não estão disponíveis para esta organização.',
            ]);
        }

        $title = trim((string) ($input['title'] ?? '')) ?: $template->name;

        if (mb_strlen($title) > 160) {
            throw ValidationException::withMessages(['title' => 'O título aceita no máximo 160 caracteres.']);
        }

        $normalized = $this->values->validate($version->variables, is_array($input['values'] ?? null) ? $input['values'] : []);
        $participants = $this->participants($version, is_array($input['participants'] ?? null) ? $input['participants'] : []);

        $formatted = $this->renderer->formatted($version, $normalized);
        $envelope = null;

        try {
            return DB::transaction(function () use ($template, $version, $user, $title, $participants, $formatted, $request, $auditContext, &$envelope): Envelope {
                $envelope = $this->newEnvelope($template, $user, $title);

                EnvelopeAudit::record($envelope, AuditEventType::EnvelopeCreated, [
                    'template' => $template->ulid,
                    'template_version' => $version->ulid,
                ] + $auditContext);

                $this->document($envelope, $version, $user, $title, $formatted, $request);

                $recipients = $this->recipients($envelope, $version, $participants);

                if ($version->fields->isNotEmpty()) {
                    $this->fields($envelope, $version, $recipients);
                }

                DB::table('template_usages')->insert([
                    'organization_id' => $template->organization_id,
                    'template_id' => $template->getKey(),
                    'template_version_id' => $version->getKey(),
                    'envelope_id' => $envelope->getKey(),
                    'created_by_user_id' => $user->getKey(),
                    'created_at' => Carbon::now(),
                ]);

                TemplateAudit::record($template, AuditEventType::TemplateUsed, [
                    'version' => $version->version_number,
                    'template_version' => $version->ulid,
                    'variables' => count($formatted),
                    'participants' => count($participants),
                ] + $auditContext, $envelope);

                // Fase 3 §3.2 (F-ANCHOR): regras de âncora do modelo → busca agendada após o
                // commit; o resultado são SUGESTÕES revisadas no editor. Flag desligada: nada.
                app(TemplateAnchorRules::class)->onTemplateUsed($template, $version, $envelope, $user);

                EnvelopeReadiness::refresh($envelope);

                return $envelope;
            });
        } catch (Throwable $exception) {
            // A transação desfez os registros; os bytes gravados na pasta do envelope saem aqui.
            if ($envelope instanceof Envelope) {
                try {
                    $this->documents->disk()->deleteDirectory(dirname($this->documents->pathFor($envelope, 'x', 'pdf')));
                } catch (Throwable) {
                    // Órfão registrado pela rotina de retenção; nunca mascara o erro original.
                }
            }

            if ($exception instanceof TemplateRejectedException) {
                throw ValidationException::withMessages(['template' => $exception->getMessage()]);
            }

            if ($exception instanceof UploadRejectedException) {
                throw ValidationException::withMessages(['template' => $exception->getMessage()]);
            }

            throw $exception;
        }
    }

    // -- Validação --------------------------------------------------------------------

    /**
     * Um participante (nome + e-mail) por papel, com as mesmas regras do passo 2 do wizard.
     *
     * @param  array<string, mixed>  $input  por ULID do papel
     * @return array<string, array{name: string, email: string, phone?: string}>
     *
     * @throws ValidationException
     */
    private function participants(TemplateVersion $version, array $input): array
    {
        $data = [];
        $rules = [];
        $attributes = [];

        foreach ($version->roles as $role) {
            $row = is_array($input[$role->ulid] ?? null) ? $input[$role->ulid] : [];
            $data[$role->ulid] = [
                'name' => is_scalar($row['name'] ?? null) ? trim((string) $row['name']) : '',
                'email' => is_scalar($row['email'] ?? null) ? mb_strtolower(trim((string) $row['email'])) : '',
            ];

            // Fase 3 §3.1: celular opcional (geração em lote com o canal SMS/WhatsApp ligado). O
            // RecipientChannels do RecipientSync valida o E.164; sem a chave, nada muda.
            if (is_scalar($row['phone'] ?? null) && trim((string) $row['phone']) !== '') {
                $data[$role->ulid]['phone'] = trim((string) $row['phone']);
            }

            $rules["participants.{$role->ulid}.name"] = ['required', 'string', 'min:2', 'max:120'];
            $rules["participants.{$role->ulid}.email"] = ['required', 'string', 'email:rfc', 'max:255'];
            $attributes["participants.{$role->ulid}.name"] = 'nome de '.$role->name;
            $attributes["participants.{$role->ulid}.email"] = 'e-mail de '.$role->name;
        }

        Validator::make(['participants' => $data], $rules, [], $attributes)->validate();

        $seen = [];

        foreach ($data as $ulid => $row) {
            if (isset($seen[$row['email']])) {
                throw ValidationException::withMessages([
                    "participants.{$ulid}.email" => 'Este e-mail já está em outro participante deste documento.',
                ]);
            }

            $seen[$row['email']] = true;
        }

        return $data;
    }

    // -- Criação ----------------------------------------------------------------------

    private function newEnvelope(Template $template, User $user, string $title): Envelope
    {
        $settings = OrganizationSettings::of($template->organization);

        // Mesmos padrões de EnvelopeController::create (Nova solicitação).
        return Envelope::query()->create([
            'organization_id' => $template->organization_id,
            'created_by_user_id' => $user->getKey(),
            'title' => $title,
            'status' => EnvelopeStatus::Draft,
            'signing_order' => $settings->defaultSigningOrder(),
            'terms_version' => (string) config('assinavelox.terms_version'),
            'settings' => [
                'otp_required' => true,
                'expiration_days' => $settings->defaultExpirationDays(),
                'initials_on_all_pages' => $settings->initialsOnAllPages(),
                'send_copy_to_all' => false,
            ],
        ]);
    }

    /**
     * @param  array<string, string>  $formatted
     *
     * @throws TemplateRejectedException|UploadRejectedException
     */
    private function document(Envelope $envelope, TemplateVersion $version, User $user, string $title, array $formatted, ?Request $request): void
    {
        $directory = $this->templateStorage->temporaryDirectory();

        try {
            match ($version->source_type) {
                TemplateSourceType::Pdf => $this->materializePdf($envelope, $version, $user, $title, $directory),
                TemplateSourceType::Html => $this->intake->store(
                    $envelope,
                    $this->upload($this->writeBytes($directory->path('documento.pdf'), $this->renderer->htmlPdf($version, $formatted, $title)), $title, 'pdf', 'application/pdf'),
                    $user,
                    $request,
                ),
                TemplateSourceType::Docx => $this->intake->store(
                    $envelope,
                    $this->upload(
                        $this->renderer->filledDocx($version, $formatted, $directory),
                        $title,
                        'docx',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ),
                    $user,
                    $request,
                ),
            };
        } finally {
            $directory->delete();
        }
    }

    /**
     * PDF fixo: cópia dos bytes do modelo como documento `ready` (sem reprocessar), com as
     * páginas conhecidas no cadastro — é o que permite gravar os campos agora.
     */
    private function materializePdf(Envelope $envelope, TemplateVersion $version, User $user, string $title, TemporaryDirectory $directory): void
    {
        $local = $this->templateStorage->copyToTemporary($version, $directory, 'modelo.pdf');

        $versionUlid = $this->documents->newVersionUlid();
        $path = $this->documents->pathFor($envelope, $versionUlid, 'pdf');
        $this->documents->putFile($local, $path);

        $filename = $this->inspector->sanitizeFilename($title.'.pdf', 'pdf');

        $document = Document::query()->create([
            'envelope_id' => $envelope->getKey(),
            'organization_id' => $envelope->organization_id,
            'position' => 1,
            'name' => mb_substr((string) pathinfo($filename, PATHINFO_FILENAME), 0, 200) ?: 'Documento',
            'original_filename' => $filename,
            'source_type' => DocumentSourceType::Pdf,
            'processing_status' => DocumentProcessingStatus::Ready,
            'page_count' => $version->page_count,
        ]);

        $documentVersion = new DocumentVersion;
        $documentVersion->forceFill([
            'ulid' => $versionUlid,
            'document_id' => $document->getKey(),
            'organization_id' => $envelope->organization_id,
            'version_number' => 1,
            'kind' => DocumentVersionKind::Original,
            'storage_disk' => DocumentStorage::DISK,
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => (int) filesize($local),
            'sha256' => (string) $version->sha256,
            'page_count' => $version->page_count,
            'pages_meta' => $version->pages_meta,
            'is_encrypted' => false,
            'has_signatures' => false,
            'created_by_type' => ActorType::User,
            'created_by_id' => $user->getKey(),
        ])->save();

        $document->forceFill(['current_version_id' => $documentVersion->getKey()])->save();

        if ($envelope->status === EnvelopeStatus::Draft) {
            $envelope->forceFill(['status' => EnvelopeStatus::Preparing])->save();
        }

        $this->documentAudit->record($envelope, AuditEventType::DocumentUploaded, [
            'document_ulid' => $document->ulid,
            'document_version_ulid' => $documentVersion->ulid,
            'original_filename' => $filename,
            'source_type' => DocumentSourceType::Pdf->value,
            'mime_type' => 'application/pdf',
            'size_bytes' => $documentVersion->size_bytes,
            'sha256' => $documentVersion->sha256,
            'template_version' => $version->ulid,
        ], $user);
    }

    /**
     * @param  array<string, array{name: string, email: string, phone?: string}>  $participants
     * @return array<int, Recipient> destinatário por id do papel do modelo
     */
    private function recipients(Envelope $envelope, TemplateVersion $version, array $participants): array
    {
        $rows = [];

        foreach ($version->roles as $position => $role) {
            $rows[] = [
                'name' => $participants[$role->ulid]['name'],
                'email' => $participants[$role->ulid]['email'],
                'role' => $role->name,
                'participant_role' => $role->participant_role->value,
                'order' => $position + 1,
            ] + (isset($participants[$role->ulid]['phone']) ? ['phone' => $participants[$role->ulid]['phone']] : []);
        }

        app(RecipientSync::class)->handle($envelope, [
            'signing_order' => ($version->signingOrder() ?? $envelope->signing_order)->value,
            'recipients' => $rows,
        ]);

        $byEmail = Recipient::query()->where('envelope_id', $envelope->getKey())->get()->keyBy('email');
        $map = [];

        foreach ($version->roles as $role) {
            /** @var TemplateRole $role */
            $map[$role->getKey()] = $byEmail->get($participants[$role->ulid]['email']);
        }

        return array_filter($map);
    }

    /**
     * Campos do modelo → FieldSync, nas MESMAS frações de página (o documento é o próprio
     * PDF do modelo, com as mesmas `pages_meta`).
     *
     * @param  array<int, Recipient>  $recipients
     */
    private function fields(Envelope $envelope, TemplateVersion $version, array $recipients): void
    {
        $rows = [];

        foreach ($version->fields as $field) {
            /** @var TemplateField $field */
            $recipient = $recipients[$field->template_role_id] ?? null;

            if ($recipient === null || $recipient->role === RecipientRole::Viewer) {
                continue;
            }

            $rows[] = [
                'recipient_id' => $recipient->ulid,
                'type' => $field->type->value,
                'page' => $field->page,
                'x' => (float) $field->x,
                'y' => (float) $field->y,
                'w' => (float) $field->width,
                'h' => (float) $field->height,
                'required' => $field->required,
                'label' => $field->label,
                'options' => $field->options ?? [],
            ];
        }

        app(FieldSync::class)->handle($envelope, [
            'initials_on_all_pages' => (bool) $envelope->setting('initials_on_all_pages', false),
            'fields' => $rows,
        ]);
    }

    private function writeBytes(string $path, string $bytes): string
    {
        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw TemplateRejectedException::make('render_failed', 'Não foi possível gerar o documento a partir do modelo. Tente novamente.');
        }

        return $path;
    }

    private function upload(string $path, string $title, string $extension, string $mime): UploadedFile
    {
        // Modo teste (`true`): o arquivo foi gerado aqui, não veio de um upload HTTP. O
        // DocumentIntake inspeciona o CONTEÚDO do mesmo jeito.
        return new UploadedFile($path, $this->inspector->sanitizeFilename($title.'.'.$extension, $extension), $mime, null, true);
    }
}
