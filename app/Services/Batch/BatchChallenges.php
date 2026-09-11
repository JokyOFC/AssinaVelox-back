<?php

namespace App\Services\Batch;

use App\Enums\AuditEventType;
use App\Enums\DeliveryStatus;
use App\Models\DeliveryAttempt;
use App\Models\Organization;
use App\Models\Recipient;
use App\Services\Batch\Models\BatchSigningChallenge;
use App\Services\Batch\Models\BatchSigningSession;
use App\Services\Batch\Notifications\BatchCodeNotification;
use App\Services\Signing\Challenges;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\SignerRequestFacts;
use App\Services\Signing\SignerTokens;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Código de uso único do lote (docs/fase-2/presencial-e-lote.md §3.3).
 *
 * As MESMAS garantias do código do fluxo individual ({@see Challenges}): 6 dígitos por
 * `random_int`, só o HMAC gravado (`Challenges::hashCode`, sal = ULID da linha), 10 minutos,
 * 5 tentativas, uso único, um código vivo por vez, intervalo de 60 s, 5 envios por hora por
 * link e 30 por hora por IP. O código vai SEMPRE por e-mail, para o endereço do participante
 * — o lote agrupa por e-mail, então é esse o canal cuja posse se prova. Semântica: o código
 * prova a posse da caixa de e-mail, não identidade.
 *
 * Itens cujo remetente pediu código por SMS/WhatsApp, PIN ou foto ficam FORA da autorização
 * em lote: pedem o fluxo individual, com a autenticação que o remetente escolheu
 * ({@see BatchItems::individualOnlyReason()}).
 */
final class BatchChallenges
{
    public function __construct(
        private readonly Repository $config,
        private readonly BatchBrowser $browser,
    ) {}

    /**
     * @throws SigningRejectedException
     */
    public function send(BatchSigningSession $batch, Request $request): BatchSigningChallenge
    {
        $anchor = $this->anchor($batch);

        foreach ($this->limiters($batch, $request) as [$key, $max, , $message]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw SigningRejectedException::rateLimited($message(RateLimiter::availableIn($key)), RateLimiter::availableIn($key));
            }
        }

        $ttl = $this->ttlMinutes();
        $expiresAt = Carbon::now()->addMinutes($ttl);
        $code = $this->generateCode();
        $correlationId = SignerTokens::correlationId();

        $challenge = DB::transaction(function () use ($batch, $expiresAt, $code): BatchSigningChallenge {
            BatchSigningChallenge::withoutOrganizationScope()
                ->where('batch_signing_session_id', $batch->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => Carbon::now()]);

            /** @var BatchSigningChallenge $challenge */
            $challenge = BatchSigningChallenge::query()->create([
                'batch_signing_session_id' => $batch->getKey(),
                'organization_id' => $batch->organization_id,
                'code_hash' => 'pending',
                'attempts' => 0,
                'max_attempts' => $this->maxAttempts(),
                'expires_at' => $expiresAt,
            ]);

            $challenge->forceFill(['code_hash' => Challenges::hashCode($challenge->ulid, $code)])->save();

            return $challenge;
        });

        /** @var Organization $organization */
        $organization = Organization::query()->whereKey($batch->organization_id)->firstOrFail();

        Notification::route('mail', $anchor->email)->notify(new BatchCodeNotification(
            organization: $organization,
            recipientName: $anchor->name,
            toAddress: $anchor->email,
            code: $code,
            ttlMinutes: $ttl,
            correlationId: $correlationId,
        ));

        /** @var DeliveryAttempt|null $attempt */
        $attempt = DeliveryAttempt::withoutOrganizationScope()
            ->where('correlation_id', $correlationId)
            ->latest('id')
            ->first();

        if ($attempt !== null) {
            $challenge->forceFill(['delivery_attempt_id' => $attempt->getKey()])->save();
        }

        foreach ($this->limiters($batch, $request) as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        BatchAudit::record($batch, AuditEventType::BatchChallengeSent, [
            'challenge' => $challenge->ulid,
            'channel' => 'email',
            'expires_at' => $expiresAt->toIso8601String(),
            'delivery_status' => ($attempt === null ? DeliveryStatus::Unknown : $attempt->status)->value,
        ], $correlationId);

        return $challenge;
    }

    /**
     * Confere o código e, se bater, autentica o lote neste navegador.
     *
     * @throws SigningRejectedException
     */
    public function verify(BatchSigningSession $batch, Request $request, string $code): BatchSigningChallenge
    {
        $this->anchor($batch);

        $challenge = $this->latest($batch);

        if ($challenge === null || ! $challenge->isVerifiable()) {
            BatchAudit::record($batch, AuditEventType::BatchChallengeFailed, ['reason' => 'no_live_challenge']);

            throw new SigningRejectedException('no_challenge', 'O código expirou ou já foi usado. Peça um novo código.');
        }

        if (! hash_equals($challenge->code_hash, Challenges::hashCode($challenge->ulid, $code))) {
            $challenge->forceFill(['attempts' => $challenge->attempts + 1])->save();
            $left = max(0, $challenge->max_attempts - $challenge->attempts);

            if ($left === 0) {
                $challenge->forceFill(['consumed_at' => Carbon::now()])->save();

                BatchAudit::record($batch, AuditEventType::BatchChallengeFailed, [
                    'challenge' => $challenge->ulid,
                    'reason' => 'attempts_exhausted',
                ]);

                throw new SigningRejectedException('attempts_exhausted', 'Código inválido. As tentativas acabaram — peça um novo código.');
            }

            BatchAudit::record($batch, AuditEventType::BatchChallengeFailed, [
                'challenge' => $challenge->ulid,
                'reason' => 'invalid_code',
                'attempts_used' => $challenge->attempts,
            ]);

            throw new SigningRejectedException(
                'invalid_code',
                sprintf('Código inválido ou expirado (%d %s restantes).', $left, $left === 1 ? 'tentativa' : 'tentativas'),
                context: ['attempts_left' => $left],
            );
        }

        $challenge->forceFill(['consumed_at' => Carbon::now()])->save();

        $this->browser->authenticate($batch, $request);

        BatchAudit::record($batch, AuditEventType::BatchChallengeVerified, [
            'challenge' => $challenge->ulid,
            'attempts_used' => $challenge->attempts,
            'session_expires_at' => $batch->refresh()->session_expires_at?->toIso8601String(),
        ]);

        return $challenge;
    }

    public function latest(BatchSigningSession $batch): ?BatchSigningChallenge
    {
        /** @var BatchSigningChallenge|null */
        return BatchSigningChallenge::withoutOrganizationScope()
            ->where('batch_signing_session_id', $batch->getKey())
            ->latest('id')
            ->first();
    }

    /**
     * Último código confirmado (a evidência de cada item aponta para ele).
     */
    public function lastVerified(BatchSigningSession $batch): ?BatchSigningChallenge
    {
        /** @var BatchSigningChallenge|null */
        return BatchSigningChallenge::withoutOrganizationScope()
            ->where('batch_signing_session_id', $batch->getKey())
            ->whereNotNull('consumed_at')
            ->whereColumn('attempts', '<', 'max_attempts')
            ->latest('id')
            ->first();
    }

    /**
     * @return array{sent_at: string|null, expires_at: string|null, resend_available_at: string|null, attempts_left: int}
     */
    public function props(BatchSigningSession $batch): array
    {
        $challenge = $this->latest($batch);
        $intervalKey = 'batch:otp:interval:'.$batch->ulid;
        $availableIn = RateLimiter::tooManyAttempts($intervalKey, 1) ? RateLimiter::availableIn($intervalKey) : 0;
        $resend = $availableIn > 0 ? Carbon::now()->addSeconds($availableIn)->toIso8601String() : null;

        if ($challenge === null) {
            return ['sent_at' => null, 'expires_at' => null, 'resend_available_at' => $resend, 'attempts_left' => $this->maxAttempts()];
        }

        $live = $challenge->isVerifiable();

        return [
            'sent_at' => $challenge->created_at?->toIso8601String(),
            'expires_at' => $live ? $challenge->expires_at->toIso8601String() : null,
            'resend_available_at' => $resend,
            'attempts_left' => $live ? max(0, $challenge->max_attempts - $challenge->attempts) : 0,
        ];
    }

    /**
     * @throws SigningRejectedException
     */
    private function anchor(BatchSigningSession $batch): Recipient
    {
        /** @var Recipient|null $anchor */
        $anchor = $batch->anchor_recipient_id === null
            ? null
            : Recipient::withoutOrganizationScope()->whereKey($batch->anchor_recipient_id)->first();

        if ($anchor === null || ! $batch->isUsable()) {
            throw SigningRejectedException::conflict('batch_unavailable', 'Este link de lote não vale mais. Peça um novo a quem enviou os documentos.');
        }

        return $anchor;
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: callable(int): string}>
     */
    private function limiters(BatchSigningSession $batch, Request $request): array
    {
        $ip = SignerRequestFacts::ip($request) ?? 'unknown';

        return [
            [
                'batch:otp:interval:'.$batch->ulid,
                1,
                max(1, (int) $this->config->get('assinavelox.otp.resend_interval_seconds', 60)),
                fn (int $seconds): string => sprintf('Aguarde %ds para reenviar o código.', max(1, $seconds)),
            ],
            [
                'batch:otp:hour:'.$batch->ulid,
                max(1, (int) $this->config->get('assinavelox.otp.resend_limit', 5)),
                3600,
                fn (): string => 'Você pediu o código muitas vezes. Tente novamente daqui a pouco.',
            ],
            [
                'batch:otp:ip:'.sha1($ip),
                max(1, (int) $this->config->get('assinavelox.otp.ip_hourly_limit', 30)),
                3600,
                fn (): string => 'Muitos pedidos de código a partir desta conexão. Tente novamente daqui a pouco.',
            ],
        ];
    }

    private function generateCode(): string
    {
        $length = max(4, (int) $this->config->get('assinavelox.otp.code_length', 6));

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) $this->config->get('assinavelox.otp.ttl_minutes', 10));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) $this->config->get('assinavelox.otp.max_attempts', 5));
    }
}
