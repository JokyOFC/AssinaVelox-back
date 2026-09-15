// Cada operação da especificação contra o servidor falso. Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.

import assert from 'node:assert/strict';
import { test } from 'node:test';

import { AssinaVelox, DownloadedFile, Page } from '../dist/index.js';
import { fakeUrl, fetchTrace, newTrace, UUID_V4 } from './support.mjs';

const client = () =>
    new AssinaVelox({ baseUrl: fakeUrl(), token: 'tok_teste_sdk' });

async function checkTrace(trace, operation, idempotency) {
    const records = await fetchTrace(trace);
    assert.ok(records.length > 0, 'o servidor falso não recebeu o pedido');
    assert.equal(records[0].operation, operation);
    assert.deepEqual(records[0].violations, []);
    const key = records[0].headers['idempotency-key'];
    if (idempotency === 'required') {
        assert.match(key ?? '', UUID_V4);
    } else if (idempotency === 'optional') {
        assert.equal(key, undefined);
    }
}

void test('operação listEnvelopes (v1.envelopes.index)', async () => {
    const [options, trace] = newTrace();
    const result = await client().listEnvelopes({}, options);
    assert.ok(result instanceof Page);
    assert.equal(result.data.length, 2);
    assert.equal(result.nextCursor, 'fake-cursor-2');
    assert.equal(typeof result.data[0], 'object');
    assert.equal(
        typeof result.data[0]['created_at'],
        'string',
        'campo created_at',
    );
    assert.equal(
        typeof result.data[0]['display_code'],
        'string',
        'campo display_code',
    );
    assert.equal(typeof result.data[0]['id'], 'string', 'campo id');
    assert.equal(typeof result.data[0]['object'], 'string', 'campo object');
    assert.equal(
        typeof result.data[0]['recipients_count'],
        'number',
        'campo recipients_count',
    );
    assert.equal(
        typeof result.data[0]['signed_count'],
        'number',
        'campo signed_count',
    );
    assert.equal(
        typeof result.data[0]['signing_order'],
        'string',
        'campo signing_order',
    );
    assert.equal(typeof result.data[0]['status'], 'string', 'campo status');
    assert.equal(
        typeof result.data[0]['status_label'],
        'string',
        'campo status_label',
    );
    assert.equal(typeof result.data[0]['title'], 'string', 'campo title');
    assert.equal(
        typeof result.data[0]['updated_at'],
        'string',
        'campo updated_at',
    );
    assert.equal(
        typeof result.data[0]['viewers_count'],
        'number',
        'campo viewers_count',
    );
    const following = await result.nextPage();
    assert.ok(following !== null);
    assert.equal(following.data.length, 1);
    assert.equal(following.hasMore, false);
    assert.equal(await following.nextPage(), null);
    let total = 0;
    for await (const _item of result) total++;
    assert.equal(total, 3);
    await checkTrace(trace, 'v1.envelopes.index', null);
});

void test('operação createEnvelope (v1.envelopes.store)', async () => {
    const [options, trace] = newTrace();
    const result = await client().createEnvelope(
        { title: 'exemplo de title' },
        options,
    );
    assert.equal(typeof result, 'object');
    assert.equal(typeof result['created_at'], 'string', 'campo created_at');
    assert.equal(typeof result['display_code'], 'string', 'campo display_code');
    assert.equal(typeof result['id'], 'string', 'campo id');
    assert.equal(typeof result['object'], 'string', 'campo object');
    assert.equal(
        typeof result['recipients_count'],
        'number',
        'campo recipients_count',
    );
    assert.equal(typeof result['signed_count'], 'number', 'campo signed_count');
    assert.equal(
        typeof result['signing_order'],
        'string',
        'campo signing_order',
    );
    assert.equal(typeof result['status'], 'string', 'campo status');
    assert.equal(typeof result['status_label'], 'string', 'campo status_label');
    assert.equal(typeof result['title'], 'string', 'campo title');
    assert.equal(typeof result['updated_at'], 'string', 'campo updated_at');
    assert.equal(
        typeof result['viewers_count'],
        'number',
        'campo viewers_count',
    );
    await checkTrace(trace, 'v1.envelopes.store', 'required');
});

void test('operação getEnvelope (v1.envelopes.show)', async () => {
    const [options, trace] = newTrace();
    const result = await client().getEnvelope(
        '01J00000000000000000000000',
        options,
    );
    assert.equal(typeof result, 'object');
    assert.equal(typeof result['created_at'], 'string', 'campo created_at');
    assert.equal(typeof result['display_code'], 'string', 'campo display_code');
    assert.equal(typeof result['id'], 'string', 'campo id');
    assert.equal(typeof result['object'], 'string', 'campo object');
    assert.equal(
        typeof result['recipients_count'],
        'number',
        'campo recipients_count',
    );
    assert.equal(typeof result['signed_count'], 'number', 'campo signed_count');
    assert.equal(
        typeof result['signing_order'],
        'string',
        'campo signing_order',
    );
    assert.equal(typeof result['status'], 'string', 'campo status');
    assert.equal(typeof result['status_label'], 'string', 'campo status_label');
    assert.equal(typeof result['title'], 'string', 'campo title');
    assert.equal(typeof result['updated_at'], 'string', 'campo updated_at');
    assert.equal(
        typeof result['viewers_count'],
        'number',
        'campo viewers_count',
    );
    await checkTrace(trace, 'v1.envelopes.show', null);
});

void test('operação uploadDocument (v1.envelopes.documents.store)', async () => {
    const [options, trace] = newTrace();
    const result = await client().uploadDocument(
        '01J00000000000000000000000',
        {
            content: new Uint8Array([
                37, 80, 68, 70, 45, 49, 46, 52, 13, 10, 37, 32, 116, 101, 115,
                116, 101, 32, 100, 111, 32, 83, 68, 75, 0, 255, 13, 10, 37, 37,
                69, 79, 70, 10,
            ]),
            filename: 'contrato.pdf',
            contentType: 'application/pdf',
        },
        options,
    );
    assert.equal(typeof result, 'object');
    assert.equal(typeof result['created_at'], 'string', 'campo created_at');
    assert.equal(typeof result['id'], 'string', 'campo id');
    assert.equal(typeof result['name'], 'string', 'campo name');
    assert.equal(typeof result['object'], 'string', 'campo object');
    assert.equal(
        typeof result['original_filename'],
        'string',
        'campo original_filename',
    );
    assert.equal(typeof result['position'], 'number', 'campo position');
    assert.equal(
        typeof result['processing_label'],
        'string',
        'campo processing_label',
    );
    assert.equal(
        typeof result['processing_status'],
        'string',
        'campo processing_status',
    );
    assert.equal(typeof result['ready'], 'boolean', 'campo ready');
    assert.equal(typeof result['source_type'], 'string', 'campo source_type');
    await checkTrace(trace, 'v1.envelopes.documents.store', 'required');
});

void test('operação listRecipients (v1.envelopes.recipients.index)', async () => {
    const [options, trace] = newTrace();
    const result = await client().listRecipients(
        '01J00000000000000000000000',
        options,
    );
    assert.ok(Array.isArray(result) && result.length >= 1);
    assert.equal(typeof result[0], 'object');
    assert.equal(typeof result[0]['id'], 'string', 'campo id');
    assert.equal(
        typeof result[0]['notifications_count'],
        'number',
        'campo notifications_count',
    );
    assert.equal(typeof result[0]['object'], 'string', 'campo object');
    assert.equal(typeof result[0]['order'], 'number', 'campo order');
    assert.equal(typeof result[0]['role'], 'string', 'campo role');
    assert.equal(typeof result[0]['role_label'], 'string', 'campo role_label');
    assert.equal(typeof result[0]['status'], 'string', 'campo status');
    assert.equal(
        typeof result[0]['status_label'],
        'string',
        'campo status_label',
    );
    await checkTrace(trace, 'v1.envelopes.recipients.index', null);
});

void test('operação syncRecipients (v1.envelopes.recipients.sync)', async () => {
    const [options, trace] = newTrace();
    const result = await client().syncRecipients(
        '01J00000000000000000000000',
        {
            recipients: [{ email: 'ana@example.com', name: 'exemplo de name' }],
            signing_order: 'sequential',
        },
        options,
    );
    assert.ok(Array.isArray(result) && result.length >= 1);
    assert.equal(typeof result[0], 'object');
    assert.equal(typeof result[0]['id'], 'string', 'campo id');
    assert.equal(
        typeof result[0]['notifications_count'],
        'number',
        'campo notifications_count',
    );
    assert.equal(typeof result[0]['object'], 'string', 'campo object');
    assert.equal(typeof result[0]['order'], 'number', 'campo order');
    assert.equal(typeof result[0]['role'], 'string', 'campo role');
    assert.equal(typeof result[0]['role_label'], 'string', 'campo role_label');
    assert.equal(typeof result[0]['status'], 'string', 'campo status');
    assert.equal(
        typeof result[0]['status_label'],
        'string',
        'campo status_label',
    );
    await checkTrace(trace, 'v1.envelopes.recipients.sync', 'optional');
});

void test('operação listFields (v1.envelopes.fields.index)', async () => {
    const [options, trace] = newTrace();
    const result = await client().listFields(
        '01J00000000000000000000000',
        options,
    );
    assert.ok(Array.isArray(result) && result.length >= 1);
    assert.equal(typeof result[0], 'object');
    assert.equal(typeof result[0]['auto'], 'boolean', 'campo auto');
    assert.equal(typeof result[0]['h'], 'number', 'campo h');
    assert.equal(typeof result[0]['id'], 'string', 'campo id');
    assert.equal(typeof result[0]['object'], 'string', 'campo object');
    assert.equal(typeof result[0]['page'], 'number', 'campo page');
    assert.equal(typeof result[0]['required'], 'boolean', 'campo required');
    assert.equal(typeof result[0]['type'], 'string', 'campo type');
    assert.equal(typeof result[0]['w'], 'number', 'campo w');
    assert.equal(typeof result[0]['x'], 'number', 'campo x');
    assert.equal(typeof result[0]['y'], 'number', 'campo y');
    await checkTrace(trace, 'v1.envelopes.fields.index', null);
});

void test('operação syncFields (v1.envelopes.fields.sync)', async () => {
    const [options, trace] = newTrace();
    const result = await client().syncFields(
        '01J00000000000000000000000',
        {
            fields: [
                { h: 0.5, page: 1, type: 'signature', w: 0.5, x: 0.5, y: 0.5 },
            ],
        },
        options,
    );
    assert.ok(Array.isArray(result) && result.length >= 1);
    assert.equal(typeof result[0], 'object');
    assert.equal(typeof result[0]['auto'], 'boolean', 'campo auto');
    assert.equal(typeof result[0]['h'], 'number', 'campo h');
    assert.equal(typeof result[0]['id'], 'string', 'campo id');
    assert.equal(typeof result[0]['object'], 'string', 'campo object');
    assert.equal(typeof result[0]['page'], 'number', 'campo page');
    assert.equal(typeof result[0]['required'], 'boolean', 'campo required');
    assert.equal(typeof result[0]['type'], 'string', 'campo type');
    assert.equal(typeof result[0]['w'], 'number', 'campo w');
    assert.equal(typeof result[0]['x'], 'number', 'campo x');
    assert.equal(typeof result[0]['y'], 'number', 'campo y');
    await checkTrace(trace, 'v1.envelopes.fields.sync', 'optional');
});

void test('operação sendEnvelope (v1.envelopes.send)', async () => {
    const [options, trace] = newTrace();
    const result = await client().sendEnvelope(
        '01J00000000000000000000000',
        options,
    );
    assert.equal(typeof result.meta, 'object');
    assert.equal(typeof result.data, 'object');
    assert.equal(
        typeof result.data['created_at'],
        'string',
        'campo created_at',
    );
    assert.equal(
        typeof result.data['display_code'],
        'string',
        'campo display_code',
    );
    assert.equal(typeof result.data['id'], 'string', 'campo id');
    assert.equal(typeof result.data['object'], 'string', 'campo object');
    assert.equal(
        typeof result.data['recipients_count'],
        'number',
        'campo recipients_count',
    );
    assert.equal(
        typeof result.data['signed_count'],
        'number',
        'campo signed_count',
    );
    assert.equal(
        typeof result.data['signing_order'],
        'string',
        'campo signing_order',
    );
    assert.equal(typeof result.data['status'], 'string', 'campo status');
    assert.equal(
        typeof result.data['status_label'],
        'string',
        'campo status_label',
    );
    assert.equal(typeof result.data['title'], 'string', 'campo title');
    assert.equal(
        typeof result.data['updated_at'],
        'string',
        'campo updated_at',
    );
    assert.equal(
        typeof result.data['viewers_count'],
        'number',
        'campo viewers_count',
    );
    await checkTrace(trace, 'v1.envelopes.send', 'required');
});

void test('operação cancelEnvelope (v1.envelopes.cancel)', async () => {
    const [options, trace] = newTrace();
    const result = await client().cancelEnvelope(
        '01J00000000000000000000000',
        {},
        options,
    );
    assert.equal(typeof result.meta, 'object');
    assert.equal(typeof result.data, 'object');
    assert.equal(
        typeof result.data['created_at'],
        'string',
        'campo created_at',
    );
    assert.equal(
        typeof result.data['display_code'],
        'string',
        'campo display_code',
    );
    assert.equal(typeof result.data['id'], 'string', 'campo id');
    assert.equal(typeof result.data['object'], 'string', 'campo object');
    assert.equal(
        typeof result.data['recipients_count'],
        'number',
        'campo recipients_count',
    );
    assert.equal(
        typeof result.data['signed_count'],
        'number',
        'campo signed_count',
    );
    assert.equal(
        typeof result.data['signing_order'],
        'string',
        'campo signing_order',
    );
    assert.equal(typeof result.data['status'], 'string', 'campo status');
    assert.equal(
        typeof result.data['status_label'],
        'string',
        'campo status_label',
    );
    assert.equal(typeof result.data['title'], 'string', 'campo title');
    assert.equal(
        typeof result.data['updated_at'],
        'string',
        'campo updated_at',
    );
    assert.equal(
        typeof result.data['viewers_count'],
        'number',
        'campo viewers_count',
    );
    await checkTrace(trace, 'v1.envelopes.cancel', 'optional');
});

void test('operação downloadFile (v1.envelopes.files.show)', async () => {
    const [options, trace] = newTrace();
    const result = await client().downloadFile(
        '01J00000000000000000000000',
        'original',
        {},
        options,
    );
    assert.ok(result instanceof DownloadedFile);
    assert.equal(Buffer.from(result.content.subarray(0, 4)).toString(), '%PDF');
    assert.equal(result.contentType, 'application/pdf');
    assert.equal(result.filename, 'AV-000123-original.pdf');
    await checkTrace(trace, 'v1.envelopes.files.show', null);
});

void test('operação listEvents (v1.envelopes.events.index)', async () => {
    const [options, trace] = newTrace();
    const result = await client().listEvents(
        '01J00000000000000000000000',
        {},
        options,
    );
    assert.ok(result instanceof Page);
    assert.equal(result.data.length, 2);
    assert.equal(result.nextCursor, 'fake-cursor-2');
    assert.equal(typeof result.data[0], 'object');
    assert.equal(typeof result.data[0]['id'], 'string', 'campo id');
    assert.equal(typeof result.data[0]['kind'], 'string', 'campo kind');
    assert.equal(typeof result.data[0]['label'], 'string', 'campo label');
    assert.equal(typeof result.data[0]['object'], 'string', 'campo object');
    assert.equal(
        typeof result.data[0]['occurred_at'],
        'string',
        'campo occurred_at',
    );
    assert.equal(typeof result.data[0]['type'], 'string', 'campo type');
    const following = await result.nextPage();
    assert.ok(following !== null);
    assert.equal(following.data.length, 1);
    assert.equal(following.hasMore, false);
    assert.equal(await following.nextPage(), null);
    let total = 0;
    for await (const _item of result) total++;
    assert.equal(total, 3);
    await checkTrace(trace, 'v1.envelopes.events.index', null);
});

void test('operação getVerification (v1.envelopes.verification.show)', async () => {
    const [options, trace] = newTrace();
    const result = await client().getVerification(
        '01J00000000000000000000000',
        options,
    );
    assert.equal(typeof result, 'object');
    await checkTrace(trace, 'v1.envelopes.verification.show', null);
});

void test('operação listTemplates (v1.templates.index)', async () => {
    const [options, trace] = newTrace();
    const result = await client().listTemplates({}, options);
    assert.ok(result instanceof Page);
    assert.equal(result.data.length, 2);
    assert.equal(result.nextCursor, 'fake-cursor-2');
    assert.equal(typeof result.data[0], 'object');
    assert.equal(typeof result.data[0]['id'], 'string', 'campo id');
    assert.equal(typeof result.data[0]['name'], 'string', 'campo name');
    assert.equal(typeof result.data[0]['object'], 'string', 'campo object');
    assert.equal(
        typeof result.data[0]['source_type'],
        'string',
        'campo source_type',
    );
    assert.equal(typeof result.data[0]['status'], 'string', 'campo status');
    assert.equal(
        typeof result.data[0]['updated_at'],
        'string',
        'campo updated_at',
    );
    assert.equal(typeof result.data[0]['usable'], 'boolean', 'campo usable');
    const following = await result.nextPage();
    assert.ok(following !== null);
    assert.equal(following.data.length, 1);
    assert.equal(following.hasMore, false);
    assert.equal(await following.nextPage(), null);
    let total = 0;
    for await (const _item of result) total++;
    assert.equal(total, 3);
    await checkTrace(trace, 'v1.templates.index', null);
});

void test('operação getTemplate (v1.templates.show)', async () => {
    const [options, trace] = newTrace();
    const result = await client().getTemplate(
        '01J00000000000000000000000',
        options,
    );
    assert.equal(typeof result, 'object');
    assert.equal(typeof result['id'], 'string', 'campo id');
    assert.equal(typeof result['name'], 'string', 'campo name');
    assert.equal(typeof result['object'], 'string', 'campo object');
    assert.equal(typeof result['source_type'], 'string', 'campo source_type');
    assert.equal(typeof result['status'], 'string', 'campo status');
    assert.equal(typeof result['updated_at'], 'string', 'campo updated_at');
    assert.equal(typeof result['usable'], 'boolean', 'campo usable');
    await checkTrace(trace, 'v1.templates.show', null);
});

void test('operação createEnvelopeFromTemplate (v1.templates.envelopes.store)', async () => {
    const [options, trace] = newTrace();
    const result = await client().createEnvelopeFromTemplate(
        '01J00000000000000000000000',
        {},
        options,
    );
    assert.equal(typeof result, 'object');
    assert.equal(typeof result['created_at'], 'string', 'campo created_at');
    assert.equal(typeof result['display_code'], 'string', 'campo display_code');
    assert.equal(typeof result['id'], 'string', 'campo id');
    assert.equal(typeof result['object'], 'string', 'campo object');
    assert.equal(
        typeof result['recipients_count'],
        'number',
        'campo recipients_count',
    );
    assert.equal(typeof result['signed_count'], 'number', 'campo signed_count');
    assert.equal(
        typeof result['signing_order'],
        'string',
        'campo signing_order',
    );
    assert.equal(typeof result['status'], 'string', 'campo status');
    assert.equal(typeof result['status_label'], 'string', 'campo status_label');
    assert.equal(typeof result['title'], 'string', 'campo title');
    assert.equal(typeof result['updated_at'], 'string', 'campo updated_at');
    assert.equal(
        typeof result['viewers_count'],
        'number',
        'campo viewers_count',
    );
    await checkTrace(trace, 'v1.templates.envelopes.store', 'required');
});

void test('operação listWebhookEvents (v1.webhook_events.index)', async () => {
    const [options, trace] = newTrace();
    const result = await client().listWebhookEvents(options);
    assert.ok(Array.isArray(result) && result.length >= 1);
    await checkTrace(trace, 'v1.webhook_events.index', null);
});

void test('operação getWebhookEventSample (v1.webhook_events.sample)', async () => {
    const [options, trace] = newTrace();
    const result = await client().getWebhookEventSample(
        'envelope.sent',
        options,
    );
    assert.equal(typeof result.meta, 'object');
    assert.ok(Array.isArray(result.data) && result.data.length >= 1);
    await checkTrace(trace, 'v1.webhook_events.sample', null);
});

void test('operação listWebhookSubscriptions (v1.webhook_subscriptions.index)', async () => {
    const [options, trace] = newTrace();
    const result = await client().listWebhookSubscriptions(options);
    assert.equal(typeof result.meta, 'object');
    assert.ok(Array.isArray(result.data) && result.data.length >= 1);
    assert.equal(typeof result.data[0], 'object');
    assert.equal(typeof result.data[0]['id'], 'string', 'campo id');
    assert.equal(typeof result.data[0]['object'], 'string', 'campo object');
    assert.equal(
        typeof result.data[0]['target_url'],
        'string',
        'campo target_url',
    );
    assert.equal(typeof result.data[0]['status'], 'string', 'campo status');
    assert.equal(
        typeof result.data[0]['status_label'],
        'string',
        'campo status_label',
    );
    assert.equal(
        typeof result.data[0]['secret_hint'],
        'string',
        'campo secret_hint',
    );
    assert.equal(
        typeof result.data[0]['signature_header'],
        'string',
        'campo signature_header',
    );
    assert.equal(
        typeof result.data[0]['created_at'],
        'string',
        'campo created_at',
    );
    await checkTrace(trace, 'v1.webhook_subscriptions.index', null);
});

void test('operação createWebhookSubscription (v1.webhook_subscriptions.store)', async () => {
    const [options, trace] = newTrace();
    const result = await client().createWebhookSubscription(
        { target_url: 'https://integracao.example/webhooks/assinavelox' },
        options,
    );
    assert.equal(typeof result.meta, 'object');
    assert.equal(typeof result.data, 'object');
    assert.equal(typeof result.data['id'], 'string', 'campo id');
    assert.equal(typeof result.data['object'], 'string', 'campo object');
    assert.equal(
        typeof result.data['target_url'],
        'string',
        'campo target_url',
    );
    assert.equal(typeof result.data['status'], 'string', 'campo status');
    assert.equal(
        typeof result.data['status_label'],
        'string',
        'campo status_label',
    );
    assert.equal(
        typeof result.data['secret_hint'],
        'string',
        'campo secret_hint',
    );
    assert.equal(
        typeof result.data['signature_header'],
        'string',
        'campo signature_header',
    );
    assert.equal(
        typeof result.data['created_at'],
        'string',
        'campo created_at',
    );
    await checkTrace(trace, 'v1.webhook_subscriptions.store', 'required');
});

void test('operação deleteWebhookSubscription (v1.webhook_subscriptions.destroy)', async () => {
    const [options, trace] = newTrace();
    const result = await client().deleteWebhookSubscription(
        '01J00000000000000000000000',
        options,
    );
    assert.equal(result, undefined);
    await checkTrace(trace, 'v1.webhook_subscriptions.destroy', null);
});

void test('operação createEmbeddedSession (v1.envelopes.recipients.embedded_sessions.store)', async () => {
    const [options, trace] = newTrace();
    const result = await client().createEmbeddedSession(
        '01J00000000000000000000000',
        '01J00000000000000000000000',
        { origin: 'exemplo de origin' },
        options,
    );
    assert.equal(typeof result, 'object');
    assert.equal(typeof result['id'], 'string', 'campo id');
    assert.equal(typeof result['object'], 'string', 'campo object');
    assert.equal(typeof result['origin'], 'string', 'campo origin');
    assert.equal(typeof result['status'], 'string', 'campo status');
    await checkTrace(
        trace,
        'v1.envelopes.recipients.embedded_sessions.store',
        'required',
    );
});

void test('operação getEmbeddedSession (v1.envelopes.recipients.embedded_sessions.show)', async () => {
    const [options, trace] = newTrace();
    const result = await client().getEmbeddedSession(
        '01J00000000000000000000000',
        '01J00000000000000000000000',
        '01J00000000000000000000000',
        options,
    );
    assert.equal(typeof result, 'object');
    assert.equal(typeof result['id'], 'string', 'campo id');
    assert.equal(typeof result['object'], 'string', 'campo object');
    assert.equal(typeof result['origin'], 'string', 'campo origin');
    assert.equal(typeof result['status'], 'string', 'campo status');
    await checkTrace(
        trace,
        'v1.envelopes.recipients.embedded_sessions.show',
        null,
    );
});

void test('operação revokeEmbeddedSession (v1.envelopes.recipients.embedded_sessions.destroy)', async () => {
    const [options, trace] = newTrace();
    const result = await client().revokeEmbeddedSession(
        '01J00000000000000000000000',
        '01J00000000000000000000000',
        '01J00000000000000000000000',
        options,
    );
    assert.equal(typeof result, 'object');
    assert.equal(typeof result['id'], 'string', 'campo id');
    assert.equal(typeof result['object'], 'string', 'campo object');
    assert.equal(typeof result['origin'], 'string', 'campo origin');
    assert.equal(typeof result['status'], 'string', 'campo status');
    await checkTrace(
        trace,
        'v1.envelopes.recipients.embedded_sessions.destroy',
        null,
    );
});
