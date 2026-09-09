<?php

namespace App\Http\Resources;

use App\Models\Recipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Destinatário no wizard (ROUTES §2.6 `WizardRecipient`). `client_id` é o próprio ULID
 * para os já persistidos — o front usa um id temporário só enquanto a linha é nova.
 *
 * @mixin Recipient
 */
class RecipientWizardResource extends JsonResource
{
    public static $wrap = null;

    protected int $colorIndex = 0;

    public function withColorIndex(int $index): static
    {
        $this->colorIndex = $index % 4;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'client_id' => $this->ulid,
            'name' => $this->name,
            'email' => $this->email,
            'role' => (string) ($this->role_label ?? ''),
            'order' => (int) $this->order_index,
            'color_index' => $this->colorIndex,
            'channel' => 'email',
            'auth_methods' => [$this->auth_method->value],
        ];
    }
}
