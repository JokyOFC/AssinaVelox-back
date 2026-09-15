<?php

use App\Http\Controllers\Sso\OidcCallbackController;
use App\Http\Controllers\Sso\SamlController;
use App\Http\Controllers\Sso\SsoDomainController;
use App\Http\Controllers\Sso\SsoLoginController;
use App\Http\Controllers\Sso\SsoSettingsController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Fase 3 §3.9 — login corporativo por OIDC e SAML 2.0 (G-SSO, docs/fase-3/sso.md)
|--------------------------------------------------------------------------
| Incluído no fim de routes/web.php (grupo `web`). Flags `sso_oidc`/`sso_saml` desligadas (o
| padrão): TODAS respondem 404 — checado nos controllers, antes de qualquer consulta. Autentica
| o USUÁRIO do painel, nunca o signatário de um envelope (T1). Limitadores NOMEADOS
| (SsoServiceProvider): `sso-login`, `sso-callback`, `sso-settings`.
*/

// Entrada pela tela de login: o domínio do e-mail leva à organização e à conexão.
Route::post('sso/entrar', [SsoLoginController::class, 'start'])
    ->middleware('throttle:sso-login')
    ->name('sso.login.start');

// Volta do provedor OIDC (redirect_uri por conexão) e ACS/metadata do SAML. Públicas.
Route::get('sso/oidc/{connection}/retorno', OidcCallbackController::class)
    ->whereUlid('connection')
    ->middleware('throttle:sso-callback')
    ->name('sso.oidc.callback');
// POST do IdP (entre sites): sem CSRF (bootstrap/app.php); protegido por assinatura, pedido
// pendente de uso único, cookie do navegador que iniciou e proteção contra replay.
Route::post('sso/saml/{connection}/acs', [SamlController::class, 'acs'])
    ->whereUlid('connection')
    ->middleware('throttle:sso-callback')
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('sso.saml.acs');
Route::get('sso/saml/{connection}/metadata', [SamlController::class, 'metadata'])
    ->whereUlid('connection')
    ->middleware('throttle:public')
    ->name('sso.saml.metadata');

// Organização com SSO obrigatório: quem entrou por senha é trazido aqui (EnforceOrganizationSso).
Route::middleware(['auth'])->group(function (): void {
    Route::get('sso/obrigatorio', [SsoLoginController::class, 'required'])->name('sso.required');
    Route::post('sso/obrigatorio/entrar', [SsoLoginController::class, 'requiredStart'])
        ->middleware('throttle:sso-login')
        ->name('sso.required.start');
});

// Configurações › Login único (SSO). Owner e admin; o SSO obrigatório só o owner muda.
Route::middleware(['auth', 'verified', 'org', 'org.2fa', 'org.role:owner,admin'])
    ->prefix('configuracoes/sso')
    ->group(function (): void {
        Route::get('/', [SsoSettingsController::class, 'edit'])->name('settings.sso');

        Route::middleware('throttle:sso-settings')->group(function (): void {
            Route::post('conexao', [SsoSettingsController::class, 'store'])->name('settings.sso.connections.store');
            Route::patch('conexao/{connection}', [SsoSettingsController::class, 'update'])->name('settings.sso.connections.update');
            Route::post('conexao/{connection}/situacao', [SsoSettingsController::class, 'status'])->name('settings.sso.connections.status');
            Route::post('conexao/{connection}/testar', [SsoSettingsController::class, 'test'])->name('settings.sso.connections.test');
            Route::post('conexao/{connection}/metadata', [SsoSettingsController::class, 'importMetadata'])->name('settings.sso.connections.metadata');
            Route::delete('conexao/{connection}', [SsoSettingsController::class, 'destroy'])->name('settings.sso.connections.destroy');

            Route::post('dominios', [SsoDomainController::class, 'store'])->name('settings.sso.domains.store');
            Route::post('dominios/{domain}/verificar', [SsoDomainController::class, 'verify'])->name('settings.sso.domains.verify');
            Route::delete('dominios/{domain}', [SsoDomainController::class, 'destroy'])->name('settings.sso.domains.destroy');
        });
    });
