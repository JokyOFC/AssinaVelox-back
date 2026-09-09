<?php

namespace App\Services\Signing\Contracts;

use App\Models\Envelope;
use App\Services\Signing\EnvelopeExpirationGuard;

/**
 * Revalidação do prazo no acesso do signatário (RECONCILIACAO Q22: "`ExpireEnvelopes` a cada
 * 15 min **e** revalidação a cada acesso").
 *
 * O agendador pode estar parado, atrasado ou ter falhado; nenhuma tela do signatário confia
 * nele. Toda resolução de link chama esta revalidação antes de decidir o que mostrar.
 *
 * Contrato publicado por este módulo e implementado pelo módulo de envio
 * (`App\Services\Envelopes\Sending\ExpireEnvelopes`), ligado no `AppServiceProvider`. Sem
 * implementação registrada vale o guarda padrão
 * {@see EnvelopeExpirationGuard}, que produz a mesma transição
 * (`in_progress` → `expired`, pendentes → `expired`, evento `envelope.expired`).
 */
interface RevalidatesEnvelopeExpiration
{
    /**
     * Devolve o envelope já com o status correto para o instante atual.
     * Idempotente e seguro para chamar em toda requisição: só toca no banco quando o prazo
     * realmente venceu. Não lança em transição inválida — envelope terminal volta como está.
     */
    public function revalidate(Envelope $envelope): Envelope;
}
