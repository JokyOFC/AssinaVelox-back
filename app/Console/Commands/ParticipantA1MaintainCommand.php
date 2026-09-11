<?php

namespace App\Console\Commands;

use App\Enums\EnvelopeStatus;
use App\Enums\ParticipantSignatureRequestStatus;
use App\Jobs\Envelopes\ApplyParticipantSignature;
use App\Jobs\Envelopes\ApplyParticipantSignatureDeadline;
use App\Models\ParticipantSignatureRequest;
use App\Services\Pdf\PdfToolClient;
use App\Services\Signing\Certificates\SealedCertificateStore;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;

/**
 * `php artisan participant-a1:maintain` — manutenção da assinatura com o certificado do
 * próprio participante (Fase 2 §2.12; integração I-2C das pendências 5 e 6 de
 * docs/fase-2/a1-do-participante.md §14):
 *
 * 1. apaga o material selado (PFX + senha cifrados) mais velho que `sealed_ttl_minutes` que
 *    nenhum worker consumiu — retenção mínima também quando o pedido some ou o worker cai;
 * 2. apaga os diretórios temporários do A1 (`a1-apply-*`, `a1-upload-*`, `a1-proc-*`) no
 *    `pdftool.tmp_path` que sobraram de um processo MORTO (tempo limite do job, OOM, deploy,
 *    `max_execution_time`): nesses casos nem o `finally` nem o destrutor rodam e o PFX
 *    decifrado ficaria no disco sem prazo. Só sai o que é mais velho que o maior tempo em que
 *    um processo vivo ainda poderia estar usando o diretório (lock, tempo limite do job e do
 *    pdftool) mais uma margem — nunca o diretório de um worker em andamento;
 * 3. para cada envelope em `finalizing` com pedido pendente cujo prazo já venceu, despacha
 *    `ApplyParticipantSignatureDeadline` (ShouldBeUnique por envelope). Com uma fila real o
 *    job já é agendado com `delay` no fim do prazo; esta varredura cobre o driver `sync` e um
 *    agendamento perdido. O job é idempotente: conclui a finalização sem as assinaturas vencidas.
 *
 * Nunca lê o conteúdo do material selado nem dos PFX órfãos e nunca imprime segredo nenhum.
 */
class ParticipantA1MaintainCommand extends Command
{
    /** Prefixos dos diretórios temporários que podem conter o PFX do participante. */
    public const TEMPORARY_PREFIXES = ['a1-apply-', 'a1-upload-', 'a1-proc-'];

    /** Margem além do maior tempo de vida legítimo de um diretório temporário. */
    private const ORPHAN_MARGIN_SECONDS = 300;

    protected $signature = 'participant-a1:maintain {--limit=200 : Envelopes vencidos por execução}';

    protected $description = 'Apaga material selado vencido e PFX órfãos do A1 do participante e conclui envelopes cujo prazo venceu';

    public function handle(SealedCertificateStore $sealed, PdfToolClient $pdftool, Filesystem $files): int
    {
        $purged = $sealed->purgeOlderThan();
        $orphans = $this->purgeOrphanTemporaryDirectories($pdftool, $files);

        $due = ParticipantSignatureRequest::withoutOrganizationScope()
            ->join('envelopes', 'envelopes.id', '=', 'participant_signature_requests.envelope_id')
            ->where('envelopes.status', EnvelopeStatus::Finalizing->value)
            ->whereIn('participant_signature_requests.status', ParticipantSignatureRequestStatus::pendingValues())
            ->whereNotNull('participant_signature_requests.window_expires_at')
            ->where('participant_signature_requests.window_expires_at', '<=', Carbon::now())
            ->select(['participant_signature_requests.envelope_id', 'participant_signature_requests.organization_id'])
            ->distinct()
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($due as $row) {
            ApplyParticipantSignatureDeadline::dispatch((int) $row->envelope_id, (int) $row->organization_id);
        }

        $this->info(sprintf(
            'Material selado apagado: %d. Diretórios temporários órfãos do A1 apagados: %d. Envelopes com prazo vencido despachados: %d.',
            $purged,
            $orphans,
            $due->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Idade mínima (segundos) para um diretório temporário do A1 ser considerado órfão.
     */
    public static function orphanAgeSeconds(): int
    {
        $pdftool = app(PdfToolClient::class);

        return max(
            max(30, (int) config('assinavelox.participant_a1.lock_seconds', 900)),
            (new \ReflectionClass(ApplyParticipantSignature::class))->getDefaultProperties()['timeout'] ?? 600,
            $pdftool->signTimeout(),
            $pdftool->timeout(),
            max(1, (int) config('assinavelox.participant_a1.sealed_ttl_minutes', 15)) * 60,
        ) + self::ORPHAN_MARGIN_SECONDS;
    }

    private function purgeOrphanTemporaryDirectories(PdfToolClient $pdftool, Filesystem $files): int
    {
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $pdftool->temporaryRoot()), DIRECTORY_SEPARATOR);

        if ($root === '' || ! is_dir($root)) {
            return 0;
        }

        $cutoff = time() - self::orphanAgeSeconds();
        $removed = 0;

        foreach (self::TEMPORARY_PREFIXES as $prefix) {
            foreach (glob($root.DIRECTORY_SEPARATOR.$prefix.'*', GLOB_ONLYDIR) ?: [] as $directory) {
                if (is_link($directory) || $this->newestModification($directory) >= $cutoff) {
                    continue;
                }

                if ($files->deleteDirectory($directory) || ! is_dir($directory)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * Modificação mais recente do diretório e do que está dentro dele (um worker vivo que
     * acabou de gravar o PFX nunca é confundido com um órfão).
     */
    private function newestModification(string $directory): int
    {
        $newest = (int) (@filemtime($directory) ?: 0);

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $entry) {
            $newest = max($newest, (int) (@filemtime($entry) ?: 0));
        }

        return $newest;
    }
}
