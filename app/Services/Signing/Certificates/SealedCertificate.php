<?php

namespace App\Services\Signing\Certificates;

use LogicException;

/**
 * PFX + senha do participante, EM MEMÓRIA, entre a abertura do material cifrado e o fim da
 * aplicação. Nunca é serializado (fila, cache, sessão, log), nunca é clonado e nunca se
 * descreve: `var_dump`/`print_r` mostram `[REDACTED]`.
 *
 * Quem abre é responsável por chamar {@see self::wipe()} em `finally`.
 */
final class SealedCertificate
{
    private bool $wiped = false;

    public function __construct(
        #[\SensitiveParameter] private string $pfx,
        #[\SensitiveParameter] private string $password,
    ) {}

    public function password(): string
    {
        $this->assertAlive();

        return $this->password;
    }

    /**
     * Grava o PFX num arquivo do diretório temporário exclusivo da operação (0600).
     */
    public function writePfxTo(string $path): void
    {
        $this->assertAlive();

        if (file_put_contents($path, $this->pfx, LOCK_EX) === false) {
            throw new LogicException('Não foi possível preparar o arquivo temporário do certificado.');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($path, 0600);
        }
    }

    public function wipe(): void
    {
        if ($this->wiped) {
            return;
        }

        $this->pfx = str_repeat("\0", strlen($this->pfx));
        $this->password = str_repeat("\0", strlen($this->password));
        $this->pfx = '';
        $this->password = '';
        $this->wiped = true;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['pfx' => '[REDACTED]', 'password' => '[REDACTED]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('O material do certificado do participante nunca é serializado.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('O material do certificado do participante nunca é serializado.');
    }

    public function __clone()
    {
        throw new LogicException('O material do certificado do participante não é copiado.');
    }

    public function __destruct()
    {
        $this->wipe();
    }

    private function assertAlive(): void
    {
        if ($this->wiped) {
            throw new LogicException('O material do certificado já foi descartado.');
        }
    }
}
