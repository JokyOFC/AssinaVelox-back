<?php

namespace App\Services\Envelopes\Delegation;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Models\Delegation;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Notifications\Envelopes\DelegationRequestedNotification;
use App\Services\Envelopes\Steps\FlowFeatures;
use App\Services\Identity\Models\IdentityVideoRequirement;
use App\Services\Risk\SubjectKeys;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerRequestFacts;
use App\Services\Signing\SignerSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Delegação pedida pelo PARTICIPANTE na página pública (docs/fase-3/etapas-e-delegacao.md §3.2).
 *
 * Exige a sessão autenticada pelo código deste participante neste navegador — delegar é uma
 * manifestação da pessoa, tão séria quanto recusar. O participante informa nome, e-mail e
 * motivo de quem vai participar no lugar dele.
 *
 * Proibições (todas conferidas de novo sob o lock do envelope):
 *
 * - delegar para si mesmo, ou para qualquer pessoa que já esteja no envelope (inclusive
 *   visualizadores e quem já delegou) — o delegado precisa ser um participante NOVO;
 * - delegar depois de ter aceitado (ou recusado) — a manifestação já existe;
 * - delegar em cadeia além de `assinavelox.delegation.max_chain_depth` (padrão 1);
 * - delegar participação marcada como pessoal pelo remetente;
 * - dois pedidos pendentes ao mesmo tempo; mais de N pedidos por participante; mais de N
 *   delegações por organização em 24 h — a delegação não pode virar disparador de e-mail
 *   para endereços escolhidos pelo público.
 */
final class DelegationService
{
    public const NAME_MAX = 160;

    public function __construct(
        private readonly SignerSessions $sessions,
        private readonly DelegationExecutor $executor,
    ) {}

    /**
     * Por que a delegação NÃO é oferecida a este participante agora (null = oferecida).
     */
    public function unavailableReason(SignerContext $context): ?string
    {
        if (! $context->isActive() || $context->action() === null) {
            return 'not_active';
        }

        if (! DelegationPolicy::allows($context->envelope)) {
            return 'policy_disabled';
        }

        if (DelegationPolicy::isPersonal($context->envelope, $context->recipient)) {
            return 'personal';
        }

        if (DelegationPolicy::chainDepth($context->recipient) >= DelegationPolicy::maxChainDepth()) {
            return 'chain_limit';
        }

        return null;
    }

    /**
     * Estado do cartão "Delegar" da página pública (GET `sign.delegation.show`). `null` = 404:
     * flag desligada, sem sessão autenticada, ou nada a mostrar a esta pessoa.
     *
     * @return array<string, mixed>|null
     */
    public function state(SignerContext $context, Request $request): ?array
    {
        if (! FlowFeatures::delegation($context->organization) || $this->sessions->current($context, $request) === null) {
            return null;
        }

        $recipient = $context->recipient;
        $reason = $this->unavailableReason($context);
        $receivedFrom = $this->receivedFrom($recipient);

        /** @var Delegation|null $pending */
        $pending = $context->envelope->status === EnvelopeStatus::InProgress
            ? Delegation::withoutOrganizationScope()
                ->where('from_recipient_id', $recipient->getKey())
                ->where('status', Delegation::STATUS_PENDING)
                ->latest('id')
                ->first()
            : null;

        /** @var Delegation|null $rejected */
        $rejected = $pending === null
            ? Delegation::withoutOrganizationScope()
                ->where('from_recipient_id', $recipient->getKey())
                ->where('status', Delegation::STATUS_REJECTED)
                ->latest('id')
                ->first()
            : null;

        if ($reason !== null && $receivedFrom === null && $pending === null && $rejected === null) {
            return null;
        }

        return [
            'can_delegate' => $reason === null && $pending === null,
            'requires_confirmation' => DelegationPolicy::requiresConfirmation($context->envelope)
                || DelegationPolicy::hasStrongerAuthentication($recipient),
            // Revisão adversarial da onda F: a exigência de vídeo curto passa à pessoa indicada
            // (DelegationExecutor::copyVideoRequirement) — o diálogo avisa quem delega.
            'video_required' => IdentityVideoRequirement::query()->withoutGlobalScopes()
                ->where('recipient_id', $recipient->getKey())
                ->exists(),
            'pending' => $pending === null ? null : [
                'id' => $pending->ulid,
                'to_name' => $pending->to_name,
                'to_email_masked' => Recipient::maskEmail($pending->to_email),
                'requested_at' => $pending->requested_at->toIso8601String(),
            ],
            'rejected' => $rejected === null ? null : [
                'rejected_at' => $rejected->rejected_at?->toIso8601String(),
                'note' => $rejected->decision_note,
            ],
            'received_from' => $receivedFrom,
            'limits' => [
                'name_max' => self::NAME_MAX,
                'reason_min' => DelegationPolicy::reasonMin(),
                'reason_max' => DelegationPolicy::reasonMax(),
            ],
        ];
    }

    /**
     * Registra o pedido. Sem confirmação do remetente, a delegação vale na hora.
     *
     * @return array{status: string, to_email_masked: string, message: string}
     *
     * @throws DelegationException
     */
    public function request(SignerContext $context, Request $request, string $name, string $email, string $reason): array
    {
        if ($this->sessions->current($context, $request) === null) {
            throw new DelegationException('session_required', 'Sua sessão expirou. Confirme o código de novo antes de delegar.', 403);
        }

        $unavailable = $this->unavailableReason($context);

        if ($unavailable !== null) {
            throw self::unavailable($unavailable);
        }

        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        $email = mb_strtolower(trim($email));
        $reason = trim($reason);

        // Tentativas recusadas contam por participante (antes de qualquer resposta que revele
        // quem está no envelope): enumerar e-mails de coparticipantes esbarra neste teto.
        $attemptsKey = 'delegation-refused:'.$context->recipient->getKey();

        if (RateLimiter::tooManyAttempts($attemptsKey, DelegationPolicy::maxRefusedAttemptsPerRecipient())) {
            throw self::unavailable('recipient_limit');
        }

        // A mesma CAIXA DE CORREIO (maria+procuradora@… é a da maria@…) conta como "si mesmo".
        if (DelegationPolicy::mailboxKey($email) === DelegationPolicy::mailboxKey($context->recipient->email)) {
            RateLimiter::hit($attemptsKey, 86_400);

            throw new DelegationException('self', 'Informe o e-mail de outra pessoa: não é possível delegar para você mesmo.');
        }

        /** @var array{error: string}|array{delegation: Delegation, confirm: bool, envelope: Envelope} $outcome */
        $outcome = DB::transaction(function () use ($context, $request, $name, $email, $reason): array {
            /** @var Envelope|null $envelope */
            $envelope = Envelope::withoutOrganizationScope()->whereKey($context->envelope->getKey())->lockForUpdate()->first();
            /** @var Recipient|null $recipient */
            $recipient = Recipient::withoutOrganizationScope()->whereKey($context->recipient->getKey())->first();

            if ($envelope === null || $recipient === null || $envelope->status !== EnvelopeStatus::InProgress) {
                return ['error' => 'not_active'];
            }

            if (! $recipient->status->canBeDelegated()
                || SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $recipient->getKey())->exists()) {
                return ['error' => 'already_acted'];
            }

            if (DelegationPolicy::mailboxInEnvelope((int) $envelope->getKey(), $email)) {
                return ['error' => 'participant'];
            }

            $mine = Delegation::withoutOrganizationScope()->where('from_recipient_id', $recipient->getKey());

            if ((clone $mine)->where('status', Delegation::STATUS_PENDING)->exists()) {
                return ['error' => 'pending_exists'];
            }

            if ((clone $mine)->count() >= DelegationPolicy::maxRequestsPerRecipient()) {
                return ['error' => 'recipient_limit'];
            }

            // O limite diário é da ORGANIZAÇÃO: pedidos em envelopes diferentes só se serializam
            // travando a linha dela (o lock acima é só do envelope).
            Organization::query()->whereKey($envelope->organization_id)->lockForUpdate()->first();

            $today = Delegation::withoutOrganizationScope()
                ->where('organization_id', $envelope->organization_id)
                ->where('requested_at', '>=', Carbon::now()->subDay())
                ->count();

            if ($today >= DelegationPolicy::maxPerOrganizationPerDay()) {
                return ['error' => 'organization_limit'];
            }

            $confirm = DelegationPolicy::requiresConfirmation($envelope) || DelegationPolicy::hasStrongerAuthentication($recipient);

            $delegation = new Delegation;
            $delegation->forceFill([
                'organization_id' => $envelope->organization_id,
                'envelope_id' => $envelope->getKey(),
                'from_recipient_id' => $recipient->getKey(),
                'to_name' => mb_substr($name, 0, self::NAME_MAX),
                'to_email' => $email,
                'reason' => mb_substr($reason, 0, DelegationPolicy::reasonMax()),
                'status' => Delegation::STATUS_PENDING,
                'chain_depth' => DelegationPolicy::chainDepth($recipient) + 1,
                'requested_at' => Carbon::now(),
                'ip_address' => SubjectKeys::truncateIp(SignerRequestFacts::ip($request)),
                'user_agent' => SignerRequestFacts::userAgent($request),
            ])->save();

            if ($confirm) {
                SignerAudit::record($envelope, $recipient, AuditEventType::DelegationRequested, [
                    'delegation' => $delegation->ulid,
                    'to_masked' => Recipient::maskEmail($email),
                    'reason_length' => mb_strlen($reason),
                    'chain_depth' => $delegation->chain_depth,
                ]);
            }

            return ['delegation' => $delegation, 'confirm' => $confirm, 'envelope' => $envelope];
        });

        if (isset($outcome['error'])) {
            if ($outcome['error'] === 'participant') {
                RateLimiter::hit($attemptsKey, 86_400);
            }

            throw self::unavailable($outcome['error']);
        }

        /** @var Delegation $delegation */
        $delegation = $outcome['delegation'];
        $masked = Recipient::maskEmail($email);

        if ($outcome['confirm']) {
            /** @var Envelope $envelope */
            $envelope = $outcome['envelope'];
            $this->notifySender($envelope, $context->recipient);

            return [
                'status' => Delegation::STATUS_PENDING,
                'to_email_masked' => $masked,
                'message' => 'Pedido enviado a quem enviou o documento. Até a confirmação, a participação continua sendo sua.',
            ];
        }

        $this->executor->execute($delegation);

        return [
            'status' => Delegation::STATUS_EFFECTIVE,
            'to_email_masked' => $masked,
            'message' => sprintf('Pronto: o convite foi enviado para %s. Este link deixou de valer para você.', $masked),
        ];
    }

    /**
     * @return array{name: string, delegated_at: string|null}|null
     */
    private function receivedFrom(Recipient $recipient): ?array
    {
        $fromId = $recipient->getAttribute('delegated_from_recipient_id');

        if ($fromId === null) {
            return null;
        }

        /** @var Recipient|null $original */
        $original = Recipient::withoutOrganizationScope()->whereKey((int) $fromId)->first();

        /** @var Delegation|null $delegation */
        $delegation = Delegation::withoutOrganizationScope()
            ->where('to_recipient_id', $recipient->getKey())
            ->latest('id')
            ->first();

        return $original === null ? null : [
            'name' => $original->name,
            'delegated_at' => $delegation?->delegated_at?->toIso8601String(),
        ];
    }

    private function notifySender(Envelope $envelope, Recipient $from): void
    {
        try {
            $envelope->creator?->notify(new DelegationRequestedNotification($envelope, $from->name));
        } catch (Throwable $exception) {
            // O pedido já está gravado e aparece no detalhe do documento: um aviso que não sai
            // não desfaz nada. Sem e-mail, nome ou motivo no log.
            Log::warning('Pedido de delegação registrado sem aviso ao remetente.', [
                'envelope_id' => $envelope->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }

    public static function unavailable(string $code): DelegationException
    {
        return match ($code) {
            'policy_disabled' => new DelegationException($code, 'Este documento não permite delegação.', 404),
            'personal' => new DelegationException($code, 'Quem enviou marcou a sua participação como pessoal: ela não pode ser delegada.'),
            'chain_limit' => new DelegationException($code, 'Este documento chegou a você por delegação e não pode ser repassado de novo.'),
            'already_acted' => new DelegationException($code, 'Sua resposta já foi registrada: não é possível delegar depois de aceitar ou recusar.', 409),
            'participant' => new DelegationException($code, 'Esta pessoa já participa deste documento. Indique alguém que ainda não esteja nele.'),
            'pending_exists' => new DelegationException($code, 'Você já pediu para delegar este documento. Aguarde a resposta de quem enviou.', 409),
            'recipient_limit' => new DelegationException($code, 'O limite de pedidos de delegação para este documento foi atingido.', 429),
            'organization_limit' => new DelegationException($code, 'O limite diário de delegações foi atingido. Tente mais tarde ou fale com quem enviou.', 429),
            default => new DelegationException($code, 'Este documento não está disponível para delegação agora.', 409),
        };
    }
}
