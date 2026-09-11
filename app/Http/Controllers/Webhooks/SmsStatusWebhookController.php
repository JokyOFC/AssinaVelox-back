<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\DeliveryChannel;
use App\Http\Controllers\Controller;
use App\Services\Signing\Channels\StatusWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/sms/status (`webhooks.sms.status`) — avisos de status do provedor de SMS.
 * Sem CSRF (autenticado por HMAC + carimbo de tempo), com `throttle:webhook`. Hoje responde
 * 503 com o motivo, porque o serviço próprio de SMS está desabilitado. Ver {@see StatusWebhooks}.
 */
class SmsStatusWebhookController extends Controller
{
    public function __construct(private readonly StatusWebhooks $webhooks) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->webhooks->handle(DeliveryChannel::Sms, $request);
    }
}
