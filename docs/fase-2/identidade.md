# Fase 2, onda B — Identidade (C-ID): CPF, CNPJ e captura simples de foto

Roadmap §2.10 e §2.11; viabilidade §1.1 (classes) e §4.4 (decisões jurídicas pendentes); brief
`docs/integracoes/cnpj-cpf.md`. Data: 11/09/2026. Identificadores em inglês; textos em PT-BR.

**Tudo nasce desligado.** Com as quatro flags em `false` (o padrão), o comportamento é o de hoje: nenhuma
rota nova responde, nenhuma chamada externa acontece e o aceite grava exatamente o mesmo snapshot.

| Flag (`assinavelox.features.*` E `plans.features.*`) | Liga                                                                   | Classe                            |
| ---------------------------------------------------- | ---------------------------------------------------------------------- | --------------------------------- |
| `cpf_field`                                          | o tipo de campo `cpf` no editor                                        | A                                 |
| `cpf_lookup`                                         | a consulta **cadastral** do CPF no aceite                              | B (produção desabilitada)         |
| `cnpj_lookup`                                        | o autopreenchimento por CNPJ (configurações; cadastro usa só o global) | A                                 |
| `identity_capture`                                   | a captura simples de foto do rosto e do documento                      | A, desligada até decisão jurídica |

Resolvedor: `App\Services\Identity\IdentityFeatures` (mesma regra de `DomainFeatures`: interruptor global
**e** plano; sem organização, só o global).

## 1. Quatro coisas diferentes, que não se substituem

| Conceito                      | O que prova                                                                            | O que **não** prova                                 | No AssinaVelox                                                                     |
| ----------------------------- | -------------------------------------------------------------------------------------- | --------------------------------------------------- | ---------------------------------------------------------------------------------- |
| **Dígitos do CPF**            | que o número tem 11 dígitos, não é sequência repetida e os dois verificadores conferem | que o CPF existe, está regular ou é de quem digitou | campo `cpf`, validado no servidor (§2.1). **Real.**                                |
| **Consulta cadastral**        | que o provedor X, no momento T, achou o CPF (e em que situação)                        | que a pessoa do outro lado da tela é a titular      | `CpfVerificationProvider` (§2.2). **Contrato + simulador; produção desabilitada.** |
| **Posse de canal**            | que o participante controla aquele e-mail/celular naquele momento                      | que o canal pertence ao titular do CPF              | código por e-mail (Fase 1); SMS/WhatsApp e PIN são da área C-CAN.                  |
| **Biometria / prova de vida** | correspondência facial com base oficial e vivacidade                                   | —                                                   | **Futuro. Não implementado** (regra fixa 2 da viabilidade).                        |

A **captura simples** de §5 não é nenhuma das quatro: é uma imagem que o participante enviou. Ela não
verifica nada e nunca é rotulada como verificação.

Vocabulário proibido em interface, evidência e trilha (T1): "identidade verificada", "biometria",
"liveness", "prova de vida", "reconhecimento facial", "assinatura avançada", "qualificada",
"reconhecimento de firma". O teste `IdentityCaptureTest` confere os textos desta área.

## 2. CPF

### 2.1 Dígitos — campo `cpf` (real)

- `FieldType::Cpf` (`'cpf'`, rótulo "CPF"). O participante digita; o servidor valida em
  `RecordAcceptance::cpfValue()`: só dígitos, pontos, hífen e espaços, até 20 caracteres, e
  `TaxId::isCpf()` (verificadores + sequência repetida). Inválido → `invalid_cpf` no campo
  (`errors.fields.{ulid}`), nenhum aceite gravado. Obrigatório vazio → `required_field_missing`.
- **Gravado formatado** (`529.982.247-25`) em `signing_field_values.value_text` e no `fields_snapshot`:
  é conteúdo do documento, preenchido pela própria pessoa, e é estampado no PDF como texto (a
  consolidação trata `cpf` como os demais campos de texto).
- **Fora do documento só sai mascarado**: `***.982.247-**` (`CpfNumber::mask()`: oculta os três
  primeiros e os dois verificadores, o padrão da Receita para CPF de sócio nos dados abertos). O
  snapshot de cada valor `cpf` ganha `cpf_masked`. A **verificação pública** não publica valor de campo
  nenhum (lista fechada de chaves) e a **trilha** nunca recebe o CPF completo — o único evento que cita
  CPF é `cpf_lookup.performed`, com `cpf_masked`. Teste: `CpfFieldTest`.
- Um campo `cpf` que já exista num envelope enviado continua validado mesmo com as flags desligadas
  (desligar não abandona envelope no meio).
- **Interface (front):** dizer que "o CPF é conferido só pelos dígitos; isso não confirma que a pessoa
  é a titular".

### 2.2 Consulta cadastral (classe B) — `cpf_lookup`

- Contrato existente `App\Integrations\Contracts\CpfVerificationProvider` (sem mudança).
- Adaptadores (`app/Integrations/Cpf/`), escolhidos por `assinavelox.cpf_lookup.driver`
  (`CpfVerificationFactory`):
    - `disabled` (padrão) → `OwnServiceCpfVerificationProvider` (`own_service`): **não chama nada**,
      `isConfigured() = false`, responde `inconclusive` com `reason_code = not_configured` e a lista do
      que falta. Nenhum endpoint, autenticação ou formato foi inventado (T4).
    - `fake` → `FakeCpfVerificationProvider` (`cpf_simulado`), **criado pela área C-CAN** e reaproveitado
      aqui (não duplicado). Só responde com `assinavelox.channels.allow_simulated` ligado **e** fora de
      produção — o mesmo interruptor dos simuladores de SMS e WhatsApp (`isConfigured()`) — e nunca devolve
      consulta positiva (só `simulate()`, nos testes). Com o interruptor desligado ou em produção, a fábrica
      recusa o driver `fake`, registra o motivo no log e vale o desabilitado (`own_service`,
      `not_configured`); resolvido direto do contêiner, o próprio simulador responde `inconclusive` com
      `reason_code = not_configured`.
    - `bound` → o que estiver ligado ao contrato no contêiner (gancho para a integração e dublês de teste).
- Uso: no aceite, **fora da transação**, para cada campo `cpf` com dígitos válidos
  (`CpfLookup::checkFields()`), só com a flag da organização. Dígito inválido nunca chega ao provedor.
- Mapeamento gravado em `fields_snapshot.values[].cpf_check`:
  `valid → verified`, `invalid → not_verified`, qualquer outra coisa, exceção ou tempo esgotado →
  `unavailable` (T5: desconhecido nunca vira sucesso). Campos: `status`, `provider`, `simulated`,
  `checked_at`, `reason_code`, `label` ("Consulta cadastral: …", com "(simulado)" quando for o caso).
  Nome, nascimento e óbito que um provedor devolva **não** são gravados.
- **Nenhum resultado bloqueia o aceite** (roadmap §2.11). O modo "estrito" é decisão pendente.
- Trilha: `cpf_lookup.performed` com `field_ulid`, `cpf_masked`, `status`, `provider`, `simulated`,
  `reason_code`.

**Produção desabilitada — o que falta do proprietário (lista exata, `OwnServiceCpfVerificationProvider::MISSING`):**

1. URL base e caminho do endpoint de consulta (homologação e produção);
2. método de autenticação e credenciais de homologação e produção;
3. entradas exigidas além do CPF (ex.: data de nascimento, nome) e seu formato;
4. formato da resposta, campos devolvidos e códigos de situação cadastral;
5. códigos de erro e o significado de tempo esgotado, 4xx e 5xx;
6. limites de taxa, SLA e janela de manutenção;
7. custo por consulta (para os limites por plano);
8. **base legal e finalidade LGPD aprovadas pelo jurídico (viabilidade §4.4 item 21)** e decisão sobre o
   modo "estrito".

SERPRO Consulta CPF, Conecta gov.br e BrasilAPI **não** são alternativas (regra fixa 1; Conecta só
atende órgãos públicos; BrasilAPI só valida dígitos).

## 3. CNPJ (real) — `cnpj_lookup`

- Adaptador `App\Integrations\Cnpj\MinhaReceitaCnpjLookup` (contrato existente `CnpjLookupProvider`):
  `GET {base_url}/{cnpj}` na instância pública do Minha Receita (documentada no brief), **sem SLA**.
  `base_url` configurável (auto-hospedagem futura sem mudar código). Timeout curto (4 s, conexão 2 s),
  **sem repetição e sem seguir redirecionamento**.
- **A BrasilAPI não é fallback**: o CNPJ dela é proxy da mesma fonte.
- Respostas: 200 válido → encontrado; 404/400 → não encontrado; 429, 5xx, outro status, tempo esgotado,
  corpo > 512 KB, JSON inválido, sem razão social ou de outro CNPJ → **indisponível** (`CnpjLookupUnavailable`).
- **Minimização**: só razão social, nome fantasia, situação, endereço e CNAE principal. Sócios (QSA,
  com nome e CPF parcial), e-mail e telefones nunca são guardados nem devolvidos. Strings saneadas e
  limitadas (T6).
- **Cache** `cnpj_lookups` (sem organização, sem registro de quem consultou): encontrado 30 dias,
  não encontrado 24 h; indisponível não é cacheado. Cache de outra fonte (ex.: simulador) é ignorado.
- Simulador identificado `FakeCnpjLookup` (`fake_cnpj`, "(simulado)" no nome, aviso no log); recusado em
  produção.
- **Nunca bloqueia**: todo desfecho responde `manual_fill: true` e uma mensagem. Os formulários de
  cadastro e de Configurações salvam o que foi digitado, com ou sem consulta.
- **Limite por usuário** (10/min) e, no cadastro sem login, por IP (5/min): 429 com `Retry-After`.
- Atribuição a exibir junto do autopreenchimento: "Fonte: Receita Federal — dados abertos do CNPJ, via
  Minha Receita" (`source.attribution`).

## 4. Posse de canal

Continua sendo o código por e-mail da Fase 1; SMS, WhatsApp e PIN são da área C-CAN
(`docs/fase-2/canais-e-pin.md`). Nada nesta área transforma posse em identidade.

## 5. Captura simples de foto do rosto e do documento — `identity_capture`

**Não é biometria.** Não há detecção de rosto, comparação facial, prova de vida nem leitura (OCR) do
documento. A flag fica desligada até a **decisão jurídica da viabilidade §4.4 item 20** (base legal do
art. 11 da LGPD, papel de controlador × operador, RIPD e prazo de retenção). Por precaução, a imagem é
tratada como dado sensível.

### 5.1 Exigência pelo remetente

`PUT documentos/{envelope}/participantes/{recipient}/captura` com `kinds ⊂ {selfie, document_front,
document_back}` (lista vazia remove; verso só com frente). Só no rascunho, só para quem registra aceite
(visualizador não), `update` no envelope, flag da organização. Tabela `identity_capture_requirements`
(uma linha por destinatário, cascata com o destinatário/envelope). Trilha
`identity_capture.requirement_updated` (`recipient_ulid`, `kinds`).

### 5.2 Envio pelo participante

`POST assinar/{token}/captura/{kind}` (sessão autenticada pelo código), multipart `image` + `source`
(`camera`|`upload`). 404, sem distinguir motivos, com a flag desligada, papel sem aceite ou tipo não
exigido: não se coleta imagem que ninguém pediu. Refazer substitui a foto anterior do mesmo tipo (o
arquivo antigo é apagado). Limite: 30 envios por participante por hora. Trilha
`identity_capture.recorded` (`capture_ulid`, `kind`, `sha256`, dimensões, `replaced`, `source`) — nunca a
imagem nem o caminho. `source` é o que o navegador declarou (`camera` | `upload`; outro valor é recusado com
422, ausente fica `null`) e não é verificado.

### 5.3 Normalização (`CaptureImageNormalizer`, GD)

1. tamanho ≤ `capture.max_upload_kb` (8 MB);
2. tipo **real** por `finfo`: só JPEG e PNG; **SVG recusado** ("Imagens SVG não são aceitas");
3. dimensões lidas do cabeçalho ≤ `max_source_pixels` (30 MP) antes de decodificar (bomba de
   descompressão) + estimativa de memória;
4. orientação EXIF aplicada aos pixels, redução ao lado máximo `output_max_side` (1600 px) e **reencode do
   zero** em JPEG sobre fundo branco: EXIF (inclusive GPS), ICC, XMP e chunks de texto somem.

### 5.4 Armazenamento, vínculo e Permissions-Policy

- Disco privado `documents`, `orgs/{org}/envelopes/{env}/identity/{kind}-{ulid}.bin`, **cifrado** com a
  chave da aplicação (`Crypt`). `identity_captures` guarda tipo, SHA-256 dos bytes normalizados,
  dimensões, tamanho, origem e momento.
- **O aceite só é gravado com todas as fotos exigidas nesta sessão** (`identity_capture_missing`). O
  aceite as vincula (`signature_acceptance_id`) e o `fields_snapshot` ganha `identity_captures`
  (`capture_ulid`, `kind`, `sha256`, dimensões, `captured_at`, `source` — a origem informada pelo
  navegador, não verificada).
- **Permissions-Policy**: `camera=()` em toda resposta, exceto nas rotas públicas de captura — o envio
  (`sign.capture.store`) e a página do participante (`sign.show`) **somente** quando o convite está ativo,
  a flag da organização está ligada e há foto exigida para aquela pessoa (`CameraPermission`). Não há
  página GET separada para a captura: a etapa mora na página pública, e uma rota GET com token quebraria
  o smoke test de rotas (ver relatório).

### 5.5 Evidência, retenção e exclusão

- Página de evidências do **remetente**: cada foto aparece como **"Imagem enviada pelo participante —
  origem informada pelo navegador: câmera"** (ou "…: arquivo do dispositivo"; sem `source`, "origem não
  informada pelo navegador"), com miniatura (data URI, ≤ 320 px) e a nota "Não houve verificação de
  identidade: a plataforma não compara rostos, não analisa a imagem e não lê o documento fotografado. A
  origem de cada imagem (câmera ou arquivo do dispositivo) é a informada pelo navegador do participante e
  não é verificada." Nunca na verificação pública nem no PDF de evidências. (`CaptureEvidence`.)
    - Revisão adversarial da onda B: o rótulo antigo, "Imagem capturada pelo participante", valia também
      para um arquivo escolhido da galeria — que pode ser foto de terceiro, baixada ou editada. De um
      envio a plataforma só sabe que alguém, com a sessão do participante, mandou aquele arquivo (T1:
      descrever o meio, não afirmar o que não se provou). A origem (`source`) é a que o navegador declara
      no envio (`camera` quando a foto saiu de `getUserMedia`, `upload` quando veio de um arquivo), gravada
      em `identity_captures.source`, no `fields_snapshot` (`identity_captures[].source`) e na trilha
      (`identity_capture.recorded`). Ela **não é verificada**: um cliente adulterado pode declarar
      `camera` para um arquivo.
- Retenção (`CapturePurge::run()`): o **arquivo** de foto vinculada sai `retention_days` (180) depois
  da captura; a linha fica com `purged_at` (o aceite continua citando o resumo). Foto sem aceite sai
  inteira depois de `orphan_retention_hours` (48). `retention_days = 0` desliga. Trilha
  `identity_capture.purged` (`count`, `reason`).
- Exclusão: a da organização leva arquivos (`orgs/{ulid}`) e linhas (cascata de destinatários e
  envelopes); `CapturePurge::forEnvelope()` fica pronto para a exclusão de envelope (§2.19).

## 6. Biometria — futuro

Até 2026-09-21, `IdentityVerificationProvider` era um contrato reservado, sem implementação. Nessa data o
proprietário pediu a comparação facial com documento pela **Verifiky** (já integrada no metta-bank) e o
contrato ganhou adaptador real, simulador e fábrica: **Fase 4 §4.1**, `docs/fase-4/verificacao-facial.md`.

O que NÃO mudou: a captura simples desta seção continua sendo só captura quando o remetente não exige a
verificação; e o vocabulário proibido (§1) vale também lá — quem compara é o provedor, e a interface só
repete o que ele informou.

## 7. Contrato de props para o front

**`features`** (quando a integração ligar em `HandleInertiaRequests::features()`): `cpf_field`,
`cpf_lookup`, `cnpj_lookup`, `identity_capture` (`IdentityFeatures::forOrganization()`).

**Campo `cpf` no editor** — mesmo formato dos demais campos (`type: 'cpf'`, `required`, `label`,
`options.placeholder`); só aparece na paleta com `features.cpf_field`. Placeholder sugerido
`000.000.000-00`.

**Campo `cpf` na página pública** — vem em `my_fields` com `type: 'cpf'` e `prefill: null`; o valor volta
em `fields[{ulid}]` no POST do aceite. Erro no campo: `errors['fields.{ulid}']`. Máscara no cliente é
conforto; quem decide é o servidor.

**Etapa de captura na página pública** — prop `identity_capture` (`CaptureStep::props($context, $session)`),
`null` quando não se aplica:

```ts
identity_capture: null | {
  required: true
  complete: boolean
  title: string                  // "Fotos para o registro do aceite"
  items: Array<{
    kind: 'selfie' | 'document_front' | 'document_back'
    label: string                // "Foto do rosto" …
    instructions: string
    facing_mode: 'user' | 'environment'
    required: true
    captured: boolean
    captured_at: string | null
    width: number | null
    height: number | null
    upload_url: string | null    // POST multipart `image` (+ `source`); null antes do código
  }>
  accept: ['image/jpeg', 'image/png']
  max_upload_kb: number
  retention_days: number
  notice: string                 // "…não são usadas para verificar sua identidade…"
}
```

Resposta do envio com `Accept: application/json`: `201 { capture: {id, kind, captured_at, width,
height}, identity_capture: <mesmo bloco atualizado> }`; erro `422/429 { message, errors: { image: [...] },
code }`. Sem JSON: redirect para `sign.show` com flash ou `errors.image`. Aceite sem foto:
`errors.signature` = "Antes de concluir, envie: …". Captura com `getUserMedia` + `canvas.toBlob('image/jpeg')`
e alternativa de upload.

**Exigência no wizard (passo 2)** — `IdentityCaptures::requirementsForEnvelope($envelope)` →
`{ [recipientUlid]: kinds[] }`; salvar com `PUT envelopes.recipients.identity_capture`.

**Evidências do remetente** — por participante `identity_captures: Array<{id, kind, kind_label, label,
source, source_label, captured_at, width, height, sha256, available, purged_at, thumbnail}>` (`source`:
`'camera' | 'upload' | null`, declarada pelo navegador) e na página
`identity_capture_notice` (`CaptureEvidence::forEnvelope()`).

**Autopreenchimento de CNPJ** — `POST settings.organization.cnpj` (Configurações › Geral) ou
`POST cnpj.lookup` (cadastro e "Nova organização") com `{ cnpj }`:

```ts
{
  status: 'found' | 'not_found' | 'invalid' | 'unavailable' | 'rate_limited'
  cnpj: string | null                       // formatado
  data: null | { legal_name, trade_name, registration_status, address: {street, number, complement?, district, city, state, postal_code}, cnae: {code, description} }
  suggestions: null | { legal_name: string | null, name: string | null }   // "Razão social" e "Nome da organização"
  source: null | { provider, simulated, attribution?, fetched_at?, cached?, reason? }
  message: string
  manual_fill: true
  retry_after: number | null
}
```

HTTP 200 (`found`, `not_found`, `unavailable`), 422 (`invalid`), 429 (`rate_limited`). Nunca desabilitar
o formulário; mostrar `source.attribution` e, com `source.simulated`, o selo "simulado".

## 8. Rotas

| Método | URI                                                       | Nome                                    | Grupo                                        |
| ------ | --------------------------------------------------------- | --------------------------------------- | -------------------------------------------- |
| POST   | `assinar/{token}/captura/{kind}`                          | `sign.capture.store`                    | signer + `signer.verified`                   |
| PUT    | `documentos/{envelope}/participantes/{recipient}/captura` | `envelopes.recipients.identity_capture` | app                                          |
| POST   | `configuracoes/organizacao/cnpj`                          | `settings.organization.cnpj`            | app                                          |
| POST   | `cnpj/consulta`                                           | `cnpj.lookup`                           | público (`throttle:public`) + limite próprio |

## 9. Banco (migrations aditivas, MySQL-compatíveis)

`2026_09_11_120101_create_cnpj_lookups_table`, `…120102_create_identity_capture_requirements_table`,
`…120103_create_identity_captures_table`. Sem ENUM SQL, sem DEFAULT em JSON, índices nomeados ≤ 64.

## 10. Configuração

`assinavelox.cnpj.*` (driver, base_url, timeouts, TTLs, limites, atribuição), `assinavelox.cpf_lookup.*`
(driver, finalidade) e `assinavelox.capture.*` (limites, lado máximo, qualidade, miniatura, retenção,
órfãs, envios por hora), todos com `ASSINAVELOX_*` no `.env`.
