<?php

namespace App\Services\Verification;

use App\Enums\AuditEventType;
use App\Enums\DeliveryChannel;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Models\AcceptanceDocument;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Documents\EnvelopeDocuments;
use App\Services\Identity\CaptureEvidence;
use App\Services\InPerson\InPersonEvidence;
use App\Services\Signing\Channels\ChannelInvitations;
use App\Services\Signing\Channels\SenderPins;
use App\Services\Signing\Channels\SimulatedChannelEvidence;
use App\Support\IpDisplay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Dossiê de evidências do envelope (ROUTES §2.8), para quem já pode ver o documento.
 *
 * Diferença essencial em relação à página pública: aqui o destinatário da informação é a
 * própria organização remetente, que já conhece nome e e-mail dos participantes. Por isso o
 * dossiê mostra o dado por extenso — **com uma exceção**: o endereço IP obedece a
 * `organizations.settings.evidence_show_ip` (`masked` por padrão, `full` ou `none`), aplicado
 * por {@see IpDisplay} para que a trilha, o card do signatário e esta página nunca discordem.
 *
 * ## Abertura detectada ≠ leitura
 *
 * `invitation.opened` prova que **alguém** pediu a URL do convite — pode ter sido o filtro
 * antivírus do servidor de e-mail do destinatário. O dossiê nomeia o fato como "abertura
 * detectada" e em nenhum lugar afirma que o documento foi lido. A prova que existe é o
 * **aceite**, com data do servidor, IP, user-agent, método de autenticação e a versão exata
 * do documento.
 */
final class EvidenceDossier
{
    /**
     * @param  Collection<int, AuditEvent>  $events
     * @return list<array<string, mixed>>
     */
    public function recipients(Envelope $envelope, Collection $events): array
    {
        $firstByType = $this->firstEventPerRecipient($events);

        // Fase 2, onda B (aditivos): fotos da captura simples (C-ID), aceite presencial
        // (C-PRES) e canal/PIN (C-CAN). Sem nada disso, as chaves ficam vazias/nulas.
        $captures = app(CaptureEvidence::class)->forEnvelope($envelope)['recipients'];
        $inPerson = collect(InPersonEvidence::forEnvelope($envelope))->keyBy('recipient_id');
        $pins = app(SenderPins::class);

        return array_values($envelope->recipients
            ->map(function (Recipient $recipient) use ($envelope, $firstByType, $captures, $inPerson, $pins): array {
                $acceptance = $recipient->acceptance;
                $key = (int) $recipient->getKey();

                $invitedAt = $firstByType[AuditEventType::InvitationSent->value][$key]
                    ?? $recipient->last_notified_at;
                $openedAt = $firstByType[AuditEventType::InvitationOpened->value][$key] ?? null;
                $otpVerifiedAt = $firstByType[AuditEventType::ChallengeVerified->value][$key] ?? null;

                return [
                    'name' => $recipient->name,
                    'email' => $recipient->email,
                    'role' => is_string($recipient->role_label) && trim($recipient->role_label) !== ''
                        ? trim($recipient->role_label)
                        : null,
                    'status' => $recipient->status->value,
                    'status_label' => $recipient->status->label(),
                    // Fase 2 §2.9: o método do código (e-mail, SMS ou WhatsApp) e, com PIN,
                    // `sender_pin`. Participante da Fase 1: `['email_otp']`.
                    'auth_methods' => $pins->requiredFor($recipient)
                        ? [($acceptance->auth_method ?? $recipient->auth_method)->value, 'sender_pin']
                        : [($acceptance->auth_method ?? $recipient->auth_method)->value],
                    'auth_method_label' => ($acceptance->auth_method ?? $recipient->auth_method)->label(),
                    // Revisão da onda B: com o simulador de SMS/WhatsApp nada foi transmitido —
                    // a evidência diz isso em vez de afirmar a posse de um celular.
                    'auth_method_simulated' => $simulated = SimulatedChannelEvidence::wasSimulated($recipient),
                    'auth_method_note' => $simulated ? SimulatedChannelEvidence::NOTE : null,
                    'delivery_channel' => (ChannelInvitations::channelOf($recipient) ?? DeliveryChannel::Email)->value,
                    // Fase 2 §2.10: "imagem capturada pelo participante" — nunca verificação.
                    'identity_captures' => $captures[$recipient->ulid] ?? [],
                    // Fase 2 §2.6: aceite registrado no dispositivo presencial.
                    'in_person' => $inPerson->get($recipient->ulid),
                    'signature_kind' => $acceptance?->signature_kind?->value,
                    // Sem rota autorizada para a imagem da representação visual na Fase 1: o
                    // relatório em PDF a embute; a tela não expõe o caminho no disco privado.
                    'signature_image_url' => null,
                    'sent_at' => $invitedAt?->toIso8601String(),
                    // Mantido pelo contrato atual da página; o rótulo honesto é `opened_label`.
                    'viewed_at' => $openedAt?->toIso8601String(),
                    'opened_at' => $openedAt?->toIso8601String(),
                    'opened_label' => $openedAt !== null
                        ? 'Abertura do link detectada (não comprova leitura)'
                        : 'Nenhuma abertura do link detectada',
                    'otp_verified_at' => $otpVerifiedAt?->toIso8601String(),
                    'signed_at' => $recipient->signed_at?->toIso8601String(),
                    'refused_at' => $recipient->refused_at?->toIso8601String(),
                    'refusal_reason' => $recipient->refusal_reason,
                    'ip' => IpDisplay::for($acceptance?->ip_address, $envelope->organization),
                    'ip_policy' => IpDisplay::mode($envelope->organization),
                    'user_agent' => $acceptance?->user_agent,
                    'geo_label' => null,
                    'accepted_at' => $acceptance?->accepted_at?->toIso8601String(),
                    'document_sha256' => $acceptance?->document_sha256,
                    'terms_version' => $acceptance?->terms_version,
                    'consent_text' => $acceptance?->consent_statement,
                    // Fase 2 (aditivos): papel de domínio, o que o aceite registrou e sobre
                    // quais documentos.
                    'participant_role' => $recipient->role->value,
                    'participant_role_label' => $recipient->role->label(),
                    'acceptance_action' => $acceptance?->action->value,
                    'acceptance_action_label' => $acceptance?->action->label(),
                    'accepted_documents' => $acceptance === null ? [] : $acceptance->documents()
                        ->with('document:id,ulid,name')
                        ->get()
                        ->map(fn (AcceptanceDocument $row): array => [
                            'document_id' => $row->document?->ulid,
                            'position' => (int) $row->position,
                            'name' => $row->document?->name,
                            'sha256' => $row->document_sha256,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->all());
    }

    /**
     * Documentos do envelope para a página de evidências (Fase 2 §2.3): os quatro resumos
     * de cada arquivo (quem lê já pode ver o documento), a página de evidências e quem
     * registrou aceite sobre ele.
     *
     * @return list<array<string, mixed>>
     */
    public function documents(Envelope $envelope, bool $canDownload): array
    {
        $completed = $envelope->status === EnvelopeStatus::Completed;
        $documents = EnvelopeDocuments::ordered($envelope);

        if ($documents->isEmpty()) {
            return [];
        }

        $versions = DocumentVersion::withoutOrganizationScope()
            ->whereIn('document_id', $documents->pluck('id')->all())
            ->orderBy('version_number')
            ->get(['id', 'document_id', 'kind', 'sha256', 'version_number'])
            ->groupBy('document_id');

        $items = [];

        foreach ($documents as $document) {
            $mine = $versions->get($document->getKey(), collect());

            $latest = fn (DocumentVersionKind $kind): ?string => $mine->where('kind', $kind)->last()?->sha256;

            $accepted = AcceptanceDocument::withoutOrganizationScope()
                ->with('acceptance.recipient')
                ->where('document_id', $document->getKey())
                ->orderBy('id')
                ->get()
                ->map(fn (AcceptanceDocument $row): array => [
                    'name' => $row->acceptance?->recipient?->name,
                    'participant_role' => $row->acceptance?->recipient?->role->value,
                    'action' => $row->acceptance?->action->value,
                    'action_label' => $row->acceptance?->action->label(),
                    'accepted_at' => $row->acceptance?->accepted_at->toIso8601String(),
                    'document_sha256' => $row->document_sha256,
                ])
                ->values()
                ->all();

            $items[] = [
                'id' => $document->ulid,
                'position' => (int) $document->position,
                'name' => $document->name,
                'original_name' => $document->original_filename,
                'hashes' => [
                    'original_sha256' => $mine->where('kind', DocumentVersionKind::Original)->first()?->sha256,
                    'sent_sha256' => $document->sent_version_id === null ? null : $mine->firstWhere('id', $document->sent_version_id)?->sha256,
                    'consolidated_sha256' => $latest(DocumentVersionKind::Consolidated),
                    'evidence_sha256' => $latest(DocumentVersionKind::Evidence),
                    'final_sha256' => $document->final_version_id === null ? null : $mine->firstWhere('id', $document->final_version_id)?->sha256,
                ],
                'accepted_by' => $accepted,
                'downloads' => [
                    'signed' => $completed && $canDownload ? route('envelopes.download', ['envelope' => $envelope->ulid, 'type' => 'signed', 'document' => $document->ulid]) : null,
                    'evidence' => $completed && $canDownload ? route('envelopes.download', ['envelope' => $envelope->ulid, 'type' => 'evidence', 'document' => $document->ulid]) : null,
                ],
            ];
        }

        return $items;
    }

    /**
     * Frases fixas que a página precisa dizer com estas palavras (arquitetura §2).
     *
     * @return array<string, string>
     */
    public function notes(): array
    {
        return [
            'opened_vs_read' => 'A trilha registra a abertura detectada do link de convite: o servidor '
                .'sabe que alguém pediu aquela URL, e nada além disso — filtros de e-mail e '
                .'pré-visualizadores abrem links automaticamente. Nenhum registro desta página '
                .'comprova que o documento foi lido. O que se comprova é o aceite eletrônico.',
            'hashes' => HashLedger::primer(),
            'not_a_certificate' => 'Esta página não é um certificado digital nem é emitida por '
                .'autoridade certificadora, e não substitui a análise das partes sobre a validade do '
                .'ato documentado.',
        ];
    }

    /**
     * Primeiro evento de cada tipo por destinatário: `[event_type][recipient_id] => Carbon`.
     *
     * @param  Collection<int, AuditEvent>  $events
     * @return array<string, array<int, Carbon>>
     */
    private function firstEventPerRecipient(Collection $events): array
    {
        $map = [];

        foreach ($events as $event) {
            $recipientId = $event->recipient_id;

            if ($recipientId === null) {
                continue;
            }

            $type = $event->event_type->value;

            if (! isset($map[$type][$recipientId])) {
                $map[$type][(int) $recipientId] = $event->occurred_at;
            }
        }

        return $map;
    }
}
