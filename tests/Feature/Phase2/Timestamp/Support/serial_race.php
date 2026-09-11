<?php

/*
| Ferramenta de TESTE (não é da aplicação): processo independente que reserva seriais da TSA
| num banco SQLite em arquivo compartilhado com outros processos — a corrida é real.
|
| Uso: php serial_race.php <caminho.sqlite> migrate
|      php serial_race.php <caminho.sqlite> reserve <quantidade>
| Saída (reserve): JSON com a lista de seriais obtidos.
*/

use App\Services\Timestamp\OperatorTsaSerials;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 5);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $database, $mode] = $argv + [null, null, null];

config()->set('database.default', 'ktsa_race');
config()->set('database.connections.ktsa_race', [
    'driver' => 'sqlite',
    'database' => $database,
    'prefix' => '',
    'foreign_key_constraints' => false,
    'busy_timeout' => 30000,
    'journal_mode' => 'wal',
]);
DB::purge('ktsa_race');

if ($mode === 'migrate') {
    $migration = require $root.'/database/migrations/2026_09_11_130101_create_operator_tsa_issuances_table.php';
    $migration->up();
    echo json_encode(['ok' => true]);
    exit(0);
}

$count = max(1, (int) ($argv[3] ?? 10));
$serials = [];

for ($i = 0; $i < $count; $i++) {
    $serials[] = app(OperatorTsaSerials::class)->reserve('race', null, 'race-'.getmypid())->serial;
}

echo json_encode($serials);
