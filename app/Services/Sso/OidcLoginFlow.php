<?php

namespace App\Services\Sso;

use App\Integrations\Sso\Oidc\IdTokenValidator;
use App\Integrations\Sso\Oidc\OidcClient;
use App\Integrations\Sso\Oidc\OidcDiscovery;
use App\Models\SsoConnection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Fluxo OIDC do login corporativo (docs/fase-3/sso.md §6): Authorization Code + PKCE (S256),
 * `state` e `nonce` de uso único ligados à SESSÃO que iniciou, validade curta.
 *
 * A volta do provedor é um GET de navegação de topo: o cookie de sessão (SameSite=Lax) vem, e o
 * `state` é procurado só nos fluxos DESTA sessão — `state` trocado, repetido ou de outra conexão
 * não encontra nada.
 */
final class OidcLoginFlow
{
    public const MODE_LOGIN = 'login';

    public const MODE_TEST = 'test';

    public function __construct(
        private readonly OidcDiscovery $discovery,
        private readonly OidcClient $client,
        private readonly IdTokenValidator $validator,
    ) {}

    public function redirectUri(SsoConnection $connection): string
    {
        return route('sso.oidc.callback', ['connection' => $connection->ulid]);
    }

    /**
     * @throws SsoFailure
     */
    public function start(SsoConnection $connection, Request $request, string $mode, ?User $initiator = null, ?string $loginHint = null): string
    {
        $metadata = $this->discovery->metadata((string) $connection->oidc_issuer);
        $algorithm = (string) ($connection->oidc_id_token_alg ?: 'RS256');

        if ($metadata->codeChallengeMethods !== null && ! in_array('S256', $metadata->codeChallengeMethods, true)) {
            throw new SsoFailure('provider_without_pkce', 'O provedor não anuncia PKCE com S256, que é obrigatório.');
        }

        if ($metadata->responseTypes !== null && ! in_array('code', $metadata->responseTypes, true)) {
            throw new SsoFailure('provider_without_code_flow', 'O provedor não oferece o fluxo Authorization Code.');
        }

        if ($metadata->idTokenAlgorithms !== null && ! in_array($algorithm, $metadata->idTokenAlgorithms, true)) {
            throw new SsoFailure('provider_alg_mismatch', 'O provedor não assina o id_token com o algoritmo fixado nesta conexão.');
        }

        $state = self::random(32);
        $nonce = self::random(32);
        $verifier = self::random(48);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $ttl = max(1, (int) config('assinavelox.sso.flow_ttl_minutes', 10)) * 60;

        SsoSession::putFlow($request, $state, [
            'connection_id' => (int) $connection->getKey(),
            'nonce' => $nonce,
            'verifier' => $verifier,
            'mode' => $mode === self::MODE_TEST ? self::MODE_TEST : self::MODE_LOGIN,
            'user_id' => $initiator?->getKey(),
            'expires_at' => Carbon::now()->getTimestamp() + $ttl,
        ]);

        return $this->client->authorizationUrl($metadata, (string) $connection->oidc_client_id, $this->redirectUri($connection), $state, $nonce, $challenge, $loginHint);
    }

    /**
     * Retira o fluxo pelo `state` (uso único) e confere que é desta conexão e não venceu.
     *
     * @return array{connection_id: int, nonce: string, verifier: string, mode: string, user_id: int|null, expires_at: int}
     *
     * @throws SsoFailure
     */
    public function consumeState(Request $request, SsoConnection $connection): array
    {
        $state = $request->query('state');

        if (! is_string($state) || $state === '' || strlen($state) > 128) {
            throw new SsoFailure('state_invalid');
        }

        $flow = SsoSession::pullFlow($request, $state);

        if ($flow === null || (int) ($flow['connection_id'] ?? 0) !== (int) $connection->getKey()) {
            throw new SsoFailure('state_invalid');
        }

        if ((int) ($flow['expires_at'] ?? 0) < Carbon::now()->getTimestamp()) {
            throw new SsoFailure('flow_expired');
        }

        return [
            'connection_id' => (int) $flow['connection_id'],
            'nonce' => (string) ($flow['nonce'] ?? ''),
            'verifier' => (string) ($flow['verifier'] ?? ''),
            'mode' => (string) ($flow['mode'] ?? self::MODE_LOGIN),
            'user_id' => isset($flow['user_id']) ? (int) $flow['user_id'] : null,
            'expires_at' => (int) $flow['expires_at'],
        ];
    }

    /**
     * @param  array{connection_id: int, nonce: string, verifier: string, mode: string, user_id: int|null, expires_at: int}  $flow
     *
     * @throws SsoFailure
     */
    public function finish(SsoConnection $connection, array $flow, Request $request): VerifiedIdentity
    {
        $error = $request->query('error');

        if (is_string($error) && $error !== '') {
            throw new SsoFailure('provider_error:'.substr((string) preg_replace('/[^a-z_]/', '', strtolower($error)), 0, 40));
        }

        $metadata = $this->discovery->metadata((string) $connection->oidc_issuer);

        // RFC 9207: com `iss` na volta, ele precisa ser o emissor desta conexão (mix-up).
        $iss = $request->query('iss');

        if ($iss !== null && (! is_string($iss) || $iss !== $metadata->issuer)) {
            throw new SsoFailure('issuer_mismatch');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '' || strlen($code) > 4096) {
            throw new SsoFailure('code_missing');
        }

        $tokens = $this->client->exchangeCode(
            $metadata,
            (string) $connection->oidc_client_id,
            (string) $connection->oidc_client_secret,
            $code,
            $this->redirectUri($connection),
            $flow['verifier'],
        );

        $claims = $this->validator->validate(
            $tokens['id_token'],
            $metadata,
            (string) $connection->oidc_client_id,
            (string) ($connection->oidc_id_token_alg ?: 'RS256'),
            $flow['nonce'],
            $tokens['access_token'],
        );

        $email = $claims['email'] ?? null;

        if (! is_string($email) || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new SsoFailure('email_missing', 'O provedor de identidade não enviou o e-mail do usuário.');
        }

        $verified = $claims['email_verified'] ?? null;

        // Só `true` (booleano) ou "true" (alguns provedores mandam texto). Ausente = não verificado.
        if ($verified !== true && $verified !== 'true') {
            throw new SsoFailure('email_not_verified');
        }

        $name = is_string($claims['name'] ?? null) && trim($claims['name']) !== ''
            ? trim($claims['name'])
            : trim(((is_string($claims['given_name'] ?? null) ? $claims['given_name'] : '').' '.(is_string($claims['family_name'] ?? null) ? $claims['family_name'] : '')));

        return new VerifiedIdentity(
            subject: (string) $claims['sub'],
            email: mb_strtolower(trim($email)),
            name: $name === '' ? null : mb_substr($name, 0, 120),
            emailVerified: true,
        );
    }

    /**
     * @param  int<1, max>  $bytes
     */
    private static function random(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
