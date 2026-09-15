<?php

namespace App\Services\BulkGeneration\Spreadsheet;

use RuntimeException;

/**
 * Planilha recusada inteira (formato, tamanho, estrutura). A mensagem é PT-BR, pronta para o
 * usuário, e nunca contém caminho interno, trecho do arquivo ou detalhe da biblioteca.
 */
final class SpreadsheetRejectedException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function make(string $code, string $message): self
    {
        return new self($code, $message);
    }
}
