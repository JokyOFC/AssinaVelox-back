<?php

namespace App\Integrations\Sso\Saml;

use App\Services\Sso\SsoFailure;
use Illuminate\Support\Carbon;

/**
 * Certificados PÚBLICOS de assinatura do IdP: normaliza para PEM, recusa o que não é
 * certificado X.509 com chave RSA/EC e descreve para a tela (impressão digital SHA-256, titular,
 * validade). Nunca aceita chave privada colada por engano.
 */
final class SamlCertificates
{
    public const MAX_CERTIFICATES = 3;

    /**
     * @return list<string> PEMs normalizados
     *
     * @throws SsoFailure
     */
    public static function normalize(string $input): array
    {
        if (str_contains($input, 'PRIVATE KEY')) {
            throw new SsoFailure('saml_certificate_private_key', 'Cole apenas o certificado PÚBLICO do provedor, nunca uma chave privada.');
        }

        $input = trim($input);

        if ($input === '') {
            return [];
        }

        $blocks = [];

        if (preg_match_all('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $input, $matches) > 0) {
            $blocks = $matches[1];
        } else {
            $blocks = [$input];
        }

        $pems = [];

        foreach ($blocks as $block) {
            $body = preg_replace('/\s+/', '', $block) ?? '';

            if ($body === '' || preg_match('/^[A-Za-z0-9+\/=]+$/', $body) !== 1) {
                throw new SsoFailure('saml_certificate_invalid', 'Certificado inválido. Cole o certificado X.509 do provedor (PEM ou base64).');
            }

            $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($body, 64, "\n")."-----END CERTIFICATE-----\n";
            self::describe($pem);
            $pems[] = $pem;
        }

        $pems = array_values(array_unique($pems));

        if (count($pems) > self::MAX_CERTIFICATES) {
            throw new SsoFailure('saml_certificate_too_many', 'Informe no máximo '.self::MAX_CERTIFICATES.' certificados (o atual e os da rotação).');
        }

        return $pems;
    }

    /**
     * @return array{fingerprint_sha256: string, subject: string, expires_at: string|null, expired: bool}
     *
     * @throws SsoFailure
     */
    public static function describe(string $pem): array
    {
        $certificate = @openssl_x509_read($pem);

        if ($certificate === false) {
            throw new SsoFailure('saml_certificate_invalid', 'Certificado inválido. Cole o certificado X.509 do provedor (PEM ou base64).');
        }

        $key = @openssl_pkey_get_public($certificate);
        $details = $key !== false ? openssl_pkey_get_details($key) : false;

        if (! is_array($details) || ! in_array($details['type'], [OPENSSL_KEYTYPE_RSA, OPENSSL_KEYTYPE_EC], true)) {
            throw new SsoFailure('saml_certificate_invalid', 'O certificado precisa ter chave RSA ou EC.');
        }

        if ($details['type'] === OPENSSL_KEYTYPE_RSA && (int) ($details['bits'] ?? 0) < 2048) {
            throw new SsoFailure('saml_certificate_weak', 'Chave RSA do certificado abaixo de 2048 bits.');
        }

        $parsed = openssl_x509_parse($certificate) ?: [];
        $subject = $parsed['subject'] ?? [];
        $cn = is_array($subject) ? ($subject['CN'] ?? null) : null;
        $validTo = isset($parsed['validTo_time_t']) ? Carbon::createFromTimestamp((int) $parsed['validTo_time_t']) : null;
        $fingerprint = (string) openssl_x509_fingerprint($certificate, 'sha256');

        return [
            'fingerprint_sha256' => strtoupper(implode(':', str_split($fingerprint, 2))),
            'subject' => is_string($cn) ? mb_substr($cn, 0, 120) : (is_array($cn) ? mb_substr((string) reset($cn), 0, 120) : ''),
            'expires_at' => $validTo?->toIso8601String(),
            'expired' => $validTo !== null && $validTo->isPast(),
        ];
    }
}
