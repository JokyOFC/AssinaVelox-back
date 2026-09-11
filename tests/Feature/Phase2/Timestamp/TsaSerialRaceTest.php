<?php

use App\Services\Timestamp\Models\OperatorTsaIssuance;
use App\Services\Timestamp\OperatorTsaSerials;
use Illuminate\Database\UniqueConstraintViolationException;
use Symfony\Component\Process\Process;
use Tests\Feature\Pdf\Support\PdfFixtures;

/*
|--------------------------------------------------------------------------
| Número de série da TSA: sequência do banco, única e sem reutilização
|--------------------------------------------------------------------------
*/

it('reserva seriais distintos e crescentes, e um serial de emissão falha nunca volta', function () {
    $serials = app(OperatorTsaSerials::class);

    $first = collect(range(1, 5))->map(fn () => $serials->reserve('provider'))->all();
    $numbers = array_map(fn (OperatorTsaIssuance $row): int => (int) $row->serial, $first);

    expect($numbers)->toBe(array_values(array_unique($numbers)))
        ->and($numbers)->toBe(collect($numbers)->sort()->values()->all());

    $serials->markFailed($first[4], 'timeout');
    $next = $serials->reserve('provider');

    expect((int) $next->serial)->toBeGreaterThan(max($numbers))
        ->and(OperatorTsaIssuance::query()->where('serial', $first[4]->serial)->value('status'))->toBe('failed');
});

it('respeita o deslocamento configurado', function () {
    config()->set('assinavelox.tsa.serial_offset', 1_000_000);

    $row = app(OperatorTsaSerials::class)->reserve('provider');

    expect((int) $row->serial)->toBe(1_000_000 + (int) $row->getKey());
});

it('o índice único recusa um serial repetido (segunda barreira)', function () {
    $row = app(OperatorTsaSerials::class)->reserve('provider');

    expect(fn () => OperatorTsaIssuance::query()->create([
        'serial' => $row->serial,
        'purpose' => 'provider',
        'status' => 'reserved',
        'environment' => 'test',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('quatro processos disputando o mesmo banco nunca recebem o mesmo serial', function () {
    $work = PdfFixtures::workspace();
    $database = $work.DIRECTORY_SEPARATOR.'race.sqlite';
    touch($database);
    $script = __DIR__.'/Support/serial_race.php';
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array'];

    try {
        (new Process([PHP_BINARY, $script, $database, 'migrate'], base_path(), $env, null, 120))->mustRun();

        $processes = [];
        foreach (range(1, 4) as $index) {
            $process = new Process([PHP_BINARY, $script, $database, 'reserve', '15'], base_path(), $env, null, 180);
            $process->start();
            $processes[] = $process;
        }

        $all = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $all = [...$all, ...json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR)];
        }

        $numbers = array_map('intval', $all);
        sort($numbers);

        expect($numbers)->toHaveCount(60)
            ->and(array_unique($numbers))->toHaveCount(60)
            ->and($numbers)->toBe(range(1, 60));
    } finally {
        PdfFixtures::cleanup($work);
    }
});
