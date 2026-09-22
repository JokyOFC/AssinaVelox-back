<?php

namespace App\Support;

/**
 * Todas as filas em que algum job da aplicação é despachado.
 *
 * Em produção quem atende as filas é o Horizon (config/horizon.php). Em desenvolvimento um
 * único `queue:listen` precisa escutar todas, e é esta a lista que o `composer run dev` usa
 * (AppServiceProvider::configureDevProcesses). Os nomes vêm da configuração, então uma fila
 * renomeada por variável de ambiente continua coberta.
 *
 * A ordem é a prioridade desse worker único: primeiro o que alguém espera na tela (código por
 * e-mail e convites, processamento do upload), depois o trabalho longo (lotes, finalização,
 * OCR). Um job que escolha a fila por uma chave fora desta lista faz o DevProcessesTest falhar.
 */
final class Queues
{
    /** @var list<string> chaves de configuração com o nome de uma fila, em ordem de prioridade */
    public const CONFIG_KEYS = [
        'assinavelox.queues.notifications',
        'assinavelox.queues.conversions',
        'assinavelox.queues.default',
        // Fase 4 §4.1: o participante espera na tela o resultado do provedor de verificação
        // facial — logo depois das filas de tela, antes do trabalho longo (padrão: `default`).
        'assinavelox.identity_verification.queue',
        'assinavelox.queues.finalization',
        'assinavelox.queues.billing',
        'assinavelox.field_anchors.queue',
        'assinavelox.ocr.queue',
        'assinavelox.bulk_generation.queue',
        'assinavelox.webhooks.queue',
        'assinavelox.dossier.queue',
        'assinavelox.ltv.refresh.queue',
        'assinavelox.hubspot.queue',
    ];

    /**
     * Nomes das filas, sem repetição, em ordem de prioridade.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $names = array_map(
            static fn (string $key): string => trim((string) config($key)),
            self::CONFIG_KEYS,
        );

        // Job despachado sem fila explícita cai na fila padrão da conexão (DB_QUEUE).
        $connection = (string) config('queue.default');
        $names[] = trim((string) config("queue.connections.{$connection}.queue"));

        return array_values(array_unique(array_filter(
            $names,
            static fn (string $name): bool => $name !== '',
        )));
    }
}
