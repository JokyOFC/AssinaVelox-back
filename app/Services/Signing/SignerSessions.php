<?php

namespace App\Services\Signing;

use App\Enums\SigningSessionStatus;
use App\Models\Recipient;
use App\Models\SigningSession;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Sessão do signatário e token de autorização final (arquitetura §4.3/§4.5).
 *
 * ## Os três tokens, e por que são três
 *
 * - **Convite** (`recipient_access_links`): prova que a pessoa recebeu o e-mail. Vive o
 *   prazo do envelope, é reutilizável e sozinho não autoriza nada além de ver a capa.
 * - **Sessão** (`signing_sessions.token_digest`): prova que a pessoa confirmou o código
 *   naquele navegador. Vive 30 minutos. O token bruto fica **na sessão Laravel**, sob uma
 *   chave por destinatário — nunca em cookie próprio, nunca em `localStorage`. Confirmar
 *   outro destinatário no mesmo navegador cria outra entrada e exige outro código.
 * - **Autorização final** (`signing_sessions.authorization_token_digest`): prova que o POST
 *   de aceite veio da mesma tela que foi renderizada. Vive 10 minutos, é emitido na
 *   renderização da tela de assinatura e carrega o `snapshot_hash` do que foi apresentado.
 *   Se o documento ou os campos mudarem entre a renderização e o clique, o hash não bate e
 *   o aceite é recusado — é assim que o aceite fica preso ao que a pessoa realmente viu.
 *
 * Nenhum dos três é gravado em claro: o banco guarda só o digest SHA-256.
 */
final class SignerSessions
{
    public function __construct(private readonly Repository $config) {}

    public function ttlMinutes(): int
    {
        return max(1, (int) $this->config->get('assinavelox.signing_session.ttl_minutes', 30));
    }

    public function authorizationTtlMinutes(): int
    {
        return max(1, (int) $this->config->get('assinavelox.signing_session.authorization_ttl_minutes', 10));
    }

    /**
     * Cria a linha `pending_auth` que hospeda os desafios OTP.
     *
     * A sessão nasce **antes** do código porque `auth_challenges.signing_session_id` é
     * obrigatório: o desafio pertence a uma tentativa de sessão, e é isso que permite
     * amarrar o aceite ao desafio que o autenticou.
     */
    public function startPending(SignerContext $context, Request $request): SigningSession
    {
        $existing = $this->pendingFor($context);

        if ($existing !== null) {
            $existing->forceFill([
                'last_seen_at' => Carbon::now(),
                'ip_address' => SignerRequestFacts::ip($request),
                'user_agent' => SignerRequestFacts::userAgent($request),
            ])->save();

            return $existing;
        }

        return SigningSession::query()->create([
            'recipient_id' => $context->recipient->getKey(),
            'envelope_id' => $context->envelope->getKey(),
            'document_version_id' => $context->envelope->sent_document_version_id,
            'access_link_id' => $context->link->getKey(),
            'organization_id' => $context->envelope->organization_id,
            'token_digest' => SignerTokens::digest(SignerTokens::generate()),
            'status' => SigningSessionStatus::PendingAuth,
            'ip_address' => SignerRequestFacts::ip($request),
            'user_agent' => SignerRequestFacts::userAgent($request),
            'expires_at' => Carbon::now()->addMinutes($this->ttlMinutes()),
            'last_seen_at' => Carbon::now(),
        ]);
    }

    /**
     * Sessão `pending_auth` ainda válida para este link, se houver.
     */
    public function pendingFor(SignerContext $context): ?SigningSession
    {
        /** @var SigningSession|null */
        return SigningSession::withoutOrganizationScope()
            ->where('recipient_id', $context->recipient->getKey())
            ->where('access_link_id', $context->link->getKey())
            ->where('status', SigningSessionStatus::PendingAuth->value)
            ->where('expires_at', '>', Carbon::now())
            ->latest('id')
            ->first();
    }

    /**
     * Promove a sessão a `authenticated` e devolve o token bruto, que é guardado na
     * sessão Laravel sob a chave do destinatário.
     */
    public function authenticate(SigningSession $session, SignerContext $context, Request $request): SigningSession
    {
        $raw = SignerTokens::generate();

        $session->forceFill([
            'token_digest' => SignerTokens::digest($raw),
            'status' => SigningSessionStatus::Authenticated,
            'document_version_id' => $context->envelope->sent_document_version_id ?? $session->document_version_id,
            'authenticated_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes($this->ttlMinutes()),
            'last_seen_at' => Carbon::now(),
            'ip_address' => SignerRequestFacts::ip($request),
            'user_agent' => SignerRequestFacts::userAgent($request),
        ])->save();

        $request->session()->put(SignerTokens::sessionKey($context->recipient->ulid), $raw);

        return $session;
    }

    /**
     * Sessão autenticada e ainda válida para ESTE destinatário neste navegador.
     *
     * A conferência é dupla: o token da sessão Laravel precisa casar com o digest da linha
     * **e** a linha precisa pertencer ao destinatário do link aberto. É isso que impede
     * usar a sessão de um signatário para assinar por outro no mesmo navegador.
     */
    public function current(SignerContext $context, Request $request): ?SigningSession
    {
        if (! $request->hasSession()) {
            return null;
        }

        $raw = $request->session()->get(SignerTokens::sessionKey($context->recipient->ulid));

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        /** @var SigningSession|null $session */
        $session = SigningSession::withoutOrganizationScope()
            ->where('token_digest', SignerTokens::digest($raw))
            ->first();

        if ($session === null || ! SignerTokens::matches($session->token_digest, $raw)) {
            return null;
        }

        if ($session->recipient_id !== $context->recipient->getKey()
            || $session->envelope_id !== $context->envelope->getKey()) {
            return null;
        }

        if ($session->status !== SigningSessionStatus::Authenticated) {
            return null;
        }

        if ($session->isExpired()) {
            $this->expire($session, $context, $request);

            return null;
        }

        // A sessão é presa à versão apresentada: se o documento foi trocado depois da
        // preparação, a sessão antiga não serve mais (arquitetura §3.3).
        if ($context->envelope->sent_document_version_id !== null
            && $session->document_version_id !== $context->envelope->sent_document_version_id) {
            $this->revoke($session, $context, $request);

            return null;
        }

        $session->forceFill(['last_seen_at' => Carbon::now()])->save();

        return $session;
    }

    /**
     * Emite (ou reemite) o token de autorização final ligado ao snapshot apresentado.
     * Devolve o token bruto — ele vai nas props da página e volta no POST do aceite.
     */
    public function issueAuthorization(SigningSession $session, string $snapshotHash): string
    {
        $raw = SignerTokens::generate();

        $session->forceFill([
            'authorization_token_digest' => SignerTokens::digest($raw),
            'authorization_expires_at' => Carbon::now()->addMinutes($this->authorizationTtlMinutes()),
            'snapshot_hash' => $snapshotHash,
        ])->save();

        return $raw;
    }

    /**
     * Confere o token de autorização e o snapshot: o aceite só vale para a tela que foi
     * mostrada. Comparações em tempo constante.
     */
    public function authorizationMatches(SigningSession $session, string $rawAuthorization, string $snapshotHash): bool
    {
        if (! $session->hasValidAuthorization()) {
            return false;
        }

        if (! SignerTokens::matches($session->authorization_token_digest, $rawAuthorization)) {
            return false;
        }

        return $session->snapshot_hash !== null && hash_equals($session->snapshot_hash, $snapshotHash);
    }

    /**
     * Consome a sessão após o aceite: nem o mesmo navegador reaproveita.
     */
    public function consume(SigningSession $session, SignerContext $context, ?Request $request = null): void
    {
        $session->forceFill([
            'status' => SigningSessionStatus::Consumed,
            'consumed_at' => Carbon::now(),
            'authorization_token_digest' => null,
            'authorization_expires_at' => null,
        ])->save();

        $this->forget($context, $request);
    }

    public function expire(SigningSession $session, SignerContext $context, ?Request $request = null): void
    {
        $session->forceFill(['status' => SigningSessionStatus::Expired])->save();

        $this->forget($context, $request);
    }

    public function revoke(SigningSession $session, SignerContext $context, ?Request $request = null): void
    {
        $session->forceFill([
            'status' => SigningSessionStatus::Revoked,
            'authorization_token_digest' => null,
            'authorization_expires_at' => null,
        ])->save();

        $this->forget($context, $request);
    }

    /**
     * Encerra todas as sessões vivas de um destinatário (recusa, cancelamento, troca de
     * e-mail). Devolve quantas foram encerradas.
     */
    public function revokeAllFor(Recipient $recipient): int
    {
        return SigningSession::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->whereIn('status', [
                SigningSessionStatus::PendingAuth->value,
                SigningSessionStatus::Authenticated->value,
            ])
            ->update([
                'status' => SigningSessionStatus::Revoked->value,
                'authorization_token_digest' => null,
                'authorization_expires_at' => null,
            ]);
    }

    private function forget(SignerContext $context, ?Request $request): void
    {
        $request ??= request();

        if ($request->hasSession()) {
            $request->session()->forget(SignerTokens::sessionKey($context->recipient->ulid));
        }
    }
}
