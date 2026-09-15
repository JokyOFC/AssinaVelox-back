<?php

use App\Enums\AuditEventType;
use App\Enums\MembershipStatus;
use App\Integrations\HubSpot\HubSpotSignature;
use App\Jobs\Documents\ProcessDocumentUpload;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\HubSpotActionExecution;
use App\Models\Membership;
use App\Models\Recipient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/ConnectorHelpers.php';

/*
|--------------------------------------------------------------------------
| HubSpot — ação de workflow "Enviar para assinatura" (docs/fase-3/conectores.md §5.2)
|--------------------------------------------------------------------------
| Assinatura v3 (HMAC + janela), idempotência por callbackId e envelope criado a partir de
| um modelo da organização DONA do portal.
*/

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Bus::fake([ProcessDocumentUpload::class]);
    Storage::fake('documents');
    connectorsDns();
    connectorsConfigure();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    templatesEnable($this->organization);
    connectorsEnable($this->organization);
    $this->template = templateHtml($this->organization, $this->owner, '<p>Contrato de prestação de serviços</p>', []);
    $this->connection = connectorsHubSpotConnection($this->organization, $this->owner, 555001);
    $this->fields = [
        'template_id' => $this->template->ulid,
        'participant_1_name' => 'Maria Alves',
        'participant_1_email' => 'maria@exemplo.test',
    ];
});

test('ação assinada cria o documento a partir do modelo, na organização do portal, e devolve o estado', function () {
    $response = connectorsHubSpotPost($this, connectorsActionPayload(555001, 'cb-0001', $this->fields))->assertOk();

    // Documento HTML: o arquivo ainda está sendo preparado, então o envio espera (job).
    $response->assertJsonPath('outputFields.assinavelox_status', 'awaiting_preparation');
    $envelope = Envelope::withoutOrganizationScope()->where('ulid', $response->json('outputFields.assinavelox_envelope_id'))->sole();

    expect($envelope->organization_id)->toBe($this->organization->getKey())
        ->and($envelope->created_by_user_id)->toBe($this->owner->getKey())
        ->and(Recipient::withoutOrganizationScope()->where('envelope_id', $envelope->getKey())->pluck('email')->all())->toBe(['maria@exemplo.test']);

    $execution = HubSpotActionExecution::withoutOrganizationScope()->sole();
    expect($execution->envelope_id)->toBe($envelope->getKey())
        ->and($execution->template_id)->toBe($this->template->getKey())
        ->and($execution->object_type)->toBe('DEAL')
        ->and($execution->object_id)->toBe('9001')
        ->and($execution->response['outputFields']['assinavelox_envelope_id'])->toBe($envelope->ulid);

    $event = AuditEvent::query()->withoutGlobalScopes()
        ->where('envelope_id', $envelope->getKey())
        ->where('event_type', AuditEventType::HubSpotActionReceived->value)
        ->sole();
    expect($event->payload['portal_id'])->toBe(555001)
        ->and($event->payload['execution'])->toBe($execution->ulid);
});

test('assinatura inválida, fora da janela ou ausente: 401 e nada é criado', function (Closure $send) {
    $send($this)->assertUnauthorized()->assertJson(['message' => 'Assinatura inválida.']);

    expect(HubSpotActionExecution::withoutOrganizationScope()->count())->toBe(0)
        ->and(Envelope::withoutOrganizationScope()->count())->toBe(0);
})->with([
    'segredo errado' => [fn (object $test) => connectorsHubSpotPost($test, connectorsActionPayload(555001, 'cb-x', $test->fields), secret: 'outro-segredo')],
    'carimbo de 6 minutos atrás' => [fn (object $test) => connectorsHubSpotPost($test, connectorsActionPayload(555001, 'cb-x', $test->fields), (int) floor(microtime(true) * 1000) - 360_000)],
    'carimbo no futuro' => [fn (object $test) => connectorsHubSpotPost($test, connectorsActionPayload(555001, 'cb-x', $test->fields), (int) floor(microtime(true) * 1000) + 600_000)],
    'sem assinatura' => [fn (object $test) => connectorsHubSpotPost($test, connectorsActionPayload(555001, 'cb-x', $test->fields), override: ['X-HubSpot-Signature-v3' => ''])],
    'sem carimbo' => [fn (object $test) => connectorsHubSpotPost($test, connectorsActionPayload(555001, 'cb-x', $test->fields), override: ['X-HubSpot-Request-Timestamp' => ''])],
    'corpo alterado' => [function (object $test) {
        $timestamp = (string) (int) floor(microtime(true) * 1000);
        $forged = HubSpotSignature::sign('POST', route('webhooks.hubspot.action'), '{"callbackId":"outro"}', $timestamp, 'HUBSPOT-CLIENT-SECRET-SENTINELA');

        return connectorsHubSpotPost($test, connectorsActionPayload(555001, 'cb-x', $test->fields), (int) $timestamp, override: ['X-HubSpot-Signature-v3' => $forged]);
    }],
]);

test('a mesma execução repetida (retentativa ou replay na janela) devolve a mesma resposta, sem outro documento', function () {
    $payload = connectorsActionPayload(555001, 'cb-repetida', $this->fields);
    $timestamp = (int) floor(microtime(true) * 1000);

    $first = connectorsHubSpotPost($this, $payload, $timestamp)->assertOk()->json();
    $replay = connectorsHubSpotPost($this, $payload, $timestamp)->assertOk()->json();
    $retry = connectorsHubSpotPost($this, $payload)->assertOk()->json();
    // Mesmo callbackId com outros campos: continua a MESMA execução.
    $changed = connectorsHubSpotPost($this, connectorsActionPayload(555001, 'cb-repetida', ['participant_1_email' => 'outra@exemplo.test'] + $this->fields))->assertOk()->json();

    expect($replay)->toBe($first)->and($retry)->toBe($first)->and($changed)->toBe($first)
        ->and(HubSpotActionExecution::withoutOrganizationScope()->count())->toBe(1)
        ->and(Envelope::withoutOrganizationScope()->count())->toBe(1);
});

test('portal desconhecido, plano sem a flag, flag global desligada ou app sem segredo', function () {
    connectorsHubSpotPost($this, connectorsActionPayload(999999, 'cb-a', $this->fields))->assertNotFound();

    $plan = $this->organization->currentSubscription()->with('plan')->first()->plan;
    $plan->forceFill(['features' => ['hubspot' => false] + (array) $plan->features])->save();
    connectorsHubSpotPost($this, connectorsActionPayload(555001, 'cb-b', $this->fields))->assertNotFound();

    connectorsEnable($this->organization);
    config()->set('assinavelox.features.hubspot', false);
    connectorsHubSpotPost($this, connectorsActionPayload(555001, 'cb-c', $this->fields))->assertNotFound();

    config()->set('assinavelox.features.hubspot', true);
    config()->set('services.hubspot.client_secret', null);
    connectorsHubSpotPost($this, connectorsActionPayload(555001, 'cb-d', $this->fields))->assertStatus(503);

    expect(HubSpotActionExecution::withoutOrganizationScope()->count())->toBe(0);
});

test('corpo sem callbackId ou sem portal é recusado', function () {
    connectorsHubSpotPost($this, ['origin' => ['portalId' => 555001]])->assertStatus(400);
    connectorsHubSpotPost($this, ['callbackId' => 'cb-sem-portal'])->assertStatus(400);

    expect(HubSpotActionExecution::withoutOrganizationScope()->count())->toBe(0);
});

test('dados que o modelo recusa viram estado "failed" com a mensagem do modelo, sem documento', function () {
    $response = connectorsHubSpotPost($this, connectorsActionPayload(555001, 'cb-invalida', ['participant_1_email' => 'nao-e-email'] + $this->fields))->assertOk();

    $response->assertJsonPath('outputFields.assinavelox_status', 'failed')
        ->assertJsonPath('outputFields.assinavelox_envelope_id', '');

    expect(HubSpotActionExecution::withoutOrganizationScope()->sole()->error_code)->toBe('invalid_input')
        ->and(Envelope::withoutOrganizationScope()->count())->toBe(0);
});

test('modelo ausente ou inexistente: estado "failed" e nada criado', function () {
    connectorsHubSpotPost($this, connectorsActionPayload(555001, 'cb-sem-modelo', ['template_id' => ''] + $this->fields))
        ->assertOk()->assertJsonPath('outputFields.assinavelox_status', 'failed');
    connectorsHubSpotPost($this, connectorsActionPayload(555001, 'cb-modelo-fantasma', ['template_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'] + $this->fields))
        ->assertOk()->assertJsonPath('outputFields.assinavelox_status', 'failed');

    expect(HubSpotActionExecution::withoutOrganizationScope()->pluck('error_code')->all())->toBe(['template_required', 'template_not_found'])
        ->and(Envelope::withoutOrganizationScope()->count())->toBe(0);
});

test('quem conectou foi suspenso: a ação é recusada e nada é criado', function () {
    Membership::query()->withoutGlobalScopes()
        ->where('organization_id', $this->organization->getKey())
        ->where('user_id', $this->owner->getKey())
        ->update(['status' => MembershipStatus::Suspended->value]);

    connectorsHubSpotPost($this, connectorsActionPayload(555001, 'cb-suspenso', $this->fields))
        ->assertOk()->assertJsonPath('outputFields.assinavelox_status', 'failed');

    expect(HubSpotActionExecution::withoutOrganizationScope()->sole()->error_code)->toBe('connector_user_unavailable')
        ->and(Envelope::withoutOrganizationScope()->count())->toBe(0);
});

test('assinatura v3: a URI é normalizada como a documentação do HubSpot descreve', function () {
    expect(HubSpotSignature::normalizeUri('https://x.test/a%3Ab%2Fc%3Fd%40e%21f%24g%27h%28i%29j%2Ak%2Cl%3Bm'))
        ->toBe("https://x.test/a:b/c?d@e!f\$g'h(i)j*k,l;m");

    $signature = new HubSpotSignature;
    $now = 1_700_000_000_000;
    $valid = HubSpotSignature::sign('POST', 'https://x.test/h', '{}', (string) $now, 's3gr3do');

    expect($signature->verify('POST', 'https://x.test/h', '{}', $valid, (string) $now, 's3gr3do', 300, $now))->toBeNull()
        ->and($signature->verify('POST', 'https://x.test/h', '{}', $valid, (string) $now, 's3gr3do', 300, $now + 301_000))->toBe(HubSpotSignature::STALE_TIMESTAMP)
        ->and($signature->verify('POST', 'https://x.test/h', '{} ', $valid, (string) $now, 's3gr3do', 300, $now))->toBe(HubSpotSignature::INVALID_SIGNATURE)
        ->and($signature->verify('POST', 'https://x.test/h', '{}', $valid, (string) $now, '', 300, $now))->toBe(HubSpotSignature::NOT_CONFIGURED);
});
