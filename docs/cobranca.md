# Cobrança — Checkout Pro, webhook e consumo do plano

Incremento 5. Este documento descreve o que **está implementado**, o que fica atrás de
configuração e o que **não** existe. Toda afirmação sobre o provedor vem de
[`docs/integracoes/mercado-pago.md`](integracoes/mercado-pago.md), que é uma pesquisa da
documentação oficial com as fontes citadas; o que lá está marcado **NÃO CONFIRMADO**
aparece aqui com a mesma marcação, nunca como fato.

Decisões de produto: `docs/design/RECONCILIACAO.md` §4 (Q20 pagamento avulso por ciclo,
Q21 sem cartão salvo e sem nota fiscal) e `docs/design/ROUTES_AND_PAGES.md` §2.15/§2.16.

---

## 1. O modelo de cobrança em uma frase

Cada ciclo é um **pagamento avulso** feito no Checkout Pro do Mercado Pago. Não há
cobrança recorrente, não há cartão salvo e não há nota fiscal.

Isso não é uma simplificação nossa: a tabela comparativa oficial do Checkout Pro marca
"Pagamentos recorrentes" como **indisponível** para esse produto — recorrência é o
produto **Assinaturas** (`/preapproval`), com checkout próprio e exigência de
`card_token_id`. Enquanto estivermos no Checkout Pro, cada renovação é uma preferência
nova e um pagamento novo.

---

## 2. Fluxo do checkout

```
usuário escolhe o plano
        │
        ▼
POST billing.checkout ──▶ StartCheckout
        │                     │
        │                     ├─ cria (ou reaproveita) Payment local: status `pending`,
        │                     │  external_reference = ULID nosso, amount_cents inteiro,
        │                     │  currency 'BRL', environment = sandbox|production
        │                     │
        │                     └─ POST /checkout/preferences ──▶ init_point
        │
        ▼
Inertia::location(init_point) ──▶ o comprador paga DENTRO do Mercado Pago
        │
        ├──▶ back_url (navegador) ──▶ billing.return ──▶ "estamos confirmando seu pagamento"
        │                                                 (NÃO ativa nada)
        │
        └──▶ webhook (servidor) ──▶ MercadoPagoController
                                        │ valida assinatura → 401 se falhar
                                        │ grava PaymentWebhookReceipt (fingerprint único)
                                        │ responde 200 em milissegundos
                                        ▼
                                   SyncMercadoPagoPayment (fila `billing`)
                                        │ GET /v1/payments/{id}  ← FONTE DA VERDADE
                                        ▼
                                   ActivateSubscription (uma vez por pagamento)
```

Detalhes que importam:

- **`external_reference`** é um ULID de 26 caracteres — dentro do limite documentado de
  64 e do alfabeto aceito (letras, números, hífen e sublinhado). É a única chave usada
  para reconciliar o pagamento do provedor com o nosso.
- **`init_point`, nunca `sandbox_init_point`.** A própria referência da API diz "Não
  utilize este parâmetro. Para testes de integração, utilize `init_point`".
- **Valores em centavos inteiros** do nosso lado, convertidos para unidades monetárias só
  na borda (`unit_price`), com `currency_id` sempre explícito.
- **Reaproveitamento**: um `Payment` `pending` do mesmo plano criado dentro de
  `MERCADOPAGO_PREFERENCE_TTL_HOURS` é reutilizado. Dois cliques no botão levam ao mesmo
  checkout, não a duas cobranças.
- **`statement_descriptor`** é cortado em 13 caracteres (limite documentado).
- **`metadata`** carrega só identificadores públicos (ULID da organização, ULID do
  pagamento, código do plano, ambiente) — nenhum dado pessoal.

---

## 3. Por que o retorno do checkout não ativa o plano

`billing.return` mostra "estamos confirmando seu pagamento" e **nunca** muda estado. Três
razões, todas na documentação:

1. A `back_url` é um **GET disparado pelo navegador do comprador**, com `payment_id`,
   `status` e `collection_status` na query string. Qualquer pessoa pode digitar essa URL.
   Nenhum desses parâmetros é lido pelo nosso controller.
2. O retorno **pode nunca acontecer**: o comprador fecha a aba, perde a conexão, o
   `auto_return` de até 40 segundos não dispara.
3. Para meios offline (boleto, Pix), o retorno é sempre `pending` — inclusive quando o
   pagamento é aprovado minutos depois. O próprio guia recomenda configurar as
   notificações "para que seu servidor receba essas atualizações".

A ativação depende de duas coisas em sequência: **webhook com assinatura válida** e
**`GET /v1/payments/{id}` respondendo `approved`**. O teste
`tests/Feature/Billing/CheckoutTest.php` ("o retorno do checkout, sozinho, não ativa plano
nenhum") entrega o retorno com os parâmetros do provedor e verifica que nada muda.

---

## 4. Validação do webhook, passo a passo

Rota: `POST /webhooks/mercadopago` — sem CSRF (exceção em `bootstrap/app.php`), sem
sessão, com `throttle:webhook`.

1. **Ler o `data.id` da query string bruta.** O provedor manda `?data.id=123&type=payment`;
   o PHP transformaria `data.id` em `data_id` ao montar `$_GET`, e o manifesto do HMAC
   precisa do valor exato. Por isso o controller lê `QUERY_STRING` diretamente.
2. **Montar o manifesto**: `id:<data.id>;request-id:<x-request-id>;ts:<ts>;` — cada par
   cujo valor esteja ausente é removido.
3. **HMAC-SHA256** hexadecimal, com a chave secreta do painel como chave e o manifesto
   como mensagem.
4. **Comparar em tempo constante** (`hash_equals`) com o `v1` do cabeçalho `x-signature`.
5. **Conferir a janela de tempo**: `|agora − ts| ≤ MERCADOPAGO_WEBHOOK_TOLERANCE_SECONDS`
   (300 s por padrão).

Os passos 2–4 são feitos pelo validador do **SDK oficial**
(`MercadoPago\Webhook\WebhookSignatureValidator`, função pura, sem rede) — usar o código
do próprio provedor elimina a chance de o nosso manifesto divergir do dele.

> **Discrepância do SDK (relevante).** O validador aceita um `toleranceSeconds`, mas
> converte o carimbo com `(int) $ts * 1000`, ou seja, tratando o `ts` do cabeçalho como
> **segundos**. O exemplo oficial da documentação traz `ts=1742505638683` — treze
> dígitos, portanto milissegundos. Passar `toleranceSeconds` ao SDK faria **toda**
> notificação real ser recusada por deriva. Por isso chamamos o validador sem tolerância
> (ele cuida do HMAC) e medimos a janela em `MercadoPagoSignature`, detectando a unidade
> pelo tamanho do número (≥ 13 dígitos = milissegundos).

> **Caixa do `data.id` — NÃO CONFIRMADO.** A documentação manda passar o id para
> minúsculas antes do HMAC; o SDK 3.16 preserva a caixa original (mudança da 3.11.3).
> Para o tópico `payment` o id é numérico e a questão não existe. Tentamos primeiro com a
> caixa original e, se o hash não bater e o id tiver maiúsculas, repetimos com o id em
> minúsculas, registrando `mercadopago.webhook.lowercased_data_id` quando a segunda
> tentativa é que vale.

### O que acontece em cada desfecho

| Situação                                       | Resposta               | Efeito                              |
| ---------------------------------------------- | ---------------------- | ----------------------------------- |
| Assinatura válida, tópico `payment`            | 200                    | recibo gravado + job despachado     |
| Assinatura válida, evento repetido             | 200 `{duplicate:true}` | nada (idempotência)                 |
| Assinatura válida, outro tópico                | 200 `{ignored:…}`      | recibo `ignored`, sem job           |
| Assinatura inválida / ausente / fora da janela | **401**                | **nada**: nem recibo, nem job       |
| Sem `MERCADOPAGO_WEBHOOK_SECRET` configurado   | **401**                | nada — sem validar, não se processa |

O corpo do 401 é genérico (`{"error":"invalid_signature"}`); o motivo da recusa vai para
o log, com o `x-request-id` para correlação com o painel do provedor.

**Assinaturas inválidas não geram linha em `payment_webhook_receipts`** — de propósito.
Um recibo por tentativa não autenticada só encheria a tabela e daria a um atacante uma
forma barata de escrever no nosso banco. A recusa fica no log.

---

## 5. Idempotência e ordem dos eventos

### Idempotência do evento

`payment_webhook_receipts` tem `UNIQUE(provider, event_fingerprint)`, e o fingerprint é
`type:data.id:action`. A reentrega (o provedor repete em 0, 15 e 30 minutos, 6 h, 48 h e
96 h até receber 200) reencontra a linha. O que a reentrega faz depende do estado do recibo:

| estado do recibo | reentrega                                                            |
| ---------------- | -------------------------------------------------------------------- |
| `processed`      | não produz trabalho — o desfecho já aconteceu                        |
| `ignored`        | não produz trabalho — o tópico não é tratado                         |
| `failed`         | **reprocessa** — nova chance                                         |
| `received`       | **reprocessa** — o desfecho nunca chegou, então o trabalho se perdeu |

O último caso é o que impede um aviso de aprovação de sumir para sempre. `received` significa
"gravamos o aviso e ainda não chegamos a um desfecho"; tratá-lo como "já em processamento"
fazia toda reentrega responder `200 {"duplicate": true}` sem produzir nada quando o job se
perdia — worker levado por OOM/SIGKILL entre a retirada da fila e o `handle()`, `queue:flush`,
Redis reiniciado sem persistência. O pagamento local ficava `pending`, `ActivateSubscription`
nunca rodava, o cliente pagava e continuava sem plano, e **nada alertava**, porque o recibo não
estava `failed`, estava `received`. Reprocessar é barato (uma consulta `GET /v1/payments/{id}`)
e seguro (a aplicação é idempotente por `payments.activated_at` sob lock); perder o aviso não é
nem barato nem seguro. Um recibo que passa de `WebhookReceipts::STALE_RECEIVED_SECONDS` (5 min)
ainda em `received` também vira `warning` com `alert = billing_webhook_receipt_stuck`.

**`SyncMercadoPagoPayment` não é `ShouldBeUnique`.** Ele já foi, e o preço era exatamente esse
sumiço: `PendingDispatch::shouldDispatch()` descarta a mensagem **em silêncio** quando o lock
de unicidade está tomado, e `payment.created` seguido de `payment.updated` do mesmo pagamento
(o cenário para o qual a unicidade existia) produzia dois recibos e um único job. A
serialização continua existindo, mas onde ela não descarta nada: um lock de cache tomado
**dentro** do `handle()`; sem o lock, o job volta para a fila em vez de sumir.

### Idempotência da ativação

A marca é **`payments.activated_at`**. `ActivateSubscription` abre uma transação, relê o
pagamento com `lockForUpdate()` e sai sem fazer nada se `activated_at` já estiver
preenchido. Isso cobre reentrega, `payment.created` + `payment.updated` do mesmo
pagamento, duas filas simultâneas e instâncias de modelo desatualizadas. A assinatura
também é relida sob lock, então ativações concorrentes na mesma organização se
serializam.

### Ordem dos eventos

O provedor não garante ordem. Uma retentativa antiga pode chegar depois de uma nova. A
regra é um **posto de finalidade** (`PaymentStatusTransition`): um estado só é gravado
quando o posto do que chega é ≥ o do estado atual.

| posto | estados                                    | leitura                              |
| ----- | ------------------------------------------ | ------------------------------------ |
| 0     | `pending`, `in_process`                    | em curso                             |
| 1     | `authorized`, `rejected`, `cancelled`      | desfecho antes do crédito            |
| 2     | `approved`                                 | creditado                            |
| 3     | `in_mediation`, `refunded`, `charged_back` | só acontece **depois** de um crédito |

- `approved` nunca é rebaixado para `pending` nem para `rejected`/`cancelled`. A
  documentação confirma que cancelar só é possível em `pending`, `in_process` ou
  `authorized`, então um `cancelled` posterior a um `approved` é sempre evento fora de
  ordem.
- `refunded` e `charged_back` **passam**: são transições legítimas posteriores.
- O mesmo estado repetido passa (a gravação é idempotente).

### Três conferências antes de qualquer ativação

1. **É nosso?** casamento por `external_reference`, com o id do provedor como rede de
   segurança. Sem correspondência → `payment_not_found`, ignorado.
2. **Mesmo ambiente?** `live_mode` da resposta tem que corresponder a
   `payments.environment`. Sandbox e produção nunca se misturam →
   `environment_mismatch`.
3. **Mesmo valor e moeda?** divergência não ativa nada, marca o recibo como `failed` e
   loga com `alert=billing_amount_mismatch` para inspeção humana.

---

## 6. Unidade de consumo do plano

A unidade é o **envelope enviado** (`ASSINAVELOX_PLAN_CONSUMPTION_UNIT=envelope_sent`).
O ledger é `plan_consumptions`, com `idempotency_key` UNIQUE = `envelope:{id}:send`.

```
reserved ──commit()──▶ committed        (envio concluído: convites despachados)
   │                       │
   └──────release()────────┴──▶ released (falha no despacho, ou cancelamento antes
                                          de qualquer assinatura)
```

- **Reserva** — dentro da transação do envio, com a assinatura bloqueada
  (`SendEnvelope`). Se dois cliques chegarem juntos, a coluna UNIQUE garante uma linha só.
- **Confirmação** — a reserva vira consumo assim que os convites são despachados, ainda
  no envio. O pipeline de finalização chama `PlanLedger::commit()` de novo ao concluir o
  envelope; a chamada é **idempotente** e serve de rede de segurança para um envio que
  tenha ficado com a reserva em aberto. Nada é cobrado duas vezes.
- **Liberação** — falha no despacho ou cancelamento antes de qualquer assinatura devolve
  a cota.

Os contadores desnormalizados (`subscriptions.envelopes_used` / `envelopes_reserved`) são
ajustados sob lock da linha da assinatura. A tela de cobrança mostra **usado + reservado**
como consumo do ciclo: é o total que o ledger já descontou do plano.

### Bloqueio de envio

`PlanLedger::assertCanSend()` recusa o envio quando:

| Motivo                        | `errorCode`             | Mensagem ao usuário                                                                                                     |
| ----------------------------- | ----------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Sem assinatura vigente        | `no_subscription`       | "Esta conta não tem um plano ativo. Escolha um plano para enviar documentos."                                           |
| Assinatura inadimplente       | `subscription_past_due` | "O pagamento do plano está em atraso, então novos envios estão bloqueados. Regularize a cobrança para voltar a enviar." |
| Assinatura cancelada/expirada | `subscription_inactive` | "O plano desta conta está … e não permite novos envios."                                                                |
| Cota esgotada                 | `quota_exhausted`       | "Você já usou os N documentos do seu plano neste período. Faça upgrade para continuar enviando."                        |

**O bloqueio é só de envio.** Continuam liberados: listar e abrir documentos, acompanhar
os que estão em andamento, baixar os concluídos, a página de evidências e a verificação
pública. O cliente nunca perde acesso ao que já é dele.

Na interface, a tela de cobrança e a de planos mostram o banner correspondente com botão
"Ver planos" / "Pagar agora" (`resources/js/pages/settings/billing.tsx`). O flash de erro
do envio carrega apenas texto — o Inertia entrega `flash.error` como string, sem suporte a
link. Um link clicável dentro do próprio toast exigiria mudar o contrato do flash e do
`FlashToaster`, o que está fora desta entrega; o caminho para os planos aparece nos
banners da tela de cobrança e no menu de configurações.

---

## 7. Inadimplência (RECONCILIACAO Q20)

```
active ──(fim do ciclo + 3 dias sem pagamento)──▶ past_due ──(+15 dias)──▶ expired
                                                     │                        │
                                            bloqueia ENVIO          volta ao plano Grátis
                                        (leitura e download seguem)   (ciclo novo)
```

Comando agendado: `php artisan billing:dunning`, todo dia às 03:20
(`routes/console.php`), `withoutOverlapping` e `onOneServer`. A regra vive em
`App\Services\Billing\SubscriptionLifecycle` e é **idempotente**: rodar duas vezes no
mesmo dia não muda nada duas vezes, e cada transição usa `where(status = <esperado>)` para
não atropelar uma ativação que aconteceu no meio do caminho.

Planos gratuitos nunca ficam inadimplentes (`price_cents = 0` é filtrado) — mas **o ciclo
deles é renovado** pelo mesmo comando. Uma assinatura de plano sem preço nunca gera pagamento,
logo nunca passa por `ActivateSubscription`, que é quem zera o consumo do ciclo; sem um passo
próprio, a cota anunciada como mensal ("5 documentos/mês" no card de plano, no fallback de
criação da organização e na RECONCILIACAO §4 Q8) seria uma cota **vitalícia** de 5 documentos,
com a organização bloqueada por "cota esgotada" para sempre depois do quinto envelope.

`SubscriptionLifecycle::renewFreeCycles()` fecha isso: para assinaturas `active` de plano com
`price_cents = 0` cujo `current_period_end` já passou, avança o período de ciclo em ciclo até
alcançar o corrente (uma organização parada por meses não ganha um ciclo por execução), zera
`envelopes_used` e **reconta** `envelopes_reserved` a partir das reservas ainda abertas no
ledger — a mesma regra de `ActivateSubscription::applyCycle()`; zerar às cegas perderia
envelopes em trânsito. A atualização exige `status = active` e o mesmo `current_period_end` que
foi lido, então é idempotente e não atropela uma ativação simultânea. Cada renovação grava
`subscription.renewed` na trilha.

Ao expirar, a assinatura antiga fica `expired` e uma **nova assinatura no plano Grátis**
é criada e ativada, com ciclo começando naquele momento. Sem isso a organização ficaria
sem assinatura vigente e nem conseguiria ler o próprio plano.

**Cancelar a renovação** (`billing.cancel`, exige papel `owner` e confirmação de senha)
marca `cancel_at_period_end` e não corta nada: o plano vale até o fim do ciclo já pago, e
a volta ao Grátis acontece pela expiração. `billing.resume` desfaz a marca. Um pagamento
aprovado também desfaz (pagar é retomar).

---

## 8. Ambiente: sandbox × produção

Não existe "modo sandbox" por host no Mercado Pago — **o ambiente é determinado pelas
credenciais**. Do nosso lado, `MERCADOPAGO_ENVIRONMENT` (`sandbox` | `production`) é
gravado em `payments.environment` e **conferido** contra o `live_mode` que a API devolve.
Um pagamento de produção chegando num pagamento marcado como sandbox (ou o contrário) é
ignorado, com log — sandbox e produção nunca se misturam.

`MERCADOPAGO_DRIVER`:

| valor           | comportamento                                                                                                                                     |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `auto` (padrão) | Mercado Pago quando há `MERCADOPAGO_ACCESS_TOKEN`; **gateway fake** quando não há                                                                 |
| `fake`          | força o dublê (desenvolvimento e testes)                                                                                                          |
| `mercadopago`   | força o real — sem credencial ele responde `isConfigured() = false` e o checkout diz claramente que está desabilitado, sem chamar endpoint nenhum |

O **gateway fake** se identifica como falso em toda superfície: `name()` devolve `fake`
(então `payments.provider` grava `fake` e nenhum pagamento de dublê se confunde com um
real), `isFake()` devolve `true`, `environment()` é sempre `sandbox`, `liveMode` é sempre
`false` e o `init_point` aponta para `https://checkout-falso.assinavelox.invalid/…` —
domínio reservado pela RFC 2606, que nunca resolve. Ele não faz nenhuma chamada de rede e
só "conhece" pagamentos programados explicitamente: nunca inventa um pagamento aprovado.

### Testar de ponta a ponta com credenciais de teste

1. Crie a aplicação no painel do Mercado Pago e pegue o Access Token da conta de teste
   vendedor (`MERCADOPAGO_ACCESS_TOKEN`; começa com `APP_USR` tanto em teste quanto em
   produção — **não** use o prefixo para detectar ambiente).
2. Configure o webhook em _Suas integrações > Webhooks_ (aba **Modo de teste**), evento
   **Pagamentos**, e copie a chave secreta gerada para `MERCADOPAGO_WEBHOOK_SECRET`.
3. `MERCADOPAGO_NOTIFICATION_URL` precisa ser **HTTPS pública**, no máximo 248
   caracteres, sem `localhost` nem `127.0.0.1`. Em desenvolvimento, use um túnel.
4. Pague com uma conta compradora de teste e um cartão de teste (o nome do titular
   controla o resultado: `APRO` aprova, `CONT` deixa pendente, `OTHE` recusa).
5. **Atenção:** "Os pagamentos de teste, criados com credenciais de teste, não enviarão
   notificações. A única maneira de testar a recepção de notificações é através da
   Configuração através de Suas integrações" — ou seja, use o **Simular** do painel para
   exercitar o webhook.

---

## 9. Recibo interno — não é nota fiscal

`GET billing.payments.receipt` gera um PDF pelo DOMPDF
(`resources/views/billing/receipt.blade.php`) com o aviso, no topo e no rodapé:

> Este recibo NÃO é documento fiscal e não substitui a nota fiscal de serviço (NFS-e).

Só existe para pagamento aprovado, é escopado pela organização corrente e não imprime
nada de cartão — porque não existe nada de cartão do nosso lado. Pagamento em ambiente
sandbox ou plano com preço fictício recebem um bloco extra dizendo isso.

Os dados de faturamento vêm de `billing.profile.update`: razão social em
`organizations.legal_name`, CPF/CNPJ em `organizations.tax_id` (coluna com cast
`encrypted`, nunca indexada) e endereço, cidade, UF, CEP e e-mail em
`organizations.settings.billing_profile`. Nenhuma migração nova foi necessária.

---

## 10. Nenhum dado de cartão, em lugar nenhum

No Checkout Pro o pagamento acontece inteiramente no ambiente do provedor. Ele **não** nos
devolve número de cartão, validade nem código de segurança, e **não há cartão salvo**
(RECONCILIACAO Q21). O que guardamos de um pagamento aprovado:

- `payment_method_id` (`pix`, `visa`, `bolbradesco`, `account_money`…), traduzido para
  texto por `PaymentMethods`;
- `payer_email_masked` (`c********@exemplo.com`);
- identificadores, valor em centavos, moeda e datas.

O `raw` das respostas passa por `MercadoPagoGateway::scrub()`, que remove `payer`, `card`,
`additional_info`, `charges_details` e `point_of_interaction` antes de qualquer uso. O
payload do webhook é gravado reduzido a `id`, `type`, `action`, `api_version`,
`live_mode`, `date_created`, `user_id` e `data.id`. O `signature_header` guardado é o MAC
(`ts=…,v1=…`), nunca a chave que o gerou.

`payment_method.last_four` na interface é **sempre `null`**: esse dado não existe do nosso
lado, e a tela não finge que existe.

`tests/Feature/Billing/NoCardDataTest.php` verifica isso em coluna, em JSON e em log.

---

## 11. Timeout, repetição e ambiguidade

O adaptador usa o HTTP Client do Laravel para as chamadas e o SDK oficial para a
assinatura do webhook. A justificativa está no cabeçalho de
`app/Integrations/Payments/MercadoPagoGateway.php`, em resumo: o SDK guarda o access token
em propriedade **estática** (risco com a exigência de nunca misturar ambientes) e monta o
próprio cliente cURL, que não pode ser fingido em teste — e é justamente timeout,
repetição e ambiguidade que precisam de teste.

- Timeout total `MERCADOPAGO_TIMEOUT_SECONDS` (20 s) e de conexão
  `MERCADOPAGO_CONNECT_TIMEOUT_SECONDS` (10 s).
- Repetição só em **erro de conexão, 429 e 5xx**, sempre com a **mesma**
  `X-Idempotency-Key` (derivada do `external_reference`). Um 4xx é resposta definitiva e
  não é repetido.
- Identificador de correlação próprio (`X-Correlation-Id`), devolvido nos logs e nas
  exceções.

> **`X-Idempotency-Key` em `/checkout/preferences` — NÃO CONFIRMADO.** O header é
> **obrigatório** em `POST /v1/payments` e em refunds; a referência de
> `POST /checkout/preferences` não o lista. Nós o enviamos assim mesmo porque é o que o
> SDK oficial faz em todo POST/PUT/PATCH: é seguro e não inventa campo de corpo.

### Ambiguidade de timeout

Uma resposta inconclusiva **nunca vira sucesso**. Quando a criação da preferência não
conclui, o `Payment` local fica `pending` **sem** `provider_preference_id`, e o usuário vê
"Não conseguimos confirmar a criação do checkout. Nada foi cobrado."

Na tentativa seguinte, antes de criar qualquer coisa, `StartCheckout` **consulta**
`GET /checkout/preferences/search?external_reference=…`:

- preferência encontrada → reaproveitada (`billing.checkout.preference_recovered`);
- confirmado que não existe → cria uma nova, para o **mesmo** pagamento local;
- a consulta também falhou → **nada é criado**, e o usuário é convidado a tentar de novo.

Resultado: uma tentativa que deu timeout nunca vira duas cobranças. Verificado em
`tests/Feature/Billing/CheckoutTest.php`.

---

## 12. Trilha de auditoria

Eventos da organização (`audit_events` com `envelope_id` nulo), gravados por
`BillingTrail`: `payment.created`, `payment.approved`, `payment.failed`,
`subscription.activated`, `subscription.canceled`, `subscription.resumed`,
`subscription.past_due`, `subscription.expired`.

> Esses oito tipos **não constam** de `RECONCILIACAO.md` §3, que enumerou apenas o ciclo
> do envelope. Foram acrescentados ao enum `AuditEventType` porque a ativação de plano, o
> cancelamento e a inadimplência precisam de trilha auditável. O catálogo do envelope
> continua inteiro — nada foi renomeado nem removido — e o teste
> `tests/Unit/Models/EnumCatalogTest.php` verifica as duas coisas.

Payload mínimo: identificadores públicos, código do plano, valor em centavos, moeda e
ambiente. Nunca token, chave de webhook, e-mail completo ou dado de cartão.

---

## 13. O que **não** está implementado

| Item                                        | Situação                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| ------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Recorrência automática**                  | Não existe no Checkout Pro (a tabela oficial marca "Pagamentos recorrentes" como indisponível). Exigiria migrar para **Assinaturas** (`/preapproval` + `/preapproval_plan`), que pede `card_token_id` e tem checkout próprio. Cada ciclo aqui é um pagamento avulso.                                                                                                                                                                                                       |
| **Pix e boleto**                            | Estão disponíveis por padrão no Checkout Pro, mas a lista efetiva depende da conta do vendedor e só pode ser confirmada com `GET /v1/payment_methods` usando a credencial real. `MERCADOPAGO_EXCLUDED_PAYMENT_TYPES` permite excluir tipos; `account_money` **não** pode ser excluído (o `BillingSettings` remove esse valor da lista se alguém o configurar). A regra "Pix só aparece com chave Pix cadastrada" está **NÃO CONFIRMADA** na doc principal do Checkout Pro. |
| **Nota fiscal (NFS-e)**                     | Fase 2. Não existe API pública do Mercado Pago para NFS-e (o índice oficial da documentação não tem nenhuma página do tema; o blog cita só NF-e/NFC-e, e nem isso é documentação de desenvolvedor). O PDF que emitimos é recibo interno, e diz isso.                                                                                                                                                                                                                       |
| **Reembolso e cancelamento pela interface** | Endpoints documentados (`POST /v1/payments/{id}/refunds`, `PUT /v1/payments/{id}` com `status=cancelled`), mas nenhuma tela ou serviço os aciona. Um `refunded` que chegue por webhook é **gravado** no pagamento; a decisão de negócio (encerrar o plano? devolver cota?) não está implementada.                                                                                                                                                                          |
| **Ordem comercial (`merchant_order`)**      | `getMerchantOrder()` existe e é testado, mas o webhook desse tópico é gravado como `ignored`. Ele só importaria com múltiplos pagamentos por preferência (boleto expirado + cartão aprovado), o que não acontece com o nosso fluxo de um pagamento por ciclo.                                                                                                                                                                                                              |
| **Contestações (chargeback)**               | Tópico reconhecido e ignorado. Um `charged_back` que chegue pelo tópico `payment` é gravado no pagamento.                                                                                                                                                                                                                                                                                                                                                                  |
| **Plano anual**                             | O seletor mensal/anual existe na interface e o modelo suporta `billing_period = yearly`, mas o catálogo do seeder só tem planos mensais.                                                                                                                                                                                                                                                                                                                                   |
| **Cobrança de excedentes**                  | O mock mostra "Excedentes: R$ 0,90 por documento". Não implementado: a cota esgotada **bloqueia** o envio, não gera cobrança extra.                                                                                                                                                                                                                                                                                                                                        |

---

## 14. Itens **NÃO CONFIRMADOS** da pesquisa oficial que afetam esta implementação

Reproduzidos de `docs/integracoes/mercado-pago.md` §10. Nenhum deles foi tratado como
fato no código.

1. Existência atual do prefixo `TEST-` em tokens — a doc atual só menciona `APP_USR`, e o
   nosso código **não** usa prefixo para detectar ambiente.
2. Conciliação entre "credenciais de teste só existem para Checkout Transparente/Bricks" e
   "as credenciais da conta de teste vendedor **são** as credenciais de teste" para o
   Checkout Pro — verificar no painel.
3. Semântica completa de `X-Idempotency-Key` (mesma chave com corpo diferente; janela de
   retenção) e sua obrigatoriedade em `/checkout/preferences` (não listada).
4. "Pix só aparece com Chave Pix cadastrada" na doc principal do Checkout Pro (só aparece
   em docs de plugins); disponibilidade de Linha de Crédito por conta.
5. Presença garantida de `order.id` em todo `GET /v1/payments/{id}` — tratado como
   opcional; não dependemos dele.
6. Tratamento de caixa do `data.id` alfanumérico na assinatura (ver §4).
7. Configuração de webhooks pelo painel indisponível para Assinaturas/QR.
8. Mecânica de recorrência por meio de pagamento em Assinaturas.
9. Qualquer API do Mercado Pago para NFS-e (inexistente na documentação consultada).

---

## 15. Configuração

Tudo em `config/assinavelox.php` (chaves `mercadopago` e `billing`);
`config/services.php` traz apenas um ponteiro para não criar duas fontes de verdade.
Nenhum segredo aparece em log, exceção, argumento de processo ou payload de fila.

| Variável                                                | Padrão          | Para que serve                                                    |
| ------------------------------------------------------- | --------------- | ----------------------------------------------------------------- |
| `MERCADOPAGO_DRIVER`                                    | `auto`          | `auto` \| `fake` \| `mercadopago`                                 |
| `MERCADOPAGO_ENVIRONMENT`                               | `sandbox`       | `sandbox` \| `production`; conferido contra `live_mode`           |
| `MERCADOPAGO_ACCESS_TOKEN`                              | —               | credencial do backend; sem ela o adaptador real fica desabilitado |
| `MERCADOPAGO_PUBLIC_KEY`                                | —               | só faria falta com SDK de frontend (não usamos)                   |
| `MERCADOPAGO_WEBHOOK_SECRET`                            | —               | chave do painel; sem ela o webhook responde 401                   |
| `MERCADOPAGO_NOTIFICATION_URL`                          | rota do webhook | HTTPS pública, ≤ 248 caracteres, sem localhost                    |
| `MERCADOPAGO_WEBHOOK_TOLERANCE_SECONDS`                 | `300`           | janela aceita para o `ts` da assinatura                           |
| `MERCADOPAGO_TIMEOUT_SECONDS`                           | `20`            | timeout total da chamada                                          |
| `MERCADOPAGO_CONNECT_TIMEOUT_SECONDS`                   | `10`            | timeout de conexão                                                |
| `MERCADOPAGO_RETRIES`                                   | `2`             | repetições em conexão/429/5xx, com a mesma chave                  |
| `MERCADOPAGO_RETRY_DELAY_MS`                            | `500`           | intervalo entre repetições                                        |
| `MERCADOPAGO_STATEMENT_DESCRIPTOR`                      | `ASSINAVELOX`   | fatura do cartão; cortado em 13 caracteres                        |
| `MERCADOPAGO_BINARY_MODE`                               | `false`         | só aprovado/recusado (pode reduzir aprovação)                     |
| `MERCADOPAGO_PREFERENCE_TTL_HOURS`                      | `48`            | validade da preferência e janela de reaproveitamento              |
| `MERCADOPAGO_INSTALLMENTS`                              | `1`             | máximo de parcelas (1 a 36)                                       |
| `MERCADOPAGO_EXCLUDED_PAYMENT_TYPES`                    | vazio           | lista por vírgula; `account_money` é ignorado                     |
| `ASSINAVELOX_BILLING_GRACE_DAYS`                        | `3`             | carência antes de `past_due`                                      |
| `ASSINAVELOX_BILLING_EXPIRED_DAYS`                      | `15`            | prazo antes de `expired`                                          |
| `ASSINAVELOX_OPERATOR_NAME` / `_LEGAL_NAME` / `_TAX_ID` | —               | operadora impressa no recibo                                      |

Fila: `billing` (Horizon em produção, driver `database` em dev). Agendamento:
`billing:dunning` diário — exige o cron chamando `php artisan schedule:run`.

---

## 16. Planos e preços fictícios

O `PlanSeeder` marca **todos** os planos pagos como `is_sandbox = true` e
`is_public = false`: os preços (`R$ 49,00` e `R$ 399,00`) são placeholders de
desenvolvimento, **não** uma oferta comercial.

- **Visibilidade**: em produção só entram planos ativos **e** públicos. Planos sandbox
  aparecem apenas em `local`/`testing`.
- **Rotulagem**: `PlanController` envia `is_sandbox` e o sinônimo explícito
  `price_is_placeholder`. A interface marca o card com o selo "Sandbox", escreve "Valor
  fictício de desenvolvimento — não é oferta" e mostra o aviso do topo da página.

Antes de anunciar qualquer plano como oferta real, é preciso definir os preços comerciais
e trocar `is_sandbox` para `false` e `is_public` para `true` no seeder.

---

## 17. Testes

`tests/Feature/Billing/` — 79 testes.

| Arquivo                     | Cobre                                                                                                                                                                                                                                              |
| --------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `WebhookSignatureTest.php`  | assinatura válida, inválida, ausente, sem `x-request-id`, fora da janela, carimbo em milissegundos, sem segredo configurado, sem CSRF                                                                                                              |
| `WebhookProcessingTest.php` | evento repetido, `created` + `updated`, evento fora de ordem, estorno legítimo, valor divergente, ambiente divergente, tópico não tratado, pagamento de outra instalação                                                                           |
| `CheckoutTest.php`          | criação do pagamento, corpo da preferência, dois cliques, plano gratuito recusado, **timeout sem duplicar** (consulta antes de recriar), consulta que também falha, preferência inexistente, timeout do adaptador real, **retorno não ativa nada** |
| `ActivationTest.php`        | ativação, idempotência, corrida com instância desatualizada, pagamento adiantado, reservas preservadas, reativação de inadimplente, pagamento não aprovado, trilha                                                                                 |
| `DunningTest.php`           | carência, `past_due`, plano gratuito imune, `expired` + volta ao Grátis, comando idempotente, cancelar/reativar, confirmação de senha                                                                                                              |
| `PlanConsumptionTest.php`   | cota esgotada bloqueia envio e leitura continua, inadimplência bloqueia, expirada bloqueia, consumo do ledger, `commit` idempotente, última unidade, plano ilimitado                                                                               |
| `PaymentReceiptTest.php`    | PDF com o aviso "não é documento fiscal" (texto extraído com pypdf), escopo por organização, só para pago, dados de faturamento, validação de CPF/CNPJ e CEP, rótulo de plano sandbox                                                              |
| `GatewayTest.php`           | real desabilitado sem credencial, dublê identificável, driver `auto`, ambientes não se misturam, centavos e moeda, idempotência na repetição, 4xx × 5xx, segredo fora das mensagens                                                                |
| `NoCardDataTest.php`        | nenhum PAN/CVV em coluna, no recibo do webhook, no log ou no payload do job; `last_four` sempre nulo                                                                                                                                               |

`tests/Feature/Billing/Support/receipt_text.py` roda no venv do pdftool (só `pypdf`) para
ler o texto do PDF: o DOMPDF embute a DejaVu Sans com `Identity-H`, então o texto fica em
identificadores de glifo e uma leitura ingênua do arquivo não encontraria a frase impressa.
