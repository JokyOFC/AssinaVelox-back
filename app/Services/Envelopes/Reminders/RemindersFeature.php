<?php

namespace App\Services\Envelopes\Reminders;

use App\Models\Organization;
use App\Models\Plan;
use App\Services\Plans\PlanLedger;

/**
 * Flag `features.reminders` (roadmap §1 T8, §2.5) — lembretes automáticos E envio agendado.
 *
 * Duas condições, as duas obrigatórias:
 *
 *  1. **interruptor global** `assinavelox.features.reminders` (padrão `false`): a instalação
 *     decide se o recurso existe;
 *  2. **plano** da organização: `plans.features.reminders = true`.
 *
 * A flag liga a interface e os agendadores. A autorização de cada ação continua nas
 * Policies (`EnvelopePolicy::send`/`update`, `OrganizationPolicy::updateSettings`). Com a
 * flag desligada nada deste item roda: o comando de lembretes não seleciona nada, o de
 * envio agendado cancela o que estiver pendente e avisa o remetente, e as rotas respondem 404.
 *
 * Memoriza por organização dentro da instância (um comando percorre muitos envelopes da
 * mesma organização). A classe não é singleton: cada job resolve uma instância nova.
 */
class RemindersFeature
{
    public const KEY = 'reminders';

    /** @var array<int, bool> */
    private array $memo = [];

    public function __construct(private readonly PlanLedger $ledger) {}

    public static function globallyEnabled(): bool
    {
        return filter_var(config('assinavelox.features.reminders', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function planIncludes(?Plan $plan): bool
    {
        $features = $plan->features ?? [];

        return (bool) ($features[self::KEY] ?? false);
    }

    public function enabledFor(Organization|int|null $organization): bool
    {
        if ($organization === null || ! self::globallyEnabled()) {
            return false;
        }

        $id = $organization instanceof Organization ? (int) $organization->getKey() : $organization;

        return $this->memo[$id] ??= self::planIncludes($this->ledger->subscriptionFor($id)?->plan);
    }
}
