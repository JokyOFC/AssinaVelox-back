<?php

use App\Http\Middleware\ApiDocsAccess;

/*
|--------------------------------------------------------------------------
| Scramble — OpenAPI da API v1 (Fase 2 §2.15, docs/fase-2/api-v1.md §10)
|--------------------------------------------------------------------------
|
| Documenta SÓ `/api/v1`. A interface (`/docs/api`) e o JSON (`/docs/api.json`) passam pelo
| gate `viewApiDocs` (App\Services\Api\ApiDocumentation): usuário autenticado com
| `manage_integrations` na organização corrente e a flag `api_integrations` ligada. Convidados
| recebem 403 em qualquer ambiente (App\Http\Middleware\ApiDocsAccess).
|
*/

return [
    'api_path' => 'api/v1',

    'api_domain' => null,

    'export_path' => 'api.json',

    'cache' => [
        'key' => 'scramble.openapi',
        'store' => env('SCRAMBLE_CACHE_STORE', 'file'),
    ],

    'info' => [
        'version' => env('API_VERSION', '1.0.0'),

        'description' => <<<'MD'
API REST da AssinaVelox, versão 1 — preparo de documentos e coleta de aceites por integração.

**Autenticação.** `Authorization: Bearer {token}`. Crie a chave em *Integrações → Chaves*; o texto do token aparece uma única vez. Cada chave pertence a uma organização e a quem a criou, tem permissões (abilities) explícitas e nunca faz mais do que essa pessoa pode fazer na interface.

**Formato.** JSON com `data` (e `links`/`meta` nas listas, paginadas por cursor). Identificadores públicos em ULID, datas em ISO-8601 UTC, dinheiro em centavos com a moeda.

**Erros.** `application/problem+json` (RFC 9457): `type`, `title`, `status`, `detail`, `errors` por campo (422) e `correlation_id`.

**Idempotência.** Criações e envio exigem o cabeçalho `Idempotency-Key`; a mesma chave com o mesmo corpo devolve a mesma resposta por 24 h.

**Limites.** Por chave e por organização, com os cabeçalhos `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset` e `Retry-After` no 429.

Exemplo:

```
curl -X POST https://{sua-instalacao}/api/v1/envelopes \
  -H "Authorization: Bearer 12|avk_..." \
  -H "Idempotency-Key: 7d1f5c2e-4b7a-4a51-9d0e-2f0b6c3a9e11" \
  -H "Content-Type: application/json" \
  -d '{"title": "Contrato de locação — Apto 302"}'
```
MD,
    ],

    'ui' => [
        'title' => 'AssinaVelox — API v1',
    ],

    'dev_tools' => [
        'enabled' => env('SCRAMBLE_DEV_TOOLS', false),
    ],

    'renderer' => 'elements',

    'renderers' => [
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '',
            // Nunca envia o cookie de sessão da interface nas chamadas "Try it".
            'tryItCredentialsPolicy' => 'omit',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
        'scalar' => [
            'view' => 'scramble::scalar',
            'cdn' => 'https://cdn.jsdelivr.net/npm/@scalar/api-reference',
            'theme' => 'laravel',
            'proxyUrl' => 'https://proxy.scalar.com',
            'darkMode' => false,
            'showDeveloperTools' => 'never',
            'agent' => ['disabled' => true],
            'credentials' => 'omit',
        ],
    ],

    'servers' => null,

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'flatten_deep_query_parameters' => true,

    'middleware' => [
        'web',
        ApiDocsAccess::class,
    ],

    'extensions' => [],

    // O esquema Bearer é declarado em App\Services\Api\ApiDocumentation (afterOpenApiGenerated).
    'security_strategy' => null,
];
