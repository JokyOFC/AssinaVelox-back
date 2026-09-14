<?php

namespace App\Services\Affiliates;

/**
 * Parâmetros do programa (`config('assinavelox.affiliates')`). Os valores padrão são
 * PROVISÓRIOS até a decisão do proprietário (docs/fase-3/afiliados.md §8).
 */
final class AffiliateSettings
{
    public const MODEL_FIRST_TOUCH = 'first_touch';

    public const MODEL_LAST_TOUCH = 'last_touch';

    public function cookieName(): string
    {
        return (string) config('assinavelox.affiliates.cookie_name', 'av_affiliate_ref');
    }

    /** Janela de atribuição: do clique no link até o cadastro. */
    public function attributionWindowDays(): int
    {
        return max(1, (int) config('assinavelox.affiliates.attribution_window_days', 60));
    }

    public function attributionModel(): string
    {
        $model = (string) config('assinavelox.affiliates.attribution_model', self::MODEL_FIRST_TOUCH);

        return $model === self::MODEL_LAST_TOUCH ? self::MODEL_LAST_TOUCH : self::MODEL_FIRST_TOUCH;
    }

    /** Meses, a partir da atribuição, em que os pagamentos geram comissão (null = sem prazo). */
    public function commissionMonths(): ?int
    {
        $value = config('assinavelox.affiliates.commission_months');

        return $value === null || $value === '' || (int) $value <= 0 ? null : (int) $value;
    }

    /** Prazo de estorno: dias em que a comissão fica pendente depois do pagamento aprovado. */
    public function approvalHoldDays(): int
    {
        return max(0, (int) config('assinavelox.affiliates.approval_hold_days', 30));
    }

    public function defaultRateBp(): int
    {
        return $this->clampRate((int) config('assinavelox.affiliates.default_rate_bp', 1000));
    }

    public function maxRateBp(): int
    {
        return max(0, min(10_000, (int) config('assinavelox.affiliates.max_rate_bp', 5000)));
    }

    public function clampRate(int $bp): int
    {
        return max(0, min($this->maxRateBp(), $bp));
    }

    /** Pagamentos de sandbox geram comissão? (produção: não.) */
    public function includeSandboxPayments(): bool
    {
        return (bool) config('assinavelox.affiliates.include_sandbox_payments', false);
    }

    public function minPayoutCents(): int
    {
        return max(1, (int) config('assinavelox.affiliates.min_payout_cents', 1));
    }

    public function termsVersion(): string
    {
        return (string) config('assinavelox.affiliates.terms_version', 'afiliados-rascunho-2026-09');
    }

    /**
     * Domínios de e-mail públicos: para eles a regra "mesmo domínio corporativo" não vale.
     *
     * @return list<string>
     */
    public function publicEmailDomains(): array
    {
        $domains = config('assinavelox.affiliates.public_email_domains', []);

        return array_values(array_map(fn ($d): string => strtolower(trim((string) $d)), is_array($domains) ? $domains : []));
    }

    /** @return list<string> */
    public function currencies(): array
    {
        $currencies = config('assinavelox.affiliates.currencies', ['BRL']);

        return array_values(array_map(fn ($c): string => strtoupper((string) $c), is_array($currencies) ? $currencies : ['BRL']));
    }
}
