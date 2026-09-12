<?php

use App\Enums\MembershipRole;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Organizations\EnvelopeVisibility;

require_once __DIR__.'/Support/ApiHelpers.php';

/*
| A API respeita a MESMA regra de visibilidade da interface (EnvelopeVisibility): um token
| criado por um Operador (sem `view_all_envelopes`) vê só o que essa pessoa veria na tela.
*/

beforeEach(function () {
    $this->withoutVite();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    apiEnable($this->organization);

    $this->member = attachMember($this->organization, MembershipRole::Member);
    // O operador não emite chave (não tem manage_integrations): o token é gravado direto,
    // o mesmo estado de uma chave criada por um administrador que depois foi rebaixado.
    [$this->memberToken] = apiRawToken($this->organization, $this->member);

    $this->mine = Envelope::factory()->forOrganization($this->organization, $this->member)->inProgress()->create(['title' => 'Contrato do operador']);
    Recipient::factory()->forEnvelope($this->mine)->notified()->create();

    $this->others = Envelope::factory()->forOrganization($this->organization, $this->owner)->inProgress()->create(['title' => 'Contrato do proprietário']);
    Recipient::factory()->forEnvelope($this->others)->notified()->create();

    $this->othersDraft = Envelope::factory()->forOrganization($this->organization, $this->owner)->draft()->create(['title' => 'Rascunho do proprietário']);
});

test('a listagem do token é exatamente a visibilidade do criador (a mesma da interface)', function () {
    $api = collect($this->getJson('/api/v1/envelopes', apiHeaders($this->memberToken))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    $rule = EnvelopeVisibility::envelopes(apiMembership($this->organization, $this->member))->pluck('ulid')->sort()->values()->all();

    expect($api)->toBe($rule)
        ->and($api)->toBe([$this->mine->ulid]);

    // A tela de Documentos do mesmo operador mostra o mesmo conjunto.
    actingAsMember($this->member, $this->organization);
    $ui = collect($this->get(route('envelopes.index'))->viewData('page')['props']['envelopes']['data'])->pluck('id')->all();
    expect($ui)->toBe([$this->mine->ulid]);
});

test('documento invisível para o criador do token: 404 em todas as rotas dele', function () {
    $headers = apiHeaders($this->memberToken, apiIdem());
    $base = '/api/v1/envelopes/'.$this->others->ulid;

    foreach ([
        ['GET', $base],
        ['GET', $base.'/recipients'],
        ['GET', $base.'/fields'],
        ['GET', $base.'/events'],
        ['GET', $base.'/verification'],
        ['GET', $base.'/files/original'],
        ['POST', $base.'/cancel'],
        ['POST', $base.'/send'],
        ['PUT', '/api/v1/envelopes/'.$this->othersDraft->ulid.'/recipients'],
        ['PUT', '/api/v1/envelopes/'.$this->othersDraft->ulid.'/fields'],
        ['POST', '/api/v1/envelopes/'.$this->othersDraft->ulid.'/documents'],
    ] as [$method, $url]) {
        assertProblem($this->json($method, $url, ['signing_order' => 'parallel', 'recipients' => [['name' => 'X Y', 'email' => 'x@y.com']], 'fields' => []], $headers), 404, 'not-found');
    }

    expect($this->others->fresh()->status->value)->toBe('in_progress')
        ->and($this->othersDraft->recipients()->count())->toBe(0);
});

test('o proprietário, com view_all_envelopes, vê todos pela API', function () {
    $token = apiIssueToken($this->organization, $this->owner, ['envelopes:read']);

    $ids = collect($this->getJson('/api/v1/envelopes', apiHeaders($token))->json('data'))->pluck('id')->all();

    expect($ids)->toContain($this->mine->ulid, $this->others->ulid, $this->othersDraft->ulid);
});
