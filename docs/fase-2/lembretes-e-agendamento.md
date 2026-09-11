# Lembretes automáticos e envio agendado (Fase 2 §2.5)

> Área B-REM. Complementa `docs/roadmap.md` §2.5 e `docs/envio-e-convites.md`. Identificadores em inglês; prosa em português.
> Estado: backend completo e testado atrás da flag `features.reminders` (desligada por padrão). O wizard e o detalhe do envelope consomem o contrato do §7, a cargo do agente de front.

## 1. Flag e ativação

| Condição             | Onde                                       | Padrão  |
| -------------------- | ------------------------------------------ | ------- |
| Interruptor global   | `config('assinavelox.features.reminders')` | `false` |
| Plano da organização | `plans.features.reminders = true`          | ausente |

Resolvida por `App\Services\Envelopes\Reminders\RemindersFeature::enabledFor($organization)`. **As duas** condições precisam ser verdadeiras. A mesma flag cobre os lembretes **e** o envio agendado.

A flag liga a interface e os agendadores. Ela não substitui a autorização: as ações continuam passando por `EnvelopePolicy::send` (agendar e cancelar agendamento), `EnvelopePolicy::update` (lembretes do envelope) e `OrganizationPolicy::updateSettings` (padrão da organização).

Com a flag desligada, o comportamento é o da Fase 1:

- `SendEnvelope` grava o envelope exatamente como antes (sem `settings.reminders`);
- `envelopes:send-reminders` não seleciona nada;
- as rotas de agendamento e de lembretes respondem **404**;
- em Configurações › Padrões de assinatura, o controle continua desabilitado com o selo "Fase 2", e o que vier em `reminders` no PATCH é ignorado.

Se a flag for desligada **depois** de um agendamento, o disparo não envia nada: registra `envelope.schedule_canceled {reason: feature_disabled}` e avisa o remetente.

## 2. Dados (migrations aditivas)

| Migration                                                 | O que cria                                                                                                                                                                                    |
| --------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `2026_09_11_110201_add_scheduled_send_to_envelopes_table` | `envelopes.scheduled_send_at` (timestamp UTC, índice `envelopes_scheduled_send_at_index`) e `envelopes.scheduled_send_audit_id` (id do `envelope.scheduled` que criou o agendamento; sem FK). |
| `2026_09_11_110202_create_envelope_reminders_table`       | `envelope_reminders`: um registro por (destinatário, número do lembrete). UNIQUE `envelope_reminders_recipient_sequence_unique` = trava de idempotência.                                      |

`envelope_reminders`: `organization_id` (FK RESTRICT), `envelope_id` e `recipient_id` (FK CASCADE), `sequence`, `status` (`sent` \| `skipped`), `reason`, `correlation_id`, `access_link_id`, `sent_at` e timestamps. Não é evidência; a evidência é o `reminder.sent` na trilha. `OrganizationPurge` apaga `recipients` antes de `organizations`, e o cascade leva estas linhas junto (coberto por teste).

Não há novo status de envelope: o agendado continua `ready` (recomendação do roadmap, para não alterar o enum público).

Configuração em JSON:

- `organizations.settings.reminders` = `{enabled, first_after_days, interval_days, max_count, window_start_hour, window_end_hour}` (padrão da organização);
- `envelopes.settings.reminders` = `{enabled, first_after_days, interval_days, max_count}` (cadência do envelope).

Limites (`ReminderSettings::LIMITS`): `first_after_days` e `interval_days` de 1 a 30, `max_count` de 1 a 10. A janela vai de 0 a 23 (início) e de 1 a 24 (fim, exclusivo), com fim maior que início. Todo valor lido do banco passa por _clamp_, então um JSON adulterado não produz "a cada 0 dias".

## 3. Cadência de onde vem

1. O wizard pode gravar a cadência do envelope (`PUT documentos/{envelope}/lembretes`).
2. Se o envelope não tem cadência própria, **o envio copia o padrão da organização** para `envelopes.settings.reminders` (`SendEnvelope::snapshotReminders`, só com a flag ligada).
3. Mudar o padrão da organização depois não afeta envelopes já enviados.
4. A cadência de um envelope em andamento pode ser alterada ou desligada pelo mesmo endpoint. Isso **não** conta como edição para o envio agendado.

## 4. Lembretes

### 4.1 Quem recebe e quando

`ReminderPlanner` seleciona e `ReminderSender` envia. O envio **revalida tudo sob `SELECT … FOR UPDATE`** do envelope e do destinatário.

Um destinatário é lembrado quando, ao mesmo tempo:

- a flag está ligada para a organização;
- o envelope está `in_progress`, dentro do prazo (`expires_at` futuro) e com `settings.reminders.enabled`;
- o momento atual está dentro da janela da organização, **no fuso da organização** (padrão 8h–20h);
- o destinatário está `notified` ou `viewed` (já foi convidado e ainda não assinou nem recusou);
- ele não é `viewer`;
- é a vez dele: no sequencial, `order_index = current_order`; no paralelo, todos os pendentes;
- ainda não atingiu `max_count` lembretes **enviados**;
- já passou `first_after_days` (primeiro lembrete) ou `interval_days` (demais) desde o **último contato**, `recipients.last_notified_at`. O convite, o reenvio manual e o próprio lembrete atualizam essa data, então um reenvio manual reinicia a contagem;
- ele não está com a página aberta: um link usado nos últimos `assinavelox.reminders.active_grace_minutes` (padrão 60) adia o lembrete, porque o lembrete revoga o link em uso.

Um lembrete que vence fora da janela sai na primeira execução horária depois da abertura. Os dias são contados em horas corridas (2 dias = 48 h).

### 4.2 Parada em estado terminal

O envelope pode mudar entre a seleção e o job. Por isso o `ReminderSender` confere o estado de novo e, se o envelope ou o destinatário já não aceita lembrete, grava uma linha `skipped` e **um** `reminder.skipped` com o motivo.

Motivos de `reminder.skipped`:

- envelope fora de `in_progress`: `envelope_completed`, `envelope_refused`, `envelope_expired`, `envelope_canceled`, `envelope_finalizing`; e `envelope_expired` também quando o prazo venceu antes da varredura de expiração;
- destinatário que deixou de estar pendente: `recipient_signed`, `recipient_refused`, …;
- papel e vez: `viewer`, `not_their_turn`;
- configuração: `reminders_disabled`, `max_reached`, `max_resends_reached` (lembretes + reenvios manuais atingiram `max_resends`, §4.5).

Estes resultados não gravam nada e são tentados de novo na próxima hora: `already_processed`, `not_due`, `outside_window`, `recently_active`, `sequence_mismatch` (job atrasado), `feature_disabled`.

### 4.3 Idempotência

`UNIQUE(recipient_id, sequence)` em `envelope_reminders`. A linha, o link novo, o `last_notified_at`, a trilha e o enfileiramento do e-mail acontecem **na mesma transação**, e a notificação é `afterCommit`. Rodar o comando duas vezes, ou repetir o job, não produz um segundo lembrete com o mesmo número. A entrega em `delivery_attempts` usa o `correlation_id` do lembrete (`purpose = reminder`).

### 4.4 Link: sempre novo (desvio consciente da decisão original)

A decisão original era "reusar o link atual se válido". Isso **não é possível** sem enfraquecer a Fase 1: o token só existe como digest (`recipient_access_links.token_digest`), e o e-mail precisaria do token em claro. Guardar o token, mesmo cifrado, quebraria a regra de segredo da Fase 1.

Por isso o lembrete sempre emite um link novo por `AccessLinks::issue()`, e isso revoga o anterior, exatamente como o reenvio manual (Q11). O e-mail diz que o link substitui os anteriores. O caso "link revogado ou expirado gera novo" fica coberto por construção. Para não derrubar quem está assinando, vale a regra do link usado há pouco (§4.1).

### 4.5 Relação com o reenvio manual (Q11)

- `recipients.notification_count` **não** muda com lembrete: ele continua medindo convite + reenvios manuais.
- O lembrete tem contagem própria (`envelope_reminders`) e limite próprio (`max_count`), **e** as duas contagens somam contra `organizations.settings.max_resends` (roadmap §2.5: o lembrete "respeita `settings.max_resends` (Q11)"). `ResendInvitations::resendCount` = reenvios manuais + lembretes enviados. Esgotado o limite, o lembrete é pulado com `max_resends_reached` e o "Reenviar" manual fica indisponível. O limite efetivo de lembretes é, portanto, o menor entre `max_count` e o que resta de `max_resends`. (Revisão adversarial da onda A: antes os contadores eram independentes, o que contrariava o roadmap.)
- Sem a flag `reminders`, `envelope_reminders` não tem linhas e o limite de reenvio é exatamente o da Fase 1.
- O lembrete alimenta a mesma trava de 10 minutos por destinatário (`ResendInvitations::throttleKeyFor`). Um "Reenviar" logo depois de um lembrete não manda um terceiro e-mail em sequência.

## 5. Envio agendado

`App\Services\Envelopes\Sending\ScheduledSend`.

```
ready ──agendar──▶ ready + scheduled_send_at ──(hora)──▶ SendEnvelope ──▶ in_progress
                     ├── cancelar (usuário) ........ schedule_canceled {reason: user}
                     ├── editar o envelope ......... schedule_canceled {reason: edited}
                     ├── "Enviar agora" ............ schedule_canceled {reason: sent_now}
                     ├── cancelar o envelope ....... schedule_canceled {reason: envelope_canceled}
                     ├── envelope excluído/fora de ready  {reason: deleted | invalid_status}
                     └── disparo falhou ............ schedule_canceled {reason: quota_exhausted | past_due |
                                                     incomplete | feature_disabled | dispatch_error | …}
                                                     + aviso ao remetente (e-mail + sino)
```

- **Ao agendar**: o envelope precisa estar `ready`, com a completude recalculada sob lock (status gravado **e** lista de pendências). A cota é verificada com `PlanLedger::assertCanSend`, sem reservar. O horário precisa estar de 5 minutos a 60 dias à frente (`assinavelox.scheduled_send.min_lead_minutes` / `max_days`). O navegador envia `Y-m-d\TH:i`, interpretado **no fuso da organização**; o valor é gravado em UTC. Reagendar é agendar de novo; o evento traz `previous`.
- **No disparo** (`envelopes:dispatch-scheduled`, a cada minuto):
    1. Reivindicação atômica: `UPDATE … SET scheduled_send_at = NULL WHERE id = ? AND scheduled_send_at = ?`. Só um processo passa.
    2. Verificação de edição.
    3. Verificação da flag.
    4. `SendEnvelope::handle($envelope, $scheduledFor)`, que revalida completude e cota sob lock e **reserva a cota ali**. A reserva é idempotente por `envelope:{id}:send`.

    Se falhar, o envelope continua `ready` (ou volta a rascunho, se ficou incompleto), o agendamento some e o remetente recebe `ScheduledSendFailedNotification`, com o mesmo texto PT-BR que o botão "Enviar" mostraria. `envelope.sent` ganha `scheduled_for` quando vem do agendamento.

- **Edição cancela**: qualquer evento `envelope.updated`, `document.uploaded`, `document.removed`, `fields.updated`, `recipients.updated` ou `documents.reordered` com id maior que `scheduled_send_audit_id` cancela o agendamento. A verificação acontece na varredura de cada minuto e de novo no disparo. É uma rede de segurança que não depende de quem edita.

    Para o cancelamento ser **imediato**, as áreas donas da edição devem chamar `app(ScheduledSend::class)->cancelBecauseEdited($envelope, 'fields'|'recipients'|'document'|'metadata')` (§8).

- **Uma vez só**: além da reivindicação, `SendEnvelope` mantém `FOR UPDATE` + `already_sent`, e o consumo do plano tem chave única.
- O job `DispatchScheduledEnvelope` tem uma tentativa só. Uma falha inesperada cai em `failed()`, que registra `dispatch_error` e avisa o remetente.

## 6. Trilha (T7) e entregas

| `audit_events.event_type`    | Rótulo                          | Payload (minimizado, sem token nem URL)                                                     |
| ---------------------------- | ------------------------------- | ------------------------------------------------------------------------------------------- |
| `envelope.scheduled`         | Envio agendado                  | `scheduled_for` (ISO-8601 UTC), `previous` (se reagendou)                                   |
| `envelope.schedule_canceled` | Agendamento de envio cancelado  | `reason`, `scheduled_for`, `change` (só em `cancelBecauseEdited`)                           |
| `reminder.sent`              | Lembrete automático enviado     | `recipient` (ulid), `to` (e-mail mascarado), `link` (ulid do link), `sequence`, `max_count` |
| `reminder.skipped`           | Lembrete automático não enviado | `recipient`, `sequence`, `reason`                                                           |

Todos os quatro têm `kind = info`. `recipient_id` e `correlation_id` preenchidos em `reminder.*`.

`delivery_attempts.purpose` ganhou:

- `reminder` — "Lembrete automático";
- `scheduled_send` — "Envio agendado", o aviso de falha ao remetente.

A notificação ao remetente usa `data.event = 'scheduled_send_failed'`. Esse evento não está no catálogo de preferências: é operacional e sai sempre nos dois canais.

## 7. Contrato para o front (wizard e detalhe)

### 7.1 Rotas a registrar em `routes/web.php`

`routes/web.php` é de outra área. Estas linhas vão dentro do grupo `Route::prefix('documentos')->name('envelopes.')->scopeBindings()` existente, depois de `{envelope}/enviar`:

```php
Route::post('{envelope}/agendamento', [EnvelopeSendController::class, 'schedule'])->name('schedule');
Route::delete('{envelope}/agendamento', [EnvelopeSendController::class, 'cancelSchedule'])->name('schedule.cancel');
Route::put('{envelope}/lembretes', [EnvelopeSendController::class, 'reminders'])->name('reminders.update');
```

Até lá, os testes registram as mesmas rotas, com os mesmos middlewares (`tests/Feature/Phase2/Reminders/Support/ReminderHelpers.php::remRegisterRoutes`). Depois de registrar, rode `php artisan wayfinder:generate --with-form` para gerar `@/routes/envelopes` (`schedule`, `schedule.cancel`, `reminders.update`).

| Rota                         | Método | Corpo                                                                                   | Resposta                                                                                                                                                                                                              |
| ---------------------------- | ------ | --------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `envelopes.schedule`         | POST   | `scheduled_for: "2026-09-15T09:00"` (datetime-local, fuso da organização)               | `back()` + flash `success` "Envio agendado para 15/09/2026 às 09:00 (America/Sao_Paulo)."; erro de horário vem em `errors.scheduled_for`; pendência, cota ou status em flash `error`/`info`; 404 com a flag desligada |
| `envelopes.schedule.cancel`  | DELETE | —                                                                                       | `back()` + `success` (cancelado) ou `info` (não havia agendamento); 404 com a flag desligada                                                                                                                          |
| `envelopes.reminders.update` | PUT    | `enabled: bool, first_after_days: int, interval_days: int, max_count: int` (limites §2) | `back()` + `success`; erros por campo; flash `error` em envelope terminal ou finalizando; 404 com a flag desligada                                                                                                    |

### 7.2 Props

`App\Services\Envelopes\Reminders\ReminderProps::forEnvelope($envelope)` devolve o bloco abaixo. `EnvelopeController::edit/show` (outra área) deve expor como prop `reminders`:

```ts
interface EnvelopeReminders {
    available: boolean; // flag ligada para a organização
    settings: {
        enabled: boolean;
        first_after_days: number;
        interval_days: number;
        max_count: number;
    };
    is_default: boolean; // true = sem cadência própria (no rascunho, mostra o padrão da org.)
    summary: string; // "A cada 2 dias · até 3 lembretes" | "Desativados"
    limits: Record<
        'first_after_days' | 'interval_days' | 'max_count',
        { min: number; max: number }
    >;
    window: { window_start_hour: number; window_end_hour: number };
    timezone: string; // fuso da organização
    recipients: Record<
        string /* recipient ulid */,
        { sent: number; last_sent_at: string | null }
    >;
    scheduled_send: null | {
        at: string /* ISO UTC */;
        at_local: string /* "15/09/2026 às 09:00" */;
        input_value: string /* "2026-09-15T09:00" */;
        timezone: string;
    };
    scheduled_send_limits: { min_lead_minutes: number; max_days: number };
}
```

Uso esperado:

- **Passo 1**: o switch "Lembretes automáticos" fica real quando `available`. Ele grava com `PUT lembretes`, e o resumo usa `summary`.
- **Passo 4**: o botão "Agendar envio" abre um `datetime-local` (`min` = agora + `min_lead_minutes`, `max` = agora + `max_days`, no fuso `timezone`) e faz `POST agendamento`. Com `scheduled_send` preenchido, o passo mostra "Envio agendado para {at_local}" com "Cancelar agendamento" (`DELETE agendamento`); "Enviar agora" continua disponível e encerra o agendamento.
- **Detalhe**: linha "Lembretes: {summary}". Na aba Signatários, "Último lembrete {last_sent_at}". Na timeline, os eventos do §6. Enquanto `available = false`, tudo continua como na Fase 1: switch desabilitado com o selo "Fase 2".

### 7.3 Configurações › Padrões de assinatura (implementado)

`settings/signing` recebe:

- `phase2.reminders: boolean`;
- `reminders: {enabled, first_after_days, interval_days, max_count, window_start_hour, window_end_hour, timezone}`;
- `limits.reminders`.

Com a flag ligada, o controle "Lembretes automáticos" (Desativados / A cada N dias) e o bloco "Primeiro lembrete após · Máximo de lembretes · Enviar a partir de · Enviar até" ficam reais. O PATCH envia `reminders.*`, validado em `UpdateSigningSettingsRequest`. Com a flag desligada, o front não envia a chave e o backend a ignora.

## 8. Pendências de outras áreas (não editadas por B-REM)

1. **Rotas**: incluir as três linhas do §7.1 em `routes/web.php`.
2. **Props do envelope**: `EnvelopeController::edit/show` deve mesclar `reminders: app(ReminderProps::class)->forEnvelope($envelope)`.
3. **Model `Envelope`**: acrescentar `@property Carbon|null $scheduled_send_at`, `@property int|null $scheduled_send_audit_id` e o cast `'scheduled_send_at' => 'datetime'`. O serviço funciona com e sem o cast (`ScheduledSend::parse`).
4. **Config** `config/assinavelox.php`. Hoje o código usa `config()` com esses padrões:

    ```php
    'features' => ['reminders' => env('ASSINAVELOX_FEATURE_REMINDERS', false)],
    'reminders' => ['window_start_hour' => 8, 'window_end_hour' => 20, 'batch_size' => 500, 'active_grace_minutes' => 60],
    'scheduled_send' => ['min_lead_minutes' => 5, 'max_days' => 60, 'batch_size' => 200],
    ```

    Também `.env.example`.

5. **Flag compartilhada**: `HandleInertiaRequests::features()['reminders']` continua `false` fixo. Quem centralizar as flags deve usar `RemindersFeature::enabledFor($organization)`.
6. **Cancelamento imediato na edição** (opcional; a trilha já garante): chamar `ScheduledSend::cancelBecauseEdited()` em `EnvelopeController::update`, `RecipientSync`, `FieldSync` e `EnvelopeDocumentController` quando o envelope estiver agendado.
7. **`OrganizationPurge::TABLES_IN_ORDER`**: incluir `envelope_reminders` antes de `recipients`, para a contagem do recibo. O cascade já apaga as linhas.
8. **Tipos TS** (`resources/js/types/enums.ts`): `AuditEventType` ganha os quatro valores do §6; `DeliveryPurpose` (se tipado) ganha `reminder` e `scheduled_send`; `NotificationEvent` pode ganhar `scheduled_send_failed`.
9. **`tests/Unit/Models/EnumCatalogTest.php`**: a contagem fixa (45) quebra com os eventos novos de todas as áreas da Fase 2. Os quatro deste item são `envelope.scheduled`, `envelope.schedule_canceled`, `reminder.sent` e `reminder.skipped`.
10. **Seeders de planos**: ligar `features.reminders` nos planos que devem ter o recurso.

## 9. Comandos e agenda

| Comando                                   | Agenda (`routes/console.php`)                           | O que faz                                                                                      |
| ----------------------------------------- | ------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| `envelopes:send-reminders [--limit=]`     | `hourlyAt(5)`, `withoutOverlapping(30)`, `onOneServer`  | Seleciona os lembretes devidos e despacha `SendReminderToRecipient` por (destinatário, número) |
| `envelopes:dispatch-scheduled [--limit=]` | `everyMinute()`, `withoutOverlapping(5)`, `onOneServer` | Cancela agendamentos invalidados e despacha `DispatchScheduledEnvelope` para os vencidos       |

Os dois são idempotentes e reprocessáveis. Os jobs carregam só ids (nada de token ou e-mail) e rodam com `CurrentOrganization::runAs()`.

## 10. Testes

Em `tests/Feature/Phase2/Reminders` (Pest, relógio congelado): 50 testes.

- `ReminderCadenceTest`: intervalo, janela no fuso, janela configurável, máximo, idempotência, `notification_count`, flag global e por plano, envio da Fase 1 intacto, cópia do padrão, precedência do wizard, token ausente da trilha.
- `ReminderSafetyTest`: estados terminais (dataset), prazo vencido, mudança entre a seleção e o envio (cancelamento e assinatura), sequencial, paralelo, visualizador, link revogado, link válido substituído, reenvio manual, página aberta, exclusão da organização.
- `ScheduledSendTest`: fuso e disparo na hora, revalidação de cota e de completude, validação do agendamento, corrida, "Enviar agora", edição (pela varredura, pelo disparo e pelo ponto de extensão), cancelamento pelo usuário e pelo envelope, flag desligada (404 e após agendar), isolamento entre organizações.
- `ReminderSettingsTest`: Configurações com a flag desligada e ligada, validação, endpoint por envelope, envelope em andamento, lembretes num envelope agendado, props do front.
