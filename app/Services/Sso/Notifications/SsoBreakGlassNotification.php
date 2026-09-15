<?php

namespace App\Services\Sso\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Alerta de uso do acesso de emergência ("break-glass", docs/fase-3/sso.md §5): um owner entrou
 * por senha + 2FA numa organização com login corporativo obrigatório. Vai para os demais owners
 * e admins no sino de notificações (canal `database`), junto do evento `sso.break_glass_used`.
 * Só escalares; nada de e-mail, IP completo ou segredo.
 */
class SsoBreakGlassNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationName,
        public readonly string $actorName,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sso_break_glass',
            'organization_id' => $this->organizationId,
            'title' => 'Acesso de emergência usado',
            'body' => $this->actorName.' entrou em '.$this->organizationName.' com senha e 2FA, sem o login corporativo obrigatório.',
            'url' => route('settings.sso'),
        ];
    }
}
