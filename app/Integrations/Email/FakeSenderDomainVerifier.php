<?php

namespace App\Integrations\Email;

use App\Integrations\Contracts\Exceptions\ProviderDisabledException;
use App\Integrations\Contracts\SenderDomainVerifier;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Simulador IDENTIFICADO da verificação de domínio de envio (Fase 2 §2.8, classe B).
 *
 * Os registros DNS devolvidos apontam para `.invalid` (TLD reservado, RFC 2606) e têm
 * "simulado" no nome: não servem para configurar DNS de verdade. A situação é `pending`
 * até um teste chamar {@see self::simulate()}. Um domínio "verificado" aqui fica com
 * `sender_domains.is_simulated = true` e NUNCA vira remetente.
 */
final class FakeSenderDomainVerifier implements SenderDomainVerifier
{
    public const NAME = 'email_dominio_simulado';

    /** @var array<string, array{status: 'pending'|'verified'|'failed', reason: string|null}> */
    private array $simulated = [];

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

    public function missingRequirements(): array
    {
        return $this->isConfigured() ? [] : [
            'O simulador de domínio de envio está desativado nesta instalação (fora de produção apenas).',
        ];
    }

    /**
     * @param  'pending'|'verified'|'failed'  $status
     */
    public function simulate(string $domain, string $status, ?string $reason = null): void
    {
        $this->simulated[strtolower($domain)] = ['status' => $status, 'reason' => $reason];
    }

    public function register(string $domain, string $verificationToken, ?string $correlationId = null): array
    {
        $this->assertConfigured();

        Log::warning('[SIMULADO] Domínio de envio NÃO cadastrado em provedor — FakeSenderDomainVerifier.', [
            'provider' => self::NAME,
            'domain' => $domain,
            'correlation_id' => $correlationId,
        ]);

        return [
            'provider_domain_id' => 'sim-'.Str::lower((string) Str::ulid()),
            'records' => [
                ['type' => 'TXT', 'host' => '_assinavelox-simulado.'.$domain, 'value' => 'assinavelox-simulado='.$verificationToken, 'purpose' => 'Posse do domínio (simulado)'],
                ['type' => 'CNAME', 'host' => 'simulado._domainkey.'.$domain, 'value' => 'dkim.simulado.invalid', 'purpose' => 'DKIM (simulado: o registro real vem do serviço de e-mail)'],
                ['type' => 'TXT', 'host' => $domain, 'value' => 'v=spf1 include:spf.simulado.invalid ~all', 'purpose' => 'SPF (simulado: o registro real vem do serviço de e-mail)'],
            ],
        ];
    }

    public function check(string $domain, ?string $providerDomainId, ?string $correlationId = null): array
    {
        $this->assertConfigured();

        $state = $this->simulated[strtolower($domain)] ?? ['status' => 'pending', 'reason' => null];

        return [
            'status' => $state['status'],
            'checked_at' => Carbon::now()->toIso8601String(),
            'reason' => $state['reason'] ?? ($state['status'] === 'pending' ? 'Simulador: nenhum DNS foi consultado.' : null),
        ];
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new ProviderDisabledException(self::NAME, $this->missingRequirements());
        }
    }
}
