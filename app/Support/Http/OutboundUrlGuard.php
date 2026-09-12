<?php

namespace App\Support\Http;

/**
 * Proteção contra SSRF para chamadas de saída a URLs informadas por clientes (webhooks —
 * roadmap §2.16; docs/fase-2/webhooks.md §6). Código próprio: não há pacote compatível com
 * PHP 8.3 (pacotes-fase-2-3 §6).
 *
 * `inspect()` é chamado no CADASTRO e a CADA tentativa de entrega (o DNS muda). Ele:
 *
 *  1. recusa URL com espaço, caractere de controle ou barra invertida (diferenças entre o
 *     parser do PHP e o do cURL), sem esquema/host, ou com usuário/senha;
 *  2. aceita só `https` — `http` apenas fora de produção e com `webhooks.allow_http`;
 *  3. recusa IP literal em QUALQUER grafia (decimal, octal, hexadecimal, curta, IPv6, IPv4
 *     mapeado): exige nome de domínio completo; nomes sem ponto e sufixos internos
 *     (`localhost`, `.local`, `.internal`, `.localdomain`, `.home.arpa`) também saem;
 *  4. restringe a porta (443 e as de `webhooks.allowed_ports`);
 *  5. resolve A e AAAA e exige que TODOS os endereços sejam públicos (IpClassifier) — basta
 *     um interno para recusar;
 *  6. devolve a URL RECONSTRUÍDA (sem fragmento) e o endereço para pinar a conexão.
 *
 * O pino (CURLOPT_RESOLVE), a recusa de redirecionamento, a desativação de proxy de ambiente
 * e os tempos-limite ficam no transporte (App\Services\Webhooks\WebhookTransport).
 */
final class OutboundUrlGuard
{
    /** Sufixos que nunca apontam para a internet pública. */
    private const INTERNAL_SUFFIXES = ['localhost', 'local', 'internal', 'localdomain', 'home.arpa'];

    public function __construct(private readonly DnsResolver $resolver) {}

    /**
     * @throws BlockedOutboundUrl
     */
    public function inspect(string $url): OutboundTarget
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new BlockedOutboundUrl(BlockedOutboundUrl::INVALID_URL);
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new BlockedOutboundUrl(BlockedOutboundUrl::INVALID_URL);
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && ! ($scheme === 'http' && $this->allowsHttp())) {
            throw new BlockedOutboundUrl(BlockedOutboundUrl::SCHEME_NOT_ALLOWED, $scheme);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BlockedOutboundUrl(BlockedOutboundUrl::CREDENTIALS_IN_URL);
        }

        $literal = IpClassifier::hostLiteral($parts['host']);

        if ($literal === IpClassifier::MALFORMED) {
            throw new BlockedOutboundUrl(BlockedOutboundUrl::INVALID_URL, 'malformed_ip_literal');
        }

        if ($literal !== null) {
            $reason = IpClassifier::blockedReason($literal, $this->testingAllowedCidrs());

            throw $reason !== null
                ? new BlockedOutboundUrl(BlockedOutboundUrl::BLOCKED_ADDRESS, $literal.' '.$reason)
                : new BlockedOutboundUrl(BlockedOutboundUrl::IP_LITERAL, $literal);
        }

        $host = $this->normalizeHost($parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (! $this->portAllowed($scheme, $port)) {
            throw new BlockedOutboundUrl(BlockedOutboundUrl::PORT_NOT_ALLOWED, (string) $port);
        }

        $addresses = array_values(array_unique(array_filter($this->resolver->resolve($host), 'is_string')));

        if ($addresses === []) {
            throw new BlockedOutboundUrl(BlockedOutboundUrl::DNS_FAILED, $host);
        }

        $allowed = $this->testingAllowedCidrs();

        foreach ($addresses as $address) {
            $reason = IpClassifier::blockedReason($address, $allowed);

            if ($reason !== null) {
                throw new BlockedOutboundUrl(BlockedOutboundUrl::BLOCKED_ADDRESS, $host.' → '.$address.' '.$reason);
            }
        }

        $pinned = $addresses[0];

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $pinned = $address;
                break;
            }
        }

        $normalized = $scheme.'://'.$host
            .(isset($parts['port']) ? ':'.$port : '')
            .(isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');

        return new OutboundTarget($normalized, $scheme, $host, $port, $addresses, $pinned);
    }

    /**
     * `http://` só fora de produção E com a configuração ligada (padrão: local/testing).
     */
    public function allowsHttp(): bool
    {
        return ! app()->environment('production')
            && (bool) config('assinavelox.webhooks.allow_http', false);
    }

    private function portAllowed(string $scheme, int $port): bool
    {
        if ($port < 1 || $port > 65535) {
            return false;
        }

        if ($scheme === 'https' && $port === 443) {
            return true;
        }

        if ($scheme === 'http' && $port === 80) {
            return true;
        }

        $allowed = array_map('intval', (array) config('assinavelox.webhooks.allowed_ports', [443]));

        return in_array($port, $allowed, true);
    }

    /**
     * Exceções de faixa para o teste de integração com servidor local (pino de IP). Ignoradas
     * em produção, qualquer que seja a configuração.
     *
     * @return list<string>
     */
    private function testingAllowedCidrs(): array
    {
        if (app()->environment('production')) {
            return [];
        }

        return array_values(array_filter((array) config('assinavelox.webhooks.testing_allowed_cidrs', []), 'is_string'));
    }

    /**
     * @throws BlockedOutboundUrl
     */
    private function normalizeHost(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));

        if (preg_match('/[^\x21-\x7e]/', $host) === 1) {
            $ascii = function_exists('idn_to_ascii')
                ? idn_to_ascii($host, IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46)
                : false;

            if (! is_string($ascii) || $ascii === '') {
                throw new BlockedOutboundUrl(BlockedOutboundUrl::INVALID_URL, 'idn');
            }

            $host = strtolower($ascii);
        }

        if (! str_contains($host, '.')) {
            throw new BlockedOutboundUrl(BlockedOutboundUrl::HOST_NOT_ALLOWED, $host);
        }

        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                throw new BlockedOutboundUrl(BlockedOutboundUrl::HOST_NOT_ALLOWED, $host);
            }
        }

        // Rótulos LDH; o último (TLD) começa com letra — um TLD numérico seria um IP disfarçado.
        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1) {
            throw new BlockedOutboundUrl(BlockedOutboundUrl::INVALID_URL, 'hostname');
        }

        return $host;
    }
}
