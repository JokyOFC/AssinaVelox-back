<?php

namespace App\Services\Batch;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\User;
use App\Services\Batch\Models\BatchSigningItem;
use App\Services\Batch\Models\BatchSigningSession;
use App\Services\Batch\Notifications\BatchLinkNotification;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\InPerson\ParticipantContexts;
use App\Services\InPerson\ParticipantSigningProps;
use App\Services\InPerson\PresenceFeatures;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerRequestFacts;
use App\Services\Signing\SignerTokens;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Emissão e resolução do link de assinatura em lote (docs/fase-2/presencial-e-lote.md §3.2).
 *
 * ## Quem entra no lote
 *
 * Participações pendentes da MESMA organização remetente com o MESMO e-mail (decisão
 * recomendada na viabilidade §4.5): papel que registra aceite (signatário, testemunha,
 * aprovador), envelope `in_progress`, a vez da pessoa e convite ativo — as mesmas regras que
 * abririam o link individual ({@see ParticipantContexts}). Documento de outra organização
 * nunca entra, mesmo com o mesmo e-mail.
 *
 * Os itens são CONGELADOS na emissão (`batch_signing_items`): um documento enviado depois não
 * entra no lote, porque ninguém consentiu com ele. O link é "a lista destes documentos", não
 * "tudo o que vier para este e-mail".
 *
 * ## O link
 *
 * 32 bytes aleatórios em base64url; o banco guarda só o digest. Emitir outro link para a
 * mesma pessoa na mesma organização revoga o anterior. Vale `batch_signing.link_ttl_days`
 * dias e morre se o e-mail do participante mudar (`email_digest` deixa de bater).
 */
final class BatchLinks
{
    public function __construct(
        private readonly Repository $config,
        private readonly ParticipantContexts $contexts,
    ) {}

    public static function emailDigest(string $email): string
    {
        return hash_hmac(
            'sha256',
            mb_strtolower(trim($email)),
            hash('sha256', 'assinavelox:batch-email:v1|'.(string) config('app.key'), true),
        );
    }

    public function minItems(): int
    {
        return max(2, (int) $this->config->get('assinavelox.batch_signing.min_items', 2));
    }

    public function maxItems(): int
    {
        return max($this->minItems(), (int) $this->config->get('assinavelox.batch_signing.max_items', 50));
    }

    public function linkTtlDays(): int
    {
        return max(1, (int) $this->config->get('assinavelox.batch_signing.link_ttl_days', 7));
    }

    /**
     * Participações que entrariam num lote para este e-mail nesta organização.
     *
     * @return list<array{recipient: Recipient, context: SignerContext}>
     */
    public function eligible(Organization $organization, string $email): array
    {
        $envelopes = Envelope::withoutOrganizationScope()
            ->select('id')
            ->where('organization_id', $organization->getKey())
            ->where('status', EnvelopeStatus::InProgress->value);

        $candidates = Recipient::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->whereIn('role', RecipientRole::participatingValues())
            ->whereIn('status', [RecipientStatus::Pending->value, RecipientStatus::Notified->value, RecipientStatus::Viewed->value])
            ->whereIn('envelope_id', $envelopes)
            ->orderBy('id')
            ->limit($this->maxItems() * 2)
            ->get();

        $eligible = [];

        foreach ($candidates as $recipient) {
            $context = $this->contexts->for($recipient);

            if ($context === null || ! $context->isActive() || $context->action() === null) {
                continue;
            }

            if ($context->organization->getKey() !== $organization->getKey()) {
                continue;
            }

            $eligible[] = ['recipient' => $context->recipient, 'context' => $context];

            if (count($eligible) >= $this->maxItems()) {
                break;
            }
        }

        return $eligible;
    }

    /**
     * Emite o link de lote para o participante `$anchor` do envelope `$envelope`.
     *
     * @return array{batch: BatchSigningSession, items: int}
     *
     * @throws SigningRejectedException
     */
    public function issue(Envelope $envelope, Recipient $anchor, User $by, Request $request): array
    {
        if ($anchor->envelope_id !== $envelope->getKey() || $anchor->organization_id !== $envelope->organization_id) {
            throw new SigningRejectedException('not_found', 'Participante não encontrado neste documento.', status: 404);
        }

        /** @var Organization $organization */
        $organization = Organization::query()->whereKey($envelope->organization_id)->firstOrFail();

        $anchorContext = $this->contexts->for($anchor);

        if ($anchorContext === null || ! $anchorContext->isActive() || $anchorContext->action() === null) {
            throw SigningRejectedException::conflict(
                'not_pending',
                'Esta pessoa não tem aceite pendente neste documento agora (já concluiu, ainda não é a vez dela ou o documento foi encerrado).',
            );
        }

        $eligible = $this->eligible($organization, $anchor->email);

        if (count($eligible) < $this->minItems()) {
            throw SigningRejectedException::conflict(
                'not_enough_items',
                sprintf(
                    '%s tem só %d documento pendente nesta conta. O lote serve para dois ou mais documentos: use o reenvio do convite.',
                    ParticipantSigningProps::firstName($anchor->name),
                    count($eligible),
                ),
            );
        }

        $raw = SignerTokens::generate();
        $digest = self::emailDigest($anchor->email);
        $now = Carbon::now();

        /** @var BatchSigningSession $batch */
        $batch = DB::transaction(function () use ($organization, $anchor, $by, $request, $raw, $digest, $now, $eligible): BatchSigningSession {
            // Um link de lote vivo por pessoa e organização: o novo substitui o anterior.
            BatchSigningSession::withoutOrganizationScope()
                ->where('organization_id', $organization->getKey())
                ->where('email_digest', $digest)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $now,
                    'status' => BatchSigningSession::STATUS_REVOKED,
                    'session_token_digest' => null,
                    'updated_at' => $now,
                ]);

            /** @var BatchSigningSession $batch */
            $batch = BatchSigningSession::query()->create([
                'organization_id' => $organization->getKey(),
                'anchor_recipient_id' => $anchor->getKey(),
                'email_digest' => $digest,
                'token_digest' => SignerTokens::digest($raw),
                'status' => BatchSigningSession::STATUS_PENDING,
                'issued_by_user_id' => $by->getKey(),
                'expires_at' => $now->copy()->addDays($this->linkTtlDays()),
                'ip_address' => SignerRequestFacts::ip($request),
                'user_agent' => SignerRequestFacts::userAgent($request),
            ]);

            foreach ($eligible as $index => $row) {
                BatchSigningItem::query()->create([
                    'batch_signing_session_id' => $batch->getKey(),
                    'organization_id' => $organization->getKey(),
                    'envelope_id' => $row['recipient']->envelope_id,
                    'recipient_id' => $row['recipient']->getKey(),
                    'position' => $index + 1,
                    'status' => BatchSigningItem::STATUS_PENDING,
                ]);
            }

            return $batch;
        });

        $correlationId = SignerTokens::correlationId();

        // O link em claro existe só aqui e no corpo do e-mail (fila cifrada).
        Notification::route('mail', $anchor->email)->notify(new BatchLinkNotification(
            organization: $organization,
            recipientName: $anchor->name,
            toAddress: $anchor->email,
            url: route('sign.batch.show', ['token' => $raw]),
            count: count($eligible),
            expiresAt: $batch->expires_at,
            correlationId: $correlationId,
        ));

        EnvelopeAudit::record($envelope, AuditEventType::BatchLinkIssued, [
            'batch' => $batch->ulid,
            'items' => count($eligible),
            'expires_at' => $batch->expires_at->toIso8601String(),
        ], $anchor, $correlationId);

        return ['batch' => $batch, 'items' => count($eligible)];
    }

    /**
     * Link de lote válido para este token bruto, ou null (404 genérico para qualquer motivo).
     */
    public function resolve(string $raw): ?BatchSigningSession
    {
        if (strlen($raw) < 20 || strlen($raw) > 128) {
            return null;
        }

        /** @var BatchSigningSession|null $batch */
        $batch = BatchSigningSession::withoutOrganizationScope()
            ->where('token_digest', SignerTokens::digest($raw))
            ->first();

        if ($batch === null || ! SignerTokens::matches($batch->token_digest, $raw) || ! $batch->isUsable()) {
            return null;
        }

        /** @var Recipient|null $anchor */
        $anchor = $batch->anchor_recipient_id === null
            ? null
            : Recipient::withoutOrganizationScope()->whereKey($batch->anchor_recipient_id)->first();

        if ($anchor === null
            || $anchor->organization_id !== $batch->organization_id
            || ! hash_equals($batch->email_digest, self::emailDigest($anchor->email))) {
            return null;
        }

        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($batch->organization_id)->first();

        if (! PresenceFeatures::batchSigning($organization)) {
            return null;
        }

        return $batch;
    }
}
