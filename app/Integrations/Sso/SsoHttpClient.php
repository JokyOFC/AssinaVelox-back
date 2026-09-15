<?php

namespace App\Integrations\Sso;

use App\Services\Sso\SsoFailure;
use App\Services\Webhooks\BoundedResponseBuffer;
use App\Support\Http\BlockedOutboundUrl;
use App\Support\Http\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Chamadas de saída do login corporativo (discovery e JWKS do OIDC, troca do código, metadata
 * SAML) com a MESMA proteção contra SSRF dos webhooks de saída (docs/fase-2/webhooks.md §6):
 *
 *  - OutboundUrlGuard a cada chamada: só https (http só fora de produção), nunca IP literal,
 *    nome resolvido e TODOS os endereços públicos;
 *  - pino de IP (CURLOPT_RESOLVE) no endereço validado, sem redirecionamento, sem proxy de
 *    ambiente, protocolo fixo e tempos-limite curtos;
 *  - corpo lido até `sso.http.max_response_kb` (acima disso a resposta é recusada);
 *  - lista de hosts permitidos quando o provedor é conhecido (`$allowedHosts`).
 *
 * Nada de segredo em exceção ou log: a falha vira SsoFailure com código estável.
 */
final class SsoHttpClient
{
    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /**
     * @param  list<string>|null  $allowedHosts  sufixos de host aceitos (null = qualquer host público)
     * @return array<string, mixed>
     *
     * @throws SsoFailure
     */
    public function getJson(string $url, ?array $allowedHosts = null): array
    {
        $response = $this->send('get', $url, $allowedHosts, static fn (PendingRequest $request, string $target) => $request
            ->accept('application/json')
            ->get($target));

        return $this->decodeJson($response);
    }

    /**
     * @param  list<string>|null  $allowedHosts
     *
     * @throws SsoFailure
     */
    public function getText(string $url, ?array $allowedHosts = null): string
    {
        $response = $this->send('get', $url, $allowedHosts, static fn (PendingRequest $request, string $target) => $request->get($target));

        return $this->body($response);
    }

    /**
     * POST application/x-www-form-urlencoded (troca do código do OIDC).
     *
     * @param  array<string, string>  $fields
     * @param  array{0: string, 1: string}|null  $basicAuth
     * @param  list<string>|null  $allowedHosts
     * @return array<string, mixed>
     *
     * @throws SsoFailure
     */
    public function postForm(string $url, #[\SensitiveParameter] array $fields, #[\SensitiveParameter] ?array $basicAuth = null, ?array $allowedHosts = null): array
    {
        $response = $this->send('post', $url, $allowedHosts, static function (PendingRequest $request, string $target) use ($fields, $basicAuth) {
            $request = $request->asForm()->accept('application/json');

            if ($basicAuth !== null) {
                $request = $request->withBasicAuth($basicAuth[0], $basicAuth[1]);
            }

            return $request->post($target, $fields);
        });

        return $this->decodeJson($response);
    }

    /**
     * Confere a URL sem chamar nada (cadastro da conexão, antes de salvar).
     *
     * @param  list<string>|null  $allowedHosts
     *
     * @throws SsoFailure
     */
    public function assertAllowed(string $url, ?array $allowedHosts = null): string
    {
        try {
            $target = $this->guard->inspect($url);
        } catch (BlockedOutboundUrl $blocked) {
            throw new SsoFailure('url_blocked:'.$blocked->reason, $blocked->userMessage());
        }

        $this->assertHost($target->host, $allowedHosts);

        return $target->url;
    }

    /**
     * @param  list<string>|null  $allowedHosts
     * @param  callable(PendingRequest, string): Response  $call
     *
     * @throws SsoFailure
     */
    private function send(string $method, string $url, ?array $allowedHosts, callable $call): Response
    {
        try {
            $target = $this->guard->inspect($url);
        } catch (BlockedOutboundUrl $blocked) {
            throw new SsoFailure('url_blocked:'.$blocked->reason, $blocked->userMessage());
        }

        $this->assertHost($target->host, $allowedHosts);

        $maxBytes = max(1024, (int) config('assinavelox.sso.http.max_response_kb', 512) * 1024);

        $request = Http::withOptions([
            'allow_redirects' => false,
            'proxy' => '',
            'protocols' => [$target->scheme],
            'curl' => [CURLOPT_RESOLVE => [$target->curlResolveEntry()]],
            // +1 byte: se couber mais que o teto, a resposta é recusada (nunca truncada em silêncio).
            'sink' => new BoundedResponseBuffer($maxBytes + 1),
            'verify' => true,
        ])
            ->connectTimeout((float) config('assinavelox.sso.http.connect_timeout_seconds', 5))
            ->timeout((float) config('assinavelox.sso.http.timeout_seconds', 10))
            ->withUserAgent((string) config('assinavelox.sso.http.user_agent', 'AssinaVelox-SSO/1.0'));

        try {
            $response = $call($request, $target->url);
        } catch (ConnectionException) {
            throw new SsoFailure('provider_unreachable');
        } catch (SsoFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new SsoFailure('provider_unreachable');
        }

        if ($response->status() >= 300 && $response->status() < 400) {
            throw new SsoFailure('provider_redirect_refused');
        }

        if (strlen($this->body($response)) > $maxBytes) {
            throw new SsoFailure('provider_response_too_large');
        }

        return $response;
    }

    /**
     * @param  list<string>|null  $allowedHosts
     *
     * @throws SsoFailure
     */
    private function assertHost(string $host, ?array $allowedHosts): void
    {
        if ($allowedHosts === null) {
            return;
        }

        $host = strtolower($host);

        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(ltrim($allowed, '.'));

            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return;
            }
        }

        throw new SsoFailure('url_blocked:host_not_in_provider_allowlist', 'Este endereço não pertence ao provedor de identidade configurado.');
    }

    private function body(Response $response): string
    {
        try {
            return (string) $response->body();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SsoFailure
     */
    private function decodeJson(Response $response): array
    {
        $body = $this->body($response);
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new SsoFailure($response->successful() ? 'provider_invalid_json' : 'provider_http_'.$response->status());
        }

        if (! $response->successful()) {
            // `error` do OAuth (RFC 6749 §5.2) é só um código; a descrição NÃO é repassada.
            $error = is_string($decoded['error'] ?? null) ? preg_replace('/[^a-z_]/', '', strtolower($decoded['error'])) : null;

            throw new SsoFailure('provider_http_'.$response->status().($error ? ':'.$error : ''));
        }

        return $decoded;
    }
}
