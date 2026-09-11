<?php

namespace App\Services\Signing\Channels;

use App\Enums\AuditEventType;
use App\Enums\SigningSessionStatus;
use App\Models\Recipient;
use App\Models\RecipientPin;
use App\Models\SigningSession;
use App\Models\User;
use App\Services\Signing\Challenges;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerSessions;
use App\Services\Signing\SignerTokens;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PIN do remetente (Fase 2 §2.9, flag `pin_auth`; docs/fase-2/canais-e-pin.md §4).
 *
 * ## O que é, e o que não é
 *
 * Um segredo COMPARTILHADO: o remetente define o PIN no wizard e o combina com o participante
 * por fora do sistema. Prova que quem está no navegador conhece esse segredo — não prova
 * identidade. É autenticação ADICIONAL: vem depois do código do canal e nunca o substitui.
 *
 * ## Guarda
 *
 * `recipient_pins.pin_hash = password_hash(HMAC-SHA256("{recipient.ulid}|{pin}", segredo))`,
 * com o segredo derivado da APP_KEY. O HMAC (a "pimenta") impede que um vazamento só do banco
 * permita testar os 10⁴–10⁸ PINs offline; o password_hash impede comparação direta. O PIN em
 * claro não é gravado, não entra em evento, log, exceção nem resposta.
 *
 * ## Fluxo
 *
 * 1. Código do canal confirmado → a sessão continua `pending_auth` e ganha um portão
 *    (`channel_verified_at` + `pin_gate_digest`, token bruto só na sessão Laravel). O portão
 *    vale a validade do código (10 min).
 * 2. PIN certo → `authenticated` (SessionStarted na trilha). Errado → conta tentativa.
 * 3. `pin.max_attempts` erros → bloqueio temporário (`pin.lockout_minutes`), o portão é
 *    fechado (é preciso pedir outro código) e `lockouts` sobe. `pin.max_lockouts` bloqueios
 *    seguidos → PIN bloqueado de vez (`blocked_at`): só o remetente redefine.
 *
 * Os contadores são por participante (e, portanto, por link: um reenvio de convite não zera).
 */
final class SenderPins
{
    public const GATE_SESSION_PREFIX = 'signer.pin_gate.';

    public function __construct(private readonly SignerSessions $sessions) {}

    public static function minLength(): int
    {
        return max(4, (int) config('assinavelox.pin.min_length', 4));
    }

    public static function maxLength(): int
    {
        return max(self::minLength(), (int) config('assinavelox.pin.max_length', 8));
    }

    // -- Remetente --------------------------------------------------------------------

    public function recordFor(Recipient $recipient): ?RecipientPin
    {
        /** @var RecipientPin|null */
        return RecipientPin::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->first();
    }

    public function requiredFor(Recipient $recipient): bool
    {
        return RecipientPin::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->exists();
    }

    public function set(Recipient $recipient, string $pin, ?User $by = null): RecipientPin
    {
        $record = $this->recordFor($recipient) ?? new RecipientPin;

        $record->forceFill([
            'recipient_id' => $recipient->getKey(),
            'envelope_id' => $recipient->envelope_id,
            'organization_id' => $recipient->organization_id,
            'pin_hash' => self::hash($recipient->ulid, $pin),
            'failed_attempts' => 0,
            'lockouts' => 0,
            'locked_until' => null,
            'blocked_at' => null,
            'last_failed_at' => null,
            'verified_at' => null,
            'set_by_user_id' => $by?->getKey(),
        ])->save();

        return $record;
    }

    public function remove(Recipient $recipient): bool
    {
        return RecipientPin::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->delete() > 0;
    }

    /**
     * Formato aceito: só dígitos, entre o mínimo e o máximo configurados.
     */
    public static function isWellFormed(string $pin): bool
    {
        return preg_match('/^\d{'.self::minLength().','.self::maxLength().'}$/', $pin) === 1;
    }

    /**
     * PIN previsível: um dígito repetido (0000) ou sequência (1234, 9876).
     */
    public static function isWeak(string $pin): bool
    {
        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }

        $digits = array_map('intval', str_split($pin));
        $ascending = true;
        $descending = true;

        for ($i = 1, $n = count($digits); $i < $n; $i++) {
            $ascending = $ascending && $digits[$i] === ($digits[$i - 1] + 1) % 10;
            $descending = $descending && $digits[$i] === ($digits[$i - 1] + 9) % 10;
        }

        return $ascending || $descending;
    }

    public static function hash(string $recipientUlid, string $pin): string
    {
        return password_hash(self::pepper($recipientUlid, $pin), PASSWORD_DEFAULT);
    }

    public static function check(RecipientPin $record, string $recipientUlid, string $pin): bool
    {
        return password_verify(self::pepper($recipientUlid, $pin), $record->pin_hash);
    }

    private static function pepper(string $recipientUlid, string $pin): string
    {
        return hash_hmac('sha256', $recipientUlid.'|'.$pin, hash('sha256', 'assinavelox:sender-pin:v1|'.(string) config('app.key'), true));
    }

    // -- Portão (código do canal já confirmado) ---------------------------------------

    public function openGate(SigningSession $session, SignerContext $context, Request $request): void
    {
        $raw = SignerTokens::generate();

        $session->forceFill([
            'channel_verified_at' => Carbon::now(),
            'pin_gate_digest' => SignerTokens::digest($raw),
            'last_seen_at' => Carbon::now(),
        ])->save();

        if ($request->hasSession()) {
            $request->session()->put(self::GATE_SESSION_PREFIX.$context->recipient->ulid, $raw);
        }
    }

    /**
     * Sessão `pending_auth` DESTE participante neste navegador com o código do canal
     * confirmado há menos da validade do código.
     */
    public function pendingGate(SignerContext $context, Request $request): ?SigningSession
    {
        if (! $request->hasSession()) {
            return null;
        }

        $raw = $request->session()->get(self::GATE_SESSION_PREFIX.$context->recipient->ulid);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        /** @var SigningSession|null $session */
        $session = SigningSession::withoutOrganizationScope()
            ->where('pin_gate_digest', SignerTokens::digest($raw))
            ->first();

        if ($session === null
            || $session->recipient_id !== $context->recipient->getKey()
            || $session->envelope_id !== $context->envelope->getKey()
            || $session->status !== SigningSessionStatus::PendingAuth
            || $session->isExpired()) {
            return null;
        }

        $verifiedAt = $session->getAttribute('channel_verified_at');

        if ($verifiedAt === null) {
            return null;
        }

        $ttl = max(1, (int) config('assinavelox.otp.ttl_minutes', 10));

        if (Carbon::parse($verifiedAt)->addMinutes($ttl)->isPast()) {
            return null;
        }

        return $session;
    }

    private function closeGate(?SigningSession $session, SignerContext $context, Request $request): void
    {
        $session?->forceFill(['pin_gate_digest' => null])->save();

        if ($request->hasSession()) {
            $request->session()->forget(self::GATE_SESSION_PREFIX.$context->recipient->ulid);
        }
    }

    // -- Verificação ------------------------------------------------------------------

    /**
     * Confere o PIN e, se bater, autentica a sessão.
     *
     * @throws SigningRejectedException com `errorCode`: not_signable, pin_not_required,
     *                                  pin_blocked, pin_locked, pin_gate_missing, invalid_pin
     */
    public function verify(SignerContext $context, Request $request, string $pin): SigningSession
    {
        if (! $context->isActive() && ! Challenges::reopensCertificateStep($context)) {
            throw SigningRejectedException::conflict('not_signable', 'Este documento não está mais disponível para assinatura.');
        }

        $record = $this->recordFor($context->recipient);

        if ($record === null) {
            throw new SigningRejectedException('pin_not_required', 'Este documento não pede PIN.');
        }

        if ($record->isBlocked()) {
            $this->recordFailure($context, 'blocked', 0);

            throw new SigningRejectedException('pin_blocked', 'O PIN foi bloqueado depois de tentativas demais. Fale com quem enviou o documento.');
        }

        if ($record->isLocked()) {
            $seconds = max(1, (int) Carbon::now()->diffInSeconds($record->locked_until, true));

            throw new SigningRejectedException(
                'pin_locked',
                sprintf('Muitas tentativas de PIN. Aguarde %d min e peça um novo código.', (int) ceil($seconds / 60)),
                $seconds,
                429,
            );
        }

        $session = $this->pendingGate($context, $request);

        if ($session === null) {
            $this->recordFailure($context, 'gate_missing', null);

            throw new SigningRejectedException(
                'pin_gate_missing',
                'Confirme primeiro o código enviado a você e, em seguida, informe o PIN.',
            );
        }

        $maxAttempts = max(1, (int) config('assinavelox.pin.max_attempts', 5));
        $maxLockouts = max(1, (int) config('assinavelox.pin.max_lockouts', 3));
        $lockoutMinutes = max(1, (int) config('assinavelox.pin.lockout_minutes', 15));
        $recipientUlid = $context->recipient->ulid;

        // Os contadores mudam sob lock: duas tentativas simultâneas não gastam uma só.
        // O bloqueio é conferido DE NOVO na linha travada: as checagens acima leram a linha sem
        // lock, e uma tentativa concorrente pode ter esgotado o limite enquanto esta esperava na
        // fila. Sem isso, N requisições simultâneas testariam N PINs além do teto.
        /** @var array{state: string, left: int, seconds?: int} $outcome */
        $outcome = DB::transaction(function () use ($record, $pin, $recipientUlid, $maxAttempts, $maxLockouts, $lockoutMinutes): array {
            /** @var RecipientPin $locked */
            $locked = RecipientPin::withoutOrganizationScope()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isBlocked()) {
                return ['state' => 'already_blocked', 'left' => 0];
            }

            if ($locked->isLocked()) {
                return [
                    'state' => 'already_locked',
                    'left' => 0,
                    'seconds' => max(1, (int) Carbon::now()->diffInSeconds($locked->locked_until, true)),
                ];
            }

            if (self::check($locked, $recipientUlid, $pin)) {
                $locked->forceFill([
                    'failed_attempts' => 0,
                    'lockouts' => 0,
                    'locked_until' => null,
                    'verified_at' => Carbon::now(),
                ])->save();

                return ['state' => 'ok', 'left' => $maxAttempts];
            }

            $failed = $locked->failed_attempts + 1;

            if ($failed < $maxAttempts) {
                $locked->forceFill(['failed_attempts' => $failed, 'last_failed_at' => Carbon::now()])->save();

                return ['state' => 'invalid', 'left' => $maxAttempts - $failed];
            }

            $lockouts = $locked->lockouts + 1;
            $blocked = $lockouts >= $maxLockouts;

            $locked->forceFill([
                'failed_attempts' => 0,
                'lockouts' => $lockouts,
                'last_failed_at' => Carbon::now(),
                'locked_until' => $blocked ? null : Carbon::now()->addMinutes($lockoutMinutes),
                'blocked_at' => $blocked ? Carbon::now() : null,
            ])->save();

            return ['state' => $blocked ? 'blocked' : 'locked', 'left' => 0];
        });

        if ($outcome['state'] === 'ok') {
            $this->closeGate($session, $context, $request);
            $correlationId = SignerTokens::correlationId();

            // Fase 2 §2.12: quem já aceitou e voltou para enviar o certificado — o PIN só
            // reabre a janela de download; nenhuma sessão de assinatura é autenticada.
            if (! $context->isActive()) {
                SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ChallengePinVerified, [
                    'session_ulid' => $session->ulid,
                    'purpose' => 'participant_certificate',
                ], $correlationId);

                return $session;
            }

            $this->sessions->authenticate($session, $context, $request);

            SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ChallengePinVerified, [
                'session_ulid' => $session->ulid,
            ], $correlationId);

            SignerAudit::record($context->envelope, $context->recipient, AuditEventType::SessionStarted, [
                'session_ulid' => $session->ulid,
                'auth_method' => $context->recipient->auth_method->value,
                'sender_pin' => true,
                'expires_at' => $session->refresh()->expires_at->toIso8601String(),
            ], $correlationId);

            return $session;
        }

        if ($outcome['state'] === 'already_blocked') {
            $this->closeGate($session, $context, $request);
            $this->recordFailure($context, 'blocked', 0);

            throw new SigningRejectedException('pin_blocked', 'O PIN foi bloqueado depois de tentativas demais. Fale com quem enviou o documento.');
        }

        if ($outcome['state'] === 'already_locked') {
            $seconds = $outcome['seconds'] ?? $lockoutMinutes * 60;
            $this->closeGate($session, $context, $request);

            throw new SigningRejectedException(
                'pin_locked',
                sprintf('Muitas tentativas de PIN. Aguarde %d min e peça um novo código.', (int) ceil($seconds / 60)),
                $seconds,
                429,
            );
        }

        if ($outcome['state'] === 'invalid') {
            $this->recordFailure($context, 'invalid_pin', $outcome['left']);

            throw new SigningRejectedException(
                'invalid_pin',
                sprintf('PIN incorreto (%d %s restantes).', $outcome['left'], $outcome['left'] === 1 ? 'tentativa' : 'tentativas'),
                context: ['attempts_left' => $outcome['left']],
            );
        }

        // Bloqueio: o portão fecha — voltar exige outro código do canal.
        $this->closeGate($session, $context, $request);
        $this->recordFailure($context, $outcome['state'] === 'blocked' ? 'blocked' : 'locked', 0);

        if ($outcome['state'] === 'blocked') {
            throw new SigningRejectedException('pin_blocked', 'PIN incorreto. O PIN foi bloqueado depois de tentativas demais — fale com quem enviou o documento.');
        }

        throw new SigningRejectedException(
            'pin_locked',
            sprintf('PIN incorreto. Por segurança, novas tentativas ficam bloqueadas por %d min; depois disso, peça um novo código.', $lockoutMinutes),
            $lockoutMinutes * 60,
            429,
        );
    }

    private function recordFailure(SignerContext $context, string $reason, ?int $attemptsLeft): void
    {
        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ChallengePinFailed, array_filter([
            'reason' => $reason,
            'attempts_left' => $attemptsLeft,
        ], fn ($value) => $value !== null));
    }

    // -- Props ------------------------------------------------------------------------

    /**
     * Estado do PIN para a página pública (null = este participante não tem PIN).
     *
     * @return array{required: bool, step_active: bool, min_length: int, max_length: int, attempts_left: int, locked_until: string|null, blocked: bool}|null
     */
    public function props(SignerContext $context, Request $request): ?array
    {
        $record = $this->recordFor($context->recipient);

        if ($record === null) {
            return null;
        }

        $max = max(1, (int) config('assinavelox.pin.max_attempts', 5));

        return [
            'required' => true,
            'step_active' => ! $record->isBlocked() && ! $record->isLocked() && $this->pendingGate($context, $request) !== null,
            'min_length' => self::minLength(),
            'max_length' => self::maxLength(),
            'attempts_left' => max(0, $max - $record->failed_attempts),
            'locked_until' => $record->isLocked() ? $record->locked_until?->toIso8601String() : null,
            'blocked' => $record->isBlocked(),
        ];
    }
}
