<?php

namespace App\Services\Pdf\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Diretório temporário exclusivo por operação: <raiz>/<ulid>, criado com
 * permissão 0700 (o SO Windows ignora o modo; lá a proteção vem da ACL do
 * diretório storage/). Deve ser removido em `finally` pelo dono; o destrutor é
 * apenas rede de segurança.
 */
final class TemporaryDirectory
{
    private bool $deleted = false;

    private function __construct(private readonly string $path) {}

    public static function create(string $root, string $prefix = ''): self
    {
        // storage_path('app/tmp/...') mistura separadores no Windows; uniformiza.
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
        if ($root === '') {
            throw new RuntimeException('Raiz do diretório temporário não configurada (pdftool.tmp_path).');
        }

        self::ensureDirectory($root);

        $path = $root.DIRECTORY_SEPARATOR.$prefix.(string) Str::ulid();
        if (is_dir($path)) {
            // ULIDs são únicos por milissegundo; um choque só acontece se o relógio voltar.
            $path .= '-'.bin2hex(random_bytes(4));
        }

        self::ensureDirectory($path);

        if (! is_writable($path)) {
            throw new RuntimeException("Diretório temporário não gravável: {$path}");
        }

        return new self($path);
    }

    /**
     * Caminho absoluto do diretório ou de um arquivo dentro dele.
     */
    public function path(?string $file = null): string
    {
        if ($file === null || $file === '') {
            return $this->path;
        }

        if (str_contains($file, '..') || str_contains($file, "\0")) {
            throw new RuntimeException('Nome de arquivo temporário inválido.');
        }

        return $this->path.DIRECTORY_SEPARATOR.ltrim($file, '/\\');
    }

    /**
     * Subdiretório (criado com 0700) dentro do diretório temporário.
     */
    public function subdirectory(string $name): string
    {
        $path = $this->path($name);
        self::ensureDirectory($path);

        return $path;
    }

    public function exists(): bool
    {
        return ! $this->deleted && is_dir($this->path);
    }

    /**
     * Remove o diretório e todo o conteúdo. Idempotente; nunca lança.
     */
    public function delete(): void
    {
        if ($this->deleted) {
            return;
        }

        $this->deleted = true;

        if (! is_dir($this->path)) {
            return;
        }

        $filesystem = new Filesystem;

        // No Windows um handle ainda aberto pelo processo filho recém-encerrado pode
        // segurar o arquivo por alguns milissegundos; tenta de novo antes de desistir.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($filesystem->deleteDirectory($this->path)) {
                return;
            }
            usleep(50_000);
        }
    }

    public function __destruct()
    {
        $this->delete();
    }

    private static function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException("Não foi possível criar o diretório temporário: {$path}");
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($path, 0700);
        }
    }
}
