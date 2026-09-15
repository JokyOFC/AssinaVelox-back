# Fase 3 — SDKs da API v1 (PHP, Node, Python)

> Roadmap §3.9 ("SDKs gerados da OpenAPI (PHP, Node, Python)"; aceite: "SDKs com testes gerados contra fake server"). Onda G, área **G-SDK**. Identificadores em inglês; prosa em português.
> Documentos-fonte: `docs/roadmap.md` §1 (T1, T5, T8, T9) e §3.9; `docs/fases-2-3-viabilidade.md` §3.2 (onda G, classe A); `docs/fase-2/api-v1.md` (contrato da API); `docs/fase-2/webhooks.md` §4 (assinatura).

## 1. Resumo

- **Especificação versionada**: `sdks/openapi/v1.json`, exportada com `php artisan scramble:export`, com chaves ordenadas e o `servers` normalizado. Um teste falha quando a API diverge dela.
- **Gerador próprio** (`tools/sdkgen`, só biblioteca padrão do Python, roda com o Python do venv do pdftool). O gerador de mercado não foi pesquisado (NÃO CONFIRMADO em `docs/fases-2-3-viabilidade.md`), e nenhum pacote novo foi instalado.
- **Três SDKs finos e tipados**, sem dependência de terceiros:
    - `sdks/php`: PHP 8.1+, PSR-4 `AssinaVelox\Sdk`, curl ou streams;
    - `sdks/node`: TypeScript com saída ESM, `fetch` nativo, Node 18+;
    - `sdks/python`: `urllib` e `dataclasses`, Python 3.10+.
- Os três fazem o mesmo: token Bearer, `Idempotency-Key` (gerada quando a rota exige), erros RFC 9457 como exceção tipada, paginação por cursor, tempo limite, User-Agent com versão, sem redirecionamento, e o verificador da assinatura dos webhooks **idêntico** ao do servidor.
- **Servidor falso** local (`tools/sdkgen/fake_server.py`, `http.server`) que responde pela especificação e confere cada pedido. Os testes de cada SDK rodam contra ele, e um teste Pest orquestra os três.
- **Teste de contrato do lado real**: as respostas da aplicação são validadas contra a mesma especificação. Foi ele que achou os buracos da §4.
- **Sem flag**: são artefatos do repositório. Nada muda no produto, nada é publicado em registro nenhum (§10).

## 2. Onde está cada coisa

| Caminho                                        | O que é                                                                                                        |
| ---------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| `sdks/openapi/v1.json`                         | Especificação exportada e normalizada (fonte dos SDKs)                                                         |
| `sdks/testdata/webhook-signature-vectors.json` | Vetores da assinatura de webhook, gerados pelo PHP real                                                        |
| `sdks/{php,node,python}/`                      | SDKs; em cada um, README com exemplo que roda contra o servidor falso                                          |
| `tools/sdkgen/sdkgen.py`                       | CLI: `export`, `vectors`, `generate`, `check`, `test`, `all`                                                   |
| `tools/sdkgen/avsdkgen/`                       | Núcleo: `spec.py` (exportação/impressão digital), `model.py` (IR), `schema.py` (validação/exemplos), emissores |
| `tools/sdkgen/overrides.json`                  | O que o Scramble não infere sem mexer em controller (§4.2)                                                     |
| `tools/sdkgen/fake_server.py`                  | Servidor falso da API v1                                                                                       |
| `tools/sdkgen/webhook_vectors.php`             | Gera os vetores com `App\Services\Webhooks\WebhookSignature`                                                   |
| `tests/Feature/Phase3/Sdk/`                    | Testes Pest (§8)                                                                                               |

Arquivos **gerados** (não edite): PHP `src/Client.php`, `src/Version.php`, `src/Model/*`, `tests/generated_operations.php`; Node `src/client.ts`, `src/types.ts`, `src/version.ts`, `test/operations.generated.test.mjs`; Python `assinavelox/_client.py`, `assinavelox/models.py`, `assinavelox/_version.py`, `tests/test_operations_generated.py`. O resto (transporte, erros, paginação, webhooks, testes de comportamento) é escrito à mão e não é sobrescrito.

## 3. Especificação, regeneração e versão

**Exportação determinística.** `sdkgen.py export` roda `php artisan scramble:export` com os valores de configuração que mudam a especificação fixados em `overrides.json` → `exportPins`: `APP_URL`, `API_VERSION` (1.0.0), `ASSINAVELOX_MAX_UPLOAD_MB` (25) e `ASSINAVELOX_API_PAGE_SIZE_MAX` (100). Depois troca o `servers` por `https://sua-instalacao.example/api/v1`, grava com chaves ordenadas e passa o `vp fmt` do repositório. O `.env` da máquina não entra no arquivo.

**Teste de divergência.** `OpenApiSpecTest` exporta no próprio processo de teste, com os mesmos valores fixados, e compara o conteúdo (não a formatação) com o arquivo versionado. A mensagem lista os caminhos JSON que mudaram e diz como regenerar. O mesmo arquivo confere que **toda** rota `/api/v1` está na especificação e em `overrides.json`, com a `Idempotency-Key` que o middleware da rota exige.

**Regerar** (na raiz; no Linux o Python é `tools/pdftool/.venv/bin/python`):

```bash
tools/pdftool/.venv/Scripts/python.exe tools/sdkgen/sdkgen.py all        # export + vectors + generate + test
tools/pdftool/.venv/Scripts/python.exe tools/sdkgen/sdkgen.py check      # os SDKs vieram da especificação atual?
```

Rota nova em `/api/v1` também precisa de uma entrada em `overrides.json` → `operations` (nome do método e idempotência); sem ela, o gerador para com a mensagem de onde acrescentar.

**Impressão digital.** Cada SDK carrega `SPEC_SHA256`: SHA-256 da forma canônica de `{spec, overrides}`. `sdkgen.py check` (e o Pest) falham se algum SDK veio de outra origem.

**Versão.** SDK `1.x.y` ↔ API `/api/v1` (a versão maior do SDK é igual à da API; o gerador recusa outra combinação). Dentro da v1, operação ou campo novo é versão menor; correção do SDK é versão de correção. Uma `/api/v2` gera SDKs `2.0.0`. A versão do SDK fica em `overrides.json` → `sdk.version`.

## 4. Buracos da especificação exportada

O Scramble infere o OpenAPI a partir do código. Onde a inferência errava, a regra da área foi: corrigir **só com anotações mínimas** nos Resources e Requests da API, sem mudar comportamento. O que dependeria de mudar controller foi para `overrides.json`. O resto está registrado aqui.

### 4.1 Corrigidos por anotação (Resources/Requests da API)

| Arquivo                                                   | Buraco                                                                                                                           | Correção                                                                                                                                        |
| --------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| `Resources/Api/V1/EnvelopeResource.php`                   | `anyOf [array, objeto]`: o detalhe (`documents`, `recipients`, `links`, `message`…) não aparecia; datas `sent_at`… não anuláveis | `$data + [...]` → `array_merge($data, [...])` (mesmo resultado: chaves distintas); `@var string\|null` nas datas; `@var list<DocumentResource>` |
| `Resources/Api/V1/TemplateResource.php`                   | O mesmo `anyOf [array, objeto]`: `variables` e `roles` sumiam                                                                    | `array_merge` (mesmo resultado)                                                                                                                 |
| `Resources/Api/V1/DocumentResource.php`                   | `ready` como texto; `size_bytes` e `sha256.sent/final` como `null` sempre                                                        | `@var bool`, `@var int\|null`, `@var string\|null`                                                                                              |
| `Resources/Api/V1/RecipientResource.php`                  | `signed_at`/`refused_at` não anuláveis; `phone_masked` com um `enum [""]` estranho                                               | `@var string\|null`                                                                                                                             |
| `Resources/Api/V1/EventResource.php`                      | `label` como enumeração fechada de 130 rótulos da trilha, que cresce a cada evento novo                                          | `@var string` (descrição diz que a lista cresce). Sem isso, todo evento novo da trilha quebraria a especificação e os SDKs                      |
| `Requests/Api/V1/GenerateEnvelopeFromTemplateRequest.php` | `values` e `participants` como lista de textos (são mapas)                                                                       | `@var array<string, …>` na regra (sem mudar a regra)                                                                                            |
| `Requests/Api/V1/SyncEnvelopeFieldsRequest.php`           | `fields.*.page` como texto (a regra herdada da interface é só `required`)                                                        | `rules()` sobrescrito devolvendo **as mesmas regras** do pai, com `@var int` na chave                                                           |

A suíte `tests/Feature/Phase2/Api` (154 testes) continua verde com essas mudanças. O Scramble ainda emite 1 aviso `PD001` ("@var redundante") em `documents`: a anotação não é redundante, porque transforma `array<array<mixed>>` em `$ref DocumentResource`.

### 4.2 Complementados em `tools/sdkgen/overrides.json` (sem mexer em controller)

- **`Idempotency-Key`** não aparece na especificação (vem de middleware). O mapa por operação (`required`/`optional`) é conferido contra o middleware das rotas pelo Pest.
- **`GET …/files/{type}`**: o Scramble descreve JSON; é o arquivo (stream). Marcado `binary`, com `type ∈ original|signed|evidence` (o `whereIn` da rota) e o parâmetro `document` (lido da query).
- **`GET` e `POST /webhook-subscriptions`**: respostas montadas à mão no controller (a criação aparecia como `string`). O esquema `WebhookSubscription` foi descrito com `secret` opcional: vem na criação (201), é `null` na listagem e não vem quando a assinatura já existia (200). Esse último caso foi achado pelo teste de contrato.
- **`POST …/cancel`**: `meta.recipients_notified` é inteiro; o Scramble diz texto.
- **Sessões embutidas (G-EMBED)**: `POST …/embedded-sessions` monta a resposta à mão, porque a URL com o token não pode ir para o armazenamento de idempotência, e o Scramble a descreve como `string`. Além disso, `EmbeddedSigningSessionResource` sai com `envelope_id`/`recipient_id` sem tipo, datas não anuláveis e `url` como `null` obrigatório. O esquema `EmbeddedSigningSession` (com `url` opcional, só na criação) e as respostas das três operações estão em `overrides.json`. Quando o Resource ganhar anotações (`@var string|null` nos ids e datas; `url` fora do `required`), essas entradas podem sair.

### 4.3 Registrados, sem correção nesta área

- As respostas de erro aparecem como `{message}` em `application/json` (padrão do Scramble). A API responde RFC 9457 (`docs/fase-2/api-v1.md` §6), e os SDKs seguem esse contrato, não o esquema exportado. Corrigir exige uma extensão de exceção do Scramble em `App\Services\Api\ApiDocumentation`, fora da área G-SDK.
- `status[]` de `GET /envelopes` traz o `enum` também no próprio array (JSON Schema diria "o array inteiro igual a um valor"). O gerador ignora esse `enum`.
- `POST …/send` descreve `anyOf [resposta, SendingException, SendingBlockedException]`: as exceções viram 409/503 RFC 9457 e não são corpo de sucesso. O gerador usa a variante com `data`.
- `GET …/verification` (`data`) e `GET /webhook-events` (itens) saem genéricos (mapa/qualquer coisa). Os SDKs os devolvem como mapa.
- `options` das variáveis de modelo sai sem tipo. `values` perdeu o `maxItems: 100` ao virar mapa.
- Cabeçalhos `RateLimit-*`, `Retry-After` e `X-Correlation-Id` não aparecem nas respostas documentadas. Os SDKs expõem `retryAfter` e `correlationId` no erro.

## 5. O gerador

`spec + overrides → IR (avsdkgen/model.py) → emit_php / emit_node / emit_python`. O servidor falso lê a **mesma** IR; assim, SDK e servidor concordam sobre cada operação.

- **Nomes**: método por operação vem de `overrides.json` (`listEnvelopes`, `createEnvelope`, `uploadDocument`, `downloadFile`…). No Python, em snake_case. Tipos de resposta perdem o sufixo `Resource` (`Envelope`, `Document`); objetos aninhados ganham o nome do pai (`EnvelopeLinks`, `DocumentSha256`, `TemplateVariable`).
- **Tipos**: `anyOf` de objetos (detalhe × listagem) vira um objeto só, e **obrigatório = interseção**. Assim os campos do detalhe ficam opcionais. Enumerações de **resposta** viram texto, porque a v1 pode acrescentar valores; as de **pedido** viram união de literais.
- **Formas de resposta**: `{data: T}` → `T`; `{data: T[]}` → lista; `{data, meta}` → `ApiResult` (`meta` sem tipo); `meta.next_cursor` → `Page<T>`; 204 → nada; arquivo → `DownloadedFile`.
- **Formatação**: a saída PHP passa pelo `pint` e a TypeScript/JSON pelo `vp fmt`, as mesmas ferramentas do repositório. `pint --dirty` e `vp check` de outras áreas não mexem nesses arquivos.
- **Suporte**: `multipart` só com um arquivo, que é o único caso da v1; o gerador recusa formas que não conhece em vez de gerar errado.

## 6. Recursos dos SDKs

| Recurso                | PHP                                                                                             | Node                                             | Python                                          |
| ---------------------- | ----------------------------------------------------------------------------------------------- | ------------------------------------------------ | ----------------------------------------------- |
| Cliente                | `new Client($baseUrl, $token, timeout: 30.0)`                                                   | `new AssinaVelox({ baseUrl, token, timeoutMs })` | `AssinaVelox(base_url=, token=, timeout=30)`    |
| Opções por chamada     | `RequestOptions(idempotencyKey, timeout, headers)`                                              | `{ idempotencyKey, timeoutMs, headers, signal }` | `idempotency_key=, timeout=, headers=`          |
| Erro da API (RFC 9457) | `ApiException` (`status`, `type`, `title`, `detail`, `errors`, `correlationId`, `retryAfter()`) | `ApiError` (idem, `retryAfter`)                  | `ApiError` (idem, `retry_after`)                |
| Rede / tempo esgotado  | `NetworkException` / `TimeoutException`                                                         | `NetworkError` / `RequestTimeoutError`           | `NetworkError` / `RequestTimeoutError`          |
| Recusa local           | `InvalidRequestException`                                                                       | `InvalidRequestError`                            | `InvalidRequestError`                           |
| Paginação              | `Page::autoPagingIterator()`                                                                    | `for await (… of page)`                          | `Page.auto_paging_iter()`                       |
| Webhooks               | `Webhook\WebhookSignature::verify/constructEvent`                                               | `webhooks.verifySignature/constructEvent`        | `webhooks.verify_signature/construct_event`     |
| User-Agent             | `assinavelox-php/1.0.0 (api-v1; php/8.x)`                                                       | `assinavelox-node/1.0.0 (api-v1; node/v…)`       | `assinavelox-python/1.0.0 (api-v1; python/3.x)` |

Regras comuns:

- **`Idempotency-Key`**: nas rotas que a exigem, o SDK gera um UUID v4 quando o chamador não passa uma chave. Nas que só a aceitam, ela só vai se informada. Uma chave fora do formato da API (1 a 255 ASCII visíveis) é recusada **antes** de sair. Repetição automática não existe: quem repete depois de uma queda passa a própria chave.
- **Erros**: qualquer resposta fora de 2xx vira o erro da API. Se o corpo não é RFC 9457 (proxy com HTML), `type = about:blank` e `title = "Erro HTTP {status}"`. Clientes devem tratar um `type` desconhecido pelo `status` (`docs/fase-2/api-v1.md` §11).
- **Segurança do cliente**: o token não aparece em `repr`/`var_dump`/`console.log` nem em mensagens de erro (campo privado; `#[\SensitiveParameter]` no PHP 8.2+). Redirecionamentos **não** são seguidos, para o token não ir a outro host. Cabeçalhos com quebra de linha são recusados. Parâmetros de caminho são codificados. TLS com verificação (padrão de cada plataforma). SSRF não se aplica: o SDK roda no servidor do cliente e só fala com a `baseUrl` que ele mesmo configura.
- **Compatibilidade**: campos desconhecidos são ignorados na leitura e ficam disponíveis (`raw` no PHP/Python; o próprio objeto no Node).

## 7. Assinatura dos webhooks

`tools/sdkgen/webhook_vectors.php` gera `sdks/testdata/webhook-signature-vectors.json` com a classe **real** do servidor: 4 casos de `compute`, 2 de `header` e 36 de `verify`, cada `expected` vindo de `WebhookSignature::verify()`. Os três SDKs conferem todos. Para serem idênticos ao PHP, eles reproduzem detalhes que um reimplementador perderia:

- o timestamp aceita **uma** quebra de linha final (o `$` do `preg_match`), mas não duas; só dígitos ASCII, de 1 a 12;
- o HMAC usa o timestamp **inteiro** (`"01789142400"` assina `"1789142400."`);
- as partes do cabeçalho são aparadas com o conjunto do `trim()` do PHP (espaço, `\t`, `\n`, `\r`, `\0`, `\x0B`), e o espaço não separável **não** é aparado;
- `v1=` compara o valor inteiro depois do primeiro `=` (maiúsculas, `=` sobrando ou `;` como separador não conferem);
- |agora − timestamp| **igual** à tolerância ainda vale.

Se o algoritmo do servidor mudar, `WebhookVectorsTest` falha. Depois de regenerar os vetores, os testes dos SDKs mostram o que ajustar.

## 8. Testes

**Servidor falso** (`tools/sdkgen/fake_server.py`): encontra a operação por método e caminho e confere `Authorization: Bearer`, User-Agent no formato do SDK, `Accept`, `Idempotency-Key` (obrigatória → 400 `idempotency-key-missing`, igual à API), parâmetros de query conhecidos e tipados, e o corpo JSON validado contra o esquema, **recusando propriedades desconhecidas**. Para multipart, lê as partes byte a byte (SHA-256 do arquivo). Pedido fora do contrato → 400 `fake-contract-violation` com a lista. A resposta é montada pelo esquema e validada antes de sair. Cenários: tokens `fake-401`, `fake-429` (`Retry-After: 7`), `fake-502-html`, `fake-slow` (2 s); id `01FAKENOTFOUND000000000000` → 404; título `fake-422` → 422 com `errors.title`; paginação em duas páginas (`fake-cursor-2`). O cabeçalho `X-Fake-Trace` e `GET /__fake/requests?trace=` mostram ao teste o que o servidor recebeu.

**Por SDK** (testes de comportamento escritos à mão + um teste **gerado** por operação):

| SDK    | Como roda                                                      | Testes                                   |
| ------ | -------------------------------------------------------------- | ---------------------------------------- |
| PHP    | `php sdks/php/tests/run.php` (harness próprio, sem PHPUnit)    | 46 × 2 transportes (curl e streams) = 92 |
| Node   | `tsc -p sdks/node/tsconfig.json` + `node --test`               | 46                                       |
| Python | `python -m unittest discover -s tests -t .` (em `sdks/python`) | 50                                       |

Cobrem: cabeçalhos, chave de idempotência gerada/informada/opcional/inválida, 401/404/422/429/502, tempo esgotado do cliente e por chamada, conexão recusada, cancelamento (Node), paginação e query com lista (`status[]`), bytes do upload preservados, download, codificação de caminho, token fora de `repr`/`inspect`/`var_dump`, configuração inválida, o exemplo do README e os vetores de webhook. As contagens são das 24 operações atuais (as 21 da Fase 2 e as 3 das sessões embutidas); cada operação nova acrescenta um teste gerado por SDK.

**Pest** (`tests/Feature/Phase3/Sdk`):

| Arquivo              | Cobre                                                                                                                                                                                                                                      |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `OpenApiSpecTest`    | Especificação versionada = exportação atual; toda rota `/api/v1` na especificação e em `overrides.json`, com a idempotência do middleware; os buracos da §4.1 continuam corrigidos                                                         |
| `WebhookVectorsTest` | Arquivo de vetores = o que o PHP real produz; aceite e recusa cobertos                                                                                                                                                                     |
| `SdkSuitesTest`      | Impressão digital (`sdkgen.py check`); SDK PHP, Node e Python contra o servidor falso. Sem Node (ou sem `node_modules/typescript`), só o teste Node é **pulado**, com a mensagem; sem o venv do pdftool, todos são pulados com a instrução |
| `ApiContractTest`    | Respostas reais (fluxo de envelope completo, modelos, REST Hooks) validadas contra a especificação em modo estrito                                                                                                                         |

Nenhum teste usa rede externa. O servidor falso sobe numa porta livre de `127.0.0.1`, e o Pest derruba só o processo que ele mesmo abriu.

## 9. Contratos para quem vem depois

- **Mudou a API v1** (rota, campo, regra, Resource)? `OpenApiSpecTest` falha até alguém rodar `sdkgen.py all` e revisar o diff de `sdks/`. Rota nova precisa de entrada em `overrides.json` → `operations`.
- **Mudou `WebhookSignature`?** Rode `php tools/sdkgen/webhook_vectors.php --write` e ajuste os três SDKs até os testes passarem.
- **Nova resposta montada à mão num controller** (`new Response(json_encode(...))`)? O Scramble não a enxerga: descreva-a em `overrides.json` (`response`) e cubra-a em `ApiContractTest`.
- **Não use** `+` para juntar o detalhe num Resource da API: use `array_merge` (§4.1), senão o detalhe some da especificação.

## 10. Publicação (pendente do proprietário)

Nada foi publicado. Para publicar, o proprietário decide e fornece:

- conta e organização nos registros: Packagist (`assinavelox/sdk`), npm (`@assinavelox/sdk`), PyPI (`assinavelox`). A disponibilidade desses nomes **não foi verificada** (NÃO CONFIRMADO);
- licença dos SDKs (hoje "proprietário"; o `package.json` está `private` e o `pyproject.toml` tem o classificador `Private :: Do Not Upload`);
- repositório público de cada SDK (ou subdiretório com `git subtree`), CI que rode `sdkgen.py all` e os testes em PHP 8.1/8.2/8.3/8.4, Node 18/20/22 e Python 3.10–3.13 (aqui só foram executados PHP 8.3, Node 22 e Python 3.13; a compatibilidade com as versões mínimas foi respeitada no código, mas não executada);
- tokens de publicação guardados no CI (nunca no repositório) e, de preferência, publicação com proveniência (npm provenance, PyPI Trusted Publishing);
- o **congelamento formal** da API v1 (`docs/fase-2/api-v1.md` §11), pedido antes da publicação em marketplaces.

## 11. Pendências

- Execução nas versões mínimas (PHP 8.1, Node 18, Python 3.10), que dependem do CI (§10).
- Esquema RFC 9457 dos erros dentro da especificação (§4.3): exige uma extensão em `App\Services\Api\ApiDocumentation`, fora desta área.
- Operações novas de outras áreas da onda G entram nos SDKs só depois de `sdkgen.py all`. As sessões embutidas (G-EMBED) já entraram na geração final desta área (§4.2), mas o teste de contrato **não** as cobre, porque exige a flag `embedded_signing` e um participante em andamento. Cabe a G-EMBED acrescentá-las a `ApiContractTest`, se quiser.
