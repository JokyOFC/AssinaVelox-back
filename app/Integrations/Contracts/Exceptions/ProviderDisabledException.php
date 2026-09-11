<?php

namespace App\Integrations\Contracts\Exceptions;

use App\Integrations\Exceptions\IntegrationException;

/**
 * Chamada a um adaptador de serviço próprio que está DESABILITADO (sem documentação nem
 * credenciais). A mensagem diz o que falta, item por item — nunca contém segredo.
 *
 * Quem chega aqui pulou a checagem de disponibilidade (`isConfigured()`): é erro de
 * programação, não resposta do provedor.
 */
class ProviderDisabledException extends IntegrationException
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $missing,
    ) {
        parent::__construct(sprintf(
            'O adaptador "%s" está desabilitado nesta instalação. Falta: %s',
            $provider,
            $missing === [] ? 'documentação e credenciais do serviço.' : implode(' ', $missing),
        ));
    }
}
