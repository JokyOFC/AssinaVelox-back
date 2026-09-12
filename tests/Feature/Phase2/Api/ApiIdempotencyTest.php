<?php

use App\Enums\PlanConsumptionStatus;
use App\Models\ApiToken;
use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Services\Api\IdempotencyStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/ApiHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';

/*
| Idempotency-Key (docs/fase-2/api-v1.md §7): obrigatória nas criações e no envio; repetição
| devolve a mesma resposta; corpo diferente → 409; corrida → uma processa, a outra recebe 409.
*/

beforeEach(function () {
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    apiEnable($this->organization);
    $this->token = apiIssueToken($this->organization, $this->owner);
});

test('sem Idempotency-Key a criação e o envio são recusados com 400', function () {
    assertProblem($this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], apiHeaders($this->token)), 400, 'idempotency-key-missing');

    $envelope = readyEnvelope($this->organization, $this->owner);
    assertProblem($this->postJson('/api/v1/envelopes/'.$envelope->ulid.'/send', [], apiHeaders($this->token)), 400, 'idempotency-key-missing');

    expect(Envelope::query()->count())->toBe(1)
        ->and($envelope->fresh()->status->value)->toBe('ready');
});

test('chave com formato inválido: 400', function () {
    foreach (['com espaço', str_repeat('a', 256), "quebra\nlinha"] as $key) {
        assertProblem($this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], apiHeaders($this->token, ['Idempotency-Key' => $key])), 400, 'idempotency-key-invalid');
    }

    expect(Envelope::query()->count())->toBe(0);
});

test('repetição com o mesmo pedido devolve a MESMA resposta e cria um único documento', function () {
    $headers = apiHeaders($this->token, apiIdem('pedido-0001'));

    $first = $this->postJson('/api/v1/envelopes', ['title' => 'Contrato de locação', 'message' => 'Olá'], $headers)->assertCreated();
    $second = $this->postJson('/api/v1/envelopes', ['message' => 'Olá', 'title' => 'Contrato de locação'], $headers)->assertCreated();

    expect($second->json())->toBe($first->json())
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($first->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and($second->headers->get('Location'))->toBe($first->headers->get('Location'))
        ->and(Envelope::query()->count())->toBe(1);
});

test('mesma chave com corpo diferente: 409 e nada é criado', function () {
    $headers = apiHeaders($this->token, apiIdem('pedido-0002'));

    $this->postJson('/api/v1/envelopes', ['title' => 'Contrato A'], $headers)->assertCreated();

    assertProblem($this->postJson('/api/v1/envelopes', ['title' => 'Contrato B'], $headers), 409, 'idempotency-key-reused');

    expect(Envelope::query()->pluck('title')->all())->toBe(['Contrato A']);
});

test('mesma chave em outra rota ou outro recurso: 409', function () {
    $envelope = readyEnvelope($this->organization, $this->owner);
    $headers = apiHeaders($this->token, apiIdem('pedido-0003'));

    $this->postJson('/api/v1/envelopes', ['title' => 'Contrato A'], $headers)->assertCreated();

    assertProblem($this->postJson('/api/v1/envelopes/'.$envelope->ulid.'/send', [], $headers), 409, 'idempotency-key-reused');
    expect($envelope->fresh()->status->value)->toBe('ready');
});

test('a mesma chave em tokens diferentes é independente', function () {
    $other = apiIssueToken($this->organization, $this->owner, ['envelopes:write']);

    $this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], apiHeaders($this->token, apiIdem('mesma')))->assertCreated();
    $this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], apiHeaders($other, apiIdem('mesma')))->assertCreated();

    expect(Envelope::query()->count())->toBe(2);
});

test('corrida: com a primeira requisição em processamento, a segunda recebe 409 e nada é criado', function () {
    // A impressão digital do pedido é a mesma para qualquer chave: pega-se a de um pedido real.
    $this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], apiHeaders($this->token, apiIdem('referencia')))->assertCreated();
    $reference = DB::table(IdempotencyStore::TABLE)->where('idempotency_key', 'referencia')->first();

    // A "primeira" requisição com a chave `corrida` está no meio do processamento.
    DB::table(IdempotencyStore::TABLE)->insert([
        'organization_id' => $this->organization->id,
        'personal_access_token_id' => $reference->personal_access_token_id,
        'idempotency_key' => 'corrida',
        'request_hash' => $reference->request_hash,
        'method' => 'POST',
        'route' => 'api.v1.envelopes.store',
        'status' => 'processing',
        'locked_until' => Carbon::now()->addMinute(),
        'expires_at' => Carbon::now()->addDay(),
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    $response = $this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], apiHeaders($this->token, apiIdem('corrida')));

    assertProblem($response, 409, 'idempotency-request-in-progress');
    expect($response->headers->get('Retry-After'))->toBe('1')
        ->and(Envelope::query()->count())->toBe(1);

    // O processo da primeira morreu: vencida a reserva, a próxima tentativa assume e conclui.
    $this->travel(2)->minutes();
    $this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], apiHeaders($this->token, apiIdem('corrida')))->assertCreated();
    expect(Envelope::query()->count())->toBe(2);
});

test('reserva no armazenamento: das duas requisições simultâneas só uma vence', function () {
    $store = app(IdempotencyStore::class);
    $token = ApiToken::withoutOrganizationScope()->firstOrFail();

    $first = $store->reserve($token, 'simultanea', str_repeat('a', 64), 'POST', 'api.v1.envelopes.store');
    $second = $store->reserve($token, 'simultanea', str_repeat('a', 64), 'POST', 'api.v1.envelopes.store');
    $other = $store->reserve($token, 'simultanea', str_repeat('b', 64), 'POST', 'api.v1.envelopes.store');

    expect($first['state'])->toBe(IdempotencyStore::RESERVED)
        ->and($second['state'])->toBe(IdempotencyStore::IN_PROGRESS)
        ->and($other['state'])->toBe(IdempotencyStore::MISMATCH)
        ->and(DB::table(IdempotencyStore::TABLE)->where('idempotency_key', 'simultanea')->count())->toBe(1);
});

test('erro não fica guardado: um 422 libera a chave para o pedido corrigido', function () {
    $headers = apiHeaders($this->token, apiIdem('pedido-0004'));

    assertProblem($this->postJson('/api/v1/envelopes', ['title' => ''], $headers), 422, 'validation-failed');
    expect(DB::table(IdempotencyStore::TABLE)->where('idempotency_key', 'pedido-0004')->exists())->toBeFalse();

    $this->postJson('/api/v1/envelopes', ['title' => 'Contrato corrigido'], $headers)->assertCreated();
    expect(Envelope::query()->count())->toBe(1);
});

test('a resposta guardada vale por 24 h; depois a chave pode ser reutilizada', function () {
    $headers = apiHeaders($this->token, apiIdem('pedido-0005'));

    $this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], $headers)->assertCreated();

    $this->travel(23)->hours();
    $this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect(Envelope::query()->count())->toBe(1);

    $this->travel(2)->hours();
    $this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], $headers)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    expect(Envelope::query()->count())->toBe(2);
});

test('envio idempotente: repetir com a mesma chave não reenvia nem consome o plano de novo', function () {
    $provider = fakeEmailProvider();
    $envelope = readyEnvelope($this->organization, $this->owner);
    $headers = apiHeaders($this->token, apiIdem('envio-0001'));

    $first = $this->postJson('/api/v1/envelopes/'.$envelope->ulid.'/send', [], $headers)->assertOk();
    $sentMessages = count($provider->sent);
    $second = $this->postJson('/api/v1/envelopes/'.$envelope->ulid.'/send', [], $headers)->assertOk();

    expect($second->json())->toBe($first->json())
        ->and($first->json('data.status'))->toBe('in_progress')
        ->and($first->json('meta.invitations_sent'))->toBe(1)
        ->and(count($provider->sent))->toBe($sentMessages)
        ->and(PlanConsumption::withoutOrganizationScope()->where('envelope_id', $envelope->id)->where('status', PlanConsumptionStatus::Committed->value)->count())->toBe(1);

    // Com uma chave NOVA, o domínio responde que já foi enviado (409), sem reenviar.
    assertProblem($this->postJson('/api/v1/envelopes/'.$envelope->ulid.'/send', [], apiHeaders($this->token, apiIdem())), 409, 'already-sent');
});
