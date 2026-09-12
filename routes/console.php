<?php

use App\Jobs\Billing\ReconcilePaymentsJob;
use App\Jobs\Dossier\PurgeExpiredDossierExports;
use App\Models\ApiRequestLog;
use App\Services\RestHooks\RestHookSubscriptions;
use App\Services\Webhooks\WebhookPruner;
use App\Services\Webhooks\WebhookRetrySweeper;
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

/*
| Indicadores de saúde (docs/seguranca-operacional.md §6) escritos no canal de log
| estruturado a cada 5 min. O alarme mora no coletor de logs, não aqui: este agendamento
| só garante que os números existam com regularidade, inclusive quando ninguém está
| olhando. `--log` não imprime nada no stdout do cron.
*/
Schedule::command('assinavelox:health --log')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->runInBackground()
    ->onOneServer();

/*
| Checkpoint encadeado da trilha de auditoria (docs/seguranca-operacional.md §3).
|
| Semanal, cobrindo o período desde o checkpoint anterior. Sozinho ele NÃO protege a
| trilha: só torna uma alteração detectável. A proteção só se completa quando o
| `chain_sha256` do último lote sai deste sistema — copiado para outro domínio
| administrativo, arquivado com o responsável jurídico ou carimbado por um terceiro.
| Esse passo é humano e está descrito na documentação; o agendador não o faz.
*/
Schedule::command('audit:checkpoint')
    ->weeklyOn(1, '02:40')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onOneServer();

/*
| Poda dos jobs falhos e dos lotes antigos.
|
| Um job que falha em definitivo tem o payload INTEIRO copiado para `failed_jobs`. Os
| convites e o código do segundo fator viajam cifrados (`ShouldBeEncrypted`), mas o
| registro em si não tem prazo: sem poda, um convite falho de 2026 continuaria em disco
| em 2030, muito depois de o link ter sido revogado e o desafio ter expirado.
|
| 168 h (7 dias) é tempo de sobra para investigar uma falha de entrega — que também fica
| registrada, sem segredo, em `delivery_attempts`.
*/
Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('03:40')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onOneServer();

Schedule::command('queue:prune-batches --hours=168')
    ->dailyAt('03:45')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onOneServer();

/*
| Exclusão efetiva das organizações cuja carência venceu (ROUTES §2.12 e Q23).
|
| A tela de Configurações › Geral e segurança promete "Remove todos os usuários e documentos
| após 30 dias" e a política de privacidade publicada promete ao titular que os dados são
| apagados dos sistemas ativos. `deletion_requested_at` sozinho é só uma data guardada: é
| este agendamento que cumpre a promessa. Diário, fora do horário de pico, e idempotente.
*/
//
// Sem `runInBackground()`, ao contrário dos demais: esta é a única rotina que APAGA dados
// em definitivo. Rodar no processo do `schedule:run` mantém a falha visível na saída do
// cron (um `deleteDirectory` que falha, uma FK nova que ninguém previu) em vez de morrer
// num processo filho sem ninguém olhando. Às 04:10 não há nenhum outro agendamento no
// horário, então ela não atrasa nada.
Schedule::command('organizations:purge')
    ->dailyAt('04:10')
    ->withoutOverlapping(30)
    ->onOneServer();

/*
| Resumo diário de pendências (ROUTES §2.14, evento `daily_digest`).
|
| A tela de Notificações não só oferece o interruptor — ela promete o horário no rodapé:
| "Resumo diário de pendências enviado às 08:00 (America/Sao_Paulo)". Este agendamento é o
| que cumpre a promessa. O horário é fixado no fuso da plataforma; o serviço decide, por
| organização, se hoje é dia útil e se há o que contar, e a marca do dia em
| `memberships.daily_digest_sent_on` garante um único e-mail por pessoa por dia.
*/
Schedule::command('notifications:daily-digest')
    ->dailyAt('08:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onOneServer();

/*
| Fase 2 §2.5 — lembretes automáticos e envio agendado (docs/fase-2/lembretes-e-agendamento.md).
|
| Os dois só fazem algo com a flag `features.reminders` ligada (interruptor global E plano
| da organização). Desligada, o de lembretes não seleciona nada e o de envio agendado não
| encontra agendamento — nenhum pode ser criado sem a flag.
|
| Lembretes: de hora em hora, no minuto 5. A janela de horário (padrão 8h–20h) é avaliada
| no fuso de CADA organização pelo serviço, não aqui. Envio agendado: a cada minuto — o
| usuário escolhe o minuto, e a varredura também cancela agendamentos de envelopes editados.
*/
Schedule::command('envelopes:send-reminders')
    ->hourlyAt(5)
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onOneServer();

Schedule::command('envelopes:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground()
    ->onOneServer();

/*
| Fase 2, onda B — retenção.
|
| Fotos da captura simples (docs/fase-2/identidade.md §5.5): o arquivo sai depois de
| `capture.retention_days` e a foto que nunca virou aceite, depois de
| `capture.orphan_retention_hours`. Envios de formulário público não confirmados
| (docs/fase-2/formulario-publico.md §5) saem quando o link vence. Ambos idempotentes e
| inertes com as flags desligadas (não há o que apagar).
*/
Schedule::command('identity:purge-captures')
    ->dailyAt('04:40')
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('public-forms:purge-submissions')
    ->hourlyAt(25)
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onOneServer();

/*
| Fase 2, onda C (K-RET) — retenção configurável e preservação (docs/fase-2/retencao-e-preservacao.md).
|
| `retention:apply`: diário, em lotes, idempotente; retoma recibos pendentes; nada sob
| preservação sai. Inerte com a flag `retention_policies` desligada (nenhuma organização é
| selecionada). Sem `runInBackground()`, pelo mesmo motivo do `organizations:purge`: APAGA dados
| em definitivo, e a falha precisa aparecer na saída do cron.
|
| `public-forms:prune-timer-marks`: apaga as marcas vencidas de uso único do carimbo de tempo
| do formulário público (agora no banco). Independe de flag.
*/
Schedule::command('retention:apply')
    ->dailyAt('04:25')
    ->withoutOverlapping(120)
    ->onOneServer();

Schedule::command('public-forms:prune-timer-marks')
    ->hourlyAt(35)
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onOneServer();

/*
| Fase 2, onda C — integração I-2C (docs/fase-2/onda-c-relatorio.md).
|
| Dossiês vencidos (K-TSA, carimbo-e-dossie.md §5.2): cada montagem já agenda a própria
| limpeza com atraso; esta varredura de hora em hora é a rede de segurança (só toca no que
| venceu). Inerte com `dossier_export` desligada (não há dossiê).
|
| A1 do participante (K-A1, a1-do-participante.md §14 itens 5 e 6): apaga o material selado
| órfão (PFX + senha cifrados que nenhum worker consumiu) e conclui envelopes cujo prazo de
| envio do certificado venceu — com o driver `sync` o prazo só seria conferido quando a
| finalização rodasse de novo. Inerte sem pedidos.
*/
Schedule::job(new PurgeExpiredDossierExports)
    ->hourlyAt(50)
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('participant-a1:maintain')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onOneServer();

/*
| Fase 2, onda D (D-HOOK) — webhooks de saída (docs/fase-2/webhooks.md §5).
|
| `webhooks:retry`: a cada minuto, despacha as retentativas vencidas (backoff 1 min → 24 h,
| teto de 8 tentativas) e, como rede de segurança, a primeira tentativa cujo job se perdeu;
| também apaga segredos anteriores cuja janela de rotação acabou. Inerte com a flag
| `outbound_webhooks` desligada.
|
| `webhooks:prune`: diário; apaga entregas encerradas e endpoints removidos há mais de
| `webhooks.retention_days`. Não toca em `audit_events`.
*/
Artisan::command('webhooks:retry', function (WebhookRetrySweeper $sweeper): void {
    $count = $sweeper->run();
    $this->info("Entregas de webhook despachadas: {$count}");
})->purpose('Despacha as retentativas vencidas dos webhooks de saída');

Artisan::command('webhooks:prune', function (WebhookPruner $pruner): void {
    $result = $pruner->run();
    $this->info("Entregas removidas: {$result['deliveries']}; endpoints removidos em definitivo: {$result['endpoints']}");
})->purpose('Remove o histórico vencido dos webhooks de saída');

Schedule::command('webhooks:retry')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground()
    ->onOneServer();

Schedule::command('webhooks:prune')
    ->dailyAt('04:55')
    ->withoutOverlapping(30)
    ->onOneServer();

/*
| Fase 2, onda D (integração I-2D — docs/fase-2/entrega-fase-2.md §4).
|
| `rest-hooks:prune`: de hora em hora remove as assinaturas REST Hook cujo token venceu, foi
| revogado fora da tela ou não existe mais. Até a varredura rodar, o motor já não entrega a elas.
|
| Registros de requisição da API (aba "Logs"): retenção de `assinavelox.api.request_logs.retention_days`
| (padrão 30 dias) pelo `MassPrunable` do model; a limpeza oportunista do gravador continua.
|
| Conciliação diária dos pagamentos (D-PAY §5): inerte com `extended_payments` desligada; só
| aponta divergências, nunca corrige pagamento.
*/
Artisan::command('rest-hooks:prune', function (RestHookSubscriptions $subscriptions): void {
    $this->info('Assinaturas REST Hook removidas: '.$subscriptions->pruneInactive());
})->purpose('Remove as assinaturas REST Hook de tokens vencidos, revogados ou apagados');

Schedule::command('rest-hooks:prune')
    ->hourlyAt(25)
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('model:prune', ['--model' => [ApiRequestLog::class]])
    ->dailyAt('04:40')
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::job(new ReconcilePaymentsJob)
    ->dailyAt('04:10')
    ->onOneServer();
