# Fase 3, onda E — assinatura externa por componente local A3 (§3.4, P3-EXT)

> Roadmap §3.4 e §1 (T1–T10); viabilidade §1.2 (§3.4 = **A** no lado servidor + **B** no componente), §3.2 (onda E),
> riscos R3, R4, R10, correção §6 item 1; `integracoes/a3-componente-local.md` (brief inteiro); `fase-2/a1-do-participante.md`
> (pipeline incremental serializado que esta entrega reaproveita). Identificadores em inglês, texto em português.
> Flag **`a3_signing`**, nova, **desligada** por padrão.

## 1. Resumo

O participante que tem certificado em token ou cartão (A3, chave NÃO exportável) assina o PDF com apoio de um componente
instalado na máquina dele. O servidor **nunca recebe a chave**: prepara a revisão pendente e um digest, o componente
assina, o servidor incorpora a assinatura e valida o arquivo inteiro.

| Entrega                                                     | Onde                                                                                    | Classe |
| ----------------------------------------------------------- | --------------------------------------------------------------------------------------- | ------ |
| `pdftool prepare-external` / `embed-external`               | `tools/pdftool/pdftool/external.py` (+ registro aditivo em `cli.py`)                    | A      |
| Reserva de revisão com TTL, consumo único, lock do envelope | `pending_external_signatures`, `app/Services/Signing/External/ExternalSignatureService` | A      |
| Encaixe na finalização (status por meio)                    | `ParticipantSignatureStage::statusForDocument()` + 1 linha em `EnvelopeFinalizer`       | A      |
| Validação de cadeia com âncoras fixadas                     | `ExternalTrustAnchors` + `--trust` no pdftool                                           | A      |
| Contrato `LocalSignerBridge` + DTOs                         | `app/Integrations/LocalSigner/**`                                                       | B      |
| `FakeLocalSigner` (simulador, só teste/local)               | `app/Integrations/LocalSigner/FakeLocalSigner.php`                                      | B      |
| `NexuLocalSigner` (documentado, produção DESABILITADA)      | `app/Integrations/LocalSigner/NexuLocalSigner.php`                                      | B      |
| Rotas JSON do participante                                  | `app/Http/Controllers/Sign/External{Signature,Simulator}Controller.php`                 | —      |
| Varredura de reservas                                       | `app/Jobs/Envelopes/PurgeExternalSignatureReservations.php`                             | —      |

**Com a flag desligada nada muda**: as rotas novas respondem 404, nenhum pedido ou reserva pode ser criado, e a finalização
só calcula um status diferente quando existe assinatura com `participant_signatures.signature_status` preenchido — o que
só este fluxo grava (testado: `ExternalSigningSecurityTest`, "com a flag desligada nada muda").

A flag vale quando as **duas** fontes dizem sim: `assinavelox.external_signing.enabled` (`ASSINAVELOX_FEATURE_A3_SIGNING`)
**e** `plans.features.a3_signing` (`ExternalSigningFeature::enabledFor()`). O `PlanSeeder` não foi alterado.

## 2. Semântica (T1, T2, T3) — o que é afirmado e o que não é

Cada meio tem valor e rótulo próprios. Por assinatura (`participant_signatures.signature_status`, coluna nova):

| valor                  | quando                                                                                                                                | rótulo                                                                                        |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| _nulo_                 | A1 por arquivo (Fase 2 §2.12) — linhas existentes                                                                                     | o da Fase 2                                                                                   |
| `participant_a3`       | componente **real** habilitado (`producesTokenSignatures()`, não simulado) **e** o certificado declara política ICP-Brasil do tipo A3 | "Assinatura com certificado A3 (token ou cartão) por componente local"                        |
| `participant_external` | qualquer outro caso: origem em token não comprovada — **inclusive tudo o que o simulador produz**                                     | "Assinatura com certificado do participante feita fora da plataforma, por componente externo" |

No envelope (`verification_records.signature_status`, enum `SignatureStatus`, valores novos): `participant_a3` (todas as
assinaturas externas são A3) ou `participant_external` (ao menos uma não é). O arquivo pode conter também assinaturas A1
de outros participantes e, por último, a da operadora (`certificate_reference_id`); a narrativa diz as três coisas.

- **Simulador**: sempre `participant_external` + `is_simulated = true`, rotulado **"simulado — nenhum token foi usado"** na
  resposta da preparação, no estado, nas evidências, na verificação pública e no rótulo de status. Nunca A3.
- **A3 real**: hoje **não é produzido** — o único componente real (NexU) está com a produção desabilitada. A regra existe e
  é testada com um dublê identificado de componente habilitado.
- **ICP-Brasil nunca é afirmado.** O que se mostra é "o certificado **declara** política ICP-Brasil (do tipo A3)" — declaração
  do próprio certificado, cadeia não validada até a ICP-Brasil. `participant_icp_brasil` (roadmap §3.4) **não** foi criado:
  exige âncoras do ITI + LCR/OCSP, que esta versão não consulta.
- **Cadeia**: sem âncora fixada e validada, "Cadeia de certificação não verificada"; com âncora válida, "validada até uma
  âncora configurada e fixada por impressão digital. Revogação não verificada." (§9).
- **Revogação**: sempre `not_checked`, dito explicitamente.
- **Perfil**: só `PAdES-B-B` (T2). Sem carimbo do tempo, sem LTV. O B-B sem `SignaturePolicyIdentifier` provavelmente não é
  aprovado como AD-RB no Verificador do ITI (R3) — não se chama de AD-RB.
- **Certificado de teste**: sempre "Certificado de TESTE — não é ICP-Brasil e não tem validade jurídica"; recusado em produção
  por padrão (`accept_test_certificates`).
- A assinatura com certificado **se soma** ao aceite eletrônico e não o substitui; recusa ou falha **não desfaz aceite**.

## 3. Fluxo e ordem

```
aceites (paralelos) ─► envelope em finalizing
                          │ a. consolidação                        — Fase 1
                          │ b. evidências                          — Fase 1 (+ bloco "participant_mode", Fase 2)
                          │ c. append ─► pre_signature (BASE CONGELADA)
                          │
                          │  participante (componente local):
                          │    intent ─► show (ready_to_sign) ─► componente devolve certificado
                          │    prepare(doc)  ── sob o lock do envelope: reserva a revisão MAIS RECENTE,
                          │                     pdftool prepare-external ─► revisão pendente + digest (TTL 10 min)
                          │    componente assina o digest (navegador ↔ 127.0.0.1; servidor não vê a chave)
                          │    submit(sig|cms) ─ consumo único; sob o lock: base ainda é a mais recente?
                          │                     pdftool embed-external (valida TUDO) ─► compare-and-set
                          │                     ─► signed_incremental + participant_signature
                          │ d. (A1 e externas, uma de cada vez, na ordem de chegada)
                          │ e. operadora POR ÚLTIMO ─► final
                          │ f. validação de TODAS as assinaturas (IncrementalChain) + verification_records
                          ▼
                      completed
```

A mesma sequência do §2.12: cada revisão é atualização incremental (os bytes da anterior são prefixo exato, conferido
no pdftool), assinatura de **aprovação** (nunca certificação), `document_version` nova `signed_incremental`. Multi-documento:
um pedido assina todos os documentos, um `prepare`/`submit` por documento; o pedido fica `applied` quando o último entra.
Com o pedido completo, a finalização é retomada (`FinalizeEnvelope`, fora do lock).

Prazo do pedido: o do A1 (`participant_a1.application_window_minutes`, padrão 72 h, aplicado por
`ParticipantSignatureStage::awaiting()` a todo pedido pendente). Vencido, o envelope conclui sem a assinatura.

## 4. pdftool

Sem rede, JSON único em stdout, exit codes 2/3/4. Argv só com caminhos: digest, assinatura e CMS vão por arquivo.

### `prepare-external --in --out --state-out --cert [--chain]… --field-name [--reason] [--location] [--trust]… [--bytes-reserved 16384] [--rsa-pss] [--expect-fingerprint]`

Sobre a revisão atual (depois da base e das assinaturas anteriores): recusa entrada corrompida/criptografada, campo já
existente (`field_name_taken`) e assinaturas anteriores quebradas (`previous_signature_invalid`); confere o certificado
(`certificate_expired`, `certificate_not_yet_valid`, `certificate_not_for_signing`, `certificate_is_ca`,
`unsupported_key_algorithm`, `certificate_invalid`); roda `PdfSigner.async_digest_doc_for_signing` com `ExternalSigner`
(placeholder) e `ExternalSigner.signed_attrs(..., use_pades=True)`; confere que a entrada é prefixo exato da revisão
pendente e que o `/ByteRange` bate com o resumo. Devolve:

- `digest_to_sign_hex` — SHA-256 do DER dos **atributos assinados** (modo `raw`: o que NexU `/v1/sign` ou Lacuna
  `signHash` assinam, sem novo hash);
- `document_digest_hex` — SHA-256 sobre o `/ByteRange` (modo `cms`: o que um serviço que devolve CMS pronto assina);
- `certificate` (fatos públicos, `icp_brasil.declared_certificate_type`, `test_certificate`), `chain_trust` (validação
  offline contra `--trust`, revogação `not_checked`), `base_sha256`, `pending_sha256`, `previous_signature_count`.

**Estado mínimo, sem segredo** (`--state-out`, JSON): `format`, `field_name`, `md_algorithm`, `signature_mechanism`,
`prefer_pss`, `document_digest_hex`, `reserved_region_start/end`, `bytes_reserved`, `signed_attrs_der_b64`,
`signed_attrs_digest_hex`, `signer_cert_fingerprint_sha256`, `chain_fingerprints_sha256`, `base_size`, `base_sha256`,
`pending_size`, `pending_sha256`, `previous_signature_count`. Nenhuma chave, senha, PIN ou `keyHandle`. (O brief marcava
como NÃO CONFIRMADO o formato de serialização entre processos; o spike confirmou: `PreparedByteRangeDigest` + DER dos
atributos assinados + o PDF pendente bastam — `PreparedByteRangeDigest.fill_with_cms()` fecha a revisão.)

### `embed-external --pending --state --out ((--signature --cert [--chain]…) | --cms) [--trust]… [--expect-fingerprint]`

Aceita os dois modos (correção §6 item 1 da viabilidade):

- **(a) assinatura bruta + certificado + cadeia** (NexU, Lacuna): o certificado precisa ser o anunciado
  (`certificate_mismatch`); a assinatura é verificada contra o DER dos atributos assinados com a chave pública do
  certificado — RSA PKCS#1 v1.5, RSA-PSS ou ECDSA (`signature_invalid`); o CMS é montado com
  `ExternalSigner.async_sign_prescribed_attributes`;
- **(b) CMS/PKCS#7 pronto** (DER, PEM ou Base64): SignedData destacado com um signatário; o certificado do signatário
  (dentro do CMS) precisa ser o anunciado; `message-digest` precisa ser o resumo desta revisão (`digest_mismatch` = outra
  revisão); `content-type` = data; a assinatura sobre os atributos é verificada (`signature_invalid`); precisa caber no
  espaço reservado (`cms_too_large`). Atributos do PAdES baseline (ETSI EN 319 142-1 §6.3; revisão adversarial I-3A):
  o ESS **signing-certificate-v2** (ou v1, SHA-1) é obrigatório e precisa apontar o certificado anunciado
  (`cms_invalid` sem ele; `certificate_mismatch` se aponta outro), e **signing-time** é recusado (`cms_invalid`: no PAdES a
  hora declarada vai no `/M`). Sem isso o CMS seria gravado e anunciado como PAdES-B-B sem sê-lo (T2). No PHP,
  `ExternalSignatureService` grava o perfil devolvido pelo pdftool e recusa a assinatura se ele não vier — não há mais
  perfil padrão assumido.

Componentes indisponíveis (revisão adversarial I-3A): sem nenhum componente capaz de assinar (simulador desligado, NexU
com a produção desabilitada), `can_request` e `can_prepare` são falsos e `POST intent` responde 409
`component_unavailable` — antes, a intenção era registrada e segurava a finalização até a janela vencer (3 dias) por uma
assinatura impossível.

Antes: o PDF pendente precisa ser o descrito pelo estado (tamanho, sha256 e `/ByteRange`; senão `pending_mismatch`) e o
estado precisa ser consistente (`state_invalid`). Depois: a base continua prefixo exato; **todas** as assinaturas são
validadas (`validate_pdf` + `analyse_chain`: todas íntegras e válidas, a nova cobrindo o arquivo inteiro, as anteriores só
com alterações permitidas, contagem exata, a mais nova é a esperada); qualquer falha apaga a saída
(`revision_chain_broken`, exit 3).

Testes: `tools/pdftool/tests/test_external.py` (12) — bruta e CMS funcionam (inclusive Base64 e ECDSA); digest de outra
revisão, assinatura adulterada (bruta e CMS), certificado diferente do anunciado (bruto, CMS e na preparação), PDF
pendente ou estado adulterados são recusados; A1 + externa + operadora formam cadeia sã; estado sem segredo; contrato de
subprocesso (uma linha JSON).

## 5. Modelo de dados (migrations aditivas, MySQL 8)

- `2026_09_11_150001_create_pending_external_signatures_table` — uma linha por preparação: participante, documento, pedido,
  `base_document_version_id` + `base_sha256`, `field_name`, `status` (`PendingExternalSignatureStatus`: `pending`,
  `embedding`, `applied`, `rejected`, `expired`, `superseded`, `discarded`), `mode` (`raw`|`cms`), `component`,
  `is_simulated`, `digest_hex` (oculto na serialização), `state_sha256`, `pending_sha256`, `pending_size`, fatos públicos do
  certificado (CPF só mascarado), `chain_trust`, **`reservation_key` ÚNICO** (= `document_id` enquanto ativa), `expires_at`,
  `submitted_at`, `consumed_at`, `closed_at`, `attempts`, `failure_code/message`, `signed_document_version_id`.
- `2026_09_11_150002_add_signature_method_to_participant_signature_requests_table` — `signature_method` (nulo = A1;
  `local_component`) e `signing_component`. Um participante continua com um pedido por envelope: A1 **ou** componente
  (`other_method_chosen`, 409).
- `2026_09_11_150003_add_external_columns_to_participant_signatures_table` — `signature_status`, `signing_component`,
  `is_simulated`, `pending_external_signature_id`.

Os arquivos da reserva (`pending.pdf` e `state.json`) ficam em `external_signing.pending_path/{ulid}` (0700, fora de
`public/`), vivem no máximo o TTL e são apagados ao consumir, expirar, substituir ou descartar.

Enums novos: `ExternalSignatureKind`, `PendingExternalSignatureStatus`, `LocalSignerComponent`; `SignatureStatus` ganhou
`participant_a3` e `participant_external` (+ `hasExternalParticipantSignatures()`). **Eventos de auditoria: nenhum tipo
novo** — reaproveitados os do §2.12 com `method = local_component` no payload (o catálogo de `EnumCatalogTest` não muda):
`participant_certificate.requested`/`withdrawn`, `participant_certificate.submitted` (na preparação: componente,
`simulated`, modo, reserva, documento, impressão digital, emissor, série, teste, cadeia, prazo — **sem digest**),
`participant_certificate.rejected` (`error_code`), `participant_signature.applied` (+ `signature_status`, componente,
`simulated`, modo), `participant_signature.expired` (`pending_expired` / `embed_interrupted`).

## 6. Concorrência — sem revisões irmãs (roadmap §2.12; R10)

1. **Lock de cache por envelope** `envelope:{id}:sign` (`EnvelopeSigningLock`, o MESMO do A1 e da finalização) em `prepare`
   e em `submit`, com espera curta (`lock_wait_seconds`, 10 s). Ocupado: `409 busy` — nada gravado, reserva não consumida.
2. **Uma reserva ativa por documento**: `reservation_key` único no banco. Outro participante recebe `409 document_reserved`
   com `retry_after`; o mesmo participante que prepara de novo **substitui** a anterior (`superseded`).
3. **Consumo único**: `update … set status = embedding where status = pending and expires_at > now` — uma vez só. Reutilizar
   = `409 already_consumed`; vencido = `409 expired`. Toda recusa depois disso consome a reserva (`rejected`).
4. **Compare-and-set** na gravação (`IncrementalRevisions::storeSigned()`, `lockForUpdate` no documento): a revisão só entra
   se a base usada ainda for a mais recente. O A1 (§2.12) não conhece reservas: se ele gravar durante a janela do token, a
   assinatura externa é recusada com `409 stale_revision` ("prepare de novo") — testado.
5. **`participant_signatures.base_document_version_id` único** (Fase 2): o banco recusa duas assinaturas sobre a mesma base.

Nenhuma transação de banco fica aberta durante o pdftool. A retomada da finalização acontece **fora** do lock.

## 7. Contrato para o front (sem mudança em `resources/js` nesta área)

Grupo público `assinar/{token}` (`throttle:signer` + `signer`), JSON, **404** com a flag desligada, papel sem assinatura
(aprovador/visualizador) ou envelope que não recebe mais. Autenticação como no A1: sessão do código **ou** janela de
download deste navegador (quem já aceitou). Sem ela: `403 {code: "not_authenticated"}`. Rodar
`php artisan wayfinder:generate --with-form` na integração.

| Rota                                  | Método e caminho                      | Corpo                                                                                                                        | Resposta               |
| ------------------------------------- | ------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- | ---------------------- |
| `sign.external.show`                  | `GET assinar/{token}/externa`         | —                                                                                                                            | `200` estado           |
| `sign.external.intent`                | `POST …/externa/intencao`             | `component?`                                                                                                                 | `201` estado           |
| `sign.external.withdraw`              | `POST …/externa/desistir`             | —                                                                                                                            | `200` estado           |
| `sign.external.prepare`               | `POST …/externa/preparar`             | `document_id` (ULID), `component` (`simulated`\|`nexu`), `mode` (`raw`\|`cms`), `certificate` (Base64 DER ou PEM), `chain[]` | `201 {pending, state}` |
| `sign.external.submit`                | `POST …/externa/assinatura`           | `pending_id`, `mode`; `raw`: `signature` (Base64), `certificate`, `chain[]`, `signature_algorithm?`; `cms`: `cms`            | `200` estado           |
| `sign.external.simulator.certificate` | `GET …/externa/simulador/certificado` | — (só teste/local)                                                                                                           | `200` certificado      |
| `sign.external.simulator.sign`        | `POST …/externa/simulador/assinar`    | `pending_id` (só teste/local; o digest nunca vem do cliente)                                                                 | `200` estado           |

Limites (por IP): `show` 60/min; `intencao`/`desistir` 20/10 min; `preparar`/`assinatura` 30/10 min; simulador 30/10 min.

**Estado** (`show`, `intent`, `withdraw`, `submit`, simulador):

```ts
type ExternalSigningState = {
    available: true;
    method: 'local_component';
    authenticated: boolean;
    stage:
        | 'choose'
        | 'awaiting_others'
        | 'ready_to_sign'
        | 'applied'
        | 'expired'
        | 'withdrawn'
        | 'closed'
        | 'other_method';
    ready: boolean;
    message: string | null;
    can_request: boolean;
    can_withdraw: boolean;
    can_prepare: boolean;
    request: null | {
        id: string;
        status: string;
        status_label: string;
        method: 'local_component';
        component: 'simulated' | 'nexu' | null;
        signature_status: 'participant_a3' | 'participant_external' | null;
        kind_label: string | null;
        label: string | null; // com "simulado — nenhum token foi usado" quando for o caso
        documents_signed: number;
        applied_at: string | null;
        window_expires_at: string | null;
        failure: null | { code: string; message: string };
    };
    documents: Array<{
        id: string;
        name: string;
        position: number;
        status: 'to_sign' | 'reserved' | 'busy' | 'waiting_base' | 'signed';
        signed_at: string | null;
        retry_after: string | null;
        pending: null | PendingProps; // a reserva ATIVA deste participante (digest: null aqui)
    }>;
    components: Array<{
        component: string;
        label: string;
        available: boolean;
        simulated: boolean;
        production_enabled: boolean;
        version: string | null;
        reason: string | null;
    }>;
    local_component: {
        // protocolo do NexU (produção desabilitada)
        component: 'nexu';
        production_enabled: false;
        http_base: string;
        https_base: string;
        minimum_version: string;
        endpoints: {
            status;
            signing_certificate;
            sign: {
                method: 'POST';
                path: '/v1/sign';
                hash_function: 'SHA256';
                mode: 'raw';
            };
        };
        browser_notes: string[];
        missing_for_production: string[];
    };
    chain: {
        anchors_pinned: number;
        label: string;
        revocation: 'not_checked';
        revocation_label: string;
    };
    limits: {
        pending_ttl_minutes: number;
        hash_function: 'SHA256';
        modes: ['raw', 'cms'];
        max_signature_bytes: number;
        max_certificate_kb: number;
        max_chain_certificates: number;
        max_cms_kb: number;
    };
    notices: string[];
    endpoints: {
        show;
        intent;
        withdraw;
        prepare;
        submit: string;
        simulator_certificate: string | null;
        simulator_sign: string | null;
    };
};

type PendingProps = {
    id: string;
    document_id: string;
    mode: 'raw' | 'cms';
    component: string;
    simulated: boolean;
    hash_function: 'SHA256';
    digest: string | null; // Base64 do digest — SÓ na resposta de `prepare`
    digest_kind: 'signed_attributes' | 'document';
    expires_at: string;
    certificate: {
        holder_name;
        holder_cpf_masked;
        issuer_cn;
        serial;
        fingerprint_sha256;
        valid_from;
        valid_to;
        is_test: boolean;
        kind_label: string;
    };
    chain: {
        trusted: boolean;
        label: string;
        revocation: 'not_checked';
        revocation_label: string;
    };
    endpoints: { submit: string | null; simulate: string | null };
};
```

**Sequência com o componente real** (quando for habilitado; hoje `nexu` responde `component_unavailable`):
`LocalSignerBridge.detect()` (`GET /v1/status`) → `getSigningCertificate()` (`POST /v1/signing-certificate`) →
`POST …/externa/preparar {document_id, component: 'nexu', mode: 'raw', certificate, chain}` →
`signDigest(keyHandle, pending.digest, 'SHA256')` (`POST /v1/sign`, sem novo hash) →
`POST …/externa/assinatura {pending_id, mode: 'raw', signature, certificate, chain, signature_algorithm}` → estado.
Serviço que devolve CMS (possivelmente Serpro): `mode: 'cms'`, assinar `pending.digest` (resumo do documento) e enviar `cms`.
**Com o simulador** (teste/local): `GET …/simulador/certificado` → `preparar {component: 'simulated', mode: 'raw', …}` →
`POST …/simulador/assinar {pending_id}`. O envio comum recusa reserva do simulador (`simulator_only`).

**Erros**: `{message, code, errors: {<campo>: [message]}, retry_after?, reason?}`. `409`: `not_ready`, `document_reserved`,
`busy`, `waiting_base`, `already_signed`, `already_consumed`, `expired`, `pending_closed`, `stale_revision`,
`pending_missing`, `request_closed`, `envelope_closed`, `window_closed`, `other_method_chosen`, `component_unavailable`
(`reason`), `simulator_only`, `not_simulated`, `cannot_withdraw`, `not_requested`; `422`: `signature_invalid`,
`certificate_mismatch`, `digest_mismatch`, `cms_invalid`, `cms_too_large`, `certificate_*`, `unsupported_key_algorithm`,
`test_certificate_not_accepted`, `holder_mismatch`, `chain_not_trusted`, `invalid_encoding`, `input_too_large`,
`mode_mismatch`, `mode_not_supported`, `component_unknown`, validação do formulário; `403 not_authenticated`;
`404 pending_not_found`/`document_not_found`; `500 embed_failed`; `503 prepare_failed`/`revision_unavailable`.

**Detalhe / evidências e verificação pública**: a lista `participant_signatures` (a mesma do A1, §2.12 §7) passa a trazer
também as assinaturas por componente, com `kind: 'participant_a3' | 'participant_external'`, `kind_label`, `simulated`,
`component`, `method` e rótulos próprios; na pública, nome mascarado e sem CPF, série ou impressão digital.
`signature_status`, `status_label`, `signature_statement` e `validation` usam `ExternalSignatureNarrative`.

## 8. Componentes locais

`LocalSignerBridge` (PHP) espelha o contrato do front do brief §7 (`detect`, `getSigningCertificate`, `signDigest`) +
`isSimulated()` e `producesTokenSignatures()` — a única porta para o rótulo A3.

- **`FakeLocalSigner`** — simulador: PKCS#12 de TESTE **no servidor**, assina o digest pronto (RSA PKCS#1 v1.5 do
  DigestInfo, sem novo hash). Só com `components.simulated.enabled` **e** ambiente em `allowed_environments` (`local`,
  `testing`); em `production` responde `environment_not_allowed` e não assina. Senha pela variável de ambiente NOMEADA
  (`pass_env`), `#[\SensitiveParameter]`, nunca em log/banco/resposta. O servidor assina com o digest da própria reserva
  (nunca um digest vindo do cliente) — custódia clara. Gerar o PKCS#12 local:
  `ASSINAVELOX_A3_SIMULATOR_PFX_PASS=... tools/pdftool/.venv/Scripts/python.exe -m pdftool gen-test-participant-cert --out-pfx storage/app/private/simulador-a3.pfx --pass-env ASSINAVELOX_A3_SIMULATOR_PFX_PASS`.
- **`NexuLocalSigner`** — **produção DESABILITADA** (`PRODUCTION_ENABLED = false`; `detect()` = `production_disabled`,
  `signingCertificate`/`signDigest` lançam). Documenta o protocolo do fork 1.25 (brief §3.4): HTTP `127.0.0.1:9795`, HTTPS
  `127.0.0.1:9895` (TLS local autoassinado), `GET /v1/status`, `POST /v1/signing-certificate`, `POST /v1/sign` (digest
  pronto, assinatura bruta). Limites: CORS de `/v1/**` exige allowlist **na máquina do participante** (curinga recusado;
  os legados `/rest/*` com `*` não serão usados); Chrome 142+ pede permissão de **Local Network Access** a cada origem e o
  NexU não emite `Access-Control-Allow-Private-Network` (interação NÃO testada); sem macOS; fork de uma pessoa.

**O que falta para ligar o componente em produção** (`NexuLocalSigner::missingForProduction()`):

1. piloto no Windows com pelo menos dois modelos de token A3 comuns no Brasil, em Chrome e Firefox, cobrindo a permissão de
   rede local e o TLS local;
2. escolha do componente: NexU (fork) com instalador próprio e parecer sobre a EUPL-1.2, Assinador Serpro (licença para SaaS e
   formato do comando de hash — `embed-external` já aceita bruta e CMS) ou Lacuna/BRy (contrato);
3. parecer jurídico sobre redistribuir o NexU (obra derivada, EUPL-1.2) e plano de manutenção do fork;
4. validação da cadeia até as raízes ICP-Brasil do ITI fixadas por impressão digital, com LCR/OCSP atualizadas por job;
5. validação da assinatura gerada em validador externo independente (Verificador de Conformidade do ITI) — critério de
   aceite do §3.4 e do T2.

## 9. Validação de cadeia e âncoras

`external_signing.trust_anchors` (`ASSINAVELOX_A3_TRUST_ANCHORS="caminho|sha256;caminho|sha256"`): cada âncora é um arquivo
com UM certificado, usado **só** se a impressão digital SHA-256 for exatamente a fixada. Arquivo trocado, ausente, ilegível
ou com vários certificados é **ignorado** e registrado (`ExternalTrustAnchors::resolve()`, sem conteúdo no log). As âncoras
válidas vão como `--trust` para `prepare-external` (avaliação offline da cadeia, registrada na reserva) e `embed-external`
(validação de todas as assinaturas). `require_trusted_chain` (desligado) recusa na preparação cadeia não validada.

Sem âncora válida: "cadeia não verificada". Com âncora: "validada até uma âncora configurada e fixada por impressão
digital" — nunca "ICP-Brasil", mesmo que a âncora seja uma raiz do ITI, porque a revogação (LCR/OCSP) **não é consultada**
(`revocation: not_checked`, sem rede). Isso é pré-requisito do `participant_icp_brasil` (§8 item 4).

## 10. Segredos e retenção

- O servidor **não recebe** chave, senha, PIN nem `keyHandle`: só certificado (público), digest (entregue) e assinatura/CMS
  (que acabam dentro do PDF). Os formulários não têm campo para material privado.
- **Digest**: gravado na reserva (`digest_hex`, oculto na serialização do model), devolvido **só** na resposta de
  `prepare`; nunca em log (o argv do pdftool só tem caminhos), evento de auditoria ou fila.
- **Estado do pdftool**: mínimo e sem segredo (§4); sha256 no banco, conferido antes de usar.
- **Simulador**: senha só por variável de ambiente nomeada; a chave aberta vive dentro de `signDigest()` e é zerada.
- Arquivos da reserva apagados ao consumir/expirar/substituir/descartar; `PurgeExternalSignatureReservations` vence
  reservas (`expireStale()`, também chamado na preparação) e apaga diretórios órfãos (> TTL + 30 min).
- Teste explícito (`ExternalSigningSecurityTest`): senha do simulador, trechos da chave do "token" e do PKCS#12, `PRIVATE KEY`
  e o digest procurados em **todas as tabelas**, na trilha, nos logs e no arquivo de estado — nada aparece.

## 11. Configuração (`config/assinavelox.php` → `external_signing`)

| chave                               | env                                                    | padrão                                                             |
| ----------------------------------- | ------------------------------------------------------ | ------------------------------------------------------------------ |
| `enabled`                           | `ASSINAVELOX_FEATURE_A3_SIGNING`                       | `false`                                                            |
| `pending_ttl_minutes`               | `ASSINAVELOX_A3_PENDING_TTL_MINUTES`                   | 10 (1–60)                                                          |
| `lock_wait_seconds`                 | `ASSINAVELOX_A3_LOCK_WAIT_SECONDS`                     | 10                                                                 |
| `pending_path`                      | `ASSINAVELOX_A3_PENDING_PATH`                          | `storage/app/private/external-signing`                             |
| `bytes_reserved`                    | `ASSINAVELOX_A3_BYTES_RESERVED`                        | 16384                                                              |
| `max_certificate_kb` / `max_cms_kb` | `ASSINAVELOX_A3_MAX_CERTIFICATE_KB` / `…_MAX_CMS_KB`   | 32 / 48                                                            |
| `max_chain_certificates`            | `ASSINAVELOX_A3_MAX_CHAIN`                             | 6                                                                  |
| `trust_anchors`                     | `ASSINAVELOX_A3_TRUST_ANCHORS`                         | vazio (cadeia "não verificada")                                    |
| `require_trusted_chain`             | `ASSINAVELOX_A3_REQUIRE_TRUSTED_CHAIN`                 | `false`                                                            |
| `accept_test_certificates`          | `ASSINAVELOX_A3_ACCEPT_TEST_CERTIFICATES`              | `true` fora de produção                                            |
| `reason`                            | `ASSINAVELOX_A3_REASON`                                | "Assinatura do participante com certificado em componente externo" |
| `components.simulated.enabled`      | `ASSINAVELOX_A3_SIMULATOR_ENABLED`                     | `false`                                                            |
| `components.simulated.pfx_path`     | `ASSINAVELOX_A3_SIMULATOR_PFX`                         | —                                                                  |
| `components.simulated.pass_env`     | `ASSINAVELOX_A3_SIMULATOR_PASS_ENV`                    | `ASSINAVELOX_A3_SIMULATOR_PFX_PASS` (nome, não valor)              |
| `components.nexu.*`                 | `ASSINAVELOX_A3_NEXU_HTTP` / `_HTTPS` / `_MIN_VERSION` | `127.0.0.1:9795` / `:9895` / `1.25.0`                              |

Plano: `plans.features.a3_signing = true`. Em produção, `pending_path` precisa ser compartilhado entre os servidores web
(a preparação e o envio podem cair em máquinas diferentes) e não versionado.

## 12. O que falta para ligar a flag em produção

1. **Pré-requisito do roadmap (§3)**: Fase 2 em produção por um ciclo completo de cobrança, API v1 congelada, matriz da Fase 2
   verde — condição de ativação, não de código.
2. Um componente real habilitado (§8, itens 1–3). Sem ele, com a flag ligada, só o simulador existe — e ele não roda em
   produção. Ligar a flag antes disso não entrega nada ao usuário final.
3. Validação externa independente de uma assinatura gerada com token real (T2; §8 item 5).
4. Front: a página pública consumir o estado (§7) e o `LocalSignerBridge` em TypeScript (fora desta área), com orientação
   sobre instalação, TLS local e Local Network Access; manter o aceite eletrônico como caminho padrão quando não há
   componente (roadmap §3.4, aceite).
5. Agendar `PurgeExternalSignatureReservations` a cada 5 min (`routes/console.php`, fora desta área).
6. Revisão jurídica do texto exibido ao participante (os avisos em `ExternalSignatureLabels::notices()`), como no A1.

## 13. Testes

- **pytest** (`tools/pdftool/tests/test_external.py`, 12) — §4.
- **Pest** (`tests/Feature/Phase3/External`, 16):
    - `ExternalSigningFlowTest` (4): fluxo completo com o **simulador** gera assinatura válida, em revisão incremental
      (base ⊂ revisão ⊂ final), confiável com a raiz de teste, **rotulada como simulada** em estado, evidências e verificação
      pública, operadora por último; componente habilitado (dublê) + certificado que declara A3 → `participant_a3` com
      assinatura bruta; modo CMS → `participant_external` (nunca A3 sem declaração); sem operadora.
    - `ExternalSigningRejectionTest` (5): digest **expirado** (e nova preparação), **reutilizado** (consumo único), de **outra
      revisão** (o A1 de outro participante gravou durante a janela → `stale_revision`, depois cadeia A1 + externa + operadora
      sã), assinatura **adulterada**, **outro certificado**, CMS de outro conteúdo; reserva do simulador fora do simulador.
    - `ExternalSigningConcurrencyTest` (3): **corrida de duas preparações no mesmo envelope** não gera revisões irmãs (uma
      espera a outra; a segunda parte da revisão da primeira); lock ocupado não grava nada nem consome a reserva; o banco
      recusa duas reservas ativas no mesmo documento.
    - `ExternalSigningSecurityTest` (4): **nenhum estado pendente contém segredo**; **flag desligada = nada muda**; simulador
      só em teste/local e NexU desabilitado; âncoras fixadas por impressão digital.
- O "token" dos testes é `tests/Feature/Phase3/External/Support/external_signer.py` (ferramenta de teste: chave no
  diretório do teste, certificado de TESTE, opcionalmente com política A3) — o servidor só vê certificado, digest e assinatura.

## 14. Pendências e limitações (reais)

1. **Validação externa não feita**: não há token nem fixture real; nenhuma assinatura foi conferida no Verificador do ITI.
2. **Revogação não verificada** e nenhuma âncora ICP-Brasil configurada/fixada; `participant_icp_brasil` não existe.
3. O **A1 (§2.12) não respeita reservas**: se um A1 for aplicado durante a janela do token, a assinatura externa é recusada
   (`stale_revision`) e o participante prepara de novo. Seguro (sem irmãs), mas custa uma nova operação no token. Fazer o
   applier do A1 esperar reservas exigiria mudar `app/Services/Signing/Certificates/**` (fora desta área).
4. `participant_a3` depende de o certificado **declarar** a política A3 (arco `2.16.76.1.2.3`, conforme entendido do
   DOC-ICP-04 — **NÃO CONFIRMADO** contra o texto vigente) e de um componente real; é declaração, não prova de hardware.
5. O mecanismo RSA do NexU (v1.5 ou PSS) segue **NÃO CONFIRMADO**: a preparação anuncia v1.5 por padrão (`--rsa-pss` existe
   no pdftool, mas o Laravel ainda não o expõe; decidir no piloto pelo `signatureAlgorithm` devolvido).
6. Página de evidências em PDF: gerada antes das assinaturas; o bloco "participant_mode" da Fase 2 fala em "próprio
   certificado" (verdadeiro também aqui). Nenhum texto novo foi acrescentado a ela.
7. **Arquivos fora da área tocados** (edições mínimas, aditivas, necessárias para T1 e para o PHPStan):
   `app/Services/Verification/SignatureNarrative.php` (desvio para `ExternalSignatureNarrative` + dois braços do `match` de
   `statusLabel`, que sem eles lançaria `UnhandledMatchError` com os valores novos do enum) e
   `app/Services/Signing/Certificates/ParticipantSignatureViews.php` (pedidos por componente saem da lista "A1" e entram
   com rótulos próprios). Sem essas duas, uma assinatura por componente apareceria como "certificado A1".
   Também `tests/Feature/Smoke/AllGetRoutesTest.php` (registro das duas rotas GET novas, `sign.external.show` e
   `sign.external.simulator.certificate`, na lista de 404 e nos parâmetros, com o mesmo token sintético de
   `sign.certificate.show` — como fizeram P3-AFF e P3-RISK): sem isso o smoke lança `UrlGenerationException`.
8. `tools/pdftool/README.md` (compartilhado) não foi alterado: os dois comandos estão documentados aqui (§4).
