<?php

namespace App\Integrations\Identity\Verifiky;

use App\Integrations\Contracts\IdentityVerificationProvider;
use App\Integrations\Dto\IdentityVerificationImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Verifiky (https://app.verifiky.com) — integrada como no metta-bank, de onde saiu o contrato:
 *
 *  - Envio: `POST {api_url}/api/verifiky/processar`, `Authorization: Bearer {VERIFIKY_API_KEY}`,
 *    multipart com `user_reference`, `documento`, `documento_verso`, `foto_ao_vivo` e
 *    `tipo_documento` (`rg` | `cnh` | `passaporte`). Responde na hora com o protocolo
 *    (`verification_id`) e, às vezes, já com o resultado.
 *  - Resultado final: webhook `verification.completed` ({@see VerifikyWebhook}) ou leitura de
 *    `GET {api_url}/api/verifiky/verificacoes/{id}` — assinada por HMAC quando há
 *    `VERIFIKY_HMAC_SECRET` ({@see VerifikyRequestSigner}), só com o Bearer quando não há.
 *  - Falhas: 402 = sem créditos, 403 = plano inativo ou sem permissão, 429 = limite de uso.
 *
 * Nada aqui lança por falha do provedor: rede, tempo esgotado, 4xx e 5xx viram `inconclusive`
 * com `reason_code` (T5). Imagens e chave nunca entram em log; o que se registra é tamanho,
 * status HTTP e o identificador da tentativa.
 */
final class VerifikyIdentityVerificationProvider implements IdentityVerificationProvider
{
    public const NAME = 'verifiky';

    /** O que o proprietário precisa entregar para este adaptador responder. */
    public const MISSING = [
        'VERIFIKY_API_KEY: chave da conta na Verifiky (painel da Verifiky)',
        'VERIFIKY_WEBHOOK_SECRET: segredo do webhook, e a URL /webhooks/verifiky cadastrada no painel da Verifiky',
        'VERIFIKY_HMAC_SECRET (opcional): segredo das leituras assinadas, para consultar o resultado quando o webhook não chega',
        'Plano com créditos ativos na Verifiky',
        'Base legal (LGPD art. 11), RIPD e texto de consentimento aprovados pelo jurídico',
    ];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly VerifikyRequestSigner $signer,
        private readonly LoggerInterface $logger,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Verifiky';
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && $this->apiKey() !== '';
    }

    public function start(string $recipientUlid, array $options = [], ?string $correlationId = null): array
    {
        if (! $this->isConfigured()) {
            return $this->inconclusive(null, 'not_configured', 'Verificação indisponível: a conta na Verifiky não está configurada.', ['missing' => self::MISSING]);
        }

        $images = $options['images'] ?? [];
        $selfie = $images['selfie'] ?? null;
        $front = $images['document_front'] ?? null;
        $back = $images['document_back'] ?? null;

        if (! $selfie instanceof IdentityVerificationImage || ! $front instanceof IdentityVerificationImage) {
            return $this->inconclusive(null, 'missing_images', 'Faltam imagens para enviar ao provedor.');
        }

        $multipart = [
            ['name' => 'user_reference', 'contents' => (string) ($options['reference'] ?? '')],
            $this->filePart('documento', $front),
            $this->filePart('foto_ao_vivo', $selfie),
            ['name' => 'tipo_documento', 'contents' => (string) ($options['document_type'] ?? 'rg')],
        ];

        if ($back instanceof IdentityVerificationImage) {
            $multipart[] = $this->filePart('documento_verso', $back);
        }

        $context = [
            'provider' => self::NAME,
            'reference' => $options['reference'] ?? null,
            'recipient' => $recipientUlid,
            'document_type' => $options['document_type'] ?? null,
            'bytes' => $selfie->sizeBytes() + $front->sizeBytes() + ($back?->sizeBytes() ?? 0),
            'correlation_id' => $correlationId,
        ];

        try {
            $response = $this->client()->asMultipart()->post($this->baseUrl().'/api/verifiky/processar', $multipart);
        } catch (ConnectionException $exception) {
            $this->logger->warning('Verifiky: sem resposta ao enviar a verificação (rede ou tempo esgotado).', $context + ['exception' => $exception::class]);

            return $this->inconclusive(null, 'provider_unavailable', 'O provedor demorou a responder. Isso não é uma reprovação: tente de novo em alguns instantes.');
        } catch (Throwable $exception) {
            $this->logger->error('Verifiky: falha inesperada ao enviar a verificação.', $context + ['exception' => $exception::class]);

            return $this->inconclusive(null, 'provider_error', 'Não foi possível falar com o provedor de verificação.');
        }

        $this->logger->info('Verifiky: verificação enviada.', $context + ['http_status' => $response->status()]);

        return $this->interpret($response, null);
    }

    public function result(string $verificationId, ?string $correlationId = null): array
    {
        if (! $this->isConfigured()) {
            return $this->inconclusive($verificationId, 'not_configured', 'Verificação indisponível: a conta na Verifiky não está configurada.', ['missing' => self::MISSING]);
        }

        $url = $this->baseUrl().'/api/verifiky/verificacoes/'.rawurlencode($verificationId);

        try {
            $request = $this->signer->isConfigured()
                ? $this->client()->withHeaders($this->signer->headers('GET', $url))
                : $this->client();

            $response = $request->get($url);
        } catch (Throwable $exception) {
            $this->logger->warning('Verifiky: sem resposta ao consultar a verificação.', [
                'provider' => self::NAME,
                'verification_id' => $verificationId,
                'correlation_id' => $correlationId,
                'exception' => $exception::class,
            ]);

            return $this->inconclusive($verificationId, 'provider_unavailable', 'O provedor não respondeu à consulta do resultado.');
        }

        return $this->interpret($response, $verificationId);
    }

    /**
     * @return array{verification_id: string|null, provider: string, status: 'pending'|'approved'|'rejected'|'inconclusive'|'expired', checked_at: string, details: array<string, mixed>}
     */
    private function interpret(Response $response, ?string $knownId): array
    {
        $json = $response->json();

        if (! $response->successful()) {
            [$code, $message] = self::failure($response->status(), is_array($json) ? $json : null, $response->body());

            return $this->inconclusive($knownId, $code, $message, ['http_status' => $response->status()]);
        }

        if (! is_array($json)) {
            return $this->inconclusive($knownId, 'unreadable_result', 'O provedor respondeu em um formato que não foi possível ler.', ['http_status' => $response->status()]);
        }

        $mapped = VerifikyResultMapper::map($json);

        return [
            'verification_id' => $mapped['verification_id'] ?? $knownId,
            'provider' => self::NAME,
            'status' => $mapped['status'],
            'checked_at' => Carbon::now()->toIso8601String(),
            'details' => $mapped['details'],
        ];
    }

    /**
     * A mesma leitura de falhas do metta-bank (VerifikyAccountGateService::interpretApiFailure):
     * o status HTTP decide primeiro, o texto da resposta depois.
     *
     * @param  array<string, mixed>|null  $json
     * @return array{0: string, 1: string} código e mensagem para o participante
     */
    public static function failure(int $status, ?array $json, ?string $rawBody = null): array
    {
        $text = Str::lower(implode(' ', array_filter([
            is_string($json['message'] ?? null) ? $json['message'] : '',
            is_string($json['error'] ?? null) ? $json['error'] : '',
            Str::limit((string) $rawBody, 500, ''),
        ])));

        return match (true) {
            $status === 402, Str::contains($text, ['crédito', 'credito', 'credit']) => ['insufficient_credits', 'A verificação está indisponível no momento. Avise quem enviou o documento.'],
            $status === 401 => ['provider_auth_failed', 'A verificação está indisponível no momento. Avise quem enviou o documento.'],
            $status === 403, Str::contains($text, ['expir', 'venc', 'plano', 'assinatura', 'subscription']) => ['plan_inactive', 'A verificação está indisponível no momento. Avise quem enviou o documento.'],
            $status === 429 => ['rate_limited', 'Muitas verificações em pouco tempo. Tente de novo em alguns minutos.'],
            $status === 422, $status === 400 => ['provider_refused', 'O provedor não aceitou as imagens enviadas. Refaça as fotos com boa luz e o documento inteiro no quadro.'],
            default => ['provider_error', 'O provedor de verificação não conseguiu concluir. Isso não é uma reprovação: tente de novo em alguns instantes.'],
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{verification_id: string|null, provider: string, status: 'inconclusive', checked_at: string, details: array<string, mixed>}
     */
    private function inconclusive(?string $verificationId, string $code, string $message, array $extra = []): array
    {
        return [
            'verification_id' => $verificationId,
            'provider' => self::NAME,
            'status' => 'inconclusive',
            'checked_at' => Carbon::now()->toIso8601String(),
            'details' => ['reason_code' => $code, 'message' => $message] + $extra,
        ];
    }

    /**
     * @return array{name: string, contents: string, filename: string, headers: array<string, string>}
     */
    private function filePart(string $name, IdentityVerificationImage $image): array
    {
        return [
            'name' => $name,
            'contents' => $image->bytes,
            'filename' => $image->filename,
            'headers' => ['Content-Type' => $image->mimeType],
        ];
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->timeout(max(5, (int) config('assinavelox.identity_verification.verifiky.timeout', 180)))
            ->connectTimeout(15)
            ->withOptions(['verify' => (bool) config('assinavelox.identity_verification.verifiky.verify_ssl', true)])
            ->acceptJson()
            ->withToken($this->apiKey());
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) config('assinavelox.identity_verification.verifiky.api_url', '')), '/');
    }

    private function apiKey(): string
    {
        return trim((string) config('assinavelox.identity_verification.verifiky.api_key', ''));
    }
}
