<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * O último aceite exigido foi gravado: o envelope passou a `finalizing` e está pronto para
 * a consolidação (incremento 4).
 *
 * **Este evento não conclui nada.** Ele apenas anuncia. Quem escuta é a finalização — que
 * ainda não existe — e é ela que vai compor o PDF, gerar a página de evidências, aplicar (ou
 * não) a assinatura criptográfica da operadora, calcular os hashes e só então marcar
 * `completed`. Enquanto ninguém escutar, o envelope fica em `finalizing` e a interface diz
 * exatamente isso ("Em andamento · finalizando"): nenhuma tela afirma conclusão, nenhum
 * arquivo final é inventado e nenhuma assinatura é simulada.
 *
 * Só ids viajam no evento. Um listener em fila precisa recarregar do banco sob lock; passar
 * o model serializado convidaria a decidir com dados velhos.
 *
 * Para escutar (incremento 4):
 *
 *     Event::listen(EnvelopeReadyForFinalization::class, FinalizeEnvelope::class);
 */
final class EnvelopeReadyForFinalization
{
    use Dispatchable;

    public function __construct(
        public readonly int $envelopeId,
        public readonly int $organizationId,
        /** `envelopes.finalization_key`: chave de idempotência da finalização (arquitetura §3.3). */
        public readonly ?string $finalizationKey = null,
        public readonly ?string $correlationId = null,
    ) {}
}
