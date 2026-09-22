<?php

namespace App\Integrations\Identity;

use App\Integrations\Contracts\IdentityVerificationProvider;
use App\Integrations\Dto\IdentityVerificationImage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Simulador IDENTIFICADO da verificação facial. **Nenhuma imagem é analisada nem sai da
 * máquina**: ele só confere que as imagens chegaram e devolve o resultado combinado.
 *
 * - Padrão: `approved`, sempre com `details.simulated = true` — a interface, a trilha e a página
 *   de evidências dizem "(simulado)" e que nenhuma comparação foi feita.
 * - {@see self::simulate()} força outro resultado nos testes (`pending`, `rejected`, …).
 * - Só responde com `assinavelox.channels.allow_simulated` ligado E fora de produção — o mesmo
 *   interruptor dos simuladores de SMS, WhatsApp e CPF. Fora disso: `inconclusive`.
 * - Log `[SIMULADO]` com tamanhos, nunca com imagem.
 */
final class FakeIdentityVerificationProvider implements IdentityVerificationProvider
{
    public const NAME = 'verificacao_simulada';

    private const NOTHING_COMPARED = 'Simulador: nenhuma imagem foi analisada e nenhum rosto foi comparado.';

    private const SWITCHED_OFF = 'Simulador de verificação desativado nesta instalação (assinavelox.channels.allow_simulated).';

    /** @var 'pending'|'approved'|'rejected'|'inconclusive'|'expired'|null */
    private ?string $forced = null;

    /** @var array<string, 'pending'|'approved'|'rejected'|'inconclusive'|'expired'> resultado por identificador, para `result()` */
    private array $issued = [];

    public function __construct(private readonly LoggerInterface $logger) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Simulador';
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return (bool) config('assinavelox.channels.allow_simulated', false)
            && ! app()->environment('production');
    }

    /**
     * @param  'pending'|'approved'|'rejected'|'inconclusive'|'expired'|null  $status  null volta ao padrão
     */
    public function simulate(?string $status): void
    {
        $this->forced = $status;
    }

    public function start(string $recipientUlid, array $options = [], ?string $correlationId = null): array
    {
        if (! $this->isConfigured()) {
            return $this->answer(null, 'inconclusive', 'not_configured', self::SWITCHED_OFF);
        }

        $images = array_filter(
            $options['images'] ?? [],
            static fn (mixed $image): bool => $image instanceof IdentityVerificationImage,
        );

        if (! isset($images['selfie'], $images['document_front'])) {
            return $this->answer(null, 'inconclusive', 'missing_images', 'Faltam imagens para a verificação simulada.');
        }

        $status = $this->forced ?? 'approved';
        $verificationId = 'sim-'.Str::lower((string) Str::ulid());
        $this->issued[$verificationId] = $status;

        $this->logger->warning('[SIMULADO] Verificação facial NÃO realizada — FakeIdentityVerificationProvider.', [
            'provider' => self::NAME,
            'reference' => $options['reference'] ?? null,
            'recipient' => $recipientUlid,
            'document_type' => $options['document_type'] ?? null,
            'images' => array_map(static fn (IdentityVerificationImage $image): int => $image->sizeBytes(), $images),
            'status' => $status,
            'correlation_id' => $correlationId,
        ]);

        return $this->answer($verificationId, $status, 'simulated', self::NOTHING_COMPARED);
    }

    public function result(string $verificationId, ?string $correlationId = null): array
    {
        if (! $this->isConfigured()) {
            return $this->answer($verificationId, 'inconclusive', 'not_configured', self::SWITCHED_OFF);
        }

        return $this->answer(
            $verificationId,
            $this->forced ?? $this->issued[$verificationId] ?? 'approved',
            'simulated',
            self::NOTHING_COMPARED,
        );
    }

    /**
     * @param  'pending'|'approved'|'rejected'|'inconclusive'|'expired'  $status
     * @return array{verification_id: string|null, provider: string, status: 'pending'|'approved'|'rejected'|'inconclusive'|'expired', checked_at: string, details: array<string, mixed>}
     */
    private function answer(?string $verificationId, string $status, string $reasonCode, string $message): array
    {
        return [
            'verification_id' => $verificationId,
            'provider' => self::NAME,
            'status' => $status,
            'checked_at' => Carbon::now()->toIso8601String(),
            'details' => [
                'simulated' => true,
                'reason_code' => $reasonCode,
                'message' => $message,
            ],
        ];
    }
}
