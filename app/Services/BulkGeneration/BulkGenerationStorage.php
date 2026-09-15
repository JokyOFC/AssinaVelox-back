<?php

namespace App\Services\BulkGeneration;

use App\Models\BulkGeneration;
use App\Models\Organization;
use App\Services\BulkGeneration\Spreadsheet\SpreadsheetRejectedException;
use App\Services\Documents\DocumentStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Planilha do lote no disco PRIVADO dos documentos (`orgs/{org}/bulk/{lote}/planilha.{ext}`),
 * que sai junto no OrganizationPurge (diretório `orgs/{ulid}`). O sha256 gravado no envio é
 * conferido a cada leitura: o arquivo lido na pré-validação é exatamente o enviado.
 */
final class BulkGenerationStorage
{
    public const DISK = DocumentStorage::DISK;

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    public function pathFor(Organization $organization, string $batchUlid, string $format): string
    {
        return "orgs/{$organization->ulid}/bulk/{$batchUlid}/planilha.{$format}";
    }

    public function put(string $localPath, string $path): void
    {
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw SpreadsheetRejectedException::make('store_failed', 'Não foi possível guardar a planilha. Tente novamente.');
        }

        try {
            $this->disk()->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Copia a planilha para um arquivo temporário local, confere o sha256 e entrega o caminho.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     *
     * @throws SpreadsheetRejectedException
     */
    public function withLocalCopy(BulkGeneration $batch, callable $callback): mixed
    {
        $source = $batch->source_file;

        if ($source === null || ! $this->disk()->exists($source)) {
            throw SpreadsheetRejectedException::make('source_missing', 'A planilha deste lote não está mais disponível. Envie o arquivo de novo.');
        }

        $directory = $this->temporaryDirectory();
        $local = $directory.DIRECTORY_SEPARATOR.Str::ulid().'.'.$batch->source_format;

        try {
            $in = $this->disk()->readStream($source);
            $out = fopen($local, 'wb');

            if (! is_resource($in) || $out === false) {
                throw SpreadsheetRejectedException::make('source_missing', 'A planilha deste lote não pôde ser lida. Envie o arquivo de novo.');
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            if (! hash_equals($batch->source_sha256, (string) hash_file('sha256', $local))) {
                throw SpreadsheetRejectedException::make('source_changed', 'A planilha guardada não confere com a enviada. Envie o arquivo de novo.');
            }

            return $callback($local);
        } finally {
            @unlink($local);
        }
    }

    /**
     * Apaga o arquivo (fim, cancelamento ou descarte do lote). O sha256 continua no registro.
     */
    public function delete(BulkGeneration $batch): void
    {
        if ($batch->source_file === null) {
            return;
        }

        try {
            $this->disk()->deleteDirectory(dirname($batch->source_file));
        } catch (Throwable) {
            // Órfão fica para a rotina de retenção; nunca derruba o fluxo.
        }

        $batch->forceFill(['source_file' => null])->save();
    }

    public function temporaryDirectory(): string
    {
        $directory = storage_path('app/tmp/bulk');

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }
}
