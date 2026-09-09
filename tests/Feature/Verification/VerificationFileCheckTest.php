<?php

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use Illuminate\Http\UploadedFile;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Conferência de arquivo e limites da página pública (ROUTES §1.4, Q14)
|--------------------------------------------------------------------------
| A comparação padrão acontece no NAVEGADOR (WebCrypto). Esta rota confere um
| resumo já calculado; ela não aceita — e não pode aceitar — o arquivo em si.
*/

test('a página entrega os hashes de que o cálculo no navegador precisa', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    $record = finalizeEnvelope($envelope);

    $result = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];

    expect($result['hashes']['final_sha256'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($result['hashes']['final_sha256'])->toBe($record->final_sha256)
        ->and($result['hash_primer'])->toContain('não é uma assinatura');
});

test('um resumo igual ao do arquivo final confere', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    $record = finalizeEnvelope($envelope);

    $this->post(route('verify.check_file', ['code' => $envelope->verification_code]), [
        'sha256' => strtoupper($record->final_sha256),
    ])->assertRedirect()->assertSessionHas('file_check', [
        'matches' => 'signed',
        'checked_sha256' => $record->final_sha256,
    ]);
});

test('um resumo igual ao da versão enviada confere como enviado, não como final', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    $record = finalizeEnvelope($envelope);

    $this->post(route('verify.check_file', ['code' => $envelope->verification_code]), [
        'sha256' => $record->sent_sha256,
    ])->assertSessionHas('file_check.matches', 'original');
});

test('um resumo qualquer não confere', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope);

    $this->post(route('verify.check_file', ['code' => $envelope->verification_code]), [
        'sha256' => hash('sha256', 'outro arquivo'),
    ])->assertSessionHas('file_check.matches', 'none');
});

test('a conferência num código inexistente responde igual a uma conferência que não bate', function () {
    $this->post(route('verify.check_file', ['code' => Envelope::generateVerificationCode()]), [
        'sha256' => hash('sha256', 'qualquer'),
    ])->assertRedirect()->assertSessionHas('file_check.matches', 'none');
});

test('a conferência recusa qualquer coisa que não seja um resumo SHA-256', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope);

    foreach (['', 'abc', str_repeat('z', 64), hash('sha256', 'x').'0'] as $invalid) {
        $this->post(route('verify.check_file', ['code' => $envelope->verification_code]), ['sha256' => $invalid])
            ->assertSessionHasErrors('sha256');
    }
});

test('a rota de conferência não aceita upload de arquivo — decisão registrada', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope);

    // Mandar só o arquivo, sem o resumo, é erro de validação: não há caminho de upload.
    $this->post(route('verify.check_file', ['code' => $envelope->verification_code]), [
        'file' => UploadedFile::fake()->create('contrato.pdf', 10, 'application/pdf'),
    ])->assertSessionHasErrors('sha256');

    // E a política de privacidade §11 afirma exatamente isso por escrito.
    expect(file_get_contents(base_path('docs/juridico/politica-de-privacidade.md')))
        ->toContain('o arquivo não é enviado');
});

test('a consulta pública é limitada por IP', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::InProgress, [
        ['name' => 'Ana Costa', 'email' => 'ana@exemplo.test'],
    ]);

    // throttle:20,1 na rota (ROUTES §1.4).
    for ($i = 0; $i < 20; $i++) {
        $this->get(route('verify.show', ['code' => $envelope->verification_code]))->assertOk();
    }

    $this->get(route('verify.show', ['code' => $envelope->verification_code]))->assertStatus(429);
});

test('a conferência de resumo tem limite mais apertado que a consulta', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner);
    finalizeEnvelope($envelope);

    for ($i = 0; $i < 10; $i++) {
        $this->post(route('verify.check_file', ['code' => $envelope->verification_code]), [
            'sha256' => hash('sha256', (string) $i),
        ])->assertRedirect();
    }

    $this->post(route('verify.check_file', ['code' => $envelope->verification_code]), [
        'sha256' => hash('sha256', 'estouro'),
    ])->assertStatus(429);
});
