<?php

namespace App\Integrations\HubSpot;

use App\Services\CloudImport\Http\ConnectorFailure;
use App\Services\CloudImport\Http\ConnectorHttp;
use Illuminate\Http\Client\Response;

/**
 * API do HubSpot pelo HTTP Client do Laravel (sem o SDK `hubspot/api-client`, que forçaria o
 * Guzzle 7 — pacotes-fase-2-3 §0.1). Fase 3 §3.9, G-CONN, docs/fase-3/conectores.md §5.
 *
 *  - OAuth: `app.hubspot.com/oauth/authorize` (navegador) + endpoints de token v3 com as
 *    credenciais no CORPO (`hubspot.token_path`, `introspect_path`, `revoke_path`);
 *  - CRM: `PATCH /crm/v3/objects/{tipo}/{id}` para gravar a propriedade de estado;
 *  - toda chamada pelo ConnectorHttp com a lista de hosts `hubspot.api_hosts`
 *    (`api.hubapi.com`): mesmo pino de IP e mesmas travas dos webhooks.
 *
 * Classe B: sem `HUBSPOT_CLIENT_ID`/`HUBSPOT_CLIENT_SECRET` nada é chamado. NÃO CONFIRMADO:
 * o formato exato da resposta do `introspect` v3 (lemos `hub_id` ou `hubId`) — conferir com a
 * conta de teste do app registrado.
 */
final class HubSpotClient
{
    private const REQUIRED = [
        'HUBSPOT_CLIENT_ID' => 'client_id',
        'HUBSPOT_CLIENT_SECRET' => 'client_secret',
    ];

    /** Tipos do objeto na ação → segmento da API de objetos do CRM. */
    private const OBJECT_TYPES = [
        'CONTACT' => 'contacts',
        'DEAL' => 'deals',
        'COMPANY' => 'companies',
        'TICKET' => 'tickets',
        '0-1' => 'contacts',
        '0-3' => 'deals',
        '0-2' => 'companies',
        '0-5' => 'tickets',
    ];

    public function __construct(private readonly ConnectorHttp $http) {}

    public function isConfigured(): bool
    {
        return $this->missingConfiguration() === [];
    }

    /**
     * @return list<string>
     */
    public function missingConfiguration(): array
    {
        $missing = [];

        foreach (self::REQUIRED as $env => $key) {
            if (trim((string) config('services.hubspot.'.$key, '')) === '') {
                $missing[] = $env;
            }
        }

        return $missing;
    }

    public static function apiUrl(string $path): string
    {
        return rtrim((string) config('assinavelox.hubspot.api_base', 'https://api.hubapi.com'), '/').'/'.ltrim($path, '/');
    }

    /**
     * @return list<string>
     */
    public static function apiHosts(): array
    {
        return array_values(array_filter((array) config('assinavelox.hubspot.api_hosts', ['api.hubapi.com']), 'is_string'));
    }

    public static function crmObjectSegment(?string $objectType): ?string
    {
        return self::OBJECT_TYPES[strtoupper((string) $objectType)] ?? null;
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return (string) config('assinavelox.hubspot.authorize_url', 'https://app.hubspot.com/oauth/authorize').'?'.http_build_query([
            'client_id' => (string) config('services.hubspot.client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', (array) config('assinavelox.hubspot.scopes', [])),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @throws ConnectorFailure
     */
    public function exchangeCode(string $code, string $redirectUri): HubSpotTokens
    {
        return $this->tokens($this->http->postForm(self::apiUrl((string) config('assinavelox.hubspot.token_path')), self::apiHosts(), [
            'grant_type' => 'authorization_code',
            'client_id' => (string) config('services.hubspot.client_id'),
            'client_secret' => (string) config('services.hubspot.client_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]));
    }

    /**
     * @throws ConnectorFailure
     */
    public function refresh(#[\SensitiveParameter] string $refreshToken): HubSpotTokens
    {
        return $this->tokens($this->http->postForm(self::apiUrl((string) config('assinavelox.hubspot.token_path')), self::apiHosts(), [
            'grant_type' => 'refresh_token',
            'client_id' => (string) config('services.hubspot.client_id'),
            'client_secret' => (string) config('services.hubspot.client_secret'),
            'refresh_token' => $refreshToken,
        ]));
    }

    /**
     * Portal (hub) dono do token, pelo `introspect` v3. null quando a resposta não diz.
     *
     * @throws ConnectorFailure
     */
    public function portalId(#[\SensitiveParameter] string $accessToken): ?int
    {
        $response = $this->http->postForm(self::apiUrl((string) config('assinavelox.hubspot.introspect_path')), self::apiHosts(), [
            'client_id' => (string) config('services.hubspot.client_id'),
            'client_secret' => (string) config('services.hubspot.client_secret'),
            'token' => $accessToken,
            'token_type_hint' => 'access_token',
        ]);

        if (! $response->successful()) {
            throw ConnectorFailure::http($response->status());
        }

        return self::portalFrom($response->json());
    }

    /**
     * Revogação na desconexão. Melhor esforço: os tokens saem do banco de qualquer forma.
     */
    public function revoke(#[\SensitiveParameter] string $token): bool
    {
        try {
            return $this->http->postForm(self::apiUrl((string) config('assinavelox.hubspot.revoke_path')), self::apiHosts(), [
                'client_id' => (string) config('services.hubspot.client_id'),
                'client_secret' => (string) config('services.hubspot.client_secret'),
                'token' => $token,
            ])->successful();
        } catch (ConnectorFailure) {
            return false;
        }
    }

    /**
     * @param  array<string, string>  $properties
     *
     * @throws ConnectorFailure
     */
    public function updateObject(#[\SensitiveParameter] string $accessToken, string $segment, string $objectId, array $properties): void
    {
        $response = $this->http->sendJson(
            'PATCH',
            self::apiUrl('/crm/v3/objects/'.rawurlencode($segment).'/'.rawurlencode($objectId)),
            self::apiHosts(),
            ['properties' => $properties],
            ['Authorization' => 'Bearer '.$accessToken],
        );

        if (! $response->successful()) {
            throw ConnectorFailure::http($response->status());
        }
    }

    /**
     * @throws ConnectorFailure
     */
    private function tokens(Response $response): HubSpotTokens
    {
        if (! $response->successful()) {
            throw ConnectorFailure::http($response->status());
        }

        $access = $response->json('access_token');
        $refresh = $response->json('refresh_token');
        $expires = $response->json('expires_in');

        if (! is_string($access) || $access === '' || strlen($access) > 8192) {
            throw new ConnectorFailure(ConnectorFailure::INVALID_RESPONSE);
        }

        $scopes = $response->json('scopes', $response->json('scope', []));
        $scopes = is_string($scopes) ? explode(' ', $scopes) : (is_array($scopes) ? $scopes : []);

        return new HubSpotTokens(
            $access,
            is_string($refresh) && $refresh !== '' && strlen($refresh) <= 8192 ? $refresh : null,
            is_numeric($expires) ? max(0, (int) $expires) : 1800,
            self::portalFrom($response->json()),
            array_values(array_filter(array_map('strval', $scopes), static fn (string $scope): bool => $scope !== '')),
        );
    }

    private static function portalFrom(mixed $json): ?int
    {
        if (! is_array($json)) {
            return null;
        }

        foreach (['hub_id', 'hubId', 'portal_id', 'portalId'] as $key) {
            $value = $json[$key] ?? null;

            if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }
}
