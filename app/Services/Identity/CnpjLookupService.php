<?php

namespace App\Services\Identity;

use App\Integrations\Cnpj\CnpjLookupFactory;
use App\Integrations\Cnpj\CnpjLookupRecord;
use App\Integrations\Cnpj\CnpjLookupUnavailable;
use App\Integrations\Cnpj\FakeCnpjLookup;
use App\Support\Correlation;
use App\Support\TaxId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Psr\Log\LoggerInterface;

/**
 * Autopreenchimento por CNPJ (docs/fase-2/identidade.md §3): razão social e nome fantasia
 * nas configurações da organização e no cadastro.
 *
 * Ordem: dígitos (inválido → nenhuma chamada) → limite por usuário/IP → cache
 * `cnpj_lookups` válido → adaptador → cache. **Nada aqui bloqueia o formulário**: todo
 * desfecho devolve `manual_fill = true` e uma mensagem; indisponível é só "preencha à mão".
 *
 * Uma consulta por ação humana, sem varredura (pedido da fonte pública).
 */
final class CnpjLookupService
{
    public function __construct(
        private readonly CnpjLookupFactory $providers,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{status: 'found'|'not_found'|'invalid'|'unavailable'|'rate_limited', cnpj: string|null, data: array<string, mixed>|null, suggestions: array{legal_name: string|null, name: string|null}|null, source: array<string, mixed>|null, message: string, manual_fill: true, retry_after: int|null}
     */
    public function lookup(string $input, string $rateKey, int $perMinute): array
    {
        $digits = TaxId::digits($input);

        if (! TaxId::isCnpj($digits)) {
            return self::result('invalid', null, 'CNPJ inválido. Confira os dígitos ou preencha os dados manualmente.');
        }

        $formatted = TaxId::format($digits);
        $key = 'cnpj-lookup:'.$rateKey;

        if (RateLimiter::tooManyAttempts($key, max(1, $perMinute))) {
            $retry = RateLimiter::availableIn($key);

            return self::result('rate_limited', $formatted, 'Muitas consultas seguidas. Aguarde um minuto ou preencha os dados manualmente.') + ['retry_after' => $retry];
        }

        RateLimiter::hit($key, 60);

        $provider = $this->providers->make();

        /** @var CnpjLookupRecord|null $cached */
        $cached = CnpjLookupRecord::query()->where('cnpj', $digits)->first();

        if ($cached !== null && $cached->isFresh() && $cached->source === $provider->name()) {
            return $this->fromRecord($cached, $formatted, true);
        }

        $correlationId = Correlation::id();

        try {
            $response = $provider->lookup($digits, $correlationId);
        } catch (CnpjLookupUnavailable $exception) {
            return self::unavailable($formatted, $provider->name(), $exception->reason);
        } catch (\Throwable $exception) {
            $this->logger->error('Consulta de CNPJ falhou de forma inesperada; formulário segue manual.', [
                'cnpj' => TaxId::mask($digits),
                'exception' => $exception::class,
                'correlation_id' => $correlationId,
            ]);

            return self::unavailable($formatted, $provider->name(), 'error');
        }

        $now = Carbon::now();
        $found = $response['found'];

        $record = CnpjLookupRecord::query()->updateOrCreate(['cnpj' => $digits], [
            'status' => $found ? CnpjLookupRecord::STATUS_FOUND : CnpjLookupRecord::STATUS_NOT_FOUND,
            'payload' => $found ? [
                'legal_name' => $response['legal_name'] ?? null,
                'trade_name' => $response['trade_name'] ?? null,
                'status' => $response['status'] ?? null,
                'address' => $response['address'] ?? [],
                'details' => $response['details'] ?? [],
            ] : null,
            'source' => $provider->name(),
            'source_updated' => null,
            'fetched_at' => $now,
            'expires_at' => $found
                ? $now->copy()->addDays(max(1, (int) config('assinavelox.cnpj.cache_ttl_days', 30)))
                : $now->copy()->addHours(max(1, (int) config('assinavelox.cnpj.not_found_ttl_hours', 24))),
        ]);

        return $this->fromRecord($record, $formatted, false);
    }

    /**
     * @return array{status: 'found'|'not_found'|'invalid'|'unavailable'|'rate_limited', cnpj: string|null, data: array<string, mixed>|null, suggestions: array{legal_name: string|null, name: string|null}|null, source: array<string, mixed>|null, message: string, manual_fill: true, retry_after: int|null}
     */
    private function fromRecord(CnpjLookupRecord $record, ?string $formatted, bool $cached): array
    {
        $source = [
            'provider' => $record->source,
            'simulated' => $record->source === FakeCnpjLookup::NAME,
            'attribution' => (string) config('assinavelox.cnpj.attribution'),
            'fetched_at' => $record->fetched_at->toIso8601String(),
            'cached' => $cached,
        ];

        if ($record->status !== CnpjLookupRecord::STATUS_FOUND) {
            return ['source' => $source] + self::result('not_found', $formatted, 'CNPJ não encontrado na base pública. Preencha os dados manualmente.');
        }

        $payload = $record->payload ?? [];
        $legalName = is_string($payload['legal_name'] ?? null) ? $payload['legal_name'] : null;
        $tradeName = is_string($payload['trade_name'] ?? null) ? $payload['trade_name'] : null;
        $details = is_array($payload['details'] ?? null) ? $payload['details'] : [];

        return [
            'status' => 'found',
            'cnpj' => $formatted,
            'data' => [
                'legal_name' => $legalName,
                'trade_name' => $tradeName,
                'registration_status' => is_string($payload['status'] ?? null) ? $payload['status'] : null,
                'address' => is_array($payload['address'] ?? null) ? $payload['address'] : [],
                'cnae' => [
                    'code' => $details['cnae_fiscal'] ?? null,
                    'description' => $details['cnae_fiscal_descricao'] ?? null,
                ],
            ],
            // Mapeamento para os formulários: "Razão social" ← razão social; "Nome da
            // organização" ← nome fantasia (ou a razão social, quando não há fantasia).
            'suggestions' => [
                'legal_name' => $legalName,
                'name' => $tradeName ?? $legalName,
            ],
            'source' => $source,
            'message' => $source['simulated']
                ? 'Dados SIMULADOS (ambiente de desenvolvimento). Confira antes de salvar.'
                : 'Dados encontrados na base pública do CNPJ. Confira antes de salvar.',
            'manual_fill' => true,
            'retry_after' => null,
        ];
    }

    /**
     * @return array{status: 'found'|'not_found'|'invalid'|'unavailable'|'rate_limited', cnpj: string|null, data: array<string, mixed>|null, suggestions: array{legal_name: string|null, name: string|null}|null, source: array<string, mixed>|null, message: string, manual_fill: true, retry_after: int|null}
     */
    private static function unavailable(string $formatted, string $provider, string $reason): array
    {
        return ['source' => ['provider' => $provider, 'simulated' => $provider === FakeCnpjLookup::NAME, 'reason' => $reason]]
            + self::result('unavailable', $formatted, 'Não foi possível consultar o CNPJ agora. Preencha os dados manualmente — isso não impede salvar.');
    }

    /**
     * @param  'found'|'not_found'|'invalid'|'unavailable'|'rate_limited'  $status
     * @return array{status: 'found'|'not_found'|'invalid'|'unavailable'|'rate_limited', cnpj: string|null, data: null, suggestions: null, source: null, message: string, manual_fill: true, retry_after: null}
     */
    private static function result(string $status, ?string $formatted, string $message): array
    {
        return [
            'status' => $status,
            'cnpj' => $formatted,
            'data' => null,
            'suggestions' => null,
            'source' => null,
            'message' => $message,
            'manual_fill' => true,
            'retry_after' => null,
        ];
    }
}
