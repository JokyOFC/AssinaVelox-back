<?php

namespace App\Services\Templates;

use App\Models\Template;
use App\Models\TemplateVersion;
use App\Services\Documents\DocumentStorage;
use App\Services\Pdf\Support\TemporaryDirectory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Arquivos de origem dos modelos no disco privado `documents`.
 *
 * Mesmo esquema opaco dos documentos ({@see DocumentStorage}), sob a pasta da organização:
 * `orgs/{organization_ulid}/templates/{template_ulid}/{version_ulid}.{ext}`. Por ficar sob
 * `orgs/{ulid}`, a exclusão definitiva da organização (OrganizationPurge) leva junto os
 * arquivos de modelos. O nome enviado pelo usuário nunca entra no caminho.
 */
final class TemplateStorage
{
    public const DISK = DocumentStorage::DISK;

    public function __construct(private readonly Repository $config) {}

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    public function newVersionUlid(): string
    {
        return (string) Str::ulid();
    }

    public function pathFor(Template $template, string $versionUlid, string $extension): string
    {
        $prefix = trim((string) $this->config->get('assinavelox.upload.path_prefix', 'orgs'), '/');
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'bin';

        return sprintf(
            '%s/%s/templates/%s/%s.%s',
            $prefix,
            $template->organization->ulid,
            $template->ulid,
            $versionUlid,
            $extension,
        );
    }

    public function putFile(string $localPath, string $storagePath): void
    {
        $stream = @fopen($localPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Não foi possível ler o arquivo do modelo para gravação.');
        }

        try {
            $this->disk()->put($storagePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function exists(TemplateVersion $version): bool
    {
        return $version->storage_path !== null && $this->disk()->exists($version->storage_path);
    }

    /**
     * Copia o arquivo da versão para o diretório temporário da operação e confere o sha256
     * gravado na versão: bytes que mudaram no disco nunca viram documento.
     *
     * @throws TemplateRejectedException
     */
    public function copyToTemporary(TemplateVersion $version, TemporaryDirectory $directory, string $filename): string
    {
        if (! $this->exists($version)) {
            throw TemplateRejectedException::make(
                'source_missing',
                'O arquivo deste modelo não foi encontrado. Envie o arquivo do modelo novamente.',
            );
        }

        $target = $directory->path($filename);
        $source = $this->disk()->readStream((string) $version->storage_path);

        if (! is_resource($source)) {
            throw TemplateRejectedException::make('source_missing', 'O arquivo deste modelo não pôde ser lido.');
        }

        $handle = @fopen($target, 'wb');

        if ($handle === false) {
            fclose($source);

            throw new RuntimeException('Não foi possível preparar o arquivo temporário do modelo.');
        }

        try {
            stream_copy_to_stream($source, $handle);
        } finally {
            fclose($source);
            fclose($handle);
        }

        if ($version->sha256 !== null && hash_file('sha256', $target) !== $version->sha256) {
            throw TemplateRejectedException::make(
                'source_integrity',
                'O arquivo deste modelo não corresponde ao que foi cadastrado. Envie o arquivo do modelo novamente.',
            );
        }

        return $target;
    }

    public function temporaryDirectory(string $prefix = 'tpl-'): TemporaryDirectory
    {
        $root = (string) $this->config->get('pdftool.tmp_path', '') ?: storage_path('app/tmp/pdftool');

        return TemporaryDirectory::create($root, $prefix);
    }

    public function stream(TemplateVersion $version, string $filename, string $disposition, string $contentType): StreamedResponse
    {
        return $this->disk()->response((string) $version->storage_path, $filename, [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ], $disposition);
    }
}
