<?php

use App\Http\Controllers\Embed\EmbedPageController;
use App\Http\Controllers\Embed\EmbedScriptController;
use App\Http\Controllers\Embed\EmbedSessionController;
use App\Services\Embed\Http\AuthenticateEmbeddedSession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Widget de assinatura embutida (Fase 3 §3.9, G-EMBED — docs/fase-3/widget-embutido.md)
|--------------------------------------------------------------------------
|
| Carregadas por bootstrap/app.php (`withRouting(then: …)`) FORA do grupo `web`: sem sessão do
| app, sem cookie e sem CSRF — o widget roda no iframe de outro site, onde o cookie seria de
| terceiro, e se autentica pelo token de execução no cabeçalho `Authorization` (que o navegador
| não envia sozinho, então CSRF não se aplica). Flag `embedded_signing` desligada: tudo 404.
|
| - `embed.js`: o script do site hospedeiro (build do Vite, saída estável nesta URL).
| - `sessoes/{session}`: a página do widget. Só ela recebe `frame-ancestors {origem exata}`
|   (SecurityHeaders + EmbedFrame); o resto continua DENY.
| - `troca`: URL de uso único (token no fragmento) → token de execução, uma única vez.
| - Demais: exigem o token de execução (AuthenticateEmbeddedSession) e reusam os serviços do
|   fluxo público (código, PIN, documento, aceite, recusa).
|
| Limites por rota com prefixo próprio (throttle nomeado), para não dividir contador.
*/

Route::prefix('embed/v1')->name('embed.')->group(function (): void {
    Route::get('embed.js', [EmbedScriptController::class, 'show'])
        ->middleware('throttle:600,1,embed-script')
        ->name('script');

    Route::prefix('sessoes/{session}')
        ->where(['session' => '[0-9A-Za-z]{26}', 'document' => '[0-9A-Za-z]{26}'])
        ->group(function (): void {
            Route::get('/', [EmbedPageController::class, 'show'])
                ->middleware('throttle:60,1,embed-show')
                ->name('show');
            Route::post('troca', [EmbedSessionController::class, 'exchange'])
                ->middleware('throttle:20,1,embed-exchange')
                ->name('exchange');

            Route::middleware(AuthenticateEmbeddedSession::class)->group(function (): void {
                Route::get('estado', [EmbedSessionController::class, 'state'])
                    ->middleware('throttle:120,1,embed-state')
                    ->name('state');
                Route::post('codigo', [EmbedSessionController::class, 'sendCode'])
                    ->middleware('throttle:10,10,embed-otp-send')
                    ->name('otp.send');
                Route::post('codigo/verificar', [EmbedSessionController::class, 'verifyCode'])
                    ->middleware('throttle:15,10,embed-otp-verify')
                    ->name('otp.verify');
                Route::post('pin', [EmbedSessionController::class, 'verifyPin'])
                    ->middleware('throttle:10,10,embed-pin')
                    ->name('pin.verify');
                Route::get('documentos/{document}', [EmbedSessionController::class, 'document'])
                    ->middleware('throttle:60,1,embed-document')
                    ->name('document');
                Route::post('assinar', [EmbedSessionController::class, 'complete'])
                    ->middleware('throttle:10,1,embed-complete')
                    ->name('complete');
                Route::post('recusar', [EmbedSessionController::class, 'refuse'])
                    ->middleware('throttle:10,10,embed-refuse')
                    ->name('refuse');
            });
        });
});
