<?php

namespace App\Services\Billing;

use App\Enums\PaymentEnvironment;
use App\Models\PaymentMethodCheck;

/**
 * Quais famílias de meio (Pix, boleto, cartão) o Checkout Pro oferece — Fase 2, onda D.
 *
 * Uma família é oferecida quando as DUAS fontes dizem sim:
 *
 * 1. **configuração** (`MERCADOPAGO_ENABLED_METHODS`), a escolha da operadora;
 * 2. **conta vendedora**: a última consulta registrada a GET /v1/payment_methods tem um meio da
 *    família com `status = active`. Sem consulta registrada, a disponibilidade é "não consultada"
 *    e vale só a configuração — a tela diz isso, em vez de supor.
 *
 * O mapeamento família → `payment_type_id` é o documentado (docs/integracoes/mercado-pago.md §3):
 * Pix = `bank_transfer`, boleto = `ticket`, cartão = `credit_card`/`debit_card`/`prepaid_card`.
 * A exclusão vai na preferência como `payment_methods.excluded_payment_types`. `account_money`
 * nunca é excluído (regra do provedor).
 */
final class PaymentMethodPolicy
{
    /** @var array<'pix'|'boleto'|'card', array{label: string, types: list<string>}> */
    public const FAMILIES = [
        'pix' => ['label' => 'Pix', 'types' => ['bank_transfer']],
        'boleto' => ['label' => 'Boleto', 'types' => ['ticket']],
        'card' => ['label' => 'Cartão de crédito ou débito', 'types' => ['credit_card', 'debit_card', 'prepaid_card']],
    ];

    public function __construct(private readonly BillingSettings $settings) {}

    public function latestCheck(string $provider, PaymentEnvironment $environment): ?PaymentMethodCheck
    {
        return PaymentMethodCheck::query()
            ->where('provider', $provider)
            ->where('environment', $environment->value)
            ->where('status', PaymentMethodCheck::STATUS_OK)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return list<array{key: string, label: string, configured: bool, available: bool|null, offered: bool}>
     */
    public function families(string $provider, PaymentEnvironment $environment): array
    {
        $configured = $this->settings->enabledMethodFamilies();
        $check = $this->latestCheck($provider, $environment);
        $rows = [];

        foreach (self::FAMILIES as $key => $family) {
            $available = $check === null ? null : $this->familyActive($check, $family['types']);
            $isConfigured = in_array($key, $configured, true);

            $rows[] = [
                'key' => $key,
                'label' => $family['label'],
                'configured' => $isConfigured,
                'available' => $available,
                'offered' => $isConfigured && $available !== false,
            ];
        }

        return $rows;
    }

    /**
     * `excluded_payment_types` da preferência: os tipos das famílias não oferecidas somados à
     * lista manual já existente (`MERCADOPAGO_EXCLUDED_PAYMENT_TYPES`).
     *
     * @return list<string>
     */
    public function excludedPaymentTypes(string $provider, PaymentEnvironment $environment): array
    {
        $excluded = $this->settings->excludedPaymentTypes();

        foreach ($this->families($provider, $environment) as $family) {
            if (! $family['offered']) {
                array_push($excluded, ...self::FAMILIES[$family['key']]['types']);
            }
        }

        return array_values(array_unique(array_filter($excluded, static fn (string $type): bool => $type !== 'account_money')));
    }

    /**
     * @param  list<string>  $types
     */
    private function familyActive(PaymentMethodCheck $check, array $types): bool
    {
        foreach ($check->methods ?? [] as $method) {
            if (in_array($method['payment_type_id'] ?? null, $types, true) && ($method['status'] ?? null) === 'active') {
                return true;
            }
        }

        return false;
    }
}
