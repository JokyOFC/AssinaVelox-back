<?php

namespace App\Services\InPerson;

use App\Enums\AuditEventType;
use App\Enums\SigningSessionStatus;
use App\Models\AuthChallenge;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningSession;
use App\Services\InPerson\Models\InPersonSession;
use App\Services\InPerson\Models\InPersonTurn;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerSessions;
use App\Services\Signing\SignerTokens;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A vez de cada participante no dispositivo presencial (docs/fase-2/presencial-e-lote.md §2.3).
 *
 * ## Isolamento entre participantes — o que acontece em cada fronteira
 *
 * Toda troca de participante (novo participante chamado, aceite registrado, tela bloqueada,
 * sessão encerrada ou expirada) passa por {@see self::close()} ou {@see self::finish()}, que:
 *
 * 1. **revogam no banco** a sessão de assinatura da vez e toda sessão que ESTE navegador
 *    iniciou para o participante (inclusive a pendente do portão do PIN). Revogar no banco é
 *    o que vale contra uma cópia antiga do cookie: o token volta, o registro não aceita;
 * 2. **invalidam os códigos vivos** pedidos durante a vez. Sem isso, um código ainda válido
 *    reautenticaria a sessão pendente revogada (`Challenges::verify()` promove a sessão do
 *    desafio);
 * 3. **apagam todo o estado de signatário** da sessão Laravel do dispositivo (`signer.*`:
 *    sessões, portão do PIN, janela de download) e o segredo da vez, e trocam o id da sessão;
 * 4. **zeram o `turn_secret_digest`**: a vez encerrada nunca reabre, e o próximo participante
 *    recebe outra linha, outro segredo e — depois do PRÓPRIO código — outra sessão.
 *
 * O anfitrião não aparece em nada disso: ele abriu a sessão, não autentica nem aceita por
 * ninguém.
 */
final class InPersonTurns
{
    /** Segredo da vez aberta, na sessão Laravel do dispositivo. */
    public const TURN_KEY = 'in_person.turn';

    public function __construct(
        private readonly SignerSessions $sessions,
        private readonly ParticipantContexts $contexts,
    ) {}

    /**
     * Chama um participante: encerra a vez anterior e abre a dele.
     *
     * @throws SigningRejectedException
     */
    public function begin(InPersonSession $session, string $recipientUlid, Request $request): InPersonTurn
    {
        /** @var Recipient|null $recipient */
        $recipient = Recipient::withoutOrganizationScope()
            ->where('ulid', $recipientUlid)
            ->where('envelope_id', $session->envelope_id)
            ->where('organization_id', $session->organization_id)
            ->first();

        if ($recipient === null) {
            throw new SigningRejectedException('participant_not_found', 'Participante não encontrado nesta sessão presencial.', status: 404);
        }

        $this->closeActive($session, InPersonTurn::CLOSE_LOCKED, $request);

        $context = $this->contexts->for($recipient);

        if ($context === null || ! $context->isActive() || $context->action() === null) {
            throw SigningRejectedException::conflict(
                'participant_unavailable',
                sprintf(
                    '%s não tem aceite a registrar agora: já concluiu, ainda não é a vez ou o documento foi encerrado.',
                    ParticipantSigningProps::firstName($recipient->name),
                ),
            );
        }

        $raw = SignerTokens::generate();
        $now = Carbon::now();

        // A vez começa do zero: um código pedido antes dela (em outro aparelho, ou numa vez
        // anterior neste dispositivo) não serve aqui. O participante pede o dele agora.
        AuthChallenge::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->where('envelope_id', $recipient->envelope_id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => $now]);

        $turn = DB::transaction(function () use ($session, $recipient, $raw, $now): InPersonTurn {
            /** @var InPersonSession|null $locked */
            $locked = InPersonSession::withoutOrganizationScope()->whereKey($session->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isActive()) {
                throw SigningRejectedException::conflict('session_ended', 'A sessão presencial foi encerrada.');
            }

            /** @var InPersonTurn $turn */
            $turn = InPersonTurn::query()->create([
                'organization_id' => $locked->organization_id,
                'in_person_session_id' => $locked->getKey(),
                'envelope_id' => $locked->envelope_id,
                'recipient_id' => $recipient->getKey(),
                'turn_secret_digest' => SignerTokens::digest($raw),
                'status' => InPersonTurn::STATUS_ACTIVE,
                'started_at' => $now,
            ]);

            $locked->forceFill(['current_turn_id' => $turn->getKey(), 'last_activity_at' => $now])->save();

            return $turn;
        });

        $session->refresh();

        if ($request->hasSession()) {
            $request->session()->forget('signer');
            $request->session()->put(self::TURN_KEY, $raw);
            $request->session()->migrate(true);
        }

        SignerAudit::system($context->envelope, AuditEventType::InPersonParticipantStarted, [
            'in_person_session' => $session->ulid,
            'turn' => $turn->ulid,
        ], $context->recipient);

        return $turn;
    }

    /**
     * Vez aberta NESTE dispositivo: a da sessão presencial E com o segredo guardado aqui.
     */
    public function current(InPersonSession $session, Request $request): ?InPersonTurn
    {
        if ($session->current_turn_id === null || ! $request->hasSession()) {
            return null;
        }

        $raw = $request->session()->get(self::TURN_KEY);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        /** @var InPersonTurn|null $turn */
        $turn = InPersonTurn::withoutOrganizationScope()->whereKey($session->current_turn_id)->first();

        if ($turn === null
            || ! $turn->isActive()
            || $turn->in_person_session_id !== $session->getKey()
            || ! SignerTokens::matches($turn->turn_secret_digest, $raw)) {
            return null;
        }

        return $turn;
    }

    public function context(InPersonTurn $turn): ?SignerContext
    {
        /** @var Recipient|null $recipient */
        $recipient = Recipient::withoutOrganizationScope()->whereKey($turn->recipient_id)->first();

        if ($recipient === null
            || $recipient->envelope_id !== $turn->envelope_id
            || $recipient->organization_id !== $turn->organization_id) {
            return null;
        }

        return $this->contexts->for($recipient);
    }

    /**
     * Sessão de assinatura autenticada DESTE participante, NESTE dispositivo e DESTA vez.
     */
    public function signingSession(InPersonTurn $turn, SignerContext $context, Request $request): ?SigningSession
    {
        $session = $this->sessions->current($context, $request);

        if ($session === null || $session->authenticated_at === null) {
            return null;
        }

        if ($turn->signing_session_id !== null && $session->getKey() !== $turn->signing_session_id) {
            return null;
        }

        // Autenticada antes de a vez começar = não foi o código pedido nesta vez.
        if ($session->authenticated_at->lt($turn->started_at->copy()->startOfSecond())) {
            return null;
        }

        return $session;
    }

    public function markAuthenticated(InPersonTurn $turn, SigningSession $session): void
    {
        $turn->forceFill([
            'signing_session_id' => $session->getKey(),
            'authenticated_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Aceite registrado: a vez termina, a tela bloqueia e a evidência presencial é gravada.
     */
    public function finish(InPersonTurn $turn, InPersonSession $session, SignatureAcceptance $acceptance, SignerContext $context, Request $request): void
    {
        $now = Carbon::now();

        $turn->forceFill([
            'status' => InPersonTurn::STATUS_ACCEPTED,
            'close_reason' => InPersonTurn::CLOSE_ACCEPTED,
            'signature_acceptance_id' => $acceptance->getKey(),
            'finished_at' => $now,
            'turn_secret_digest' => null,
        ])->save();

        $session->forceFill(['current_turn_id' => null, 'last_activity_at' => $now])->save();

        $this->wipeDevice($request);

        // O ator é o PARTICIPANTE (é dele o aceite). O anfitrião entra só como quem abriu a
        // sessão e atestou a presença — nunca como autor.
        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::InPersonAcceptanceRecorded, [
            'acceptance_ulid' => $acceptance->ulid,
            'in_person_session' => $session->ulid,
            'turn' => $turn->ulid,
            'host_user_id' => $session->host_user_id,
            'device_label' => $session->device_label,
            'auth_method' => $acceptance->auth_method->value,
        ]);
    }

    /**
     * Encerra a vez aberta da sessão, se houver.
     */
    public function closeActive(InPersonSession $session, string $reason, ?Request $deviceRequest = null): ?InPersonTurn
    {
        if ($session->current_turn_id === null) {
            if ($deviceRequest !== null) {
                $this->wipeDevice($deviceRequest);
            }

            return null;
        }

        /** @var InPersonTurn|null $turn */
        $turn = InPersonTurn::withoutOrganizationScope()->whereKey($session->current_turn_id)->first();

        $session->forceFill(['current_turn_id' => null])->save();

        if ($turn === null || ! $turn->isActive()) {
            if ($deviceRequest !== null) {
                $this->wipeDevice($deviceRequest);
            }

            return null;
        }

        $this->close($turn, $reason, $deviceRequest);

        return $turn;
    }

    /**
     * Encerra uma vez SEM aceite (bloqueio, troca de participante, fim da sessão).
     *
     * `$deviceRequest` só quando a requisição vem do PRÓPRIO dispositivo presencial: é da
     * sessão Laravel dele que saem os tokens a revogar e o estado a apagar. Encerrada de outro
     * dispositivo (o anfitrião no computador dele), a revogação no banco já basta — o que
     * sobrar no dispositivo aponta para registros revogados.
     */
    public function close(InPersonTurn $turn, string $reason, ?Request $deviceRequest = null): void
    {
        $now = Carbon::now();

        // 1. A sessão de assinatura da vez, no banco.
        if ($turn->signing_session_id !== null) {
            SigningSession::withoutOrganizationScope()
                ->whereKey($turn->signing_session_id)
                ->whereIn('status', [SigningSessionStatus::PendingAuth->value, SigningSessionStatus::Authenticated->value])
                ->update([
                    'status' => SigningSessionStatus::Revoked->value,
                    'authorization_token_digest' => null,
                    'authorization_expires_at' => null,
                ]);
        }

        // 2. Toda sessão que ESTE navegador iniciou (código confirmado, portão do PIN).
        if ($deviceRequest !== null) {
            $this->revokeDeviceSessions($deviceRequest);
        }

        // 3. Códigos vivos pedidos durante a vez.
        AuthChallenge::withoutOrganizationScope()
            ->where('recipient_id', $turn->recipient_id)
            ->where('envelope_id', $turn->envelope_id)
            ->whereNull('consumed_at')
            ->where('created_at', '>=', $turn->started_at->copy()->startOfSecond())
            ->update(['consumed_at' => $now]);

        $turn->forceFill([
            'status' => InPersonTurn::STATUS_CLOSED,
            'close_reason' => $reason,
            'finished_at' => $now,
            'turn_secret_digest' => null,
        ])->save();

        if ($deviceRequest !== null) {
            $this->wipeDevice($deviceRequest);
        }

        /** @var Recipient|null $recipient */
        $recipient = Recipient::withoutOrganizationScope()->whereKey($turn->recipient_id)->first();
        $envelope = $turn->envelope()->first();

        if ($envelope !== null) {
            /** @var InPersonSession|null $session */
            $session = InPersonSession::withoutOrganizationScope()->whereKey($turn->in_person_session_id)->first();

            SignerAudit::system($envelope, AuditEventType::InPersonParticipantClosed, [
                'in_person_session' => $session?->ulid,
                'turn' => $turn->ulid,
                'reason' => $reason,
            ], $recipient);
        }
    }

    /**
     * Revoga, no banco, as sessões de assinatura cujos tokens estão na sessão deste navegador.
     */
    private function revokeDeviceSessions(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $state = $request->session()->get('signer', []);
        $state = is_array($state) ? $state : [];

        $targets = [
            'token_digest' => is_array($state['sessions'] ?? null) ? $state['sessions'] : [],
            'pin_gate_digest' => is_array($state['pin_gate'] ?? null) ? $state['pin_gate'] : [],
        ];

        foreach ($targets as $column => $tokens) {
            foreach ($tokens as $raw) {
                if (! is_string($raw) || $raw === '') {
                    continue;
                }

                SigningSession::withoutOrganizationScope()
                    ->where($column, SignerTokens::digest($raw))
                    ->whereIn('status', [SigningSessionStatus::PendingAuth->value, SigningSessionStatus::Authenticated->value])
                    ->update([
                        'status' => SigningSessionStatus::Revoked->value,
                        'authorization_token_digest' => null,
                        'authorization_expires_at' => null,
                    ]);
            }
        }
    }

    /**
     * Apaga do dispositivo todo o estado de signatário e o segredo da vez; troca o id da sessão.
     * O segredo do DISPOSITIVO fica (a sessão presencial continua).
     */
    public function wipeDevice(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->forget(['signer', self::TURN_KEY]);
        $request->session()->migrate(true);
    }
}
