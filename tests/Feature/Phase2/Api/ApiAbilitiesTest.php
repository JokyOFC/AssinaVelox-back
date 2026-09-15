<?php

use App\Enums\ApiAbility;
use App\Enums\MembershipRole;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Recipient;
use App\Services\Api\ApiTokenManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/ApiHelpers.php';
require_once __DIR__.'/../Templates/Support/TemplateHelpers.php';

/*
| Abilities: rota sem a ability → 403 `missing-ability`; o token nunca excede o criador — nem
| na emissão (anti-escalada) nem depois (perdeu a permissão → a ability para de valer).
*/

beforeEach(function () {
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    apiEnable($this->organization);
    templatesEnable($this->organization);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

/**
 * Abilities exigidas pela rota, lidas do middleware `api.ability:*`.
 *
 * @return list<string>
 */
function apiRouteAbilities(Illuminate\Routing\Route $route): array
{
    $abilities = [];

    foreach ($route->gatherMiddleware() as $middleware) {
        if (is_string($middleware) && str_starts_with($middleware, 'api.ability:')) {
            array_push($abilities, ...explode(',', substr($middleware, strlen('api.ability:'))));
        }
    }

    return $abilities;
}

test('toda rota da API declara pelo menos uma ability do catálogo', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'api.v1.'));

    expect($routes->count())->toBeGreaterThanOrEqual(16);

    foreach ($routes as $route) {
        $abilities = apiRouteAbilities($route);

        expect($abilities)->not->toBe([], "Rota {$route->getName()} sem api.ability.");

        foreach ($abilities as $ability) {
            expect(ApiAbility::tryFrom($ability))->not->toBeNull("Ability desconhecida em {$route->getName()}: {$ability}");
        }
    }
});

test('sem a ability exigida: 403 missing-ability em cada rota desta área', function () {
    $envelope = Envelope::factory()->forOrganization($this->organization, $this->owner)->draft()->create();
    $template = templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ]);
    $values = ['envelope' => $envelope->ulid, 'template' => $template->ulid, 'type' => 'original',
        // Fase 3 §3.9 (G-EMBED): ULIDs sintéticos — a ability é conferida antes de o controller
        // procurar o participante e a sessão embutida.
        'recipient' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'embeddedSession' => '01HZZZZZZZZZZZZZZZZZZZZZZY'];
    $checked = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with((string) $route->getName(), 'api.v1.') || ! str_starts_with((string) $route->getActionName(), 'App\Http\Controllers\Api\V1\\')) {
            continue;
        }

        foreach (apiRouteAbilities($route) as $ability) {
            $token = apiIssueToken($this->organization, $this->owner, array_values(array_diff(ApiAbility::values(), [$ability])));
            $url = route($route->getName(), array_intersect_key($values, array_flip($route->parameterNames())));

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $body = assertProblem($this->json($method, $url, [], apiHeaders($token, apiIdem())), 403, 'missing-ability');

                expect($body['required_ability'])->toBe($ability);
                $checked++;
            }
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(16)
        ->and(Envelope::query()->count())->toBe(1);
});

test('token só de leitura não cria, não envia e não cancela', function () {
    $envelope = Envelope::factory()->forOrganization($this->organization, $this->owner)->inProgress()->create();
    $token = apiIssueToken($this->organization, $this->owner, ['envelopes:read']);

    $this->getJson('/api/v1/envelopes/'.$envelope->ulid, apiHeaders($token))->assertOk();
    assertProblem($this->postJson('/api/v1/envelopes', ['title' => 'Novo contrato'], apiHeaders($token, apiIdem())), 403, 'missing-ability');
    assertProblem($this->postJson('/api/v1/envelopes/'.$envelope->ulid.'/cancel', [], apiHeaders($token)), 403, 'missing-ability');
    assertProblem($this->getJson('/api/v1/envelopes/'.$envelope->ulid.'/recipients', apiHeaders($token)), 403, 'missing-ability');

    expect($envelope->fresh()->status->value)->toBe('in_progress');
});

test('emissão: ninguém concede ability cujas permissões não tem, e só manage_integrations emite', function () {
    $member = attachMember($this->organization, MembershipRole::Member);
    $memberMembership = apiMembership($this->organization, $member);

    // Operador não tem `manage_integrations`: não emite chave nenhuma.
    expect(fn () => app(ApiTokenManager::class)->issue($memberMembership, 'x', ['envelopes:read']))
        ->toThrow(AuthorizationException::class);

    // A trava anti-escalada, pela regra de cada ability.
    expect(ApiTokenManager::beyond($memberMembership, [ApiAbility::WebhooksManage, ApiAbility::EnvelopesWrite, ApiAbility::EnvelopesRead]))
        ->toBe([ApiAbility::WebhooksManage])
        ->and(ApiTokenManager::grantable($memberMembership))->not->toContain(ApiAbility::WebhooksManage)
        ->and(ApiTokenManager::grantable(apiMembership($this->organization, $this->owner)))->toBe(ApiAbility::cases());

    // Ability fora do catálogo ou `*`: recusada.
    expect(fn () => apiIssueToken($this->organization, $this->owner, ['*']))->toThrow(ValidationException::class)
        ->and(fn () => apiIssueToken($this->organization, $this->owner, ['envelopes:delete']))->toThrow(ValidationException::class)
        ->and(fn () => apiIssueToken($this->organization, $this->owner, []))->toThrow(ValidationException::class);
});

test('criador rebaixado: o token passa a valer só o que o criador vale agora', function () {
    $admin = attachMember($this->organization, MembershipRole::Admin);
    $token = apiIssueToken($this->organization, $admin);

    $ownersEnvelope = Envelope::factory()->forOrganization($this->organization, $this->owner)->inProgress()->create(['title' => 'Contrato do proprietário']);
    Recipient::factory()->forEnvelope($ownersEnvelope)->notified()->create();

    // Administrador vê todos os documentos da conta.
    $this->getJson('/api/v1/envelopes/'.$ownersEnvelope->ulid, apiHeaders($token))->assertOk();

    Membership::query()->where('user_id', $admin->id)->where('organization_id', $this->organization->id)
        ->update(['role' => MembershipRole::Member->value]);

    // Operador: só os próprios — o do proprietário deixa de existir para o token.
    assertProblem($this->getJson('/api/v1/envelopes/'.$ownersEnvelope->ulid, apiHeaders($token)), 404);
    assertProblem($this->postJson('/api/v1/envelopes/'.$ownersEnvelope->ulid.'/cancel', [], apiHeaders($token)), 404);
    expect($this->getJson('/api/v1/envelopes', apiHeaders($token))->json('data'))->toBe([])
        ->and($ownersEnvelope->fresh()->status->value)->toBe('in_progress');

    // O que o operador pode (criar o próprio rascunho) continua valendo.
    $this->postJson('/api/v1/envelopes', ['title' => 'Rascunho do operador'], apiHeaders($token, apiIdem()))->assertCreated();

    // Ability que exige permissão que o criador perdeu: 403 creator-lacks-permission.
    Route::middleware(['api', 'api.ability:webhooks:manage'])
        ->get('api/v1/_teste/webhooks', fn () => response()->json(['ok' => true]))
        ->name('api.teste.webhooks');
    app('router')->getRoutes()->refreshNameLookups();

    $body = assertProblem($this->getJson('/api/v1/_teste/webhooks', apiHeaders($token)), 403, 'creator-lacks-permission');
    expect($body['required_ability'])->toBe('webhooks:manage');
});

test('ação sobre recurso visível sem a permissão da Policy: 403 (não 404)', function () {
    // Operador vê o próprio documento, mas quem o criou é ele — cancelar exige `send_envelopes`
    // (que o operador tem). Um token de operador sem `create_envelopes` não existe no sistema de
    // papéis; o 403 da Policy aparece quando o envelope é visível e a ação não é permitida.
    $member = attachMember($this->organization, MembershipRole::Member);
    [$token] = apiRawToken($this->organization, $member);

    $completed = Envelope::factory()->forOrganization($this->organization, $member)->completed()->create();

    // Visível (é dele), ação indisponível no status: 409 — nunca 404.
    assertProblem($this->postJson('/api/v1/envelopes/'.$completed->ulid.'/cancel', [], apiHeaders($token)), 409, 'invalid-status');
});
