<?php

namespace App\Services\Risk;

/**
 * Minimização da evidência de um sinal (roadmap §3.7; LGPD art. 6º, III).
 *
 * Regras, nesta ordem:
 * 1. só entram chaves da lista da regra ({@see RiskRule::evidenceKeys()}) ou comuns;
 * 2. chave com nome de dado proibido (CPF, e-mail, telefone, documento, conteúdo, token,
 *    senha, PIN, código, segredo, nome) é descartada mesmo que a regra a liste;
 * 3. só escalares: int, float, bool ou texto de até 64 caracteres (arrays e objetos saem);
 * 4. texto que PARECE e-mail, CPF/CNPJ ou sequência longa de dígitos é descartado;
 * 5. `ip_prefix` é sempre regravado truncado (/24 ou /48), mesmo que chegue completo.
 *
 * Quantas chaves foram descartadas fica em `_dropped` — o número, nunca o nome ou o valor.
 */
final class RiskEvidence
{
    /** @var list<string> */
    public const COMMON_KEYS = ['window_minutes', 'window_days', 'threshold', 'source'];

    private const FORBIDDEN_KEY = '/cpf|cnpj|e-?mail|phone|telefone|celular|document|conte[uú]do|content|token|password|senha|\bpin\b|otp|code|c[oó]digo|secret|segredo|\bname\b|nome|address|endere[cç]o|body|payload/iu';

    private const MAX_STRING = 64;

    /**
     * @param  array<array-key, mixed>  $evidence
     * @return array<string, int|float|bool|string>
     */
    public static function minimize(RiskRule $rule, array $evidence): array
    {
        $allowed = array_flip([...self::COMMON_KEYS, ...$rule->evidenceKeys()]);
        $clean = [];
        $dropped = 0;

        foreach ($evidence as $key => $value) {
            if (! is_string($key) || ! isset($allowed[$key]) || preg_match(self::FORBIDDEN_KEY, $key) === 1) {
                $dropped++;

                continue;
            }

            if ($key === 'ip_prefix') {
                $value = is_string($value) ? self::ipPrefix($value) : null;
            }

            $safe = self::scalar($value);

            if ($safe === null) {
                $dropped++;

                continue;
            }

            $clean[$key] = $safe;
        }

        if ($dropped > 0) {
            $clean['_dropped'] = $dropped;
        }

        ksort($clean);

        return $clean;
    }

    /**
     * Pares rótulo/valor para as telas (o painel mostra a evidência como gravada).
     *
     * @param  array<string, mixed>|null  $evidence
     * @return list<array{key: string, label: string, value: string}>
     */
    public static function present(?array $evidence): array
    {
        $rows = [];

        foreach ($evidence ?? [] as $key => $value) {
            $rows[] = [
                'key' => (string) $key,
                'label' => self::label((string) $key),
                'value' => match (true) {
                    is_bool($value) => $value ? 'sim' : 'não',
                    is_float($value) => rtrim(rtrim(number_format($value, 3, ',', ''), '0'), ','),
                    default => (string) (is_scalar($value) ? $value : ''),
                },
            ];
        }

        return $rows;
    }

    private static function label(string $key): string
    {
        return match ($key) {
            'window_minutes' => 'Janela (min)',
            'window_days' => 'Janela (dias)',
            'threshold' => 'Limiar',
            'source' => 'Origem',
            'sent_in_window' => 'Envios na janela',
            'organization_age_days' => 'Idade da conta (dias)',
            'attempts_in_window' => 'Entregas na janela',
            'failed_in_window' => 'Falhas/devoluções',
            'failure_rate' => 'Taxa de falha',
            'scope' => 'Escopo',
            'failures_in_window' => 'Tentativas erradas',
            'recipient' => 'Participante (id)',
            'ip_prefix' => 'Rede (IP truncado)',
            'distinct_external_recipients' => 'Destinatários externos distintos',
            'envelopes_in_window' => 'Envelopes na janela',
            'chargebacks_in_window' => 'Contestações na janela',
            'payment' => 'Pagamento (id)',
            'signups_in_window' => 'Cadastros na janela',
            'affiliate' => 'Afiliado (id)',
            'referral' => 'Indicação (id)',
            'match' => 'Coincidência',
            'same_user' => 'Mesmo usuário',
            'same_ip' => 'Mesma rede',
            'same_device' => 'Mesmo dispositivo',
            'same_payment_method' => 'Mesmo meio de pagamento',
            'same_email_domain' => 'Mesmo domínio de e-mail',
            '_dropped' => 'Campos descartados na minimização',
            default => $key,
        };
    }

    private static function scalar(mixed $value): int|float|bool|string|null
    {
        if (is_int($value) || is_bool($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? round($value, 4) : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_STRING) {
            return null;
        }

        // ULID (id público opaco dos models): pode conter dígitos seguidos por acaso e não é
        // dado pessoal — passa direto.
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) === 1) {
            return $value;
        }

        // Parece e-mail, CPF/CNPJ (com ou sem máscara), telefone com máscara ou sequência
        // longa de dígitos (telefone, documento, cartão): fora.
        if (str_contains($value, '@')
            || preg_match('/\d{3}\.?\d{3}\.?\d{3}-?\d{2}/', $value) === 1
            || preg_match('/\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}/', $value) === 1
            || preg_match('/\(?\d{2}\)?\s?\d{4,5}[-\s]?\d{4}/', $value) === 1
            || preg_match('/\d{8,}/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private static function ipPrefix(string $value): ?string
    {
        $bare = explode('/', trim($value))[0];

        return SubjectKeys::truncateIp($bare);
    }
}
