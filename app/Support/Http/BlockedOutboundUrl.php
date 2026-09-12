<?php

namespace App\Support\Http;

use RuntimeException;

/**
 * URL de saída recusada pela proteção contra SSRF. `reason` é o código estável (gravado no
 * histórico da entrega); `detail` é diagnóstico interno (endereço e faixa) e NUNCA vai para a
 * mensagem mostrada ao usuário — ela é propositalmente igual para "não resolve" e "resolve
 * para endereço interno", para a tela de cadastro não virar oráculo do DNS interno.
 */
final class BlockedOutboundUrl extends RuntimeException
{
    public const INVALID_URL = 'invalid_url';

    public const SCHEME_NOT_ALLOWED = 'scheme_not_allowed';

    public const CREDENTIALS_IN_URL = 'credentials_in_url';

    public const PORT_NOT_ALLOWED = 'port_not_allowed';

    public const IP_LITERAL = 'ip_literal';

    public const HOST_NOT_ALLOWED = 'host_not_allowed';

    public const DNS_FAILED = 'dns_failed';

    public const BLOCKED_ADDRESS = 'blocked_address';

    public function __construct(
        public readonly string $reason,
        public readonly ?string $detail = null,
    ) {
        parent::__construct('URL de saída recusada: '.$reason.($detail !== null ? ' ('.$detail.')' : ''));
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::SCHEME_NOT_ALLOWED => 'Use um endereço https://.',
            self::CREDENTIALS_IN_URL => 'A URL não pode conter usuário ou senha.',
            self::PORT_NOT_ALLOWED => 'Porta não permitida. Use a porta padrão do https (443).',
            self::IP_LITERAL => 'Informe um nome de domínio; endereços IP não são aceitos.',
            self::HOST_NOT_ALLOWED => 'Informe um domínio público completo (ex.: hooks.suaempresa.com.br).',
            self::DNS_FAILED, self::BLOCKED_ADDRESS => 'O domínio não resolve para um endereço público da internet. Endereços internos, locais ou reservados são bloqueados.',
            default => 'URL inválida.',
        };
    }
}
