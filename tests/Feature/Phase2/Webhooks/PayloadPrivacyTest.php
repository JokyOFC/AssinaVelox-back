<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Enums\SignatureStatus;
use App\Models\VerificationRecord;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/WebhookHelpers.php';

/*
|--------------------------------------------------------------------------
| Payload mínimo, estável e sem dado proibido — docs/fase-2/webhooks.md §3
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $context = webhookOrg();
    $this->organization = $context['organization'];
    $this->owner = $context['owner'];
    ['secret' => $this->secret] = makeEndpoint($this->organization, $this->owner);

    ['envelope' => $this->envelope, 'recipient' => $this->recipient] = webhookEnvelope($this->organization, $this->owner, [
        'title' => 'Contrato de João da Silva — CPF 123.456.789-09',
        'message' => 'Mensagem pessoal com telefone 11 98888-7777',
        'verification_code' => 'ABCDEFGH2345',
    ], [
        'name' => 'Maria Souza Pereira',
        'email' => 'maria.souza@cliente.com.br',
        'phone' => '+55 11 97777-6666',
        'refusal_reason' => 'Não concordo — meu CPF é 987.654.321-00',
    ]);
});

function payloadOf(WebhookDelivery $delivery): array
{
    return json_decode($delivery->payload, true, flags: JSON_THROW_ON_ERROR);
}

function assertNoForbiddenData(string $raw, array $forbidden): void
{
    foreach ($forbidden as $value) {
        expect($raw)->not->toContain($value);
    }
}

it('corpo tem forma mínima e estável, só com ULIDs públicos', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $event = recordAudit($this->envelope, AuditEventType::EnvelopeSent);
    $payload = payloadOf(WebhookDelivery::withoutOrganizationScope()->sole());

    expect(array_keys($payload))->toBe(['id', 'type', 'version', 'occurred_at', 'organization', 'data'])
        ->and($payload['id'])->toBe($event->ulid)
        ->and($payload['type'])->toBe('envelope.sent')
        ->and($payload['version'])->toBe(1)
        ->and($payload['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($payload['organization'])->toBe(['id' => $this->organization->ulid])
        ->and(array_keys($payload['data']))->toBe(['envelope'])
        ->and(array_keys($payload['data']['envelope']))->toBe([
            'id', 'code', 'status', 'status_label', 'signing_order', 'sent_at', 'expires_at',
            'completed_at', 'refused_at', 'expired_at', 'canceled_at', 'participants',
        ])
        ->and($payload['data']['envelope']['id'])->toBe($this->envelope->ulid)
        ->and($payload['data']['envelope']['participants'])->toBe(['total' => 1, 'concluded' => 0]);
});

it('nenhum evento carrega nome, e-mail, telefone, CPF, título, motivo, código ou segredo', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $this->recipient->forceFill(['status' => RecipientStatus::Viewed])->save();
    recordAudit($this->envelope, AuditEventType::InvitationOpened, $this->recipient, ['link_ulid' => '01JLINKLINKLINKLINKLINKLIN', 'meaning' => 'opened_detected_not_read']);
    $this->recipient->forceFill(['status' => RecipientStatus::Signed, 'signed_at' => now()])->save();
    recordAudit($this->envelope, AuditEventType::AcceptanceRecorded, $this->recipient, ['acceptance_ulid' => '01JACCACCACCACCACCACCACCACC', 'cpf' => '123.456.789-09']);
    recordAudit($this->envelope, AuditEventType::RecipientRefused, $this->recipient, ['reason' => 'Não concordo — meu CPF é 987.654.321-00']);
    recordAudit($this->envelope, AuditEventType::EnvelopeCanceled, null, ['reason' => 'Cancelado por João']);

    $forbidden = [
        'João', 'Silva', 'Maria', 'Souza', 'maria.souza', 'cliente.com.br', '97777', '98888',
        '123.456.789-09', '12345678909', '987.654.321-00', 'Não concordo', 'Contrato de',
        'ABCDEFGH2345', 'Mensagem pessoal', 'link_ulid', '01JLINKLINKLINKLINKLINKLIN', $this->secret,
        '"id":'.$this->envelope->id.',', 'token', 'otp', 'pin', 'password', 'signature_image', 'path',
    ];

    $deliveries = WebhookDelivery::withoutOrganizationScope()->orderBy('id')->get();
    expect($deliveries)->toHaveCount(4);

    foreach ($deliveries as $delivery) {
        assertNoForbiddenData($delivery->payload, $forbidden);
    }

    foreach (webhookRequests() as $request) {
        assertNoForbiddenData($request->body(), $forbidden);
    }
});

it('rótulos honestos: abertura não é leitura; aceite eletrônico não é assinatura com certificado', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    recordAudit($this->envelope, AuditEventType::InvitationOpened, $this->recipient);
    recordAudit($this->envelope, AuditEventType::AcceptanceRecorded, $this->recipient);
    recordAudit($this->envelope, AuditEventType::RecipientRefused, $this->recipient);

    [$viewed, $signed, $refused] = WebhookDelivery::withoutOrganizationScope()->orderBy('id')->get()->map(fn ($d) => payloadOf($d))->all();

    expect($viewed['type'])->toBe('recipient.viewed')
        ->and($viewed['data']['recipient'])->toMatchArray([
            'id' => $this->recipient->ulid,
            'role' => 'signer',
            'meaning' => 'opened_detected_not_read',
        ])
        ->and($signed['type'])->toBe('recipient.signed')
        ->and($signed['data']['recipient']['action'])->toBe('electronic_acceptance')
        ->and($signed['data']['recipient']['action_label'])->toContain('não é assinatura com certificado')
        ->and($refused['data']['recipient'])->not->toHaveKey('reason')
        ->and(array_keys($signed['data']['recipient']))->toBe(['id', 'role', 'role_label', 'status', 'order', 'action', 'action_label']);
});

it('envelope.completed traz o rótulo da verificação pública (sem assinatura criptográfica)', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $this->envelope->forceFill(['status' => EnvelopeStatus::Completed, 'completed_at' => now()])->save();
    VerificationRecord::factory()->create([
        'envelope_id' => $this->envelope->id,
        'organization_id' => $this->organization->id,
        'signature_status' => SignatureStatus::None,
        'signature_profile' => null,
        'sent_sha256' => str_repeat('b', 64),
        'final_sha256' => str_repeat('a', 64),
    ]);

    recordAudit($this->envelope, AuditEventType::EnvelopeCompleted);
    $payload = payloadOf(WebhookDelivery::withoutOrganizationScope()->sole());

    expect($payload['data']['signature'])->toBe([
        'status' => 'none',
        'label' => SignatureStatus::None->label(),
        'profile' => null,
        'sent_sha256' => str_repeat('b', 64),
        'final_sha256' => str_repeat('a', 64),
    ])
        ->and($payload['data']['signature']['label'])->toContain('sem assinatura criptográfica')
        ->and($payload['data'])->not->toHaveKey('verification_code');
});

it('envelope.completed com A1 da operadora diz exatamente isso (nunca "assinatura digital" genérica)', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    VerificationRecord::factory()->create([
        'envelope_id' => $this->envelope->id,
        'organization_id' => $this->organization->id,
        'signature_status' => SignatureStatus::CompanyA1,
        'signature_profile' => 'PAdES-B-B',
    ]);

    recordAudit($this->envelope, AuditEventType::EnvelopeCompleted);
    $signature = payloadOf(WebhookDelivery::withoutOrganizationScope()->sole())['data']['signature'];

    expect($signature['status'])->toBe('company_a1')
        ->and($signature['label'])->toBe(SignatureStatus::CompanyA1->label())
        ->and($signature['profile'])->toBe('PAdES-B-B');
});

it('document.processing_failed traz só o id do documento e o código da falha', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $documentUlid = (string) Str::ulid();
    recordAudit($this->envelope, AuditEventType::DocumentProcessingFailed, null, [
        'document_ulid' => $documentUlid,
        'failure_code' => 'conversion_failed',
        'failure_message' => 'Arquivo /var/storage/private/doc.docx corrompido de João',
    ]);

    $payload = payloadOf(WebhookDelivery::withoutOrganizationScope()->sole());

    expect($payload['data']['document'])->toBe(['id' => $documentUlid, 'failure_code' => 'conversion_failed'])
        ->and(WebhookDelivery::withoutOrganizationScope()->sole()->payload)->not->toContain('storage');
});

it('trecho da resposta guardado no histórico é truncado e redigido', function (): void {
    Http::fake(fn ($request) => Http::response(
        'eco '.$this->secret.' '.$request->header('X-AssinaVelox-Signature')[0].' cpf 123.456.789-09 '.str_repeat('x', 2000),
        400,
    ));

    recordAudit($this->envelope, AuditEventType::EnvelopeSent);
    $excerpt = (string) WebhookDelivery::withoutOrganizationScope()->sole()->history[0]['response_excerpt'];

    expect(strlen($excerpt))->toBeLessThanOrEqual(512)
        ->and($excerpt)->not->toContain($this->secret)
        ->and($excerpt)->not->toContain('123.456.789-09')
        ->and($excerpt)->not->toMatch('/v1=[0-9a-f]{64}/')
        ->and($excerpt)->toContain('[redigido]');

    expect(WebhookTransport::sanitizeExcerpt("ok\x00\x07 whsec_abcDEF123 12345678909"))->toBe('ok [redigido] [redigido]');
});
