<?php

namespace App\Integrations\Cnpj;

use App\Integrations\Contracts\CnpjLookupProvider;
use App\Support\Correlation;
use App\Support\TaxId;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

/**
 * Consulta de CNPJ na instância pública do Minha Receita (docs/integracoes/cnpj-cpf.md §2.2).
 *
 * - Endpoint documentado: `GET {base_url}/{cnpj}` → 200 encontrado, 400 inválido, 404 não
 *   encontrado. `base_url` configurável (padrão `https://minhareceita.org`) para uma futura
 *   instância auto-hospedada, sem mudar código.
 * - **Sem SLA**: timeout curto, nenhuma repetição, nenhum redirecionamento seguido. Tempo
 *   esgotado, 5xx, 429 e resposta malformada viram {@see CnpjLookupUnavailable} — resultado
 *   desconhecido, nunca sucesso nem "não existe" (T5).
 * - A BrasilAPI NÃO é fallback: o CNPJ dela é proxy desta mesma fonte.
 * - Resposta tratada como dado não confiável (T6): só campos esperados, strings saneadas e
 *   limitadas. O payload devolvido é MINIMIZADO: sem sócios (QSA com nome e CPF parcial),
 *   e-mail ou telefones.
 */
final class MinhaReceitaCnpjLookup implements CnpjLookupProvider
{
    public const NAME = 'minha_receita';

    public function __construct(private readonly LoggerInterface $logger) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== null;
    }

    public function lookup(string $cnpj, ?string $correlationId = null): array
    {
        $digits = TaxId::digits($cnpj);
        $base = $this->baseUrl();

        if ($base === null) {
            throw new CnpjLookupUnavailable('not_configured', 'A consulta de CNPJ não está configurada (assinavelox.cnpj.base_url).');
        }

        $correlationId ??= Correlation::id();

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'User-Agent' => 'AssinaVelox/1.0 (autopreenchimento de cadastro)',
                    'X-Correlation-Id' => $correlationId,
                ])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(max(0.5, (float) config('assinavelox.cnpj.connect_timeout_seconds', 2)))
                ->timeout(max(1.0, (float) config('assinavelox.cnpj.timeout_seconds', 4)))
                ->get($base.'/'.$digits);
        } catch (ConnectionException $exception) {
            $this->warn('timeout', $digits, $correlationId);

            throw new CnpjLookupUnavailable('timeout');
        }

        $status = $response->status();

        if ($status === 404 || $status === 400) {
            return ['found' => false, 'provider' => self::NAME, 'details' => ['reason' => $status === 404 ? 'not_found' : 'rejected_by_source']];
        }

        if ($status === 429) {
            $this->warn('rate_limited_by_source', $digits, $correlationId);

            throw new CnpjLookupUnavailable('rate_limited_by_source');
        }

        if ($status !== 200) {
            $this->warn($status >= 500 ? 'source_error' : 'unexpected_status', $digits, $correlationId, $status);

            throw new CnpjLookupUnavailable($status >= 500 ? 'source_error' : 'unexpected_status');
        }

        $body = $response->body();
        $maxBytes = max(16, (int) config('assinavelox.cnpj.max_response_kb', 512)) * 1024;

        if (strlen($body) > $maxBytes) {
            $this->warn('response_too_large', $digits, $correlationId);

            throw new CnpjLookupUnavailable('response_too_large');
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            $this->warn('malformed_response', $digits, $correlationId);

            throw new CnpjLookupUnavailable('malformed_response');
        }

        return $this->map($data, $digits, $correlationId);
    }

    /**
     * @param  array<mixed>  $data
     * @return array{found: bool, provider: string, legal_name?: string, trade_name?: string, status?: string, address?: array<string, string>, details?: array<string, mixed>}
     */
    private function map(array $data, string $digits, string $correlationId): array
    {
        $returned = TaxId::digits(is_scalar($data['cnpj'] ?? null) ? (string) $data['cnpj'] : '');
        $legalName = self::text($data['razao_social'] ?? null, 200);

        // Resposta 200 que não é deste CNPJ, ou sem razão social: não dá para confiar em
        // nenhum campo dela.
        if (($returned !== '' && $returned !== $digits) || $legalName === null) {
            $this->warn('malformed_response', $digits, $correlationId);

            throw new CnpjLookupUnavailable('malformed_response');
        }

        $address = array_filter([
            'street' => self::text($data['logradouro'] ?? null, 160),
            'number' => self::text($data['numero'] ?? null, 20),
            'complement' => self::text($data['complemento'] ?? null, 120),
            'district' => self::text($data['bairro'] ?? null, 120),
            'city' => self::text($data['municipio'] ?? null, 120),
            'state' => self::text($data['uf'] ?? null, 2),
            'postal_code' => self::digitsOrNull($data['cep'] ?? null, 8),
        ], static fn (?string $value): bool => $value !== null);

        $result = [
            'found' => true,
            'provider' => self::NAME,
            'legal_name' => $legalName,
            'address' => $address,
            'details' => array_filter([
                'cnae_fiscal' => self::digitsOrNull($data['cnae_fiscal'] ?? null, 7),
                'cnae_fiscal_descricao' => self::text($data['cnae_fiscal_descricao'] ?? null, 200),
            ], static fn (?string $value): bool => $value !== null),
        ];

        $tradeName = self::text($data['nome_fantasia'] ?? null, 200);

        if ($tradeName !== null) {
            $result['trade_name'] = $tradeName;
        }

        $situation = self::text($data['descricao_situacao_cadastral'] ?? null, 40);

        if ($situation !== null) {
            $result['status'] = $situation;
        }

        return $result;
    }

    private function baseUrl(): ?string
    {
        $base = rtrim(trim((string) config('assinavelox.cnpj.base_url', '')), '/');

        if ($base === '' || preg_match('#^https?://[^\s/]+#i', $base) !== 1) {
            return null;
        }

        return $base;
    }

    /**
     * String da fonte externa: sem caracteres de controle, espaços normalizados e tamanho
     * limitado. Vazio vira null.
     */
    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');

        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, $max);
    }

    private static function digitsOrNull(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $digits = TaxId::digits((string) $value);

        return $digits === '' ? null : substr($digits, 0, $max);
    }

    private function warn(string $reason, string $digits, string $correlationId, ?int $status = null): void
    {
        $this->logger->warning('Consulta de CNPJ indisponível (Minha Receita).', array_filter([
            'reason' => $reason,
            'cnpj' => TaxId::mask($digits),
            'http_status' => $status,
            'correlation_id' => $correlationId,
        ], static fn ($value): bool => $value !== null));
    }
}
