<?php

use App\Support\SmtpTransportOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/*
|--------------------------------------------------------------------------
| E-mail por SMTP do proprietário (o mesmo desenho do metta-bank)
|--------------------------------------------------------------------------
| O envio é SMTP puro: host, porta 587, STARTTLS, usuário e senha. O `.env` que vem de
| instalações antigas diz `MAIL_ENCRYPTION=tls` (ou `MAIL_SCHEME=tls`), e o Laravel 13 só aceita
| `smtp`/`smtps` — o valor cru derruba TODO envio. Estes testes prendem a tradução
| (SmtpTransportOptions), o que o doctor diz sobre um SMTP mal configurado e o comando de
| teste de envio.
*/

/**
 * @param  array<string, mixed>  $options
 */
function smtpTransport(array $options): EsmtpTransport
{
    $transport = app('mail.manager')->createSymfonyTransport($options + [
        'transport' => 'smtp',
        'host' => 'smtp.exemplo.test',
        'port' => 587,
        'username' => 'conta@exemplo.test',
        'password' => 'segredo-de-teste',
    ]);

    expect($transport)->toBeInstanceOf(EsmtpTransport::class);

    /** @var EsmtpTransport $transport */
    return $transport;
}

it('traduz o que o operador escreve para o que o transporte aceita', function (?string $scheme, ?string $legacy, mixed $requireTls, array $expected) {
    expect(SmtpTransportOptions::resolve($scheme, $legacy, $requireTls))->toBe($expected);
})->with([
    'tls = STARTTLS obrigatório' => ['tls', null, null, ['scheme' => 'smtp', 'require_tls' => true]],
    'MAIL_ENCRYPTION=tls (nome antigo)' => [null, 'tls', null, ['scheme' => 'smtp', 'require_tls' => true]],
    'starttls' => ['STARTTLS', null, null, ['scheme' => 'smtp', 'require_tls' => true]],
    'ssl = TLS desde a conexão' => ['ssl', null, null, ['scheme' => 'smtps', 'require_tls' => false]],
    'smtps' => ['smtps', null, null, ['scheme' => 'smtps', 'require_tls' => false]],
    'smtp = STARTTLS oportunista' => ['smtp', null, null, ['scheme' => 'smtp', 'require_tls' => false]],
    'vazio = o Laravel decide pela porta' => [null, null, null, ['scheme' => null, 'require_tls' => false]],
    'MAIL_SCHEME vence MAIL_ENCRYPTION' => ['ssl', 'tls', null, ['scheme' => 'smtps', 'require_tls' => false]],
    'MAIL_REQUIRE_TLS explícito liga' => ['smtp', null, 'true', ['scheme' => 'smtp', 'require_tls' => true]],
    'MAIL_REQUIRE_TLS explícito desliga' => ['tls', null, 'false', ['scheme' => 'smtp', 'require_tls' => false]],
    'MAIL_REQUIRE_TLS vazio não decide' => ['tls', null, '', ['scheme' => 'smtp', 'require_tls' => true]],
    'valor desconhecido segue como está' => ['quic', null, null, ['scheme' => 'quic', 'require_tls' => false]],
]);

it('o valor antigo "tls", passado cru, derruba o envio — é por isso que a tradução existe', function () {
    expect(fn () => smtpTransport(['scheme' => 'tls']))->toThrow(UnsupportedSchemeException::class);

    expect(SmtpTransportOptions::isSupported('tls'))->toBeFalse()
        ->and(SmtpTransportOptions::isSupported('smtp'))->toBeTrue()
        ->and(SmtpTransportOptions::isSupported('smtps'))->toBeTrue()
        ->and(SmtpTransportOptions::isSupported(null))->toBeTrue();
});

it('monta o transporte com STARTTLS obrigatório quando o operador pede tls', function () {
    $transport = smtpTransport(SmtpTransportOptions::resolve('tls'));

    expect($transport->isTlsRequired())->toBeTrue();

    expect(smtpTransport(SmtpTransportOptions::resolve('smtp'))->isTlsRequired())->toBeFalse();
});

it('o mailer smtp da aplicação traz relógio próprio e a opção de TLS obrigatório', function () {
    expect(config('mail.mailers.smtp'))->toHaveKeys(['scheme', 'require_tls', 'timeout'])
        ->and(config('mail.mailers.smtp.timeout'))->toBeInt()->toBeGreaterThan(0);
});

describe('assinavelox:doctor com SMTP', function () {
    beforeEach(function () {
        $this->work = storage_path('framework/testing/smtp-doctor-'.uniqid());
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

        // O assunto aqui é o e-mail: o pdftool aponta para o nada e cai no ramo "indisponível".
        config()->set('pdftool.python', $this->work.'/python-inexistente');

        config()->set('assinavelox.email.provider', 'laravel');
        config()->set('assinavelox.email.mailer', null);
        config()->set('mail.default', 'smtp');
        config()->set('mail.from.address', 'nao-responda@exemplo.test');
    });

    afterEach(function () {
        File::deleteDirectory($this->work ?? '');
    });

    /**
     * @return array<string, array{status: string, message: string}>
     */
    function smtpDoctorChecks(): array
    {
        Artisan::call('assinavelox:doctor', ['--json' => true]);

        /** @var array{checks: list<array{group: string, name: string, status: string, message: string}>} $report */
        $report = json_decode(Artisan::output(), true);

        return collect($report['checks'])
            ->where('group', 'E-mail')
            ->mapWithKeys(fn (array $check): array => [$check['name'] => ['status' => $check['status'], 'message' => $check['message']]])
            ->all();
    }

    it('aprova um SMTP completo, com STARTTLS obrigatório, sem mostrar usuário nem senha', function () {
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'scheme' => 'smtp',
            'host' => 'smtp.provedor.test',
            'port' => 587,
            'username' => 'conta-secreta@provedor.test',
            'password' => 'senha-que-nao-pode-vazar',
            'require_tls' => true,
            'timeout' => 30,
        ]);

        $checks = smtpDoctorChecks();

        expect($checks['Transporte']['status'])->toBe('ok')
            ->and($checks['Servidor SMTP'])->toBe(['status' => 'ok', 'message' => 'smtp.provedor.test:587'])
            ->and($checks['Credenciais SMTP']['status'])->toBe('ok')
            ->and($checks['Criptografia SMTP'])->toBe(['status' => 'ok', 'message' => 'STARTTLS obrigatório.']);

        Artisan::call('assinavelox:doctor');

        expect(Artisan::output())->not->toContain('senha-que-nao-pode-vazar')
            ->not->toContain('conta-secreta@provedor.test');
    });

    it('avisa quando o servidor ainda é o padrão, falta credencial e o TLS é só oportunista', function () {
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'scheme' => null,
            'host' => '127.0.0.1',
            'port' => 2525,
            'username' => null,
            'password' => null,
            'require_tls' => false,
        ]);

        $checks = smtpDoctorChecks();

        expect($checks['Servidor SMTP']['status'])->toBe('aviso')
            ->and($checks['Servidor SMTP']['message'])->toContain('MAIL_HOST não foi definido')
            ->and($checks['Credenciais SMTP']['status'])->toBe('aviso')
            ->and($checks['Credenciais SMTP']['message'])->toContain('MAIL_USERNAME')->toContain('MAIL_PASSWORD')
            ->and($checks['Criptografia SMTP']['status'])->toBe('aviso')
            ->and($checks['Criptografia SMTP']['message'])->toContain('MAIL_SCHEME=tls');
    });

    it('reprova um esquema que o transporte não conhece', function () {
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'scheme' => 'quic',
            'host' => 'smtp.provedor.test',
            'port' => 587,
            'username' => 'conta@provedor.test',
            'password' => 'x',
        ]);

        $checks = smtpDoctorChecks();

        expect($checks['Criptografia SMTP']['status'])->toBe('falha')
            ->and($checks['Criptografia SMTP']['message'])->toContain('todo envio falha');
    });

    it('não confere SMTP quando o mailer não transmite nada', function () {
        config()->set('mail.default', 'log');

        expect(smtpDoctorChecks())->not->toHaveKey('Servidor SMTP');
    });
});

describe('assinavelox:mail-test', function () {
    it('envia pelo provedor configurado e sai com 0 quando o recibo é "sent"', function () {
        config()->set('mail.default', 'array');

        $exit = Artisan::call('assinavelox:mail-test', ['to' => 'destino@exemplo.test', '--json' => true]);

        /** @var array<string, mixed> $report */
        $report = json_decode(Artisan::output(), true);

        expect($exit)->toBe(0)
            ->and($report['ok'])->toBeTrue()
            ->and($report['status'])->toBe('sent')
            ->and($report['mailer'])->toBe('array');

        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();

        expect($messages)->toHaveCount(1)
            ->and($messages->first()->getOriginalMessage()->getSubject())->toContain('Teste de envio');
    });

    it('sai com 1 e diz que nada foi transmitido quando o mailer só registra', function () {
        config()->set('mail.default', 'log');

        $exit = Artisan::call('assinavelox:mail-test', ['to' => 'destino@exemplo.test', '--json' => true]);

        /** @var array<string, mixed> $report */
        $report = json_decode(Artisan::output(), true);

        expect($exit)->toBe(1)
            ->and($report['status'])->toBe('unknown')
            ->and($report['error'])->toContain('nada foi transmitido');
    });

    it('recusa um destinatário que não é e-mail, sem enviar nada', function () {
        config()->set('mail.default', 'array');

        expect(Artisan::call('assinavelox:mail-test', ['to' => 'nao-e-email']))->toBe(2);

        expect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    });
});
