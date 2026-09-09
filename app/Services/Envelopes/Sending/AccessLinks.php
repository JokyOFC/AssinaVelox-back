<?php

namespace App\Services\Envelopes\Sending;

use App\Enums\AccessLinkPurpose;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Services\Envelopes\Sending\Dto\IssuedLink;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Emissão, revogação e resolução dos links de convite/download (`recipient_access_links`).
 *
 * Ciclo de vida:
 *
 *   emitir ──▶ ativo ──┬── usar (last_used_at, use_count) ──▶ ativo
 *                      ├── expirar (expires_at do envelope)
 *                      └── revogar (novo link, e-mail alterado, cancelamento, expiração)
 *
 * Segredo: `random_bytes(32)` em base64url (43 caracteres, sem padding). O banco guarda
 * apenas `token_digest = sha256(token)`. O token em claro não aparece em log, evento de
 * auditoria, `delivery_attempts` nem notificação persistida — só no corpo do e-mail.
 *
 * Emitir um novo link REVOGA os anteriores do mesmo destinatário e propósito: existe no
 * máximo um link de assinatura ativo por signatário, e o e-mail mais recente é o que vale.
 */
class AccessLinks
{
    public const TOKEN_BYTES = 32;

    /**
     * Emite um link novo e revoga os anteriores do mesmo propósito.
     *
     * @param  CarbonInterface|null  $expiresAt  padrão: o prazo do envelope (null = sem prazo próprio)
     */
    public function issue(
        Recipient $recipient,
        AccessLinkPurpose $purpose = AccessLinkPurpose::Signing,
        ?CarbonInterface $expiresAt = null,
        ?Envelope $envelope = null,
    ): IssuedLink {
        $envelope ??= $recipient->relationLoaded('envelope')
            ? $recipient->envelope
            : Envelope::withoutOrganizationScope()->whereKey($recipient->envelope_id)->firstOrFail();

        $versionId = $envelope->sent_document_version_id;

        if ($versionId === null) {
            throw new RuntimeException('Não é possível emitir um link antes de congelar a versão enviada do documento.');
        }

        $token = $this->generateToken();

        $link = DB::transaction(function () use ($recipient, $envelope, $purpose, $expiresAt, $token, $versionId): RecipientAccessLink {
            $this->revokeFor($recipient, $purpose);

            return RecipientAccessLink::query()->create([
                'recipient_id' => $recipient->getKey(),
                'envelope_id' => $envelope->getKey(),
                'document_version_id' => $versionId,
                'organization_id' => $envelope->organization_id,
                'token_digest' => RecipientAccessLink::digestFor($token),
                'purpose' => $purpose,
                'expires_at' => $expiresAt ?? $envelope->expires_at,
            ]);
        });

        return new IssuedLink($link, $token, $this->urlFor($token, $purpose));
    }

    /**
     * URL pública do link. `signing` aponta para a página do signatário; `download` usa a
     * mesma rota (a página decide o que oferecer conforme o estado do envelope).
     */
    public function urlFor(string $token, AccessLinkPurpose $purpose = AccessLinkPurpose::Signing): string
    {
        return route('sign.show', ['token' => $token]);
    }

    /**
     * Revoga os links ativos de um destinatário. `null` em `$purpose` revoga todos.
     *
     * @return int quantidade revogada
     */
    public function revokeFor(Recipient $recipient, ?AccessLinkPurpose $purpose = null): int
    {
        $query = RecipientAccessLink::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->whereNull('revoked_at');

        if ($purpose !== null) {
            $query->where('purpose', $purpose->value);
        }

        return $query->update(['revoked_at' => Carbon::now()]);
    }

    /**
     * Revoga todos os links ativos do envelope (cancelamento, expiração, recusa).
     *
     * `$exceptRecipientIds` preserva os links de quem NÃO deve perder o acesso — na
     * expiração, quem já registrou o aceite: o link é a única porta para o próprio
     * comprovante, e revogá-lo apagava, para essa pessoa, a prova do que ela fez. O
     * resolver já impede que um envelope fora de `in_progress` volte a ser assinado, então
     * manter o link não reabre nada.
     *
     * @param  list<int>  $exceptRecipientIds
     * @return int quantidade revogada
     */
    public function revokeForEnvelope(Envelope $envelope, ?AccessLinkPurpose $purpose = null, array $exceptRecipientIds = []): int
    {
        $query = RecipientAccessLink::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereNull('revoked_at');

        if ($purpose !== null) {
            $query->where('purpose', $purpose->value);
        }

        if ($exceptRecipientIds !== []) {
            $query->whereNotIn('recipient_id', $exceptRecipientIds);
        }

        return $query->update(['revoked_at' => Carbon::now()]);
    }

    /**
     * Resolve o token bruto da URL para o link correspondente — CONTRATO da página pública.
     *
     * Devolve o link mesmo revogado/expirado: quem chama precisa distinguir "link inválido"
     * (404) de "prazo encerrado" (tela `expired`). Use `RecipientAccessLink::isUsable()`.
     * A comparação é por digest, então um token inexistente não gasta consulta extra.
     */
    public function resolve(string $token): ?RecipientAccessLink
    {
        if ($token === '') {
            return null;
        }

        return RecipientAccessLink::withoutOrganizationScope()
            ->where('token_digest', RecipientAccessLink::digestFor($token))
            ->first();
    }

    /**
     * Registra o uso do link (abertura da página). Não consome nem invalida nada.
     */
    public function markUsed(RecipientAccessLink $link): void
    {
        RecipientAccessLink::withoutOrganizationScope()
            ->whereKey($link->getKey())
            ->update([
                'last_used_at' => Carbon::now(),
                'use_count' => DB::raw('use_count + 1'),
            ]);
    }

    /**
     * Link de assinatura ativo do destinatário, se houver (sem o token — ele não é recuperável).
     */
    public function activeFor(Recipient $recipient, AccessLinkPurpose $purpose = AccessLinkPurpose::Signing): ?RecipientAccessLink
    {
        return RecipientAccessLink::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->where('purpose', $purpose->value)
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();
    }

    /**
     * 32 bytes aleatórios em base64url, sem padding — 43 caracteres em [A-Za-z0-9_-],
     * compatível com a restrição da rota `assinar/{token}`.
     */
    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }
}
