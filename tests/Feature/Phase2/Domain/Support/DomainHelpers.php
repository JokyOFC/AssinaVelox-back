<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da Fase 2 — domínio (B-DOM): vários documentos e papéis
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Enums\AccessLinkPurpose;
use App\Enums\ActorType;
use App\Enums\AuthMethod;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Enums\SignatureKind;
use App\Enums\SigningOrder;
use App\Models\AcceptanceDocument;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Models\User;
use App\Services\Pdf\PdfToolClient;
use App\Services\Signing\SignerTokens;
use Database\Factories\DocumentVersionFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Pdf\Support\PdfFixtures;

if (! function_exists('domainEnableFlags')) {
    /**
     * Liga as flags da onda A para a organização: configuração global E plano vigente.
     */
    function domainEnableFlags(Organization $organization, bool $multiDocument = true, bool $roles = true, int $maxDocuments = 10): void
    {
        config()->set('assinavelox.features.multi_document', $multiDocument);
        config()->set('assinavelox.features.participant_roles', $roles);
        config()->set('assinavelox.multi_document.max_documents', $maxDocuments);

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);
        $features['multi_document'] = $multiDocument;
        $features['participant_roles'] = $roles;
        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('domainEnvelope')) {
    /**
     * Envelope com N documentos (bytes mínimos no disco — o fluxo público só os transmite),
     * destinatários com papel, campos por documento e links de convite com token conhecido.
     *
     * Por padrão o envelope sai ENVIADO (`in_progress`, versões congeladas por documento);
     * com `$sent = false` fica em rascunho, sem links.
     *
     * @param  list<string>  $documents  nomes dos arquivos, na ordem
     * @param  list<array{name: string, email: string, role?: RecipientRole, order?: int, status?: RecipientStatus, fields?: list<array{doc: int, type: FieldType, required?: bool, label?: string|null, page?: int}>}>  $recipients
     * @return array{organization: Organization, owner: User, envelope: Envelope, documents: list<Document>, versions: list<DocumentVersion>, recipients: array<string, Recipient>, tokens: array<string, string>, fields: array<string, SigningField>}
     */
    function domainEnvelope(
        array $documents,
        array $recipients,
        SigningOrder $order = SigningOrder::Parallel,
        ?Organization $organization = null,
        ?User $owner = null,
        bool $sent = true,
    ): array {
        if ($organization === null || $owner === null) {
            ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
        }

        $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create([
            'title' => 'Contrato de locação com anexos',
            'signing_order' => $order,
            'terms_version' => 'v1-2026-09-08',
        ]);

        $documentModels = [];
        $versions = [];

        foreach (array_values($documents) as $index => $name) {
            $document = Document::factory()->forEnvelope($envelope)->create([
                'position' => $index + 1,
                'name' => $name,
                'original_filename' => $name.'.pdf',
                'processing_status' => DocumentProcessingStatus::Ready,
                'page_count' => 2,
            ]);

            $bytes = signerPdfBytes($envelope->ulid.'-'.$name);
            $versionUlid = (string) Str::ulid();
            $path = sprintf('orgs/%s/envelopes/%s/%s.pdf', $organization->ulid, $envelope->ulid, $versionUlid);

            Storage::disk('documents')->put($path, $bytes);

            $version = DocumentVersion::factory()->forDocument($document)->create([
                'ulid' => $versionUlid,
                'kind' => DocumentVersionKind::Original,
                'storage_path' => $path,
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'page_count' => 2,
                'pages_meta' => DocumentVersionFactory::pagesMeta(2),
            ]);

            $document->forceFill(['current_version_id' => $version->id])->save();

            $documentModels[] = $document->fresh();
            $versions[] = $version;
        }

        $models = [];
        $tokens = [];
        $fields = [];
        $turn = 0;

        foreach ($recipients as $spec) {
            $role = $spec['role'] ?? RecipientRole::Signer;

            if ($role->participates()) {
                $turn++;
            }

            $recipientOrder = $spec['order'] ?? ($role->participates()
                ? ($order === SigningOrder::Sequential ? $turn : 1)
                : 0);

            $status = $spec['status'] ?? (! $sent
                ? RecipientStatus::Pending
                : ($recipientOrder <= 1 ? RecipientStatus::Notified : RecipientStatus::Pending));

            $recipient = Recipient::factory()->forEnvelope($envelope, $recipientOrder)->create([
                'name' => $spec['name'],
                'email' => $spec['email'],
                'role' => $role,
                'status' => $status,
                'notification_count' => $status === RecipientStatus::Pending ? 0 : 1,
                'last_notified_at' => $status === RecipientStatus::Pending ? null : now()->subDay(),
            ]);

            foreach ($spec['fields'] ?? [] as $position => $definition) {
                $field = SigningField::factory()->create([
                    'envelope_id' => $envelope->id,
                    'document_version_id' => $versions[$definition['doc']]->id,
                    'recipient_id' => $recipient->id,
                    'organization_id' => $organization->id,
                    'type' => $definition['type'],
                    'page' => $definition['page'] ?? 1,
                    'x' => 0.1,
                    'y' => 0.1 + ($position * 0.1),
                    'width' => 0.3,
                    'height' => 0.06,
                    'required' => $definition['required'] ?? true,
                    'label' => $definition['label'] ?? null,
                    'sort_order' => $position,
                ]);

                $fields[$spec['email'].':'.$definition['doc'].':'.$definition['type']->value] = $field;
            }

            if ($sent) {
                $raw = SignerTokens::generate();

                RecipientAccessLink::query()->create([
                    'recipient_id' => $recipient->id,
                    'envelope_id' => $envelope->id,
                    'document_version_id' => $versions[0]->id,
                    'organization_id' => $organization->id,
                    'token_digest' => SignerTokens::digest($raw),
                    'purpose' => AccessLinkPurpose::Signing,
                    'expires_at' => now()->addDays(10),
                ]);

                $tokens[$spec['email']] = $raw;
            }

            $models[$spec['email']] = $recipient;
        }

        if ($sent) {
            // O congelamento POR documento acontece junto com o do envelope (Envelope::saved).
            $envelope->forceFill([
                'status' => EnvelopeStatus::InProgress,
                'sent_at' => now()->subDay(),
                'expires_at' => now()->addDays(10),
                'sent_document_version_id' => $versions[0]->id,
                'verification_code' => Envelope::generateVerificationCode(),
                'current_order' => 1,
            ])->save();
        }

        return [
            'organization' => $organization,
            'owner' => $owner,
            'envelope' => $envelope->fresh(),
            'documents' => array_map(fn (Document $document): Document => $document->fresh(), $documentModels),
            'versions' => $versions,
            'recipients' => $models,
            'tokens' => $tokens,
            'fields' => $fields,
        ];
    }
}

if (! function_exists('domainAuthenticate')) {
    /**
     * Confirma o código por e-mail e devolve as props da tela seguinte (`sign` ou `view`),
     * SEM buscar nenhum documento.
     *
     * @return array<string, mixed>
     */
    function domainAuthenticate(object $test, string $token): array
    {
        $test->post(route('sign.otp.send', ['token' => $token]))->assertRedirect();

        $codes = $test->codes;

        $test->post(route('sign.otp.verify', ['token' => $token]), [
            'code' => $codes[count($codes) - 1],
        ])->assertRedirect();

        return $test->get(route('sign.show', ['token' => $token]))->viewData('page')['props'];
    }
}

if (! function_exists('domainPresent')) {
    /**
     * Busca os PDFs dos documentos indicados (índices em `props.documents`); todos por padrão.
     *
     * @param  array<string, mixed>  $props
     * @param  list<int>|null  $only
     */
    function domainPresent(object $test, array $props, ?array $only = null): void
    {
        foreach ($props['documents'] as $index => $document) {
            if ($only !== null && ! in_array($index, $only, true)) {
                continue;
            }

            $test->get($document['pdf_url'])->assertOk();
        }
    }
}

if (! function_exists('domainAccept')) {
    /**
     * POST do aceite com assinatura desenhada (ou sem, para o aprovador).
     *
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $fields
     */
    function domainAccept(object $test, string $token, array $props, array $fields = [], bool $withSignature = true): mixed
    {
        $payload = [
            'authorization' => $props['authorization']['token'],
            'consent' => true,
            'fields' => $fields,
        ];

        if ($withSignature) {
            $payload['signature'] = ['method' => 'draw', 'image_base64' => pngDataUri()];
        }

        return $test->post(route('sign.complete', ['token' => $token]), $payload);
    }
}

if (! function_exists('domainFinalizingEnvelope')) {
    /**
     * Envelope em `finalizing` com N documentos REAIS no disco (PDF do DOMPDF, `pages_meta`
     * do `pdftool inspect`), dois signatários com aceite gravado cobrindo o conjunto e campos
     * em documentos diferentes. É o estado em que o último aceite entrega o envelope à
     * finalização.
     *
     * @param  list<string>  $names
     * @return array{organization: Organization, envelope: Envelope, documents: list<Document>, versions: list<DocumentVersion>, sources: list<string>}
     */
    function domainFinalizingEnvelope(string $workspace, array $names = ['Contrato', 'Anexo A', 'Anexo B']): array
    {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Consultoria']);

        $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create([
            'title' => 'Contrato com anexos',
            'signing_order' => SigningOrder::Parallel,
            'terms_version' => 'v1-2026-09-08',
        ]);

        $documents = [];
        $versions = [];
        $sources = [];

        foreach ($names as $index => $name) {
            $source = $index === 0
                ? PdfFixtures::twoPagePdf($workspace.DIRECTORY_SEPARATOR.'doc-'.$index.'.pdf')
                : PdfFixtures::onePagePdf($workspace.DIRECTORY_SEPARATOR.'doc-'.$index.'.pdf', $name);

            $inspection = app(PdfToolClient::class)->inspect($source);
            $bytes = (string) file_get_contents($source);

            $document = Document::factory()->forEnvelope($envelope)->create([
                'position' => $index + 1,
                'name' => $name,
                'original_filename' => $name.'.pdf',
                'processing_status' => DocumentProcessingStatus::Ready,
            ]);

            $versionUlid = (string) Str::ulid();
            $path = sprintf('orgs/%s/envelopes/%s/%s.pdf', $organization->ulid, $envelope->ulid, $versionUlid);
            Storage::disk('documents')->put($path, $bytes);

            $version = DocumentVersion::factory()->forDocument($document)->create([
                'ulid' => $versionUlid,
                'version_number' => 1,
                'kind' => DocumentVersionKind::Original,
                'storage_path' => $path,
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'page_count' => $inspection->pageCount,
                'pages_meta' => $inspection->pagesMeta(),
                'created_by_type' => ActorType::User,
                'created_by_id' => $owner->getKey(),
            ]);

            $document->forceFill(['current_version_id' => $version->getKey(), 'page_count' => $inspection->pageCount])->save();

            $documents[] = $document;
            $versions[] = $version;
            $sources[] = $source;
        }

        $envelope->forceFill([
            'status' => EnvelopeStatus::Finalizing,
            'sent_at' => now()->subDays(2),
            'expires_at' => now()->addDays(10),
            'sent_document_version_id' => $versions[0]->getKey(),
            'verification_code' => Envelope::generateVerificationCode(),
            'finalization_key' => (string) Str::ulid(),
            'current_order' => 1,
        ])->save();

        $people = [
            ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'text' => 'M-2291'],
            ['name' => 'Henrique Dias', 'email' => 'henrique@exemplo.test', 'text' => 'H-8814'],
        ];

        foreach ($people as $index => $person) {
            $recipient = Recipient::factory()->forEnvelope($envelope, 1)->create([
                'name' => $person['name'],
                'email' => $person['email'],
                'status' => RecipientStatus::Signed,
                'signed_at' => now()->subHour(),
                'notification_count' => 1,
            ]);

            $signature = SigningField::factory()->create([
                'envelope_id' => $envelope->getKey(),
                'document_version_id' => $versions[0]->getKey(),
                'recipient_id' => $recipient->getKey(),
                'organization_id' => $organization->getKey(),
                'type' => FieldType::Signature,
                'page' => 1,
                'x' => 0.10, 'y' => 0.30 + ($index * 0.25), 'width' => 0.30, 'height' => 0.08,
            ]);

            $last = count($versions) - 1;

            $text = SigningField::factory()->create([
                'envelope_id' => $envelope->getKey(),
                'document_version_id' => $versions[$last]->getKey(),
                'recipient_id' => $recipient->getKey(),
                'organization_id' => $organization->getKey(),
                'type' => FieldType::Text,
                'page' => 1,
                'x' => 0.10, 'y' => 0.30 + ($index * 0.25), 'width' => 0.40, 'height' => 0.05,
                'label' => 'Matrícula',
            ]);

            $imagePath = sprintf('orgs/%s/envelopes/%s/signatures/signature-%s.png', $organization->ulid, $envelope->ulid, (string) Str::ulid());
            Storage::disk('documents')->put($imagePath, finalizationSignaturePng());

            $acceptance = SignatureAcceptance::query()->create([
                'recipient_id' => $recipient->getKey(),
                'envelope_id' => $envelope->getKey(),
                'document_version_id' => $versions[0]->getKey(),
                'organization_id' => $organization->getKey(),
                'accepted_at' => now()->subHour(),
                'ip_address' => '203.0.113.'.(10 + $index),
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
                'auth_method' => AuthMethod::EmailOtp,
                'terms_version' => 'v1-multi-2026-09-11',
                'consent_statement' => 'Declaração de aceite eletrônico — versão v1-multi-2026-09-11',
                'document_sha256' => $versions[0]->sha256,
                'fields_snapshot' => ['schema' => 2, 'fields' => [], 'values' => []],
                'signature_kind' => SignatureKind::Drawn,
                'signature_image_path' => $imagePath,
            ]);

            SigningFieldValue::query()->create([
                'signing_field_id' => $signature->getKey(),
                'recipient_id' => $recipient->getKey(),
                'signature_acceptance_id' => $acceptance->getKey(),
                'envelope_id' => $envelope->getKey(),
                'organization_id' => $organization->getKey(),
                'image_path' => $imagePath,
            ]);

            SigningFieldValue::query()->create([
                'signing_field_id' => $text->getKey(),
                'recipient_id' => $recipient->getKey(),
                'signature_acceptance_id' => $acceptance->getKey(),
                'envelope_id' => $envelope->getKey(),
                'organization_id' => $organization->getKey(),
                'value_text' => $person['text'],
            ]);

            foreach ($documents as $position => $document) {
                AcceptanceDocument::query()->create([
                    'signature_acceptance_id' => $acceptance->getKey(),
                    'document_id' => $document->getKey(),
                    'document_version_id' => $versions[$position]->getKey(),
                    'organization_id' => $organization->getKey(),
                    'position' => $position + 1,
                    'document_sha256' => $versions[$position]->sha256,
                    'fields_snapshot' => ['fields' => [], 'values' => []],
                ]);
            }

            AuditEvent::query()->create([
                'organization_id' => $organization->getKey(),
                'envelope_id' => $envelope->getKey(),
                'recipient_id' => $recipient->getKey(),
                'actor_type' => ActorType::Recipient,
                'actor_id' => $recipient->getKey(),
                'event_type' => 'acceptance.recorded',
                'payload' => ['documents' => count($documents)],
                'occurred_at' => now()->subHour(),
            ]);
        }

        return [
            'organization' => $organization,
            'envelope' => $envelope->fresh(),
            'documents' => array_map(fn (Document $document): Document => $document->fresh(), $documents),
            'versions' => $versions,
            'sources' => $sources,
        ];
    }
}

if (! function_exists('domainVersionsOfKind')) {
    /**
     * @return Collection<int, DocumentVersion>
     */
    function domainVersionsOfKind(Document $document, DocumentVersionKind $kind): Collection
    {
        return DocumentVersion::withoutOrganizationScope()
            ->where('document_id', $document->getKey())
            ->where('kind', $kind->value)
            ->get();
    }
}
