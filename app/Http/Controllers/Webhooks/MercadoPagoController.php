<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook do Mercado Pago (ROUTES §1.2 webhooks.mercadopago). Sem CSRF; sem sessão.
 * // TODO(Wave B - cobrança): validar assinatura (x-signature/x-request-id + MERCADOPAGO_WEBHOOK_SECRET),
 * gravar PaymentWebhookReceipt idempotente por fingerprint e despachar SyncMercadoPagoPayment.
 */
class MercadoPagoController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        Log::info('mercadopago.webhook.received', [
            'type' => $request->input('type'),
            'action' => $request->input('action'),
            'data_id' => $request->input('data.id'),
        ]);

        // Responde 200 rapidamente (o provedor reenvia em caso de falha); processamento é assíncrono.
        return response()->json(['received' => true]);
    }
}
