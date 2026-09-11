<?php

namespace App\Services\Dossier;

use App\Models\Organization;
use App\Models\Recipient;
use App\Support\IpDisplay;

/**
 * O que NUNCA sai no dossiê e como os dados pessoais saem.
 *
 * - Chaves de payload com cara de segredo (token, digest de código, senha, PIN, CPF, segredo
 *   de webhook…) são OMITIDAS — o payload da trilha já é minimizado (T7), isto é a segunda
 *   barreira;
 * - IP segue `evidence_show_ip` da organização ({@see IpDisplay}: masked | full | none);
 * - e-mail acompanha a mesma política (roadmap §2.13: "e-mails completos só quando a política
 *   não mascarar"): `full` mostra por extenso; `masked` e `none` mascaram como a página
 *
 *   pública (`m***@exemplo.com`).
 */
final class DossierRedaction
{
    public const OMITTED = '[omitido no dossiê]';

    /** Nomes exatos (sem diferenciar caixa). */
    private const SECRET_KEYS = [
        'password', 'password_confirmation', 'senha', 'current_password', 'passphrase', 'pfx', 'pfx_password',
        'private_key', 'secret', 'client_secret', 'webhook_secret', 'api_key', 'apikey', 'authorization',
        'bearer', 'cookie', 'token', 'access_token', 'refresh_token', 'token_digest', 'authorization_token',
        'authorization_token_digest', 'code', 'otp', 'code_hash', 'pin', 'pin_hash', 'two_factor_secret',
        'recovery_code', 'tax_id', 'cpf', 'cnpj', 'secret_ref', 'storage_path', 'path', 'image',
        'signature_image_path', 'image_path',
    ];

    /** Sufixos (após `_`) que também indicam segredo. */
    private const SECRET_SUFFIXES = ['_token', '_secret', '_password', '_passphrase', '_digest', '_otp', '_pin', '_code_hash', '_cpf'];

    public function __construct(private readonly string $ipMode) {}

    public static function for(Organization $organization): self
    {
        return new self(IpDisplay::mode($organization));
    }

    public function ipMode(): string
    {
        return $this->ipMode;
    }

    public function ip(?string $ip, Organization $organization): ?string
    {
        return IpDisplay::for($ip, $organization);
    }

    public function email(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return $this->ipMode === 'full' ? $email : Recipient::maskEmail($email);
    }

    public static function isSecretKey(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, self::SECRET_KEYS, true)) {
            return true;
        }

        foreach (self::SECRET_SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Payload de evento sem segredos; IPs aplicados pela política.
     *
     * @param  array<array-key, mixed>|null  $payload
     * @return array<array-key, mixed>|null
     */
    public function payload(?array $payload, Organization $organization): ?array
    {
        if ($payload === null) {
            return null;
        }

        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                $clean[$key] = self::OMITTED;

                continue;
            }

            if (is_string($key) && in_array(strtolower($key), ['ip', 'ip_address'], true)) {
                $clean[$key] = is_string($value) ? $this->ip($value, $organization) : null;

                continue;
            }

            if (is_string($key) && str_contains(strtolower($key), 'email') && is_string($value)) {
                $clean[$key] = $this->email($value);

                continue;
            }

            $clean[$key] = is_array($value) ? $this->payload($value, $organization) : $value;
        }

        return $clean;
    }
}
