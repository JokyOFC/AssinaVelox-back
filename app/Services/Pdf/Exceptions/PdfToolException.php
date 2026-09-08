<?php

namespace App\Services\Pdf\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base das falhas do pdftool. Carrega o contexto necessário para diagnóstico
 * (código snake_case do JSON, exit code, argv saneado, correlation id) e NUNCA
 * o ambiente do processo — a passphrase do certificado não passa por aqui.
 */
class PdfToolException extends RuntimeException
{
    /**
     * @param  list<string>  $command  argv exatamente como executado (caminhos e opções; nunca variáveis de ambiente)
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?int $exitCode = null,
        public readonly array $command = [],
        public readonly ?string $correlationId = null,
        public readonly ?string $stderrExcerpt = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Contexto seguro para logs/relatórios.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'error_code' => $this->errorCode,
            'exit_code' => $this->exitCode,
            'command' => $this->command,
            'correlation_id' => $this->correlationId,
            'stderr' => $this->stderrExcerpt,
        ];
    }
}
