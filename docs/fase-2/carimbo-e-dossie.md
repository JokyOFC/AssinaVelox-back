# Fase 2, onda C — carimbo do tempo da operadora (RFC 3161) e dossiê ZIP (§2.13)

> Área K-TSA. Fontes, em ordem de precedência: `docs/design/RECONCILIACAO.md` → `docs/arquitetura.md` (§2, §5) → `docs/roadmap.md` (T1–T10, §2.13, §3.6) → `docs/fases-2-3-viabilidade.md` (R3, R5, correção §6 item 4) → `docs/integracoes/carimbo-do-tempo-e-ltv.md`.
> Data: 2026-09-11. Tudo nasce **desligado**. Com as flags desligadas nada muda no comportamento existente.

## 1. Resumo

| Entrega                                         | Situação                                                                                                                                                                                |
| ----------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| TSA RFC 3161 da operadora (`tsa_kind=operator`) | Implementada no pdftool (`tsa-issue`, `tsa-verify`, `tsa-gen-test`) + serviço Laravel + endpoint HTTP interno. Validada por pyHanko **e** `openssl ts -verify` nos testes.              |
| Número de série                                 | Sequência do banco (`operator_tsa_issuances`), única, nunca reutilizada; teste de corrida com 4 processos.                                                                              |
| Provedores                                      | `OperatorTimestampProvider` (operator); `IcpBrasilTimestampProvider` (contrato, produção **desabilitada**) + `FakeIcpBrasilTimestampProvider` (simulador que nunca grava `icp_brasil`). |
| Carimbo do manifesto do dossiê                  | Com `operator_tsa`: `carimbo/manifesto.tsr` sobre o SHA-256 do `manifest.json`.                                                                                                         |
| B-T no `pdftool sign`                           | Código pronto atrás de `pades_bt`; **o perfil anunciado continua `PAdES-B-B`**. Não está ligado à finalização (§4.2).                                                                   |
| Dossiê ZIP                                      | Job assíncrono, idempotente por (envelope, versão final), link assinado com expiração, arquivo apagado no prazo, "Baixar" em lote (Q12).                                                |

### 1.1 Flags (`config/assinavelox.php` → `features`)

| Flag             | Escopo                                            | Efeito                                                                                                   |
| ---------------- | ------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `operator_tsa`   | plataforma (só a chave global)                    | liga a TSA da operadora: endpoint `POST /tsa`, carimbo do manifesto, provedor `operator`.                |
| `pades_bt`       | plataforma; exige `operator_tsa`                  | permite `PadesBtSigner` (assinatura com carimbo embutido). Não muda `signature_profile` nem a interface. |
| `dossier_export` | organização (chave global **E** `plans.features`) | liga as rotas do dossiê. Desligada: 404.                                                                 |

Resolvedores: `App\Services\Timestamp\TimestampFeatures::{operatorTsa,padesBt}()` e `App\Services\Dossier\DossierFeature::enabled($org)`. **Pendência de integração:** expor as três em `HandleInertiaRequests::features()` (fora da área).

## 2. TSA da operadora

### 2.1 O que ela prova — e o que não prova

"A AssinaVelox, operadora da plataforma, atesta com a chave da própria TSA que este resumo existia no horário indicado." **Não é carimbo ICP-Brasil**: pelo DOC-ICP-11 §2.7.2 só SCT de ACT credenciada, auditado pela EAT, produz carimbo aceito na ICP-Brasil. Rótulo único, em todo lugar (banco, props, README do dossiê, `carimbo.json`, saída do pdftool): **"Carimbo do tempo da operadora — não é carimbo ICP-Brasil"** (`TsaKind::OPERATOR_LABEL`).

### 2.2 pdftool

| Comando        | Entrada                                                                                                                                                                                        | Saída / regras                                                                                                                                                                                                                                                                                                                                                                                    |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tsa-issue`    | `--tsa-pfx`, `--pass-env` (NOME da variável), `--policy-oid`, `--serial N` (ou `--serial-file`), `--request <tsq>` **ou** `--digest <hex> --hash-alg`, `--accuracy-ms`, `--out`, `--token-out` | `TimeStampResp` DER. `TSTInfo` com política configurável, serial dado pelo chamador (nunca aleatório), `genTime` UTC do servidor com milissegundos, `accuracy`, `nonce` ecoado, `tsa` = nome do certificado. `SignedData` v3 com `SigningCertificateV2` (ESSCertIDv2 SHA-256, RFC 5816), RSA-PKCS#1 v1.5 ou ECDSA P-256/384 com SHA-256. `certReq=true` → certificado incluso; `false` → ausente. |
| (recusas)      | versão ≠ 1, hash fora de {sha256, sha384, sha512}, tamanho do resumo errado, `reqPolicy` diferente, extensões, DER inválido                                                                    | Resposta RFC 3161 `rejection` com `failInfo` (`bad_request`, `bad_alg`, `bad_data_format`, `unaccepted_policy`, `unaccepted_extensions`), sem token, exit 0.                                                                                                                                                                                                                                      |
| (certificado)  | —                                                                                                                                                                                              | Recusa emitir se o certificado não tiver **exatamente uma** EKU `id-kp-timeStamping` **crítica** (RFC 3161 §2.3), estiver fora da validade, com chave RSA < 2048 ou curva não aceita (`invalid_tsa_certificate`, exit 4).                                                                                                                                                                         |
| `tsa-verify`   | `--token` (`.tsr` ou token), `--digest` **ou** `--data`, `--trust` (raízes), `--tsa-cert`, `--nonce`, `--policy-oid`                                                                           | `valid` = concedido ∧ resumo confere ∧ assinatura íntegra e válida (pyHanko `validate_tst_signed_data`, independente do código de emissão) ∧ EKU ok ∧ ESSCertID confere ∧ política/nonce quando pedidos. `trusted` só com raiz configurada; sem raiz → `no_trust_roots_configured`. Revogação: `not_checked`.                                                                                     |
| `tsa-gen-test` | `--out-pfx`, `--pass-env`, `--out-root-pem`, `--out-chain-pem`, `--key rsa-3072                                                                                                                | ec-p256`                                                                                                                                                                                                                                                                                                                                                                                          | AC interna de TESTE + certificado da TSA (EKU timeStamping crítica, KU digitalSignature+nonRepudiation). CN sempre com "TESTE". `test_only: true`. |
| `sign --tsa-*` | `--tsa-pfx`, `--tsa-pass-env`, `--tsa-serial`, `--tsa-policy-oid`, `--tsa-accuracy-ms`                                                                                                         | Carimbo de assinatura (B-T técnico) emitido em processo pela TSA da operadora. `"profile"` continua `PAdES-B-B`, `"timestamp"` continua `null` (contrato do PHP); os fatos saem em `signature_timestamp` com `announced: false`. O "dummy" de estimativa de tamanho do pyHanko **não** consome serial (assinatura zerada, nunca embutida).                                                        |

`asn1crypto.tsp.TimeStampResp` declara o token como obrigatório; o pdftool usa `TimeStampResponse` próprio com o token **opcional**, como a RFC define (senão uma rejeição não pode ser codificada).

### 2.3 Número de série

`OperatorTsaSerials::reserve()` insere uma linha em `operator_tsa_issuances` numa transação curta; o serial é `serial_offset + id`. Não repete em corrida porque o id autoincremental é atribuído pelo banco sob o próprio lock (InnoDB auto-inc; SQLite `AUTOINCREMENT`) — nada de "MAX + 1". Nenhuma linha é apagada: um serial de emissão que falhou fica `failed`/`rejected` e nunca volta. `serial` é `UNIQUE` como segunda barreira. O pdftool recebe o serial por argumento e o grava no `TSTInfo` exatamente.

A transação fecha **antes** da chamada ao pdftool (regra da onda C).

### 2.4 Configuração (`config/assinavelox.php` → `tsa`)

| Chave (env)                                                                            | Uso                                                                                          |
| -------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| `pfx_path` (`ASSINAVELOX_TSA_PFX_PATH`)                                                | PKCS#12 da TSA (chave + certificado + cadeia), por referência de arquivo.                    |
| `password_env` (`ASSINAVELOX_TSA_PASSWORD_ENV`)                                        | **Nome** da variável com a senha (padrão `ASSINAVELOX_TSA_PASSWORD`). Valor nunca em config. |
| `chain_pem`, `trust_roots`                                                             | Cadeia pública (vai no dossiê) e raízes para `tsa-verify`.                                   |
| `environment`                                                                          | `test` (padrão) ou `production`.                                                             |
| `policy_oid`                                                                           | Padrão: OID de **exemplo** da ITU-T X.667 (arco 2.25) — só teste; `tsa:status` avisa.        |
| `accuracy_ms`, `serial_offset`, `timeout_seconds`                                      | Precisão declarada, deslocamento da sequência, teto do processo.                             |
| `http.token_env`, `http.allowed_ips`, `http.max_request_bytes`, `http.rate_per_minute` | Endpoint interno.                                                                            |
| `icp_brasil.driver`                                                                    | `disabled` (padrão) ou `fake`.                                                               |

Comandos: `php artisan tsa:generate-test [--dir --pass-env --key --days --force]` (recusa em `production`, exige a senha já no ambiente, nunca a imprime; imprime as linhas do `.env`) e `php artisan tsa:status [--json]` (flags, o que falta, contagem de emissões, checklist — sem segredo).

### 2.5 Endpoint HTTP interno

`POST /tsa` (`tsa.timestamp`), fora de CSRF. Ordem das barreiras: flag → 404; IP fora de `allowed_ips` (CIDR aceito) → 403; token Bearer ausente/errado → 401 (`hash_equals`; valor só na variável nomeada em `http.token_env`; sem ela, 503 — fechado); `Content-Type ≠ application/timestamp-query` → 415; corpo vazio ou > limite → 413; `throttle:{rate},1,tsa-http`. Pedido recusado pela TSA volta como **rejeição RFC 3161 com HTTP 200** (é o que a RFC manda). Resposta: `application/timestamp-reply`, `Cache-Control: no-store`, `X-TSA-Kind: operator`.

### 2.6 Checklist de produção (pendência do proprietário)

1. Chave da TSA em **HSM/KMS** antes de clientes pagantes (arquivo `0400` do usuário do serviço até lá). O pdftool hoje só lê PKCS#12 — HSM exigirá um assinador PKCS#11/KMS no lugar de `_sign()` em `tsa.py`.
2. **NTP monitorado**, com a TSA respondendo `timeNotAvailable` quando o desvio passar da `accuracy` declarada. O software **não mede o relógio**; esse controle é da infraestrutura (e é por isso que a flag nasce desligada).
3. **OID de política próprio** (arco da operadora, ex.: PEN IANA; processo NÃO CONFIRMADO).
4. **AC interna** da operadora emitindo o certificado da TSA com EKU `timeStamping` crítica; publicar a raiz para quem confere os dossiês.
5. Texto dos Termos/Política com o rótulo "carimbo do tempo da operadora — não é carimbo ICP-Brasil" (revisão jurídica).

## 3. Provedores (`App\Integrations\Timestamp`)

| Classe                           | `tsa_kind`  | Produção                                                                                                                                                                                                  |
| -------------------------------- | ----------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `OperatorTimestampProvider`      | `operator`  | Disponível com `operator_tsa` + configuração completa. Token RFC 3161 de verdade (`simulated=false`).                                                                                                     |
| `IcpBrasilTimestampProvider`     | —           | **Desabilitada**: `isConfigured()` sempre false; `timestamp()` lança `ProviderDisabledException` com o que falta (contrato com ACT, homologação do formato, preço, raiz fixada). Viabilidade §4.2 item 7. |
| `FakeIcpBrasilTimestampProvider` | `simulated` | Só fora de produção (`channels.allow_simulated`). Nunca `icp_brasil`.                                                                                                                                     |
| `IcpBrasilTimestampFactory`      | —           | `tsa.icp_brasil.driver`: `disabled` \| `fake`; outro valor = erro explícito.                                                                                                                              |

T3 é imposta no **único ponto de gravação**, `TimestampTokens::assertKindAllowed()`: resultado simulado só grava `simulated`; `icp_brasil` só de um `IcpBrasilTimestampProvider` configurado e não simulado — hoje impossível. O binding padrão de `TimestampProvider` no container continua o simulador (o `IntegrationsServiceProvider` está fora da área); quem precisa do provedor da operadora injeta a classe.

## 4. Uso do carimbo

### 4.1 Manifesto do dossiê

Com `operator_tsa`, a montagem carimba `sha256(manifest.json)` e grava `carimbo/manifesto.tsr` (TimeStampResp), `carimbo/carimbo.json` (rótulo, serial, genTime, política, resultado da conferência automática, comando de conferência) e `carimbo/cadeia-tsa.pem`. O carimbo fica **fora** do manifesto por construção (é emitido depois dele); o manifesto lista isso em `outside_manifest`. A linha vai para `timestamp_tokens` (`purpose=dossier_manifest`, com o resultado de `tsa-verify` em `verification`). TSA indisponível ⇒ o dossiê sai **sem** carimbo, com `carimbo/INDISPONIVEL.txt` e `timestamp_status=unavailable` — degradação explícita, nunca silenciosa (R5).

Conferência independente: `openssl ts -verify -data manifest.json -in carimbo/manifesto.tsr -CAfile <raiz-da-AC-interna.pem>` (coberto por teste quando o OpenSSL está no PATH).

### 4.2 B-T atrás de `pades_bt` — o que falta para anunciar

`PadesBtSigner::sign()` reserva o serial, chama `pdftool sign --tsa-*` com as duas senhas injetadas por nome e devolve `declared_profile = 'PAdES-B-B'` sempre. **Não está ligado à finalização** (`OperatorSignature` e o pipeline do participante são de outras áreas): com a flag ligada, a finalização continua B-B puro — há teste que garante que o `signature_profile` exibido não muda. Para anunciar `PAdES-B-T` (`PadesProfilePolicy::checklist()`):

1. `pdftool validate` com fixtures **reais** (A1 real + TSA de produção) — hoje só com certificados de teste;
2. **validação externa independente**: DSS da Comissão Europeia (política customizada confiando na AC interna) e leitor PDF de referência, registrada por release. O Verificador do ITI deve responder Indeterminado para carimbo `operator` (âncora não ICP-Brasil) — não é o critério;
3. TSA de produção (§2.6);
4. integração na finalização com degradação explícita para B-B registrada;
5. decisão registrada em `arquitetura.md`. Só então `signature_profile` pode receber `PAdES-B-T`.

## 5. Dossiê ZIP

### 5.1 Conteúdo (e só isto)

| Caminho                          | O que identifica                                                                                                                                                                                                                                                         |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `manifest.json`                  | formato `assinavelox-dossier/1`; envelope, organização, política de IP/e-mail, participantes, documentos e versões, SHA-256 + tamanho + descrição de **cada** arquivo abaixo, resumos publicados na verificação, `never_included`.                                       |
| `documentos/NN-nome/vK-tipo.ext` | cada versão guardada de cada documento (`original`, `convertido`, `consolidado`, `evidencias`, `final` e revisões futuras, como as do K-A1). `matches_record` compara os bytes com o `sha256` do banco; arquivo ausente vira `status: missing` (nunca some em silêncio). |
| `trilha/auditoria.json` / `.csv` | trilha append-only do envelope até a montagem; CSV com BOM, `;` e `Csv::row()` contra fórmula.                                                                                                                                                                           |
| `validacao.json`                 | `validation_result` gravado na conclusão + revalidação no pdftool do final assinado (`dossier.revalidate_signatures`) + carimbos de assinatura existentes, sempre com perfil anunciado B-B.                                                                              |
| `LEIA-ME.txt`                    | como conferir resumos (sha256sum/certutil) e o carimbo; perfil; o que nunca entra.                                                                                                                                                                                       |
| `carimbo/*`                      | §4.1, só com `operator_tsa`.                                                                                                                                                                                                                                             |

**Nunca**: tokens de convite/sessão/API, OTP ou seus resumos, PIN, senhas, PFX, chaves, segredos de webhook, caminhos internos do disco, imagens de assinatura e fotos de captura. Payloads da trilha passam por `DossierRedaction` (chaves suspeitas → `[omitido no dossiê]`). IP segue `evidence_show_ip` (`IpDisplay`); e-mail acompanha a mesma política (`full` = por extenso; `masked`/`none` = mascarado). Teste varre todo o conteúdo do ZIP atrás de segredos plantados no banco, no payload e no ambiente.

**Reprodutível**: o manifesto não tem data de montagem; entradas com mtime fixo (conclusão do envelope); mesma trilha ⇒ mesmo `manifest.json` byte a byte (teste).

### 5.2 Fluxo, idempotência e expiração

1. `POST documentos/{envelope}/dossie` → `DossierExports::requestSingle()`. Chave de idempotência: envelope + versões finais de cada documento + formato + política de exibição + carimbo ligado. Mesmo pedido pronto ⇒ devolve o mesmo (link novo); em preparação ⇒ não redispara; expirado/falho/travado (> 30 min) ⇒ o **mesmo registro** é remontado. Corrida de duas requisições ⇒ índice único, a segunda relê.
2. `BuildDossierExport` (fila `dossier.queue`): `ShouldBeUnique` + `WithoutOverlapping` por pedido, 3 tentativas, só o id no payload, nenhuma transação aberta durante pdftool/TSA/ZIP, bytes primeiro e linha `ready` depois. Erro de negócio (`DossierException`) falha sem repetir.
3. `GET dossies/{id}` → JSON de status com `download_url` (URL assinada pela APP_KEY, vence em `expires_at`).
4. `GET dossies/{id}/baixar` → exige sessão na organização, permissão **agora** sobre cada envelope (no lote, ser quem pediu), assinatura correta (senão 403) e dentro do prazo (senão **410** e o arquivo é apagado na hora). Grava `envelope.downloaded` com `type=dossier` em cada envelope.
5. Depois de `dossier.ttl_hours` (24 h) o arquivo é apagado: `PurgeExpiredDossierExports` é despachado com atraso por cada montagem e pode rodar a qualquer momento (só toca no que venceu). A linha fica `expired` com `purged_at`.

"Baixar" em lote (Q12): `POST dossies/lote` com `ids[]` (máx. `dossier.max_bulk_envelopes`, 50). Visibilidade aplicada no pedido (`EnvelopeVisibility` + policy `download`) e **de novo** na montagem; o ZIP externo tem um `AV-xxxxx.zip` por envelope (cada um é um dossiê completo), `indice.json` (SHA-256 de cada dossiê e `skipped` com motivo) e `LEIA-ME.txt`.

Só envelopes **concluídos** geram dossiê (recusado/expirado/cancelado: decisão de produto pendente).

## 6. Segredos e concorrência (regras da onda)

- Senha do PKCS#12 da TSA e do certificado da operadora: só pelo **nome** da variável, injetada no processo filho por `TsaToolRunner` (ambiente mínimo do `ProcessEnvironment`); nunca em argv, log, exceção, fila, banco ou ZIP. Testes conferem log, exceção, saída de comandos e ZIP.
- Token do endpoint HTTP: só na variável de ambiente; comparação em tempo constante.
- Nenhuma transação aberta durante chamadas ao pdftool; serial reservado em transação curta antes.
- O dossiê só **lê** artefatos; não grava revisão de PDF (não concorre com a assinatura serializada do K-A1).

## 7. Contrato para o front (rotas e props — `resources/js` fora da área)

| Rota (nome)               | Método/URL                                       | Resposta                                                                                                                                                     |
| ------------------------- | ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `envelopes.dossier.store` | `POST /documentos/{envelope}/dossie`             | JSON (`Accept: application/json`): `202/200 {export}`; 422 `{message, code}`. Inertia: `back()` com flash `info`/`success`/`error` e `flash.dossier_export`. |
| `dossiers.bulk`           | `POST /dossies/lote` `{ids: string[]}`           | idem.                                                                                                                                                        |
| `dossiers.show`           | `GET /dossies/{export}`                          | `{export}` para polling.                                                                                                                                     |
| `dossiers.download`       | `GET /dossies/{export}/baixar?expires&signature` | ZIP (`attachment`). Use exatamente o `download_url` recebido.                                                                                                |
| `tsa.timestamp`           | `POST /tsa`                                      | interno, não é do front.                                                                                                                                     |

`export` = `{id, kind: 'single'|'bulk', status: 'pending'|'building'|'ready'|'failed'|'expired', status_label, envelope_count, size_bytes, sha256, timestamp_status: 'granted'|'disabled'|'unavailable'|'not_applicable'|null, timestamp_label, error, expires_at, download_url, status_url}`.

Sugestão de UI: no detalhe do envelope concluído, botão "Baixar dossiê (ZIP)" (flag `dossier_export`); no `envelopes.index`, a ação "Baixar" do lote (Q12) chama `dossiers.bulk`; ambos fazem polling em `status_url` a cada 2–3 s até `ready`/`failed`.

Carimbos (para as páginas de evidências e verificação, cujos controllers são de outras áreas — pendência de integração):

- **evidências**: `timestamps = TimestampEvidence::forEnvelope($envelope)` → `{items: [{id, purpose, purpose_label, tsa_kind, label, statement, gen_time, serial, policy_oid, tsa_subject, tsa_cert_fingerprint, hash_algorithm, imprint, accuracy_ms, environment, is_test, test_notice, verification}], notice}`;
- **verificação pública**: `timestamps = TimestampEvidence::forPublic($envelope)` → `[{tsa_kind, label, purpose_label, gen_time, is_test}]` (sem impressão digital nem ids). Acrescentar a chave ao conjunto fechado de `VerificationContractTest`.
- Sempre exibir `label` como veio; `is_test` exige o `test_notice`. Nunca exibir "ICP-Brasil" para `operator`.

## 8. Banco (migrations aditivas, MySQL-compatíveis)

| Migration                                               | Tabela                   | Notas                                                                               |
| ------------------------------------------------------- | ------------------------ | ----------------------------------------------------------------------------------- |
| `2026_09_11_130101_create_operator_tsa_issuances_table` | `operator_tsa_issuances` | livro de emissões/sequência de seriais; `serial` UNIQUE; nunca apagar.              |
| `2026_09_11_130102_create_timestamp_tokens_table`       | `timestamp_tokens`       | metadados públicos; DER no disco privado; UNIQUE(`tsa_cert_fingerprint`, `serial`). |
| `2026_09_11_130103_create_dossier_exports_table`        | `dossier_exports`        | UNIQUE(`organization_id`, `idempotency_key`); `purged_at`.                          |

Os modelos moram em `App\Services\Timestamp\Models` e `App\Services\Dossier\Models` (a área não inclui `app/Models`); a integração pode movê-los sem mudar as tabelas. **Retenção (K-RET):** ao purgar um envelope, apagar também os `.tsr` de `timestamp_tokens` e os ZIPs de `dossier_exports` (roadmap §2.19 "Propagação"). `operator_tsa_issuances` não tem dado de envelope e **não** deve ser apagado (é a sequência).

## 9. Testes

- pytest (`tools/pdftool/tests/test_tsa.py`, `test_tsa_sign.py`): token emitido valida (pyHanko e `openssl ts -verify`), resumo diferente não valida, token adulterado inválido, raiz errada/sem raiz nunca `trusted`, rejeições RFC 3161 com `failInfo`, `certReq`, nonce, serial exato e obrigatório, serial por arquivo único sob 4 processos, certificado sem EKU crítica recusado, senha nunca na saída, B-T embutido com perfil B-B, assinatura seguinte preserva a carimbada, um serial = um token real.
- Pest (`tests/Feature/Phase2/Timestamp`, `tests/Feature/Phase2/Dossier`): provedor operator verificável; rótulo operador e nunca ICP-Brasil; flag desligada não gasta serial; ICP-Brasil desabilitado e simulador nunca grava `icp_brasil`; senha fora de log/exceção; seriais crescentes, sem reuso, UNIQUE e **corrida real entre 4 processos** num SQLite em arquivo; endpoint HTTP (404/401/403/415/413/503, resposta verificável, rejeição 200); B-T atrás de flag com perfil B-B e finalização inalterada; comandos; dossiê com exatamente os arquivos esperados e manifesto conferindo com os bytes e com o banco; CSV contra fórmula; política de IP/e-mail; varredura de segredos; idempotência e reprodutibilidade; lote e visibilidade; outra organização 404; membro sem acesso; link adulterado 403; link expirado 410 com arquivo apagado; limpeza; remontagem após expirar; carimbo do manifesto verificável (pyHanko + OpenSSL) e degradação sem TSA.

## 10. Pendências

**Do proprietário:** checklist §2.6 (HSM/KMS, NTP monitorado, OID próprio, AC interna, texto jurídico); contrato com ACT para o ICP-Brasil (viabilidade §4.2 item 7); validação externa independente para anunciar B-T (§4.2); decisão sobre dossiê de envelope recusado/expirado/cancelado.

**De integração (fora da área K-TSA):** `HandleInertiaRequests::features()` com `operator_tsa`, `pades_bt`, `dossier_export`; props de carimbo em `EnvelopeEvidenceController` e `PublicVerification` (§7); botão "Baixar dossiê" e "Baixar" em lote no front + `wayfinder:generate`; `Schedule::job(new PurgeExpiredDossierExports)->hourly()` em `routes/console.php`; eventos próprios de auditoria (`dossier.requested`, `timestamp.issued`) no `AuditEventType` — hoje o download usa `envelope.downloaded` com `type=dossier`; ligar `PadesBtSigner` na finalização quando o checklist §4.2 permitir; propagação da retenção (§8); mover os modelos para `app/Models` se desejado.

## 11. Limitações conhecidas

- TSA baseada em arquivo PKCS#12; sem HSM, sem medição de NTP, sem `timeNotAvailable` automático.
- Revogação (CRL/OCSP) do certificado da TSA não é verificada (sem rede, como todo o pdftool).
- `tsa-verify` confia só nas raízes passadas; o certificado da TSA de teste é autoassinado pela AC interna de teste.
- O endpoint HTTP é síncrono (um processo Python por pedido) — adequado ao uso interno, não a volume público.
- O ZIP inteiro é montado em disco temporário; teto `dossier.max_mb` (500 MB).
- A revalidação no dossiê usa `pdftool.trust_roots`; sem raízes, `trusted=false` com motivo.
