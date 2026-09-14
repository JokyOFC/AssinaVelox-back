<?php

namespace App\Services\Signing\External;

use FilesystemIterator;
use Illuminate\Contracts\Config\Repository;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Arquivos de uma reserva de assinatura externa (Fase 3 §3.4): a revisão pendente
 * (`pending.pdf`, com o espaço reservado vazio) e o estado mínimo do pdftool (`state.json`),
 * num diretório por reserva (`{pending_path}/{ulid}`, 0700, fora de `public/`).
 *
 * Nada aqui é segredo (sem chave, senha ou sessão de token), mas é trabalho em andamento:
 * vive no máximo o TTL da reserva e é apagado ao consumir, expirar ou descartar. O banco
 * guarda o sha256 de cada arquivo; quem lê confere antes de usar.
 */
final class PendingSignatureFiles
{
    public const PENDING = 'pending.pdf';

    public const STATE = 'state.json';

    public function __construct(private readonly Repository $config) {}

    public function root(): string
    {
        $root = (string) $this->config->get('assinavelox.external_signing.pending_path', storage_path('app/private/external-signing'));

        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
    }

    public function directory(string $ulid): string
    {
        $path = $this->root().DIRECTORY_SEPARATOR.$this->checked($ulid);

        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Não foi possível preparar o diretório da assinatura externa.');
        }

        return $path;
    }

    public function path(string $ulid, string $name): string
    {
        if (! in_array($name, [self::PENDING, self::STATE], true)) {
            throw new RuntimeException('Arquivo de reserva desconhecido.');
        }

        return $this->root().DIRECTORY_SEPARATOR.$this->checked($ulid).DIRECTORY_SEPARATOR.$name;
    }

    /** O arquivo existe e tem exatamente o sha256 registrado? */
    public function matches(string $ulid, string $name, string $sha256): bool
    {
        $path = $this->path($ulid, $name);
        $actual = is_file($path) ? hash_file('sha256', $path) : false;

        return is_string($actual) && hash_equals($sha256, $actual);
    }

    public function delete(?string $ulid): void
    {
        if ($ulid === null || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ulid) !== 1) {
            return;
        }

        $this->removeTree($this->root().DIRECTORY_SEPARATOR.$ulid);
    }

    /**
     * Apaga diretórios de reserva mais velhos que `$minutes` (processo que morreu no meio).
     * Nunca lê o conteúdo.
     */
    public function purgeOlderThan(int $minutes): int
    {
        $root = $this->root();

        if (! is_dir($root)) {
            return 0;
        }

        $limit = time() - max(1, $minutes) * 60;
        $removed = 0;

        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isDir() && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $entry->getFilename()) === 1 && $entry->getMTime() < $limit) {
                $this->removeTree($entry->getPathname());
                $removed++;
            }
        }

        return $removed;
    }

    private function checked(string $ulid): string
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ulid) !== 1) {
            throw new RuntimeException('Identificador de reserva inválido.');
        }

        return $ulid;
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
