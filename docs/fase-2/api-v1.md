# Fase 2 — API REST v1

> Roadmap §2.15. Área D-API (onda D). Identificadores em inglês; prosa em português.
> Complementa `docs/autorizacao-e-isolamento.md` e `docs/fase-2/permissoes-e-times.md`, que continuam valendo.
> Flag `features.api_integrations` — **desligada por padrão** (roadmap T8). Com ela desligada, `/api/v1/*` responde 404 e nada muda no produto.

## 1. Resumo

- API JSON versionada por caminho (`/api/v1`), **sem sessão, sem cookie e sem CSRF**. Autenticação só por `Authorization: Bearer {token}`.
- Tokens do Sanctum (hash SHA-256, texto exibido **uma única vez**) estendidos com a organização, quem criou, prefixo de exibição, último uso e revogação.
- Cada token tem **abilities explícitas** de um conjunto fechado (§3) e age com as permissões **atuais** de quem o criou, na organização do token — nunca mais do que essa pessoa pode fazer na interface.
- As rotas reusam os serviços e as Policies da interface (sem regra de negócio duplicada) e aplicam a **mesma regra de visibilidade** de envelopes (`EnvelopeVisibility`).
- Erros em RFC 9457 (`application/problem+json`), idempotência por `Idempotency-Key`, limites por token e por organização com cabeçalhos `RateLimit-*`, registro mínimo de requisições e OpenAPI 3.1 (Scramble) com acesso restrito.

## 2. Autenticação e tokens

### 2.1 Formato e armazenamento

- Texto do token: `{id}|avk_{40 caracteres aleatórios}{crc32}` (o padrão do Sanctum, com o prefixo `avk_` de `SANCTUM_TOKEN_PREFIX` para ferramentas de varredura de segredos).
- O banco guarda só `sha256(segredo)` em `personal_access_tokens.token`; a busca é a do Sanctum (`id` + `hash_equals`). O texto sai **uma vez**, no retorno de `ApiTokenManager::issue()` (`NewApiToken::$plainTextToken`); nenhuma leitura posterior (recurso, trilha, log) o devolve, nem o hash.
- `token_prefix` guarda os 8 primeiros caracteres do segredo (`avk_Ab3d`) só para a tela identificar a chave.
- Tabela: migration `2026_09_11_140001` — colunas padrão do Sanctum + `ulid`, `organization_id` (FK, cascata), `created_by_user_id` (FK, cascata: o token nunca sobrevive ao criador), `token_prefix`, `last_used_ip`, `revoked_at`, `revoked_by_user_id`. Modelo `App\Models\ApiToken` (extensão de `Laravel\Sanctum\PersonalAccessToken`, com `BelongsToOrganization`).

### 2.2 Quem cria e revoga

- Só quem tem `manage_integrations` (membership **ativa**) na organização, e só com a flag ligada para ela (`App\Services\Api\ApiTokenManager`).
- Revogar marca `revoked_at`/`revoked_by_user_id` (o registro continua nas telas e nos logs). Idempotente.
- Limite de chaves ativas por organização (`assinavelox.api.tokens.max_active_per_organization`, padrão 50) e validade opcional de até `max_expiration_days` (365).
- Trilha (append-only, `envelope_id` nulo): `api_token.created` (payload: ULID, nome, abilities, validade) e `api_token.revoked` (ULID, nome). Nunca o texto, o hash ou o prefixo.

### 2.3 De onde vêm as permissões do token (decisão)

O roadmap deixou em aberto "permissões do autor ou papel `integration`". **Decisão desta onda:** o token age como uma _pessoa de integração_ cujas permissões são a **interseção** de:

1. as **abilities** do token (o que a chave foi autorizada a fazer), e
2. as permissões **atuais** do usuário criador na organização do token, avaliadas a cada chamada pelas mesmas Policies da interface.

Consequências, todas testadas:

- **O token nunca excede o criador.** Na emissão, ninguém concede uma ability cujas permissões não tem (`ApiAbility::requiredPermissions()`, anti-escalada). Na chamada, a rota confere a ability **e** se o criador ainda tem as permissões dela (`403 creator-lacks-permission`); depois, as Policies decidem sobre o recurso.
- **Rebaixar o criador rebaixa o token** na próxima requisição (ex.: administrador que vira Operador passa a ver só os próprios documentos pela API).
- Criador **suspenso, removido ou com a conta bloqueada**, organização excluída → o token deixa de autenticar (401).
- As mesmas barreiras da interface valem para o token (revisão adversarial da onda D): criador com **e-mail não verificado** (ex.: trocou o e-mail e ainda não confirmou — a interface exige `verified`) ou **sem 2FA numa organização que exige 2FA** (`org.2fa`) → 401, até resolver. Nessa mesma situação a emissão de chave é recusada (`ApiTokenManager::issue`).
- A trilha registra o **criador** como autor das ações feitas pelo token (ator `user`), e `envelope.created` pela API leva `{"channel": "api"}`.

Por que não um papel de sistema `integration` separado agora: exigiria mudar o catálogo de papéis e a matriz de permissões (área B-PERM) e, sem a interseção com o criador, permitiria que um token sobrevivesse com mais poder do que a pessoa que o criou. Com a regra acima, o "papel de integração" é configurável por chave (abilities) e limitado por construção. Se um dia houver usuário técnico dedicado, ele entra como membership com função personalizada, e esta regra continua valendo.

### 2.4 Respostas de autenticação

| Situação                                                                                                                                                      | Resposta                                                                          |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| Flag global desligada                                                                                                                                         | `404 not-found` (antes de olhar a credencial)                                     |
| Sem token, token incorreto, expirado ou revogado, criador sem acesso (suspenso, removido, bloqueado, e-mail não verificado, sem 2FA exigido pela organização) | `401 unauthenticated` + `WWW-Authenticate: Bearer` (a resposta não diz qual caso) |
| Flag desligada no plano da organização do token                                                                                                               | `404 not-found`                                                                   |
| 30 falhas de autenticação por minuto na mesma origem                                                                                                          | `429 rate-limited`                                                                |

`last_used_at`/`last_used_ip` são atualizados no máximo uma vez por minuto. A sessão web **não** autentica a API (`config/sanctum.php`: `stateful` e `guard` vazios — sem o "token transiente" do Sanctum).

## 3. Abilities (`App\Enums\ApiAbility`)

Conjunto fechado; `*` não existe. Valores estáveis.

| Ability           | Permite                                                                                                                                                                                              | Permissão que o criador precisa ter para concedê-la |
| ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| `envelopes:read`  | Listar e detalhar documentos, campos, eventos da trilha e o registro de verificação                                                                                                                  | — (a visibilidade do criador limita o que aparece)  |
| `envelopes:write` | Criar rascunho, enviar arquivo, definir participantes e campos                                                                                                                                       | `create_envelopes`                                  |
| `envelopes:send`  | Enviar para assinatura e cancelar                                                                                                                                                                    | `send_envelopes`                                    |
| `documents:read`  | Baixar arquivo original, final e página de evidências                                                                                                                                                | —                                                   |
| `recipients:read` | Situação e **dados pessoais** dos participantes (nome, e-mail, celular mascarado, método de autenticação, motivo da recusa) — em qualquer rota que devolva participantes e no `actor.name` da trilha | —                                                   |
| `templates:read`  | Listar modelos, ver variáveis e papéis                                                                                                                                                               | —                                                   |
| `templates:use`   | Gerar documento a partir de modelo                                                                                                                                                                   | `create_envelopes`                                  |
| `webhooks:manage` | Reservada às assinaturas de webhook (§2.16/§2.17, outras áreas)                                                                                                                                      | `manage_integrations`                               |

## 4. Convenções

- JSON UTF-8. Um recurso: `{"data": {...}}`. Lista: `{"data": [...], "links": {...}, "meta": {...}}`.
- Cada objeto traz `object` (`envelope`, `document`, `recipient`, `field`, `event`, `template`).
- Identificadores públicos **ULID** (26 caracteres). Nunca sai id interno (`organization_id`, `created_by_user_id`, `number`…).
- Datas em **ISO-8601 UTC** com `Z` (`2026-09-11T14:03:22Z`); `null` quando não houver.
- Dinheiro (quando aparecer): `{"amount_cents": 15000, "currency": "BRL"}` (`ApiFormat::money`). Nenhum recurso da v1 expõe valores hoje.
- Filtros de data aceitam ISO-8601; sem fuso, são interpretados em UTC.

## 5. Rotas

Prefixo `/api/v1`. "Idem." = exige `Idempotency-Key`; "Idem. opc." = aceita.

| Método | Caminho                              | Nome                                 | Ability           | Observação                                                                                                                                        |
| ------ | ------------------------------------ | ------------------------------------ | ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/envelopes`                         | `api.v1.envelopes.index`             | `envelopes:read`  | Filtros `status` (um ou vários, vírgula), `folder` (ULID), `q` (título ou `AV-00012`), `created_after`, `created_before`, `updated_after`; cursor |
| POST   | `/envelopes`                         | `api.v1.envelopes.store`             | `envelopes:write` | Idem. Corpo: `title`*, `message`, `signing_order`, `expires_in_days`, `folder_id`, `send_copy_to_all`. 201 + `Location`                           |
| GET    | `/envelopes/{envelope}`              | `api.v1.envelopes.show`              | `envelopes:read`  | Detalhe com arquivos, participantes e links                                                                                                       |
| POST   | `/envelopes/{envelope}/documents`    | `api.v1.envelopes.documents.store`   | `envelopes:write` | Idem. **Só multipart** (`file`); sem upload por URL (evita SSRF). 201                                                                             |
| GET    | `/envelopes/{envelope}/recipients`   | `api.v1.envelopes.recipients.index`  | `recipients:read` |                                                                                                                                                   |
| PUT    | `/envelopes/{envelope}/recipients`   | `api.v1.envelopes.recipients.sync`   | `envelopes:write` | Idem. opc. Substitui a lista (contrato do passo 2 do wizard: `signing_order`, `recipients[]` com `id` para manter)                                |
| GET    | `/envelopes/{envelope}/fields`       | `api.v1.envelopes.fields.index`      | `envelopes:read`  |                                                                                                                                                   |
| PUT    | `/envelopes/{envelope}/fields`       | `api.v1.envelopes.fields.sync`       | `envelopes:write` | Idem. opc. Contrato do passo 3 (`fields[]` em frações [0,1], `recipient_id`, `document_id`)                                                       |
| POST   | `/envelopes/{envelope}/send`         | `api.v1.envelopes.send`              | `envelopes:send`  | Idem. `meta.invitations_sent`                                                                                                                     |
| POST   | `/envelopes/{envelope}/cancel`       | `api.v1.envelopes.cancel`            | `envelopes:send`  | Idem. opc. `reason`; `meta.recipients_notified`                                                                                                   |
| GET    | `/envelopes/{envelope}/files/{type}` | `api.v1.envelopes.files.show`        | `documents:read`  | `type` ∈ `original`, `signed`, `evidence`; `?document={ulid}`. Stream do disco privado; `envelope.downloaded` na trilha                           |
| GET    | `/envelopes/{envelope}/events`       | `api.v1.envelopes.events.index`      | `envelopes:read`  | Cursor, ordem cronológica                                                                                                                         |
| GET    | `/envelopes/{envelope}/verification` | `api.v1.envelopes.verification.show` | `envelopes:read`  | Exatamente a projeção da verificação pública                                                                                                      |
| GET    | `/templates`                         | `api.v1.templates.index`             | `templates:read`  | Só com a flag `templates`                                                                                                                         |
| GET    | `/templates/{template}`              | `api.v1.templates.show`              | `templates:read`  | Variáveis e papéis                                                                                                                                |
| POST   | `/templates/{template}/envelopes`    | `api.v1.templates.envelopes.store`   | `templates:use`   | Idem. `title`, `values{chave: valor}`, `participants{ulid_do_papel: {name, email}}`. 201                                                          |

Serviços reutilizados: `EnvelopeVisibility`, `EnvelopePolicy`, `TemplatePolicy`, `DocumentIntake` (+`UploadInspector`), `RecipientSync`, `FieldSync`, `SendEnvelope`, `CancelEnvelope`, `EnvelopeDownloadController`, `CreateEnvelopeFromTemplate`, `PublicVerification`. As Form Requests de participantes e campos **estendem** as da interface. O rascunho (`App\Services\Api\EnvelopeDrafts`) aplica os mesmos padrões da organização de `EnvelopeController::create` e grava `envelope.created`. A diferença é que cada `Idempotency-Key` nova cria um documento novo; na interface, um rascunho intocado é reaproveitado.

### 5.1 Campos dos recursos

- **Envelope**: `id`, `object`, `display_code`, `title`, `status`, `status_label`, `signing_order`, `folder{id,name}`, `created_by{name}`, `recipients_count`, `signed_count`, `viewers_count`, `verification_code` (só depois do envio), `signature_status` (`null` até o registro do arquivo final; depois `none`, `company_a1`, `participants_a1` ou `mixed`), `signature_status_label`, `created_at`, `updated_at`, `sent_at`, `expires_at`, `completed_at`, `refused_at`, `expired_at`, `canceled_at`. No detalhe, também `message`, `expiration_days`, `send_copy_to_all`, `cancel_reason`, `documents[]`, `recipients[]` e `links`.
- **Document**: `id`, `position`, `name`, `original_filename`, `source_type`, `processing_status`, `processing_label`, `ready`, `pages`, `size_bytes`, `sha256{original,sent,final}`, `failure{code,message}`, `created_at`.
- **Recipient**: `id`, `name`, `email`, `phone_masked`, `role` (`signer|witness|approver|viewer`), `role_label`, `label` (papel livre), `order`, `status`, `status_label`, `auth_method`, `notified_at`, `notifications_count`, `signed_at`, `refused_at`, `refusal_reason`. **Sem `recipients:read`** (detalhe do envelope, `PUT …/recipients`, `send`, `cancel`, geração por modelo), a forma é a mesma, mas `name`, `email`, `phone_masked`, `auth_method` e `refusal_reason` saem `null` — o `id`, o papel e a situação continuam, para o cliente mapear campos. Na trilha (`Event`), `actor.name` de um participante também sai `null` sem essa ability (o `recipient_id` continua).
- **Field**: `id`, `recipient_id`, `document_id`, `type`, `page`, `x`, `y`, `w`, `h`, `required`, `label`, `auto`. O valor preenchido **não** sai.
- **Event**: `id`, `type` (catálogo `AuditEventType`), `label`, `kind`, `actor{type,name}`, `recipient_id`, `occurred_at`. Sem payload, IP ou user-agent.
- **Template**: `id`, `name`, `description`, `category`, `source_type`, `status`, `usable`, `version`, `updated_at`; no detalhe, `variables[]` e `roles[]`.

### 5.2 Semântica honesta (roadmap T1)

`status_label` de um envelope concluído é **"Assinado" só quando houve assinatura criptográfica**; sem ela é "Concluído" (`SignatureNarrative::completedLabel`). `signature_status_label` usa os rótulos de `SignatureStatus` (ex.: "Aceite eletrônico com evidências (sem assinatura criptográfica)"). A verificação devolve a mesma projeção da página pública, com os mesmos rótulos. Nenhum texto da API usa o vocabulário proibido (teste).

## 6. Erros — RFC 9457

Toda resposta de erro em `/api/*` sai como `application/problem+json`, com a mesma forma (`App\Services\Api\ApiProblem`, registrado em `bootstrap/app.php`):

```json
{
    "type": "urn:assinavelox:problem:validation-failed",
    "title": "Dados inválidos",
    "status": 422,
    "detail": "Um ou mais campos não passaram na validação.",
    "instance": "/api/v1/envelopes",
    "errors": { "title": ["O campo título deve ter pelo menos 3 caracteres."] },
    "correlation_id": "01K…"
}
```

- `type` é uma URN estável e **não resolvível**: trate-a como string.
- `detail` só aparece quando o texto foi escrito pela aplicação (PT-BR). Mensagens do framework, que podem citar classe ou tabela, nunca aparecem.
- `correlation_id` é igual ao cabeçalho `X-Correlation-Id`.
- Um 500 nunca traz stack, mensagem da exceção ou dado nenhum, nem com `APP_DEBUG` ligado.

| Status | `type` (sufixo)                                                                                                                                                                                       | Quando                                                                                        |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| 400    | `idempotency-key-missing`, `idempotency-key-invalid`, `bad-request`                                                                                                                                   | Cabeçalho ausente ou inválido                                                                 |
| 401    | `unauthenticated`                                                                                                                                                                                     | §2.4                                                                                          |
| 403    | `missing-ability` (+`required_ability`), `creator-lacks-permission`, `forbidden`                                                                                                                      | Ability ausente; criador sem a permissão; Policy nega uma ação num recurso **visível**        |
| 404    | `not-found`, `verification-unavailable`                                                                                                                                                               | Recurso inexistente, de **outra organização** ou **invisível** para o criador; flag desligada |
| 405    | `method-not-allowed`                                                                                                                                                                                  | + cabeçalho `Allow`                                                                           |
| 409    | `invalid-status`, `already-sent`, `envelope-not-ready` (+`issues`), `sending-blocked` (+`code`), `template-unavailable`, `idempotency-key-reused`, `idempotency-request-in-progress` (+`Retry-After`) | Conflito com o estado                                                                         |
| 413    | `payload-too-large`                                                                                                                                                                                   | Corpo acima do limite do servidor                                                             |
| 422    | `validation-failed` (+`errors`), `upload-rejected` (+`code`, `errors.file`)                                                                                                                           | Formato ou conteúdo                                                                           |
| 429    | `rate-limited`                                                                                                                                                                                        | + `Retry-After` e `RateLimit-*`                                                               |
| 500    | `internal-error`                                                                                                                                                                                      |                                                                                               |
| 503    | `dispatch-failed`                                                                                                                                                                                     | Convites não emitidos; nada foi cobrado do plano (+`Retry-After`)                             |

**404, 403 e 409.** A API não confirma a existência do que o token não enxerga. Por isso, envelope de outra organização e envelope da mesma organização fora da visibilidade do criador dão o mesmo **404**. **403** vale para uma ação negada sobre um recurso visível, e **409** para um recurso visível num status incompatível.

## 7. Idempotência (`Idempotency-Key`)

- **Obrigatória** em `POST /envelopes`, `POST …/documents`, `POST …/send` e `POST /templates/{template}/envelopes`. Aceita, sem obrigação, em `PUT …/recipients`, `PUT …/fields` e `POST …/cancel`.
- Valor: 1–255 caracteres ASCII visíveis (recomendado: UUID).
- Escopo **por token** (tabela `api_idempotency_keys`, UNIQUE `(token, chave)`), com a **impressão digital** do pedido: método, rota, parâmetros de rota, corpo canônico (a ordem dos campos não importa) e SHA-256 de cada arquivo.
- Repetir com o mesmo pedido devolve a **mesma resposta** (status, corpo, `Location`) com `Idempotent-Replayed: true`, por **24 h** (`ttl_hours`).
- A repetição **não passa por cima da autorização atual**: antes de devolver a resposta guardada, o documento da rota (`{envelope}`) e o documento que a resposta descreve precisam continuar visíveis para o criador, e o modelo (`{template}`) continua sujeito à `TemplatePolicy`. Criador rebaixado ou fora da pasta → **404**, como qualquer recurso invisível (§6). A ability e a permissão do criador já são conferidas antes, pela rota.
- Mesma chave com outro pedido (corpo, arquivo, rota ou recurso diferentes) → `409 idempotency-key-reused`.
- **Concorrência**: o INSERT com UNIQUE decide; só uma requisição reserva. A outra recebe `409 idempotency-request-in-progress` com `Retry-After: 1`. Se a reserva ficar abandonada (processo morto), ela vence em `lock_seconds` (60 s) e a próxima tentativa assume.
- Só respostas de **sucesso** ficam guardadas (JSON até 1 MB). Um erro (4xx/5xx) libera a chave, para o cliente repetir o pedido corrigido.
- Chaves vencidas são apagadas na próxima reserva do mesmo token.

## 8. Paginação por cursor

`GET /envelopes`, `/envelopes/{envelope}/events` e `/templates`:

- `per_page` de 1 a 100 (padrão 25); `cursor` opaco.
- Resposta: `links.next`/`links.prev` (URLs completas, com os filtros) e `meta.next_cursor`/`meta.prev_cursor`/`meta.per_page`/`meta.path`.
- Ordem: envelopes e modelos do mais novo para o mais antigo (por ULID); eventos em ordem cronológica.
- O cursor carrega só o ULID, nunca id interno. Um cursor adulterado não amplia o escopo: a consulta continua restrita à visibilidade do criador.

## 9. Limites

| Limite                                        | Padrão                                                                        | Configuração                             |
| --------------------------------------------- | ----------------------------------------------------------------------------- | ---------------------------------------- |
| Requisições por token                         | 120/min                                                                       | `ASSINAVELOX_API_RATE_PER_TOKEN`         |
| Requisições por organização (todos os tokens) | 600/min                                                                       | `ASSINAVELOX_API_RATE_PER_ORGANIZATION`  |
| Falhas de autenticação por IP                 | 30/min                                                                        | `ASSINAVELOX_API_FAILED_AUTH_PER_MINUTE` |
| Upload                                        | igual à interface (`ASSINAVELOX_MAX_UPLOAD_MB`, 25 MB) + inspeção do conteúdo | `assinavelox.upload.*`                   |
| Participantes / campos por documento          | 20 / 200                                                                      | regras da interface                      |

Cabeçalhos em toda resposta autenticada, conforme o rascunho IETF _RateLimit header fields_:

- `RateLimit-Limit`: o teto do balde mais apertado.
- `RateLimit-Remaining`: o que ainda resta nesse balde.
- `RateLimit-Reset`: segundos até o balde renovar.
- `RateLimit-Policy`: todos os baldes, no formato `120;w=60, 600;w=60`.

No 429 também vai `Retry-After`.

### 9.1 Registro de requisições (`api_request_logs`)

Cada requisição **autenticada** grava uma linha só com metadados:

- organização e token;
- método;
- **nome** e **padrão** da rota (ex.: `api/v1/envelopes/{envelope}`, nunca a URL com ULIDs ou query);
- status, duração em ms e `correlation_id`;
- se foi repetição idempotente.

Não se grava corpo, cabeçalho, IP ou dado pessoal. Requisições sem token válido não são registradas: sem organização, não há a quem mostrar o registro.

**Retenção curta**: `ASSINAVELOX_API_REQUEST_LOG_RETENTION_DAYS` (30). `ApiRequestLog` é `MassPrunable` e, além disso, 1 em cada 100 gravações apaga um lote de registros vencidos. Assim a retenção vale mesmo sem o agendador.

## 10. OpenAPI (Scramble)

- `/docs/api` (interface Stoplight Elements) e `/docs/api.json` (OpenAPI 3.1), gerados a partir das rotas, das Form Requests e dos Resources.
- Documenta **só** `/api/v1` (`config/scramble.php` → `api_path`); servidor `…/api/v1`; esquema `bearerAuth` (HTTP Bearer) aplicado globalmente (`ApiDocumentation`); título, versão (`API_VERSION`, 1.0.0), descrição e exemplo `curl`.
- **Acesso** (interface e JSON) pelo gate `viewApiDocs`: usuário autenticado na **sessão web**, com membership ativa na organização corrente, `manage_integrations` e a flag ligada. Convidado, usuário sem a permissão ou organização sem a flag: **403**, em qualquer ambiente. `App\Http\Middleware\ApiDocsAccess` substitui o `RestrictedDocsAccess` do Scramble, que libera tudo em `local`. Um token de API **não** abre a documentação.
- O "Try it" nunca envia o cookie da sessão (`tryItCredentialsPolicy: omit`).

## 11. Versionamento e política de mudanças

- A versão fica no caminho (`/api/v1`). Dentro da v1 só entram mudanças **compatíveis**: campos novos em respostas, rotas novas, filtros opcionais novos, `type` de erro novo e abilities novas.
- **Não** entram na v1: renomear ou remover campo, mudar tipo ou semântica de campo, tornar obrigatório o que era opcional, mudar o significado de uma ability ou de um `type` de erro. Mudanças assim criam `/api/v2`, e a v1 convive com ela.
- **Depreciação**: a rota ou versão depreciada passa a responder com os cabeçalhos `Deprecation` e `Sunset` (RFC 8594) e com uma nota na documentação, pelo menos **6 meses** antes de ser removida. Não há rota depreciada hoje.
- **Clientes** devem ignorar campos desconhecidos e tratar `type` de erro desconhecido pelo `status`.
- **Contrato ainda não congelado** (roadmap §2.15): multi-documento e papéis (§2.3/§2.4) já estão refletidos nos recursos (`documents[]`, `role`), mas a publicação em marketplaces (§2.17) só ocorre depois do congelamento formal.

## 12. Privacidade

Nunca sai por nenhuma rota:

- CPF (completo ou não) e o valor de qualquer campo preenchido;
- celular completo (só `phone_masked`);
- IP e user-agent;
- código de verificação por e-mail/SMS, PIN ou estado do PIN;
- token ou link de acesso do participante;
- imagem de assinatura, captura de identidade, `fields_snapshot` e texto de consentimento;
- payload da trilha, `finalization_key`, caminho no disco e ids internos.

A verificação devolve **exatamente** a projeção pública (`PublicVerification::result`) e só quando a pública também devolveria: rascunho, não enviado ou registro revogado dão 404. As respostas idempotentes guardadas (24 h) contêm só o que a própria resposta já continha.

## 13. Configuração

| Chave (`.env`)                                                                                    | Padrão         | Efeito                                                                           |
| ------------------------------------------------------------------------------------------------- | -------------- | -------------------------------------------------------------------------------- |
| `ASSINAVELOX_FEATURE_API_INTEGRATIONS`                                                            | `false`        | Interruptor global; o plano também precisa de `features.api_integrations = true` |
| `SANCTUM_TOKEN_PREFIX`                                                                            | `avk_`         | Prefixo do segredo                                                               |
| `ASSINAVELOX_API_RATE_PER_TOKEN` / `_PER_ORGANIZATION` / `ASSINAVELOX_API_FAILED_AUTH_PER_MINUTE` | 120 / 600 / 30 | §9                                                                               |
| `ASSINAVELOX_API_IDEMPOTENCY_TTL_HOURS` / `_LOCK_SECONDS`                                         | 24 / 60        | §7                                                                               |
| `ASSINAVELOX_API_REQUEST_LOG_RETENTION_DAYS`                                                      | 30             | §9.1                                                                             |
| `ASSINAVELOX_API_MAX_ACTIVE_TOKENS` / `ASSINAVELOX_API_TOKEN_MAX_EXPIRATION_DAYS`                 | 50 / 365       | §2.2                                                                             |
| `ASSINAVELOX_API_PAGE_SIZE` / `_MAX`                                                              | 25 / 100       | §8                                                                               |
| `API_VERSION`                                                                                     | 1.0.0          | Versão exibida na documentação                                                   |

## 14. Contrato para as telas (Integrações → Chaves e Logs)

Esta área não cria telas nem rotas web. O agente das telas usa:

- **Criar**: `POST` numa rota do grupo `app` com `App\Http\Requests\Api\StoreApiTokenRequest` (autoriza `manage_integrations`; valida `name`, `abilities[]` do catálogo, `expires_at` opcional). Em seguida chama `app(ApiTokenManager::class)->issue($membership, $request->validated('name'), $request->abilities(), $request->expiresAt())`, que devolve `NewApiToken`. `plainTextToken` deve ser mostrado **uma única vez** (por exemplo, numa prop da resposta imediata), nunca gravado em sessão persistente ou log. `ValidationException` e `AuthorizationException` saem com as mensagens em PT-BR.
- **Opções do formulário**: `ApiTokenManager::grantable($membership)` devolve só as abilities que a pessoa pode conceder, cada uma com `label()` e `description()`.
- **Listar**: `ApiTokenManager::forOrganization($organization)->with('creator')` + `App\Http\Resources\Api\ApiTokenResource`. Campos: `id`, `name`, `prefix` (`avk_Ab3d…`), `abilities[{value,label}]`, `state` (`active|expired|revoked`), `created_by`, `created_at`, `last_used_at`, `last_used_ip`, `expires_at`, `revoked_at`.
- **Revogar**: `app(ApiTokenManager::class)->revoke($token, $membership)`. O token vem por ULID e o binding é escopado à organização corrente, logo o de outra organização dá 404.
- **Logs**: `ApiRequestLog::query()->with('token')->latest('occurred_at')` (já escopado à organização corrente) + `App\Http\Resources\Api\ApiRequestLogResource`. Campos: `id`, `method`, `route`, `path`, `status`, `duration_ms`, `correlation_id`, `idempotent_replay`, `token{id,name}`, `occurred_at`. Filtros sugeridos: token, status e período.
- **Flag**: `App\Services\Api\ApiFeature::enabled($organization)`.
- **Documentação**: o link para `/docs/api` fica na aba "Documentação" (acesso pelo gate `viewApiDocs`).

## 15. Contrato para REST Hooks e webhooks (§2.16/§2.17)

- Rotas novas entram em `routes/api.php`, dentro do grupo `v1` (prefixo de nome `api.v1.`), e herdam flag, token, limites e binding escopado.
- Cada rota declara `api.ability:{ability}`. Assinaturas dinâmicas usam `webhooks:manage`, que já exige `manage_integrations` do criador. `POST` de criação usa `api.idempotent`.
- Contexto: `ApiContext::token($request)` (ex.: para vincular a assinatura ao token e desativá-la quando o token for revogado), `ApiContext::membership()` e `ApiContext::organization()`.
- Erros: lance `App\Services\Api\Exceptions\ApiProblemException` (status, `slug`, título, detalhe, extensões) ou as exceções usuais do Laravel. O renderizador faz o resto.
- Recurso de outra organização dá 404 (modelo com `BelongsToOrganization` + ULID).
- Os testes de isolamento (`ApiIsolationTest`) e de abilities (`ApiAbilitiesTest`) percorrem **todas** as rotas `api.v1.*`. Rota nova sem `api.ability` quebra o teste.
- Payloads de webhook devem seguir §12 (nada que a API não mostraria).

## 16. Integrações pendentes fora da área D-API

1. **`HandleInertiaRequests::features()`**: `'api_integrations' => false` deve passar a `ApiFeature::enabled($organization)`. Com a flag desligada o valor continua `false`, e `SharedPropsTest` não muda.
2. **Agendamento da retenção dos logs**: incluir `Schedule::command('model:prune', ['--model' => [App\Models\ApiRequestLog::class]])->daily()` em `routes/console.php`. Até lá vale a limpeza oportunista (§9.1).
3. **Registro de atividades da organização** (`AdminEventCatalog`/`OrganizationAuditLog`): incluir `api_token.created` e `api_token.revoked` na categoria de integrações, se a tela os deve listar.
4. **Rotas placeholder** `integrations.keys` e `integrations.logs` (302 na Fase 1): trocar por telas reais, com `org.role` → permissão `manage_integrations`.

## 17. Testes (`tests/Feature/Phase2/Api`)

| Arquivo                  | Cobre                                                                                                                                                                                                             |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ApiFeatureFlagTest`     | Flag desligada → 404 com e sem token; global × plano; sem emissão                                                                                                                                                 |
| `ApiAuthenticationTest`  | 401 sem token, token errado, revogado, expirado; criador suspenso, removido ou bloqueado; hash SHA-256 e texto exibido uma vez; `last_used_*`; a sessão web não autentica                                         |
| `ApiIsolationTest`       | Token de outra organização → 404 em **todas** as rotas com recurso (lidas do roteador), sem alterar nada; listagens; filtro por pasta de outra organização                                                        |
| `ApiAbilitiesTest`       | Toda rota declara ability; sem a ability → 403 em cada rota; anti-escalada na emissão; criador rebaixado; `creator-lacks-permission`                                                                              |
| `ApiVisibilityTest`      | Operador vê pela API exatamente o que vê na interface; invisível → 404 em todas as rotas                                                                                                                          |
| `ApiIdempotencyTest`     | 400 sem chave; repetição com a mesma resposta; corpo diferente e outra rota → 409; tokens independentes; corrida e reserva abandonada; erro não guardado; 24 h; envio idempotente sem reenviar nem cobrar de novo |
| `ApiProblemFormatTest`   | Mesma forma em 400/401/403/404/405/409/422/429/500; o 500 não vaza nada, nem com debug ligado                                                                                                                     |
| `ApiRateLimitTest`       | Cabeçalhos `RateLimit-*`; limite por token e por organização; falhas de autenticação por origem                                                                                                                   |
| `ApiPrivacyTest`         | Nenhum dado proibido; rótulos honestos; verificação idêntica à pública; vocabulário                                                                                                                               |
| `ApiUploadTest`          | Conteúdo validado como na interface (mesma mensagem); SVG; tamanho; 409 depois do envio; PDF real idempotente                                                                                                     |
| `ApiEnvelopeFlowTest`    | Fluxo completo (com o pdftool) e fluxo sem o pdftool; cursor e filtros; formato                                                                                                                                   |
| `ApiTemplatesTest`       | Listar/detalhar; flag `templates`; 422 por campo; arquivado → 409; geração idempotente                                                                                                                            |
| `ApiDocumentationTest`   | Documentação restrita (convidado, operador, flag desligada, token de API → 403); JSON só com v1 e Bearer                                                                                                          |
| `ApiTokenManagementTest` | Emitir e revogar; limite de chaves ativas; validade; `StoreApiTokenRequest`; `ApiTokenResource`                                                                                                                   |
| `ApiRequestLogTest`      | Colunas só de metadados; registro por requisição; erros; repetição marcada; retenção                                                                                                                              |

Ajustes em testes existentes, justificados: `tests/Unit/Models/EnumCatalogTest` acrescenta os 2 eventos `api_token.*` à contagem, como pede `permissoes-e-times.md` §7.1 item 5 para eventos novos. `tests/Feature/Smoke/AllGetRoutesTest` exclui o prefixo `api/`: as rotas da API não são páginas de sessão por papel, respondem 404 com a flag desligada e têm matriz própria aqui — é a mesma razão já registrada ali para `docs/api`.
