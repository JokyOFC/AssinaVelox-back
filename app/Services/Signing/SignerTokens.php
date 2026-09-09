<?php

namespace App\Services\Signing;

use Illuminate\Support\Str;

/**
 * Geração e conferência dos três tokens do fluxo público (docs/fluxo-do-signatario.md §2).
 *
 * Os três são bytes aleatórios de `random_bytes` em base64url, e de todos eles **só o
 * digest SHA-256 é persistido**:
 *
 * | Token          | Onde nasce                     | Onde o digest fica                              | Vida |
 * |----------------|--------------------------------|-------------------------------------------------|------|
 * | convite        | envio/reenvio (B-SEND)         | `recipient_access_links.token_digest`            | prazo do envelope |
 * | sessão         | verificação do código          | `signing_sessions.token_digest`                  | 30 min |
 * | autorização    | renderização da tela de aceite | `signing_sessions.authorization_token_digest`    | 10 min |
 *
 * O token de convite viaja na URL do e-mail; o da sessão fica na sessão Laravel (server-side,
 * cookie httpOnly do framework); o de autorização viaja nas props da página e volta no POST
 * do aceite. Nenhum deles é gravado em log, exceção ou evento de auditoria.
 */
final class SignerTokens
{
    /** Bytes de entropia (arquitetura §3.1: token de 32 bytes). */
    public const TOKEN_BYTES = 32;

    /**
     * Token bruto em base64url (43 caracteres para 32 bytes), compatível com a
     * restrição da rota `[A-Za-z0-9_-]{20,128}`.
     */
    public static function generate(int $bytes = self::TOKEN_BYTES): string
    {
        return rtrim(strtr(base64_encode(random_bytes(max(16, $bytes))), '+/', '-_'), '=');
    }

    /**
     * Digest gravado no banco. Igual a RecipientAccessLink::digestFor().
     */
    public static function digest(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Comparação em tempo constante entre um digest guardado e um token bruto recebido.
     *
     * A busca no banco já é por digest (coluna UNIQUE), mas a confirmação final passa por
     * hash_equals para que nenhuma comparação de segredo dependa de `===`.
     */
    public static function matches(?string $storedDigest, string $rawToken): bool
    {
        if ($storedDigest === null || $storedDigest === '') {
            return false;
        }

        return hash_equals($storedDigest, self::digest($rawToken));
    }

    /**
     * Chave da sessão Laravel onde fica o token bruto da sessão de assinatura.
     * Por destinatário: verificar outro destinatário no mesmo navegador exige novo código.
     */
    public static function sessionKey(string $recipientUlid): string
    {
        return 'signer.sessions.'.$recipientUlid;
    }

    /**
     * Correlação para amarrar delivery_attempts, audit_events e logs de uma mesma ação.
     */
    public static function correlationId(): string
    {
        return (string) Str::ulid();
    }
}
