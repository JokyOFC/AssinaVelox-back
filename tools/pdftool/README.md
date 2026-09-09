# pdftool

Ferramenta de linha de comando autocontida, em Python, usada pelo backend Laravel do **AssinaVelox** para **inspecionar, compor, assinar (PAdES B-B) e validar PDFs**. O Laravel a invoca como subprocesso (argumentos estruturados, sem shell) e recebe **exatamente um objeto JSON em stdout**.

- Sem rede em tempo de execucao (nenhuma consulta OCSP/CRL/TSA, nenhum download).
- Sem `shell=True`, sem `eval`; caminhos tratados com `pathlib`.
- Senhas de certificado **somente** via variavel de ambiente, nunca por argumento e nunca impressas.

## Sumario

1. [Requisitos e instalacao](#requisitos-e-instalacao)
2. [Como o Laravel deve invocar](#como-o-laravel-deve-invocar)
3. [Contrato geral de saida e codigos de saida](#contrato-geral-de-saida-e-codigos-de-saida)
4. [Convencao de coordenadas](#convencao-de-coordenadas)
5. [Comandos](#comandos): `inspect`, `image2pdf`, `compose`, `append`, `sign`, `validate`, `cert-info`, `gen-test-cert`, `selftest`
6. [Codigos de erro](#codigos-de-erro)
7. [Seguranca](#seguranca)
8. [Limitacoes](#limitacoes)
9. [Testes](#testes)
10. [Estrutura do pacote](#estrutura-do-pacote)

## Requisitos e instalacao

- Python **3.13** (testado com 3.13.14) em Windows ou Linux. Nao ha dependencias de sistema (sem Docker, sem Ghostscript, sem poppler).
- Dependencias fixadas em `requirements.txt` (e o congelamento completo em `requirements.lock.txt`):

| Pacote    | Versao                                                           |
| --------- | ---------------------------------------------------------------- |
| pypdf     | 6.18.0                                                           |
| reportlab | 5.0.1                                                            |
| pyHanko   | 0.37.0 (traz pyhanko-certvalidator 0.32.0 e cryptography 50.0.1) |
| Pillow    | 12.3.0                                                           |
| pytest    | 9.1.1                                                            |

Instalacao (a partir de `tools/pdftool/`):

```bash
python -m venv .venv
# Windows
.venv/Scripts/python.exe -m pip install -r requirements.lock.txt
# Linux
.venv/bin/python -m pip install -r requirements.lock.txt
```

Verificacao rapida:

```bash
.venv/Scripts/python.exe -m pdftool selftest
```

## Como o Laravel deve invocar

O interpretador do venv e `tools/pdftool/.venv/Scripts/python.exe` no Windows e `tools/pdftool/.venv/bin/python` no Linux. O pacote `pdftool` precisa ser importavel: execute com o diretorio de trabalho em `tools/pdftool/` **ou** defina `PYTHONPATH=tools/pdftool`.

```text
<python> -m pdftool inspect       --in <pdf>
<python> -m pdftool image2pdf     --in <png|jpg|webp> --out <pdf> [--page A4|letter|fit] [--margin-pt 36]
<python> -m pdftool compose       --plan <plan.json> --out <pdf>
<python> -m pdftool append        --base <pdf> --extra <pdf> --out <pdf>
<python> -m pdftool sign          --in <pdf> --out <pdf> --pfx <arquivo.pfx> --pass-env <NOME_DA_VARIAVEL>
                                  [--field-name AssinaVelox] [--reason ..] [--location ..] [--contact ..]
                                  [--visible "<pagina>,<x>,<y>,<w>,<h>"]
<python> -m pdftool validate      --in <pdf> [--trust <pem|der>]... [--no-revocation]
<python> -m pdftool gen-test-cert --out-pfx <arquivo.pfx> --pass-env <NOME_DA_VARIAVEL>
                                  [--subject "CN=AssinaVelox TESTE,O=AssinaVelox,C=BR"] [--days 365] [--out-pem <arquivo.pem>]
<python> -m pdftool selftest      [--keep]
```

Exemplo com Symfony Process (sem shell, argumentos como array, senha via ambiente):

```php
use Symfony\Component\Process\Process;

$python = base_path('tools/pdftool/.venv/Scripts/python.exe'); // Linux: .venv/bin/python
$process = new Process(
    [$python, '-m', 'pdftool', 'sign',
        '--in', $entrada, '--out', $saida,
        '--pfx', $pfx, '--pass-env', 'ASSINAVELOX_PFX_PASS',
        '--reason', 'Assinatura eletronica', '--location', 'Brasil'],
    base_path('tools/pdftool'),                         // cwd
    ['ASSINAVELOX_PFX_PASS' => $senhaDoCertificado],    // somente as variaveis necessarias
    null,
    120
);
$process->run();
$json = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
if ($process->getExitCode() !== 0 || !($json['ok'] ?? false)) {
    // $json['error']['code'] e $json['error']['message']; stderr em $process->getErrorOutput()
}
```

Sempre leia stdout como **UTF-8** (o JSON e emitido com `ensure_ascii=False`). Use caminhos absolutos.

## Contrato geral de saida e codigos de saida

- **stdout**: exatamente uma linha JSON (UTF-8, terminada em `\n`). Nada mais e escrito em stdout, nem em caso de erro.
- **stderr**: diagnosticos (avisos do pypdf/pyHanko, tracebacks de erros internos). Nao faz parte do contrato; registre em log.
- Sucesso: objeto com `"ok": true` e os campos do comando.
- Erro: `{"ok": false, "error": {"code": "<snake_case>", "message": "<texto legivel, sem segredos>"}}`.

| Codigo de saida | Significado                                                                                                                        |
| --------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `0`             | sucesso                                                                                                                            |
| `2`             | erro de uso: argumentos invalidos, plano invalido, variavel de ambiente ausente (`missing_passphrase`)                             |
| `3`             | erro de processamento (falha inesperada com entrada aparentemente valida; `internal_error`, `signing_failed`, `pdf_write_failed`)  |
| `4`             | entrada rejeitada: PDF corrompido (`invalid_pdf`), criptografado (`encrypted_pdf`), imagem invalida/nao suportada, arquivo ausente |

## Convencao de coordenadas

Usada por `compose` (campos) e por `sign --visible`:

- `x`, `y`, `width`, `height` sao fracoes em `[0, 1]` da pagina **como exibida** (CropBox apos aplicar `/Rotate`), exatamente como um canvas HTML sobreposto ao PDF.js.
- Origem no **canto superior esquerdo**; `y` cresce para baixo.
- A conversao para o espaco do usuario do PDF (origem inferior esquerda, pagina nao rotacionada, CropBox com deslocamento) e feita por `pdftool/geometry.py` (`normalized_to_pdf_rect`) para `/Rotate` 0/90/180/270. O texto e as imagens sao desenhados **em pe em relacao a exibicao** da pagina.
- Campo opcional `"box": "cropbox" | "mediabox"` (padrao `cropbox`) define a caixa de referencia.

`inspect` devolve, por pagina, `width_pt`/`height_pt` ja **exibidos** (trocados para 90/270) para que o front-end calcule as fracoes.

## Comandos

### `inspect --in <pdf>`

Descreve o PDF sem modifica-lo.

```json
{
    "ok": true,
    "pdf_version": "1.7",
    "page_count": 2,
    "encrypted": false,
    "openable": true,
    "has_signatures": true,
    "signature_count": 1,
    "signature_fields": ["AssinaVelox"],
    "has_acroform": true,
    "has_xfa": false,
    "metadata": {
        "title": null,
        "author": null,
        "producer": "ReportLab PDF Library",
        "creator": "ReportLab"
    },
    "pages": [
        {
            "index": 1,
            "rotation": 0,
            "mediabox": [0, 0, 595.2756, 841.8898],
            "cropbox": [0, 0, 595.2756, 841.8898],
            "width_pt": 595.2756,
            "height_pt": 841.8898
        },
        {
            "index": 2,
            "rotation": 90,
            "mediabox": [0, 0, 595.2756, 841.8898],
            "cropbox": [0, 0, 595.2756, 841.8898],
            "width_pt": 841.8898,
            "height_pt": 595.2756
        }
    ]
}
```

- PDFs criptografados: tenta a senha de usuario vazia. Se abrir, responde `"encrypted": true` e inclui `"permissions": [...]` (ex.: `["print","modify",...]`). Se nao abrir: `encrypted_pdf`, saida 4.
- Corrompido: `invalid_pdf`, saida 4. Ausente: `missing_input`, saida 4.
- Assinaturas: campos `/FT /Sig` do AcroForm com `/V` preenchido (nomes totalmente qualificados).

### `image2pdf --in <png|jpg|webp> --out <pdf> [--page A4|letter|fit] [--margin-pt 36]`

Normaliza a imagem com Pillow e gera um PDF de uma pagina:

- Aceita PNG, JPEG e WEBP (`unsupported_image` para outros formatos).
- Rejeita mais de **40 megapixels** ou bombas de descompressao (`image_too_large`).
- Aplica a orientacao EXIF, converte para RGB (transparencia composta sobre branco), **remove todos os metadados** (EXIF/ICC/XMP/chunks de texto) copiando os pixels para uma imagem nova, e limita o lado maior a **4000 px**.
- `A4`/`letter`: escolhe retrato ou paisagem conforme a imagem, ajusta dentro das margens preservando a proporcao e centraliza. `fit`: a pagina tem o tamanho da imagem a 72 dpi (lado limitado a 14400 pt).

Saida: o mesmo objeto de `inspect` para o PDF gerado, mais `"source":{"width_px":..,"height_px":..,"format":"PNG|JPEG|WEBP"}`, `"normalized":{"width_px":..,"height_px":..}` e `"page_size"`.

### `compose --plan <plan.json> --out <pdf>`

Achata ("flatten") os valores dos campos sobre o PDF de origem, preservando todas as paginas e caixas.

```json
{
    "source": "C:/tmp/contrato.pdf",
    "font": { "path": "C:/fonts/Inter.ttf", "name": "Inter" },
    "fields": [
        {
            "id": "fld_1",
            "page": 1,
            "type": "signature",
            "x": 0.1,
            "y": 0.7,
            "width": 0.3,
            "height": 0.08,
            "image": "C:/tmp/assinatura.png"
        },
        {
            "id": "fld_2",
            "page": 1,
            "type": "name",
            "x": 0.1,
            "y": 0.79,
            "width": 0.3,
            "height": 0.04,
            "value": "Joao da Silva",
            "font_size": 10,
            "align": "left"
        },
        {
            "id": "fld_3",
            "page": 1,
            "type": "date",
            "x": 0.1,
            "y": 0.84,
            "width": 0.3,
            "height": 0.04,
            "value": "08/09/2026",
            "align": "center"
        },
        {
            "id": "fld_4",
            "page": 2,
            "type": "checkbox",
            "x": 0.05,
            "y": 0.05,
            "width": 0.03,
            "height": 0.03,
            "value": true
        },
        {
            "id": "fld_5",
            "page": 2,
            "type": "text",
            "x": 0.1,
            "y": 0.05,
            "width": 0.5,
            "height": 0.04,
            "value": "Li e concordo",
            "box": "cropbox"
        }
    ]
}
```

- `type`: `signature` | `initials` (imagem PNG/JPEG/WEBP ajustada e centralizada na caixa, transparencia preservada), `name` | `date` | `text` (texto em uma linha; fonte reduzida automaticamente ate caber na largura/altura, minimo 5 pt; centralizado verticalmente; 2 pt de margem interna; `align` = `left` | `center` | `right`), `checkbox` (`true` desenha um quadrado com marca de duas linhas; `false` nao desenha nada).
- Fonte padrao **Helvetica** (WinAnsi, cobre acentos do portugues). Com `font.path` (TTF) a fonte e registrada e incorporada (Unicode completo).
- Valores vazios (`""`, `null`, checkbox `false`) sao pulados e listados em `skipped`.
- Erros: imagem inexistente -> `missing_image` (4); origem inexistente -> `missing_source` (4); plano malformado, tipo desconhecido, pagina fora do intervalo -> `invalid_plan` (2); fonte -> `missing_font`/`invalid_font` (4); `--out` igual a origem -> `same_path` (2).
- Caminhos relativos sao resolvidos a partir do diretorio de trabalho; use caminhos absolutos.

Saida: `{"ok":true,"page_count":2,"fields_drawn":5,"skipped":[]}`.

### `append --base <pdf> --extra <pdf> --out <pdf>`

Paginas de `base` seguidas das paginas de `extra`, preservando MediaBox/CropBox/Rotate de cada pagina. Saida: `{"ok":true,"page_count":N}`.

### `sign --in <pdf> --out <pdf> --pfx <arquivo> --pass-env <VAR> [...]`

Aplica **uma** assinatura **PAdES B-B** (`/ETSI.CAdES.detached`, SHA-256) com pyHanko como **atualizacao incremental**: o arquivo original e mantido byte a byte e revisoes/assinaturas anteriores sao preservadas. Se o documento ja tem assinaturas, a nova e simplesmente adicionada.

- `--pass-env` recebe o **nome** da variavel de ambiente com a senha do PKCS#12. Variavel ausente ou vazia -> `missing_passphrase`, saida 2. Senha errada / arquivo invalido -> `pfx_load_failed`, saida 4.
- `--field-name` (padrao `AssinaVelox`): se ja existir um campo de assinatura **vazio** com esse nome, ele e preenchido; se ja existir um campo **assinado**, usa-se `AssinaVelox_2`, `_3`, ... O nome efetivo vem em `field_name`.
- `--visible "<pagina>,<x>,<y>,<w>,<h>"`: carimbo textual simples (nome do signatario e data/hora) na posicao normalizada; em paginas rotacionadas o carimbo e exibido em pe (`/Matrix` na aparencia). Sem `--visible` a assinatura e invisivel.
- PDFs criptografados sao rejeitados (`encrypted_pdf`, 4). PDFs corrompidos: `invalid_pdf` (4). Recusa do pyHanko (ex.: documento certificado que proibe alteracoes): `signing_failed` (3).

```json
{
    "ok": true,
    "profile": "PAdES-B-B",
    "field_name": "AssinaVelox",
    "signer_subject": "CN=AssinaVelox TESTE,O=AssinaVelox,C=BR",
    "issuer": "CN=AssinaVelox TESTE,O=AssinaVelox,C=BR",
    "serial_hex": "034b9be4...",
    "cert_fingerprint_sha256": "0f1a64b4...",
    "not_before": "2026-09-08T16:23:13+00:00",
    "not_after": "2026-10-08T16:28:13+00:00",
    "md_algorithm": "sha256",
    "timestamp": null,
    "visible": false,
    "page_count": 3
}
```

### `validate --in <pdf> [--trust <pem|der>]... [--no-revocation]`

Valida cada assinatura com `pyhanko.sign.validation.validate_pdf_signature`.

- `--trust` (repetivel): certificados raiz confiaveis (PEM com um ou varios certificados, ou DER). A cadeia do signatario e validada contra eles e `trusted` reflete o resultado.
- **Sem** `--trust`: o proprio certificado do signatario e usado como ancora **apenas** para verificar integridade e criptografia; a resposta traz `"trusted": false, "trust_reason": "no_trust_roots_configured"`. A ferramenta **nunca** afirma confianca sem cadeia validada.
- Revogacao (CRL/OCSP) **nao e verificada** (exigiria rede). `--no-revocation` e aceito por compatibilidade; a resposta sempre traz `"revocation": "not_checked"`.
- Chave do certificado: aceita `nonRepudiation` **ou** `digitalSignature`.

```json
{
    "ok": true,
    "signature_count": 1,
    "all_intact": true,
    "all_valid": true,
    "all_covering": true,
    "all_docmdp_ok": true,
    "trust_roots_configured": 1,
    "revocation": "not_checked",
    "signatures": [
        {
            "field_name": "AssinaVelox",
            "intact": true,
            "valid": true,
            "trusted": true,
            "trust_reason": null,
            "signer_subject": "CN=...",
            "issuer": "CN=...",
            "serial_hex": "...",
            "cert_fingerprint_sha256": "...",
            "not_before": "...",
            "not_after": "...",
            "signing_time": "2026-09-08T16:28:13+00:00",
            "md_algorithm": "sha256",
            "subfilter": "/ETSI.CAdES.detached",
            "coverage": "ENTIRE_FILE",
            "modification_level": "NONE",
            "docmdp_ok": null,
            "revocation": "not_checked",
            "summary": "INTACT:TRUSTED,UNTOUCHED",
            "errors": []
        }
    ]
}
```

Campos por assinatura:

| Campo                | Significado                                                                                                                                                   |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `intact`             | os bytes cobertos pelo `/ByteRange` nao foram alterados (digest confere)                                                                                      |
| `valid`              | a assinatura CMS e criptograficamente valida                                                                                                                  |
| `trusted`            | `valid`, `intact` **e** cadeia validada ate uma raiz de `--trust`                                                                                             |
| `trust_reason`       | `null` quando confiavel; senao `no_trust_roots_configured`, indicacao do pyHanko (ex.: `NO_CERTIFICATE_CHAIN_FOUND`), `signature_invalid`, `validation_error` |
| `coverage`           | `ENTIRE_FILE`, `ENTIRE_REVISION`, `CONTIGUOUS_BLOCK_FROM_START`, `UNCLEAR`                                                                                    |
| `modification_level` | `NONE`, `LTA_UPDATES`, `FORM_FILLING`, `ANNOTATIONS`, `OTHER` (ou `null` se a analise de diferencas nao for conclusiva)                                       |
| `docmdp_ok`          | respeito ao nivel DocMDP (`null` se nao ha assinatura de certificacao)                                                                                        |
| `summary`            | `summary()` do pyHanko (`INTACT:...` ou `INVALID`)                                                                                                            |
| `errors`             | lista legivel: `digest_mismatch`, `invalid_signature`, `trust: ...`, `suspicious_modification`, `docmdp_violation`, `validation_error`                        |

Agregados:

| Campo           | Significado                                                                        |
| --------------- | ---------------------------------------------------------------------------------- |
| `all_intact`    | `intact` em todas as assinaturas — apenas sobre os bytes **cobertos** por cada uma |
| `all_valid`     | `intact` **e** `valid` em todas                                                    |
| `all_covering`  | `coverage == ENTIRE_FILE` em todas: nao ha conteudo fora da revisao assinada       |
| `all_docmdp_ok` | nenhuma assinatura com `docmdp_ok == false`                                        |

`all_intact`/`all_valid` sao `false` quando o PDF nao tem assinaturas (`signature_count: 0`); `all_covering` e `all_docmdp_ok` tambem.

> **Atencao:** `all_intact` **nao** significa "o arquivo nao mudou depois da assinatura". Um PDF com uma atualizacao incremental acrescentada apos a revisao assinada continua `intact`, `valid` e ate `trusted`; o que muda e `coverage` (`ENTIRE_REVISION`) e, quando a alteracao toca o catalogo, `modification_level`/`docmdp_ok`. Quem quiser afirmar integridade do arquivo inteiro precisa de `all_intact` **e** `all_covering` **e** `all_docmdp_ok`.

### `cert-info --pfx <arquivo> --pass-env <VAR>`

Metadados **publicos** do certificado dentro de um PKCS#12, sem assinar nada e sem gravar nada: `subject`, `issuer`, `serial_hex`, `cert_fingerprint_sha256`, `not_before`, `not_after`, `self_signed`, `has_private_key`, `chain_length`, `pfx_path`. A senha e lida da variavel de ambiente pelo NOME (nunca de `argv`) e nao aparece na saida nem em mensagem de erro. Nenhum material de chave e exposto.

Serve para **identificar o certificado que vai assinar antes de a assinatura existir** — por exemplo, para a pagina de evidencias imprimir o titular e o emissor corretos em vez de adivinha-los pela ultima linha de `certificate_references`.

Erros: `missing_input` (arquivo ausente), `invalid_pkcs12` (senha errada, container corrompido ou sem certificado de titular), `missing_passphrase`/`invalid_env_name`.

### `gen-test-cert --out-pfx <arquivo> --pass-env <VAR> [--subject ..] [--days 365] [--out-pem <arquivo>]`

Gera um certificado **autoassinado de TESTE** (RSA-2048, SHA-256, KeyUsage `digitalSignature` + `nonRepudiation`, EKU emailProtection/clientAuth) e o grava em PKCS#12 protegido pela senha da variavel de ambiente. O CN sempre contem `TESTE` (o sufixo e acrescentado se faltar). `--out-pem` grava tambem o certificado em PEM para uso em `validate --trust`.

> **Atencao:** certificados gerados aqui **nao sao ICP-Brasil**, nao tem validade juridica e servem apenas para testes automatizados e ambientes de desenvolvimento. Em producao use certificados A1 (.pfx) emitidos por AC da ICP-Brasil.

Saida: `subject`, `issuer`, `serial_hex`, `cert_fingerprint_sha256`, `not_before`, `not_after`, `pfx_path`, `pem_path`, `self_signed: true`, `test_only: true`, `warning`.

### `selftest [--keep]`

Em um diretorio temporario: gera um PDF de 2 paginas (a segunda com `/Rotate 90`), compoe 5 campos (incluindo assinatura PNG), anexa uma pagina extra, roda `inspect` e `image2pdf`, gera certificado de teste, assina (carimbo visivel) e valida com e sem raiz de confianca. Saida `{"ok":true,"steps":[{"step":"compose","ok":true,"ms":42,...},...],"temp_dir":null}`. Com `--keep` o diretorio e mantido e informado em `temp_dir`.

## Codigos de erro

| `error.code`                                                                              | Saida | Comandos                                 | Situacao                                                           |
| ----------------------------------------------------------------------------------------- | ----- | ---------------------------------------- | ------------------------------------------------------------------ |
| `usage_error`                                                                             | 2     | todos                                    | argumentos invalidos/ausentes                                      |
| `missing_passphrase`                                                                      | 2     | sign, cert-info, gen-test-cert           | variavel de ambiente ausente ou vazia                              |
| `invalid_env_name`                                                                        | 2     | sign, cert-info, gen-test-cert           | nome de variavel invalido                                          |
| `invalid_pkcs12`                                                                          | 2     | cert-info                                | PKCS#12 corrompido, senha errada ou sem certificado de titular     |
| `invalid_plan`, `missing_plan`                                                            | 2     | compose                                  | plano JSON malformado ou inexistente                               |
| `same_path`                                                                               | 2     | compose, append, sign                    | `--out` igual a entrada                                            |
| `invalid_visible`                                                                         | 2     | sign                                     | especificacao `--visible` invalida                                 |
| `invalid_page_size`, `invalid_margin`                                                     | 2     | image2pdf                                | opcoes invalidas                                                   |
| `invalid_subject`, `invalid_days`                                                         | 2     | gen-test-cert                            | parametros invalidos                                               |
| `trust_file_not_found`, `invalid_trust_file`                                              | 2     | validate                                 | arquivo de `--trust` ausente/ilegivel                              |
| `missing_input`, `missing_source`, `missing_image`, `missing_font`, `pfx_not_found`       | 4     | varios                                   | arquivo de entrada inexistente                                     |
| `invalid_pdf`                                                                             | 4     | todos com PDF                            | PDF corrompido/ilegivel                                            |
| `encrypted_pdf`                                                                           | 4     | inspect, compose, append, validate, sign | criptografado sem senha vazia (sign rejeita qualquer criptografia) |
| `unsupported_image`, `invalid_image`, `image_too_large`                                   | 4     | image2pdf, compose                       | imagem rejeitada                                                   |
| `invalid_font`                                                                            | 4     | compose                                  | TTF ilegivel                                                       |
| `pfx_load_failed`                                                                         | 4     | sign                                     | senha errada ou PKCS#12 invalido                                   |
| `signing_failed`, `pdf_write_failed`, `write_failed`, `selftest_failed`, `internal_error` | 3     | varios                                   | falha de processamento                                             |

## Seguranca

- **Senhas**: apenas via variavel de ambiente nomeada em `--pass-env`; nunca aparecem em argv, em stdout, em stderr ou em mensagens de erro. Passe ao subprocesso somente as variaveis necessarias.
- **Sem rede**: `ValidationContext(allow_fetching=False)`; nenhum TSA/OCSP/CRL. A ferramenta funciona offline.
- **Sem shell**: invoque com array de argumentos (`Process`), nunca concatenando strings de comando.
- **Entradas hostis**: PDFs sao abertos em modo tolerante e qualquer falha de parsing vira `invalid_pdf`; imagens tem limites (40 MP, 4000 px, bomba de descompressao) e perdem todos os metadados; o texto dos campos e saneado para uma linha. Ainda assim, trate o diretorio de trabalho como temporario e aplique limites de tempo/memoria no processo.
- **PDF criptografado**: `inspect` abre com senha de usuario vazia e informa `encrypted: true`; `compose`/`append` geram saida **sem** criptografia (a protecao original e removida); `sign` recusa.
- **Confianca**: `validate` so afirma `trusted: true` com cadeia validada ate uma raiz fornecida em `--trust`; sem raizes o campo e `false` com `trust_reason` explicito. Revogacao nunca e assumida como OK (`revocation: not_checked`). Para validade juridica plena (ICP-Brasil) complemente com um verificador oficial (ex.: Verificador de Conformidade do ITI).
- **Certificados de teste**: autoassinados, com `TESTE` no CN, apenas para testes; nao sao ICP-Brasil.

## Limitacoes

- Perfil **PAdES B-B** apenas: sem carimbo do tempo (B-T), sem LTV/LTA, sem DSS. `timestamp` e sempre `null`.
- Nenhuma verificacao de revogacao (CRL/OCSP).
- Apenas certificados **A1** (arquivo PKCS#12). Tokens/smartcards (A3, PKCS#11) nao sao suportados.
- Carimbo visivel simples (texto em Courier, borda), sem imagem/logotipo.
- `compose`: texto em uma unica linha por campo; fontes padrao sao WinAnsi (caracteres fora dessa tabela virem `?` - use `font.path` TTF para Unicode completo); nao preenche campos AcroForm existentes (apenas desenha sobre a pagina).
- `append`: paginas sao copiadas com `add_page`; campos AcroForm do documento extra nao sao fundidos ao AcroForm da base.
- XFA e apenas detectado (`has_xfa`), nao processado.
- `modification_level` pode ser `null` quando o pyHanko nao consegue concluir a analise de diferencas.
- Um PDF sem assinaturas retorna `all_intact: false`.

## Testes

```bash
./run_tests.sh          # Linux / Git Bash
.\run_tests.ps1         # PowerShell
# ou
.venv/Scripts/python.exe -m pytest -q
```

Todos os artefatos de teste (PDFs, imagens, certificados) sao gerados programaticamente em `tmp_path`; nenhum binario e versionado. A suite cobre: geometria (4 rotacoes + CropBox deslocado), `inspect` (normal, rotacionado, criptografado com senha de proprietario, com senha de usuario, corrompido, assinado), `image2pdf` (retrato/paisagem/fit, limite de pixels, remocao de metadados, EXIF), `compose` (posicao do texto verificada por extracao com pypdf e orientacao em pe nas 4 rotacoes, imagem com transparencia, checkbox, auto-reducao de fonte, TTF), `append`, `sign`+`validate` (ida e volta, confianca com/sem `--trust`, duas assinaturas preservando a primeira, documento adulterado, carimbo visivel em pagina rotacionada, senha ausente -> 2, senha errada -> 4, entrada corrompida -> 4) e `selftest`.

## Estrutura do pacote

```text
tools/pdftool/
  README.md
  requirements.txt            # pins exatos
  requirements.lock.txt       # pip freeze do venv
  pytest.ini
  run_tests.sh / run_tests.ps1
  pdftool/
    __init__.py   __main__.py   cli.py        # argparse + contrato JSON/exit codes
    errors.py                                 # PdfToolError / UsageError / ProcessingError / InputRejected
    geometry.py                               # coordenadas normalizadas -> espaco do PDF (rotacao + CropBox)
    inspect_cmd.py                            # inspect + abertura segura de PDFs (pypdf)
    images.py                                 # normalizacao Pillow + image2pdf
    compose.py                                # compose (overlay reportlab + merge pypdf) e append
    certs.py                                  # gen-test-cert, cert-info e utilitarios de certificado
    sign.py                                   # PAdES B-B com pyHanko (incremental, carimbo rotacionado)
    validate.py                               # validacao pyHanko com/sem raizes de confianca
    selftest.py                               # smoke test ponta a ponta
  tests/                                      # pytest (fixtures geradas em tmp_path)
```
