<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Envelope;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;
use App\Services\InPerson\Models\InPersonSession;

/**
 * Sessão presencial (Fase 2 §2.6, docs/fase-2/presencial-e-lote.md §2.1), por permissão:
 *
 *  - ver a tela de início: `send_envelopes` na organização corrente;
 *  - abrir para um envelope: exatamente quem pode ENVIAR aquele envelope
 *    ({@see EnvelopePolicy::send()}: ver o envelope, `send_envelopes` e ser o criador,
 *    `manage_any_envelope` ou gerenciar a pasta);
 *  - encerrar: o próprio anfitrião, quem pode enviar o envelope ou quem tem
 *    `cancel_any_envelope`.
 *
 * Nenhuma dessas permissões deixa o anfitrião registrar aceite por um participante: o aceite
 * exige a sessão de assinatura do PRÓPRIO participante, criada pelo código dele.
 *
 * Não é registrada por descoberta automática (o modelo mora em `App\Services\InPerson\Models`):
 * os controllers a chamam diretamente.
 */
class InPersonSessionPolicy
{
    use ResolvesMembership;

    public function __construct(private readonly EnvelopePolicy $envelopes) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::SendEnvelopes);
    }

    public function start(User $user, Envelope $envelope): bool
    {
        return $this->envelopes->send($user, $envelope);
    }

    public function end(User $user, InPersonSession $session): bool
    {
        $membership = $this->membershipFor($user, $session->organization_id);

        if ($membership === null) {
            return false;
        }

        if ($session->host_user_id === $user->getKey()) {
            return true;
        }

        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->whereKey($session->envelope_id)->first();

        if ($envelope === null || $envelope->organization_id !== $membership->organization_id) {
            return false;
        }

        return $this->envelopes->send($user, $envelope)
            || $membership->hasPermission(Permission::CancelAnyEnvelope);
    }
}
