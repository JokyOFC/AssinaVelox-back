<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da Fase 3 §3.2 — F-ANCHOR (âncoras e OCR)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes. As fixtures PDF são
| geradas em tempo de teste pelo `make_anchor_pdf.py` com o Python do pdftool (reportlab);
| nenhum binário é versionado.
*/

use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\SigningOrder;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Models\User;
use App\Services\Envelopes\EnvelopeReadiness;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';

if (! function_exists('anchorsEnable')) {
    /**
     * Liga `field_anchors` (e opcionalmente `ocr`): configuração global E plano vigente.
     */
    function anchorsEnable(Organization $organization, bool $ocr = false, bool $plan = true): void
    {
        config()->set('assinavelox.features.field_anchors', true);
        config()->set('assinavelox.features.ocr', $ocr);

        $current = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($current === null) {
            return;
        }

        $features = (array) ($current->features ?? []);
        $features['field_anchors'] = $plan;
        $features['ocr'] = $plan && $ocr;
        $current->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('anchorsIsolatedDisk')) {
    /**
     * Disco `documents` local DENTRO da área de trabalho do teste. `Storage::fake()` usa uma
     * pasta fixa (storage/framework/testing/disks/documents) que outro processo de teste rodando
     * ao mesmo tempo apaga no próprio `fake()` — o arquivo sumia no meio da busca.
     */
    function anchorsIsolatedDisk(string $work): void
    {
        $root = $work.DIRECTORY_SEPARATOR.'documents-disk';

        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }

        Storage::set('documents', Storage::build(['driver' => 'local', 'root' => $root, 'throw' => false]));
    }
}

if (! function_exists('anchorsRequirePdftool')) {
    function anchorsRequirePdftool(): void
    {
        if (! PdfFixtures::available()) {
            test()->markTestSkipped(PdfFixtures::skipMessage());
        }
    }
}

if (! function_exists('anchorsPdf')) {
    /**
     * PDF de teste gerado pelo reportlab (via o Python do pdftool).
     *
     * @param  list<array<string, mixed>>  $pages  {texts: [[x, y, texto, tamanho?]], rotate?, cropbox?, box?}
     */
    function anchorsPdf(string $work, array $pages, ?string $encryptOwner = null): string
    {
        $spec = $work.DIRECTORY_SEPARATOR.'spec-'.Str::lower((string) Str::ulid()).'.json';
        $out = $work.DIRECTORY_SEPARATOR.'ancoras-'.Str::lower((string) Str::ulid()).'.pdf';

        file_put_contents($spec, json_encode(['pages' => $pages, 'encrypt_owner' => $encryptOwner], JSON_THROW_ON_ERROR));

        $process = new Process(
            [PdfFixtures::pythonBinary(), __DIR__.DIRECTORY_SEPARATOR.'make_anchor_pdf.py', $spec, $out],
            base_path('tools/pdftool'),
            null,
            null,
            60,
        );
        $process->mustRun();

        return $out;
    }
}

if (! function_exists('anchorsEnvelope')) {
    /**
     * Rascunho com UM documento real (bytes no disco `documents` falso), `pages_meta` do
     * `pdftool inspect`, participantes e — por padrão — um campo de assinatura por signatário,
     * de modo que o envelope está PRONTO antes da detecção.
     *
     * @param  list<array{name: string, email: string, role_label?: string|null, role?: RecipientRole}>  $recipients
     * @return array{envelope: Envelope, document: Document, version: DocumentVersion, recipients: list<Recipient>}
     */
    function anchorsEnvelope(Organization $organization, User $owner, string $pdf, array $recipients, bool $withSignatureFields = true, bool $encrypted = false): array
    {
        $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create([
            'title' => 'Contrato com âncoras',
            'signing_order' => SigningOrder::Parallel,
        ]);

        $bytes = (string) file_get_contents($pdf);
        $versionUlid = (string) Str::ulid();
        $path = sprintf('orgs/%s/envelopes/%s/%s.pdf', $organization->ulid, $envelope->ulid, $versionUlid);
        Storage::disk('documents')->put($path, $bytes);

        $pagesMeta = $encrypted
            ? [['width_pt' => 600.0, 'height_pt' => 800.0, 'rotation' => 0, 'mediabox' => [0, 0, 600, 800], 'cropbox' => [0, 0, 600, 800]]]
            : app(PdfToolClient::class)->inspect($pdf)->pagesMeta();

        $document = Document::factory()->forEnvelope($envelope)->create([
            'position' => 1,
            'name' => 'Contrato',
            'original_filename' => 'contrato.pdf',
            'processing_status' => DocumentProcessingStatus::Ready,
            'page_count' => count($pagesMeta),
        ]);

        $version = DocumentVersion::factory()->forDocument($document)->create([
            'ulid' => $versionUlid,
            'kind' => DocumentVersionKind::Original,
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'page_count' => count($pagesMeta),
            'pages_meta' => $pagesMeta,
        ]);

        $document->forceFill(['current_version_id' => $version->id])->save();

        $models = [];

        foreach ($recipients as $spec) {
            $recipient = Recipient::factory()->forEnvelope($envelope, 1)->create([
                'name' => $spec['name'],
                'email' => $spec['email'],
                'role' => $spec['role'] ?? RecipientRole::Signer,
                'role_label' => $spec['role_label'] ?? null,
            ]);

            if ($withSignatureFields && $recipient->role->requiresSignatureField()) {
                SigningField::factory()->create([
                    'envelope_id' => $envelope->id,
                    'document_version_id' => $version->id,
                    'recipient_id' => $recipient->id,
                    'organization_id' => $organization->id,
                    'type' => FieldType::Signature,
                    'page' => 1,
                    'x' => 0.05,
                    // Rodapé da página 1: longe dos marcadores das fixtures (que ficam no alto).
                    'y' => 0.80 + 0.065 * count($models),
                    'width' => 0.3,
                    'height' => 0.06,
                    'required' => true,
                ]);
            }

            $models[] = $recipient;
        }

        EnvelopeReadiness::refresh($envelope);

        return ['envelope' => $envelope->fresh(), 'document' => $document->fresh(), 'version' => $version, 'recipients' => $models];
    }
}

if (! function_exists('anchorsReady')) {
    function anchorsReady(Envelope $envelope): bool
    {
        EnvelopeReadiness::refresh($envelope);

        return $envelope->fresh()?->status === EnvelopeStatus::Ready;
    }
}
