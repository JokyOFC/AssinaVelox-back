<?php

use App\Enums\CertificateEnvironment;
use App\Enums\CertificateKind;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\SignatureStatus;
use App\Enums\SigningOrder;
use App\Models\CertificateReference;
use App\Models\Envelope;
use App\Models\VerificationRecord;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de design — o comprovante promete o futuro de um documento pronto
|--------------------------------------------------------------------------
| `SignerPageProps::receiptState()` publica `completion_notice` chamando
| `ConsentText::completionNotice()` SEM argumento. Isso tem duas consequências na
| tela "Documento concluído":
|
|  1. o texto continua no futuro ("Ao final, este documento SERÁ concluído
|     como…") num documento que já foi concluído — observado no navegador no
|     comprovante do AV-00006, logo abaixo do título "Documento concluído"; e
|  2. mais grave, a frase é derivada da CONFIGURAÇÃO ATUAL da instalação, não do
|     `signature_status` que o envelope de fato registrou. Basta o certificado da
|     operadora ser ligado depois da conclusão para o comprovante de um arquivo
|     sem assinatura nenhuma passar a prometer "a AssinaVelox aplicará ao arquivo
|     uma assinatura criptográfica" — exatamente a afirmação que arquitetura §2
|     proíbe.
|
| O dado honesto já existe e já é usado nas outras três superfícies:
| `SignatureNarrative::for($envelope, $record)['statement']`.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-receipt-notice-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

/** Registro administrativo do A1 da operadora (mesma forma usada em Sign/SignerConsentTest). */
function reviewOperatorCertificate(): CertificateReference
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

/**
 * Assina com um único participante e força o desfecho real que a finalização
 * gravaria sem certificado: `completed` com `signature_status = none`.
 *
 * @return array{token: string, envelope: Envelope}
 */
function signedAndConcludedWithoutSignature(mixed $test): array
{
    $ctx = signerEnvelope([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]],
    ], SigningOrder::Parallel);

    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($test, $token);

    $test->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
        'fields' => [],
    ])->assertRedirect();

    /** @var Envelope $envelope */
    $envelope = Envelope::withoutOrganizationScope()->findOrFail($ctx['envelope']->getKey());

    $envelope->forceFill([
        'status' => EnvelopeStatus::Completed,
        'completed_at' => now(),
    ])->save();

    VerificationRecord::query()->firstOrCreate(
        ['envelope_id' => $envelope->getKey()],
        [
            'code' => $envelope->verification_code,
            'organization_id' => $envelope->organization_id,
            'final_document_version_id' => $envelope->sent_document_version_id,
            'original_sha256' => str_repeat('a', 64),
            'sent_sha256' => str_repeat('b', 64),
            'consolidated_sha256' => str_repeat('c', 64),
            'final_sha256' => str_repeat('d', 64),
            'signature_status' => SignatureStatus::None,
            'signature_profile' => null,
            'validation_result' => null,
            'validated_at' => null,
        ],
    );

    return ['token' => $token, 'envelope' => $envelope->fresh()];
}

it('não promete assinatura criptográfica num arquivo já concluído sem nenhuma', function () {
    $signed = signedAndConcludedWithoutSignature($this);

    // O certificado passa a existir DEPOIS da conclusão — trocar a configuração
    // não pode reescrever o que aconteceu com um arquivo que já está pronto.
    reviewOperatorCertificate();
    bindConfiguredSigner(true);

    $props = $this->get(route('sign.show', ['token' => $signed['token']]))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['screen'])->toBe('completed')
        ->and($props['receipt']['completion_notice'])
        ->not->toContain('assinatura criptográfica com certificado');
});

it('não fala no futuro sobre um documento que já foi concluído', function () {
    $signed = signedAndConcludedWithoutSignature($this);

    $props = $this->get(route('sign.show', ['token' => $signed['token']]))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['screen'])->toBe('completed')
        ->and($props['receipt']['completion_notice'])->not->toContain('Ao final');
});
