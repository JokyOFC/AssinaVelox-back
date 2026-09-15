<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Model;

/**
 * Recipient (API v1).
 *
 * Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.
 */
final class Recipient implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw  Resposta original, com campos que esta versão do SDK ainda não conhece.
     */
    public function __construct(
        public readonly ?string $authMethod,
        public readonly ?string $email,
        public readonly string $id,
        public readonly ?string $label,
        public readonly ?string $name,
        public readonly int $notificationsCount,
        public readonly ?string $notifiedAt,
        public readonly string $object,
        public readonly int $order,
        public readonly ?string $phoneMasked,
        public readonly ?string $refusalReason,
        public readonly ?string $refusedAt,
        public readonly string $role,
        public readonly string $roleLabel,
        public readonly ?string $signedAt,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            authMethod: $data['auth_method'] ?? null,
            email: $data['email'] ?? null,
            id: $data['id'] ?? null,
            label: $data['label'] ?? null,
            name: $data['name'] ?? null,
            notificationsCount: $data['notifications_count'] ?? null,
            notifiedAt: $data['notified_at'] ?? null,
            object: $data['object'] ?? null,
            order: $data['order'] ?? null,
            phoneMasked: $data['phone_masked'] ?? null,
            refusalReason: $data['refusal_reason'] ?? null,
            refusedAt: $data['refused_at'] ?? null,
            role: $data['role'] ?? null,
            roleLabel: $data['role_label'] ?? null,
            signedAt: $data['signed_at'] ?? null,
            status: $data['status'] ?? null,
            statusLabel: $data['status_label'] ?? null,
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
