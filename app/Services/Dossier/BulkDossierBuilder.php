<?php

namespace App\Services\Dossier;

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Services\Pdf\Support\TemporaryDirectory;
use Illuminate\Support\Carbon;
use Throwable;
use ZipArchive;

/**
 * "Baixar" em lote (Q12): um ZIP externo com UM dossiê por envelope selecionado
 * (`AV-00012.zip`), mais `indice.json` e `LEIA-ME.txt`.
 *
 * A visibilidade foi conferida no pedido E é conferida de novo aqui pelo chamador (quem
 * perdeu o acesso entre o pedido e a montagem não recebe o envelope). Envelope que não pode
 * entrar aparece em `indice.json.skipped` com o motivo — nunca some em silêncio.
 */
final class BulkDossierBuilder
{
    public const FORMAT = 'assinavelox-dossier-bulk/1';

    public function __construct(private readonly DossierBuilder $builder) {}

    /**
     * @param  iterable<Envelope>  $envelopes  já filtrados pela visibilidade do solicitante
     * @param  list<array{envelope: string, reason: string}>  $skipped
     */
    public function build(iterable $envelopes, string $zipPath, TemporaryDirectory $work, ?int $dossierExportId, bool $stamp, array $skipped = [], ?string $correlationId = null): BuiltDossier
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new DossierException('Não foi possível criar o arquivo do lote.', 'zip_failed');
        }

        $index = [];
        $entries = [];
        $count = 0;
        $mtime = Carbon::now()->getTimestamp();

        foreach ($envelopes as $envelope) {
            if ($envelope->status !== EnvelopeStatus::Completed) {
                $skipped[] = ['envelope' => $envelope->display_code, 'reason' => 'Documento não concluído.'];

                continue;
            }

            $inner = $work->path('lote-'.$envelope->ulid.'.zip');

            try {
                $built = $this->builder->build($envelope, $inner, $work, $dossierExportId, $stamp, $correlationId);
            } catch (Throwable $exception) {
                $skipped[] = ['envelope' => $envelope->display_code, 'reason' => $exception instanceof DossierException ? $exception->getMessage() : 'Falha ao montar o dossiê deste documento.'];

                continue;
            }

            $name = $envelope->display_code.'.zip';
            $zip->addFile($inner, $name);
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
            $zip->setMtimeName($name, $mtime);
            $entries[] = $name;
            $count++;

            $index[] = [
                'file' => $name,
                'display_code' => $envelope->display_code,
                'title' => $envelope->title,
                'verification_code' => $envelope->formatted_verification_code,
                'dossier_sha256' => $built->sha256,
                'manifest_sha256' => $built->manifestSha256,
                'timestamp' => $built->timestampStatus,
            ];
        }

        if ($count === 0) {
            $zip->close();
            @unlink($zipPath);

            throw new DossierException('Nenhum dos documentos selecionados pôde entrar no lote.', 'bulk_empty');
        }

        $indexJson = (string) json_encode([
            'format' => self::FORMAT,
            'envelopes' => $index,
            'skipped' => $skipped,
            'note' => 'Cada arquivo .zip é um dossiê completo e independente, com o próprio manifest.json e LEIA-ME.txt.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        $readme = "LOTE DE DOSSIÊS — AssinaVelox\n\nCada arquivo AV-xxxxx.zip é o dossiê de um documento, com instruções próprias (LEIA-ME.txt dentro dele).\nindice.json lista o SHA-256 de cada dossiê e os documentos que ficaram de fora, com o motivo.\n";

        foreach (['indice.json' => $indexJson, 'LEIA-ME.txt' => $readme] as $name => $contents) {
            $zip->addFromString($name, $contents);
            $zip->setMtimeName($name, $mtime);
            $entries[] = $name;
        }

        if (! $zip->close()) {
            throw new DossierException('Não foi possível finalizar o arquivo do lote.', 'zip_failed');
        }

        $max = max(1, (int) config('assinavelox.dossier.max_mb', 500)) * 1024 * 1024;
        $size = (int) filesize($zipPath);

        if ($size > $max) {
            @unlink($zipPath);

            throw new DossierException('O lote ultrapassa o tamanho máximo permitido; selecione menos documentos.', 'dossier_too_large');
        }

        return new BuiltDossier($zipPath, (string) hash_file('sha256', $zipPath), $size, null, 'not_applicable', null, $count, $entries);
    }
}
