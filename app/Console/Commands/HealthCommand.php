<?php

namespace App\Console\Commands;

use App\Support\OperationalHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Saúde operacional em execução (docs/seguranca-operacional.md §6).
 *
 *   php artisan assinavelox:health [--json] [--log]
 *
 * Complementa a rota `/up` do Laravel, que só responde "o processo web está de pé". Este
 * comando olha para o que acontece FORA da requisição: fila, conversão, finalização e
 * assinatura, entrega de e-mail e conciliação de pagamento.
 *
 * É um comando, e não uma rota, por decisão deliberada: um endereço HTTP que enumere
 * quantos envelopes estão travados e quantos pagamentos ficaram sem aplicar é informação
 * de negócio, e teria de ser protegido, limitado e auditado. O agendador chama o comando
 * com `--log`, os números viram linhas do canal estruturado e o alarme fica no coletor,
 * que é onde ele já sabe morar.
 *
 * Código de saída 1 quando algum indicador está degradado — o suficiente para um
 * `systemd` OnFailure ou um cron que envia e-mail em caso de erro.
 */
class HealthCommand extends Command
{
    protected $signature = 'assinavelox:health
        {--json : Saída em JSON (para monitoramento)}
        {--log : Também registra o resultado no canal de log estruturado}';

    protected $description = 'Indicadores de saúde de fila, conversão, assinatura, entrega de e-mail e conciliação de pagamento.';

    public function handle(): int
    {
        $report = OperationalHealth::report();

        if ($this->option('log')) {
            $context = ['health' => $report['indicators'], 'degraded' => $report['degraded']];

            $report['ok']
                ? Log::info('health.ok', $context)
                : Log::warning('health.degraded', $context);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($report['indicators'] as $name => $indicator) {
            $this->components->twoColumnDetail((string) $name, match ($indicator['status']) {
                OperationalHealth::OK => '<fg=green>ok</>',
                OperationalHealth::DEGRADED => '<fg=red>degradado</>',
                default => '<fg=yellow>desconhecido</>',
            });
            $this->line('    '.$indicator['message']);

            foreach ($indicator['metrics'] as $key => $value) {
                $this->line(sprintf('      %s: %s', $key, is_bool($value) ? ($value ? 'sim' : 'não') : (string) $value));
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Correlação', (string) $report['correlation_id']);

        $report['ok']
            ? $this->components->info('Nenhum indicador degradado.')
            : $this->components->error('Degradado: '.implode(', ', $report['degraded']));

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
