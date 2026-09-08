<?php

namespace App\Http\Resources;

use App\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Linha de membro (ROUTES §2.10 → types/models.ts `Membership`). Espera `user` carregado.
 * `last_seen_at` é null na Fase 1 (não há coluna de último acesso no schema de B1).
 *
 * @mixin Membership
 */
class MembershipResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $user = $this->user;

        return [
            'id' => (string) $this->getKey(),
            'user' => UserRefResource::ref($user, withEmail: true),
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'last_seen_at' => null,
            'is_me' => $actor !== null && $this->user_id === $actor->getKey(),
            'can' => [
                'change_role' => $actor?->can('update', $this->resource) ?? false,
                'suspend' => $actor?->can('updateStatus', $this->resource) ?? false,
                'remove' => $actor?->can('delete', $this->resource) ?? false,
                'transfer_ownership' => $actor?->can('transferOwnership', $this->resource) ?? false,
            ],
        ];
    }
}
