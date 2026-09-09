<?php

namespace App\Services\Billing;

/**
 * Tradução do `payment_method_id` do provedor para o par (tipo, rótulo) que a interface
 * e o recibo usam.
 *
 * Os identificadores vêm de `GET /v1/payment_methods` e de `GET /v1/payments/{id}`
 * (`payment_method_id` e `payment_type_id`) — os valores listados aqui são só os que a
 * documentação nomeia explicitamente (`pix`, `bolbradesco`, `account_money`, bandeiras
 * de cartão). Qualquer outro cai em "Outro" em vez de virar palpite.
 *
 * **Não existe cartão salvo** (RECONCILIACAO Q21): o Checkout Pro não devolve número,
 * validade nem código de segurança, e este arquivo não tem sequer um lugar para isso.
 * `last_four` é sempre `null` na interface.
 */
final class PaymentMethods
{
    /**
     * @return 'credit_card'|'debit_card'|'pix'|'boleto'|'account_money'|'other'
     */
    public static function type(?string $methodId): string
    {
        return match (true) {
            $methodId === null || $methodId === '' => 'other',
            $methodId === 'pix' => 'pix',
            in_array($methodId, ['bolbradesco', 'boleto', 'pec'], true) => 'boleto',
            $methodId === 'account_money' => 'account_money',
            in_array($methodId, ['debvisa', 'debmaster', 'debelo', 'debcabal'], true) => 'debit_card',
            in_array($methodId, ['visa', 'master', 'amex', 'elo', 'hipercard', 'diners', 'cabal', 'melicard'], true) => 'credit_card',
            default => 'other',
        };
    }

    public static function label(?string $methodId): string
    {
        return match (self::type($methodId)) {
            'pix' => 'Pix',
            'boleto' => 'Boleto',
            'account_money' => 'Saldo em conta Mercado Pago',
            'debit_card' => 'Cartão de débito'.self::brandSuffix($methodId),
            'credit_card' => 'Cartão de crédito'.self::brandSuffix($methodId),
            default => 'Outro meio de pagamento',
        };
    }

    private static function brandSuffix(?string $methodId): string
    {
        $brands = [
            'visa' => 'Visa',
            'debvisa' => 'Visa',
            'master' => 'Mastercard',
            'debmaster' => 'Mastercard',
            'amex' => 'American Express',
            'elo' => 'Elo',
            'debelo' => 'Elo',
            'hipercard' => 'Hipercard',
            'diners' => 'Diners',
            'cabal' => 'Cabal',
            'debcabal' => 'Cabal',
            'melicard' => 'Mercado Pago',
        ];

        return isset($brands[$methodId]) ? ' ('.$brands[$methodId].')' : '';
    }
}
