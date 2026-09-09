<?php

namespace App\Console\Commands;

use App\Support\Correlation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checkpoint encadeado da trilha de auditoria (docs/seguranca-operacional.md §3).
 *
 *   php artisan audit:checkpoint --since=2026-09-01 --until=2026-09-08
 *   php artisan audit:checkpoint                # desde o fim do último checkpoint até agora
 *   php artisan audit:checkpoint --verify       # confere a cadeia inteira contra o disco
 *
 * O que ele faz: exporta os `audit_events` de um período para um arquivo JSON Lines no
 * disco privado e grava, em `audit_checkpoints`, um resumo cujo hash INCLUI o hash do
 * checkpoint anterior. Reescrever um evento antigo passa a exigir reescrever também todos
 * os arquivos e todas as linhas de resumo posteriores.
 *
 * O que ele NÃO faz — e é importante dizer com todas as letras:
 *
 *  - não impede alteração. Nem a tabela append-only, nem a exclusão lógica, nem o
 *    encadeamento impedem quem tem privilégio de UPDATE/DELETE no MySQL, ou acesso ao
 *    arquivo de dados do InnoDB, de reescrever a trilha;
 *  - o encadeamento só vira PROVA quando o último `chain_sha256` sai do alcance de quem
 *    administra o banco: cópia para outro domínio administrativo, e-mail para o
 *    responsável jurídico, repositório de retenção, ou um carimbo de tempo de terceiro.
 *    Guardado apenas aqui, ele pode ser recalculado por quem alterou os dados.
 *
 * O que protege de verdade é a combinação: privilégio restrito (sem UPDATE/DELETE nas
 * tabelas de evidência) + checkpoint copiado para fora.
 */
class AuditCheckpointCommand extends Command
{
    protected $signature = 'audit:checkpoint
        {--since= : Início do período (ISO 8601 ou Y-m-d). Padrão: fim do último checkpoint}
        {--until= : Fim do período, exclusivo (ISO 8601 ou Y-m-d). Padrão: agora}
        {--verify : Não exporta nada; apenas reconfere a cadeia já existente}
        {--allow-empty : Grava o checkpoint mesmo sem nenhum evento no período}
        {--json : Saída em JSON (para monitoramento)}';

    protected $description = 'Exporta os eventos de auditoria de um período com resumo encadeado (torna alteração detectável).';

    public function handle(): int
    {
        try {
            $report = $this->option('verify') ? $this->verify() : $this->create();
        } catch (Throwable $exception) {
            $report = ['ok' => false, 'error' => $exception->getMessage()];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return ($report['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $this->option('verify') ? $this->renderVerify($report) : $this->renderCreate($report);

        return ($report['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function create(): array
    {
        $disk = $this->disk();

        $previous = DB::table('audit_checkpoints')->orderByDesc('sequence')->first();

        $since = $this->parseOption('since')
            ?? ($previous !== null ? CarbonImmutable::parse($previous->period_end) : $this->firstEventAt());
        $until = $this->parseOption('until') ?? CarbonImmutable::now();

        if ($since !== null && $since->greaterThanOrEqualTo($until)) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'Período vazio: --since (%s) não é anterior a --until (%s).',
                    $since->toIso8601String(),
                    $until->toIso8601String(),
                ),
            ];
        }

        // Sem nenhum evento na base ainda: o período começa no próprio fim.
        $since ??= $until;

        $sequence = (int) ($previous->sequence ?? 0) + 1;
        $ulid = (string) Str::ulid();
        $generatedAt = CarbonImmutable::now();

        [$lines, $count, $firstId, $lastId, $eventsHash] = $this->collect($since, $until);

        if ($count === 0 && ! $this->option('allow-empty')) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'Nenhum evento no período; nada foi gravado (use --allow-empty para registrar mesmo assim).',
                'since' => $since->toIso8601String(),
                'until' => $until->toIso8601String(),
            ];
        }

        $header = [
            'format' => 'assinavelox.audit-checkpoint/1',
            'ulid' => $ulid,
            'sequence' => $sequence,
            'application' => (string) config('app.name'),
            'environment' => (string) config('app.env'),
            'generated_at' => $generatedAt->toIso8601String(),
            'period_start' => $since->toIso8601String(),
            'period_end' => $until->toIso8601String(),
            'event_count' => $count,
            'first_event_id' => $firstId,
            'last_event_id' => $lastId,
            'events_sha256' => $eventsHash,
            'previous_sha256' => $previous->chain_sha256 ?? null,
            'notice' => 'Resumo encadeado da trilha. Torna alteração DETECTÁVEL; não a impede. '
                .'A prova só existe se este hash for guardado fora do alcance de quem administra o banco.',
        ];

        $chainHash = $this->chainHash($header);
        $header['chain_sha256'] = $chainHash;

        $body = json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n".$lines;
        $path = $this->path($generatedAt, $sequence, $ulid);

        $disk->put($path, $body);

        $fileHash = hash('sha256', $body);

        DB::table('audit_checkpoints')->insert([
            'ulid' => $ulid,
            'sequence' => $sequence,
            'period_start' => $since->utc()->format('Y-m-d H:i:s'),
            'period_end' => $until->utc()->format('Y-m-d H:i:s'),
            'first_event_id' => $firstId,
            'last_event_id' => $lastId,
            'event_count' => $count,
            'events_sha256' => $eventsHash,
            'file_sha256' => $fileHash,
            'previous_sha256' => $previous->chain_sha256 ?? null,
            'chain_sha256' => $chainHash,
            'storage_disk' => $this->diskName(),
            'storage_path' => $path,
            'size_bytes' => strlen($body),
            'generated_by' => Str::limit((string) (get_current_user() ?: 'desconhecido'), 120, ''),
            'created_at' => $generatedAt->utc()->format('Y-m-d H:i:s'),
        ]);

        return [
            'ok' => true,
            'skipped' => false,
            'ulid' => $ulid,
            'sequence' => $sequence,
            'since' => $since->toIso8601String(),
            'until' => $until->toIso8601String(),
            'event_count' => $count,
            'events_sha256' => $eventsHash,
            'file_sha256' => $fileHash,
            'previous_sha256' => $previous->chain_sha256 ?? null,
            'chain_sha256' => $chainHash,
            'disk' => $this->diskName(),
            'path' => $path,
            'correlation_id' => Correlation::id(),
        ];
    }

    /**
     * Reconfere a cadeia: cada elo recalculado a partir do arquivo no disco tem de bater
     * com o resumo gravado, e o elo anterior de cada linha tem de ser o da linha anterior.
     *
     * @return array<string, mixed>
     */
    private function verify(): array
    {
        $disk = $this->disk();
        $rows = DB::table('audit_checkpoints')->orderBy('sequence')->get();

        $checked = [];
        $problems = [];
        $expectedPrevious = null;
        $expectedSequence = 1;

        foreach ($rows as $row) {
            $issues = [];

            if ((int) $row->sequence !== $expectedSequence) {
                $issues[] = sprintf('sequência esperada %d, encontrada %d (checkpoint removido?)', $expectedSequence, $row->sequence);
            }

            if (($row->previous_sha256 ?? null) !== $expectedPrevious) {
                $issues[] = 'elo anterior não corresponde ao checkpoint precedente';
            }

            $content = null;
            if (! $disk->exists($row->storage_path)) {
                $issues[] = 'arquivo do lote ausente no disco '.$row->storage_disk;
            } else {
                $content = (string) $disk->get($row->storage_path);

                if (hash('sha256', $content) !== $row->file_sha256) {
                    $issues[] = 'o arquivo do lote foi alterado (file_sha256 não confere)';
                }

                $recomputed = $this->recomputeFromFile($content);

                if ($recomputed['events_sha256'] !== $row->events_sha256) {
                    $issues[] = 'os eventos do arquivo não produzem o events_sha256 registrado';
                }

                if ($recomputed['chain_sha256'] !== $row->chain_sha256) {
                    $issues[] = 'o cabeçalho do arquivo não produz o chain_sha256 registrado';
                }
            }

            $checked[] = [
                'sequence' => (int) $row->sequence,
                'ulid' => $row->ulid,
                'path' => $row->storage_path,
                'event_count' => (int) $row->event_count,
                'chain_sha256' => $row->chain_sha256,
                'ok' => $issues === [],
                'issues' => $issues,
            ];

            $problems = array_merge($problems, array_map(
                fn (string $issue): string => sprintf('checkpoint #%d: %s', $row->sequence, $issue),
                $issues,
            ));

            $expectedPrevious = $row->chain_sha256;
            $expectedSequence = (int) $row->sequence + 1;
        }

        return [
            'ok' => $problems === [],
            'checkpoints' => $checked,
            'count' => count($checked),
            'head' => $expectedPrevious,
            'problems' => $problems,
            'limitation' => 'A conferência prova coerência interna. Só prova AUSÊNCIA de adulteração '
                .'se o último chain_sha256 tiver sido guardado fora deste sistema.',
        ];
    }

    /**
     * Monta o corpo canônico do lote: uma linha JSON por evento, na ordem de `id`.
     *
     * A ordem e a serialização precisam ser DETERMINÍSTICAS — o hash é sobre o texto —,
     * por isso cada evento é reduzido a um array com chaves fixas, em ordem fixa, e o
     * payload é re-serializado com as chaves ordenadas.
     *
     * @return array{0: string, 1: int, 2: int|null, 3: int|null, 4: string}
     */
    private function collect(CarbonImmutable $since, CarbonImmutable $until): array
    {
        $chunk = max(100, (int) config('assinavelox.audit.checkpoint.chunk', 1000));

        $lines = '';
        $count = 0;
        $firstId = null;
        $lastId = null;
        $hash = hash_init('sha256');

        DB::table('audit_events')
            ->where('occurred_at', '>=', $since->utc()->format('Y-m-d H:i:s'))
            ->where('occurred_at', '<', $until->utc()->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->chunk($chunk, function ($rows) use (&$lines, &$count, &$firstId, &$lastId, $hash): void {
                foreach ($rows as $row) {
                    /** @var array<string, mixed> $event */
                    $event = (array) $row;

                    $line = $this->canonicalEvent($event);

                    $lines .= $line."\n";
                    hash_update($hash, $line."\n");

                    $firstId ??= (int) $event['id'];
                    $lastId = (int) $event['id'];
                    $count++;
                }
            });

        return [$lines, $count, $firstId, $lastId, hash_final($hash)];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function canonicalEvent(array $row): string
    {
        $payload = null;
        $rawPayload = $row['payload'] ?? null;

        if (is_string($rawPayload) && $rawPayload !== '') {
            $decoded = json_decode($rawPayload, true);
            if (is_array($decoded)) {
                $payload = $this->sortKeys($decoded);
            }
        }

        $nullableInt = static fn (mixed $value): ?int => $value === null ? null : (int) $value;

        return (string) json_encode([
            'id' => (int) $row['id'],
            'ulid' => $row['ulid'],
            'organization_id' => (int) $row['organization_id'],
            'envelope_id' => $nullableInt($row['envelope_id'] ?? null),
            'recipient_id' => $nullableInt($row['recipient_id'] ?? null),
            'actor_type' => $row['actor_type'] ?? null,
            'actor_id' => $nullableInt($row['actor_id'] ?? null),
            'event_type' => $row['event_type'] ?? null,
            'payload' => $payload,
            'ip_address' => $row['ip_address'] ?? null,
            'user_agent' => $row['user_agent'] ?? null,
            'correlation_id' => $row['correlation_id'] ?? null,
            'occurred_at' => CarbonImmutable::parse((string) $row['occurred_at'])->utc()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function sortKeys(array $data): array
    {
        $sorted = [];

        foreach ($data as $key => $value) {
            $sorted[$key] = is_array($value) ? $this->sortKeys($value) : $value;
        }

        if (! array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }

    /**
     * Elo da cadeia. A entrada é o cabeçalho SEM o próprio `chain_sha256`, em ordem fixa,
     * de modo que qualquer um consiga recalcular o valor a partir do arquivo publicado.
     *
     * @param  array<string, mixed>  $header
     */
    private function chainHash(array $header): string
    {
        return hash('sha256', implode('|', [
            'assinavelox.audit-checkpoint/1',
            (string) $header['sequence'],
            (string) $header['period_start'],
            (string) $header['period_end'],
            (string) $header['event_count'],
            (string) ($header['first_event_id'] ?? ''),
            (string) ($header['last_event_id'] ?? ''),
            (string) $header['events_sha256'],
            (string) ($header['previous_sha256'] ?? ''),
        ]));
    }

    /**
     * @return array{events_sha256: string, chain_sha256: string}
     */
    private function recomputeFromFile(string $content): array
    {
        $newline = strpos($content, "\n");
        $headerLine = $newline === false ? $content : substr($content, 0, $newline);
        $body = $newline === false ? '' : substr($content, $newline + 1);

        /** @var array<string, mixed> $header */
        $header = (array) json_decode($headerLine, true);

        return [
            'events_sha256' => hash('sha256', $body),
            'chain_sha256' => $this->chainHash($header + [
                'sequence' => 0,
                'period_start' => '',
                'period_end' => '',
                'event_count' => 0,
                'events_sha256' => '',
            ]),
        ];
    }

    private function firstEventAt(): ?CarbonImmutable
    {
        $value = DB::table('audit_events')->min('occurred_at');

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    private function parseOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);
        $raw = is_string($value) ? trim($value) : '';

        return $raw === '' ? null : CarbonImmutable::parse($raw);
    }

    private function path(CarbonImmutable $at, int $sequence, string $ulid): string
    {
        $prefix = trim((string) config('assinavelox.audit.checkpoint.path', 'audit-checkpoints'), '/');

        return sprintf('%s/%s/%06d-%s.jsonl', $prefix, $at->format('Y'), $sequence, $ulid);
    }

    private function diskName(): string
    {
        return (string) config('assinavelox.audit.checkpoint.disk', 'documents');
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderCreate(array $report): void
    {
        if (! ($report['ok'] ?? false)) {
            $this->components->error((string) ($report['error'] ?? 'Falha ao gerar o checkpoint.'));

            return;
        }

        if ($report['skipped'] ?? false) {
            $this->components->info((string) $report['reason']);

            return;
        }

        $this->components->info(sprintf('Checkpoint #%d gravado.', $report['sequence']));
        $this->components->twoColumnDetail('Período', $report['since'].' → '.$report['until']);
        $this->components->twoColumnDetail('Eventos', (string) $report['event_count']);
        $this->components->twoColumnDetail('Arquivo', $report['disk'].':'.$report['path']);
        $this->components->twoColumnDetail('Elo anterior', (string) ($report['previous_sha256'] ?? '— (primeiro da cadeia)'));
        $this->components->twoColumnDetail('<options=bold>Elo deste lote</>', (string) $report['chain_sha256']);
        $this->newLine();
        $this->components->warn(
            'Guarde o elo acima FORA deste sistema (outro domínio administrativo, arquivo de '
            .'retenção, responsável jurídico). Dentro do banco ele pode ser recalculado por '
            .'quem tiver privilégio para alterar a trilha.'
        );
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderVerify(array $report): void
    {
        if (isset($report['error'])) {
            $this->components->error((string) $report['error']);

            return;
        }

        foreach ($report['checkpoints'] as $checkpoint) {
            $this->components->twoColumnDetail(
                sprintf('#%d %s (%d eventos)', $checkpoint['sequence'], $checkpoint['ulid'], $checkpoint['event_count']),
                $checkpoint['ok'] ? '<fg=green>ok</>' : '<fg=red>FALHA</>',
            );
            foreach ($checkpoint['issues'] as $issue) {
                $this->components->bulletList([$issue]);
            }
        }

        $this->newLine();

        if ($report['count'] === 0) {
            $this->components->warn('Nenhum checkpoint gerado ainda.');

            return;
        }

        if ($report['ok']) {
            $this->components->info(sprintf('Cadeia coerente: %d checkpoint(s).', $report['count']));
            $this->components->twoColumnDetail('Elo atual', (string) $report['head']);
        } else {
            $this->components->error('Cadeia INCOERENTE — veja os itens acima.');
        }

        $this->components->warn((string) $report['limitation']);
    }
}
