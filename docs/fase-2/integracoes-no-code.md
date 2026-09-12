# Fase 2 — Integrações no-code (n8n, Zapier, Make) e telas de API e integrações

> Roadmap §2.17 (REST Hooks) e as telas de §2.15/§2.16. Onda D, área D-PLAT.
> Depende da API v1 ([api-v1.md](api-v1.md)) e do motor de webhooks ([webhooks.md](webhooks.md)).
> Nada aqui duplica esses dois: a assinatura dinâmica **é** um endpoint do motor, criado pela API.

## 1. Resumo

| O quê                                                                                               | Classe (viabilidade §2) | Estado                                                                                            |
| --------------------------------------------------------------------------------------------------- | ----------------------- | ------------------------------------------------------------------------------------------------- |
| Endpoints REST Hooks do nosso lado (`/api/v1/webhook-subscriptions`, catálogo e payload de exemplo) | **A**                   | Pronto, atrás da flag `rest_hooks` (desligada)                                                    |
| Telas Documentação, Chaves, Webhooks e Logs                                                         | **A**                   | Prontas, atrás de `api_integrations` / `outbound_webhooks` (desligadas)                           |
| Receitas com os módulos genéricos de HTTP e webhook do n8n, do Zapier e do Make                     | **A** (do nosso lado)   | Roteiro abaixo; **não executado de ponta a ponta em conta real**                                  |
| Apps **publicados** nos marketplaces (Zapier, Make) ou nó comunitário do n8n                        | **B**                   | **Não existe.** Exige conta de desenvolvedor, revisão do fornecedor e o contrato da API congelado |

**Custos:** n8n, Zapier e Make são produtos de terceiros, com planos e limites próprios. Alguns módulos usados aqui (webhook, HTTP, apps privados) podem exigir plano pago na plataforma. A AssinaVelox **não** promete gratuidade nessas plataformas; confira o plano antes de montar o fluxo.

**Nomes de telas e módulos das plataformas:** os nomes citados abaixo ("HTTP Request", "Webhook", "Webhooks by Zapier", "Custom webhook"…) são os usuais, mas **não foram verificados nesta rodada** (viabilidade §2.17: requisitos, CLI e versões NÃO CONFIRMADOS). Se a interface da plataforma estiver diferente, o que vale é o contrato HTTP da §3.

## 2. Flags

| Flag                | Tipo                             | Liga                                                       | Com ela desligada                                                      |
| ------------------- | -------------------------------- | ---------------------------------------------------------- | ---------------------------------------------------------------------- |
| `rest_hooks`        | organização (global **e** plano) | rotas de REST Hooks e a seção "REST Hooks" da Documentação | `404 not-found` em todas as rotas de REST Hooks                        |
| `api_integrations`  | organização                      | API v1, abas Documentação (API), Chaves e Logs             | placeholder da Fase 1                                                  |
| `outbound_webhooks` | organização                      | motor de webhooks e aba Webhooks                           | placeholder da Fase 1 (se `api_integrations` também estiver desligada) |

`rest_hooks` só vale com `api_integrations` **e** `outbound_webhooks` também ligadas (`App\Services\RestHooks\RestHooksFeature`): sem API não há token; sem o motor nada seria entregue, e aceitar uma assinatura que nunca recebe evento seria enganoso.

Com as três desligadas (o padrão) a interface é **exatamente** a da Fase 1: `integrations.index` renderiza `integrations/index` com as mesmas props, e `integrations.keys`/`integrations.logs` redirecionam (302).

## 3. Contrato REST Hooks (`/api/v1`)

Todas as rotas passam pela pilha da API v1: flag, token Bearer, limite por token e por organização, e registro em `api_request_logs`. Todas exigem a ability **`webhooks:manage`**, e quem criou o token precisa ter **`manage_integrations`** agora; sem ela a resposta é `403 creator-lacks-permission`. A `WebhookEndpointPolicy` é aplicada com o criador do token.

| Método | Caminho                          | Nome                                   | Resposta                                                                              |
| ------ | -------------------------------- | -------------------------------------- | ------------------------------------------------------------------------------------- |
| GET    | `/webhook-events`                | `api.v1.webhook_events.index`          | `{data: [{object, value, label, description}]}` — eventos assináveis                  |
| GET    | `/webhook-events/{event}/sample` | `api.v1.webhook_events.sample`         | `{data: [payload], meta: {sample: true}}` — exemplo **sem dados reais**               |
| GET    | `/webhook-subscriptions`         | `api.v1.webhook_subscriptions.index`   | assinaturas **deste token**, sem segredo                                              |
| POST   | `/webhook-subscriptions`         | `api.v1.webhook_subscriptions.store`   | `201` com o segredo (única vez) · `200` se a mesma assinatura já existe (sem segredo) |
| DELETE | `/webhook-subscriptions/{id}`    | `api.v1.webhook_subscriptions.destroy` | `204` · `404` se não for deste token                                                  |

### 3.1 Assinar

```http
POST /api/v1/webhook-subscriptions
Authorization: Bearer <chave>
Content-Type: application/json
Idempotency-Key: <valor único por assinatura>

{"target_url": "https://receptor.exemplo.com/hooks/123", "event": "envelope.completed"}
```

- `target_url` (obrigatório): URL **HTTPS** pública, validada pela proteção de rede do motor (`OutboundUrlGuard`): sem IP literal em qualquer grafia, sem rede interna, sem metadados de nuvem, sem redirecionamento. A validação vale no cadastro e em cada entrega.
- `event` (um valor do catálogo, ou `*` para todos os eventos, inclusive os futuros) **ou** `events[]`.

Resposta `201`, com `Cache-Control: no-store` e `Location`:

```json
{
    "data": {
        "id": "01K7Q3N6T2S8R4P1M0L9K7J5H3",
        "object": "webhook_subscription",
        "target_url": "https://receptor.exemplo.com/hooks/123",
        "events": ["envelope.completed"],
        "status": "active",
        "status_label": "Ativa",
        "paused_reason": null,
        "secret_hint": "…b7e2",
        "signature_header": "X-AssinaVelox-Signature",
        "created_at": "2026-09-11T14:03:22Z",
        "secret": "whsec_…"
    }
}
```

**Idempotência e o segredo (decisão).** A rota declara `api.idempotent`, então o cabeçalho é obrigatório, e duas requisições simultâneas com a mesma chave resultam em `409 idempotency-request-in-progress`. Só que o armazenamento de `Idempotency-Key` (`api_idempotency_keys`) guarda por 24 h o corpo das respostas JSON de sucesso, e o segredo não pode ficar gravado em claro ([webhooks.md](webhooks.md) §1: só cifrado). Por isso a resposta de criação sai como resposta HTTP simples (não `JsonResponse`), e o armazenamento libera a chave em vez de gravar o corpo. A repetição segura é resolvida pela chave natural: **mesmo token + mesma URL + mesmos eventos devolve a assinatura existente (`200`, `meta.existing: true`) sem o segredo**. Consequência: a repetição não devolve a resposta byte a byte. Se o segredo se perdeu, rotacione em Integrações → Webhooks.

Erros (RFC 9457, `urn:assinavelox:problem:*`):

| Status | `type`                                         | Quando                                                                                                                           |
| ------ | ---------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| 400    | `idempotency-key-missing`                      | sem `Idempotency-Key`                                                                                                            |
| 403    | `missing-ability` / `creator-lacks-permission` | token sem `webhooks:manage` / criador sem `manage_integrations`                                                                  |
| 404    | `not-found`                                    | flag desligada; assinatura de outro token ou organização; evento de exemplo inexistente                                          |
| 409    | `subscription-limit-reached` (+`limit`)        | o token já tem o máximo de assinaturas (`ASSINAVELOX_REST_HOOKS_MAX_PER_TOKEN`, padrão 10)                                       |
| 409    | `subscription-refused`                         | teto de endpoints da organização do motor (`webhooks.max_endpoints_per_organization`)                                            |
| 422    | `target-url-blocked` (+`errors.target_url`)    | URL recusada pela proteção de rede. A mensagem é a mesma para "não resolve" e "resolve para endereço interno", e nunca cita o IP |
| 422    | `validation-failed`                            | URL ausente, evento fora do catálogo (inclusive `webhook.ping`)                                                                  |

### 3.2 Remover

`DELETE /api/v1/webhook-subscriptions/{id}` responde `204`. Só a assinatura **do próprio token**: outro token, mesmo da organização, recebe `404`, e um endpoint cadastrado pela tela também dá `404`. Remover é o `delete()` do motor: cancela as entregas abertas e sobrescreve o segredo. Revogar a chave em Integrações → Chaves **remove todas as assinaturas dela**.

### 3.3 Exemplo para mapear campos

`GET /api/v1/webhook-events/{event}/sample` devolve um payload montado por `App\Services\RestHooks\RestHookSamples`:

- ULIDs fictícios, todos começando com `01SAMP`, e datas fixas;
- nenhum nome, e-mail, CPF, título ou resumo real;
- **exatamente a forma** de uma entrega real, com os mesmos rótulos honestos. `recipient.signed` é "aceite eletrônico", `recipient.viewed` é "abertura detectada", e `envelope.completed` traz `data.signature` com o rótulo da verificação pública. Um teste compara as chaves com o `WebhookPayloadFactory` para seis eventos.

### 3.4 Entregas

Iguais às dos endpoints da tela ([webhooks.md](webhooks.md) §3–§5):

- mesmo corpo mínimo (só ULIDs e status);
- mesma assinatura `X-AssinaVelox-Signature: v1=HMAC-SHA256(segredo, "{timestamp}.{corpo}")`;
- mesmas retentativas, pausa automática e histórico.

O **responsável** pelo endpoint é quem criou o token. O endpoint só recebe eventos de documentos que essa pessoa pode ver, pela mesma regra da interface (teste com uma função personalizada sem `view_all_envelopes`). Se ela perder `manage_integrations`, o motor pausa o endpoint.

## 4. Validar a assinatura no fluxo

A validação usa o **segredo** devolvido na criação e o **corpo bruto** da entrega. Os exemplos de referência (PHP, Node, Python) estão em [webhooks.md](webhooks.md) §4 e na aba Documentação.

Nas plataformas no-code, o ponto delicado é obter o corpo **exatamente como chegou**, antes de qualquer interpretação do JSON. Uma reformatação muda o HMAC. Confirme, na documentação da sua versão da plataforma, como o nó ou módulo de webhook entrega o corpo bruto. Se a plataforma não oferecer o corpo bruto, a assinatura **não** pode ser validada ali. Nesse caso, trate o evento só como aviso e consulte o estado real pela API com o id recebido (`GET /api/v1/envelopes/{id}`): quem decide é a API, não o corpo do webhook.

Esboço para um passo de código em JavaScript (n8n "Code" ou equivalente), assumindo que o corpo bruto e os cabeçalhos estão disponíveis:

```js
const crypto = require('crypto');

const segredo = '<segredo whsec_… guardado nas credenciais da plataforma>';
const corpoBruto = /* string exatamente como recebida */ '';
const timestamp = /* cabeçalho X-AssinaVelox-Timestamp */ '';
const assinaturas = /* cabeçalho X-AssinaVelox-Signature */ '';

const esperado = crypto
    .createHmac('sha256', segredo)
    .update(`${timestamp}.${corpoBruto}`)
    .digest('hex');
const recente = Math.abs(Date.now() / 1000 - Number(timestamp)) <= 300;
const confere = assinaturas.split(',').some((parte) => {
    const [versao, valor = ''] = parte.trim().split('=');
    return (
        versao === 'v1' &&
        valor.length === esperado.length &&
        crypto.timingSafeEqual(Buffer.from(valor), Buffer.from(esperado))
    );
});

if (!recente || !confere) {
    throw new Error('Assinatura inválida — evento descartado');
}
```

Deduplique pelo cabeçalho `X-AssinaVelox-Delivery-Id`: ele se repete nas retentativas e no reenvio manual.

## 5. Passo a passo

### 5.0 Na AssinaVelox (vale para as três)

1. **Integrações → Chaves → Nova chave.** Crie **uma chave por fluxo**, com o mínimo de permissões:
    - só receber eventos: `webhooks:manage`;
    - receber eventos e consultar documentos: acrescente `envelopes:read` (e `documents:read` para baixar o PDF final);
    - criar e enviar documentos: acrescente `envelopes:write` e `envelopes:send`;
    - gerar a partir de modelo: acrescente `templates:read` e `templates:use`.
2. Copie a chave **na hora**: ela é exibida uma única vez. Guarde-a no cofre de credenciais da plataforma, nunca num campo de texto visível do fluxo.
3. Chamadas que criam (POST) exigem `Idempotency-Key`: gere um valor único por operação, por exemplo um UUID ou um id estável do item que disparou o fluxo. Assim uma repetição da plataforma não cria outro documento.

### 5.1 n8n

**Receber eventos (gatilho):**

1. Adicione um nó **Webhook** (método POST) e copie a URL de produção dele. A URL precisa ser **HTTPS pública**. Instâncias locais (`localhost`, rede interna) são recusadas pela proteção de rede.
2. Registre a assinatura com um nó **HTTP Request**:
    - `POST {base}/api/v1/webhook-subscriptions`;
    - autenticação por cabeçalho `Authorization: Bearer <chave>` (credencial do tipo "header auth" ou equivalente);
    - cabeçalho `Idempotency-Key`;
    - corpo JSON `{"target_url": "<URL do nó Webhook>", "event": "envelope.completed"}`.

    Guarde o `data.id` e o `data.secret` da resposta.

3. Opcional: valide a assinatura num nó de código (§4) antes de seguir.
4. Para desligar, `DELETE {base}/api/v1/webhook-subscriptions/{id}`.
5. Para mapear campos antes do primeiro evento, chame `GET {base}/api/v1/webhook-events/envelope.completed/sample` e use o resultado como dado de teste.

**Agir (ações):** nós **HTTP Request** para as rotas da API v1, por exemplo:

- `POST /api/v1/templates/{modelo}/envelopes` gera a partir de modelo;
- `POST /api/v1/envelopes/{id}/send` envia;
- `GET /api/v1/envelopes/{id}/files/signed` baixa o arquivo final.

Sempre com `Accept: application/json`.

> Nó próprio do n8n (comunitário ou verificado): **não existe** (classe B, decisão pendente no roadmap §2.17).

### 5.2 Zapier

**Receber eventos (gatilho instantâneo):**

1. Num Zap, use o app de **webhooks** da Zapier com o gatilho de captura (a variante de corpo bruto, se quiser validar a assinatura). Copie a URL gerada.
2. Registre a assinatura com uma requisição da própria Zapier (ação de requisição HTTP personalizada) ou, fora dela, com `curl`: o mesmo `POST /api/v1/webhook-subscriptions` da §3.1.
3. Para testar o gatilho sem esperar um evento real, use "Enviar teste" em Integrações → Webhooks. Ele entrega `webhook.ping` para o endpoint criado. Outra opção é usar o exemplo da §3.3 como amostra.

**App privado (Zapier Platform):** o padrão REST Hooks foi desenhado para isso.

- _subscribe_ = `POST /webhook-subscriptions` com `target_url` = a URL que a Zapier fornece;
- _unsubscribe_ = `DELETE /webhook-subscriptions/{id}`;
- _perform list_ = `GET /webhook-events/{event}/sample`.

Autenticação: chave de API no cabeçalho `Authorization: Bearer`.

Criar um app, mesmo privado, exige **conta de desenvolvedor na Zapier**. Publicá-lo no diretório exige **revisão da Zapier** e o contrato da API v1 **congelado** (roadmap §2.15). Nada disso existe hoje.

### 5.3 Make

**Receber eventos:**

1. Num cenário, use o módulo **Webhooks → Custom webhook** e copie o endereço gerado.
2. Registre a assinatura com o módulo **HTTP → Make a request**: `POST /api/v1/webhook-subscriptions`, cabeçalhos `Authorization` e `Idempotency-Key`, corpo JSON com `target_url` e `event`.
3. Para a estrutura de dados do webhook, envie um teste ("Enviar teste" na tela) ou use o exemplo da §3.3.

**Agir:** módulo **HTTP → Make a request** com as rotas da API v1.

> App **custom** do Make: exige conta de desenvolvedor no Make. A publicação exige revisão do Make (classe B, não existe hoje).

### 5.4 Receita do aceite do roadmap — "PDF no Drive → envelope enviado → status na planilha"

Roteiro. O aceite do roadmap §2.17 pede execução de ponta a ponta em **conta de teste real** das plataformas, e isso **não foi feito**: depende de contas e de ligar as flags num ambiente com HTTPS.

1. **Gatilho**: arquivo novo numa pasta do Drive (módulo nativo da plataforma).
2. `POST /api/v1/envelopes` (`Idempotency-Key` = id do arquivo no Drive) → `data.id`.
3. `POST /api/v1/envelopes/{id}/documents` (multipart, campo `file` com o binário baixado do Drive; a API **não** aceita URL).
4. `PUT /api/v1/envelopes/{id}/recipients` e `PUT …/fields` (ou use `POST /templates/{modelo}/envelopes` quando o documento vier de um modelo).
5. `POST /api/v1/envelopes/{id}/send` (`Idempotency-Key` = `send-` + id do arquivo).
6. **Segundo fluxo**, com gatilho REST Hook `envelope.completed` ou `envelope.refused`: com `data.envelope.id`, consulte `GET /api/v1/envelopes/{id}` e escreva a linha na planilha. O webhook não traz título nem participantes, por privacidade.

## 6. Telas (Integrações)

| Aba          | Rota                                                                      | Página                                                      | Requer                                      |
| ------------ | ------------------------------------------------------------------------- | ----------------------------------------------------------- | ------------------------------------------- |
| Documentação | `GET /api-integracoes` (`integrations.index`)                             | `integrations/docs`                                         | alguma das flags + `manage_integrations`    |
| Chaves       | `GET /api-integracoes/chaves` (`integrations.keys`)                       | `integrations/keys`                                         | `api_integrations` + `manage_integrations`  |
| — criar      | `POST /api-integracoes/chaves` (`integrations.keys.store`)                | responde com `integrations/keys`                            | idem; 10/min                                |
| — revogar    | `DELETE /api-integracoes/chaves/{apiToken}` (`integrations.keys.destroy`) | 302 → Chaves                                                | idem; 30/min                                |
| Webhooks     | `integrations.webhooks.*` (D-HOOK)                                        | `integrations/webhooks/index`, `integrations/webhooks/show` | `outbound_webhooks` + `manage_integrations` |
| Logs         | `GET /api-integracoes/logs` (`integrations.logs`)                         | `integrations/logs`                                         | `api_integrations` + `manage_integrations`  |

- **Documentação**: guia rápido com base URL, autenticação, abilities, idempotência, criação de documento (cURL/PHP/JS), a lista de rotas **lida do roteador** (método, caminho, ability e exigência de `Idempotency-Key` saem do próprio middleware), webhooks (catálogo, payload de exemplo, validação em Node/PHP/Python), REST Hooks e erros. O link para a OpenAPI (`/docs/api`, Scramble) só aparece quando o gate `viewApiDocs` permite.
- **Chaves** (texto exibido uma única vez):
    - a criação responde **direto** com a página (resposta Inertia ao POST, `Cache-Control: no-store`), com o texto numa prop. Ele não passa pela sessão (sem flash) nem por log;
    - a página copia o texto para o estado local e o apaga do histórico do navegador (`router.replaceProp`);
    - a listagem mostra só o prefixo (`ApiTokenResource`);
    - as permissões que a pessoa não pode conceder aparecem desabilitadas, e o `ApiTokenManager` confere de novo;
    - a revogação pede confirmação e informa quantas assinaturas de webhook serão removidas.
- **Webhooks**: cadastro com validação e mensagem clara quando a proteção de rede recusa, eventos, segredo exibido uma vez (após criar ou rotacionar), rotação com convivência, encerrar o anterior, pausar e reativar, teste, histórico com filtro por situação e evento, gaveta de detalhe e reenvio. Endpoints de REST Hook aparecem com o selo "REST Hook".
- **Logs**: requisições da API (período, resultado, chave), com resumo e id de correlação copiável. Só metadados, com retenção de `ASSINAVELOX_API_REQUEST_LOG_RETENTION_DAYS`.

**Diferenças em relação aos mocks** ("App - API", "App - Integracoes"), por honestidade:

- não há "ambiente sandbox" nem chaves `av_test_`/`av_live_`: não existe sandbox;
- não há lista de "IPs de origem": as saídas não têm IP fixo garantido;
- não há SDKs publicados (Fase 3, §3.9);
- não há pílula "API operacional": não existe monitor de status;
- os eventos seguem o catálogo real (`envelope.*`, `recipient.*`), não os nomes antigos do mock (`document.*`, `signer.*`).

## 7. Privacidade e segurança

- A chave pertence à organização **e** a quem a criou, tem abilities explícitas, é guardada só como hash e exibida uma vez. Todas as rotas passam pelo escopo da organização do token e pelas policies.
- Assinaturas isoladas por token: outro token, mesmo da organização, não lista nem remove.
- Exemplo sem dado real. Entregas com o payload mínimo do motor. Nenhuma resposta traz CPF, código, PIN, token, senha, imagem de assinatura ou captura.
- O segredo aparece só na criação (API) ou na criação e rotação (tela). Fica cifrado no banco e nunca no armazenamento de idempotência.
- Nenhum teste acessa a rede: DNS falso e `Http::preventStrayRequests()`.

## 8. Pendências (fora da área D-PLAT)

1. **`HandleInertiaRequests::features()`**: expor `api_integrations` (`ApiFeature::enabled`), `outbound_webhooks` (`WebhooksFeature::enabled`) e `rest_hooks` (`RestHooksFeature::enabled`). Até lá acontece o seguinte:
    - a tag "Fase 2" do menu continua aparecendo, porque a barra lateral lê essas chaves;
    - nas páginas de Webhooks (do D-HOOK, sem a prop `navigation`), as abas Chaves e Logs só aparecem quando `features.api_integrations` vier verdadeira.

    As páginas deste agente recebem `navigation` do servidor e não dependem disso.

2. **Token expirado**: revogar pela tela remove as assinaturas, mas um token que só **expira** mantém os endpoints de REST Hook recebendo eventos até alguém removê-los. Sugestão: o motor (D-HOOK) ignora ou pausa endpoints `source = rest_hook` cujo `api_token_id` não esteja mais utilizável, ou uma varredura agendada remove esses endpoints. `RestHookSubscriptions::removeForToken()` já existe para isso.
3. **Trilha**: criar e remover assinatura por REST Hook fica só no log estruturado do motor (`webhooks.endpoint_created`, com `source = rest_hook`), como a gestão de endpoints pela tela (pendência já registrada pelo D-HOOK).
4. **Papel `integration`** (roadmap §2.17): não criado. Seguimos a decisão da API v1 (api-v1.md §2.3): o token vale a interseção das abilities com as permissões atuais do criador. Crie uma pessoa de integração com uma função personalizada mínima e emita a chave com ela.

## 9. Testes

- `tests/Feature/Phase2/RestHooks` (flags, abilities, isolamento, assinatura, SSRF, limites, remoção, revogação, exemplo e contrato com o `WebhookPayloadFactory`).
- `tests/Feature/Phase2/IntegrationsUi` (placeholder da Fase 1 com as flags desligadas, acesso por `manage_integrations`, token exibido uma vez, anti-escalada, isolamento, logs).
