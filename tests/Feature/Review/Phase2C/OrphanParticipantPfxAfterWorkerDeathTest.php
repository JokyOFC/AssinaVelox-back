<?php

use Illuminate\Support\Str;

require_once __DIR__.'/../../Phase2/ParticipantA1/Support/ParticipantA1Helpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda C — custódia e criptografia
|--------------------------------------------------------------------------
| `ParticipantSignatureApplier::applyLocked()` decifra o material selado e grava o PFX do
| participante em `<pdftool.tmp_path>/a1-apply-<ulid>/participante.pfx` (linhas 201-205); o
| upload faz o mesmo em `a1-upload-<ulid>/certificado.pfx`. A remoção depende SÓ do `finally`
| e do destrutor. Quando o worker é morto — `$timeout = 600` do job (o Laravel mata o processo
| com SIGKILL), OOM, deploy, `max_execution_time` na requisição —, nem `finally` nem destrutor
| rodam: o PFX (protegido só pela senha do titular) fica no disco para sempre.
|
| `participant-a1:maintain` promete "retenção mínima também quando o pedido some ou o worker
| cai", mas só varre o diretório SELADO; nada no agendamento varre o diretório temporário do
| pdftool. Este teste reproduz o que sobra de um worker morto e roda a manutenção agendada.
*/

beforeEach(fn () => participantA1Boot($this, false));
afterEach(fn () => participantA1Teardown($this));

it('o PFX decifrado deixado por um worker morto é apagado pela manutenção agendada', function () {
    $maria = participantA1Certificate($this->work.'/certs', 'Maria Alves Souza', 'senha-da-Maria-Orfa-7!');
    $root = rtrim((string) config('pdftool.tmp_path'), '/\\');
    $old = time() - 6 * 3600; // bem além do prazo do selado (15 min), do lock (900 s) e do job (600 s)

    $orphans = [];

    foreach (['a1-apply-' => 'participante.pfx', 'a1-upload-' => 'certificado.pfx'] as $prefix => $file) {
        $dir = $root.DIRECTORY_SEPARATOR.$prefix.(string) Str::ulid();
        mkdir($dir, 0700, true);
        $path = $dir.DIRECTORY_SEPARATOR.$file;
        copy($maria['pfx'], $path);
        touch($path, $old);
        touch($dir, $old);
        $orphans[] = $path;
    }

    $this->artisan('participant-a1:maintain')->assertSuccessful();

    foreach ($orphans as $path) {
        expect(file_exists($path))->toBeFalse("PFX do participante continua no disco: {$path}");
    }
});
