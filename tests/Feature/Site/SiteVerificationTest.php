<?php

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Verification/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Verificação pública pelo site institucional (docs/site-institucional.md)
|--------------------------------------------------------------------------
| A mesma consulta e o mesmo resultado publicável da página /verificar, em JSON e sem sessão.
| O contrato fechado de chaves e a resposta uniforme continuam sendo garantidos pelos testes
| de tests/Feature/Verification; aqui se garante que o site recebe EXATAMENTE aquilo.
*/

test('a consulta pelo site devolve o mesmo resultado publicável da página /verificar', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Imobiliária Aurora']);

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed, [
        ['name' => 'Maria Aparecida Silva', 'email' => 'maria@exemplo.test', 'signed' => true, 'role_label' => 'Locatária'],
    ]);
    $record = finalizeEnvelope($envelope);

    $response = $this->getJson(route('site.verify.show', ['code' => strtolower($envelope->formatted_verification_code)]))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Cache-Control', 'no-store, private');

    $json = $response->json();

    expect($json['found'])->toBeTrue()
        ->and($json['code'])->toBe($envelope->verification_code)
        ->and($json['result']['organization_name'])->toBe('Imobiliária Aurora')
        ->and($json['result']['hashes']['final_sha256'])->toBe($record->final_sha256)
        ->and($json['result']['recipients'][0]['name_masked'])->toBe('Maria A. S.')
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();

    $web = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];

    expect($json['result'])->toEqual($web);
});

test('código inexistente e código de rascunho devolvem exatamente a mesma resposta', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $draft = Envelope::factory()->forOrganization($organization, $owner)->draft()->create([
        'verification_code' => Envelope::generateVerificationCode(),
    ]);

    $missing = $this->getJson(route('site.verify.show', ['code' => 'ABCDEFGHJKLM']))->assertOk()->json();
    $unpublished = $this->getJson(route('site.verify.show', ['code' => $draft->verification_code]))->assertOk()->json();

    expect($missing)->toBe(['code' => 'ABCDEFGHJKLM', 'found' => false, 'result' => null])
        ->and($unpublished)->toBe(['code' => $draft->verification_code, 'found' => false, 'result' => null]);
});

test('a conferência por resumo responde igual à da página, em JSON e sem sessão', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    $record = finalizeEnvelope($envelope);

    $url = route('site.verify.check_file', ['code' => $envelope->verification_code]);

    $this->postJson($url, ['sha256' => strtoupper($record->final_sha256)])
        ->assertOk()
        ->assertJson(['code' => $envelope->verification_code, 'file_check' => ['matches' => 'signed', 'checked_sha256' => $record->final_sha256]]);

    $this->postJson($url, ['sha256' => $record->sent_sha256])
        ->assertOk()
        ->assertJsonPath('file_check.matches', 'original');

    $this->postJson($url, ['sha256' => str_repeat('a', 64)])
        ->assertOk()
        ->assertJsonPath('file_check.matches', 'none');

    // Código sem documento publicável: a mesma forma de resposta ("não confere").
    $this->postJson(route('site.verify.check_file', ['code' => 'ABCDEFGHJKLM']), ['sha256' => $record->final_sha256])
        ->assertOk()
        ->assertJsonPath('file_check.matches', 'none');
});

test('a conferência recusa o que não é um resumo SHA-256, com erro em problem+json', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope);

    $this->postJson(route('site.verify.check_file', ['code' => $envelope->verification_code]), ['sha256' => 'isto-nao-e-um-hash'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('errors.sha256.0', 'Informe o resumo SHA-256 do arquivo: 64 caracteres hexadecimais.');
});
