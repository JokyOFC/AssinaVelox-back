<?php

namespace App\Integrations\Sso\Oidc;

use App\Services\Sso\SsoFailure;
use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Carbon;
use stdClass;
use Throwable;
use UnexpectedValueException;

/**
 * Validação do id_token (OpenID Connect Core §3.1.3.7) com firebase/php-jwt 7.x:
 *
 *  1. o `alg` do cabeçalho é EXATAMENTE o fixado pela conexão — nunca `none`, nunca HS* (a
 *     lista de algoritmos da conexão só tem assimétricos, e a chave do JWKS é criada já
 *     presa a esse algoritmo, então a troca RS256→HS256 cai em "chave errada");
 *  2. assinatura pela chave do JWKS escolhida pelo `kid` (OidcDiscovery acompanha a rotação);
 *  3. `iss` idêntico ao do discovery; `aud` contém o client_id; com várias audiências, `azp`
 *     obrigatório; `azp`, se vier, é o client_id;
 *  4. `exp` e `iat` obrigatórios, com tolerância pequena (`sso.clock_leeway_seconds`) e idade
 *     máxima do `iat`; `nbf` respeitado;
 *  5. `nonce` idêntico ao do fluxo (uso único, guardado na sessão);
 *  6. `at_hash` conferido sempre que vier (e exigido com `sso.require_at_hash`).
 *
 * O relógio é o do Laravel (Carbon), não `time()`, para o teste controlar o tempo. As
 * propriedades estáticas do php-jwt são restauradas no `finally`.
 */
final class IdTokenValidator
{
    public function __construct(private readonly OidcDiscovery $discovery) {}

    /**
     * @return array<string, mixed> claims validadas
     *
     * @throws SsoFailure
     */
    public function validate(
        #[\SensitiveParameter] string $idToken,
        OidcProviderMetadata $metadata,
        string $clientId,
        string $algorithm,
        #[\SensitiveParameter] string $expectedNonce,
        #[\SensitiveParameter] ?string $accessToken,
    ): array {
        $allowed = (array) config('assinavelox.sso.oidc_algorithms', []);

        if (! in_array($algorithm, $allowed, true) || str_starts_with($algorithm, 'HS') || strtolower($algorithm) === 'none') {
            throw new SsoFailure('id_token_alg_not_allowed');
        }

        $header = $this->header($idToken);
        $headerAlg = $header['alg'] ?? null;

        if (! is_string($headerAlg) || $headerAlg !== $algorithm) {
            throw new SsoFailure('id_token_alg_not_allowed');
        }

        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : null;
        $key = $this->discovery->keyFor($metadata, $algorithm, $kid);

        $leeway = max(0, (int) config('assinavelox.sso.clock_leeway_seconds', 60));
        $now = Carbon::now()->getTimestamp();
        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;

        try {
            JWT::$leeway = $leeway;
            JWT::$timestamp = $now;
            $payload = JWT::decode($idToken, $key);
        } catch (SignatureInvalidException) {
            throw new SsoFailure('id_token_signature_invalid');
        } catch (ExpiredException) {
            throw new SsoFailure('id_token_expired');
        } catch (BeforeValidException) {
            throw new SsoFailure('id_token_not_yet_valid');
        } catch (UnexpectedValueException|DomainException) {
            throw new SsoFailure('id_token_invalid');
        } catch (Throwable) {
            throw new SsoFailure('id_token_invalid');
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }

        $claims = $this->toArray($payload);

        if (! is_string($claims['iss'] ?? null) || $claims['iss'] !== $metadata->issuer) {
            throw new SsoFailure('id_token_issuer');
        }

        $audience = $claims['aud'] ?? null;
        $audiences = is_string($audience) ? [$audience] : (is_array($audience) ? array_values(array_filter($audience, 'is_string')) : []);

        if (! in_array($clientId, $audiences, true)) {
            throw new SsoFailure('id_token_audience');
        }

        $azp = $claims['azp'] ?? null;

        if ((count($audiences) > 1 && ! is_string($azp)) || ($azp !== null && $azp !== $clientId)) {
            throw new SsoFailure('id_token_azp');
        }

        if (! is_numeric($claims['exp'] ?? null)) {
            throw new SsoFailure('id_token_expired');
        }

        $iat = $claims['iat'] ?? null;
        $maxAge = max(60, (int) config('assinavelox.sso.id_token_max_age_seconds', 600));

        if (! is_numeric($iat) || (int) $iat > $now + $leeway || (int) $iat < $now - $maxAge - $leeway) {
            throw new SsoFailure('id_token_iat');
        }

        $nonce = $claims['nonce'] ?? null;

        if (! is_string($nonce) || $expectedNonce === '' || ! hash_equals($expectedNonce, $nonce)) {
            throw new SsoFailure('id_token_nonce');
        }

        $subject = $claims['sub'] ?? null;

        if (! is_string($subject) || $subject === '' || strlen($subject) > 255) {
            throw new SsoFailure('id_token_subject');
        }

        $atHash = $claims['at_hash'] ?? null;

        if ($atHash !== null) {
            if (! is_string($atHash) || $accessToken === null || $accessToken === '' || ! hash_equals(self::atHash($accessToken, $algorithm), $atHash)) {
                throw new SsoFailure('id_token_at_hash');
            }
        } elseif ((bool) config('assinavelox.sso.require_at_hash', false) && $accessToken !== null) {
            throw new SsoFailure('id_token_at_hash_missing');
        }

        return $claims;
    }

    /**
     * `at_hash` (Core §3.1.3.6): metade esquerda do hash do access_token com a função de hash
     * do `alg`, em base64url sem preenchimento.
     */
    public static function atHash(#[\SensitiveParameter] string $accessToken, string $algorithm): string
    {
        $bits = (int) substr($algorithm, -3);
        $function = match ($bits) {
            384 => 'sha384',
            512 => 'sha512',
            default => 'sha256',
        };
        $digest = hash($function, $accessToken, true);

        return rtrim(strtr(base64_encode(substr($digest, 0, intdiv(strlen($digest), 2))), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SsoFailure
     */
    private function header(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || strlen($token) > 16384) {
            throw new SsoFailure('id_token_invalid');
        }

        try {
            $decoded = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new SsoFailure('id_token_invalid');
        }

        if (! is_array($decoded)) {
            throw new SsoFailure('id_token_invalid');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(stdClass $payload): array
    {
        $decoded = json_decode((string) json_encode($payload), true);

        return is_array($decoded) ? $decoded : [];
    }
}
