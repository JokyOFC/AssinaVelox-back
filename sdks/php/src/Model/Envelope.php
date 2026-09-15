<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Model;

/**
 * Envelope (API v1).
 *
 * Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.
 */
final class Envelope implements \JsonSerializable
{
    /**
     * @param  string|null  $cancelReason  Só no detalhe.
     * @param  list<Document>|null  $documents  Só no detalhe.
     * @param  int|null  $expirationDays  Só no detalhe.
     * @param  EnvelopeLinks|null  $links  Só no detalhe.
     * @param  string|null  $message  Só no detalhe.
     * @param  list<Recipient>|null  $recipients  Só no detalhe.
     * @param  bool|null  $sendCopyToAll  Só no detalhe.
     * @param  array<string, mixed>  $raw  Resposta original, com campos que esta versão do SDK ainda não conhece.
     */
    public function __construct(
        public readonly ?string $cancelReason,
        public readonly ?string $canceledAt,
        public readonly ?string $completedAt,
        public readonly string $createdAt,
        public readonly EnvelopeCreatedBy $createdBy,
        public readonly string $displayCode,
        public readonly ?array $documents,
        public readonly ?int $expirationDays,
        public readonly ?string $expiredAt,
        public readonly ?string $expiresAt,
        public readonly ?EnvelopeFolder $folder,
        public readonly string $id,
        public readonly ?EnvelopeLinks $links,
        public readonly ?string $message,
        public readonly string $object,
        public readonly ?array $recipients,
        public readonly int $recipientsCount,
        public readonly ?string $refusedAt,
        public readonly ?bool $sendCopyToAll,
        public readonly ?string $sentAt,
        public readonly ?string $signatureStatus,
        public readonly ?string $signatureStatusLabel,
        public readonly int $signedCount,
        public readonly string $signingOrder,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly string $title,
        public readonly string $updatedAt,
        public readonly ?string $verificationCode,
        public readonly int $viewersCount,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            cancelReason: $data['cancel_reason'] ?? null,
            canceledAt: $data['canceled_at'] ?? null,
            completedAt: $data['completed_at'] ?? null,
            createdAt: $data['created_at'] ?? null,
            createdBy: isset($data['created_by']) && is_array($data['created_by']) ? EnvelopeCreatedBy::fromArray($data['created_by']) : null,
            displayCode: $data['display_code'] ?? null,
            documents: isset($data['documents']) && is_array($data['documents']) ? Hydrator::listOf(Document::class, $data['documents']) : null,
            expirationDays: $data['expiration_days'] ?? null,
            expiredAt: $data['expired_at'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            folder: isset($data['folder']) && is_array($data['folder']) ? EnvelopeFolder::fromArray($data['folder']) : null,
            id: $data['id'] ?? null,
            links: isset($data['links']) && is_array($data['links']) ? EnvelopeLinks::fromArray($data['links']) : null,
            message: $data['message'] ?? null,
            object: $data['object'] ?? null,
            recipients: isset($data['recipients']) && is_array($data['recipients']) ? Hydrator::listOf(Recipient::class, $data['recipients']) : null,
            recipientsCount: $data['recipients_count'] ?? null,
            refusedAt: $data['refused_at'] ?? null,
            sendCopyToAll: $data['send_copy_to_all'] ?? null,
            sentAt: $data['sent_at'] ?? null,
            signatureStatus: $data['signature_status'] ?? null,
            signatureStatusLabel: $data['signature_status_label'] ?? null,
            signedCount: $data['signed_count'] ?? null,
            signingOrder: $data['signing_order'] ?? null,
            status: $data['status'] ?? null,
            statusLabel: $data['status_label'] ?? null,
            title: $data['title'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
            verificationCode: $data['verification_code'] ?? null,
            viewersCount: $data['viewers_count'] ?? null,
            raw: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
