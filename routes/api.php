<?php

use App\Http\Controllers\Api\HooksCatalogController;
use App\Http\Controllers\Api\HooksSubscriptionController;
use App\Http\Controllers\Api\V1\EmbeddedSigningSessionController;
use App\Http\Controllers\Api\V1\EnvelopeController;
use App\Http\Controllers\Api\V1\EnvelopeDocumentController;
use App\Http\Controllers\Api\V1\EnvelopeEventController;
use App\Http\Controllers\Api\V1\EnvelopeFieldController;
use App\Http\Controllers\Api\V1\EnvelopeFileController;
use App\Http\Controllers\Api\V1\EnvelopeLifecycleController;
use App\Http\Controllers\Api\V1\EnvelopeRecipientController;
use App\Http\Controllers\Api\V1\EnvelopeVerificationController;
use App\Http\Controllers\Api\V1\TemplateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API REST v1 (Fase 2 §2.15 — docs/fase-2/api-v1.md)
|--------------------------------------------------------------------------
|
| Prefixo `/api` (bootstrap/app.php) + `v1`. Grupo `api` (bootstrap/app.php), nesta ordem:
| registro da requisição → flag global (404) → token Bearer (401; flag do plano → 404) →
| limite por token e por organização (429) → binding por ULID escopado à organização do token
| (404 para recurso de outra organização). Sem sessão, sem cookie, sem CSRF.
|
| Em cada rota: `api.ability:{ability}` (403 sem a ability ou sem a permissão do criador) e,
| nas criações e no envio, `api.idempotent` (cabeçalho `Idempotency-Key`). Depois disso as
| Policies da interface decidem sobre o recurso.
|
| Outras áreas da onda D (webhooks, REST Hooks) acrescentam rotas neste mesmo grupo `v1`.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('envelopes', [EnvelopeController::class, 'index'])
        ->middleware('api.ability:envelopes:read')
        ->name('envelopes.index');
    Route::post('envelopes', [EnvelopeController::class, 'store'])
        ->middleware(['api.ability:envelopes:write', 'api.idempotent'])
        ->name('envelopes.store');
    Route::get('envelopes/{envelope}', [EnvelopeController::class, 'show'])
        ->middleware('api.ability:envelopes:read')
        ->name('envelopes.show');

    Route::post('envelopes/{envelope}/documents', [EnvelopeDocumentController::class, 'store'])
        ->middleware(['api.ability:envelopes:write', 'api.idempotent'])
        ->name('envelopes.documents.store');

    Route::get('envelopes/{envelope}/recipients', [EnvelopeRecipientController::class, 'index'])
        ->middleware('api.ability:recipients:read')
        ->name('envelopes.recipients.index');
    Route::put('envelopes/{envelope}/recipients', [EnvelopeRecipientController::class, 'sync'])
        ->middleware(['api.ability:envelopes:write', 'api.idempotent:optional'])
        ->name('envelopes.recipients.sync');

    Route::get('envelopes/{envelope}/fields', [EnvelopeFieldController::class, 'index'])
        ->middleware('api.ability:envelopes:read')
        ->name('envelopes.fields.index');
    Route::put('envelopes/{envelope}/fields', [EnvelopeFieldController::class, 'sync'])
        ->middleware(['api.ability:envelopes:write', 'api.idempotent:optional'])
        ->name('envelopes.fields.sync');

    Route::post('envelopes/{envelope}/send', [EnvelopeLifecycleController::class, 'send'])
        ->middleware(['api.ability:envelopes:send', 'api.idempotent'])
        ->name('envelopes.send');
    Route::post('envelopes/{envelope}/cancel', [EnvelopeLifecycleController::class, 'cancel'])
        ->middleware(['api.ability:envelopes:send', 'api.idempotent:optional'])
        ->name('envelopes.cancel');

    Route::get('envelopes/{envelope}/files/{type}', [EnvelopeFileController::class, 'show'])
        ->whereIn('type', ['original', 'signed', 'evidence'])
        ->middleware('api.ability:documents:read')
        ->name('envelopes.files.show');

    Route::get('envelopes/{envelope}/events', [EnvelopeEventController::class, 'index'])
        ->middleware('api.ability:envelopes:read')
        ->name('envelopes.events.index');

    Route::get('envelopes/{envelope}/verification', [EnvelopeVerificationController::class, 'show'])
        ->middleware('api.ability:envelopes:read')
        ->name('envelopes.verification.show');

    Route::get('templates', [TemplateController::class, 'index'])
        ->middleware('api.ability:templates:read')
        ->name('templates.index');
    Route::get('templates/{template}', [TemplateController::class, 'show'])
        ->middleware('api.ability:templates:read')
        ->name('templates.show');
    Route::post('templates/{template}/envelopes', [TemplateController::class, 'generate'])
        ->middleware(['api.ability:templates:use', 'api.idempotent'])
        ->name('templates.envelopes.store');

    /*
    | REST Hooks (Fase 2 §2.17, D-PLAT — docs/fase-2/integracoes-no-code.md). Flag `rest_hooks`
    | (que exige `api_integrations` E `outbound_webhooks`): desligada, 404 em todas. Cada
    | assinatura é um endpoint do motor de webhooks ligado ao token que a criou.
    */
    Route::get('webhook-events', [HooksCatalogController::class, 'events'])
        ->middleware('api.ability:webhooks:manage')
        ->name('webhook_events.index');
    Route::get('webhook-events/{event}/sample', [HooksCatalogController::class, 'sample'])
        ->where('event', '[A-Za-z0-9_.]{1,60}')
        ->middleware('api.ability:webhooks:manage')
        ->name('webhook_events.sample');
    Route::get('webhook-subscriptions', [HooksSubscriptionController::class, 'index'])
        ->middleware('api.ability:webhooks:manage')
        ->name('webhook_subscriptions.index');
    Route::post('webhook-subscriptions', [HooksSubscriptionController::class, 'store'])
        ->middleware(['api.ability:webhooks:manage', 'api.idempotent'])
        ->name('webhook_subscriptions.store');
    Route::delete('webhook-subscriptions/{subscription}', [HooksSubscriptionController::class, 'destroy'])
        ->middleware('api.ability:webhooks:manage')
        ->name('webhook_subscriptions.destroy');

    /*
    | Assinatura embutida (Fase 3 §3.9, G-EMBED — docs/fase-3/widget-embutido.md §2). Flag
    | `embedded_signing` (global E plano): desligada, 404 em todas. A criação devolve a URL de uso
    | único UMA vez (resposta fora do armazenamento de idempotência; a repetição com a mesma chave
    | reemite a URL da MESMA sessão enquanto ela não foi usada).
    */
    Route::post('envelopes/{envelope}/recipients/{recipient}/embedded-sessions', [EmbeddedSigningSessionController::class, 'store'])
        ->middleware(['api.ability:embedded_signing:manage', 'api.idempotent'])
        ->name('envelopes.recipients.embedded_sessions.store');
    Route::get('envelopes/{envelope}/recipients/{recipient}/embedded-sessions/{embeddedSession}', [EmbeddedSigningSessionController::class, 'show'])
        ->middleware('api.ability:embedded_signing:manage')
        ->name('envelopes.recipients.embedded_sessions.show');
    Route::delete('envelopes/{envelope}/recipients/{recipient}/embedded-sessions/{embeddedSession}', [EmbeddedSigningSessionController::class, 'destroy'])
        ->middleware('api.ability:embedded_signing:manage')
        ->name('envelopes.recipients.embedded_sessions.destroy');
});
