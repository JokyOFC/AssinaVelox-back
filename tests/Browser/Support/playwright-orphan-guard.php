<?php

/*
|--------------------------------------------------------------------------
| Guarda contra servidor do Playwright órfão (integração I-2C, docs/testes.md §6-C)
|--------------------------------------------------------------------------
| Uso (disparado por tests/BrowserTestCase.php, uma vez por processo da suíte):
|
|     php playwright-orphan-guard.php <pid do processo de teste> <pid do servidor do Playwright>
|
| Se o processo de teste morrer de forma anormal (teto de tempo, erro fatal, memória, ou
| alguém o encerra à força), o `Plugin::terminate()` do plugin não roda e o servidor do
| Playwright (cmd → node → navegador) fica vivo segurando o stdout herdado: o terminal ou o
| script que esperava a suíte parece travado para sempre. Esta guarda espera o processo de
| teste sumir e, SÓ se o servidor que ele iniciou ainda estiver vivo e for mesmo o
| `playwright run-server`, encerra essa árvore. Nunca mata node/navegador por nome.
|
| Saída normal: o plugin para o servidor antes de o processo de teste terminar; a guarda vê o
| servidor sumir e sai sem fazer nada.
*/

if ($argc < 3 || ! ctype_digit($argv[1]) || ! ctype_digit($argv[2])) {
    exit(2);
}

$parent = (int) $argv[1];
$server = (int) $argv[2];
$windows = PHP_OS_FAMILY === 'Windows';

$alive = static function (int $pid) use ($windows): bool {
    if ($windows) {
        $output = [];
        exec(sprintf('tasklist /FI "PID eq %d" /NH /FO CSV 2>NUL', $pid), $output);

        return str_contains(implode("\n", $output), sprintf('"%d"', $pid));
    }

    return is_dir('/proc/'.$pid) || (function_exists('posix_kill') && posix_kill($pid, 0));
};

$commandLine = static function (int $pid) use ($windows): string {
    $output = [];

    if ($windows) {
        exec(sprintf(
            'powershell -NoProfile -NonInteractive -Command "(Get-CimInstance Win32_Process -Filter \'ProcessId=%d\').CommandLine" 2>NUL',
            $pid,
        ), $output);
    } elseif (is_readable('/proc/'.$pid.'/cmdline')) {
        $output[] = str_replace("\0", ' ', (string) file_get_contents('/proc/'.$pid.'/cmdline'));
    }

    return implode(' ', $output);
};

// Teto de vida da guarda: nenhuma suíte de navegador dura 6 h.
$deadline = time() + 6 * 3600;

while (time() < $deadline) {
    if (! $alive($server)) {
        exit(0);
    }

    if (! $alive($parent)) {
        break;
    }

    sleep(1);
}

if (! $alive($server)) {
    exit(0);
}

$command = $commandLine($server);

if (! str_contains($command, 'playwright') || ! str_contains($command, 'run-server')) {
    exit(0);
}

if ($windows) {
    exec(sprintf('taskkill /T /F /PID %d 2>NUL', $server));
} else {
    exec(sprintf('pkill -TERM -P %d 2>/dev/null; kill -TERM %d 2>/dev/null', $server, $server));
}

fwrite(STDERR, sprintf('[navegador] Servidor do Playwright órfão (PID %d) encerrado: o processo de teste %d terminou sem pará-lo.', $server, $parent).PHP_EOL);

exit(0);
