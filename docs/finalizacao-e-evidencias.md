# Finalização, página de evidências e assinatura da operadora

> Contrato do incremento 4 (arquitetura `docs/arquitetura.md` §2, §3.1, §3.3 e §5 item 7).
> Ferramenta Python: `tools/pdftool/README.md`. Camada PHP do PDF: `docs/pdf-pipeline.md`.
> Textos legais: `docs/juridico/declaracao-de-aceite.md` §5 e §6.
> Código: `app/Jobs/Envelopes/FinalizeEnvelope.php`, `app/Listeners/Envelopes/StartEnvelopeFinalization.php`,
> `app/Services/Envelopes/Finalization/**`, `resources/views/evidence/page.blade.php`,
> `config/assinavelox.php` (seção `evidence`).

---

## 1. Onde a finalização começa

O incremento 3 termina assim: quando o **último aceite exigido** é gravado,
`RecordAcceptance::advance()` move o envelope para `finalizing` sob lock, gera
`envelopes.finalization_key` (se ainda não houver) e dispara o evento
`App\Events\EnvelopeReadyForFinalization` com **apenas ids**.

O listener `StartEnvelopeFinalization` consome esse evento e **só enfileira**
`App\Jobs\Envelopes\FinalizeEnvelope` na fila `finalization`. Nenhuma decisão fica no
listener: um segundo lugar onde decidir seria um segundo lugar onde errar.

O evento é registrado por **descoberta automática** (o Laravel varre `app/Listeners`).

### Por que o listener captura a falha do despacho

Com um driver de fila real (`database` em desenvolvimento, Redis/Horizon em produção),
`dispatch()` só grava a mensagem: a finalização roda em outro processo e uma falha nunca
volta para a requisição. Com o driver **`sync`** (padrão da suíte de testes, e possível em
instalações pequenas) o job roda **dentro da requisição do signatário**. Uma falha ali
viraria um HTTP 500 para quem acabou de assinar — depois de o aceite já estar commitado.

Por isso o listener captura e registra em nível `error` (com `alert =
envelope_finalization_dispatch_failed`). Nada de estado se perde: o próprio job já gravou
`envelope.finalization_failed` na trilha, o envelope continua em `finalizing`, nenhum
arquivo é publicado, e a interface continua dizendo "Em andamento · finalizando".

---

## 2. O pipeline, etapa a etapa

```
                       envelopes.sent_document_version_id  (versão CONGELADA no envio)
                                          │
 (a) consolidação   pdftool compose ──────┤  campos AUTORIZADOS achatados
                                          │  + rodapé "Verifique em … · código …" em toda página
                                          ▼
                        DocumentVersion(kind = consolidated)  ── sha256 consolidado
                                          │
 (b) evidências     Blade → DOMPDF ───────┤  identificação, participantes, linha do tempo,
                                          │  3 hashes + explicação, QR code, rodapé
                                          ▼
                        DocumentVersion(kind = evidence)
                                          │
 (c) junção         pdftool append ───────┤  consolidado + evidências
                                          ▼
                        arquivo pré-assinatura (só no diretório temporário)
                                          │
 (d) assinatura     pdftool sign + validate  ── SE PdfSigner::isConfigured();
                                          │     senão a etapa é PULADA
                                          ▼
 (e) versão final       DocumentVersion(kind = final)  ── sha256 FINAL, calculado
                                          │                DEPOIS da assinatura
                                          ▼
 (f) verificação        VerificationRecord (4 hashes, signature_status, perfil,
                                          │  certificado, validation_result)
                                          ▼
 (g) conclusão          envelopes.status = completed, final_document_version_id,
                        trilha, consumo do plano, notificações e link de download
```

### (a) Consolidação — `ConsolidationPlanner`

Monta o plano JSON do `pdftool compose` a partir dos **campos autorizados** e o aplica sobre
a versão congelada.

**"Autorizado" tem uma definição estreita, e é de propósito**: um valor só entra no PDF se
existir um `SignatureAcceptance` gravado do próprio envelope apontando para ele
(`signing_field_values.signature_acceptance_id`), **e** se o campo pertencer à
`sent_document_version_id`. Quem recusou, expirou ou nunca chegou a aceitar não deixa
nenhum traço no documento: escrever no PDF um valor sem aceite seria afirmar uma
manifestação de vontade que não existe.

Coordenadas: frações `[0,1]` da página **como exibida** (CropBox após `/Rotate`), origem no
canto superior esquerdo — o mesmo referencial de `signing_fields`, do editor e da tela do
signatário (`docs/campos-e-geometria.md` §1). Por isso a **rotação de página é respeitada
sem nenhuma conta extra aqui**: o `pdftool` faz a conversão para o espaço do usuário e
desenha o conteúdo em pé em relação à exibição.

Por tipo:

| Tipo                    | O que é desenhado                                              |
| ----------------------- | -------------------------------------------------------------- |
| `signature`, `initials` | o PNG normalizado de `signing_field_values.image_path`         |
| `name`, `date`, `text`  | `value_text` (a data já foi carimbada pelo servidor no aceite) |
| `checkbox`              | a marca, **só** quando `value_bool = true`                     |

**Assinatura digitada**: quando o participante escolheu "digitar", não existe imagem —
existe `signature_acceptances.typed_name`. O campo de assinatura é então desenhado como
**texto** dentro do mesmo retângulo. A fonte manuscrita da tela (Caveat) é carregada pelo
navegador e não existe no servidor, então o nome sai na fonte padrão do PDF; `typed_font`
continua gravado como evidência do que a pessoa viu. Ver §7 (limitações).

**Rodapé impresso** (`docs/juridico` §6): `Verifique em {url} · código XXXX-XXXX-XXXX`, uma
linha em todas as páginas do documento consolidado, carimbada **nesta etapa** para ficar
coberta pela assinatura criptográfica quando ela existir. O rodapé nunca traz nomes,
e-mails, IPs nem hashes. Geometria e fonte em `config/assinavelox.php → evidence.footer`.

O que a composição **não** faz: não altera cláusula, não reescreve texto, não move nada. Só
achata valores nas coordenadas onde os campos foram apresentados.

### (b) Página de evidências — `EvidenceData` + `EvidenceRenderer` + `resources/views/evidence/page.blade.php`

Gerada **antes** da assinatura, para ficar coberta por ela. Blade → DOMPDF (a única
biblioteca de PDF que roda dentro do PHP; todo o resto é o pdftool), sem rede
(`isRemoteEnabled = false`), com o QR code embutido como `data:` URI.

Conteúdo:

1. **Identificação** — título, código de verificação formatado `XXXX-XXXX-XXXX`,
   organização remetente, datas (criação, envio, conclusão) no fuso da organização, ordem de
   assinatura, número de páginas, versão do texto de aceite.
2. **Participantes** — nome, e-mail, situação, data do aceite (local **e** UTC), método de
   autenticação, IP e user-agent, tipo de representação visual, motivo da recusa quando
   houver. O IP obedece `organizations.settings.evidence_show_ip` (`masked` | `full` |
   `none`) pela **mesma** classe que a interface usa (`App\Support\IpDisplay`), para que a
   tela e o PDF nunca discordem.
3. **Linha do tempo** — eventos relevantes de `audit_events`, com a lista em
   `config('assinavelox.evidence.timeline_events')` e teto em `timeline_limit`.
4. **Os hashes e o que cada um identifica** — a tabela do `docs/juridico` §5.1, com
   **três** valores impressos (original, enviado, consolidado) e a explicação de por que o
   quarto não está ali.
5. **QR code** apontando para `{app.url}/verificar/{code}` — só a URL pública, nada de
   dados pessoais.
6. **Bloco sobre a assinatura criptográfica**, na variante que corresponde ao resultado
   real (§4), incluindo o aviso destacado quando o certificado é de ambiente de teste. O
   certificado impresso é o **do PKCS#12 configurado**, identificado por
   `pdftool cert-info` antes da assinatura (`OperatorSignature::configuredCertificate()`) —
   não a linha mais recente de `certificate_references`. As linhas dessa tabela só nascem
   depois de uma assinatura bem-sucedida, então adivinhar por ali fazia a primeira
   finalização com um certificado novo (rotação, troca de teste para produção ou o
   contrário) imprimir o titular, o emissor e a validade de um certificado que não tocou
   naquele arquivo — e omitir o aviso obrigatório de ambiente de teste, porque o aviso segue
   o ambiente do certificado impresso. Não sendo possível identificar o certificado
   (adaptador sem PKCS#12, pdftool fora do ar, senha errada), a página **não imprime
   certificado nenhum**: imprimir outro seria pior.
7. **Linha de encerramento** — "não é um certificado digital", referências normativas a
   avaliar caso a caso, URL e código.

Fonte: `DejaVu Sans` (acompanha o dompdf, cobertura Unicode completa). Trocá-la por uma
fonte sem cobertura faz "ação" virar "a??o" no documento entregue.

O rodapé `Verifique em … · código …` é repetido em todas as páginas desta seção por um bloco
`position: fixed`.

### (c) Junção

`pdftool append --base <consolidado> --extra <evidências> --out <pré-assinatura>`. Páginas,
MediaBox, CropBox e `/Rotate` de cada página são preservados. O arquivo pré-assinatura
existe **apenas** no diretório temporário: ele não vira `DocumentVersion`.

### (d) Assinatura da operadora — `OperatorSignature`

**Se `PdfSigner::isConfigured()`**: aplica **uma** assinatura PAdES **B-B**
(`/ETSI.CAdES.detached`, SHA-256) como atualização incremental, com o certificado A1 da
AssinaVelox, e **em seguida valida** o resultado com `pdftool validate`. Se a validação não
confirmar `signature_count ≥ 1`, `all_intact`, `all_valid`, `all_covering` **e**
`all_docmdp_ok`, a finalização **falha**: um arquivo "assinado" que o próprio validador não
verifica não pode ser publicado como assinado.

As duas últimas condições não são preciosismo. `all_intact` responde apenas "os bytes
**cobertos** pela assinatura foram alterados?": um PDF com uma atualização incremental
acrescentada depois da revisão assinada continua `intact = true, valid = true, trusted =
true`, e a única pista é `coverage` cair de `ENTIRE_FILE` para `ENTIRE_REVISION` (mais
`modification_level` e `docmdp_ok` quando a alteração toca o catálogo). Publicar esse arquivo
como "assinado e íntegro" seria afirmar sobre bytes que ninguém verificou. A mesma barreira
vale ao **recuperar** um `final` de execução anterior (`recoverSignatureState()`) e ao
descrever o resultado na página pública (`SignatureNarrative`).

**Se não estiver configurado**: a etapa é **pulada**. O arquivo pré-assinatura vira o final
sem nenhuma modificação, `signature_status = none`, nenhum campo de assinatura é criado e
nada na interface ou no PDF diz "assinado digitalmente". Nunca se simula assinatura.

**Falha de assinatura não conclui o envelope.** A exceção sobe, o job falha, o envelope
permanece em `finalizing` e a próxima tentativa retoma do ponto em que parou.

**Senha**: esta camada só conhece o **nome** da variável de ambiente
(`COMPANY_CERT_PASSWORD_ENV`, padrão `COMPANY_CERT_PASSWORD`). O `PdfToolClient` lê o valor
do ambiente do PHP e o injeta no ambiente do processo filho sob esse nome, passando ao
pdftool apenas `--pass-env <NOME>`. O valor nunca aparece em argv, log, exceção, payload de
fila ou banco; `certificate_references.secret_ref` guarda o **nome**, não o valor.

Cada assinatura garante um `CertificateReference` (`organization_id = null`, ou seja, o
certificado da operadora) identificado pelo `fingerprint_sha256` — reassinar com o mesmo
certificado reaproveita a linha em vez de multiplicá-la.

### (e) Versão final e hash final

`DocumentVersion(kind = final)` com os bytes que existem em disco, e
`sha256 = hash_file('sha256', <arquivo final>)` calculado **depois** da assinatura.

### (f) Registro de verificação

`VerificationRecord` — o contrato lido pela página pública `/verificar/{code}` (§5).

### (g) Conclusão

Só então, **sob `SELECT … FOR UPDATE` no envelope** e apenas a partir de `finalizing`:

- `status = completed`, `completed_at`, `final_document_version_id`;
- trilha: `envelope.consolidated`, `envelope.evidence_generated`,
  `envelope.signed_company_a1` (quando houve assinatura) e `envelope.completed`;
- consumo do plano confirmado (`PlanLedger::commit`, idempotente — a confirmação já
  acontece no envio; aqui ela existe para o caso de uma reserva ainda aberta e **nunca**
  cobra duas vezes);
- notificações e link de download autorizado por signatário, pelo serviço do incremento 3
  (`CompletionNotifier`), que é idempotente por `settings.completion_notified_at`.

Uma falha **nas notificações** não desfaz a conclusão: o arquivo existe, o envelope está
concluído, e repetir a finalização inteira por causa de um e-mail seria pior. O incidente é
registrado em nível `error`.

---

## 3. O que cada hash identifica

| Hash                  | Bytes de que arquivo                                                                                                      |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `original_sha256`     | o arquivo **como foi enviado** pela organização (PDF, DOCX ou imagem), antes de qualquer conversão                        |
| `sent_sha256`         | a versão em PDF **congelada no envio** e apresentada a todos os signatários; é o hash citado em cada declaração de aceite |
| `consolidated_sha256` | o PDF com os campos autorizados achatados e o rodapé, **antes** da página de evidências                                   |
| `final_sha256`        | o arquivo final completo (consolidado + evidências + assinatura, quando houver)                                           |

**Ordem obrigatória**: o hash final é calculado **depois** da assinatura criptográfica e
guardado **fora** do PDF, em `verification_records`. O hash de um arquivo não pode estar
contido nele — é por isso que a página de evidências imprime três hashes e explica onde o
quarto é publicado. Há teste que falha se o `final_sha256` aparecer dentro do PDF.

Um resumo SHA-256 **não é uma assinatura**: ele permite conferir se dois arquivos são
idênticos, e nada mais.

---

## 4. O que é afirmado — e o que não é

| Afirmação                                             | Situação                                                                                        |
| ----------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| Perfil **PAdES B-B**, SHA-256, `/ETSI.CAdES.detached` | é o que o pyHanko produz e é o único perfil declarado (`signature_profile = 'PAdES-B-B'`)       |
| Carimbo do tempo (B-T)                                | **não existe**. `SignResult::timestamp` é sempre `null`; `validation_result.timestamp` é `null` |
| LTV / LTA / DSS                                       | **não existe**. `validation_result.long_term_validation = false`                                |
| Revogação (CRL/OCSP)                                  | **nunca verificada** — o pdftool roda offline. `revocation = 'not_checked'`                     |
| Cadeia de confiança                                   | `trusted = true` só com `PDFTOOL_TRUST_ROOTS` configurado; sem raízes, `false` com motivo       |
| Validade ICP-Brasil                                   | **não é afirmada** por esta camada, em nenhuma hipótese, para nenhum certificado                |

**Assinatura da operadora × assinatura pessoal.** A assinatura aplicada aqui usa o
certificado **da AssinaVelox**. Ela identifica a operadora que consolidou e lacrou o arquivo
e permite que leitores de PDF detectem alterações posteriores. Ela **não** é a assinatura
pessoal de nenhum participante nem um certificado emitido em nome deles. A manifestação de
vontade de cada participante é o **aceite eletrônico** — vontade vinculada à versão exata do
documento, aos campos apresentados, ao método de autenticação, à data do servidor em UTC, ao
IP e ao user-agent — sustentado pelas evidências da página anexada.

**Sem certificado configurado.** O envelope conclui como _aceite eletrônico com evidências_,
com `signature_status = none`. O PDF diz literalmente "Este arquivo não possui assinatura
criptográfica" e "Nenhum certificado digital foi utilizado, e nenhuma indicação de
'assinatura digital' deve ser esperada em leitores de PDF". O registro de verificação traz
`signature_profile = null` e `validation_result.reason = 'signer_not_configured'`.

**Certificado de teste.** `COMPANY_CERT_ENVIRONMENT=test` (o padrão, e também o valor
assumido quando a configuração é inválida) propaga `environment = test` para
`certificate_references`, para o registro de verificação e para a página de evidências, que
imprime um aviso destacado: _"Certificado de ambiente de teste … não é ICP-Brasil"_. A
palavra "ICP-Brasil" aparece **uma única vez** na página de evidências de um envelope de
teste, e é para negar — há teste que verifica isso.

---

## 5. Contrato do `VerificationRecord` (para a página pública `/verificar/{code}`)

Uma linha por envelope, criada **antes** de o envelope sair de `finalizing`.

| Coluna                      | Conteúdo                                                                                      |
| --------------------------- | --------------------------------------------------------------------------------------------- |
| `code`                      | `envelopes.verification_code` (12 caracteres base32 sem `0/1/O/I`); accessor `formatted_code` |
| `envelope_id`               | UNIQUE — um registro por envelope                                                             |
| `final_document_version_id` | a `DocumentVersion(kind = final)`                                                             |
| `original_sha256`           | pode ser `null` se a versão original tiver sido removida                                      |
| `sent_sha256`               | sempre presente                                                                               |
| `consolidated_sha256`       | sempre presente                                                                               |
| `final_sha256`              | sempre presente; é o valor a comparar com o arquivo que o usuário tem em mãos                 |
| `signature_status`          | `none` \| `company_a1`                                                                        |
| `signature_profile`         | `'PAdES-B-B'` **somente** quando `signature_status = company_a1`; `null` caso contrário       |
| `certificate_reference_id`  | `certificate_references` (metadados + `secret_ref` = **nome** da variável, nunca o segredo)   |
| `validated_at`              | quando a validação técnica foi feita                                                          |
| `revoked_at`                | reservado (revogação administrativa do registro público)                                      |

`validation_result` (JSON) — formato estável:

```json
{
    "signed": true,
    "profile": "PAdES-B-B",
    "environment": "test",
    "validated_at": "2026-09-09T05:15:15+00:00",
    "timestamp": null,
    "long_term_validation": false,
    "revocation": "not_checked",
    "reason": null,
    "result": {
        "signature_count": 1,
        "all_intact": true,
        "all_valid": true,
        "all_trusted": false,
        "trust_roots_configured": 0,
        "revocation": "not_checked",
        "signatures": [
            {
                "field_name": "AssinaVelox",
                "intact": true,
                "valid": true,
                "trusted": false,
                "trust_reason": "no_trust_roots_configured",
                "signer_subject": "CN=…",
                "issuer": "CN=…",
                "serial_hex": "…",
                "cert_fingerprint_sha256": "…",
                "signing_time": "…",
                "md_algorithm": "sha256",
                "subfilter": "/ETSI.CAdES.detached",
                "coverage": "ENTIRE_FILE",
                "modification_level": "NONE",
                "summary": "INTACT:…",
                "errors": []
            }
        ]
    }
}
```

Sem certificado: `signed = false`, `profile = null`, `environment = null`,
`reason = "signer_not_configured"` e **`result = null`** (rodar `validate` em um PDF sem
assinaturas devolveria `all_intact = false`, o que na tela pública leria como "arquivo
adulterado" — que é falso).

Chaves que a página pública pode usar sem medo: `signed`, `profile`, `environment`,
`revocation`, `timestamp`, `long_term_validation`, `reason` e, quando `result` não é nulo,
`result.all_intact`, `result.all_valid`, `result.all_trusted`,
`result.trust_roots_configured` e `result.signature_count`.

Lembrete de escopo (arquitetura §6): a página pública mostra estado, data de conclusão,
número de participantes, hashes, `signature_status`/perfil e o resultado técnico. **Não**
mostra o PDF, nem CPF, e-mail, IP ou dossiê.

---

## 6. Idempotência, retomada e concorrência

**Unicidade ≠ idempotência, e as duas existem.**

- `FinalizeEnvelope implements ShouldBeUnique`, `uniqueId() = envelope-finalization:{id}`,
  `uniqueFor = 1800` — evita **dois workers ao mesmo tempo**. Não basta: a trava tem prazo e
  um worker pode morrer sem liberá-la.
- A garantia real é a idempotência do `EnvelopeFinalizer`. Cada etapa pergunta antes se o
  artefato já existe (`FinalizationArtifacts::existing()`, que exige **linha no banco E
  bytes no disco**) e reaproveita em vez de recriar. Eventos de trilha só são emitidos
  quando a etapa de fato roda.
- A transição para `completed` acontece **sob lock** e apenas a partir de `finalizing`. Um
  segundo job encontra o envelope já `completed` e devolve `already_completed` sem tocar em
  nada.

**Ordem de gravação: bytes primeiro, linha depois.** Isso admite um estado intermediário
(arquivo órfão no disco, sem linha) e recusa o oposto (linha apontando para bytes que não
existem). O órfão custa espaço; a linha fantasma quebraria download, verificação e hash — e
não haveria como detectá-la sem ler o disco.

Cenários cobertos por teste (`tests/Feature/Finalization/FinalizationIdempotencyTest.php`):

| Queda / repetição                                        | Comportamento                                                                              |
| -------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| Job repetido 4× depois de concluir                       | uma versão de cada tipo, um `VerificationRecord`, um `envelope.completed`, uma notificação |
| Queda depois da consolidação                             | a mesma versão consolidada é reaproveitada; nenhum evento duplicado                        |
| Linha no banco mas arquivo apagado do disco              | o artefato é regerado (a linha órfã não é reaproveitada)                                   |
| Arquivo final gravado e banco não atualizado             | o mesmo `final` é reaproveitado; o registro de verificação é recriado com o mesmo hash     |
| `final` gerado sem certificado, mas agora há certificado | o artefato **nunca publicado** é descartado e refeito assinado (ver abaixo)                |

**Reaproveitar exige três conferências, não uma.** `FinalizationArtifacts::existing()` só
devolve um artefato quando (1) a linha existe, (2) os bytes existem no disco e (3) o
**`sha256` recalculado dos bytes bate com o gravado na linha**. A terceira existe porque o
`final_sha256` publicado em `verification_records` vem da COLUNA enquanto
`envelopes.download` entrega os BYTES: se eles divergirem entre a queda e a retentativa
(corrupção de disco ou de bucket, restauração parcial de backup, sincronização malfeita,
adulteração), a retomada publicaria o resumo antigo sobre o arquivo novo e a conferência
"Conferir meu arquivo" responderia **"Não confere" para o arquivo verdadeiro** — a
plataforma acusando de adulterado o próprio arquivo que entrega. Divergência ⇒ o artefato é
descartado e refeito. Bytes ilegíveis ⇒ o artefato é ignorado (mas **não** apagado: apagar
destruiria a única cópia de algo talvez recuperável).

**Descarte de artefato incompatível.** Um `final` só é reaproveitado se for coerente com a
configuração atual de assinatura (assinado ⇔ signer configurado). Um arquivo produzido sob
outra configuração descreve uma finalização diferente; como o envelope não concluiu e
nenhum `verification_record` aponta para ele, ele não é público — descartá-lo é reconciliar
um passo interrompido, não apagar histórico.

**A página de evidências tem a mesma conferência.** O bloco "5. Sobre a assinatura
criptográfica deste arquivo" é escrito **antes** de a assinatura existir, a partir da
configuração vigente. Se a configuração mudar entre uma tentativa e outra — a assinatura
falha e o operador desliga o certificado para destravar o envelope, o certificado é
rotacionado, o ambiente muda de teste para produção — a página gravada afirma algo que não
vai acontecer. A variante impressa (situação de assinatura + impressão digital do
certificado) é gravada no evento `envelope.evidence_generated` da trilha; na retomada, o
finalizador compara e **descarta a página quando ela descreve outra execução**. Sem isso, o
arquivo entregue podia carregar dentro de si "este arquivo recebeu uma assinatura digital"
com zero assinaturas no PDF. Uma consolidação refeita também invalida a página (os hashes
impressos são daquele consolidado), e uma página refeita invalida o `final` que a embutia.

**O registro de verificação também é conferido.** `verificationRecord()` só reaproveita um
`VerificationRecord` existente se ele descrever o arquivo final **desta** execução (mesma
versão, mesmo resumo, mesma situação de assinatura, mesmo perfil e mesmo certificado). Uma
queda entre a etapa (f) e a etapa (g) deixa o envelope em `finalizing` com o registro já
commitado; se o certificado sair do ar nesse intervalo, a retentativa reconstrói o `final`
sem assinatura e reaproveitar o registro antigo publicaria `signature_status = company_a1` e
o `final_sha256` de um arquivo descartado sobre um PDF sem assinatura nenhuma. Divergindo, o
registro é **reescrito** com os valores da execução corrente (`steps.verification_record =
rewritten`, com `warning` no log).

Essas quatro situações — bytes incoerentes com o resumo, `final` incompatível com a
configuração, página de evidências de outra variante e registro de outra execução — são as
**únicas** em que a finalização apaga ou reescreve algo, e todas tratam de artefatos que
nunca chegaram a ser publicados.

**Transações.** Nenhuma transação de banco fica aberta durante uma chamada ao pdftool ou ao
DOMPDF (arquitetura §3.3). Cada gravação é uma transação curta, entre chamadas. O diretório
temporário é exclusivo da execução (`storage/app/tmp/pdftool/<ulid>`) e removido em
`finally`, inclusive quando a etapa termina em exceção.

**Falha definitiva.** Três tentativas (`backoff` 30 s, 120 s, 600 s). Esgotadas, `failed()`
grava `envelope.finalization_failed` na trilha e registra em nível `error` com
`alert = envelope_finalization_failed` — o gancho para o alerta operacional. O envelope
**continua em `finalizing`**: não existe estado "falhou a finalização" na máquina de estados
(arquitetura §3.2), e inventar um esconderia do operador que há um documento aguardando.
Reprocessar é despachar o job de novo.

---

## 7. Certificado A1: teste e produção

### Certificado de teste (desenvolvimento, CI, testes automatizados)

Helper: `App\Services\Envelopes\Finalization\Support\TestCertificate`.

```php
// tinker, seeder de desenvolvimento ou beforeEach de teste
putenv('ASSINAVELOX_TEST_CERT_PASS=uma-senha-forte-local');   // o VALOR só no ambiente

$certificate = TestCertificate::generate(
    storage_path('app/private/certs'),   // diretório (criado com 0700)
    'ASSINAVELOX_TEST_CERT_PASS',        // NOME da variável, nunca o valor
    TestCertificate::SUBJECT,            // CN=AssinaVelox TESTE,O=AssinaVelox,C=BR
    30,                                  // dias de validade
);

TestCertificate::configure($certificate);  // liga o PyHankoSigner e usa o .pem como raiz
TestCertificate::register($certificate);   // grava certificate_references (metadados)
```

Equivalente pela linha de comando (o que o helper faz por baixo):

```bash
export COMPANY_CERT_PASSWORD='senha-forte-local'   # PowerShell: $env:COMPANY_CERT_PASSWORD='...'
cd tools/pdftool && .venv/bin/python -m pdftool gen-test-cert \
    --out-pfx ../../storage/app/private/certs/teste.pfx --pass-env COMPANY_CERT_PASSWORD \
    --out-pem ../../storage/app/private/certs/teste.pem --days 365
```

```dotenv
COMPANY_CERT_ENABLED=true
COMPANY_CERT_PFX_PATH=/abs/storage/app/private/certs/teste.pfx
COMPANY_CERT_PASSWORD_ENV=COMPANY_CERT_PASSWORD   # NOME da variável
COMPANY_CERT_ENVIRONMENT=test
PDFTOOL_TRUST_ROOTS=/abs/storage/app/private/certs/teste.pem
```

O certificado gerado é autoassinado, tem `TESTE` no CN e é rotulado `environment = test` em
toda a cadeia. **Ele não é ICP-Brasil, não tem validade jurídica e nunca pode ser
apresentado como se tivesse.**

### Produção

1. Obter um A1 (`.pfx`/`.p12`) emitido por AC da **ICP-Brasil** em nome da empresa operadora.
2. Guardar o arquivo fora do repositório, legível **apenas** pelo usuário do worker
   (`chmod 400`, dono = usuário do serviço).
3. Colocar a senha no **ambiente real do serviço**, sob o nome de `COMPANY_CERT_PASSWORD_ENV`:
   `EnvironmentFile=` da unit systemd do worker/Horizon, `env[…]` no pool do PHP-FPM, ou um
   gerenciador de segredos que exporte a variável. Com `php artisan config:cache` o Laravel
   **não carrega o `.env`** — a variável precisa existir no ambiente do processo.
4. `COMPANY_CERT_ENVIRONMENT=production` e `PDFTOOL_TRUST_ROOTS` com a cadeia da AC.
5. `php artisan pdftool:selftest` após cada deploy e a cada troca de certificado.
6. Nunca commitar o `.pfx`, a senha, nem colocar o valor em `config/*.php`.

### Marco pendente — assinatura A1 com credencial de produção

**Ainda não verificado.** Toda a cobertura automatizada usa certificados gerados por
`gen-test-cert`. O que não foi exercitado com credencial real: cadeia com AC intermediária,
políticas de certificado da ICP-Brasil, `.pfx` com algoritmos ou parâmetros diferentes dos
do certificado autoassinado, e a leitura do arquivo final por verificadores oficiais (ITI).
Este marco só pode ser dado como cumprido depois de assinar um envelope com um A1 real, com
a cadeia da AC em `PDFTOOL_TRUST_ROOTS`, e conferir o resultado em um verificador
independente.

---

## 8. Configuração (`config/assinavelox.php` → `evidence`)

| Chave                      | Padrão                                            | Efeito                                                                                             |
| -------------------------- | ------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `evidence.view`            | `evidence.page`                                   | view Blade renderizada pelo DOMPDF                                                                 |
| `evidence.paper`           | `a4`                                              | papel da página de evidências                                                                      |
| `evidence.font`            | `DejaVu Sans`                                     | fonte padrão do DOMPDF (precisa cobrir acentuação)                                                 |
| `evidence.timeline_events` | lista de `audit_events`                           | quais eventos aparecem na linha do tempo                                                           |
| `evidence.timeline_limit`  | `200`                                             | teto de eventos impressos                                                                          |
| `evidence.qr.module_px`    | `4`                                               | tamanho do módulo do QR code em pixels                                                             |
| `evidence.qr.quiet_zone`   | `4`                                               | zona de silêncio do QR em módulos — mínimo da ISO/IEC 18004; `QrCode::png()` eleva valores menores |
| `evidence.footer.enabled`  | `true`                                            | carimba o rodapé nas páginas do documento consolidado                                              |
| `evidence.footer.*`        | `x .06 / y .962 / w .88 / h .022 / 7 pt / center` | geometria e tipografia do rodapé                                                                   |

Fila: `config('assinavelox.queues.finalization')` (padrão `finalization`). Em produção,
inclua essa fila nos workers/Horizon.

Exibição de IP: `organizations.settings.evidence_show_ip` (`masked` padrão, `full`, `none`),
com o padrão global em `config('assinavelox.evidence_show_ip')`.

---

## 9. Testes

`php artisan test --filter=Finalization` — 24 testes em `tests/Feature/Finalization/`,
rodando contra o **pdftool real** (pulados com instrução clara se o venv não existir).

- `FinalizationPipelineTest` (12) — conclusão sem certificado; posição de cada valor
  autorizado dentro do retângulo do seu campo (extração de texto com coordenadas no espaço
  do usuário do PDF, o mesmo método dos testes do pdftool); rodapé em todas as páginas; o
  hash final **não** dentro do PDF; página de evidências no fim, com código, participantes e
  linha do tempo; hash final que muda com um byte alterado; campo de quem não aceitou não
  entra; rotação de página respeitada (texto em pé, direção `(0,1)` em `/Rotate 90`);
  assinatura digitada desenhada como texto; trilha e notificações; disparo pelo gancho;
  envelope fora de `finalizing` intocado; diretório temporário limpo.
- `FinalizationSignatureTest` (5) — com certificado de teste: **uma** assinatura íntegra e
  válida, `trusted` com a raiz, `signature_status = company_a1`, perfil `PAdES-B-B`,
  `certificate_references` com `environment = test` e `secret_ref` = nome da variável;
  rótulo de teste na página de evidências e "ICP-Brasil" apenas na negação; falha de
  assinatura não conclui; assinatura não validável não conclui; certificado desligado
  conclui **sem nenhuma assinatura** no PDF.
- `FinalizationIdempotencyTest` (7) — os cenários da §6, mais o contrato do job (fila,
  `uniqueId`, `tries`, `backoff`) e o registro da falha definitiva.

Ferramenta de teste: `tests/Feature/Finalization/Support/pdf_probe.py`, executada com o
interpretador do venv do pdftool (que já traz o pypdf). Ela extrai texto **com coordenadas**,
aplica `/Rotate` a uma página (fixture rotacionada) e altera um byte de um arquivo (para
provar que o hash muda). Não faz parte da aplicação nem do contrato do pdftool.
