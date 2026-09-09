<?php

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| H-SEC §4/§5/§6 — comandos operacionais
|--------------------------------------------------------------------------
| Três exigências, nesta ordem de importância:
|
|  1. nenhum comando imprime segredo — nem por acidente, nem em `--json`;
|  2. o que não pôde ser verificado é relatado como NÃO VERIFICADO, nunca como ok;
|  3. o comando falha (código 1) quando a instalação está de fato quebrada.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/hardening-cmd-'.uniqid());
    File::ensureDirectoryExists($this->work);

    config()->set('filesystems.disks.documents', [
        'driver' => 'local',
        'root' => $this->work,
        'visibility' => 'private',
        'serve' => false,
        'throw' => true,
        'report' => false,
    ]);
    Storage::forgetDisk('documents');

    /*
     * O selftest real do pdftool leva segundos e é coberto pelo próprio
     * `pdftool:selftest`. Aqui o assunto é o doctor, então o interpretador aponta para um
     * caminho inexistente: o comando cai no ramo "indisponível" sem gastar processo
     * Python. Um teste abaixo faz questão de rodar o selftest de verdade.
     */
    config()->set('pdftool.python', $this->work.'/python-inexistente');
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

/**
 * @return array<string, mixed>
 */
function runJsonCommand(string $command, array $parameters = []): array
{
    Artisan::call($command, $parameters + ['--json' => true]);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(Artisan::output(), true) ?? [];

    return $decoded;
}

it('o doctor descreve a instalação sem imprimir nenhum valor de segredo', function () {
    // Segredos plantados na configuração: nenhum deles pode aparecer na saída.
    config([
        'assinavelox.mercadopago.access_token' => 'APP_USR-token-secretissimo-do-mercado-pago',
        'assinavelox.mercadopago.webhook_secret' => 'segredo-do-webhook-nao-imprimir',
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
    ]);

    Artisan::call('assinavelox:doctor');
    $output = Artisan::output();

    expect($output)
        ->not->toContain('APP_USR-token-secretissimo-do-mercado-pago')
        ->not->toContain('segredo-do-webhook-nao-imprimir')
        ->not->toContain((string) config('app.key'))
        // …e ainda assim responde a pergunta útil.
        ->toContain('MERCADOPAGO_ACCESS_TOKEN definido')
        ->toContain('Nenhum valor de segredo é impresso');

    $report = runJsonCommand('assinavelox:doctor');

    expect(json_encode($report))
        ->not->toContain('APP_USR-token-secretissimo-do-mercado-pago')
        ->not->toContain('segredo-do-webhook-nao-imprimir');
});

it('o doctor falha quando algo essencial está faltando', function () {
    config(['app.key' => '', 'mail.from.address' => '']);

    $report = runJsonCommand('assinavelox:doctor');

    $failures = collect($report['checks'])->where('status', 'falha')->pluck('name')->all();

    expect($report['ok'])->toBeFalse()
        ->and($failures)->toContain('APP_KEY')
        ->and($failures)->toContain('Remetente');
});

it('o doctor não confunde "desligado" com "quebrado" quando não há certificado A1', function () {
    config(['pdftool.company_certificate.enabled' => false]);

    $report = runJsonCommand('assinavelox:doctor');

    $certificate = collect($report['checks'])->firstWhere('name', 'Estado');

    // Sem certificado o envelope conclui como aceite eletrônico com evidências — é o
    // comportamento documentado, não um defeito. Marcá-lo como falha ensinaria a ignorar
    // falhas.
    expect($certificate['status'])->toBe('aviso')
        ->and($certificate['message'])->toContain('ACEITE ELETRÔNICO COM EVIDÊNCIAS');
});

it('o doctor reprova quando o pdftool não responde e aprova quando ele responde', function () {
    // Interpretador inexistente (posto no beforeEach): sem pdftool não há inspeção,
    // composição nem assinatura — é falha, não aviso.
    $indisponivel = collect(runJsonCommand('assinavelox:doctor')['checks'])->firstWhere('name', 'pdftool');

    expect($indisponivel['status'])->toBe('falha')
        ->and($indisponivel['message'])->toContain('indisponível');

    // Agora com o venv real do projeto: o selftest ponta a ponta precisa passar.
    config()->set('pdftool.python', base_path(PHP_OS_FAMILY === 'Windows'
        ? 'tools/pdftool/.venv/Scripts/python.exe'
        : 'tools/pdftool/.venv/bin/python'));

    $selftest = collect(runJsonCommand('assinavelox:doctor')['checks'])->firstWhere('name', 'pdftool selftest');

    expect($selftest['status'])->toBe('ok');
})->skip(
    fn (): bool => ! is_file(base_path(PHP_OS_FAMILY === 'Windows'
        ? 'tools/pdftool/.venv/Scripts/python.exe'
        : 'tools/pdftool/.venv/bin/python')),
    'venv do pdftool não instalado neste ambiente.',
);

it('o doctor avisa quando a criptografia do armazenamento nunca foi verificada', function () {
    Storage::disk('local')->delete((string) config('assinavelox.storage_encryption.receipt_path'));

    $report = runJsonCommand('assinavelox:doctor');

    $check = collect($report['checks'])->firstWhere('name', 'Criptografia em repouso');

    expect($check['status'])->toBe('aviso')
        ->and($check['message'])->toContain('Flysystem NÃO cifra');
});

it('o storage:verify grava, lê de volta e apaga o objeto de prova', function () {
    $report = runJsonCommand('storage:verify');

    expect($report['ok'])->toBeTrue()
        ->and($report['writable']['ok'])->toBeTrue()
        ->and($report['writable']['probe_deleted'])->toBeTrue()
        ->and($report['private']['ok'])->toBeTrue();

    // O objeto de prova não ficou para trás.
    expect(Storage::disk('documents')->files('hardening'))->toBe([]);
});

it('o storage:verify nunca afirma criptografia em disco local', function () {
    $report = runJsonCommand('storage:verify');

    expect($report['encryption']['method'])->toBe('local')
        // `verified` é sempre falso em disco local: a cifra é do volume e o PHP enxerga o
        // sistema de arquivos já aberto. Dizer "ok" aqui seria inventar uma garantia.
        ->and($report['encryption']['verified'])->toBeFalse()
        ->and($report['encryption']['notice'])->toContain('LUKS');
});

it('o storage:verify reprova um disco de documentos que não é privado', function () {
    config()->set('filesystems.disks.documents.visibility', 'public');
    config()->set('filesystems.disks.documents.serve', true);
    Storage::forgetDisk('documents');

    $report = runJsonCommand('storage:verify');

    expect($report['ok'])->toBeFalse()
        ->and(implode(' ', $report['problems']))->toContain('private')
        ->and(implode(' ', $report['problems']))->toContain('serve');
});

it('o storage:verify deixa um recibo que o doctor lê depois', function () {
    runJsonCommand('storage:verify');

    $path = (string) config('assinavelox.storage_encryption.receipt_path');

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $report = runJsonCommand('assinavelox:doctor');
    $check = collect($report['checks'])->firstWhere('name', 'Criptografia em repouso');

    expect($check['message'])->not->toContain('Nunca verificada');

    Storage::disk('local')->delete($path);
});

it('o health responde ok numa base limpa e degradado quando algo trava', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    expect(runJsonCommand('assinavelox:health')['ok'])->toBeTrue();

    // Um envelope preso em `finalizing` há mais tempo do que o limiar.
    Envelope::factory()->forOrganization($organization, $owner)->create([
        'status' => EnvelopeStatus::Finalizing,
    ]);

    DB::table('envelopes')->update(['updated_at' => now()->subHours(3)]);

    $report = runJsonCommand('assinavelox:health');

    expect($report['ok'])->toBeFalse()
        ->and($report['degraded'])->toContain('signature')
        ->and($report['indicators']['signature']['message'])->toContain('finalizing');
});

it('o health devolve `desconhecido`, e não `ok`, quando o indicador não pode ser lido', function () {
    // Fila em Redis: as contagens não são legíveis por SQL. Responder "ok" aqui seria um
    // painel verde por ignorância.
    config(['queue.default' => 'redis']);

    $report = runJsonCommand('assinavelox:health');

    expect($report['indicators']['queue']['status'])->toBe('desconhecido')
        ->and($report['indicators']['queue']['message'])->toContain('Horizon');
});

it('os comandos operacionais estão agendados', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event) => (string) $event->command)
        ->implode(' ');

    expect($commands)->toContain('assinavelox:health')
        ->and($commands)->toContain('audit:checkpoint');
});
