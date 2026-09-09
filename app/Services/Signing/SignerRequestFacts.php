<?php

namespace App\Services\Signing;

use App\Support\IpDisplay;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Os dois fatos técnicos que o aceite eletrônico registra sobre quem clicou: endereço IP e
 * identificação do navegador (arquitetura §2).
 *
 * **IP**: vem de `Request::ip()`, que só considera `X-Forwarded-For` quando o proxy está
 * declarado em `assinavelox.trusted_proxies` (aplicado em AppServiceProvider). Sem proxy
 * confiável configurado, o cabeçalho é ignorado e o IP é o da conexão — é por isso que a
 * configuração de proxy é parte da qualidade da evidência, não um detalhe de infraestrutura.
 *
 * **User-Agent**: texto livre enviado pelo cliente. É truncado porque a coluna é TEXT e um
 * agente hostil pode mandar megabytes; o valor não é interpretado em lugar nenhum.
 */
final class SignerRequestFacts
{
    public const MAX_USER_AGENT = 500;

    public static function ip(?Request $request = null): ?string
    {
        $request ??= request();

        $ip = $request->ip();

        return is_string($ip) && $ip !== '' ? Str::limit($ip, 45, '') : null;
    }

    public static function userAgent(?Request $request = null): ?string
    {
        $request ??= request();

        $agent = $request->userAgent();

        if (! is_string($agent) || trim($agent) === '') {
            return null;
        }

        return Str::limit($agent, self::MAX_USER_AGENT, '');
    }

    /**
     * IP para EXIBIÇÃO conforme `organizations.settings.evidence_show_ip`.
     * O IP continua sempre gravado; o que muda é o que aparece na tela e na página de
     * evidências (docs/juridico/aviso-de-privacidade-signatario.md).
     */
    public static function displayIp(?string $ip, string $mode): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return match ($mode) {
            'none' => null,
            'full' => $ip,
            // Uma única máscara em todo o produto: {@see IpDisplay::mask()}. A máscara
            // própria que existia aqui escondia só o último octeto (`127.0.0.•••`)
            // enquanto as telas do remetente escondiam dois (`127.0.***.***`) — o mesmo
            // aceite aparecia de duas formas, e a minimização mais fraca era justamente
            // a da tela que o titular do dado vê.
            default => IpDisplay::mask($ip),
        };
    }
}
