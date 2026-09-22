<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Fase 4 §4.1 — `assinavelox:doctor`, grupo "Verificação facial"
|--------------------------------------------------------------------------
| Nenhum segredo da Verifiky é impresso; o que não está configurado é dito com o NOME da
| variável; desligado não é quebrado; simulador em produção é falha.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/doctor-verifiky-'.uniqid());
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

    // Sem gastar processo Python: o assunto aqui é o grupo da verificação facial.
    config()->set('pdftool.python', $this->work.'/python-inexistente');
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

/**
 * @return array<string, array<string, mixed>> checks do grupo, por nome
 */
function doctorVerificationChecks(): array
{
    Artisan::call('assinavelox:doctor', ['--json' => true]);

    /** @var array<string, mixed> $report */
    $report = json_decode(Artisan::output(), true) ?? [];

    return collect($report['checks'] ?? [])
        ->where('group', 'Verificação facial')
        ->keyBy('name')
        ->all();
}

it('com a flag desligada diz "desligada" numa linha só, sem chamar isso de defeito', function () {
    $checks = doctorVerificationChecks();

    expect($checks)->toHaveCount(1)
        ->and($checks['Estado']['status'])->toBe('ok')
        ->and($checks['Estado']['message'])->toContain('Desligada');
});

it('driver disabled com a flag ligada é aviso; fake é aviso fora de produção e falha em produção; driver desconhecido é falha', function () {
    config()->set('assinavelox.features.identity_verification', true);

    config()->set('assinavelox.identity_verification.driver', 'disabled');
    $estado = doctorVerificationChecks()['Estado'];
    expect($estado['status'])->toBe('aviso')->and($estado['message'])->toContain('inconclusivo — não configurado');

    config()->set('assinavelox.identity_verification.driver', 'fake');
    config()->set('assinavelox.channels.allow_simulated', true);
    $estado = doctorVerificationChecks()['Estado'];
    expect($estado['status'])->toBe('aviso')->and($estado['message'])->toContain('(simulado)');

    config()->set('assinavelox.identity_verification.driver', 'nenhum');
    expect(doctorVerificationChecks()['Estado']['status'])->toBe('falha');

    app()->detectEnvironment(fn (): string => 'production');
    config()->set('assinavelox.identity_verification.driver', 'fake');
    $estado = doctorVerificationChecks()['Estado'];
    expect($estado['status'])->toBe('falha')->and($estado['message'])->toContain('produção');
    app()->detectEnvironment(fn (): string => 'testing');
});

it('com a Verifiky descreve chave, segredos e TLS pelo NOME das variáveis, sem imprimir valor nenhum', function () {
    config()->set('assinavelox.features.identity_verification', true);
    config()->set('assinavelox.identity_verification.driver', 'verifiky');
    config()->set('assinavelox.identity_verification.verifiky', [
        'api_url' => 'https://app.verifiky.com',
        'api_key' => 'vk_chave_secretissima_nao_imprimir',
        'webhook_secret' => 'segredo-webhook-nao-imprimir',
        'hmac_secret' => 'segredo-hmac-nao-imprimir',
        'timeout' => 180,
        'verify_ssl' => true,
    ]);

    Artisan::call('assinavelox:doctor');
    $output = Artisan::output();

    expect($output)
        ->not->toContain('vk_chave_secretissima_nao_imprimir')
        ->not->toContain('segredo-webhook-nao-imprimir')
        ->not->toContain('segredo-hmac-nao-imprimir')
        ->toContain('VERIFIKY_API_KEY definida')
        ->toContain('VERIFIKY_WEBHOOK_SECRET definido')
        ->toContain('VERIFIKY_HMAC_SECRET definido');

    $checks = doctorVerificationChecks();

    expect($checks['Credenciais']['status'])->toBe('ok')
        ->and($checks['Segredo do webhook']['status'])->toBe('ok')
        ->and($checks['Segredo das consultas']['status'])->toBe('ok')
        ->and($checks['TLS do provedor']['status'])->toBe('ok')
        ->and(json_encode($checks))->not->toContain('nao_imprimir');

    // Sem chave: falha. Sem segredo do webhook: aviso que explica o caminho alternativo.
    config()->set('assinavelox.identity_verification.verifiky.api_key', '');
    config()->set('assinavelox.identity_verification.verifiky.webhook_secret', '');
    config()->set('assinavelox.identity_verification.verifiky.hmac_secret', '');
    config()->set('assinavelox.identity_verification.verifiky.verify_ssl', false);

    $checks = doctorVerificationChecks();

    expect($checks['Credenciais']['status'])->toBe('falha')
        ->and($checks['Credenciais']['message'])->toContain('VERIFIKY_API_KEY ausente')
        ->and($checks['Segredo do webhook']['status'])->toBe('aviso')
        ->and($checks['Segredo do webhook']['message'])->toContain('sem ele o resultado só chega pela resposta do envio ou por consulta')
        ->and($checks['Segredo das consultas']['status'])->toBe('aviso')
        ->and($checks['TLS do provedor']['status'])->toBe('aviso');

    // `verify_ssl=false` em produção é falha.
    app()->detectEnvironment(fn (): string => 'production');
    expect(doctorVerificationChecks()['TLS do provedor']['status'])->toBe('falha');
    app()->detectEnvironment(fn (): string => 'testing');
});
