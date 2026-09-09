<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Logger as MonologLogger;

/**
 * "Tap" de canal (config/logging.php → `'tap' => [App\Logging\RedactionTap::class]`) que
 * instala o redator em TODOS os handlers do canal.
 *
 * O processador é pendurado no HANDLER, e não no logger, de propósito. Processadores do
 * logger rodam em ordem inversa à de registro e ANTES do `ContextLogProcessor` do Laravel,
 * que é quem copia o `Context` (identificador de correlação e o que mais a aplicação tiver
 * posto lá) para o campo `extra`. Pendurado no handler, o redator é a ÚLTIMA
 * transformação antes da escrita — ele enxerga o registro completo, contexto do Laravel
 * incluído, e nada escapa por ter sido acrescentado tarde demais.
 */
final class RedactionTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        // Canais que não são Monolog (um logger PSR de terceiro apontado por `custom`)
        // não têm handler para pendurar nada; nesse caso a redação precisa vir do próprio
        // destino, e a documentação diz isso.
        if (! $monolog instanceof MonologLogger) {
            return;
        }

        $redactor = new RedactSensitiveData;

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor($redactor);
            }
        }
    }
}
