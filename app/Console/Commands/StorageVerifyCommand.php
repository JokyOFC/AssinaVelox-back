<?php

namespace App\Console\Commands;

use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Verificação do armazenamento dos documentos (docs/seguranca-operacional.md §5).
 *
 *   php artisan storage:verify [--disk=documents] [--json] [--keep-probe]
 *
 * Confere três coisas, nesta ordem:
 *
 *  1. o disco é GRAVÁVEL e o que é escrito volta igual (grava um objeto de prova, lê,
 *     compara o sha256 e apaga);
 *  2. o disco é PRIVADO — em disco local, que a raiz não está sob `public/` nem exposta
 *     por link simbólico e que `serve`/`visibility` não abrem acesso direto; em S3, que
 *     o objeto de prova não é legível anonimamente e que o bloqueio de acesso público do
 *     bucket está ligado;
 *  3. a CRIPTOGRAFIA EM REPOUSO está mesmo ativa.
 *
 * Sobre o item 3, sem rodeios: **o Flysystem não cifra nada**. Ele fala com o backend; o
 * que existe é a cifra do backend.
 *
 *  - Em S3 isso é verificável: o comando pergunta ao próprio serviço
 *    (`GetBucketEncryption`) e confere o cabeçalho `x-amz-server-side-encryption`
 *    devolvido para o objeto de prova. Se o serviço não responder, o comando FALHA
 *    dizendo que não conseguiu verificar — nunca supõe que está cifrado.
 *  - Em disco local não há nada a consultar a partir do PHP: a cifra é do volume
 *    (LUKS, dm-crypt, BitLocker) e o processo enxerga o sistema de arquivos já aberto.
 *    O comando então relata ATESTAÇÃO DO OPERADOR
 *    (`ASSINAVELOX_STORAGE_ENCRYPTION_ATTESTED`), rotulada como atestação e não como
 *    verificação, e falha em produção quando ela não existe.
 *
 * O resultado é gravado como recibo em `storage/app/private/hardening/storage-verify.json`
 * para o `assinavelox:doctor` mostrar quando foi a última conferência.
 */
class StorageVerifyCommand extends Command
{
    protected $signature = 'storage:verify
        {--disk= : Disco a verificar (padrão: o disco de documentos)}
        {--json : Saída em JSON (para monitoramento)}
        {--keep-probe : Não apaga o objeto de prova (diagnóstico manual)}';

    protected $description = 'Confere que o disco de documentos é gravável, privado e cifrado em repouso (consulta ao backend em S3).';

    public function handle(): int
    {
        $diskName = trim((string) $this->option('disk')) ?: 'documents';

        $report = [
            'ok' => true,
            'disk' => $diskName,
            'driver' => (string) config("filesystems.disks.{$diskName}.driver", 'desconhecido'),
            'checked_at' => CarbonImmutable::now()->toIso8601String(),
            'writable' => $this->checkWritable($diskName),
            'private' => null,
            'encryption' => null,
            'problems' => [],
        ];

        $report['private'] = $report['driver'] === 's3'
            ? $this->checkS3Private($diskName)
            : $this->checkLocalPrivate($diskName);

        $report['encryption'] = $report['driver'] === 's3'
            ? $this->checkS3Encryption($diskName)
            : $this->checkLocalEncryption();

        foreach (['writable', 'private', 'encryption'] as $section) {
            /** @var array<string, mixed> $result */
            $result = $report[$section];

            if (! ($result['ok'] ?? false)) {
                $report['ok'] = false;
            }

            foreach ((array) ($result['problems'] ?? []) as $problem) {
                $report['problems'][] = (string) $problem;
            }
        }

        $this->writeReceipt($report);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->render($report);

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Grava um objeto de prova, lê de volta e compara o sha256. "O put não lançou exceção"
     * não é o mesmo que "os bytes chegaram": em bucket com permissão parcial, a escrita
     * pode ser aceita e a leitura, não.
     *
     * @return array<string, mixed>
     */
    private function checkWritable(string $diskName): array
    {
        $path = 'hardening/probe-'.Str::ulid().'.txt';
        $content = 'assinavelox storage:verify '.CarbonImmutable::now()->toIso8601String()."\n";

        try {
            $disk = Storage::disk($diskName);
            $disk->put($path, $content);

            $readBack = (string) $disk->get($path);
            $matches = hash('sha256', $readBack) === hash('sha256', $content);

            $result = [
                'ok' => $matches,
                'probe_path' => $path,
                'problems' => $matches ? [] : ['O conteúdo lido do disco não confere com o que foi gravado.'],
            ];

            if (! $this->option('keep-probe')) {
                $disk->delete($path);
                $result['probe_deleted'] = ! $disk->exists($path);
                if (! $result['probe_deleted']) {
                    $result['problems'][] = 'O objeto de prova não pôde ser apagado (falta permissão de DELETE?).';
                    $result['ok'] = false;
                }
            }

            return $result;
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'probe_path' => $path,
                'problems' => ['Falha ao gravar/ler no disco: '.$exception->getMessage()],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkLocalPrivate(string $diskName): array
    {
        $root = (string) config("filesystems.disks.{$diskName}.root", '');
        $visibility = (string) config("filesystems.disks.{$diskName}.visibility", 'private');
        $serve = (bool) config("filesystems.disks.{$diskName}.serve", false);

        $problems = [];

        if ($root === '') {
            $problems[] = 'O disco não tem `root` configurado.';
        }

        $realRoot = $root !== '' ? (realpath($root) ?: $root) : '';
        $publicRoot = realpath(public_path()) ?: public_path();

        if ($realRoot !== '' && str_starts_with($realRoot, rtrim($publicRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            $problems[] = 'A raiz do disco está dentro de public/: os arquivos ficariam acessíveis sem passar por autorização.';
        }

        if ($visibility !== 'private') {
            $problems[] = "A visibilidade do disco é `{$visibility}`; deve ser `private`.";
        }

        if ($serve) {
            $problems[] = 'O disco tem `serve => true`: o Laravel publicaria uma rota de acesso direto aos arquivos.';
        }

        // Link simbólico de `storage:link` apontando para dentro da raiz do disco.
        foreach ((array) config('filesystems.links', []) as $link => $target) {
            $realTarget = realpath((string) $target) ?: (string) $target;

            if ($realRoot !== '' && ($realTarget === $realRoot || str_starts_with($realRoot, rtrim($realTarget, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))) {
                $problems[] = sprintf('O link público %s aponta para (ou contém) a raiz do disco.', $link);
            }
        }

        return [
            'ok' => $problems === [],
            'root' => $root,
            'visibility' => $visibility,
            'serve' => $serve,
            'problems' => $problems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkS3Private(string $diskName): array
    {
        $bucket = (string) config("filesystems.disks.{$diskName}.bucket", '');
        $problems = [];
        $publicAccessBlock = null;

        if ((string) config("filesystems.disks.{$diskName}.visibility", '') !== 'private') {
            $problems[] = 'A visibilidade padrão do disco não é `private`.';
        }

        try {
            $client = $this->s3Client($diskName);
            $result = $client->getPublicAccessBlock(['Bucket' => $bucket]);
            $config = (array) ($result['PublicAccessBlockConfiguration'] ?? []);

            $publicAccessBlock = [
                'BlockPublicAcls' => (bool) ($config['BlockPublicAcls'] ?? false),
                'IgnorePublicAcls' => (bool) ($config['IgnorePublicAcls'] ?? false),
                'BlockPublicPolicy' => (bool) ($config['BlockPublicPolicy'] ?? false),
                'RestrictPublicBuckets' => (bool) ($config['RestrictPublicBuckets'] ?? false),
            ];

            foreach ($publicAccessBlock as $key => $enabled) {
                if (! $enabled) {
                    $problems[] = "Bloqueio de acesso público incompleto: {$key} está desligado.";
                }
            }
        } catch (Throwable $exception) {
            /*
             * Nem todo serviço compatível com S3 implementa GetPublicAccessBlock (MinIO,
             * Backblaze B2, Wasabi). Isso é AVISO, não aprovação: a conferência precisa
             * ser feita no painel do provedor e registrada.
             */
            $problems[] = 'Não foi possível confirmar o bloqueio de acesso público ('
                .Str::limit($exception->getMessage(), 160)
                .'). Confirme no painel do provedor — este item fica NÃO VERIFICADO.';
        }

        return [
            'ok' => $problems === [],
            'bucket' => $bucket,
            'public_access_block' => $publicAccessBlock,
            'problems' => $problems,
        ];
    }

    /**
     * Criptografia do lado do servidor CONSULTADA ao backend — não suposta.
     *
     * @return array<string, mixed>
     */
    private function checkS3Encryption(string $diskName): array
    {
        $bucket = (string) config("filesystems.disks.{$diskName}.bucket", '');
        $expected = (string) config('assinavelox.storage_encryption.s3_expected_algorithm', 'AES256');

        try {
            $client = $this->s3Client($diskName);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'verified' => false,
                'method' => 's3',
                'problems' => ['Não foi possível obter o cliente S3: '.$exception->getMessage()],
            ];
        }

        $problems = [];
        $bucketDefault = null;

        try {
            $result = $client->getBucketEncryption(['Bucket' => $bucket]);
            $rules = (array) ($result['ServerSideEncryptionConfiguration']['Rules'] ?? []);
            $rule = (array) ($rules[0]['ApplyServerSideEncryptionByDefault'] ?? []);
            $bucketDefault = $rule['SSEAlgorithm'] ?? null;

            if ($bucketDefault === null) {
                $problems[] = 'O bucket respondeu sem regra de criptografia padrão.';
            } elseif ($expected !== '' && $bucketDefault !== $expected) {
                $problems[] = sprintf('Algoritmo padrão do bucket é %s; o esperado é %s.', $bucketDefault, $expected);
            }
        } catch (Throwable $exception) {
            $problems[] = 'Não foi possível consultar GetBucketEncryption ('
                .Str::limit($exception->getMessage(), 160)
                .'). Sem essa resposta a criptografia em repouso fica NÃO VERIFICADA — e não verificada não é o mesmo que ativa.';
        }

        // Segunda evidência: o cabeçalho devolvido para um objeto real deste disco.
        $objectAlgorithm = null;
        $probe = 'hardening/probe-sse-'.Str::ulid().'.txt';

        try {
            $disk = Storage::disk($diskName);
            $disk->put($probe, 'sse-probe');

            $head = $client->headObject([
                'Bucket' => $bucket,
                'Key' => ltrim((string) config("filesystems.disks.{$diskName}.root", ''), '/') !== ''
                    ? rtrim((string) config("filesystems.disks.{$diskName}.root"), '/').'/'.$probe
                    : $probe,
            ]);

            $objectAlgorithm = $head['ServerSideEncryption'] ?? null;

            if ($objectAlgorithm === null) {
                $problems[] = 'O objeto gravado voltou SEM cabeçalho x-amz-server-side-encryption: os arquivos estão sendo escritos em claro.';
            }

            if (! $this->option('keep-probe')) {
                $disk->delete($probe);
            }
        } catch (Throwable $exception) {
            $problems[] = 'Não foi possível conferir a criptografia do objeto de prova: '.Str::limit($exception->getMessage(), 160);
        }

        return [
            'ok' => $problems === [],
            'verified' => $problems === [],
            'method' => 's3',
            'bucket_default_algorithm' => $bucketDefault,
            'object_algorithm' => $objectAlgorithm,
            'expected_algorithm' => $expected,
            'problems' => $problems,
        ];
    }

    /**
     * Disco local: não há o que consultar. Reporta a atestação do operador COMO
     * atestação, e falha em produção quando ela não existe.
     *
     * @return array<string, mixed>
     */
    private function checkLocalEncryption(): array
    {
        $attested = (bool) config('assinavelox.storage_encryption.local_attested', false);
        $note = (string) config('assinavelox.storage_encryption.local_attestation_note', '');
        $production = app()->isProduction();

        $problems = [];

        if (! $attested && $production) {
            $problems[] = 'Em produção com disco local é obrigatório atestar a cifra do volume '
                .'(ASSINAVELOX_STORAGE_ENCRYPTION_ATTESTED=true + ASSINAVELOX_STORAGE_ENCRYPTION_NOTE). '
                .'O Flysystem não cifra nada por conta própria.';
        }

        if ($attested && $note === '') {
            $problems[] = 'A atestação está marcada, mas sem referência (ASSINAVELOX_STORAGE_ENCRYPTION_NOTE): '
                .'uma atestação sem rastro não vale como evidência.';
        }

        return [
            'ok' => $problems === [],
            // Nunca `true`: em disco local a aplicação não VERIFICA cifra nenhuma.
            'verified' => false,
            'method' => 'local',
            'operator_attested' => $attested,
            'attestation_note' => $note !== '' ? $note : null,
            'problems' => $problems,
            'notice' => 'Em disco local a criptografia em repouso é do VOLUME (LUKS/dm-crypt/BitLocker) '
                .'e não pode ser verificada pelo PHP: o processo enxerga o sistema de arquivos já aberto.',
        ];
    }

    private function s3Client(string $diskName): S3Client
    {
        $adapter = Storage::disk($diskName);

        if (! $adapter instanceof AwsS3V3Adapter) {
            throw new \RuntimeException("O disco `{$diskName}` não é um disco S3.");
        }

        return $adapter->getClient();
    }

    /**
     * Recibo lido depois por `assinavelox:doctor`. Vai para o disco `local`
     * (storage/app/private), nunca para o disco de documentos.
     *
     * @param  array<string, mixed>  $report
     */
    private function writeReceipt(array $report): void
    {
        try {
            Storage::disk('local')->put(
                (string) config('assinavelox.storage_encryption.receipt_path', 'hardening/storage-verify.json'),
                (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        } catch (Throwable) {
            // Um recibo que não pôde ser gravado não invalida a verificação em si.
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): void
    {
        $this->components->info(sprintf('Disco `%s` (driver %s)', $report['disk'], $report['driver']));

        $this->components->twoColumnDetail('Gravável e íntegro', $report['writable']['ok'] ? '<fg=green>sim</>' : '<fg=red>NÃO</>');
        $this->components->twoColumnDetail('Privado', $report['private']['ok'] ? '<fg=green>sim</>' : '<fg=red>NÃO</>');

        $encryption = $report['encryption'];
        $this->components->twoColumnDetail('Criptografia em repouso', match (true) {
            (bool) $encryption['verified'] => '<fg=green>verificada junto ao backend</>',
            (bool) ($encryption['operator_attested'] ?? false) => '<fg=yellow>atestada pelo operador (não verificável em disco local)</>',
            // Fora de produção, disco local sem atestação não é defeito — é o padrão de
            // desenvolvimento. Pintar de vermelho aqui ensinaria a ignorar o vermelho.
            (bool) $encryption['ok'] => '<fg=yellow>não atestada (disco local fora de produção)</>',
            default => '<fg=red>NÃO verificada</>',
        });

        if (isset($encryption['bucket_default_algorithm'])) {
            $this->components->twoColumnDetail('  Algoritmo padrão do bucket', (string) $encryption['bucket_default_algorithm']);
        }
        if (isset($encryption['object_algorithm'])) {
            $this->components->twoColumnDetail('  Algoritmo do objeto de prova', (string) $encryption['object_algorithm']);
        }
        if (isset($encryption['notice'])) {
            $this->newLine();
            $this->components->warn((string) $encryption['notice']);
        }

        if ($report['problems'] !== []) {
            $this->newLine();
            foreach ($report['problems'] as $problem) {
                $this->components->error($problem);
            }
        }

        $this->newLine();
        $report['ok']
            ? $this->components->info('Armazenamento conferido.')
            : $this->components->error('Armazenamento NÃO conferido. Veja docs/seguranca-operacional.md §5.');
    }
}
