# Fase 3 — Assinatura embutida por iframe e `embed.js` (§3.9, G-EMBED)

> Roadmap §3.9 (widget iframe). Área G-EMBED da onda G. Identificadores em inglês; prosa em português.
> Flag `features.embedded_signing` — **desligada por padrão** (roadmap T8), global **e** por plano.
> Com ela desligada, nada muda: a rota da API, `/embed/*`, o `embed.js` e a tela de origens respondem 404, e o app inteiro continua com `X-Frame-Options: DENY` e `frame-ancestors 'none'`.
> Complementa `docs/fase-2/api-v1.md`, `docs/fase-2/webhooks.md` e `docs/fluxo-do-signatario.md`, que continuam valendo.

## 1. Resumo

- O **servidor do cliente** cria, pela API v1, uma **sessão embutida** para UM participante de UM envelope, presa a UMA origem exata (`https://app.cliente.com.br`). A resposta traz uma **URL de uso único** com o token no fragmento (`#t=`).
- A **página do cliente** carrega o `embed.js` e chama `AssinaVelox.mount({ url, container, … })`. O widget abre num iframe e fala com a página só por mensagens `assinavelox:*` tipadas.
- Dentro do widget, a pessoa passa pelas **mesmas regras do link por e-mail**: código pelo canal escolhido pelo remetente (e-mail, SMS ou WhatsApp), PIN do remetente quando houver, documento apresentado, declaração de aceite, recusa com motivo. O widget **não muda o valor do aceite eletrônico** (T1): é o mesmo `RecordAcceptance`, a mesma declaração, a mesma trilha — com o canal `embedded` anotado na apresentação do documento.
- Só a página do widget recebe `frame-ancestors {origem exata da sessão}`; o resto do app continua `DENY`. Nenhum cookie é usado.

## 2. API v1

Ability nova (acrescentada no fim de `ApiAbility`): **`embedded_signing:manage`** — "Gerenciar assinatura embutida". Quem cria o token precisa de `send_envelopes` (trava anti-escalada). Flag `embedded_signing` (global **e** plano) desligada → 404, como as demais flags da API.

| Método | Caminho                                                                 | Nome                                                    | Observação                                                                                                            |
| ------ | ----------------------------------------------------------------------- | ------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| POST   | `/api/v1/envelopes/{envelope}/recipients/{recipient}/embedded-sessions` | `api.v1.envelopes.recipients.embedded_sessions.store`   | Exige `Idempotency-Key`. Corpo: `origin`* (origem exata), `expires_in` (60–900 s, padrão 300). **201** com `data.url` |
| GET    | `…/embedded-sessions/{embeddedSession}`                                 | `api.v1.envelopes.recipients.embedded_sessions.show`    | Situação. A `url` nunca volta                                                                                         |
| DELETE | `…/embedded-sessions/{embeddedSession}`                                 | `api.v1.envelopes.recipients.embedded_sessions.destroy` | Revoga (idempotente). 200 com o recurso                                                                               |

### 2.1 Regras da criação (`App\Services\Embed\EmbeddedSessionIssuer`)

Conferidas nesta ordem, **antes** da idempotência:

1. envelope em andamento → senão `409 invalid-status`;
2. papel que registra aceite (signatário, testemunha, aprovador) → o visualizador recebe `409 recipient-not-signable`;
3. convite ativo (link de assinatura não revogado) → senão `409 recipient-not-invited`. A sessão fica **presa a esse convite**: troca de e-mail, reenvio, recusa ou cancelamento que revogam o link derrubam também a sessão embutida;
4. é a vez da pessoa e ela ainda não respondeu (mesma regra do link) → senão `409 recipient-not-active`;
5. nada que o widget não oferece foi exigido (foto ou vídeo, §3.4) → senão `409 embedded-unsupported` com `requirement`;
6. `origin` na forma canônica (§4) **e** cadastrada na organização → senão `422 validation-failed` com `errors.origin`;
7. no máximo `max_live_per_recipient` (5) sessões utilizáveis por participante → senão `409 too-many-sessions`.

Envelope ou participante de outra organização, invisível para o criador do token, ou participante de outro envelope: **404**, como no resto da API. Erros em problem+json (RFC 9457).

### 2.2 Recurso `embedded_signing_session`

`id`, `object`, `envelope_id`, `recipient_id`, `origin`, `status` (`pending` | `active` | `completed` | `refused` | `expired` | `revoked` | `closed`), `expires_at`, `used_at`, `completed_at`, `revoked_at`, `created_at` e — **só na criação** — `url`. Nunca saem: digest de token, estado da sessão, IP ou user-agent de quem abriu.

- `completed`/`refused` (desfecho registrado **por este widget**) vêm antes de tudo: revogar depois do aceite nunca troca o desfecho por `revoked`.
- `closed` (revisão adversarial): a pessoa já respondeu por **outro caminho** (link do e-mail, delegação) ou foi encerrada (cancelamento, prazo), sem desfecho por este widget. A sessão não abre mais, mesmo com o token de execução vivo. Não precisa sair do teto `max_live_per_recipient`: a criação já recusa quem respondeu (`recipient-not-active`) antes de contar.

### 2.3 Idempotência sem guardar o segredo

O armazenamento de `Idempotency-Key` da API grava o corpo de respostas JSON por 24 h — e a URL contém o token. Por isso a criação responde como resposta HTTP simples (o mesmo recurso dos REST Hooks) e **a repetição é resolvida pelo serviço**: mesma chave + mesmo pedido → a **mesma sessão** (mesmo `id`), com uma **URL nova** de uso único (a anterior deixa de valer), `201` e `Idempotent-Replayed: true`. Mesma chave com outro pedido → `409 idempotency-key-reused`. Sessão já aberta, revogada, com desfecho **ou presa a um convite que já não vale** (reenvio ou troca de e-mail depois da criação) → `409 embedded-session-closed`. Reemitir é a forma honesta de quem perdeu a resposta recuperar a URL sem que o segredo fique gravado em lugar nenhum.

### 2.4 Revogação

`DELETE` marca `revoked_at`, apaga o estado cifrado e revoga a `signing_sessions` que o widget tinha aberto (um aceite não sai mais daquele iframe). O widget aberto recebe `410 session_revoked`. Um aceite já registrado nunca é desfeito: com desfecho registrado pelo widget, o `DELETE` responde `200` **sem mudar nada** — nenhum `embedded_session.revoked` na trilha, e a API continua informando `completed`/`refused`.

## 3. A sessão embutida

Tabela `embedded_signing_sessions` (migration `2026_09_14_170001`): `organization_id`, `envelope_id`, `recipient_id`, `access_link_id` (o convite ao qual a sessão está presa), `api_token_id`, `created_by_user_id`, `allowed_origin`, `token_digest`, `runtime_token_digest`, `session_state` (cifrado), `idempotency_key_digest`, `request_fingerprint`, `expires_at`, `used_at`, `runtime_expires_at`, `revoked_at`, `revoked_reason`, `completed_at`, `outcome`, `last_seen_at`, `used_ip` (truncado /24 ou /48), `used_user_agent`. Modelo `App\Models\EmbeddedSigningSession`.

### 3.1 Página (`GET /embed/v1/sessoes/{session}`, `embed.show`)

Não autentica e não revela o documento: devolve a casca do widget (`embed/sign`) com a origem que pode enquadrá-la, os endereços das ações e os limites de tela. Responde igual para sessão nova, usada, vencida ou revogada — quem diz o que aconteceu é a troca, e só para quem tem o token certo. Sessão inexistente, flag desligada ou origem descadastrada: 404 com `DENY`.

### 3.2 Troca (`POST …/troca`, `embed.exchange`)

O widget lê o token do fragmento, **apaga o fragmento do endereço** (`history.replaceState`) antes de qualquer requisição e envia o token uma vez no corpo. Token errado → o mesmo `404 invalid_link` (o ULID da sessão está no endereço e não é segredo). Com o token certo: `410 link_used`, `link_expired`, `link_revoked` ou `unavailable` (o convite ao qual a sessão está presa foi revogado — reenvio, troca de e-mail, recusa, cancelamento — antes da troca; nada de token de execução nem de `embedded_session.opened`), ou `200` com o **token de execução** (`runtime_ttl_minutes`, 30 min, nunca além do prazo do envelope). A marcação de uso é atômica. A trilha registra `embedded_session.opened` com a origem.

### 3.3 Sem cookie de terceiro — decisão

Safari bloqueia todo cookie de terceiro; no Chrome a pessoa pode bloquear; CHIPS (`Partitioned`) é Baseline desde dezembro de 2025, mas a sessão Laravel é **global**, e `SameSite=None` nela enfraqueceria o CSRF do painel. **Decisão: o widget não usa cookie nenhum.**

- As rotas `/embed/*` ficam **fora do grupo `web`** (`routes/embed.php`, carregado em `bootstrap/app.php` com `then:`): sem sessão do app, sem cookie, sem CSRF. CSRF não se aplica porque a credencial — `Authorization: Bearer {token de execução}` — não é enviada sozinha pelo navegador.
- O token de execução vive **só na memória** do widget (nem `localStorage`, nem `sessionStorage`, nem URL). Recarregar o iframe exige uma URL nova — é o comportamento desejado para uma credencial de uso único.
- O que no fluxo por e-mail mora na sessão Laravel do navegador (o token da `signing_sessions` e o portão do PIN) é guardado, **cifrado com a APP_KEY** (`encrypted:array`), na própria linha da sessão embutida. A cada requisição autenticada, `AuthenticateEmbeddedSession` + `EmbedSessionStore` montam uma sessão Laravel NOVA, em memória, com apenas essas duas chaves deste participante. Resultado: `SignerSessions`, `SenderPins`, `Challenges` e `RecordAcceptance` funcionam **sem nenhuma alteração**, e o cookie de sessão do painel nunca é lido nem escrito pelo widget (teste: estar logado no painel não autentica o widget).
- O cookie `__Host-…; SameSite=None; Secure; Partitioned` ficou **fora**: não traria nada que o cabeçalho não traga, e seria mais uma superfície.

### 3.4 O que o widget não oferece (decisão documentada)

| Recurso                                                                              | No widget                                                         | Por quê                                                                                                                        |
| ------------------------------------------------------------------------------------ | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Foto ou vídeo exigidos (§2.10, §3.3)                                                 | recusado na criação (`embedded-unsupported`) e tela `unsupported` | câmera em iframe de terceiro exige `allow="camera"` do site e a decisão jurídica da viabilidade §4.4 item 20 continua pendente |
| Visualizador (papel `viewer`)                                                        | recusado na criação                                               | não há aceite a registrar                                                                                                      |
| Download do comprovante e do arquivo final                                           | removido                                                          | escopo restrito; a cópia final chega por e-mail, como no fluxo normal                                                          |
| Certificado do participante (A1), componente local (A3), devolução gov.br, delegação | não aparecem                                                      | cada um tem fluxo próprio e janela de download; seguem pelo link do e-mail                                                     |
| Multilíngue                                                                          | só PT-BR                                                          | a tradução depende do idioma da sessão do navegador; fica para quando o widget tiver sessão de idioma própria                  |
| "Lembrar dispositivo"                                                                | não existe                                                        | nenhuma persistência no navegador                                                                                              |

O documento que está sendo assinado pode ser baixado pelo botão "Baixar PDF" do visualizador (é o mesmo arquivo exibido); nada além dele.

### 3.5 Estado da tela (`GET …/estado`, `embed.state`)

`EmbedScreenProps` usa **exatamente** `SignerPageProps::build` (dado mínimo por etapa, declaração, token de autorização preso ao snapshot) e ajusta o escopo: a URL do PDF de cada documento vira `embed.document`; toda URL `/assinar/{token}/…` é removida (o token do convite nunca chega ao widget — o contexto usa um marcador fixo); `receipt.can_download=false`. Telas extras: `unavailable` (convite revogado, fora da vez), `unsupported` (§3.4), `revoked`.

### 3.6 Ações

| Rota                          | Nome               | Serviço reutilizado                                                                                                                                                                                   |
| ----------------------------- | ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| POST `…/codigo`               | `embed.otp.send`   | `Challenges::send` (limites por link e por IP do fluxo público)                                                                                                                                       |
| POST `…/codigo/verificar`     | `embed.otp.verify` | `Challenges::verify`                                                                                                                                                                                  |
| POST `…/pin`                  | `embed.pin.verify` | `SenderPins::verify`                                                                                                                                                                                  |
| GET `…/documentos/{document}` | `embed.document`   | `DocumentStorage::stream`; só a versão congelada de um documento **deste** envelope, só com a sessão do código viva; marca `document.presented` (payload com `channel: embedded` e a sessão embutida) |
| POST `…/assinar`              | `embed.complete`   | `RecordAcceptance::handle` (`EmbedAcceptanceRequest` = forma do aceite público + `interaction`)                                                                                                       |
| POST `…/recusar`              | `embed.refuse`     | `RecordRefusal::handle`                                                                                                                                                                               |

Todas com `Cache-Control: no-store` e limite por rota com prefixo próprio (`throttle:N,M,embed-…`).

## 4. Origens permitidas

Tabela `integration_settings` (migration `2026_09_14_170002`), uma linha por organização: `allowed_origins` (JSON) e `updated_by_user_id`. Regras em `App\Services\Embed\AllowedOrigins`:

- forma canônica igual à que o navegador serializa em `event.origin` e a CSP compara: `https://host[:porta]`, host em minúsculas e em ASCII (IDN → punycode), porta padrão removida;
- **recusados**: curinga, `http` (salvo loopback), caminho, query, fragmento, usuário/senha, IP literal (inclusive em hexadecimal/octal), host sem ponto, porta 0;
- `http://localhost`, `http://127.0.0.1` e `http://[::1]` só **fora de produção** — e a leitura também normaliza, então uma origem de loopback copiada para produção simplesmente não vale;
- até `max_allowed_origins` (20) por organização; um item inválido recusa a lista inteira.

Tela: **API e integrações → Widget de assinatura** (`GET/PUT /api-integracoes/widget`, `integrations.embed.edit|update`, página `embed/origins`), só com `manage_integrations`. A trilha da organização registra `embed_origins.updated` (acrescentadas, removidas, total). Tirar uma origem da lista derruba na hora as sessões dela (`410 origin_removed`, página 404).

## 5. Cabeçalhos e anti-clickjacking

`App\Http\Middleware\SecurityHeaders` chama, no fim, `App\Services\Embed\Http\EmbedSecurityHeaders::apply` — que **só age em `/embed/*`**:

- toda resposta `/embed/*`: `Referrer-Policy: no-referrer` e `X-Robots-Tag: noindex`;
- **página do widget com sessão encontrada** (marcada por `EmbedFrame::allowFramingBy`): `X-Frame-Options` **omitido** (só aceita `DENY`/`SAMEORIGIN` e, com `DENY`, contradiria a CSP), `frame-ancestors {origem exata}` no lugar de `'none'`, `blob:` em `connect-src` (o PDF é baixado com o cabeçalho e entregue ao PDF.js como `blob:`), `Cache-Control: no-store`. Se a CSP estiver desligada ou em report-only pela configuração, uma CSP **aplicada** só com `frame-ancestors` é emitida mesmo assim — report-only não bloqueia enquadramento, e sem `X-Frame-Options` a página ficaria enquadrável por qualquer um;
- `embed.js`: `Cross-Origin-Resource-Policy: cross-origin` (é carregado por outra origem);
- **todo o resto** — inclusive JSON e 404 de `/embed/*` — continua `DENY` + `frame-ancestors 'none'`. Teste: percorre todas as rotas GET sem parâmetro com a flag ligada.

No widget (`resources/js/pages/embed/sign.tsx`):

- recusa operar **fora de iframe** e, onde o navegador expõe `location.ancestorOrigins`, com ancestral diferente da origem da sessão;
- recusa operar com a **página oculta** (`document.visibilityState !== 'visible'`) ou com o iframe **menor que o mínimo** (`min_frame_width` × `min_frame_height`, 320 × 420): mostra o aviso, desabilita as ações e informa o site (`assinavelox:error`, `frame_too_small`, não fatal);
- o aceite final exige a **confirmação visual**: um painel próprio (sem animação, transformação ou opacidade no caminho do botão), aberto **na altura do clique** — num iframe alto, o centro dele pode estar fora da tela do site —, cujo botão "Confirmar e assinar" só habilita depois de `confirm_delay_ms` (800 ms) **com visibilidade real** (medida contra a tela do site, não só a do iframe):
    - **IntersectionObserver v2** (`trackVisibility: true`, `delay: 100`) sobre o **cartão inteiro** da confirmação (título, texto e botões): `isVisible` só é verdadeiro sem nada por cima, sem opacidade, filtro ou transformação — exatamente o que um site mal-intencionado precisaria fazer para enganar o clique. (O alvo é o cartão, e não o botão, porque o botão desabilitado tem opacidade reduzida e o v2 o trataria como invisível para sempre — achado do teste de navegador.);
    - se o botão fica parcialmente fora da tela do site (iframe mais alto que a janela), o cartão **se move** pela parte cortada que o próprio observador informa (`intersectionRect` × `boundingClientRect`, até 8 ajustes) até ficar inteiro na parte visível. Só a posição muda; a exigência de visibilidade real continua a mesma. Totalmente fora da tela, ele não se move (não há como saber para onde) e a pessoa rola a página;
    - **no instante do clique** a visibilidade é medida de novo (revisão adversarial): o widget puxa os registros do observador que ainda não foram entregues (`takeRecords()`) e exige o intervalo contado desde o primeiro registro visível **depois do último invisível** — uma sobreposição posta pouco antes do clique zera o intervalo, mesmo que o estado da tela ainda não tenha sido atualizado;
    - **heurística onde o v2 não existe** (Firefox, Safari): botão 100% dentro da área visível do iframe (`intersectionRatio ≥ 0,99`), janela com foco (`document.hasFocus()`), aba visível, clique confiável (`event.isTrusted`) e o intervalo mínimo. É mais fraca que o v2 — **não detecta sobreposição nem opacidade**. Sem o v2, o `frame-ancestors` exato é a **única** proteção contra uma página por cima do widget;
    - as etapas **antes** da confirmação (caixa de consentimento, desenho, código, botão "Assinar documento") só são bloqueadas com a página oculta ou o iframe pequeno demais, **sem** medição de visibilidade. Decisão: nenhuma delas registra aceite — o registro só sai da confirmação medida acima. Um bloqueio por v2 nesses passos fica para depois da validação em navegadores reais (§11);
- o servidor exige `interaction.confirmed` e `interaction.visible` no aceite. **Não é fronteira de segurança** (quem tem o token de execução poderia forjar); garante que o widget nunca registra um aceite por um caminho que pulou a confirmação.

## 6. Protocolo de mensagens (`postMessage`)

Forma: `{ type, v: 1, session: "<ULID da sessão>", payload }`. **Nunca** dado pessoal. A **única** mensagem que leva um segredo é `assinavelox:token` (abaixo); as demais levam só estado, códigos e ids públicos.

| Direção       | `type`                  | `payload`                                                                                  |
| ------------- | ----------------------- | ------------------------------------------------------------------------------------------ |
| widget → site | `assinavelox:boot`      | `{}` — o widget pede o token de uso único (só quando a URL do iframe veio sem o fragmento) |
| widget → site | `assinavelox:ready`     | `{ screen }` — enviado quando o estado chega e em resposta a `ping`                        |
| widget → site | `assinavelox:completed` | `{ status }` (`completed` \| `finalizing` \| `already_signed_pending_others`)              |
| widget → site | `assinavelox:refused`   | `{}`                                                                                       |
| widget → site | `assinavelox:error`     | `{ code, message, fatal }`                                                                 |
| widget → site | `assinavelox:resize`    | `{ height }`                                                                               |
| site → widget | `assinavelox:token`     | `{ token }` — resposta ao `boot`, **uma vez**; depois o `embed.js` esquece o token         |
| site → widget | `assinavelox:ping`      | `{}`                                                                                       |

Regras nos dois lados: `targetOrigin` sempre **exato** (widget → `parent_origin` da sessão; `embed.js` → origem da URL do widget), nunca `"*"`. O `embed.js` só aceita mensagem com `event.origin` igual à origem do widget **e** `event.source` igual ao `contentWindow` do **seu** iframe (outro iframe da mesma origem é ignorado), `v` igual e `session` igual. O widget só aceita `ping` e `token` com `event.origin` igual à origem da sessão, `event.source === window.parent`, `v` igual **e** `session` igual — mensagem sem `session` é ignorada (revisão adversarial: com dois widgets da mesma origem no site, um `ping` sem id não pode ser respondido por todos).

**Por que o token vai por mensagem** (revisão adversarial): com `iframe.src = url` (fragmento `#t=` incluído), o token ficava no atributo `src`, no DOM do site, ao alcance de scripts de terceiros e de gravadores de sessão — e, se a troca não acontecesse, valia por até 15 min. Agora o `embed.js` carrega o iframe **sem** o fragmento e só entrega o token ao **próprio** iframe, para a origem exata do widget, em resposta ao `boot`. A URL completa posta direto num iframe (sem o `embed.js`) continua funcionando pelo fragmento. O site não deve registrar em log a `url` devolvida pela API.

Códigos de erro (`code`): `invalid_link`, `link_used`, `link_expired`, `link_revoked`, `unauthenticated`, `session_expired`, `session_revoked`, `origin_removed`, `not_framed`, `frame_too_small` (não fatal), `unavailable`, `unsupported`, `revoked`, `expired`, `canceled`, `network`, e no `embed.js` `timeout` (o widget não respondeu — tipicamente origem fora da lista, bloqueada pela CSP).

## 7. Trilha (append-only, T7)

Acrescentados no fim de `AuditEventType` (e na lista fechada do `EnumCatalogTest`):

| Evento                     | Onde                             | Ator             | Payload                                                                               |
| -------------------------- | -------------------------------- | ---------------- | ------------------------------------------------------------------------------------- |
| `embedded_session.created` | envelope + participante          | criador do token | `session`, `origin`, `expires_at`, `channel: api`, `reissued` (repetição idempotente) |
| `embedded_session.opened`  | envelope + participante          | participante     | `session`, `origin`                                                                   |
| `embedded_session.revoked` | envelope + participante          | criador do token | `session`, `reason`                                                                   |
| `embed_origins.updated`    | organização (`envelope_id` nulo) | usuário          | `added`, `removed`, `count`                                                           |

Nenhum payload leva token, URL, digest ou estado. O `document.presented` do widget ganha `channel: embedded` e `embedded_session`.

## 8. Configuração (`config/assinavelox.php`)

`features.embedded_signing` (`ASSINAVELOX_FEATURE_EMBEDDED_SIGNING`) e a seção `embedded_signing`:

| Chave                                     | Padrão    | Env                                                  |
| ----------------------------------------- | --------- | ---------------------------------------------------- |
| `url_ttl_seconds` / `url_ttl_max_seconds` | 300 / 900 | `ASSINAVELOX_EMBED_URL_TTL_SECONDS`, `…_MAX_SECONDS` |
| `runtime_ttl_minutes`                     | 30        | `ASSINAVELOX_EMBED_RUNTIME_TTL_MINUTES`              |
| `max_live_per_recipient`                  | 5         | `ASSINAVELOX_EMBED_MAX_LIVE_PER_RECIPIENT`           |
| `max_allowed_origins`                     | 20        | `ASSINAVELOX_EMBED_MAX_ALLOWED_ORIGINS`              |
| `min_frame_width` / `min_frame_height`    | 320 / 420 | `ASSINAVELOX_EMBED_MIN_FRAME_WIDTH`, `…_HEIGHT`      |
| `confirm_delay_ms`                        | 800       | `ASSINAVELOX_EMBED_CONFIRM_DELAY_MS`                 |

## 9. `embed.js`

Fonte: `resources/js/embed/embed.ts`, **sem importações**, entrada própria em `vite.config.ts`. O build gera um arquivo único com hash; a rota `embed.script` (`GET /embed/v1/embed.js`) o serve num endereço **estável e versionado pelo caminho** (`/v1/`), com cache de 5 minutos. Uma mudança incompatível do protocolo ganha `/v2/` e a v1 continua servida. Em desenvolvimento com o Vite ligado, a rota redireciona para o dev server.

O iframe é carregado com a URL **sem** o fragmento `#t=`: o token de uso único fica só na memória do `embed.js` e é entregue ao widget uma vez, por mensagem (§6), e então esquecido — nunca no atributo `src`, no DOM do site.

API: `AssinaVelox.mount({ url, container, onReady, onCompleted, onRefused, onError, autoResize = true, minHeight = 640, readyTimeout = 20000, title })` → `{ iframe, session, ping(), destroy() }`. Opções inválidas lançam `Error("AssinaVelox.mount: …")` com a causa em português; erros em tempo de execução chegam em `onError({ code, message, fatal, session })`. O iframe nasce com `sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-downloads"` (o widget não navega o site hospedeiro) e `referrerpolicy="no-referrer"`.

### Exemplo de página hospedeira

```html
<!-- Página do cliente, servida em https://app.cliente.com.br (origem cadastrada). -->
<div id="assinatura"></div>
<script src="https://assinavelox.com.br/embed/v1/embed.js"></script>
<script>
    // `urlDaSessao` vem do SEU servidor, que chamou a API com o token (o token da API nunca vai
    // para o navegador):
    //   POST /api/v1/envelopes/{envelope}/recipients/{recipient}/embedded-sessions
    //   Idempotency-Key: <uuid>
    //   { "origin": "https://app.cliente.com.br" }
    const widget = AssinaVelox.mount({
        url: urlDaSessao,
        container: '#assinatura',
        onReady: (e) => console.log('widget pronto', e.screen),
        onCompleted: (e) => {
            // Só estado: confirme o resultado no seu servidor (GET da sessão ou webhook).
            console.log('aceite registrado', e.session, e.status);
        },
        onRefused: (e) => console.log('recusado', e.session),
        onError: (e) => {
            console.error(e.code, e.message);
            if (e.fatal) widget.destroy();
        },
    });
</script>
```

### 9.1 Página de exemplo para QA (revisão adversarial)

`docs/fase-3/exemplos/widget-hospedeiro.html` é uma página hospedeira **autocontida**, fora do app, para ver o widget em outra origem sem escrever código: sirva a pasta com `php -S 127.0.0.1:8200 -t docs/fase-3/exemplos`, cadastre a origem `http://127.0.0.1:8200` (loopback só fora de produção), crie a sessão pela API com essa origem e cole a `url`. A página escolhe a largura (375 px, 768 px ou total), mostra os eventos e apaga a URL do campo ao montar. Percorrer o widget nela em 375 px faz parte da validação do §11 antes de ligar a flag.

A mensagem `completed` é um aviso de interface, não prova: o site deve confirmar pelo `GET` da sessão, pelos eventos do envelope ou pelos webhooks (`recipient.signed`), que continuam valendo.

## 10. Testes

- `tests/Feature/Phase3/Embed/ApiSessionTest.php` — criação (token só por digest, nada em claro no banco, na idempotência, na trilha ou no log da API), idempotência (URL nova, pedido diferente, sessão usada), token de outra organização (404), participante de outro envelope (404), origens fora da lista e fora do formato (422), forma canônica, fora da vez, sem convite, envelope cancelado, ability, flag global e do plano, consulta, revogação, teto.
- `WidgetFlowTest.php` — troca única, sem cookie em nenhuma resposta, URL vencida, token de execução obrigatório e sem convite/`/assinar` no estado, fluxo completo (código → PDF com cabeçalho → aceite com confirmação), aceite sem confirmação (422), escopo (PDF sem código e de outro envelope: 404), recusa, **PIN atravessando requisições pela sessão isolada**, vencimento do token de execução, origem descadastrada, convite revogado, sessão do painel não autentica o widget.
- `FrameHeadersTest.php` — cabeçalhos da página do widget, 404 com DENY, CSP desligada/report-only, JSON com DENY, **todas as rotas GET sem parâmetro continuam DENY com a flag ligada**, `embed.js` com CORP.
- `OriginsTest.php` — normalização, recusas, produção sem loopback, tela (404/403), gravação e trilha.
- `FlagOffTest.php` — flag global e do plano desligadas.
- `tests/Browser/EmbedWidgetTest.php` — o servidor de teste responde em `127.0.0.1` e em `localhost` (origens diferentes): página hospedeira permitida carrega e recebe `assinavelox:ready`; origem não permitida é bloqueada pela CSP (nenhuma troca acontece; o `embed.js` avisa `timeout`); mensagens forjadas — da própria página e de outro iframe da MESMA origem do widget — são ignoradas nos dois sentidos.
- Smoke (`AllGetRoutesTest`): `embed.script`, `embed.show`, `embed.state`, `embed.document` → 404 para todos; `integrations.embed.edit` → 404 no painel. `SharedPropsTest` ganha `embedded_signing: false`.

## 11. O que falta para ligar em produção

1. **Decisão de produto e contrato**: quais planos incluem o widget (`plans.features.embedded_signing`) e se o cliente pode usá-lo sem e-mail de convite (hoje o convite continua saindo por e-mail; o widget é um caminho a mais).
2. **HTTPS e domínio**: `APP_URL` em HTTPS; a origem do widget é a do app.
3. **Validação em navegadores reais** (checklist manual por release): Chrome/Edge (IO v2), Firefox e Safari (heurística), iOS Safari em iframe, com e sem bloqueio de cookies de terceiros; site hospedeiro com CSP própria (`frame-src` precisa liberar a origem do app e `script-src` o `embed.js`).
4. **Revisão jurídica** do texto da confirmação visual e da página de evidências mencionando que o aceite ocorreu dentro de site integrado (o evento `embedded_session.opened` já registra a origem).
5. **Monitoramento**: taxa de `timeout` e `frame_too_small` no `embed.js` (hoje só no console do cliente).
6. O participante com foto/vídeo exigidos, A1/A3/gov.br ou delegação continua pelo link do e-mail (§3.4).

Nada disto depende de credencial externa: o widget é classe A (padrões web + API congelada).
