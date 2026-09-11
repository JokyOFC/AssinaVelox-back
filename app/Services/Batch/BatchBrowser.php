<?php

namespace App\Services\Batch;

use App\Services\Batch\Models\BatchSigningSession;
use App\Services\Signing\SignerRequestFacts;
use App\Services\Signing\SignerTokens;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * O lote neste navegador (docs/fase-2/presencial-e-lote.md §3.3).
 *
 * O token do link chega na URL do e-mail uma vez e é trocado imediatamente por uma entrada
 * na sessão Laravel (`batch.link`); a página redireciona para `assinar/lote`, sem o token na
 * barra de endereços nem no `Referer`. Depois do código, a autenticação do lote vive na
 * sessão Laravel (`batch.auth.{ulid}`, token bruto) e no banco (digest em
 * `session_token_digest`, validade `batch_signing.session_ttl_minutes`). Autenticar em outro
 * navegador troca o digest e encerra este.
 */
final class BatchBrowser
{
    public const LINK_KEY = 'batch.link';

    public function __construct(
        private readonly Repository $config,
        private readonly BatchLinks $links,
    ) {}

    public function ttlMinutes(): int
    {
        return max(1, (int) $this->config->get('assinavelox.batch_signing.session_ttl_minutes', 30));
    }

    public static function authKey(string $batchUlid): string
    {
        return 'batch.auth.'.$batchUlid;
    }

    public function remember(Request $request, string $raw): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::LINK_KEY, $raw);
        }
    }

    /**
     * Lote cujo link foi aberto neste navegador, se ainda vale.
     */
    public function current(Request $request): ?BatchSigningSession
    {
        if (! $request->hasSession()) {
            return null;
        }

        $raw = $request->session()->get(self::LINK_KEY);

        return is_string($raw) && $raw !== '' ? $this->links->resolve($raw) : null;
    }

    public function hasLink(Request $request): bool
    {
        return $request->hasSession() && is_string($request->session()->get(self::LINK_KEY));
    }

    public function authenticate(BatchSigningSession $batch, Request $request): void
    {
        $raw = SignerTokens::generate();
        $now = Carbon::now();

        $batch->forceFill([
            'session_token_digest' => SignerTokens::digest($raw),
            'status' => BatchSigningSession::STATUS_AUTHENTICATED,
            'authenticated_at' => $now,
            'session_expires_at' => $now->copy()->addMinutes($this->ttlMinutes()),
            'last_seen_at' => $now,
            'ip_address' => SignerRequestFacts::ip($request),
            'user_agent' => SignerRequestFacts::userAgent($request),
        ])->save();

        if ($request->hasSession()) {
            $request->session()->put(self::authKey($batch->ulid), $raw);
            $request->session()->migrate(true);
        }
    }

    public function isAuthenticated(BatchSigningSession $batch, Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $raw = $request->session()->get(self::authKey($batch->ulid));

        if (! is_string($raw) || $raw === '' || ! SignerTokens::matches($batch->session_token_digest, $raw)) {
            return false;
        }

        if ($batch->session_expires_at === null || $batch->session_expires_at->isPast()) {
            return false;
        }

        $batch->forceFill(['last_seen_at' => Carbon::now()])->save();

        return true;
    }

    /**
     * Sai do lote neste navegador: esquece o link e a autenticação; a autenticação some também
     * no banco. As sessões de assinatura por item (se abertas) são revogadas por quem chama.
     */
    public function forget(Request $request, ?BatchSigningSession $batch): void
    {
        if ($batch !== null) {
            $batch->forceFill(['session_token_digest' => null, 'session_expires_at' => null])->save();
        }

        if (! $request->hasSession()) {
            return;
        }

        $request->session()->forget(['batch', 'signer']);
        $request->session()->migrate(true);
    }
}
