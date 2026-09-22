# Verifiky — verificação facial com documento

> Fase 4 §4.1. Adaptador: `App\Integrations\Identity\Verifiky\*`. Funcionalidade: [`docs/fase-4/verificacao-facial.md`](../fase-4/verificacao-facial.md).
> **Fonte do contrato:** a integração que já roda no metta-bank (`VerifikyService`, `VerifikyUpstreamClient`, `VerifikySignatureValidator`, `VerifikyWebhookProcessor`, `VerifikyReadClient`), levantada em 2026-09-21. O que está aqui foi **lido daquele código**, não da documentação oficial (`https://app.verifiky.com/docs`), que não foi consultada. Onde o metta-bank tenta mais de um formato, isso está dito.

## 1. O que a Verifiky faz aqui

Recebe três imagens — a foto do rosto tirada na hora e a frente e o verso do documento — mais o tipo do documento. Lê o documento, compara o rosto da foto com o do documento e responde: aprovado, reprovado ou em análise. **Quem compara é ela.** A plataforma envia as imagens da captura (`identity_captures`) e guarda a resposta.

A Verifiky **não** recebe o documento que está sendo assinado, nem o nome do envelope, nem o e-mail do participante. Recebe as imagens, o tipo do documento e uma referência nossa (um ULID sem significado fora daqui).

## 2. Configuração

Os nomes das variáveis são **os mesmos do metta-bank**, de propósito: quem opera os dois sistemas não precisa aprender outro vocabulário.

```dotenv
ASSINAVELOX_FEATURE_IDENTITY_VERIFICATION=false   # liga a funcionalidade (global E plano; exige identity_capture)
ASSINAVELOX_IDENTITY_VERIFICATION_DRIVER=disabled # disabled | fake | verifiky

VERIFIKY_API_URL=https://app.verifiky.com
VERIFIKY_API_KEY=            # [SEG] chave da conta
VERIFIKY_WEBHOOK_SECRET=     # [SEG] HMAC do webhook
VERIFIKY_HMAC_SECRET=        # [SEG] HMAC das leituras — segredo SEPARADO do webhook
VERIFIKY_TIMEOUT=180
VERIFIKY_VERIFY_SSL=true
```

| Driver     | O que acontece                                                                                                                                                           |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `disabled` | Padrão. Nenhuma chamada; toda tentativa termina "inconclusiva — não configurada", com a lista do que falta.                                                              |
| `fake`     | Simulador **identificado**: não analisa nada, aprova por padrão e marca tudo como "(simulado)". Só com `ASSINAVELOX_CHANNELS_ALLOW_SIMULATED` ligado e fora de produção. |
| `verifiky` | A API de verdade. Sem `VERIFIKY_API_KEY`, o próprio adaptador responde "não configurado" — ele nunca cai no simulador em silêncio.                                       |

`php artisan assinavelox:doctor` mostra o estado (chave definida? segredo do webhook definido? TLS conferido?) **sem imprimir valor nenhum**.

## 3. Envio

```
POST {VERIFIKY_API_URL}/api/verifiky/processar
Authorization: Bearer {VERIFIKY_API_KEY}
Accept: application/json
Content-Type: multipart/form-data
```

| Campo             | Conteúdo                                                                 |
| ----------------- | ------------------------------------------------------------------------ |
| `user_reference`  | referência nossa da tentativa (`identity_verifications.reference`, ULID) |
| `documento`       | frente do documento (JPEG da captura)                                    |
| `documento_verso` | verso do documento                                                       |
| `foto_ao_vivo`    | foto do rosto tirada na hora                                             |
| `tipo_documento`  | `rg` \| `cnh` \| `passaporte`                                            |

O processamento pode levar **minutos** (o metta-bank usa 180 s de tempo-limite). Por isso o envio roda em **fila** (`SubmitIdentityVerification`), nunca dentro da requisição do participante; a tela consulta o andamento.

A resposta traz o protocolo (`verification_id`, `verificacao_id` ou `data.verification_id`) e, às vezes, já o resultado (`status`, `verificado`, `face_match`). Quando o resultado vem completo, ele vale; quando não, a tentativa fica "em análise" e o resultado chega pelo webhook (§4) ou por consulta (§5).

**Fora desta integração (existem no metta-bank):** RG e CNH digitais em PDF (`/extrair-dados-rg-digital`, `/extrair-dados-cnh-digital`), OCR avulso, comparação avulsa de duas imagens, antecedentes (`background_check.*`), o GET de conta/plano (`/account-status`) e os microsserviços Python locais. Nada disso é necessário para comparar rosto e documento de quem assina.

## 4. Webhook

```
POST {APP_URL}/webhooks/verifiky
X-Verifiky-Signature: <HMAC-SHA256 em hexadecimal do corpo CRU, com VERIFIKY_WEBHOOK_SECRET>
```

Cadastre essa URL no painel da Verifiky. Corpo (formas aceitas — a Verifiky já mandou mais de uma):

```json
{
    "event": "verification.completed",
    "verification_id": 4821,
    "user_reference": "01J…",
    "status": "approved",
    "face_match": { "match": true, "approved": true }
}
```

- `face_match` pode ser booleano ou objeto (`match`/`verified`, `approved`, e às vezes uma pontuação).
- Webhook sem `event` é tratado como `verification.completed` (compatibilidade, como lá).
- `background_check.completed` é reconhecido e ignorado.
- A tentativa é achada por `user_reference`; na falta, por `verification_id`. **Referência desconhecida responde 200 "ignorado"**: a mesma conta da Verifiky pode servir outros sistemas do proprietário, e um 4xx faria a Verifiky reenviar para sempre.
- **Idempotência:** resultado conclusivo não muda. Webhook repetido ou atrasado não vira um aprovado em reprovado, nem o contrário.

### Duas diferenças deliberadas em relação ao metta-bank

1. **Sem segredo configurado, nenhum webhook é aceito (401).** Lá, sem segredo, qualquer POST passava. Aqui um webhook aberto deixaria qualquer pessoa aprovar a verificação de qualquer participante. É a mesma regra do webhook do Mercado Pago.
2. **Rosto ausente não é rosto diferente.** Lá, `status = pending` sem o campo `face_match` reprovava a pessoa. Aqui a tentativa continua em análise até vir o resultado completo (ou vencer o prazo, §6).

## 5. Consulta do resultado

Quando o webhook não chega (ambiente local, URL ainda não cadastrada):

```
GET {VERIFIKY_API_URL}/api/verifiky/verificacoes/{id}
Authorization: Bearer {VERIFIKY_API_KEY}
X-Verifiky-Timestamp: <epoch>
X-Verifiky-Nonce: <24 hex>
X-Verifiky-Signature: <HMAC-SHA256 hex do texto canônico, com VERIFIKY_HMAC_SECRET>
```

Texto canônico, uma parte por linha: método, caminho, query ordenada (RFC 3986), timestamp, nonce, SHA-256 do corpo. Sem `VERIFIKY_HMAC_SECRET` a consulta vai só com o Bearer (o metta-bank tem as duas formas). A tela do participante dispara a consulta no máximo uma vez a cada 10 s.

## 6. Como a resposta vira resultado

| A Verifiky diz                                                         | Resultado aqui                                             |
| ---------------------------------------------------------------------- | ---------------------------------------------------------- |
| `status = approved` **e** rosto corresponde (e `approved` não é falso) | `approved`                                                 |
| `status = approved` só com `verificado = true`                         | `approved`                                                 |
| `status = approved` sem nenhuma informação do rosto                    | `pending` (espera o resto)                                 |
| rosto **não** corresponde, em qualquer status                          | `rejected` (`face_mismatch`)                               |
| `status = rejected` / `declined`                                       | `rejected` (`provider_rejected`)                           |
| `status = pending` / só o protocolo                                    | `pending`                                                  |
| `status = expired`                                                     | `expired`                                                  |
| `success = false`, status desconhecido, corpo vazio ou ilegível        | `inconclusive`                                             |
| HTTP 402, ou "crédito" no texto                                        | `inconclusive` (`insufficient_credits`)                    |
| HTTP 401                                                               | `inconclusive` (`provider_auth_failed`)                    |
| HTTP 403, ou "plano/expir/venc" no texto                               | `inconclusive` (`plan_inactive`)                           |
| HTTP 429                                                               | `inconclusive` (`rate_limited`)                            |
| HTTP 400/422                                                           | `inconclusive` (`provider_refused`)                        |
| outros 4xx/5xx, rede, tempo esgotado                                   | `inconclusive` (`provider_error` / `provider_unavailable`) |
| em análise há mais de `pending_timeout_minutes` (20)                   | `inconclusive` (`timeout`)                                 |

**Inconclusivo nunca libera o aceite e nunca gasta tentativa** — é falha técnica, não reprovação. A leitura das falhas HTTP é a mesma do `VerifikyAccountGateService::interpretApiFailure` do metta-bank.

**O que se guarda da resposta** (`identity_verifications.provider_result`): status do provedor, os booleanos da comparação, a pontuação quando vem, e o motivo quando há o que explicar. **Não se guarda** o que a Verifiky leu do documento (nome, CPF, número): a evidência registra o que o provedor concluiu, não o que ele extraiu.

## 7. O que o proprietário precisa entregar

1. `VERIFIKY_API_KEY` no `.env` do AssinaVelox. Pode ser a mesma conta do metta-bank; a chave **não** foi copiada de lá por este trabalho.
2. `VERIFIKY_WEBHOOK_SECRET` e a URL `{APP_URL}/webhooks/verifiky` cadastrada no painel da Verifiky. Em desenvolvimento a Verifiky não alcança `localhost`: o resultado vem pela resposta do envio ou pela consulta (§5).
3. `VERIFIKY_HMAC_SECRET`, se a conta tiver leituras assinadas.
4. Plano com créditos. Sem crédito, toda tentativa termina `insufficient_credits` e o participante lê "avise quem enviou o documento".
5. **Decisão jurídica antes de ligar a flag:** base legal para tratar imagem de rosto e de documento (LGPD art. 11), RIPD, o texto de consentimento (a cláusula atual é minuta), o contrato de operador com a Verifiky e o prazo de retenção das imagens **lá**.

> **Atenção (fora deste repositório):** o arquivo `ENV_VERIFIKY_CONFIG.txt`, na raiz do metta-bank, guarda uma chave da Verifiky em texto puro. Se ele está versionado, vale regenerar a chave no painel — o próprio arquivo diz isso.

## 8. Testar sem gastar crédito

```dotenv
ASSINAVELOX_FEATURE_IDENTITY_CAPTURE=true
ASSINAVELOX_FEATURE_IDENTITY_VERIFICATION=true
ASSINAVELOX_IDENTITY_VERIFICATION_DRIVER=fake
ASSINAVELOX_CHANNELS_ALLOW_SIMULATED=true
```

O simulador aprova e marca "(simulado)" em toda parte. Os testes automatizados (`tests/Feature/Phase4/IdentityVerification/`) prendem o pedido que sai para a Verifiky, a leitura das respostas e a regra de que falha nunca vira aprovação — sem tocar na rede.
