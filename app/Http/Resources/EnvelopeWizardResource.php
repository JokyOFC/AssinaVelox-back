<?php

namespace App\Http\Resources;

use App\Models\Envelope;
use App\Support\OrganizationSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cabeçalho do wizard (ROUTES §2.6 `WizardProps.envelope`).
 *
 * @mixin Envelope
 */
class EnvelopeWizardResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = OrganizationSettings::of($this->organization);

        return [
            'id' => $this->ulid,
            'display_code' => $this->display_code,
            'status' => $this->status->value,
            'title' => $this->title,
            'folder_id' => $this->folder?->ulid,
            'expires_in_days' => (int) $this->setting('expiration_days', $settings->defaultExpirationDays()),
            'message' => (string) ($this->message ?? ''),
            'signing_order' => $this->signing_order->value,
            'send_copy_to_all' => (bool) $this->setting('send_copy_to_all', false),
            'initials_on_all_pages' => (bool) $this->setting('initials_on_all_pages', $settings->initialsOnAllPages()),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
