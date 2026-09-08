<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notificação in-app (canal database) para o popover do sino (types/models.ts `AppNotification`).
 * `data` esperado: { title, body?, url?, organization_id? }.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = (array) $this->data;

        return [
            'id' => (string) $this->id,
            'title' => (string) ($data['title'] ?? 'Notificação'),
            'body' => isset($data['body']) ? (string) $data['body'] : null,
            'url' => isset($data['url']) ? (string) $data['url'] : null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
