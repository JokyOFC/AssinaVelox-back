<?php

namespace App\Services\Embed;

use App\Enums\AuditEventType;
use App\Models\EmbeddedSigningSession;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Risk\SubjectKeys;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerRequestFacts;
use App\Services\Signing\SignerTokens;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Troca da URL de uso único pelo token de execução (docs/fase-3/widget-embutido.md §3.2).
 *
 * O widget lê o token do fragmento (`#t=`), apaga o fragmento do endereço e o envia UMA vez no
 * corpo deste POST. Enquanto o token não confere, a resposta é o mesmo 404 genérico — o ULID
 * da sessão está no endereço e não é segredo; o token é. Só quem tem o token certo fica sabendo
 * se o link foi usado, venceu ou foi revogado.
 *
 * A marcação de uso é atômica (UPDATE condicionado a `used_at IS NULL`): duas trocas
 * simultâneas não geram dois tokens de execução.
 */
final class EmbeddedSessionExchange
{
    public function __construct(private readonly EmbeddedContextResolver $contexts) {}

    public static function runtimeTtlMinutes(): int
    {
        return max(1, (int) config('assinavelox.embedded_signing.runtime_ttl_minutes', 30));
    }

    /**
     * @return array{token: string, expires_at: Carbon}
     *
     * @throws EmbedRejected
     */
    public function exchange(EmbeddedSigningSession $session, string $raw, Request $request): array
    {
        if ($raw === '' || strlen($raw) > 128 || ! SignerTokens::matches($session->token_digest, $raw)) {
            throw new EmbedRejected('invalid_link', 'Este link de assinatura não é válido.', 404);
        }

        if ($session->isRevoked()) {
            throw new EmbedRejected('link_revoked', 'Este acesso foi encerrado. Peça um novo acesso no site em que você está.', 410);
        }

        if ($session->isUsed()) {
            throw new EmbedRejected('link_used', 'Este link de acesso já foi usado. Peça um novo acesso no site em que você está.', 410);
        }

        if ($session->expires_at->isPast()) {
            throw new EmbedRejected('link_expired', 'Este link de acesso venceu. Peça um novo acesso no site em que você está.', 410);
        }

        // Convite ao qual a sessão está presa já revogado (reenvio, troca de e-mail, recusa,
        // cancelamento): a URL não vira token de execução nem grava `embedded_session.opened`.
        if ($this->contexts->forSession($session) === null) {
            throw new EmbedRejected('unavailable', 'Este acesso não está mais disponível. Peça um novo acesso no site em que você está.', 410);
        }

        $now = Carbon::now();
        $runtime = SignerTokens::generate();
        $expiresAt = $now->copy()->addMinutes(self::runtimeTtlMinutes());

        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->whereKey($session->envelope_id)->first();

        if ($envelope?->expires_at !== null && $envelope->expires_at->lt($expiresAt)) {
            $expiresAt = $envelope->expires_at->copy();
        }

        $updated = EmbeddedSigningSession::withoutGlobalScopes()
            ->whereKey($session->getKey())
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('token_digest', $session->token_digest)
            ->where('expires_at', '>', $now)
            ->update([
                'used_at' => $now,
                'runtime_token_digest' => SignerTokens::digest($runtime),
                'runtime_expires_at' => $expiresAt,
                'last_seen_at' => $now,
                'used_ip' => SubjectKeys::truncateIp(SignerRequestFacts::ip($request)),
                'used_user_agent' => Str::limit((string) SignerRequestFacts::userAgent($request), 500, ''),
                'updated_at' => $now,
            ]);

        if ($updated !== 1) {
            throw new EmbedRejected('link_used', 'Este link de acesso já foi usado. Peça um novo acesso no site em que você está.', 410);
        }

        $session->refresh();

        /** @var Recipient|null $recipient */
        $recipient = Recipient::withoutOrganizationScope()->whereKey($session->recipient_id)->first();

        if ($envelope !== null && $recipient !== null) {
            SignerAudit::record($envelope, $recipient, AuditEventType::EmbeddedSessionOpened, [
                'session' => $session->ulid,
                'origin' => $session->allowed_origin,
            ]);
        }

        return ['token' => $runtime, 'expires_at' => $expiresAt];
    }
}
