<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Integrations\Payments\MercadoPagoGateway;
use App\Integrations\Payments\MercadoPagoSignature;
use App\Jobs\Billing\SyncMercadoPagoPayment;
use App\Models\PaymentWebhookReceipt;
use App\Services\Billing\WebhookReceipts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook do Mercado Pago (ROUTES §1.2 `webhooks.mercadopago`).
 *
 * Sem CSRF (a exceção está em bootstrap/app.php), sem sessão, com `throttle:webhook`.
 *
 * ## O que acontece a cada aviso
 *
 * 1. **Validar a assinatura** (`MercadoPagoSignature`, algoritmo oficial: manifesto
 *    `id:<data.id>;request-id:<x-request-id>;ts:<ts>;`, HMAC-SHA256 com a chave secreta
 *    do painel, comparação em tempo constante, janela de tolerância configurável).
 *    Assinatura ausente, inválida ou fora da janela → **401, sem processar nada** e sem
 *    gravar recibo (um recibo por tentativa não autenticada só serviria para encher a
 *    tabela). Sem chave secreta configurada também é 401: sem validar, não se processa.
 * 2. **Gravar o recibo** com `event_fingerprint` único (`type:data.id:action`). Uma
 *    reentrega do mesmo evento reencontra a linha e **não reprocessa**.
 * 3. **Responder 200 imediatamente** e despachar `SyncMercadoPagoPayment`, que consulta
 *    `GET /v1/payments/{id}` — a fonte da verdade. O payload do aviso nunca decide nada.
 *
 * Tópicos que não são `payment` (ordem comercial, contestação, reclamação) são
 * reconhecidos, gravados como `ignored` e respondidos com 200: repetir uma notificação
 * que não sabemos tratar não ajudaria ninguém.
 *
 * ## Detalhe do `data.id`
 *
 * O provedor manda `?data.id=123&type=payment`. O PHP transforma `data.id` em `data_id`
 * ao montar `$_GET`, então lemos a **query string bruta** para recuperar o valor exato
 * que entra no manifesto do HMAC. Sem isso, a assinatura nunca bateria.
 */
class MercadoPagoController extends Controller
{
    public function __construct(
        private readonly MercadoPagoSignature $signature,
        private readonly WebhookReceipts $receipts,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $xSignature = $request->header('x-signature');
        $xRequestId = $request->header('x-request-id');
        $dataId = $this->dataId($request);

        $verification = $this->signature->verify(
            is_string($xSignature) ? $xSignature : null,
            is_string($xRequestId) ? $xRequestId : null,
            $dataId,
        );

        if ($verification['valid'] !== true) {
            Log::warning('mercadopago.webhook.rejected', [
                'reason' => $verification['reason'],
                'x_request_id' => $xRequestId,
                'data_id' => $dataId,
                'ip' => $request->ip(),
            ]);

            // Corpo genérico de propósito: nada de expor o motivo da rejeição.
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        if ($verification['lowercased_data_id'] === true) {
            Log::notice('mercadopago.webhook.lowercased_data_id', [
                'x_request_id' => $xRequestId,
                'data_id' => $dataId,
            ]);
        }

        $type = $this->topic($request);
        $action = $request->input('action');
        $action = is_string($action) ? $action : null;

        if ($dataId === null || $dataId === '' || $type === null) {
            Log::warning('mercadopago.webhook.incomplete', [
                'x_request_id' => $xRequestId,
                'type' => $type,
            ]);

            return response()->json(['received' => true, 'ignored' => 'incomplete']);
        }

        $receipt = $this->receipts->record(
            provider: MercadoPagoGateway::NAME,
            fingerprint: PaymentWebhookReceipt::fingerprint($type, $dataId, $action),
            topic: $type,
            action: $action,
            // O corpo do aviso é guardado como evidência do que chegou; ele não é usado
            // para decidir nada. Não contém credencial (é `id`, `type`, `action`, `data.id`).
            payload: $this->safePayload($request),
            signatureHeader: is_string($xSignature) ? $xSignature : null,
            signatureValid: true,
        );

        if (! $this->receipts->shouldProcess($receipt)) {
            Log::info('mercadopago.webhook.duplicate', [
                'receipt_id' => $receipt->getKey(),
                'fingerprint' => $receipt->event_fingerprint,
                'status' => $receipt->processing_status->value,
            ]);

            return response()->json(['received' => true, 'duplicate' => true]);
        }

        if ($type !== 'payment') {
            $this->receipts->markIgnored($receipt, 'topic_not_handled');

            return response()->json(['received' => true, 'ignored' => 'topic_not_handled']);
        }

        SyncMercadoPagoPayment::dispatch($dataId, (int) $receipt->getKey());

        return response()->json(['received' => true]);
    }

    /**
     * Valor exato do query param `data.id` (o PHP renomearia para `data_id` em `$_GET`).
     * Cai para o corpo quando o provedor manda só o JSON.
     */
    private function dataId(Request $request): ?string
    {
        $queryString = $request->server('QUERY_STRING');

        if (is_string($queryString) && $queryString !== '') {
            foreach (explode('&', $queryString) as $pair) {
                $pieces = explode('=', $pair, 2);

                if (count($pieces) !== 2) {
                    continue;
                }

                if (rawurldecode($pieces[0]) === 'data.id') {
                    $value = rawurldecode(str_replace('+', ' ', $pieces[1]));

                    return $value === '' ? null : $value;
                }
            }
        }

        // `$request->query('data_id')` cobre o caso em que só o PHP normalizado sobrou.
        foreach (['data_id', 'id'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $value = $request->input('data.id');

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Tópico do aviso: `type` no estilo Webhooks, `topic` no estilo IPN (legado).
     */
    private function topic(Request $request): ?string
    {
        foreach ([$request->query('type'), $request->input('type'), $request->query('topic'), $request->input('topic')] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function safePayload(Request $request): array
    {
        $body = $request->json()->all();

        return [
            'id' => $body['id'] ?? null,
            'type' => $body['type'] ?? null,
            'action' => $body['action'] ?? null,
            'api_version' => $body['api_version'] ?? null,
            'live_mode' => $body['live_mode'] ?? null,
            'date_created' => $body['date_created'] ?? null,
            'user_id' => $body['user_id'] ?? null,
            'data' => ['id' => $body['data']['id'] ?? null],
        ];
    }
}
