<?php

use Symfony\Component\Process\ExecutableFinder;

require_once __DIR__.'/Support/SdkHelpers.php';

/*
|--------------------------------------------------------------------------
| G-SDK (roadmap §3.9, aceite "SDKs com testes gerados contra fake server")
|--------------------------------------------------------------------------
| Orquestra os testes de cada SDK contra o servidor falso local (tools/sdkgen/fake_server.py,
| que confere método, caminho, cabeçalhos e corpo pela especificação). Nenhum teste usa rede
| externa. Sem o Python do venv do pdftool (que roda o servidor falso), os testes são pulados
| com a instrução de instalação; sem Node.js, só o teste do SDK Node é pulado.
*/

beforeEach(function () {
    if (sdkPython() === null) {
        $this->markTestSkipped('Python do venv do pdftool não encontrado (ele roda o servidor falso dos SDKs). Crie: cd tools/pdftool && python -m venv .venv');
    }
});

test('SDKs gerados da especificação versionada (impressão digital)', function () {
    $process = sdkProcess([(string) sdkPython(), 'tools/sdkgen/sdkgen.py', 'check'], base_path());

    expect($process->getExitCode())->toBe(0, 'SDKs desatualizados. '.SDK_REGENERATE_HINT.sdkOutput($process));
});

test('SDK PHP contra o servidor falso (curl e streams)', function () {
    $process = sdkWithFakeServer((string) sdkPython(), static fn (string $url) => sdkProcess([PHP_BINARY, 'tests/run.php'], base_path('sdks/php'), $url));

    expect($process->getExitCode())->toBe(0, 'SDK PHP falhou.'.sdkOutput($process))
        ->and($process->getOutput())->toMatch('/\d+ testes, 0 falhas/');

    // "0 testes, 0 falhas" também casaria: exige que algo tenha rodado (revisão G).
    preg_match('/(\d+) testes, 0 falhas/', $process->getOutput(), $match);
    expect((int) ($match[1] ?? 0))->toBeGreaterThan(0, 'SDK PHP: nenhum teste rodou.'.sdkOutput($process));
});

test('SDK Node (TypeScript) contra o servidor falso', function () {
    $node = (new ExecutableFinder)->find('node');
    $tsc = base_path('node_modules/typescript/bin/tsc');

    if ($node === null) {
        $this->markTestSkipped('Node.js não encontrado no PATH: o SDK Node NÃO foi testado. Instale o Node 18+ e rode de novo.');
    }

    if (! is_file($tsc)) {
        $this->markTestSkipped('TypeScript não encontrado em node_modules (rode npm install): o SDK Node NÃO foi compilado nem testado.');
    }

    $build = sdkProcess([$node, $tsc, '-p', 'tsconfig.json'], base_path('sdks/node'));
    expect($build->getExitCode())->toBe(0, 'O SDK Node não compilou.'.sdkOutput($build));

    $files = array_map(static fn (string $file): string => 'test/'.basename($file), glob(base_path('sdks/node/test/*.test.mjs')) ?: []);
    expect($files)->not->toBeEmpty();

    $process = sdkWithFakeServer((string) sdkPython(), static fn (string $url) => sdkProcess([$node, '--test', ...$files], base_path('sdks/node'), $url));

    expect($process->getExitCode())->toBe(0, 'SDK Node falhou.'.sdkOutput($process))
        ->and($process->getOutput())->toMatch('/# fail 0/');

    // Um arquivo sem nenhum `test()` (ou só com testes pulados) também dá "fail 0" (revisão G).
    preg_match('/# pass (\d+)/', $process->getOutput(), $pass);
    expect((int) ($pass[1] ?? 0))->toBeGreaterThan(0, 'SDK Node: nenhum teste passou.'.sdkOutput($process))
        ->and($process->getOutput())->toMatch('/# skipped 0/');
});

test('SDK Python contra o servidor falso', function () {
    $process = sdkWithFakeServer((string) sdkPython(), static fn (string $url) => sdkProcess(
        [(string) sdkPython(), '-m', 'unittest', 'discover', '-s', 'tests', '-t', '.'],
        base_path('sdks/python'),
        $url,
    ));

    expect($process->getExitCode())->toBe(0, 'SDK Python falhou.'.sdkOutput($process))
        ->and($process->getErrorOutput())->toMatch('/^OK/m');

    preg_match('/^Ran (\d+) tests?/m', $process->getErrorOutput(), $ran);
    expect((int) ($ran[1] ?? 0))->toBeGreaterThan(0, 'SDK Python: nenhum teste rodou.'.sdkOutput($process));
});
