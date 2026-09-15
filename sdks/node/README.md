# SDK Node.js da API v1 da AssinaVelox

Cliente fino e tipado, gerado da especificação OpenAPI (`sdks/openapi/v1.json`). TypeScript com saída ESM, `fetch` nativo, Node.js 18+, sem dependências.

Versão `1.0.0` — a versão maior acompanha a API (`/api/v1`).

## Instalação

Ainda não publicado no npm (decisão pendente do proprietário; o `package.json` está marcado `private`). Para compilar a partir do repositório:

```bash
cd sdks/node && npx tsc -p tsconfig.json   # gera dist/ (ESM + .d.ts)
```

## Uso

```ts
import { ApiError, AssinaVelox, fileFromPath } from '@assinavelox/sdk';

const client = new AssinaVelox({
    baseUrl: 'https://sua-instalacao.example/api/v1',
    token: process.env.ASSINAVELOX_TOKEN!,
});

const envelope = await client.createEnvelope({
    title: 'Contrato de locação — Apto 302',
});
await client.uploadDocument(envelope.id, await fileFromPath('contrato.pdf'));
await client.syncRecipients(envelope.id, {
    signing_order: 'sequential',
    recipients: [{ name: 'Ana Souza', email: 'ana@example.com' }],
});
const sent = await client.sendEnvelope(envelope.id);
console.log(sent.meta.invitations_sent);

for await (const item of await client.listEnvelopes({
    status: ['in_progress'],
})) {
    console.log(item.display_code, item.status_label);
}

try {
    await client.getEnvelope('01J00000000000000000000000');
} catch (error) {
    if (error instanceof ApiError) {
        // RFC 9457: error.status, error.type, error.title, error.detail, error.errors
        console.log(error.slug, error.correlationId);
    }
}
```

- **Token.** `Authorization: Bearer` em toda chamada. O token fica num campo privado (`#`): não aparece em `console.log`, `util.inspect` nem em mensagens de erro.
- **Idempotency-Key.** Nas criações e no envio, o SDK gera um UUID v4 se você não passar uma chave. Para repetir com segurança depois de uma queda, guarde e reenvie a sua: `{ idempotencyKey: 'pedido-42' }`.
- **Erros.** Resposta de erro da API → `ApiError`, com os campos da RFC 9457. Falha de rede → `NetworkError` (tempo esgotado: `RequestTimeoutError`). Pedido recusado pelo próprio SDK → `InvalidRequestError`.
- **Paginação.** `listEnvelopes`, `listEvents` e `listTemplates` devolvem `Page`: `page.data` (só esta página), `page.nextCursor`, `await page.nextPage()`, e `for await` percorre todas.
- **Tempo limite e cancelamento.** `new AssinaVelox({ ..., timeoutMs: 10_000 })` ou, por chamada, `{ timeoutMs: 5000, signal }`.
- **Tipos.** Respostas e corpos seguem a especificação (`Envelope`, `Recipient`, `StoreEnvelopeRequest`…), com os nomes de campo da API (`display_code`). Campos novos podem aparecer: a v1 só cresce de forma compatível.
- **Redirecionamentos** não são seguidos: o token nunca vai para outro endereço.

## Webhooks de saída

```ts
import express from 'express';
import { webhooks } from '@assinavelox/sdk';

app.post(
    '/webhooks/assinavelox',
    express.raw({ type: 'application/json' }),
    (req, res) => {
        try {
            const event = webhooks.constructEvent(
                req.body,
                req.headers,
                process.env.ASSINAVELOX_WEBHOOK_SECRET!,
            );
            res.sendStatus(204); // processe depois; deduplique por X-AssinaVelox-Delivery-Id
        } catch {
            res.sendStatus(400);
        }
    },
);
```

`webhooks.verifySignature()` é o mesmo algoritmo do servidor (HMAC-SHA256 de `"{timestamp}.{corpo}"`, janela de 300 s, `timingSafeEqual`, duas assinaturas durante a rotação do segredo). O corpo precisa chegar **bruto** (`express.raw`, não `express.json`).

## Experimentar sem a API real

```bash
python tools/sdkgen/fake_server.py --port 8765
```

Use `baseUrl: 'http://127.0.0.1:8765/api/v1'` e qualquer token. O exemplo acima é o teste "exemplo do README" em `test/client.test.mjs`.

## Testes

```bash
python tools/sdkgen/sdkgen.py test     # compila e roda os três SDKs contra o servidor falso
```

`src/client.ts`, `src/types.ts`, `src/version.ts` e `test/operations.generated.test.mjs` são gerados: não edite, rode `python tools/sdkgen/sdkgen.py generate`.
