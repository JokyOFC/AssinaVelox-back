<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Exibição do endereço IP registrado como evidência.
 *
 * `organizations.settings.evidence_show_ip` (arquitetura §3.1) vale `masked` (padrão),
 * `full` ou `none` e governa **todas** as telas do remetente — o detalhe do documento, a
 * trilha de auditoria, a página de evidências e a lista de Assinaturas (ROUTES §2.9). Antes desta classe cada resource decidia
 * por conta própria: a trilha mascarava dois octetos lendo a chave de `config/`, o card do
 * signatário entregava o IP inteiro sem olhar para nada, e a página de evidências fazia o
 * mesmo. Na mesma página o operador via `127.0.***.***` num lugar e `127.0.0.1` no outro.
 *
 * Máscara: os dois últimos octetos IPv4 (ou tudo depois do terceiro bloco IPv6). Um IP com
 * apenas o último octeto escondido continua identificando a rede /24 — para minimização de
 * dado pessoal isso é pouco.
 */
final class IpDisplay
{
    /**
     * Modo vigente para a organização, com o padrão da configuração como base.
     */
    public static function mode(?Organization $organization): string
    {
        $mode = $organization?->setting('evidence_show_ip');

        return is_string($mode) && $mode !== ''
            ? $mode
            : (string) config('assinavelox.evidence_show_ip', 'masked');
    }

    /**
     * IP como deve aparecer na tela, ou `null` quando não deve aparecer
     * (endereço ausente ou modo `none`).
     */
    public static function for(?string $ip, ?Organization $organization): ?string
    {
        if ($ip === null || trim($ip) === '') {
            return null;
        }

        return match (self::mode($organization)) {
            'full' => $ip,
            'none' => null,
            default => self::mask($ip),
        };
    }

    public static function mask(string $ip): string
    {
        if (str_contains($ip, ':')) {
            $blocks = explode(':', $ip);

            return implode(':', array_slice($blocks, 0, 3)).':…';
        }

        $octets = explode('.', $ip);

        return count($octets) === 4 ? "{$octets[0]}.{$octets[1]}.***.***" : $ip;
    }
}
