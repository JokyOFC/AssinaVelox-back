<?php

use App\Enums\FieldType;
use App\Integrations\IntegrationsServiceProvider;
use App\Services\Pdf\Dto\ComposePlan;
use App\Services\Pdf\Exceptions\PdfToolInputRejectedException;
use App\Services\Pdf\Exceptions\PdfToolUsageException;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\ProcessEnvironment;
use Tests\Feature\Pdf\Support\PdfFixtures;

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->app->register(IntegrationsServiceProvider::class);
    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    $this->client = app(PdfToolClient::class);
});

afterEach(function () {
    PdfFixtures::cleanup($this->work ?? null);
});

it('inspeciona um PDF A4 de uma página e limpa o diretório temporário', function () {
    $pdf = PdfFixtures::onePagePdf($this->work.'/one.pdf');

    $inspection = $this->client->inspect($pdf);

    expect($inspection->pageCount)->toBe(1)
        ->and($inspection->encrypted)->toBeFalse()
        ->and($inspection->openable)->toBeTrue()
        ->and($inspection->hasSignatures)->toBeFalse()
        ->and($inspection->signatureCount)->toBe(0)
        ->and($inspection->hasAcroform)->toBeFalse()
        ->and($inspection->hasXfa)->toBeFalse()
        ->and($inspection->isBlockedForPreparation())->toBeFalse()
        ->and($inspection->metadata)->toHaveKey('producer')
        ->and($inspection->pages)->toHaveCount(1);

    $page = $inspection->page(1);
    expect($page)->not->toBeNull()
        ->and($page->index)->toBe(1)
        ->and($page->rotation)->toBe(0)
        ->and($page->widthPt)->toEqualWithDelta(595.28, 0.5)
        ->and($page->heightPt)->toEqualWithDelta(841.89, 0.5)
        ->and($page->mediabox)->toHaveCount(4)
        ->and($page->cropbox)->toHaveCount(4)
        ->and($page->isLandscape())->toBeFalse();

    expect($inspection->pagesMeta()[0])->toMatchArray(['index' => 1, 'rotation' => 0]);

    // O diretório exclusivo da chamada foi removido em finally.
    expect(glob($this->work.'/pdftool-tmp/*') ?: [])->toBe([]);
});

it('inspeciona um PDF de duas páginas', function () {
    $pdf = PdfFixtures::twoPagePdf($this->work.'/two.pdf');

    $inspection = $this->client->inspect($pdf);

    expect($inspection->pageCount)->toBe(2)
        ->and($inspection->pages)->toHaveCount(2)
        ->and($inspection->page(2)?->index)->toBe(2)
        ->and($inspection->page(3))->toBeNull();
});

it('compõe texto, imagem e checkbox mantendo o número de páginas', function () {
    $source = PdfFixtures::twoPagePdf($this->work.'/source.pdf');
    $png = PdfFixtures::signaturePng($this->work.'/assinatura.png');
    $out = $this->work.'/composto.pdf';

    $plan = ComposePlan::source($source)
        ->addImage('fld_sig', 1, FieldType::Signature, 0.10, 0.70, 0.30, 0.08, $png)
        ->addText('fld_nome', 1, 0.10, 0.79, 0.30, 0.04, 'João da Silva', ['align' => 'left', 'font_size' => 10])
        ->addField('fld_data', 1, FieldType::Date, 0.10, 0.84, 0.30, 0.04, '08/09/2026', ['align' => 'center'])
        ->addCheckbox('fld_chk', 2, 0.05, 0.05, 0.03, 0.03, true)
        ->addText('fld_vazio', 2, 0.10, 0.05, 0.50, 0.04, '');

    $result = $this->client->compose($plan, $out);

    expect($result->pageCount)->toBe(2)
        ->and($result->fieldsDrawn)->toBe(4)
        ->and($result->skipped)->toBe(['fld_vazio'])
        ->and($result->outputPath)->toBe($out)
        ->and($result->correlationId)->not->toBeEmpty()
        ->and(is_file($out))->toBeTrue();

    $inspection = $this->client->inspect($out);
    expect($inspection->pageCount)->toBe(2)
        ->and($inspection->openable)->toBeTrue()
        ->and($inspection->encrypted)->toBeFalse()
        ->and($inspection->hasSignatures)->toBeFalse();

    expect(glob($this->work.'/pdftool-tmp/*') ?: [])->toBe([]);
});

it('serializa o plano exatamente no formato do pdftool', function () {
    $plan = ComposePlan::source('/abs/contrato.pdf')
        ->font('/abs/Inter.ttf', 'Inter')
        ->addImage('fld_1', 1, 'signature', 0.10, 0.70, 0.30, 0.08, '/abs/assinatura.png')
        ->addField('fld_2', 1, FieldType::Name, 0.10, 0.79, 0.30, 0.04, 'Joao da Silva', ['font_size' => 10, 'align' => 'left'])
        ->addCheckbox('fld_4', 2, 0.05, 0.05, 0.03, 0.03, true)
        ->addField('fld_5', 2, 'text', 0.10, 0.05, 0.50, 0.04, 'Li e concordo', ['box' => 'cropbox']);

    expect($plan->toArray())->toBe([
        'source' => '/abs/contrato.pdf',
        'font' => ['path' => '/abs/Inter.ttf', 'name' => 'Inter'],
        'fields' => [
            ['id' => 'fld_1', 'page' => 1, 'type' => 'signature', 'x' => 0.10, 'y' => 0.70, 'width' => 0.30, 'height' => 0.08, 'image' => '/abs/assinatura.png'],
            ['id' => 'fld_2', 'page' => 1, 'type' => 'name', 'x' => 0.10, 'y' => 0.79, 'width' => 0.30, 'height' => 0.04, 'value' => 'Joao da Silva', 'font_size' => 10.0, 'align' => 'left'],
            ['id' => 'fld_4', 'page' => 2, 'type' => 'checkbox', 'x' => 0.05, 'y' => 0.05, 'width' => 0.03, 'height' => 0.03, 'value' => true],
            ['id' => 'fld_5', 'page' => 2, 'type' => 'text', 'x' => 0.10, 'y' => 0.05, 'width' => 0.50, 'height' => 0.04, 'value' => 'Li e concordo', 'box' => 'cropbox'],
        ],
    ])->and($plan->count())->toBe(4)
        ->and($plan->imagePaths())->toBe(['/abs/assinatura.png'])
        ->and(json_decode($plan->toJson(), true))->toBe($plan->toArray());
});

it('valida os campos do plano no PHP antes de chamar o pdftool', function () {
    expect(fn () => ComposePlan::source('/abs/a.pdf')->addText('x', 0, 0.1, 0.1, 0.1, 0.1, 'a'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ComposePlan::source('/abs/a.pdf')->addText('x', 1, 0.9, 0.1, 0.2, 0.1, 'a'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ComposePlan::source('/abs/a.pdf')->addText('x', 1, 0.1, 0.1, 0, 0.1, 'a'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ComposePlan::source('/abs/a.pdf')->addField('x', 1, 'stamp', 0.1, 0.1, 0.1, 0.1, 'a'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ComposePlan::source('/abs/a.pdf')->addField('x', 1, 'signature', 0.1, 0.1, 0.1, 0.1, null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ComposePlan::source('/abs/a.pdf')->addText('x', 1, 0.1, 0.1, 0.1, 0.1, 'a', ['align' => 'justify']))->toThrow(InvalidArgumentException::class);
});

it('anexa páginas somando os totais', function () {
    $base = PdfFixtures::onePagePdf($this->work.'/base.pdf');
    $extra = PdfFixtures::twoPagePdf($this->work.'/extra.pdf');
    $out = $this->work.'/anexado.pdf';

    $pages = $this->client->append($base, $extra, $out);

    expect($pages)->toBe(3)
        ->and($this->client->inspect($out)->pageCount)->toBe(3);
});

it('rejeita PDF corrompido com PdfToolInputRejectedException', function () {
    $pdf = PdfFixtures::corruptedPdf($this->work.'/corrompido.pdf');

    try {
        $this->client->inspect($pdf);
        $this->fail('Esperava PdfToolInputRejectedException.');
    } catch (PdfToolInputRejectedException $exception) {
        expect($exception->errorCode)->toBe('invalid_pdf')
            ->and($exception->isInvalidPdf())->toBeTrue()
            ->and($exception->exitCode)->toBe(PdfToolClient::EXIT_INPUT_REJECTED)
            ->and($exception->correlationId)->not->toBeEmpty()
            ->and($exception->command)->toContain('inspect')
            ->and($exception->command)->toContain('--in')
            ->and($exception->getMessage())->toContain('invalid_pdf')
            ->and($exception->context())->toHaveKeys(['error_code', 'exit_code', 'command', 'correlation_id']);
    }
});

it('rejeita arquivo inexistente com missing_input', function () {
    expect(fn () => $this->client->inspect($this->work.'/nao-existe.pdf'))
        ->toThrow(PdfToolInputRejectedException::class, 'missing_input');
});

it('mapeia erro de uso (exit 2) para PdfToolUsageException', function () {
    $base = PdfFixtures::onePagePdf($this->work.'/base.pdf');
    $extra = PdfFixtures::onePagePdf($this->work.'/extra.pdf');

    try {
        $this->client->append($base, $extra, $base);
        $this->fail('Esperava PdfToolUsageException.');
    } catch (PdfToolUsageException $exception) {
        expect($exception->errorCode)->toBe('same_path')
            ->and($exception->exitCode)->toBe(PdfToolClient::EXIT_USAGE);
    }
});

it('exige caminhos absolutos', function () {
    expect(fn () => $this->client->inspect('relativo.pdf'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->client->imageToPdf('/abs/a.png', '/abs/a.pdf', 'a3'))->toThrow(InvalidArgumentException::class)
        ->and(PdfToolClient::isAbsolutePath('C:\\x\\y.pdf'))->toBeTrue()
        ->and(PdfToolClient::isAbsolutePath('/tmp/y.pdf'))->toBeTrue()
        ->and(PdfToolClient::isAbsolutePath('y.pdf'))->toBeFalse();
});

it('expõe versões do interpretador e do pdftool', function () {
    expect($this->client->isAvailable())->toBeTrue()
        ->and($this->client->version())->toStartWith('pdftool ')
        ->and($this->client->pythonVersion())->toStartWith('Python 3');
});

it('monta um ambiente mínimo para o processo filho', function () {
    putenv('PDFTEST_LEAK_CHECK=nao-deve-vazar');
    try {
        $env = ProcessEnvironment::minimal($this->work, ['PDFTEST_EXTRA' => 'ok']);
    } finally {
        putenv('PDFTEST_LEAK_CHECK');
    }

    $passed = array_keys(array_filter($env, fn ($value) => $value !== false));
    $allowed = ['SYSTEMROOT', 'PATH', 'TEMP', 'TMP', 'TMPDIR', 'HOME', 'USERPROFILE', 'LANG', 'PDFTEST_EXTRA'];

    expect(array_diff($passed, $allowed))->toBe([])
        ->and($env['PDFTEST_LEAK_CHECK'] ?? false)->toBeFalse()
        ->and($env['PDFTEST_EXTRA'])->toBe('ok')
        ->and($env['TEMP'])->toBe($this->work)
        ->and($env['PATH'] ?? false)->not->toBeFalse();

    foreach (['APP_KEY', 'DB_PASSWORD', 'AWS_SECRET_ACCESS_KEY'] as $sensitive) {
        expect($env[$sensitive] ?? false)->toBeFalse();
    }
});

it('roda o selftest do pdftool', function () {
    $result = $this->client->selftest();

    expect($result['ok'])->toBeTrue()
        ->and(collect($result['steps'])->pluck('step')->all())->toContain('sign', 'validate_with_trust', 'validate_without_trust')
        ->and(collect($result['steps'])->every(fn ($step) => $step['ok'] === true))->toBeTrue();
});
