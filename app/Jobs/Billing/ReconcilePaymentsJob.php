<?php

namespace App\Jobs\Billing;

use App\Models\User;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\ReconcilePayments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Conciliação diária (Fase 2, onda D). Com a flag `extended_payments` desligada não faz nada.
 *
 * Agendamento: `routes/console.php` está fora da área desta onda — a linha a acrescentar é
 * `Schedule::job(new ReconcilePaymentsJob)->dailyAt('04:10')->onOneServer();` (pendência
 * registrada em docs/fase-2/pagamentos-e-fiscal.md §5). O painel interno também dispara uma
 * execução sob demanda ("Conciliar agora").
 */
class ReconcilePaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  'schedule'|'admin'  $trigger
     */
    public function __construct(
        public readonly string $trigger = 'schedule',
        public readonly ?int $userId = null,
    ) {
        $this->onQueue(app(BillingSettings::class)->queue());
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('billing-reconciliation'))->dontRelease()->expireAfter(900)];
    }

    public function handle(ReconcilePayments $reconcile, BillingSettings $settings): void
    {
        if (! $settings->extendedPayments()) {
            return;
        }

        $reconcile->run($this->trigger, $this->userId !== null ? User::query()->find($this->userId) : null);
    }
}
