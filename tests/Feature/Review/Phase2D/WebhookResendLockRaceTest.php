<?php

use App\Enums\AuditEventType;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookActionRefused;
use App\Services\Webhooks\WebhookEndpointManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Phase2/Webhooks/Support/WebhookHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão 2D (webhooks) — reenvio manual derruba a trava de uma tentativa em andamento
|--------------------------------------------------------------------------
| docs/fase-2/webhooks.md §5: "dois jobs para a mesma entrega nunca geram duas tentativas
| simultâneas". WebhookEndpointManager::resend confere `locked_until` no MODELO já carregado
| (binding da rota) e depois grava `locked_until = null` com save() incondicional. Se a
| varredura `webhooks:retry` reivindicar a entrega entre a leitura e a escrita, o reenvio apaga
| a trava viva e o job manual reivindica de novo: duas tentativas ao mesmo tempo.
| O teste reproduz a janela: o modelo é lido antes, e a reivindicação do worker (o mesmo
| UPDATE de WebhookDeliverer::claim) acontece depois da leitura.
*/

it('reenvio não atropela uma tentativa que outro worker reivindicou depois da leitura', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    makeEndpoint($organization, $owner);
    ['envelope' => $envelope] = webhookEnvelope($organization, $owner);
    Http::fake(['*' => Http::response('indisponível', 503)]);

    recordAudit($envelope, AuditEventType::EnvelopeSent);

    // Um worker anterior morreu no meio da tentativa: sobrou uma trava JÁ VENCIDA (a doc diz que
    // ela "é retomada"). Com trava nula o save() não grava a coluna (não está suja) e não há
    // corrida; com trava vencida, gravar `null` é uma escrita real.
    WebhookDelivery::withoutOrganizationScope()->update(['locked_until' => Carbon::now()->subMinute()]);

    // A tela carrega a entrega (binding da rota): falhou, trava vencida.
    $loaded = WebhookDelivery::withoutOrganizationScope()->sole();
    expect($loaded->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($loaded->locked_until?->isPast())->toBeTrue();
    $requestsBefore = count(webhookRequests());

    // Entre a leitura e a escrita, o worker da varredura reivindica a entrega e está enviando.
    $workerLock = Carbon::now()->addSeconds(45);
    WebhookDelivery::withoutOrganizationScope()->whereKey($loaded->getKey())->update(['locked_until' => $workerLock]);

    try {
        app(WebhookEndpointManager::class)->resend($loaded);
    } catch (WebhookActionRefused) {
        // recusar é o comportamento correto
    }

    expect(WebhookDelivery::withoutOrganizationScope()->whereKey($loaded->getKey())->value('locked_until'))->not->toBeNull()
        ->and(count(webhookRequests()))->toBe($requestsBefore);
});
