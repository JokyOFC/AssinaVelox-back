# Fase 2 — Assinatura presencial em tablet e assinatura em lote (C-PRES)

Roadmap §2.6 e §2.7; viabilidade §1.1 (ambos **classe A**) e §4.5. Data: 11/09/2026. Nenhum
commit foi feito. As duas flags nascem **desligadas** (`in_person`, `batch_signing`); com elas
desligadas, nada muda no produto.

## 1. O que é e o que não é

| Recurso    | É                                                                                                                                                                                                                                                                                                        | Não é                                                                                                                                                             |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Presencial | Várias pessoas registram, **cada uma**, o próprio aceite no mesmo dispositivo. Cada uma confirma o código enviado pelo canal DELA (e-mail por padrão; SMS/WhatsApp se o remetente escolheu) e o PIN, se houver. A evidência diz "presencial, na presença de {anfitrião}", com o dispositivo e o momento. | Assinatura "atestada" pelo anfitrião, autenticação pelo anfitrião, identidade verificada, biometria ou foto obrigatória. O anfitrião **nunca** é autor do aceite. |
| Lote       | Um código por e-mail abre a **lista** de documentos pendentes da mesma organização remetente; cada documento é aberto, revisado e autorizado **separadamente**, e cada autorização grava o aceite só daquele documento.                                                                                  | "Aceitar todos", consentimento genérico, consentimento para documentos futuros, assinatura criptográfica por item (depende da onda C).                            |

Semântica (arquitetura §2, T1): o código prova a **posse do canal**, não a identidade. O PIN é um
segredo combinado pelo remetente. O aceite continua sendo o aceite eletrônico com evidências de
sempre, gravado pelo mesmo `RecordAcceptance`.

## 2. Presencial em tablet

### 2.1 Quem abre

O **anfitrião** é um membro da organização com permissão de **enviar** aquele envelope
(`InPersonSessionPolicy::start` = `EnvelopePolicy::send`: ver o envelope, `send_envelopes` e ser o
criador, ou `manage_any_envelope`, ou gerenciar a pasta). A tela `presencial/iniciar` lista os
envelopes `in_progress` que ele pode enviar e as sessões ativas que ele pode encerrar.
Encerrar: o próprio anfitrião, quem pode enviar o envelope ou quem tem `cancel_any_envelope`.

### 2.2 Ciclo de vida

1. **Abrir** (`POST presencial`): envelope `in_progress` da organização corrente, nome do
   dispositivo (vai para a evidência). O segredo do dispositivo (32 bytes) fica **só na sessão
   Laravel** do navegador; o banco guarda o SHA-256 (`in_person_sessions.device_secret_digest`).
2. **Desconectar o anfitrião** (padrão): antes de o dispositivo ir para os participantes, a conta
   do anfitrião sai deste navegador (`logout` + `invalidate`). Assim ninguém na fila chega ao painel
   da conta. A opção "Manter minha conta conectada" existe para quem usa o próprio computador; ela
   não dá ao anfitrião nenhum poder sobre o aceite (teste `o anfitrião não consegue registrar aceite
pelo participante`).
3. **Usar**: cada requisição do dispositivo resolve a sessão pelo segredo e renova
   `last_activity_at`.
4. **Expirar** (verificado a cada requisição e na tela do anfitrião): sem atividade por
   `in_person.idle_minutes` (15), teto `in_person.max_hours` (8), envelope fora de `in_progress`
   (todos concluíram, cancelado, expirado) ou flag desligada. A página do dispositivo também
   recarrega sozinha no prazo de inatividade.
5. **Encerrar**: pelo anfitrião de qualquer dispositivo dele (`POST presencial/{sessão}/encerrar`)
   ou pelo próprio dispositivo (`POST presencial/encerrar`). Encerrada, não reabre. No
   dispositivo, "Encerrar sessão presencial" pede confirmação no `ConfirmDialog` do design
   system (nunca a caixa nativa do navegador), dizendo que a sessão termina para todos — quem
   ainda não assinou perde a vez neste dispositivo — sem mostrar nome ou dado de participante.

### 2.3 A vez de cada participante e o isolamento

A tela **bloqueada** mostra a fila (nome, papel e estado — nada mais). A pessoa toca no próprio
nome (`POST presencial/participante`) e abre-se a **vez** dela (`in_person_turns`), com um segredo
próprio guardado na sessão do dispositivo. A partir daí o fluxo é o do link individual, pelo
contexto DELA:

| Etapa                                        | Serviço existente reutilizado                                                                     |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| Pedir e confirmar o código                   | `Challenges::send` / `Challenges::verify` (limites por link e por IP, HMAC, 10 min, 5 tentativas) |
| PIN do remetente                             | `SenderPins::verify` (bloqueio, tentativas)                                                       |
| Ver o documento                              | `Sign\DocumentController::show` (versão congelada, marca de apresentação por sessão e documento)  |
| Foto exigida (C-ID, flag `identity_capture`) | `Sign\CaptureController::store`                                                                   |
| Registrar o aceite                           | `RecordAcceptance::handle` (revalidação sob lock, snapshot, `UNIQUE(recipient_id)`)               |

**Toda fronteira de vez** (outro participante chamado, aceite registrado, "Não sou {nome} —
bloquear tela", inatividade, encerramento) passa por `InPersonTurns::close()`/`finish()`, que:

1. **revoga no banco** a sessão de assinatura da vez e toda sessão que ESTE navegador iniciou para
   o participante (inclusive a pendente do portão do PIN) — é o que vale contra uma cópia antiga do
   cookie;
2. **invalida os códigos vivos** pedidos durante a vez (sem isso, um código ainda válido
   reautenticaria a sessão pendente revogada, porque `Challenges::verify` promove a sessão do
   desafio). Ao **abrir** uma vez, os códigos anteriores do participante também morrem: o código
   que vale é o pedido na vez;
3. **apaga todo o estado de signatário** da sessão do dispositivo (`signer.*`: sessões, portão do
   PIN, janela de download) e o segredo da vez, e troca o id da sessão;
4. **zera o `turn_secret_digest`**: a vez encerrada nunca reabre.

A sessão de assinatura só é aceita se foi autenticada **nesta vez** (`authenticated_at` ≥ início da
vez) e é a registrada na vez (`signing_session_id`). Na página, a troca remonta o componente
(`preserveState: false`) e nada fica em memória.

### 2.4 Telas (`pages/in-person/kiosk.tsx`, `pages/in-person/start.tsx`)

`kiosk` usa a casca pública do signatário (`SignerLayout`, via `KioskShell`), sem menu e sem conta:

- `unavailable`: interruptor global desligado;
- `none`: nenhum dispositivo presencial ativo neste navegador (com o motivo do último
  encerramento, quando houver);
- `queue`: fila bloqueada; depois de um aceite, faixa "Aceite registrado. A tela foi bloqueada —
  devolva o dispositivo";
- `participant`: `identify` (código) → `pin` → `sign` (documento, campos, representação visual,
  fotos se exigidas, aviso de privacidade, declaração de aceite). O botão "Não sou {nome} —
  bloquear tela" aparece em todas as etapas.

`start` (painel, `AppLayout`): com a flag desligada, o estado "Fase 2"; ligada, escolher documento
e dispositivo, "Como funciona" e a lista de sessões ativas com "Encerrar".

### 2.5 Evidência

- `acceptance.recorded` continua com o **participante** como ator e o método que ELE confirmou
  (`email_otp`, `sms_otp`, `whatsapp_otp`; PIN nos eventos `challenge.pin_*`).
- `in_person.acceptance_recorded` (ator: o participante) liga o aceite à sessão presencial:
  `acceptance_ulid`, `in_person_session`, `turn`, `host_user_id`, `device_label`, `auth_method`.
- `in_person.session_started` (ator: o anfitrião), `in_person.participant_started`,
  `in_person.participant_closed` (motivo) e `in_person.session_ended` (motivo, quantos aceites).
- `InPersonEvidence::forEnvelope($envelope)` devolve, por aceite presencial: `mode = in_person`,
  rótulo "Presencial, na presença de {anfitrião}", anfitrião, dispositivo, início da sessão,
  momento do aceite, ULID do aceite e método. **Integração pendente** na página de evidências
  (§6).

### 2.6 Canais, PIN e foto

- Código por SMS/WhatsApp: segue a disponibilidade do C-CAN (simulador identificado fora de
  produção; produção desabilitada). Nada muda aqui.
- PIN: pedido depois do código, digitado pelo participante (teste com PIN no dispositivo).
- Foto (C-ID): **não obrigatória por padrão** (decisão pendente, viabilidade §4.5 item 30). Se o
  remetente exigiu e a flag `identity_capture` está ligada, a etapa aparece no dispositivo e o
  envio usa `presencial/captura/{kind}`. Limitação: a `Permissions-Policy` do C-ID só libera a
  câmera nas rotas `sign.*`; até a integração, no dispositivo presencial vale o envio por arquivo
  (§6, pendência 5).

## 3. Assinatura em lote

### 3.1 Decisões aplicadas (viabilidade §4.5)

- Lote só da **mesma organização remetente** (recomendado). Documento de outra organização com o
  mesmo e-mail nunca entra, nem pelo ULID do item.
- Autenticação do lote: **código por e-mail**, para o e-mail do participante (o lote agrupa por
  e-mail; é esse canal cuja posse se prova).
- Itens cujo remetente pediu autenticação diferente — código por **SMS/WhatsApp**, **PIN** — ou
  **foto** ficam fora da autorização em lote: aparecem como "Abrir pelo link individual". O lote
  nunca rebaixa a autenticação escolhida pelo remetente.

### 3.2 Emissão

`POST documentos/{envelope}/destinatarios/{recipient}/lote` (mesma permissão do reenvio do
convite: `update` do envelope). O serviço `BatchLinks`:

1. confere que o participante tem aceite pendente e é a vez dele;
2. reúne as participações pendentes da **mesma organização** com o mesmo e-mail (sem diferenciar
   maiúsculas), com as regras do link individual (`ParticipantContexts`): papel que registra
   aceite, envelope `in_progress`, vez, convite ativo; mínimo `batch_signing.min_items` (2), teto
   `max_items` (50);
3. **congela os itens** (`batch_signing_items`): documento enviado depois não entra — ninguém
   consentiu com ele;
4. revoga o link de lote anterior da mesma pessoa na mesma organização;
5. envia o e-mail com o link (canal rastreado, fila cifrada) e grava `batch.link_issued` no
   envelope de origem (ator: o remetente; payload: ULID do lote, quantidade, validade).

O remetente vê **quantos** documentos entraram, não quais (a lista pode ter envelopes que ele não
enxerga). O link vale `batch_signing.link_ttl_days` (7) dias e morre se o e-mail do participante
mudar (`email_digest` é um HMAC do e-mail normalizado).

### 3.3 Código e navegador

- `GET assinar/lote/{token}` troca o token do e-mail por uma entrada na sessão e redireciona para
  `assinar/lote`: o token sai da barra de endereços e do `Referer`. Token desconhecido, revogado ou
  vencido: 404 genérico.
- Antes do código a página mostra só a organização, o primeiro nome, o e-mail mascarado e a
  quantidade — nenhum título.
- `BatchChallenges`: mesmas garantias do código individual (6 dígitos por CSPRNG, só o HMAC
  `Challenges::hashCode` com o ULID da linha como sal, 10 min, 5 tentativas, uso único, um código
  vivo, 60 s entre envios, 5/h por link, 30/h por IP).
- Confirmado o código, o lote fica autenticado **neste navegador** por
  `batch_signing.session_ttl_minutes` (30): token bruto na sessão Laravel, digest no banco.
  Autenticar em outro navegador encerra este. "Sair da lista" revoga as sessões dos itens abertos.

### 3.4 Autorização por item

- **Abrir** um item (`POST .../itens/{item}/abrir`) cria a sessão de assinatura DAQUELE envelope
  (`signing_sessions`, a mesma do fluxo individual), autenticada pelo código do lote, e grava na
  trilha do envelope `session.started` (com `batch`) e `batch.item_opened` (lote, item, código
  confirmado).
- **Autorizar** (`POST .../itens/{item}/autorizar`) chama `RecordAcceptance::handle` com o
  contexto, a sessão e o payload daquele item: aceite próprio, com snapshot, campos, documentos,
  IP, navegador e revalidação sob lock próprios. O token de autorização é o daquela tela, preso ao
  snapshot daquele envelope: o de um item não serve para outro (`stale_presentation`).
- **Não existe "autorizar todos"**: cada POST autoriza exatamente o item do caminho; qualquer lista
  no corpo é ignorada.
- **Falha num item** (campo obrigatório, envelope cancelado, sessão vencida) fica registrada
  naquele item (`last_error_code`, `batch.item_failed`) e não toca nos demais.
- **Corrida**: duas autorizações do mesmo item (duas abas, ou o lote e o link individual ao mesmo
  tempo) gravam um aceite só — a revalidação sob lock e o `UNIQUE(recipient_id)` decidem, e a
  outra recebe "aceite já registrado".
- Itens **expirados, recusados, cancelados, encerrados ou fora da vez** aparecem com o estado e
  sem botão.

### 3.5 Telas (`pages/sign-batch/show.tsx`)

`unavailable` | `none` (sem link neste navegador) | `invalid` (404) | `identify` | `list` (um
cartão por documento, com estado e "Abrir e revisar") | `item` (o mesmo painel de revisão e aceite
do presencial, "Autorizar este documento", "Voltar para a lista").

**Aviso de privacidade do lote** (`privacy` em `identify` e `list`, `BatchPageProps::noticeRecipient()`):
condicional ao que o lote de fato pede. O lote só autoriza itens com código por e-mail, sem PIN e sem
fotos; os demais vão para o link individual, cujo aviso por participante diz o que eles pedem. Entre os
autorizáveis, o único complemento possível é o campo CPF: com um item autorizável que tem campo `cpf`, o
aviso (e a versão, com o sufixo `+b1`/`+b2`) é o de `ConsentText` para aquele participante — "o CPF que
você digitar no documento, conferido apenas pelos dígitos" (ou a consulta cadastral, com `cpf_lookup`) e
"Não pedimos senha, foto ou localização." Sem item com CPF, o aviso padrão. Revisão adversarial da onda
B: antes, a tela de código dizia "Não pedimos senha, CPF, foto ou localização." com um item de CPF no
mesmo lote. O item aberto (`current.privacy`) continua com o aviso do seu participante.

### 3.6 Extensão criptográfica (onda C)

A assinatura com o A1 do participante por item (§2.12) é extensão: cada item já tem sessão,
snapshot e aceite próprios, então a assinatura criptográfica entraria item a item, no mesmo ponto
em que entrará no fluxo individual. Nada aqui assina com certificado.

## 4. Reuso sem mudança de comportamento

Nenhum serviço existente foi alterado. O ponto de extensão é **aditivo e local**:

- `ParticipantContexts` monta o `SignerContext` a partir do convite ATIVO do participante,
  aplicando as mesmas regras do `SignerLinkResolver` (coerência, rascunho, revalidação do prazo,
  prazo próprio do link, `stateFor`). O token do convite não é recuperável; o contexto leva o
  marcador `ParticipantContexts::NO_TOKEN`, que casa com o formato da rota mas não abre convite
  nenhum.
- `Sign\DocumentController` e `Sign\CaptureController` são chamados diretamente, com o contexto e a
  sessão postos nos mesmos atributos de requisição que os middlewares `signer`/`signer.verified`
  usam.
- `ParticipantSigningProps` monta o mesmo snapshot que `RecordAcceptance` recalcula
  (`SignerPresentation::snapshotFor` + `ConsentText::statement`).

## 5. Rotas

| Método | URI                                                    | Nome                            | Grupo                                  |
| ------ | ------------------------------------------------------ | ------------------------------- | -------------------------------------- |
| GET    | `presencial/iniciar`                                   | `in_person.create`              | app                                    |
| POST   | `presencial`                                           | `in_person.store`               | app (throttle 20/min)                  |
| POST   | `presencial/{session}/encerrar`                        | `in_person.end`                 | app                                    |
| GET    | `presencial`                                           | `in_person.kiosk.show`          | público                                |
| POST   | `presencial/participante`                              | `in_person.kiosk.participant`   | público                                |
| POST   | `presencial/codigo`                                    | `in_person.kiosk.otp.send`      | público                                |
| POST   | `presencial/codigo/verificar`                          | `in_person.kiosk.otp.verify`    | público                                |
| POST   | `presencial/pin`                                       | `in_person.kiosk.pin.verify`    | público                                |
| GET    | `presencial/documento?document=`                       | `in_person.kiosk.document`      | público (404 seco sem vez autenticada) |
| POST   | `presencial/captura/{kind}`                            | `in_person.kiosk.capture.store` | público                                |
| POST   | `presencial/aceite`                                    | `in_person.kiosk.complete`      | público                                |
| POST   | `presencial/bloquear`                                  | `in_person.kiosk.lock`          | público                                |
| POST   | `presencial/encerrar`                                  | `in_person.kiosk.end`           | público                                |
| POST   | `documentos/{envelope}/destinatarios/{recipient}/lote` | `envelopes.recipients.batch`    | app                                    |
| GET    | `assinar/lote/{token?}`                                | `sign.batch.show`               | público                                |
| POST   | `assinar/lote/codigo`                                  | `sign.batch.otp.send`           | público                                |
| POST   | `assinar/lote/codigo/verificar`                        | `sign.batch.otp.verify`         | público                                |
| GET    | `assinar/lote/documento?item=&document=`               | `sign.batch.document`           | público (404 seco sem item aberto)     |
| POST   | `assinar/lote/itens/{item}/abrir`                      | `sign.batch.items.open`         | público                                |
| POST   | `assinar/lote/itens/{item}/autorizar`                  | `sign.batch.items.authorize`    | público                                |
| POST   | `assinar/lote/sair`                                    | `sign.batch.leave`              | público                                |

As rotas públicas usam `throttle:signer` e limites próprios nos POSTs sensíveis. "lote" não casa
com o `{token}` do grupo `assinar/{token}` (20..128 caracteres). Os GETs novos não têm parâmetro
obrigatório (o smoke `AllGetRoutesTest` monta URLs sem parâmetros); os dois de PDF entraram na
lista de 404 esperados do smoke, com a mesma justificativa de `sign.document`.

## 6. Contrato de front e integrações pendentes (fora da área C-PRES)

1. `HandleInertiaRequests::features()`: acrescentar `...PresenceFeatures::forOrganization($org)`
   (`in_person`, `batch_signing`) e as duas chaves em `Features` (`resources/js/types/index.ts`) e
   no `SharedPropsTest`.
2. Sidebar/detalhe do documento: atalho para a tela de início — componente pronto
   `components/in-person/start-in-person-link.tsx` (`<StartInPersonLink envelopeId=… />`, só
   aparece com a flag).
3. Aba Participantes / tela Assinaturas: "Enviar link de lote" — componente pronto
   `components/batch/send-batch-link-button.tsx` (`<SendBatchLinkButton envelopeId recipientId />`).
4. Página de evidências e `EvidenceDossier`: usar `InPersonEvidence::forEnvelope($envelope)` (modo
   presencial, anfitrião, dispositivo, momento) e rotular `batch.item_*`/`in_person.*` na linha do
   tempo (`AdminEventCatalog`, se aplicável).
5. `App\Services\Identity\CameraPermission::allows()`: liberar `camera=(self)` também em
   `in_person.kiosk.show` e `in_person.kiosk.capture.store` quando o atributo
   `IdentityCaptures::CAMERA_ATTRIBUTE` estiver marcado (o `InPersonKioskProps` já marca).
6. `SecurityHeaders::isPublicSensitivePath()`: incluir `presencial` e `presencial/*` (noindex e
   `no-referrer`; hoje não há segredo na URL, mas a página é pública).
7. `OrganizationPurge`: as cinco tabelas novas saem em cascata pelo envelope/organização
   (`cascadeOnDelete`); se a integração preferir a ordem explícita, incluir
   `in_person_turns`, `in_person_sessions`, `batch_signing_items`, `batch_signing_challenges`,
   `batch_signing_sessions` antes de `signing_sessions`/`recipients`/`envelopes`.
8. Plano/seeders: `plans.features.in_person` e `batch_signing` (e rótulos na comparação de planos).
9. `phpunit.xml`/`.env.example`: variáveis `ASSINAVELOX_FEATURE_IN_PERSON`,
   `ASSINAVELOX_FEATURE_BATCH_SIGNING`, `ASSINAVELOX_IN_PERSON_*`, `ASSINAVELOX_BATCH_*`.
10. Teste de navegador com dois participantes no mesmo dispositivo (roadmap §2.6 "Aceite"): não
    escrito; os testes de feature cobrem o isolamento (§8). O plugin de navegador tem as
    limitações de `docs/testes.md`.

## 7. Flags, configuração e tabelas

| Chave                                   | Padrão  | Uso                               |
| --------------------------------------- | ------- | --------------------------------- |
| `features.in_person`                    | `false` | global E plano                    |
| `features.batch_signing`                | `false` | global E plano                    |
| `in_person.idle_minutes`                | 15      | inatividade do dispositivo        |
| `in_person.max_hours`                   | 8       | teto da sessão                    |
| `batch_signing.link_ttl_days`           | 7       | validade do link                  |
| `batch_signing.session_ttl_minutes`     | 30      | autenticação do lote no navegador |
| `batch_signing.min_items` / `max_items` | 2 / 50  | tamanho do lote                   |

Migrations (só aditivas, MySQL-compatíveis, sem ENUM SQL, índices ≤ 64): `2026_09_11_120301`
`in_person_sessions`, `120302` `in_person_turns`, `120303` `batch_signing_sessions`, `120304`
`batch_signing_challenges`, `120305` `batch_signing_items`. Segredos: só digests (dispositivo, vez,
link, sessão do lote); código: só HMAC.

## 8. Trilha

Eventos novos no `AuditEventType` (com rótulo e tom): `in_person.session_started`,
`in_person.participant_started`, `in_person.acceptance_recorded` (ok),
`in_person.participant_closed`, `in_person.session_ended`, `batch.link_issued`,
`batch.challenge_sent`, `batch.challenge_verified` (ok), `batch.challenge_failed` (warn),
`batch.item_opened`, `batch.item_authorized` (ok), `batch.item_failed` (warn). Nenhum payload
carrega código, PIN, token, segredo do dispositivo ou e-mail completo (testes procuram por eles).

## 9. Testes

`tests/Feature/Phase2/InPerson` (flags desligadas, fluxo, evidência, anfitrião, sequencial, PIN,
isolamento, expiração, encerramento, organizações) e `tests/Feature/Phase2/Batch` (emissão, código,
itens independentes, sem "autorizar todos", token de outro item, falha isolada, trilha, outra
organização, itens encerrados, corrida, SMS/PIN fora do lote, mínimo e revogação).

Verificação (11/09/2026, execuções reais):

| Verificação                                                    | Resultado                                                                                                                                                                  |
| -------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Feature/Phase2/InPerson` + `tests/Feature/Phase2/Batch` | 29 testes, 614 asserções, todos verdes                                                                                                                                     |
| Unit + Feature (`--parallel`)                                  | 1.354 testes: 1.351 passam, 2 pulados, 1 falha — `SystemRolesTest` pela rota `settings.branding` (C-BRAND), anterior a esta área                                           |
| PHPStan nos arquivos da área                                   | 0 erros; projeto inteiro: 1 erro em `FieldGeometry.php:137` (tipos `cpf`/`stamp`, C-ID/C-BRAND)                                                                            |
| Pint (`--test`) nos arquivos tocados                           | verde                                                                                                                                                                      |
| `npm run types:check`                                          | 0 erros                                                                                                                                                                    |
| `vp check` nos arquivos da área                                | formatados, sem avisos                                                                                                                                                     |
| `npm run build`                                                | OK                                                                                                                                                                         |
| Suíte de navegador                                             | não concluiu: parada sem saída por mais de 10 min com outras suítes rodando em paralelo (mesmo sintoma do relatório da onda A §1); interrompida só a execução deste agente |

Testes existentes alterados: `Unit/Models/EnumCatalogTest` (12 eventos novos listados e
contados; nada renomeado) e `Feature/Smoke/AllGetRoutesTest` (`in_person.kiosk.document` e
`sign.batch.document` na lista de 404 esperados, pela mesma regra de `sign.document`).

## 10. Limitações e pendências

- **Jurídico**: revisar se a declaração de aceite deve mencionar o modo presencial (hoje o texto é
  o mesmo do link individual; o fato presencial fica na evidência) e o texto do e-mail de lote.
- **Produto**: foto no presencial (viabilidade §4.5 item 30) — hoje segue a exigência do remetente
  com a flag `identity_capture`; recusa no dispositivo presencial não foi implementada (quem quiser
  recusar usa o link individual); lote só por e-mail (itens com SMS/WhatsApp/PIN/foto ficam no
  individual).
- A inatividade de 15 min conta requisições ao servidor: leitura longa sem interação bloqueia a
  tela (configurável).
- Invalidar os códigos ao abrir/fechar a vez também invalida um código que o participante tenha
  pedido no mesmo instante pelo link individual (conservador por desenho).
- Teste de navegador do presencial não escrito (§6, item 10).
