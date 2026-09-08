<?php

namespace App\Http\Resources;

use App\Models\MembershipInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Convite pendente na tabela de usuários (ROUTES §2.10 → types/models.ts `Invitation`).
 * Espera `inviter` carregado.
 *
 * @mixin MembershipInvitation
 */
class MembershipInvitationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'status' => $this->status->value,
            'sent_at' => ($this->updated_at ?? $this->created_at)->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'invited_by' => UserRefResource::ref($this->inviter) ?? ['id' => '', 'name' => 'AssinaVelox', 'initials' => 'AV'],
        ];
    }
}
