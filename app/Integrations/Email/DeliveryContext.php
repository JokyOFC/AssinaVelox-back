<?php

namespace App\Integrations\Email;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Tudo que `delivery_attempts` precisa saber sobre uma mensagem, montado por quem
 * dispara a notificação e passado ao canal de envio.
 *
 * O `correlationId` é a chave de idempotência: repetir a mesma tentativa (retentativa de
 * fila, clique duplo) com o mesmo correlationId reaproveita a linha existente e não
 * duplica o envio quando ela já está `sent`/`delivered`.
 *
 * Nunca carrega token, código OTP ou corpo da mensagem — só metadados.
 */
final readonly class DeliveryContext
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public int $organizationId,
        public string $toAddress,
        public DeliveryPurpose $purpose,
        public string $correlationId,
        public ?int $envelopeId = null,
        public ?int $recipientId = null,
        public DeliveryChannel $channel = DeliveryChannel::Email,
        public array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function forRecipient(
        Recipient $recipient,
        DeliveryPurpose $purpose,
        ?string $correlationId = null,
        array $meta = [],
    ): self {
        return new self(
            organizationId: (int) $recipient->organization_id,
            toAddress: $recipient->email,
            purpose: $purpose,
            correlationId: $correlationId ?? (string) Str::ulid(),
            envelopeId: (int) $recipient->envelope_id,
            recipientId: $recipient->getKey(),
            meta: $meta,
        );
    }

    /**
     * Mensagem para um usuário da conta (remetente do envelope).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function forUser(
        User $user,
        Envelope $envelope,
        DeliveryPurpose $purpose,
        ?string $correlationId = null,
        array $meta = [],
    ): self {
        return new self(
            organizationId: (int) $envelope->organization_id,
            toAddress: $user->email,
            purpose: $purpose,
            correlationId: $correlationId ?? (string) Str::ulid(),
            envelopeId: $envelope->getKey(),
            recipientId: null,
            meta: $meta + ['audience' => 'sender'],
        );
    }

    public function withAddress(string $address): self
    {
        return new self(
            $this->organizationId,
            $address,
            $this->purpose,
            $this->correlationId,
            $this->envelopeId,
            $this->recipientId,
            $this->channel,
            $this->meta,
        );
    }
}
