<?php

use App\Enums\DocumentSourceType;
use App\Integrations\Contracts\PdfConverter;
use App\Integrations\Dto\ConversionRequest;
use App\Integrations\Dto\ConversionStatus;
use App\Integrations\Exceptions\ConverterNotConfiguredException;
use App\Integrations\Exceptions\UnsupportedSourceTypeException;
use App\Integrations\IntegrationsServiceProvider;
use App\Integrations\Pdf\FakePdfConverter;
use App\Integrations\Pdf\ImageToPdfConverter;
use App\Integrations\Pdf\LibreOfficeConverter;
use App\Integrations\Pdf\PassthroughPdfConverter;
use App\Integrations\Pdf\PdfConverterManager;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Pdf\Support\PdfFixtures;

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->app->register(IntegrationsServiceProvider::class);
    $this->work = PdfFixtures::workspace();
    $this->tmpRoot = $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp';
    config()->set('pdftool.tmp_path', $this->tmpRoot);
    $this->client = app(PdfToolClient::class);
});

afterEach(function () {
    putenv('PDFTEST_CERT_PASS');
    PdfFixtures::cleanup($this->work ?? null);
});

describe('PassthroughPdfConverter', function () {
    it('aceita um PDF simples e copia para o destino', function () {
        $in = PdfFixtures::onePagePdf($this->work.'/original.pdf');
        $out = $this->work.'/pronto.pdf';

        $result = app(PassthroughPdfConverter::class)->convert(new ConversionRequest($in, $out, DocumentSourceType::Pdf));

        expect($result->isReady())->toBeTrue()
            ->and($result->status)->toBe(ConversionStatus::Ready)
            ->and($result->converter)->toBe(PassthroughPdfConverter::NAME)
            ->and($result->outputPath)->toBe($out)
            ->and($result->pageCount())->toBe(1)
            ->and($result->correlationId)->not->toBeEmpty()
            ->and(is_file($out))->toBeTrue()
            ->and(hash_file('sha256', $out))->toBe(hash_file('sha256', $in));
    });

    it('marca blocked para PDF já assinado digitalmente', function () {
        putenv('PDFTEST_CERT_PASS=senha-teste');
        $pfx = $this->work.'/t.pfx';
        $this->client->generateTestCertificate($pfx, 'PDFTEST_CERT_PASS', days: 1);
        $plain = PdfFixtures::onePagePdf($this->work.'/plain.pdf');
        $signed = $this->work.'/signed.pdf';
        $this->client->sign($plain, $signed, $pfx, 'PDFTEST_CERT_PASS');

        $result = app(PassthroughPdfConverter::class)->convert(new ConversionRequest($signed, $this->work.'/out.pdf', 'pdf'));

        expect($result->isBlocked())->toBeTrue()
            ->and($result->reasonCode)->toBe('has_signatures')
            ->and($result->reasonMessage)->toContain('assinaturas digitais')
            ->and($result->inspection?->signatureCount)->toBe(1)
            ->and($result->status->toDocumentProcessingStatus()->value)->toBe('blocked')
            ->and(is_file($this->work.'/out.pdf'))->toBeFalse();
    });

    it('marca blocked para PDF cifrado apenas com senha de proprietário', function () {
        $in = PdfFixtures::encryptedPdf($this->work.'/owner-only.pdf', '', 'owner-secret');

        $result = app(PassthroughPdfConverter::class)->convert(new ConversionRequest($in, $this->work.'/out.pdf', DocumentSourceType::Pdf));

        expect($result->isBlocked())->toBeTrue()
            ->and($result->reasonCode)->toBe('encrypted_pdf')
            ->and($result->inspection?->encrypted)->toBeTrue()
            ->and($result->inspection?->openable)->toBeTrue()
            ->and(is_file($this->work.'/out.pdf'))->toBeFalse();
    });

    it('marca blocked para PDF cifrado com senha de usuário', function () {
        $in = PdfFixtures::encryptedPdf($this->work.'/user-pass.pdf', 'user-secret', 'owner-secret');

        $result = app(PassthroughPdfConverter::class)->convert(new ConversionRequest($in, $this->work.'/out.pdf', DocumentSourceType::Pdf));

        expect($result->isBlocked())->toBeTrue()
            ->and($result->reasonCode)->toBe('encrypted_pdf')
            ->and($result->inspection)->toBeNull()
            ->and($result->reasonMessage)->toContain('protegido');
    });

    it('marca blocked para PDF corrompido e failed para arquivo inexistente', function () {
        $corrupted = PdfFixtures::corruptedPdf($this->work.'/corrompido.pdf');
        $converter = app(PassthroughPdfConverter::class);

        $blocked = $converter->convert(new ConversionRequest($corrupted, $this->work.'/out.pdf', DocumentSourceType::Pdf));
        expect($blocked->isBlocked())->toBeTrue()
            ->and($blocked->reasonCode)->toBe('invalid_pdf');

        $failed = $converter->convert(new ConversionRequest($this->work.'/nao-existe.pdf', $this->work.'/out.pdf', DocumentSourceType::Pdf));
        expect($failed->isFailed())->toBeTrue()
            ->and($failed->reasonCode)->toBe('missing_input');
    });

    it('recusa tipos que não sejam pdf', function () {
        expect(fn () => app(PassthroughPdfConverter::class)->convert(new ConversionRequest('/x.docx', '/y.pdf', DocumentSourceType::Docx)))
            ->toThrow(UnsupportedSourceTypeException::class);
    });
});

describe('ImageToPdfConverter', function () {
    it('normaliza (≤ 4000 px, sem metadados) e converte PNG em PDF de uma página', function () {
        $png = PdfFixtures::largePng($this->work.'/grande.png', 4200, 1000);
        $out = $this->work.'/imagem.pdf';

        $result = app(ImageToPdfConverter::class)->convert(new ConversionRequest($png, $out, DocumentSourceType::Image, 'image/png', 'grande.png'));

        expect($result->isReady())->toBeTrue()
            ->and($result->converter)->toBe(ImageToPdfConverter::NAME)
            ->and($result->pageCount())->toBe(1)
            ->and($result->inspection?->page(1)?->isLandscape())->toBeTrue()
            ->and($result->details['normalized']['width_px'])->toBe(4000)
            ->and($result->details['normalized']['height_px'])->toBe(952)
            ->and($result->details['source']['mime'])->toBe('image/png')
            ->and($result->details['source']['width_px'])->toBe(4200)
            ->and($result->details['page_size'])->toBe('a4')
            ->and(is_file($out))->toBeTrue()
            ->and(glob($this->tmpRoot.'/*') ?: [])->toBe([]);
    });

    it('converte JPEG', function () {
        $jpeg = PdfFixtures::jpeg($this->work.'/foto.jpg');
        $out = $this->work.'/foto.pdf';

        $result = app(ImageToPdfConverter::class)->convert(new ConversionRequest($jpeg, $out, 'image', options: ['page' => 'letter']));

        expect($result->isReady())->toBeTrue()
            ->and($result->details['normalized']['format'])->toBe('jpeg')
            ->and($result->details['page_size'])->toBe('letter')
            ->and($result->inspection?->page(1)?->widthPt)->toEqualWithDelta(792.0, 0.5);
    });

    it('rejeita SVG', function () {
        $svg = PdfFixtures::svg($this->work.'/vetor.svg');

        $result = app(ImageToPdfConverter::class)->convert(new ConversionRequest($svg, $this->work.'/out.pdf', DocumentSourceType::Image));

        expect($result->isFailed())->toBeTrue()
            ->and($result->reasonCode)->toBe('unsupported_image')
            ->and($result->reasonMessage)->toContain('PNG, JPEG ou WEBP')
            ->and(is_file($this->work.'/out.pdf'))->toBeFalse();
    });

    it('rejeita imagem acima de 40 megapixels lendo só o cabeçalho', function () {
        $png = PdfFixtures::pngHeaderOnly($this->work.'/enorme.png', 7000, 7000);

        $result = app(ImageToPdfConverter::class)->convert(new ConversionRequest($png, $this->work.'/out.pdf', DocumentSourceType::Image));

        expect($result->isFailed())->toBeTrue()
            ->and($result->reasonCode)->toBe('image_too_large');
    });

    it('rejeita arquivo que não é imagem e imagem corrompida', function () {
        file_put_contents($this->work.'/texto.png', 'isto nao e uma imagem');
        $converter = app(ImageToPdfConverter::class);

        $notImage = $converter->convert(new ConversionRequest($this->work.'/texto.png', $this->work.'/out.pdf', DocumentSourceType::Image));
        expect($notImage->isFailed())->toBeTrue()
            ->and($notImage->reasonCode)->toBe('unsupported_image');

        $header = PdfFixtures::pngHeaderOnly($this->work.'/truncado.png', 100, 100);
        $truncated = $converter->convert(new ConversionRequest($header, $this->work.'/out.pdf', DocumentSourceType::Image));
        expect($truncated->isFailed())->toBeTrue()
            ->and($truncated->reasonCode)->toBe('invalid_image');
    });
});

describe('PdfConverterManager', function () {
    it('escolhe o conversor pelo tipo de origem configurado', function () {
        $manager = app(PdfConverterManager::class);

        expect($manager->converterFor(DocumentSourceType::Pdf))->toBeInstanceOf(PassthroughPdfConverter::class)
            ->and($manager->converterFor('image'))->toBeInstanceOf(ImageToPdfConverter::class)
            ->and($manager->converterFor(DocumentSourceType::Docx))->toBeInstanceOf(LibreOfficeConverter::class)
            ->and($manager->supports('xlsx'))->toBeFalse()
            ->and(fn () => $manager->converterFor('xlsx'))->toThrow(UnsupportedSourceTypeException::class)
            ->and(app(PdfConverter::class))->toBeInstanceOf(PdfConverterManager::class)
            ->and($manager->status())->toHaveKeys(['pdf', 'docx', 'image']);

        $in = PdfFixtures::onePagePdf($this->work.'/a.pdf');
        $result = $manager->convert(new ConversionRequest($in, $this->work.'/b.pdf', DocumentSourceType::Pdf));
        expect($result->isReady())->toBeTrue()->and($result->converter)->toBe(PassthroughPdfConverter::NAME);
    });

    it('permite apontar docx para o FakePdfConverter, que se identifica como fake e avisa em log', function () {
        Log::spy();
        config()->set('pdftool.converters.docx', FakePdfConverter::class);
        file_put_contents($this->work.'/contrato.docx', 'conteudo irrelevante');
        $out = $this->work.'/fake.pdf';

        $result = app(PdfConverterManager::class)->convert(new ConversionRequest($this->work.'/contrato.docx', $out, DocumentSourceType::Docx, originalFilename: 'contrato.docx'));

        expect($result->isReady())->toBeTrue()
            ->and($result->converter)->toBe('fake')
            ->and($result->details['fake'])->toBeTrue()
            ->and($result->pageCount())->toBe(1)
            ->and(hash_file('sha256', $out))->toBe(hash_file('sha256', PdfFixtures::fakeConvertedPdf()));

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'FakePdfConverter'))->once();
    });
});

describe('LibreOfficeConverter', function () {
    it('sem binário não está configurado e convert() lança ConverterNotConfiguredException', function () {
        config()->set('pdftool.libreoffice.binary', null);
        $converter = app(LibreOfficeConverter::class);
        file_put_contents($this->work.'/doc.docx', 'x');

        expect($converter->isConfigured())->toBeFalse()
            ->and($converter->resolvedBinary())->toBeNull()
            ->and(fn () => $converter->convert(new ConversionRequest($this->work.'/doc.docx', $this->work.'/doc.pdf', DocumentSourceType::Docx)))
            ->toThrow(ConverterNotConfiguredException::class, 'LIBREOFFICE_BIN');

        config()->set('pdftool.libreoffice.binary', $this->work.'/nao/existe/soffice');
        expect($converter->isConfigured())->toBeFalse();
    });

    it('com binário fake converte passando argumentos estruturados, perfil isolado e ambiente mínimo', function () {
        $log = $this->work.'/soffice-args.txt';
        config()->set('pdftool.libreoffice.binary', PdfFixtures::fakeSoffice());
        config()->set('pdftool.libreoffice.env', [
            'FAKE_SOFFICE_LOG' => $log,
            'FAKE_SOFFICE_PDF' => PdfFixtures::fakeConvertedPdf(),
        ]);
        file_put_contents($this->work.'/contrato final.docx', 'PK conteudo irrelevante para o fake');
        $out = $this->work.'/convertido.pdf';

        $converter = app(LibreOfficeConverter::class);
        expect($converter->isConfigured())->toBeTrue();

        $result = $converter->convert(new ConversionRequest($this->work.'/contrato final.docx', $out, DocumentSourceType::Docx, null, 'contrato final.docx'));

        expect($result->isReady())->toBeTrue()
            ->and($result->converter)->toBe(LibreOfficeConverter::NAME)
            ->and($result->pageCount())->toBe(1)
            ->and(is_file($out))->toBeTrue()
            ->and(is_file($log))->toBeTrue();

        $recorded = str_replace('\\', '/', (string) file_get_contents($log));
        $tmpRoot = str_replace('\\', '/', $this->tmpRoot);

        foreach (LibreOfficeConverter::ARGUMENTS as $flag) {
            expect($recorded)->toContain($flag);
        }
        expect($recorded)->toContain('--convert-to pdf')
            ->and($recorded)->toContain('--outdir '.$tmpRoot.'/lo-')
            ->and($recorded)->toContain('-env:UserInstallation=file:///')
            ->and($recorded)->toContain('/profile ')
            ->and($recorded)->toContain('/in/input.docx')
            ->and($recorded)->toContain('PROFILE_XCU=1')
            ->and($recorded)->toContain('FAKE_SOFFICE_LOG=')
            ->and($recorded)->not->toContain('APP_KEY=')
            ->and($recorded)->not->toContain('DB_PASSWORD=');

        // Diretório temporário exclusivo removido ao final.
        expect(glob($this->tmpRoot.'/*') ?: [])->toBe([]);
    });

    it('reporta failed quando o binário falha ou não gera saída', function () {
        config()->set('pdftool.libreoffice.binary', PdfFixtures::fakeSoffice());
        file_put_contents($this->work.'/doc.docx', 'x');
        $converter = app(LibreOfficeConverter::class);

        config()->set('pdftool.libreoffice.env', ['FAKE_SOFFICE_PDF' => PdfFixtures::fakeConvertedPdf(), 'FAKE_SOFFICE_FAIL' => '1']);
        $failed = $converter->convert(new ConversionRequest($this->work.'/doc.docx', $this->work.'/doc.pdf', DocumentSourceType::Docx));
        expect($failed->isFailed())->toBeTrue()
            ->and($failed->reasonCode)->toBe('libreoffice_failed')
            ->and($failed->details['exit_code'])->toBe(1);

        config()->set('pdftool.libreoffice.env', ['FAKE_SOFFICE_PDF' => PdfFixtures::fakeConvertedPdf(), 'FAKE_SOFFICE_NOOUT' => '1']);
        $noOutput = $converter->convert(new ConversionRequest($this->work.'/doc.docx', $this->work.'/doc.pdf', DocumentSourceType::Docx));
        expect($noOutput->isFailed())->toBeTrue()
            ->and($noOutput->reasonCode)->toBe('no_output')
            ->and(is_file($this->work.'/doc.pdf'))->toBeFalse();
    });

    it('gera URI file:/// e registro do perfil válidos', function () {
        expect(LibreOfficeConverter::fileUri('C:\\Users\\Nome Com Espaço\\tmp\\profile'))->toBe('file:///C:/Users/Nome%20Com%20Espa%C3%A7o/tmp/profile')
            ->and(LibreOfficeConverter::fileUri('/tmp/pdftool/abc/profile'))->toBe('file:///tmp/pdftool/abc/profile');

        $xml = LibreOfficeConverter::profileRegistry();
        expect(simplexml_load_string($xml))->not->toBeFalse()
            ->and($xml)->toContain('MacroSecurityLevel')
            ->and($xml)->toContain('DisableMacrosExecution')
            ->and($xml)->toContain('ooInetProxyType');
    });
});
