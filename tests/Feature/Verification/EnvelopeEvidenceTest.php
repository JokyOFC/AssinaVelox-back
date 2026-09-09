<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Models\AuditEvent;
use App\Models\CertificateReference;
use App\Models\Envelope;
use App\Support\OrganizationSettings;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Página de evidências autenticada (ROUTES §2.8)
|--------------------------------------------------------------------------
*/

function evidenceEnvelope(): array
{
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Imobiliária Aurora']);

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed, [
        ['name' => 'Maria Aparecida Silva', 'email' => 'maria@exemplo.test', 'signed' => true, 'role_label' => 'Locatária'],
    ]);

    return [$organization, $owner, $envelope];
}

test('a página de evidências exige autenticação', function () {
    [, , $envelope] = evidenceEnvelope();

    $this->get(route('envelopes.evidence', $envelope))->assertRedirect(route('login'));
});

test('um usuário de outra organização recebe 404, não 403', function () {
    [, , $envelope] = evidenceEnvelope();

    ['organization' => $other, 'owner' => $intruder] = createOrganizationWithOwner();
    actingAsMember($intruder, $other);

    // 403 já confirmaria que o documento existe: o binding escopado devolve 404.
    $this->get(route('envelopes.evidence', $envelope))->assertNotFound();
});

test('um member não vê o envelope de outra pessoa da mesma organização', function () {
    [$organization, , $envelope] = evidenceEnvelope();

    $member = attachMember($organization, MembershipRole::Member);
    actingAsMember($member, $organization);

    $this->get(route('envelopes.evidence', $envelope))->assertForbidden();
});

test('a página monta identificação, participantes, trilha, hashes e situação da assinatura', function () {
    [$organization, $owner, $envelope] = evidenceEnvelope();
    $record = finalizeEnvelope($envelope);

    actingAsMember($owner, $organization);

    $response = $this->get(route('envelopes.evidence', $envelope));
    assertInertiaComponent($response, 'envelopes/evidence');

    $props = verifyProps($response);

    expect($props['envelope']['title'])->toBe('Contrato de locação residencial')
        ->and($props['envelope']['verification_code'])->toBe($envelope->formatted_verification_code)
        ->and($props['organization']['name'])->toBe('Imobiliária Aurora')
        ->and($props['recipients'][0]['name'])->toBe('Maria Aparecida Silva')
        ->and($props['recipients'][0]['email'])->toBe('maria@exemplo.test')
        ->and($props['recipients'][0]['role'])->toBe('Locatária')
        ->and($props['recipients'][0]['consent_text'])->not->toBeNull()
        ->and($props['recipients'][0]['document_sha256'])->toBe($envelope->sentVersion->sha256)
        // O dossiê interno mostra os quatro resumos; a página pública, só dois.
        ->and($props['hashes']['original_sha256'])->toBe($record->original_sha256)
        ->and($props['hashes']['sent_sha256'])->toBe($record->sent_sha256)
        ->and($props['hashes']['consolidated_sha256'])->toBe($record->consolidated_sha256)
        ->and($props['hashes']['final_sha256'])->toBe($record->final_sha256)
        ->and($props['hashes']['signed_sha256'])->toBe($record->final_sha256)
        ->and($props['hashes']['evidence_sha256'])->not->toBeNull()
        ->and($props['signature_status'])->toBe('none')
        ->and($props['verify_url'])->toBe(route('verify.show', ['code' => $envelope->verification_code]));
});

test('os quatro hashes vêm com a explicação de quais bytes cada um identifica', function () {
    [$organization, $owner, $envelope] = evidenceEnvelope();
    $record = finalizeEnvelope($envelope);

    actingAsMember($owner, $organization);

    $items = verifyProps($this->get(route('envelopes.evidence', $envelope)))['hashes']['items'];

    expect(array_column($items, 'key'))->toBe(['original', 'sent', 'consolidated', 'final'])
        ->and(array_column($items, 'value'))->toBe([
            $record->original_sha256,
            $record->sent_sha256,
            $record->consolidated_sha256,
            $record->final_sha256,
        ]);

    foreach ($items as $item) {
        expect($item['description'])->toBeString()->not->toBe('');
    }

    // O resumo final é calculado depois do arquivo pronto e vive fora do PDF.
    expect($items[3]['description'])->toContain('não pode constar dentro dele');
});

test('a trilha distingue abertura detectada de leitura', function () {
    [$organization, $owner, $envelope] = evidenceEnvelope();
    finalizeEnvelope($envelope);

    $recipient = $envelope->recipients()->first();

    AuditEvent::query()->create([
        'organization_id' => $envelope->organization_id,
        'envelope_id' => $envelope->getKey(),
        'recipient_id' => $recipient->getKey(),
        'actor_type' => 'recipient',
        'event_type' => AuditEventType::InvitationOpened,
        'payload' => ['meaning' => 'opened_detected_not_read'],
        'ip_address' => '203.0.113.77',
        'correlation_id' => (string) Str::ulid(),
        'occurred_at' => now()->subHour(),
    ]);

    actingAsMember($owner, $organization);

    $props = verifyProps($this->get(route('envelopes.evidence', $envelope)));

    expect($props['recipients'][0]['opened_at'])->not->toBeNull()
        ->and($props['recipients'][0]['opened_label'])->toContain('não comprova leitura')
        ->and($props['notes']['opened_vs_read'])->toContain('Nenhum registro desta página comprova que o documento foi lido')
        ->and($props['notes']['not_a_certificate'])->toContain('não é um certificado digital');
});

test('quando não há abertura registrada a página diz isso, em vez de omitir', function () {
    [$organization, $owner, $envelope] = evidenceEnvelope();
    finalizeEnvelope($envelope);

    actingAsMember($owner, $organization);

    $props = verifyProps($this->get(route('envelopes.evidence', $envelope)));

    expect($props['recipients'][0]['opened_at'])->toBeNull()
        ->and($props['recipients'][0]['opened_label'])->toBe('Nenhuma abertura do link detectada');
});

test('a data de confirmação do código vem do evento challenge.verified', function () {
    [$organization, $owner, $envelope] = evidenceEnvelope();
    finalizeEnvelope($envelope);

    $recipient = $envelope->recipients()->first();
    $verifiedAt = now()->subHours(2)->startOfSecond();

    AuditEvent::query()->create([
        'organization_id' => $envelope->organization_id,
        'envelope_id' => $envelope->getKey(),
        'recipient_id' => $recipient->getKey(),
        'actor_type' => 'recipient',
        'event_type' => AuditEventType::ChallengeVerified,
        'correlation_id' => (string) Str::ulid(),
        'occurred_at' => $verifiedAt,
    ]);

    actingAsMember($owner, $organization);

    $props = verifyProps($this->get(route('envelopes.evidence', $envelope)));

    expect($props['recipients'][0]['otp_verified_at'])->toBe($verifiedAt->toIso8601String());
});

test('evidence_show_ip governa o IP nas três configurações', function () {
    [$organization, $owner, $envelope] = evidenceEnvelope();
    finalizeEnvelope($envelope);

    actingAsMember($owner, $organization);

    // masked é o padrão da organização (arquitetura §3.1).
    $props = verifyProps($this->get(route('envelopes.evidence', $envelope)));
    expect($props['recipients'][0]['ip'])->toBe('203.0.***.***')
        ->and($props['recipients'][0]['ip_policy'])->toBe('masked');

    OrganizationSettings::of($organization->fresh())->put(['evidence_show_ip' => 'full']);
    $props = verifyProps($this->get(route('envelopes.evidence', $envelope)));
    expect($props['recipients'][0]['ip'])->toBe('203.0.113.77')
        ->and($props['recipients'][0]['ip_policy'])->toBe('full');

    OrganizationSettings::of($organization->fresh())->put(['evidence_show_ip' => 'none']);
    $props = verifyProps($this->get(route('envelopes.evidence', $envelope)));
    expect($props['recipients'][0]['ip'])->toBeNull()
        ->and($props['recipients'][0]['ip_policy'])->toBe('none');

    // Com `none` o endereço não aparece em canto nenhum da página, nem na trilha.
    expect(json_encode($props, JSON_THROW_ON_ERROR))->not->toContain('203.0.113.77');
});

test('sem certificado a página de evidências não afirma assinatura digital', function () {
    [$organization, $owner, $envelope] = evidenceEnvelope();
    finalizeEnvelope($envelope, 'none');

    actingAsMember($owner, $organization);

    $props = verifyProps($this->get(route('envelopes.evidence', $envelope)));

    expect($props['signature_status'])->toBe('none')
        ->and($props['certificate'])->toBeNull()
        ->and($props['signature']['label'])->toBe('Aceite eletrônico com evidências')
        ->and($props['signature']['profile'])->toBeNull()
        ->and($props['signature']['statement'])->toContain('sem assinatura criptográfica')
        ->and($props['signature']['statement'])->not->toContain('assinatura digital')
        ->and($props['signature']['statement'])->not->toContain('assinado digitalmente')
        ->and($props['validation']['available'])->toBeFalse();
});

test('com certificado A1 a página mostra titular, emissor, validade e o ambiente', function () {
    [$organization, $owner, $envelope] = evidenceEnvelope();

    $certificate = CertificateReference::factory()->active()->create();
    finalizeEnvelope($envelope, 'company_a1', $certificate, validationSummary());

    actingAsMember($owner, $organization);

    $props = verifyProps($this->get(route('envelopes.evidence', $envelope)));

    expect($props['signature_status'])->toBe('company_a1')
        ->and($props['signature']['profile'])->toBe('PAdES-B-B')
        ->and($props['certificate']['subject'])->toBe($certificate->subject)
        ->and($props['certificate']['issuer'])->toBe($certificate->issuer)
        ->and($props['certificate']['serial'])->toBe($certificate->serial_number)
        ->and($props['certificate']['valid_from'])->not->toBeNull()
        ->and($props['certificate']['valid_to'])->not->toBeNull()
        ->and($props['certificate']['policy'])->toBe('PAdES-B-B')
        ->and($props['certificate']['environment'])->toBe('test')
        ->and($props['certificate']['is_test'])->toBeTrue()
        ->and($props['validation']['integrity'])->toBe('intact')
        ->and($props['validation']['chain_trust'])->toBe('not_verified')
        // Frase do meio de "Resultado técnico da validação no momento da conclusão: {…}".
        ->and($props['validation_summary'])->toBe(
            'assinatura íntegra e válida na conclusão; cadeia de certificação não verificada '
            .'por esta plataforma; revogação não verificada',
        );

    // Nem o segredo do certificado nem a impressão digital entram nas props.
    expect(json_encode($props, JSON_THROW_ON_ERROR))
        ->not->toContain($certificate->secret_ref)
        ->not->toContain($certificate->fingerprint_sha256);
});

test('um envelope ainda em andamento mostra evidências sem prometer arquivo final', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::InProgress, [
        ['name' => 'Ana Costa', 'email' => 'ana@exemplo.test'],
    ]);

    actingAsMember($owner, $organization);

    $props = verifyProps($this->get(route('envelopes.evidence', $envelope)));

    expect($props['hashes']['signed_sha256'])->toBeNull()
        ->and($props['hashes']['evidence_sha256'])->toBeNull()
        ->and($props['signature']['state'])->toBe('pending')
        ->and($props['signature']['statement'])->toContain('ainda está em andamento')
        ->and($props['certificate'])->toBeNull();
});

test('o link para a verificação pública usa o código do envelope', function () {
    [$organization, $owner, $envelope] = evidenceEnvelope();
    finalizeEnvelope($envelope);

    actingAsMember($owner, $organization);

    $props = verifyProps($this->get(route('envelopes.evidence', $envelope)));

    expect($props['verify_url'])->toContain($envelope->verification_code);

    // E o código realmente resolve na página pública.
    expect(verifyProps($this->get($props['verify_url']))['found'])->toBeTrue();
});

test('um envelope sem código de verificação cai no formulário público, sem quebrar', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create();

    actingAsMember($owner, $organization);

    $props = verifyProps($this->get(route('envelopes.evidence', $envelope)));

    expect($props['verify_url'])->toBe(route('verify.index'));
});
