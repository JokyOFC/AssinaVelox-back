<?php

namespace App\Support;

/**
 * Traduz o que o operador escreve no `.env` para o que o transporte SMTP do Symfony aceita.
 *
 * O Laravel 13 só conhece dois esquemas, `smtp` e `smtps`. O `.env` que todo mundo traz de
 * instalações antigas diz `MAIL_ENCRYPTION=tls` (ou `MAIL_SCHEME=tls`), e esse valor, passado
 * adiante como está, derruba TODO envio com UnsupportedSchemeException — o provedor devolve
 * `failed` e nenhum convite nem código sai. Aqui o valor antigo vira o seu significado:
 *
 * | No `.env`                | Esquema | TLS obrigatório | Na prática                              |
 * |--------------------------|---------|-----------------|-----------------------------------------|
 * | `tls` / `starttls`       | `smtp`  | sim             | porta 587, STARTTLS exigido             |
 * | `ssl` / `smtps`          | `smtps` | —               | porta 465, TLS desde a conexão          |
 * | `smtp`                   | `smtp`  | não             | STARTTLS se o servidor oferecer         |
 * | vazio                    | (auto)  | não             | o Laravel decide pela porta (465→smtps) |
 *
 * "TLS obrigatório" é a opção `require_tls` do Symfony: sem ela, um servidor que não anuncie
 * STARTTLS recebe o e-mail em claro — e os e-mails daqui levam link de convite e código.
 * `MAIL_REQUIRE_TLS` decide explicitamente quando preenchido.
 */
final class SmtpTransportOptions
{
    /** Esquemas que o EsmtpTransportFactory do Symfony aceita. */
    public const SUPPORTED_SCHEMES = ['smtp', 'smtps'];

    /**
     * `env()` devolve `bool|string|null` (um `MAIL_SCHEME=false` literal vira booleano), por
     * isso os dois primeiros parâmetros são `mixed`: só texto é aproveitado.
     *
     * @param  mixed  $scheme  `MAIL_SCHEME`
     * @param  mixed  $legacyEncryption  `MAIL_ENCRYPTION` (nome antigo, ainda aceito)
     * @param  mixed  $requireTls  `MAIL_REQUIRE_TLS`; null ou vazio = decidir pelo esquema
     * @return array{scheme: string|null, require_tls: bool}
     */
    public static function resolve(mixed $scheme, mixed $legacyEncryption = null, mixed $requireTls = null): array
    {
        $declared = is_string($scheme) ? strtolower(trim($scheme)) : '';

        if ($declared === '') {
            $declared = is_string($legacyEncryption) ? strtolower(trim($legacyEncryption)) : '';
        }

        [$resolved, $implied] = match ($declared) {
            '' => [null, false],
            'tls', 'starttls' => ['smtp', true],
            'ssl', 'smtps' => ['smtps', false],
            'smtp' => ['smtp', false],
            // Valor desconhecido segue como está: o envio falha alto e o doctor aponta a causa.
            default => [$declared, false],
        };

        $explicit = $requireTls === null || $requireTls === ''
            ? null
            : filter_var($requireTls, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return ['scheme' => $resolved, 'require_tls' => $explicit ?? $implied];
    }

    /** O esquema resolvido é um dos que o transporte aceita (ou automático)? */
    public static function isSupported(?string $scheme): bool
    {
        return $scheme === null || in_array($scheme, self::SUPPORTED_SCHEMES, true);
    }
}
