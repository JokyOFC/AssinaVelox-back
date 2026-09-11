# Fase 2, onda A — envelope com múltiplos documentos (§2.3) e papéis de participante (§2.4)

> Agente B-DOM (backend). Data: 2026-09-11. Precedência: `docs/design/RECONCILIACAO.md` → `docs/arquitetura.md` →
> `docs/design/ROUTES_AND_PAGES.md` → `docs/roadmap.md` §1, §2.0, §2.3, §2.4 → este documento.
> Identificadores em inglês; prosa em português. Este documento é o **contrato** para o agente de front.

## 1. Resumo

| Item                   | O que muda                                                                                                                                                     | Ativação                                                      |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| §2.3 vários documentos | um envelope carrega até N arquivos (`documents.position`); cada um tem versão congelada, final e resumos próprios; um aceite por participante cobre o conjunto | `features.multi_document` (config **e** plano)                |
| §2.4 papéis            | `recipients.role` ∈ `signer`, `witness`, `approver`, `viewer`; `signature_acceptances.action` ∈ `sign`, `witness`, `approve`                                   | `features.participant_roles` (config **e** plano) — flag nova |

Com as duas flags desligadas (padrão) **nada muda na Fase 1**: o segundo upload substitui o primeiro, todos os
participantes são `signer`, os textos, os snapshots (`schema` 1), os payloads da trilha e as props são os mesmos
(só ganham chaves aditivas). A suíte da Fase 1 continua verde sem alteração.

As flags só governam o **preparo** (aceitar um segundo arquivo, aceitar papel diferente de `signer`). Um envelope já
enviado com N documentos ou papéis mistos é conduzido até o fim mesmo que a flag seja desligada depois — o pipeline
decide pelo que existe no banco, não pela flag.

### 1.1 Como as flags são lidas

`App\Services\Envelopes\DomainFeatures`:

- `multiDocument(Organization)`: `config('assinavelox.features.multi_document') === true` **e**
  `plans.features.multi_document === true` no plano vigente;
- `participantRoles(Organization)`: idem com `participant_roles`;
- `maxDocuments(Organization)`: `1` com a flag desligada; `config('assinavelox.multi_document.max_documents', 10)` ligada;
- `forOrganization(Organization)`: `{multi_document: bool, participant_roles: bool}`.

Chaves de configuração lidas com padrão seguro (o arquivo `config/assinavelox.php` não é desta área; ver §13):
`assinavelox.features.multi_document` (false), `assinavelox.features.participant_roles` (false),
`assinavelox.multi_document.max_documents` (10).

## 2. Modelo de dados (migrations aditivas)

| Migration                                                         | Tabela / colunas                                                                                                                                                                                                   |
| ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `2026_09_11_110001_add_multi_document_columns_to_documents_table` | `documents.position` (uint, default 1), `documents.sent_version_id` (FK `document_versions`, null on delete), `documents.final_version_id` (idem), índice `(envelope_id, position)`                                |
| `2026_09_11_110002_add_action_to_signature_acceptances_table`     | `signature_acceptances.action` string(32) default `sign`                                                                                                                                                           |
| `2026_09_11_110003_create_acceptance_documents_table`             | `acceptance_documents` (id, signature_acceptance_id, document_id, document_version_id, organization_id, position, document_sha256, fields_snapshot JSON, created_at) — UNIQUE (aceite, documento)                  |
| `2026_09_11_110004_create_signing_session_documents_table`        | `signing_session_documents` (id, signing_session_id, document_id, document_version_id, organization_id, presented_at, created_at) — UNIQUE (sessão, documento)                                                     |
| `2026_09_11_110005_create_verification_record_documents_table`    | `verification_record_documents` (id, verification_record_id, document_id, position, name, original/sent/consolidated/final_sha256, final_document_version_id, page_count, timestamps) — UNIQUE (registro, posição) |

Compatíveis com MySQL 8: sem ENUM de SQL, sem DEFAULT em JSON/TEXT, nomes de índice/FK explícitos e ≤ 64 caracteres.

**Por que colunas em `documents` e não `envelope_sent_documents`:** a relação é 1:1 por documento e imutável depois do
envio; uma tabela extra acrescentaria um JOIN e um segundo lugar onde a mesma verdade poderia divergir. É também o
desenho do roadmap §2.3. `envelopes.sent_document_version_id` e `final_document_version_id` **continuam preenchidos com
o primeiro documento** (compatibilidade com todo o código da Fase 1).

**Congelamento por documento:** feito em `Envelope::saved` — quando `sent_document_version_id` é gravado (envio, sob o
lock do `SendEnvelope`), `documents.sent_version_id = current_version_id` para todos os documentos do envelope; quando
é zerado (reversão de envio que falhou), os congelamentos são desfeitos. Não há caminho que congele um sem o outro.

**Modelos novos:** `AcceptanceDocument`, `SigningSessionDocument` (ambos com escopo de organização) e
`VerificationRecordDocument` (sem escopo, como o registro-pai: a verificação é pública).
Relações novas: `Envelope::orderedDocuments()`, `Document::sentVersion()/finalVersion()/acceptanceDocuments()`,
`SignatureAcceptance::documents()`, `VerificationRecord::documents()`, `SigningSession::presentations()`,
`Recipient::participates()/isViewer()/isPendingParticipant()/scopeParticipating()`.

`Envelope::document()` passou de `oldestOfMany()` para `ofMany(['position' => 'min', 'id' => 'min'])`: é o **primeiro
da lista** (idêntico na Fase 1, em que há um documento em `position` 1).

Serviço de leitura único: `App\Services\Documents\EnvelopeDocuments` (`ordered`, `sent`, `find`, `count`, `isMulti`,
`sentVersionIds`, `currentVersionIds`, `nextPosition`, `compactPositions`). Toda consulta é restrita a
`envelope_id` + `organization_id` do próprio envelope; `find()` devolve `null` para ULID de outro envelope ou organização.

## 3. Papéis e estados

| papel      | aceite (`action`) | campos                                               | vez (`order_index`)   | conta para conclusão | tela pública                              |
| ---------- | ----------------- | ---------------------------------------------------- | --------------------- | -------------------- | ----------------------------------------- |
| `signer`   | `sign`            | ≥ 1 assinatura obrigatória                           | 1..K (sequencial) / 1 | sim                  | `sign` — "Assinar documento"              |
| `witness`  | `witness`         | ≥ 1 assinatura obrigatória (rótulo "Testemunha")     | 1..K / 1              | sim                  | `sign` — "Assinar como testemunha"        |
| `approver` | `approve`         | nenhuma assinatura/rubrica (outros tipos permitidos) | 1..K / 1              | sim                  | `sign` — "Aprovar documento", sem captura |
| `viewer`   | —                 | nenhum campo                                         | 0 (sem vez)           | não                  | `view` — leitura, depois do código        |

- **Status do destinatário**: a máquina de estados não mudou. Aprovador e testemunha vão a `signed` ao registrar
  (o rótulo do aprovador é "Aprovado" nas telas). O visualizador fica em `pending → notified → viewed` e só sai por
  `canceled`/`expired` (cancelamento, recusa de outro, prazo) — **nunca** é pendência (`Recipient::isPendingParticipant()`).
- **Ordem sequencial**: considera signatários, testemunhas e aprovadores por `order_index`; o `RecipientSync` numera
  só quem participa (1..K na ordem da lista) e grava `0` no visualizador. A coerência da ordem
  (`signingOrderIsCoherent`) ignora visualizadores.
- **Convites**: no envio saem os da vez **e** todos os visualizadores (link somente leitura); a cada aceite o próximo
  da vez é convidado; na conclusão os visualizadores ativos recebem a cópia final (`CompletionNotifier`).
- **Recusa**: signatário, testemunha ou aprovador podem recusar (encerra o envelope como `refused`, política da Fase 1);
  visualizador não recusa (409 `not_refusable`). O evento `recipient.refused` ganha `role` quando não é `signer`.

## 4. Preparo

- **Upload** (`envelopes.document.store`): flag desligada → substitui (Fase 1); flag ligada → acrescenta ao fim até o
  teto (`too_many_documents`: "Este documento aceita no máximo N arquivos."). Flag desligada com um rascunho que já
  tem vários arquivos → `multi_document_disabled`. O teto é reconferido sob lock.
- **Remover** (`envelopes.document.destroy?document={ulid}`): sem parâmetro remove o primeiro (Fase 1). Com vários
  arquivos, só os campos do arquivo removido saem; as posições são compactadas (1..N).
- **Reordenar**: `PATCH envelopes.update` com `document_order: [ulid, …]` (cada arquivo exatamente uma vez) →
  `DocumentIntake::reorder()` sob lock; evento `documents.reordered`. Erro em `document_order`.
- **Campos** (`envelopes.fields.sync`): cada campo aceita `document_id` (ULID; ausente = primeiro). Página, geometria e
  `pages_meta` são validados contra a versão exibível **daquele** documento. Erros novos: `fields.N.document_id`
  ("Este arquivo não pertence ao documento." / "O arquivo "X" ainda não foi processado…"), `fields.N.recipient_id`
  (visualizador), `fields.N.type` (assinatura/rubrica para aprovador). A rubrica automática vale por documento e só para
  quem assina (signatário/testemunha).
- **Destinatários** (`envelopes.recipients.sync`): `recipients.*.participant_role` ∈ `signer|witness|approver|viewer`
  (o `role` continua sendo o rótulo livre). Ausente: mantém o papel existente; linha nova é `signer`. Papel diferente
  de `signer` com a flag `participant_roles` desligada → erro em `recipients.N.participant_role` (um papel já gravado é
  preservado).
- **Prontidão** (`ready`): todos os arquivos `ready` com versão exibível; ≥ 1 `signer`; ordem coerente (sem
  visualizadores); todo `signer`/`witness` com ≥ 1 assinatura obrigatória em qualquer arquivo; `approver` sem
  assinatura/rubrica; `viewer` sem campo. Pendências novas (passo 4): `O arquivo "X" ainda está sendo processado.` (e
  equivalentes por arquivo), `Aprovador não recebe campo de assinatura ou rubrica: N.`, `Visualizador não recebe campos: N.`
  As mensagens da Fase 1 são as mesmas quando só há signatários e um arquivo.
- **Duplicar**: copia todos os arquivos na ordem, os papéis e os campos (cada campo vai para o arquivo correspondente).

## 5. Fluxo público

- `sign.document?document={ulid}` transmite a versão congelada daquele arquivo (sem parâmetro: o primeiro). Cada
  transmissão registra `signing_session_documents` (uma vez por sessão e arquivo) e `document.presented` (um evento por
  arquivo; payload ganha `document_ulid`/`position` com vários arquivos). `signing_sessions.document_presented_at` continua
  marcando a primeira entrega.
- **Aceite** (`sign.complete`): com vários arquivos exige TODOS entregues à sessão (`document_not_presented`: "Abra e
  confira todos os arquivos antes de assinar: o arquivo "X" ainda não foi carregado nesta sessão.") e os obrigatórios
  de todos os arquivos (`required_field_missing` no campo). Grava UM `signature_acceptances` (colunas de versão/hash =
  primeiro arquivo, `action` do papel) e uma linha `acceptance_documents` por arquivo. Snapshot `schema` 2 (lista
  ordenada de documentos, ação, campos com `document_ulid`) quando há vários arquivos ou papel ≠ `signer`; `schema` 1
  (Fase 1) nos demais casos. Aprovador: `signature` opcional e ignorada — nenhuma imagem é gravada.
- **Visualizador**: `identify` → código → `view`. `sign.complete` e `sign.refuse` recusam (409). Pode baixar a cópia
  final (`sign.download/signed?document=`) com sessão ou janela de download, como os signatários.
- **Conclusão**: `advance()` só considera quem participa; o visualizador nunca impede `finalizing`.

## 6. Finalização por documento

`EnvelopeFinalizer::handle()` escolhe o caminho pelo que existe no banco (`EnvelopeDocuments::sent()`):

- **um documento**: o pipeline da Fase 1, intacto; acrescenta só `verification_record_documents` (uma linha) e
  `documents.final_version_id`, gravado na mesma transação da conclusão.
- **vários documentos** (`handleMulti`):
    1. confere que TODOS os bytes congelados existem (falha rápida, sem trabalho parcial);
    2. **a.** consolida cada arquivo (reaproveitando o que existe no disco com o sha256 certo);
    3. **b.** gera a página de evidências de cada arquivo — ela lista todos os arquivos com seus resumos e quem aceitou
       cada um. Qualquer consolidação refeita nesta execução invalida todas as páginas; numa retomada, a marca
       `documents_digest` gravada no `envelope.evidence_generated` faz a mesma conferência;
    4. **c/d/e.** junta, assina (quando há certificado e o plano inclui) e valida o final de cada arquivo; grava
       `document.finalized` por arquivo;
    5. **f.** `verification_records` (colunas = primeiro arquivo) + uma linha em `verification_record_documents` por arquivo
       (reescrita só se algo mudou — tabela sem DELETE);
    6. **g.** sob lock, grava `documents.final_version_id` de todos e passa a `completed` (payload do
       `envelope.completed` ganha `documents: [{document_ulid, final_sha256}]`).
- Cada arquivo usa um diretório temporário próprio. `FinalizationOutcome::steps` usa chaves
  `documents.{n}.consolidated|evidence|final|signature` e `verification_documents` (`created`/`reused`/`written`).
- **Retomável por documento**: falha no arquivo 3 deixa os artefatos de 1 e 2; a retentativa os reaproveita (testado).
  O envelope fica em `finalizing` até todos os finais existirem.

## 7. Declarações de aceite (textos)

| combinação                 | versão (`terms_version`)                                |
| -------------------------- | ------------------------------------------------------- |
| signatário, 1 arquivo      | a do envelope (Fase 1, inalterada)                      |
| signatário, N arquivos     | `v1-multi-2026-09-11`                                   |
| testemunha, 1 / N arquivos | `v1-witness-2026-09-11` / `v1-witness-multi-2026-09-11` |
| aprovador, 1 / N arquivos  | `v1-approve-2026-09-11` / `v1-approve-multi-2026-09-11` |

Os textos estão em `ConsentText::variantStatement()` e `variantCheckboxLabel()`: mesma estrutura em 6 itens da
declaração da §3 de `docs/juridico/declaracao-de-aceite.md`; com N arquivos, o item 1 lista cada arquivo com o seu
SHA-256. A testemunha declara "na qualidade de testemunha … Esta declaração não me torna parte do documento nem expressa
concordância pessoal com as obrigações nele previstas"; o aprovador declara que aprova o conteúdo e que "Esta aprovação
não contém representação visual de assinatura e não me torna signatário(a) do documento".

> ⚠️ **As variantes de testemunha, aprovador e vários documentos foram escritas pela engenharia de forma conservadora e
> EXIGEM REVISÃO JURÍDICA antes de ligar as flags em produção.** Qualquer mudança de palavra exige nova versão.

## 8. Contrato de props para o front (tudo aditivo)

### 8.1 Wizard (`envelopes/wizard`)

| prop                                  | tipo                                                                                                                                                                                                                        | observação                                                               |
| ------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| `document`                            | igual à Fase 1 (primeiro arquivo) + `position`, `name`                                                                                                                                                                      |                                                                          |
| `documents`                           | `WizardDocument[]` — mesmo formato de `document` (`id`, `original_name`, `size_bytes`, `mime`, `source_type`, `processing`, `pdf_url`, `page_thumb_url_template`, `page_sizes`, `sha256`, `position`, `name`, `pages_meta`) | ordem de apresentação; `pdf_url` do 2º em diante traz `?document={ulid}` |
| `domain_features`                     | `{multi_document: boolean, participant_roles: boolean}`                                                                                                                                                                     | derivada de config + plano desta organização                             |
| `participant_roles`                   | `{value: 'signer'                                                                                                                                                                                                           | 'witness'                                                                | 'approver' | 'viewer', label: string}[]` | rótulos PT-BR                     |
| `limits.max_documents`                | `number`                                                                                                                                                                                                                    | 1 com a flag desligada                                                   |
| `recipients[].participant_role`       | `'signer'                                                                                                                                                                                                                   | 'witness'                                                                | 'approver' | 'viewer'`                   | `role` segue sendo o rótulo livre |
| `recipients[].participant_role_label` | `string`                                                                                                                                                                                                                    |                                                                          |
| `recipients[].order`                  | `number` — `0` para visualizador                                                                                                                                                                                            |                                                                          |
| `fields[].document_id`                | `string` (ULID do arquivo)                                                                                                                                                                                                  |                                                                          |

Requisições: `PUT recipients.sync` aceita `recipients[].participant_role`; `PUT fields.sync` aceita `fields[].document_id`;
`PATCH envelopes.update` aceita `document_order: string[]`; `POST document.store` acrescenta (flag ligada);
`DELETE document.destroy?document=`; `GET document.status?document=` (sem parâmetro devolve o primeiro e, com vários
arquivos, `documents: [{id, position, …processing}]`); `GET document.preview?document=`.

### 8.2 Detalhe (`envelopes/show`)

`envelope.documents[]`: `{id, position, name, original_name, processing_status, pages, size_bytes, sha256_original,
sha256_sent, sha256_final, pdf_url, downloads: {original, signed, evidence}}` (URLs com `?document=`).
`envelope.recipients_count` passa a contar **só quem participa**; novo `envelope.viewers_count`.
`recipients[]` ganham `participant_role`, `participant_role_label`, `acceptance_action` (`sign|witness|approve|null`);
`status_label` do aprovador assinado é "Aprovado". `fields[].document_id`.
Downloads: `envelopes.download/{type}?document={ulid}` (sem parâmetro: primeiro arquivo, como antes).

### 8.3 Página pública do participante (`sign/show`)

Novas chaves (presentes em todas as telas, inclusive `invalid`):

| prop                                                                                                            | tipo                                                                                                   | observação                                                                                                   |
| --------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------ |
| `screen`                                                                                                        | acrescenta `'view'`                                                                                    | visualizador depois do código                                                                                |
| `action`                                                                                                        | `{type: 'sign'                                                                                         | 'witness'                                                                                                    | 'approve'    | 'view', label, button_label: string | null, requires_signature: boolean, requires_consent: boolean}` | `null` em `invalid`; o botão usa `button_label` ("Assinar documento", "Assinar como testemunha", "Aprovar documento") |
| `documents`                                                                                                     | `{id, position, name, pages, pdf_url, page_sizes, sha256, presented: boolean}[]`                       | em `sign` e `view`; o front deve carregar TODOS (cada `pdf_url` marca a entrega) antes de habilitar o aceite |
| `copy`                                                                                                          | `{final_available, completed_at, can_download, downloads: {document_id, name, position, available, url | null}[], notice}`ou`null`                                                                                    | só em `view` |
| `recipient.participant_role` / `participant_role_label`                                                         |                                                                                                        |                                                                                                              |
| `others[].participant_role` / `participant_role_label`                                                          |                                                                                                        | visualizadores não aparecem em `others`                                                                      |
| `my_fields[].document_id` / `other_fields[].document_id`                                                        |                                                                                                        | campo de assinatura da testemunha vem com `label: 'Testemunha'` e placeholder próprio                        |
| `consent.version` / `checkbox_label` / `statement`                                                              | variantes da §7                                                                                        |                                                                                                              |
| `receipt.action`, `receipt.action_label`, `receipt.documents[]` (`{id, position, name, sha256, final_pdf_url}`) |                                                                                                        |                                                                                                              |

Aceite do aprovador: `POST sign.complete` com `authorization`, `consent`, `fields` — **sem** `signature`.

### 8.4 Evidências (`envelopes/evidence`)

`documents[]`: `{id, position, name, original_name, hashes: {original_sha256, sent_sha256, consolidated_sha256,
evidence_sha256, final_sha256}, accepted_by: {name, participant_role, action, action_label, accepted_at,
document_sha256}[], downloads: {signed, evidence}}`.
`recipients[]` ganham `participant_role`, `participant_role_label`, `acceptance_action`, `acceptance_action_label`,
`accepted_documents: {document_id, position, name, sha256}[]`.

### 8.5 Verificação pública (`verify/show`)

A página pública tem uma **lista fechada de chaves** (privacidade §11, fixada em `VerificationContractTest`): as
chaves novas só aparecem quando o recurso é usado.

- `result.documents[]`: `{position, name, pages, sent_sha256, final_sha256|null}` e `result.documents_count` —
  **presentes só quando o envelope tem mais de um arquivo**. Com um arquivo, a resposta é a da Fase 1.
- `result.recipients[]` exclui visualizadores; ganha `participant_role`/`participant_role_label` **só quando o envelope
  tem algum papel diferente de `signer`**. Aprovador assinado → `status_label: 'Aprovado'`; marco
  "Aprovação registrada · N***".
  `file_check` com vários arquivos ganha `document: {position, name}|null` — o resumo pode ser de qualquer arquivo.

## 9. Página de evidências (PDF)

Com vários arquivos, cada arquivo final traz a sua página, que acrescenta: "Arquivo desta página: n de N — nome", a
seção "Documentos deste envelope (N)" (resumos original/enviado/consolidado de cada um e quem registrou aceite) e a nota
de que os resumos da seção 4 são deste arquivo. Com papéis, cada participante mostra "Papel" e "Registro" (aprovação sem
representação visual); visualizadores aparecem numa linha própria ("Receberam cópia para acompanhamento, sem registrar
aceite"). Um envelope de um arquivo só com signatários gera exatamente a página da Fase 1.

## 10. Eventos de auditoria novos (`App\Enums\AuditEventType`)

| valor                 | rótulo                      | kind | payload                                                                                        |
| --------------------- | --------------------------- | ---- | ---------------------------------------------------------------------------------------------- |
| `documents.reordered` | Ordem dos arquivos alterada | info | `order: [ulid…]`                                                                               |
| `approval.recorded`   | Aprovação registrada        | ok   | igual a `acceptance.recorded` + `action`, `documents`                                          |
| `document.finalized`  | Arquivo final gerado        | ok   | `document_ulid`, `position`, `final_document_version_ulid`, `final_sha256`, `signature_status` |

Payloads aditivos (só com vários arquivos ou papel ≠ `signer`): `acceptance.recorded` (`action`, `documents[]`),
`document.uploaded` (`position`), `document.presented` (`document_ulid`, `position`), `envelope.consolidated` /
`envelope.evidence_generated` / `envelope.signed_company_a1` (`document_ulid`, `position`, `documents_digest`),
`envelope.completed` (`documents[]`), `recipient.refused` (`role`). Nenhum payload carrega segredo, token ou e-mail completo.
A linha do tempo da página de evidências inclui `approval.recorded` sempre que a lista configurada incluir
`acceptance.recorded`.

## 11. Regras de autorização para o agente de Policies

- Adicionar, remover e reordenar arquivos, e posicionar campos por arquivo: mesma regra de hoje — `update` do envelope
  (e só em `draft|preparing|ready`, reconferido sob lock no serviço).
- Pré-visualizar/baixar por arquivo (`?document=`): `view`/`download` do envelope; o ULID é sempre resolvido dentro do
  envelope autorizado (`EnvelopeDocuments::find`), nunca direto.
- Definir papel diferente de `signer`: `update` do envelope + flag `participant_roles` (verificada no serviço). Se a
  matriz de permissões dinâmica (§2.14) quiser uma permissão própria ("definir testemunhas/aprovadores"), o ponto é
  `RecipientSync::resolveRoles()`.
- O visualizador não tem Policy: acesso só pelo token + código (fluxo público); não registra aceite nem recusa.

## 12. Alterações mínimas fora do núcleo desta área (documentadas)

- `app/Services/Envelopes/Sending/InvitationDispatcher.php`: `pendingForCurrentTurn()` inclui visualizadores no
  sequencial; `isTheirTurn()` é verdadeiro para visualizador.
- `app/Services/Envelopes/Sending/CompletionNotifier.php`: a cópia final também vai para visualizadores ativos.
- `SendEnvelope` **não** foi alterado: o congelamento por documento acontece em `Envelope::saved`.

## 13. Pendências para outras áreas

1. `HandleInertiaRequests::features()` deve derivar `multi_document` de `DomainFeatures::multiDocument()` e acrescentar
   `participant_roles` (`tests/Feature/Organizations/SharedPropsTest.php` fixa a lista atual).
2. `config/assinavelox.php`: declarar `features.multi_document`, `features.participant_roles` (false) e
   `multi_document.max_documents` (10); acrescentar `acceptance_documents => no_update_no_delete`,
   `signing_session_documents => no_update_no_delete` e `verification_record_documents => no_delete` em
   `audit.evidence_tables` (e ajustar `AppendOnlyEvidenceTest`, que fixa o mapa); acrescentar `approval.recorded` a
   `evidence.timeline_events` (hoje entra automaticamente, ver §10).
3. `app/Notifications/Envelopes/RecipientInvitationNotification` e `EnvelopeCompletedNotification`: texto próprio para
   visualizador ("você recebeu uma cópia para acompanhamento"), aprovador ("aprovar") e testemunha. Hoje o texto é o do
   signatário.
4. Contagens de "pendentes" fora desta área ainda contam visualizadores: `EnvelopeNotifications::notifySenderSigned`
   (total), `RecipientController` (aba Assinaturas), `DashboardController`, `DailyDigest`. Devem filtrar
   `Recipient::participating()` ou o papel.
5. `OrganizationPurge::TABLES`: as três tabelas novas somem por cascata (FK), mas vale listá-las explicitamente.
6. Rotas: nenhuma rota nova foi necessária (parâmetro `document` em query string). Se o front preferir rotas por
   documento (`documentos/{envelope}/arquivos/{document}`), é uma alteração de `routes/web.php`.
7. Front (`resources/js/**`): consumir o contrato da §8; o PDF.js deve abrir cada `documents[].pdf_url` antes de
   liberar o aceite.

## 14. Testes

`tests/Feature/Phase2/Domain` (22 testes): ciclo HTTP completo com 3 arquivos e pdftool real
(`MultiDocumentLifecycleTest`), regras do aceite por documento (`MultiDocumentAcceptanceTest`), preparo e flag
(`MultiDocumentPreparationTest`), finalização por documento e retomada após falha no meio
(`MultiDocumentFinalizationTest`), papéis e ordem mista (`ParticipantRolesTest`), isolamento entre organizações
(`DomainIsolationTest`).

## 15. Limitações conhecidas

- As declarações de testemunha/aprovador/vários documentos aguardam revisão jurídica (§7).
- "Recusar um documento" recusa o envelope inteiro (política padrão do roadmap); não há recusa parcial por arquivo.
- O aprovador não tem etapa própria "antes de todos" imposta pelo sistema: ele entra na ordem que o remetente definir.
- Um único aceite cobre o conjunto; não há checkbox de aceite por arquivo na declaração (o registro por arquivo fica em
  `acceptance_documents`). Se o jurídico exigir aceite explícito por arquivo, é uma variante nova do texto e da tela.
- A página de evidências é uma por arquivo (anexada ao final de cada um), cada qual listando o conjunto.
- Links de download por e-mail na conclusão seguem o mecanismo existente do `CompletionNotifier` (não alterado aqui).
