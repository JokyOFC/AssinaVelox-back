import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';

export const UUID_V4 =
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

/** URL do servidor falso (tools/sdkgen/fake_server.py). */
export function fakeUrl() {
    const url = process.env.FAKE_API_URL;
    if (!url) {
        throw new Error(
            'FAKE_API_URL não definido. Rode: python tools/sdkgen/sdkgen.py test',
        );
    }
    return url.replace(/\/+$/, '');
}

/** Opções com o cabeçalho de rastreio e o id para consultar o servidor falso. */
export function newTrace(extra = {}) {
    const trace = randomUUID().replaceAll('-', '');
    return [{ ...extra, headers: { 'X-Fake-Trace': trace } }, trace];
}

export async function fetchTrace(trace) {
    const url = fakeUrl();
    const origin = url.slice(0, url.indexOf('/api/v1'));
    const response = await fetch(`${origin}/__fake/requests?trace=${trace}`);
    return response.json();
}

export async function lastRecord(trace) {
    const records = await fetchTrace(trace);
    if (records.length === 0) {
        throw new Error('o servidor falso não recebeu o pedido');
    }
    return records[records.length - 1];
}

export function loadVectors() {
    return JSON.parse(
        readFileSync(
            new URL(
                '../../testdata/webhook-signature-vectors.json',
                import.meta.url,
            ),
            'utf8',
        ),
    );
}
