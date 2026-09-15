# Fase 3 — onda G (integrações de plataforma): relatório de integração (I-3G)

> Integração da última onda da Fase 3 (15/09/2026). Áreas: G-EMBED (widget iframe + `embed.js`),
> G-SDK (SDKs gerados da OpenAPI), G-SSO (OIDC e SAML), G-CONN (Google Drive, Dropbox, HubSpot e a
> trilha bloqueada). Classificação A/B/C conforme `docs/fases-2-3-viabilidade.md` §0. Nenhum commit
> foi feito nesta integração. O consolidado da Fase 3 inteira está em `entrega-fase-3.md`.

## 1. Resumo

As cinco flags novas nascem **desligadas**, globais **e** por plano: `embedded_signing`,
`sso_oidc`, `sso_saml`, `cloud_import` e `hubspot`. Com todas desligadas nada muda: rotas novas
respondem 404, o app inteiro continua com `X-Frame-Options: DENY` e `frame-ancestors 'none'`, e a
suíte existente passa sem mudança de asserção. As únicas listas fechadas que cresceram são as de
sempre (T7/T8): chaves de `features` no `SharedPropsTest` (com `false`) e eventos no fim do
`EnumCatalogTest`.

| Área                                                  | Documento                         | Classe                                                         | Flag(s)                             | Migrations                   |
| ----------------------------------------------------- | --------------------------------- | -------------------------------------------------------------- | ----------------------------------- | ---------------------------- |
| Widget embutido (§3.9)                                | `docs/fase-3/widget-embutido.md`  | A (depende só de engenharia e de HTTPS)                        | `embedded_signing` (exige a API v1) | `2026_09_14_170001`–`170002` |
| SDKs PHP, Node, Python (§3.9)                         | `docs/fase-3/sdks.md`             | A (publicação nos registros depende do proprietário)           | — (código fora do app)              | nenhuma                      |
| SSO OIDC e SAML (§3.9)                                | `docs/fase-3/sso.md`              | B (código real, provedores simulados; sem IdP real registrado) | `sso_oidc`, `sso_saml`              | `170201`–`170206`            |
| Google Drive e Dropbox (§3.9)                         | `docs/fase-3/conectores.md`       | B (sem apps registrados: tela "Aguardando app registrado…")    | `cloud_import`                      | `170301`                     |
| App HubSpot (§3.9)                                    | `docs/fase-3/conectores.md`       | B (sem app de desenvolvedor)                                   | `hubspot`                           | `170302`–`170303`            |
| Cartão de CRM HubSpot, API gov.br direta, e-Notariado | `docs/fase-3/trilha-bloqueada.md` | C — nenhum código, só documentação                             | —                                   | —                            |

Todas as migrations são aditivas e MySQL-compatíveis, dentro das faixas da onda.

## 2. Verificação (números reais desta integração)

| Verificação                                                                                                              | Resultado                                                                            |
| ------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------ |
| Pastas da onda + testes compartilhados (Sso, Embed, Connectors, Smoke, SharedProps, EnumCatalog, Phase2/Api, Permissões) | **375 / 375**, 4.521 asserções                                                       |
| SDKs (`tests/Feature/Phase3/Sdk`, depois da correção §3.1)                                                               | **12 / 12**, 206 asserções (PHP, Node e Python contra o servidor falso)              |
| Ponta a ponta (`tests/Feature/EndToEnd/Phase3WaveGTest.php`)                                                             | **2 / 2**, 155 asserções                                                             |
| Ponta a ponta no navegador (`tests/Browser/Phase3WaveGTest.php`, sozinho)                                                | **1 / 1**, 23 asserções                                                              |
| Unit+Feature (paralelo, final)                                                                                           | **2.655 / 2.655**, 25.902 asserções (8 min em paralelo)                              |
| Navegador (`--testsuite=Browser`, sozinho e em série)                                                                    | **46 testes — 43 passaram, 3 pulados** (os mesmos 3 de antes), 897 asserções         |
| pdftool (`pytest -q`)                                                                                                    | **239 passaram**                                                                     |
| PHPStan (projeto inteiro)                                                                                                | **0 erros**                                                                          |
| Pint (`--test`)                                                                                                          | passa (depois de `pint --dirty`, que só reordenou imports em 7 arquivos)             |
| `npm run types:check` (tsc)                                                                                              | 0 erros                                                                              |
| `npm run check` (vp: formato + lint)                                                                                     | passa (depois de formatar `sso.md`, `widget-embutido.md` e os dois relatórios novos) |
| `npm run build`                                                                                                          | concluído (manifesto com `resources/js/embed/embed.ts`)                              |
| `php artisan wayfinder:generate --with-form` / `route:list`                                                              | sem erro; 402 rotas                                                                  |

## 3. Correções e ajustes feitos na integração

### 3.1 Suíte dos SDKs travava sem prazo no Windows

`SdkSuitesTest` ficou 10+ minutos parado esperando o servidor falso — o mesmo travamento que o
G-SSO relatou. O servidor tinha subido e impresso `FAKE_API_URL=…`, mas `sdkWithFakeServer()`
esperava a linha com `Process::waitUntil()` sem prazo. No Windows a saída do processo vai por
arquivo temporário e é lida em lote: a linha chegava ao buffer antes do callback e nunca era
entregue a ele. Troquei por um laço que lê a saída **acumulada** (`getOutput()`) a cada 50 ms, com
prazo de 30 s e mensagem de erro com a saída do servidor
(`tests/Feature/Phase3/Sdk/Support/SdkHelpers.php`). Resultado: 12/12 em 25 s. Só o processo do
meu teste foi encerrado (árvore do PID que eu mesmo iniciei).

### 3.2 Documentação fora do formato

`npm run check` falhava em `docs/fase-3/sso.md` e `docs/fase-3/widget-embutido.md`; formatados com
`vp fmt` (só espaçamento de tabela).

### 3.3 `.env.example`

Não listava nenhuma chave da onda. Acrescentei as cinco flags (`false`),
`ASSINAVELOX_SSO_HOMOLOGATED=false` e as credenciais vazias dos apps (`GOOGLE_DRIVE_*`,
`DROPBOX_APP_KEY`, `HUBSPOT_*`). As flags das partes anteriores da Fase 3 também não estão lá —
registrado como pendência (§8).

### 3.4 Conferência dos arquivos compartilhados

Li o diff de cada arquivo compartilhado: `routes/web.php`, `routes/api.php`, `bootstrap/app.php`,
`bootstrap/providers.php`, `config/assinavelox.php`, `config/services.php`, `AuditEventType`,
`ApiAbility`, `HandleInertiaRequests`, `SecurityHeaders`, `Permissions`, `vite.config.ts`,
`resources/js/app.tsx`, `types/index.ts`, `login.tsx`, `general.tsx`, `wizard.tsx`,
`integrations/{index,docs}.tsx` e os três testes de lista fechada. Todas as edições são aditivas,
nenhuma apagou a de outra área, e os eventos novos estão no fim, com rótulo. Props × tipos:
`features.{embedded_signing,sso_oidc,sso_saml,cloud_import,hubspot}` existem em
`HandleInertiaRequests::features()` e em `Features` (`types/index.ts`); as props das telas novas
têm tipos próprios (`components/sso/types.ts`, `components/integrations/cloud/types.ts`,
`embed/widget-protocol.ts`). O `pint --dirty` trocou nomes totalmente qualificados por `use` em
sete arquivos compartilhados; conferi que `route:list` continua com 402 rotas e que o `then:` de
`bootstrap/app.php` e os providers ficaram iguais em comportamento.

### 3.5 Pendências cruzadas relatadas pelas áreas — conferidas

- `SystemRolesTest` (prefixo `settings.sso` sem permissão): já corrigido pelo G-SSO; verde.
- `OpenApiSpecTest`/`SdkSuitesTest` (especificação desatualizada depois das rotas de sessão
  embutida): a especificação versionada já inclui as três rotas; verde.
- Rótulo de `EmbedOriginsUpdated` e parâmetros de `embed.document` no `AllGetRoutesTest`: já
  presentes; verdes.

## 4. Ponta a ponta

### 4.1 `tests/Feature/EndToEnd/Phase3WaveGTest.php` (flags ligadas)

Pelas rotas HTTP reais, com todos os provedores simulados (`Http::fake` +
`preventStrayRequests` + DNS falso — nenhuma chamada de rede):

1. **OIDC:** uma pessoa de `empresa.com.br` entra pela tela de login; IdP simulado (discovery,
   JWKS e token com id_token RS256 assinado no teste); JIT cria a conta como **member**;
   `memberships.auth_via = sso`; trilha `sso.user_provisioned` e `sso.login_succeeded`.
2. **SAML:** outra pessoa entra em **outra organização** (uma conexão por organização) com
   AuthnRequest SP-initiated e assertion assinada por certificado de IdP de teste.
3. **API v1** (token da organização) cria o rascunho.
4. O owner importa, no rascunho criado pela API, um **PDF real** do Google Drive simulado (OAuth
   com PKCE, `drive.file`); o arquivo passa pelo pipeline do upload (pdftool real); o token é
   **revogado** no Google ao terminar.
5. A API vê o documento pronto, define participante e campo e envia (o convite por e-mail continua
   saindo).
6. O owner cadastra a origem na tela "Widget de assinatura"; a API recusa origem fora da lista (422)
   e cria a sessão embutida para a origem cadastrada. A página do widget sai sem
   `X-Frame-Options`, com `frame-ancestors` = origem exata e sem cookie; o painel continua `DENY`.
7. O widget troca a URL de uso único, confirma o código, apresenta o PDF e registra o aceite com a
   confirmação visual; `document.presented` ganha `channel: embedded`.
8. A finalização conclui com o **pdftool real** e o certificado de **teste** da operadora; o pdftool
   valida o PDF final (1 assinatura, íntegra, válida, confiável, cobrindo o arquivo inteiro).
9. A resposta real de `GET /api/v1/envelopes/{id}` segue `sdks/openapi/v1.json` em modo estrito, e
   o **SDK Python** a lê de um servidor local de teste que devolve esse corpo (status `completed`,
   1 documento, 1 assinatura, User-Agent `assinavelox-python/…`, token fora do `repr`; o token vai
   pelo ambiente, nunca pela linha de comando).
10. **T1:** a sessão do painel da pessoa que entrou por SSO não abre o widget (401) — só o token do
    participante vale. Nenhum segredo (client secret, tokens OAuth, token da sessão) na trilha.

### 4.2 Mesmo arquivo, flags desligadas

Fluxo antigo (API v1 da Fase 2 + página pública): as cinco chaves de `features` saem `false`;
`embed.js`, `sso.login.start`, ação do HubSpot, importação, HubSpot, origens do widget, tela de SSO
e criação de sessão embutida respondem **404**; login, painel e página de assinatura continuam
`DENY`; o envelope conclui e o pdftool valida o PDF; `document.presented` sem chave nova; nenhuma
linha nas dez tabelas novas; nenhum evento `sso.*`, `embedded_session.*`, `embed_origins.*`,
`cloud_import.*` ou `hubspot.*`; nenhuma requisição de saída.

### 4.3 `tests/Browser/Phase3WaveGTest.php`

O site do cliente (`http://localhost:PORTA`, outra origem que a do app em `127.0.0.1`) carrega o
`embed.js`; o iframe abre a sessão criada pela API sobre um PDF vindo do Drive simulado; o
participante pede o código, desenha a assinatura, marca o aceite e confirma no cartão de
confirmação visual; o site recebe exatamente `ready` e `completed`; o envelope conclui e o pdftool
valida o PDF final.

**Do teste, não do produto:** no ambiente de teste a aplicação é a mesma entre requisições, e uma
chamada com Bearer deixa `sanctum` como guard padrão — a tela seguinte do painel veria um visitante
(302 para o login). O E2E volta ao guard `web` antes de cada bloco do painel (`waveGPanel()`). Em
produção cada requisição começa do zero.

## 5. Dados de demonstração (`DemoOrganizationSeeder`)

- **Horizonte** (plano Profissional): as cinco flags de plano ligadas; origem de exemplo do widget
  `https://portal.imobiliaria-horizonte.example` (domínio reservado); conexão OIDC **em rascunho**,
  sem client secret; domínio `horizonte.demo` **não verificado**. Nada é semeado para Drive,
  Dropbox e HubSpot: uma conexão falsa fingiria um app que o proprietário ainda não registrou.
- **Vega** (Grátis): as cinco flags de plano desligadas.
- Os interruptores globais continuam os do `.env` (desligados). `migrate:fresh --seed` conferido num
  banco próprio (`database/i3g.sqlite`, apagado ao terminar).

## 6. QA no navegador (banco `database/i3g.sqlite`, `php artisan serve --port=8161`, flags ligadas)

| Tela                                      | Resultado                                                                                                                                                                             |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Login                                     | "Entrar com SSO" abre "E-mail corporativo"; `maria@horizonte.demo` (domínio não verificado) recebe "Não há login corporativo disponível para este domínio. Entre com e-mail e senha." |
| Configurações › Login único (SSO)         | aviso "Em homologação"; conexão de exemplo "Sem configuração"; domínio "Aguardando verificação"; instrução do registro TXT                                                            |
| Importar da nuvem (rascunho da Horizonte) | Google Drive e Dropbox com "Aguardando app registrado pelo proprietário" e botões desabilitados                                                                                       |
| API e integrações › Widget de assinatura  | origem semeada, adicionar/salvar e exemplo com `embed.js`                                                                                                                             |
| API e integrações › HubSpot               | "Aguardando app registrado pelo proprietário", conectar desabilitado, campos da ação e modelos disponíveis                                                                            |
| API e integrações (375 px)                | cartões "Conectores" e "Widget de assinatura" empilhados, sem corte                                                                                                                   |
| Console                                   | limpo em todas as telas                                                                                                                                                               |

Achado menor (não corrigido): no campo "E-mail corporativo" a tecla Enter não envia; só o botão
"Continuar com o login da empresa". O widget dentro de um site hospedeiro não foi exercido no painel
de QA (o servidor de QA não serve uma página de outra origem): está coberto pelo teste de navegador
(§4.3), em 900 px.

## 7. Contratos consolidados (para quem vem depois)

- **Flags e props:** `features.embedded_signing`, `features.sso_oidc`, `features.sso_saml`,
  `features.cloud_import`, `features.hubspot` (sem organização, só o interruptor global das SSO).
- **API v1:** habilidade `embedded_signing:manage` (exige `send_envelopes`);
  `POST|GET|DELETE /api/v1/envelopes/{envelope}/recipients/{recipient}/embedded-sessions[/{id}]`,
  recurso `embedded_signing_session`; nos SDKs `createEmbeddedSession`, `getEmbeddedSession`,
  `revokeEmbeddedSession`. Mudou a API: `sdkgen.py all` (o `OpenApiSpecTest` falha até isso).
- **Widget:** `embed.script` (`/embed/v1/embed.js`), `embed.show|exchange|state|otp.send|otp.verify|pin.verify|document|complete|refuse`;
  `postMessage` `assinavelox:{ready,completed,refused,error,resize,ping}`; painel
  `integrations.embed.edit|update`.
- **SSO:** `sso.login.start`, `sso.oidc.callback`, `sso.saml.acs`, `sso.saml.metadata`,
  `sso.required[.start]`, `settings.sso*`; limitadores `sso-login`, `sso-callback`, `sso-settings`;
  notificação `sso_break_glass`.
- **Conectores:** `cloud_import.{show,google.start,google.callback,google.token,google.store,dropbox.store}`,
  `integrations.hubspot.{show,connect,callback,disconnect}`, `webhooks.hubspot.action`.
- **Eventos** (fim de `AuditEventType`): 12 `sso.*`, 4 do widget, 5 dos conectores — nenhum sai
  pelos webhooks de saída.
- **Textos públicos novos:** "Entrar com SSO", "E-mail corporativo", "Continuar com o login da
  empresa", "Login corporativo obrigatório", "Aguardando app registrado pelo proprietário",
  "Importar da nuvem", "Conectores", "Widget de assinatura", o cartão "Confirmar e assinar" do
  widget.

## 8. Pendências — o que o proprietário precisa fornecer ou decidir

**Widget (`embedded_signing`)** — HTTPS; decidir os planos; validar a cada release em Chrome/Edge,
Firefox, Safari e iOS com cookie de terceiros bloqueado; revisão jurídica do texto da confirmação e
da menção ao site integrado nas evidências; monitorar `timeout`/`frame_too_small` (hoje só no
console do site do cliente). Onde não há IntersectionObserver v2 (Firefox, Safari), a proteção
contra sobreposição é mais fraca (documentado).

**SDKs** — decidir se publica; contas no Packagist, npm e PyPI; nomes; licença; CI com tokens;
rodar nas versões mínimas (PHP 8.1, Node 18, Python 3.10 — só 8.3/22/3.13 foram executadas);
congelamento formal da API v1. A especificação ainda descreve os erros como `{message}` (RFC 9457
não refletido), `sdks.md` §4.3.

**SSO (`sso_oidc`, `sso_saml`)** — um IdP de teste real (OIDC e SAML) e, depois dele,
`ASSINAVELOX_SSO_HOMOLOGATED=true`; política de break-glass aprovada; ligar as flags no plano
Empresarial (o `PlanSeeder` não foi alterado); HTTPS (cookie SAML `SameSite=None; Secure`);
agendador para `sso:prune`. Fora do escopo: re-verificação periódica do TXT, SLO, assertion
cifrada, AuthnRequest assinado, `userinfo`, emissor `common` da Microsoft, SCIM. Para outras áreas:
categoria "Login corporativo" no registro de atividades e item no menu de Configurações.

**Drive e Dropbox (`cloud_import`)** — projeto no Google Cloud com a marca verificada, escopo
`drive.file` e retorno `/integracoes/nuvem/google/retorno` (`GOOGLE_DRIVE_*`); app do Dropbox com os
domínios do Chooser (`DROPBOX_APP_KEY`); confirmar no primeiro teste real o domínio do link direto
do Dropbox e os hosts do Picker/Chooser na CSP da página de importação.

**HubSpot (`hubspot`)** — app de desenvolvedor e conta de teste, retorno
`/api-integracoes/hubspot/retorno`, ação em `/webhooks/hubspot/acao` (`HUBSPOT_*`); propriedade
`assinavelox_status` em negócios e contatos; confirmar onde vem o `hub_id` e se o HubSpot aceita
PKCE. O refresh token do HubSpot fica persistido e cifrado (exceção documentada, `conectores.md`
§5.4).

**Trilha bloqueada (classe C)** — API direta do gov.br (órgão público cliente + aceite da SGD),
e-Notariado (resposta formal do CNB-CF), cartão de CRM do HubSpot (confirmação para apps públicos).

**Engenharia** — `.env.example` sem as flags das partes anteriores da Fase 3; Enter no campo de
e-mail corporativo; item do menu de Configurações para SSO.

## 9. Arquivos editados pela integração

- `tests/Feature/Phase3/Sdk/Support/SdkHelpers.php` — espera do servidor falso com prazo (§3.1).
- `tests/Feature/EndToEnd/Phase3WaveGTest.php` e `tests/Browser/Phase3WaveGTest.php` — novos.
- `database/seeders/DemoOrganizationSeeder.php` — flags de plano da onda e `seedHorizonteWaveG()`.
- `.env.example` — chaves da onda (§3.3).
- `docs/fase-3/sso.md`, `docs/fase-3/widget-embutido.md` — só formatação (§3.2).
- `pint --dirty`: imports em `HandleInertiaRequests`, `SecurityHeaders`, `bootstrap/app.php`,
  `bootstrap/providers.php`, `routes/api.php`, `routes/web.php` e no seeder.
- Wayfinder regenerado.

## 10. Revisão adversarial

Quatro lentes (widget/CSP/postMessage; SSO, contas e isolamento; conectores, HubSpot e SDKs;
produto, semântica e design) produziram **31 achados** (1 crítico, 2 altos, 8 médios, 20 baixos),
com testes de prova em `tests/Feature/Review/Phase3G` e nos SDKs. Todos foram corrigidos na causa;
os testes de prova ficaram verdes sem afrouxar asserção, e dois arquivos novos
(`ReviewFixesSsoTest`, `ReviewFixesEmbedTest`) provam que o caminho legítimo continua aberto
depois de cada trava nova.

### 10.1 Correção por achado

**Widget embutido**

| Achado                                            | Correção                                                                                                                                                                                                                                        |
| ------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Revogar depois do aceite virava `revoked` (médio) | `EmbeddedSessionRevoker` não faz nada com desfecho registrado (sem evento); `status()` testa o desfecho antes da revogação.                                                                                                                     |
| Reemissão presa a convite revogado (baixo)        | `replay()` recusa `409 embedded-session-closed` quando o convite da sessão não é mais o ativo; a troca recusa `410 unavailable` com o convite revogado, sem `embedded_session.opened`.                                                          |
| `active` depois de responder pelo e-mail (baixo)  | Status novo `closed` (pessoa em estado terminal, sem desfecho pelo widget), no recurso, no OpenAPI e nos SDKs. O teto não muda: a criação já recusa quem respondeu.                                                                             |
| Mensagem do site sem `session` aceita (baixo)     | `isHostMessage` exige `session` igual.                                                                                                                                                                                                          |
| Visibilidade não re-medida no clique (baixo)      | `recheck()` com `takeRecords()` e intervalo desde o primeiro registro visível depois do último invisível. §5 do documento reescrito: sem IO v2, `frame-ancestors` é a única proteção contra sobreposição; etapas anteriores sem gate (decisão). |
| Token no `src` do iframe (baixo)                  | O `embed.js` carrega o iframe sem o fragmento e entrega o token por `assinavelox:token`, uma vez, só ao próprio iframe e à origem exata, em resposta a `assinavelox:boot`. A URL posta direto no iframe continua funcionando.                   |
| Sem página de exemplo (baixo)                     | `docs/fase-3/exemplos/widget-hospedeiro.html` (autocontida, largura 375/768/total) e §9.1 do documento.                                                                                                                                         |

**SSO**

| Achado                                                       | Correção                                                                                                                                                              |
| ------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Admin reaponta o IdP e entra como owner (crítico)            | Só o owner cria a conexão, muda os campos do provedor, `two_factor_policy`, `jit_role` e `saml_allow_idp_initiated`, e ativa. A tela trava esses campos para o admin. |
| Admin liga `trust_idp` (alto)                                | Idem: `two_factor_policy` é do owner.                                                                                                                                 |
| `trust_idp` vale na conta inteira (alto)                     | A sessão guarda `sso.two_factor_trusted_for`; em outra organização o login volta ao desafio do Fortify; o break-glass exige o 2FA digitado nesta sessão.              |
| Vínculo sobrevive à troca de IdP (médio)                     | Emissor/entityID novo invalida os vínculos (marcador que nunca casa; `identities_invalidated` na trilha); o login certo refaz o vínculo pelo e-mail.                  |
| Client secret na sessão (baixo)                              | `dontFlash(['oidc_client_secret'])` em `bootstrap/app.php`.                                                                                                           |
| Replay SAML depois do `sso:prune` (baixo)                    | ID guardado até o maior entre Conditions e SubjectConfirmationData; sem nenhum dos dois, recusa.                                                                      |
| JIT e IdP-initiated pelo admin (baixo)                       | Campos do owner (acima).                                                                                                                                              |
| Pedido SAML gasto antes da assinatura (baixo)                | `claim()` só confere; o pedido é gasto em `consume()`, depois da validação.                                                                                           |
| `at_hash` não exigido (baixo)                                | Mantido (opcional no Authorization Code, OIDC Core §3.1.3.6); documentado em `sso.md` §6.3.                                                                           |
| Texto do admin sobre a obrigatoriedade e "Desativar" (médio) | Texto novo para o admin; confirmação ao desativar com a obrigatoriedade ligada e ao ligá-la.                                                                          |
| Owner sem 2FA liga a obrigatoriedade (médio)                 | `enforce_requires_two_factor`; a tela avisa e trava.                                                                                                                  |
| Tab de "Entrar com SSO" (baixo)                              | `tabIndex={4}` no botão, no campo e no envio do SSO (logo depois de "Entrar").                                                                                        |
| OIDC sem client secret com "Testar" habilitado (baixo)       | "Falta o client secret" no cartão, teste desabilitado, dica "Obrigatório para testar a conexão".                                                                      |

**Conectores, HubSpot e SDKs**

| Achado                                                   | Correção                                                                                                                                               |
| -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Reconectar não revoga o refresh token (médio)            | `connect()` revoga o refresh token anterior depois da transação (melhor esforço) e registra `reconnected`/`previous_portal_id`.                        |
| Teto do download furado por Content-Encoding (baixo)     | O sink é o temporário com `BoundedWriteFilter`: conta os bytes gravados e faz a escrita falhar ao passar do teto.                                      |
| Token com NUL na mensagem do SDK Node (baixo)            | Construtor recusa token fora de `[\x21-\x7e\x80-\xff]`; o `catch` nunca repete cabeçalho recusado nem o token, e não anexa o erro original nesse caso. |
| `retry_after` com dígito não ASCII no SDK Python (baixo) | `isascii() and isdigit()`.                                                                                                                             |
| Suíte dos SDKs aceita zero testes (baixo)                | `SdkSuitesTest` exige total > 0 no PHP, `# pass` > 0 e `# skipped 0` no Node, `Ran N` > 0 no Python.                                                   |

**Produto e semântica**

| Achado                                                 | Correção                                                                                                                                                                  |
| ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Widget oferecido ao lado de "a API não existe" (médio) | `EmbedFeature::enabled` exige `api_integrations`; o placeholder deixou de dizer "Fase 2" ("ainda não disponível para esta organização").                                  |
| Importação da nuvem anunciada sem app (médio)          | Prop `cloud_import_available` (wizard, `integrations/index`, `integrations/docs`); sem provedor, o passo 1 não mostra o cartão e "Conectores" diz "ainda não disponível". |
| Recusa com código cru (baixo)                          | `reason` em PT-BR (`CloudImportRejected::describe`) e nome do arquivo gravado na recusa.                                                                                  |
| "ability"/"origin" na tela de origens (baixo)          | "permissão" e "endereço (origem)".                                                                                                                                        |
| HubSpot "aguardando app" como se funcionasse (baixo)   | Ação, modelos e execuções recolhidos em `awaiting_app`; valores traduzidos; motivo da falha em PT-BR.                                                                     |

### 10.2 Asserções existentes alteradas

- `tests/Feature/Review/Phase3G/SsoEnforcementCopyTest.php` — o admin passa a ter a sessão aberta
  pelo SSO antes de desativar. Com a obrigatoriedade ligada, é o único jeito de um admin alcançar a
  organização (`sso.md` §5; é o mesmo preparo de `EnforcementTest`). Sem isso o POST era desviado para
  `/sso/obrigatorio` e a premissa do teste nunca rodava; ele falhava na linha 41 antes e depois da
  correção.
- `tests/Feature/Review/Phase3G/ConnectorDownloadContentEncodingCapTest.php` — a sonda de teste também
  conta as escritas quando o sink é um recurso (o arquivo com o filtro da correção), não só quando é
  um caminho. As asserções são as mesmas.
- `tests/Feature/Phase3/Sdk/SdkSuitesTest.php` — ganhou exigências (total de testes > 0); nenhuma saiu.
- Nenhuma asserção de teste anterior à revisão foi mudada.

### 10.3 Decisões que precisam do aval do proprietário

1. **Só o owner** configura o provedor, a política de 2FA, o JIT e o IdP-initiated, e ativa. O admin
   testa, verifica domínios, muda o nome e o JIT ligado/desligado, desativa e remove.
2. **A conta do owner continua vinculável pelo e-mail** (a revisão sugeria proibir). A proteção
   passa a ser o item 1 mais a política `keep`. Proibir exigiria um fluxo de vínculo com senha e 2FA na
   tela de configurações.
3. **`at_hash` continua opcional.**
4. **Etapas antes da confirmação do widget sem gate de visibilidade** (só página oculta e tamanho).
5. **Widget exige a API v1** (`embedded_signing` sem `api_integrations` não aparece).
6. **Protocolo do widget**: o token de uso único passa por uma mensagem (`assinavelox:token`), a
   única que leva um segredo — decisão de segurança que muda o contrato do `embed.js` v1 de forma
   compatível (a URL com fragmento direto no iframe continua valendo).

### 10.4 Números finais

| Verificação                                               | Resultado                                                                                                                            |
| --------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| `tests/Feature/Review/Phase3G` (achados + regressões)     | **30 / 30** (24 dos achados, que falhavam 23 antes da correção, + 6 novos)                                                           |
| Pastas da onda (Sso, Embed, Connectors) + revisão         | primeira rodada 277 / 279 (os 2 restantes corrigidos em seguida); SSO + revisão **137 / 137**; todas dentro da rodada final completa |
| Testes de revisão dos SDKs                                | Node `review_token_in_error` **1 / 1**; Python `review_retry_after_non_ascii` **2 / 2**                                              |
| SDKs contra o servidor falso (`sdkgen.py all`)            | PHP ok, Node **46 / 46**, Python **50 / 50** (spec e SDKs regenerados com o status `closed`)                                         |
| Unit+Feature (paralelo, final)                            | **2.685 / 2.685**, 26.075 asserções (6,3 min)                                                                                        |
| Navegador (`--testsuite=Browser`, sozinho e em série)     | **46 testes — 43 passaram, 3 pulados** (os mesmos 3 de antes), 897 asserções                                                         |
| pdftool (`pytest -q`)                                     | **239 passaram**                                                                                                                     |
| PHPStan / Pint                                            | **0 erros** / passa                                                                                                                  |
| `npm run types:check` / `npm run check` / `npm run build` | 0 erros / 0 erros (47 avisos preexistentes, nenhum nos arquivos da revisão) / concluído                                              |

A primeira rodada completa depois das correções deu 2.682 / 2.685: `OpenApiSpecTest` e a impressão
digital dos SDKs (a especificação ainda sem `closed` — resolvido com `sdkgen.py all`) e
`VacuousNegativeAssertionTest`, que apontou no `SsoEnforcementCopyTest` da revisão um
`not->toContain(texto, mensagem)` cuja "mensagem" era uma segunda agulha (reescrito com uma agulha
só, a mesma intenção). A rodada final acima é depois disso.

### 10.5 Limitações que continuam

- Execuções antigas do HubSpot ficam `not_connected` depois de trocar de portal.
- Firefox e Safari: sem IO v2, nenhuma detecção de sobreposição além do `frame-ancestors`.
- A página de exemplo do widget não foi percorrida num navegador real em 375 px nesta revisão (o
  teste de navegador cobre 900 px); fica no checklist do §11 de `widget-embutido.md`.
- A marca do `trust_idp` vale por sessão: um usuário que troca de organização recebe o desafio do
  Fortify, sem tela intermediária própria explicando o motivo além do aviso.
