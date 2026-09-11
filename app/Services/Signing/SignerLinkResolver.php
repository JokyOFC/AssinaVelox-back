<?php

namespace App\Services\Signing;

use App\Enums\AccessLinkPurpose;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;

/**
 * Resolução do token de convite (arquitetura §4.1; ROUTES §3.2 passo 1).
 *
 * O que este serviço decide, em ordem, e por quê:
 *
 * 1. **O token existe?** A busca é pelo digest SHA-256 (coluna UNIQUE) e a confirmação
 *    passa por `hash_equals`. O token bruto não está em lugar nenhum do banco.
 * 2. **O link foi revogado?** Troca de e-mail, reenvio, recusa → inválido, sem explicação.
 * 3. **O envelope saiu do rascunho?** Link de envelope `draft/preparing/ready` não deveria
 *    existir; se existir, não abre.
 * 4. **O prazo continua de pé?** Revalidação a cada acesso, sem confiar no agendador — e
 *    ANTES de olhar o `expires_at` do link, que em produção é o mesmo do envelope. Só
 *    depois disso um link vencido com envelope ainda vivo é recusado.
 * 5. **Qual é o estado desta pessoa?** Ver {@see SignerContext}.
 * 6. **É a vez dela?** No sequencial, `order_index > current_order` não abre.
 *
 * Qualquer falha devolve `null` e o middleware responde **404 genérico** — a mesma resposta
 * para token inexistente, revogado, vencido, de envelope em rascunho e fora da vez. Um 404
 * diferente por motivo transformaria a página em um oráculo de existência de convites.
 */
final class SignerLinkResolver
{
    /**
     * Comprimento mínimo aceito antes de tocar no banco (a rota já exige 20..128, mas o
     * serviço é chamado de outros lugares e não confia no roteador).
     */
    public const MIN_TOKEN_LENGTH = 20;

    public function resolve(string $token): ?SignerContext
    {
        if (strlen($token) < self::MIN_TOKEN_LENGTH || strlen($token) > 128) {
            return null;
        }

        $digest = SignerTokens::digest($token);

        /** @var RecipientAccessLink|null $link */
        $link = RecipientAccessLink::withoutOrganizationScope()
            ->where('token_digest', $digest)
            ->where('purpose', AccessLinkPurpose::Signing->value)
            ->first();

        if ($link === null || ! SignerTokens::matches($link->token_digest, $token)) {
            return null;
        }

        // Revogação é definitiva e independe do envelope: link trocado por um reenvio,
        // e-mail alterado, recusa. Some sem explicação, como token desconhecido.
        if ($link->isRevoked()) {
            return null;
        }

        /** @var Recipient|null $recipient */
        $recipient = Recipient::withoutOrganizationScope()->whereKey($link->recipient_id)->first();
        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->whereKey($link->envelope_id)->first();

        if ($recipient === null || $envelope === null) {
            return null;
        }

        // Coerência dura: o link precisa pertencer ao mesmo destinatário, envelope e
        // organização. Uma linha inconsistente (migração manual, importação) não vira acesso.
        if ($recipient->envelope_id !== $envelope->getKey()
            || $recipient->organization_id !== $envelope->organization_id
            || $link->organization_id !== $envelope->organization_id) {
            return null;
        }

        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($envelope->organization_id)->first();

        if ($organization === null) {
            return null;
        }

        // Envelope ainda em preparação: o convite não deveria existir.
        if ($envelope->status->isDraftLike()) {
            return null;
        }

        // Revalidação do prazo a cada acesso (RECONCILIACAO Q22). O agendador
        // (`envelopes:expire`, a cada 15 min) pode estar parado ou atrasado; nenhuma tela do
        // signatário confia nele. Idempotente: só toca no banco quando o prazo venceu mesmo.
        $envelope = EnvelopeExpiration::revalidate($envelope);

        // O prazo PRÓPRIO do link só recusa enquanto o envelope continua de pé. Como
        // `AccessLinks::issue()` grava `expires_at = envelope.expires_at`, recusar aqui
        // antes da revalidação — que era a ordem anterior — fazia o link vencer no mesmo
        // instante que o envelope: o signatário recebia o 404 genérico "link inválido" em
        // vez da tela `expired` de ROUTES §3, a revalidação prometida por RECONCILIACAO
        // Q22 nunca rodava por este caminho (o envelope ficava `in_progress` até o
        // agendador passar) e quem já tinha assinado perdia o acesso ao próprio
        // comprovante. Um link de vida mais curta que a do envelope continua sendo
        // recusado, que é o caso que esta checagem existe para cobrir.
        if ($link->isExpired() && $envelope->status === EnvelopeStatus::InProgress) {
            return null;
        }

        $recipient = Recipient::withoutOrganizationScope()->whereKey($recipient->getKey())->first() ?? $recipient;

        $state = self::stateFor($envelope, $recipient);

        if ($state === null) {
            return null;
        }

        return new SignerContext($token, $link, $recipient, $envelope, $organization, $state);
    }

    /**
     * Estado do convite, ou `null` quando o link não deve abrir de jeito nenhum.
     *
     * A ordem importa: o que aconteceu com **esta pessoa** manda sobre o que aconteceu com
     * o envelope. Quem já aceitou vê o comprovante mesmo que o envelope tenha expirado
     * depois; quem foi cancelado pela recusa de outro vê "cancelado", não "recusado" —
     * dizer "você recusou" a quem não recusou seria falso.
     */
    public static function stateFor(Envelope $envelope, Recipient $recipient): ?string
    {
        // Visualizador (Fase 2 §2.4): não tem vez nem aceite. O link abre enquanto o
        // envelope está vivo e depois da conclusão (para a cópia final); encerramento sem
        // conclusão mostra o motivo, como para os demais.
        if ($recipient->role === RecipientRole::Viewer) {
            return match (true) {
                $recipient->status === RecipientStatus::Canceled => SignerContext::STATE_CANCELED,
                $recipient->status === RecipientStatus::Expired => SignerContext::STATE_EXPIRED,
                $envelope->status === EnvelopeStatus::Expired => SignerContext::STATE_EXPIRED,
                $envelope->status === EnvelopeStatus::Canceled,
                $envelope->status === EnvelopeStatus::Refused => SignerContext::STATE_CANCELED,
                in_array($envelope->status, [EnvelopeStatus::InProgress, EnvelopeStatus::Finalizing, EnvelopeStatus::Completed], true) => SignerContext::STATE_ACTIVE,
                default => null,
            };
        }

        return match (true) {
            $recipient->status === RecipientStatus::Signed => match ($envelope->status) {
                EnvelopeStatus::Completed => SignerContext::STATE_COMPLETED,
                // Ninguém falta: o envelope está sendo consolidado, não "aguardando os
                // outros". Quem assina por último cai exatamente aqui.
                EnvelopeStatus::Finalizing => SignerContext::STATE_FINALIZING,
                default => SignerContext::STATE_SIGNED_PENDING_OTHERS,
            },

            $recipient->status === RecipientStatus::Refused => SignerContext::STATE_REFUSED,
            $recipient->status === RecipientStatus::Expired => SignerContext::STATE_EXPIRED,
            $recipient->status === RecipientStatus::Canceled => SignerContext::STATE_CANCELED,

            $envelope->status === EnvelopeStatus::Expired => SignerContext::STATE_EXPIRED,
            $envelope->status === EnvelopeStatus::Canceled => SignerContext::STATE_CANCELED,
            // Recusa de outro participante encerra o envelope; para quem não recusou o fato
            // é "a solicitação foi encerrada", nunca "você recusou".
            $envelope->status === EnvelopeStatus::Refused => SignerContext::STATE_CANCELED,

            // Envelope concluído com destinatário não assinado é estado impossível
            // (a conclusão exige todos os aceites): não abre.
            $envelope->status === EnvelopeStatus::Completed => null,

            // Só `in_progress` e `finalizing` sobram; em `finalizing` já não há o que assinar.
            $envelope->status !== EnvelopeStatus::InProgress => null,

            // Fora da vez no sequencial: 404 genérico, igual a token desconhecido.
            $envelope->isSequential() && $recipient->order_index > $envelope->current_order => null,

            $recipient->status->isPendingSignature() => SignerContext::STATE_ACTIVE,

            default => null,
        };
    }
}
