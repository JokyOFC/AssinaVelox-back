<?php

use App\Enums\AuthMethod;
use App\Enums\FieldType;
use App\Models\SigningField;
use App\Services\Identity\IdentityCaptures;
use App\Services\Signing\Channels\SenderPins;
use App\Services\Signing\ConsentText;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/Identity/Support/IdentityHelpers.php';

/*
|--------------------------------------------------------------------------
| Textos legais por participante na onda B (integração I-2B)
|--------------------------------------------------------------------------
| Achado da QA: para quem confirma o código por SMS, a tela dizia "código confirmado por
| e-mail", a declaração gravada dizia "código de uso único confirmado no e-mail acima" e o aviso
| de privacidade dizia "Não pedimos senha, CPF, foto" mesmo com PIN, campo CPF e selfie. Agora o
| texto acompanha o participante; só e-mail continua exatamente o de antes.
*/

function consentWaveBContext(): array
{
    $ctx = signerEnvelope();
    $ctx['recipient'] = $ctx['recipients']['maria@exemplo.test'];

    return $ctx;
}

it('participante só por e-mail: textos e versões idênticos aos de antes', function () {
    $ctx = consentWaveBContext();
    $r = $ctx['recipient'];

    expect(ConsentText::versionFor($ctx['envelope'], $r))->toBe($ctx['envelope']->terms_version)
        ->and(ConsentText::privacyNoticeVersion($r->role, $r))->toBe(ConsentText::PRIVACY_NOTICE_VERSION)
        ->and(ConsentText::privacySummary($ctx['organization'], $r->role, $r))->toBe(ConsentText::privacySummary($ctx['organization'], $r->role))
        ->and(ConsentText::privacyNotice($ctx['organization'], $r->role, $r))->toBe(ConsentText::privacyNotice($ctx['organization'], $r->role))
        ->and(ConsentText::statement($ctx['envelope'], $r, $ctx['organization'], str_repeat('a', 64), false))->toContain('(código de uso único confirmado no e-mail acima)');
});

it('SMS + PIN + CPF + selfie: a tela, a declaração e o aviso dizem o que acontece de fato', function () {
    $ctx = consentWaveBContext();
    $r = $ctx['recipient'];
    identityEnableFlags($ctx['organization'], ['identity_capture']);

    $r->forceFill(['auth_method' => AuthMethod::SmsOtp, 'phone' => '+5511912345678'])->save();
    app(SenderPins::class)->set($r, '48291573');
    SigningField::withoutOrganizationScope()->where('recipient_id', $r->id)->firstOrFail()->replicate()->forceFill(['ulid' => (string) Str::ulid(), 'type' => FieldType::Cpf, 'y' => 0.5])->save();
    app(IdentityCaptures::class)->setRequirement($ctx['envelope'], $r, ['selfie'], null);
    $r->refresh();

    $statement = ConsentText::statement($ctx['envelope'], $r, $ctx['organization'], str_repeat('a', 64), false);
    $notice = ConsentText::privacyNotice($ctx['organization'], $r->role, $r);

    // Revisão adversarial da onda B: o SMS deste teste sai pelo SIMULADOR, e o texto agora diz
    // que nada foi transmitido — "mudou uma palavra, nova versão" (ConsentText, regra 1): `+b2`.
    expect(ConsentText::versionFor($ctx['envelope'], $r))->toBe($ctx['envelope']->terms_version.'+b2')
        ->and(strlen(ConsentText::WITNESS_MULTI_TERMS_VERSION.ConsentText::WAVE_B_VERSION_SUFFIX))->toBeLessThanOrEqual(32)
        ->and(ConsentText::privacySummary($ctx['organization'], $r->role, $r))->toContain('código confirmado por SMS e PIN combinado com a remetente')
        ->and(ConsentText::checkboxLabel($ctx['envelope'], $r))->toContain('código confirmado por SMS e PIN')
        ->and($statement)->toContain('versão '.$ctx['envelope']->terms_version.'+b2')
        ->and($statement)->toContain('envio por SMS simulado nesta instalação')
        ->and($statement)->toContain('(código de uso único enviado por SMS ao celular informado pela remetente, seguido do PIN combinado com a remetente)')
        ->and($statement)->toContain('as fotos que enviei nesta tela (foto do rosto), guardadas como registro, sem verificação de identidade')
        ->and($statement)->not->toContain('confirmado no e-mail acima')
        ->and($notice)->toContain('o código de confirmação enviado por SMS ao seu celular')
        ->and($notice)->toContain('conferido apenas pelos dígitos')
        ->and($notice)->toContain('sem comparação de rostos')
        ->and($notice)->toContain('Não pedimos localização.')
        ->and($notice)->not->toContain('Não pedimos senha, CPF, foto');

    foreach ([$statement, $notice] as $text) {
        foreach (['biometria', 'identidade verificada', 'assinatura avançada', 'qualificada', 'reconhecimento de firma', '48291573'] as $forbidden) {
            expect(mb_strtolower($text))->not->toContain($forbidden);
        }
    }
});
