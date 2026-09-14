# Fase 3, onda E — PAdES de longo prazo: B-T, B-LT, B-LTA e re-carimbo (§3.6)

> Área P3-LTV. Fontes, em ordem de precedência: `docs/design/RECONCILIACAO.md` → `docs/arquitetura.md` (§2) → `docs/roadmap.md` (T1–T10, §3.6) → `docs/fases-2-3-viabilidade.md` (R3, R5, §4.5 item 29, correção §6 item 9) → `docs/integracoes/carimbo-do-tempo-e-ltv.md` → `docs/fase-2/carimbo-e-dossie.md`.
> Data: 2026-09-11. Tudo nasce **desligado**. Com as flags desligadas nada muda: nenhuma rota, nenhum job agendado, nenhuma coluna lida pela interface.

## 1. Resumo

| Entrega                      | Situação                                                                                                                                                                                                                                         |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| B-T, B-LT e B-LTA no pdftool | `ltv-sign` em três etapas incrementais (assinatura + carimbo → DSS com CRL/OCSP e VRI → carimbo de documento), com **degradação explícita** etapa a etapa. Testado offline com uma PKI de TESTE (CRL e OCSP em arquivo, sem rede).               |
| Re-carimbo                   | `ltv-refresh`: valida o último carimbo no momento do re-carimbo, guarda o material de validação no DSS e acrescenta um carimbo de documento. Teste com **relógio avançado**: o arquivo continua válido depois de o 1º certificado da TSA vencer. |
| Validação                    | `ltv-validate`: nível **efetivamente presente** por assinatura, cobertura, DSS/VRI, cobertura de revogação, cadeia de carimbos e data do último carimbo. `SignaturePolicyIdentifier`: informa presença; conformidade `not_checked`.              |
| Estado técnico               | `verification_records.ltv_status` (`not_applicable` \| `b_t` \| `b_lt` \| `b_lta`) + data do último carimbo, revogação embutida, vencimento do carimbo de arquivamento e próximo re-carimbo. **Interno**: não é o perfil anunciado.              |
| `RefreshArchiveTimestamp`    | Job idempotente, serializado com o pipeline (mesmo lock do envelope), que re-carimba antes do vencimento do certificado da TSA; agendador `ScheduleArchiveTimestampRefreshes`.                                                                   |
| Histórico de hashes          | `verification_hash_history`: o resumo vigente e os anteriores, com as datas. Sem linhas (e sem mudança na resposta) enquanto houver um só resumo.                                                                                                |
| Perfil exibido               | Continua **PAdES-B-B** (T2). Só a flag separada `pades_ltv_advertise` — desligada, com checklist (§7) — permite outro. Nada é ICP-Brasil (T3).                                                                                                   |

## 2. Flags e condição de ativação

| Flag (`config/assinavelox.php` → `features`)    | Escopo     | Efeito                                                                                                                                                                 |
| ----------------------------------------------- | ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `pades_ltv` (`ASSINAVELOX_FEATURE_PADES_LTV`)   | plataforma | Exige `operator_tsa`. Libera `LtvSigner`, `ltv_status` e o re-carimbo. **Não** muda `signature_profile` nem a interface.                                               |
| `pades_ltv_advertise` (`…_PADES_LTV_ADVERTISE`) | plataforma | Exige `pades_ltv`. Única que faz `LtvProfilePolicy::displayProfile()` devolver `PAdES-B-T/B-LT/B-LTA`. Ligar só depois do checklist §7 registrado em `arquitetura.md`. |

Resolvedores: `App\Services\Ltv\LtvFeatures::{enabled,advertise}()`.

**O que falta para ligar `pades_ltv` em produção** (além do pré-requisito do roadmap: Fase 2 em produção por um ciclo de cobrança, API v1 congelada e matriz da Fase 2 verde):

1. TSA da operadora em produção (checklist `docs/fase-2/carimbo-e-dossie.md` §2.6: HSM/KMS, NTP monitorado, OID próprio, AC interna).
2. Material de revogação real: rede de saída do worker até as ACs (viabilidade §4.3 item 19) com `ASSINAVELOX_LTV_ALLOW_FETCHING=true` e `revocation_mode` `hard-fail`/`require`, **ou** CRL/OCSP coletados por processo operacional e entregues em `ASSINAVELOX_LTV_CRL_PATHS`/`…_OCSP_PATHS`. A busca na rede existe no código, mas **não é exercida pelos testes** (sem rede).
3. Integração na finalização (fora da área — §9): `OperatorSignature`/pipeline do participante chamam `LtvSigner` e gravam `LtvState::apply()`.
4. Decisão de produto do histórico de hashes (§6) sobre o texto exibido. A parte técnica foi feita na revisão adversarial I-3A: `PublicVerification::checkHash` consulta `VerificationHashHistory::match()` e responde `signed_previous`. Isso vale **desde `pades_ltv`** — é o re-carimbo que troca o arquivo final —, não só com `pades_ltv_advertise`: sem isso, o arquivo entregue na conclusão passaria a "não conferir".
5. `Schedule::job(new ScheduleArchiveTimestampRefreshes)->daily()` em `routes/console.php` e alerta sobre `ltv_refresh_failed`.

## 3. pdftool (`tools/pdftool/pdftool/ltv.py`)

Registrado de forma aditiva em `cli.py` (`add_ltv_commands`). `sign`, `validate` e `sign --tsa-*` (K-TSA) não mudaram.

### 3.1 `ltv-sign`

```text
ltv-sign --in <pdf> --out <pdf> --pfx <pfx> --pass-env <VAR> --level B-T|B-LT|B-LTA
         [--field-name] [--reason] [--location]
         --tsa-pfx <pfx> --tsa-pass-env <VAR> --tsa-serial N [--tsa-serial M] --tsa-policy-oid <oid> [--tsa-accuracy-ms]
       | --tsa-url <url> [--tsa-token-env <VAR>] [--tsa-timeout s]
         [--tsa-kind operator|commercial] [--trust <pem>]... [--crl <der|pem>]... [--ocsp <der>]...
         [--revocation-mode hard-fail|require] [--allow-fetching]
```

Etapas, cada uma uma **revisão incremental** (o arquivo anterior fica intacto byte a byte):

| Etapa | O que faz                                                                                    | Se falhar                                                                                                                  |
| ----- | -------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| 1     | assinatura PAdES (`ETSI.CAdES.detached`) com carimbo de assinatura (B-T)                     | TSA sem resposta ⇒ assina **sem** carimbo: **B-B**, `degradations[0] = {step: signature_timestamp, code: tsa_unavailable}` |
| 2     | DSS: certificados + CRL/OCSP de toda a cadeia do signatário **e** da TSA, entrada VRI (B-LT) | material ausente/revogado ⇒ fica **B-T**, `{step: validation_info}`; o serial do carimbo de documento **não** é gasto      |
| 3     | carimbo de documento (`DocTimeStamp`, B-LTA); o pyHanko atualiza o DSS com a cadeia dele     | ⇒ fica **B-LT**, `{step: document_timestamp}`                                                                              |

Uma etapa só é aceita depois de conferida (a etapa 2 roda `analyse` e exige cobertura de revogação completa); nunca `soft-fail` (R5). O serial da TSA da operadora é consumido **só** por token real; o "dummy" de estimativa de tamanho do pyHanko não gasta serial.

Saída (campos principais): `announced_profile: "PAdES-B-B"`, `profile: "PAdES-B-B"`, `announced: false`, `requested_level`, `effective_level`, `validated_level`, `degraded`, `degradations[]`, `icp_brasil: false`, `tsa_kind`, `timestamps[] {purpose: signature|document, serial, gen_time, policy_oid, tsa_subject, tsa_cert_not_after, label, announced: false}`, `serials_used`, `serials_unused`, `dss {present, vri_entries, certs, crls, ocsps}`, `last_timestamp_at`, `archive_timestamp {gen_time, tsa_cert_not_after, …}`, `revocation_embedded`, `signature_policy_identifier_present`, `sha256`.

T3: `--tsa-kind icp_brasil` ⇒ `icp_brasil_not_available` (exit 2). A TSA em processo (`--tsa-pfx`) é sempre `operator`. O tipo de uma TSA HTTP vem do chamador, nunca da resposta.

### 3.2 `ltv-refresh`

Mesmas opções de TSA e revogação. Recusa arquivo sem assinatura ou com assinatura inválida; recusa quando o certificado da TSA do último carimbo **já venceu** (`archive_timestamp_expired` — tarde demais; o job existe para chegar antes). Assinaturas B-T sem DSS ganham o DSS primeiro. Depois `update_archival_timestamp_chain`: valida o último carimbo **no momento do re-carimbo** com o material de revogação **novo** (entregue ou buscado — o DSS antigo não conta como prova de hoje) e acrescenta o carimbo. TSA sem resposta ⇒ `tsa_unavailable` (exit 3) e **nada é gravado**. Saída: `level_before`, `effective_level`, `document_timestamps_before/after`, `timestamp_chain_valid`, `timestamps[] {purpose: archive}`, `sha256_before`, `sha256`.

### 3.3 `ltv-validate`

`--in <pdf> --trust <pem>... [--tsa-trust] [--crl] [--ocsp] [--at ISO-8601]`. Offline: DSS embutido + material entregue. Cada carimbo de documento é validado no instante do **carimbo seguinte** (a prova de existência dele) e o último em `--at` (padrão: agora) — é isso que mantém válido um arquivo cujo primeiro certificado de TSA venceu. Por assinatura: `level` (`B-B`…`B-LTA`, ou `null` se íntegra/válida falhar), `intact`, `valid`, `trusted`, `coverage`, `modification_level`, `later_changes_lta_only`, `signature_timestamp {present, gen_time, trusted, tsa_subject}`, `vri_present`, `revocation {signer, signature_timestamp}` (`covered`, `missing`, `revoked`), `signature_policy_identifier_present`, `policy_conformance: "not_checked"`. Agregados: `effective_level` (o menor entre as assinaturas), `timestamp_chain_valid`, `dss`, `last_timestamp_at`, `archive_timestamp`.

Atenção: num arquivo B-LT/B-LTA o `validate` (B-B) diz `coverage = ENTIRE_REVISION` e `modification_level = LTA_UPDATES` — é o correto (há revisões depois da assinatura), e é por isso que quem integrar deve usar `ltv-validate` (`all_later_changes_lta_only`) para afirmar "nada além de material de longo prazo foi acrescentado".

### 3.4 `ltv-gen-test-pki`

AC raiz, assinante e TSA (EKU `timeStamping` crítica), todos EC P-256 com "TESTE" no CN, ponto de distribuição de CRL e OCSP em `.invalid` (nunca resolvem), mais `raiz-teste.crl`, `assinante-teste.ocsp` e `tsa-teste.ocsp`. `test_only: true`. Nunca para produção. A classe `TestPki` gera CRL/OCSP em qualquer data (usada no teste de relógio avançado).

## 4. Laravel

| Peça                                             | Papel                                                                                                                                                                                                                                                                           |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `App\Services\Ltv\LtvSigner`                     | `ltv-sign` atrás de `pades_ltv`. Reserva os seriais antes (1 para B-T/B-LT, 2 para B-LTA: `ltv_signature`, `ltv_document`), marca `granted` os usados e `failed/not_used` os que sobraram. Sem raízes de confiança pede B-T e registra. Devolve `declared_profile = PAdES-B-B`. |
| `LtvState`                                       | `apply()` grava `ltv_*` a partir do relatório; `view()` é a visão interna. Nunca toca em `signature_profile`/`signature_status`.                                                                                                                                                |
| `ArchiveTimestampRefresher`                      | Re-carimbo sob `EnvelopeSigningLock` (mesmo lock das assinaturas de participante e da finalização), só de envelope `completed` não revogado; nova `document_version kind=final`, ponteiros, histórico e estado numa transação curta, depois dos bytes.                          |
| `App\Jobs\Ltv\RefreshArchiveTimestamp`           | Payload: ids + versões finais vistas no despacho. `ShouldBeUnique` por registro, `WithoutOverlapping(envelope:{id}:sign)`, 3 tentativas (60 s, 10 min, 1 h). Lock ocupado ⇒ volta à fila.                                                                                       |
| `App\Jobs\Ltv\ScheduleArchiveTimestampRefreshes` | Despacha o re-carimbo dos registros com camada de arquivamento (`b_lta` ou `ltv_archive_expires_at` preenchido, §6.1) e `ltv_next_refresh_at` vencido (lote `ltv.refresh.batch_size`).                                                                                          |
| `LtvProfilePolicy`                               | Perfil exibido e checklist (§7).                                                                                                                                                                                                                                                |
| `VerificationHashHistory`                        | Histórico de resumos (§6).                                                                                                                                                                                                                                                      |

**Idempotência do re-carimbo:** só é renovado o documento cuja versão final vigente ainda é uma das vistas no despacho; `ltv_operations.idempotency_key = refresh:{registro}:{posição}:{versão de origem}` é a segunda barreira. Uma falha deixa a operação `failed` com `error_code` e `attempts`; a retentativa reaproveita a linha. Cada tentativa reserva o próprio serial, e um serial nunca volta.

**Próximo re-carimbo:** `ltv_archive_expires_at` (vencimento do certificado da TSA do último carimbo de documento) − `ltv.refresh.margin_days` (30). B-T e B-LT não são agendados automaticamente (não têm carimbo de arquivamento); o `ltv-refresh` aceita esses arquivos se alguém despachar o job.

**Segredos:** as senhas (certificado e TSA) só por nome de variável, injetadas pelo `TsaToolRunner`; nunca em argv, log, exceção, fila, `ltv_operations` ou resposta. O servidor nunca recebe chave: tudo é arquivo PKCS#12 por referência, como no K-TSA.

### 4.1 Configuração (`config/assinavelox.php` → `ltv`)

| Chave (env)                                                                                                  | Uso                                                                                     |
| ------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------- |
| `level` (`ASSINAVELOX_LTV_LEVEL`, padrão `B-LTA`)                                                            | nível pedido ao pdftool                                                                 |
| `trust_roots` (`…_LTV_TRUST_ROOTS`)                                                                          | raízes para validar antes de embutir; vazio = `pdftool.trust_roots` + `tsa.trust_roots` |
| `crl_paths`, `ocsp_paths` (`…_LTV_CRL_PATHS`, `…_LTV_OCSP_PATHS`)                                            | material de revogação entregue por arquivo                                              |
| `allow_fetching` (`…_LTV_ALLOW_FETCHING`, padrão `false`)                                                    | produção: buscar CRL/OCSP na rede                                                       |
| `revocation_mode` (`…_LTV_REVOCATION_MODE`)                                                                  | `hard-fail` (padrão) ou `require`; qualquer outro valor vale `hard-fail`                |
| `timeout_seconds`, `refresh.margin_days`, `refresh.batch_size`, `refresh.queue`, `refresh.lock_wait_seconds` | tetos e agenda                                                                          |

## 5. Estado técnico × perfil anunciado

`ltv_status` responde "o que há no arquivo"; `signature_profile` continua respondendo "o que a plataforma afirma". Com `pades_ltv_advertise` desligada, a verificação pública, a página de evidências, os webhooks e o dossiê continuam dizendo **PAdES-B-B** (há teste). Mesmo com o anúncio ligado, `displayProfile()` nunca produz "ICP-Brasil": o carimbo é "Carimbo do tempo da operadora — não é carimbo ICP-Brasil" (T3), e o provedor ICP continua o simulador que nunca grava `icp_brasil`.

## 6. Histórico de hashes — decisão de produto pendente (viabilidade §4.5 item 29)

**O problema:** o re-carimbo acrescenta uma revisão ao PDF; o conteúdo do documento não muda, mas o SHA-256 do arquivo sim. Quem baixou o arquivo antes do re-carimbo e confere o resumo na página pública receberia "não confere".

**O que foi implementado (aditivo):** `verification_hash_history` guarda, por registro e posição do documento, cada resumo final com `valid_from` e `superseded_at` e o motivo (`finalized` | `ltv_refresh`). O resumo **vigente** continua em `verification_records.final_sha256` (e em `verification_record_documents`). Nenhuma linha existe antes do primeiro re-carimbo, e `VerificationHashHistory::publicProps()` devolve `[]` nesse caso — a resposta atual **não muda** enquanto houver um único resumo (há teste).

**O que o produto precisa decidir:**

1. Se a página pública aceita um resumo anterior como "confere (versão anterior do mesmo arquivo final, substituída em {data} por um novo carimbo de arquivamento)". Recomendação técnica: sim — o arquivo anterior continua sendo uma assinatura válida do mesmo documento; negar criaria falso "não confere".
2. Se o histórico é exibido na página pública (as chaves `hash_history`/`hash_history_notice`) ou só na página de evidências.
3. Se o dossiê inclui as versões anteriores (hoje ele já lista todas as versões guardadas).

Integração **feita** (revisão adversarial I-3A): em `PublicVerification::checkHash`, quando o resultado seria `none`, `VerificationHashHistory::match($record, $sha)` é consultado e a resposta é `matches = 'signed_previous'` com as datas (e o documento, com vários). O front (`file-check.tsx`) já mostrava "Confere com uma versão anterior do arquivo final… o conteúdo do documento não mudou". Continua pendente a decisão de produto 1–3 acima sobre o texto e a exibição do histórico.

### 6.1 Nível do arquivo × camada de arquivamento (revisão adversarial I-3A)

`ltv_status` é o nível técnico **do arquivo inteiro**: no `ltv-sign`, `validated_level` (análise final); no `ltv-refresh`, `effective_level` (o menor nível entre as assinaturas). Um arquivo com assinaturas de participantes sem carimbo nem revogação próprios (A1, componente local, devolução do portal) é **B-B** e fica `not_applicable`, mesmo com a camada de arquivamento da operadora. `LtvProfilePolicy::displayProfile()` lê só `ltv_status`, então o perfil exibido nunca passa do nível efetivo do arquivo, nem com `pades_ltv_advertise` ligada (T2). A **camada de arquivamento** é um fato à parte: `ltv_archive_expires_at` e `ltv_next_refresh_at` vêm dela (`LtvState::archiveLayer()`), e o agendador renova os registros com camada vencida, qualquer que seja o nível. "Revogação embutida" só é afirmada quando o nível do arquivo é B-LT ou acima. A visão interna traz `archive_layer` e, nesse caso, o rótulo "Carimbo de arquivamento da operadora sobre o arquivo; …o nível do arquivo continua PAdES-B-B".

## 7. Checklist para ligar `pades_ltv_advertise` (T2) — `LtvProfilePolicy::checklist()`

1. **`pdftool ltv-validate` com fixtures reais** (A1 real, TSA de produção, CRL/OCSP reais das ACs) — hoje só com PKI de TESTE (_parcial_).
2. **Validação externa independente** das fixtures B-T, B-LT e B-LTA, inclusive depois de um re-carimbo: DSS da Comissão Europeia com política customizada que confie na AC interna da TSA, e leitor PDF de referência; resultado registrado por release (_pendente_).
3. **ACT ICP-Brasil**, se o anúncio for mencionar ICP-Brasil: só com ACT credenciada contratada (viabilidade §4.2 item 7) (_bloqueado_). Sem ela o anúncio, se houver, é "carimbo do tempo da operadora — não é ICP-Brasil".
4. **Política de assinatura e VRI conferidos**: para AD-RT/AD-RA, `SignaturePolicyIdentifier` da versão vigente na LPA embutido (via `CAdESSignedAttrSpec`, ainda não implementado) e aprovado no Verificador de Conformidade do ITI; VRI presente (o pdftool grava e o `ltv-validate` informa, mas o pyHanko **não** confere a política — correção §6 item 9) (_pendente_).
5. **Verificação pública aceitando o histórico de hashes** (§6) (_cumprido tecnicamente na revisão I-3A; falta a decisão de produto sobre o texto_).
6. **Operação de produção**: TSA de produção, rede de saída com `hard-fail`/`require`, agendamento e alerta do re-carimbo (_pendente_).
7. **Decisão registrada em `arquitetura.md`** (_pendente_).

## 8. Contrato para o front (`resources/js` fora da área)

Nada disto aparece enquanto `pades_ltv` estiver desligada. **Não anunciar perfil**: exibir `announced_profile` como veio (hoje sempre `PAdES-B-B`) e nunca derivar um perfil de `status`/`level`.

- **Página autenticada de evidências** (controller de outra área): `ltv = LtvState::view($record)` →
  `{status: 'not_applicable'|'b_t'|'b_lt'|'b_lta', label, level: 'B-T'|'B-LT'|'B-LTA'|null, last_timestamp_at, revocation_embedded, archive_expires_at, next_refresh_at, checked_at, tsa_kind: 'operator', announced: boolean, announced_profile, notice}`.
  Sugestão de UI: bloco "Material de longo prazo (estado técnico)" com `label`, "último carimbo em {last_timestamp_at}", "próxima renovação em {next_refresh_at}" e o `notice` sempre visível. Não usar as palavras "B-LTA", "longo prazo garantido" ou "ICP-Brasil" como selo.
- **Histórico de hashes** (evidências e, se o produto decidir, verificação pública): `VerificationHashHistory::publicProps($record)` → `[]` ou `{hash_history: [{position, sha256, current, valid_from, superseded_at, reason: 'finalized'|'ltv_refresh', reason_label}], hash_history_notice}`. Exibir a lista só quando a chave existir; marcar o `current`; mostrar `hash_history_notice`.
- **Conferência de resumo** (depois da integração do §6): novo valor `matches = 'signed_previous'` com `valid_from`/`superseded_at` — texto sugerido: "Confere com uma versão anterior do arquivo final (substituída em {data} por um novo carimbo do tempo de arquivamento; o conteúdo do documento não mudou)".
- **Features compartilhadas** (`HandleInertiaRequests::features()`, fora da área): `pades_ltv` e `pades_ltv_advertise` de `LtvFeatures`.

## 9. Banco (migrations aditivas, MySQL-compatíveis)

| Migration                                                         | Tabela                      | Notas                                                                                                                                                                                                        |
| ----------------------------------------------------------------- | --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `2026_09_11_150201_add_ltv_columns_to_verification_records_table` | `verification_records`      | `ltv_status` (padrão `not_applicable`), `ltv_last_timestamp_at`, `ltv_revocation_embedded`, `ltv_archive_expires_at`, `ltv_next_refresh_at`, `ltv_checked_at`; índice (`ltv_status`, `ltv_next_refresh_at`). |
| `2026_09_11_150202_create_verification_hash_history_table`        | `verification_hash_history` | FK em cascata com o registro (a retenção que apaga o registro leva o histórico).                                                                                                                             |
| `2026_09_11_150203_create_ltv_operations_table`                   | `ltv_operations`            | UNIQUE `idempotency_key`; degradações e seriais em JSON; sem segredo.                                                                                                                                        |

Modelos em `App\Services\Ltv\Models` (a área não inclui `app/Models`); `VerificationRecord` não foi alterado — as colunas novas são lidas por `getAttribute()` e gravadas por `forceFill()`.

## 10. Testes

- **pytest** (`tools/pdftool/tests/test_ltv.py`, 17; a suíte inteira do pdftool tem 182, os 139 anteriores inalterados): B-T (carimbo embutido, perfil anunciado B-B, confiança só com a CRL entregue); B-LT com CRL e com OCSP (DSS, VRI, cobertura de revogação, serial não usado informado); B-LTA (carimbo de documento, arquivo de arquivamento, `validate` honesto sobre as revisões); degradação sem revogação (→ B-T) e com signatário revogado; TSA que não responde (porta fechada em 127.0.0.1 → B-B registrado); **re-carimbo com relógio avançado** (nova TSA e CRL em T0+9d; validação em T0+30d com o 1º certificado da TSA vencido: o arquivo renovado continua B-LTA, o original cai para B-LT); recusa depois do vencimento; re-carimbo com TSA inacessível não grava nada; B-LT → B-LTA por re-carimbo; `icp_brasil` recusado; TSA em processo sempre `operator`; `soft-fail` indisponível; senha fora de stdout/stderr; PKI de teste rotulada.
- **Pest** (`tests/Feature/Phase3/Ltv`, 19: 9 de flags/estado, 4 de assinatura, 6 de re-carimbo): flags desligadas por padrão e dependências entre elas; perfil exibido B-B para qualquer estado com o anúncio desligado e nunca "ICP"; assinador recusa sem gastar serial; registros existentes `not_applicable`; estado técnico não mexe no perfil; job e agendador inertes sem a flag; agendador só despacha `b_lta` vencido; assinatura B-LTA real (seriais, operação, `ltv-validate`, nada ICP); degradação sem CRL e sem raízes; senha errada sem vazar; re-carimbo real (nova versão, ponteiros, hash, bytes anteriores preservados, histórico, verificação pública ainda B-B); idempotência; serialização pelo lock do envelope; TSA não configurada; falha explícita sem revogação nova e retentativa na mesma operação; envelope revogado ignorado.

## 11. Pendências e limitações

**De integração (fora da área P3-LTV):** chamar `LtvSigner` na finalização e no pipeline do participante e gravar `LtvState::apply()`; usar `ltv-validate` para a conferência de arquivos LT/LTA; `PublicVerification::checkHash` + `publicProps()` (§6); `LtvState::view()` na página de evidências; `LtvProfilePolicy::displayProfile()` em `SignatureNarrative`/webhooks/dossiê; `HandleInertiaRequests::features()`; agendamento em `routes/console.php`; eventos próprios de auditoria (`document.ltv_refreshed`) no `AuditEventType`; README do pdftool com os comandos `ltv-*`; retenção apagando `ltv_operations` do envelope expurgado.

**Retomada (2026-09-14):** o trabalho foi retomado depois do checkpoint `wip(fase 3, parte 1)`. Único defeito encontrado: dois textos de `LtvProfilePolicy` punham "ICP-Brasil" junto de carimbo/TSA sem negação na mesma oração (reprovados pelo `VocabularyTest`, T1/T3); foram reescritos como negação clara, sem mudar o sentido. As falhas vistas antes no `RefreshArchiveTimestampTest` não se reproduziram sobre o código do checkpoint (19/19 verdes isolados e na suíte completa).

**Integração I-3A (2026-09-14):**

- **Defeito corrigido em `LtvState::apply()`**: no relatório do `ltv-refresh`, `effective_level` é o menor nível entre as
  assinaturas do arquivo; num arquivo com assinaturas de participantes sem carimbo próprio (A1, componente local,
  devolução do portal) ele é B-B. O estado caía para `not_applicable`, apagava `ltv_next_refresh_at` e o arquivo nunca
  mais era renovado. Agora, com cadeia de carimbos de documento válida e carimbo de arquivamento presente, o estado
  continua `b_lta` (os rótulos de `LtvStatus` descrevem a camada da operadora). Teste: `tests/Feature/Phase3/Ltv/LtvStateRefreshTest.php`;
  encontrado pelo ponta a ponta `tests/Feature/EndToEnd/Phase3PartOneTest.php`, que também mostra que o `ltv-validate`
  do arquivo continua dizendo B-B (as assinaturas dos participantes não ganham carimbo).
- **Evidências**: `EnvelopeEvidenceController` envia `ltv` (`LtvState::view`) e o histórico de resumos
  (`VerificationHashHistory::publicProps`) só com `pades_ltv` ligada. A verificação pública e o `checkHash` NÃO foram
  alterados — a decisão de produto do §6 continua pendente.
- **Flags**: `pades_ltv` e `pades_ltv_advertise` entraram em `HandleInertiaRequests::features()` (desligadas).
- **Continua pendente**: a finalização ainda não chama o `LtvSigner` (o ponta a ponta aplica o B-LTA como a integração
  aplicaria), o agendamento em `routes/console.php` e os eventos próprios de auditoria.

**Limitações conhecidas:** revogação só por arquivo nos testes (a busca na rede não foi exercida); `SignaturePolicyIdentifier` não é embutido (nenhuma política ICP-Brasil é reivindicada); o estado `ltv_*` é por registro — num envelope com vários documentos reflete o último documento renovado; o re-carimbo exige material de revogação **novo** para o certificado da TSA anterior (o DSS antigo não serve de prova de hoje), então sem CRL/OCSP atual ele falha de forma explícita; a TSA de produção é a da operadora (arquivo PKCS#12, sem HSM).
