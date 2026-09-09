<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes de finalização (B-FINAL)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
|
| Monta um envelope em `finalizing` com bytes REAIS no disco (PDF gerado com DOMPDF),
| aceites gravados e valores de campo — o estado exato em que o incremento 3 entrega o
| envelope para o incremento 4. Nada aqui simula o pdftool: os testes rodam contra a
| ferramenta Python de verdade.
*/

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\AuthMethod;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientStatus;
use App\Enums\SignatureKind;
use App\Enums\SigningOrder;
use App\Jobs\Envelopes\FinalizeEnvelope;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Models\User;
use App\Services\Envelopes\FieldGeometry;
use App\Services\Envelopes\PageBox;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\Pdf\Support\PdfFixtures;

if (! function_exists('finalizationEnableCompanySignature')) {
    /**
     * Liga `features.company_signature` no plano vigente da organização.
     *
     * A assinatura criptográfica da operadora depende de DUAS coisas: certificado
     * configurado e plano que inclua o item (`EnvelopeFinalizer::signsFor()`). Os cenários
     * de finalização assinada medem o certificado — o plano só não pode atrapalhar. Mexe no
     * plano que a organização já tem (o banco é recriado a cada teste), sem trocar a
     * assinatura de plano e sem alterar cotas.
     */
    function finalizationEnableCompanySignature(Organization $organization): void
    {
        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);

        if (($features['company_signature'] ?? false) === true) {
            return;
        }

        $features['company_signature'] = true;
        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('finalizationDisk')) {
    /**
     * Disco `documents` com raiz exclusiva do teste (raiz compartilhada com Storage::fake
     * falha de forma intermitente no Windows).
     */
    function finalizationDisk(string $root): void
    {
        config()->set('filesystems.disks.documents', [
            'driver' => 'local',
            'root' => $root,
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ]);

        Storage::forgetDisk('documents');
    }
}

if (! function_exists('finalizationProbe')) {
    /**
     * Executa `tests/Feature/Finalization/Support/pdf_probe.py` com o interpretador do venv
     * do pdftool (que já traz o pypdf). Ferramenta de teste, não da aplicação.
     *
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    function finalizationProbe(array $args): array
    {
        $script = base_path('tests/Feature/Finalization/Support/pdf_probe.py');

        $process = new Process(
            [PdfFixtures::pythonBinary(), $script, ...$args],
            base_path('tools/pdftool'),
            ['PYTHONUTF8' => '1', 'PYTHONIOENCODING' => 'utf-8'],
            null,
            120,
        );

        $process->run();

        $decoded = json_decode(trim($process->getOutput()), true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'pdf_probe não devolveu JSON (exit %s): %s',
                $process->getExitCode() ?? '?',
                mb_substr($process->getErrorOutput(), 0, 800),
            ));
        }

        if (($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException('pdf_probe falhou: '.(string) ($decoded['error'] ?? 'sem mensagem'));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

if (! function_exists('finalizationPdfPages')) {
    /**
     * Páginas do PDF com boxes, rotação, texto e a posição de cada trecho no espaço do
     * usuário do PDF.
     *
     * @return list<array<string, mixed>>
     */
    function finalizationPdfPages(string $path): array
    {
        /** @var list<array<string, mixed>> $pages */
        $pages = finalizationProbe(['text', '--in', $path])['pages'];

        return $pages;
    }
}

if (! function_exists('finalizationTextIsInsideField')) {
    /**
     * O trecho `$needle` foi desenhado dentro do retângulo do campo, na página do campo?
     *
     * Espelha exatamente o que os testes do próprio pdftool fazem: converte a geometria
     * normalizada para o espaço do usuário (`FieldGeometry::toPdfRect`, o mesmo cálculo de
     * `pdftool/geometry.py`) e confere se a origem do texto cai dentro — o que só é
     * verdade se a página, a rotação e o CropBox foram respeitados.
     *
     * @param  list<array<string, mixed>>  $pages
     */
    function finalizationTextIsInsideField(array $pages, SigningField $field, string $needle, float $tolerance = 1.0): bool
    {
        $page = $pages[$field->page - 1] ?? null;

        if ($page === null) {
            return false;
        }

        $box = PageBox::fromPageMeta([
            'rotation' => $page['rotation'],
            'mediabox' => $page['mediabox'],
            'cropbox' => $page['cropbox'],
        ]);

        [$llx, $lly, $urx, $ury] = FieldGeometry::toPdfRect(
            $box,
            (float) $field->x,
            (float) $field->y,
            (float) $field->width,
            (float) $field->height,
        );

        foreach ($page['runs'] as $run) {
            if (! str_contains((string) $run['text'], $needle)) {
                continue;
            }

            $x = (float) $run['x'];
            $y = (float) $run['y'];

            if ($x >= $llx - $tolerance && $x <= $urx + $tolerance && $y >= $lly - $tolerance && $y <= $ury + $tolerance) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('finalizationEnvelope')) {
    /**
     * Envelope em `finalizing`, pronto para o pipeline:
     *
     * - PDF real de 2 páginas no disco como `DocumentVersion(kind=original)`, com
     *   `pages_meta` vindo do `pdftool inspect` (nunca inventado);
     * - `sent_document_version_id` congelado nessa versão;
     * - campos por destinatário nas coordenadas informadas;
     * - aceite gravado (com valores de campo) para quem `accepted = true`.
     *
     * @param  list<array{name: string, email: string, accepted?: bool, refused?: bool, typed?: bool, fields?: list<array<string, mixed>>}>  $recipients
     * @return array{
     *     organization: Organization,
     *     owner: User,
     *     envelope: Envelope,
     *     document: Document,
     *     version: DocumentVersion,
     *     recipients: array<string, Recipient>,
     *     fields: array<string, SigningField>,
     *     source: string,
     * }
     */
    function finalizationEnvelope(
        string $workspace,
        array $recipients = [],
        array $envelopeAttributes = [],
        ?string $sourcePdf = null,
        ?Organization $organization = null,
        ?User $owner = null,
    ): array {
        if ($organization === null || $owner === null) {
            ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Consultoria']);
        }

        // A assinatura criptográfica da operadora é item de PLANO
        // (`plans.features.company_signature`, consultado por `EnvelopeFinalizer::signsFor()`)
        // além de depender do certificado configurado. O plano Grátis, com que toda
        // organização nasce, não inclui o item — um cenário de finalização assinada precisa
        // de uma organização em plano que o inclua, senão o teste mediria a política de
        // plano em vez do certificado.
        finalizationEnableCompanySignature($organization);

        $recipients = $recipients === [] ? [finalizationDefaultRecipient()] : $recipients;

        $source = $sourcePdf ?? PdfFixtures::twoPagePdf($workspace.DIRECTORY_SEPARATOR.'contrato.pdf');

        $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create(array_merge([
            'title' => 'Contrato de prestação de serviços',
            'signing_order' => SigningOrder::Parallel,
            'terms_version' => 'v1-2026-09-08',
        ], $envelopeAttributes));

        $document = Document::factory()->forEnvelope($envelope)->create([
            'processing_status' => DocumentProcessingStatus::Ready,
            'name' => 'contrato',
            'original_filename' => 'contrato.pdf',
        ]);

        $inspection = app(PdfToolClient::class)->inspect($source);

        $bytes = (string) file_get_contents($source);
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

        $document->forceFill([
            'current_version_id' => $version->getKey(),
            'page_count' => $inspection->pageCount,
        ])->save();

        $envelope->forceFill([
            'status' => EnvelopeStatus::Finalizing,
            'sent_at' => now()->subDays(2),
            'expires_at' => now()->addDays(10),
            'sent_document_version_id' => $version->getKey(),
            'verification_code' => Envelope::generateVerificationCode(),
            'finalization_key' => (string) Str::ulid(),
            'current_order' => 1,
        ])->save();

        // Trilha mínima do que já aconteceu antes da finalização: sem ela, a linha do tempo
        // da página de evidências não descreveria nada.
        AuditEvent::query()->create([
            'organization_id' => $organization->getKey(),
            'envelope_id' => $envelope->getKey(),
            'actor_type' => ActorType::User,
            'actor_id' => $owner->getKey(),
            'event_type' => AuditEventType::EnvelopeSent,
            'payload' => ['recipients' => count($recipients)],
            'occurred_at' => now()->subDays(2),
        ]);

        $recipientModels = [];
        $fieldModels = [];

        foreach (array_values($recipients) as $index => $spec) {
            $accepted = $spec['accepted'] ?? true;
            $refused = $spec['refused'] ?? false;

            $recipient = Recipient::factory()->forEnvelope($envelope, 1)->create([
                'name' => $spec['name'],
                'email' => $spec['email'],
                'status' => $refused
                    ? RecipientStatus::Refused
                    : ($accepted ? RecipientStatus::Signed : RecipientStatus::Notified),
                'signed_at' => $accepted && ! $refused ? now()->subHour() : null,
                'refused_at' => $refused ? now()->subHour() : null,
                'refusal_reason' => $refused ? 'Valores divergentes do combinado.' : null,
                'notification_count' => 1,
                'last_notified_at' => now()->subDay(),
            ]);

            $created = [];

            foreach ($spec['fields'] ?? [] as $position => $definition) {
                $field = SigningField::factory()->create([
                    'envelope_id' => $envelope->getKey(),
                    'document_version_id' => $version->getKey(),
                    'recipient_id' => $recipient->getKey(),
                    'organization_id' => $organization->getKey(),
                    'type' => $definition['type'],
                    'page' => $definition['page'] ?? 1,
                    'x' => $definition['x'],
                    'y' => $definition['y'],
                    'width' => $definition['width'],
                    'height' => $definition['height'],
                    'required' => $definition['required'] ?? true,
                    'label' => $definition['label'] ?? null,
                    'options' => $definition['options'] ?? null,
                    'sort_order' => $position,
                ]);

                $key = $definition['key'] ?? ($spec['email'].':'.$definition['type']->value.':'.$position);
                $created[$key] = $field;
                $fieldModels[$key] = $field;
            }

            if ($accepted && ! $refused) {
                finalizationRecordAcceptance($envelope, $recipient, $version, $created, $spec, $index);

                AuditEvent::query()->create([
                    'organization_id' => $organization->getKey(),
                    'envelope_id' => $envelope->getKey(),
                    'recipient_id' => $recipient->getKey(),
                    'actor_type' => ActorType::Recipient,
                    'actor_id' => $recipient->getKey(),
                    'event_type' => AuditEventType::AcceptanceRecorded,
                    'payload' => ['document_sha256' => $version->sha256],
                    'occurred_at' => now()->subHour(),
                ]);
            }

            $recipientModels[$spec['email']] = $recipient;
        }

        return [
            'organization' => $organization,
            'owner' => $owner,
            'envelope' => $envelope->fresh(),
            'document' => $document->fresh(),
            'version' => $version,
            'recipients' => $recipientModels,
            'fields' => $fieldModels,
            'source' => $source,
        ];
    }
}

if (! function_exists('finalizationDefaultRecipient')) {
    /**
     * Um signatário com assinatura desenhada, nome, data (página 1), texto e checkbox
     * (página 2) — cobre os cinco caminhos do `compose` de uma vez.
     *
     * @return array<string, mixed>
     */
    function finalizationDefaultRecipient(): array
    {
        return [
            'name' => 'Maria Alves Souza',
            'email' => 'maria@exemplo.test',
            'accepted' => true,
            'fields' => [
                ['key' => 'signature', 'type' => FieldType::Signature, 'page' => 1, 'x' => 0.10, 'y' => 0.60, 'width' => 0.30, 'height' => 0.08],
                ['key' => 'name', 'type' => FieldType::Name, 'page' => 1, 'x' => 0.10, 'y' => 0.70, 'width' => 0.40, 'height' => 0.04],
                ['key' => 'date', 'type' => FieldType::Date, 'page' => 1, 'x' => 0.55, 'y' => 0.70, 'width' => 0.25, 'height' => 0.04],
                ['key' => 'text', 'type' => FieldType::Text, 'page' => 2, 'x' => 0.10, 'y' => 0.30, 'width' => 0.50, 'height' => 0.04],
                ['key' => 'checkbox', 'type' => FieldType::Checkbox, 'page' => 2, 'x' => 0.10, 'y' => 0.40, 'width' => 0.03, 'height' => 0.03],
            ],
        ];
    }
}

if (! function_exists('finalizationRecordAcceptance')) {
    /**
     * Aceite eletrônico gravado como o fluxo público grava: `SignatureAcceptance` +
     * `SigningFieldValue` por campo, com a imagem normalizada já no disco `documents`.
     *
     * @param  array<string, SigningField>  $fields
     * @param  array<string, mixed>  $spec
     */
    function finalizationRecordAcceptance(
        Envelope $envelope,
        Recipient $recipient,
        DocumentVersion $version,
        array $fields,
        array $spec,
        int $index,
    ): SignatureAcceptance {
        $typed = ($spec['typed'] ?? false) === true;

        $imagePath = null;

        if (! $typed) {
            $imagePath = sprintf(
                'orgs/%s/envelopes/%s/signatures/signature-%s.png',
                $envelope->organization->ulid,
                $envelope->ulid,
                (string) Str::ulid(),
            );

            Storage::disk('documents')->put($imagePath, finalizationSignaturePng());
        }

        /** @var SignatureAcceptance $acceptance */
        $acceptance = SignatureAcceptance::query()->create([
            'recipient_id' => $recipient->getKey(),
            'envelope_id' => $envelope->getKey(),
            'document_version_id' => $version->getKey(),
            'organization_id' => $envelope->organization_id,
            'accepted_at' => now()->subHour(),
            'ip_address' => '203.0.113.'.(10 + $index),
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
            'auth_method' => AuthMethod::EmailOtp,
            'terms_version' => 'v1-2026-09-08',
            'consent_statement' => 'Declaração de aceite eletrônico — versão v1-2026-09-08',
            'document_sha256' => $version->sha256,
            'fields_snapshot' => ['fields' => [], 'values' => []],
            'signature_kind' => $typed ? SignatureKind::Typed : SignatureKind::Drawn,
            'signature_image_path' => $imagePath,
            'typed_name' => $typed ? ($spec['typed_name'] ?? $recipient->name) : null,
            'typed_font' => $typed ? 'caveat' : null,
        ]);

        foreach ($fields as $key => $field) {
            $value = match ($field->type) {
                FieldType::Signature, FieldType::Initials => ['text' => null, 'bool' => null, 'image' => $imagePath],
                FieldType::Name => ['text' => $recipient->name, 'bool' => null, 'image' => null],
                FieldType::Date => ['text' => '09/09/2026', 'bool' => null, 'image' => null],
                FieldType::Checkbox => ['text' => null, 'bool' => true, 'image' => null],
                FieldType::Text => ['text' => $spec['text_value'] ?? 'Concordo com a cláusula de reajuste', 'bool' => null, 'image' => null],
            };

            SigningFieldValue::query()->create([
                'signing_field_id' => $field->getKey(),
                'recipient_id' => $recipient->getKey(),
                'signature_acceptance_id' => $acceptance->getKey(),
                'envelope_id' => $envelope->getKey(),
                'organization_id' => $envelope->organization_id,
                'value_text' => $value['text'],
                'value_bool' => $value['bool'],
                'image_path' => $value['image'],
            ]);

            unset($key);
        }

        return $acceptance;
    }
}

if (! function_exists('finalizationSignaturePng')) {
    /**
     * PNG transparente com um traço (o mesmo formato que `SignatureImages` grava).
     */
    function finalizationSignaturePng(int $width = 400, int $height = 140): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) $transparent);
        imagealphablending($image, true);
        $ink = imagecolorallocate($image, 12, 24, 58);
        imagesetthickness($image, 6);
        imageline($image, 12, $height - 24, intdiv($width, 2), 20, (int) $ink);
        imageline($image, intdiv($width, 2), 20, $width - 12, $height - 24, (int) $ink);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}

if (! function_exists('finalizationRun')) {
    /**
     * Roda o job de finalização como o worker rodaria (mesma classe, mesmo `handle`).
     */
    function finalizationRun(Envelope $envelope, ?string $correlationId = null): void
    {
        $job = new FinalizeEnvelope(
            (int) $envelope->getKey(),
            (int) $envelope->organization_id,
            $envelope->finalization_key,
            $correlationId,
        );

        app()->call([$job, 'handle']);
    }
}

if (! function_exists('finalizationDownload')) {
    /**
     * Copia uma versão do disco `documents` para um arquivo local (para inspecionar).
     */
    function finalizationDownload(DocumentVersion $version, string $target): string
    {
        $contents = Storage::disk('documents')->get($version->storage_path);

        if ($contents === null) {
            throw new RuntimeException('Versão sem bytes no disco: '.$version->storage_path);
        }

        file_put_contents($target, $contents);

        return $target;
    }
}
