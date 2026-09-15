<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Http;

use AssinaVelox\Sdk\Exception\NetworkException;

/**
 * Envia uma requisição HTTP. O SDK traz duas implementações: {@see CurlTransport} (padrão,
 * com a extensão curl) e {@see StreamTransport} (sem extensão nenhuma). Nenhuma segue
 * redirecionamentos: o token nunca vai para outro endereço.
 */
interface Transport
{
    /**
     * @param  list<string>  $headers  Linhas "Nome: valor".
     *
     * @throws NetworkException
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): RawResponse;
}
