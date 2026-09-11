# Fase 2, onda B — canais SMS/WhatsApp, PIN do remetente e domínios de envio (C-CAN)

Área C-CAN. Itens do roadmap: §2.9 (código por SMS/WhatsApp e PIN), §2.18 (WhatsApp, só o
contrato), §2.8 parte B (`sender_domains`) e os simuladores dos contratos reservados. Data:
11/09/2026. Nenhum commit foi feito.

Classificação (docs/fases-2-3-viabilidade.md): SMS, WhatsApp e a API de e-mail são **B**:
serviços próprios do proprietário, sem documentação. Por isso há contrato, simulador
identificado e produção **desabilitada**. O PIN do remetente é **A**: é implementado de verdade.

## 0. Flags

Todas nascem **desligadas**. Cada uma vale só quando a configuração global
(`config/assinavelox.php` › `features`) **e** `plans.features` do plano vigente dizem sim
(`App\Services\Signing\Channels\ChannelFeatures`).

| Flag                    | Variável                             | Liga                                                           |
| ----------------------- | ------------------------------------ | -------------------------------------------------------------- |
| `sms_whatsapp`          | `ASSINAVELOX_FEATURE_SMS_WHATSAPP`   | escolher código por SMS/WhatsApp e aviso de convite pelo canal |
| `pin_auth` (nova)       | `ASSINAVELOX_FEATURE_PIN_AUTH`       | o remetente define um PIN por participante                     |
| `sender_domains` (nova) | `ASSINAVELOX_FEATURE_SENDER_DOMAINS` | cadastrar e verificar domínios de envio                        |

Com as três desligadas, tudo funciona como antes: o código sai por e-mail, `phone` enviado
no sync é ignorado, o evento `challenge.sent` tem o mesmo payload e os webhooks de status
respondem 503. A flag liga a interface e as regras de preparo. Um participante que **já**
tem `sms_otp` ou PIN continua sendo atendido se a flag for desligada depois: desligar impede
criar coisas novas, mas não abandona um envelope no meio.

## 1. Semântica (arquitetura §2, T1)

- O código por SMS ou WhatsApp **prova a posse do canal**, isto é, do número que recebeu a
  mensagem. Não prova identidade.
- O PIN é um **segredo compartilhado** que o remetente combina com o participante por fora
  do sistema. É autenticação **adicional**: vem depois do código e nunca o substitui.
- Uma mensagem simulada não chegou a celular nenhum. A trilha (`challenge.sent.simulated`),
  `delivery_attempts.meta.simulated` e as props (`simulated: true`) dizem isso.
- A trilha e o evento `session.started` registram o `auth_method` real (`sms_otp`,
  `whatsapp_otp`) e `sender_pin: true` quando houve PIN.

## 2. Contratos, simuladores e produção desabilitada

| Contrato (`App\Integrations\Contracts`)                       | Simulador identificado                                      | Produção                                                                     | Binding                                          |
| ------------------------------------------------------------- | ----------------------------------------------------------- | ---------------------------------------------------------------------------- | ------------------------------------------------ |
| `SmsProvider` (estende `MessagingProvider`)                   | `Sms\FakeSmsProvider` (`sms_simulado`)                      | `Sms\HttpSmsProvider` (`sms_servico_proprio`), desabilitado                  | `channels.sms.driver`: `fake` (padrão) ou `http` |
| `WhatsAppProvider` (estende `MessagingProvider`)              | `WhatsApp\FakeWhatsAppProvider` (`whatsapp_simulado`)       | `WhatsApp\HttpWhatsAppProvider` (`whatsapp_servico_proprio`), desabilitado   | `channels.whatsapp.driver`                       |
| `SenderDomainVerifier`                                        | `Email\FakeSenderDomainVerifier` (`email_dominio_simulado`) | `Email\HttpSenderDomainVerifier` (`email_api_servico_proprio`), desabilitado | `sender_domains.verifier`                        |
| `TimestampProvider`                                           | `Timestamp\FakeTimestampProvider` (`tsa_simulada`)          | — (roadmap §2.13/§3.6)                                                       | `integrations.timestamp.driver` (só `fake`)      |
| `FiscalInvoiceProvider`                                       | `Fiscal\FakeFiscalInvoiceProvider` (`nfse_simulada`)        | — (roadmap §2.21)                                                            | `integrations.fiscal_invoice.driver` (só `fake`) |
| `CpfVerificationProvider`                                     | `Cpf\FakeCpfVerificationProvider` (`cpf_simulado`)          | `Cpf\OwnServiceCpfVerificationProvider` (C-ID)                               | fábrica do C-ID, `cpf_lookup.driver`             |
| `Services\Branding\Contracts\VerifiedSenderDomains` (C-BRAND) | —                                                           | `Email\SenderDomainVerification`                                             | ligado no `IntegrationsServiceProvider`          |

`MessagingProvider` expõe: `send(ChannelMessage)`, `status(providerMessageId)`,
`verifyStatusCallback(IncomingStatusCallback)`, `parseStatusCallback(...)`, `isConfigured()`,
`acceptsStatusCallbacks()`, `isSimulated()`, `name()` e `missingRequirements()`.
`ChannelMessage` carrega destino E.164, finalidade (`DeliveryPurpose`), template, parâmetros,
texto e a chave de idempotência. Também lista os parâmetros **sensíveis** (`code`, `url`), que
nunca vão para log nem metadado.

**Os simuladores** gravam cada mensagem no `Sms\SimulatedOutbox` e escrevem no log um aviso
`[SIMULADO] ... NÃO enviado`, só com metadados (destino mascarado, finalidade, template,
correlation_id). Nunca transmitem nada. O registro fica em memória, para os testes, e no cache
no ambiente `local`, para o desenvolvedor ler o código com
`app(App\Integrations\Sms\SimulatedOutbox::class)->recent()`. Por padrão o recibo é `unknown`:
registrar não é enviar. Os simuladores só funcionam com `channels.allow_simulated`, que por
padrão vale "fora de produção".

- O carimbo simulado tem sempre `tsa_kind = simulated`. Nunca aparece `icp_brasil` nem
  `operator`, e o token não é DER nem RFC 3161.
- A NFS-e simulada devolve `status = simulated`, sem número, código, PDF ou XML.
- O CPF simulado nunca responde `valid` sozinho: dígitos corretos dão `inconclusive`.

**Os adaptadores de produção** têm `isConfigured()` e `acceptsStatusCallbacks()` sempre
`false`, mesmo com as variáveis de `config/services.php` preenchidas. `send()` e `status()`
lançam `ProviderDisabledException` com a lista do que falta (§8). Nenhum endpoint é presumido.

## 3. Telefone, canal e método por participante (sync do wizard)

`PUT envelopes.recipients.sync` aceita, por linha:

| Campo         | Valores                                | Regra                                                                                                                                                                              |
| ------------- | -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `phone`       | texto ≤ 32                             | normalizado em E.164 (`App\Rules\PhoneE164`, libphonenumber, região `BR`); só celular (ou "fixo ou celular"). Com `sms_whatsapp` desligada e sem canal de telefone, é **ignorado** |
| `channel`     | `email`, `sms`, `whatsapp`             | canal do **convite** (`recipients.delivery_channel`; `email` = nulo). O e-mail sai sempre; `sms`/`whatsapp` mandam também um aviso com o link                                      |
| `auth_method` | `email_otp`, `sms_otp`, `whatsapp_otp` | canal do **código**. Padrão: `email_otp`                                                                                                                                           |
| `pin`         | 4 a 8 dígitos                          | só com `pin_auth`; recusa previsíveis (`1234`, `0000`, `98765`). Nunca volta em props                                                                                              |
| `remove_pin`  | booleano                               | remove o PIN                                                                                                                                                                       |

- Campo ausente mantém o valor gravado.
- Escolher SMS/WhatsApp (como método ou como canal) exige o canal **disponível agora**:
  flag ligada **e** provedor pronto. Senão, o sync responde 422 no campo
  (`recipients.N.auth_method` ou `recipients.N.channel`) com o motivo.
- SMS/WhatsApp exigem `phone` válido. A mensagem de erro fica em `recipients.N.phone`.
- O PIN é retirado da entrada logo depois da validação e não vai para `_old_input`.

## 4. Código por canal

O código por SMS/WhatsApp tem exatamente as mesmas garantias do código por e-mail
(`App\Services\Signing\Challenges`): 6 dígitos com `random_int`, `code_hash` =
HMAC-SHA256(`ulid|código`) com segredo derivado da APP_KEY, validade de 10 min, 5
tentativas, consumo único, um código vivo por vez, intervalo de 60 s, 5 envios por link por
hora e 30 por IP por hora. Os freios da rota (`throttle:otp-send`, `throttle:otp-verify`) são
os mesmos.

Diferenças:

- sai pelo provedor do canal (`Channels\ChannelDelivery`), com uma linha em
  `delivery_attempts` por tentativa (`channel`, `provider`, `to_address` = E.164,
  `purpose = otp`). A chave de idempotência enviada ao provedor é o ULID da linha;
- o status é honesto: o simulador grava `unknown`; `sent` só quando o provedor aceita;
  `delivered` só por aviso assinado ou por consulta (`ChannelDelivery::refresh`). Tempo
  esgotado (`ConnectionException`) é `unknown`, nunca sucesso;
- há um limite diário de mensagens (SMS + WhatsApp) por organização
  (`channels.org_daily_limit`, 500), por causa do custo;
- se o provedor ficar indisponível ou o celular for inválido, o código **não é gerado** e a
  tela recebe o motivo (`channel_unavailable`, `phone_missing`). Não há troca silenciosa
  para o e-mail: o método foi escolhido pelo remetente.

O código só existe na memória do processo, no parâmetro `code` enviado ao provedor e no
registro do simulador. Não fica em `auth_challenges`, `delivery_attempts`, trilha nem log.

## 5. PIN do remetente (`Channels\SenderPins`)

- **Guarda:** `recipient_pins.pin_hash = password_hash(HMAC-SHA256("{recipient.ulid}|{pin}",
segredo derivado da APP_KEY))`. O HMAC funciona como "pimenta": com um vazamento só do
  banco não dá para testar os 10⁴–10⁸ PINs offline. Nem o PIN em claro nem o seu tamanho são
  gravados. O modelo esconde `pin_hash` na serialização.
- **Fluxo:**
    1. O participante confirma o código do canal. Com PIN, a sessão continua `pending_auth` e
       ganha um portão: `signing_sessions.channel_verified_at` e `pin_gate_digest`, com o token
       bruto guardado só na sessão Laravel. O portão vale a validade do código (10 min).
    2. `POST /assinar/{token}/pin`. O PIN certo autentica a sessão (`session.started` com
       `sender_pin: true`) e abre a janela de download, como o código fazia.
- **Tentativas:** a cada `pin.max_attempts` erros (5), o PIN fica bloqueado por
  `pin.lockout_minutes` (15). O portão fecha e é preciso pedir um novo código. Depois de
  `pin.max_lockouts` bloqueios seguidos (3), o PIN fica **bloqueado de vez** (`blocked_at`) e
  só o remetente pode redefini-lo. Os contadores são por participante: um reenvio de convite
  não os zera. A rota também tem um freio por IP (`throttle:10,10,sign-pin`).
- **Trilha:** `challenge.verified` com `next_step: sender_pin`; `challenge.pin_verified`;
  `challenge.pin_failed`, com `reason` ∈ `invalid_pin`, `locked`, `blocked`, `gate_missing` e
  `attempts_left`; e `recipient.pin_updated` (`action` ∈ `set`, `removed`). Nenhum deles
  carrega o PIN.

## 6. Domínios de envio (`Email\SenderDomainRegistry`)

Tabela `sender_domains`: organização, domínio (minúsculas, ASCII), `status` ∈ `pending`,
`verified`, `failed`, `expected_records` (JSON), `provider`, `provider_domain_id` e
`is_simulated`.

- `create()` exige a flag e um verificador pronto. Recusa domínio inválido, domínio da
  plataforma (`mail.from.address`, `app.url`), domínio repetido e o que passar do limite
  (`sender_domains.max_per_organization`, 5). Grava `sender_domain.created`.
- `check()` consulta o verificador. Uma resposta inconclusiva não muda o status. As
  transições gravam `sender_domain.verified` ou `sender_domain.failed`.
- `delete()` grava `sender_domain.deleted`. `props()` alimenta a futura tela.
- **O remetente próprio só é usado com um domínio verificado de verdade.**
  `SenderDomainVerification::isVerified()` exige a flag, `status = verified` e
  `is_simulated = false` para aquela organização. Em todos os outros casos o e-mail sai pelo
  remetente padrão, com Reply-To do cliente (`Services\Branding\ParticipantMailSender`, do
  C-BRAND, que já consulta esse contrato).

## 7. Webhooks de status (contrato proposto)

`POST /webhooks/sms/status` (`webhooks.sms.status`) e `POST /webhooks/whatsapp/status`
(`webhooks.whatsapp.status`). Não passam por CSRF (`withoutMiddleware(PreventRequestForgery)`)
e usam `throttle:webhook`.

- Cabeçalhos: `X-AssinaVelox-Timestamp` (segundos Unix) e `X-AssinaVelox-Signature: v1=<hex>`,
  com hex = HMAC-SHA256(segredo, `"{timestamp}.{corpo bruto}"`). Vários `v1=` separados por
  vírgula são aceitos, para a rotação de segredo.
- Janela: |agora − timestamp| ≤ `channels.status_webhook.tolerance_seconds` (300).
- Corpo: `{"events":[{"event_id","message_id","status","occurred_at","error"}]}` ou um único
  objeto. `status` ∈ `queued`, `accepted`, `sent`, `delivered`, `read`, `failed`,
  `undelivered`, `rejected`, `expired`.
- Respostas:
    - **503** `{error: "channel_status_disabled", reason}` com o provedor sem webhook ativo.
      É o caso de hoje: produção desabilitada, ou simulador sem
      `ASSINAVELOX_CHANNELS_SIMULATED_WEBHOOK_SECRET`.
    - **401** `{error: "invalid_signature"}` com assinatura ausente ou inválida, fora da janela
      ou corpo adulterado. Nada é gravado, e o corpo nunca é lido antes da assinatura conferir.
    - **200** `{received, processed, duplicates, ignored}` nos demais casos.
- Idempotência: `channel_status_receipts` com UNIQUE(`provider`, `event_fingerprint`), e o
  fingerprint é o SHA-256 do `event_id` (ou de `message_id|status|occurred_at`). Uma
  repetição não é reprocessada.
- A tentativa é localizada por (canal, provedor, `provider_message_id`). `delivered` é
  definitivo e grava a evidência em `meta.delivery_evidence` (origem, recibo, simulado);
  `failed` não rebaixa uma tentativa já entregue.

Se o serviço próprio usar outro formato, o adaptador real o traduz para `ChannelStatusEvent`.

## 8. O que o proprietário precisa fornecer, por serviço

**SMS (serviço próprio)**

1. Documentação oficial da API: endpoint de envio e endpoint de consulta de status.
2. Esquema de autenticação e credenciais de homologação e de produção.
3. Catálogo de erros e de status (aceito, entregue, falha, devolvido).
4. Limites de envio (por segundo, por dia, por destinatário) e custo por mensagem, para os
   limites por plano.
5. Formato do webhook de status: cabeçalhos, algoritmo da assinatura, janela de tempo e
   política de reentrega.
6. Política de idempotência (chave aceita, janela de deduplicação) e o que acontece em tempo
   esgotado.
7. Remetente aprovado (número curto, número longo ou nome).

**WhatsApp Business (serviço próprio)**

1. Documentação oficial da API: envio por template e consulta de status.
2. Templates pré-aprovados por finalidade (código, convite, reenvio), com os nomes e
   parâmetros exatos. Os nomes provisórios estão em `channels.whatsapp.templates`.
3. Esquema de autenticação e credenciais de homologação e de produção.
4. Catálogo de erros e de status (aceito, entregue, lido, falha), limites e custo por conversa.
5. Formato e assinatura do webhook de status.
6. Política de idempotência e comportamento em tempo esgotado.
7. Decisão entre o número da operadora e o número do cliente como remetente.

**API do serviço de e-mail (domínios de envio)**

1. Documentação para cadastrar um domínio e consultar a sua situação.
2. Registros DNS exigidos (DKIM, SPF, Return-Path, posse) e o formato em que a API os devolve.
3. Autenticação da API e credenciais de homologação.
4. Códigos de situação e de erro, e limites de chamadas.
5. Confirmação de que o SMTP atual aceita remetente do domínio verificado (alinhamento DKIM/SPF).

**Consulta de CPF** — lista em `Cpf\OwnServiceCpfVerificationProvider::MISSING` (área C-ID).
**Carimbo do tempo** — TSA própria (roadmap §2.13) ou contrato com uma ACT (§3.6).
**NFS-e** — viabilidade §4.2 item 8.

**Decisão de produto pendente:** o PIN pode substituir o código quando `otp_required = false`?
Hoje ele nunca substitui.

## 9. Contrato para o front (estágio seguinte)

### 9.1 Wizard (passo 2) e `settings.signing`

`App\Services\Signing\Channels\ChannelAvailability::wizardProps($organization)` devolve:

```ts
type ChannelInfo = {
    channel: 'email' | 'sms' | 'whatsapp';
    label: string;
    available: boolean;
    simulated: boolean;
    provider: string;
    reason_code: 'feature_disabled' | 'provider_disabled' | null;
    reason: string | null; // motivo pronto para a tela quando indisponível
    notice: string | null; // "Ambiente de testes: as mensagens por SMS são simuladas…"
};
type ChannelsProps = {
    enabled: boolean; // flag sms_whatsapp
    channels: Record<'email' | 'sms' | 'whatsapp', ChannelInfo>;
    auth_methods: Array<{
        value: 'email_otp' | 'sms_otp' | 'whatsapp_otp';
        label: string;
        channel: string;
        requires_phone: boolean;
        available: boolean;
        simulated: boolean;
        reason_code: string | null;
        reason: string | null;
        notice: string | null;
    }>;
    pin: { enabled: boolean; min_length: 4; max_length: 8; notice: string };
    phone: { default_region: 'BR'; example: '+55 11 91234-5678' };
};
```

Por destinatário, `RecipientChannels::wizardFields($recipient)` devolve `{ phone, phone_masked,
channel, auth_method, auth_method_label, has_pin }`. O PIN nunca volta.

O envio segue §3. Erros vêm em `recipients.N.phone`, `recipients.N.auth_method`,
`recipients.N.channel` e `recipients.N.pin`.

### 9.2 Página pública (`sign/show`, tela `identify`)

`SignerAuthProps::for($context, $request)`:

```ts
type SignerAuth = {
    method: 'email_otp' | 'sms_otp' | 'whatsapp_otp';
    method_label: string;
    channel: 'email' | 'sms' | 'whatsapp';
    channel_label: string;
    destination: string; // "m•••@exemplo.com" ou "+55 •••••••5678"
    simulated: boolean; // mostrar selo "simulado"
    available: boolean;
    unavailable_reason: string | null;
    notice: string | null;
    step: 'code' | 'pin';
    pin: null | {
        required: true;
        step_active: boolean;
        min_length: number;
        max_length: number;
        attempts_left: number;
        locked_until: string | null;
        blocked: boolean;
    };
};
```

`auth_methods` passa a ser `SignerAuthProps::authMethods($context)`, por exemplo
`['sms_otp', 'sender_pin']`.

| Tela / ação      | Rota                            | Sucesso                                                                                                           | Erro (bag)                                                                                    |
| ---------------- | ------------------------------- | ----------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| Pedir código     | `POST sign.otp.send`            | flash `info` "Enviamos um código para {destino}."                                                                 | `otp`: intervalo, limites, `channel_unavailable`, `phone_missing`, limite diário              |
| Confirmar código | `POST sign.otp.verify` `{code}` | sem PIN: `success` + tela `sign`; com PIN: `info` "Código confirmado. Agora informe o PIN…" e `auth.step = 'pin'` | `code`                                                                                        |
| Informar PIN     | `POST sign.pin.verify` `{pin}`  | `success` "PIN confirmado…" + tela `sign`                                                                         | `pin`: incorreto (N restantes), bloqueio temporário, bloqueado, "Confirme primeiro o código…" |

Enquanto `step = 'pin'`, a tela continua `identify` (a sessão não está autenticada).
`pin.blocked` = mostrar "fale com quem enviou". `pin.locked_until` = contagem regressiva e
depois "peça um novo código".

## 10. Pendências de integração (fora da área C-CAN)

1. `HandleInertiaRequests::features()`: trocar `'sms_whatsapp' => false` por
   `ChannelFeatures::forOrganization($organization)`, que traz também `pin_auth` e
   `sender_domains`.
2. `SignerPageProps::build()` e `invalid()`: acrescentar `auth` (§9.2) e trocar
   `auth_methods`.
3. `EnvelopeController::edit` e `SigningController::edit`: expor `channels` (§9.1).
   `RecipientWizardResource` e `RecipientResource`: usar `wizardFields()` no lugar de
   `'channel' => 'email'`.
4. Página de evidências e comprovante (`EvidenceData`, `AcceptanceReceipt`): mostrar "Código
   por SMS" com o selo "(simulado)" quando `challenge.sent.simulated`, e "+ PIN do remetente"
   quando `session.started.sender_pin`.
5. Rotas e tela de domínios de envio (settings): `SenderDomainRegistry::create/check/delete/props`.
6. `PlanController::FEATURE_LABELS`: `pin_auth` e `sender_domains`. Seeders: as flags nos
   planos de demonstração.
7. `OrganizationPurge`: as tabelas novas usam `cascadeOnDelete`/`nullOnDelete` na organização,
   no participante e na tentativa. Conferir a ordem de exclusão.
8. Agendador: chamar `ChannelDelivery::refresh()` para tentativas `unknown` antigas (T5)
   quando existir um provedor real.
9. `DuplicateEnvelope`: hoje copia `auth_method`, mas não `phone`, `delivery_channel` nem o
   PIN. Não copiar o PIN é o comportamento seguro; decidir o telefone e o canal.
10. `observability.redaction.keys`: acrescentar `pin`.
11. `AdminEventCatalog`: incluir os eventos `sender_domain.*` no registro de atividades.
12. Lembretes automáticos (`RecipientReminderNotification`) ainda não mandam aviso pelo canal.
    O reenvio manual e o convite mandam (`InvitationDispatcher::dispatch`).

## 11. Eventos novos (`AuditEventType`)

| Evento                   | Rótulo                                      | Tom  |
| ------------------------ | ------------------------------------------- | ---- |
| `challenge.pin_verified` | PIN do remetente confirmado                 | ok   |
| `challenge.pin_failed`   | PIN do remetente incorreto                  | warn |
| `recipient.pin_updated`  | PIN do participante alterado pelo remetente | info |
| `sender_domain.created`  | Domínio de envio cadastrado                 | info |
| `sender_domain.verified` | Domínio de envio verificado                 | ok   |
| `sender_domain.failed`   | Falha na verificação do domínio de envio    | warn |
| `sender_domain.deleted`  | Domínio de envio removido                   | info |

`challenge.sent` ganhou `simulated` só para SMS/WhatsApp; o payload do e-mail não mudou.
`challenge.verified` ganhou `next_step` só quando há PIN.

## 12. Migrations (aditivas, MySQL-compatíveis)

| Arquivo                                                      | O que faz                                                   |
| ------------------------------------------------------------ | ----------------------------------------------------------- |
| `2026_09_11_120001_add_delivery_channel_to_recipients_table` | `recipients.delivery_channel` VARCHAR(16) nulo              |
| `2026_09_11_120002_create_recipient_pins_table`              | PIN (hash), tentativas, bloqueios                           |
| `2026_09_11_120003_add_pin_gate_to_signing_sessions_table`   | `channel_verified_at`, `pin_gate_digest` (indexado)         |
| `2026_09_11_120004_create_channel_status_receipts_table`     | recibos do webhook, UNIQUE(`provider`, `event_fingerprint`) |
| `2026_09_11_120005_create_sender_domains_table`              | domínios de envio, UNIQUE(`organization_id`, `domain`)      |

## 13. Testes (`tests/Feature/Phase2/Channels`)

`ProvidersTest` (simuladores, produção desabilitada, binding, contratos reservados),
`PhoneTest` (E.164, máscara, sync), `AvailabilityTest` (canal indisponível não é escolhido),
`OtpChannelTest` (SMS e WhatsApp: código expirado, reutilizado, tentativas, reenvio por link e
por IP, sent × delivered, tempo esgotado, limite diário, código nunca em claro),
`PinTest` (só hash, etapa depois do código, bloqueio temporário e definitivo, PIN fora de
banco, trilha, log e sessão), `StatusWebhookTest` (503, 401 por assinatura, janela ou
adulteração, idempotência, isolamento por canal), `SenderDomainsTest`, `FlagsOffTest`,
`InvitationChannelTest`. Nenhum acessa a rede.
