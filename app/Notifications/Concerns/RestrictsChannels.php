<?php

namespace App\Notifications\Concerns;

/**
 * Permite que o serviço que dispara a notificação limite os canais às preferências do
 * usuário (`memberships.notification_preferences`, ROUTES §2.14) sem que a notificação
 * precise consultar o banco dentro de `via()`.
 *
 * Sem restrição, `via()` devolve todos os canais que a notificação suporta.
 */
trait RestrictsChannels
{
    /** @var list<string>|null canais lógicos permitidos ('mail', 'database') */
    public ?array $onlyChannels = null;

    /**
     * @param  list<string>  $channels
     */
    public function restrictChannels(array $channels): static
    {
        $this->onlyChannels = $channels;

        return $this;
    }

    /**
     * @param  array<string, string>  $map  canal lógico => driver do Laravel
     * @return list<string>
     */
    protected function channelsFrom(array $map): array
    {
        if ($this->onlyChannels === null) {
            return array_values($map);
        }

        return array_values(array_intersect_key($map, array_flip($this->onlyChannels)));
    }
}
