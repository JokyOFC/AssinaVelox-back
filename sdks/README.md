# SDKs da API v1

Clientes finos e tipados da API REST v1 da AssinaVelox, **gerados** da especificação OpenAPI por um gerador próprio (`tools/sdkgen`). Nenhum deles tem dependência de terceiros.

| Diretório            | Linguagem                    | Requisito    | Pacote (nome reservado, não publicado) |
| -------------------- | ---------------------------- | ------------ | -------------------------------------- |
| [`php/`](php/)       | PHP, PSR-4 `AssinaVelox\Sdk` | PHP 8.1+     | `assinavelox/sdk`                      |
| [`node/`](node/)     | TypeScript com saída ESM     | Node.js 18+  | `@assinavelox/sdk`                     |
| [`python/`](python/) | Python, dataclasses          | Python 3.10+ | `assinavelox`                          |

- `openapi/v1.json`: a especificação exportada do Scramble (`php artisan scramble:export`), ordenada e versionada. Um teste falha se ela divergir da API.
- `testdata/webhook-signature-vectors.json`: vetores da assinatura dos webhooks de saída, gerados pelo código PHP real do servidor e conferidos pelos três SDKs.

**Versão.** SDK `1.x.y` ↔ API `/api/v1`. A versão maior só muda com uma `/api/v2`.

**Publicação.** Nenhum SDK foi publicado em registro nenhum (Packagist, npm, PyPI): isso depende de decisão do proprietário. Veja [docs/fase-3/sdks.md](../docs/fase-3/sdks.md) §10.

**Regerar e testar** (na raiz do repositório, com o Python do venv do pdftool):

```bash
python tools/sdkgen/sdkgen.py all     # exporta, gera vetores e SDKs, e roda os testes dos três
```
