<?php

use App\Enums\MembershipRole;
use App\Models\Envelope;
use App\Models\Recipient;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de isolamento — busca global (`search.index`) e documentos excluídos
|--------------------------------------------------------------------------
| App\Services\Organizations\EnvelopeVisibility::recipients() devolve `Recipient::query()`
| SEM nenhuma junção com `envelopes` quando o papel é owner/admin. Como `Recipient` não usa
| SoftDeletes e `Envelope` usa, a consulta casa signatários de envelopes já excluídos
| (soft delete). Consequências, ambas verificadas abaixo:
|
| 1. `SearchController@index` (linha 73) faz `$recipient->envelope->ulid` sem proteção; a
|    relação devolve null para um envelope excluído → ErrorException → HTTP 500 na busca ⌘K.
| 2. Mesmo sem o 500, o registro não deveria aparecer: ROUTES_AND_PAGES §1.2 diz que
|    `search.index` "Retorna até 10 envelopes + 5 recipients" e §5 que "Enter abre
|    `envelopes.show`" — e `envelopes.show` de um envelope excluído devolve 404
|    (BelongsToOrganization::resolveRouteBinding usa newQuery(), que exclui os trashed).
|    RECONCILIACAO Q7 ("contagens respeitam o escopo") e o cabeçalho do próprio
|    SearchController ("signatários visíveis ao usuário") pedem o mesmo.
|
| O caminho do papel `member` já está correto porque usa whereHas('envelope', ...) —
| o segundo teste registra isso para que a correção não inverta o problema.
*/

beforeEach(fn () => $this->withoutVite());

test('a busca global não devolve signatários de documentos excluídos para owner/admin', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create([
        'title' => 'Rascunho excluído',
    ]);
    Recipient::factory()->forEnvelope($envelope)->create([
        'name' => 'Zoraide Sigilosa',
        'email' => 'zoraide@exemplo.test',
    ]);

    actingAsMember($owner, $organization);

    $this->delete(route('envelopes.destroy', $envelope))->assertRedirect(route('envelopes.index'));

    // O documento realmente saiu de circulação: a página de detalhe já devolve 404.
    $this->get(route('envelopes.show', ['envelope' => $envelope->ulid]))->assertNotFound();

    $response = $this->getJson(route('search.index', ['q' => 'Zoraide']));

    $response->assertOk();
    expect($response->json('recipients'))->toBe([])
        ->and($response->json('envelopes'))->toBe([]);
});

test('a busca global do member continua sem devolver signatários de documentos excluídos', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);

    $envelope = Envelope::factory()->forOrganization($organization, $member)->draft()->create();
    Recipient::factory()->forEnvelope($envelope)->create(['name' => 'Zoraide Sigilosa']);

    actingAsMember($member, $organization);
    $this->delete(route('envelopes.destroy', $envelope))->assertRedirect(route('envelopes.index'));

    $response = $this->getJson(route('search.index', ['q' => 'Zoraide']));

    $response->assertOk();
    expect($response->json('recipients'))->toBe([]);
});
