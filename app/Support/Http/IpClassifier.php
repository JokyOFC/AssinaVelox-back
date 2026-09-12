<?php

namespace App\Support\Http;

/**
 * Classificação de endereços IP para a proteção contra SSRF (docs/fase-2/webhooks.md §6).
 *
 * Duas camadas, de propósito:
 *
 *  1. lista explícita de faixas (registros "special-purpose" da IANA para IPv4 e IPv6) — é a
 *     que dá o rótulo do bloqueio e não depende de detalhe de implementação do PHP;
 *  2. `FILTER_FLAG_GLOBAL_RANGE` (PHP ≥ 8.2) como rede de segurança: se o PHP disser que o
 *     endereço não é global, ele é bloqueado mesmo que a lista acima não o cubra. Se essa
 *     flag cobre cada faixa abaixo está NÃO CONFIRMADO no php.net (pacotes-fase-2-3 §6),
 *     daí a lista própria.
 *
 * Endereços IPv6 que EMBUTEM um IPv4 (mapeado `::ffff:a.b.c.d`, SIIT, NAT64 `64:ff9b::/96`,
 * 6to4 `2002::/16`) são sempre recusados: o destino real seria o IPv4, e o rótulo mostra a
 * faixa dele (ex.: `ipv4_embedded:loopback`).
 */
final class IpClassifier
{
    /** Resultado de {@see self::hostLiteral()} para um literal numérico malformado. */
    public const MALFORMED = 'malformed';

    /** @var array<string, string> CIDR => rótulo */
    public const BLOCKED_V4 = [
        '0.0.0.0/8' => 'this_network',
        '10.0.0.0/8' => 'private',
        '100.64.0.0/10' => 'cgnat',
        '127.0.0.0/8' => 'loopback',
        '169.254.0.0/16' => 'link_local',
        '172.16.0.0/12' => 'private',
        '192.0.0.0/24' => 'ietf_protocol',
        '192.0.2.0/24' => 'documentation',
        '192.31.196.0/24' => 'reserved',
        '192.52.193.0/24' => 'reserved',
        '192.88.99.0/24' => 'relay_6to4',
        '192.168.0.0/16' => 'private',
        '192.175.48.0/24' => 'reserved',
        '198.18.0.0/15' => 'benchmarking',
        '198.51.100.0/24' => 'documentation',
        '203.0.113.0/24' => 'documentation',
        '224.0.0.0/4' => 'multicast',
        '240.0.0.0/4' => 'reserved',
        '255.255.255.255/32' => 'broadcast',
    ];

    /** @var array<string, string> CIDR => rótulo (ordem importa: os mais específicos antes) */
    public const BLOCKED_V6 = [
        '::/128' => 'unspecified',
        '::1/128' => 'loopback',
        '::/96' => 'ipv4_compatible',
        '100::/64' => 'discard',
        '2001:db8::/32' => 'documentation',
        '2001::/23' => 'ietf_protocol',
        '3fff::/20' => 'documentation',
        '5f00::/16' => 'reserved',
        '64:ff9b:1::/48' => 'nat64_local',
        'fc00::/7' => 'unique_local',
        'fe80::/10' => 'link_local',
        'fec0::/10' => 'site_local',
        'ff00::/8' => 'multicast',
    ];

    /**
     * Rótulo da faixa bloqueada, ou null quando o endereço é público.
     *
     * @param  list<string>  $allowedCidrs  exceções SÓ fora de produção (testes de integração
     *                                      com servidor local); o chamador garante isso.
     */
    public static function blockedReason(string $ip, array $allowedCidrs = []): ?string
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return 'invalid';
        }

        foreach ($allowedCidrs as $cidr) {
            if (self::inCidr($binary, $cidr)) {
                return null;
            }
        }

        if (strlen($binary) === 16) {
            $embedded = self::embeddedIpv4($binary);

            if ($embedded !== null) {
                return 'ipv4_embedded:'.(self::blockedReason($embedded) ?? 'public');
            }

            foreach (self::BLOCKED_V6 as $cidr => $label) {
                if (self::inCidr($binary, $cidr)) {
                    return $label;
                }
            }
        } else {
            foreach (self::BLOCKED_V4 as $cidr => $label) {
                if (self::inCidr($binary, $cidr)) {
                    return $label;
                }
            }
        }

        $canonical = (string) inet_ntop($binary);

        if (filter_var($canonical, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return 'non_global';
        }

        return null;
    }

    /**
     * O host da URL é um IP literal, em QUALQUER grafia que o cURL/`inet_aton` aceitaria?
     *
     * Devolve o IP canônico, `null` quando o host não é literal (é um nome) ou
     * {@see self::MALFORMED} para um literal numérico impossível (ex.: `256.1.1.1`, `0x1FFFFFFFF`).
     * Cobre decimal inteiro (`2130706433`), octal (`0177.0.0.1`), hexadecimal (`0x7f.1`),
     * formas curtas (`127.1`) e IPv6 entre colchetes (com ou sem zona `%eth0`).
     */
    public static function hostLiteral(string $host): ?string
    {
        $host = strtolower(rtrim(trim($host), '.'));

        if ($host === '') {
            return null;
        }

        if (str_starts_with($host, '[') || str_ends_with($host, ']')) {
            $inner = explode('%', trim($host, '[]'), 2)[0];
            $binary = @inet_pton($inner);

            return $binary === false ? self::MALFORMED : (string) inet_ntop($binary);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return (string) inet_ntop((string) inet_pton($host));
        }

        if (str_contains($host, ':')) {
            return self::MALFORMED;
        }

        $parts = explode('.', $host);

        if (count($parts) > 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            if (preg_match('/^0x([0-9a-f]*)$/', $part, $match) === 1) {
                if (strlen($match[1]) > 8) {
                    return self::MALFORMED;
                }
                $values[] = $match[1] === '' ? 0 : (int) hexdec($match[1]);
            } elseif (preg_match('/^0[0-7]+$/', $part) === 1) {
                if (strlen($part) > 12) {
                    return self::MALFORMED;
                }
                $values[] = (int) octdec($part);
            } elseif (preg_match('/^(0|[1-9][0-9]*)$/', $part) === 1) {
                if (strlen($part) > 10) {
                    return self::MALFORMED;
                }
                $values[] = (int) $part;
            } else {
                // Não é numérico: é um nome (a validação de hostname decide o resto).
                return null;
            }
        }

        $count = count($values);
        $last = array_pop($values);

        foreach ($values as $value) {
            if ($value > 255) {
                return self::MALFORMED;
            }
        }

        $maxLast = (1 << (8 * (5 - $count))) - 1;

        if ($last > $maxLast) {
            return self::MALFORMED;
        }

        $integer = 0;

        foreach ($values as $index => $value) {
            $integer |= $value << (8 * (3 - $index));
        }

        $integer |= $last;

        return (string) long2ip($integer);
    }

    /**
     * IPv4 embutido num IPv6 (mapeado, SIIT, NAT64 bem conhecido, 6to4); null se não houver.
     */
    private static function embeddedIpv4(string $binary): ?string
    {
        $zeros = static fn (int $length): string => str_repeat("\x00", $length);

        // ::ffff:a.b.c.d (mapeado)
        if (substr($binary, 0, 12) === $zeros(10)."\xff\xff") {
            return (string) inet_ntop(substr($binary, 12, 4));
        }

        // ::ffff:0:a.b.c.d (SIIT)
        if (substr($binary, 0, 12) === $zeros(8)."\xff\xff".$zeros(2)) {
            return (string) inet_ntop(substr($binary, 12, 4));
        }

        // 64:ff9b::a.b.c.d (NAT64 bem conhecido)
        if (substr($binary, 0, 12) === "\x00\x64\xff\x9b".$zeros(8)) {
            return (string) inet_ntop(substr($binary, 12, 4));
        }

        // 2002:AABB:CCDD::/48 (6to4)
        if (substr($binary, 0, 2) === "\x20\x02") {
            return (string) inet_ntop(substr($binary, 2, 4));
        }

        return null;
    }

    private static function inCidr(string $binary, string $cidr): bool
    {
        [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $networkBinary = @inet_pton((string) $network);

        if ($networkBinary === false || strlen($networkBinary) !== strlen($binary)) {
            return false;
        }

        $bits = $bits === null ? strlen($binary) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);

        if (substr($binary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($binary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask);
    }
}
