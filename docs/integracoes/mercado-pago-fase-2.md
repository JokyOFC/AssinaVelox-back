# Mercado Pago — ampliação para a Fase 2 (roadmap §2.20 Pagamentos ampliados)

Data da pesquisa: 2026-09-11. Este documento **amplia** `docs/integracoes/mercado-pago.md` (Checkout Pro, webhooks, `GET /v1/payments/{id}`, `merchant_orders`, visão inicial de assinaturas, reembolso/cancelamento, SDK PHP 3.16.0). O que já está lá não é repetido; quando necessário, é referenciado como "brief base §N".

Convenções:

- Toda afirmação traz a URL oficial entre colchetes. Resumos de páginas oficiais foram obtidos pela versão Markdown (`.md`) ou pela página HTML da documentação `mercadopago.com.br/developers`.
- **NÃO CONFIRMADO** = não localizado em fonte oficial; não implementar como verdade.
- Classificação de disponibilidade: **(a)** API pública e documentada; **(b)** existe, mas exige credenciamento/contrato/elegibilidade; **(c)** sem API pública.

---

## 0. Mudança de contexto importante: API de Payments virou "legado" para Checkout Transparente

- A visão geral do Checkout Transparente via API de Payments diz, em resumo: o Mercado Pago agora oferece uma nova API para integrações de Checkout Transparente e, "se você está iniciando uma nova integração", recomenda a **API de Orders**. A API de Payments "continuará funcionando normalmente", mas **não receberá novas funcionalidades**, só correções de segurança e estabilidade. [https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/overview.md]
- A API de Orders: `POST https://api.mercadopago.com/v1/orders`, com `X-Idempotency-Key` obrigatório, `type` (`online`), `processing_mode` (`automatic` | `manual`), `total_amount` (deve ser igual à soma de `transactions.payments[].amount`), `external_reference` (máx. 64), `transactions.payments[].payment_method.{id,type,token,installments}`, `expiration_time` (duração ISO 8601). Para Pix/boleto, a resposta traz `payment_method.qr_code`, `qr_code_base64`, `ticket_url`, `barcode_content` e `digitable_line`. Erros 400: `empty_required_header`, `invalid_total_amount`, `required_properties`, `property_value`. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api/create-order/post]
- Consequência para o AssinaVelox: o **Checkout Pro (preferências)** continua sendo o caminho da Fase 1 e **já oferece** Pix, boleto e cartão (brief base §3). A API de Payments (`/v1/payments`) segue documentada e é a que os guias de Pix/boleto/Bricks usam, mas é legado para integrações novas. Se um dia houver checkout embutido (Card Payment Brick), a recomendação oficial é fazê-lo sobre Orders. Classificação: **(a)** para ambas.

---

## 1. Pix via API de Payments (`POST /v1/payments`, `payment_method_id = "pix"`)

### 1.1 Pré-requisito: chave Pix cadastrada

- Guia de Pix (Orders): "Para oferecer pagamentos via Pix, é necessário ter as chaves Pix cadastradas." [https://www.mercadopago.com.br/developers/pt/docs/checkout-api-orders/payment-integration/pix]
- Guia de Pix (Payments): "Após a criação das chaves Pix, é preciso realizar a captura de dados para pagamento." [https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/integration-configuration/integrate-pix]
- Isso fecha a pendência nº 4 do brief base **para o Checkout Transparente**. Para o **Checkout Pro**, a doc principal continua sem essa frase explícita. Regra operacional: sem chave Pix, tratar Pix como indisponível, confirmando pelo `status` de `pix` em `GET /v1/payment_methods` (brief base §3). O comportamento exato do Checkout Pro sem chave (oculta o meio ou dá erro) segue **NÃO CONFIRMADO**.
- A mensagem ou código de erro que a API de Payments devolve quando falta chave Pix é **NÃO CONFIRMADO**.

### 1.2 Request (exemplo oficial)

```bash
curl -X POST 'https://api.mercadopago.com/v1/payments' \
  -H 'accept: application/json' -H 'content-type: application/json' \
  -H 'Authorization: Bearer ENV_ACCESS_TOKEN' \
  -H 'X-Idempotency-Key: SOME_UNIQUE_VALUE' \
  -d '{
    "transaction_amount": 100,
    "description": "Título do produto",
    "payment_method_id": "pix",
    "payer": {
      "email": "PAYER_EMAIL", "first_name": "Test", "last_name": "User",
      "identification": { "type": "CPF", "number": "19119119100" },
      "address": { "zip_code": "06233200", "street_name": "Av. das Nações Unidas",
                   "street_number": "3003", "neighborhood": "Bonfim", "city": "Osasco", "federal_unit": "SP" }
    }
  }'
```

[https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/integration-configuration/integrate-pix.md]

- Campos: `transaction_amount`, `payment_method_id` e `payer.email` são obrigatórios no schema da referência (erros `4001 payment_method_id required`, `4002 transaction_amount required`, `4292` sem `X-Idempotency-Key`, `2131 Cannot infer Payment Method`). [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-payment/post]
- O guia de Pix pede também `payer.identification` (tipo e número) no exemplo. Não foi confirmado se `payer.identification` e `payer.address` são **obrigatórios** para Pix ou só recomendados: **NÃO CONFIRMADO**. Enviar ambos quando houver dado de faturamento (§2.21 NFS-e usa os mesmos dados).
- Opcionais úteis da referência: `description`, `external_reference`, `notification_url`, `statement_descriptor`, `metadata`, `binary_mode`, `additional_info` ("dados enviados para outras APIs, como Risco"). [mesma URL de referência]

### 1.3 Expiração (`date_of_expiration`)

- "Por padrão, a data de expiração para pagamentos com Pix é de **24 horas**, mas você pode alterá-la enviando o campo `date_of_expiration` na requisição de criação do pagamento. A data configurada deve estar entre **30 minutos e 30 dias** a partir da data de emissão do pagamento." [https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/integration-configuration/integrate-pix.md]
- Formato: `yyyy-MM-dd'T'HH:mm:ssz` (descrição do campo na referência). [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-payment/post]
- Guia de Orders: "Se passarem 30 dias após a data de vencimento estabelecida para um pagamento e este não tiver sido realizado, o Mercado Pago o considerará expirado." [https://www.mercadopago.com.br/developers/pt/docs/checkout-api-orders/payment-integration/pix]

### 1.4 Response: `point_of_interaction.transaction_data`

- `qr_code`: string "copia e cola" (exemplo oficial começa com `00020126600014br.gov.bcb.pix...`); `qr_code_base64`: imagem do QR em Base64; `ticket_url`: página hospedada pelo MP (`https://www.mercadopago.com.br/payments/{id}/ticket?caller_id=...&hash=...`). Estado inicial: `status: "pending"`, `status_detail: "pending_waiting_transfer"`. [https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/integration-configuration/integrate-pix.md]
- Formas de exibir: botão ou link para `ticket_url` ("todas as informações para pagamento, como QR Code, Pix Copia e Cola e as instruções de pagamento"), ou renderizar `qr_code_base64` como imagem mais um campo copiável com `qr_code`. [https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/integration-configuration/integrate-pix]
- A confirmação chega pelo webhook `payment` e pela consulta `GET /v1/payments/{id}` (brief base §4.6). O QR nunca ativa plano.

---

## 2. Boleto via API de Payments (`payment_method_id = "bolbradesco"`)

- `payment_method_id`: **`bolbradesco`**. [https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/integration-configuration/other-payment-methods.md]
- Dados do pagador: `first_name`, `last_name`, `email`, `identification` (`type: "CPF"`, `number`) e endereço. Texto oficial: "é obrigatório que os campos `zip_code`, `street_name`, `street_number`, `neighborhood`, `city` e `federal_unit` estejam presentes". [mesma URL]
- Expiração: padrão **3 dias**; intervalo permitido "entre 1 e 30 dias a partir da emissão do boleto"; formato ISO 8601 `yyyy-MM-dd'T'HH:mm:ssz`. A recomendação é de ao menos 3 dias, porque a aprovação do boleto leva até 2 horas úteis. [mesma URL]
- Resposta: `status: "pending"` e `transaction_details.external_resource_url` (URL com as instruções e o boleto). [mesma URL]
- Linha digitável e código de barras na resposta da **API de Payments** (nomes exatos dos campos, p. ex. `barcode.content` ou `transaction_details.digitable_line`): **NÃO CONFIRMADO**. O exemplo de resposta da referência não traz esses campos [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-payment/post]. Na **API de Orders**, os campos documentados são `payment_method.barcode_content` e `payment_method.digitable_line` [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api/create-order/post]. Na prática, basta usar `external_resource_url`.
- Cancelamento: "só é possível cancelar pagamentos pendentes ou em processamento". O guia também diz que, se um pagamento vence em até 30 dias, "o cancelamento é automático". [https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/integration-configuration/other-payment-methods.md]
- `pec` (pagamento em lotérica): **NÃO CONFIRMADO** para o Brasil. Não foi localizado nos guias consultados.

---

## 3. Cartão via Checkout Bricks (Card Payment Brick): tokenização no front

- O que é: formulário de cartão com campos de nome do titular, documento, número, validade e CVV. Esses campos "já cumprem os padrões de segurança PCI", o que dispensa o integrador de processar os dados. No `onSubmit`, o Brick "recebe os dados do formulário, incluindo o token do cartão". Aceita crédito e débito. [https://www.mercadopago.com.br/developers/en/docs/checkout-bricks/card-payment-brick/introduction.md]
- Front: o SDK JS é carregado de `https://sdk.mercadopago.com/js/v2`; em React, o pacote é `@mercadopago/sdk-react` com `initMercadoPago(<PUBLIC_KEY>, { locale })`. O componente `CardPayment` recebe `initialization` (`amount`), `onSubmit`, `onReady` e `onError`. Ao sair da tela, é preciso destruir a instância (`window.cardPaymentBrickController.unmount()`). [https://www.mercadopago.com.br/developers/en/docs/checkout-bricks/card-payment-brick/default-rendering.md]
- Exemplo oficial de `onSubmit` (JS): envia `formData` via `fetch('/process_payment', { method: 'POST', body: JSON.stringify(formData) })` e resolve ou rejeita a Promise. [https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/card-payment-brick/default-rendering]
- Back: `POST /v1/payments` com `token`, `transaction_amount`, `installments`, `payment_method_id`, `issuer_id` e `payer.email` (mais `payer.identification`, recomendado para aprovação), com o header `X-Idempotency-Key`. A doc manda **validar os dados vindos do front contra a sessão/pedido no backend** e recomenda 3DS 2.0 para aumentar a aprovação. [https://www.mercadopago.com.br/developers/en/docs/checkout-bricks/card-payment-brick/payment-submission.md]
- Pacote npm `@mercadopago/sdk-react`: versão **1.0.7**, licença **Apache-2.0**, `peerDependencies` React/ReactDOM `^16.8.0 || ^17.0.0 || ^18.0.0 || ^19.0.0` (compatível com React 19). [https://registry.npmjs.org/@mercadopago/sdk-react/latest] O README cita componentes seguros (`CardNumber`, `SecurityCode`, `ExpirationDate`) que permitem "certificação PCI SAQ A". [https://raw.githubusercontent.com/mercadopago/sdk-react/main/README.md]
- A lista exata de chaves do `formData` do Card Payment Brick (p. ex. `token`, `issuer_id`, `payment_method_id`, `transaction_amount`, `installments`, `payer.email`, `payer.identification`) não aparece enumerada nas páginas lidas. O exemplo de backend lê exatamente essas chaves, então elas são prováveis, mas ficam **NÃO CONFIRMADO** até um teste com credenciais.
- Regra AssinaVelox: o servidor **nunca** recebe o PAN nem o CVV, só `token` (uso único). Nada de cartão em log ou `audit_events`. A Public Key vai para o front; o Access Token fica só no backend (brief base §1.1).
- CSP: o SDK JS precisa de `sdk.mercadopago.com` e dos domínios de iframe e requisição do MP liberados. A lista completa de domínios exigida pelo Brick é **NÃO CONFIRMADO**.

---

## 4. Reembolsos (totais e parciais) e prazos

Complementa o brief base §7 (endpoint, header e erros principais).

- Regras (guia de cancelamentos e reembolsos): reembolso **total** ou **parcial**; "é possível reembolsar um pagamento dentro de **180 dias** a partir da data de aprovação"; exige "saldo suficiente disponível na sua conta"; no cartão de crédito "o valor será devolvido diretamente na fatura"; no Pix, boleto e dinheiro em conta "o valor será devolvido para a conta do pagador". [https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/payment-management/cancellations-and-refunds.md]
- Erros adicionais da referência de reembolso (API de Payments): `4295 Partial-charge-refund-is-not-allowed`, `4296 Charge-already-refunded`, além de `2024`/`15016` (pagamento antigo demais) e `3024` (parcial não suportado para a transação). [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-refund/post]
- Quantidade máxima de reembolsos parciais por pagamento: **NÃO CONFIRMADO**.
- Prazo para o dinheiro chegar ao pagador (dias até aparecer na fatura; tempo do Pix): **NÃO CONFIRMADO**. A doc fala do destino, não do prazo.
- Código de erro específico de saldo insuficiente no reembolso: **NÃO CONFIRMADO**.
- Estado local sugerido: `payment_refunds.status` espelha `approved | in_process | rejected | cancelled | authorized` (brief base §7). Com reembolso parcial, `payments.status` continua `approved` e `status_detail = partially_refunded` (brief base §5.1). Com reembolso total, fica `refunded`. `X-Idempotency-Key` próprio por solicitação (UUID gravado em `payment_refunds` antes da chamada, reusado em retentativas).

## 5. Cancelamentos

- Só com status `pending` ou `in_process` pelo guia ("Pendente ou Em processo"). A referência de cancelamento também aceita `authorized` (brief base §7). [https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/payment-management/cancellations-and-refunds.md]
- Expiração automática: o prazo "varia conforme o meio de pagamento integrado". Depois de expirar, `status = cancelled` e `status_detail = expired`. [mesma URL] Para Pix: 30 dias após o vencimento [https://www.mercadopago.com.br/developers/pt/docs/checkout-api-orders/payment-integration/pix].
- Recomendação: ao expirar localmente (`payments.expires_at`), chamar `PUT /v1/payments/{id}` com `status=cancelled` (Pix/boleto gerados via API) **antes** de gerar um novo pagamento. Isso reduz o risco de pagar duas vezes o mesmo ciclo. No Checkout Pro, os pagamentos pendentes de uma preferência podem continuar vivos, por isso a conciliação (§8) precisa pegar "aprovado após expirar", que exige decisão de produto (brief base §10).

---

## 6. Chargebacks (contestações)

- Notificação (API de Payments e Checkout Pro): evento **"Contestações"** no painel, com tópico `topic_chargebacks_wh` / `chargebacks` (Webhooks) ou `topic=chargebacks` (IPN). O recurso é `https://api.mercadopago.com/v1/chargebacks/[ID]` (brief base §4.2 e §4.6). [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-content/notifications/introduction.md]
- Notificação (API de Orders): evento "Chargebacks" no painel; payload com `"action": "order.charged_back"`, `"type": "order"`, `data.id` (id da order), `data.status: "charged_back"` e `transactions.chargebacks[]` (id, case id, valor). [https://www.mercadopago.com.br/developers/en/docs/checkout-api-orders/payment-management/chargebacks/notifications.md]
- `GET https://api.mercadopago.com/v1/chargebacks/{id}`: headers `Authorization` e **`X-Caller-Id`** ("ID do usuário autenticado (seller) e dono do recurso"). Campos: `id`, `payments[]` (pode ter vários, em compras de carrinho), `currency`, `amount`, `reason`, `reason_id`, `coverage_applied`, `coverage_eligible`, `documentation_required`, `documentation_status`, `documentation[]` (`type`, `url`, `description`, `uuid`), `date_documentation_deadline`, `date_created`, `date_last_updated`, `live_mode`. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/chargebacks/get-chargeback/get]
    - A obrigatoriedade de `X-Caller-Id` para o dono direto da conta (sem marketplace) é **NÃO CONFIRMADO**. Enviar o `user_id` do vendedor por segurança.
- Gestão (guia Orders; os endpoints `/v1/chargebacks` são os mesmos): `GET /v1/chargebacks/search` (por payment id), `POST /v1/chargebacks/{id}/documentation` (envio de evidências), `GET /v1/chargebacks/documentation/{type}/{uuid}`. Limite: **até 10 arquivos, 10 MB no total, JPEG/PNG/PDF**. `documentation_status`: `pending`, `review_pending`, `valid`, `invalid`, `not_supplied` (prazo vencido), `not_applicable`. `documentation_required` é campo legado ("documentação deve ser sempre enviada"). `coverage_applied = true` significa decisão a favor do vendedor. A resolução "pode levar até **6 meses**, conforme a bandeira". [https://www.mercadopago.com.br/developers/en/docs/checkout-api-orders/payment-management/chargebacks/management.md]
- Durante a disputa, "o valor contestado fica retido na conta do vendedor até o fim do processo". [https://www.mercadopago.com.br/developers/en/docs/checkout-pro/chargebacks]
- No pagamento: `status = charged_back` com `status_detail` `in_process` | `settled` | `reimbursed` (brief base §5.1).
- Para o AssinaVelox, o dossiê de evidências (§2 de `arquitetura.md`: aceite, IP, user-agent, hash do documento) pode compor a documentação da contestação, mas é **evidência de uso do serviço, não prova de autoria do titular do cartão**. O texto enviado ao MP deve seguir o vocabulário da arquitetura §2. Os tipos de evidência que as bandeiras aceitam para serviço digital são **NÃO CONFIRMADO**.

---

## 7. Assinaturas recorrentes (`preapproval_plan`, `preapproval`)

Complementa o brief base §6 (campos de criação e gestão).

- Estados de `preapproval` (referência de busca): `pending`, `authorized`, `paused`, `cancelled`. Campos úteis: `payment_method_id`, `next_payment_date`, `summarized.{quotas, charged_quantity, charged_amount, pending_charge_quantity, last_charged_date, semaphore}`. Busca: `GET https://api.mercadopago.com/preapproval/search`, com filtros `payer_id`, `payer_email` e `preapproval_plan_id`. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/search-preapproval/get]
- Estados de `preapproval_plan`: `active` | `canceled` (brief base §6.2).
- Faturas (`authorized_payments`): `GET https://api.mercadopago.com/authorized_payments/{id}`. Campos: `type` (`scheduled`), `preapproval_id`, `external_reference`, `transaction_amount`, `currency_id`, `debit_date`, `retry_attempt`, `next_retry_date`, `status` (`scheduled`, `processed`, `recycling`, `cancelled`), `payment.{id,status,status_detail}`, `rejection_code`. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/get-authorized-payment/get]
- Retentativas (assinatura com pagamento autorizado): a parcela fica em `recycling` "enquanto não tiver expirado ou não tiver atingido o número máximo de retentativas". O máximo é **4**, numa janela padrão de **10 dias** (ajustada ao vencimento). "Após **3 parcelas com pagamentos recusados**, a assinatura é **cancelada automaticamente**", e o vendedor é avisado por e-mail. Na criação, uma cobrança mínima de validação do cartão é feita e estornada. [https://www.mercadopago.com.br/developers/pt/docs/subscriptions/integration-configuration/subscription-no-associated-plan/authorized-payments.md]
- Assinatura com pagamento pendente: "o meio de pagamento não é definido quando a assinatura é criada". O comprador completa atualizando a assinatura via `PUT /preapproval/{id}` ou por link de pagamento "com o meio de pagamento de sua escolha". [https://www.mercadopago.com.br/developers/pt/docs/subscriptions/integration-configuration/subscription-no-associated-plan/pending-payments.md]
- Meios: a visão geral lista para o Brasil "Dinheiro em conta, Pix, cartão de crédito ou débito, Linha de Crédito, boleto" e diz que, "após o primeiro pagamento via Mercado Pago, as cobranças seguintes ocorrem automaticamente". [https://www.mercadopago.com.br/developers/pt/docs/subscriptions/overview.md]
    - **Conflito com o roadmap §2.20** ("Preapproval do Mercado Pago só com cartão"): a doc oficial **não confirma** essa restrição. Mas também **não explica** como Pix ou boleto recorrem automaticamente, e o schema de `payment_methods_allowed` no plano só traz objetos vazios no exemplo, sem enumerar valores. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/create-preapproval-plan/post] → mecânica por meio e valores aceitos em `payment_methods_allowed`: **NÃO CONFIRMADO**. Manter a premissa conservadora do roadmap (recorrência automática só com cartão; Pix/boleto avulsos por ciclo) até testar em conta real.
- **Conflito com Q20** (`past_due` após 3 dias, `expired` após 15): o MP faz retentativas próprias (até 4 em 10 dias) e **cancela a assinatura** após 3 parcelas recusadas. O estado local não pode ser só um espelho do MP. `subscriptions` deve ter o ciclo interno de inadimplência (Q20) e tratar `preapproval.cancelled` vindo do MP como evento externo que força `expired` (ou a reemissão por link avulso, se o produto decidir assim).
- Webhooks: `subscription_preapproval`, `subscription_preapproval_plan`, `subscription_authorized_payment` (brief base §4.2). A indisponibilidade da configuração via painel para Assinaturas continua como "verificar" (pendência 7 do brief base).

---

## 8. Conciliação: busca de pagamentos e relatórios

### 8.1 Busca de pagamentos (base do job diário do roadmap)

- `GET https://api.mercadopago.com/v1/payments/search`: `sort` (`date_approved`, `date_created`, `date_last_updated`, `id`, `money_release_date`) e `criteria` (`asc`/`desc`); filtros `external_reference`, `range` + `begin_date`/`end_date`, `limit` (padrão 30), `offset`, `collector.id`, `payer.id`. Resposta: `paging.{total,limit,offset}` + `results[]`. Restrições: só os **últimos 12 meses**; intervalo de datas de no máximo **365 dias**. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/search-payments/get]
- Valor máximo de `limit` e teto de `offset`: **NÃO CONFIRMADO**. Paginar por janela de `date_last_updated` em vez de offset alto.

### 8.2 Relatório de Liberações (Release report, "dinheiro liberado")

- Conteúdo: composição do saldo disponível, movimentos do período, bloqueios e desbloqueios (disputas), saques, coluna de detalhe da venda. Pode ser gerado pelo painel ou pela API ("manualmente, quantas vezes quiser, ou agendado"). [https://www.mercadopago.com.br/developers/en/docs/checkout-api-orders/resources/reports/released-money/introduction.md]
- Endpoints: `POST https://api.mercadopago.com/v1/account/release_report` (body `begin_date`, `end_date` em UTC, ambos obrigatórios; resposta **202** com `id`, `status: "pending"`, `created_from: "manual"`, `report_id`; erros `invalid_begin_date`, `invalid_end_date`, `end_date_before_begin_date`) [https://www.mercadopago.com.br/developers/pt/reference/releases-report/create-report/post]; `GET /v1/account/release_report/list` (itens com `id`, `status` (`processed`), `begin_date`, `end_date`, `created_from` (`manual`|`schedule`), `file_name`, `format` (`CSV`)) [https://www.mercadopago.com.br/developers/pt/reference/releases-report/consult-report/get]; `GET /v1/account/release_report/{file_name}` (arquivo CSV ou XLSX) [https://www.mercadopago.com.br/developers/pt/reference/releases-report/download-report/get].
- Configuração e agenda: `POST|PUT|GET /v1/account/release_report/config`, com `file_name_prefix`, `columns`, `frequency` (diária, semanal ou mensal, com hora), `sftp_info`, `separator`, `display_timezone` (padrão GMT-04), `report_translation` (`en`/`es`/`pt`), `notification_email_list`, `include_withdrawal_at_end`, `execute_after_withdrawal`, `check_available_balance`, `compensate_detail`, `scheduled` (somente leitura). Agendamento: `POST|DELETE /v1/account/release_report/schedule`. Formatos: CSV e XLSX. [https://www.mercadopago.com.br/developers/en/docs/checkout-pro/additional-content/reports/released-money/api]

### 8.3 Relatório de Dinheiro em conta (Settlement report)

- Conteúdo: "detalhe dos seus pagamentos, recebimentos, chargebacks e reembolsos em um período, com decomposição bruta e líquida". [https://www.mercadopago.com.br/developers/en/docs/checkout-api-orders/resources/reports/account-money/introduction.md]
- Endpoints: `POST https://api.mercadopago.com/v1/account/settlement_report` (body `begin_date`/`end_date` UTC; resposta 202 com `id`, `status: "pending"`, `report_id`, `created_from: "manual"`) [https://www.mercadopago.com.br/developers/pt/reference/settlements-report/create-report/post]; `GET /v1/account/settlement_report/list`, `GET /v1/account/settlement_report/search_report`, `POST|PUT|GET /v1/account/settlement_report/config`, `POST /v1/account/settlement_report/create`, `POST .../enable_automatic_generation`, `DELETE .../disable_automatic_generation`. Os campos incluem `format` (CSV/XLSX). [https://www.mercadopago.com.br/developers/pt/reference/settlements-report/query-report/get] Download: `GET /v1/account/settlement_report/{file_name}` (arquivo CSV/XLSX; 401 e 404). [https://www.mercadopago.com.br/developers/pt/reference/settlements-report/download-report/get]
- A página de consulta mostra `POST .../settlement_report/create`, e a página de criação mostra `POST .../settlement_report`: a discrepância entre os dois caminhos de criação fica **NÃO CONFIRMADO**, até testar qual responde.
- Lista de colunas e glossário de cada relatório (nomes exatos das colunas de CSV): **NÃO CONFIRMADO** neste levantamento. Os glossários ficam em `.../reports/account-money/glossary` e `.../report-fields`. Ler antes de escrever o parser. [https://www.mercadopago.com.br/developers/en/docs/checkout-api-orders/resources/reports/account-money/introduction.md]
- Classificação: **(a)**, com o Access Token da própria conta.

### 8.4 Uso recomendado no AssinaVelox

- `ReconcilePaymentsJob` diário: janela `date_last_updated` D-2..D0 via `/v1/payments/search`, comparando com `payments` locais (roadmap §2.20). É suficiente para detectar "aprovado sem webhook", "estornado ou contestado sem webhook" e divergência de valor.
- Relatórios de liberação e settlement: **fase posterior** (conciliação financeira de saldo líquido e taxas), gerados sob demanda pelo admin. São assíncronos (202 → listar → baixar), então precisam de job com polling ou agenda com `notification_email_list`.

---

## 9. Merchant Orders (ampliação)

- Busca: `GET https://api.mercadopago.com/merchant_orders/search`, com filtros `status`, `preference_id`, `application_id`, `payer_id`, `sponsor_id`, `external_reference`, `site_id`, `marketplace`, `date_created_from/to`, `last_updated_from/to`, `items`, `limit`, `offset`. Resposta: `elements[]`, `total`, `next_offset`. "**Somente os últimos 90 dias de dados serão retornados.**" Para dados mais antigos, usar `GET /merchant_orders/{id}`. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/merchant-orders/search-merchant-order/get]
- Atualização: `PUT /merchant_orders/{id}` existe na referência [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/merchant-orders/update-merchant-order/put]. Não há uso previsto no AssinaVelox.
- Uso: no Checkout Pro, uma preferência pode gerar vários pagamentos (boleto expirado + Pix pago). O webhook `topic_merchant_order_wh` e `GET /merchant_orders/{id}` (brief base §5.2) permitem decidir por `order_status = paid`. A conciliação por `external_reference` deve preferir `/v1/payments/search` (12 meses) a `/merchant_orders/search` (90 dias).

---

## 10. Pendências NÃO CONFIRMADAS (novas nesta ampliação)

1. Comportamento do Checkout Pro sem chave Pix (oculta o meio ou dá erro) e o código de erro da API de Payments sem chave Pix.
2. Obrigatoriedade de `payer.identification` e `payer.address` para Pix na API de Payments.
3. Nomes dos campos de linha digitável e código de barras do boleto na resposta da API de Payments (em Orders: `barcode_content`, `digitable_line`); existência de `pec` no Brasil.
4. Chaves exatas do `formData` do Card Payment Brick; lista de domínios para CSP.
5. Número máximo de reembolsos parciais; prazo de devolução por meio; código de erro de saldo insuficiente.
6. Obrigatoriedade de `X-Caller-Id` em `GET /v1/chargebacks/{id}` para conta própria; tipos de evidência aceitos para serviço digital.
7. Recorrência automática de Pix/boleto em Assinaturas e valores aceitos em `payment_methods_allowed` (contradiz ou confirma o roadmap "só cartão").
8. Teto de `limit`/`offset` em `/v1/payments/search`.
9. Caminho de criação do settlement report (`/settlement_report` vs `/settlement_report/create`) e glossário de colunas dos dois relatórios.

---

## Decisão recomendada para o AssinaVelox

| Item §2.20                                                                                                        | Disponibilidade                                                     | Decisão                                                                                                                                      |
| ----------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| Pix/boleto/cartão pelo **Checkout Pro** (configurar `payment_methods`, `date_of_expiration`, tratar `pending` longo) | (a)                                                                 | **Implementar de verdade agora**                                                                                                             |
| Reembolso total/parcial e cancelamento (`/v1/payments/{id}/refunds`, `PUT status=cancelled`)                        | (a)                                                                 | **Implementar de verdade agora**                                                                                                             |
| Webhook de chargeback + `GET /v1/chargebacks/{id}` + `past_due` por `charged_back`                                  | (a)                                                                 | **Implementar de verdade agora** (envio de documentação: só manual no painel, por ora)                                                        |
| Conciliação diária via `/v1/payments/search`                                                                        | (a)                                                                 | **Implementar de verdade agora**                                                                                                             |
| Merchant Orders (webhook + GET)                                                                                   | (a)                                                                 | **Implementar de verdade agora** (necessário para vários pagamentos por preferência)                                                          |
| Relatórios de liberação/settlement                                                                                | (a)                                                                 | **Contrato + fake identificado, produção desabilitada**; ativar depois de ler o glossário de colunas e testar com a conta real                  |
| Pix/boleto transparente (QR na própria tela) e Card Payment Brick                                                 | (a) — API de Payments é legado; Orders é a recomendada              | **Contrato + fake identificado, produção desabilitada**. Não é necessário na Fase 2: o Checkout Pro já cobre os três meios. Se for adotado, usar a **API de Orders** |
| Assinaturas (`preapproval`/`preapproval_plan`)                                                                    | (a), mas a mecânica de Pix/boleto e a política de retentativa conflitam com Q20 | **Contrato + fake identificado, produção desabilitada** (`mp_preapproval_id` segue reservado); renovação por link avulso por ciclo           |

Justificativa:

- Tudo o que está marcado como "implementar agora" usa endpoints públicos e documentados, com a **mesma credencial e o mesmo SDK da Fase 1** (`PaymentClient::cancel`, `PaymentRefundClient::refund/refundTotal`, `MerchantOrderClient::get`, `PaymentClient::search`; brief base §8). Cada ação continua coberta por `FakePaymentGateway` nos testes, e a produção depende só das credenciais já previstas no `.env`.
- Os fixtures "reais do sandbox" pedidos no aceite do roadmap têm uma limitação: pagamentos de teste **não disparam webhooks**, só o simulador do painel (brief base §1.4). As fixtures precisam ser capturadas via `GET` com credenciais de teste e marcadas como tal.
- Assinaturas ficam no fake porque a doc oficial não fecha o comportamento de Pix/boleto recorrentes. Além disso, o cancelamento automático do MP após 3 parcelas recusadas exige uma decisão de produto sobre como ele convive com `past_due`/`expired` (Q20).
- Checkout transparente e Brick ficam no fake porque aumentam o escopo de segurança (CSP, tokenização, 3DS) sem ganho de meios de pagamento, e porque a API de Payments não recebe mais novas funcionalidades.
- Para desbloquear Assinaturas em produção falta: (1) testar com conta vendedora real, confirmando quais meios `payment_methods_allowed` aceita e como Pix/boleto se comportam no ciclo 2; (2) decisão de produto sobre a relação entre o cancelamento automático do MP e Q20; (3) confirmar que o painel entrega os webhooks `subscription_*` (pendência 7 do brief base).
- Para desbloquear os relatórios falta: ler os glossários de colunas e testar qual caminho de criação do settlement report responde.
