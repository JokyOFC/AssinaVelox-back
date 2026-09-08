# Mercado Pago — Brief de integração (Checkout Pro + Webhooks) para SaaS Laravel 13 (Brasil)

Data da pesquisa: 2026-09-08. Todas as afirmações abaixo vêm da documentação oficial em `mercadopago.com.br/developers` ou do repositório oficial `github.com/mercadopago/sdk-php`. Cada fato traz a URL de origem entre colchetes. O que não pôde ser confirmado em fonte oficial está marcado como **NÃO CONFIRMADO**.

Observação metodológica: a documentação de referência (API) mudou de estrutura de URL. As URLs antigas do tipo `/developers/pt/reference/preferences/_checkout_preferences/post` hoje respondem "O que você estava procurando não existe mais ou mudou de lugar"; as URLs canônicas atuais estão sob `/developers/pt/reference/online-payments/checkout-pro-preferences/...` e são as citadas aqui. Os índices oficiais para agentes estão em `https://www.mercadopago.com.br/developers/pt/docs/llms.txt` e `https://www.mercadopago.com.br/developers/pt/reference/llms.txt`. Muitas páginas de guia expõem uma versão Markdown acrescentando `.md` à URL (ex.: `.../configure-back-urls.md`).

---

## 1. Credenciais, contas de teste, sandbox e `X-Idempotency-Key`

### 1.1 Public Key vs Access Token

- "**Public Key**: A chave pública da aplicação é geralmente utilizada no frontend. Permite, por exemplo, acessar informações sobre os meios de pagamento e criptografar os dados do cartão." "**Access Token**: Chave privada da aplicação que sempre deve ser utilizada no backend para gerar pagamentos. É essencial manter esta informação segura em seus servidores." [https://www.mercadopago.com.br/developers/pt/docs/your-integrations/credentials]
- Envie o Access Token sempre no header `Authorization: Bearer {{YOUR_ACCESS_TOKEN}}`, nunca como query param. [https://www.mercadopago.com.br/developers/pt/docs/your-integrations/credentials]
- As credenciais são vinculadas a uma **aplicação** criada em "Suas integrações"; caminho no painel: `Testes > Credenciais de teste` ou `Produção > Credenciais de produção`. [https://www.mercadopago.com.br/developers/pt/docs/your-integrations/credentials]

### 1.2 Credenciais de teste vs produção

- Produção exibe dois pares: `Public Key`/`Access Token` e `Client ID`/`Client Secret`. Para ativar as credenciais de produção é preciso informar Indústria, Website (obrigatório), aceitar Declaração de Privacidade e Termos, preencher reCAPTCHA e clicar "Ativar credenciais de produção". [https://www.mercadopago.com.br/developers/pt/docs/your-integrations/credentials]
- "As credenciais de teste não precisam ser ativadas. Assim que você cria uma aplicação, elas já estão disponíveis para uso imediato." [https://www.mercadopago.com.br/developers/pt/docs/your-integrations/credentials]
- **Prefixo do token**: "O Access Token de teste começa com o prefixo `APP_USR`, assim como o seu Access Token de produção." [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/create-application] — e, na página de contas de teste do Checkout Pro: a conta de teste **vendedor** "é criada automaticamente após a criação da aplicação e suas credenciais se tornam suas **credenciais de teste**. Por isso seu Access Token de teste começa com o prefixo `APP_USR`." [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/test-accounts]
    - O antigo prefixo `TEST-` não aparece na documentação atual: **NÃO CONFIRMADO** que ainda exista.
- Nota da página geral de credenciais (versão `.md`): "Test credentials are only available for Checkout Transparente and Checkout Bricks integrations." [https://www.mercadopago.com.br/developers/pt/docs/your-integrations/credentials.md] — enquanto a doc do Checkout Pro afirma que as credenciais da conta de teste vendedor **são** as credenciais de teste. Interpretação operacional para Checkout Pro: usar o Access Token da conta de teste vendedor (aba "Testes" da aplicação) no backend de homologação. A coexistência exata das duas afirmações no painel: **NÃO CONFIRMADO** — verificar no painel da aplicação.
- Credenciais podem ser compartilhadas com até 10 contas e renovadas; renovar invalida as antigas e exige atualizar a integração. [https://www.mercadopago.com.br/developers/pt/docs/your-integrations/credentials]

### 1.3 Contas de teste (usuários de teste)

- Até **15** contas de teste simultâneas; ainda não é possível apagá-las. Tipos: **Vendedor** ("configurar a aplicação e as credenciais para a cobrança"), **Comprador** ("testar o processo de compra"), **Integrador** (marketplace). País escolhido na criação, imutável; comprador e vendedor devem ser do mesmo país. Cada conta recebe User ID, usuário, senha e um **código de 6 dígitos** para verificação por e-mail no login. Logado como conta de teste, seções como "Credenciais de teste" não ficam acessíveis. [https://www.mercadopago.com.br/developers/pt/docs/your-integrations/test/accounts] e [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/test-accounts]
- Cartões de teste (Brasil): Mastercard `5480 8328 0103 3311`, Visa `4235 6477 2802 5682`, Amex `3753 651535 56885` (CVV 1234), Elo débito `5067 7667 8388 8311`; CVV `123`, validade `11/30`. Nome do titular controla o resultado: `APRO` aprovado (CPF `12345678909`), `OTHE` recusado erro geral, `CONT` pendente, `CALL` recusado com validação, `FUND` saldo insuficiente, `SECU` CVV inválido, `EXPI` validade, `FORM` erro de formulário, `CARD`, `INST`, `DUPL`, `LOCK`, `CTNA`, `ATTE`, `BLAC`, `UNSU`, `TEST`. [https://www.mercadopago.com.br/developers/pt/docs/your-integrations/test/cards] e [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/integration-test/test-purchases]
- Fluxo de compra de teste: logar em Mercado Pago Developers como **comprador de teste** (janela anônima, "para evitar erros por duplicidade de credenciais"), abrir o checkout a partir da preferência criada e pagar com cartão de teste; para Pix/boleto, "um teste bem-sucedido será aquele em que o estado do pagamento permanece como 'pendente'". [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/integration-test/test-purchases]

### 1.4 Comportamento "sandbox"

- Não existe um "modo sandbox" separado por host: o ambiente é determinado pelas credenciais usadas. A referência da API declara sobre o campo `sandbox_init_point`: "**Não utilize este parâmetro. Para testes de integração, utilize `init_point`.**" [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-preference/post]
- Em `GET /v1/payments/{id}`, `live_mode` "Indica se o pagamento foi feito em ambiente de produção ou em ambiente de teste." [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/get-payment/get]
- **Importante**: "Os pagamentos de teste, criados com credenciais de teste, não enviarão notificações. A única maneira de testar a recepção de notificações é através da Configuração através de Suas integrações" (simulador do painel). [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/payment-notifications]
- Ir para produção: trocar as credenciais de teste pelas de produção, SSL obrigatório em produção ("Although the SSL certificate is not required during the testing period, its implementation is mandatory for production"). [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/go-to-production]

### 1.5 Header `X-Idempotency-Key`

- Em `POST /v1/payments` (API Payments) o header é **OBRIGATÓRIO**: "Esta função permite repetir solicitações de forma segura, sem o risco de realizar a mesma ação mais de uma vez por engano. Isso é útil para evitar erros, como a criação de dois pagamentos idênticos, por exemplo. Para garantir que cada solicitação seja única, é importante usar um valor exclusivo no header da sua solicitação. Sugerimos o uso de um UUID V4 ou strings randômicas." Erro `4292`: "Header X-Idempotency-Key can't be null." [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-payment/post]
- Em `POST /v1/payments/{id}/refunds` o header também é **OBRIGATÓRIO** (mesmo texto; exemplo `X-Idempotency-Key: 2c197973-59e3-4ebd-acd6-1ad6efdd647d`). [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-refund/post]
- Em `POST /checkout/preferences` a referência lista apenas o header `Authorization` como obrigatório; `X-Idempotency-Key` **não** é listado. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-preference/post]
- Notícia oficial: a chave de idempotência é obrigatória nas APIs de Payments e Refunds; para novas integrações passou a ser exigida (datas conforme a notícia); quem atualiza o SDK recebe o suporte automaticamente. [https://www.mercadopago.com.br/developers/en/news/2023/01/04/Idempotency-key-usage-will-be-mandatory]
- Formato: a referência sugere UUID v4. Semântica exata de "mesma chave + payload diferente" e janela de retenção da chave: **NÃO CONFIRMADO** na documentação consultada.

---

## 2. Checkout Pro — `POST /checkout/preferences`, redirecionamento e retorno

Fonte principal da referência: [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-preference/post]. Guia: [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/create-payment-preference].

### 2.1 Endpoint e headers

- `POST https://api.mercadopago.com/checkout/preferences`
- Header `Authorization: Bearer <ACCESS_TOKEN>` — **OBRIGATÓRIO**; `Content-Type: application/json`.
- "Você precisa criar uma preferência de pagamento para cada pedido ou fluxo de pagamento que deseja iniciar." (guia; texto original em inglês na versão `.md`: "You need to create a payment preference for each order or payment flow you want to initiate.") [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/create-payment-preference.md]

### 2.2 Campos do body (descrições oficiais)

- `items` (array) **OBRIGATÓRIO** — "Informações sobre o item."
    - `items[].id` (string) **OBRIGATÓRIO** — "Identificador do item."
    - `items[].title` (string) **OBRIGATÓRIO** — "título do item, que será exibido durante o processo de pagamento, no checkout, nas atividades e nos e-mails."
    - `items[].description` (string), `items[].picture_url` (string, HTTPS obrigatório), `items[].category_id` (string; sugestão em `https://api.mercadopago.com/item_categories`).
    - `items[].quantity` (number) **OBRIGATÓRIO** — "Quantidade de itens. Esta propriedade é usada para calcular o custo total."
    - `items[].currency_id` (string) — "Código ISO_4217 ... o checkout sempre processa as transações em moeda local." Para o Brasil: `BRL`.
    - `items[].unit_price` (number) **OBRIGATÓRIO** — "Preço unitário do item... Pode conter duas casas decimais ou nenhuma."
- `payer` (object): `name`, `surname`, `email`, `phone.area_code`, `phone.number`, `identification.type` (ex.: `CPF`; lista em `/v1/identification_types`), `identification.number`, `address.zip_code|street_name|street_number`, `date_created`.
- `back_urls` (object): "URLs de retorno ao site do vendedor, automaticamente ("auto_return") ou através do botão 'Voltar ao site', segundo o status do pagamento. **É obrigatório o uso do protocolo ("https") na URL. URLs com protocolo HTTP (sem "s") são automaticamente descartadas pela API, que considerará o campo vazio.**"
    - `back_urls.success` — "URL de retorno ante o pagamento aprovado."
    - `back_urls.pending` — "URL de retorno diante de pagamento pendente ou em processo."
    - `back_urls.failure` — "URL de retorno ante o pagamento cancelado."
- `auto_return` (string): "No caso de estar especificado, o comprador será redirecionado para o site do vendedor automaticamente após a compra aprovada com cartão de crédito." Valores: `approved` ("O redirecionamento ocorre apenas para pagamentos aprovados com cartão de crédito.") e `all` ("compatibilidade futura somente se alterarmos o comportamento padrão").
- `notification_url` (string): "URL de notificações disponível para receber notificações de eventos relacionados ao Pagamento. A quantidade máxima de caracteres permitidos para envio neste parâmetro é de **248 caracteres**. É obrigatório o uso do protocolo ("https") na URL. Importante: esta URL **não é validada** pelos sistemas, portanto é responsabilidade do integrador garantir sua validade."
- `external_reference` (string): "Referência que pode sincronizar com seu sistema de pagamentos. Importante: Este campo deve ter no **máximo 64 caracteres** e deve conter apenas números, letras, hífens (-) e sublinhados (_)."
- `expires` (boolean): "Booleano que determina se uma preferência expira." `expiration_date_from` / `expiration_date_to` (string): "Data no formato "yyyy-MM-dd'T'HH:mm:ssz"" que indica início/fim da validade da preferência (ex.: `2022-11-17T09:37:52.000-04:00`). Guia de vigência: enviar `expires: true` + as duas datas. [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/term-of-preference]
- `date_of_expiration` (não listado no schema principal do POST, mas documentado no guia): define a data limite para **pagamentos offline e Pix**; recomendação de ao menos 3 dias; pode ser alterado por `PUT /checkout/preferences/{id}`. [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/expiration-date]
- `payment_methods` (object):
    - `excluded_payment_methods[].id` — "Métodos de pagamento excluídos do checkout (exceto account_money e wallet, que estarão sempre disponíveis)." (ex.: `visa`, `master`)
    - `excluded_payment_types[].id` — "Tipos de pagamento excluídos do processo de pagamento." (ex.: `ticket`)
    - `default_payment_method_id` (string), `installments` (number) — "Número máximo de parcelas."; `default_installments` (number). Erro `invalid_payment_methods`: "installments invalid. Should be a number between 1 and 36."
- `statement_descriptor` (string): "texto longo (até **13 caracteres**) que será exibido na fatura do cartão de crédito do pagador". Guia: [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/invoice-description]
- `metadata` (object): "JSON válido que pode ser adicionado ao pagamento para salvar atributos adicionais."
- `binary_mode` (boolean): "Quando definido como TRUE, os pagamentos só podem ser aprovados ou rejeitados. Caso contrário, eles também podem resultar in_process." Guia alerta que pode reduzir a taxa de aprovação. [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/binary-mode]
- `purpose: "wallet_purchase"`: restringe o checkout a usuários com conta Mercado Pago (desabilita dinheiro/transferência). [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/mp-wallet]
- Outros: `shipments`, `marketplace`, `marketplace_fee`, `differential_pricing`, `tracks`, `additional_info` (não relevantes para SaaS).
- Erro relevante `400 collector_does_not_comply_with_current_regulation`: "A conta do vendedor ("collector_id") tem requisitos pendentes de validação de identidade e conformidade regulatória para este site. Resolva-os antes de criar uma nova preferência."

### 2.3 Exemplo oficial de request (cURL, trecho)

```bash
curl -X POST 'https://api.mercadopago.com/checkout/preferences' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer APP_USR-...' \
  -d '{
    "items": [{"id": "Sound system", "title": "Dummy Title", "description": "Dummy description",
               "picture_url": "https://www.myapp.com/myimage.jpg", "category_id": "car_electronics",
               "quantity": 1, "currency_id": "BRL", "unit_price": 24.5}],
    "payer": {"name": "João", "surname": "Silva", "email": "test@testuser.com",
              "phone": {"area_code": "11", "number": 98765},
              "identification": {"type": "CPF", "number": "19119119100"}},
    "payment_methods": {"excluded_payment_methods": [{"id": "visa"}],
                        "excluded_payment_types": [{"id": "ticket"}],
                        "default_payment_method_id": "visa", "installments": 10, "default_installments": 5},
    "back_urls": {"success": "https://test.com/success", "pending": "https://test.com/pending", "failure": "https://test.com/failure"},
    "notification_url": "https://notificationurl.com",
    "auto_return": "approved",
    "external_reference": "1643827245",
    "expires": false,
    "expiration_date_from": "2022-11-17T09:37:52.000-04:00",
    "expiration_date_to": "2022-11-17T10:37:52.000-05:00"
  }'
```

[https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-preference/post]

### 2.4 Exemplo oficial de response (trecho)

```json
{
    "collector_id": 202809963,
    "items": [
        {
            "title": "Dummy Item",
            "description": "Multicolor Item",
            "currency_id": "BRL",
            "quantity": 1,
            "unit_price": "24.50"
        }
    ],
    "payer": {
        "email": "test@testuser.com",
        "identification": { "type": "CPF" }
    },
    "back_urls": {
        "success": "https://test.com/success",
        "pending": "https://test.com/pending",
        "failure": "https://test.com/failure"
    },
    "auto_return": "approved",
    "client_id": "6295877106812064",
    "notification_url": "https://notificationurl.com",
    "statement_descriptor": "MERCADOPAGO",
    "expiration_date_from": "2022-11-17T09:37:52.000-04:00",
    "expiration_date_to": "2022-11-17T10:37:52.000-05:00",
    "date_created": "2022-11-17T10:37:52.000-05:00",
    "id": "202809963-920c288b-4ebb-40be-966f-700250fa5370",
    "init_point": "https://www.mercadopago.com/mla/checkout/start?pref_id=202809963-920c288b-4ebb-40be-966f-700250fa5370",
    "sandbox_init_point": "https://sandbox.mercadopago.com/mla/checkout/pay?pref_id=202809963-920c288b-4ebb-40be-966f-700250fa5370",
    "metadata": {}
}
```

Campos da resposta: `id` — "ID exclusivo gerado automaticamente que identifica a preferência"; `init_point` — "URL gerado automaticamente para abrir o Checkout"; `sandbox_init_point` — "Não utilize este parâmetro. Para testes de integração, utilize init_point."; `client_id` — "É o Application ID"; `collector_id` — "É o mesmo que o Cust ID". [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-preference/post]

Endpoints correlatos: `GET /checkout/preferences/{id}`, `PUT /checkout/preferences/{id}`, `GET /checkout/preferences/search`. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/overview]

### 2.5 Redirecionamento e retorno (`back_urls`)

- A URL de retorno "deve ser uma página web controlável, como um servidor com domínio nomeado (DNS)". "Não utilize domínios locais no valor back_urls, como 'localhost/' ou '127.0.0.1'... Caso contrário, aparecerá a mensagem "Alguma coisa deu errado"." [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/configure-back-urls]
- `auto_return`: "Os compradores são redirecionados automaticamente ao site quando o pagamento é aprovado. O valor padrão é approved. O tempo de redirecionamento será de até **40 segundos** e não poderá ser personalizado. Por padrão, também será exibido um botão de "Voltar ao site"." [mesma URL]
- Meios offline (boleto etc.): "o comprador será redirecionado para a URL definida no atributo back_urls como **pending**". [mesma URL]
- "As back_urls fornecem vários parâmetros úteis por meio de uma solicitação **GET**." Exemplo oficial da requisição recebida pelo site do vendedor:

```
GET /test?collection_id=106400160592&collection_status=rejected&payment_id=106400160592&status=rejected&external_reference=qweqweqwe&payment_type=credit_card&merchant_order_id=29900492508&preference_id=724484980-ecb2c41d-ee0e-4cf4-9950-8ef2f07d3d82&site_id=MLC&processing_mode=aggregator&merchant_account_id=null HTTP/1.1
```

Parâmetros documentados na tabela: `payment_id` ("ID do pagamento do Mercado Pago"), `status` ("Status do pagamento. Por exemplo: approved... ou pending"), `external_reference`, `merchant_order_id` ("ID único da ordem de pagamento criada no Mercado Pago"). Os demais (`collection_id`, `collection_status`, `payment_type`, `preference_id`, `site_id`, `processing_mode`, `merchant_account_id`) aparecem no exemplo oficial, sem descrição individual na tabela. [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/configure-back-urls]

### 2.6 A URL de retorno NÃO deve ativar o plano

Regra de engenharia desta integração (não é citação da doc, mas decorre dela): a `back_url` é um **GET disparado pelo navegador do comprador** com parâmetros em query string, portanto forjável e não garantido (o comprador pode fechar a aba; para offline o retorno é sempre `pending`). O próprio guia orienta que, para meios offline, "o Mercado Pago será notificado, e o estado do pagamento será atualizado. Recomendamos que você configure as notificações de pagamento para que seu servidor receba essas atualizações e atualize o estado do pedido na sua base de dados" [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/configure-back-urls]; e a referência descreve os endpoints de pagamentos como "Endpoints de leitura para verificar o estado de um pagamento após receber a notificação webhook ou o redirect de retorno" [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/overview]. Logo: a página de retorno só deve exibir estado provisório; a ativação do plano acontece **exclusivamente** após webhook validado (assinatura) + `GET /v1/payments/{id}` retornando `status = approved` (ver §4 e §5), casando `external_reference` com o pedido interno.

---

## 3. Meios de pagamento no Checkout Pro (Brasil)

- Tabela comparativa oficial (linha "Meios de pagamento", coluna Checkout Pro, site MLB): "**Cartão de crédito ou débito, Pix, boleto, Conta Mercado Pago e Linha de Crédito**". Disponibilidade por país inclui BR. [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/overview]
- "Por padrão, todos os meios de pagamento estão disponíveis no Checkout Pro. Essa configuração pode ser personalizada por meio da preferência de pagamento... **O meio de pagamento Dinheiro em conta não pode ser excluído.**" [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/payment-methods]
- A lista efetiva para **a sua conta** vem de `GET https://api.mercadopago.com/v1/payment_methods` com o seu Access Token ("Lista os meios de pagamento disponíveis para o site, para incluí-los ou excluí-los na preferência"). Cada item tem `id` (ex.: `visa`, `pix`, `bolbradesco`), `name`, `payment_type_id`, `status` (`active` "Disponível para uso", `deactive` "Desativado", `temporally_deactive` "Indisponível para uso, possível interrupção do serviço"), `min_allowed_amount`, `max_allowed_amount`, `accreditation_time`. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/payment-methods/get] e [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/overview]
- Valores de `payment_type_id` (usados em `excluded_payment_types` e retornados em `GET /v1/payments/{id}`): `account_money` ("Dinheiro na conta do Mercado Pago"), `ticket` ("Boleto, Pago Fácil, Rapipago, PayCash, Efecty, Oxxo, Abitab e Red Pagos"), `bank_transfer` ("Pix, SPEI, PSE... e Yape"), `atm`, `credit_card`, `debit_card`, `prepaid_card`, `digital_currency` ("Compras com Linha de Crédito"), `digital_wallet` ("Paypal"), `voucher_card` ("Benefícios Alelo e Sodexo"), `crypto_transfer`. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/payment-methods/get] e [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/get-payment/get]
- Dependência da conta do vendedor:
    - Criação de preferência falha com `collector_does_not_comply_with_current_regulation` se a conta tiver "requisitos pendentes de validação de identidade e conformidade regulatória". [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-preference/post]
    - Pix: a doc de Pix do Checkout Transparente pressupõe a criação de chaves Pix antes de integrar ("After creating the Pix Keys, it is necessary to capture data for payment"). [https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/integration-configuration/integrate-pix.md] Para o **Checkout Pro** especificamente, a regra "Pix só é exibido se houver Chave Pix cadastrada" aparece em docs de plugins (VTEX/WooCommerce) e não foi localizada na doc principal do Checkout Pro: **NÃO CONFIRMADO** na doc principal (assumir que a conta precisa de chave Pix ativa e verificar via `/v1/payment_methods`).
    - Linha de Crédito / parcelamento sem cartão: disponibilidade por conta/comprador: **NÃO CONFIRMADO** (a doc só lista o meio).
- Exemplo oficial de exclusão:

```json
"payment_methods": {
  "excluded_payment_methods": [{"id": "master"}],
  "excluded_payment_types": [{"id": "ticket"}],
  "installments": 12
}
```

[https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/payment-methods]

---

## 4. Webhooks (Notificações)

Fontes: guia do Checkout Pro [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/payment-notifications]; guia geral de Webhooks [https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks] (cópia sob Checkout Pro: [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-content/notifications/webhooks.md]); introdução/tópicos [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-content/notifications/introduction.md]; IPN [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-content/notifications/ipn.md].

### 4.1 Duas formas de configurar

- **Via "Suas integrações"** (recomendado "para a maioria das integrações"): aplicação > `Webhooks > Configurar notificações`; abas **Modo de teste** e **Modo produtivo** com URL **HTTPS**; selecionar o evento **Pagamentos**; "Salvar configuração. Isso gerará uma **chave secreta** exclusiva para a aplicação... essa chave não possui prazo de validade, mas recomenda-se sua renovação periódica... Para renovar a chave, basta clicar no botão Restabelecer." Há botão **Simular** (escolhe URL de teste/produção, tipo de evento e `Data ID`). [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/payment-notifications]
- **Via `notification_url` na preferência**: "As URLs configuradas durante a criação de um pagamento terão **prioridade** sobre aquelas configuradas através de Suas integrações." Não usar `localhost`/`127.0.0.1`. HTTPS obrigatório. [mesma URL]
- Nota oficial: a configuração via painel não está disponível para QR Code e Assinaturas (informação da página geral de webhooks, conforme resumo da versão `.md`): **verificar** [https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks].

### 4.2 Tópicos/eventos relevantes para Checkout Pro

| Evento                                                                                                                        | Nome no painel       | `topic`/`type`                                                                                 | Produtos                                                 |
| ----------------------------------------------------------------------------------------------------------------------------- | -------------------- | ---------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| Criação e atualização de pagamentos                                                                                           | Pagamentos           | `payment`                                                                                      | Checkout Pro, Bricks, Assinaturas, Checkout API (legacy) |
| Criação, fechamento ou expiração de ordens comerciais                                                                         | Ordens comerciais    | `topic_merchant_order_wh` / `merchant_order`                                                   | Checkout Pro                                             |
| Contestações (chargebacks)                                                                                                    | Contestações         | `topic_chargebacks_wh` / `chargebacks`                                                         | Checkout Pro, Checkout API, Bricks                       |
| Reembolsos e reclamações                                                                                                      | Reclamações          | `topic_claims_integration_wh`                                                                  | Checkout Pro e outros                                    |
| Alertas de fraude                                                                                                             | Alertas de fraude    | `stop_delivery_op_wh` / `delivery_cancellation`                                                | Checkout Pro, Checkout API                               |
| Card Updater                                                                                                                  | Card Updater         | `topic_card_id_wh`                                                                             | Checkout Pro e outros                                    |
| Assinaturas                                                                                                                   | Planos e Assinaturas | `subscription_preapproval`, `subscription_preapproval_plan`, `subscription_authorized_payment` | Assinaturas                                              |
| [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-content/notifications/introduction.md] |

- Estilo Webhooks: body JSON com `type`/`action`, e query params `data.id` e `type` (ex.: `POST /test?data.id=123456&type=payment`). Estilo IPN (legado): apenas query params `topic` e `id` (ex.: `https://www.yoursite.com/notifications?topic=payment&id=123456789`), `topic` em `payment | chargebacks | merchant_order | point_integration_ipn`. "IPN notifications will be discontinued... they do not allow validation through the secret key". Para receber só Webhooks quando `notification_url` é usada, acrescentar `source_news=ipn`... (texto oficial: "To receive notifications exclusively via Webhooks and not via IPN, you can add the parameter `source_news=ipn` to the `notification_url`"). [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-content/notifications/ipn.md]

### 4.3 Payload (exemplo oficial)

```json
{
    "id": 12345,
    "live_mode": true,
    "type": "payment",
    "date_created": "2015-03-25T10:04:58.396-04:00",
    "user_id": 44444,
    "api_version": "v1",
    "action": "payment.created",
    "data": { "id": "999999999" }
}
```

Tabela oficial: `id` "ID da notificação"; `live_mode` "Indica se a URL inserida é válida"; `type` "Tipo de notificação recebida de acordo com o tópico"; `date_created` "Data de criação do recurso notificado"; `user_id` "Identificador do vendedor"; `api_version` "v1"; `action` "Evento notificado, que indica se é uma atualização de um recurso ou a criação de um novo" (ex.: `payment.created`, `payment.updated`); `data.id` "ID do pagamento, da ordem comercial ou da reclamação". [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/payment-notifications]

Exemplo oficial da requisição completa (headers relevantes):

```
POST /test?data.id=123456&type=payment HTTP/1.1
Content-Type: application/json
User-Agent: restclient-node/4.15.3
X-Request-Id: bb56a2f1-6aae-46ac-982e-9dcd3581d08e
X-Retry: 0
X-Signature: ts=1742505638683,v1=ced36ab6d33566bb1e16c125819b8d840d6b8ef136b0b9127c76064466f5229b
X-Socket-Timeout: 22000
{"action":"payment.updated","api_version":"v1","data":{"id":"123456"},"date_created":"2021-11-01T02:02:02Z","id":"123456","live_mode":false,"type":"payment","user_id":724484980}
```

[https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/payment-notifications]

### 4.4 Validação da assinatura (`x-signature` / `x-request-id`) — algoritmo oficial "sem SDK"

1. Separar o header `x-signature` por `,`: `ts=<timestamp em ms>` e `v1=<hash>`.
2. Montar o **manifesto** com o template oficial:
    ```
    id:[data.id_url];request-id:[x-request-id_header];ts:[ts_header];
    ```
    - `[data.id_url]` = valor do query param `data.id`. "**Se `data.id` for retornado com caracteres alfanuméricos maiúsculos, converta para minúsculas antes de usá-lo no manifesto**" (texto oficial: "If `data.id` is returned with uppercase alphanumeric characters, convert it to lowercase before using it in the manifest. For example, `ORD01JQ4S4KY8HWQ6NA5PXB65B3D3` should be used as `ord01jq4s4ky8hwq6na5pxb65b3d3`").
    - `[x-request-id_header]` = header `x-request-id`; `[ts_header]` = `ts` extraído.
    - "If any of the values (`data.id`, `x-request-id`) are not present in the received notification, you must remove them from the manifest before computing the HMAC."
3. Obter a chave secreta em Suas integrações > Webhooks > Configurar notificação.
4. Calcular `HMAC-SHA256` em hexadecimal com a **chave secreta como key** e o **manifesto como mensagem**; comparar com `v1`. Exemplo PHP oficial: `$cyphedSignature = hash_hmac('sha256', $data, $key);`
   [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-content/notifications/webhooks.md] e [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/payment-notifications]

Com SDK PHP (exemplo oficial da doc):

```php
use MercadoPago\Webhook\WebhookSignatureValidator;
use MercadoPago\Exceptions\InvalidWebhookSignatureException;

try {
    WebhookSignatureValidator::validate(
        $_SERVER['HTTP_X_SIGNATURE'],
        $_SERVER['HTTP_X_REQUEST_ID'],
        $_GET['data_id'],
        $secret
    );
    http_response_code(200);
} catch (InvalidWebhookSignatureException $e) {
    http_response_code(401);
}
```

[https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/payment-notifications]
Nota: o exemplo lê `$_GET['data_id']` (PHP converte `data.id` em `data_id` em `$_GET`); em Laravel use `$request->query('data.id')` ou leia a query string bruta. Discrepância: o código atual do validador do SDK monta `'id:' . $dataId` **sem** `strtolower` (release 3.11.3: "preserve data.id case in signature manifest"), enquanto a doc manda converter para minúsculas. Qual comportamento o servidor do MP efetivamente assina para IDs alfanuméricos: **NÃO CONFIRMADO** — para IDs numéricos de `payment` é irrelevante; implementar tentativa com o valor em minúsculas e, se falhar, com o valor original, registrando o caso. [https://raw.githubusercontent.com/mercadopago/sdk-php/master/src/MercadoPago/Webhook/WebhookSignatureValidator.php] e [https://github.com/mercadopago/sdk-php/releases]

### 4.5 Resposta esperada, timeout e retentativas

- "você deve devolver um **HTTP STATUS 200 (OK) ou 201 (CREATED)**. O tempo de espera para essa confirmação será de **22 segundos**. Se não for enviada essa resposta, o sistema entenderá que a notificação não foi recebida e realizará uma nova tentativa de envio **a cada 15 minutos**, até que receba a resposta. Após a terceira tentativa, o prazo será prorrogado, mas os envios continuarão acontecendo."
- Cronograma do diagrama oficial: tentativa 1: 0 min; 2: 15 min; 3: 30 min; 4: 6 horas; 5: 48 horas; 6, 7, 8: 96 horas. Header `X-Retry` indica a retentativa.
  [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/payment-notifications]

### 4.6 Fluxo recomendado

"Após responder a notificação, confirmando seu recebimento, você pode obter todas as informações sobre o evento do tópico payments notificado fazendo um **GET ao endpoint `v1/payments/{id}`**. Com essas informações, você poderá realizar as atualizações necessárias na sua plataforma, como por exemplo, atualizar um pagamento aprovado." Tabela tópico → recurso: `payment` → `https://api.mercadopago.com/v1/payments/[ID]`; `topic_merchant_order_wh` → `https://api.mercadopago.com/merchant_orders/[ID]`; `topic_chargebacks_wh` → `https://api.mercadopago.com/v1/chargebacks/[ID]`; `subscription_preapproval` → `https://api.mercadopago.com/preapproval/search`; `subscription_authorized_payment` → `https://api.mercadopago.com/authorized_payments/[ID]`. [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/payment-notifications] e [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-content/notifications/webhooks.md]

Implementação Laravel (decorrente): rota `POST` sem CSRF; validar assinatura; responder 200 imediatamente (enfileirar job); job faz `GET /v1/payments/{data.id}`, verifica `status`, `external_reference`, `transaction_amount`, `currency_id`, `live_mode`; processar de forma **idempotente** (retentativas e `payment.created` + `payment.updated` chegam para o mesmo pagamento); tratar a notificação apenas como gatilho — a fonte da verdade é o `GET`.

---

## 5. `GET /v1/payments/{id}` e `GET /merchant_orders/{id}`

### 5.1 `GET https://api.mercadopago.com/v1/payments/{id}`

Header `Authorization` obrigatório; path `id` (number). [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/get-payment/get]

Campos (descrições oficiais):

- `id` (number) — "Identificador único de pagamento".
- `status` (string) — "É o estado atual do pagamento": `pending` ("O usuário ainda não concluiu o processo de pagamento (por exemplo, após gerar um boleto...)"), `approved` ("O pagamento foi aprovado e creditado com sucesso."), `authorized` ("autorizado, mas ainda não foi capturado"), `in_process` ("em análise"), `in_mediation` ("O usuário iniciou uma disputa"), `rejected` ("rejeitado (o usuário pode tentar pagar novamente)"), `cancelled` ("cancelado por uma das partes ou expirou"), `refunded` ("reembolsado ao usuário"), `charged_back` ("Um chargeback foi aplicado no cartão de crédito do comprador").
- `status_detail` (string) — ex.: `accredited` ("Pagamento creditado"), `partially_refunded`, `pending_capture`, `by_collector`/`by_payer`/`by_admin`/`expired` (para `cancelled`: "cancelado após ficar com status pendente por 30 dias"), `offline_process`, `pending_contingency`, `pending_review_manual`, `deferred_retry`, `pending_waiting_transfer`, `pending_waiting_payment`, `pending_challenge`, `in_process`/`settled`/`reimbursed` (para `charged_back`), `refunded`, `bank_error`, `cc_rejected_3ds_challenge`, `cc_rejected_bad_filled_card_number`, `cc_rejected_bad_filled_date`, `cc_rejected_bad_filled_other`, `cc_rejected_bad_filled_security_code`, `cc_rejected_blacklist`, `cc_rejected_call_for_authorize`, `cc_rejected_card_disabled`, `cc_rejected_duplicated_payment`, `cc_rejected_high_risk`, `cc_rejected_insufficient_amount`, `cc_rejected_invalid_installments`, `cc_rejected_max_attempts`, `cc_rejected_other_reason`, `cc_rejected_time_out`, `rejected_by_bank`, `rejected_by_regulations`, `rejected_high_risk`, `rejected_insufficient_data`, `rejected_other_reason`, `insufficient_amount`, `cc_rejected_card_type_not_allowed`, `pending` (mediação). Tabela status × status_detail também em [https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/response-handling/query-results].
- `transaction_amount` (number) — "Custo do produto."; `transaction_amount_refunded`; `currency_id` (string); `payment_method_id` (string) — "Caso seja um pagamento com cartão, indicará a bandeira" (ex.: `visa`, `pix`, `bolbradesco`); `payment_type_id` (string) — `credit_card`, `debit_card`, `ticket`, `bank_transfer`, `account_money`, `prepaid_card`, `digital_currency`, `digital_wallet`, `voucher_card`, `crypto_transfer`, `atm`; `external_reference` (string); `date_created`, `date_approved` ("Um pagamento pode ser gerado em um estado intermediário e depois aprovado, portanto, a data de criação nem sempre coincidirá com a data de aprovação"), `date_last_updated`, `date_of_expiration`, `money_release_date`; `payer.id`, `payer.email` ("Por razões de segurança, os dados pessoais do pagador são retornados como null na resposta" — texto do campo `payer`), `payer.identification.type|number`, `payer.type` (`customer`/`guest`); `live_mode` (boolean); `metadata` (object); `statement_descriptor`; `installments`; `captured`; `binary_mode`; `notification_url`; `processing_mode` (`aggregator`/`gateway`); `merchant_account_id`; `transaction_details.total_paid_amount|net_received_amount|installment_amount|external_resource_url`; `operation_type` (`regular_payment`, `recurring_payment`, ...). [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/get-payment/get]
- `order.id`: o schema da resposta de `GET /v1/payments/{id}` na referência **não enumera** `order`; porém o exemplo oficial de resposta de `PUT /v1/payments/{payment_id}` (cancelamento) traz `"order": [{"type": "mercadopago", "id": "3754501423"}]` [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-cancellation/put] e o código oficial do guia IPN usa `MercadoPago\MerchantOrder::find_by_id($payment->order->id)` [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-content/notifications/ipn.md]. Presença garantida de `order.id` em todo pagamento do Checkout Pro: **NÃO CONFIRMADO** — tratar como opcional.

Exemplo oficial de resposta (trecho):

```json
{
    "id": 1,
    "date_created": "2017-08-31T11:26:38.000Z",
    "date_approved": "2017-08-31T11:26:38.000Z",
    "payment_method_id": "visa",
    "payment_type_id": "credit_card",
    "status": "approved",
    "status_detail": "accredited",
    "currency_id": "BRL",
    "description": "Pago Pizza",
    "collector_id": 2,
    "payer": {
        "id": 123,
        "email": "test_payer@example.com",
        "identification": { "type": "CPF", "number": "19119119100" },
        "type": "customer"
    },
    "metadata": {},
    "external_reference": "MP0001",
    "transaction_amount": "24.50",
    "transaction_amount_refunded": 0,
    "transaction_details": {
        "net_received_amount": 250,
        "total_paid_amount": "50.00",
        "overpaid_amount": 0,
        "installment_amount": 250
    },
    "installments": 1
}
```

[https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/get-payment/get]

Erros: `404 2000 Payment not found`; `400 3 Token must be for test`; `403 4 The caller is not authorized to access this resource`. [mesma URL]

### 5.2 `GET https://api.mercadopago.com/merchant_orders/{id}`

Header `Authorization` obrigatório; path `id` (number). [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/merchant-orders/get-merchant-order/get]

- `status` (string): `opened` ("Pedido sem pagamentos"), `closed` ("Pedido com pagamentos que cobrem o valor total"), `expired` ("Pedido cancelado que não possui pagamentos aprovados ou pendentes").
- `order_status` (string) — "Estado atual da merchant order de acordo com o status dos pagamentos": `payment_required`, `reverted`, `paid` ("a soma de todos os pagamentos "aprovados", "estorno" ou "em_mediação" cobre o valor total do pedido"), `partially_reverted`, `partially_paid`, `payment_in_process`, `undefined`, `expired`.
- `preference_id` (string) — "Identificador da preferência de pagamento associada ao pedido"; `external_reference` (string); `payments[]` com `id`, `transaction_amount`, `total_paid_amount`, `shipping_cost`, `currency_id`, `status`, `status_detail`, `operation_type`, `date_approved`, `date_created`, `last_modified`, `amount_refunded`; `total_amount`, `paid_amount`, `refunded_amount`, `notification_url`, `site_id` (`MLB` = Brasil), `collector`, `payer`, `items[]`, `canceled` (boolean), `date_created`, `last_updated`.
  Exemplo oficial (trecho): `{"id": 9999999999, "status": "closed", "external_reference": "default", "preference_id": "Preference identification", "payments": [{"id": 9999999999, "transaction_amount": 1, "total_paid_amount": 1, "currency_id": "BRL", "status": "approved", "status_detail": "accredited", ...}], "total_amount": 5, "paid_amount": 5, "refunded_amount": 0, "site_id": "mla", "order_status": "paid"}`. [mesma URL]

Uso no SaaS: o `merchant_order` agrega vários `payments` da mesma preferência (ex.: boleto expirado + cartão aprovado). Regra oficial do guia IPN: somar `transaction_amount` dos pagamentos `approved` e liberar quando `>= total_amount`. [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-content/notifications/ipn.md]

---

## 6. Assinaturas / recorrência (`/preapproval`, `/preapproval_plan`) e Checkout Pro

### 6.1 Checkout Pro **não** faz cobrança recorrente

- Na tabela comparativa oficial da visão geral do Checkout Pro, a linha "Pagamentos recorrentes" mostra "-" (não marcado) para Checkout Pro e marcado para Checkout Transparente e Checkout Bricks (dados de origem da tabela: `line_text: Pagamentos recorrentes / line_values: false|true|true`). [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/overview]
- Recorrência é um produto separado, **Assinaturas**, com checkout próprio: `init_point` do tipo `https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=...` (ou `preapproval_plan_id=...`). [https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/create-preapproval/post] e [https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/create-preapproval-plan/post]
- Consequência: com Checkout Pro, cada renovação de plano é uma **nova preferência/pagamento avulso**; renovação automática exige migrar para Assinaturas.

### 6.2 O que existe em Assinaturas

- Visão geral: "Meios de pagamento: **Dinheiro em conta, Pix, cartão de crédito ou débito, Línea de crédito, boleto**"; países AR, BR, CL, CO, MX, PE, UY; frequência "semanal, mensal ou anual"; "Tentativas automáticas se uma cobrança for recusada"; período de teste; "Opção de pagar sem conta do Mercado Pago". Fluxo: "O comprador acessa o link da assinatura... Após concluir o pagamento, o cliente passa a estar inscrito na assinatura e será cobrado de acordo com a periodicidade definida." [https://www.mercadopago.com.br/developers/pt/docs/subscriptions/overview]
- Dois modelos: **com plano associado** (`POST /preapproval_plan` → `id` = `preapproval_plan_id` → `POST /preapproval`) e **sem plano associado** (`POST /preapproval` direto), este último com **pagamento autorizado** (`status: "authorized"` + `card_token_id`) ou **pagamento pendente** (`status: "pending"`, comprador escolhe o meio no checkout do link). [https://www.mercadopago.com.br/developers/pt/docs/subscriptions/integration-configuration/subscription-associated-plan.md], [https://www.mercadopago.com.br/developers/pt/docs/subscriptions/integration-configuration/subscription-no-associated-plan/introduction.md], [https://www.mercadopago.com.br/developers/pt/docs/subscriptions/integration-configuration/subscription-no-associated-plan/authorized-payments.md], [https://www.mercadopago.com.br/developers/pt/docs/subscriptions/integration-configuration/subscription-no-associated-plan/pending-payments.md]
- "A subscription with an associated plan must always be created with your `card_token_id` and with the status `Authorized`." [https://www.mercadopago.com.br/developers/pt/docs/subscriptions/integration-configuration/subscription-associated-plan.md]

`POST https://api.mercadopago.com/preapproval_plan` (body): `reason` **OBRIGATÓRIO**; `auto_recurring.frequency` **OBRIGATÓRIO** (number), `auto_recurring.frequency_type` **OBRIGATÓRIO** (`days` | `months`), `auto_recurring.repetitions` ("assinatura limitada"), `auto_recurring.billing_day` ("Apenas aceita valores entre 1 e 28"), `auto_recurring.billing_day_proportional` (boolean; ciclos "sempre calculados com base em 30 dias"), `auto_recurring.free_trial.frequency|frequency_type`, `auto_recurring.transaction_amount`, `auto_recurring.currency_id` **OBRIGATÓRIO** (`BRL`), `payment_methods_allowed.payment_types[].id`, `payment_methods_allowed.payment_methods[].id`, `back_url` **OBRIGATÓRIO**. Resposta: `id`, `init_point` ("URL de checkout para adicionar um meio de pagamento"), `status` (`active` | `canceled`), `external_reference`, `date_created`. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/create-preapproval-plan/post]

`POST https://api.mercadopago.com/preapproval` (body): `preapproval_plan_id` (opcional), `reason` (obrigatório só sem plano), `external_reference` (obrigatório só sem plano), `payer_email` **OBRIGATÓRIO** ("validamos se o e-mail fornecido corresponde ao utilizado pelo pagador. Se essa validação não for bem-sucedida, o pagamento será recusado"), `card_token_id` (marcado OBRIGATÓRIO no schema; exigido para `authorized`), `auto_recurring.frequency|frequency_type` **OBRIGATÓRIOS**, `auto_recurring.start_date` ("somente funciona em conjunto com `end_date`"), `auto_recurring.end_date`, `auto_recurring.transaction_amount`, `auto_recurring.currency_id` **OBRIGATÓRIO**, `back_url` **OBRIGATÓRIO** (só sem plano), `status` (`pending` "Assinatura sem método de pagamento" | `authorized` "Assinatura com método de pagamento"). Resposta: `id`, `init_point` ("URL para o checkout para adicionar ou modificar um meio de pagamento"), `status`, `payer_id`, `card_id`, `payment_method_id`, `next_payment_date`, `auto_recurring.*`. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/create-preapproval/post]

- Gestão: `PUT /preapproval/{id}` com `status: canceled` ou `paused`; alterar valor via `auto_recurring.transaction_amount`; trocar cartão via `card_token_id`; `GET /preapproval/search`. [https://www.mercadopago.com.br/developers/pt/docs/subscriptions/subscription-management.md]
- Retentativas (assinatura com pagamento autorizado): parcela recusada fica `recycling` e "enters a reattempt scheme with a maximum of 4 possibilities"; "By default, the reattempt is within a 10-day window." [https://www.mercadopago.com.br/developers/pt/docs/subscriptions/integration-configuration/subscription-no-associated-plan/authorized-payments.md]
- Webhooks de assinatura: `subscription_preapproval`, `subscription_preapproval_plan`, `subscription_authorized_payment` (+ `payment`). [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-content/notifications/introduction.md]

### 6.3 Pontos não confirmados sobre recorrência

- Quais meios efetivamente recorrem **automaticamente** no Brasil (Pix e boleto exigem ação do pagador a cada ciclo? débito automático via conta MP?): a visão geral lista todos os meios, mas a mecânica por meio: **NÃO CONFIRMADO**.
- Se `payment_methods_allowed` aceita `pix`/`ticket` em plano: **NÃO CONFIRMADO** (exemplo oficial usa apenas `visa`).
- Se a configuração de Webhooks via painel está indisponível para Assinaturas (mencionado na página geral): **verificar**.

---

## 7. Reembolsos e cancelamentos

- **Reembolso**: `POST https://api.mercadopago.com/v1/payments/{id}/refunds`. Headers: `Authorization` **OBRIGATÓRIO**, `X-Idempotency-Key` **OBRIGATÓRIO**. Body: `amount` (number) — "Valor do reembolso. Se a propriedade (amount) for removida do body, criará um reembolso integral." Resposta: `id`, `payment_id`, `amount`, `status` (`approved`, `in_process`, `rejected`, `cancelled`, `authorized`), `refund_mode`, `source[]`, `date_created`. Erros: `2063 The action requested is not valid for the current payment state`, `2024`/`15016 Payment too old to be refunded`, `4292 Header X-Idempotency-Key can't be null`, `3024 Partial refund unsupported for this transaction`. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-refund/post]
    - Regras do guia: reembolso total (body vazio) ou parcial; prazo de **180 dias** após a aprovação; exige **saldo** na conta; cartão → estorno na fatura, Pix e outros → conta do pagador. [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/refunds-and-cancellations.md]
    - Listar/consultar: `GET /v1/payments/{id}/refunds`, `GET /v1/payments/{id}/refunds/{refund_id}`. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/overview] (referência lista "Obter lista de reembolsos"/"Obter reembolso específico")
- **Cancelamento**: `PUT https://api.mercadopago.com/v1/payments/{payment_id}` com body `{"status": "cancelled"}` — "desde que o status do pagamento seja "in_process", "pending" ou "authorized". Em caso de sucesso, a requisição retornará um código de status 200." Campo `status` **OBRIGATÓRIO**: "Este campo aceita exclusivamente o status "cancelled"." Erro `2018` se o status inicial não for um dos três. Exemplo de resposta: `"status": "cancelled", "status_detail": "by_collector", "payment_method_id": "bolbradesco", "payment_type_id": "ticket"`. [https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-cancellation/put]
    - Guia: "Payments automatically expire after 30 days without confirmation. The final status will be `canceled` or `expired`". [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/refunds-and-cancellations.md]

---

## 8. SDK oficial PHP `mercadopago/dx-php` 3.x

- Repositório: [https://github.com/mercadopago/sdk-php]. Versão estável mais recente: **3.16.0** (2026-08-27), `"php": ">=8.2"`, sem dependências de produção, namespace `MercadoPago\` → `src/MercadoPago`. [https://raw.githubusercontent.com/mercadopago/sdk-php/master/composer.json], [https://repo.packagist.org/p2/mercadopago/dx-php.json], [https://github.com/mercadopago/sdk-php/releases]
- Instalação (README): `composer require "mercadopago/dx-php:3.16.0"`; guia do Checkout Pro: `php composer.phar require "mercadopago/dx-php"`. [https://raw.githubusercontent.com/mercadopago/sdk-php/master/README.md], [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/configure-development-enviroment]
- Configuração global (`MercadoPago\MercadoPagoConfig`): `setAccessToken(string)` ("Bearer token in all API requests. Must be called before any API interaction"); `setRuntimeEnviroment(MercadoPagoConfig::LOCAL | MercadoPagoConfig::SERVER)` — **LOCAL desabilita a verificação SSL** (uso local), **SERVER** é o padrão com SSL; `BASE_URL = https://api.mercadopago.com`; `setConnectionTimeout(ms)` (padrão 20000), `setMaxConnections`, `max_retries` 3, `retry_delay` 500 ms; `setHttpClient()`. Não há chave de "sandbox" no SDK — o ambiente é dado pelas credenciais. [https://raw.githubusercontent.com/mercadopago/sdk-php/master/src/MercadoPago/MercadoPagoConfig.php], [https://raw.githubusercontent.com/mercadopago/sdk-php/master/README.md]
- Headers montados pelo cliente base (`MercadoPago\Client\MercadoPagoClient`): `Authorization: Bearer`, `Content-Type: application/json; charset=UTF-8`, `Accept`, `X-Product-Id`, `User-Agent: MercadoPago DX-PHP SDK/<versão>`, `X-Tracking-Id`; **`X-Idempotency-Key` é gerado automaticamente (UUID v4) para POST/PUT/PATCH** quando não fornecido em `RequestOptions` (checagem case-insensitive); token de `RequestOptions` prevalece sobre o global. [https://raw.githubusercontent.com/mercadopago/sdk-php/master/src/MercadoPago/Client/MercadoPagoClient.php]
- `MercadoPago\Client\Common\RequestOptions`: `setAccessToken(string)`, `setConnectionTimeout(int)`, `setCustomHeaders(array)` (ex.: `["X-Idempotency-Key: <SOME_UNIQUE_VALUE>"]`), além de `setMaxRetries`, `setRetryOn`, `setInitialDelayMs`, `setMaxDelayMs`, `setJitter`, `setOnRetry`. [https://raw.githubusercontent.com/mercadopago/sdk-php/master/src/MercadoPago/Client/Common/RequestOptions.php], [https://raw.githubusercontent.com/mercadopago/sdk-php/master/README.md]
- Clients e assinaturas:
    - `PreferenceClient::create(array $request, ?RequestOptions $o = null): Preference` (POST `/checkout/preferences`), `get(string $id)`, `update(string $id, array)`, `search(MPSearchRequest)`. Resource `Preference` expõe `id`, `init_point`, `sandbox_init_point`, `items`, `payer`, `back_urls`, `auto_return`, `notification_url`, `external_reference`, `expires`, `expiration_date_from/to`, `date_of_expiration`, `payment_methods`, `statement_descriptor`, `metadata`, `binary_mode`, `purpose`, `live_mode`, `site_id`... [https://raw.githubusercontent.com/mercadopago/sdk-php/master/src/MercadoPago/Client/Preference/PreferenceClient.php], [https://raw.githubusercontent.com/mercadopago/sdk-php/master/src/MercadoPago/Resources/Preference.php]
    - `PaymentClient::get(int $id, ?RequestOptions): Payment` (GET `/v1/payments/{id}`), `create(array)`, `update(int, array)`, `cancel(int)` (PUT com `status=cancelled`; docblock: "Only payments in "pending" status can be cancelled"), `capture(int, ?float)`, `search`, `searchAll`. [https://raw.githubusercontent.com/mercadopago/sdk-php/master/src/MercadoPago/Client/Payment/PaymentClient.php]
    - `MerchantOrderClient::get(int $id): MerchantOrder` (GET `/merchant_orders/{id}`), `create`, `update`, `search`. [https://raw.githubusercontent.com/mercadopago/sdk-php/master/src/MercadoPago/Client/MerchantOrder/MerchantOrderClient.php]
    - `PaymentRefundClient::refund(int $payment_id, float $amount)`, `refundTotal(int $payment_id)`, `get`, `list` (POST/GET `/v1/payments/{id}/refunds`). [https://raw.githubusercontent.com/mercadopago/sdk-php/master/src/MercadoPago/Client/Payment/PaymentRefundClient.php]
    - `PreApprovalClient::create/get/update/search/searchAll` (`/preapproval`). [https://raw.githubusercontent.com/mercadopago/sdk-php/master/src/MercadoPago/Client/PreApproval/PreApprovalClient.php]
    - `MercadoPago\Webhook\WebhookSignatureValidator::validate($xSignature, $xRequestId, $dataId, $secret, ?int $toleranceSeconds)` — HMAC-SHA256 + `hash_equals`; lança `InvalidWebhookSignatureException` com `SignatureFailureReason`. Adicionado na release **3.10.0** (2026-05-21); 3.11.2/3.11.3 alteraram o tratamento de caixa do `data.id`; 3.13.0 ajustou `toleranceSeconds`. [https://raw.githubusercontent.com/mercadopago/sdk-php/master/src/MercadoPago/Webhook/WebhookSignatureValidator.php], [https://github.com/mercadopago/sdk-php/releases]
- Erros: capturar `MercadoPago\Exceptions\MPApiException` → `$e->getApiResponse()->getStatusCode()` e `->getContent()`; 3.13.0 introduziu exceções tipadas por status (400/401/403/404/429/5xx). [https://raw.githubusercontent.com/mercadopago/sdk-php/master/README.md], [https://raw.githubusercontent.com/mercadopago/sdk-php/master/src/MercadoPago/Net/MPDefaultHttpClient.php]
- Exemplo oficial (README) de criação de preferência para Checkout Pro (trecho):

```php
$request = [
    "items" => $items,                       // id, title, description, currency_id => "BRL", quantity, unit_price
    "payer" => $payer,                       // name, surname, email
    "payment_methods" => ["excluded_payment_methods" => [], "installments" => 12, "default_installments" => 1],
    "back_urls" => ['success' => route('mercadopago.success'), 'failure' => route('mercadopago.failed')],
    "statement_descriptor" => "NAME_DISPLAYED_IN_USER_BILLING",
    "external_reference" => "1234567890",
    "expires" => false,
    "auto_return" => 'approved',
];
$client = new PreferenceClient();
try { $preference = $client->create($request); } catch (MPApiException $error) { return null; }
```

[https://raw.githubusercontent.com/mercadopago/sdk-php/master/README.md]

- Exemplo oficial (guia) de `notification_url` via SDK: `$client->create(["notification_url" => "https://www.your_url_to_notification.com/", "items" => [["title" => "Mi producto", "quantity" => 1, "unit_price" => 2000]]]);` [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/payment-notifications]
- Inicialização (guia): `MercadoPagoConfig::setAccessToken("TEST_ACCESS_TOKEN");` [https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/configure-development-enviroment]
- Nota: os exemplos "sem SDK" antigos nas páginas de notificações (`MercadoPago\SDK::setAccessToken`, `MercadoPago\Payment::find_by_id`) referem-se ao SDK **2.x** e não existem no 3.x.

---

## 9. Notas fiscais / NFS-e

- Nos índices oficiais da documentação de desenvolvedores (`docs/llms.txt` e `reference/llms.txt`) não há nenhuma página sobre "nota fiscal", "NF-e", "NFS-e" ou API fiscal; a única ocorrência de "invoice" é a descrição de fatura do cartão (`statement_descriptor`) e as "Faturas" de assinatura (`authorized_payments`). [https://www.mercadopago.com.br/developers/pt/docs/llms.txt], [https://www.mercadopago.com.br/developers/pt/reference/llms.txt]
- O blog do Mercado Pago (não é documentação de desenvolvedor) afirma: "O Sistema de Gestão do Mercado Pago conecta as vendas realizadas nas soluções de pagamento do Mercado Pago diretamente à emissão de **NF-e e NFC-e**, sem necessidade de integrar softwares de terceiros" e, sobre NFS-e: "Já para a NFS-e, o cadastro é feito na prefeitura do município." [https://www.mercadopago.com.br/blog/integracao-nota-fiscal-eletronica-sistema-pagamento]
- Conclusão: **não existe API pública documentada do Mercado Pago para emissão de NFS-e** dos serviços do próprio vendedor. **NÃO CONFIRMADO** se o "Sistema de Gestão" emite NFS-e (o blog cita apenas NF-e/NFC-e) ou se expõe API. A API fiscal existente é do **Mercado Livre** (para vendas no marketplace), fora do escopo. Recomendação: integrar um emissor de NFS-e terceiro (ou o Portal Nacional NFS-e) disparado pelo evento interno "pagamento aprovado".

---

## 10. Suposições e pendências (tudo que a implementação deve externalizar em configuração)

Configuração (`.env` / `config/mercadopago.php`):

- `MP_ACCESS_TOKEN` (teste e produção; ambos com prefixo `APP_USR` — não usar prefixo para detectar ambiente), `MP_PUBLIC_KEY` (só se houver frontend SDK), `MP_WEBHOOK_SECRET` (chave secreta de Webhooks do painel, por ambiente), `MP_NOTIFICATION_URL` (HTTPS, ≤ 248 chars, pública, sem localhost), `MP_BACK_URL_SUCCESS|PENDING|FAILURE` (HTTPS, sem localhost), `MP_AUTO_RETURN` (`approved` | vazio), `MP_STATEMENT_DESCRIPTOR` (≤ 13 chars), `MP_BINARY_MODE` (bool; default false), `MP_RUNTIME_ENV` (`SERVER` em produção; `LOCAL` só em dev), `MP_HTTP_TIMEOUT_MS`, `MP_WEBHOOK_TOLERANCE_SECONDS` (se usar `toleranceSeconds`).
- Planos/preços: catálogo de planos (id interno, `title`, `unit_price` em BRL com 2 casas, `quantity` 1, `description`, `category_id`), duração do ciclo (dias) e regra de prorrogação/renovação — Checkout Pro não renova sozinho.
- Meios habilitados: lista `excluded_payment_types` / `excluded_payment_methods` (ex.: excluir `ticket` para evitar boletos pendentes), `installments` máximo e `default_installments`; lembrar que `account_money` não pode ser excluído.
- Expiração: `expires` + `expiration_date_from/to` da preferência (ex.: 24–72 h) e `date_of_expiration` para Pix/boleto (≥ 3 dias recomendado).
- `external_reference`: formato ≤ 64 chars `[A-Za-z0-9_-]` (ex.: `sub_<tenant>_<orderUuid>`); `metadata` com `tenant_id`, `plan_id`, `order_id`.
- Credenciais de teste: conta vendedor de teste (Access Token de teste), contas compradoras de teste (usuário/senha/código 6 dígitos), cartões de teste e nomes `APRO`/`CONT`/`OTHE`.
- Webhooks: URL de teste e de produção no painel (evento Pagamentos + Ordens comerciais + Contestações), ou `notification_url` por preferência (prioridade); resposta 200 em < 22 s; processamento assíncrono e idempotente; auditoria de `x-request-id`.
- Tolerância a duplicidade: mesma `external_reference` pode ter vários `payments` (via `merchant_order`); regra de negócio para pagamento aprovado após expiração do pedido interno (reembolsar? ativar?).
- Reembolso/cancelamento: política (prazo 180 dias, saldo em conta) e quem pode acionar; idempotência com `X-Idempotency-Key` próprio por operação.

Pendências marcadas como **NÃO CONFIRMADO**:

1. Existência atual do prefixo `TEST-` em tokens (doc atual só menciona `APP_USR`).
2. Conciliação entre "Test credentials are only available for Checkout Transparente and Checkout Bricks" e "credenciais da conta de teste vendedor = credenciais de teste" para Checkout Pro — verificar no painel.
3. Semântica completa de `X-Idempotency-Key` (chave repetida com payload diferente; janela de retenção); obrigatoriedade em `/checkout/preferences` (não listada).
4. Regra "Pix só aparece com Chave Pix cadastrada" na doc principal do Checkout Pro (só em docs de plugins); disponibilidade de Linha de Crédito por conta.
5. Presença garantida de `order.id` em todo `GET /v1/payments/{id}` de Checkout Pro.
6. Tratamento de caixa do `data.id` alfanumérico na assinatura (doc: minúsculas; SDK ≥ 3.11.3: preserva).
7. Configuração de Webhooks via painel indisponível para Assinaturas/QR (mencionado no resumo da página geral — confirmar).
8. Mecânica de recorrência por meio de pagamento (Pix/boleto/conta) em Assinaturas e aceitação de `pix`/`ticket` em `payment_methods_allowed`.
9. Qualquer API do Mercado Pago para NFS-e (inexistente na doc; Sistema de Gestão só cita NF-e/NFC-e).
10. Datas exatas da notícia de idempotência (a URL indica 2023/01/04; o conteúdo cita 2024) — irrelevante para a implementação, mas não conciliado.

---

## Fontes (índice de URLs citadas)

- Credenciais: https://www.mercadopago.com.br/developers/pt/docs/your-integrations/credentials
- Criar aplicação / prefixo APP_USR: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/create-application
- Contas de teste (geral): https://www.mercadopago.com.br/developers/pt/docs/your-integrations/test/accounts
- Contas de teste (Checkout Pro): https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/test-accounts
- Cartões de teste: https://www.mercadopago.com.br/developers/pt/docs/your-integrations/test/cards
- Compras de teste: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/integration-test/test-purchases
- Ir para produção: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/go-to-production
- Ambiente de desenvolvimento / SDK: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/configure-development-enviroment
- Visão geral Checkout Pro (meios de pagamento, recorrência): https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/overview
- Criar preferência (guia): https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/create-payment-preference
- URLs de retorno: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/configure-back-urls
- Notificações de pagamento (Checkout Pro): https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/payment-notifications
- Webhooks (geral): https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks
- Webhooks (Checkout Pro, .md): https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-content/notifications/webhooks.md
- Notificações — introdução/tópicos: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-content/notifications/introduction.md
- IPN: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-content/notifications/ipn.md
- Excluir meios de pagamento: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/payment-methods
- Vigência da preferência: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/term-of-preference
- Data de expiração (Pix/offline): https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/expiration-date
- Descrição na fatura (statement_descriptor): https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/invoice-description
- Modo binário: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/binary-mode
- Reembolsos e cancelamentos (guia): https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/refunds-and-cancellations
- Conta Mercado Pago como único meio (purpose): https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/additional-settings/mp-wallet
- Status de pagamento (tabela): https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/response-handling/query-results
- Pix (Checkout Transparente, chaves Pix): https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/integration-configuration/integrate-pix
- Referência — visão geral Checkout Pro: https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/overview
- Referência — Criar preferência: https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-preference/post
- Referência — Obter preferência: https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/get-preference/get
- Referência — Obter pagamento: https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/get-payment/get
- Referência — Obter pedido comercial: https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/merchant-orders/get-merchant-order/get
- Referência — Criar reembolso: https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-refund/post
- Referência — Criar cancelamento: https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-cancellation/put
- Referência — Obter meios de pagamento: https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/payment-methods/get
- Referência — Criar pagamento (X-Idempotency-Key): https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-payment/post
- Notícia idempotência: https://www.mercadopago.com.br/developers/en/news/2023/01/04/Idempotency-key-usage-will-be-mandatory
- Assinaturas — visão geral: https://www.mercadopago.com.br/developers/pt/docs/subscriptions/overview
- Assinaturas — com plano: https://www.mercadopago.com.br/developers/pt/docs/subscriptions/integration-configuration/subscription-associated-plan
- Assinaturas — sem plano (intro/autorizado/pendente): https://www.mercadopago.com.br/developers/pt/docs/subscriptions/integration-configuration/subscription-no-associated-plan/introduction , .../authorized-payments , .../pending-payments
- Assinaturas — gestão: https://www.mercadopago.com.br/developers/pt/docs/subscriptions/subscription-management
- Referência — Criar assinatura: https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/create-preapproval/post
- Referência — Criar plano: https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/create-preapproval-plan/post
- SDK PHP: https://github.com/mercadopago/sdk-php ; README: https://raw.githubusercontent.com/mercadopago/sdk-php/master/README.md ; releases: https://github.com/mercadopago/sdk-php/releases ; Packagist: https://repo.packagist.org/p2/mercadopago/dx-php.json
- SDK fontes: .../src/MercadoPago/MercadoPagoConfig.php , .../Client/MercadoPagoClient.php , .../Client/Common/RequestOptions.php , .../Client/Preference/PreferenceClient.php , .../Client/Payment/PaymentClient.php , .../Client/Payment/PaymentRefundClient.php , .../Client/MerchantOrder/MerchantOrderClient.php , .../Client/PreApproval/PreApprovalClient.php , .../Resources/Preference.php , .../Webhook/WebhookSignatureValidator.php (prefixo https://raw.githubusercontent.com/mercadopago/sdk-php/master/)
- Índices llms.txt: https://www.mercadopago.com.br/developers/pt/docs/llms.txt ; https://www.mercadopago.com.br/developers/pt/reference/llms.txt
- Blog NF-e (não é doc de desenvolvedor): https://www.mercadopago.com.br/blog/integracao-nota-fiscal-eletronica-sistema-pagamento
