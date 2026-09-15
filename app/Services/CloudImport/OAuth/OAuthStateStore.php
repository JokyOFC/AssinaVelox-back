<?php

namespace App\Services\CloudImport\OAuth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * `state` e PKCE dos fluxos OAuth dos conectores (Google Drive e HubSpot — Fase 3 §3.9,
 * docs/fase-3/conectores.md §4.1 e §5.1), guardados na SESSÃO de quem iniciou.
 *
 *  - `state`: 32 bytes aleatórios (base64url). A sessão guarda só o SHA-256 dele como chave.
 *  - `code_verifier`: 48 bytes aleatórios (64 caracteres base64url, dentro de 43–128 da
 *    RFC 7636), CIFRADO na sessão; o desafio enviado é S256.
 *  - USO ÚNICO: `consume()` remove a entrada ANTES de conferir qualquer coisa. O mesmo `state`
 *    apresentado de novo (replay, dois cliques no retorno, retorno reaberto) não vale.
 *  - Vence em `cloud_import.state_ttl_minutes`; propósito errado também é recusado.
 *  - Contexto (organização, usuário, envelope) volta no consumo: quem chama confere que o
 *    retorno veio para a mesma organização e o mesmo usuário que iniciaram.
 */
final class OAuthStateStore
{
    private const SESSION_KEY = 'connectors.oauth_states';

    private const MAX_PENDING = 5;

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function issue(string $purpose, array $context, bool $pkce = true): PendingAuthorization
    {
        $state = self::base64Url(random_bytes(32));
        $verifier = $pkce ? self::base64Url(random_bytes(48)) : null;
        $challenge = $verifier === null ? null : self::base64Url(hash('sha256', $verifier, true));

        $entries = $this->pruned($this->entries());

        // Mantém só as mais recentes (várias abas), sem deixar a sessão crescer.
        while (count($entries) >= self::MAX_PENDING) {
            array_shift($entries);
        }

        $entries[hash('sha256', $state)] = [
            'purpose' => $purpose,
            'context' => $context,
            'verifier' => $verifier === null ? null : Crypt::encryptString($verifier),
            'expires_at' => Carbon::now()->getTimestamp() + max(60, (int) config('assinavelox.cloud_import.state_ttl_minutes', 10) * 60),
        ];

        session()->put(self::SESSION_KEY, $entries);

        return new PendingAuthorization($state, $verifier, $challenge);
    }

    /**
     * Consome o `state` (uso único). null = desconhecido, vencido, de outro propósito ou já usado.
     */
    public function consume(string $purpose, mixed $state): ?ConsumedAuthorization
    {
        if (! is_string($state) || strlen($state) < 20 || strlen($state) > 200) {
            return null;
        }

        $key = hash('sha256', $state);
        $entries = $this->entries();
        $entry = $entries[$key] ?? null;

        unset($entries[$key]);
        session()->put(self::SESSION_KEY, $this->pruned($entries));

        if (! is_array($entry) || ($entry['purpose'] ?? null) !== $purpose || (int) ($entry['expires_at'] ?? 0) < Carbon::now()->getTimestamp()) {
            return null;
        }

        $verifier = null;

        if (is_string($entry['verifier'] ?? null)) {
            try {
                $verifier = Crypt::decryptString($entry['verifier']);
            } catch (Throwable) {
                return null;
            }
        }

        /** @var array<string, scalar|null> $context */
        $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];

        return new ConsumedAuthorization($context, $verifier);
    }

    /**
     * @return array<string, mixed>
     */
    private function entries(): array
    {
        $entries = session()->get(self::SESSION_KEY, []);

        return is_array($entries) ? $entries : [];
    }

    /**
     * @param  array<string, mixed>  $entries
     * @return array<string, mixed>
     */
    private function pruned(array $entries): array
    {
        $now = Carbon::now()->getTimestamp();

        return array_filter($entries, static fn ($entry): bool => is_array($entry) && (int) ($entry['expires_at'] ?? 0) >= $now);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
