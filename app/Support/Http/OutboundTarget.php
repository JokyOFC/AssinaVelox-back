<?php

namespace App\Support\Http;

/**
 * Destino de saída já validado pelo OutboundUrlGuard: URL reconstruída a partir das partes
 * analisadas (sem fragmento, sem credenciais), host em ASCII, porta efetiva, todos os
 * endereços resolvidos (todos públicos) e o endereço em que a conexão será PINADA.
 */
final class OutboundTarget
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public readonly string $url,
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly array $addresses,
        public readonly string $pinnedAddress,
    ) {}

    /**
     * Entrada de CURLOPT_RESOLVE ("host:porta:endereço"). O host continua na URL, então o SNI
     * e a validação do certificado continuam sendo do NOME — só o endereço é fixado. IPv6 vai
     * entre colchetes (formato aceito pelo libcurl).
     */
    public function curlResolveEntry(): string
    {
        $address = str_contains($this->pinnedAddress, ':') ? '['.$this->pinnedAddress.']' : $this->pinnedAddress;

        return $this->host.':'.$this->port.':'.$address;
    }
}
