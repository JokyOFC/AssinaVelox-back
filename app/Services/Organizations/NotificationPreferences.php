<?php

namespace App\Services\Organizations;

use App\Models\Membership;
use App\Models\Organization;

/**
 * Preferências de notificação por membership (usuário × organização), eventos × canais.
 *
 * ARMAZENAMENTO (decisão B2, ver relatório): o alvo definido é a coluna JSON
 * `memberships.notification_preferences`, que ainda não existe na camada de dados
 * entregue por B1. Até a migration ser adicionada, as preferências ficam em
 * `organizations.settings['notification_preferences'][user_id]`. Toda leitura/escrita
 * passa por esta classe, então a troca de armazenamento é local a este arquivo.
 */
class NotificationPreferences
{
    public const SETTINGS_KEY = 'notification_preferences';

    /** @var list<string> */
    public const CHANNELS = ['mail', 'database'];

    /**
     * Catálogo de eventos (ROUTES §2.14 / DESIGN §6.11) com canais padrão e travas.
     *
     * @return array<string, array{label: string, description: string, default: list<string>, locked: array<string, bool>}>
     */
    public static function catalog(): array
    {
        return [
            'recipient_signed' => [
                'label' => 'Signatário assinou',
                'description' => 'A cada assinatura concluída',
                'default' => ['mail', 'database'],
                'locked' => [],
            ],
            'envelope_completed' => [
                'label' => 'Documento concluído',
                'description' => 'Todos assinaram; PDF final disponível',
                'default' => ['mail', 'database'],
                'locked' => [],
            ],
            'recipient_refused' => [
                'label' => 'Signatário recusou',
                'description' => 'Inclui o motivo informado',
                'default' => ['mail', 'database'],
                'locked' => [],
            ],
            'envelope_expiring' => [
                'label' => 'Documento expira em 48 h',
                'description' => 'Ainda com pendências',
                'default' => ['mail', 'database'],
                'locked' => [],
            ],
            'daily_digest' => [
                'label' => 'Resumo diário de pendências',
                'description' => 'Um e-mail por dia útil',
                'default' => ['mail'],
                'locked' => ['database' => true],
            ],
            'invitation_accepted' => [
                'label' => 'Convite de usuário aceito',
                'description' => 'Novo membro entrou na conta',
                'default' => ['mail', 'database'],
                'locked' => [],
            ],
            'product_news' => [
                'label' => 'Novidades do produto',
                'description' => 'Lançamentos e melhorias, no máximo 1×/mês',
                'default' => ['database'],
                'locked' => ['mail' => true],
            ],
        ];
    }

    /**
     * Preferências efetivas (padrões sobrescritos pelo que o usuário salvou).
     *
     * @return array<string, list<string>>
     */
    public function for(Membership $membership): array
    {
        $stored = $this->stored($membership);
        $result = [];

        foreach (self::catalog() as $event => $definition) {
            $channels = $stored[$event] ?? $definition['default'];
            $result[$event] = array_values(array_intersect(self::CHANNELS, (array) $channels));
        }

        return $result;
    }

    /**
     * Linhas no formato consumido por pages/settings/Notifications.tsx.
     *
     * @return array<int, array{key: string, label: string, description: string, channels: array<string, bool>, locked: array<string, bool>}>
     */
    public function rows(Membership $membership): array
    {
        $effective = $this->for($membership);
        $rows = [];

        foreach (self::catalog() as $event => $definition) {
            $rows[] = [
                'key' => $event,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'channels' => [
                    'mail' => in_array('mail', $effective[$event], true),
                    'database' => in_array('database', $effective[$event], true),
                ],
                'locked' => $definition['locked'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, list<string>>  $preferences
     */
    public function save(Membership $membership, array $preferences): void
    {
        $clean = [];

        foreach (self::catalog() as $event => $definition) {
            $channels = array_values(array_intersect(self::CHANNELS, (array) ($preferences[$event] ?? [])));

            // Canais travados nunca são ativados pelo usuário.
            foreach ($definition['locked'] as $channel => $locked) {
                if ($locked) {
                    $channels = array_values(array_diff($channels, [$channel]));
                }
            }

            $clean[$event] = $channels;
        }

        $organization = $membership->organization()->firstOrFail();
        $settings = $organization->settings ?? [];
        $all = $settings[self::SETTINGS_KEY] ?? [];
        $all[(string) $membership->user_id] = $clean;
        $settings[self::SETTINGS_KEY] = $all;

        $organization->forceFill(['settings' => $settings])->save();
    }

    /**
     * @return array<string, list<string>>
     */
    protected function stored(Membership $membership): array
    {
        $organization = $membership->relationLoaded('organization')
            ? $membership->organization
            : $membership->organization()->first();

        if (! $organization instanceof Organization) {
            return [];
        }

        $all = ($organization->settings ?? [])[self::SETTINGS_KEY] ?? [];

        return (array) ($all[(string) $membership->user_id] ?? []);
    }
}
