<?php

namespace App\Services\Verification;

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
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

        return array_values($envelope->recipients
            ->map(function (Recipient $recipient) use ($envelope, $firstByType): array {
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
                    'auth_methods' => [$recipient->auth_method->value],
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
                ];
            })
            ->all());
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
