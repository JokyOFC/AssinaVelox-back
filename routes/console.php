<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Agendamento
|--------------------------------------------------------------------------
|
| Requer um cron chamando `php artisan schedule:run` a cada minuto (ver
| docs/configuracao.md). Todos os comandos são idempotentes e usam
| `withoutOverlapping()`, então uma execução travada não empilha trabalho.
|
| `expiration.batch_size` limita cada execução: uma varredura que encontra mais do que isso
| simplesmente continua na próxima rodada, 15 minutos depois.
*/

// Expiração dos envelopes vencidos (RECONCILIACAO Q22). A expiração também é revalidada a
// cada acesso do signatário — este agendamento mantém a lista do app coerente.
Schedule::command('envelopes:expire')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onOneServer();

// Aviso "seu prazo está acabando" (padrão: 48 h antes). Roda de hora em hora; a marca
// `settings.expiring_warned_at` garante um único aviso por envelope.
Schedule::command('envelopes:notify-expiring')
    ->hourly()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onOneServer();

// Inadimplência das assinaturas (RECONCILIACAO Q20): `past_due` depois da carência
// (bloqueia envio, mantém leitura e download) e `expired` depois, voltando ao plano
// Grátis. Uma vez por dia basta — a granularidade da regra é de dias.
Schedule::command('billing:dunning')
    ->dailyAt('03:20')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onOneServer();
