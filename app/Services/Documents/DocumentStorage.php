<?php

namespace App\Services\Documents;

use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Services\Pdf\Support\TemporaryDirectory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Caminhos e leitura/escrita no disco privado `documents`.
 *
 * O caminho é montado **só** com identificadores opacos:
 * `orgs/{organization_ulid}/envelopes/{envelope_ulid}/{version_ulid}.{ext}`.
 * O nome original do arquivo nunca entra no caminho — ele é apenas exibido
 * (`documents.original_filename`). Isso elimina de uma vez path traversal, colisão de
 * nomes, caracteres inválidos por sistema de arquivos e vazamento de nome entre
 * organizações.
 */
class DocumentStorage
{
    public const DISK = 'documents';

    public function __construct(private readonly Repository $config) {}

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * Caminho de uma nova versão. `$versionUlid` é gerado por quem cria a versão.
     */
    public function pathFor(Envelope $envelope, string $versionUlid, string $extension): string
    {
        $prefix = trim((string) $this->config->get('assinavelox.upload.path_prefix', 'orgs'), '/');
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'bin';

        return sprintf(
            '%s/%s/envelopes/%s/%s.%s',
            $prefix,
            $envelope->organization->ulid,
            $envelope->ulid,
            $versionUlid,
            $extension,
        );
    }

    public function newVersionUlid(): string
    {
        return (string) Str::ulid();
    }

    /**
     * Grava o conteúdo de um arquivo local no disco `documents` em modo streaming.
     */
    public function putFile(string $localPath, string $storagePath): void
    {
        $stream = @fopen($localPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Não foi possível ler o arquivo enviado para gravação no disco de documentos.');
        }

        try {
            $this->disk()->put($storagePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Copia a versão do disco `documents` para um arquivo dentro do diretório temporário
     * exclusivo da operação. Funciona com qualquer driver (local ou S3).
     */
    public function copyToTemporary(DocumentVersion $version, TemporaryDirectory $directory, string $filename): string
    {
        $target = $directory->path($filename);
        $source = $this->disk()->readStream($version->storage_path);

        if ($source === null) {
            throw new RuntimeException('Arquivo da versão do documento não encontrado no disco.');
        }

        $handle = @fopen($target, 'wb');

        if ($handle === false) {
            if (is_resource($source)) {
                fclose($source);
            }

            throw new RuntimeException('Não foi possível preparar o arquivo temporário do documento.');
        }

        try {
            stream_copy_to_stream($source, $handle);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            fclose($handle);
        }

        return $target;
    }

    public function exists(DocumentVersion $version): bool
    {
        return $this->disk()->exists($version->storage_path);
    }

    /**
     * Transmite a versão pelo controller autorizado. Nunca existe URL pública nem
     * assinada: o disco é privado e cada byte passa por policy (RECONCILIACAO Q23).
     *
     * `$disposition`: `inline` (visualizador PDF.js) ou `attachment` (download).
     */
    public function stream(
        DocumentVersion $version,
        string $filename,
        string $disposition = 'inline',
        ?string $contentType = null,
    ): StreamedResponse {
        $headers = [
            'Content-Type' => $contentType ?? $version->mime_type,
            'Content-Length' => (string) $version->size_bytes,
            'X-Content-Type-Options' => 'nosniff',
            // Documento privado: nada em cache de navegador ou proxy.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        return $this->disk()->response($version->storage_path, $filename, $headers, $disposition);
    }

    /**
     * Nome de arquivo seguro para o cabeçalho Content-Disposition (o nome enviado pelo
     * usuário nunca é usado como caminho; aqui ele só é exibido no download).
     */
    public function downloadFilename(string $preferred, string $fallback, string $extension): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', '', $preferred) ?? '';
        $name = trim($name);

        if ($name === '') {
            $name = $fallback;
        }

        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== strtolower($extension)) {
            $name .= '.'.$extension;
        }

        return mb_substr($name, 0, 200);
    }
}
