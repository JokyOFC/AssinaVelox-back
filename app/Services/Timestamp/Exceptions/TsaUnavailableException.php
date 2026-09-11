<?php

namespace App\Services\Timestamp\Exceptions;

/**
 * A TSA da operadora não pode emitir nesta instalação: flag desligada ou configuração
 * incompleta. `reasons` lista o que falta, item por item (sem segredo nenhum).
 */
class TsaUnavailableException extends TsaException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(
            'A TSA da operadora está indisponível: '.($reasons === [] ? 'motivo não informado.' : implode(' ', $reasons)),
            'tsa_unavailable',
        );
    }
}
