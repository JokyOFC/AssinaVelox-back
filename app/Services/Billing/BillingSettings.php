<?php

namespace App\Services\Billing;

use App\Enums\PaymentEnvironment;
use Illuminate\Contracts\Config\Repository;

/**
 * Leitura tipada de `config('assinavelox.mercadopago')` e `config('assinavelox.billing')`.
 *
 * Existe para que nenhum serviço precise repetir `(int) config(...)` nem adivinhar o
 * default de uma chave. Os segredos (access token, chave do webhook) são devolvidos por
 * métodos próprios e **nunca** entram em log, exceção, payload de fila ou argumento de
 * processo — quem precisar apenas saber se estão presentes usa `isConfigured()` /
 * `hasWebhookSecret()`.
 */
final readonly class BillingSettings
{
    public function __construct(private Repository $config) {}

    // -- Ambiente e credenciais -----------------------------------------------------

    /**
     * @return 'auto'|'fake'|'mercadopago'
     */
    public function driver(): string
    {
        $driver = (string) $this->config->get('assinavelox.mercadopago.driver', 'auto');

        return in_array($driver, ['auto', 'fake', 'mercadopago'], true) ? $driver : 'auto';
    }

    public function environment(): PaymentEnvironment
    {
        return PaymentEnvironment::tryFrom((string) $this->config->get('assinavelox.mercadopago.environment', 'sandbox'))
            ?? PaymentEnvironment::Sandbox;
    }

    /**
     * Somente para o adaptador HTTP. Nunca logar, nunca serializar.
     */
    public function accessToken(): ?string
    {
        $token = $this->config->get('assinavelox.mercadopago.access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function isConfigured(): bool
    {
        return $this->accessToken() !== null;
    }

    /**
     * Somente para a validação da assinatura do webhook. Nunca logar.
     */
    public function webhookSecret(): ?string
    {
        $secret = $this->config->get('assinavelox.mercadopago.webhook_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function hasWebhookSecret(): bool
    {
        return $this->webhookSecret() !== null;
    }

    public function webhookToleranceSeconds(): int
    {
        return max(0, (int) $this->config->get('assinavelox.mercadopago.webhook_tolerance_seconds', 300));
    }

    // -- HTTP -----------------------------------------------------------------------

    public function timeoutSeconds(): int
    {
        return max(1, (int) $this->config->get('assinavelox.mercadopago.timeout_seconds', 20));
    }

    public function connectTimeoutSeconds(): int
    {
        return max(1, (int) $this->config->get('assinavelox.mercadopago.connect_timeout_seconds', 10));
    }

    public function retries(): int
    {
        return max(0, (int) $this->config->get('assinavelox.mercadopago.retries', 2));
    }

    public function retryDelayMs(): int
    {
        return max(0, (int) $this->config->get('assinavelox.mercadopago.retry_delay_ms', 500));
    }

    // -- Preferência ----------------------------------------------------------------

    /**
     * No máximo 13 caracteres (limite documentado do `statement_descriptor`).
     */
    public function statementDescriptor(): ?string
    {
        $value = trim((string) $this->config->get('assinavelox.mercadopago.statement_descriptor', ''));

        return $value === '' ? null : mb_substr($value, 0, 13);
    }

    public function binaryMode(): bool
    {
        return (bool) $this->config->get('assinavelox.mercadopago.binary_mode', false);
    }

    public function preferenceTtlHours(): int
    {
        return max(1, (int) $this->config->get('assinavelox.mercadopago.preference_ttl_hours', 48));
    }

    public function installments(): ?int
    {
        $value = (int) $this->config->get('assinavelox.mercadopago.installments', 1);

        return $value >= 1 ? min($value, 36) : null;
    }

    /**
     * @return list<string>
     */
    public function excludedPaymentTypes(): array
    {
        $raw = (string) $this->config->get('assinavelox.mercadopago.excluded_payment_types', '');

        $types = array_values(array_filter(array_map(
            static fn (string $type): string => trim($type),
            explode(',', $raw),
        ), static fn (string $type): bool => $type !== ''));

        // `account_money` não pode ser excluído (regra do provedor); ignorar em silêncio
        // seria mentir para quem configurou, então removemos e o documento explica.
        return array_values(array_filter($types, static fn (string $type): bool => $type !== 'account_money'));
    }

    public function notificationUrl(): ?string
    {
        $url = $this->config->get('assinavelox.mercadopago.notification_url');

        if (is_string($url) && $url !== '') {
            // Limite documentado do campo `notification_url`.
            return mb_substr($url, 0, 248);
        }

        return null;
    }

    // -- Ciclo e inadimplência ------------------------------------------------------

    public function graceDays(): int
    {
        return max(0, (int) $this->config->get('assinavelox.billing.grace_days', 3));
    }

    public function expiredDays(): int
    {
        return max(1, (int) $this->config->get('assinavelox.billing.expired_days', 15));
    }

    /**
     * @return array{name: string, legal_name: string|null, tax_id: string|null}
     */
    public function operator(): array
    {
        $legalName = $this->config->get('assinavelox.billing.operator.legal_name');
        $taxId = $this->config->get('assinavelox.billing.operator.tax_id');

        return [
            'name' => (string) $this->config->get('assinavelox.billing.operator.name', 'AssinaVelox'),
            'legal_name' => is_string($legalName) && $legalName !== '' ? $legalName : null,
            'tax_id' => is_string($taxId) && $taxId !== '' ? $taxId : null,
        ];
    }

    public function queue(): string
    {
        return (string) $this->config->get('assinavelox.queues.billing', 'billing');
    }

    // -- Fase 2, onda D (flag `extended_payments`) ------------------------------------

    /**
     * Interruptor da plataforma (roadmap §1 T8). Desligado, nenhum dos métodos abaixo muda
     * comportamento algum.
     */
    public function extendedPayments(): bool
    {
        return $this->config->get('assinavelox.features.extended_payments', false) === true;
    }

    /**
     * Famílias habilitadas por configuração, na ordem canônica.
     *
     * @return list<'pix'|'boleto'|'card'>
     */
    public function enabledMethodFamilies(): array
    {
        $raw = (string) $this->config->get('assinavelox.mercadopago.enabled_methods', 'pix,boleto,card');
        $wanted = array_map(static fn (string $value): string => strtolower(trim($value)), explode(',', $raw));

        return array_values(array_filter(['pix', 'boleto', 'card'], static fn (string $family): bool => in_array($family, $wanted, true)));
    }

    /**
     * `date_of_expiration` dos meios offline e Pix, em horas, limitado a 1 h .. 30 dias.
     */
    public function offlineExpirationHours(): int
    {
        return min(720, max(1, (int) $this->config->get('assinavelox.mercadopago.offline_expiration_hours', 72)));
    }

    /**
     * `user_id` do vendedor para o cabeçalho X-Caller-Id (obrigatoriedade NÃO CONFIRMADA).
     */
    public function sellerUserId(): ?string
    {
        $value = $this->config->get('assinavelox.mercadopago.seller_user_id');

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function reconciliationWindowDays(): int
    {
        return min(30, max(1, (int) $this->config->get('assinavelox.mercadopago.reconciliation.window_days', 2)));
    }

    public function reconciliationPageSize(): int
    {
        return min(100, max(1, (int) $this->config->get('assinavelox.mercadopago.reconciliation.page_size', 30)));
    }

    public function reconciliationMaxPages(): int
    {
        return min(200, max(1, (int) $this->config->get('assinavelox.mercadopago.reconciliation.max_pages', 20)));
    }

    /**
     * Quem pode pedir estorno: `platform_admin` sempre; `owner` só se listado.
     *
     * @return list<string>
     */
    public function refundInitiators(): array
    {
        $raw = (string) $this->config->get('assinavelox.billing.refunds.initiators', 'platform_admin');
        $values = array_map(static fn (string $value): string => strtolower(trim($value)), explode(',', $raw));

        return array_values(array_unique(['platform_admin', ...array_filter($values, static fn (string $value): bool => $value === 'owner')]));
    }

    public function ownerCanRequestRefund(): bool
    {
        return in_array('owner', $this->refundInitiators(), true);
    }

    public function ownerRefundWindowDays(): int
    {
        return max(0, (int) $this->config->get('assinavelox.billing.refunds.owner_window_days', 7));
    }

    public function refundMaxAgeDays(): int
    {
        return min(180, max(1, (int) $this->config->get('assinavelox.billing.refunds.max_age_days', 180)));
    }

    public function alertEmail(): ?string
    {
        $value = $this->config->get('assinavelox.billing.alert_email');

        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    /**
     * @return 'disabled'|'simulated'
     */
    public function preapprovalDriver(): string
    {
        return $this->config->get('assinavelox.mercadopago.preapproval.driver', 'disabled') === 'simulated' ? 'simulated' : 'disabled';
    }
}
