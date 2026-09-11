<?php

use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\SignatureStatus;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\SigningField;
use App\Services\Branding\BrandingManager;
use App\Services\Branding\BrandingPresenter;
use App\Services\Branding\Stamp\StampEvidence;
use App\Services\Documents\DocumentStorage;
use App\Services\Envelopes\Finalization\EvidenceData;
use App\Services\Envelopes\Finalization\EvidenceRenderer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/BrandingHelpers.php';
require_once __DIR__.'/../../Verification/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Página de evidências: a marca só troca o cabeçalho; o conteúdo não muda
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);
    $this->withoutVite();
});

/**
 * @return array{0: Organization, 1: Envelope, 2: array<string, mixed>}
 */
function brandingEvidenceFixture(bool $enabled = true): array
{
    ['organization' => $organization, 'owner' => $owner] = brandingOrganization(enabled: $enabled, actAs: false);

    $manager = app(BrandingManager::class);
    $manager->update($organization, $owner, ['display_name' => 'Aurora Imóveis']);
    $path = tempnam(sys_get_temp_dir(), 'logo');
    file_put_contents($path, brandingPng());
    $manager->replaceLogo($organization, $owner, $path);
    @unlink($path);

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Finalizing);
    $sent = DocumentVersion::withoutOrganizationScope()->findOrFail($envelope->sent_document_version_id);

    $data = app(EvidenceData::class)->build(
        $envelope,
        $sent,
        ['original' => str_repeat('a', 64), 'sent' => $sent->sha256, 'consolidated' => str_repeat('c', 64)],
        SignatureStatus::None,
        generatedAt: Carbon::parse('2026-09-11 15:00:00', 'UTC'),
    );

    return [$organization, $envelope, $data];
}

function brandingEvidenceHtml(array $data): string
{
    return view('evidence.page', ['evidence' => $data])->render();
}

function brandingNormalize(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $html));
}

test('flag desligada: a página de evidências é idêntica à da Fase 1', function () {
    [$organization, , $data] = brandingEvidenceFixture(enabled: false);

    $branding = app(BrandingPresenter::class)->forEvidence($organization);

    expect($branding)->toBeNull()
        ->and($data['branding'])->toBeNull()
        ->and(brandingEvidenceHtml(array_replace($data, ['branding' => $branding])))->toBe(brandingEvidenceHtml($data));
});

test('flag ligada: só o cabeçalho ganha o logo da organização remetente', function () {
    [$organization, , $data] = brandingEvidenceFixture();

    $branding = app(BrandingPresenter::class)->forEvidence($organization);
    expect($branding['logo_data_uri'])->toStartWith('data:image/png;base64,');

    // Integração (I-2B): `EvidenceData::build()` já traz `branding`; a linha de base é o
    // mesmo dado sem a marca.
    $baseline = brandingEvidenceHtml(array_replace($data, ['branding' => null]));
    $branded = brandingEvidenceHtml(array_replace($data, ['branding' => $branding]));

    preg_match('#<table class="brand">.*?</table>#s', $branded, $block);

    expect($block)->toHaveCount(1)
        ->and($block[0])->toContain($branding['logo_data_uri'])
        ->and($block[0])->toContain('Aurora Imóveis')
        ->and($block[0])->toContain('Organização remetente')
        // O bloco identifica quem ENVIOU; não diz que a organização emite ou certifica nada.
        ->and(mb_strtolower($block[0]))->not->toContain('certific')
        ->and(mb_strtolower($block[0]))->not->toContain('emiti')
        ->and(mb_strtolower($block[0]))->not->toContain('assinad');

    // Tirando o bloco da marca, o HTML é exatamente o mesmo.
    expect(brandingNormalize(str_replace($block[0], '', $branded)))->toBe(brandingNormalize($baseline));

    // O bloco vem antes do título; a linha da operadora continua lá.
    expect(strpos($branded, '<table class="brand">'))->toBeLessThan(strpos($branded, 'Página de evidências do aceite eletrônico'))
        ->and($branded)->toContain('Documento consolidado por '.$data['operator']['name']);
});

test('o PDF de evidências com a marca é gerado pelo DOMPDF sem rede (logo embutido)', function () {
    [$organization, , $data] = brandingEvidenceFixture();

    $work = storage_path('app/tmp/tests/'.Str::ulid());
    mkdir($work, 0700, true);

    try {
        $renderer = app(EvidenceRenderer::class);
        $plain = $renderer->render(array_replace($data, ['branding' => null]), $work.'/plain.pdf');
        $branded = $renderer->render(array_replace($data, ['branding' => app(BrandingPresenter::class)->forEvidence($organization)]), $work.'/branded.pdf');

        $images = fn (string $path): int => preg_match_all('#/Subtype\s*/Image#', (string) file_get_contents($path));

        // O logo PNG com transparência vira duas imagens no PDF (a cor e a /SMask do alfa).
        expect(file_get_contents($branded))->toStartWith('%PDF')
            ->and($images($branded))->toBe($images($plain) + 2);
    } finally {
        (new Filesystem)->deleteDirectory($work);
    }
});

test('o carimbo visual é descrito como representação visual, não prova — e só quando existe', function () {
    [, $envelope, $data] = brandingEvidenceFixture();

    expect(StampEvidence::forEnvelope($envelope))->toBeNull()
        ->and(brandingEvidenceHtml($data + ['stamp' => null]))->toBe(brandingEvidenceHtml($data));

    $recipient = $envelope->recipients()->firstOrFail();
    $sent = DocumentVersion::withoutOrganizationScope()->findOrFail($envelope->sent_document_version_id);

    SigningField::factory()->forRecipient($recipient, $sent)->create([
        'type' => FieldType::Stamp,
        'width' => 0.30,
        'height' => 0.10,
    ]);

    $stamp = StampEvidence::forEnvelope($envelope, $sent->id);

    expect($stamp)->toBe(['description' => 'Carimbo visual da organização — representação visual, não prova.', 'count' => 1])
        ->and(brandingEvidenceHtml(array_replace($data, ['stamp' => $stamp])))
        ->toContain('<tr><td>Carimbo visual</td><td>Carimbo visual da organização — representação visual, não prova.</td></tr>');
});
