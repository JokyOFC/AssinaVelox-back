<?php

namespace App\Http\Resources;

use App\Models\Recipient;
use App\Services\Signing\Channels\RecipientChannels;
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
        /** @var Recipient $recipient */
        $recipient = $this->resource;

        // Fase 2 §2.9 (C-CAN, docs/fase-2/canais-e-pin.md §9.1): celular, canal do convite,
        // método do código e `has_pin`. O PIN nunca volta. Participante da Fase 1: canal
        // `email`, método `email_otp`, sem telefone e sem PIN — os mesmos valores de antes.
        $channels = app(RecipientChannels::class)->wizardFields($recipient);

        return [
            'id' => $this->ulid,
            'client_id' => $this->ulid,
            'name' => $this->name,
            'email' => $this->email,
            'role' => (string) ($this->role_label ?? ''),
            'order' => (int) $this->order_index,
            'color_index' => $this->colorIndex,
            'channel' => $channels['channel'],
            'auth_methods' => [$this->auth_method->value],
            // Fase 2 §2.4: papel de domínio (o `role` acima é o rótulo livre).
            'participant_role' => $this->role->value,
            'participant_role_label' => $this->role->label(),
            'phone' => $channels['phone'],
            'phone_masked' => $channels['phone_masked'],
            'auth_method' => $channels['auth_method'],
            'auth_method_label' => $channels['auth_method_label'],
            'has_pin' => $channels['has_pin'],
        ];
    }
}
