<?php

use App\Models\Envelope;
use App\Services\Templates\TemplateStatus;
use Illuminate\Support\Carbon;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/ApiHelpers.php';
require_once __DIR__.'/../Templates/Support/TemplateHelpers.php';

/*
| Modelos pela API: só com a flag `templates` (senão 404, como na interface); a geração usa o
| MESMO serviço de "Usar modelo" (validação por tipo, participantes por papel).
*/

beforeEach(function () {
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    apiEnable($this->organization);
    templatesEnable($this->organization);
    $this->token = apiIssueToken($this->organization, $this->owner);

    $this->template = templateHtml($this->organization, $this->owner, '<p>Locatário: {{nome}}. Aluguel: {{valor}}.</p>', [
        ['key' => 'nome', 'label' => 'Nome do locatário', 'type' => 'text', 'required' => true],
        ['key' => 'valor', 'label' => 'Valor do aluguel', 'type' => 'currency', 'required' => false],
    ]);
    $this->roleId = $this->template->currentVersion()->with('roles')->first()->roles->first()->ulid;
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('lista e detalha modelos com as variáveis e os papéis esperados na geração', function () {
    $list = $this->getJson('/api/v1/templates', apiHeaders($this->token))->assertOk();

    expect($list->json('data.0.id'))->toBe($this->template->ulid)
        ->and($list->json('data.0.usable'))->toBeTrue()
        ->and($list->json('meta'))->toHaveKey('next_cursor');

    $show = $this->getJson('/api/v1/templates/'.$this->template->ulid, apiHeaders($this->token))->assertOk()->json('data');

    expect(collect($show['variables'])->pluck('key')->all())->toBe(['nome', 'valor'])
        ->and($show['variables'][0]['required'])->toBeTrue()
        ->and($show['roles'][0]['id'])->toBe($this->roleId)
        ->and($show['roles'][0]['participant_role'])->toBe('signer');
});

test('flag templates desligada: 404 nas rotas de modelos', function () {
    config()->set('assinavelox.features.templates', false);

    assertProblem($this->getJson('/api/v1/templates', apiHeaders($this->token)), 404);
    assertProblem($this->getJson('/api/v1/templates/'.$this->template->ulid, apiHeaders($this->token)), 404);
    assertProblem($this->postJson('/api/v1/templates/'.$this->template->ulid.'/envelopes', [], apiHeaders($this->token, apiIdem())), 404);
});

test('valores inválidos ou participante faltando: 422 com erros por campo, nada criado', function () {
    $body = assertProblem($this->postJson('/api/v1/templates/'.$this->template->ulid.'/envelopes', [
        'values' => ['valor' => 'não é dinheiro'],
        'participants' => [],
    ], apiHeaders($this->token, apiIdem())), 422, 'validation-failed');

    expect(implode(' ', array_keys($body['errors'])))->toContain('values.nome')
        ->and(Envelope::query()->count())->toBe(0);
});

test('modelo arquivado: 409', function () {
    $this->template->forceFill(['status' => TemplateStatus::Archived, 'archived_at' => Carbon::now()])->save();

    assertProblem($this->postJson('/api/v1/templates/'.$this->template->ulid.'/envelopes', [], apiHeaders($this->token, apiIdem())), 409, 'template-unavailable');
    expect($this->getJson('/api/v1/templates', apiHeaders($this->token))->json('data'))->toBe([]);
});

test('gera o documento a partir do modelo (idempotente)', function () {
    templatesRequirePdftool();

    $headers = apiHeaders($this->token, apiIdem('modelo-0001'));
    $payload = [
        'title' => 'Locação — Apto 302',
        'values' => ['nome' => 'Maria Alves', 'valor' => '1.500,00'],
        'participants' => [$this->roleId => ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com']],
    ];

    $first = $this->postJson('/api/v1/templates/'.$this->template->ulid.'/envelopes', $payload, $headers)->assertCreated();

    expect($first->json('data.title'))->toBe('Locação — Apto 302')
        ->and($first->json('data.status'))->toBeIn(['draft', 'preparing', 'ready'])
        ->and($first->json('data.recipients.0.email'))->toBe('maria@exemplo.com');

    $second = $this->postJson('/api/v1/templates/'.$this->template->ulid.'/envelopes', $payload, $headers)->assertCreated();
    expect($second->json())->toBe($first->json())
        ->and(Envelope::query()->count())->toBe(1);
});
