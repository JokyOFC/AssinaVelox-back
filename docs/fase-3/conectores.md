# Fase 3, onda G — Google Drive, Dropbox e HubSpot (§3.9, G-CONN)

> Roadmap §3.9; viabilidade §1.2 (classe **B** em Drive, Dropbox e HubSpot API; **C** no cartão de CRM do HubSpot), §3.2
> (onda G) e §7; `integracoes/pacotes-fase-2-3.md` §4, §6 e §8. Identificadores em inglês, texto em português. Flags
> **`cloud_import`** e **`hubspot`**, novas, **desligadas** por padrão (organização: interruptor global E plano).
> A trilha bloqueada (API direta do gov.br, e-Notariado, cartão de CRM) está em [`trilha-bloqueada.md`](trilha-bloqueada.md).

## 1. Resumo

| Entrega                                             | Onde                                                                                                         |
| --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Contrato `CloudFileSource`                          | `app/Integrations/Contracts/CloudFileSource.php`                                                             |
| Google Drive (OAuth + PKCE, `drive.file`, download) | `app/Integrations/GoogleDrive/{GoogleOAuthClient,GoogleDriveSource,GoogleAccessToken}.php`                   |
| Dropbox (Chooser, link direto, sem token)           | `app/Integrations/Dropbox/DropboxSource.php`                                                                 |
| Simulador identificado                              | `app/Services/CloudImport/SimulatedCloudFileSource.php` (recusado em produção)                               |
| Importação pelo caminho do upload                   | `app/Services/CloudImport/CloudImporter.php` → `DocumentIntake`/`UploadInspector`                            |
| Cliente HTTP com proteção contra SSRF               | `app/Services/CloudImport/Http/ConnectorHttp.php` (+ `DownloadLimit`, `ConnectorFailure`)                    |
| `state` + PKCE, token do Google na sessão           | `app/Services/CloudImport/OAuth/*`, `app/Services/CloudImport/GoogleImportSession.php`                       |
| CSP só da página de importação                      | `app/Services/CloudImport/CloudImportCsp.php` + gancho de uma linha no `SecurityHeaders`                     |
| App HubSpot (OAuth, assinatura v3, cliente da API)  | `app/Integrations/HubSpot/{HubSpotClient,HubSpotSignature,HubSpotTokens}.php`                                |
| Conexão, ação de workflow, atualização do CRM       | `app/Services/HubSpot/**` (`HubSpotConnections`, `HubSpotActionHandler`, `HubSpotObjectSync`, jobs, gancho)  |
| Controllers                                         | `app/Http/Controllers/Integrations/{CloudImportController,HubSpotController,HubSpotActionController}.php`    |
| Tabelas                                             | `cloud_imports` (170301), `hubspot_connections` (170302), `hubspot_action_executions` (170303)               |
| Telas                                               | `resources/js/pages/integrations/{cloud-import,hubspot}.tsx`, `resources/js/components/integrations/cloud/*` |
| Testes                                              | `tests/Feature/Phase3/Connectors/**`                                                                         |

**Com as flags desligadas nada muda**: as 11 rotas novas respondem 404, o wizard não mostra o cartão "Importar da
nuvem", "API e integrações" não mostra "Conectores", o gancho da trilha sai antes de qualquer consulta e nenhuma chamada
de rede acontece. Os testes existentes não mudaram de asserção (a lista fechada de `features` do `SharedPropsTest` e a do
`EnumCatalogTest` ganharam as chaves novas, por acréscimo).

## 2. Classificação e o que falta para ligar em produção

| Frente                                | Classe | Situação                                                                                                     |
| ------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------------ |
| Importar do Google Drive              | **B**  | Código real, testado contra o Google simulado (`Http::fake`). Falta o app do Google registrado e verificado. |
| Importar do Dropbox                   | **B**  | Código real, testado contra o Dropbox simulado. Falta o app do Dropbox com os domínios do Chooser.           |
| App HubSpot: OAuth, ação, atualização | **B**  | Código real, testado contra o HubSpot simulado. Falta o app de desenvolvedor e uma conta de teste.           |
| Cartão de CRM do HubSpot (app card)   | **C**  | Sem código. Ver [`trilha-bloqueada.md`](trilha-bloqueada.md) §3.                                             |

Sem as credenciais, cada tela diz **"Aguardando app registrado pelo proprietário"**, o botão fica desabilitado e nenhuma
chamada sai (`missingConfiguration()` lista os NOMES das variáveis que faltam, nunca valores).

**Checklist para ligar `cloud_import`** (todos):

1. Projeto no Google Cloud com _brand verification_ (homepage, política de privacidade, domínio verificado). Escopo
   **só** `drive.file` (não sensível — sem _security assessment_). URI de retorno registrada:
   `https://{domínio}/integracoes/nuvem/google/retorno`. Variáveis: `GOOGLE_DRIVE_CLIENT_ID`,
   `GOOGLE_DRIVE_CLIENT_SECRET`, `GOOGLE_DRIVE_API_KEY` (restrita por referrer), `GOOGLE_DRIVE_APP_ID` (número do
   projeto).
2. App do Dropbox com o domínio do AssinaVelox registrado para o Chooser. Variável: `DROPBOX_APP_KEY`.
3. Teste manual com os apps reais, conferindo e ajustando o que está NÃO CONFIRMADO (§10): hosts do link direto do
   Dropbox (`ASSINAVELOX_DROPBOX_DOWNLOAD_HOSTS`) e os hosts do Picker/Chooser na CSP (`cloud_import.csp.sources`).
4. `ASSINAVELOX_FEATURE_CLOUD_IMPORT=true` e `plans.features.cloud_import = true` nos planos que terão o recurso.

**Checklist para ligar `hubspot`** (todos):

1. App de desenvolvedor HubSpot (público) com a URI de retorno `https://{domínio}/api-integracoes/hubspot/retorno`, os
   escopos de `hubspot.scopes` e a ação de workflow apontando para `https://{domínio}/webhooks/hubspot/acao`, com os
   campos de entrada de §5.2 e os de saída `assinavelox_envelope_id`, `assinavelox_status`, `assinavelox_message`.
   Variáveis: `HUBSPOT_CLIENT_ID`, `HUBSPOT_CLIENT_SECRET`, `HUBSPOT_APP_ID`.
2. Conta de teste: conferir a resposta do token v3 e do `introspect` (onde vem o `hub_id` — NÃO CONFIRMADO) e a
   propriedade `assinavelox_status` criada nos negócios e contatos (`ASSINAVELOX_HUBSPOT_STATUS_PROPERTY`).
3. `ASSINAVELOX_FEATURE_HUBSPOT=true` e `plans.features.hubspot = true`.
4. Worker ouvindo a fila `hubspot.queue` (padrão `default`): envio adiado e atualização do CRM rodam em fila.

## 3. Importação: o MESMO caminho de um upload

`CloudImporter::import()` baixa o arquivo para um temporário e o entrega ao `DocumentIntake::store()` como um
`UploadedFile` — o mesmo método do upload do wizard. Então:

- o **tipo** é decidido pelo conteúdo (assinatura de bytes + `finfo`), a extensão tem de conferir, o **tamanho** respeita
  `upload.max_mb`, **DOCX** passa pela inspeção do pacote e **PDF protegido ou já assinado** é bloqueado pelo
  `ProcessDocumentUpload`, exatamente como num envio do computador. Um executável renomeado para `.pdf` no Drive é
  recusado (teste);
- a regra de um documento × vários (`multi_document`) e a preservação legal valem igual;
- o tipo declarado pelo provedor serve só para escolher download × exportação e para dar extensão a um nome que não tem.

**Registro da origem**: cada tentativa gera uma linha em `cloud_imports` (provedor, id externo, nome, SHA-256, tamanho,
quem importou, `completed`/`rejected` + código, `simulated`) e um evento na trilha do envelope (§7). O temporário é
apagado com sucesso ou não. Nunca se guarda link de download, token ou conteúdo.

## 4. Google Drive e Dropbox

### 4.1 Google Drive — OAuth com PKCE, Picker e `drive.file`

1. **Início** (`GET documentos/{envelope}/importar/google`): `state` de 32 bytes e `code_verifier` de 48 bytes (64
   caracteres), guardados na sessão de quem iniciou (o `state` só como SHA-256; o verificador cifrado), vinculados à
   organização, ao usuário e ao envelope, válidos por `cloud_import.state_ttl_minutes`. Redireciona para o Google com
   `code_challenge` S256, `scope=drive.file`, `access_type=online` (sem refresh token) e `prompt=consent`.
2. **Retorno** (`GET integracoes/nuvem/google/retorno`, URL fixa): lê `state`/`code` e **apaga a query da requisição**
   (o Laravel guardaria a URL — com o código — em `_previous.url` da sessão); consome o `state` (uso único: o mesmo
   retorno de novo é recusado); confere organização e usuário; troca o `code` com o verificador. Se o Google não concedeu
   `drive.file`, nada é guardado. Um `refresh_token`, se vier, é descartado sem ser lido.
3. **Picker** (navegador): o token chega por `POST documentos/{envelope}/importar/google/token` (JSON,
   `Cache-Control: no-store`), só dentro da janela e só para o mesmo usuário e envelope. Não vai em prop do Inertia (que
   fica no histórico do navegador).
4. **Importação** (`POST documentos/{envelope}/importar/google`, `file_ids[]`): metadados → `alt=media` (arquivo comum)
   ou `export?mimeType=application/pdf` (Documento/Planilha/Apresentação/Desenho do Google, até 10 MB); pasta, atalho e
   formulário são recusados. Ao terminar — com sucesso ou não — o token sai da sessão e é **revogado** no Google.

### 4.2 Dropbox — Chooser com link direto

O Chooser (`linkType: "direct"`, extensões e `sizeLimit` do upload) devolve um link temporário; o navegador manda
`files[] = {link, name, id, bytes}` para `POST documentos/{envelope}/importar/dropbox`. O servidor só aceita `https`,
só os hosts de `cloud_import.dropbox_download_hosts` (padrão `dl.dropboxusercontent.com` e
`*.dropboxusercontent.com`), com a proteção contra SSRF de §6, e baixa na hora. **Sem OAuth, sem token, sem conexão
persistente.** O link não é guardado.

### 4.3 Decisão: nenhum token além da importação

- **Google**: token só na sessão, cifrado, por no máximo `google_session_ttl_minutes` (padrão 10) ou até vencer, o que
  vier primeiro; apagado e revogado ao fim da importação. Nenhum refresh token é pedido nem guardado. Consequência: cada
  importação pede a autorização de novo (o Google costuma aprovar sem nova tela quando o usuário já consentiu).
- **Dropbox**: nenhum token existe no fluxo de importação.
- **HubSpot** é a exceção documentada em §5.4.

### 4.4 CSP

O Picker e o Chooser só funcionam com scripts, iframe e janela dos provedores. A página de importação — e só ela — é
marcada pelo middleware `AllowCloudPickers`; o `SecurityHeaders` chama `CloudImportCsp::apply()`, que acrescenta os
hosts de `cloud_import.csp.sources` (tira o `'none'` do `frame-src`) e troca a COOP para `same-origin-allow-popups`
(o Chooser conversa com a janela do Dropbox por `postMessage`). `frame-ancestors 'none'` continua. O wizard e todo o
resto do app seguem com a política da Fase 1 (teste). Por isso o cartão do wizard é um link comum (`<a>`), não uma
visita do Inertia: a página precisa ser carregada inteira para receber a CSP dela.

## 5. App HubSpot

### 5.1 Conexão OAuth por organização

- **Conectar** (`POST api-integracoes/hubspot/conectar`, `manage_integrations`): `state` de uso único (sem PKCE: a
  documentação do HubSpot não o prevê para o fluxo com client secret — NÃO CONFIRMADO) e redirecionamento para
  `app.hubspot.com/oauth/authorize` com os escopos mínimos (contatos e negócios, leitura e escrita).
- **Retorno** (`GET api-integracoes/hubspot/retorno`): query apagada como no Google, `state` consumido, troca do `code`
  no endpoint de token v3 (credenciais no corpo), portal pelo `hub_id` da resposta ou pelo `introspect`.
- **Um portal, uma organização** (`hubspot_connections.portal_id` UNIQUE): um portal já conectado a outra organização é
  recusado. É pelo portal que a ação de workflow encontra a organização.
- **Desconectar** (`DELETE api-integracoes/hubspot`): revoga o refresh token no HubSpot (melhor esforço) e apaga a
  linha.
- **Reconectar** com uma conexão existente (o botão continua disponível, para trocar de conta — revisão adversarial da
  onda G): o par de tokens anterior sai do banco e o refresh token antigo é **revogado** no HubSpot, depois da
  transação e em melhor esforço, como na desconexão. O refresh token do HubSpot não vence sozinho; sem isso ele
  continuaria válido lá sem que ninguém pudesse revogá-lo pela tela. `hubspot.connected` ganha `reconnected` e
  `previous_portal_id`. Trocando de portal, as execuções antigas continuam na lista, mas a sincronização delas passa a
  `not_connected` (o portal antigo não tem mais conexão) — limitação registrada.

### 5.2 Ação de workflow "Enviar para assinatura"

`POST webhooks/hubspot/acao` — sem sessão e sem CSRF; autenticada **só** pela assinatura v3:

1. flag global desligada → 404; app sem client secret → 503;
2. `X-HubSpot-Request-Timestamp` fora da janela (`hubspot.signature_tolerance_seconds`, 5 min) → 401;
3. HMAC-SHA256 (base64) de `MÉTODO + URI + corpo + timestamp` com o client secret, URI com `%3A %2F %3F %40 %21 %24 %27
%28 %29 %2A %2C %3B` decodificados, comparado em tempo constante → senão 401. A resposta é a mesma para todo motivo;
   o log interno leva só o código (`hubspot.action_signature_rejected`);
4. portal sem conexão, ou organização sem a flag → 404;
5. **idempotência**: UNIQUE(`portal_id`, `callback_id`). Retentativa do HubSpot, replay dentro da janela ou o mesmo
   `callbackId` com outros campos recebem a MESMA resposta guardada, sem novo documento;
6. o envelope nasce do modelo pelo MESMO `CreateEnvelopeFromTemplate` do "Usar modelo", em nome de quem conectou o
   HubSpot — que precisa continuar membro ativo com `create_envelopes` (senão: `connector_user_unavailable`). Modelo de
   outra organização é "não encontrado";
7. pronto → `SendEnvelope` (mesmas regras de plano e antifraude; exige `send_envelopes`); arquivo ainda processando →
   `SendHubSpotEnvelope` tenta de novo (`hubspot.send_attempts` × `send_retry_seconds`); faltou algo → rascunho com
   estado "precisa de revisão".

**Campos de entrada**: `template_id` (ULID do modelo), `title` (opcional), `participant_N_name` e `participant_N_email`
(N = posição do papel no modelo, a partir de 1, até `hubspot.max_participants`), `var_{chave}` para as variáveis.
**Saída** (`outputFields`): `assinavelox_envelope_id`, `assinavelox_status` (`sent`, `awaiting_preparation`,
`needs_review`, `failed`) e `assinavelox_message`. Dados que o modelo recusa voltam com `failed` e a mensagem da
validação, com HTTP 200 (o HubSpot não repete 4xx, e o workflow pode ramificar pelo estado).

### 5.3 Atualização do negócio/contato — decisão: chamada direta

Quando um envelope criado pela ação muda de estado (`envelope.sent|completed|refused|canceled|expired` na trilha), o
gancho `eloquent.created` de `AuditEvent` (registrado pelo `HubSpotServiceProvider`) enfileira `SyncHubSpotObject`, que
grava `sent|completed|refused|canceled|expired` na propriedade `hubspot.status_property` do objeto da execução
(`PATCH /crm/v3/objects/{deals|contacts|companies|tickets}/{id}`).

**Por que não pelo motor de webhooks de saída**: ele entrega o NOSSO payload assinado a uma URL do cliente; o HubSpot
exige o formato dele e um Bearer OAuth da organização. A chamada direta passa pela mesma proteção contra SSRF (hosts
`api.hubapi.com`), roda em fila e só repete falha transitória (rede, 429, 5xx); 4xx (propriedade inexistente, objeto
apagado) encerra como `failed`, visível na tela. Objeto de tipo não suportado fica "não se aplica".

### 5.4 Tokens do HubSpot — a exceção documentada

A atualização acontece quando o envelope conclui, sem ninguém logado. Por isso a conexão guarda o **refresh token**,
cifrado (cast `encrypted`, chave da `APP_KEY`), junto do access token (30 min). A renovação acontece quando falta menos
de 1 minuto, sob lock por conexão; o HubSpot pode girar o refresh token, e o novo substitui o anterior. Renovação
recusada (400/401/403) marca a conexão `error` ("Conexão precisa ser refeita") — nada tenta em laço. A desconexão
revoga e apaga.

## 6. Proteção contra SSRF (`ConnectorHttp`)

Toda chamada dos conectores — inclusive a endpoints fixos do Google e do HubSpot — passa por:

1. só `https` (nem fora de produção existe `http` aqui);
2. host na lista do provedor **antes** de qualquer DNS (`host` exato ou `.sufixo`) — link do Dropbox para outro lugar
   morre sem consulta (teste: `lookups` vazio);
3. `OutboundUrlGuard` dos webhooks: IP literal em qualquer grafia, credenciais na URL, sufixos internos, porta, e
   **todos** os endereços resolvidos precisam ser públicos (teste com privado, metadados, loopback, IPv4 mapeado,
   resposta mista e "não resolve");
4. conexão **pinada** (`CURLOPT_RESOLVE`), sem redirecionamento, sem proxy de ambiente, só `https` — as opções do
   `WebhookTransport`, cujo pino tem teste de integração próprio (docs/fase-2/webhooks.md §6.4);
5. download com teto: `Content-Length` nos cabeçalhos, `progress` aborta ao passar do teto, e o tamanho gravado é
   conferido de novo. Nunca `stream => true` (o Guzzle trocaria o handler cURL e o pino sumiria).
   **O teto vale para os bytes GRAVADOS** (revisão adversarial da onda G): o cURL descomprime qualquer
   `Content-Encoding` da resposta, mesmo sem ter pedido, e o `progress` e o `Content-Length` só enxergam os bytes da
   rede. Por isso o `sink` é o próprio temporário aberto com o filtro de escrita `BoundedWriteFilter`, que conta o que
   chega ao disco e, passado o teto, descarta o resto e faz a escrita falhar — o cURL aborta na hora. Medido: um gzip
   de 48 KB que viraria 48 MB para no teto (antes: 48 MB gravados até a recusa final).

Nenhuma mensagem de erro carrega URL, cabeçalho, corpo ou token: só `ConnectorFailure` com um código.

## 7. Segredos, trilha e privacidade

- **Segredos**: client secrets só no ambiente (`config/services.php`); tokens do HubSpot cifrados no banco e fora de
  `toArray()`; token do Google cifrado na sessão; objetos que carregam token (`CloudFileSelection`, `GoogleAccessToken`,
  `HubSpotTokens`) escondem o valor em dump e **se recusam a serializar**; parâmetros marcados `#[\SensitiveParameter]`;
  jobs levam só ids. Teste varre log, trilha, tabelas, sessão, fila e respostas atrás de cada token, segredo e código.
- **Eventos novos** (`AuditEventType`, acrescentados no fim):

| Evento                    | Onde        | Payload                                                                                   |
| ------------------------- | ----------- | ----------------------------------------------------------------------------------------- |
| `cloud_import.completed`  | envelope    | `import`, `provider`, `external_id`, `document_ulid`, `sha256`, `size_bytes`, `simulated` |
| `cloud_import.rejected`   | envelope    | `import`, `provider`, `external_id`, `code`, `simulated`                                  |
| `hubspot.connected`       | organização | `connection`, `portal_id`, `scopes`                                                       |
| `hubspot.disconnected`    | organização | `connection`, `portal_id`                                                                 |
| `hubspot.action_received` | envelope    | `execution`, `portal_id`, `object_type`, `object_id`, `template`                          |

Nenhum dos eventos novos é publicado pelos webhooks de saída (não estão no catálogo de `WebhookEventType`).

- **Semântica (T1)**: importar da nuvem só muda a ORIGEM do arquivo; o aceite eletrônico, as evidências e a assinatura
  da operadora são os mesmos. A ação do HubSpot só cria e envia o documento; quem aceita continua sendo o participante,
  pelo fluxo de sempre.

## 8. Contratos para quem vem depois

**Rotas** (todas no grupo `auth`, `verified`, `org`, `org.2fa`, exceto a da ação):

| Método | URI                                           | Nome                              | Observação                                |
| ------ | --------------------------------------------- | --------------------------------- | ----------------------------------------- |
| GET    | `documentos/{envelope}/importar`              | `cloud_import.show`               | página `integrations/cloud-import`        |
| GET    | `documentos/{envelope}/importar/google`       | `cloud_import.google.start`       | `throttle:10,1,cloud-import-google-start` |
| GET    | `integracoes/nuvem/google/retorno`            | `cloud_import.google.callback`    | URL fixa registrada no Google             |
| POST   | `documentos/{envelope}/importar/google/token` | `cloud_import.google.token`       | JSON, `no-store`                          |
| POST   | `documentos/{envelope}/importar/google`       | `cloud_import.google.store`       | `file_ids[]`                              |
| POST   | `documentos/{envelope}/importar/dropbox`      | `cloud_import.dropbox.store`      | `files[] = {link, name, id?, bytes?}`     |
| GET    | `api-integracoes/hubspot`                     | `integrations.hubspot.show`       | página `integrations/hubspot`             |
| POST   | `api-integracoes/hubspot/conectar`            | `integrations.hubspot.connect`    | `Inertia::location` para o HubSpot        |
| GET    | `api-integracoes/hubspot/retorno`             | `integrations.hubspot.callback`   | URL fixa registrada no HubSpot            |
| DELETE | `api-integracoes/hubspot`                     | `integrations.hubspot.disconnect` |                                           |
| POST   | `webhooks/hubspot/acao`                       | `webhooks.hubspot.action`         | sem CSRF; `throttle:webhook`              |

**Props compartilhadas**: `features.cloud_import` e `features.hubspot` (`resources/js/types/index.ts`).
**Props de página** (revisão adversarial da onda G): `cloud_import_available` no wizard (`envelopes/wizard`) e em
`integrations/index` e `integrations/docs` = `features.cloud_import` E algum provedor com app registrado
(`CloudFileSources::anyConfigured()`). Sem nenhum, o passo 1 não mostra "Importar da nuvem" e "Conectores" diz
"ainda não disponível". Em `integrations/cloud-import`, cada item de `recent` ganhou `reason` (motivo em PT-BR,
`CloudImportRejected::describe`); a tela nunca mostra o código cru, e a recusa grava o nome informado na seleção. A
tela do HubSpot recolhe ação, modelos e execuções enquanto `status = awaiting_app` e traduz o motivo das falhas.
**Tipos do front**: `resources/js/components/integrations/cloud/types.ts`. **Ganchos** de uma linha: `CloudImportEntry`
no passo 1 do wizard, `ConnectorsCallout` em `integrations/index.tsx` e `integrations/docs.tsx`.
**Habilidades da API**: nenhuma nova (a API v1 não expõe os conectores).
**Contrato**: `App\Integrations\Contracts\CloudFileSource`; outro provedor entra implementando-o e registrando-se em
`CloudFileSources` (ou em `cloud_import.source.{provider}` no container, como o simulador).

## 9. Isolamento

- rotas com `{envelope}` usam o binding escopado à organização corrente (envelope de outra organização = 404);
- o `state` e o token do Google são vinculados a organização, usuário e envelope — autorização de um envelope não vale
  para outro, e retorno iniciado numa organização não vale com outra corrente (testes);
- a ação do HubSpot decide a organização pelo portal (UNIQUE) e só aceita modelos dessa organização;
- a tela do HubSpot lista só a conexão e as execuções da organização corrente.

## 10. NÃO CONFIRMADO (validar com os apps reais antes de ligar)

1. Domínio exato do link direto do Dropbox (`*.dropboxusercontent.com` presumido) — pacotes-fase-2-3 §4.2.
2. Hosts exatos que o Google Picker e o Dropbox Chooser carregam (lista de CSP em `cloud_import.csp.sources`).
3. Formato da resposta do token e do `introspect` v3 do HubSpot (onde vem o `hub_id`); PKCE no OAuth do HubSpot.
4. O `associationTypeId` de notas (não usado: a atualização grava uma propriedade, sem notas).
5. Limite absoluto de tamanho de arquivo do Drive além da exportação de 10 MB (vale o `upload.max_mb` nosso).

## 11. Verificação

Ver o relatório da onda G para os números da execução final.
