<?php

namespace App\Services\Identity\Jobs;

use App\Services\Identity\IdentityVerifications;
use App\Services\Identity\Models\IdentityVerification;
use App\Services\Identity\VerificationStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envio de uma tentativa de verificação facial com documento ao provedor (Fase 4 §4.1), fila
 * `identity_verification.queue` (padrão `default`).
 *
 * Só o id da tentativa viaja na fila: as imagens são lidas do disco cifrado dentro do
 * `handle()`, vivem na memória do job e morrem com ele — nunca no payload, no log ou no banco.
 *
 * Uma tentativa só (`$tries = 1`): repetir o envio criaria uma segunda análise no provedor
 * com o mesmo `reference`. Falhas viram `inconclusive` dentro do serviço; o `failed()` cobre o
 * que restou (tempo esgotado do worker), para a linha nunca ficar presa em `queued`. O timeout
 * é o do provedor mais folga, com teto de 600 s — abaixo do `retry_after` (900 s) da conexão.
 */
class SubmitIdentityVerification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $verificationId)
    {
        $this->onQueue((string) config('assinavelox.identity_verification.queue', 'default'));
        $this->timeout = self::timeoutSeconds();
    }

    /** Timeout do provedor (`verifiky.timeout`) + 60 s de folga, entre 90 s e 600 s. */
    public static function timeoutSeconds(): int
    {
        return max(90, min(600, (int) config('assinavelox.identity_verification.verifiky.timeout', 180) + 60));
    }

    public function handle(IdentityVerifications $verifications): void
    {
        $verifications->process($this->verificationId);
    }

    /**
     * O worker morreu no meio (tempo esgotado, processo derrubado): a tentativa que ainda está
     * `queued` vira inconclusiva, sem consumir tentativa, e o participante pode enviar de novo.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Job de verificação facial falhou.', [
            'verification_id' => $this->verificationId,
            'exception' => $exception?->getMessage(),
        ]);

        try {
            /** @var IdentityVerification|null $verification */
            $verification = IdentityVerification::withoutOrganizationScope()->whereKey($this->verificationId)->first();

            if ($verification === null || $verification->status !== VerificationStatus::Queued) {
                return;
            }

            app(IdentityVerifications::class)->applyResult($verification, [
                'verification_id' => null,
                'provider' => $verification->provider,
                'status' => VerificationStatus::Inconclusive->value,
                'checked_at' => now()->toIso8601String(),
                'details' => [
                    'reason_code' => 'provider_error',
                    'message' => 'O envio ao provedor de verificação não foi concluído. Isso não é uma reprovação: envie as fotos de novo.',
                ],
            ], 'submit');
        } catch (Throwable $inner) {
            Log::error('Não foi possível marcar a verificação facial como inconclusiva.', [
                'verification_id' => $this->verificationId,
                'exception' => $inner::class,
            ]);
        }
    }
}
