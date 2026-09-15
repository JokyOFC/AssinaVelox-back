<?php

namespace App\Integrations\GoogleDrive;

use App\Services\CloudImport\CloudImportRejected;
use App\Services\CloudImport\Http\ConnectorFailure;
use App\Services\CloudImport\Http\ConnectorHttp;

/**
 * OAuth 2.0 do Google para a importação do Drive (Fase 3 §3.9, G-CONN,
 * docs/fase-3/conectores.md §4.1; pacotes-fase-2-3 §4.1).
 *
 *  - authorization code + PKCE (S256) + `state` de uso único (OAuthStateStore);
 *  - escopo ÚNICO `https://www.googleapis.com/auth/drive.file` (não sensível: acesso só aos
 *    arquivos que o usuário escolher no Picker). Nunca `drive` nem `drive.readonly`;
 *  - `access_type=online`: o Google não emite refresh token. Se um vier mesmo assim, é
 *    DESCARTADO sem ser lido;
 *  - depois da importação o access token é revogado (`/revoke`, melhor esforço).
 *
 * Endpoints fixos do Google (não vêm de configuração), chamados pelo ConnectorHttp com a lista
 * de hosts `oauth2.googleapis.com`. Classe B: sem as quatro credenciais do app registrado pelo
 * proprietário, `isConfigured()` é false e nada é chamado.
 */
final class GoogleOAuthClient
{
    public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    /** @var list<string> */
    public const TOKEN_HOSTS = ['oauth2.googleapis.com'];

    /** Variável de ambiente => chave em config/services.php `google_drive`. */
    private const REQUIRED = [
        'GOOGLE_DRIVE_CLIENT_ID' => 'client_id',
        'GOOGLE_DRIVE_CLIENT_SECRET' => 'client_secret',
        'GOOGLE_DRIVE_API_KEY' => 'api_key',
        'GOOGLE_DRIVE_APP_ID' => 'app_id',
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
            if (trim((string) config('services.google_drive.'.$key, '')) === '') {
                $missing[] = $env;
            }
        }

        return $missing;
    }

    public function authorizationUrl(string $state, string $challenge, string $redirectUri): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => (string) config('services.google_drive.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'access_type' => 'online',
            'include_granted_scopes' => 'false',
            'prompt' => 'consent',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Troca o `code` pelo access token (com o `code_verifier`).
     *
     * @throws CloudImportRejected
     */
    public function exchange(string $code, #[\SensitiveParameter] string $verifier, string $redirectUri): GoogleAccessToken
    {
        try {
            $response = $this->http->postForm(self::TOKEN_URL, self::TOKEN_HOSTS, [
                'code' => $code,
                'client_id' => (string) config('services.google_drive.client_id'),
                'client_secret' => (string) config('services.google_drive.client_secret'),
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
                'code_verifier' => $verifier,
            ]);
        } catch (ConnectorFailure $failure) {
            throw self::authorizationFailed($failure->errorCode);
        }

        if (! $response->successful()) {
            throw self::authorizationFailed('http_'.$response->status());
        }

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');
        $scopes = array_values(array_filter(explode(' ', (string) $response->json('scope', '')), static fn (string $scope): bool => $scope !== ''));

        if (! is_string($token) || $token === '' || strlen($token) > 4096) {
            throw self::authorizationFailed('invalid_response');
        }

        if (! in_array(self::SCOPE, $scopes, true)) {
            throw CloudImportRejected::make(
                'scope_not_granted',
                'O acesso aos arquivos escolhidos no Google Drive não foi autorizado. Tente de novo e marque a permissão pedida.',
            );
        }

        // `refresh_token`, se vier, nunca é lido nem guardado (docs/fase-3/conectores.md §4.3).
        return new GoogleAccessToken($token, is_numeric($expiresIn) ? max(0, (int) $expiresIn) : 0, $scopes);
    }

    /**
     * Revogação depois da importação. Melhor esforço: o token já vence sozinho em até 1 hora.
     */
    public function revoke(#[\SensitiveParameter] string $token): bool
    {
        try {
            return $this->http->postForm(self::REVOKE_URL, self::TOKEN_HOSTS, ['token' => $token])->successful();
        } catch (ConnectorFailure) {
            return false;
        }
    }

    /**
     * Configuração PÚBLICA do Picker no navegador (a API key do Picker é pública por natureza
     * e deve ser restrita por referrer no console do Google). Nunca inclui o client secret.
     *
     * @return array{client_id: string, api_key: string, app_id: string}
     */
    public function pickerConfig(): array
    {
        return [
            'client_id' => (string) config('services.google_drive.client_id'),
            'api_key' => (string) config('services.google_drive.api_key'),
            'app_id' => (string) config('services.google_drive.app_id'),
        ];
    }

    private static function authorizationFailed(string $code): CloudImportRejected
    {
        return CloudImportRejected::make(
            'authorization_failed_'.$code,
            'Não foi possível concluir a autorização com o Google. Tente de novo.',
        );
    }
}
