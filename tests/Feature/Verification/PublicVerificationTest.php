<?php

use App\Enums\EnvelopeStatus;
use App\Models\CertificateReference;
use App\Models\Envelope;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Verificação pública por código (arquitetura §6, ROUTES §4)
|--------------------------------------------------------------------------
*/

test('o formulário de verificação responde a qualquer visitante', function () {
    $response = $this->get(route('verify.index'));

    assertInertiaComponent($response, 'verify/index');
});

test('um código de envelope concluído mostra estado, remetente, datas, hashes e participantes', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Imobiliária Aurora']);

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed, [
        ['name' => 'Maria Aparecida Silva', 'email' => 'maria@exemplo.test', 'signed' => true, 'role_label' => 'Locatária'],
    ]);
    $record = finalizeEnvelope($envelope);

    $props = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])));

    expect($props['found'])->toBeTrue();

    $result = $props['result'];

    expect($result['status'])->toBe('completed')
        ->and($result['organization_name'])->toBe('Imobiliária Aurora')
        ->and($result['title'])->toBe('Contrato de locação residencial')
        ->and($result['pages'])->toBe(4)
        ->and($result['created_at'])->not->toBeNull()
        ->and($result['sent_at'])->not->toBeNull()
        ->and($result['completed_at'])->not->toBeNull()
        ->and($result['hashes']['sent_sha256'])->toBe($record->sent_sha256)
        ->and($result['hashes']['final_sha256'])->toBe($record->final_sha256)
        ->and($result['recipients'][0]['name_masked'])->toBe('Maria A. S.')
        ->and($result['recipients'][0]['role'])->toBe('Locatária')
        ->and($result['recipients'][0]['status'])->toBe('signed');
});

test('o código é aceito com ou sem hífens e em qualquer caixa', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope);

    $formatted = $envelope->formatted_verification_code;

    foreach ([$envelope->verification_code, $formatted, strtolower($formatted)] as $input) {
        $props = verifyProps($this->get(route('verify.show', ['code' => $input])));

        expect($props['found'])->toBeTrue()
            ->and($props['code'])->toBe($envelope->verification_code);
    }
});

test('código inexistente e código de rascunho devolvem exatamente a mesma resposta', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    // Rascunho com código: o código só nasce no envio, mas a página não pode depender disso.
    $draft = Envelope::factory()->forOrganization($organization, $owner)->draft()->create([
        'verification_code' => Envelope::generateVerificationCode(),
    ]);

    $missing = Envelope::generateVerificationCode();

    $draftProps = verifyProps($this->get(route('verify.show', ['code' => $draft->verification_code])));
    $missingProps = verifyProps($this->get(route('verify.show', ['code' => $missing])));

    expect($draftProps['found'])->toBeFalse()
        ->and($missingProps['found'])->toBeFalse()
        ->and($draftProps['result'])->toBeNull()
        ->and($missingProps['result'])->toBeNull()
        // Mesma forma, mesmas chaves: só o código ecoado difere.
        ->and(array_keys($draftProps))->toBe(array_keys($missingProps))
        ->and(Arr::only($draftProps, ['found', 'result', 'file_check']))
        ->toBe(Arr::only($missingProps, ['found', 'result', 'file_check']));
});

test('envelopes ainda não enviados nunca aparecem, qualquer que seja o estado de rascunho', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    foreach (['draft', 'preparing', 'ready'] as $state) {
        $envelope = Envelope::factory()->forOrganization($organization, $owner)->{$state}()->create([
            'verification_code' => Envelope::generateVerificationCode(),
        ]);

        expect(verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['found'])
            ->toBeFalse();
    }
});

test('um envelope excluído desaparece da verificação pública', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope);
    $code = $envelope->verification_code;

    $envelope->delete();

    expect(verifyProps($this->get(route('verify.show', ['code' => $code])))['found'])->toBeFalse();
});

test('um registro de verificação revogado deixa de responder, sem revelar o motivo', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    $record = finalizeEnvelope($envelope);
    $record->forceFill(['revoked_at' => now()])->save();

    $props = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])));

    expect($props['found'])->toBeFalse()
        ->and($props['result'])->toBeNull();
});

test('envelope em andamento mostra o estado sem vazar nada do que ainda não existe', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::InProgress, [
        ['name' => 'João Pedro Nunes', 'email' => 'joao@exemplo.test'],
        ['name' => 'Ana Costa', 'email' => 'ana@exemplo.test'],
    ]);

    $result = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];

    expect($result['status'])->toBe('in_progress')
        ->and($result['status_label'])->toBe('Em andamento')
        ->and($result['completed_at'])->toBeNull()
        ->and($result['hashes']['final_sha256'])->toBeNull()
        ->and($result['hashes']['sent_sha256'])->not->toBeNull()
        ->and($result['certificate'])->toBeNull()
        ->and($result['signature_state'])->toBe('pending')
        ->and($result['validation']['available'])->toBeFalse()
        ->and($result['recipients'])->toHaveCount(2)
        ->and($result['recipients'][0]['name_masked'])->toBe('João P. N.');
});

test('finalizing aparece como em andamento — o estágio do pipeline não é assunto público', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Finalizing);

    $result = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];

    expect($result['status'])->toBe('in_progress')
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('finalizing');
});

test('envelope recusado, expirado e cancelado respondem com o próprio estado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $cases = [
        EnvelopeStatus::Refused->value => 'Recusado',
        EnvelopeStatus::Expired->value => 'Expirado',
        EnvelopeStatus::Canceled->value => 'Cancelado',
    ];

    foreach ($cases as $status => $label) {
        $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::from($status));

        $result = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];

        expect($result['status'])->toBe($status)
            ->and($result['status_label'])->toBe($label)
            ->and($result['hashes']['final_sha256'])->toBeNull()
            ->and($result['signature_statement'])->not->toContain('assinatura digital');
    }
});

test('a resposta pública não contém e-mail, IP, user agent, token, nome completo, campo nem PDF', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed, [
        ['name' => 'Maria Aparecida Silva', 'email' => 'maria.aparecida@exemplo.test', 'signed' => true],
    ], ['message' => 'Segue o contrato conforme combinamos por telefone.']);
    finalizeEnvelope($envelope);

    $recipient = $envelope->recipients()->first();
    $acceptance = $recipient->acceptance;

    $payload = json_encode(
        verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code]))),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    );

    expect($payload)
        ->not->toContain('maria.aparecida@exemplo.test')
        ->not->toContain('Maria Aparecida Silva')
        ->not->toContain($acceptance->ip_address)
        ->not->toContain($acceptance->user_agent)
        ->not->toContain('Segue o contrato conforme combinamos')
        ->not->toContain($envelope->ulid)
        ->not->toContain($recipient->ulid)
        ->not->toContain($envelope->display_code)
        ->not->toContain('/documentos/')
        ->not->toContain('download')
        ->not->toContain($owner->name)
        ->not->toContain($owner->email);
});

test('o consentimento gravado e os valores de campo não vazam na página pública', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope);

    $acceptance = $envelope->recipients()->first()->acceptance;
    $acceptance->forceFill(['fields_snapshot' => [['label' => 'CPF', 'value' => '123.456.789-00']]])->save();

    $payload = json_encode(
        verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code]))),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    );

    expect($payload)
        ->not->toContain('123.456.789-00')
        ->not->toContain($acceptance->consent_statement);
});

test('os hashes publicados são os do registro de verificação, e o original não é publicado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    $record = finalizeEnvelope($envelope);

    $result = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];

    expect($result['hashes']['sent_sha256'])->toBe($record->sent_sha256)
        ->and($result['hashes']['final_sha256'])->toBe($record->final_sha256)
        // Alias do contrato atual do front: "original_sha256" é o resumo ENVIADO.
        ->and($result['hashes']['original_sha256'])->toBe($record->sent_sha256)
        ->and($result['hashes']['signed_sha256'])->toBe($record->final_sha256)
        ->and($result['hashes'])->not->toHaveKey('consolidated_sha256');

    $payload = json_encode($result, JSON_THROW_ON_ERROR);

    expect($payload)->not->toContain($record->original_sha256)
        ->and($payload)->not->toContain($record->consolidated_sha256);
});

test('sem certificado a página diz aceite eletrônico com evidências e nunca assinatura digital', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope, 'none');

    $result = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];

    expect($result['signature_status'])->toBe('none')
        ->and($result['certificate'])->toBeNull()
        ->and($result['signature_profile'])->toBeNull()
        ->and($result['status_label'])->toBe('Concluído · aceite eletrônico com evidências')
        ->and($result['signature_statement'])->toContain('sem assinatura criptográfica')
        ->and($result['signature_statement'])->not->toContain('assinatura digital')
        ->and($result['signature_statement'])->not->toContain('assinado digitalmente')
        ->and($result['signature_statement'])->not->toContain('ICP-Brasil')
        ->and($result['validation']['available'])->toBeFalse();
});

test('com certificado A1 a página identifica a operadora sem prometer ICP-Brasil pessoal', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $certificate = CertificateReference::factory()->active()->create();

    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope, 'company_a1', $certificate, validationSummary());

    $result = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];

    expect($result['signature_status'])->toBe('company_a1')
        ->and($result['signature_profile'])->toBe('PAdES-B-B')
        ->and($result['certificate']['subject_cn'])->toBe('AssinaVelox Teste')
        ->and($result['certificate']['issuer_cn'])->toBe('AssinaVelox Test CA')
        ->and($result['certificate']['valid_to'])->not->toBeNull()
        ->and($result['certificate']['environment'])->toBe('test')
        ->and($result['certificate']['is_test'])->toBeTrue()
        ->and($result['signature_statement'])->toContain('AMBIENTE DE TESTE')
        ->and($result['signature_statement'])->toContain('Não é a assinatura pessoal de nenhum participante');

    // O segredo do certificado nunca acompanha o metadado.
    expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain($certificate->secret_ref)
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain($certificate->fingerprint_sha256);
});

test('o resultado técnico da validação nunca inventa confiança nem revogação verificada', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $certificate = CertificateReference::factory()->active()->create();
    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope, 'company_a1', $certificate, validationSummary(intact: true, trustRoots: 0));

    $validation = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result']['validation'];

    expect($validation['available'])->toBeTrue()
        ->and($validation['integrity'])->toBe('intact')
        ->and($validation['chain_trust'])->toBe('not_verified')
        ->and($validation['chain_trust_label'])->toContain('NÃO verificada')
        ->and($validation['revocation'])->toBe('not_checked')
        ->and($validation['revocation_label'])->toContain('NÃO verificada');
});

test('validação que não confirma integridade é apresentada como inconclusiva, não como sucesso', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $certificate = CertificateReference::factory()->active()->create();
    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope, 'company_a1', $certificate, validationSummary(intact: false));

    $validation = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result']['validation'];

    expect($validation['integrity'])->toBe('broken')
        ->and($validation['integrity_label'])->toContain('NÃO confirmou');
});

test('assinatura sem resultado de validação registrado não vira integridade confirmada', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $certificate = CertificateReference::factory()->active()->create();
    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope, 'company_a1', $certificate, null);

    $validation = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result']['validation'];

    expect($validation['available'])->toBeFalse()
        ->and($validation['integrity'])->toBe('unknown');
});

test('a página de verificação pede noindex e não envia referrer, inclusive quando não encontra', function () {
    $this->get(route('verify.show', ['code' => Envelope::generateVerificationCode()]))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});
