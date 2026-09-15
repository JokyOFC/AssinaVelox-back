<?php

namespace App\Services\Sso;

/**
 * Identidade que o adaptador do protocolo já VALIDOU (assinatura, emissor, audiência, tempo,
 * nonce/InResponseTo, replay). Ainda falta a regra de negócio: domínio verificado, vínculo e
 * provisionamento (SsoLoginCompleter). Nunca carrega token, assertion ou claim bruta.
 */
final class VerifiedIdentity
{
    public function __construct(
        public readonly string $subject,
        public readonly string $email,
        public readonly ?string $name,
        public readonly bool $emailVerified,
    ) {}

    public function emailDomain(): string
    {
        $at = strrpos($this->email, '@');

        return $at === false ? '' : strtolower(substr($this->email, $at + 1));
    }
}
