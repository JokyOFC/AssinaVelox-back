<?php

/*
|--------------------------------------------------------------------------
| Apoio dos testes dos SDKs (G-SDK, docs/fase-3/sdks.md). Não contém testes.
|--------------------------------------------------------------------------
*/

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Feature\Pdf\Support\PdfFixtures;

const SDK_REGENERATE_HINT = 'Regenere e revise o diff: tools/pdftool/.venv/Scripts/python.exe tools/sdkgen/sdkgen.py all (Linux: tools/pdftool/.venv/bin/python). Detalhes em docs/fase-3/sdks.md §3.';

if (! function_exists('sdkOverrides')) {
    /**
     * @return array<string, mixed>
     */
    function sdkOverrides(): array
    {
        return json_decode((string) file_get_contents(base_path('tools/sdkgen/overrides.json')), true, 512, JSON_THROW_ON_ERROR);
    }
}

if (! function_exists('sdkCanonical')) {
    /**
     * Chaves de objeto em ordem; listas intactas — compara conteúdo, não formatação.
     */
    function sdkCanonical(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $result = new stdClass;

            foreach ($properties as $key => $item) {
                $result->{$key} = sdkCanonical($item);
            }

            return $result;
        }

        return is_array($value) ? array_map('sdkCanonical', $value) : $value;
    }
}

if (! function_exists('sdkDifferences')) {
    /**
     * Caminhos JSON que diferem (para a mensagem de falha).
     *
     * @param  list<string>  $out
     */
    function sdkDifferences(mixed $expected, mixed $actual, string $path, array &$out): void
    {
        if (count($out) >= 15) {
            return;
        }

        if ($expected instanceof stdClass && $actual instanceof stdClass) {
            $keys = array_unique([...array_keys(get_object_vars($expected)), ...array_keys(get_object_vars($actual))]);

            foreach ($keys as $key) {
                $key = (string) $key;

                if (! property_exists($expected, $key)) {
                    $out[] = "+ {$path}.{$key} (novo na exportação)";
                } elseif (! property_exists($actual, $key)) {
                    $out[] = "- {$path}.{$key} (sumiu da exportação)";
                } else {
                    sdkDifferences($expected->{$key}, $actual->{$key}, "{$path}.{$key}", $out);
                }
            }

            return;
        }

        if (is_array($expected) && is_array($actual) && count($expected) === count($actual)) {
            foreach ($expected as $index => $item) {
                sdkDifferences($item, $actual[$index], "{$path}[{$index}]", $out);
            }

            return;
        }

        if ($expected != $actual) {
            $out[] = "~ {$path}";
        }
    }
}

if (! function_exists('sdkExportSpec')) {
    /**
     * `scramble:export` no processo do teste, com os valores fixados em overrides.json e o
     * `servers` normalizado — o mesmo que `sdkgen.py export` faz.
     */
    function sdkExportSpec(): stdClass
    {
        $overrides = sdkOverrides();

        foreach ($overrides['exportPins'] as $pin) {
            config()->set($pin['config'], $pin['value']);
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'sdkspec');

        try {
            Artisan::call('scramble:export', ['--path' => $path]);
            $spec = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        } finally {
            @unlink($path);
        }

        $spec->servers = [(object) ['url' => $overrides['server']['url'], 'description' => $overrides['server']['description']]];

        return $spec;
    }
}

if (! function_exists('sdkPython')) {
    function sdkPython(): ?string
    {
        $python = PdfFixtures::pythonBinary();

        return is_file($python) ? $python : null;
    }
}

if (! function_exists('sdkProcess')) {
    /**
     * @param  list<string>  $command
     */
    function sdkProcess(array $command, string $cwd, ?string $fakeUrl = null, int $timeout = 300): Process
    {
        $env = ['PYTHONIOENCODING' => 'utf-8', 'NO_PROXY' => '127.0.0.1,localhost', 'no_proxy' => '127.0.0.1,localhost'];

        if ($fakeUrl !== null) {
            $env['FAKE_API_URL'] = $fakeUrl;
        }

        $process = new Process($command, $cwd, $env);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }
}

if (! function_exists('sdkWithFakeServer')) {
    /**
     * Sobe tools/sdkgen/fake_server.py numa porta livre, roda o callback e derruba SÓ esse
     * processo (Symfony Process: no Windows, taskkill /T no PID que ele mesmo abriu).
     *
     * @template T
     *
     * @param  Closure(string): T  $callback
     * @return T
     */
    function sdkWithFakeServer(string $python, Closure $callback): mixed
    {
        $server = new Process([$python, 'tools/sdkgen/fake_server.py', '--port', '0'], base_path(), ['PYTHONIOENCODING' => 'utf-8']);
        $server->setTimeout(null);
        $server->start();

        try {
            $url = null;
            // Integração I-3G: `waitUntil()` travava sem prazo no Windows quando a linha chegava
            // ao buffer do Process antes de o callback ser registrado (a saída vai por arquivo
            // temporário e é lida em lote). Aqui a saída ACUMULADA é consultada em laço, com prazo.
            $deadline = microtime(true) + 30;

            while ($url === null && microtime(true) < $deadline) {
                if (preg_match('#FAKE_API_URL=(\S+)#', $server->getOutput(), $match) === 1) {
                    $url = $match[1];

                    break;
                }

                if (! $server->isRunning()) {
                    break;
                }

                usleep(50_000);
            }

            if ($url === null) {
                throw new RuntimeException('O servidor falso não subiu em 30 s: '.$server->getOutput().$server->getErrorOutput());
            }

            return $callback($url);
        } finally {
            $server->stop(3);
        }
    }
}

if (! function_exists('sdkOutput')) {
    function sdkOutput(Process $process): string
    {
        return "\n--- saída ---\n".$process->getOutput()."\n--- erros ---\n".$process->getErrorOutput();
    }
}
