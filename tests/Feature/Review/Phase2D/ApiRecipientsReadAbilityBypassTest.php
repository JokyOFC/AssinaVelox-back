<?php

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../../Phase2/Api/Support/ApiHelpers.php';

/*
| Revisão adversarial — Fase 2, onda D (lente: segurança da API — mínimo privilégio).
|
| App\Enums\ApiAbility separa `recipients:read` ("Consultar a situação de cada participante")
| de `envelopes:read`, e a rota GET …/recipients exige `recipients:read` (403 missing-ability
| sem ela). A regra da onda: "token … tem abilities explícitas".
|
| Defeito: GET /api/v1/envelopes/{envelope} (só `envelopes:read`) devolve, no detalhe, a MESMA
| lista de App\Http\Resources\Api\V1\RecipientResource — nome, e-mail, celular mascarado,
| situação e motivo da recusa (EnvelopeResource::toArray, chave `recipients`) — e a trilha
| (`…/events`, também `envelopes:read`) traz o nome de cada participante em `actor.name`.
| Uma chave emitida SEM `recipients:read` lê exatamente o que essa ability deveria guardar: a
| ability é decorativa.
*/

beforeEach(function () {
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    apiEnable($this->organization);

    $this->envelope = Envelope::factory()->forOrganization($this->organization, $this->owner)->inProgress()->create();
    $this->recipient = Recipient::factory()->forEnvelope($this->envelope)->create([
        'name' => 'Joana Participante Sigilosa',
        'email' => 'joana.sigilosa@exemplo.com',
    ]);

    AuditEvent::query()->create([
        'organization_id' => $this->organization->id,
        'envelope_id' => $this->envelope->id,
        'recipient_id' => $this->recipient->id,
        'actor_type' => ActorType::Recipient,
        'event_type' => AuditEventType::InvitationOpened,
        'payload' => [],
        'occurred_at' => Carbon::now(),
    ]);

    // Chave de leitura de documentos, SEM `recipients:read`.
    $this->token = apiIssueToken($this->organization, $this->owner, ['envelopes:read']);
});

test('sem recipients:read a rota de participantes é negada (controle)', function () {
    assertProblem(
        $this->getJson('/api/v1/envelopes/'.$this->envelope->ulid.'/recipients', apiHeaders($this->token)),
        403,
        'missing-ability',
    );
});

test('sem recipients:read o detalhe do documento não entrega os participantes', function () {
    $response = $this->getJson('/api/v1/envelopes/'.$this->envelope->ulid, apiHeaders($this->token))->assertOk();

    expect($response->getContent())
        ->not->toContain('joana.sigilosa@exemplo.com')
        ->not->toContain('Joana Participante Sigilosa');
});

test('sem recipients:read a trilha não entrega o nome dos participantes', function () {
    $response = $this->getJson('/api/v1/envelopes/'.$this->envelope->ulid.'/events', apiHeaders($this->token))->assertOk();

    expect($response->getContent())->not->toContain('Joana Participante Sigilosa');
});
