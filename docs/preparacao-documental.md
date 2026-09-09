# Preparação documental — upload, validação, conversão e versionamento

> Camada `App\Services\Documents` + `App\Jobs\Documents`. Regras de domínio: `docs/arquitetura.md` §3.1, §3.2 e §5. Adaptadores e processo Python: `docs/pdf-pipeline.md` e `tools/pdftool/README.md`. Rotas e props: `docs/design/ROUTES_AND_PAGES.md` §1.2 e §2.6.

## 1. Visão geral

```
POST /documentos/{envelope}/documento          (multipart, um arquivo)
   │
   ├─ StoreEnvelopeDocumentRequest      forma: presente, um arquivo, ≤ 25 MB
   │
   ├─ UploadInspector                   CONTEÚDO: magic number + finfo + extensão
   │                                    DOCX → inspeção do ZIP · imagem → cabeçalho
   │
   └─ DocumentIntake
          ├─ (substituição) remove documento anterior, arquivos e campos → envelope draft
          ├─ grava bytes no disco privado `documents`
          ├─ Document(processing_status=uploaded) + DocumentVersion(kind=original, sha256)
          ├─ envelope → preparing
          ├─ AuditEvent `document.uploaded`
          └─ despacha ProcessDocumentUpload (fila `conversions`)

ProcessDocumentUpload  (idempotente, ShouldBeUnique por documento)
   ├─ documento → converting · AuditEvent `document.conversion_started`
   ├─ copia a original para um diretório temporário exclusivo
   ├─ PdfConverterManager escolhe o conversor por `source_type`
   │     pdf   → PassthroughPdfConverter  (só inspeciona)
   │     docx  → LibreOfficeConverter
   │     image → ImageToPdfConverter (GD + pdftool image2pdf)
   ├─ ready   → versão exibível + pages_meta + page_count + sha256 → `document.converted`
   ├─ blocked → failure_code/message PT-BR, original preservado → `document.blocked`
   ├─ failed  → failure_code/message PT-BR → `document.processing_failed`
   ├─ EnvelopeReadiness::recompute()  (draft | preparing | ready)
   └─ remove o diretório temporário em `finally`, inclusive em caso de exceção
```

Nada de shell em lugar nenhum: o `pdftool` e o LibreOffice são processos com argumentos em array e ambiente mínimo (`docs/pdf-pipeline.md` §2).

## 2. Formatos aceitos e limites

| Origem | `source_type` | Extensões                        | MIME gravado                                                              | Conversor                                            |
| ------ | ------------- | -------------------------------- | ------------------------------------------------------------------------- | ---------------------------------------------------- |
| PDF    | `pdf`         | `.pdf`                           | `application/pdf`                                                         | `PassthroughPdfConverter` (não converte, inspeciona) |
| Word   | `docx`        | `.docx`                          | `application/vnd.openxmlformats-officedocument.wordprocessingml.document` | `LibreOfficeConverter`                               |
| Imagem | `image`       | `.png`, `.jpg`, `.jpeg`, `.webp` | `image/png`, `image/jpeg`, `image/webp`                                   | `ImageToPdfConverter`                                |

Limites (`config/assinavelox.php` → `upload`, todos com variável de ambiente própria):

| Limite                           | Padrão              | Chave                                         |
| -------------------------------- | ------------------- | --------------------------------------------- |
| Tamanho do arquivo               | 25 MB               | `upload.max_mb` (`ASSINAVELOX_MAX_UPLOAD_MB`) |
| DOCX — total descompactado       | 300 MB              | `upload.docx.max_uncompressed_mb`             |
| DOCX — razão de compressão       | 150×                | `upload.docx.max_compression_ratio`           |
| DOCX — número de entradas do ZIP | 2000                | `upload.docx.max_entries`                     |
| DOCX — entrada obrigatória       | `word/document.xml` | `upload.docx.required_entry`                  |
| Imagem — megapixels              | 40 MP               | `upload.image.max_megapixels`                 |
| Imagem — lado máximo             | 20000 px            | `upload.image.max_side_px`                    |

**SVG é recusado**, sempre: pode carregar `<script>` e referências externas, e não é um documento paginado. Qualquer formato fora da tabela também.

Fase 1: **um documento por envelope**. Enviar um segundo arquivo substitui o primeiro — os campos posicionados sobre a versão antiga são apagados e o envelope volta a `draft`, porque a geometria normalizada dos campos só faz sentido sobre a versão a que se refere.

## 3. Validação: por que a extensão não basta

`App\Services\Documents\UploadInspector` valida **o conteúdo**, na ordem do mais barato para o mais caro, e nada é decodificado antes de passar pelos limites:

1. **Upload concluído** e arquivo não vazio.
2. **Tamanho** ≤ `upload.max_mb` (o `max:` do FormRequest é a primeira barreira; o inspetor confere de novo sobre o arquivo em disco).
3. **Assinatura de bytes** (magic number) decide o formato real: `%PDF-`, `\x89PNG…`, `\xFF\xD8\xFF`, `RIFF…WEBP`, `PK\x03\x04`. Fora disso → `unsupported_type`.
4. **`finfo`** sobre o conteúdo precisa concordar com a assinatura. A lista por formato tolera bases libmagic antigas (que respondem `application/zip` para OOXML), mas nunca aceita o MIME de outro formato.
5. **Extensão × conteúdo**: um arquivo com bytes de PNG chamado `contrato.pdf` é recusado (`extension_mismatch`). Não é firula: o nome exibido, o `Content-Type` do download e o conversor escolhido derivam do tipo, e um descasamento é sinal de engano ou de tentativa deliberada.
6. **DOCX**: o pacote é aberto com `ZipArchive` em modo leitura e verificado antes de ir para o conversor — número de entradas, soma dos tamanhos **descompactados**, razão de compressão e nomes de entrada (recusa `..`, caminho absoluto, `\` e byte nulo). Por fim, exige `word/document.xml`. Sem isso, um ZIP de 40 KB pode declarar dezenas de GB e derrubar o worker antes de qualquer limite de tempo.
7. **Imagem**: largura e altura vêm de `getimagesize`, que lê só o cabeçalho. Uma imagem de 64 MP é recusada com o arquivo ainda intocado — nenhum pixel é decodificado.

Recusa → `UploadRejectedException` com `errorCode` estável (log/auditoria) e mensagem PT-BR que vai direto para o campo `file` do wizard. **Nada** é gravado: sem `Document`, sem `DocumentVersion`, sem arquivo no disco, sem job na fila.

### Nome do arquivo

O nome enviado pelo navegador **nunca** vira caminho no disco. Ele é sanitizado (`basename`, sem caracteres de controle, sem separadores, no máximo 200 caracteres) e guardado em `documents.original_filename` (exibição) e `documents.name` (sem extensão). O caminho é montado só com identificadores opacos:

```
orgs/{organization_ulid}/envelopes/{envelope_ulid}/{document_version_ulid}.{ext}
```

Isso resolve de uma vez path traversal, colisão de nomes, caracteres inválidos por sistema de arquivos e vazamento de nome entre organizações.

## 4. O que é bloqueado, e por quê

`blocked` é um estado do **documento**: o arquivo é válido, está preservado, mas não pode ser preparado para assinatura. O envelope volta a `draft` e o usuário pode remover ou substituir o arquivo.

| `failure_code`   | Situação                             | Mensagem exibida                                                                                           |
| ---------------- | ------------------------------------ | ---------------------------------------------------------------------------------------------------------- |
| `encrypted_pdf`  | PDF protegido por senha/criptografia | "O PDF está protegido por senha ou criptografia. Remova a proteção e envie novamente."                     |
| `has_signatures` | PDF **já assinado digitalmente**     | "O PDF já contém assinaturas digitais; qualquer alteração as invalidaria. Envie a versão sem assinaturas." |
| `invalid_pdf`    | PDF corrompido/ilegível              | "O arquivo não é um PDF válido ou está corrompido."                                                        |
| `not_openable`   | PDF sem páginas legíveis             | "O PDF não pôde ser aberto."                                                                               |

O caso de **PDF já assinado** é o mais importante e não é uma limitação técnica que se possa contornar: preparar o documento significa desenhar campos sobre ele e reescrever os bytes. Qualquer byte alterado quebra o `/ByteRange` das assinaturas existentes, que passariam a validar como adulteradas. Preferimos recusar e dizer isso do que produzir um arquivo com assinaturas quebradas. (A assinatura da operadora, na finalização, é aplicada como **atualização incremental** justamente para não destruir assinaturas anteriores — mas isso acontece no fim do fluxo, sobre o PDF consolidado, não na preparação.)

`failed` é falha de processamento, não do documento:

| `failure_code`                                                                                               | Situação                                                                                      |
| ------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------- |
| `converter_not_configured`                                                                                   | conversor do tipo indisponível (ver §9: LibreOffice)                                          |
| `missing_original`                                                                                           | o arquivo da versão original sumiu do disco                                                   |
| `processing_error`                                                                                           | tentativas esgotadas (timeout, processo que não inicia) — marcado pelo hook `failed()` do job |
| códigos do conversor (`unsupported_image`, `image_too_large`, `libreoffice_failed`, `no_output`, `timeout`…) | ver `App\Integrations\Pdf\ConversionMessages`                                                 |

## 5. Versões e hashes — quais bytes cada hash identifica

`document_versions` é **imutável quanto aos bytes**: `storage_path`, `size_bytes` e `sha256` nunca mudam depois de criados. O que o job preenche uma única vez, ao terminar a inspeção, são os metadados **derivados** daqueles mesmos bytes: `page_count`, `pages_meta`, `is_encrypted`, `has_signatures`. Não há como conhecê-los antes de rodar o `inspect`, e recriar a linha só para guardá-los duplicaria o arquivo sem ganho.

| `kind`         | Quando nasce               | Bytes que o `sha256` identifica                                      | Fase     |
| -------------- | -------------------------- | -------------------------------------------------------------------- | -------- |
| `original`     | no upload                  | o arquivo **exatamente como o usuário enviou** (PDF, DOCX ou imagem) | 2 (aqui) |
| `converted`    | no job, para DOCX e imagem | o PDF gerado pelo conversor                                          | 2 (aqui) |
| `consolidated` | na finalização             | o PDF com os campos achatados                                        | 4        |
| `evidence`     | na finalização             | a página de evidências (Blade → DOMPDF)                              | 4        |
| `final`        | na finalização             | o arquivo final, **depois** da assinatura da operadora quando houver | 4        |

**Versão exibível** (`documents.current_version_id`) é o PDF que o editor de campos e o signatário veem:

- **PDF de origem**: é a própria versão `original`. Os bytes não são copiados — não faria sentido guardar duas vezes o mesmo arquivo, e o hash "original" e o hash "exibido" são o mesmo.
- **DOCX / imagem**: é a versão `converted`. O `original` continua no disco, intacto, e é o que `download/original` entrega.

O hash "enviado" (`sent_sha256`, congelado em `envelopes.sent_document_version_id`) e o hash final (`verification_records.final_sha256`, calculado **depois** da assinatura) pertencem aos incrementos 3 e 4; o vocabulário e a regra "o hash de um arquivo nunca está dentro dele" estão em `docs/pdf-pipeline.md` §5.

### `pages_meta`

Cópia fiel do que o `pdftool inspect` devolve por página: `index`, `rotation`, `mediabox[4]`, `cropbox[4]`, `width_pt`, `height_pt`. As dimensões já são as **exibidas** — em páginas com `/Rotate 90` ou `270` a largura e a altura vêm trocadas. É esse referencial que o front usa para converter as coordenadas dos campos em frações `[0,1]` sobre o CropBox exibido (`docs/arquitetura.md` §3.1).

## 6. Estados

**Documento** (`DocumentProcessingStatus`):

```
uploaded ──► converting ──► ready
                      ├───► blocked   (arquivo válido, mas impreparável; original preservado)
                      └───► failed    (falha de processamento)
```

**Envelope** (recomputado por `EnvelopeReadiness`, só enquanto `draft | preparing | ready`):

| Situação                                                                                                               | Status      |
| ---------------------------------------------------------------------------------------------------------------------- | ----------- |
| documento `uploaded` ou `converting`                                                                                   | `preparing` |
| documento `ready` **e** ≥ 1 destinatário **e** todo destinatário com ≥ 1 campo `signature` na versão exibível corrente | `ready`     |
| qualquer outra coisa                                                                                                   | `draft`     |

Envelopes já enviados (`in_progress` em diante) e terminais nunca são tocados.

### Contrato público para as outras áreas

```php
use App\Services\Documents\EnvelopeReadiness;

$readiness = app(EnvelopeReadiness::class);

$status = $readiness->recompute($envelope);        // recalcula e PERSISTE; devolve o status
$status = $readiness->computeStatus($envelope);    // só calcula, não grava
$flags  = $readiness->completeness($envelope);     // ['document' => bool, 'recipients' => bool, 'fields' => bool]
```

Quem altera destinatários ou campos chama `recompute($envelope)` depois de gravar. O método é idempotente, não lança em transição inválida (só decide entre `draft`, `preparing` e `ready`, mutuamente alcançáveis) e ignora envelopes fora do rascunho. `completeness()` alimenta a prop homônima do wizard (ROUTES §2.6).

## 7. Filas e idempotência

`ProcessDocumentUpload` roda na fila **`conversions`** (`config('assinavelox.queues.conversions')`), com `tries = 3` e backoff `10s / 60s / 180s`. Em desenvolvimento o driver é `database`; em produção, Redis + Horizon.

- `ShouldBeUnique` com `uniqueId() = "document:{id}"` e `uniqueFor = 900` impede dois workers na mesma conversão; a trava expira sozinha se o worker morrer.
- Um documento já `ready` **com** `current_version_id` faz o job retornar imediatamente.
- Se já existir uma versão `converted` com arquivo no disco, ela é **reaproveitada** em vez de recriada — reprocessar não duplica versões nem bytes (caso do worker que morre depois de gravar o arquivo e antes de fechar o documento).
- Falha de infraestrutura sobe como exceção e o job tenta de novo; esgotadas as tentativas, o hook `failed()` marca o documento `failed` para que ele não fique preso em `converting`.
- Falta de dependência externa (`ConverterNotConfiguredException`) **não** é repetida: repetir não instala o LibreOffice. O documento vai direto para `failed` com a mensagem honesta da §9.

Cada execução usa um diretório temporário exclusivo (`storage/app/tmp/pdftool/doc-<ulid>`, modo 0700), removido em `finally` mesmo quando a conversão termina em exceção.

## 8. Rotas

| Método | Rota                                                  | Nome                         | O que faz                                                                                                                                                                                                             |
| ------ | ----------------------------------------------------- | ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| POST   | `/documentos/{envelope}/documento`                    | `envelopes.document.store`   | Upload multipart. `can:update`. Só em `draft/preparing/ready`. Erro de conteúdo volta como erro de validação em `file`.                                                                                               |
| DELETE | `/documentos/{envelope}/documento`                    | `envelopes.document.destroy` | Remove documento, arquivos e campos; envelope → `draft`.                                                                                                                                                              |
| GET    | `/documentos/{envelope}/documento/status`             | `envelopes.document.status`  | JSON do polling: `status`, `label`, `pages`, `error`, `failure_code`, `ready`, `terminal`, `progress_pct`, `envelope_status`.                                                                                         |
| GET    | `/documentos/{envelope}/documento/preview`            | `envelopes.document.preview` | **Stream do PDF da versão exibível**: `Content-Type: application/pdf`, `Content-Disposition: inline`, `Cache-Control: no-store…private`, `X-Content-Type-Options: nosniff`. Fonte do visualizador PDF.js. `can:view`. |
| GET    | `/documentos/{envelope}/documento/paginas/{page}.png` | `envelopes.document.page`    | **404 por decisão de arquitetura** (ver abaixo).                                                                                                                                                                      |
| GET    | `/documentos/{envelope}/download/{type}`              | `envelopes.download`         | `original` sempre; `signed` e `evidence` só com o envelope `completed` e a versão existente (senão 404). Emite `envelope.downloaded`.                                                                                 |

O disco `documents` é privado: **não existe URL pública nem assinada**. Todo byte passa por um controller com policy, e o route model binding do envelope já é escopado pela organização corrente — um envelope de outra organização responde 404, sem revelar que existe.

### Miniaturas: decisão

A rota `envelopes.document.page` (miniatura PNG por página) **não foi implementada** e responde 404 com mensagem explicativa. Motivo: gerar PNG no servidor exigiria um rasterizador (Ghostscript, poppler ou pdfium) que o projeto decidiu não ter — o `pdftool` compõe, assina e inspeciona, mas não rasteriza — e custaria uma chamada de processo e um arquivo por página a cada visita ao editor. O rail de miniaturas é renderizado **no navegador**, pelo mesmo PDF.js que já carregou o documento em `envelopes.document.preview`; o `pdfjs-dist` já está no `package.json`. A rota continua registrada (removê-la é decisão do dono de `routes/`) e `DocumentResource.page_thumb_url_template` é `null` para que o front saiba que não deve tentar.

### Rota acrescentada

`envelopes.document.preview` foi **acrescentada** a `routes/web.php` (uma linha, no grupo `envelopes`). Sem ela não haveria como transmitir o PDF exibível: `envelopes.download` com `type=original` entrega o arquivo como foi enviado, que para DOCX e imagem não é um PDF — o PDF.js não conseguiria abrir. As duas rotas coexistem e servem coisas diferentes.

## 9. Limitações conhecidas

- **LibreOffice não está instalado no ambiente de desenvolvimento local.** `LIBREOFFICE_BIN` vazio ⇒ `LibreOfficeConverter::isConfigured() === false`. Um DOCX enviado nessa máquina é aceito, gravado como `original` e o job o marca **`failed`** com:

    > "A conversão de arquivos DOCX não está disponível nesta instalação. Converta o documento para PDF e envie novamente."

    Nenhum PDF é produzido, nenhuma versão `converted` é criada e nada finge que a conversão aconteceu. Para habilitar, instale o LibreOffice e defina `LIBREOFFICE_BIN` (`docs/pdf-pipeline.md` §4); confira com `php artisan pdftool:selftest`. O caminho completo (argumentos, perfil isolado, ambiente mínimo, timeout) é exercitado nos testes com o binário falso `tests/Fixtures/fake-soffice.{bat,sh}` — o comportamento com o `soffice` **real** ainda não foi verificado.

- **`progress_pct` é sempre `null`.** Nem o LibreOffice nem o `pdftool` reportam progresso; a UI deve mostrar progresso indeterminado. Inventar um percentual seria mentir.
- **Sem OCR e sem detecção de páginas em branco.** Um PDF de imagens escaneadas é aceito como está.
- **DOCX com macros é convertido sem executá-las**, mas não é recusado. Recusar exigiria inspecionar `vbaProject.bin` no ZIP — a checagem já existe estruturalmente no `UploadInspector` e seria um acréscimo pequeno, deixado fora do escopo desta fase.
- **Um documento por envelope.** Juntar vários arquivos em um envelope é Fase 2.
- **A conversão de DOCX não preserva paginação com fidelidade garantida**: fontes ausentes no servidor são substituídas silenciosamente pelo LibreOffice.
- **`is_encrypted` na versão original de um PDF bloqueado** só é preenchido quando o `inspect` conseguiu abrir o arquivo (senha de proprietário). Um PDF com senha de **usuário** é recusado pelo `pdftool` antes da inspeção; nesse caso o bloqueio é registrado com o `failure_code`, sem metadados de página.

## 10. Testes

`php -d extension=intl artisan test tests/Feature/Documents` — **46 testes, 282 asserções**, pulados automaticamente se o venv do `pdftool` não existir (`--filter=Documents` casa só parte deles; prefira o caminho do diretório):

- `DocumentUploadTest` — upload aceito (PDF, imagem, PNG, WEBP), caminho e hash, eventos, substituição, remoção; recusas (SVG puro e disfarçado de `.png`, `.pdf` com conteúdo de PNG, executável renomeado, DOCX zip bomb, ZIP sem `word/document.xml`, imagem de 64 MP, arquivo grande demais, arquivo vazio, envelope enviado, envelope de outra organização); despacho na fila `conversions`; sanitização do nome.
- `DocumentProcessingTest` — PDF cifrado e PDF **já assinado** (fixture gerada com `gen-test-cert` + `sign`) ficam `blocked` com o original preservado byte a byte; PDF corrompido; DOCX sem LibreOffice → `failed` honesto; DOCX com o binário falso → `ready`; `pages_meta` idêntico ao `inspect`, inclusive na página girada 90°; idempotência do job; recomputação da prontidão do envelope.
- `DocumentAccessTest` — `preview` (cabeçalhos, 404 sem versão exibível, 403 para membro que não criou, 404 entre organizações), `status`, miniatura 404, downloads (`original` em qualquer status, `signed`/`evidence` só após conclusão, arquivo sumido, isolamento) e `envelope.downloaded`.

Fixtures em `tests/Feature/Documents/Support/DocumentFixtures.php`, todas geradas em tempo de teste (nenhum binário versionado): PDF assinado, PDF com página rotacionada (pypdf do venv), DOCX válido, DOCX zip bomb e ZIP sem `word/document.xml`.

Os testes **não** usam `Storage::fake('documents')`: ela compartilha uma raiz entre todos os testes e precisa limpá-la a cada execução, o que no Windows falha de forma intermitente (`FilesystemIterator: o sistema não pode encontrar o arquivo`) enquanto o SO ainda solta handles. Em vez disso, `fakeDocumentsDisk()` (em `Support/helpers.php`) aponta o disco `documents` para uma raiz **exclusiva do teste**, dentro da área de trabalho que o `afterEach` remove com retentativa. Verificado com três execuções consecutivas do diretório, todas verdes.
