<?php

use App\Http\Middleware\EnforceTwoFactorForOrganization;
use App\Http\Middleware\EnsureCurrentOrganization;
use App\Http\Middleware\EnsureMembershipRole;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResetCurrentOrganization;
use App\Http\Middleware\SecurityHeaders;
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
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxies confiáveis (X-Forwarded-*): configurados em AppServiceProvider::configureTrustedProxies()
        // a partir de config('assinavelox.trusted_proxies') — aqui a configuração ainda não foi carregada
        // e env() devolve null com `config:cache`.

        // Nenhuma organização corrente vaza entre requisições; só o middleware `org` a define.
        $middleware->prepend(ResetCurrentOrganization::class);

        // Cabeçalhos de segurança e CSP com nonce em todas as respostas (inclusive webhooks).
        $middleware->append(SecurityHeaders::class);

        // Route model binding escopado exige a organização corrente já resolvida:
        // `verified` e `org` passam a rodar ANTES de SubstituteBindings (após `auth`).
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureEmailIsVerified::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureCurrentOrganization::class);

        $middleware->encryptCookies(except: ['sidebar_state']);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('webhooks/*') || $request->expectsJson(),
        );

        // Páginas de erro Inertia (resources/js/pages/errors/{403,404,500}.tsx) fora de debug.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
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
                    ? $exception->getMessage()
                    : null,
            ])->toResponse($request)->setStatusCode($status);
        });
    })->create();
