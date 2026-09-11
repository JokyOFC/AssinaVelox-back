<?php

use App\Enums\FieldType;
use App\Integrations\IntegrationsServiceProvider;
use App\Models\Envelope;
use App\Services\Branding\BrandingManager;
use App\Services\Branding\Stamp\StampComposer;
use App\Services\Branding\Stamp\StampImages;
use App\Services\Branding\Stamp\StampRenderer;
use App\Services\Documents\DocumentStorage;
use App\Services\Pdf\Dto\ComposePlan;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/BrandingHelpers.php';

/*
|--------------------------------------------------------------------------
| Carimbo visual (FieldType::Stamp): imagem da marca, retrato congelado e posição no PDF
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);
});

function brandingStampOrganization(bool $enabled = true): array
{
    ['organization' => $organization, 'owner' => $owner] = brandingOrganization(enabled: $enabled, actAs: false);

    $manager = app(BrandingManager::class);
    $manager->update($organization, $owner, ['display_name' => 'Aurora Imóveis', 'primary_color' => '#0B3D91', 'accent_color' => '#2F80ED']);
    $path = tempnam(sys_get_temp_dir(), 'logo');
    file_put_contents($path, brandingPng());
    $manager->replaceLogo($organization, $owner, $path);
    @unlink($path);

    return [$organization, $owner];
}

test('o tipo existe no enum com rótulo próprio e não é captura do participante', function () {
    expect(FieldType::from('stamp'))->toBe(FieldType::Stamp)
        ->and(FieldType::Stamp->label())->toBe('Carimbo visual')
        ->and(FieldType::Stamp->isImageBased())->toBeFalse();
});

test('a imagem do carimbo é um PNG transparente de 900×300 com logo e nome', function () {
    $renderer = new StampRenderer;
    $bytes = $renderer->render('Aurora Imóveis', brandingPng(), '#0B3D91', '#2F80ED');

    $image = imagecreatefromstring($bytes);
    $corner = imagecolorsforindex($image, imagecolorat($image, 450, 150 - 140));

    expect(getimagesizefromstring($bytes)[0])->toBe(StampRenderer::WIDTH)
        ->and(getimagesizefromstring($bytes)[1])->toBe(StampRenderer::HEIGHT)
        ->and($bytes)->toStartWith("\x89PNG")
        // Dentro da moldura, longe do logo e do texto: transparente.
        ->and($corner['alpha'])->toBe(127);

    // Moldura na cor de destaque.
    $frame = imagecolorsforindex($image, imagecolorat($image, 4, 150));
    expect([$frame['red'], $frame['green'], $frame['blue']])->toBe([0x2F, 0x80, 0xED]);

    // Sem logo o nome ainda sai (não quebra).
    expect(getimagesizefromstring($renderer->render('Só o nome', null, '#0B3D91', '#2F80ED'))[0])->toBe(StampRenderer::WIDTH);
});

test('sem a flag não há carimbo; com a flag o retrato é congelado junto do envelope', function () {
    [$organization, $owner] = brandingStampOrganization(enabled: false);
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->create();
    $images = app(StampImages::class);

    expect($images->render($organization))->toBeNull()
        ->and($images->snapshot($envelope))->toBeNull()
        ->and(Storage::disk(DocumentStorage::DISK)->allFiles('orgs/'.$organization->ulid.'/envelopes'))->toBe([]);

    brandingEnable($organization);

    $path = $images->snapshot($envelope);

    expect($path)->toMatch('#^orgs/'.$organization->ulid.'/envelopes/'.$envelope->ulid.'/stamp-[a-f0-9]{32}\.png$#')
        ->and(Storage::disk(DocumentStorage::DISK)->exists($path))->toBeTrue()
        // Mesma marca, mesmo arquivo: não duplica a cada aceite.
        ->and($images->snapshot($envelope))->toBe($path)
        ->and(Storage::disk(DocumentStorage::DISK)->allFiles('orgs/'.$organization->ulid.'/envelopes'))->toHaveCount(1);
});

test('o carimbo vai ao pdftool pelo caminho de imagem existente (tipo signature)', function () {
    $plan = StampComposer::addAt(ComposePlan::source('/abs/contrato.pdf'), 'fld_stamp', 2, 0.55, 0.70, 0.35, 0.12, '/abs/stamp.png', 'cropbox');

    expect($plan->toArray()['fields'])->toBe([
        ['id' => 'fld_stamp', 'page' => 2, 'type' => 'signature', 'x' => 0.55, 'y' => 0.70, 'width' => 0.35, 'height' => 0.12, 'image' => '/abs/stamp.png', 'box' => 'cropbox'],
    ]);
});

test('no PDF consolidado o carimbo aparece como imagem, centralizado no retângulo do campo', function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->app->register(IntegrationsServiceProvider::class);
    $work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $work.DIRECTORY_SEPARATOR.'pdftool-tmp');

    try {
        [$organization] = brandingStampOrganization();
        $stamp = $work.'/stamp.png';
        file_put_contents($stamp, app(StampImages::class)->render($organization));

        $source = PdfFixtures::onePagePdf($work.'/source.pdf');
        $out = $work.'/composto.pdf';
        [$x, $y, $w, $h] = [0.55, 0.70, 0.35, 0.12];

        $plan = StampComposer::addAt(ComposePlan::source($source), 'fld_stamp', 1, $x, $y, $w, $h, $stamp);
        $result = app(PdfToolClient::class)->compose($plan, $out);

        expect($result->fieldsDrawn)->toBe(1);

        $run = Process::run([PdfFixtures::pythonBinary(), __DIR__.'/Support/image_boxes.py', $out, '1']);
        expect($run->successful())->toBeTrue($run->errorOutput());

        $boxes = json_decode($run->output(), true, flags: JSON_THROW_ON_ERROR);
        [$pageW, $pageH] = [$boxes['width'], $boxes['height']];

        expect($boxes['images'])->toHaveCount(1);
        $image = $boxes['images'][0];

        // Retângulo do campo em pontos (origem do PDF embaixo à esquerda).
        $field = ['x0' => $x * $pageW, 'x1' => ($x + $w) * $pageW, 'y0' => (1 - $y - $h) * $pageH, 'y1' => (1 - $y) * $pageH];
        $tolerance = 0.6;

        expect($image['x0'])->toBeGreaterThanOrEqual($field['x0'] - $tolerance)
            ->and($image['x1'])->toBeLessThanOrEqual($field['x1'] + $tolerance)
            ->and($image['y0'])->toBeGreaterThanOrEqual($field['y0'] - $tolerance)
            ->and($image['y1'])->toBeLessThanOrEqual($field['y1'] + $tolerance)
            // Centralizado no campo.
            ->and(($image['x0'] + $image['x1']) / 2)->toEqualWithDelta(($field['x0'] + $field['x1']) / 2, 1.0)
            ->and(($image['y0'] + $image['y1']) / 2)->toEqualWithDelta(($field['y0'] + $field['y1']) / 2, 1.0)
            // Sem distorção: mantém a proporção 3:1 do carimbo e ocupa a largura toda do campo.
            ->and(($image['x1'] - $image['x0']) / ($image['y1'] - $image['y0']))->toEqualWithDelta(3.0, 0.02)
            ->and($image['x1'] - $image['x0'])->toEqualWithDelta($field['x1'] - $field['x0'], 1.0);
    } finally {
        PdfFixtures::cleanup($work);
    }
});

// O fluxo completo (campo de carimbo no editor → aceite → PDF consolidado) é coberto pela
// integração em tests/Feature/EndToEnd/Phase2OndaBTest.php e tests/Feature/Phase2/FieldTypeFlagsTest.php.
