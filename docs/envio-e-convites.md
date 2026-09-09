# Envio, convites, expiração e consumo do plano

> Incremento 3, camada de envio (B-SEND). Complementa `docs/arquitetura.md` §3.2/§3.3 (estados
> e invariantes), §5 item 5 (congelamento da versão) e §8 (adaptadores);
> `docs/design/RECONCILIACAO.md` §4 (Q11 reenvio, Q20 inadimplência, Q22 expiração);
> `docs/design/ROUTES_AND_PAGES.md` §1.2 e §3.1.
>
> O que **não** está aqui: preparação do documento (`docs/preparacao-documental.md`), campos e
> geometria (`docs/campos-e-geometria.md`), fluxo público do signatário e OTP (módulo
> `App\Services\Signing`), finalização e assinatura da operadora (incremento 4).

---

## 1. O que "enviar" significa aqui

Enviar um documento é o momento em que o rascunho vira compromisso. A partir dele:

- a **versão exibida ao signatário fica congelada** (`envelopes.sent_document_version_id`) — o
  aceite vai se referir a bytes específicos, não a "o documento";
- o envelope ganha um **código de verificação público** permanente;
- começa a correr um **prazo**;
- o **plano é debitado**;
- **links pessoais** passam a existir e a circular por e-mail.

Nada disso é reversível pela via normal: o caminho de volta é cancelar (§7), não "desenviar".

---

## 2. Fluxo do envio

`POST /documentos/{envelope}/enviar` → `EnvelopeSendController@store` →
`App\Services\Envelopes\Sending\SendEnvelope::handle()`.

O serviço tem **duas fases separadas de propósito**.

### Fase 1 — transação curta, só banco

Abre transação, faz `SELECT ... FOR UPDATE` no envelope e, sob esse lock:

| Passo      | Regra                                                                                                                                                                          |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Status     | precisa estar em `draft`/`preparing`/`ready`. Já `in_progress` → `already_sent`.                                                                                               |
| Completude | **recalculada agora**, não confiando no status gravado: `App\Services\Documents\EnvelopeReadiness::recompute()` (o contrato de B-DOC). Só segue se resultar em `ready`.        |
| Versão     | `sent_document_version_id = documents.current_version_id`. Sem versão exibível, o envio para.                                                                                  |
| Código     | `verification_code`: 12 caracteres de `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (sem `0/1/O/I`), com verificação de colisão antes de gravar; o UNIQUE da coluna é a autoridade final. |
| Prazo      | `expires_at` (§5).                                                                                                                                                             |
| Termos     | `terms_version` congelada da configuração.                                                                                                                                     |
| Plano      | reserva no ledger (§6).                                                                                                                                                        |
| Estado     | `transitionTo(in_progress)`, `sent_at`, `current_order = 1`.                                                                                                                   |
| Trilha     | `envelope.sent` + `plan.consumption_reserved`.                                                                                                                                 |

Nenhuma chamada externa, nenhum e-mail, nenhuma fila dentro dessa transação — a regra
"nada de transação aberta durante chamada externa" (arquitetura §3.3) vale integralmente.

### Fase 2 — fora da transação

Emite os links e despacha os convites (§3). Depois:

- **deu certo** → o consumo do plano passa a `committed`;
- **falhou** (qualquer exceção) → o consumo é `released`, o erro vai para o log **sem corpo de
  mensagem nem token**, e o usuário recebe: _"Não foi possível emitir os convites agora.
  Nenhum documento foi descontado do seu plano — tente enviar novamente em instantes."_

> **Limitação honesta.** Se a fase 2 falhar, o envelope **permanece `in_progress`** — a máquina
> de estados não admite `in_progress → ready` e voltar atrás inventaria uma transição que a
> arquitetura não tem. O que se garante é que a cota não é cobrada. O remetente reemite os
> convites com "Lembrar pendentes".

### Corrida (dois cliques, duas abas)

O lock serializa: a segunda requisição encontra `in_progress` e para com `already_sent`. A rede
de segurança é `plan_consumptions.idempotency_key = envelope:{id}:send`, **UNIQUE** — mesmo em
um banco onde o lock não vale (SQLite dos testes), o plano não é debitado duas vezes e os
convites não saem em dobro.

### O que bloqueia o envio

| Situação                         | `errorCode`             | Mensagem ao usuário                                                                                                     |
| -------------------------------- | ----------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Sem plano vigente                | `no_subscription`       | "Esta conta não tem um plano ativo. Escolha um plano para enviar documentos."                                           |
| Assinatura `past_due` (Q20)      | `subscription_past_due` | "O pagamento do plano está em atraso, então novos envios estão bloqueados. Regularize a cobrança para voltar a enviar." |
| Assinatura cancelada/expirada    | `subscription_inactive` | "O plano desta conta está … e não permite novos envios."                                                                |
| Cota do período esgotada         | `quota_exhausted`       | "Você já usou os N documentos do seu plano neste período. Faça upgrade para continuar enviando."                        |
| Falta documento/signatário/campo | `incomplete`            | a primeira pendência de `EnvelopeReadiness::issues()`, em PT-BR                                                         |

Quando o envio é recusado por incompletude, o status recalculado **é gravado** depois do
rollback: a lista deixa de mostrar "Pronto para enviar" um documento que já não está.

---

## 3. Convites: quem recebe e quando

`App\Services\Envelopes\Sending\InvitationDispatcher`.

| Ordem de assinatura | Quem é convidado no envio                                                                                            |
| ------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `parallel`          | todos os destinatários `pending`, de uma vez                                                                         |
| `sequential`        | apenas quem está em `envelopes.current_order` (ordem 1); os demais ficam `pending` — a interface diz "Aguarda a vez" |

Um convite é, sempre, quatro coisas:

1. um `recipient_access_link` **novo**, que revoga o anterior do mesmo destinatário;
2. `recipients.status`: `pending → notified` (nunca regride — quem já visualizou continua
   `viewed`), `notification_count + 1`, `last_notified_at`;
3. `invitation.sent` (ou `invitation.resent`) na trilha, **sem token e sem URL**: só o ULID do
   link, o ULID do destinatário e o **e-mail mascarado** (`m***@exemplo.com`);
4. a notificação enfileirada em `notifications`.

Quando chega a vez do próximo no sequencial, o módulo do signatário chama
`InvitationDispatcher::notifyCurrentTurn($envelope)` depois de avançar `current_order`.
É idempotente: quem já está `notified` não recebe de novo.

---

## 4. Ciclo de vida do link de convite

`App\Services\Envelopes\Sending\AccessLinks` — tabela `recipient_access_links`.

```
emitir ──▶ ativo ──┬── usar (last_used_at, use_count++) ──▶ continua ativo
                   ├── vencer   (expires_at do envelope)
                   └── revogar  (novo link, troca de e-mail, cancelamento,
                                 recusa, expiração)
```

- **Segredo**: `random_bytes(32)` codificado em base64url sem padding — 43 caracteres em
  `[A-Za-z0-9_-]`, compatível com a restrição da rota `assinar/{token}`.
- **O banco guarda só `token_digest = sha256(token)`.** O token em claro existe em três
  lugares e em nenhum outro: na memória durante a requisição, no payload da notificação
  enfileirada e no corpo do e-mail. Não vai para log, `audit_events`, `delivery_attempts` nem
  resposta HTTP. O DTO `IssuedLink` redige `__debugInfo()` justamente para que um `dd()`
  distraído não o exponha.
- **Um link ativo por destinatário e propósito.** Emitir revoga o anterior — o e-mail mais
  recente é o único que funciona. É por isso que o reenvio invalida o link antigo (Q11).
- **Prazo**: herda `envelopes.expires_at`. O link de **download** enviado na conclusão tem prazo
  próprio (30 dias, `CompletionNotifier::DOWNLOAD_LINK_DAYS`).
- **Resolução**: `AccessLinks::resolve($token)` devolve o link **mesmo revogado ou vencido** —
  quem chama precisa distinguir "link inválido" de "prazo encerrado". Use
  `RecipientAccessLink::isUsable()`.

### Contrato para a página pública do signatário

O módulo `App\Services\Signing` conversa com esta camada por estes pontos:

| Uso                                                           | Chamada                                                                                                                                                    |
| ------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Resolver o token da URL                                       | `AccessLinks::resolve(string $token): ?RecipientAccessLink`                                                                                                |
| Contar o acesso (não consome nem invalida)                    | `AccessLinks::markUsed(RecipientAccessLink $link): void`                                                                                                   |
| Revalidar o prazo a cada acesso                               | contrato `App\Services\Signing\Contracts\RevalidatesEnvelopeExpiration`, implementado por `ExpireEnvelopes` (`enforce(): bool` / `revalidate(): Envelope`) |
| Mensagens que decorrem do aceite/recusa                       | contrato `App\Services\Signing\Contracts\SignerNotifications`, implementado por `EnvelopeNotifications` e registrado no `AppServiceProvider`               |
| Convidar quem passou a ser a vez (alternativa sem o contrato) | `InvitationDispatcher::notifyCurrentTurn(Envelope $envelope): int`                                                                                         |
| Emitir novo link quando o e-mail de um pendente muda          | contrato `App\Services\Envelopes\Contracts\RotatesInvitations`, implementado por `InvitationDispatcher`                                                    |

---

## 5. Expiração

**Prazo (Q22)**: `expires_at` = **23:59:59 do fuso da organização**, `expiration_days` dias
depois do envio. A origem do número, em ordem: `envelopes.settings.expiration_days` →
`organizations.settings.default_expiration_days` → `assinavelox.default_expiration_days`
(30), sempre limitado a 1..90. O valor é gravado em UTC, como todo timestamp.

**Dois gatilhos, ambos necessários:**

| Gatilho   | Onde                                                                    | Por quê                                                                                                  |
| --------- | ----------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Agendado  | `envelopes:expire`, a cada 15 min (`routes/console.php`)                | mantém a lista do app e as notificações coerentes                                                        |
| No acesso | `ExpireEnvelopes::enforce()`, chamado pela página pública a cada visita | o agendador pode estar parado, atrasado ou ter falhado; nenhum link continua funcionando por causa disso |

Os dois caminhos usam **a mesma rotina**, então o resultado é indistinguível: transição sob
lock, destinatários pendentes → `expired`, **todos os links revogados**, um único
`envelope.expired`. Rodar de novo não faz nada (idempotente e reprocessável).

**Aviso de prazo curto**: `envelopes:notify-expiring` (de hora em hora) avisa quem ainda não
assinou e o remetente, `expiration.warning_hours` (48) antes do fim. Um único aviso por
envelope — a marca fica em `settings.expiring_warned_at`. O aviso ao signatário emite um link
novo (e revoga o anterior), porque o token antigo não é recuperável: só o digest existe.
Quem ainda aguarda a vez no sequencial **não** é avisado — não há o que ele possa fazer.

Isto **não** é o lembrete automático recorrente ("a cada 2 dias") do mock: esse é Fase 2.

---

## 6. Consumo do plano (ledger)

`App\Services\Plans\PlanLedger` — tabela `plan_consumptions`, uma linha por unidade.

```
                 commit()
reserved ─────────────────────▶ committed
   │                                │
   └──────── release() ─────────────┴────────▶ released
```

| Momento                                 | Estado      | Contadores em `subscriptions`                  |
| --------------------------------------- | ----------- | ---------------------------------------------- |
| Dentro da transação do envio            | `reserved`  | `envelopes_reserved + 1`                       |
| Convites despachados com sucesso        | `committed` | `envelopes_reserved - 1`, `envelopes_used + 1` |
| Falha no despacho                       | `released`  | `envelopes_reserved - 1`                       |
| Cancelamento **sem nenhuma assinatura** | `released`  | `envelopes_used - 1`                           |

- **Idempotência é da coluna**: `idempotency_key = "envelope:{id}:send"` é UNIQUE. Duas
  requisições concorrentes produzem uma única linha; a segunda recebe a existente.
- A cota disponível considera **reservas pendentes**: `quota - used - reserved`. Uma reserva
  presa (envio interrompido) segura a vaga até ser liberada — é o comportamento seguro.
- `commit()` e `release()` fazem UPDATE condicionado ao estado anterior e ajustam os contadores
  sob `lockForUpdate` da linha da assinatura: reexecutar não dobra nada.

**Quando a cota NÃO volta**: expiração e recusa. Nos dois casos o envio aconteceu, os convites
saíram e o documento circulou. Só o cancelamento antes de qualquer assinatura devolve a vaga.

---

## 7. Cancelamento e recusa

### Cancelamento (remetente) — `CancelEnvelope`

Sob lock: envelope → `canceled` (com `settings.cancel_reason`), pendentes → `canceled`, **todos
os links revogados**, `envelope.canceled` na trilha. Fora da transação: aviso por e-mail a quem
**tinha sido convidado** e não assinou — quem ainda aguardava a vez no sequencial não é avisado,
porque nunca soube que o documento existia. Por fim, o consumo é liberado se ninguém assinou.

Chamado por `EnvelopeController@cancel` e pela ação em lote `envelopes.bulk?action=cancel`.

### Recusa (signatário) — `EnvelopeNotifications`

Divisão de responsabilidade combinada com o módulo do signatário: **quem muda estado é
`App\Services\Signing\RecordRefusal`** — marca `refused`, aplica
`organizations.settings.refusal_policy`, encerra o envelope, cancela os demais pendentes
(`canceled`, não `refused`: eles não recusaram nada), revoga links e sessões e grava a
trilha, tudo sob lock. **Quem produz mensagem é esta camada**, pelo contrato
`App\Services\Signing\Contracts\SignerNotifications`, implementado por
`App\Services\Envelopes\Sending\EnvelopeNotifications`:

| Método do contrato                                    | O que faz aqui                                                   |
| ----------------------------------------------------- | ---------------------------------------------------------------- |
| `inviteRecipients($envelope, $recipients)`            | chegou a vez no sequencial: emite link novo e despacha o convite |
| `notifySenderRefused($envelope, $refusedBy)`          | avisa quem enviou, com o motivo, por e-mail e pelo sino          |
| `notifyEnvelopeClosed($envelope, $canceled, $reason)` | avisa quem tinha sido convidado e não assinou                    |

Um único escritor de estado por transição; um único lugar que sabe emitir link e escrever
e-mail. O cancelamento pelo remetente (`CancelEnvelope`) usa **a mesma**
`notifyEnvelopeClosed()`, para que cancelamento e recusa digam a mesma coisa do mesmo jeito.

Quem ainda aguardava a vez no sequencial (`notification_count = 0`) **não** é avisado do
encerramento: nunca soube que o documento existia.

O aviso ao remetente respeita `memberships.notification_preferences` (evento
`recipient_refused`). Sem o binding registrado no `AppServiceProvider`, o fluxo público
apenas registra no log que ninguém foi avisado — e o aceite/recusa continua válido, porque
nada se desfaz por causa de um e-mail que não saiu.

### Conclusão — `CompletionNotifier`

Contrato para o agente da finalização (incremento 4): chamar `notify($envelope)` **depois** de
o envelope estar `completed`, com o arquivo final e `verification_records` gravados. Emite um
link de download por signatário (`purpose = download`, 30 dias) e despacha os avisos.
Idempotente por `settings.completion_notified_at`.

O texto do e-mail muda conforme `verification_records.signature_status`:

- `none` → "reúne as evidências do **aceite eletrônico** de cada participante…";
- `company_a1` → "…e recebeu a **assinatura criptográfica da AssinaVelox** (PAdES), que
  **identifica a operadora do serviço**".

Nunca "assinatura digital ICP-Brasil do participante", em nenhum dos dois casos (arquitetura §2).

---

## 8. Reenvio manual (Q11)

`App\Services\Envelopes\Sending\ResendInvitations`. **Não é lembrete automático.**

Três limites, todos verificáveis:

| Limite                                       | Padrão | Configuração                                                                           |
| -------------------------------------------- | ------ | -------------------------------------------------------------------------------------- |
| Intervalo por destinatário                   | 10 min | `assinavelox.resend.throttle_minutes`                                                  |
| Máximo de reenvios por destinatário          | 5      | `organizations.settings.max_resends` (cai para `assinavelox.resend.max_per_recipient`) |
| "Lembrar todos os pendentes" por organização | 1 h    | `assinavelox.resend.bulk_throttle_minutes`                                             |

Implementados com `RateLimiter` (intervalo) e com `recipients.notification_count` menos o
convite inicial (máximo). Todo reenvio **emite link novo e revoga o anterior**.

Um reenvio é recusado, com mensagem PT-BR, quando: o envelope não está `in_progress`; o
destinatário já assinou/recusou/expirou; no sequencial ainda não chegou a vez dele; o intervalo
não passou; o máximo foi atingido.

| Entrada             | Rota                           | Comportamento                                                                                                                                                 |
| ------------------- | ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Botão da linha      | `envelopes.recipients.resend`  | um destinatário; erro vira flash                                                                                                                              |
| "Lembrar pendentes" | `envelopes.resend`             | todos os elegíveis do envelope; os bloqueados viram "N ignorado(s)"                                                                                           |
| Lote da lista       | `envelopes.bulk?action=resend` | idem, por envelope selecionado                                                                                                                                |
| Tela Assinaturas    | `recipients.resend_pending`    | job `ResendPendingInvitations` na fila `notifications`, `ShouldBeUnique` por organização, escopo de visibilidade do usuário (`member` só reenvia o que criou) |

`ResendInvitations::canResend()` devolve o `can_resend` das props (ROUTES §2.7);
`cooldownMinutes()` e `resendCount()` completam o quadro para a interface.

---

## 9. Provedor de e-mail: o que garante e o que não garante

Contrato `App\Integrations\Contracts\EmailProvider`, duas implementações.

### `LaravelMailEmailProvider`

Fala **apenas** com o `MailManager` do Laravel. Em produção, o serviço de e-mail do proprietário
entra por configuração — `MAIL_MAILER=smtp` com host/porta/credenciais, ou um transporte HTTP
registrado no próprio `config/mail.php`. **Nenhum endpoint é inventado no código.**

| Resultado                     | Recibo    | Significado                                         |
| ----------------------------- | --------- | --------------------------------------------------- |
| Transporte aceitou sem lançar | `sent`    | "aceito para entrega" — nada além disso             |
| Transporte lançou             | `failed`  | mensagem legível, sem segredos                      |
| Mailer não transmite (`log`)  | `unknown` | escrever no log não é enviar                        |
| Mailer inexistente            | `failed`  | "O mailer X não está configurado nesta instalação." |

### `LogEmailProvider` (`ASSINAVELOX_EMAIL_PROVIDER=log`)

Fake de desenvolvimento. **Nada é enviado.** Escreve uma linha marcada `[FAKE]` com metadados
apenas — destinatário, assunto, propósito, `correlation_id`. **O corpo nunca é registrado**: é
ele que carrega o link de convite. O recibo é sempre `unknown`, jamais `sent`, para que o
ambiente de desenvolvimento seja impossível de confundir com entregas reais.

### `delivery_attempts` — o que a tabela afirma

Uma linha por tentativa, escrita por `App\Integrations\Email\DeliveryRecorder`:

```
queued ──▶ sent      (o provedor aceitou)
       ├─▶ unknown   (resposta inconclusiva — NÃO é sucesso)
       └─▶ failed    (recusa explícita)

sent ──▶ delivered | bounced   ← só com evidência do provedor
```

**"Enviado" não é "entregue".** `delivered` só é gravado por
`DeliveryRecorder::markDelivered()`, que exige a evidência do provedor (webhook ou consulta à
API) e a guarda em `meta.delivery_evidence`. Nenhum caminho síncrono chama esse método —
na Fase 1 não há webhook de entrega configurado, então na prática o estado final observado é
`sent`, `unknown` ou `failed`. A tabela nunca vai dizer "entregue" sem prova.

**Idempotência da entrega**: a chave é `correlation_id` + propósito + destinatário. Uma
retentativa do mesmo job reaproveita a linha (incrementando `meta.retries`) e, se ela já está
`sent`/`delivered`, **não reenvia**. Cada mensagem tem o seu `correlation_id` (a coluna é
CHAR(26), um ULID), compartilhado com a linha correspondente da trilha.

**Timeout**: o tempo-limite é o do transporte configurado (`mail.mailers.smtp.timeout`), não
um relógio próprio deste código — impor um timeout por fora do `MailManager` exigiria
reimplementar o transporte. Documentado como está.

### Canal de notificação

`App\Notifications\Channels\TrackedMailChannel`: renderiza o `MailMessage` com o template
Markdown padrão do Laravel, entrega ao `EmailProvider` e grava a tentativa. Toda notificação
que passa por ele implementa `App\Notifications\Contracts\TracksDelivery`, que fornece o
contexto (organização, envelope, destinatário, propósito, correlação).

**Sem rastreadores**: nenhum pixel, nenhum redirecionador de cliques, nenhum parâmetro `utm_`.
O botão aponta direto para a rota da aplicação. O cabeçalho `X-Auto-Response-Suppress` evita
resposta automática de férias.

### Notificações (`app/Notifications/Envelopes/`)

| Classe                               | Para quem               | Propósito da entrega                      |
| ------------------------------------ | ----------------------- | ----------------------------------------- |
| `RecipientInvitationNotification`    | signatário              | `invitation` / `resend` (com o link)      |
| `EnvelopeExpiringNotification`       | signatário pendente     | `resend` + `meta.reason = expiring_soon`  |
| `EnvelopeCanceledNotification`       | signatário convidado    | `canceled` / `refused`                    |
| `EnvelopeCompletedNotification`      | signatários e remetente | `completed` (link de download autorizado) |
| `EnvelopeRefusedNotification`        | remetente               | `refused` (com o motivo)                  |
| `SenderEnvelopeExpiringNotification` | remetente               | `resend` + `meta.reason = expiring_soon`  |

Todas em PT-BR, enfileiradas em `notifications`. As dirigidas ao remetente respeitam
`memberships.notification_preferences` (mail / sino do app) via `RestrictsChannels`.

---

## 10. Configuração

```env
# Provedor de e-mail: laravel (usa config/mail.php) | log (fake de desenvolvimento)
ASSINAVELOX_EMAIL_PROVIDER=laravel
ASSINAVELOX_EMAIL_MAILER=          # vazio = mail.default
ASSINAVELOX_EMAIL_LOG_CHANNEL=     # canal do LogEmailProvider (vazio = padrão)

ASSINAVELOX_RESEND_THROTTLE_MINUTES=10
ASSINAVELOX_MAX_RESENDS=5
ASSINAVELOX_BULK_RESEND_THROTTLE_MINUTES=60

ASSINAVELOX_DEFAULT_EXPIRATION_DAYS=30
ASSINAVELOX_EXPIRATION_WARNING_HOURS=48
ASSINAVELOX_EXPIRATION_BATCH_SIZE=200
```

O agendamento exige um cron chamando `php artisan schedule:run` a cada minuto. Os dois comandos
usam `withoutOverlapping()` e `onOneServer()`.

---

## 11. Limitações conhecidas

1. **`delivered` nunca é atingido na Fase 1.** Não há webhook de entrega configurado; o
   `DeliveryRecorder` tem o método e o formato da evidência prontos, mas nada o chama. O estado
   final observado é `sent`/`unknown`/`failed` — e é exatamente isso que a interface deve dizer.
2. **Timeout do e-mail é o do transporte**, não deste código (§9).
3. **`DeliveryPurpose` não tem um caso para "expirando"**: os avisos de prazo usam `resend` com
   `meta.reason = expiring_soon`. O enum é área de outro agente; a recomendação é acrescentar
   `case Expiring = 'expiring';` — a coluna é `string(32)`, não precisa de migration.
4. **Falha no despacho deixa o envelope `in_progress` sem convites** (§2). A cota não é cobrada,
   mas o remetente precisa usar "Lembrar pendentes".
5. **O token viaja no payload da fila.** É o mesmo desenho já usado por
   `MembershipInvitationNotification` no incremento 1. Com `QUEUE_CONNECTION=database` isso
   significa o token em claro na tabela `jobs` até o job terminar. Mitigações possíveis
   (guardar o ULID do link e reemitir dentro do job, ou cifrar o payload) ficam registradas
   aqui como decisão consciente, não como esquecimento.
6. **Sem lembretes automáticos recorrentes** (Fase 2), sem SMS/WhatsApp (os contratos existem,
   sem implementação), sem reenvio em massa entre organizações.
7. **`lockForUpdate` não faz nada no SQLite dos testes.** A serialização real do envio depende
   do MySQL; nos testes a garantia que sobra é o UNIQUE do ledger, que é justamente o motivo de
   ele existir.
