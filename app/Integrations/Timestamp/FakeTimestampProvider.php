<?php

namespace App\Integrations\Timestamp;

use App\Integrations\Contracts\Exceptions\ProviderDisabledException;
use App\Integrations\Contracts\TimestampProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Simulador IDENTIFICADO de carimbo do tempo (contrato reservado, roadmap §2.13/§3.6).
 *
 * - `tsa_kind` é sempre `simulated` e `simulated` é sempre true: nunca `icp_brasil`,
 *   nunca `operator` (regra T3);
 * - o "token" é um JSON em base64 com o aviso de que não é RFC 3161 — não é DER, não
 *   passa em `openssl ts -verify` e não pode ser embutido num PDF como carimbo;
 * - só funciona com `assinavelox.channels.allow_simulated` (fora de produção).
 */
final class FakeTimestampProvider implements TimestampProvider
{
    public const NAME = 'tsa_simulada';

    private const DIGEST_LENGTHS = ['sha256' => 64, 'sha384' => 96, 'sha512' => 128];

    public function __construct(private readonly Repository $config) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return (bool) $this->config->get('assinavelox.channels.allow_simulated', false);
    }

    public function timestamp(string $digestHex, string $hashAlgorithm = 'sha256', ?string $correlationId = null): array
    {
        if (! $this->isConfigured()) {
            throw new ProviderDisabledException(self::NAME, [
                'O simulador de carimbo do tempo está desativado nesta instalação (fora de produção apenas).',
                'Carimbo real: TSA própria (roadmap §2.13) ou ACT credenciada pelo ITI (roadmap §3.6).',
            ]);
        }

        $algorithm = strtolower($hashAlgorithm);
        $expected = self::DIGEST_LENGTHS[$algorithm] ?? null;

        if ($expected === null || strlen($digestHex) !== $expected || ! ctype_xdigit($digestHex)) {
            throw new InvalidArgumentException('Hash inválido para o algoritmo informado.');
        }

        $serial = 'SIMULADO-'.(string) Str::ulid();
        $genTime = Carbon::now('UTC')->toIso8601ZuluString();

        Log::warning('[SIMULADO] Carimbo do tempo simulado — FakeTimestampProvider (não é RFC 3161; sem valor jurídico).', [
            'provider' => self::NAME,
            'hash_algorithm' => $algorithm,
            'serial' => $serial,
            'correlation_id' => $correlationId,
        ]);

        return [
            'token_der_base64' => base64_encode((string) json_encode([
                'simulated' => true,
                'warning' => 'NÃO é um token RFC 3161: carimbo simulado, sem valor jurídico.',
                'digest' => strtolower($digestHex),
                'hash_algorithm' => $algorithm,
                'serial' => $serial,
                'gen_time' => $genTime,
            ], JSON_UNESCAPED_UNICODE)),
            'tsa' => 'TSA simulada do AssinaVelox — sem valor jurídico',
            'genTime' => $genTime,
            'serial' => $serial,
            'hash_algorithm' => $algorithm,
            'tsa_kind' => 'simulated',
            'simulated' => true,
        ];
    }
}
