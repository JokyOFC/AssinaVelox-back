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
/*
| Rotas registradas por pacotes, fora do produto:
| - `sanctum/csrf-cookie`: rota do Sanctum para SPA em outro domínio. O front é Inertia na
|   mesma origem (sessão + CSRF do Laravel), então ela não participa de nenhum fluxo; o
|   Sanctum entra na Fase 2 só pelos tokens da API v1.
| - `docs/api*` e `_scramble/*`: documentação OpenAPI do Scramble. Por padrão responde 403
|   fora do ambiente local (gate `viewApiDocs`), que é o comportamento desejado até a onda
|   da API definir quem pode vê-la — e essa onda terá teste próprio para isso.
| - `api/*`: API REST v1 (Fase 2 §2.15, docs/fase-2/api-v1.md). Não é página de sessão por
|   papel: autentica só por token Bearer (sem cookie), responde 404 com a flag
|   `api_integrations` desligada e tem matriz própria (isolamento em todas as rotas, abilities,
|   401/403/404/409/422/429, flag desligada) em tests/Feature/Phase2/Api. O mesmo vale para o
|   gate da documentação, testado ali.
*/
const SMOKE_EXCLUDED_URI_PREFIXES = ['horizon', '_inertia', 'storage/', 'sanctum/', 'docs/api', '_scramble/', 'api/'];

/**
 * Rotas que respondem 404 de propósito neste contexto: ou continuam esqueleto (Wave C),
 * ou dependem de um arquivo que o envelope do smoke não tem.
 *
 * - `envelopes.download` / `envelopes.document.preview`: implementadas (B-DOC), mas o
 *   envelope usado aqui não tem documento com arquivo no disco.
 * - `envelopes.document.page`: miniatura PNG descontinuada por decisão de arquitetura —
 *   o rail é renderizado no navegador com PDF.js (docs/preparacao-documental.md).
 */
const SMOKE_WAVE_B_STUBS_404 = [
    'envelopes.download',
    'envelopes.document.page',
    'envelopes.document.preview',
    // `billing.payments.receipt` saiu desta lista no incremento 5: o recibo interno em
    // PDF passou a existir e o pagamento aprovado dos seeders responde 200.
    // Fluxo público do signatário: o token do smoke não corresponde a convite nenhum, e a
    // resposta é 404 genérico — idêntica para token desconhecido, revogado, vencido ou fora
    // da vez, para que a página não vire um oráculo de existência de convites
    // (docs/fluxo-do-signatario.md §3). `sign.show` devolve a página `sign/show` com
    // `screen: 'invalid'` e status 404; as demais abortam com 404 seco.
    'sign.show',
    'sign.document',
    'sign.page',
    'sign.download',
    // Fase 2 §2.12 (K-A1): estado do certificado do participante — mesmo token sintético, 404.
    'sign.certificate.show',
    // Fase 3 §3.4 (P3-EXT, docs/fase-3/assinatura-externa-a3.md §7): estado da assinatura por
    // componente local e certificado do simulador — token sintético e flag `a3_signing`
    // desligada (o padrão) respondem o mesmo 404.
    'sign.external.show',
    'sign.external.simulator.certificate',
    // Fase 3 §3.5 (P3-GOV, docs/fase-3/gov-br.md §6): estado da devolução do PDF assinado no
    // portal e download da revisão reservada — token sintético e flag `govbr_return` desligada
    // (o padrão) respondem o mesmo 404.
    'sign.govbr.show',
    'sign.govbr.download',
    // Fase 3 §3.3 (F-FLOW, docs/fase-3/etapas-e-delegacao.md §5): estado da delegação do
    // participante — token sintético e flag `delegation` desligada (o padrão) respondem 404.
    'sign.delegation.show',
    // Fase 2 §2.2 (C-FORM): token sintético de formulário público e de confirmação. Token
    // desconhecido, rascunho, revogado ou flag desligada recebem o mesmo 404
    // (docs/fase-2/formulario-publico.md §5).
    'form_fill.show',
    'form_fill.confirm.show',
    // Fase 2 §2.6/§2.7 (C-PRES): PDF do dispositivo presencial e do item do lote. Sem sessão
    // presencial / lote autenticado neste navegador, 404 seco — mesma regra de `sign.document`
    // (docs/fase-2/presencial-e-lote.md §5).
    'in_person.kiosk.document',
    'sign.batch.document',
];

/**
 * Exceções às expectativas padrão do grupo: rota => [papel|'*' => status].
 *
 * @var array<string, array<string, int>>
 */
const SMOKE_OVERRIDES = [
    // Fase 3 §3.9 (G-CONN, docs/fase-3/conectores.md §8): importação da nuvem e app HubSpot — com
    // as flags `cloud_import` e `hubspot` desligadas (o padrão), 404 para os papéis do app.
    'cloud_import.show' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'cloud_import.google.start' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'cloud_import.google.callback' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'integrations.hubspot.show' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'integrations.hubspot.callback' => ['owner' => 404, 'admin' => 404, 'member' => 404],
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
    // Fase 2 §2.1 (docs/fase-2/modelos.md): com a flag `templates` desligada — o padrão —
    // só `templates.index` existe (placeholder); as demais rotas de modelos respondem 404.
    'templates.edit' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'templates.preview' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'templates.source.show' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'templates.picker' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    // Fase 2 §2.2 (docs/fase-2/formulario-publico.md): com a flag `public_forms` desligada —
    // o padrão — todas as telas internas do formulário público respondem 404.
    'public_forms.index' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'public_forms.edit' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    // Fase 2 §2.19 (K-RET, docs/fase-2/retencao-e-preservacao.md): a tela de retenção exige
    // `manage_settings` pela Policy (sem `org.role`), então o operador recebe 403.
    'settings.retention' => ['member' => 403],
    // Revisão adversarial da onda C: prévia da exclusão (JSON) — flag `retention_policies`
    // desligada responde 404 (RetentionFeature::ensure); sem `manage_settings`, 403.
    'settings.retention.preview' => ['owner' => 404, 'admin' => 404, 'member' => 403],
    // Fase 2 §2.13 (K-TSA, docs/fase-2/carimbo-e-dossie.md): com a flag `dossier_export`
    // desligada — o padrão — status e download do dossiê respondem 404.
    'dossiers.show' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'dossiers.download' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    // Fase 2 §2.16 (D-HOOK, docs/fase-2/webhooks.md): com a flag `outbound_webhooks`
    // desligada — o padrão — a gestão de webhooks responde 404 para todos.
    'integrations.webhooks.index' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'integrations.webhooks.show' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'integrations.webhooks.deliveries.show' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    // Fase 3 §3.10 (P3-AFF, docs/fase-3/afiliados.md §2): com a flag `affiliates` desligada —
    // o padrão — todas as rotas do programa respondem 404 depois dos middlewares do grupo
    // (convidado continua indo para o login; nas rotas do painel sem parâmetro quem não é da
    // plataforma continua com 403).
    'affiliates.link' => ['*' => 404],
    'affiliates.index' => ['owner' => 404, 'admin' => 404, 'member' => 404, 'platform_admin' => 404],
    'affiliates.commissions.export' => ['owner' => 404, 'admin' => 404, 'member' => 404, 'platform_admin' => 404],
    // Nas rotas com ULID sintético, o binding implícito (SubstituteBindings) roda antes do
    // middleware `platform-admin` (fora da lista de prioridade), então quem não é da
    // plataforma recebe 404 do ULID inexistente em vez de 403.
    'admin.affiliates.index' => ['platform_admin' => 404],
    'admin.affiliates.show' => ['owner' => 404, 'admin' => 404, 'member' => 404, 'platform_admin' => 404],
    'admin.affiliates.payouts.index' => ['platform_admin' => 404],
    'admin.affiliates.payouts.show' => ['owner' => 404, 'admin' => 404, 'member' => 404, 'platform_admin' => 404],
    'admin.affiliates.payouts.export' => ['owner' => 404, 'admin' => 404, 'member' => 404, 'platform_admin' => 404],
    // Fase 3 §3.7 (P3-RISK, docs/fase-3/antifraude.md; RiskFeature): com a flag `antifraud`
    // desligada — o padrão — o painel e o pedido de revisão respondem 404. `admin.risk.show`
    // usa ULID sintético: mesmo efeito do binding descrito acima para quem não é da plataforma.
    'admin.risk.index' => ['platform_admin' => 404],
    'admin.risk.precision' => ['platform_admin' => 404],
    'admin.risk.show' => ['owner' => 404, 'admin' => 404, 'member' => 404, 'platform_admin' => 404],
    'risk.appeal.show' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    // Fase 3 §3.3 (F-FLOW, docs/fase-3/etapas-e-delegacao.md §5): JSON do fluxo (etapas e
    // delegação) — com as flags `conditional_steps` e `delegation` desligadas, 404 para todos.
    'envelopes.flow.show' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    // Fase 3 §3.3 (F-VIDEO, docs/fase-3/captura-de-video.md): com a flag `identity_video`
    // desligada — o padrão — a lista de vídeos e o arquivo respondem 404 (antes da assinatura).
    'envelopes.identity_videos.index' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'envelopes.identity_videos.file' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    // Fase 3 §3.1 (F-BULK, docs/fase-3/geracao-em-lote.md): com a flag `bulk_generation`
    // desligada — o padrão — todas as telas do lote respondem 404.
    'bulk_generations.index' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'bulk_generations.create' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'bulk_generations.sample' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'bulk_generations.show' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'bulk_generations.report' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    // Fase 3 §3.2 (F-ANCHOR, docs/fase-3/ancoras-e-ocr.md §8): JSON do painel de âncoras e das
    // regras do modelo — com a flag `field_anchors` desligada (o padrão), 404 para todos.
    'anchors.envelope.index' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    'anchors.template.index' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    // Fase 3 §3.3 (F-I18N, docs/fase-3/multilingue.md §3): JSON dos idiomas dos participantes —
    // com a flag `multilingual` desligada (o padrão), 404 para todos.
    'envelopes.recipients.locales' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    // Fase 3 §3.9 (G-EMBED, docs/fase-3/widget-embutido.md §10): com a flag `embedded_signing`
    // desligada — o padrão — o embed.js, a página do widget, o estado e o PDF respondem 404 para
    // todos (rotas fora do grupo `web`, sem login), e a tela de origens responde 404 no painel.
    'embed.script' => ['*' => 404],
    'embed.show' => ['*' => 404],
    'embed.state' => ['*' => 404],
    'embed.document' => ['*' => 404],
    'integrations.embed.edit' => ['owner' => 404, 'admin' => 404, 'member' => 404],
    // Fase 3 §3.9 (G-SSO, docs/fase-3/sso.md §11): com as flags `sso_oidc`/`sso_saml` desligadas —
    // o padrão — a tela de configuração, a página de SSO obrigatório, a volta do OIDC e o metadata
    // do SAML respondem 404 (a tela exige owner/admin: o member recebe 403 antes, do `org.role`).
    'settings.sso' => ['owner' => 404, 'admin' => 404],
    'sso.required' => ['owner' => 404, 'admin' => 404, 'member' => 404, 'platform_admin' => 404],
    'sso.oidc.callback' => ['*' => 404],
    'sso.saml.metadata' => ['*' => 404],
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
        // Fase 3 §3.9 (G-CONN): rascunho real — flag `cloud_import` desligada responde 404.
        'cloud_import.show', 'cloud_import.google.start' => ['envelope' => $draft->ulid],
        'envelopes.edit' => ['envelope' => $draft->ulid],
        'envelopes.show', 'envelopes.evidence', 'envelopes.document.status', 'envelopes.document.preview' => ['envelope' => $envelope->ulid],
        // Fase 2 §2.19 (K-RET): JSON de preservação do detalhe (só `view`).
        'envelopes.legal_hold.show' => ['envelope' => $envelope->ulid],
        'envelopes.document.page' => ['envelope' => $envelope->ulid, 'page' => 1],
        'envelopes.download' => ['envelope' => $envelope->ulid, 'type' => 'original'],
        'billing.payments.receipt' => ['payment' => $ctx['payment']->ulid],
        'billing.return' => ['outcome' => 'success'],
        'admin.organizations.show' => ['organization' => $org->ulid],
        'invitations.accept' => ['token' => str_repeat('a', 43)],
        'sign.show', 'sign.document', 'sign.certificate.show' => ['token' => str_repeat('b', 43)],
        // Fase 3 §3.4 (P3-EXT): mesmo token sintético — 404.
        'sign.external.show', 'sign.external.simulator.certificate' => ['token' => str_repeat('b', 43)],
        // Fase 3 §3.5 (P3-GOV): mesmo token sintético e ULID de pedido inexistente — 404.
        'sign.govbr.show' => ['token' => str_repeat('b', 43)],
        'sign.govbr.download' => ['token' => str_repeat('b', 43), 'pedido' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
        // Fase 3 §3.3 (F-FLOW): token sintético (404) e o envelope em andamento do seeder (flags desligadas, 404).
        'sign.delegation.show' => ['token' => str_repeat('b', 43)],
        'envelopes.flow.show' => ['envelope' => $envelope->ulid],
        'sign.page' => ['token' => str_repeat('b', 43), 'page' => 1],
        'sign.download' => ['token' => str_repeat('b', 43), 'type' => 'signed'],
        'verify.show' => ['code' => 'ABCD-EFGH-JKLM'],
        'password.reset' => ['token' => 'token-de-teste'],
        'search.index' => ['q' => 'contrato'],
        'verification.verify' => ['id' => $user->getKey(), 'hash' => sha1($user->email)],
        // Fase 2: ULID sintético — com a flag desligada a rota responde 404 antes do binding.
        'templates.edit', 'templates.preview', 'templates.source.show' => ['template' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
        // Fase 2 §2.2: ULID e tokens sintéticos — flag desligada (interno) e token desconhecido
        // (público) respondem 404.
        'public_forms.edit' => ['publicForm' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
        'form_fill.show' => ['token' => str_repeat('c', 40)],
        'form_fill.confirm.show' => ['token' => str_repeat('c', 40), 'confirmation' => str_repeat('d', 48)],
        // Fase 2 §2.13 (K-TSA): ULID sintético — flag `dossier_export` desligada responde 404.
        'dossiers.show', 'dossiers.download' => ['dossierExport' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
        // Fase 2 §2.16 (D-HOOK): ULIDs sintéticos — flag `outbound_webhooks` desligada responde 404.
        'integrations.webhooks.show' => ['webhookEndpoint' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
        'integrations.webhooks.deliveries.show' => ['webhookEndpoint' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'delivery' => '01HZZZZZZZZZZZZZZZZZZZZZZY'],
        // Fase 3 §3.10 (P3-AFF): código e ULIDs sintéticos — flag `affiliates` desligada responde 404.
        'affiliates.link' => ['code' => 'ABCDEFGH'],
        'admin.affiliates.show' => ['affiliate' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
        'admin.affiliates.payouts.show', 'admin.affiliates.payouts.export' => ['batch' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
        // Fase 3 §3.7 (P3-RISK): ULID sintético de caso — flag `antifraud` desligada responde 404.
        'admin.risk.show' => ['review' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
        // Fase 3 §3.3 (F-VIDEO): envelope real e ULID sintético de vídeo — flag desligada, 404.
        'envelopes.identity_videos.index' => ['envelope' => $envelope->ulid],
        'envelopes.identity_videos.file' => ['envelope' => $envelope->ulid, 'video' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
        // Fase 3 §3.1 (F-BULK): ULIDs sintéticos — flag `bulk_generation` desligada responde 404.
        'bulk_generations.create', 'bulk_generations.sample' => ['template' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
        'bulk_generations.show', 'bulk_generations.report' => ['bulkGeneration' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
        // Fase 3 §3.2 (F-ANCHOR): rascunho real e ULID sintético de modelo — flag desligada, 404.
        'anchors.envelope.index' => ['envelope' => $draft->ulid],
        'anchors.template.index' => ['template' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
        // Fase 3 §3.3 (F-I18N): envelope em andamento do seeder — flag `multilingual` desligada, 404.
        'envelopes.recipients.locales' => ['envelope' => $envelope->ulid],
        // Fase 3 §3.9 (G-EMBED): ULIDs sintéticos de sessão e documento — flag `embedded_signing`
        // desligada responde 404.
        'embed.show', 'embed.state' => ['session' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
        'embed.document' => ['session' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'document' => '01HZZZZZZZZZZZZZZZZZZZZZZY'],
        // Fase 3 §3.9 (G-SSO): ULID sintético de conexão — flags `sso_oidc`/`sso_saml` desligadas, 404.
        'sso.oidc.callback', 'sso.saml.metadata' => ['connection' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
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
            'app' => 302, // sem membership → painel interno (admin.organizations.index)
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

test('platform admin sem membership é levado ao painel interno ao acessar o app', function (): void {
    $admin = User::query()->where('email', 'admin@assinavelox.local')->firstOrFail();

    expect(Membership::query()->where('user_id', $admin->getKey())->exists())->toBeFalse();
    expect($admin->is_platform_admin)->toBeTrue();

    // Antes ia para organizations.create; o app de cliente não é para essa conta
    // (App\Support\LandingRoute). Criar organização continua possível pelo endereço direto.
    $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('admin.organizations.index'));
    $this->actingAs($admin)->get(route('admin.organizations.index'))->assertOk();
    $this->actingAs($admin)->get(route('organizations.create'))->assertOk();
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
