<?php

namespace App\Services\Embed;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Models\ApiToken;
use App\Models\EmbeddedSigningSession;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\User;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Envelopes\Sending\AccessLinks;
use App\Services\Identity\IdentityCaptures;
use App\Services\Identity\IdentityVerifications;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerTokens;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Emite a sessão de assinatura embutida pela API v1 (docs/fase-3/widget-embutido.md §2).
 *
 * O que é conferido, e em que ordem (tudo ANTES da idempotência, porque uma repetição gera uma
 * URL nova e utilizável — ela não pode passar por cima do estado atual):
 *
 * 1. envelope em andamento (`in_progress`);
 * 2. papel que registra aceite (signatário, testemunha, aprovador; o visualizador não);
 * 3. convite ativo (link de assinatura não revogado) — a sessão fica PRESA a ele;
 * 4. é a vez da pessoa e ela ainda não respondeu (mesma regra do link por e-mail);
 * 5. nada que o widget não ofereça foi exigido (foto ou vídeo — decisão documentada em §3.4);
 * 6. origem na forma canônica e cadastrada em `integration_settings.allowed_origins`;
 * 7. teto de sessões utilizáveis por participante.
 *
 * Idempotência: a MESMA `Idempotency-Key` do mesmo token, com o mesmo pedido, devolve a MESMA
 * sessão (mesmo `id`) com uma URL NOVA de uso único — o token anterior deixa de valer —, enquanto
 * a sessão não tiver sido usada. O token não é guardado em claro em lugar nenhum (nem no
 * armazenamento de respostas da API), então repetir a resposta idêntica seria impossível sem
 * gravar o segredo; reemitir é a forma honesta de o cliente que perdeu a resposta recuperar a URL.
 */
final class EmbeddedSessionIssuer
{
    public function __construct(
        private readonly AccessLinks $links,
        private readonly EmbeddedContextResolver $contexts,
        private readonly IdentityCaptures $captures,
        private readonly IdentityVerifications $verifications,
    ) {}

    public static function defaultTtlSeconds(): int
    {
        return min(self::maxTtlSeconds(), max(60, (int) config('assinavelox.embedded_signing.url_ttl_seconds', 300)));
    }

    public static function maxTtlSeconds(): int
    {
        return max(60, (int) config('assinavelox.embedded_signing.url_ttl_max_seconds', 900));
    }

    /**
     * @return array{session: EmbeddedSigningSession, url: string, replayed: bool}
     *
     * @throws EmbedRejected
     * @throws ValidationException
     */
    public function issue(
        Envelope $envelope,
        Recipient $recipient,
        string $origin,
        ?int $expiresIn,
        ?ApiToken $token,
        ?User $creator,
        ?string $idempotencyKey = null,
        ?string $fingerprint = null,
    ): array {
        if ($envelope->status !== EnvelopeStatus::InProgress) {
            throw new EmbedRejected('invalid-status', 'Só é possível abrir a assinatura embutida de um documento em andamento.', 409, [
                'envelope_status' => $envelope->status->value,
            ]);
        }

        if ($recipient->role->acceptanceAction() === null) {
            throw new EmbedRejected('recipient-not-signable', 'Este participante só acompanha o documento: não há aceite a registrar pelo widget.');
        }

        $link = $this->links->activeFor($recipient);

        if ($link === null) {
            throw new EmbedRejected('recipient-not-invited', 'Este participante ainda não tem convite ativo. Ele recebe o convite quando chega a vez dele.');
        }

        $context = $this->contexts->fromLink($link);

        if ($context === null || $context->state !== SignerContext::STATE_ACTIVE) {
            throw new EmbedRejected('recipient-not-active', 'Não é a vez deste participante ou ele já respondeu.', 409, [
                'recipient_status' => $recipient->status->value,
            ]);
        }

        // Fase 4 §4.1: a verificação facial com documento implica as três fotos, então vem
        // ANTES da checagem das fotos — o motivo devolvido é o mais específico.
        if ($this->verifications->requiredFor($context)) {
            throw new EmbedRejected('embedded-unsupported', 'Quem enviou exigiu a verificação facial com documento deste participante; as fotos e o envio ao provedor não são feitos pelo widget. Use o link enviado por e-mail.', 409, [
                'requirement' => 'identity_verification',
            ]);
        }

        if ($this->captures->photoRequiredFor($context)) {
            throw new EmbedRejected('embedded-unsupported', 'Quem enviou exigiu foto deste participante; a captura não é feita pelo widget. Use o link enviado por e-mail.', 409, [
                'requirement' => 'identity_capture',
            ]);
        }

        if ($this->captures->videoRequiredFor($context)) {
            throw new EmbedRejected('embedded-unsupported', 'Quem enviou exigiu vídeo curto deste participante; a gravação não é feita pelo widget. Use o link enviado por e-mail.', 409, [
                'requirement' => 'identity_video',
            ]);
        }

        $normalized = AllowedOrigins::normalize($origin);

        if ($normalized === null) {
            throw ValidationException::withMessages(['origin' => [AllowedOrigins::invalidMessage()]]);
        }

        if (! in_array($normalized, AllowedOrigins::forOrganization($context->organization), true)) {
            throw ValidationException::withMessages([
                'origin' => ['Esta origem não está na lista de origens permitidas da organização (API e integrações → Widget de assinatura).'],
            ]);
        }

        $ttl = $expiresIn === null ? self::defaultTtlSeconds() : min(self::maxTtlSeconds(), max(60, $expiresIn));
        $keyDigest = $token !== null && $idempotencyKey !== null && $idempotencyKey !== '' ? hash('sha256', $idempotencyKey) : null;

        if ($keyDigest !== null) {
            $existing = $this->existingFor($token, $keyDigest);

            if ($existing !== null) {
                return $this->replay($existing, $recipient, $envelope, $link->getKey(), $fingerprint, $ttl);
            }
        }

        $this->assertBelowLiveCap($recipient);

        $raw = SignerTokens::generate();

        try {
            /** @var EmbeddedSigningSession $session */
            $session = EmbeddedSigningSession::query()->create([
                'organization_id' => $envelope->organization_id,
                'envelope_id' => $envelope->getKey(),
                'recipient_id' => $recipient->getKey(),
                'access_link_id' => $link->getKey(),
                'api_token_id' => $token?->getKey(),
                'created_by_user_id' => $creator?->getKey(),
                'allowed_origin' => $normalized,
                'token_digest' => SignerTokens::digest($raw),
                'idempotency_key_digest' => $keyDigest,
                'request_fingerprint' => $keyDigest !== null ? $fingerprint : null,
                'expires_at' => Carbon::now()->addSeconds($ttl),
            ]);
        } catch (QueryException $exception) {
            // Corrida entre duas requisições com a mesma chave (o middleware da API já serializa;
            // isto é a segunda defesa, pelo índice único).
            $existing = $keyDigest !== null ? $this->existingFor($token, $keyDigest) : null;

            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $recipient, $envelope, $link->getKey(), $fingerprint, $ttl);
        }

        EnvelopeAudit::record($envelope, AuditEventType::EmbeddedSessionCreated, [
            'session' => $session->ulid,
            'origin' => $normalized,
            'expires_at' => $session->expires_at->toIso8601String(),
            'channel' => 'api',
        ], $recipient);

        return ['session' => $session, 'url' => self::urlFor($session, $raw), 'replayed' => false];
    }

    public static function urlFor(EmbeddedSigningSession $session, string $raw): string
    {
        return route('embed.show', ['session' => $session->ulid]).'#t='.$raw;
    }

    private function existingFor(ApiToken $token, string $keyDigest): ?EmbeddedSigningSession
    {
        /** @var EmbeddedSigningSession|null $existing */
        $existing = EmbeddedSigningSession::withoutGlobalScopes()
            ->where('api_token_id', $token->getKey())
            ->where('idempotency_key_digest', $keyDigest)
            ->first();

        if ($existing === null) {
            return null;
        }

        $ttlHours = max(1, (int) config('assinavelox.api.idempotency.ttl_hours', 24));

        // Chave vencida: libera o índice e a chave passa a valer como nova.
        if ($existing->created_at !== null && $existing->created_at->lt(Carbon::now()->subHours($ttlHours))) {
            $existing->forceFill(['idempotency_key_digest' => null, 'request_fingerprint' => null])->save();

            return null;
        }

        return $existing;
    }

    /**
     * @return array{session: EmbeddedSigningSession, url: string, replayed: bool}
     */
    private function replay(EmbeddedSigningSession $existing, Recipient $recipient, Envelope $envelope, mixed $currentLinkId, ?string $fingerprint, int $ttl): array
    {
        if ($existing->request_fingerprint === null || $fingerprint === null || ! hash_equals($existing->request_fingerprint, $fingerprint)
            || $existing->recipient_id !== $recipient->getKey()) {
            throw new EmbedRejected('idempotency-key-reused', 'Esta Idempotency-Key já foi usada com outro pedido. Gere uma chave nova para um pedido diferente.');
        }

        // Vencida SEM uso pode ser reemitida (o cliente perdeu a resposta e demorou a repetir);
        // usada, revogada, com desfecho ou presa a um convite que já não vale (reenvio, troca de
        // e-mail), nunca — a URL nova apontaria para um convite morto.
        if ($existing->used_at !== null || $existing->revoked_at !== null || $existing->outcome !== null
            || (int) $existing->access_link_id !== (int) $currentLinkId) {
            throw new EmbedRejected('embedded-session-closed', 'A sessão criada com esta Idempotency-Key já foi usada ou encerrada. Use outra chave para criar uma sessão nova.');
        }

        $raw = SignerTokens::generate();

        $existing->forceFill([
            'token_digest' => SignerTokens::digest($raw),
            'expires_at' => Carbon::now()->addSeconds($ttl),
        ])->save();

        EnvelopeAudit::record($envelope, AuditEventType::EmbeddedSessionCreated, [
            'session' => $existing->ulid,
            'origin' => $existing->allowed_origin,
            'expires_at' => $existing->expires_at->toIso8601String(),
            'channel' => 'api',
            'reissued' => true,
        ], $recipient);

        return ['session' => $existing, 'url' => self::urlFor($existing, $raw), 'replayed' => true];
    }

    private function assertBelowLiveCap(Recipient $recipient): void
    {
        $max = max(1, (int) config('assinavelox.embedded_signing.max_live_per_recipient', 5));
        $now = Carbon::now();

        $live = EmbeddedSigningSession::withoutGlobalScopes()
            ->where('recipient_id', $recipient->getKey())
            ->whereNull('revoked_at')
            ->whereNull('outcome')
            ->where(function ($query) use ($now): void {
                $query->where(function ($pending) use ($now): void {
                    $pending->whereNull('used_at')->where('expires_at', '>', $now);
                })->orWhere('runtime_expires_at', '>', $now);
            })
            ->count();

        if ($live >= $max) {
            throw new EmbedRejected('too-many-sessions', sprintf('Este participante já tem %d sessões embutidas utilizáveis. Revogue uma ou espere vencerem.', $live), 409);
        }
    }
}
