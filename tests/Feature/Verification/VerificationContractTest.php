<?php

use App\Enums\EnvelopeStatus;
use App\Models\CertificateReference;
use App\Services\Verification\NameMask;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Contrato do que sai da verificação pública (ROUTES §4.3)
|--------------------------------------------------------------------------
| §4.3 é uma lista de PROIBIÇÕES. Um teste que só verifica o que está presente
| não a protege: o campo indevido entra numa alteração futura e ninguém percebe.
| Por isso aqui a asserção é sobre o conjunto FECHADO de chaves.
*/

test('o resultado público expõe exatamente as chaves previstas, e nenhuma a mais', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $certificate = CertificateReference::factory()->active()->create();
    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope, 'company_a1', $certificate, validationSummary());

    $result = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];

    expect(array_keys($result))->toEqualCanonicalizing([
        'verification_code', 'status', 'status_label', 'title', 'organization_name',
        'created_at', 'sent_at', 'completed_at', 'pages', 'hashes', 'hash_primer',
        'signature_status', 'signature_state', 'signature_label', 'signature_statement',
        'signature_profile', 'certificate', 'validation', 'validation_summary',
        'verify_url', 'recipients', 'events_summary',
    ]);

    expect(array_keys($result['recipients'][0]))->toEqualCanonicalizing([
        'name_masked', 'role', 'status', 'status_label', 'signed_at',
    ]);

    expect(array_keys($result['hashes']))->toEqualCanonicalizing([
        'sent_sha256', 'final_sha256', 'original_sha256', 'signed_sha256',
    ]);

    expect(array_keys($result['certificate']))->toEqualCanonicalizing([
        'subject_cn', 'subject', 'issuer_cn', 'issuer', 'serial', 'valid_from', 'valid_to',
        'policy', 'environment', 'environment_label', 'is_test',
    ]);
});

test('os marcos da linha do tempo saem do domínio, não da trilha com IP e user agent', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed, [
        ['name' => 'Maria Aparecida Silva', 'email' => 'maria@exemplo.test', 'signed' => true],
    ]);
    finalizeEnvelope($envelope);

    $summary = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result']['events_summary'];

    $labels = array_column($summary, 'label');

    expect($labels)->toContain('Documento enviado para assinatura')
        ->and($labels)->toContain('Aceite registrado · Maria A. S.')
        ->and($labels)->toContain('Documento concluído');

    foreach ($summary as $milestone) {
        expect(array_keys($milestone))->toBe(['label', 'occurred_at'])
            ->and($milestone['label'])->not->toContain('Maria Aparecida Silva');
    }

    // Os marcos estão em ordem cronológica.
    $timestamps = array_column($summary, 'occurred_at');
    $sorted = $timestamps;
    sort($sorted);
    expect($timestamps)->toBe($sorted);
});

test('o nome mascarado preserva o primeiro nome e abrevia o resto', function () {
    expect(NameMask::mask('Maria Aparecida Silva'))->toBe('Maria A. S.')
        ->and(NameMask::mask('João'))->toBe('João')
        ->and(NameMask::mask('  Ana   Beatriz  Cardoso '))->toBe('Ana B. C.')
        // Partículas não viram inicial: "Maria d. S." não informaria nada.
        ->and(NameMask::mask('Maria da Silva'))->toBe('Maria S.')
        ->and(NameMask::mask('Luís de Souza dos Santos'))->toBe('Luís S. S.')
        ->and(NameMask::mask('ana paula'))->toBe('ana P.')
        ->and(NameMask::mask('Ángela Núñez'))->toBe('Ángela N.')
        ->and(NameMask::mask(''))->toBe('—')
        ->and(NameMask::mask(null))->toBe('—');
});

test('a mesma abreviação vale na lista de participantes e nos marcos', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed, [
        ['name' => 'Carlos Eduardo de Almeida', 'email' => 'carlos@exemplo.test', 'signed' => true],
    ]);
    finalizeEnvelope($envelope);

    $result = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];

    expect($result['recipients'][0]['name_masked'])->toBe('Carlos E. A.')
        ->and($result['events_summary'])->toContain([
            'label' => 'Aceite registrado · Carlos E. A.',
            'occurred_at' => $envelope->recipients()->first()->signed_at->toIso8601String(),
        ]);
});

test('a página pública não entrega nenhuma URL de documento, miniatura ou download', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope);

    $payload = json_encode(
        verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code]))),
        JSON_THROW_ON_ERROR,
    );

    foreach (['pdf_url', 'downloads', 'signature_image', '/paginas/', 'preview', 'storage'] as $forbidden) {
        expect($payload)->not->toContain($forbidden);
    }
});
