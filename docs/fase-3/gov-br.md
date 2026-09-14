# Fase 3, onda E — PDF assinado no portal gov.br e devolvido (§3.5, P3-GOV)

> Roadmap §3.5 (fluxo alternativo); viabilidade §1.2 (classe **C** na API direta + **B** no fluxo de devolução), §3.2
> (onda E), riscos R3 e R10, correção §6.2; `integracoes/gov-br-assinatura.md` (brief inteiro). Identificadores em
> inglês, texto em português. Flag **`govbr_return`**, nova, **desligada** por padrão.

## 1. Resumo

| Entrega                                              | Onde                                                                          |
| ---------------------------------------------------- | ----------------------------------------------------------------------------- |
| Contrato da API direta (classe C, sem implementação) | `app/Integrations/GovBr/GovBrSignatureProvider.php`                           |
| Fluxo de devolução (reserva, download, conferência)  | `app/Services/Signing/GovBr/**`                                               |
| Rotas do participante (JSON + download)              | `app/Http/Controllers/Sign/GovBrReturnController.php`, `routes/web.php`       |
| `pdftool verify-incremental`                         | `tools/pdftool/pdftool/incremental.py` (registrado em `cli.py`)               |
| Tabelas                                              | `external_signature_requests` (150101), `external_signature_returns` (150102) |
| Configuração                                         | `config/assinavelox.php` → `govbr`                                            |
| Testes                                               | `tests/Feature/Phase3/GovBr/**`, `tools/pdftool/tests/test_incremental.py`    |

O participante escolhe "assinar no gov.br"; quando o documento está pronto, o sistema **reserva** a revisão mais recente
(hash registrado), o participante **baixa** exatamente esses bytes, assina no `assinador.iti.br` e **devolve** o arquivo. O
servidor aceita **somente** o arquivo que estende a revisão reservada com exatamente uma assinatura nova e nada mais
(§4). Nada de aceitar "qualquer PDF com assinatura válida".

**Com a flag desligada nada muda**: as seis rotas respondem 404, nenhuma linha é criada, e a finalização não é tocada
(nenhum arquivo dela foi alterado). Os testes existentes não mudaram de asserção.

## 2. Classificação e o que está bloqueado

| Frente                                                   | Classe | Situação nesta entrega                                                                                                                                                                                                                                                      |
| -------------------------------------------------------- | ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| API direta (OAuth no CAS do ITI + `assinarPKCS7`)        | **C**  | **Bloqueada.** O AssinaVelox não é elegível: a credencial é só para órgão público, com serviço público, Login Único e `redirect_uri` em domínio de governo (Portaria SGD/MGI 7.076/2024). Só a interface documentada, sem adaptador, sem fake, sem binding (teste garante). |
| Fluxo de devolução (`assinador.iti.br` → upload)         | **B**  | Implementado com a regra de aceitação completa e testado com PDFs de um **simulador de teste**. Produção desabilitada até as pendências de §9.                                                                                                                              |
| Validação automática pelo VALIDAR (`validar.iti.gov.br`) | **C**  | Não existe API. Uso só manual, como checklist de release e para produzir a fixture real.                                                                                                                                                                                    |

**O que desbloquearia a API direta** (todos): (1) um órgão público cliente que peça a credencial para um serviço público
dele; (2) implantação sob o domínio oficial desse órgão, com Login Único; (3) aceite por escrito da SGD
(`integracaoid@gestao.gov.br`) de que um produto de terceiro nesse arranjo é admitido (NÃO CONFIRMADO). Mesmo assim seria
um projeto por cliente, não uma funcionalidade do SaaS.

## 3. Semântica (T1, T2, T3)

`GovBrSignatureKind` (valor gravado em `external_signature_requests.signature_kind`; candidato a
`verification_records.signature_status` na integração, §8):

| valor                             | quando                                                                                                             | rótulo                                                  |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------- |
| `participant_govbr`               | vínculo com a revisão reservada conferido **e** cadeia validada até uma âncora gov.br fixada por impressão digital | "Assinatura gov.br (avançada)"                          |
| `participant_external_unverified` | qualquer outro caso aceito (sem âncora configurada)                                                                | "Assinatura digital de terceiro, cadeia não verificada" |

- **Sem âncora, nunca "gov.br"**, mesmo que a assinatura seja íntegra (teste explícito, inclusive quando o pdftool diria
  "trusted"). Com âncora configurada, cadeia que não confere é **recusada** (não rebaixada).
- "(avançada)" é o nível da Lei 14.063/2020 para contas prata/ouro. Nunca "qualificada", nunca "ICP-Brasil" (a descrição
  diz isso). Certificado de teste recebe o sufixo "— certificado de TESTE".
- **Perfil:** nada é anunciado sobre a assinatura devolvida (`profile: null`, `timestamp: "not_evaluated"`,
  `long_term_validation: false`, `revocation: "not_checked"`). O B-B da operadora continua como está (T2).
- Carimbo do tempo: esta área não aplica nem afirma carimbo algum (T3).
- A assinatura no portal **soma-se** ao aceite eletrônico; não o substitui, e uma recusa nunca invalida aceite.

## 4. A regra de aceitação

Tudo é conferido no servidor, offline, sem confiar em nada do arquivo recebido. A decisão é
`GovBrReturnDecision::decide()` sobre a saída do `pdftool verify-incremental`.

| #   | Exigência                                                                                                                                                   | Código de recusa                                                                 |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| a1  | os bytes da revisão reservada são **prefixo exato** do arquivo devolvido (conferido em PHP antes do Python e de novo no pdftool)                            | `base_not_prefix` (inclui o caso do portal **regravar** o arquivo)               |
| a2  | exatamente **uma** revisão acrescentada                                                                                                                     | `no_new_revision`, `unexpected_revision_count`                                   |
| b1  | exatamente **uma** assinatura nova; nenhum carimbo de documento novo                                                                                        | `no_new_signature`, `multiple_new_signatures`, `unexpected_document_timestamp`   |
| b2  | a assinatura nova é íntegra, válida e cobre o **arquivo inteiro**                                                                                           | `signature_not_intact`, `signature_invalid`, `signature_not_covering_file`       |
| b3  | a revisão da assinatura **não muda nada além do que uma assinatura traz** (análise objeto a objeto, abaixo)                                                 | `unpermitted_changes`                                                            |
| b4  | assinaturas anteriores (A1 de participantes) continuam íntegras, com mudanças posteriores permitidas; sem violação de DocMDP                                | `previous_signature_broken`, `docmdp_violation`                                  |
| b5  | certificação que proíbe mudanças (`DocMDP P=1`) é recusada — os demais e a operadora ainda assinam depois; `P=2` é aceita                                   | `docmdp_locks_document`                                                          |
| c   | com âncoras configuradas, a cadeia confere com elas                                                                                                         | `chain_not_trusted`; âncora mal configurada → `trust_anchor_misconfigured` (503) |
| d   | com CPF informado pelo participante (campo `cpf`), o CPF do certificado confere; com `require_holder_cpf`, ele precisa existir                              | `holder_mismatch`, `holder_cpf_not_found`                                        |
| d2  | sem CPF informado, o nome do titular do certificado corresponde ao do participante                                                                          | `holder_name_mismatch`, `holder_name_not_found`                                  |
| —   | sob o lock do envelope, o pedido ainda é a MESMA reserva (pending, mesma revisão); duas devoluções simultâneas: a perdedora não mexe no pedido já concluído | `already_completed` (409), `not_reserved` (409)                                  |
| —   | certificado de teste só onde `accept_test_certificates`                                                                                                     | `test_certificate_not_accepted`                                                  |
| —   | reserva vigente e revisão reservada ainda a mais recente (compare-and-set sob o lock do envelope)                                                           | `reservation_expired` (409), `base_changed` (409)                                |

**Análise da revisão da assinatura (b3).** A análise de diferenças do pyHanko revisa mudanças feitas **depois** de uma
revisão assinada; aqui a base normalmente **não tem assinatura nem AcroForm** (o pyHanko falha com
`PdfReadError`) e a pergunta é a inversa. Por isso `incremental.py` compara objeto a objeto a revisão acrescentada com a
reservada. São admitidos **somente**: o campo novo da assinatura e o seu widget (com aparência — o carimbo visível); o
AcroForm ganhando esse campo; o catálogo ganhando `/AcroForm`, `/Extensions`, `/Version` (e `/Perms` só para certificação
ligada à assinatura nova); o `/Annots` de uma página crescendo pelo widget; metadados (`/Info`, `/Metadata`). Qualquer
outro objeto existente reescrito, página acrescentada/removida, outro campo ou anotação que não seja widget (nível
`ANNOTATIONS`, fora do padrão `NONE,FORM_FILLING`) é recusado. Risco residual conhecido e comum a qualquer assinatura
PDF: a aparência do widget pode se sobrepor ao conteúdo da página.

**CPF (d).** O CPF que o participante informou vai ao pdftool pela **variável de ambiente** `AV_GOVBR_EXPECTED_CPF`
(nunca argv — o argv vai para log); o pdftool devolve só `match`/`mismatch`/`unknown` e o CPF do certificado
**mascarado**; nomes de certificado com `NOME:CPF` saem mascarados. Onde o CPF fica num certificado gov.br é **NÃO
CONFIRMADO**; o código lê o `otherName 2.16.76.1.3.1` (layout ICP-Brasil) e o sufixo `:CPF` do CN — a leitura correta
precisa ser confirmada com a fixture real (§9). Com `require_holder_cpf` (padrão), certificado sem CPF legível é recusado
quando o participante informou CPF.

**Nome, sem CPF informado (revisão adversarial I-3A).** A decisão anterior ("sem CPF informado, nada é comparado; o
nome só é exibido") contradizia o brief (docs/integracoes/gov-br-assinatura.md §6, item 3: o certificado "precisa
corresponder ao participante: nome e CPF") e o rótulo T1: qualquer conta gov.br com cadeia até a âncora assinaria "pelo"
participante e viraria `participant_govbr`. Agora, sem CPF informado, o **nome do titular** do certificado precisa
corresponder ao do participante (`GovBrReturnDecision::namesCorrespond`): sem acento e sem caixa, palavra a palavra, o
primeiro nome igual e todas as demais palavras do nome cadastrado (fora "de", "da", "dos"…) presentes no nome do
certificado — o cadastro costuma abreviar, o certificado traz o nome completo; o sufixo `:CPF` do CN e o marcador `TESTE`
são ignorados. Nome diferente → `holder_name_mismatch` (422); certificado sem nome legível → `holder_name_not_found`.
Nome não é identificador único (homônimos): o CPF continua sendo a conferência forte, e a recomendação para produção é
exigir o campo CPF nos envelopes que oferecem a devolução gov.br (pendência do §9).

## 5. Fluxo, estados e concorrência

```
 choose ──intenção──► requested ──(documento pronto)── reservar ──► pending ──devolver (aceito)──► completed
                         ▲  ▲                                        │   │
                         │  └──── reserva venceu / base mudou ◄──────┘   └── devolver (recusado): continua pending
                         └──── desistir ──► withdrawn                          (tentativas e motivo registrados)
 prazo total vencido ──► expired          envelope concluído/encerrado ──► closed
```

- **Pronto** = envelope em `finalizing`, base congelada (`pre_signature`) existente para cada documento, sem `final`.
- **Um participante por vez por documento**: outra reserva vigente no mesmo documento → `reservation_busy` (409), com o
  horário em que vence. O arquivo devolvido é **a** nova revisão, e as seguintes se empilham sobre ela (brief §6.4.5).
- **Compare-and-set**: a revisão só entra se a reservada ainda for a mais recente (`lockForUpdate` no documento, sob o
  lock `envelope:{id}:sign` — o mesmo das assinaturas A1 e da operadora). Se um A1 entrou antes, a devolução é recusada
  com `base_changed` e o participante reserva de novo: a plataforma não pode refazer assinatura de terceiro. Nunca revisão
  irmã (teste).
- Reserva: `reservation_ttl_minutes` (120). Prazo total: `application_window_minutes` (4320), contado da primeira reserva
  (ou da primeira espera da finalização, na integração).
- Multi-documento: um pedido por participante **e documento**; a reserva cobre todos os documentos; cada um é assinado e
  devolvido à parte.
- Toda devolução, aceita ou recusada, fica em `external_signature_returns` (somente inclusão): SHA-256 e tamanho do que
  chegou, a revisão esperada, o resultado, o código e as conferências técnicas **sem** nome nem CPF. O arquivo recusado não
  é guardado.

## 6. Contrato para o front (sem mudança em `resources/js`)

Grupo público `assinar/{token}` (`throttle:signer` + `signer`). 404 com a flag desligada, papel sem assinatura (aprovador,
visualizador) ou convite recusado/expirado/cancelado. Autenticação no serviço: sessão do código **ou** janela de download
deste navegador; sem ela, `403 {code: "not_authenticated"}`. Rodar `php artisan wayfinder:generate --with-form` na
integração.

| Rota                  | Método e caminho                              | Corpo                             | Resposta                                      | Limite    |
| --------------------- | --------------------------------------------- | --------------------------------- | --------------------------------------------- | --------- |
| `sign.govbr.show`     | `GET assinar/{token}/gov-br`                  | —                                 | `200` estado                                  | 60/min    |
| `sign.govbr.intent`   | `POST …/gov-br/intencao`                      | —                                 | `201` estado                                  | 20/10 min |
| `sign.govbr.withdraw` | `POST …/gov-br/desistir`                      | —                                 | `200` estado                                  | 20/10 min |
| `sign.govbr.reserve`  | `POST …/gov-br/reservar`                      | —                                 | `200` estado (com `expected_revision`)        | 20/10 min |
| `sign.govbr.download` | `GET …/gov-br/{pedido}/revisao`               | —                                 | `200` PDF (`attachment`), os bytes reservados | 30/10 min |
| `sign.govbr.upload`   | `POST …/gov-br/{pedido}/devolver` (multipart) | `file` (PDF, até `max_upload_mb`) | `201` estado                                  | 10/10 min |

`{pedido}` = `documents[].request.id` (ULID).

**Estado:**

```ts
type GovBrReturnState = {
    available: true;
    authenticated: boolean;
    stage:
        | 'choose'
        | 'awaiting_others'
        | 'ready_to_reserve'
        | 'reserved'
        | 'completed'
        | 'expired'
        | 'closed';
    ready: boolean; // documento pronto para reservar
    message: string | null; // por que ainda não (PT-BR)
    can_request: boolean;
    can_withdraw: boolean;
    can_reserve: boolean;
    can_upload: boolean;
    trust: {
        anchors_configured: boolean;
        accepted_kind: 'participant_govbr' | 'participant_external_unverified'; // o que um aceite gravaria HOJE
        accepted_label: string;
        revocation_checked: false;
    };
    documents: Array<{
        document: { id: string; name: string; position: number };
        request: null | {
            id: string;
            status:
                | 'requested'
                | 'pending'
                | 'completed'
                | 'expired'
                | 'withdrawn'
                | 'closed';
            status_label: string;
            expected_revision: null | {
                sha256: string;
                size_bytes: number;
                download_url: string | null;
            };
            upload_url: string | null; // só com reserva vigente e sessão
            reserved_at: string | null;
            expires_at: string | null; // só com reserva vigente
            window_expires_at: string | null;
            completed_at: string | null;
            attempts: number;
            failure: null | { code: string; message: string }; // motivo da última recusa/liberação
            last_return: null | {
                outcome: 'accepted' | 'rejected';
                rejection_code: string | null;
                received_sha256: string;
                received_at: string;
            };
            signature: null | {
                kind: string;
                label: string;
                description: string;
                holder_name: string | null;
                holder_cpf_masked: string | null;
                issuer: string | null;
                fingerprint_sha256: string | null;
                valid_from: string | null;
                valid_to: string | null;
                trusted: boolean;
                is_test: boolean;
                signed_at: string | null;
            };
        };
    }>;
    instructions: string[]; // passos PT-BR (reservar, assinar no portal com conta prata/ouro, devolver sem regravar)
    limits: {
        max_upload_mb: number;
        accepted_extensions: ['pdf'];
        reservation_ttl_minutes: number;
        application_window_minutes: number;
    };
    notices: string[]; // inclui, sem âncora, o aviso de que o aceite será "cadeia não verificada"
    portal_url: string;
    endpoints: {
        show: string;
        intent: string;
        withdraw: string;
        reserve: string;
    };
};
```

**Erros:** `{message, code, errors: {<campo>: [message]}}`. `422` recusas do arquivo (códigos de §4, mais `not_pdf`,
`file_too_large`, `upload_failed`, `invalid_pdf`, `encrypted_pdf` e a validação do formulário); `409` `not_ready`,
`reservation_busy`, `reservation_expired`, `base_changed`, `not_reserved`, `already_completed`, `envelope_closed`,
`window_closed`, `cannot_withdraw`, `busy`; `403 not_authenticated`; `404 not_found` (pedido de outro participante);
`503 verification_unavailable`, `trust_anchor_misconfigured`. `message` é o texto a mostrar.

Fluxo sugerido na página pública: opção "assinar também no gov.br" (→ `intent`); com `ready`, "Reservar versão para
assinar" (→ `reserve`) → botão de download por documento (`expected_revision.download_url`) + link para `portal_url` +
`instructions` → campo de arquivo por documento (`upload_url`) → mostrar `failure.message` numa recusa e
`signature.label` no aceite. Mostrar `notices` sempre (o aviso de "cadeia não verificada" é obrigatório sem âncora).

## 7. Modelo de dados (aditivo, MySQL-compatível)

`2026_09_11_150101_create_external_signature_requests_table` — nomes do roadmap (`recipient_id`, `provider`,
`expected_revision_sha256`, `status`, `expires_at`) mais: `document_id`, `expected_document_version_id`,
`expected_revision_size`, `reserved_at`, `window_expires_at`, `attempts`, `failure_code/message`,
`signed_document_version_id`, `signature_kind`, `trusted`, fatos públicos do certificado (assunto/emissor com CPF
mascarado, série, impressão digital, validade), `holder_name`, `holder_cpf_masked`, `holder_cpf_match`,
`is_test_certificate`, `validation_result` (conferências, sem dado pessoal), `completed_at`, `closed_at`.
`UNIQUE(envelope_id, recipient_id, document_id)`. Chaves para `document_versions` com `nullOnDelete` (a finalização
descarta revisões nunca publicadas quando refaz a base; o estágio reabre o pedido).

`2026_09_11_150102_create_external_signature_returns_table` — uma linha por devolução, sem `updated_at`.

Os modelos ficam em `App\Services\Signing\GovBr\Models` (escopo desta parte); podem ir para `app/Models` na integração
sem mudar as tabelas.

## 8. Integração com a finalização — FEITA na integração I-3A (itens 1–5); 6–9 seguem pendentes

> **Atualização I-3A (2026-09-14).** Os itens 1 a 5 abaixo foram integrados em `ParticipantSignatureStage`
> (que recebe `GovBrReturnStage`): pedido gov.br conta como pedido ativo, segura a finalização enquanto espera, soma na
> quantidade esperada de assinaturas da cadeia, é reaberto quando a base é refeita, e o `signature_status` ganhou
> `participant_govbr` / `participant_external_unverified` (com mais de um meio externo no mesmo arquivo, o registro fica
> `participant_external` e a lista por assinatura traz o meio de cada uma). As páginas de evidências e de verificação
> pública listam a devolução (`GovBrSignatureViews`). A trava `finalizer_integration` continua existindo (padrão
> `false`), mas agora pode ser ligada junto com `return_enabled` — o ponta a ponta `tests/Feature/EndToEnd/Phase3PartOneTest.php`
> roda com as duas ligadas. Continuam pendentes: 6 (espera do A1 enquanto houver reserva gov.br — hoje o participante
> gov.br perde a reserva com `base_changed`), 7 (eventos próprios em `audit_events`), 8 e 9. Relatório:
> `docs/fase-3/parte-1-relatorio.md`.

O `EnvelopeFinalizer`, o `ParticipantSignatureStage`, `App\Enums\SignatureStatus` e `App\Enums\AuditEventType` não são
desta área e **não foram alterados**. Por isso a flag tem a **trava** `assinavelox.govbr.finalizer_integration`
(`ASSINAVELOX_GOVBR_FINALIZER_INTEGRATION`, padrão `false`): sem ela, `GovBrReturnFeature::enabledFor()` é sempre falso.
Ligar a flag sem a integração faria a finalização (a) concluir sem esperar a devolução quando não houver pedido A1, e
(b) recusar o arquivo quando houver, porque a cadeia teria uma assinatura a mais do que ela conta. O que a integração
precisa fazer, usando `GovBrReturnStage`:

1. `EnvelopeFinalizer`: entrar em `participantsPipeline` quando `participants->activeFor($envelope) || govbr->activeFor($envelope)`,
   e considerar `participant_mode` da página de evidências da mesma forma (texto sobre assinaturas acrescentadas depois
   da página, que precisa citar a devolução gov.br);
2. no bloqueio sob o lock: `if ($this->participants->awaiting(...) || $this->govbr->awaiting($envelope, $cid)) return null;`
3. `participantFinal()`: `$expected = signatureCount(A1) + govbr->signatureCount($document) + (operadora ? 1 : 0)`;
4. `signature_status`: acrescentar a `App\Enums\SignatureStatus` os casos `participant_govbr` e
   `participant_external_unverified` (ou uma combinação "misto", a decidir) com os rótulos de §3, e mapear a partir de
   `govbr->kindsFor($document)`; as páginas de evidências e verificação pública passam a listar a devolução com o
   rótulo honesto (nome mascarado, sem CPF);
5. `ParticipantSignatureStage::resetDocument()` → também `govbr->resetDocument($document)`; ao concluir/cancelar →
   `govbr->closeFor($envelope)`;
6. `ParticipantSignatureApplier` (A1): antes de assinar, esperar enquanto houver reserva gov.br vigente no documento
   (hoje o compare-and-set impede revisão irmã, mas o participante gov.br perde a reserva — `base_changed`);
7. eventos em `audit_events` (catálogo T7): `govbr_signature.requested`, `.reserved`, `.uploaded`, `.verified`,
   `.rejected`, `.expired` — o `AuditEventType` é fora desta área; enquanto isso a trilha fica em
   `external_signature_returns` e no log (sem dado pessoal). `EnumCatalogTest` precisa ser estendido junto;
8. `SignerPageProps` pode dobrar o estado (`sign.govbr.show`) nas props da página pública;
9. convergir a reserva de revisão com a do P3-EXT (`pending_external_signatures`, A3), que tem o mesmo papel (R10).

Depois disso, ligar `finalizer_integration` e rodar o teste ponta a ponta (base → devolução → operadora por último →
`verification_records`), que hoje não existe porque depende desses arquivos.

## 9. Pendências para ligar em produção (NÃO CONFIRMADO no brief §8)

1. **Fixture real**: um PDF assinado no `assinador.iti.br` por uma conta **prata/ouro** de teste (pendência 15 do
   proprietário), conferido **manualmente no VALIDAR** e registrado num checklist. Com ela: medir `SubFilter`, carimbo do
   tempo, DocMDP, forma do carimbo visual (widget × anotação livre — pode exigir `ANNOTATIONS` em
   `permitted_modification_levels`, só depois de revisar), e transformá-la em teste de contrato.
2. **Atualização incremental**: confirmar que o portal **acrescenta** a assinatura em vez de regravar o arquivo. Se
   regravar, este fluxo **recusa** tudo (`base_not_prefix`, testado) e o item volta para reavaliação (o vínculo passaria a
   depender de comparação de conteúdo renderizado, mais fraca — não implementado de propósito).
3. **Raiz gov.br fixada**: extrair a cadeia do CMS da fixture, conferir contra o arquivo do link oficial (hospedado na
   UFSC) e contra o VALIDAR, e registrar o SHA-256 de cada certificado em `ASSINAVELOX_GOVBR_TRUST_ROOT_FINGERPRINTS`,
   com origem e data de verificação. Nada é baixado em execução. Revogação (CRL/OCSP) da AC gov.br: NÃO CONFIRMADA;
   `revocation: not_checked`.
4. **CPF no certificado**: confirmar onde o certificado gov.br traz o CPF e ajustar `holder_facts` se não for o layout
   ICP-Brasil (`otherName 2.16.76.1.3.1`) nem o sufixo do CN.
5. **Integração com a finalização** (§8) e a trava `finalizer_integration`.
6. **Jurídico**: cláusula de aceitação desse meio pelas partes nos termos do envelope (Lei 14.063/2020 vale nas relações
   com o poder público; entre particulares, MP 2.200-2 art. 10 §2º) — pendência 26 do proprietário.
7. **Front** (fora desta área): a tela da página pública consumindo o contrato de §6.
8. Pré-requisito da Fase 3: Fase 2 em produção por um ciclo de cobrança.

## 10. Configuração (`config/assinavelox.php` → `govbr`)

| chave                           | env                                                | padrão                                    |
| ------------------------------- | -------------------------------------------------- | ----------------------------------------- |
| `return_enabled`                | `ASSINAVELOX_FEATURE_GOVBR_RETURN`                 | `false`                                   |
| `finalizer_integration`         | `ASSINAVELOX_GOVBR_FINALIZER_INTEGRATION`          | `false` (trava, §8)                       |
| `portal_url` / `validator_url`  | `ASSINAVELOX_GOVBR_PORTAL_URL` / `…_VALIDATOR_URL` | `assinador.iti.br` / `validar.iti.gov.br` |
| `reservation_ttl_minutes`       | `ASSINAVELOX_GOVBR_RESERVATION_TTL_MINUTES`        | 120                                       |
| `application_window_minutes`    | `ASSINAVELOX_GOVBR_WINDOW_MINUTES`                 | 4320                                      |
| `max_upload_mb`                 | `ASSINAVELOX_GOVBR_MAX_UPLOAD_MB`                  | 100 (limite do portal)                    |
| `verify_timeout_seconds`        | `ASSINAVELOX_GOVBR_VERIFY_TIMEOUT`                 | 180                                       |
| `lock_wait_seconds`             | `ASSINAVELOX_GOVBR_LOCK_WAIT_SECONDS`              | 20                                        |
| `trust_roots`                   | `ASSINAVELOX_GOVBR_TRUST_ROOTS` (`;`)              | vazio → "cadeia não verificada"           |
| `trust_root_fingerprints`       | `ASSINAVELOX_GOVBR_TRUST_ROOT_FINGERPRINTS` (`;`)  | vazio                                     |
| `require_holder_cpf`            | `ASSINAVELOX_GOVBR_REQUIRE_HOLDER_CPF`             | `true`                                    |
| `accept_test_certificates`      | `ASSINAVELOX_GOVBR_ACCEPT_TEST_CERTIFICATES`       | `true` fora de produção                   |
| `permitted_modification_levels` | `ASSINAVELOX_GOVBR_PERMITTED_LEVELS` (`,`)         | `NONE,FORM_FILLING`                       |
| `api.provider`                  | —                                                  | `none` (classe C, fixo)                   |

Plano: `plans.features.govbr_return = true` (o `PlanSeeder` não foi alterado). O limite de upload também depende de
`upload_max_filesize`/`post_max_size` do PHP.

## 11. pdftool — `verify-incremental`

`verify-incremental --base <revisão reservada> --in <devolvido> [--trust <pem|der>]… [--permitted-level NONE|FORM_FILLING|ANNOTATIONS]… [--expect-cpf-env <VAR>]`

Sem rede. Recusa por política **não** é erro: exit 0 com `accepted: false` e `problems` (códigos de §4, na ordem de
prioridade). Exit 4 só para arquivo ausente, ilegível ou cifrado. Saída: `accepted`, `problems`, `permitted_levels`,
`base {size, sha256, revisions, signature_count}`, `returned {…}`, `prefix_preserved`, `new_revisions`,
`new_signature_count`, `changes_since_base {modification_level, suspicious, detail[], notes[]}`,
`previous_signatures_ok`, `trust_roots_configured`, `revocation: "not_checked"`, `signature {field_name, intact, valid,
trusted, trust_reason, coverage, modification_level, docmdp_ok, is_certification, docmdp_permission, subfilter,
md_algorithm, signing_time, signer_subject (CPF mascarado), issuer, serial_hex, cert_fingerprint_sha256, not_before,
not_after, test_certificate, holder {name, cpf_masked, cpf_source, cpf_present, cpf_match, cpf_confirmed: false},
errors[]}`. O `README.md` do pdftool (compartilhado) não foi alterado; o comando está documentado aqui.

## 12. Testes

- **pytest** (`tools/pdftool/tests/test_incremental.py`, 14): página reescrita pelo assinador com os mesmos números
  (base do dompdf grava `595.280`, o pyHanko regrava `595.28`) **não** conta como alteração — números são comparados
  pelo valor; devolução correta aceita sem raiz (nunca `trusted`) e com a
  raiz certa; outra cadeia recusada; outro documento e arquivo **regravado** recusados (`base_not_prefix`); duas
  assinaturas; atualização depois da assinatura (não cobre o arquivo); conteúdo alterado **na mesma revisão** da
  assinatura; atualização sem assinatura; certificação `P=1` recusada e `P=2` aceita; carimbo visível aceito; CPF
  comparado dentro do pdftool, só o mascarado sai (nem o do certificado nem o informado aparecem no stdout); assinatura A1
  anterior preservada; entrada ausente = exit 4.
- **Pest** (`tests/Feature/Phase3/GovBr`): fluxo HTTP completo com pdftool real e o **simulador de portal**
  (`Support/portal_simulator.py`, certificado de TESTE): aceite com rótulo honesto e bytes da reserva como prefixo;
  rótulo gov.br só com raiz fixada e cadeia conferida; outra cadeia recusada; âncora sem impressão digital → 503; prefixo
  (outro documento, regravado); duas assinaturas, não cobre o arquivo, conteúdo alterado, certificação `P=1`; carimbo
  visível aceito; CPF divergente recusado e o do titular aceito sem CPF completo nas tabelas; certificado sem CPF com
  exigência; pedido **expirado** recusado e download bloqueado; `base_changed` (nunca revisão irmã); um participante por
  vez; arquivo que não é PDF; sem sessão 403 e pedido de outro 404; desistência; **flag desligada** (global, trava, plano),
  aprovador e visualizador = 404 em todas as rotas e nenhuma linha criada. Regras sem PDF: decisão, rótulos, âncoras
  fixadas, flag e trava, API direta sem implementação nem binding, estágio (espera, reserva vencida, prazo total,
  contagem, reabertura, encerramento).

O simulador **não** é o portal gov.br nem um modelo dele: produz, com certificado de teste, as formas que a regra de
aceitação precisa tratar. A fixture real (§9.1) é o que falta para afirmar que o portal produz uma delas.
