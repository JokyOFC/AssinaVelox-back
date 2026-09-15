import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createServer } from 'node:net';
import { test } from 'node:test';
import { inspect } from 'node:util';

import {
    API_MAJOR,
    ApiError,
    AssinaVelox,
    InvalidRequestError,
    NetworkError,
    Page,
    RequestTimeoutError,
    SDK_VERSION,
    SPEC_SHA256,
} from '../dist/index.js';
import {
    fakeUrl,
    fetchTrace,
    lastRecord,
    newTrace,
    UUID_V4,
} from './support.mjs';

const NOT_FOUND = '01FAKENOTFOUND000000000000';
const ENVELOPE = '01J00000000000000000000000';
const client = (token = 'tok_teste_sdk', extra = {}) =>
    new AssinaVelox({ baseUrl: fakeUrl(), token, ...extra });

void test('versão ligada à API v1', () => {
    assert.equal(SDK_VERSION, '1.0.0');
    assert.equal(API_MAJOR, 1);
    assert.match(SPEC_SHA256, /^[0-9a-f]{64}$/);
});

void test('cabeçalhos de autenticação, user agent e corpo', async () => {
    const [options, trace] = newTrace();
    const envelope = await client().createEnvelope(
        { title: 'Contrato de locação — Apto 302' },
        options,
    );
    assert.equal(typeof envelope.id, 'string');
    const record = await lastRecord(trace);
    assert.deepEqual(record.violations, []);
    assert.equal(record.headers.authorization, 'Bearer tok_teste_sdk');
    assert.match(
        record.headers['user-agent'],
        /^assinavelox-node\/1\.0\.0 \(api-v1; node\/v\d+/,
    );
    assert.equal(record.headers.accept, 'application/json');
    assert.equal(record.headers['content-type'], 'application/json');
    assert.deepEqual(record.json, { title: 'Contrato de locação — Apto 302' });
});

void test('Idempotency-Key gerada quando obrigatória e mantida quando informada', async () => {
    let [options, trace] = newTrace();
    await client().createEnvelope({ title: 'Contrato' }, options);
    assert.match((await lastRecord(trace)).headers['idempotency-key'], UUID_V4);

    [options, trace] = newTrace({ idempotencyKey: 'pedido-42' });
    await client().createEnvelope({ title: 'Contrato' }, options);
    assert.equal(
        (await lastRecord(trace)).headers['idempotency-key'],
        'pedido-42',
    );
});

void test('Idempotency-Key opcional só vai se informada', async () => {
    let [options, trace] = newTrace();
    await client().cancelEnvelope(ENVELOPE, undefined, options);
    let record = await lastRecord(trace);
    assert.equal(record.headers['idempotency-key'], undefined);
    assert.equal(record.json, undefined);

    [options, trace] = newTrace({ idempotencyKey: 'cancela-1' });
    await client().cancelEnvelope(
        ENVELOPE,
        { reason: 'Enviado por engano' },
        options,
    );
    record = await lastRecord(trace);
    assert.equal(record.headers['idempotency-key'], 'cancela-1');
    assert.deepEqual(record.json, { reason: 'Enviado por engano' });
});

void test('Idempotency-Key inválida nem sai', async () => {
    for (const invalid of ['com espaço', '', 'x'.repeat(256), 'acentuação']) {
        const [options, trace] = newTrace({ idempotencyKey: invalid });
        await assert.rejects(
            client().createEnvelope({ title: 'Contrato' }, options),
            InvalidRequestError,
        );
        assert.deepEqual(await fetchTrace(trace), []);
    }
});

void test('erro 404 RFC 9457', async () => {
    const error = await client()
        .getEnvelope(NOT_FOUND)
        .catch((caught) => caught);
    assert.ok(error instanceof ApiError);
    assert.equal(error.status, 404);
    assert.equal(error.type, 'urn:assinavelox:problem:not-found');
    assert.ok(error.hasType('not-found'));
    assert.equal(error.title, 'Recurso não encontrado');
    assert.ok(error.correlationId);
    assert.equal(error.instance, `/api/v1/envelopes/${NOT_FOUND}`);
    assert.match(error.message, /^404 Recurso não encontrado/);
});

void test('erro 422 com erros por campo', async () => {
    const error = await client()
        .createEnvelope({ title: 'fake-422' })
        .catch((caught) => caught);
    assert.ok(error instanceof ApiError);
    assert.equal(error.status, 422);
    assert.equal(error.slug, 'validation-failed');
    assert.equal(error.detail, 'Um ou mais campos não passaram na validação.');
    assert.deepEqual(error.errors, {
        title: ['O campo título deve ter pelo menos 3 caracteres.'],
    });
});

void test('erros 401, 429 (Retry-After) e resposta que não é RFC 9457', async () => {
    let error = await client('fake-401')
        .listTemplates()
        .catch((caught) => caught);
    assert.equal(error.status, 401);
    assert.equal(error.slug, 'unauthenticated');

    error = await client('fake-429')
        .listEnvelopes()
        .catch((caught) => caught);
    assert.equal(error.status, 429);
    assert.equal(error.retryAfter, 7);

    error = await client('fake-502-html')
        .listEnvelopes()
        .catch((caught) => caught);
    assert.equal(error.status, 502);
    assert.equal(error.type, 'about:blank');
    assert.equal(error.title, 'Erro HTTP 502');
});

void test('tempo esgotado (do cliente e por chamada) e cancelamento', async () => {
    await assert.rejects(
        client('fake-slow', { timeoutMs: 300 }).listEnvelopes(),
        RequestTimeoutError,
    );
    await assert.rejects(
        client('fake-slow').listEnvelopes({}, { timeoutMs: 300 }),
        RequestTimeoutError,
    );
    const controller = new AbortController();
    const pending = client('fake-slow').listEnvelopes(
        {},
        { signal: controller.signal },
    );
    controller.abort();
    await assert.rejects(
        pending,
        (error) => !(error instanceof RequestTimeoutError),
    );
});

void test('conexão recusada', async () => {
    const port = await new Promise((resolve) => {
        const server = createServer();
        server.listen(0, '127.0.0.1', () => {
            const { port: free } = server.address();
            server.close(() => resolve(free));
        });
    });
    const error = await new AssinaVelox({
        baseUrl: `http://127.0.0.1:${port}/api/v1`,
        token: 'tok_segredo',
        timeoutMs: 5000,
    })
        .listEnvelopes()
        .catch((caught) => caught);
    assert.ok(error instanceof NetworkError);
    assert.ok(!(error instanceof RequestTimeoutError));
    assert.ok(!error.message.includes('tok_segredo'));
});

void test('paginação e query com lista', async () => {
    const [options, trace] = newTrace();
    const page = await client().listEnvelopes(
        { status: ['draft', 'completed'], per_page: 10 },
        options,
    );
    assert.ok(page instanceof Page);
    const record = await lastRecord(trace);
    assert.deepEqual(record.violations, []);
    assert.deepEqual(record.query, [
        ['per_page', '10'],
        ['status[]', 'draft'],
        ['status[]', 'completed'],
    ]);
    const items = [];
    for await (const item of page) {
        items.push(item);
    }
    assert.equal(items.length, 3);
    const records = await fetchTrace(trace);
    assert.deepEqual(records.at(-1).query[1], ['cursor', 'fake-cursor-2']);

    await assert.rejects(
        client().listEnvelopes({ statu: 'draft' }),
        InvalidRequestError,
    );
});

void test('upload multipart preserva os bytes', async () => {
    const content = Buffer.concat([
        Buffer.from('%PDF-1.4\r\n'),
        Buffer.from([0, 255]),
        Buffer.from(' bytes\r\n--quase-um-boundary\r\n%%EOF'),
    ]);
    const [options, trace] = newTrace();
    const document = await client().uploadDocument(
        ENVELOPE,
        {
            content: new Uint8Array(content),
            filename: 'contrato "final".pdf',
            contentType: 'application/pdf',
        },
        options,
    );
    assert.equal(document.object, 'document');
    const record = await lastRecord(trace);
    assert.deepEqual(record.violations, []);
    assert.equal(
        record.files.file.sha256,
        createHash('sha256').update(content).digest('hex'),
    );
    assert.equal(record.files.file.filename, 'contrato %22final%22.pdf');
    assert.equal(record.files.file.content_type, 'application/pdf');
});

void test('download', async () => {
    const file = await client().downloadFile(ENVELOPE, 'original', {
        document: ENVELOPE,
    });
    assert.equal(Buffer.from(file.content.subarray(0, 4)).toString(), '%PDF');
    assert.equal(file.contentType, 'application/pdf');
    assert.equal(file.filename, 'AV-000123-original.pdf');
});

void test('parâmetro de caminho codificado', async () => {
    const [options, trace] = newTrace();
    await client().getEnvelope('a/b?c', options);
    const record = await lastRecord(trace);
    assert.equal(record.path, '/api/v1/envelopes/a%2Fb%3Fc');
    assert.deepEqual(record.path_params, { envelope: 'a/b?c' });
});

void test('token não aparece em inspect', () => {
    const dump = inspect(client('tok_super_secreto'), {
        showHidden: true,
        depth: 10,
    });
    assert.ok(!dump.includes('tok_super_secreto'));
});

void test('configuração inválida', async () => {
    assert.throws(
        () => new AssinaVelox({ baseUrl: 'ftp://exemplo', token: 'x' }),
        InvalidRequestError,
    );
    assert.throws(
        () => new AssinaVelox({ baseUrl: fakeUrl(), token: 'com espaço' }),
        InvalidRequestError,
    );
    await assert.rejects(
        client().listEnvelopes(
            {},
            { headers: { 'X-Teste': 'a\r\nInjetado: 1' } },
        ),
        InvalidRequestError,
    );
});

void test('exemplo do README', async () => {
    const av = new AssinaVelox({ baseUrl: fakeUrl(), token: '12|avk_exemplo' });

    const envelope = await av.createEnvelope({
        title: 'Contrato de locação — Apto 302',
    });
    await av.uploadDocument(envelope.id, {
        content: '%PDF-1.4 ...',
        filename: 'contrato.pdf',
        contentType: 'application/pdf',
    });
    await av.syncRecipients(envelope.id, {
        signing_order: 'sequential',
        recipients: [{ name: 'Ana Souza', email: 'ana@example.com' }],
    });
    const sent = await av.sendEnvelope(envelope.id);
    assert.equal(typeof sent.meta.invitations_sent, 'number');

    const codes = [];
    for await (const item of await av.listEnvelopes({
        status: ['in_progress'],
    })) {
        codes.push(item.display_code);
    }
    assert.equal(codes.length, 3);

    try {
        await av.getEnvelope(NOT_FOUND);
        assert.fail('deveria lançar');
    } catch (error) {
        assert.ok(error instanceof ApiError);
        assert.ok(error.type.startsWith('urn:assinavelox:problem:'));
    }
});
