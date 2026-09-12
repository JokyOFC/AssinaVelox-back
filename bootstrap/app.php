<?php

use App\Http\Middleware\ApiAuthenticate;
use App\Http\Middleware\ApiEnsureEnabled;
use App\Http\Middleware\ApiIdempotency;
use App\Http\Middleware\ApiRateLimit;
use App\Http\Middleware\ApiRequestLogger;
use App\Http\Middleware\ApiRequireAbility;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnforceImpersonationReadOnly;
use App\Http\Middleware\EnforceSessionIdleTimeout;
use App\Http\Middleware\EnforceTwoFactorForOrganization;
use App\Http\Middleware\EnsureAccountNotBlocked;
use App\Http\Middleware\EnsureCurrentOrganization;
use App\Http\Middleware\EnsureMembershipRole;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureSignerVerified;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResetCurrentOrganization;
use App\Http\Middleware\ResolveSignerToken;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleSensitiveRoutes;
use App\Services\Api\ApiProblem;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // API REST v1 (Fase 2 §2.15, docs/fase-2/api-v1.md): prefixo `/api`, grupo `api` abaixo.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxies confiáveis (X-Forwarded-*): configurados em AppServiceProvider::configureTrustedProxies()
        // a partir de config('assinavelox.trusted_proxies') — aqui a configuração ainda não foi carregada
        // e env() devolve null com `config:cache`.

        // Hosts confiáveis: só APP_URL (e seus subdomínios). Requisições com um Host forjado são
        // recusadas antes de chegar à aplicação, o que fecha a injeção de Host nos links enviados
        // por e-mail. O TrustHosts do Laravel não roda em `local` nem nos testes; a raiz das URLs
        // absolutas é fixada de qualquer forma em AppServiceProvider::configureAbsoluteUrls().
        $middleware->trustHosts(at: fn (): array => array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
        ]));

        // Nenhuma organização corrente vaza entre requisições; só o middleware `org` a define.
        $middleware->prepend(ResetCurrentOrganization::class);

        // Identificador de correlação: PRIMEIRO middleware da pilha (o último `prepend`
        // fica na frente), para que tudo o que acontecer depois — inclusive uma exceção
        // lançada dentro de outro middleware — seja registrado sob o mesmo identificador.
        $middleware->prepend(AssignCorrelationId::class);

        // Cabeçalhos de segurança e CSP com nonce em todas as respostas (inclusive webhooks).
        $middleware->append(SecurityHeaders::class);

        // Route model binding escopado exige a organização corrente já resolvida:
        // `verified` e `org` passam a rodar ANTES de SubstituteBindings (após `auth`).
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureEmailIsVerified::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureCurrentOrganization::class);

        $middleware->encryptCookies(except: ['sidebar_state']);

        $middleware->web(append: [
            // Limites por NOME de rota (config `assinavelox.rate_limit_routes`): cobre o
            // cadastro, a recuperação e a redefinição de senha — rotas do pacote Fortify,
            // que não traz limitador nenhum nelas — e os downloads/exportações.
            ThrottleSensitiveRoutes::class,
            // Política "Encerrar sessões após N horas inativas" (ROUTES §7 Q27): vale para
            // toda tela autenticada, inclusive as que ficam fora do grupo `app`.
            EnforceSessionIdleTimeout::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Fase 2 (docs/fase-2/tags-relatorios-e-logs.md): conta bloqueada pelo painel interno
            // e sessão de "acessar como" somente leitura. Sem bloqueio nem impersonation na
            // sessão, os dois não fazem nada.
            EnsureAccountNotBlocked::class,
            EnforceImpersonationReadOnly::class,
        ]);

        // Webhook do Mercado Pago: sem CSRF (autenticado por assinatura do provedor).
        $middleware->validateCsrfTokens(except: [
            'webhooks/mercadopago',
        ]);

        $middleware->alias([
            'org' => EnsureCurrentOrganization::class,
            'org.role' => EnsureMembershipRole::class,
            'org.2fa' => EnforceTwoFactorForOrganization::class,
            'platform-admin' => EnsurePlatformAdmin::class,
            // Fluxo público do signatário (docs/fluxo-do-signatario.md).
            'signer' => ResolveSignerToken::class,
            'signer.verified' => EnsureSignerVerified::class,
            // API REST v1 (docs/fase-2/api-v1.md): ability exigida pela rota e Idempotency-Key.
            'api.ability' => ApiRequireAbility::class,
            'api.idempotent' => ApiIdempotency::class,
        ]);

        /*
         * API REST v1 (docs/fase-2/api-v1.md). O grupo `api` padrão é SUBSTITUÍDO por esta
         * pilha, nesta ordem: registro da requisição (mede e grava tudo, até os erros) → flag
         * global (404) → token Bearer, organização e flag do plano (401/404) → limite por token
         * e por organização (429) → binding por ULID, escopado à organização DO TOKEN (404).
         * Sem sessão, cookie ou CSRF. Nas rotas: `api.ability:*` e `api.idempotent`.
         */
        $middleware->group('api', [
            ApiRequestLogger::class,
            ApiEnsureEnabled::class,
            ApiAuthenticate::class,
            ApiRateLimit::class,
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('webhooks/*') || $request->expectsJson(),
        );

        // API v1: todo e qualquer erro sai como problem+json (RFC 9457) com a mesma forma — 400, 401, 403,
        // 404, 409, 422, 429 e 500 —, sem stack nem mensagem interna (App\Services\Api\ApiProblem).
        // Só muda a RESPOSTA: o registro no log continua como antes.
        $exceptions->render(function (Throwable $exception, Request $request) {
            return $request->is('api', 'api/*') ? ApiProblem::fromThrowable($exception) : null;
        });

        /**
         * As páginas de erro só exibem mensagens escritas pela aplicação (`abort(404, '…')`,
         * sempre em PT-BR). Mensagens geradas pelo framework são em inglês e podem revelar
         * detalhes internos ("No query results for model [App\Models\Envelope] 01K…"), então
         * são descartadas e a página usa a própria cópia padrão.
         */
        $userFacingMessage = function (string $message): ?string {
            $message = trim($message);

            $frameworkPrefixes = [
                'The route ',
                'No query results for model',
                'The GET method is not supported',
                'The POST method is not supported',
                'This action is unauthorized',
                'Unauthenticated',
                'Server Error',
                'Not Found',
                'Forbidden',
                'Service Unavailable',
            ];

            foreach ($frameworkPrefixes as $prefix) {
                if (str_starts_with($message, $prefix)) {
                    return null;
                }
            }

            return $message === '' ? null : $message;
        };

        // Páginas de erro Inertia (resources/js/pages/errors/{403,404,500}.tsx) fora de debug.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) use ($userFacingMessage) {
            $status = $response->getStatusCode();

            if (! in_array($status, [403, 404, 500, 503], true) || $request->expectsJson() || $request->is('api/*', 'webhooks/*')) {
                return $response;
            }

            // Em debug o 500 mantém a página de diagnóstico; nos testes a resposta bruta
            // preserva status/mensagem para as asserções.
            if (($status >= 500 && app()->hasDebugModeEnabled()) || app()->runningUnitTests()) {
                return $response;
            }

            $component = match ($status) {
                403 => 'errors/403',
                404 => 'errors/404',
                default => 'errors/500',
            };

            return Inertia::render($component, [
                'status' => $status,
                'message' => $exception instanceof HttpExceptionInterface
                    ? $userFacingMessage($exception->getMessage())
                    : null,
            ])->toResponse($request)->setStatusCode($status);
        });
    })->create();
