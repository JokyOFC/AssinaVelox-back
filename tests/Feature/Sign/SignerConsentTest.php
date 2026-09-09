<?php

use App\Enums\CertificateEnvironment;
use App\Enums\CertificateKind;
use App\Enums\SigningOrder;
use App\Models\CertificateReference;
use App\Models\SignatureAcceptance;
use App\Services\Signing\ConsentText;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Texto de aceite e semântica de assinatura (arquitetura §2, docs/juridico)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/signer-consent-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('sem certificado da operadora, o texto diz aceite eletrônico com evidências e nada mais', function () {
    $ctx = signerEnvelope();
    $props = authenticateSigner($this, $ctx['tokens']['maria@exemplo.test']);

    $texto = $props['consent_text'];

    expect(CertificateReference::query()->count())->toBe(0)
        ->and($texto)->toContain('aceite eletrônico com evidências, sem assinatura criptográfica')
        ->and($props['consent']['completion_notice'])->toContain('sem assinatura criptográfica')
        // Nunca se promete assinatura que não vai existir.
        ->and($texto)->not->toContain('aplicará ao arquivo final uma assinatura criptográfica')
        ->and($texto)->not->toContain('ICP-Brasil')
        ->and($texto)->not->toContain('assinado digitalmente por');
});

/**
 * Registro administrativo do certificado da Operadora, ativo e na validade.
 * Sozinho ele NÃO assina nada — ver o teste do adaptador desligado, abaixo.
 */
if (! function_exists('registerOperatorCertificate')) {
    function registerOperatorCertificate(): CertificateReference
    {
        return CertificateReference::query()->create([
            'organization_id' => null,
            'name' => 'A1 da operadora (teste)',
            'kind' => CertificateKind::CompanyA1,
            'environment' => CertificateEnvironment::Test,
            'secret_ref' => 'ASSINAVELOX_CERT_PASSPHRASE',
            'subject' => 'CN=AssinaVelox',
            'issuer' => 'CN=Teste',
            'not_before' => now()->subDay(),
            'not_after' => now()->addYear(),
            'is_active' => true,
        ]);
    }
}

it('com certificado da operadora ativo E adaptador configurado, o texto explica de quem é a assinatura', function () {
    registerOperatorCertificate();
    bindConfiguredSigner(true);

    $ctx = signerEnvelope();
    $props = authenticateSigner($this, $ctx['tokens']['maria@exemplo.test']);

    $texto = $props['consent_text'];

    expect($texto)->toContain('certificado digital de sua própria titularidade')
        ->toContain('não é a minha assinatura pessoal')
        ->and($props['consent']['completion_notice'])->toContain('não é a sua assinatura pessoal');
});

it('com o registro do certificado mas o adaptador desligado, não promete assinatura criptográfica', function () {
    // A combinação real encontrada na integração: o seeder cria a linha de
    // `certificate_references`, mas nenhuma variável COMPANY_CERT_* existe, então o
    // contêiner entrega o NullPdfSigner. Prometer a assinatura aqui seria descrever
    // um arquivo final que nunca vai existir (arquitetura §2).
    registerOperatorCertificate();
    bindConfiguredSigner(false);

    $ctx = signerEnvelope();
    $props = authenticateSigner($this, $ctx['tokens']['maria@exemplo.test']);

    expect(ConsentText::operatorCertificateActive())->toBeFalse()
        ->and($props['consent_text'])->toContain('aceite eletrônico com evidências, sem assinatura criptográfica')
        ->and($props['consent']['completion_notice'])->toContain('sem assinatura criptográfica')
        ->and($props['consent_text'])->not->toContain('certificado digital de sua própria titularidade');
});

it('o rótulo do checkbox traz o título e a versão vem do envelope', function () {
    $ctx = signerEnvelope();
    $props = authenticateSigner($this, $ctx['tokens']['maria@exemplo.test']);

    expect($props['consent']['checkbox_label'])->toContain('Contrato de locação')
        ->toContain('constituem evidência do meu aceite eletrônico')
        ->and($props['consent']['version'])->toBe('v1-2026-09-08')
        ->and(ConsentText::ACCEPTANCE_TERMS_VERSION)->toBe('v1-2026-09-08');
});

it('o aceite grava o texto integral resolvido, não uma referência a template', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect();

    $acceptance = SignatureAcceptance::query()->sole();

    expect($acceptance->consent_statement)->toBe($props['consent_text'])
        ->and($acceptance->consent_statement)->not->toContain('{{')
        ->and($acceptance->terms_version)->toBe($props['consent']['version']);
});

it('o envelope mantém a versão congelada mesmo se a constante mudar durante a coleta', function () {
    $ctx = signerEnvelope([], SigningOrder::Sequential, ['terms_version' => 'v0-2026-01-01']);

    $props = authenticateSigner($this, $ctx['tokens']['maria@exemplo.test']);

    expect($props['consent']['version'])->toBe('v0-2026-01-01')
        ->and($props['consent_text'])->toContain('versão v0-2026-01-01');
});

it('o aviso de privacidade aparece na etapa de identificação, antes de qualquer código', function () {
    $ctx = signerEnvelope();

    $props = $this->get(route('sign.show', ['token' => $ctx['tokens']['maria@exemplo.test']]))
        ->viewData('page')['props'];

    expect($props['privacy']['version'])->toBe(ConsentText::PRIVACY_NOTICE_VERSION)
        ->and($props['privacy']['summary'])->toContain('data, IP, navegador')
        ->and($props['privacy']['notice'])->toContain('A abertura deste link é registrada')
        ->and($props['privacy']['notice'])->toContain('abertura detectada')
        ->and($props['privacy']['notice'])->toContain('Não pedimos senha, CPF, foto ou localização');
});
