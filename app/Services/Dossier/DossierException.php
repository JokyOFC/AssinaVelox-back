<?php

namespace App\Services\Dossier;

use RuntimeException;

/**
 * Pedido de dossiê impossível (nada visível selecionado, envelope não concluído, arquivo
 * grande demais). A mensagem é para o usuário, em PT-BR, e nunca contém caminho de disco.
 */
class DossierException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'dossier_error')
    {
        parent::__construct($message);
    }
}
