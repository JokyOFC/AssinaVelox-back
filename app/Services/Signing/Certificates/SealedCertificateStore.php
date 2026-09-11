<?php

namespace App\Services\Signing\Certificates;

use App\Services\Signing\Certificates\Exceptions\ParticipantCertificateException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Armazenamento TEMPORÁRIO e CIFRADO do conjunto PFX + senha, entre a requisição do
 * participante e o worker que aplica a assinatura (Fase 2 §2.12, regra de segredos).
 *
 * - **Cifra**: AES-256-GCM. A chave é derivada por HKDF-SHA256 da APP_KEY com um sal
 *   aleatório de 16 bytes POR ARQUIVO e o rótulo `assinavelox:participant-a1:seal:v1`; o
 *   identificador do pedido entra como dado autenticado (AAD) — um arquivo não serve para
 *   outro pedido. Nem a chave, nem o sal, nem o conteúdo vão ao banco: o banco guarda só o
 *   `sealed_ulid` e o prazo.
 * - **Consumo único**: {@see self::open()} lê e APAGA o arquivo antes de decifrar. Um segundo
 *   worker, uma retentativa ou um replay não encontram nada.
 * - **Prazo curto**: quem sela registra `sealed_expires_at`; arquivos mais velhos que o prazo
 *   são apagados por {@see self::purgeOlderThan()} e recusados na abertura.
 * - **Nunca em fila**: o job carrega só o id do pedido; o material não é serializável
 *   ({@see SealedCertificate}).
 *
 * O diretório (`assinavelox.participant_a1.sealed_path`) fica fora de `public/`, com 0700.
 */
final class SealedCertificateStore
{
    private const MAGIC = 'AVS1';

    private const INFO = 'assinavelox:participant-a1:seal:v1';

    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly Repository $config) {}

    public function directory(): string
    {
        $path = (string) $this->config->get('assinavelox.participant_a1.sealed_path', '');

        if ($path === '') {
            $path = storage_path('app/private/participant-a1');
        }

        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    /**
     * Cifra e grava. Devolve o identificador do arquivo (o único dado que vai ao banco).
     */
    public function seal(
        string $bindTo,
        #[\SensitiveParameter] string $pfxBytes,
        #[\SensitiveParameter] string $password,
    ): string {
        $this->ensureDirectory();

        $ulid = (string) Str::ulid();
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $tag = '';
        $plain = pack('N', strlen($password)).$password.$pfxBytes;

        try {
            $cipher = openssl_encrypt($plain, self::CIPHER, $this->key($salt), OPENSSL_RAW_DATA, $iv, $tag, $this->aad($ulid, $bindTo), 16);
        } finally {
            $plain = str_repeat("\0", strlen($plain));
            unset($plain);
        }

        if ($cipher === false || strlen($tag) !== 16) {
            throw new RuntimeException('Não foi possível cifrar o material temporário do certificado.');
        }

        $path = $this->path($ulid);

        if (file_put_contents($path, self::MAGIC.$salt.$iv.$tag.$cipher, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar o material temporário do certificado.');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($path, 0600);
        }

        return $ulid;
    }

    /**
     * Abre (e APAGA) o material. Falha fechado: arquivo ausente, velho, adulterado ou de
     * outro pedido ⇒ exceção, e o arquivo não existe mais depois da chamada.
     *
     * @throws ParticipantCertificateException
     */
    public function open(string $sealedUlid, string $bindTo, ?int $maxAgeMinutes = null): SealedCertificate
    {
        $path = $this->path($sealedUlid);

        if (! is_file($path)) {
            throw new ParticipantCertificateException('sealed_missing', 'O certificado enviado não está mais disponível. Envie o certificado de novo.', 410);
        }

        $maxAgeMinutes ??= $this->ttlMinutes();
        $mtime = @filemtime($path);
        $raw = @file_get_contents($path);
        $this->unlink($path);

        if ($raw === false || $mtime === false) {
            throw new ParticipantCertificateException('sealed_unreadable', 'O certificado enviado não pôde ser lido. Envie o certificado de novo.', 410);
        }

        if ($mtime < time() - $maxAgeMinutes * 60) {
            throw new ParticipantCertificateException('sealed_expired', 'O prazo para usar o certificado enviado terminou. Envie o certificado de novo.', 410);
        }

        if (strlen($raw) < 4 + 16 + 12 + 16 + 4 || ! str_starts_with($raw, self::MAGIC)) {
            throw new ParticipantCertificateException('sealed_unreadable', 'O certificado enviado não pôde ser lido. Envie o certificado de novo.', 410);
        }

        $salt = substr($raw, 4, 16);
        $iv = substr($raw, 20, 12);
        $tag = substr($raw, 32, 16);
        $cipher = substr($raw, 48);

        $plain = openssl_decrypt($cipher, self::CIPHER, $this->key($salt), OPENSSL_RAW_DATA, $iv, $tag, $this->aad($sealedUlid, $bindTo));

        if ($plain === false || strlen($plain) < 4) {
            throw new ParticipantCertificateException('sealed_unreadable', 'O certificado enviado não pôde ser lido. Envie o certificado de novo.', 410);
        }

        /** @var array{1: int} $length */
        $length = unpack('N', substr($plain, 0, 4));
        $password = substr($plain, 4, $length[1]);
        $pfx = substr($plain, 4 + $length[1]);
        $plain = str_repeat("\0", strlen($plain));

        return new SealedCertificate($pfx, $password);
    }

    public function exists(?string $sealedUlid): bool
    {
        return $sealedUlid !== null && $sealedUlid !== '' && is_file($this->path($sealedUlid));
    }

    /**
     * Apaga o material, se existir. Idempotente; nunca lança.
     */
    public function discard(?string $sealedUlid): void
    {
        if ($sealedUlid === null || $sealedUlid === '') {
            return;
        }

        try {
            $this->unlink($this->path($sealedUlid));
        } catch (\Throwable) {
            // Identificador inválido: não há o que apagar.
        }
    }

    /**
     * Apaga todo material mais velho que o prazo. Devolve quantos arquivos saíram.
     */
    public function purgeOlderThan(?int $minutes = null): int
    {
        $minutes ??= $this->ttlMinutes();
        $directory = $this->directory();

        if (! is_dir($directory)) {
            return 0;
        }

        $removed = 0;

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.sealed') ?: [] as $file) {
            $mtime = @filemtime($file);

            if ($mtime !== false && $mtime < time() - $minutes * 60) {
                $this->unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) $this->config->get('assinavelox.participant_a1.sealed_ttl_minutes', 15));
    }

    private function path(string $ulid): string
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ulid) !== 1) {
            throw new RuntimeException('Identificador de material temporário inválido.');
        }

        return $this->directory().DIRECTORY_SEPARATOR.$ulid.'.sealed';
    }

    private function aad(string $ulid, string $bindTo): string
    {
        return self::INFO.'|'.$ulid.'|'.$bindTo;
    }

    private function key(string $salt): string
    {
        $appKey = (string) $this->config->get('app.key', '');

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY ausente: o material temporário do certificado não pode ser cifrado.');
        }

        return hash_hkdf('sha256', $appKey, 32, self::INFO, $salt);
    }

    private function ensureDirectory(): void
    {
        $directory = $this->directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório do material temporário do certificado.');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($directory, 0700);
        }
    }

    private function unlink(string $path): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (! file_exists($path) || @unlink($path)) {
                return;
            }

            usleep(20_000);
        }
    }
}
