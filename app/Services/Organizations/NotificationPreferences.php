<?php

namespace App\Services\Organizations;

use App\Models\Membership;

/**
 * Preferências de notificação por membership (usuário × organização), eventos × canais.
 *
 * Armazenamento: coluna JSON `memberships.notification_preferences` (migration B2
 * 2026_09_08_200001). O model Membership (área B1) não declara cast para a coluna, por isso
 * a (de)serialização é feita aqui — toda leitura/escrita passa por esta classe.
 */
class NotificationPreferences
{
    public const COLUMN = 'notification_preferences';

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
     * @return list<string>
     */
    public static function events(): array
    {
        return array_keys(self::catalog());
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
            $channels = array_key_exists($event, $stored) ? (array) $stored[$event] : $definition['default'];
            $result[$event] = array_values(array_intersect(self::CHANNELS, $channels));
        }

        return $result;
    }

    /**
     * Linhas no formato consumido por pages/settings/notifications.tsx.
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
     * Usuário quer receber `$event` por `$channel`?
     */
    public function wants(Membership $membership, string $event, string $channel): bool
    {
        return in_array($channel, $this->for($membership)[$event] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $preferences
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

        $membership->forceFill([self::COLUMN => json_encode($clean, JSON_THROW_ON_ERROR)])->save();
    }

    /**
     * @return array<string, list<string>>
     */
    protected function stored(Membership $membership): array
    {
        $raw = $membership->getAttribute(self::COLUMN);

        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
