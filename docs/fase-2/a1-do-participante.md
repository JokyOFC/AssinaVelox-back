# Fase 2, onda C — assinatura com o certificado A1 do próprio participante (§2.12, K-A1)

> Roadmap §2.12; viabilidade §1.1 (classe **A**), riscos R3, R5 e R10; `integracoes/carimbo-do-tempo-e-ltv.md` §2.4
> e §3. Identificadores em inglês, texto em português. Flag **`participant_a1`**, nova, **desligada** por padrão.

## 1. Resumo

O participante que tem um certificado digital A1 (arquivo `.pfx`/`.p12`) pode, **além** do aceite eletrônico, acrescentar
ao arquivo final uma assinatura PAdES com o **próprio** certificado. A assinatura da operadora, quando configurada,
continua sendo a **última**.

| Entrega                                  | Onde                                                                                      |
| ---------------------------------------- | ----------------------------------------------------------------------------------------- |
| `pdftool inspect-cert`                   | `tools/pdftool/pdftool/inspect_cert.py`                                                   |
| `pdftool participant-sign`               | `tools/pdftool/pdftool/participant_sign.py`                                               |
| `pdftool gen-test-participant-cert`      | `tools/pdftool/pdftool/inspect_cert.py` (só testes/desenvolvimento)                       |
| Serviços, material cifrado, lock, cadeia | `app/Services/Signing/Certificates/**`                                                    |
| Encaixe na finalização                   | `app/Services/Envelopes/Finalization/ParticipantSignatureStage.php` + `EnvelopeFinalizer` |
| Worker serializado                       | `app/Jobs/Envelopes/ApplyParticipantSignature.php` (+ `…Deadline`)                        |
| Rotas do participante (JSON)             | `app/Http/Controllers/Sign/CertificateController.php`                                     |
| Tabelas                                  | `participant_signature_requests`, `participant_signatures`                                |

Com a flag desligada **nada muda**: as rotas novas respondem 404, nenhum pedido pode ser criado, e a finalização só
entra no caminho novo quando existe pedido ativo — sem pedido, é o pipeline da Fase 1, byte a byte (testado).

A flag vale quando as **duas** fontes dizem sim: `assinavelox.participant_a1.enabled`
(`ASSINAVELOX_FEATURE_PARTICIPANT_A1`) **e** `plans.features.participant_a1` do plano vigente
(`ParticipantA1Feature::enabledFor()`). Ela não entra na prop compartilhada `features` (o front descobre o recurso
pelo `GET sign.certificate.show`, §7). Envelopes com pedidos já criados são conduzidos até o fim mesmo se a flag for
desligada depois; os pendentes vencem pelo prazo.

## 2. Semântica (T1, T2, T3) — o que é afirmado e o que não é

Três coisas diferentes, cada uma com rótulo e valor próprios:

| Coisa                                            | Registro                                                                | Rótulo na UI/verificação                                   |
| ------------------------------------------------ | ----------------------------------------------------------------------- | ---------------------------------------------------------- |
| Aceite eletrônico (todos os participantes)       | `signature_acceptances`                                                 | "aceite eletrônico" — inalterado                           |
| Assinatura com o certificado **do participante** | `participant_signatures` + `signature_status` `participants_a1`/`mixed` | "Assinado com certificado A1 de {nome} (emitido por {AC})" |
| Assinatura da **operadora**                      | `signature_status` `company_a1`/`mixed`                                 | "assinatura criptográfica da operadora" — inalterado       |

`verification_records.signature_status` ganhou dois valores (roadmap §2.12, "Novas entidades"):

| valor             | significa                                                          | `statusLabel()` público                                                |
| ----------------- | ------------------------------------------------------------------ | ---------------------------------------------------------------------- |
| `none`            | só aceite eletrônico (Fase 1)                                      | Concluído · aceite eletrônico com evidências                           |
| `company_a1`      | só a operadora (Fase 1)                                            | Concluído · assinado com certificado da operadora                      |
| `participants_a1` | um ou mais participantes com o próprio A1; a operadora não assinou | Concluído · assinado com certificado dos participantes                 |
| `mixed`           | participantes com o próprio A1 **e**, por último, a operadora      | Concluído · assinado com certificados dos participantes e da operadora |

- **Perfil:** sempre `PAdES-B-B` (`signature_profile`). Nada de B-T/B-LT/B-LTA (T2). `validation_result.timestamp = null`,
  `long_term_validation = false`, `revocation = not_checked`.
- **ICP-Brasil nunca é afirmado.** A plataforma não valida a cadeia até uma raiz ICP-Brasil (âncoras não configuradas)
  nem consulta revogação. O que se mostra é "o certificado **declara** política ICP-Brasil (cadeia não validada por esta
  plataforma)" quando há OID sob `2.16.76.1.2`. O B-B sem `SignaturePolicyIdentifier` provavelmente não é aprovado como
  AD-RB no Verificador do ITI (viabilidade R3) — não se chama de AD-RB.
- **Certificado de teste** (CN ou emissor com "TESTE"): sempre rotulado "Certificado de TESTE — não é ICP-Brasil e não tem
  validade jurídica", na prévia, no consentimento, nas evidências e na verificação. Em produção é **recusado** por padrão
  (`accept_test_certificates`).
- **Carimbo do tempo:** esta área não aplica carimbo nenhum. O B-T da operadora (K-TSA) é independente e não muda o
  perfil anunciado.

## 3. Pipeline e ordem final

```
 aceites (paralelos) ──► envelope em finalizing
                              │ a. consolidação (campos achatados)           — Fase 1
                              │ b. página de evidências (sem o hash final)    — Fase 1 (+ bloco "participantes", §3.3)
                              │ c. append ──► pre_signature  (BASE CONGELADA, sem assinatura)
                              │
                              │    ┌── espera, com prazo, pelos pedidos pendentes (envelope continua em finalizing)
                              │    │
                              │ d1. participante 1 ──► signed_incremental #1   ┐ ApplyParticipantSignature
                              │ d2. participante 2 ──► signed_incremental #2   │ um por vez, sob o lock do envelope,
                              │ …                                              ┘ ordem de chegada dos envios
                              │ e. operadora POR ÚLTIMO (se configurada e no plano) ──► final
                              │ f. validação de TODAS as assinaturas juntas (cadeia) + verification_records
                              ▼    (hash final calculado DEPOIS da última assinatura)
                          completed
```

Cada revisão é uma **atualização incremental**: os bytes da revisão anterior são prefixo exato da seguinte
(`pdftool participant-sign` confere byte a byte; os testes conferem `base ⊂ #1 ⊂ #2 ⊂ final`). Assinaturas de
**aprovação** (nunca de certificação), para que as seguintes continuem permitidas. Cada assinatura gera uma
`document_version` nova com `sha256` (`kind = signed_incremental`); o `final` (`kind = final`) é gerado sobre a última
revisão, e o `final_sha256` publicado é o dos bytes depois da última assinatura. Sem a operadora, o `final` **é** a
última revisão assinada pelo participante (mesmo hash).

Multi-documento: cada documento tem a sua própria cadeia; um pedido de participante assina todos os documentos do
envelope, na ordem de apresentação, com um campo por documento.

### 3.1 Quando a assinatura é aplicada — decisão

**Aplicação ao final, com o material usado "na hora" ou selado por minutos.** Conforme §2.12, o participante assina
com o certificado o que não pode mais mudar: consolidado + evidências (`pre_signature`). Esse conteúdo só existe
depois do **último** aceite. Por isso:

1. antes disso, o participante só registra a **escolha** (`POST …/certificado/intencao`). Nenhum PFX é aceito e
   nenhuma senha é guardada;
2. quando o envelope entra em `finalizing`, a finalização monta a base e **espera** pelos pedidos pendentes (evento
   `envelope.awaiting_participant_signatures`);
3. o participante volta pelo link (código → janela deste navegador), confere o certificado (prévia), consente e envia
   PFX + senha. O servidor **inspeciona na hora** e **sela** o conjunto (§6) com prazo de minutos
   (`sealed_ttl_minutes`, padrão 15). O worker serializado do envelope consome o material, assina e o destrói.

Por que não "usar na requisição, sem fila": a gravação precisa do lock do envelope, que pode estar ocupado por outro
participante ou pela finalização (sign + validate levam segundos). Esperar isso segurando a requisição HTTP, e ainda
com o risco de timeout, é pior que uma fila com material cifrado de vida curta. Com o driver `sync` o job roda na
própria requisição — o comportamento é o mesmo.

Por que não "reservar a revisão para quem assina antes": custódia do PFX por dias até os demais aceitarem é custódia
persistente, que esta onda **não** implementa (§6.3). Reserva de revisão com expiração é o desenho da Fase 3 para A3 e
gov.br (viabilidade R10), onde o segredo não passa pelo servidor.

**Quem assina antes dos demais**, portanto, não deixa certificado nenhum na plataforma: deixa a escolha registrada e
volta quando o documento estiver pronto (a tela diz isso; `stage = awaiting_others`).

### 3.2 Prazo, falha e retomada

- **Prazo** (`application_window_minutes`, padrão 4320 = 72 h), contado a partir do momento em que a finalização
  começa a esperar. Vencido, o pedido vira `expired` (`participant_signature.expired`) e a finalização conclui **sem**
  aquela assinatura — o aceite eletrônico já registrado continua valendo (roadmap §2.12, riscos). Numa fila real,
  `ApplyParticipantSignatureDeadline` é agendado com `delay` para o fim do prazo; com o driver `sync`, o prazo é
  conferido na próxima execução da finalização.
- **Falha** na aplicação (senha que mudou, certificado vencido no meio, cadeia que não valida): o pedido vira `failed`,
  o material já foi destruído e nada foi gravado. O participante pode reenviar dentro do prazo. A finalização continua
  esperando pedidos `failed` até o prazo.
- **Material vencido sem worker** (`queued` com `sealed_expires_at` no passado) ou **aplicação interrompida**
  (`applying` parado além do `lock_seconds`): viram `failed` na próxima espera, com mensagem para reenviar.
- **Retomada parcial** (documento 1 assinado, documento 2 não): o reenvio pula o que já foi assinado e **exige o mesmo
  certificado** (impressão digital), para não haver dois titulares para o mesmo participante.
- **Desistência** (`POST …/certificado/desistir`) antes da aplicação: `withdrawn`; a finalização é retomada.
- **Base refeita** (consolidação ou evidências regeneradas numa retomada): as revisões assinadas sobre a base antiga —
  nunca publicadas — são descartadas e os pedidos aplicados voltam a `requested` ("envie o certificado de novo"). A
  plataforma não tem o PFX para refazer a assinatura, e dizer isso é o honesto.
- A falha de uma assinatura **não desfaz aceite nenhum**.

### 3.3 Página de evidências (PDF)

A página é gerada **antes** das assinaturas, então não pode listá-las. Com pedidos ativos (`participant_mode`), a seção
"5. Sobre a assinatura criptográfica deste arquivo" ganha um bloco que diz que participantes optaram por assinar com o
próprio certificado, que essas assinaturas, **quando aplicadas**, entram depois da página como revisões incrementais,
que são distintas do aceite eletrônico, que quem não concluir no prazo fica sem a assinatura, e onde a lista real é
publicada (página de verificação). Sem a operadora, o bloco "Este arquivo não possui assinatura criptográfica" (que
seria falso) é trocado por um texto que não o afirma. A marca `participant_mode` entra na variante da página
(`envelope.evidence_generated`), e a página é regenerada se a variante mudar, como já acontecia com a assinatura da
operadora. Sem pedidos, a página é a da Fase 1.

## 4. Modelo de dados (migrations aditivas, MySQL-compatíveis)

`2026_09_11_130001_create_participant_signature_requests_table` — um pedido por participante e envelope
(`UNIQUE(envelope_id, recipient_id)`): `status` (`ParticipantSignatureRequestStatus`: `requested`, `queued`,
`applying`, `applied`, `failed`, `expired`, `withdrawn`), consentimento (`consent_version`, `consent_statement` com o
texto resolvido, `consented_at`, `consent_ip`), fatos públicos do certificado (`subject`/`subject_cn` com CPF mascarado,
`issuer`, `issuer_cn`, `serial_number`, `fingerprint_sha256`, validade, `holder_cpf_masked`, `is_test_certificate`,
`certificate_facts` JSON sem CPF), material temporário (`sealed_ulid` — só o identificador —, `sealed_expires_at`),
`window_expires_at`, `attempts`, `failure_code`/`failure_message`, `queued_at`, `applied_at`, `closed_at`.

`2026_09_11_130002_create_participant_signatures_table` — uma assinatura por pedido **e documento** (nomes do roadmap):
`signature_acceptance_id`, `certificate_reference_id` (reservado, nulo — `certificate_references` continua sendo a
tabela dos certificados da operadora), `base_document_version_id` (**UNIQUE**), `signed_document_version_id`,
`revision_index` (`UNIQUE(document_id, revision_index)`), `field_name`, `profile`, titular/emissor/série/impressão
digital/validade, `validation_result` JSON (cadeia e resultado de todas as assinaturas daquela revisão, sem dados
pessoais), `signed_at`.

Enums: `SignatureStatus` + `participants_a1`, `mixed` (e `hasParticipantSignatures()`, `hasOperatorSignature()`);
`DocumentVersionKind` + `pre_signature`, `signed_incremental`; `ParticipantSignatureRequestStatus` (novo);
`AuditEventType` (§8). As colunas existentes (`string(32)`) comportam os valores novos; nada foi alterado nelas.

`envelopes.crypto_mode` (roadmap) **não** foi criado: o modo é derivado de haver pedido ativo, o que evita um estado
duplicado que pudesse divergir.

## 5. Concorrência — participação paralela, gravação serial

Por que (roadmap §2.12): uma assinatura PAdES cobre, pelo `/ByteRange`, todos os bytes até ela; dois gravadores partindo
da mesma revisão N produziriam dois arquivos irmãos, cada um válido sozinho, impossíveis de fundir.

Camadas, todas testadas:

1. **Lock de cache por envelope** `envelope:{id}:sign` (`EnvelopeSigningLock`), tomado pela aplicação de cada
   participante **e** pela etapa final da finalização (base, decisão "não falta ninguém", assinatura da operadora). Uma
   assinatura de participante não entra entre a decisão e o `final`.
2. **Job** `ApplyParticipantSignature`: `WithoutOverlapping(envelope:{id}:sign)` (o segundo job do mesmo envelope volta
   para a fila) + `ShouldBeUnique` por pedido.
3. **Compare-and-set no banco**: a revisão assinada só é gravada se a base usada ainda for a mais recente
   (`lockForUpdate` no documento, `IncrementalRevisions::storeSigned()`); senão, a saída é descartada e a assinatura é
   recalculada sobre a revisão atual (até 3 vezes, com o material ainda em memória).
4. **`participant_signatures.base_document_version_id` único**: o banco recusa duas assinaturas sobre a mesma revisão.
5. **Validação da cadeia antes e depois**: `participant-sign` recusa assinar sobre uma cadeia quebrada e valida o
   arquivo inteiro depois; a finalização valida tudo de novo (`IncrementalChain`) antes de publicar.

Nenhuma transação de banco fica aberta durante o pdftool. O job é idempotente (pedido aplicado → `applied`; documento
já assinado pelo pedido → pulado) e retomável.

### Integridade de uma cadeia (`IncrementalChain`)

`OperatorSignature::assertPublishable()` exige `all_covering`, que é falso por construção numa cadeia (cada assinatura
anterior cobre a sua revisão). No modo de participantes vale: todas `intact` e `valid`; a **mais recente** cobre o
arquivo inteiro (`ENTIRE_FILE`); cada anterior cobre a sua revisão e o que veio depois é só alteração permitida
(`NONE`/`FORM_FILLING`); nenhuma modificação suspeita; nenhuma violação de DocMDP; quantidade exata de assinaturas. A
análise vai para `validation_result.incremental_chain` e é o que as páginas leem para afirmar integridade.
`signAndValidate()` ganhou um parâmetro opcional para essa asserção; sem ele, o comportamento da Fase 1 é o mesmo.

## 6. Segredos, retenção mínima e custódia

### 6.1 Regras cumpridas

- **Senha**: chega no corpo da requisição (campo `password`, que o Laravel nunca devolve à sessão) e só vai ao pdftool
  pela variável de ambiente **nomeada** do processo filho (`AV_PARTICIPANT_PFX_PASS`), com o ambiente mínimo de
  `ProcessEnvironment`. Nunca em argv, log, exceção, evento, fila ou banco. Todos os parâmetros que a carregam são
  `#[\SensitiveParameter]` (não aparecem em stack trace). stderr e mensagens do pdftool passam por redação. As exceções
  do cliente não encadeiam a exceção do Symfony Process (que carrega o ambiente do filho).
- **PFX**: copiado para um diretório temporário **exclusivo** (0700), inspecionado e apagado em `finally` na mesma
  requisição — o diretório e o arquivo temporário do upload do PHP —, com sucesso ou falha (testado).
- **Entre a requisição e o worker** (`SealedCertificateStore`): PFX + senha são cifrados juntos com **AES-256-GCM**; a
  chave é derivada por **HKDF-SHA256 da APP_KEY** com sal aleatório de 16 bytes **por arquivo** e rótulo
  `assinavelox:participant-a1:seal:v1`; o ULID do arquivo e o do pedido entram como dado autenticado (um arquivo não
  serve para outro pedido). O arquivo fica em `sealed_path` (fora de `public/`), vive no máximo `sealed_ttl_minutes` e
  é **apagado ao ser aberto** (consumo único). O banco guarda só o `sealed_ulid`. O job carrega só IDs.
- **No worker**: o material aberto (`SealedCertificate`) não se serializa, não se clona, não se descreve
  (`[REDACTED]`) e é zerado em `finally`; o PFX vai para um diretório temporário exclusivo, apagado em `finally`.
- **Processo morto** (tempo limite do job, OOM, deploy, `max_execution_time` da requisição): nem `finally` nem
  destrutor rodam. A varredura agendada `participant-a1:maintain` (a cada 15 min) apaga os diretórios `a1-apply-*`,
  `a1-upload-*` e `a1-proc-*` do `pdftool.tmp_path` mais velhos que o maior tempo em que um processo vivo ainda os
  usaria — `max(lock_seconds, $timeout do ApplyParticipantSignature, tempos limite do pdftool, sealed_ttl_minutes)` +
  5 min de margem (hoje ~20 min). Nunca lê o conteúdo; um diretório com arquivo recém-gravado não é tocado. Os
  demais diretórios temporários do pdftool (documentos) não são tocados por essa rotina (revisão adversarial da onda C).
- **stdout do pdftool não é redigido**: é o JSON público (o pdftool nunca imprime a senha — há teste no pdftool);
  trocar a senha ali corromperia fatos públicos que contivessem a mesma sequência (uma senha igual ao ano, por
  exemplo, apagava o ano da validade). A redação vale para o stderr e para a mensagem de erro.
- **Nada persiste depois do uso.** O que fica são os fatos públicos do certificado (titular, emissor, série, validade,
  impressão digital) e o CPF **mascarado**. O CPF completo não é gravado: o CN de um e-CPF (`NOME:CPF`) é gravado e
  mostrado com o CPF mascarado.
- **Teste explícito** (`ParticipantA1SecurityTest`): procura a senha, a senha errada, trechos do PFX (bytes, base64,
  hex) e o PFX inteiro em base64 em **todas as tabelas**, na trilha, nos logs, no payload serializado da fila, no
  arquivo selado e na exceção (mensagem, contexto e pilha) — nada aparece.

### 6.2 Rotação da APP_KEY

Trocar a APP_KEY invalida o material selado em curso (minutos): os pedidos `queued` falham com "envie de novo". Nenhum
dado permanente depende dessa chave.

### 6.3 Custódia persistente — desenho, NÃO implementado

Decisão pendente (roadmap §2.12; viabilidade §4.5 item 30; recomendação: não reter). Se um dia for aceita:

1. consentimento próprio e revogável ("guardar meu certificado para próximas assinaturas"), com prazo (TTL) e aviso
   de risco; `certificate_references.kind = personal_a1`, `organization_id` do titular, `secret_ref` apontando para o
   objeto cifrado;
2. **envelope encryption com KMS/HSM**: o PFX (que já é protegido pela senha dele) é cifrado com uma chave de dados
   própria, embrulhada pela chave-mestra do KMS; o aplicativo nunca vê a chave-mestra;
3. a **senha nunca é guardada**: a cada uso o titular informa a senha e passa pelo código (a posse do PFX guardado,
   sozinha, não assina nada);
4. job de expurgo no fim do TTL e na revogação do consentimento, com evento na trilha; rotação da chave-mestra;
5. teste de segredos igual ao desta onda, estendido ao armazenamento persistente.

## 7. Contrato para o front (sem mudança em `resources/js` nesta área)

Todas as rotas ficam no grupo público `assinar/{token}` (`throttle:signer` + middleware `signer`), respondem JSON e
dão **404** com a flag desligada, papel sem assinatura (aprovador, visualizador) ou envelope que não recebe mais.
Autenticação: sessão do código deste participante (antes do aceite) **ou** a janela de download deste navegador
(aberta pelo aceite ou por um novo código). Sem ela: `403 {code: "not_authenticated"}`.

| Rota                        | Método e caminho                            | Corpo                                                                                 | Resposta                               |
| --------------------------- | ------------------------------------------- | ------------------------------------------------------------------------------------- | -------------------------------------- |
| `sign.certificate.show`     | `GET assinar/{token}/certificado`           | —                                                                                     | `200` estado (abaixo)                  |
| `sign.certificate.intent`   | `POST assinar/{token}/certificado/intencao` | —                                                                                     | `201` estado                           |
| `sign.certificate.withdraw` | `POST …/certificado/desistir`               | —                                                                                     | `200` estado                           |
| `sign.certificate.inspect`  | `POST …/certificado/conferir` (multipart)   | `certificate` (arquivo), `password`                                                   | `200` prévia (abaixo); nada é guardado |
| `sign.certificate.store`    | `POST …/certificado` (multipart)            | `certificate`, `password`, `consent=1`, `consent_version`, `fingerprint?` (da prévia) | `202` estado                           |

Limites: `show` 60/min; `intencao`/`desistir` 20/10 min; `conferir` 10/10 min; envio 6/10 min (por IP).
Wayfinder: rodar `php artisan wayfinder:generate --with-form` na integração (gera arquivos em `resources/js`).

**Estado** (`show`, `intent`, `withdraw`, `store`):

```ts
type ParticipantCertificateState = {
    available: true;
    authenticated: boolean;
    stage:
        | 'choose'
        | 'awaiting_others'
        | 'ready_to_upload'
        | 'queued'
        | 'applying'
        | 'applied'
        | 'failed'
        | 'expired'
        | 'withdrawn'
        | 'closed';
    ready: boolean; // conteúdo congelado: pode enviar o PFX agora
    message: string | null; // por que ainda não (PT-BR)
    can_request: boolean;
    can_withdraw: boolean;
    can_upload: boolean;
    request: null | {
        id: string;
        status: string;
        status_label: string;
        certificate: null | {
            holder_name;
            holder_cpf_masked;
            issuer_cn;
            serial;
            fingerprint_sha256;
            valid_from;
            valid_to;
            is_test: boolean;
            kind_label;
            label;
        };
        consent_version;
        consented_at;
        queued_at;
        applied_at;
        window_expires_at: string | null;
        documents_signed: number;
        failure: null | { code: string; message: string };
    };
    consent: {
        version: string;
        checkbox_label: string;
        summary: string;
        legal_review_required: true;
    };
    limits: {
        max_upload_kb: number;
        accepted_extensions: ['pfx', 'p12'];
        sealed_ttl_minutes: number;
        application_window_minutes: number;
    };
    notices: string[];
    endpoints: { show; intent; withdraw; inspect; store: string };
};
```

**Prévia** (`inspect`):
`{certificate: {holder_name, holder_cpf_masked, cpf_source, cpf_confirmed: false, subject, subject_cn, issuer,
issuer_cn, serial, fingerprint_sha256, valid_from, valid_to, key_usage[], extended_key_usage[], self_signed,
chain_length, declares_icp_brasil_policy, icp_brasil_validated: false, is_test, kind_label, label, warnings[]},
holder_match: {cpf: 'match'|'mismatch'|'unknown', name: 'match'|'different'|'unknown', rule},
consent: {version, checkbox_label, statement /* texto integral a exibir */, legal_review_required},
ready, message}`.

**Erros**: `{message, code, errors: {certificate|password|consent: [message]}}`. `422` para recusas do certificado
(`wrong_passphrase`, `invalid_pkcs12`, `pkcs12_too_large`, `pkcs12_without_key`, `pkcs12_without_certificate`,
`key_certificate_mismatch`, `certificate_is_ca`, `certificate_expired`, `certificate_not_yet_valid`,
`certificate_not_for_signing`, `test_certificate_not_accepted`, `holder_mismatch`, `certificate_changed`) e validação
do formulário (`consent`, `consent_version`); `409` para `not_ready` (a escolha fica registrada), `already_queued`,
`already_applied`, `window_closed`, `envelope_closed`, `cannot_withdraw`; `403 not_authenticated`;
`503 certificate_check_unavailable`.

Fluxo sugerido na página pública (`sign/show`): opção "assinar também com meu certificado" (→ `intent`) antes do
aceite; depois do aceite, com `ready`, o passo "enviar certificado": arquivo + senha → `inspect` → mostrar a prévia
(com o rótulo de teste quando houver) e o `consent.statement` com a caixa `consent.checkbox_label` → `store` com o
`fingerprint` da prévia → acompanhar `stage` por `show`.

**Detalhe / evidências** (`envelopes/evidence`): prop nova **`participant_signatures`**, presente só quando há pedidos:
`[{id, kind: 'participant_a1', kind_label, recipient: {id, name}, status, status_label, label,
certificate: {holder_name, holder_cpf_masked, subject, issuer, issuer_cn, serial, fingerprint_sha256, valid_from,
valid_to, is_test, kind_label, declares_icp_brasil_policy, icp_brasil_validated: false} | null,
consent: {version, consented_at, legal_review_required} | null,
documents: [{document_id, name, position, field_name, revision_index, signed_at, signed_sha256, profile,
integrity: 'intact'|'broken'|'unknown', trusted, coverage, modification_level}],
signed_at, window_expires_at, failure: {code, message} | null}]`. `signature`, `signature_status`, `validation` e
`certificate` (da operadora, no `mixed`) seguem o formato de antes, com os valores novos de `signature_status`.

**Verificação pública** (`verify/show`, `result`): chave nova **`participant_signatures`**, presente **só** quando há
assinatura aplicada (a lista de chaves é fechada): `[{kind, kind_label, label /* com o nome MASCARADO */,
holder_name_masked, issuer_cn, valid_from, valid_to, is_test, certificate_label, signed_at, documents_count,
integrity, integrity_label, chain_trust, chain_trust_label}]` — **sem** CPF, série, impressão digital ou e-mail.
`signature_status`, `signature_state`, `signature_label`, `signature_statement` e `validation` usam a linguagem de
`ParticipantSignatureNarrative` para `participants_a1`/`mixed`.

## 8. Eventos de auditoria novos

Todos no envelope, payload só com ULIDs, códigos, impressão digital/emissor/série do certificado:
`participant_certificate.requested`, `participant_certificate.withdrawn`, `participant_certificate.submitted`
(impressão digital, emissor, série, `is_test_certificate`, versão do consentimento), `participant_certificate.rejected`
(`error_code`), `participant_signature.applied` (documento, versão, `sha256`, `revision_index`, campo, perfil,
impressão digital, `chain_ok`), `participant_signature.failed` (`error_code`), `participant_signature.expired`
(`reason`), `envelope.awaiting_participant_signatures` (`pending`, `window_expires_at`). O evento
`envelope.signed_company_a1` ganha `participant_signatures` quando a operadora assina por cima delas; o
`envelope.completed` ganha `participant_signatures` no modo novo. `EnumCatalogTest` foi estendido (T7: só acréscimos).

## 9. pdftool

Sem rede, JSON único em stdout, exit codes 2/3/4 como os demais comandos. Senha só pelo NOME da variável (`--pass-env`).

### `inspect-cert --pfx <arquivo> --pass-env <VAR>`

Devolve só fatos públicos: `subject`, `subject_cn`, `issuer`, `issuer_cn`, `serial_hex`, `cert_fingerprint_sha256`,
`not_before`, `not_after`, `days_remaining`, `key_algorithm`, `key_size`, `key_usage`, `extended_key_usage`,
`has_private_key`, `key_matches_certificate`, `self_signed`, `chain` (cada certificado da cadeia **contida no
arquivo**), `chain_length`, `chain_reaches_self_signed_root`, `holder {name, cpf, cpf_masked, cpf_source,
cpf_check_digits_valid, cpf_confirmed: false, cnpj}`, `icp_brasil {policy_oids, declares_icp_brasil_policy,
chain_validated: false}`, `test_certificate`, `warnings`. Recusa (exit 4): `invalid_pkcs12` (corrompido),
`wrong_passphrase` (estrutura válida, senha não abre — sem ecoar a senha), `pkcs12_without_key`,
`pkcs12_without_certificate`, `key_certificate_mismatch`, `certificate_is_ca`, `certificate_expired`,
`certificate_not_yet_valid`, `certificate_not_for_signing` (keyUsage sem `digitalSignature`/`nonRepudiation`, ou EKU
sem uso de assinatura), `pkcs12_too_large`, `missing_input`.

**Onde fica o CPF num certificado ICP-Brasil** (lido do certificado, nunca "confirmado"):

1. `subjectAltName` → `otherName` OID **`2.16.76.1.3.1`** ("dados do titular" do e-CPF): 8 dígitos da data de
   nascimento (DDMMAAAA) seguidos dos **11 dígitos do CPF**, depois NIS, RG e órgão expedidor. **NÃO CONFIRMADO** contra o
   texto vigente do DOC-ICP-04 nesta onda (layout como entendido pelo projeto; o tipo ASN.1 varia entre ACs — OCTET
   STRING, PrintableString, UTF8String — e todos são aceitos). `2.16.76.1.3.3` (CNPJ do e-CNPJ) também é lido.
2. Convenção do CN do e-CPF `NOME DO TITULAR:CPF` (só como alternativa).

`cpf_confirmed` é sempre `false` (não há consulta à Receita); `cpf_check_digits_valid` confere só os dígitos.

### `participant-sign --in --out --pfx --pass-env --field-name [--reason] [--location] [--trust]… [--expect-fingerprint]`

Assinatura **PAdES B-B de aprovação**, incremental, com campo **único** (`field_name_taken` se já existir; o Laravel
usa `AV_Participante_{ulid do participante}`). Antes: as mesmas conferências do `inspect-cert`, a impressão digital
esperada (`fingerprint_mismatch`) e a validade das assinaturas já presentes (`previous_signature_invalid`). Depois:
confere que a entrada é prefixo exato da saída e valida o arquivo inteiro, devolvendo `validation` (todas as
assinaturas — a da operadora, se houver, e as dos demais participantes) e `chain {ok, signature_count,
newest_covers_entire_file, earlier_changes_permitted, problems}`. Cadeia quebrada: a saída é apagada,
`revision_chain_broken` (exit 3).

### `gen-test-participant-cert --out-pfx --pass-env [--name] [--cpf] [--days] [--valid-from-days] [--key-usage signing|encipherment] [--no-key] [--self-signed] [--out-ca-pem]`

Certificado de **TESTE** emitido por uma AC de teste descartável (CN sempre com "TESTE"), com CPF opcional no
`otherName` `2.16.76.1.3.1` e no sufixo do CN. Serve para os testes produzirem certificados vencidos, ainda não
válidos, sem chave e sem uso de assinatura. Nunca ICP-Brasil.

## 10. Configuração (`config/assinavelox.php` → `participant_a1`)

| chave                        | env                                                   | padrão                                         |
| ---------------------------- | ----------------------------------------------------- | ---------------------------------------------- |
| `enabled`                    | `ASSINAVELOX_FEATURE_PARTICIPANT_A1`                  | `false`                                        |
| `max_upload_kb`              | `ASSINAVELOX_PARTICIPANT_A1_MAX_UPLOAD_KB`            | 64                                             |
| `sealed_path`                | `ASSINAVELOX_PARTICIPANT_A1_SEALED_PATH`              | `storage/app/private/participant-a1`           |
| `sealed_ttl_minutes`         | `ASSINAVELOX_PARTICIPANT_A1_SEALED_TTL_MINUTES`       | 15                                             |
| `application_window_minutes` | `ASSINAVELOX_PARTICIPANT_A1_WINDOW_MINUTES`           | 4320                                           |
| `lock_seconds`               | `ASSINAVELOX_PARTICIPANT_A1_LOCK_SECONDS`             | 900                                            |
| `lock_wait_seconds`          | `ASSINAVELOX_PARTICIPANT_A1_LOCK_WAIT_SECONDS`        | 120                                            |
| `trust_roots`                | `ASSINAVELOX_PARTICIPANT_A1_TRUST_ROOTS` (`;`)        | vazio (cadeia "não verificada")                |
| `accept_test_certificates`   | `ASSINAVELOX_PARTICIPANT_A1_ACCEPT_TEST_CERTIFICATES` | `true` fora de produção                        |
| `reason`                     | `ASSINAVELOX_PARTICIPANT_A1_REASON`                   | "Assinatura com o certificado do participante" |

Plano: `plans.features.participant_a1 = true` (o `PlanSeeder` **não** foi alterado; a flag nasce desligada nos planos).
Em produção, `sealed_path` deve estar num volume não versionado, com permissão só do usuário do serviço; o worker da
fila `finalization` precisa de acesso ao mesmo diretório.

## 11. Regra de correspondência titular × participante

Decisão pendente no roadmap. Adotada a mais conservadora que não depende de cadastro externo: **se o certificado
declara um CPF e o próprio participante informou um CPF neste envelope (campo `cpf`), os dois precisam ser iguais**
(`holder_mismatch`). O nome do titular é comparado ao do participante e exibido (`holder_match.name`), mas **não**
bloqueia (abreviações, nomes sociais e grafias diferentes são comuns). Um CNPJ do e-CNPJ é lido, não comparado.

## 12. Consentimento específico

`ParticipantCertificateConsent`, versão **`v1-a1-participante-2026-09-11`**, separado da declaração de aceite. O texto
resolvido (titular, emissor, série, validade, impressão digital, cada documento com o SHA-256 da versão apresentada)
é gravado no pedido. Itens: autorização de uso único; declaração de titularidade e de conhecimento da senha; a
assinatura entra como revisão incremental depois das evidências e não substitui o aceite; PFX e senha descartados após
o uso; a plataforma não valida a cadeia até a ICP-Brasil nem consulta revogação; sem assinatura no prazo, o documento
conclui sem ela; certificado de teste é dito teste.

> ⚠️ **Escrito pela engenharia de forma conservadora. EXIGE REVISÃO JURÍDICA antes de ligar a flag em produção**
> (`LEGAL_REVIEW_REQUIRED = true`, exposto ao front). Qualquer mudança de palavra exige versão nova.

## 13. Testes

- **pytest** (`tools/pdftool`): `tests/test_participant_cert.py` (13) e `tests/test_participant_sign.py` (8) — prévia só
  com fatos públicos e CPF do `otherName`; senha errada (em processo e em subprocesso) sem vazar; corrompido, truncado,
  sem chave, vencido, ainda não válido, sem uso de assinatura; **duas assinaturas de participantes seguidas preservam a
  primeira**; a operadora por último e a cadeia inteira sã; **adulterar um byte depois quebra a validação** (e ninguém
  assina por cima); campo único; impressão digital; análise da cadeia.
- **Pest** (`tests/Feature/Phase2/ParticipantA1`, 15 testes): pipeline com dois participantes A1 + operadora (três
  assinaturas íntegras, válidas e confiáveis com as raízes de teste; revisões encadeadas byte a byte; hash final depois
  da última; verificação pública com nome mascarado e sem CPF; evidências com CPF mascarado); só participantes
  (`participants_a1`); prazo vencido conclui sem a assinatura; **flag desligada = comportamento atual**; **corrida de duas
  aplicações no mesmo envelope** (lock, compare-and-set, `UNIQUE` da base — nenhuma revisão irmã, uma espera a outra);
  job só com IDs; **PFX e senha nunca em banco, fila, log, trilha ou exceção**; **PFX temporário apagado mesmo em
  falha**; **vencido, senha errada e sem chave recusados com mensagem clara**; **certificado de teste rotulado como
  teste** (e recusado onde não é aceito); consentimento obrigatório e na versão vigente; PFX recusado antes de o
  conteúdo congelar; sem a janela do navegador, 403; material selado (cifra, consumo único, amarração ao pedido,
  adulteração, prazo, expurgo).

## 14. Pendências e limitações (reais)

1. **Front**: a página pública, a de evidências e a de verificação ainda não consomem as props novas (fora desta área).
   Rodar o Wayfinder na integração. O estado do certificado vem por `GET sign.certificate.show`; a integração pode
   dobrá-lo em `SignerPageProps` (arquivo de outra área) se preferir.
2. **Notificação ao participante** quando o documento fica pronto para o envio do certificado: não implementada (a
   tela explica e o participante volta pelo link). Caberia um e-mail no evento `envelope.awaiting_participant_signatures`.
3. **Revogação (LCR/OCSP) e âncoras ICP-Brasil**: não verificadas (sem rede, sem raízes fixadas). Viabilidade §4.3 item 19.
4. **Validação externa** (Verificador do ITI, validador de PDF de terceiros) não foi feita: não há fixture com A1 real.
   O marco "A1 do participante com certificado real" **permanece pendente**, como o da operadora.
5. **Driver `sync`**: sem fila real, o fim do prazo só é conferido quando a finalização roda de novo (outro envio,
   desistência ou despacho manual de `FinalizeEnvelope`). Em produção, com fila, `ApplyParticipantSignatureDeadline` cuida
   disso; nenhum agendamento em `routes/console.php` foi necessário.
6. **Expurgo do material selado órfão** (arquivo cujo pedido sumiu): `SealedCertificateStore::purgeOlderThan()` existe,
   mas não está agendado (agendar junto dos demais comandos na integração).
7. `tools/pdftool/README.md` (arquivo compartilhado) não foi alterado: os três comandos estão documentados aqui (§9).
8. Achado fora da área, **não corrigido**: `tools/pdftool/pdftool/certs.py::describe_pkcs12` usa `InputRejected` sem
   importá-lo (`NameError` quando o PFX da operadora não existe).
9. `VocabularyTest` falha hoje por arquivos de outra área (`app/Services/Timestamp/**`, K-TSA: "ICP-Brasil" junto de
   "carimbo"/TSA na mesma linha); nenhum arquivo desta área é apontado.
