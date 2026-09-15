<?php

namespace App\Services\Embed;

use App\Enums\AuditEventType;
use App\Enums\SigningSessionStatus;
use App\Models\EmbeddedSigningSession;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningSession;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Signing\SignerTokens;
use Illuminate\Support\Carbon;

/**
 * Encerra uma sessão embutida antes do fim (docs/fase-3/widget-embutido.md §2.3).
 *
 * Revogar derruba também a sessão de assinatura do participante que o widget abriu (a linha de
 * `signing_sessions` cujo token está guardado, cifrado, no estado da sessão embutida) — senão um
 * aceite ainda poderia sair do iframe já aberto. O token de execução continua no banco (só o
 * digest) para o widget aberto receber "acesso encerrado" em vez de um erro genérico.
 */
final class EmbeddedSessionRevoker
{
    public function revoke(EmbeddedSigningSession $session, string $reason): bool
    {
        // Idempotente, e um desfecho já registrado pelo widget nunca é "revogado" depois: a API
        // continua informando `completed`/`refused` e a trilha não ganha uma revogação posterior
        // ao aceite (revisão adversarial da onda G).
        if ($session->isRevoked() || $session->outcome !== null) {
            return false;
        }

        $state = $session->session_state ?? [];
        $raw = $state['signer_session'] ?? null;

        if (is_string($raw) && $raw !== '') {
            SigningSession::withoutOrganizationScope()
                ->where('token_digest', SignerTokens::digest($raw))
                ->where('recipient_id', $session->recipient_id)
                ->whereIn('status', [SigningSessionStatus::PendingAuth->value, SigningSessionStatus::Authenticated->value])
                ->update([
                    'status' => SigningSessionStatus::Revoked->value,
                    'authorization_token_digest' => null,
                    'authorization_expires_at' => null,
                ]);
        }

        $session->forceFill([
            'revoked_at' => Carbon::now(),
            'revoked_reason' => substr($reason, 0, 32),
            'session_state' => null,
        ])->save();

        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->whereKey($session->envelope_id)->first();
        /** @var Recipient|null $recipient */
        $recipient = Recipient::withoutOrganizationScope()->whereKey($session->recipient_id)->first();

        if ($envelope !== null) {
            EnvelopeAudit::record($envelope, AuditEventType::EmbeddedSessionRevoked, [
                'session' => $session->ulid,
                'reason' => $reason,
            ], $recipient);
        }

        return true;
    }
}
