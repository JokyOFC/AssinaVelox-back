<?php

namespace App\Services\Envelopes\Delegation;

use App\Enums\AuditEventType;
use App\Enums\AuthMethod;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Delegation;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\User;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Envelopes\Sending\AccessLinks;
use App\Services\Envelopes\Sending\InvitationDispatcher;
use App\Services\Identity\Models\IdentityCaptureRequirement;
use App\Services\Identity\Models\IdentityVideoRequirement;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerSessions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Faz uma delegação VALER (ou a recusa) — docs/fase-3/etapas-e-delegacao.md §3.3.
 *
 * Sob `SELECT ... FOR UPDATE` no envelope e no pedido, numa transação só:
 *
 * 1. revalida tudo (coleta em andamento, prazo, pedido ainda pendente, original ainda pendente
 *    e sem aceite, e-mail do delegado ainda livre no envelope);
 * 2. cria o DELEGADO — um participante NOVO, na mesma posição, vez e etapa, com o mesmo papel,
 *   `pending`, autenticação pelo código por e-mail e `delegated_from_recipient_id`;
 * 3. passa para ele os campos do original (o original não tem valor gravado: delegar depois do
 *    aceite é proibido) e a exigência de fotos, se houver — nunca uma exigência menor;
 * 4. marca o original `delegated` (nunca "assinado") e revoga os links dele: o link antigo
 *    deixa de abrir já na resolução do token;
 * 5. grava `recipient.delegated` na trilha.
 *
 * Depois do commit: revoga as sessões do original e convida o delegado, se for a vez dele.
 * O aceite do delegado é DELE, com declaração, código e evidências próprios.
 */
final class DelegationExecutor
{
    public function __construct(
        private readonly AccessLinks $links,
        private readonly SignerSessions $sessions,
        private readonly InvitationDispatcher $invitations,
    ) {}

    /**
     * @throws DelegationException
     */
    public function execute(Delegation $delegation, ?User $approvedBy = null): Recipient
    {
        $correlationId = (string) Str::ulid();

        /** @var array{error: string}|array{envelope: Envelope, original: Recipient, delegate: Recipient} $outcome */
        $outcome = DB::transaction(function () use ($delegation, $approvedBy, $correlationId): array {
            /** @var Envelope|null $envelope */
            $envelope = Envelope::withoutOrganizationScope()->whereKey($delegation->envelope_id)->lockForUpdate()->first();
            /** @var Delegation|null $fresh */
            $fresh = Delegation::withoutOrganizationScope()->whereKey($delegation->getKey())->lockForUpdate()->first();

            if ($envelope === null || $fresh === null || ! $fresh->isPending()) {
                return ['error' => 'not_pending'];
            }

            /** @var Recipient|null $original */
            $original = Recipient::withoutOrganizationScope()->whereKey($fresh->from_recipient_id)->first();

            $closed = $envelope->status !== EnvelopeStatus::InProgress
                || ($envelope->expires_at !== null && $envelope->expires_at->isPast());

            if ($original === null) {
                return ['error' => 'not_pending'];
            }

            $acted = ! $original->status->canBeDelegated()
                || SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $original->getKey())->exists();

            if ($closed || $acted) {
                // O pedido perdeu o objeto: fica registrado como "sem efeito", e o original segue.
                $fresh->forceFill([
                    'status' => Delegation::STATUS_VOID,
                    'decision_note' => $closed ? 'A coleta terminou antes da decisão.' : 'O participante já respondeu antes da decisão.',
                ])->save();

                return ['error' => $closed ? 'closed' : 'already_acted'];
            }

            // Mesma caixa de correio (+alias, pontos do Gmail) de quem já está no envelope.
            $taken = DelegationPolicy::mailboxInEnvelope((int) $envelope->getKey(), $fresh->to_email);

            if ($taken) {
                return ['error' => 'recipient_exists'];
            }

            $now = Carbon::now();

            $delegate = new Recipient;
            $delegate->forceFill([
                'envelope_id' => $envelope->getKey(),
                'organization_id' => $envelope->organization_id,
                'name' => $fresh->to_name,
                'email' => $fresh->to_email,
                'phone' => null,
                'role' => $original->role,
                'role_label' => $original->getAttribute('role_label'),
                'order_index' => $original->order_index,
                'position' => $original->getAttribute('position'),
                'status' => RecipientStatus::Pending,
                'auth_method' => AuthMethod::EmailOtp,
                'notification_count' => 0,
                'signing_step_index' => $original->getAttribute('signing_step_index'),
                'delegated_from_recipient_id' => $original->getKey(),
                // Integração I-3F (F-I18N): o idioma e o fuso foram escolhidos por quem enviou
                // para esta posição; o delegado recebe o convite e a página neles e pode trocar
                // o idioma de exibição na própria página. Sem a flag `multilingual`, não valem.
                'locale' => $original->getAttribute('locale') ?? 'pt_BR',
                'timezone' => $original->getAttribute('timezone'),
            ])->save();

            $movedFields = SigningField::withoutOrganizationScope()
                ->where('envelope_id', $envelope->getKey())
                ->where('recipient_id', $original->getKey())
                ->update(['recipient_id' => $delegate->getKey(), 'updated_at' => $now]);

            $this->copyCaptureRequirement($original, $delegate);
            $this->copyVideoRequirement($original, $delegate);

            // `delegated` não é transição da máquina genérica (RecipientStatus): este é o único
            // caminho, e o estado de origem acabou de ser conferido sob lock.
            $original->status = RecipientStatus::Delegated;
            $original->forceFill(['status_reason' => DelegationPolicy::REASON_DELEGATED])->save();

            $this->links->revokeFor($original);

            $fresh->forceFill([
                'status' => Delegation::STATUS_EFFECTIVE,
                'to_recipient_id' => $delegate->getKey(),
                'delegated_at' => $now,
                'approved_by_sender_at' => $approvedBy !== null ? $now : null,
                'approved_by_user_id' => $approvedBy?->getKey(),
            ])->save();

            $payload = [
                'delegation' => $fresh->ulid,
                'from' => $original->ulid,
                'to' => $delegate->ulid,
                'to_masked' => Recipient::maskEmail($delegate->email),
                'reason_length' => mb_strlen($fresh->reason),
                'confirmed_by_sender' => $approvedBy !== null,
                'chain_depth' => $fresh->chain_depth,
                'fields_moved' => $movedFields,
            ];

            if ($approvedBy !== null) {
                // Ator: quem confirmou. O original continua sendo o sujeito do evento.
                EnvelopeAudit::record($envelope, AuditEventType::RecipientDelegated, $payload, $original, $correlationId);
            } else {
                SignerAudit::record($envelope, $original, AuditEventType::RecipientDelegated, $payload, $correlationId);
            }

            return ['envelope' => $envelope, 'original' => $original, 'delegate' => $delegate];
        });

        if (isset($outcome['error'])) {
            throw self::failure($outcome['error']);
        }

        /** @var Envelope $envelope */
        $envelope = $outcome['envelope'];
        /** @var Recipient $original */
        $original = $outcome['original'];
        /** @var Recipient $delegate */
        $delegate = $outcome['delegate'];

        $this->sessions->revokeAllFor($original);

        if ($this->invitations->isTheirTurn($delegate, $envelope)) {
            $this->invitations->dispatch($delegate->refresh(), $envelope->refresh(), false);
        }

        return $delegate;
    }

    /**
     * O remetente recusa o pedido: o original continua exatamente como estava.
     *
     * @throws DelegationException
     */
    public function reject(Delegation $delegation, User $by, ?string $note = null): void
    {
        $rejected = DB::transaction(function () use ($delegation, $by, $note): bool {
            /** @var Envelope|null $envelope */
            $envelope = Envelope::withoutOrganizationScope()->whereKey($delegation->envelope_id)->lockForUpdate()->first();
            /** @var Delegation|null $fresh */
            $fresh = Delegation::withoutOrganizationScope()->whereKey($delegation->getKey())->lockForUpdate()->first();

            if ($envelope === null || $fresh === null || ! $fresh->isPending()) {
                return false;
            }

            $fresh->forceFill([
                'status' => Delegation::STATUS_REJECTED,
                'rejected_at' => Carbon::now(),
                'rejected_by_user_id' => $by->getKey(),
                'decision_note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 500),
            ])->save();

            /** @var Recipient|null $original */
            $original = Recipient::withoutOrganizationScope()->whereKey($fresh->from_recipient_id)->first();

            EnvelopeAudit::record($envelope, AuditEventType::DelegationRejected, [
                'delegation' => $fresh->ulid,
                'from' => $original?->ulid,
                'has_note' => $fresh->decision_note !== null,
            ], $original);

            return true;
        });

        if (! $rejected) {
            throw self::failure('not_pending');
        }
    }

    private function copyCaptureRequirement(Recipient $original, Recipient $delegate): void
    {
        /** @var IdentityCaptureRequirement|null $requirement */
        $requirement = IdentityCaptureRequirement::query()->withoutGlobalScopes()
            ->where('recipient_id', $original->getKey())
            ->first();

        if ($requirement === null) {
            return;
        }

        $copy = new IdentityCaptureRequirement;
        $copy->forceFill([
            'organization_id' => $requirement->organization_id,
            'envelope_id' => $requirement->envelope_id,
            'recipient_id' => $delegate->getKey(),
            'kinds' => $requirement->kinds,
            'updated_by_user_id' => $requirement->updated_by_user_id,
        ])->save();
    }

    /**
     * Integração I-3F (F-VIDEO): a exigência de vídeo curto também passa ao delegado — nunca
     * uma exigência menor. O vídeo gravado é do delegado, com consentimento próprio.
     */
    private function copyVideoRequirement(Recipient $original, Recipient $delegate): void
    {
        /** @var IdentityVideoRequirement|null $requirement */
        $requirement = IdentityVideoRequirement::query()->withoutGlobalScopes()
            ->where('recipient_id', $original->getKey())
            ->first();

        if ($requirement === null) {
            return;
        }

        $copy = new IdentityVideoRequirement;
        $copy->forceFill([
            'organization_id' => $requirement->organization_id,
            'envelope_id' => $requirement->envelope_id,
            'recipient_id' => $delegate->getKey(),
            'max_seconds' => $requirement->max_seconds,
            'updated_by_user_id' => $requirement->updated_by_user_id,
        ])->save();
    }

    public static function failure(string $code): DelegationException
    {
        return match ($code) {
            'closed' => new DelegationException($code, 'A coleta deste documento terminou antes da decisão. O pedido ficou sem efeito.', 409),
            'already_acted' => new DelegationException($code, 'O participante já respondeu ao documento. O pedido de delegação ficou sem efeito.', 409),
            'recipient_exists' => new DelegationException($code, 'A pessoa indicada já participa deste documento. Recuse o pedido ou ajuste os participantes.', 409),
            default => new DelegationException($code, 'Este pedido de delegação não está mais aguardando decisão.', 409),
        };
    }
}
