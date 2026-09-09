<?php

use App\Jobs\Billing\SyncMercadoPagoPayment;
use App\Jobs\Envelopes\FinalizeEnvelope;
use App\Jobs\Envelopes\ResendPendingInvitations;

/*
|--------------------------------------------------------------------------
| retry_after × timeout dos jobs
|--------------------------------------------------------------------------
| Regra do Laravel que nenhum teste de comportamento pega: `retry_after` da
| conexão precisa ser MAIOR que o `$timeout` do job mais demorado daquela
| conexão. Menor, a fila devolve o job para outro worker enquanto a primeira
| execução ainda está rodando.
|
| Aqui isso custaria caro: FinalizeEnvelope leva até 600 s consolidando PDF,
| montando a página de evidências e assinando. Ele é idempotente e serializa
| por lock, então o resultado não corrompe — mas duplica o trabalho mais caro
| do sistema, e o sintoma (finalização lenta sob carga) não aponta para a
| causa. O valor esteve em 90 s durante toda a Fase 1.
*/

$jobs = [
    'FinalizeEnvelope' => FinalizeEnvelope::class,
    'ResendPendingInvitations' => ResendPendingInvitations::class,
    'SyncMercadoPagoPayment' => SyncMercadoPagoPayment::class,
];

$longest = 0;

foreach ($jobs as $class) {
    $longest = max($longest, (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? 0);
}

it('mantém retry_after acima do timeout do job mais longo', function (string $connection) use ($longest) {
    $retryAfter = config("queue.connections.{$connection}.retry_after");

    expect($retryAfter)->toBeInt()->toBeGreaterThan(
        $longest,
        "queue.connections.{$connection}.retry_after ({$retryAfter}s) precisa passar do job mais longo ({$longest}s)."
    );
})->with(['database', 'redis']);

it('mantém o timeout do supervisor de finalização igual ao do job', function () {
    expect(config('horizon.defaults.supervisor-finalization.timeout'))
        ->toBe((new ReflectionClass(FinalizeEnvelope::class))->getDefaultProperties()['timeout']);
});

it('mantém todo supervisor do Horizon dentro do retry_after da conexão', function () {
    /** @var array<string, array{connection?: string, timeout?: int}> $supervisors */
    $supervisors = config('horizon.defaults', []);

    foreach ($supervisors as $name => $supervisor) {
        $connection = $supervisor['connection'] ?? 'redis';
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

        expect((int) ($supervisor['timeout'] ?? 0))->toBeLessThan(
            $retryAfter,
            "O supervisor {$name} pode rodar mais que o retry_after de {$connection} ({$retryAfter}s)."
        );
    }
});
