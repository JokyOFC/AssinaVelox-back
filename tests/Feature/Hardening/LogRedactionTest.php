<?php

use App\Logging\RedactionTap;
use App\Logging\RedactSensitiveData;
use App\Support\Correlation;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;

/*
|--------------------------------------------------------------------------
| H-SEC §6 — mascaramento obrigatório nos registros
|--------------------------------------------------------------------------
| O processador é a última transformação antes de a linha ser escrita (RedactionTap
| pendura-o no HANDLER, não no logger). O que se cobra aqui é que ele redija por PADRÃO —
| sem que quem chama `Log::info()` precise lembrar de nada — e que continue deixando
| passar o que serve para diagnosticar.
*/

function makeRecord(string $message, array $context = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Info,
        message: $message,
        context: $context,
    );
}

it('redige token de convite, código, senha, e-mail e CPF/CNPJ na mensagem', function () {
    $redactor = new RedactSensitiveData;

    $record = ($redactor)(makeRecord(
        'falha ao abrir https://app.assinavelox.com.br/assinar/8Xk2vQpLm4RsTuVwXyZ0123456789abcdEFGH '
        .'para maria.silva@cliente.com.br (CPF 123.456.789-09, CNPJ 12.345.678/0001-90) '
        .'com password=SuperSecreta123 e token=abcDEF123456ghiJKL',
    ));

    expect($record->message)
        ->not->toContain('8Xk2vQpLm4RsTuVwXyZ0123456789abcdEFGH')
        ->not->toContain('maria.silva@cliente.com.br')
        ->not->toContain('123.456.789-09')
        ->not->toContain('12.345.678/0001-90')
        ->not->toContain('SuperSecreta123')
        ->not->toContain('abcDEF123456ghiJKL')
        // O que sobra ainda permite entender o que aconteceu.
        ->toContain('falha ao abrir')
        ->toContain('/assinar/')
        ->toContain('m***@***.br');
});

it('redige chaves sensíveis do contexto em qualquer profundidade', function () {
    $redactor = new RedactSensitiveData;

    $record = ($redactor)(makeRecord('evento', [
        'envelope_ulid' => '01JB5Q7Z0000000000000000AB',
        'password' => 'senha-do-usuario',
        'headers' => [
            'x-signature' => 'ts=123,v1=deadbeef',
            'authorization' => 'Bearer AbCdEf0123456789XyZ',
            'x-request-id' => 'req-42',
        ],
        'challenge' => ['code' => '123456', 'attempts' => 2],
        'page_count' => 12,
    ]));

    expect($record->context['password'])->toBe('[REDIGIDO]')
        ->and($record->context['headers']['x-signature'])->toBe('[REDIGIDO]')
        ->and($record->context['headers']['authorization'])->toBe('[REDIGIDO]')
        ->and($record->context['challenge']['code'])->toBe('[REDIGIDO]')
        // Nada disto é segredo e some do log seria perda pura.
        ->and($record->context['envelope_ulid'])->toBe('01JB5Q7Z0000000000000000AB')
        ->and($record->context['challenge']['attempts'])->toBe(2)
        ->and($record->context['page_count'])->toBe(12)
        ->and($record->context['headers']['x-request-id'])->toBe('req-42');
});

it('não estraga o log: nome de classe, caminho e identificador de correlação passam inteiros', function () {
    $redactor = new RedactSensitiveData;

    $record = ($redactor)(makeRecord(
        'App\\Services\\Envelopes\\Finalization\\EnvelopeFinalizer falhou em '
        .'/var/www/assinavelox/storage/app/documents/orgs/1/envelopes/9/final.pdf',
        ['correlation_id' => '01JB5Q7Z0000000000000000AB'],
    ));

    expect($record->message)
        ->toContain('EnvelopeFinalizer')
        ->toContain('final.pdf')
        ->and($record->context['correlation_id'])->toBe('01JB5Q7Z0000000000000000AB');
});

it('preserva os códigos de diagnóstico que a regra de sufixo pegaria por engano', function () {
    // A regra de chaves casa por sufixo — `_code` alcança `exit_code`. Isso é o
    // certo para `otp_code` e o oposto do certo para um código de saída: sem a
    // exceção, o log estruturado escondia justamente o campo que diz por que a
    // conversão falhou. Ver `assinavelox.observability.redaction.allow_keys`.
    $redactor = new RedactSensitiveData;

    $record = ($redactor)(makeRecord('pdftool: comando executado', [
        'command' => 'convert',
        'exit_code' => 3,
        'failure_code' => 'source_unreadable',
        'error_code' => 'E_PDFTOOL_TIMEOUT',
        'reason_code' => 'expired',
        'status_code' => 502,
        // Os de verdade continuam saindo redigidos.
        'otp_code' => '488218',
        'code' => '488218',
    ]));

    expect($record->context)
        ->toMatchArray([
            'exit_code' => 3,
            'failure_code' => 'source_unreadable',
            'error_code' => 'E_PDFTOOL_TIMEOUT',
            'reason_code' => 'expired',
            'status_code' => 502,
        ])
        ->and($record->context['otp_code'])->toBe('[REDIGIDO]')
        ->and($record->context['code'])->toBe('[REDIGIDO]');
});

it('redige o digest SHA-256 apenas quando ele é o valor de uma chave sensível', function () {
    $redactor = new RedactSensitiveData;
    $digest = hash('sha256', 'documento');

    $record = ($redactor)(makeRecord('conferido', [
        'token_digest' => $digest,
        'document_sha256' => $digest,
    ]));

    // O digest do token é material de autenticação; o do documento é a própria evidência
    // e precisa continuar legível no log.
    expect($record->context['token_digest'])->toBe('[REDIGIDO]')
        ->and($record->context['document_sha256'])->toBe($digest);
});

it('pode ser desligado por configuração, e o padrão é ligado', function () {
    expect(config('assinavelox.observability.redaction.enabled'))->toBeTrue();

    config(['assinavelox.observability.redaction.enabled' => false]);

    $record = (new RedactSensitiveData)(makeRecord('senha=abc123XYZ'));

    expect($record->message)->toBe('senha=abc123XYZ');
});

it('está instalado nos canais de arquivo do config/logging.php', function () {
    foreach (['single', 'daily', 'monthly', 'stderr', 'syslog', 'errorlog', 'structured'] as $channel) {
        expect(config("logging.channels.{$channel}.tap"))
            ->toContain(RedactionTap::class);
    }
});

it('grava linha estruturada com o identificador de correlação e sem o segredo', function () {
    $path = storage_path('logs/hardening-'.uniqid().'.jsonl');

    config([
        'logging.channels.hardening' => [
            'tap' => [RedactionTap::class],
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => $path],
            'formatter' => JsonFormatter::class,
            'formatter_with' => ['batchMode' => JsonFormatter::BATCH_MODE_NEWLINES],
        ],
    ]);

    $correlation = Correlation::start();

    Log::channel('hardening')->info('convite enviado', [
        'url' => 'https://app.assinavelox.com.br/assinar/8Xk2vQpLm4RsTuVwXyZ0123456789abcdEFGH',
        'to' => 'maria@cliente.com.br',
    ]);

    $line = trim((string) file_get_contents($path));
    @unlink($path);

    $decoded = json_decode($line, true);

    expect($line)
        ->not->toContain('8Xk2vQpLm4RsTuVwXyZ0123456789abcdEFGH')
        ->not->toContain('maria@cliente.com.br')
        ->and($decoded['message'])->toBe('convite enviado')
        // O Context do Laravel entra em `extra` e o redator, pendurado no handler, roda
        // DEPOIS dele — por isso o identificador está aqui e continua inteiro.
        ->and($decoded['extra'][Correlation::KEY] ?? null)->toBe($correlation);
});
