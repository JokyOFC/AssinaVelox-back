<?php

namespace App\Services\CloudImport;

use App\Integrations\GoogleDrive\GoogleAccessToken;
use App\Models\Envelope;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Access token do Google DURANTE a importação (docs/fase-3/conectores.md §4.3).
 *
 *  - guardado na sessão de quem autorizou, CIFRADO (Crypt, chave da APP_KEY) — a sessão pode
 *    estar em banco ou arquivo e não é cifrada por padrão;
 *  - vinculado ao envelope, ao usuário e à organização: outro envelope, outro usuário ou outra
 *    organização não o enxergam;
 *  - vence no que vier primeiro: o `expires_in` do Google (menos 60 s) ou
 *    `cloud_import.google_session_ttl_minutes`;
 *  - apagado da sessão e revogado no Google depois da importação (sucesso ou não).
 *
 * Nunca vai para banco, log, fila, evento ou prop do Inertia (prop fica no histórico do
 * navegador): o Picker recebe o token por um POST JSON sem cache, só nesta janela.
 */
final class GoogleImportSession
{
    private const KEY = 'connectors.google_drive';

    public function store(Envelope $envelope, User $user, GoogleAccessToken $token): void
    {
        $ceiling = max(60, (int) config('assinavelox.cloud_import.google_session_ttl_minutes', 10) * 60);
        $ttl = $token->expiresIn > 0 ? min($ceiling, max(30, $token->expiresIn - 60)) : $ceiling;

        session()->put(self::KEY, [
            'envelope' => $envelope->ulid,
            'organization' => (int) $envelope->organization_id,
            'user' => (int) $user->getKey(),
            'token' => Crypt::encryptString($token->value()),
            'expires_at' => Carbon::now()->getTimestamp() + $ttl,
        ]);
    }

    public function token(Envelope $envelope, User $user): ?string
    {
        $entry = $this->entry($envelope, $user);

        if ($entry === null) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $entry['token']);
        } catch (Throwable) {
            $this->forget();

            return null;
        }
    }

    public function authorized(Envelope $envelope, User $user): bool
    {
        return $this->entry($envelope, $user) !== null;
    }

    public function secondsLeft(Envelope $envelope, User $user): int
    {
        $entry = $this->entry($envelope, $user);

        return $entry === null ? 0 : max(0, (int) $entry['expires_at'] - Carbon::now()->getTimestamp());
    }

    public function forget(): void
    {
        session()->forget(self::KEY);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entry(Envelope $envelope, User $user): ?array
    {
        $entry = session()->get(self::KEY);

        if (! is_array($entry)) {
            return null;
        }

        if ((int) ($entry['expires_at'] ?? 0) < Carbon::now()->getTimestamp()) {
            $this->forget();

            return null;
        }

        if (($entry['envelope'] ?? null) !== $envelope->ulid
            || (int) ($entry['organization'] ?? 0) !== (int) $envelope->organization_id
            || (int) ($entry['user'] ?? 0) !== (int) $user->getKey()
            || ! is_string($entry['token'] ?? null)) {
            return null;
        }

        return $entry;
    }
}
