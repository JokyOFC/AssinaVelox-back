<?php

use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Http\Middleware\EnsureCurrentOrganization;
use App\Http\Middleware\EnsureMembershipRole;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\DemoOrganizationSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\PlatformAdminSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Illuminate\View\View;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Smoke test: TODAS as rotas GET registradas × papel
|--------------------------------------------------------------------------
| Percorre as rotas GET da aplicação (sem parâmetros e com parâmetros vindos dos
| seeders de demonstração) e confere o status esperado por papel: convidado,
| owner, admin, member e platform admin. Qualquer rota nova entra automaticamente
| com a expectativa padrão do seu grupo de middleware; exceções ficam explícitas
| em OVERRIDES. Serve para detectar 500, autorização errada e páginas quebradas.
*/

/** Rotas de terceiros/dev que não fazem parte da aplicação. */
const SMOKE_EXCLUDED_URI_PREFIXES = ['horizon', '_inertia', 'storage/'];

/** Rotas "esqueleto" (Wave B/C) que ainda respondem 404 de propósito. */
const SMOKE_WAVE_B_STUBS_404 = [
    'envelopes.download',
    'envelopes.document.page',
    'billing.payments.receipt',
    'sign.document',
    'sign.page',
    'sign.download',
];

/**
 * Exceções às expectativas padrão do grupo: rota => [papel|'*' => status].
 *
 * @var array<string, array<string, int>>
 */
const SMOKE_OVERRIDES = [
    // Cria um rascunho e redireciona para o wizard.
    'envelopes.create' => ['owner' => 302, 'admin' => 302, 'member' => 302],
    // Placeholders Fase 2: redirecionam para o índice (member cai no org.role → 403).
    'integrations.keys' => ['owner' => 302, 'admin' => 302],
    'integrations.logs' => ['owner' => 302, 'admin' => 302],
    // Retorno do Checkout Pro: sempre redireciona para billing.index com flash.
    'billing.return' => ['owner' => 302, 'admin' => 302, 'member' => 302],
    // Usuário verificado é redirecionado para o dashboard.
    'verification.notice' => ['owner' => 302, 'admin' => 302, 'member' => 302, 'platform_admin' => 302],
    // Link assinado de verificação: usuário já verificado → redirect.
    'verification.verify' => ['*' => 302],
    // Desafio 2FA sem `login.id` na sessão → volta ao login.
    'two-factor.login' => ['*' => 302],
    // Fortify: chave secreta de usuário SEM TOTP ativado → 404 (qr-code/recovery-codes devolvem 200 vazio).
    'two-factor.secret-key' => ['owner' => 404, 'admin' => 404, 'member' => 404, 'platform_admin' => 404],
];

/**
 * Parâmetros por rota, resolvidos a partir dos dados do DemoOrganizationSeeder.
 *
 * @return array<string, mixed>
 */
function smokeRouteParameters(string $name, array $ctx): array
{
    /** @var Organization $org */
    $org = $ctx['organization'];
    /** @var Envelope $envelope */
    $envelope = $ctx['envelope'];
    /** @var Envelope $draft */
    $draft = $ctx['draft'];
    /** @var User $user */
    $user = $ctx['user'];

    return match ($name) {
        'envelopes.edit' => ['envelope' => $draft->ulid],
        'envelopes.show', 'envelopes.evidence', 'envelopes.document.status' => ['envelope' => $envelope->ulid],
        'envelopes.document.page' => ['envelope' => $envelope->ulid, 'page' => 1],
        'envelopes.download' => ['envelope' => $envelope->ulid, 'type' => 'original'],
        'billing.payments.receipt' => ['payment' => $ctx['payment']->ulid],
        'billing.return' => ['outcome' => 'success'],
        'admin.organizations.show' => ['organization' => $org->ulid],
        'invitations.accept' => ['token' => str_repeat('a', 43)],
        'sign.show', 'sign.document' => ['token' => str_repeat('b', 43)],
        'sign.page' => ['token' => str_repeat('b', 43), 'page' => 1],
        'sign.download' => ['token' => str_repeat('b', 43), 'type' => 'signed'],
        'verify.show' => ['code' => 'ABCD-EFGH-JKLM'],
        'password.reset' => ['token' => 'token-de-teste'],
        'search.index' => ['q' => 'contrato'],
        'verification.verify' => ['id' => $user->getKey(), 'hash' => sha1($user->email)],
        default => [],
    };
}

/**
 * Grupo de middleware da rota: public | guest | account | app | admin.
 */
function smokeRouteGroup(RouteInstance $route): string
{
    $has = smokeMiddlewareMatcher($route);

    return match (true) {
        $has('platform-admin', EnsurePlatformAdmin::class) => 'admin',
        $has('org', EnsureCurrentOrganization::class) => 'app',
        $has('auth', Authenticate::class) => 'account',
        $has('guest', RedirectIfAuthenticated::class) => 'guest',
        default => 'public',
    };
}

/**
 * Casa tanto o alias declarado na rota (`org`, `auth:web`, `org.role:owner`) quanto a
 * classe resolvida (Route::gatherRouteMiddleware), para não depender de cache do Router.
 *
 * @return callable(string, string): bool
 */
function smokeMiddlewareMatcher(RouteInstance $route): callable
{
    $declared = collect($route->middleware())->map(fn ($m) => is_string($m) ? $m : '');
    $resolved = collect(Route::gatherRouteMiddleware($route));

    return fn (string $alias, string $class): bool => $declared->contains(
        fn (string $m): bool => $m === $alias || str_starts_with($m, $alias.':'),
    ) || $resolved->contains(
        fn (string $m): bool => $m === $class || str_starts_with($m, $class.':'),
    );
}

/**
 * Status esperado por papel e grupo, antes das exceções.
 */
function smokeDefaultStatus(string $role, string $group, RouteInstance $route): int
{
    $requiresOrgRole = smokeMiddlewareMatcher($route)('org.role', EnsureMembershipRole::class);

    return match ($role) {
        'guest' => in_array($group, ['public', 'guest'], true) ? 200 : 302,
        'platform_admin' => match ($group) {
            'guest' => 302,
            'app' => 302, // sem membership → organizations.create
            default => 200,
        },
        'member' => match ($group) {
            'guest' => 302,
            'admin' => 403,
            'app' => $requiresOrgRole ? 403 : 200,
            default => 200,
        },
        default => match ($group) { // owner, admin
            'guest' => 302,
            'admin' => 403,
            default => 200,
        },
    };
}

/**
 * Rotas GET da aplicação: [identificador, rota].
 *
 * @return array<string, RouteInstance>
 */
function smokeGetRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = $route->uri();

        foreach (SMOKE_EXCLUDED_URI_PREFIXES as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                continue 2;
            }
        }

        $name = $route->getName() ?? (str_contains((string) $route->getActionName(), 'RedirectController')
            ? 'redirect:'.$uri
            : 'uri:'.$uri);

        $routes[$name] = $route;
    }

    ksort($routes);

    return $routes;
}

/**
 * Props de página NÃO podem sombrear as props compartilhadas do shell (ROUTES §0.3):
 * `organization` deve manter o shape CurrentOrganization (plan/role/permissions) e
 * `organizations` deve ser a lista do switcher. Uma página que quebre isso derruba a
 * sidebar/menu da conta em runtime (React), mesmo com HTTP 200.
 *
 * @return list<string>
 */
function smokeSharedPropsProblems(TestResponse $response): array
{
    $base = $response->baseResponse;
    $original = $base instanceof HttpResponse ? $base->getOriginalContent() : null;

    if (! $original instanceof View) {
        return []; // download, JSON ou redirect
    }

    $page = $original->getData()['page'] ?? null;

    if (! is_array($page)) {
        return []; // view Blade que não é a raiz do Inertia
    }

    $props = $page['props'] ?? [];
    $problems = [];

    if (array_key_exists('organizations', $props) && ! array_is_list($props['organizations'])) {
        $problems[] = 'prop `organizations` sombreia a lista compartilhada do switcher (não é lista)';
    }

    if (isset($props['organization']) && is_array($props['organization'])) {
        foreach (['id', 'name', 'initials', 'role', 'plan', 'permissions'] as $key) {
            if (! array_key_exists($key, $props['organization'])) {
                $problems[] = "prop `organization` sem `{$key}` — sombreia a prop compartilhada CurrentOrganization";
            }
        }
    }

    foreach (['auth', 'counts', 'flash', 'features'] as $shared) {
        if (! array_key_exists($shared, $props)) {
            $problems[] = "prop compartilhada `{$shared}` ausente";
        }
    }

    return $problems;
}

/**
 * @return array<string, mixed>
 */
function smokeContext(?string $email): array
{
    $org = Organization::query()->where('name', 'Imobiliária Horizonte Demo')->firstOrFail();
    $user = $email ? User::query()->where('email', $email)->firstOrFail() : User::query()->where('email', 'owner@horizonte.demo')->firstOrFail();

    $member = User::query()->where('email', 'operador@horizonte.demo')->firstOrFail();

    // Envelopes criados pelo operador (member): visíveis para todos os papéis da org.
    $envelope = Envelope::forOrganization($org)
        ->where('created_by_user_id', $member->getKey())
        ->where('status', EnvelopeStatus::InProgress->value)
        ->orderBy('id')
        ->firstOrFail();

    $draft = Envelope::forOrganization($org)
        ->where('created_by_user_id', $member->getKey())
        ->where('status', EnvelopeStatus::Draft->value)
        ->orderBy('id')
        ->firstOrFail();

    $payment = Payment::forOrganization($org)
        ->where('status', PaymentStatus::Approved->value)
        ->orderBy('id')
        ->firstOrFail();

    return compact('org', 'user', 'envelope', 'draft', 'payment') + ['organization' => $org];
}

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed([PlanSeeder::class, PlatformAdminSeeder::class, DemoOrganizationSeeder::class]);
});

test('todas as rotas GET respondem com o status esperado para o papel', function (string $role, ?string $email): void {
    $ctx = smokeContext($email);

    if ($email !== null) {
        $session = ['auth.password_confirmed_at' => time()];

        if ($role !== 'platform_admin') {
            $session[EnsureCurrentOrganization::SESSION_KEY] = $ctx['organization']->getKey();
        }

        $this->actingAs($ctx['user'])->withSession($session);
    }

    $failures = [];
    $visited = 0;

    foreach (smokeGetRoutes() as $name => $route) {
        $group = smokeRouteGroup($route);
        $expected = SMOKE_OVERRIDES[$name][$role]
            ?? SMOKE_OVERRIDES[$name]['*']
            ?? (in_array($name, SMOKE_WAVE_B_STUBS_404, true) && smokeDefaultStatus($role, $group, $route) === 200
                ? 404
                : smokeDefaultStatus($role, $group, $route));

        $parameters = smokeRouteParameters($name, $ctx);

        $url = $name === 'verification.verify'
            ? URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), $parameters)
            : ($route->getName() ? route($name, $parameters) : '/'.ltrim($route->uri(), '/'));

        $response = $this->get($url);
        $visited++;

        if ($response->getStatusCode() !== $expected) {
            $failures[] = sprintf(
                '%s [%s] GET %s → %d (esperado %d)',
                $name,
                $group,
                $url,
                $response->getStatusCode(),
                $expected,
            );

            continue;
        }

        foreach (smokeSharedPropsProblems($response) as $problem) {
            $failures[] = "{$name}: {$problem}";
        }
    }

    // ROUTES §1.2: as URIs da conta passaram a ser PT-BR (`/perfil`, `/perfil/seguranca`,
    // `/perfil/senha`) e os dois redirects do kit (`/settings`, `/perfil`) deixaram de existir.
    expect($visited)->toBeGreaterThanOrEqual(59);
    expect($failures)->toBe([], "Rotas fora do esperado para {$role}:\n - ".implode("\n - ", $failures));
})->with([
    'convidado' => ['guest', null],
    'owner' => ['owner', 'owner@horizonte.demo'],
    'admin' => ['admin', 'admin@horizonte.demo'],
    'member' => ['member', 'operador@horizonte.demo'],
    'platform admin' => ['platform_admin', 'admin@assinavelox.local'],
]);

test('member não vê documentos criados por outros usuários da organização', function (): void {
    $ctx = smokeContext('operador@horizonte.demo');
    $admin = User::query()->where('email', 'admin@horizonte.demo')->firstOrFail();

    $foreign = Envelope::forOrganization($ctx['organization'])
        ->where('created_by_user_id', $admin->getKey())
        ->orderBy('id')
        ->firstOrFail();

    actingAsMember($ctx['user'], $ctx['organization']);

    $this->get(route('envelopes.show', ['envelope' => $foreign->ulid]))->assertForbidden();
    $this->get(route('envelopes.evidence', ['envelope' => $foreign->ulid]))->assertForbidden();
    $this->get(route('envelopes.show', ['envelope' => $ctx['envelope']->ulid]))->assertOk();
});

test('platform admin sem membership é levado a criar organização ao acessar o app', function (): void {
    $admin = User::query()->where('email', 'admin@assinavelox.local')->firstOrFail();

    expect(Membership::query()->where('user_id', $admin->getKey())->exists())->toBeFalse();
    expect($admin->is_platform_admin)->toBeTrue();

    $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('organizations.create'));
    $this->actingAs($admin)->get(route('admin.organizations.index'))->assertOk();
});

test('owner da demo tem os papéis esperados nos seeders', function (): void {
    $ctx = smokeContext('owner@horizonte.demo');

    $roles = Membership::query()
        ->where('organization_id', $ctx['organization']->getKey())
        ->with('user')
        ->get()
        ->mapWithKeys(fn (Membership $m) => [$m->user->email => $m->role]);

    expect($roles['owner@horizonte.demo'])->toBe(MembershipRole::Owner)
        ->and($roles['admin@horizonte.demo'])->toBe(MembershipRole::Admin)
        ->and($roles['operador@horizonte.demo'])->toBe(MembershipRole::Member);
});
