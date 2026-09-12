# Pagamentos ampliados e NFS-e por contrato — Fase 2, onda D (D-PAY)

Roadmap §2.20 (pagamentos ampliados) e §2.21 (NFS-e). Este documento descreve o que **está
implementado**, o que fica **atrás de configuração** e o que **não existe**. Fatos sobre o provedor
vêm de [`docs/integracoes/mercado-pago.md`](../integracoes/mercado-pago.md) e
[`docs/integracoes/mercado-pago-fase-2.md`](../integracoes/mercado-pago-fase-2.md); fatos fiscais,
de [`docs/integracoes/nfse.md`](../integracoes/nfse.md). O que lá está **NÃO CONFIRMADO** continua
assim aqui — virou configuração ou pendência, nunca suposição no código.

A base (Checkout Pro, webhook, ativação idempotente, inadimplência Q20, recibo interno) está em
[`docs/cobranca.md`](../cobranca.md) e não muda.

---

## 0. Flags

| Flag                | Escopo     | Padrão    | Liga                                                                                                                                              |
| ------------------- | ---------- | --------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `extended_payments` | plataforma | desligada | meios configuráveis + `date_of_expiration`, estorno, cancelamento, contestação e ordem comercial pelo webhook, conciliação, `admin.billing.index` |
| `fiscal_invoices`   | plataforma | desligada | situação da NFS-e por pagamento na tela de cobrança e o job de emissão (que hoje só roda com o simulador, fora de produção)                       |

As duas são **da plataforma** (`ASSINAVELOX_FEATURE_EXTENDED_PAYMENTS`,
`ASSINAVELOX_FEATURE_FISCAL_INVOICES`): quem recebe é a operadora, não um plano. **Com as duas
desligadas nada muda** — mesmas rotas respondendo o mesmo, mesmo corpo de preferência, mesmos props
na tela de cobrança, tópicos `chargebacks`/`merchant_order` ignorados, `admin.billing.index` no
placeholder. Os 80 testes de `tests/Feature/Billing` passam sem alteração;
`tests/Feature/Phase2/Billing/FlagsOffTest.php` verifica cada um desses pontos.

A prop compartilhada `features` (em `HandleInertiaRequests`, fora desta área) **ainda não expõe**
as duas chaves: as páginas desta área recebem o que precisam como props próprias (`extended`,
`fiscal_invoices`), o mesmo padrão já usado por `ToolFlags`. Ver §17.

---

## 1. O que existe, por item

| Item                                                     | Classe | Estado                                                                                                                         |
| -------------------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------------------------------ |
| Pix, boleto e cartão pelo Checkout Pro, por configuração | A      | implementado (§2)                                                                                                              |
| Estorno total e parcial                                  | A      | implementado (§3, §4)                                                                                                          |
| Cancelamento de pendente                                 | A      | implementado (§5)                                                                                                              |
| Contestação (chargeback) pelo webhook                    | A      | implementado (§6); envio de documentação da disputa segue **manual no painel do Mercado Pago**                                 |
| Ordem comercial pelo webhook                             | A      | implementado (§7)                                                                                                              |
| Conciliação diária                                       | A      | implementada (§8); o **agendamento** depende de uma linha em `routes/console.php` (fora da área, §17)                          |
| Painel interno de faturamento                            | A      | implementado (§9)                                                                                                              |
| Assinaturas recorrentes (preapproval)                    | **B**  | contrato + simulador identificado; produção desabilitada (§10)                                                                 |
| NFS-e                                                    | **B**  | `fiscal_invoices` + escolha de provedor; adaptador do Sistema Nacional **desabilitado**; simulador da onda B reutilizado (§11) |
| Relatórios de liberação/settlement                       | B      | **não implementados** nesta onda (a pesquisa os deixa para fase posterior: glossário de colunas NÃO CONFIRMADO)                |
| Checkout transparente / Card Payment Brick               | B      | **não implementados** (sem ganho de meio; aumentariam escopo PCI/CSP; se vierem, sobre a API de Orders)                        |

---

## 2. Checkout Pro com meios configuráveis

`App\Services\Billing\PaymentMethodPolicy`. Uma família é oferecida quando **as duas fontes**
dizem sim:

1. **configuração** — `MERCADOPAGO_ENABLED_METHODS` (`pix,boleto,card` por padrão);
2. **conta vendedora** — a última consulta registrada a `GET /v1/payment_methods` tem um meio da
   família com `status = active`.

Sem consulta registrada, a disponibilidade aparece como **"não consultada"** e vale só a
configuração. A tela de cobrança do cliente recebe `extended.methods[].available` (`true`/`false`
pela última consulta, `null` sem consulta) e, com algum meio oferecido ainda `null`, diz "Meios
configurados para o checkout: … (ainda não confirmados na conta do Mercado Pago)" — nunca "aceitos"
como fato. O comportamento do Checkout Pro quando a conta não tem chave Pix é **NÃO
CONFIRMADO**; por isso a regra operacional é consultar e registrar os meios ativos (botão
"Consultar meios ativos" no painel interno, `RefreshPaymentMethods`, tabela
`payment_method_checks`, que guarda só `id`, `name`, `payment_type_id` e `status` de cada meio).

O mapeamento família → tipo é o documentado: Pix = `bank_transfer`, boleto = `ticket`, cartão =
`credit_card`/`debit_card`/`prepaid_card`. Famílias não oferecidas vão para
`payment_methods.excluded_payment_types` da preferência, somadas à lista manual
`MERCADOPAGO_EXCLUDED_PAYMENT_TYPES`. `account_money` nunca é excluído (regra do provedor).

Prazo de Pix e boleto: `date_of_expiration` da preferência = agora +
`MERCADOPAGO_OFFLINE_EXPIRATION_HOURS` (72 h por padrão; limitado a 1 h..30 dias; o guia recomenda
ao menos 3 dias). A validade da preferência (`expiration_date_to`) continua a da Fase 1.

**O retorno do checkout continua apenas informativo** — nada disso toca `billing.return`.

---

## 3. Estornos

`POST /v1/payments/{id}/refunds` (total: corpo `{}` sem `amount`; parcial: `amount` em unidades),
`GET /v1/payments/{id}/refunds` para consultar. Serviço: `App\Services\Billing\RequestRefund`;
tabela `payment_refunds`.

### Idempotência em três camadas

1. **Pedido** — o formulário gera uma chave (UUID) por pedido; ela é gravada em
   `payment_refunds.idempotency_key` (UNIQUE) **antes** de qualquer chamada. Repetir o POST
   (duplo clique, reenvio) reencontra a linha e não chama o provedor de novo.
2. **Provedor** — a mesma chave vai como `X-Idempotency-Key` (obrigatório nessa rota) em toda
   repetição, inclusive nas do cliente HTTP em 5xx/429.
3. **Pagamento** — com um estorno aberto (`requested`, `pending`, `unknown`), outro pedido para o
   mesmo pagamento é recusado.

### Timeout = desconhecido (T5)

Resposta inconclusiva → estorno `unknown`, mensagem "Nada será repetido às cegas" e o job
`ResolveUnknownRefund` (fila `billing`, 1 min depois, espaçamento crescente). Ele **consulta antes
de repetir**: um estorno no provedor que ainda não é nosso e tem o mesmo valor é adotado; nenhum →
repete com a **mesma** chave; consulta também falhou → continua `unknown` e tenta de novo. Esgotadas
as tentativas, `alert=billing_refund_unresolved` no log. Semântica completa de
`X-Idempotency-Key` segue **NÃO CONFIRMADA** (mesma chave com corpo diferente, janela de retenção);
por isso a consulta vem primeiro.

### Confirmação

A resposta atualiza a linha do estorno (`approved`, `pending` para `in_process`/`authorized`,
`rejected`, `cancelled`). O estado do **pagamento** — e o efeito no plano — vem da consulta
`GET /v1/payments/{id}` feita logo em seguida pelo mesmo `SyncPaymentFromGateway` do webhook:
estorno total → `refunded`; parcial → continua `approved` com `status_detail =
partially_refunded`. `payments.refunded_cents` espelha `transaction_amount_refunded`. A linha do
estorno grava o valor **devolvido pelo provedor** (não o pedido), para o total estornado nunca passar
do valor pago.

**Estorno `pending` (`in_process`/`authorized`)** é concluído por
`App\Services\Billing\RefreshPendingRefunds`, que roda em toda consulta do pagamento
(`SyncPaymentFromGateway`: webhook `payment`, "Reconsultar pagamento", consulta depois do pedido)
quando há linha `pending`: 1) `GET /v1/payments/{id}/refunds` — o estorno com o mesmo id em
`approved`/`rejected`/`cancelled` fecha a linha; 2) se ainda pendente e o total estornado informado
pela consulta do pagamento já cobre os aprovados mais essa linha, ela é aprovada. Sem nenhuma das
duas, continua `pending` até a próxima consulta.

**Saldo estornável** (`Payment::refundableCents`) = valor − o maior entre `refunded_cents` e a soma
das linhas aprovadas, em processamento, solicitadas ou sem confirmação. Uma consulta que falhou
depois de um estorno aprovado (`billing.refund.sync_deferred`) não devolve o saldo cheio, e o pedido
sem valor só vira "integral" (corpo `{}`) quando nada foi estornado por nenhuma das duas fontes.

### Quem pede

| Quem                        | Onde                            | Regras                                                                                                                                                                        |
| --------------------------- | ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Administrador da plataforma | `admin.billing.payments.refund` | senha confirmada; total ou parcial; motivo obrigatório                                                                                                                        |
| Proprietário da organização | `billing.payments.refund`       | **só se** `ASSINAVELOX_BILLING_REFUND_INITIATORS` contiver `owner` (padrão: não); senha confirmada; só integral; dentro de `ASSINAVELOX_BILLING_OWNER_REFUND_WINDOW_DAYS` (7) |

Limites do provedor respeitados antes da chamada: só pagamento `approved` do gateway corrente,
até **180 dias** da aprovação (documentado), valor entre R$ 0,01 e o saldo estornável. Quantidade
máxima de parciais e código de "saldo insuficiente" são **NÃO CONFIRMADOS**: uma recusa do provedor
vira estorno `failed` com o código devolvido.

Durante "acessar como" nada disso é possível: a lista fechada `ReadOnlyRoutes` bloqueia todo POST.

---

## 4. Política de estorno no plano e na cota — decisão do proprietário

A decisão estava pendente (viabilidade §4.5 item 28). **Foi adotada a política conservadora e
registrada aqui como decisão do proprietário**, configurável só por código (mudá-la exige nova
decisão):

- **Estorno total** do pagamento que pagou o ciclo **vigente**: o ciclo pago é cancelado — a
  assinatura não renova (`cancel_at_period_end`), ganha `paid_cycle_refunded_at` e, **ao fim do
  período**, a organização volta ao plano Grátis **sem passar por `past_due`** (não há dívida). O
  acesso ao plano segue até lá; a cota já consumida **não é devolvida**. Implementação:
  `PaymentReversalEffects` + `SubscriptionLifecycle::expireRefundedCycles()` (dentro do
  `billing:dunning` diário, que já existe).
- **Estorno total de um ciclo antigo** (já houve pagamento posterior ativado): não mexe no plano.
- **Estorno parcial**: não altera plano nem cota.
- **Um novo pagamento aprovado** limpa `paid_cycle_refunded_at` e `cancel_at_period_end`.

"Aprovado depois de expirado/cancelado" continua **decisão pendente** (§17): hoje o webhook grava e
ativa como qualquer aprovação, e a conciliação o aponta.

---

## 5. Cancelamento de pagamento pendente

`App\Services\Billing\CancelPendingPayment`. Só `pending`, `in_process` e `authorized` (a
referência de `PUT /v1/payments/{id}` aceita exatamente esses; outro status é o erro 2018).
**Aprovado não se cancela: desfaz-se por estorno.**

- **Sem pagamento no provedor** (checkout aberto, nada pago): cancela só aqui
  (`status_detail = cancelled_before_payment`). A preferência expira sozinha na validade já enviada.
- **Com pagamento no provedor** (Pix/boleto gerado): `PUT /v1/payments/{id}` `{"status":"cancelled"}`
  com `X-Idempotency-Key = cancel-{ulid}`; o estado final vem da consulta. Timeout: nada muda aqui e
  o usuário é avisado.

Quem: `billing.payments.cancel` (quem tem `manage_billing` na organização) e
`admin.billing.payments.cancel` (senha confirmada).

---

## 6. Contestação (chargeback)

Tópico documentado `chargebacks` / `topic_chargebacks_wh`. Com `extended_payments`, o webhook
**continua exigindo assinatura válida** (mesmo `MercadoPagoSignature`, 401 sem nada gravado) e
responde 200 despachando `SyncMercadoPagoChargeback`, que:

1. consulta `GET /v1/chargebacks/{id}` (com `X-Caller-Id` = `MERCADOPAGO_SELLER_USER_ID` quando
   configurado — obrigatoriedade **NÃO CONFIRMADA**);
2. para cada pagamento nosso da contestação (mesmo ambiente), consulta `GET /v1/payments/{id}` — a
   fonte da verdade grava `charged_back`;
3. registra `payment_chargebacks` (valor, moeda, motivo, cobertura, situação e prazo da
   documentação).

Efeito (em `PaymentReversalEffects`, aplicado na transição para `charged_back` vinda da consulta,
pelo tópico `chargebacks` ou pelo `payment`): se o pagamento pagou o ciclo vigente, a assinatura
ativa vai para **`past_due`** (roadmap: "`charged_back` suspende envio como `past_due`" — bloqueia
só o envio) e a equipe é **alertada** (`alert=billing_chargeback` no log e, com
`ASSINAVELOX_BILLING_ALERT_EMAIL`, por e-mail). **Nada em envelopes, documentos, evidências ou
verificação pública é tocado**: um documento concluído continua concluído.

A resolução a favor da operadora (`coverage_applied = true`) é exibida no painel, mas **não
reativa** a assinatura sozinha — decisão de produto pendente (§17). O envio de documentação da
disputa (até 10 arquivos, 10 MB) fica manual no painel do Mercado Pago; o dossiê de evidências é
evidência de uso do serviço, **não prova de autoria do titular do cartão**.

---

## 7. Ordem comercial

Tópico `merchant_order` / `topic_merchant_order_wh` → `SyncMercadoPagoMerchantOrder` →
`GET /merchant_orders/{id}` → cada pagamento nosso da ordem é sincronizado pela consulta
`GET /v1/payments/{id}`. Cobre a preferência que gerou mais de um pagamento (boleto expirado + Pix
pago) e o aviso `payment` perdido.

---

## 8. Conciliação diária

`App\Services\Billing\ReconcilePayments` + `ReconcilePaymentsJob` (fila `billing`,
`WithoutOverlapping`). Busca `GET /v1/payments/search` na janela de `date_last_updated`
(D-`MERCADOPAGO_RECONCILIATION_WINDOW_DAYS`..agora, 2 dias por padrão), `sort=date_last_updated`,
`criteria=asc`, páginas de `page_size` (30, o padrão documentado) por `offset`, no máximo
`max_pages` (20). O teto de `limit`/`offset` é **NÃO CONFIRMADO**: passando do limite de páginas a
execução fica `partial`, sem fingir que viu tudo. O formato exato de `begin_date`/`end_date` não
está no brief (**NÃO CONFIRMADO**): enviamos ISO 8601 em UTC — conferir com credencial de teste.

Compara com a base local por `external_reference` (id do provedor como rede): **status, valor em
centavos e moeda**. Cada diferença vira `reconciliation_items` (`status_mismatch`,
`amount_mismatch`, `currency_mismatch`, `missing_local` — só para referência no formato ULID dos
nossos); a mesma divergência aberta não é duplicada nas execuções seguintes; divergência →
`alert=billing_reconciliation_divergence`; falha da busca → execução `failed` +
`alert=billing_reconciliation_failed`. Outro ambiente (`live_mode`) é ignorado.

**A conciliação não corrige nada.** O admin revisa: "Reconsultar pagamento" roda o caminho do
webhook (`GET /v1/payments/{id}`) e "Marcar como revisada" só anota a revisão.

Agendamento: `routes/console.php` está fora da área desta onda. Linha a acrescentar:

```php
Schedule::job(new \App\Jobs\Billing\ReconcilePaymentsJob)->dailyAt('04:10')->onOneServer();
```

Até lá, a execução é pelo botão "Conciliar agora" do painel interno.

---

## 9. Painel interno — `admin.billing.index`

`App\Http\Controllers\Admin\BillingController` (substitui o placeholder com a flag ligada),
página `resources/js/pages/admin/billing.tsx`. Só `platform-admin`; **sem acesso a documentos** —
nenhuma consulta toca envelopes, arquivos, evidências ou signatários.

- **Receita do período** por moeda, em centavos, sobre os pagamentos **recebidos no período**
  (`paid_at`): bruto; estornado (por pagamento, o maior entre `refunded_cents` — que inclui estorno
  feito direto no painel do Mercado Pago — e os estornos aprovados aqui, limitado ao valor pago);
  contestado (`charged_back_cents`: o restante de pagamentos `charged_back` sem cobertura a favor
  da operadora); e líquido = bruto − estornado − contestado — nunca somando moedas diferentes.
- **Contadores**: pendentes, contestações em disputa, inadimplentes, divergências abertas,
  estornos abertos.
- **Abas**: Pagamentos (filtro por período, ambiente, status e busca), Estornos, Contestações,
  Inadimplência, Conciliação (abertas/todas).
- **Situação**: última conciliação, meios oferecidos e última consulta, política de estorno,
  assinaturas recorrentes (desabilitadas, com o que falta) e NFS-e (modo e pendências).

A leitura é somente leitura. As operações são POSTs explícitos (`BillingActionController`):
estornar e cancelar com **senha confirmada**; reconsultar pagamento, conciliar agora, consultar
meios e marcar divergência como revisada. A trilha delas é a própria linha (`payment_refunds.
requested_by_user_id`, `reason`, datas, chave; `reconciliation_items.resolved_by_user_id`) mais o
log estruturado — os novos tipos de `platform_audit_events`/`audit_events` ficam para o dono
desses enums (§17).

---

## 10. Assinaturas recorrentes (preapproval) — classe B

`App\Integrations\Payments\Preapproval`: contrato `PreapprovalGateway` com os campos documentados
de `POST /preapproval` sem plano associado; `SimulatedPreapprovalGateway` (identificado:
`simulated = true`, `init_point` em `.invalid`, recusa operar em produção);
`MercadoPagoPreapprovalGateway` **desabilitado** (não faz chamada nenhuma; responde a mensagem do
que falta). `PreapprovalGatewayFactory`: em produção é **sempre** o desabilitado, qualquer que seja
`MERCADOPAGO_PREAPPROVAL_DRIVER`.

Mensagem em produção: "Assinaturas recorrentes (preapproval) estão desabilitadas em produção:
faltam o teste com conta vendedora real, a decisão sobre o conflito com a regra Q20 e a
confirmação dos meios aceitos. Cada ciclo continua sendo um pagamento avulso pelo Checkout Pro."

O que falta decidir e testar:

1. **Conta vendedora real** — quais meios `payment_methods_allowed` aceita e como Pix e boleto se
   comportam no segundo ciclo. "Preapproval só com cartão" **não é confirmado nem desmentido** pela
   documentação; a premissa conservadora do roadmap (recorrência automática só com cartão; Pix e
   boleto avulsos por ciclo) continua.
2. **Conflito com Q20** — o Mercado Pago faz até 4 retentativas em 10 dias e **cancela a
   assinatura após 3 parcelas recusadas**; a regra local é `past_due` em 3 dias e `expired` em 15.
   Decidir se `preapproval.cancelled` força `expired` ou reemite por link avulso.
3. **Webhooks `subscription_*`** — confirmar que o painel os entrega (a configuração pelo painel
   para Assinaturas é "verificar" na pesquisa).

`mp_preapproval_id` (reservado no roadmap) continua sem coluna nem uso.

---

## 11. NFS-e — classe B

- Tabela `fiscal_invoices` (`payment_id` UNIQUE, `idempotency_key = nfse-{ulid do pagamento}`,
  `status` pending | issued | canceled | failed | simulated). `fiscal_profiles` **não** foi criada: o
  tomador é o de `billing.profile.update`.
- `FiscalInvoiceProviderFactory` (`ASSINAVELOX_FISCAL_PROVIDER`): `none` (padrão) | `simulated`
  (reutiliza o `FakeFiscalInvoiceProvider` da onda B; só fora de produção; nunca emite nota) |
  `sefin_nacional` (`SefinNacionalFiscalInvoiceProvider`, **desabilitado**: `isConfigured()` falso,
  nenhuma conexão, toda operação responde a lista do que falta). O binding do contrato no container
  continua como estava.
- `IssueFiscalInvoiceJob` é despachado na primeira aprovação (com `fiscal_invoices` ligada).
  Sem provedor ou com provedor desabilitado, **nada é criado**. Com o simulador, a linha é
  `simulated`, sem número, código, PDF ou XML, e ao simulador só vai a referência do pagamento.
  Exceção do provedor que não seja indisponibilidade = **inconclusiva**: a linha fica `pending`
  com `error = inconclusive` e não é reemitida sozinha, porque o contrato atual não tem consulta
  pelo identificador da DPS (o `GET/HEAD /dps/{id}` do Sistema Nacional é o mecanismo previsto).
  O log leva `alert=fiscal_invoice_inconclusive` e o cliente vê "Nota fiscal: emissão sem
  confirmação — em análise pela equipe; não será reemitida automaticamente" (nada a consulta).
- **Interface**: com a flag, cada pagamento pago mostra **"Nota fiscal: não emitida — integração
  fiscal pendente"** enquanto não houver provedor (ou "simulada — nenhuma NFS-e foi emitida (sem
  validade fiscal)"). O rótulo é **NFS-e**, nunca "NF-e". O **recibo continua com o aviso** "Este
  recibo NÃO é documento fiscal e não substitui a nota fiscal de serviço (NFS-e)."

O que falta para a emissão real (lista exata, `SefinNacionalFiscalInvoiceProvider::MISSING`):

1. CNPJ, razão social, município (código IBGE) e regime tributário da operadora.
2. Confirmação de que o município emite pelo Emissor Nacional (`GET /parametros_municipais/{codigoMunicipio}/convenio`) e cadastro da operadora no **CNC**.
3. Certificado digital da operadora para **mTLS** e assinatura **XMLDSig** da DPS (`kind=fiscal_a1`, separado do A1 do PAdES), com o tipo aceito confirmado no Swagger/FAQ oficial.
4. **Parecer contábil**: subitem da LC 116/2003, código NBS, alíquota e retenções de ISS e tratamento de IBS/CBS em 2026/2027.
5. Escolha da biblioteca de XMLDSig e validação contra o XSD v1.01.
6. Rodada completa em produção restrita (emissão, rejeição, timeout → consulta, cancelamento) com fixtures gravadas.

> **Alerta ao proprietário — prazo regulatório.** A Resolução CGSN nº 191, de 04/08/2026, obriga
> **ME e EPP optantes do Simples Nacional** que prestam serviço sujeito à NFS-e a emitir a NFS-e de
> padrão nacional **a partir de 1º/11/2026**, pelo Emissor Nacional (web ou API)
> ([nfse §3.7](../integracoes/nfse.md)). Este documento **não afirma** o enquadramento da
> operadora — isso é decisão contábil. Se ela se enquadrar, a emissão manual (Emissor Nacional web
> ou o Sistema de Gestão do Mercado Pago) é contingência **fora do software** até o desbloqueio
> acima.

---

## 12. Privacidade: nada de cartão, nada do pagador

- Nenhuma tabela nova tem coluna para cartão ou pagador. As respostas passam pelo mesmo
  `MercadoPagoGateway::scrub()` e os DTOs novos só carregam ids, status, valores em centavos,
  moeda, datas e motivo.
- `payment_method_checks.methods` guarda só `id`, `name`, `payment_type_id`, `status`.
- Alertas e logs: ULIDs, centavos, moeda — nunca e-mail do pagador, token ou dado de cartão.
- `tests/Feature/Phase2/Billing/NoCardDataTest.php` injeta nas respostas simuladas o que o provedor
  poderia devolver (primeiros/últimos dígitos, titular, e-mail, CPF, QR Pix) e confere que nada
  disso sobra em nenhuma tabela.

---

## 13. Rotas

| Rota                                | Método e caminho                                           | Proteção                                                                                         |
| ----------------------------------- | ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| `billing.payments.cancel`           | `POST /configuracoes/plano/pagamentos/{payment}/cancelar`  | org + `org.role:owner,admin` + `manage_billing`                                                  |
| `billing.payments.refund`           | `POST /configuracoes/plano/pagamentos/{payment}/estorno`   | + permissão `delete_organization` (só proprietário, no controller), `password.confirm`, política |
| `admin.billing.index`               | `GET /admin/faturamento`                                   | `platform-admin` (placeholder sem a flag)                                                        |
| `admin.billing.payments.refund`     | `POST /admin/faturamento/pagamentos/{payment}/estorno`     | `platform-admin` + `password.confirm`                                                            |
| `admin.billing.payments.cancel`     | `POST /admin/faturamento/pagamentos/{payment}/cancelar`    | `platform-admin` + `password.confirm`                                                            |
| `admin.billing.payments.resync`     | `POST /admin/faturamento/pagamentos/{payment}/reconsultar` | `platform-admin`                                                                                 |
| `admin.billing.reconcile`           | `POST /admin/faturamento/conciliar`                        | `platform-admin`                                                                                 |
| `admin.billing.methods.refresh`     | `POST /admin/faturamento/meios`                            | `platform-admin`                                                                                 |
| `admin.billing.divergences.resolve` | `POST /admin/faturamento/divergencias/{item}/revisar`      | `platform-admin`                                                                                 |

Todas as novas respondem **404 com `extended_payments` desligada**. O webhook
`webhooks.mercadopago` é o mesmo.

---

## 14. Configuração

| Variável                                       | Padrão            | Para que serve                                           |
| ---------------------------------------------- | ----------------- | -------------------------------------------------------- |
| `ASSINAVELOX_FEATURE_EXTENDED_PAYMENTS`        | `false`           | flag da plataforma                                       |
| `ASSINAVELOX_FEATURE_FISCAL_INVOICES`          | `false`           | flag da plataforma                                       |
| `MERCADOPAGO_ENABLED_METHODS`                  | `pix,boleto,card` | famílias oferecidas (com a conta confirmando)            |
| `MERCADOPAGO_OFFLINE_EXPIRATION_HOURS`         | `72`              | `date_of_expiration` de Pix/boleto (1 h..30 dias)        |
| `MERCADOPAGO_SELLER_USER_ID`                   | —                 | `X-Caller-Id` na consulta de contestação                 |
| `MERCADOPAGO_RECONCILIATION_WINDOW_DAYS`       | `2`               | janela da conciliação                                    |
| `MERCADOPAGO_RECONCILIATION_PAGE_SIZE`         | `30`              | `limit` por página                                       |
| `MERCADOPAGO_RECONCILIATION_MAX_PAGES`         | `20`              | teto de páginas (depois disso: `partial`)                |
| `MERCADOPAGO_PREAPPROVAL_DRIVER`               | `disabled`        | `simulated` só fora de produção                          |
| `ASSINAVELOX_BILLING_REFUND_INITIATORS`        | `platform_admin`  | `platform_admin,owner` libera o pedido do proprietário   |
| `ASSINAVELOX_BILLING_OWNER_REFUND_WINDOW_DAYS` | `7`               | janela do proprietário                                   |
| `ASSINAVELOX_BILLING_REFUND_MAX_AGE_DAYS`      | `180`             | prazo do provedor (não passa de 180)                     |
| `ASSINAVELOX_BILLING_ALERT_EMAIL`              | —                 | e-mail dos alertas (sempre também no log)                |
| `ASSINAVELOX_FISCAL_PROVIDER`                  | `none`            | `none` \| `simulated` \| `sefin_nacional` (desabilitado) |

---

## 15. Migrations (D-PAY, só aditivas)

`2026_09_11_140201` colunas em `payments` (`payment_type_id`, `expires_at`, `refunded_cents`,
`cancelled_at`, `provider_updated_at`) · `140202` `subscriptions.paid_cycle_refunded_at` ·
`140203` `payment_refunds` · `140204` `payment_chargebacks` · `140205` `reconciliation_runs` ·
`140206` `reconciliation_items` · `140207` `payment_method_checks` · `140208` `fiscal_invoices`.
Todas compatíveis com MySQL 8 (índices com nome curto, strings indexadas ≤ 191).

---

## 16. Testes

`tests/Feature/Phase2/Billing/` — **64 testes** (460 asserções); nenhum acessa a rede (dublê
identificado ou `Http::fake` com `preventStrayRequests`). Os 80 testes da Fase 1 em
`tests/Feature/Billing/` seguem verdes sem mudança de asserção.

| Arquivo                      | Cobre                                                                                                                                                                                                                                                                                        |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `FlagsOffTest.php`           | flags nascem desligadas; placeholder; rotas novas 404; tópico de contestação ignorado; props da tela iguais; `charged_back` sem efeito no plano                                                                                                                                              |
| `RefundTest.php`             | total com chave e confirmação; repetição não duplica; timeout depois/antes de aplicar (consulta antes de repetir, mesma chave); parcial sem efeito; volta ao Grátis sem `past_due`; novo pagamento limpa; senha e platform admin; 180 dias, saldo, motivo; política do proprietário e janela |
| `CancelTest.php`             | só pendente; local sem provedor; PUT com chave estável; inconclusivo não muda nada; outra organização 404; cancelamento pelo admin com senha                                                                                                                                                 |
| `ChargebackTest.php`         | assinatura inválida/ausente 401; consulta + `charged_back` + `past_due` + alerta sem tocar documento concluído; reentrega não duplica; contestação desconhecida; tópico `payment`; ciclo antigo; ordem comercial; alerta por e-mail                                                          |
| `ReconciliationTest.php`     | divergência de status sem corrigir; de valor e de moeda em centavos; sem divergência sem alerta; falha do provedor; referência nossa × alheia; outro ambiente; sem duplicar; disparo e revisão pelo admin                                                                                    |
| `PaymentMethodsTest.php`     | só configuração sem consulta; família inativa na conta sai; falha mantém a anterior; `account_money`; tela mostra os meios                                                                                                                                                                   |
| `GatewayOperationsTest.php`  | contrato HTTP do adaptador real: estorno total/parcial com chave, timeout com a mesma chave, lista de estornos, PUT de cancelamento, contestação com/sem `X-Caller-Id`, busca paginada, meios, preferência com e sem a flag                                                                  |
| `RecurringAndFiscalTest.php` | preapproval desabilitado em produção com mensagem; simulador identificado; Sefin desabilitado com a lista; simulador fiscal nunca em produção; "não emitida — integração fiscal pendente"; linha `simulated` única e sem número; recibo não fiscal                                           |
| `AdminBillingTest.php`       | só platform admin; receita por moeda em centavos, estornos, contestações, inadimplência, divergências — sem conteúdo de documento; ações por linha                                                                                                                                           |
| `NoCardDataTest.php`         | nenhum dado de cartão ou do pagador nas tabelas novas                                                                                                                                                                                                                                        |

---

## 17. Pendências do proprietário e integrações fora desta área

Decisões do proprietário:

1. **Política de estorno** (§4) — adotada a conservadora; confirmar ou revisar.
2. **"Aprovado depois de expirado/cancelado"** — hoje ativa como qualquer aprovação; decidir se
   ativa, estorna ou só alerta.
3. **Contestação decidida a favor** (`coverage_applied = true`) — reativar o envio automaticamente
   ou só por ação do admin.
4. **Preapproval** — conta vendedora real, conflito com Q20, webhooks `subscription_*` (§10).
5. **NFS-e** — os seis itens do §11 e a escolha entre Sefin Nacional direto e provedor comercial;
   atenção ao prazo da Resolução CGSN 191/2026 (1º/11/2026) se a operadora for ME/EPP do Simples.
6. **Chave Pix** cadastrada na conta vendedora e primeira consulta de meios com a credencial real.
7. **Liberar o estorno pelo proprietário** (`ASSINAVELOX_BILLING_REFUND_INITIATORS`) e a janela.

Integrações que dependem de arquivos fora da área D-PAY:

- `routes/console.php`: agendar `ReconcilePaymentsJob` (linha no §8).
- `HandleInertiaRequests::features()`: expor `extended_payments` e `fiscal_invoices` na prop
  compartilhada (hoje as páginas recebem props próprias).
- `App\Enums\AuditEventType` / `App\Services\AdminLog\PlatformAction`: tipos próprios para
  estorno pedido/confirmado, cancelamento e contestação (hoje a trilha usa
  `subscription.canceled`, `subscription.past_due`, `payment.failed` e as tabelas novas).
- `IntegrationsServiceProvider`: o binding de `FiscalInvoiceProvider` continua aceitando só
  `fake`; os serviços desta onda escolhem o provedor pela `FiscalInvoiceProviderFactory`.
