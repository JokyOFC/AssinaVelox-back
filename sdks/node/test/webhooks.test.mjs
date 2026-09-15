import assert from 'node:assert/strict';
import { test } from 'node:test';

import { WebhookSignatureError, webhooks } from '../dist/index.js';
import { loadVectors } from './support.mjs';

const vectors = loadVectors();
const bytes = (base64) => new Uint8Array(Buffer.from(base64, 'base64'));

void test('webhook: há vetores dos dois resultados', () => {
    const results = new Set(vectors.verify.map((item) => item.expected));
    assert.equal(results.size, 2);
    assert.ok(results.has(true) && results.has(false));
    assert.equal(vectors.default_tolerance, webhooks.DEFAULT_TOLERANCE_SECONDS);
});

void test('webhook: vetores de compute', () => {
    for (const item of vectors.compute) {
        assert.equal(
            webhooks.computeSignature(
                item.secret,
                item.timestamp,
                bytes(item.body_base64),
            ),
            item.signature,
            item.name,
        );
    }
});

void test('webhook: vetores de header', () => {
    for (const item of vectors.header) {
        assert.equal(
            webhooks.signatureHeader(
                item.secrets,
                item.timestamp,
                bytes(item.body_base64),
            ),
            item.header,
            item.name,
        );
    }
});

void test('webhook: vetores de verify', () => {
    for (const item of vectors.verify) {
        assert.equal(
            webhooks.verifySignature(
                item.secret,
                item.signature_header,
                item.timestamp_header,
                bytes(item.body_base64),
                { now: item.now, tolerance: item.tolerance },
            ),
            item.expected,
            item.name,
        );
    }
});

void test('webhook: constructEvent (objeto de cabeçalhos e Headers)', () => {
    const item = vectors.verify[0];
    const body = bytes(item.body_base64);
    const plain = {
        'x-assinavelox-signature': [item.signature_header],
        'X-ASSINAVELOX-TIMESTAMP': item.timestamp_header,
    };
    const expected = JSON.parse(Buffer.from(body).toString('utf8'));
    assert.deepEqual(
        webhooks.constructEvent(body, plain, item.secret, { now: item.now }),
        expected,
    );
    const headers = new Headers({
        'X-AssinaVelox-Signature': item.signature_header,
        'X-AssinaVelox-Timestamp': item.timestamp_header,
    });
    assert.deepEqual(
        webhooks.constructEvent(
            Buffer.from(body).toString('utf8'),
            headers,
            item.secret,
            { now: item.now },
        ),
        expected,
    );
    assert.throws(
        () =>
            webhooks.constructEvent(
                Buffer.concat([body, Buffer.from(' ')]),
                plain,
                item.secret,
                { now: item.now },
            ),
        WebhookSignatureError,
    );
    assert.throws(
        () => webhooks.constructEvent(body, {}, item.secret, { now: item.now }),
        WebhookSignatureError,
    );
});
