<?php

use App\Http\Controllers\Site\PlanCatalogController;
use App\Http\Controllers\Site\VerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API do site institucional (docs/site-institucional.md)
|--------------------------------------------------------------------------
|
| Consumida pelo site em assinavelox.com.br (React estático, outro domínio). Carregada por
| bootstrap/app.php (`withRouting(then: …)`) FORA do grupo `web`: sem sessão, sem cookie e sem
| CSRF — nada aqui depende de quem consulta, e o navegador do visitante chama de outra origem.
| Prefixo `api/*`: erros em problem+json (ApiProblem) e CORS aberto (config padrão do framework);
| NÃO passa pela pilha `api` (token Bearer) do routes/api.php, que é a API v1 das organizações.
|
| - `planos`: catálogo público de planos (mesma regra de visibilidade da tela interna).
| - `verificar/{code}`: a consulta pública por código, no mesmo contrato da página /verificar.
| - `verificar/{code}/conferir`: conferência de um resumo SHA-256 já calculado (nunca o arquivo).
|
| Limites por rota com prefixo próprio (throttle nomeado), para não dividir contador com a
| página /verificar do próprio app.
*/

Route::prefix('api/site')->name('site.')->middleware('throttle:public')->group(function (): void {
    Route::get('planos', [PlanCatalogController::class, 'index'])->name('plans');

    Route::get('verificar/{code}', [VerificationController::class, 'show'])
        ->middleware('throttle:20,1,site-verify')
        ->where('code', '[A-Za-z0-9-]{12,24}')
        ->name('verify.show');
    Route::post('verificar/{code}/conferir', [VerificationController::class, 'checkFile'])
        ->middleware('throttle:10,1,site-verify-check')
        ->where('code', '[A-Za-z0-9-]{12,24}')
        ->name('verify.check_file');
});
