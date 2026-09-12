<?php

use App\Enums\MembershipRole;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Recipient;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../../Phase2/Api/Support/ApiHelpers.php';

/*
| Revisão adversarial — Fase 2, onda D (lente: segurança da API — idempotência × visibilidade).
|
| docs/fase-2/api-v1.md §2.3: "Rebaixar o criador rebaixa o token na próxima requisição" e §6:
| envelope fora da visibilidade do criador → 404 ("a API não confirma a existência do que o
| token não enxerga").
|
| Defeito: App\Http\Middleware\ApiIdempotency devolve a resposta guardada
| (IdempotencyStore::replay) ANTES de o controller conferir a visibilidade
| (ApiEnvelopeAccess::ensureVisible) e a Policy. Por até 24 h (`ttl_hours`), repetir a mesma
| `Idempotency-Key` entrega o detalhe completo — título, participantes com nome e e-mail — de
| um documento que o criador, já rebaixado, não pode mais ver: a interface e o mesmo token sem
| a chave respondem 404.
*/

test('depois do rebaixamento, repetir a Idempotency-Key não entrega documento que o criador deixou de ver', function () {
    Mail::fake();
    Notification::fake();

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    apiEnable($organization);

    $admin = attachMember($organization, MembershipRole::Admin);
    $token = apiIssueToken($organization, $admin);

    $ownersEnvelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create(['title' => 'Contrato reservado do proprietário']);
    Recipient::factory()->forEnvelope($ownersEnvelope)->notified()->create([
        'name' => 'Paulo Contraparte Reservada',
        'email' => 'paulo.reservado@exemplo.com',
    ]);

    $key = apiIdem('cancelamento-0001');

    // Administrador (view_all_envelopes) cancela o documento do proprietário.
    $this->postJson('/api/v1/envelopes/'.$ownersEnvelope->ulid.'/cancel', [], apiHeaders($token, $key))->assertOk();

    // Rebaixado a Operador: só os próprios documentos.
    Membership::query()->where('user_id', $admin->id)->where('organization_id', $organization->id)
        ->update(['role' => MembershipRole::Member->value]);

    // Controle: sem repetir a chave, o documento já não existe para o token.
    assertProblem($this->getJson('/api/v1/envelopes/'.$ownersEnvelope->ulid, apiHeaders($token)), 404);

    // Repetição da mesma chave: também tem de ser 404, sem o conteúdo do documento.
    $replay = $this->postJson('/api/v1/envelopes/'.$ownersEnvelope->ulid.'/cancel', [], apiHeaders($token, $key));

    // A repetição hoje sai como Symfony\Component\HttpFoundation\Response crua (IdempotencyStore::replay).
    expect($replay->baseResponse->getStatusCode())->toBe(404)
        ->and($replay->getContent())
        ->not->toContain('paulo.reservado@exemplo.com')
        ->not->toContain('Contrato reservado do proprietário');
});
