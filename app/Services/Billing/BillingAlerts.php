<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Alertas da cobrança para a equipe da plataforma (Fase 2, onda D): contestação recebida,
 * divergência de conciliação, falha da conciliação.
 *
 * Sempre vai para o log com a chave `alert=` (o mesmo padrão de `billing_amount_mismatch` e
 * `billing_webhook_receipt_stuck`, que a operação já monitora). Com
 * `ASSINAVELOX_BILLING_ALERT_EMAIL` configurado, também vai por e-mail. O contexto é mínimo:
 * ULIDs, valores em centavos e moeda — nunca e-mail do pagador, token ou dado de cartão.
 */
final class BillingAlerts
{
    public function __construct(private readonly BillingSettings $settings) {}

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function raise(string $alert, string $summary, array $context = []): void
    {
        Log::error('billing.alert', ['alert' => $alert, 'summary' => $summary, ...$context]);

        $email = $this->settings->alertEmail();

        if ($email === null) {
            return;
        }

        $lines = [$summary, ''];

        foreach ($context as $key => $value) {
            $lines[] = $key.': '.(is_bool($value) ? ($value ? 'sim' : 'não') : (string) $value);
        }

        $lines[] = '';
        $lines[] = 'Detalhes no painel interno › Planos e faturamento.';

        try {
            Mail::raw(implode("\n", $lines), function ($message) use ($email, $alert): void {
                $message->to($email)->subject('[AssinaVelox] Alerta de cobrança: '.$alert);
            });
        } catch (Throwable $exception) {
            // O alerta já está no log; a falha do e-mail não pode derrubar o processamento.
            Log::warning('billing.alert.mail_failed', ['alert' => $alert, 'exception' => $exception::class]);
        }
    }
}
