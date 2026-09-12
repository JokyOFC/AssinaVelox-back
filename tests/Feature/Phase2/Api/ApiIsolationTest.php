<?php

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Recipient;
use App\Models\SigningField;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/ApiHelpers.php';
require_once __DIR__.'/../Templates/Support/TemplateHelpers.php';

/*
| Isolamento por organização: um token de B nunca lê nem altera recurso de A — 404 (sem
| confirmar a existência) em TODAS as rotas da API que recebem um recurso. A lista de rotas é
| lida do roteador: rota nova entra no teste sozinha.
*/

beforeEach(function () {
    $this->work = templatesWorkspace();
    ['a' => $this->a, 'ownerA' => $this->ownerA, 'b' => $this->b, 'ownerB' => $this->ownerB] = apiTwoOrganizations();
    templatesEnable($this->a);

    $this->envelopeA = Envelope::factory()->forOrganization($this->a, $this->ownerA)->inProgress()->create();
    Recipient::factory()->forEnvelope($this->envelopeA)->notified()->create(['name' => 'Maria Alves', 'email' => 'maria@exemplo.com']);

    $this->templateA = templateHtml($this->a, $this->ownerA, '<p>{{nome}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ]);

    $this->tokenB = apiIssueToken($this->b, $this->ownerB);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('um token de outra organização recebe 404 em TODAS as rotas com recurso — e nada muda', function () {
    $snapshot = fn (): array => [
        'envelope' => Envelope::withoutOrganizationScope()->whereKey($this->envelopeA->id)->first()?->only(['status', 'title', 'updated_at', 'canceled_at']),
        'recipients' => Recipient::withoutOrganizationScope()->where('envelope_id', $this->envelopeA->id)->get(['id', 'name', 'email', 'status'])->toArray(),
        'documents' => Document::withoutOrganizationScope()->where('envelope_id', $this->envelopeA->id)->count(),
        'fields' => SigningField::withoutOrganizationScope()->where('envelope_id', $this->envelopeA->id)->count(),
        'events' => AuditEvent::withoutOrganizationScope()->where('organization_id', $this->a->id)->count(),
        'envelopes' => Envelope::withoutOrganizationScope()->where('organization_id', $this->a->id)->count(),
    ];

    $before = $snapshot();
    $values = ['envelope' => $this->envelopeA->ulid, 'template' => $this->templateA->ulid, 'type' => 'original'];
    $checked = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = $route->getName();

        if (! is_string($name) || ! str_starts_with($name, 'api.v1.') || $route->parameterNames() === []) {
            continue;
        }

        // Parâmetro de outra área (ex.: assinaturas de webhook): ULID sintético — ainda assim 404.
        $parameters = [];
        foreach ($route->parameterNames() as $parameter) {
            $parameters[$parameter] = $values[$parameter] ?? '01HZZZZZZZZZZZZZZZZZZZZZZZ';
        }

        $url = route($name, $parameters);

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $response = $this->json($method, $url, ['title' => 'Invasão', 'reason' => 'x', 'recipients' => [], 'fields' => []], apiHeaders($this->tokenB, apiIdem()));

            $body = assertProblem($response, 404, 'not-found');
            expect(json_encode($body))->not->toContain($this->envelopeA->title);

            $checked[] = "{$method} {$name}";
        }
    }

    // As rotas desta área com recurso: 11 (envelope) + 2 (modelo); rotas novas de outras áreas
    // no grupo /api/v1 entram sozinhas.
    expect(count($checked))->toBeGreaterThanOrEqual(13)
        ->and($snapshot())->toEqual($before);
});

test('listagens de outra organização não mostram nada da organização A', function () {
    Envelope::factory()->forOrganization($this->b, $this->ownerB)->draft()->create(['title' => 'Documento de B']);

    $envelopes = $this->getJson('/api/v1/envelopes', apiHeaders($this->tokenB))->assertOk()->json('data');
    expect(collect($envelopes)->pluck('id')->all())->not->toContain($this->envelopeA->ulid)
        ->and(collect($envelopes)->pluck('title')->all())->toBe(['Documento de B']);

    $templates = $this->getJson('/api/v1/templates', apiHeaders($this->tokenB))->assertOk()->json('data');
    expect($templates)->toBe([]);
});

test('o token de A continua enxergando os próprios recursos (controle positivo)', function () {
    $tokenA = apiIssueToken($this->a, $this->ownerA);

    $this->getJson('/api/v1/envelopes/'.$this->envelopeA->ulid, apiHeaders($tokenA))->assertOk()->assertJsonPath('data.id', $this->envelopeA->ulid);
    $this->getJson('/api/v1/templates/'.$this->templateA->ulid, apiHeaders($tokenA))->assertOk()->assertJsonPath('data.id', $this->templateA->ulid);
});

test('filtro por pasta de outra organização é recusado sem revelar a pasta', function () {
    $folderA = Folder::factory()->create(['organization_id' => $this->a->id, 'name' => 'Pasta de A']);

    $body = assertProblem($this->getJson('/api/v1/envelopes?folder='.$folderA->ulid, apiHeaders($this->tokenB)), 422, 'validation-failed');
    expect(json_encode($body))->not->toContain('Pasta de A');
});
