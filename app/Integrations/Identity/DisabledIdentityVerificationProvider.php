<?php

namespace App\Integrations\Identity;

use App\Integrations\Contracts\IdentityVerificationProvider;
use App\Integrations\Identity\Verifiky\VerifikyIdentityVerificationProvider;
use Illuminate\Support\Carbon;

/**
 * Verificação facial DESLIGADA (`assinavelox.identity_verification.driver = disabled`, o padrão).
 * Nenhuma chamada é feita: toda resposta é "inconclusivo — não configurado", com a lista do
 * que falta o proprietário entregar. Inconclusivo nunca libera o aceite (T5).
 */
final class DisabledIdentityVerificationProvider implements IdentityVerificationProvider
{
    public const NAME = 'disabled';

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Não configurado';
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function start(string $recipientUlid, array $options = [], ?string $correlationId = null): array
    {
        return $this->notConfigured(null);
    }

    public function result(string $verificationId, ?string $correlationId = null): array
    {
        return $this->notConfigured($verificationId);
    }

    /**
     * @return array{verification_id: string|null, provider: string, status: 'inconclusive', checked_at: string, details: array<string, mixed>}
     */
    private function notConfigured(?string $verificationId): array
    {
        return [
            'verification_id' => $verificationId,
            'provider' => self::NAME,
            'status' => 'inconclusive',
            'checked_at' => Carbon::now()->toIso8601String(),
            'details' => [
                'reason_code' => 'not_configured',
                'message' => 'A verificação facial não está configurada nesta instalação.',
                'missing' => VerifikyIdentityVerificationProvider::MISSING,
            ],
        ];
    }
}
