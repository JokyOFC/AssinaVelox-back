<?php

namespace Tests\Support\Https;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Servidor HTTPS local de teste (HttpsPinTest): AC e certificados gerados em tempo de teste pelo
 * `cryptography` do venv do pdftool (make_certs.py) e um servidor mínimo com `ssl` do Python
 * (https_server.py). Só escuta em loopback. Cada instância é encerrada pelo PID que ela iniciou
 * (`stop()`), nunca por nome de processo.
 */
final class HttpsTestServer
{
    public const HOST = 'hooks.assinavelox.test';

    private function __construct(
        public readonly Process $process,
        public readonly string $bind,
        public readonly int $port,
        public readonly string $log,
    ) {}

    public static function python(): ?string
    {
        foreach (['tools/pdftool/.venv/Scripts/python.exe', 'tools/pdftool/.venv/bin/python'] as $candidate) {
            $path = base_path($candidate);

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Gera a AC de teste e as folhas (good, wrong, iponly, rogue) num diretório temporário.
     */
    public static function makeCertificates(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avhttps-'.bin2hex(random_bytes(6));
        $process = new Process([(string) self::python(), __DIR__.'/make_certs.py', $directory, self::HOST]);
        $process->setTimeout(60)->mustRun();

        return $directory;
    }

    public static function freePort(string $bind = '127.0.0.1'): int
    {
        $socket = stream_socket_server('tcp://'.$bind.':0', $errno, $error);

        if ($socket === false) {
            throw new RuntimeException("sem porta livre: {$error}");
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) strrchr($name, ':'), 1);
    }

    /**
     * @param  string  $certificate  good | wrong | iponly | rogue
     */
    public static function start(string $certificates, string $certificate, string $label, string $bind = '127.0.0.1', ?int $port = null): self
    {
        $port ??= self::freePort($bind);
        $log = (string) tempnam(sys_get_temp_dir(), 'avhttpslog');

        $process = new Process([
            (string) self::python(), __DIR__.'/https_server.py', $bind, (string) $port,
            $certificates.DIRECTORY_SEPARATOR.$certificate.'.pem',
            $certificates.DIRECTORY_SEPARATOR.$certificate.'.key',
            $log, $label,
        ]);
        $process->setTimeout(null);
        $process->start();

        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            if (str_contains($process->getOutput(), 'ready')) {
                return new self($process, $bind, $port, $log);
            }

            if (! $process->isRunning()) {
                break;
            }

            usleep(50_000);
        }

        $process->stop(0);

        throw new RuntimeException('o servidor HTTPS de teste não subiu: '.$process->getErrorOutput());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(?string $type = null): array
    {
        $content = is_file($this->log) ? trim((string) file_get_contents($this->log)) : '';
        $events = $content === '' ? [] : array_map(
            static fn (string $line): array => (array) json_decode($line, true),
            explode("\n", $content),
        );

        return array_values(array_filter($events, static fn (array $event): bool => $type === null || $event['event'] === $type));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requests(): array
    {
        return $this->events('request');
    }

    public function stop(): void
    {
        $this->process->stop(1);
        @unlink($this->log);
    }

    public static function removeCertificates(string $directory): void
    {
        foreach ((array) glob($directory.DIRECTORY_SEPARATOR.'*') as $file) {
            @unlink((string) $file);
        }

        @rmdir($directory);
    }
}
