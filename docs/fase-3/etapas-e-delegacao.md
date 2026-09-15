# Fase 3 §3.3 — etapas condicionais e delegação auditada (F-FLOW)

> Onda F, parte 2 da Fase 3. Identificadores em inglês; prosa em português.
> Precedência: `docs/design/RECONCILIACAO.md` → `docs/arquitetura.md` → `docs/roadmap.md` §3.3 → este arquivo.

## 1. Resumo

- **Etapas condicionais** (flag `conditional_steps`): o remetente agrupa os participantes em etapas. Da segunda em diante, a etapa pode depender da **decisão de um aprovador** (aprovou/recusou) ou do **valor de um campo** (caixa de seleção ou texto) preenchido numa etapa **anterior**, comparado com um texto literal. Etapa cuja condição é falsa é **pulada com registro** (regra e valores avaliados); os participantes dela ficam `canceled` com o motivo `step_skipped` e **nunca são notificados**. O envelope conclui quando não resta etapa aplicável.
- **Delegação auditada** (flag `delegation`): com a política do remetente (`settings.allow_delegation`, padrão desligado), o signatário ou aprovador indica nome, e-mail e motivo de quem vai participar no lugar dele. Por padrão a delegação só vale depois da **confirmação do remetente**. O delegado é um **participante novo**, com convite, código e aceite próprios; o original fica `delegated` (nunca "assinado") e o link dele deixa de abrir.
- **Com as duas flags desligadas nada muda**: nenhuma rota nova responde (404), `current_order` funciona exatamente como antes (`Envelope::hasTurns()` é `isSequential()`), a página de evidências não ganha chave nova. Os testes das suítes `Sending`, `Sign` e `Phase2` passam sem mudar asserção.

Semântica (T1): o aceite do delegado é **dele** — a declaração, o código e as evidências são dele, e nenhum texto diz "em nome de". A condição é um motor de regras **declarativo e fechado** — nada é avaliado como expressão, `eval`, regex ou fórmula; valores de campo são dados não confiáveis (T6) e só são comparados como texto.

## 2. Etapas condicionais

### 2.1 Modelo de dados (migrations `2026_09_14_1602xx`, só aditivas)

| Tabela/coluna                            | Conteúdo                                                                                                                                                                                                                                |
| ---------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `signing_steps`                          | `ulid`, `organization_id`, `envelope_id`, **`step_index`** (o "index" do roadmap — `INDEX` é palavra reservada do MySQL), `name`, `condition` (JSON), `status`, `evaluated_at`, `evaluation` (JSON). Único `(envelope_id, step_index)`. |
| `recipients.signing_step_index`          | Etapa do participante (nula sem etapas; o visualizador nunca tem etapa).                                                                                                                                                                |
| `recipients.status_reason`               | Motivo curto do estado: `step_skipped` (etapa pulada) ou `delegated` (delegou).                                                                                                                                                         |
| `recipients.delegated_from_recipient_id` | O delegado aponta para quem delegou (sem FK: autorreferência; a trilha com FKs fica em `delegations`).                                                                                                                                  |
| `envelopes.uses_signing_steps`           | Boolean, padrão `false`: a vez é conduzida pelas etapas.                                                                                                                                                                                |

`signing_steps.status`: `pending` (não alcançada) → `active` (alcançada; condição verdadeira ou ausente) **ou** `skipped` (condição falsa). A transição sai de `pending` por `UPDATE ... WHERE status = 'pending'` — duas execuções concorrentes produzem uma transição e um evento.

### 2.2 Esquema fechado da condição (`App\Services\Envelopes\Steps\StepCondition`)

```json
{
    "match": "all | any",
    "rules": [
        {
            "type": "approver_decision",
            "recipient": "<ULID do aprovador>",
            "equals": "approved | refused"
        },
        {
            "type": "field_value",
            "field": "<ULID do campo>",
            "operator": "equals | not_equals | contains",
            "value": "<literal>"
        }
    ]
}
```

- Qualquer chave desconhecida, tipo de regra desconhecido, operador fora da lista, valor que não seja texto de 1 a `flow.max_literal_length` (padrão 200) caracteres sem quebra de linha, ou mais de `flow.max_rules_per_step` (padrão 10) regras: **recusa a definição inteira** (422, com a mensagem no caminho do campo, ex.: `steps.1.condition.rules.0.operator`).
- Caixa de seleção: valor `checked`/`unchecked`, só `equals`/`not_equals`; sem valor gravado conta como desmarcada.
- Texto: comparação sem diferenciar maiúsculas/minúsculas e com espaços normalizados; `contains` é substring literal.
- "Lista": o editor de campos (`FieldType`) não tem campo de lista hoje. Quando existir, entra no esquema com `equals`/`not_equals` — sem mudar o formato.
- Aprovador que delegou: vale a decisão do delegado (a cadeia `delegated_from` é seguida). Aprovador que não decidiu (ou cuja etapa foi pulada) não satisfaz nem "aprovou" nem "recusou".

### 2.3 Validação da definição (`StepDefinitionValidator`)

Cada participante (signatário, testemunha, aprovador) fica em **exatamente uma** etapa; o visualizador não entra; toda etapa tem participante; **a primeira etapa nunca tem condição**; a regra de decisão só aponta para um **aprovador de etapa anterior**; a regra de campo só aponta para caixa/texto preenchido por alguém de **etapa anterior**. Referência para a mesma etapa ou para frente é recusada — a condição é avaliada quando a etapa começa, e só o passado existe. Tetos: `flow.max_steps` (padrão 10).

A definição é gravada no preparo (`PUT envelopes.steps.update`, sob o lock de preparo). **No envio** ela é revalidada sob o mesmo lock do `SendEnvelope` contra o envelope como está naquele instante (participantes e campos podem ter mudado): inválida, o envio para com a mensagem e a transação inteira é desfeita. Depois do envio, a definição é congelada.

### 2.4 Progressão (`StepProgression`)

Chamada dentro da transação que já travou o envelope:

- **depois de cada aceite** (`RecordAcceptance::advance`, antes da regra de conclusão): se a próxima vez cai numa etapa `pending`, a condição é avaliada com os fatos do banco naquele instante (`EvaluationFacts`). Verdadeira → `active` + `envelope.step_started`; falsa → `skipped`, participantes `canceled`/`step_skipped` (links revogados se houver) + `envelope.step_skipped`, e a avaliação segue para a etapa seguinte. Sem participante pendente em etapa aplicável, o envelope segue para `finalizing` como sempre.
- **depois da recusa de um APROVADOR cuja decisão alguma etapa posterior lê** (`RecordRefusal`): em vez de encerrar, o fluxo é recalculado sob o mesmo lock. Resta etapa aplicável → a vez avança e os participantes dela são convidados; não resta → a recusa encerra o envelope, como sempre. Recusa de qualquer outro participante (ou de aprovador que nenhuma condição lê) encerra como na Fase 2.
- **recusa com a própria etapa ainda aberta** (revisão adversarial da onda F): se outro participante da MESMA etapa ainda está pendente, as etapas que leem a decisão só podem ser avaliadas quando a etapa terminar. A recusa fica em suspenso (`envelopes.settings.refusal_pending_close` = ULID do aprovador) e é decidida no fim da etapa, dentro do `RecordAcceptance::advance` (`StepProgression::deferredRefusal`): nenhuma etapa posterior se aplicou → o envelope é encerrado como **recusado** (`RecordRefusal::closeEnvelope`, `envelope.refused` com `deferred_until_step_end: true`); alguma se aplicou → a marca sai e o fluxo segue. Assim o resultado é o mesmo qualquer que seja a ordem dos cliques dentro da etapa (antes, recusar antes do coparticipante assinar levava o envelope a `completed`).
- **regra de produto — nunca "concluído" sem assinatura** (revisão adversarial da onda F): o envio já exige ao menos um signatário (`EnvelopeReadiness`), mas com etapas todos os signatários podem estar em etapas que acabam puladas (ex.: a única etapa com signatário depende de "recusou" e o aprovador aprova). Quando não resta pendente e nenhum participante com papel **signatário** aceitou, o envelope não vai para `finalizing`: é encerrado pelo sistema como **cancelado** (`CancelEnvelope`, com o motivo `RecordAcceptance::NO_SIGNATURE_REASON` — "Nenhuma etapa com signatário se aplicou: o documento foi encerrado sem assinatura."). A cota fica consumida (houve aceite de aprovador). Um ramo "só aprovação" não produz documento concluído; para isso, coloque um signatário em alguma etapa desse ramo.

Registro: a regra, a decisão esperada, o valor observado (limitado a 120 caracteres) e o resultado ficam em `signing_steps.evaluation` e no payload do evento. Nenhum texto do motivo de recusa ou de delegação vai para a trilha.

### 2.5 A vez (`current_order`) com etapas (`StepTurns`)

`current_order` continua sendo a vez corrente, e o núcleo continua comparando `order_index` com ela — só a origem da vez muda:

- `sequential` + etapas: uma vez por participante, na ordem (etapa, posição, id); cada etapa é um trecho contínuo de vezes;
- `parallel` + etapas: **a vez é a etapa** — todos os participantes da etapa assinam juntos.

`Envelope::hasTurns()` (= `isSequential() || usesSigningSteps()`) substituiu `isSequential()` onde a vez é conferida: `InvitationDispatcher` (convite e `isTheirTurn`), `ResendInvitations::eligible`, `ReminderPlanner`/`ReminderSender` (lembretes), `SignerLinkResolver::stateFor` (página pública: fora da vez = 404 genérico), `RecordAcceptance` (vez sob lock), `BatchItems` (lote), `InPersonKioskProps` (presencial). A expiração não muda: pendentes de qualquer etapa expiram. `RecipientSync::handle`/`reindex` chamam `StepTurns::apply` depois de gravar a lista (participante sem etapa vai para a última etapa, e o envio revalida). `Documents\EnvelopeReadiness::signingOrderIsCoherent` usa `StepTurns::isCoherent` com etapas.

### 2.6 Duplicar

`DuplicateEnvelope` copia as etapas com as referências das condições trocadas pelas da cópia (ULID do aprovador e do campo), todas `pending` (`FlowDuplication`). Referência sem correspondente fica como está e o envio da cópia a recusa — nunca aponta silenciosamente para outra pessoa.

## 3. Delegação auditada

### 3.1 Política do remetente (`DelegationPolicy`, em `envelopes.settings`, gravada no preparo e congelada no envio)

| Chave                              | Padrão  | Efeito                                                                          |
| ---------------------------------- | ------- | ------------------------------------------------------------------------------- |
| `allow_delegation`                 | `false` | O participante vê "Delegar a outra pessoa".                                     |
| `delegation_requires_confirmation` | `true`  | A delegação só vale depois que o remetente confirma.                            |
| `delegation_personal_recipients`   | `[]`    | ULIDs de participantes cuja participação é **pessoal** (não pode ser delegada). |

Limites da instalação (`config/assinavelox.php`, seção `delegation`): `max_chain_depth` (padrão **1** — quem recebeu por delegação não delega de novo), `max_requests_per_recipient` (padrão 3, qualquer desfecho), `max_per_organization_per_day` (padrão 50, janela de 24 h), `reason_min`/`reason_max` (10/500).

### 3.2 Pedido do participante (`DelegationService`)

Exige a **sessão autenticada pelo código** deste participante neste navegador (sem ela: 403 `session_required`; o GET responde 404). O participante informa nome, e-mail e motivo. Tudo é conferido de novo sob o lock do envelope. Proibições (código estável no JSON de erro):

| Proibição                                                                                        | Código                                   | HTTP |
| ------------------------------------------------------------------------------------------------ | ---------------------------------------- | ---- |
| Para si mesmo (mesma caixa de correio: maiúsculas, `+alias` e pontos do Gmail não diferenciam)   | `self`                                   | 422  |
| Para quem já está no envelope (inclusive visualizador/delegado; mesma regra de caixa de correio) | `participant`                            | 422  |
| Em cadeia além de `max_chain_depth`                                                              | `chain_limit`                            | 422  |
| Depois de aceitar ou recusar                                                                     | `already_acted`                          | 409  |
| Participação marcada como pessoal                                                                | `personal`                               | 422  |
| Remetente não permitiu                                                                           | `policy_disabled`                        | 404  |
| Já existe pedido pendente deste participante                                                     | `pending_exists`                         | 409  |
| Limite por participante / por organização                                                        | `recipient_limit` / `organization_limit` | 429  |

Freio por IP na rota: `throttle:6,10,sign-delegation-store`. O `delegations.ip_address` é **truncado** (/24 no IPv4, /48 no IPv6, `SubjectKeys::truncateIp`).

Revisão adversarial da onda F:

- **Caixa de correio, não texto**: `DelegationPolicy::mailboxKey` compara em minúsculas, sem o sufixo `+…` da parte local e, no Gmail/Googlemail, sem os pontos. Vale para `self`, `participant` e para o `recipient_exists` do `DelegationExecutor`. O e-mail gravado continua o digitado.
- **Tentativas recusadas contam**: `self` e `participant` somam num limite próprio por participante (`delegation.max_refused_attempts_per_recipient`, padrão 6 em 24 h, `RateLimiter`), conferido antes de qualquer resposta que revele quem está no envelope. Esgotado → `recipient_limit` (429). Sem isso, a resposta "já participa" permitia testar e-mails de coparticipantes trocando de IP.
- **Limite diário da organização sob o lock da organização**: a contagem de `max_per_organization_per_day` roda com a linha da organização travada (`SELECT … FOR UPDATE`), então pedidos simultâneos em envelopes diferentes não passam do limite juntos.

**Confirmação obrigatória mesmo com a política desligada** quando o participante tem autenticação reforçada ou captura exigida — código por SMS/WhatsApp, PIN do remetente, fotos ou vídeo curto exigidos: o delegado entra pelo código por e-mail, e uma exigência não se transfere para outra pessoa sem que o remetente saiba. Com vídeo exigido, o diálogo da página pública avisa que a pessoa indicada também vai gravar o vídeo (`video_required` no estado da delegação).

Com confirmação: o pedido fica `pending`, a trilha recebe `delegation.requested`, o remetente recebe aviso no sino (`delegation_requested`) e **até a decisão a participação continua sendo do original** (ele ainda pode assinar ou recusar). O pedido que perde o objeto fica **"sem efeito" já na transição** (`DelegationVoider`, evento `delegation.voided` com o código `closed`, `already_acted` ou `step_skipped`): aceite ou recusa de quem pediu, etapa dele pulada, conclusão, recusa que encerra, cancelamento e expiração. Antes, o pedido só virava `void` quando alguém tentava confirmá-lo, e ficava "aguardando decisão" para sempre nos outros casos.

### 3.3 Quando a delegação vale (`DelegationExecutor`)

Sob `SELECT ... FOR UPDATE` no envelope e no pedido: revalida coleta em andamento, prazo, pedido pendente, original ainda pendente e **sem aceite**, e-mail do delegado ainda livre. Então:

1. cria o **delegado** — participante novo, mesmo papel, `role_label`, posição, vez e etapa; `pending`, código por e-mail, `delegated_from_recipient_id`;
2. passa para ele os **campos** do original (o original não tem valor gravado) e a **exigência de fotos**, se houver — nunca uma exigência menor;
3. marca o original `delegated` + `status_reason = delegated` (não é transição da máquina genérica de `RecipientStatus`: é o único caminho, e o estado de origem acabou de ser conferido sob lock) e **revoga os links dele** — o link antigo deixa de abrir já na resolução do token (404 genérico);
4. grava `recipient.delegated` (ator: o participante, sem confirmação; o usuário que confirmou, com confirmação).

Depois do commit: revoga as sessões do original e convida o delegado se for a vez dele. O aceite do delegado passa pelo mesmo `RecordAcceptance`, com declaração, código e evidências próprios. Decisão de aprovador delegado conta como a decisão daquela posição para as condições.

### 3.4 Confirmação ou recusa do remetente

No detalhe do documento (`POST envelopes.delegations.approve|reject`, permissão `send`, coleta em andamento). "Confirmar delegação" abre um diálogo que diz a consequência antes do POST: o link do original deixa de valer, a pessoa indicada recebe convite e código próprios, herda etapa, campos e capturas exigidas e registra o próprio aceite; não há como desfazer. **Recusa mantém o original** exatamente como estava (`delegation.rejected`, observação opcional mostrada ao participante). Confirmar um pedido que perdeu o objeto (original já respondeu, coleta encerrada) marca-o `void` e mostra o motivo.

### 3.5 Decisões registradas

- "Papel pessoal" do roadmap = **participante** marcado pelo remetente (a lista é por participante, não por tipo de papel): o mesmo envelope pode ter um signatário pessoal e outro delegável.
- Limite de cadeia padrão **1**; configurável.
- Pedido pendente não bloqueia o original.
- Duplicar recomeça com o **participante original** (o delegado não é copiado; os campos voltam para o original).
- Aviso ao remetente só no sino nesta onda (sem e-mail): o catálogo de preferências não tem o evento `delegation_requested`.
- O convite do delegado usa o texto padrão do convite; a página pública mostra "Você recebeu este documento por delegação de {nome}. O aceite que você registrar é seu".

## 4. Evidências e trilha

- `EvidenceData::build()` ganha, **só quando houver** etapas ou delegações: `flow.steps` (etapa, situação, regra e valores avaliados em frases) e `flow.delegations` (quem → quem, pedido, confirmação, vigência/recusa, motivo, IP truncado conforme `evidence_show_ip`, navegador); e, por participante, `flow_note` ("Delegou a Carla Souza em … com confirmação de quem enviou. Motivo: “…”", "Participou por delegação de Maria Alves, com aceite próprio", "Não participou: a etapa 3 não se aplicou").
- `resources/views/evidence/page.blade.php`: a nota por participante e as seções "Delegações" e "Etapas do fluxo" (textos citados sempre escapados).
- Linha do tempo das evidências: `delegation.requested`, `recipient.delegated`, `delegation.rejected`, `envelope.step_started`, `envelope.step_skipped` entram junto com o aceite (sem config nova).
- A trilha do detalhe mostra os eventos com os rótulos de `AuditEventType::label()`.

## 5. Contratos para quem vem depois

### 5.1 Rotas

| Método | URI                                                      | Nome                            | Resposta                                                                      |
| ------ | -------------------------------------------------------- | ------------------------------- | ----------------------------------------------------------------------------- |
| GET    | `/documentos/{envelope}/fluxo`                           | `envelopes.flow.show`           | JSON `EnvelopeFlowState` (`view`); 404 com as flags desligadas e nada gravado |
| PUT    | `/documentos/{envelope}/etapas`                          | `envelopes.steps.update`        | JSON `{enabled, steps[]}` → estado; 422 com erros por caminho                 |
| PUT    | `/documentos/{envelope}/delegacao`                       | `envelopes.delegation.update`   | JSON `{allow, requires_confirmation, personal[]}` → estado                    |
| POST   | `/documentos/{envelope}/delegacoes/{delegation}/aprovar` | `envelopes.delegations.approve` | 302 back + flash                                                              |
| POST   | `/documentos/{envelope}/delegacoes/{delegation}/recusar` | `envelopes.delegations.reject`  | 302 back + flash (`note?`)                                                    |
| GET    | `/assinar/{token}/delegar`                               | `sign.delegation.show`          | JSON do cartão; 404 sem flag/política/sessão ou nada a mostrar                |
| POST   | `/assinar/{token}/delegar`                               | `sign.delegation.store`         | 201 `{status: pending                                                         | effective, to_email_masked, message}`ou erro com`code` |

Todas com `throttle` de prefixo próprio (o `throttle:N,M` genérico divide o contador por usuário com o link de verificação de e-mail).

### 5.2 Front

- `resources/js/components/envelopes/steps/`: `types.ts` (tipos do JSON), `use-envelope-flow.ts`, `flow-editor.tsx` (`SigningFlowEditor`, passo 4 do wizard) e `flow-panel.tsx` (`EnvelopeFlowPanel`, detalhe).
- `resources/js/components/sign/delegation/delegation-card.tsx` (`DelegationCard`, página pública).
- Ganchos de uma linha: `pages/envelopes/wizard.tsx`, `pages/envelopes/show.tsx`, `pages/sign/show.tsx`.
- `RecipientStatus` (TS) ganhou `delegated` (rótulo "Delegado", tom neutro, nota "Delegou a outra pessoa").
- Props compartilhadas: `features.conditional_steps` e `features.delegation`.

## 6. Eventos de auditoria novos (acrescentados no fim de `AuditEventType`)

`signing_steps.updated`, `envelope.step_started`, `envelope.step_skipped`, `delegation.policy_updated`, `delegation.requested`, `recipient.delegated`, `delegation.rejected`. Payload só com ULIDs, contagens, regra/valores avaliados e o e-mail **mascarado** do delegado.

## 7. Webhooks e API — pendência

O catálogo da §2.16 aceita extensão por contrato ("significado novo = tipo novo", `*` inclui os futuros), mas **não sem mudar o que existe hoje**: `WebhookEventType` é global e não conhece flags; acrescentar `recipient.delegated` e `envelope.step_skipped` mudaria, com as flags desligadas, a lista mostrada na tela de webhooks e o catálogo do REST Hooks (`SampleAndCatalogTest` exige amostra para cada tipo), e passaria a entregar os tipos novos a endpoints assinados em `*`. Por isso ficam **pendentes**, com o contrato proposto: `recipient.delegated` ← `recipient.delegated` (`data.recipient` do original + `data.delegate.id`, sem nome/e-mail/motivo) e `envelope.step_skipped` ← `envelope.step_skipped` (`data.step.{index, name}` + `canceled_recipients` como ULIDs, sem valores de campo). Precisa: catálogo sensível à flag (ou decisão do proprietário de publicar para todos), amostras em `RestHookSamples` e atualização de `docs/fase-2/webhooks.md`. Na API v1, o recurso de participante já devolve `status = delegated`.

## 8. Flags, configuração e o que falta para ligar em produção

- `ASSINAVELOX_FEATURE_CONDITIONAL_STEPS`, `ASSINAVELOX_FEATURE_DELEGATION` (global) **e** `plans.features.conditional_steps|delegation` (plano).
- `ASSINAVELOX_FLOW_MAX_STEPS|MAX_RULES|MAX_LITERAL`, `ASSINAVELOX_DELEGATION_MAX_CHAIN_DEPTH|MAX_PER_RECIPIENT|MAX_PER_ORG_DAY|MAX_REFUSED_ATTEMPTS`.
- **Flag desligada com rascunho que já tinha etapas** (revisão adversarial da onda F): no envio, `SigningStepsOnSend` desfaz as etapas como o "desligar etapas" do wizard (apaga `signing_steps`, limpa `signing_step_index`, `uses_signing_steps = false`, `RecipientSync::reindex`) e grava `signing_steps.updated` com `reason: feature_disabled_before_send` — o envio é exatamente o de antes (T8), e uma definição que ficou inválida não trava mais o rascunho. O `PUT envelopes.steps.update` com `enabled: false` também passa com a flag desligada (desligar não cria nada). Envelope **já enviado** continua pelas etapas.
- Antes de ligar: (1) **política de delegação padrão** e texto dos termos sobre delegação (jurídico — viabilidade §4.5); (2) decidir se o convite do delegado ganha texto próprio e se o remetente recebe **e-mail** do pedido (preferência `delegation_requested`); (3) decidir a publicação dos eventos em webhooks (§7); (4) revisar os limites por plano; (5) rodar um piloto com envelopes reais de aprovação (a revisão manual dos textos das evidências em PDF).

## 9. Alterações fora da área, registradas

| Arquivo                                                                                                    | Alteração mínima e motivo                                                                                                       |
| ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `app/Services/Documents/EnvelopeReadiness.php`                                                             | `signingOrderIsCoherent` usa `StepTurns::isCoherent` com etapas (no paralelo, a vez é a etapa — sem isso o envio era recusado). |
| `app/Http/Resources/RecipientListItemResource.php`                                                         | Braço `Delegated` no `match` da nota (sem ele, a lista de Assinaturas lançaria `UnhandledMatchError`).                          |
| `resources/views/evidence/page.blade.php`                                                                  | Nota por participante e seções "Delegações"/"Etapas do fluxo", só quando existem.                                               |
| `app/Notifications/Envelopes/DelegationRequestedNotification.php`                                          | Arquivo novo: aviso no sino ao remetente.                                                                                       |
| `resources/js/lib/labels.ts`, `resources/js/types/enums.ts`, `resources/js/components/avatar-initials.tsx` | `delegated` nos mapas de `RecipientStatus` (exigido pelo `RecipientStatusLabelTest`, que compara PHP × TS).                     |
| `tests/Unit/Models/EnumCatalogTest.php`                                                                    | Os 7 eventos novos na lista fechada do catálogo (cada onda acrescenta os seus; nada renomeado).                                 |

Compartilhados (edições aditivas): `routes/web.php`, `config/assinavelox.php`, `app/Enums/AuditEventType.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `tests/Feature/Smoke/AllGetRoutesTest.php`, `tests/Feature/Organizations/SharedPropsTest.php`, os três `pages/*.tsx`.

## 10. Limitações conhecidas (fora da área F-FLOW, só cosméticas)

- O aviso "Fulano assinou" ao remetente (`EnvelopeNotifications::notifySenderSigned`) conta no denominador todos os participantes pelo papel — inclusive quem delegou e quem ficou numa etapa pulada ("2 de 4" onde o esperado seria "2 de 3").
- Na página pública, `others[].signs_after_me` (`SignerPageProps`) só considera o sequencial; no paralelo com etapas os participantes das etapas seguintes aparecem sem a marca "assina depois".
- ~~A captura de **vídeo** exigida do original não é copiada para o delegado.~~ **Resolvido na integração I-3F** (docs/fase-3/onda-f-relatorio.md §3): `DelegationExecutor::copyVideoRequirement` passa a exigência de vídeo curto (com a mesma duração máxima) ao delegado, e `DelegationPolicy::hasStrongerAuthentication` passa a contar o vídeo exigido — delegar um participante com vídeo exigido sempre precisa da confirmação de quem enviou. O delegado também herda o **idioma e o fuso** escolhidos para a posição (F-I18N); ele pode trocar o idioma de exibição na própria página.

## 11. Testes (`tests/Feature/Phase3/Flow/`)

| Arquivo                    | Cobre                                                                                                                                                                                                                                                                                                                                                    |
| -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ConditionalStepsTest.php` | Os dois ramos (aprovou/recusou), recusa de aprovador não referenciado encerra, paralelo com etapas e condição de campo (marcada/desmarcada), concorrência (uma avaliação, um convite; pular uma vez a partir do mesmo estado), vez respeitada em convite/reenvio/lembrete/página pública, motor literal.                                                 |
| `StepDefinitionTest.php`   | Gravação e vez por etapa, estado JSON, desligar etapas, 14 formas de condição recusadas (expressão, chave, tipo, operador, campo de assinatura, quebra de linha, não-aprovador, mesma etapa, para frente, campo posterior, condição na 1ª etapa, visualizador, participante sem/duplicado), revalidação no envio, congelamento após o envio, duplicação. |
| `DelegationTest.php`       | Com confirmação (fim a fim até a finalização, evidência e PDF de evidências), sem confirmação, exigência reforçada força confirmação e é herdada, recusa do remetente, cada proibição, cadeia, depois do aceite (e pedido anulado), pessoal, sem política/flag/sessão, limites, duplicação.                                                              |
| `FlagsOffTest.php`         | Rotas 404 e nada gravado, vez e evidência idênticas no sequencial e no paralelo, recusa de aprovador encerra, desligar a flag depois do envio não abandona o envelope.                                                                                                                                                                                   |
