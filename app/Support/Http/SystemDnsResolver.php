<?php

namespace App\Support\Http;

/**
 * Resolvedor do sistema: `dns_get_record(A|AAAA)` e, se vier vazio, `gethostbynamel` (que
 * também consulta o arquivo hosts). TODOS os endereços devolvidos passam pelo filtro de
 * faixas; a conexão depois é pinada num deles (CURLOPT_RESOLVE), então o que o sistema
 * resolveria no instante da conexão não importa mais.
 */
final class SystemDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $addresses[] = $record['ip'];
                }

                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        if ($addresses === []) {
            $ipv4 = @gethostbynamel($host);

            if (is_array($ipv4)) {
                $addresses = $ipv4;
            }
        }

        return array_values(array_unique($addresses));
    }
}
