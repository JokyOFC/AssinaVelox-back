<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Integrations\Identity\Verifiky\VerifikyIdentityVerificationProvider;
use App\Integrations\Identity\Verifiky\VerifikyWebhook;
use App\Models\Organization;
use App\Services\Identity\IdentityVerifications;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Webhook da Verifiky (`POST /webhooks/verifiky`, rota `webhooks.verifiky`; Fase 4 §4.1,
 * docs/integracoes/verifiky.md). Sem CSRF (exceção em bootstrap/app.php, como o do Mercado
 * Pago), sem sessão, com `throttle:webhook`.
 *
 * 1. **Autenticidade**: `X-Verifiky-Signature` = HMAC-SHA256 do corpo CRU com
 *    `VERIFIKY_WEBHOOK_SECRET` ({@see VerifikyWebhook::isAuthentic()}). Assinatura ausente,
 *    inválida ou segredo não configurado → 401 e nada processado. O corpo cru nunca vai
 *    para o log.
 * 2. Só `verification.completed` interessa; outros eventos são reconhecidos e ignorados (200).
 * 3. A tentativa é achada pelo `user_reference` que nós mesmos enviamos (`reference`), com
 *    fallback pelo protocolo do provedor. Referência desconhecida → 200 `ignored_unknown_reference`:
 *    a mesma conta na Verifiky pode servir outros sistemas, e um 4xx só provocaria reenvios.
 * 4. {@see IdentityVerifications::applyResult()} grava a resposta — idempotente: uma tentativa
 *    já conclusiva não muda com um aviso atrasado ou repetido.
 */
class VerifikyWebhookController extends Controller
{
    public function __construct(
        private readonly VerifikyWebhook $webhook,
        private readonly IdentityVerifications $verifications,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $raw = (string) $request->getContent();
        $signature = $request->header(VerifikyWebhook::SIGNATURE_HEADER);

        if (! $this->webhook->isAuthentic($raw, is_string($signature) ? $signature : null)) {
            Log::warning('verifiky.webhook.rejected', [
                'reason' => $this->webhook->isConfigured() ? 'invalid_signature' : 'not_configured',
                'ip' => $request->ip(),
                'bytes' => strlen($raw),
            ]);

            // Corpo genérico de propósito: nada de expor o motivo da rejeição.
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->json(['status' => 'ignored']);
        }

        /** @var array<string, mixed> $payload */
        if (! $this->webhook->isVerificationCompleted($payload)) {
            return response()->json(['status' => 'ignored']);
        }

        $result = $this->webhook->result($payload);

        $verification = $result['reference'] !== null ? $this->verifications->findByReference($result['reference']) : null;

        if ($verification === null && $result['verification_id'] !== null) {
            $verification = $this->verifications->findByProviderId(VerifikyIdentityVerificationProvider::NAME, $result['verification_id']);
        }

        if ($verification === null) {
            Log::info('verifiky.webhook.unknown_reference', [
                'has_reference' => $result['reference'] !== null,
                'has_verification_id' => $result['verification_id'] !== null,
            ]);

            return response()->json(['status' => 'ignored_unknown_reference']);
        }

        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($verification->organization_id)->first();

        $updated = CurrentOrganization::instance()->runAs($organization, fn () => $this->verifications->applyResult($verification, [
            'verification_id' => $result['verification_id'],
            'provider' => VerifikyIdentityVerificationProvider::NAME,
            'status' => $result['status'],
            'checked_at' => Carbon::now()->toIso8601String(),
            'details' => $result['details'],
        ], 'webhook'));

        return response()->json(['status' => $updated->status->value]);
    }
}
