<?php

namespace App\Policies;

use App\Models\Envelope;
use App\Models\User;

/**
 * Link de assinatura em lote (Fase 2 §2.7, docs/fase-2/presencial-e-lote.md §3.2).
 *
 * Emitir o link para um participante exige a mesma permissão de reenviar o convite dele
 * (`EnvelopeRecipientController::resend` → `update` do envelope). O remetente não vê a lista
 * dos demais documentos que entram no lote (ela pode incluir envelopes que ele não enxerga);
 * vê só quantos entraram.
 */
class BatchSigningPolicy
{
    public function __construct(private readonly EnvelopePolicy $envelopes) {}

    public function issue(User $user, Envelope $envelope): bool
    {
        return $this->envelopes->update($user, $envelope);
    }
}
