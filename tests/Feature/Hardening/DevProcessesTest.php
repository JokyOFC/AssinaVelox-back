<?php

use App\Services\Billing\BillingSettings;
use App\Support\Queues;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| `composer run dev` sobe tudo o que o desenvolvimento precisa
|--------------------------------------------------------------------------
| O `composer run dev` (= `php artisan dev`) subia o Horizon no lugar do worker de fila: o
| pacote se inscreve sozinho no comando e tira de lá o worker do framework. Sem Redis, o
| Horizon caía na hora e nada da fila rodava — upload em "processando", código do signatário
| sem envio, envelope sem finalizar, e nenhum erro na tela. O worker do framework também não
| resolvia, porque só escuta a fila `default`.
|
| Ver AppServiceProvider::configureDevProcesses e App\Support\Queues.
*/

/**
 * Processos que o `php artisan dev` sobe, indexados pelo nome.
 *
 * @return array<string, string>
 */
function composerDevProcesses(): array
{
    Artisan::call('dev:list', ['--json' => true]);

    /** @var list<array{name: string, command: string}> $processes */
    $processes = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return array_column($processes, 'command', 'name');
}

test('sobe servidor, Vite, worker de fila e agendador, e não o Horizon', function () {
    $processes = composerDevProcesses();

    // Sem `toHaveCount`: onde há pcntl (Linux, macOS) o framework acrescenta o `pail`.
    expect($processes)->toHaveKeys(['server', 'vite', 'queue', 'scheduler'])
        ->not->toHaveKey('horizon')
        ->and($processes['scheduler'])->toBe('php artisan schedule:work');
});

test('o worker escuta todas as filas da aplicação', function () {
    expect(composerDevProcesses()['queue'])->toBe(
        'php artisan queue:listen --queue='.implode(',', Queues::all()).' --tries=1 --timeout=0',
    );
});

test('lê os nomes das filas da configuração, sem repetir nem deixar vazio, em ordem de prioridade', function () {
    config([
        'assinavelox.webhooks.queue' => 'webhooks',
        'assinavelox.hubspot.queue' => '',
        'queue.default' => 'database',
        'queue.connections.database.queue' => 'principal',
    ]);

    expect(Queues::all())->toBe([
        'notifications', 'conversions', 'default', 'finalization', 'billing', 'anchors', 'ocr', 'webhooks', 'principal',
    ]);
});

test('toda fila escolhida por um job está na lista do worker', function () {
    $outside = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $path = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
        $source = $file->getContents();

        // Outras formas de escolher a fila passariam por fora da conferência abaixo.
        if (preg_match('/function viaQueues\b|->allOnQueue\(|public\s+(\??string\s+)?\$queue\s*=/', $source) === 1) {
            $outside[] = "{$path}: escolhe a fila de um jeito que este teste não confere";
        }

        preg_match_all('/->onQueue\((.+?)\);/s', $source, $calls);

        foreach ($calls[1] as $argument) {
            if (str_contains($argument, 'BillingSettings::class)->queue()')) {
                continue; // conferida no fim do teste
            }

            if (preg_match("/^\\(string\\) config\\('([^']+)'/", $argument, $key) === 1
                && in_array($key[1], Queues::CONFIG_KEYS, true)) {
                continue;
            }

            if (preg_match("/^'([^']+)'$/", $argument, $literal) === 1
                && in_array($literal[1], Queues::all(), true)) {
                continue;
            }

            $outside[] = "{$path}: onQueue({$argument})";
        }
    }

    expect($outside)->toBe([])
        ->and(Queues::all())->toContain(app(BillingSettings::class)->queue());
});
