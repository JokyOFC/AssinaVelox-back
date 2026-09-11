<?php

namespace App\Services\InPerson;

use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\User;
use App\Services\InPerson\Models\InPersonSession;
use App\Services\InPerson\Models\InPersonTurn;

/**
 * O que a evidência diz sobre os aceites registrados presencialmente
 * (docs/fase-2/presencial-e-lote.md §2.5).
 *
 * Contrato para a página de evidências e o `EvidenceDossier` (fora da área C-PRES): para cada
 * aceite presencial, o modo (`in_person`), o anfitrião que abriu a sessão e atestou a
 * presença, o dispositivo, o início da sessão e o momento do aceite — sem trocar o autor: o
 * aceite continua sendo do participante, com o método que ELE confirmou (código pelo canal
 * dele, PIN se houve).
 *
 * Rótulo sugerido: "Presencial, na presença de {anfitrião}". A prova é a do código do
 * próprio participante; o atestado do anfitrião é informação adicional, não substituto.
 */
final class InPersonEvidence
{
    /**
     * @return list<array{mode: string, label: string, recipient_id: string|null, recipient_name: string|null, host_name: string|null, device_label: string, session_id: string, session_started_at: string|null, accepted_at: string|null, acceptance_id: string|null, auth_method: string|null}>
     */
    public static function forEnvelope(Envelope $envelope): array
    {
        $turns = InPersonTurn::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('organization_id', $envelope->organization_id)
            ->where('status', InPersonTurn::STATUS_ACCEPTED)
            ->whereNotNull('signature_acceptance_id')
            ->orderBy('finished_at')
            ->orderBy('id')
            ->get();

        $entries = [];

        foreach ($turns as $turn) {
            /** @var InPersonSession|null $session */
            $session = InPersonSession::withoutOrganizationScope()->whereKey($turn->in_person_session_id)->first();
            /** @var Recipient|null $recipient */
            $recipient = Recipient::withoutOrganizationScope()->whereKey($turn->recipient_id)->first();
            /** @var SignatureAcceptance|null $acceptance */
            $acceptance = SignatureAcceptance::withoutOrganizationScope()->whereKey($turn->signature_acceptance_id)->first();
            /** @var User|null $host */
            $host = $session?->host_user_id === null ? null : User::query()->whereKey($session->host_user_id)->first();

            if ($session === null) {
                continue;
            }

            $hostName = $host?->name;

            $entries[] = [
                'mode' => 'in_person',
                'label' => $hostName === null
                    ? 'Presencial'
                    : 'Presencial, na presença de '.$hostName,
                'recipient_id' => $recipient?->ulid,
                'recipient_name' => $recipient?->name,
                'host_name' => $hostName,
                'device_label' => $session->device_label,
                'session_id' => $session->ulid,
                'session_started_at' => $session->started_at->toIso8601String(),
                'accepted_at' => $acceptance?->accepted_at->toIso8601String(),
                'acceptance_id' => $acceptance?->ulid,
                'auth_method' => $acceptance?->auth_method->label(),
            ];
        }

        return $entries;
    }
}
