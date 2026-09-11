<?php

use App\Services\Timestamp\Exceptions\TsaException;
use App\Services\Timestamp\TsaToolRunner;
use Tests\Feature\Pdf\Support\PdfFixtures;

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda C — custódia e criptografia
|--------------------------------------------------------------------------
| `TsaToolRunner::run()` encadeia a `ProcessTimedOutException` / `ProcessException` do Symfony
| na `TsaException` (linhas 84-88). Essas exceções carregam o objeto `Process`, e o `Process`
| carrega o ambiente do filho — com a senha do PKCS#12 da TSA (e, no `PadesBtSigner`, também a
| do PFX da operadora). A regra da onda C é "senha nunca em exceção", e o próprio K-A1
| (`ParticipantCertificateTool`, docblock) evita exatamente esse encadeamento por esse motivo.
| Qualquer coletor de erros que serialize a exceção (ou `$e->getPrevious()->getProcess()->getEnv()`)
| lê a senha.
*/

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->work = PdfFixtures::workspace();

    // Um "pdftool" que nunca responde: força o tempo limite do runner sem depender de máquina lenta.
    $fake = $this->work.DIRECTORY_SEPARATOR.'fake-cwd'.DIRECTORY_SEPARATOR.'pdftool';
    mkdir($fake, 0700, true);
    file_put_contents($fake.DIRECTORY_SEPARATOR.'__init__.py', '');
    file_put_contents($fake.DIRECTORY_SEPARATOR.'__main__.py', "import time\ntime.sleep(60)\n");

    config()->set('pdftool.cwd', dirname($fake));
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    putenv('REVIEW2C_TSA_PASSWORD=senha-da-tsa-NAO-PODE-VAZAR-5k');
});

afterEach(function () {
    putenv('REVIEW2C_TSA_PASSWORD');
    PdfFixtures::cleanup($this->work ?? null);
});

it('a exceção de tempo limite da TSA não carrega a senha do PKCS#12 no ambiente do processo', function () {
    $caught = null;

    try {
        app(TsaToolRunner::class)->run('tsa-issue', ['--out', $this->work.DIRECTORY_SEPARATOR.'r.tsr'], ['REVIEW2C_TSA_PASSWORD'], 2);
    } catch (TsaException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(TsaException::class)
        ->and($caught->errorCode)->toBe('timeout');

    $leaks = [];

    for ($exception = $caught; $exception !== null; $exception = $exception->getPrevious()) {
        if (method_exists($exception, 'getProcess')) {
            $env = (array) $exception->getProcess()->getEnv();

            if (in_array('senha-da-tsa-NAO-PODE-VAZAR-5k', $env, true)) {
                $leaks[] = $exception::class;
            }
        }
    }

    expect($leaks)->toBe([]);
});
