<?php

use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/Support/TimestampHelpers.php';

/*
|--------------------------------------------------------------------------
| tsa:generate-test e tsa:status — sem segredo na saída; teste nunca em produção
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = ktsaWorkspace($this);
});

afterEach(function () {
    putenv(KTSA_TSA_PASS_ENV);
    ktsaCleanup($this->work ?? null);
});

it('recusa gerar TSA de teste em produção', function () {
    putenv(KTSA_TSA_PASS_ENV.'='.KTSA_TSA_PASSWORD);
    app()->detectEnvironment(fn () => 'production');

    try {
        $code = Artisan::call('tsa:generate-test', ['--dir' => $this->work.'/tsa', '--pass-env' => KTSA_TSA_PASS_ENV]);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('nunca é gerada em produção')
        ->and(is_file($this->work.'/tsa/tsa-teste.pfx'))->toBeFalse();
});

it('gera a TSA de teste, orienta o .env e nunca imprime a senha', function () {
    putenv(KTSA_TSA_PASS_ENV.'='.KTSA_TSA_PASSWORD);

    $code = Artisan::call('tsa:generate-test', ['--dir' => $this->work.'/tsa', '--pass-env' => KTSA_TSA_PASS_ENV, '--key' => 'ec-p256', '--days' => 3]);
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('TESTE')
        ->and($output)->toContain('ASSINAVELOX_TSA_PFX_PATH=')
        ->and($output)->toContain('ASSINAVELOX_TSA_PASSWORD_ENV='.KTSA_TSA_PASS_ENV)
        ->and($output)->not->toContain(KTSA_TSA_PASSWORD)
        ->and(is_file($this->work.'/tsa/tsa-teste.pfx'))->toBeTrue()
        ->and(is_file($this->work.'/tsa/ac-interna-teste.pem'))->toBeTrue();

    // Sem --force não sobrescreve.
    expect(Artisan::call('tsa:generate-test', ['--dir' => $this->work.'/tsa', '--pass-env' => KTSA_TSA_PASS_ENV]))->toBe(1);
});

it('não gera sem a senha no ambiente', function () {
    putenv(KTSA_TSA_PASS_ENV);

    expect(Artisan::call('tsa:generate-test', ['--dir' => $this->work.'/tsa', '--pass-env' => KTSA_TSA_PASS_ENV]))->toBe(1);
});

it('tsa:status mostra o checklist de produção sem nenhum segredo', function () {
    ktsaConfigureTsa($this->work);

    expect(Artisan::call('tsa:status', ['--json' => true]))->toBe(0);
    $output = Artisan::output();
    $report = json_decode($output, true);

    expect($report['configured'])->toBeTrue()
        ->and($report['password_set'])->toBeTrue()
        ->and($report['announced_pades_profile'])->toBe('PAdES-B-B')
        ->and(collect($report['production_checklist'])->pluck('status')->implode(' '))->toContain('pendente')
        ->and($output)->not->toContain(KTSA_TSA_PASSWORD);
});
