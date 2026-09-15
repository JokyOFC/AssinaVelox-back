<?php

declare(strict_types=1);

use AssinaVelox\Sdk\ApiResult;
use AssinaVelox\Sdk\Client;
use AssinaVelox\Sdk\Exception\ApiException;
use AssinaVelox\Sdk\Exception\InvalidRequestException;
use AssinaVelox\Sdk\Exception\NetworkException;
use AssinaVelox\Sdk\Exception\TimeoutException;
use AssinaVelox\Sdk\FileUpload;
use AssinaVelox\Sdk\Model;
use AssinaVelox\Sdk\RequestOptions;
use AssinaVelox\Sdk\Version;

/*
 * Comportamento do cliente: autenticação, idempotência, erros RFC 9457, paginação, tempo esgotado.
 */

const SDK_NOT_FOUND = '01FAKENOTFOUND000000000000';
const SDK_ENVELOPE = '01J00000000000000000000000';

SdkTest::add('versão ligada à API v1', static function (TestContext $t): void {
    $t->same('1.0.0', Version::SDK);
    $t->same(1, Version::API_MAJOR);
    $t->matches('/^[0-9a-f]{64}$/', Version::SPEC_SHA256);
});

SdkTest::add('cabeçalhos de autenticação, user agent e corpo', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $envelope = $t->client()->createEnvelope(['title' => 'Contrato de locação — Apto 302'], $options);
    $t->instanceOf(Model\Envelope::class, $envelope);

    $record = $t->last($trace);
    $t->same([], $record['violations']);
    $t->same('Bearer tok_teste_sdk', $record['headers']['authorization']);
    $t->matches('#^assinavelox-php/1\.0\.0 \(api-v1; php/\d+\.\d+#', $record['headers']['user-agent']);
    $t->same('application/json', $record['headers']['accept']);
    $t->same('application/json', $record['headers']['content-type']);
    $t->same(['title' => 'Contrato de locação — Apto 302'], $record['json']);
});

SdkTest::add('Idempotency-Key gerada quando obrigatória e mantida quando informada', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $t->client()->createEnvelope(['title' => 'Contrato'], $options);
    $t->matches(TestContext::UUID_V4, $t->last($trace)['headers']['idempotency-key']);

    [$options, $trace] = $t->trace('pedido-42');
    $t->client()->createEnvelope(['title' => 'Contrato'], $options);
    $t->same('pedido-42', $t->last($trace)['headers']['idempotency-key']);
});

SdkTest::add('Idempotency-Key opcional só vai se informada', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $t->client()->cancelEnvelope(SDK_ENVELOPE, null, $options);
    $record = $t->last($trace);
    $t->false(isset($record['headers']['idempotency-key']));
    $t->false(isset($record['json']));

    [$options, $trace] = $t->trace('cancela-1');
    $t->client()->cancelEnvelope(SDK_ENVELOPE, ['reason' => 'Enviado por engano'], $options);
    $record = $t->last($trace);
    $t->same('cancela-1', $record['headers']['idempotency-key']);
    $t->same(['reason' => 'Enviado por engano'], $record['json']);
});

SdkTest::add('Idempotency-Key inválida nem sai', static function (TestContext $t): void {
    foreach (['com espaço', '', str_repeat('x', 256), 'acentuação', "quebra\n"] as $invalid) {
        [$options, $trace] = $t->trace($invalid);
        $t->throws(InvalidRequestException::class, static fn () => $t->client()->createEnvelope(['title' => 'Contrato'], $options));
        $t->same([], $t->records($trace));
    }
});

SdkTest::add('corpo vazio vira objeto JSON', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $t->client()->createEnvelopeFromTemplate(SDK_ENVELOPE, [], $options);
    $record = $t->last($trace);
    $t->same([], $record['violations']);
    $t->same([], $record['json']);
});

SdkTest::add('erro 404 RFC 9457', static function (TestContext $t): void {
    $error = $t->throws(ApiException::class, static fn () => $t->client()->getEnvelope(SDK_NOT_FOUND));
    $t->same(404, $error->status);
    $t->same(404, $error->getCode());
    $t->same('urn:assinavelox:problem:not-found', $error->type);
    $t->true($error->hasType('not-found'));
    $t->same('Recurso não encontrado', $error->title);
    $t->true($error->correlationId !== null && $error->correlationId !== '');
    $t->same('/api/v1/envelopes/'.SDK_NOT_FOUND, $error->instance);
    $t->true(str_contains($error->getMessage(), '404 Recurso não encontrado'));
});

SdkTest::add('erro 422 com erros por campo', static function (TestContext $t): void {
    $error = $t->throws(ApiException::class, static fn () => $t->client()->createEnvelope(['title' => 'fake-422']));
    $t->same(422, $error->status);
    $t->same('validation-failed', $error->slug());
    $t->same('Um ou mais campos não passaram na validação.', $error->detail);
    $t->same(['title' => ['O campo título deve ter pelo menos 3 caracteres.']], $error->errors);
});

SdkTest::add('erros 401, 429 (Retry-After) e resposta que não é RFC 9457', static function (TestContext $t): void {
    $error = $t->throws(ApiException::class, static fn () => $t->client('fake-401')->listTemplates());
    $t->same(401, $error->status);
    $t->same('unauthenticated', $error->slug());

    $error = $t->throws(ApiException::class, static fn () => $t->client('fake-429')->listEnvelopes());
    $t->same(429, $error->status);
    $t->same(7, $error->retryAfter());

    $error = $t->throws(ApiException::class, static fn () => $t->client('fake-502-html')->listEnvelopes());
    $t->same(502, $error->status);
    $t->same('about:blank', $error->type);
    $t->same('Erro HTTP 502', $error->title);
});

SdkTest::add('tempo esgotado (do cliente e por chamada)', static function (TestContext $t): void {
    $t->throws(TimeoutException::class, static fn () => $t->client('fake-slow', 0.4)->listEnvelopes());
    $t->throws(TimeoutException::class, static fn () => $t->client('fake-slow')->listEnvelopes([], new RequestOptions(timeout: 0.4)));
});

SdkTest::add('conexão recusada', static function (TestContext $t): void {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    $error = $t->throws(NetworkException::class, static fn () => $t->client('tok_segredo', 5.0, 'http://'.$name.'/api/v1')->listEnvelopes());
    $t->false($error instanceof TimeoutException, 'conexão recusada não é tempo esgotado');
    $t->false(str_contains($error->getMessage(), 'tok_segredo'));
});

SdkTest::add('paginação e query com lista', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $page = $t->client()->listEnvelopes(['status' => ['draft', 'completed'], 'per_page' => 10], $options);
    $record = $t->last($trace);
    $t->same([], $record['violations']);
    $t->same([['per_page', '10'], ['status[]', 'draft'], ['status[]', 'completed']], $record['query']);

    $t->same(3, iterator_count($page->autoPagingIterator()));
    $records = $t->records($trace);
    $t->same(['cursor', 'fake-cursor-2'], $records[count($records) - 1]['query'][1]);

    $t->throws(InvalidRequestException::class, static fn () => $t->client()->listEnvelopes(['statu' => 'draft']));
});

SdkTest::add('upload multipart preserva os bytes', static function (TestContext $t): void {
    $content = "%PDF-1.4\r\n\x00\xff bytes\r\n--quase-um-boundary\r\n%%EOF";
    [$options, $trace] = $t->trace();
    $document = $t->client()->uploadDocument(SDK_ENVELOPE, FileUpload::fromString($content, 'contrato "final".pdf', 'application/pdf'), $options);
    $t->instanceOf(Model\Document::class, $document);

    $record = $t->last($trace);
    $t->same([], $record['violations']);
    $t->same(hash('sha256', $content), $record['files']['file']['sha256']);
    $t->same('contrato %22final%22.pdf', $record['files']['file']['filename']);
    $t->same('application/pdf', $record['files']['file']['content_type']);
});

SdkTest::add('download', static function (TestContext $t): void {
    $file = $t->client()->downloadFile(SDK_ENVELOPE, 'original', ['document' => SDK_ENVELOPE]);
    $t->true(str_starts_with($file->content, '%PDF'));
    $t->same('application/pdf', $file->contentType);
    $t->same('AV-000123-original.pdf', $file->filename);
});

SdkTest::add('parâmetro de caminho codificado', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $t->client()->getEnvelope('a/b?c', $options);
    $record = $t->last($trace);
    $t->same('/api/v1/envelopes/a%2Fb%3Fc', $record['path']);
    $t->same(['envelope' => 'a/b?c'], $record['path_params']);
});

SdkTest::add('token não aparece em var_dump nem print_r', static function (TestContext $t): void {
    $client = $t->client('tok_super_secreto');
    ob_start();
    var_dump($client);
    print_r($client);
    $dump = (string) ob_get_clean();
    $t->false(str_contains($dump, 'tok_super_secreto'));
});

SdkTest::add('configuração inválida', static function (TestContext $t): void {
    $t->throws(InvalidRequestException::class, static fn () => new Client('ftp://exemplo', 'x'));
    $t->throws(InvalidRequestException::class, static fn () => new Client($t->url, 'com espaço'));
    $t->throws(InvalidRequestException::class, static fn () => $t->client()->listEnvelopes([], new RequestOptions(headers: ['X-Teste' => "a\r\nInjetado: 1"])));
});

SdkTest::add('exemplo do README', static function (TestContext $t): void {
    $client = new Client($t->url, '12|avk_exemplo', 30.0, [], $t->transport());

    $envelope = $client->createEnvelope(['title' => 'Contrato de locação — Apto 302']);
    $client->uploadDocument($envelope->id, FileUpload::fromString('%PDF-1.4 ...', 'contrato.pdf', 'application/pdf'));
    $client->syncRecipients($envelope->id, [
        'signing_order' => 'sequential',
        'recipients' => [['name' => 'Ana Souza', 'email' => 'ana@example.com']],
    ]);
    $sent = $client->sendEnvelope($envelope->id);
    $t->instanceOf(ApiResult::class, $sent);
    $t->true(is_int($sent->meta['invitations_sent'] ?? null));

    $codes = [];

    foreach ($client->listEnvelopes(['status' => ['in_progress']])->autoPagingIterator() as $item) {
        $codes[] = $item->displayCode;
    }

    $t->same(3, count($codes));

    try {
        $client->getEnvelope(SDK_NOT_FOUND);
        $t->true(false, 'deveria lançar');
    } catch (ApiException $error) {
        $t->true(str_starts_with($error->type, 'urn:assinavelox:problem:'));
    }
});
