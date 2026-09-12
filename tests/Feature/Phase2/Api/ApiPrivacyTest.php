<?php

use App\Enums\ActorType;
use App\Enums\ApiAbility;
use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Enums\FieldType;
use App\Enums\SignatureStatus;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Models\RecipientPin;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Models\VerificationRecord;
use App\Services\Verification\PublicVerification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/Support/ApiHelpers.php';

/*
| Privacidade e semântica (roadmap T1): nenhuma resposta da API traz CPF completo, celular
| completo, IP, código, PIN, token/link de acesso, imagem de assinatura ou valor de campo — nada
| que a verificação pública esconde. E os rótulos de assinatura são os honestos.
*/

beforeEach(function () {
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    apiEnable($this->organization);
    $this->token = apiIssueToken($this->organization, $this->owner);

    $this->envelope = Envelope::factory()->forOrganization($this->organization, $this->owner)->completed()->create(['title' => 'Contrato de locação — Apto 302']);

    $document = Document::factory()->forEnvelope($this->envelope)->create(['processing_status' => DocumentProcessingStatus::Ready, 'page_count' => 1]);
    $version = DocumentVersion::factory()->forDocument($document)->create(['page_count' => 1]);
    $document->forceFill(['current_version_id' => $version->id])->save();

    $this->recipient = Recipient::factory()->forEnvelope($this->envelope)->signed()->create([
        'name' => 'Maria Alves Pereira',
        'email' => 'maria@exemplo.com',
        'phone' => '+5511987654321',
    ]);

    $field = SigningField::factory()->forRecipient($this->recipient, $version)->create(['type' => FieldType::from('cpf'), 'label' => 'CPF']);
    $this->acceptance = SignatureAcceptance::factory()->forRecipient($this->recipient, $version)->create(['ip_address' => '203.0.113.77']);
    SigningFieldValue::factory()->forField($field, $this->acceptance)->create(['value_text' => '529.982.247-25']);

    $this->link = RecipientAccessLink::factory()->forRecipient($this->recipient)->create();

    $this->pin = RecipientPin::query()->create([
        'recipient_id' => $this->recipient->id,
        'envelope_id' => $this->envelope->id,
        'organization_id' => $this->organization->id,
        'pin_hash' => Hash::make('482193'),
        'failed_attempts' => 0,
        'lockouts' => 0,
    ]);

    AuditEvent::query()->create([
        'organization_id' => $this->organization->id,
        'envelope_id' => $this->envelope->id,
        'recipient_id' => $this->recipient->id,
        'actor_type' => ActorType::Recipient,
        'event_type' => AuditEventType::AcceptanceRecorded,
        'payload' => ['cpf_masked' => '***.982.247-**', 'code' => '739104'],
        'ip_address' => '203.0.113.77',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/140',
        'occurred_at' => Carbon::now(),
    ]);

    VerificationRecord::factory()->forEnvelope($this->envelope)->create(['signature_status' => SignatureStatus::None]);
});

test('nenhuma resposta traz dado proibido', function () {
    $base = '/api/v1/envelopes/'.$this->envelope->ulid;
    $raw = '';

    foreach (['/api/v1/envelopes', $base, $base.'/recipients', $base.'/fields', $base.'/events', $base.'/verification'] as $url) {
        $response = $this->getJson($url, apiHeaders($this->token))->assertOk();
        $raw .= $response->getContent()."\n";
    }

    foreach ([
        '529.982.247-25', '52998224725', '98224725',   // CPF completo
        '11987654321', '98765-4321', '987654321',        // celular completo
        '203.0.113.77',                                  // IP
        'Chrome/140', 'Mozilla',                         // user-agent
        '739104', 'cpf_masked',                          // payload da trilha (código, dado de identidade)
        $this->link->token_digest, 'token_digest',        // link de acesso
        $this->pin->pin_hash, 'pin_hash', 'pin_state',   // PIN
        (string) $this->acceptance->signature_image_path, 'signature_image', 'image_path', 'signatures/',
        'fields_snapshot', 'consent_statement', 'finalization_key',
        '"organization_id"', '"created_by_user_id"', '"envelope_id"',
    ] as $forbidden) {
        expect($raw)->not->toContain($forbidden);
    }

    // O celular sai só mascarado.
    $recipients = $this->getJson($base.'/recipients', apiHeaders($this->token))->json('data');
    expect($recipients[0]['phone_masked'])->not->toBeNull()
        ->and($recipients[0]['phone_masked'])->not->toContain('98765');
});

test('rótulos honestos: concluído sem assinatura criptográfica não é "Assinado"', function () {
    $data = $this->getJson('/api/v1/envelopes/'.$this->envelope->ulid, apiHeaders($this->token))->json('data');

    expect($data['status'])->toBe('completed')
        ->and($data['status_label'])->toBe('Concluído')
        ->and($data['signature_status'])->toBe('none')
        ->and($data['signature_status_label'])->toBe(SignatureStatus::None->label());

    $verification = $this->getJson('/api/v1/envelopes/'.$this->envelope->ulid.'/verification', apiHeaders($this->token))->json('data');
    expect($verification['status_label'])->toBe('Concluído · aceite eletrônico com evidências')
        ->and($verification['signature_status'])->toBe('none');
});

test('"Assinado" só com assinatura criptográfica de fato', function () {
    VerificationRecord::query()->where('envelope_id', $this->envelope->id)
        ->update(['signature_status' => SignatureStatus::CompanyA1->value]);

    $data = $this->getJson('/api/v1/envelopes/'.$this->envelope->ulid, apiHeaders($this->token))->json('data');

    expect($data['status_label'])->toBe('Assinado')
        ->and($data['signature_status'])->toBe('company_a1')
        ->and($data['signature_status_label'])->toContain('operadora');
});

test('antes da conclusão a situação da assinatura é desconhecida (null), nunca "none"', function () {
    $sent = Envelope::factory()->forOrganization($this->organization, $this->owner)->inProgress()->create();

    $data = $this->getJson('/api/v1/envelopes/'.$sent->ulid, apiHeaders($this->token))->json('data');

    expect($data['signature_status'])->toBeNull()
        ->and($data['signature_status_label'])->toBeNull();
});

test('a verificação pela API é exatamente a pública — e some quando a pública some', function () {
    $api = $this->getJson('/api/v1/envelopes/'.$this->envelope->ulid.'/verification', apiHeaders($this->token))->json('data');
    $public = app(PublicVerification::class)->result(
        app(PublicVerification::class)->lookup((string) $this->envelope->verification_code),
    );

    expect($api)->toEqual(json_decode(json_encode($public), true));

    VerificationRecord::query()->where('envelope_id', $this->envelope->id)->update(['revoked_at' => Carbon::now()]);
    assertProblem($this->getJson('/api/v1/envelopes/'.$this->envelope->ulid.'/verification', apiHeaders($this->token)), 404, 'verification-unavailable');

    $draft = Envelope::factory()->forOrganization($this->organization, $this->owner)->draft()->create();
    assertProblem($this->getJson('/api/v1/envelopes/'.$draft->ulid.'/verification', apiHeaders($this->token)), 404, 'verification-unavailable');
});

test('nenhum texto da API usa vocabulário proibido (T1)', function () {
    $raw = '';

    foreach (['/api/v1/envelopes/'.$this->envelope->ulid, '/api/v1/envelopes/'.$this->envelope->ulid.'/events', '/api/v1/envelopes/'.$this->envelope->ulid.'/verification'] as $url) {
        $raw .= $this->getJson($url, apiHeaders($this->token))->getContent();
    }

    $raw .= json_encode(array_map(fn ($ability) => [$ability->label(), $ability->description()], ApiAbility::cases()), JSON_UNESCAPED_UNICODE);

    foreach (['/assinatura(?:\s+eletr[ôo]nica)?\s+avan[çc]ada/iu', '/qualificada/iu', '/biometri/iu', '/liveness/iu', '/identidade\s+verificada/iu', '/cart[óo]rio/iu'] as $pattern) {
        expect(preg_match($pattern, json_decode('"'.addcslashes($raw, '"').'"') ?? $raw))->toBe(0);
    }
});
