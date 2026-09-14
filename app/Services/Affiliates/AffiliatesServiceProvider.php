<?php

namespace App\Services\Affiliates;

use App\Listeners\Affiliates\SyncCommissionsOnPaymentStatus;
use App\Models\Payment;
use App\Services\Affiliates\Console\SettleCommissionsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Programa de afiliados (Fase 3 §3.10, docs/fase-3/afiliados.md).
 *
 * - Gancho `eloquent.saved` de Payment → SyncCommissionsOnPaymentStatus (aprovado, estorno,
 *   contestação). Explícito porque a descoberta automática de eventos só enxerga eventos-classe.
 * - Comando `affiliates:settle` (varredura idempotente + aprovação das pendentes vencidas),
 *   agendado diariamente. Com a flag desligada, gancho e comando não fazem nada.
 *
 * Registro: `bootstrap/providers.php` (ver docs/fase-3/afiliados.md §9 — condição de ativação).
 */
final class AffiliatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AffiliateSettings::class);
    }

    public function boot(): void
    {
        Event::listen('eloquent.saved: '.Payment::class, [SyncCommissionsOnPaymentStatus::class, 'onPaymentSaved']);

        if ($this->app->runningInConsole()) {
            $this->commands([SettleCommissionsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('affiliates:settle')->dailyAt('05:20')->onOneServer()->withoutOverlapping();
        });
    }
}
