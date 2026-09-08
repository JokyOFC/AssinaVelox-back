<?php

namespace App\Integrations\Dto;

/**
 * Preferência criada no gateway. `checkoutUrl` é o init_point para
 * redirecionar o pagador (nunca sandbox_init_point).
 */
final readonly class CheckoutPreference
{
    /**
     * @param  array<string, mixed>  $raw  resposta do gateway sem credenciais
     */
    public function __construct(
        public string $preferenceId,
        public string $checkoutUrl,
        public string $externalReference,
        public bool $liveMode,
        public array $raw = [],
    ) {}
}
