<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\ActorType;
use App\Enums\ApiAbility;
use App\Models\AuditEvent;
use App\Services\Api\ApiContext;
use App\Services\Api\ApiFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Evento da trilha de auditoria do envelope na API v1: tipo estável
 * (App\Enums\AuditEventType), rótulo PT-BR, tom e quem agiu.
 *
 * O PAYLOAD do evento, o IP e o user-agent nunca saem — a trilha completa (com IP mascarado
 * conforme a organização) continua na página de evidências.
 *
 * @mixin AuditEvent
 */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AuditEvent $event */
        $event = $this->resource;

        $recipient = $event->relationLoaded('recipient') ? $event->getRelation('recipient') : null;
        $user = $event->relationLoaded('actorUser') ? $event->getRelation('actorUser') : null;

        return [
            'id' => $event->ulid,
            'object' => 'event',
            'type' => $event->event_type->value,
            'label' => $event->event_type->label(),
            'kind' => $event->event_type->kind(),
            'actor' => [
                'type' => $event->actor_type->value,
                'name' => match ($event->actor_type) {
                    ActorType::User => $user?->getAttribute('name'),
                    // Nome de participante é dado de `recipients:read` (o `recipient_id` continua).
                    ActorType::Recipient => ApiContext::allows(ApiAbility::RecipientsRead, $request) ? $recipient?->getAttribute('name') : null,
                    ActorType::System => null,
                },
            ],
            'recipient_id' => $recipient?->getAttribute('ulid'),
            'occurred_at' => ApiFormat::date($event->occurred_at),
        ];
    }
}
