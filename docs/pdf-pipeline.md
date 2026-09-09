# Pipeline de PDF — integração Laravel × pdftool × LibreOffice

> Documento operacional da camada `App\Services\Pdf` + `App\Integrations\Pdf`. Vocabulário e regras de negócio: `docs/arquitetura.md` §2, §5 e §8. Contrato da ferramenta Python: `tools/pdftool/README.md`.

## 1. Arquitetura

O **Laravel orquestra**; o **pdftool é um processo controlado**. Nenhuma biblioteca de PDF roda dentro do PHP além do DOMPDF (página de evidências) e do GD (normalização de imagens).

```
Job/Serviço Laravel
   │
   ├─ PdfConverterManager ──► PassthroughPdfConverter (pdf)   ──► PdfToolClient::inspect
   │                      ──► ImageToPdfConverter (image)    ──► GD (ImageNormalizer) ──► PdfToolClient::imageToPdf
   │                      ──► LibreOfficeConverter (docx)    ──► soffice --headless (processo isolado) ──► PdfToolClient::inspect
   │                      ──► FakePdfConverter (dev/test)    ──► copia fixture + WARNING em log
   │
   ├─ PdfToolClient ── compose / append / sign / validate / gen-test-cert / selftest
   │        │
   │        └─ Symfony\Component\Process\Process([python, -m, pdftool, <cmd>, ...])   cwd = tools/pdftool
   │                 stdout = 1 JSON · stderr = diagnóstico · exit 0/2/3/4
   │
   └─ PdfSigner ──► PyHankoSigner (certificado A1 configurado)  ──► PdfToolClient::sign (PAdES B-B)
                ──► NullPdfSigner (sem certificado)             ──► lança SignerNotConfiguredException
```

| Componente      | Local                                              | Papel                                                                                                                                                                                                                                                                                                           |
| --------------- | -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PdfToolClient` | `app/Services/Pdf/PdfToolClient.php`               | Único ponto que executa o pdftool. Timeout, ambiente mínimo, parse do JSON, exceções tipadas, logs, correlation id, diretório temporário exclusivo.                                                                                                                                                             |
| DTOs do pdftool | `app/Services/Pdf/Dto/*`                           | `PdfInspection`/`PdfPage`, `ComposePlan` (builder) / `ComposeField` / `ComposeResult`, `SignOptions` / `VisibleStamp` / `SignResult`, `ValidationResult` / `SignatureValidation`. Todos `readonly`, exceto o builder.                                                                                           |
| Exceções        | `app/Services/Pdf/Exceptions/*`                    | `PdfToolUsageException` (exit 2), `PdfToolProcessingException` (exit 3, timeout, saída inválida), `PdfToolInputRejectedException` (exit 4). Carregam `errorCode` (snake_case do JSON), `exitCode`, `command` (argv saneado), `correlationId`, `stderrExcerpt`. `ImageRejectedException` vem da normalização GD. |
| Suporte         | `app/Services/Pdf/Support/*`                       | `TemporaryDirectory`, `ProcessEnvironment`, `ImageNormalizer`, `JpegOrientation`.                                                                                                                                                                                                                               |
| Contratos       | `app/Integrations/Contracts/*`                     | `PdfConverter`, `PdfSigner`, `EmailProvider`, `PaymentGateway` (Fase 1) e os reservados (`SmsProvider`, `WhatsAppProvider`, `CpfVerificationProvider`, `CnpjLookupProvider`, `IdentityVerificationProvider`, `TimestampProvider`, `FiscalInvoiceProvider` — Fase 2/3, sem implementação).                       |
| Adaptadores     | `app/Integrations/Pdf/*`                           | Conversores, manager, signers.                                                                                                                                                                                                                                                                                  |
| Provider        | `app/Integrations/IntegrationsServiceProvider.php` | Bindings (precisa estar em `bootstrap/providers.php`).                                                                                                                                                                                                                                                          |
| Config          | `config/pdftool.php`                               | Todas as chaves lidas de `.env` (seção 9). Nomes alinhados ao `.env.example` (`PDFTOOL_PYTHON`, `LIBREOFFICE_BIN`, `COMPANY_CERT_*`).                                                                                                                                                                           |
| Comando         | `php artisan pdftool:selftest`                     | Diagnóstico operacional (seção 8).                                                                                                                                                                                                                                                                              |

### Contrato de erro dos conversores

- Problema **do documento** → `ConversionResult` com `status` `blocked` (PDF protegido por senha, já assinado digitalmente, inválido) ou `failed` (imagem inválida/não suportada, LibreOffice sem saída), `reasonCode` estável e `reasonMessage` em PT-BR (`ConversionMessages`). Original preservado; não repetir.
- Problema **de infraestrutura** → exceção: `ConverterNotConfiguredException` (LibreOffice sem binário, pdftool sem venv), `UnsupportedSourceTypeException`, `PdfToolProcessingException` (timeout/crash do pdftool). O job deve falhar de forma visível e tentar de novo depois, não marcar o documento como inválido.

### Coordenadas

`ComposePlan` serializa exatamente o formato do README do pdftool: frações `[0, 1]` relativas ao **CropBox exibido** (após `/Rotate`), origem no canto **superior esquerdo**, `y` cresce para baixo — o mesmo referencial de `signing_fields` no banco e do canvas sobre o PDF.js. `PdfInspection::pages[].widthPt/heightPt` já vêm trocados para páginas rotacionadas 90/270.

## 2. Segurança

**Sem shell.** Todo processo é `new Process([...argv])` com argumentos em array. Nunca se concatena linha de comando; caminhos, nomes de campos, `--reason`/`--location` passam íntegros mesmo com espaços, aspas, `&`, `%`, `!` e acentos (verificado no Windows, onde o Symfony Process delega a `cmd.exe` com escape próprio). Caminhos precisam ser absolutos (`InvalidArgumentException` caso contrário).

**Ambiente mínimo, nunca herdado** (`App\Services\Pdf\Support\ProcessEnvironment`). O ambiente do PHP (CLI, FPM, worker) carrega `APP_KEY`, credenciais de banco/S3/SMTP, tokens de gateway e a própria passphrase do certificado. Um processo filho poderia gravá-los em tracebacks, perfis, arquivos temporários; uma biblioteca comprometida os leria trivialmente. Por isso **toda** variável visível ao PHP é marcada com `false` (o Symfony Process a remove do filho) e só passam:

| Variável                                                           | Quando                               | Motivo                                                                                                                                                                                         |
| ------------------------------------------------------------------ | ------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SYSTEMROOT`, `COMSPEC`, `PATH`                                    | Windows                              | `SYSTEMROOT`: carregamento de DLLs do sistema/Winsock; `COMSPEC`: o Symfony Process executa o filho via `cmd.exe` no Windows; `PATH`: localização de dependências do interpretador/LibreOffice |
| `PATH`, `LANG=C.UTF-8`                                             | Linux                                | localização de binários auxiliares; nomes de arquivo UTF-8                                                                                                                                     |
| `TEMP`, `TMP`, `TMPDIR`, `HOME` (Linux) / `USERPROFILE` (Windows)  | sempre                               | apontam para o **diretório temporário exclusivo da operação** — nada é gravado fora dele                                                                                                       |
| `PYTHONUTF8=1`, `PYTHONIOENCODING=utf-8`, `PYTHONNOUSERSITE=1`     | pdftool                              | stdout/stderr UTF-8; ignora `~/.local/lib/pythonX/site-packages`                                                                                                                               |
| **`<COMPANY_CERT_PASSWORD_ENV>`** (padrão `COMPANY_CERT_PASSWORD`) | **somente** `sign` e `gen-test-cert` | a senha do PKCS#12, sob o nome configurado                                                                                                                                                     |
| `pdftool.libreoffice.env` (ex.: `SAL_USE_VCLPLUGIN=svp`)           | LibreOffice                          | extras explícitos de config; nunca segredos                                                                                                                                                    |

O `cmd.exe` do Windows acrescenta `PATHEXT` e `PROMPT` por conta própria. A lista é verificada por teste (`ProcessEnvironment::minimal` — toda variável visível ao PHP fora da lista aparece com `false`; `TEMP`/`TMP`/`TMPDIR`/`HOME|USERPROFILE` iguais ao diretório exclusivo) e pelo binário falso do LibreOffice, que grava o ambiente que efetivamente recebeu (sem `APP_KEY`, `DB_PASSWORD`, `APP_NAME`).

**Senha do certificado só por variável de ambiente nomeada.** A config guarda o **nome** da variável (`COMPANY_CERT_PASSWORD_ENV`, padrão `COMPANY_CERT_PASSWORD`), nunca o valor. O `PdfToolClient` lê o valor do ambiente do PHP (`Env::getRepository()`, ou seja `.env`/`$_ENV`/`$_SERVER`/`putenv`) e o injeta no ambiente do filho **sob esse mesmo nome**, passando ao pdftool apenas `--pass-env <NOME>`. O valor nunca aparece em argv, log, exceção, fila ou banco; se aparecer em stderr por acidente de biblioteca, o cliente o redige (`[REDACTED]`) antes de registrar. Variável ausente/vazia → `PdfToolUsageException(missing_passphrase)` **antes** de iniciar o processo. Senha errada → `PdfToolInputRejectedException(pfx_load_failed)`. Verificado por testes explícitos: argv e mensagem das exceções (variável ausente e senha errada) e **todos os registros de log** de uma assinatura bem-sucedida e de uma com senha errada (logger capturado em memória) não contêm a senha; os registros trazem `--pass-env <NOME>` e nunca a chave `env`.

A assinatura só é ligada com `COMPANY_CERT_ENABLED=true`. `PyHankoSigner::isConfigured()` exige, ao mesmo tempo: `enabled`, arquivo PFX existente em `COMPANY_CERT_PFX_PATH`, variável de senha definida e não vazia no ambiente do PHP, e pdftool disponível; faltando qualquer um, o container resolve `PdfSigner` para `NullPdfSigner` e `pdftool:selftest` lista os motivos.

> Com `php artisan config:cache`, o Laravel **não carrega o `.env`**. Em produção a variável deve estar no ambiente real do serviço: `EnvironmentFile=` da unit systemd do worker/Horizon, `env[COMPANY_CERT_PASSWORD]` no pool do PHP-FPM, ou gerenciador de segredos que exporte a variável. Nunca em `config/*.php`.

**Diretórios temporários exclusivos.** `storage/app/tmp/pdftool/<ulid>` (ou `PDFTOOL_TMP_PATH`), `mkdir 0700`, um por operação, removidos em `finally` — também quando a operação termina em exceção (verificado por teste para PDF corrompido, senha ausente, senha errada, LibreOffice sem saída e timeout do LibreOffice) — com nova tentativa no Windows por causa de handles recém-liberados; destrutor como rede de segurança. O LibreOffice ganha três subpastas: `in/` (entrada copiada com nome neutro `input.docx`), `out/`, `profile/` (perfil de usuário isolado). `storage/app/.gitignore` já ignora `tmp/`.

**Timeouts.** `PDFTOOL_TIMEOUT_SECONDS` (60) por comando, `PDFTOOL_SIGN_TIMEOUT_SECONDS` (120) para `sign`/`gen-test-cert`/`selftest`, `LIBREOFFICE_TIMEOUT_SECONDS` (120). Ao estourar, o filho é encerrado (`taskkill /F /T` no Windows, SIGKILL no Linux); pdftool → `PdfToolProcessingException(timeout)`; LibreOffice → `ConversionResult failed (timeout)` com `details.timeout_seconds`. O caminho do LibreOffice é exercitado por teste com o binário falso atrasado (`FAKE_SOFFICE_SLEEP`) e timeout de 1 s: o processo é encerrado, nenhum PDF é gravado e o diretório temporário é removido.

**O que NUNCA vai para logs:** o valor da passphrase; o ambiente do processo filho; conteúdo dos documentos; o plano de composição com valores de campos (só `fields_drawn`/`skipped`). O que vai: comando, argv (caminhos locais e opções), exit code, duração, correlation id, stderr **truncado** (`pdftool.stderr_log_limit`, 4000 caracteres) e sem caracteres de controle. Nível `debug` em sucesso, `warning` em erro, `error` em timeout.

**Imagens.** `ImageNormalizer` (GD) roda antes do pdftool: MIME real por `finfo` (SVG e qualquer coisa fora de PNG/JPEG/WEBP é recusada — SVG pode conter script e referências externas), dimensões lidas do cabeçalho antes de decodificar (limite 40 MP), verificação de `memory_limit`, orientação EXIF (leitor próprio, não depende da extensão `exif`), redução a 4000 px e reencode do zero (remove EXIF/ICC/XMP/chunks de texto). O pdftool repete a normalização com Pillow.

Memória: a memória do GD conta para `memory_limit`. O normalizador mantém no máximo **duas** imagens vivas (a origem é liberada antes de codificar a cópia reduzida; sem redução, JPEG e PNG-com-alfa são reencodados diretamente, sem canvas) e usa `imagefilledrectangle` em vez de `imagefill` (o flood fill aloca uma pilha proporcional à área). Medidas locais (PHP 8.3, GD bundled): 4100×600 PNG → pico +20 MB; 4200×1000 → +34 MB; 800×600 JPEG → +2 MB; 6000×6000 (36 MP) → +244 MB; 6320×6320 (40 MP, o máximo) → +288 MB. Antes de decodificar, `assertFitsInMemory` estima ~8 bytes/pixel (decodificação) ou 4 bytes/pixel da origem + 8 do destino e recusa com `failed (image_too_large)` se não couber no `memory_limit` restante — em vez de derrubar o worker. Recomendação: workers que processam imagens com `memory_limit` **≥ 512M** (processa qualquer imagem dentro dos limites de 40 MP/4000 px); com 256M, imagens acima de ~25 MP são recusadas antes de alocar (coberto por teste). A suíte `--filter=Pdf` foi executada com `memory_limit=512M` sem falhas.

**Confiança.** `validate` só afirma `trusted=true` com cadeia até uma raiz em `PDFTOOL_TRUST_ROOTS`; sem raízes, `trusted=false` e `trust_reason=no_trust_roots_configured`. Revogação (CRL/OCSP) **nunca** é verificada (`revocation=not_checked`) — o pdftool roda sem rede. Para validade jurídica plena (ICP-Brasil), complementar com verificador oficial (ITI).

## 3. Instalação do pdftool em produção (Linux)

Pré-requisito: Python 3.13 (`python3 --version`). Não há dependências de sistema (sem Ghostscript/poppler/Docker).

```bash
cd /var/www/assinavelox            # raiz do projeto Laravel
python3 -m venv tools/pdftool/.venv
tools/pdftool/.venv/bin/python -m pip install --upgrade pip
tools/pdftool/.venv/bin/python -m pip install -r tools/pdftool/requirements.lock.txt
tools/pdftool/.venv/bin/python -m pdftool selftest    # a partir de tools/pdftool, ou com PYTHONPATH=tools/pdftool
php artisan pdftool:selftest                          # visão do Laravel (config, adaptadores)
```

- `.venv` está no `.gitignore`; recrie a cada deploy em máquina nova ou quando `requirements.lock.txt` mudar (`pip install -r` é idempotente).
- O usuário do PHP/worker precisa de **leitura+execução** em `tools/pdftool` e **escrita** em `storage/app/tmp`. Escrita em `tools/pdftool/pdftool/__pycache__` é opcional (Python ignora se não puder gravar).
- `PDFTOOL_PYTHON` só é necessário se o venv estiver em outro lugar; o padrão é `tools/pdftool/.venv/bin/python` (Linux) e `.venv/Scripts/python.exe` (Windows).
- Windows (desenvolvimento): `python -m venv .venv && .venv/Scripts/python.exe -m pip install -r requirements.lock.txt` dentro de `tools/pdftool`.

## 4. LibreOffice (DOCX → PDF)

### Instalação

- **Ubuntu/Debian**: `sudo apt-get install --no-install-recommends libreoffice-writer libreoffice-core fonts-dejavu fonts-liberation` (Writer basta para DOCX/ODT/RTF; fontes evitam substituições feias). Binário: `/usr/bin/soffice`. Alternativa: pacote `.deb` oficial em libreoffice.org ou AppImage, apontando `LIBREOFFICE_BIN` para o executável.
- **Windows**: instalador oficial (libreoffice.org). Binário: `C:\Program Files\LibreOffice\program\soffice.exe`. Não usar `soffice.com` nem `.exe` do Microsoft Store.

`LIBREOFFICE_BIN` vazio ⇒ `LibreOfficeConverter::isConfigured() === false`, `convert()` lança `ConverterNotConfiguredException` e `pdftool:selftest` mostra "DOCX indisponível". Em desenvolvimento sem LibreOffice, `config('pdftool.converters.docx')` pode apontar para `App\Integrations\Pdf\FakePdfConverter` (copia `tests/Fixtures/fake-converted.pdf` e emite WARNING a cada uso — nunca em produção).

### Flags usadas

```
soffice --headless --norestore --nologo --nodefault --nolockcheck \
        --convert-to pdf --outdir <tmp>/out \
        -env:UserInstallation=file:///<tmp>/profile \
        <tmp>/in/input.docx
```

| Flag                              | Efeito                                                                   |
| --------------------------------- | ------------------------------------------------------------------------ |
| `--headless`                      | sem interface, sem diálogos                                              |
| `--norestore`                     | não tenta recuperar documentos de sessões anteriores travadas            |
| `--nologo` / `--nodefault`        | sem splash, sem documento em branco                                      |
| `--nolockcheck`                   | não verifica lock de outra instância (cada conversão tem perfil próprio) |
| `--convert-to pdf --outdir`       | filtro padrão `writer_pdf_Export`; saída `input.pdf`                     |
| `-env:UserInstallation=file:///…` | **perfil de usuário isolado** por conversão (veja abaixo)                |

### Perfil isolado e bloqueio de macros/rede

Cada conversão cria `profile/user/registrymodifications.xcu` (gerado por `LibreOfficeConverter::profileRegistry()`) antes de iniciar o processo, com: `MacroSecurityLevel=3` (muito alto), `DisableMacrosExecution=true`, `OfficeBasic=0`, atualização de links do Writer `Link=0` (nunca), proxy manual sem host (`ooInetProxyType=1`), `FirstRun=false` e verificação de atualização desligada. O perfil isolado também impede que uma conversão herde extensões, macros ou configurações do usuário do SO e é apagado com o diretório temporário.

**Isso não substitui controles do sistema operacional.** O bloqueio de macros e de rede que pode ser afirmado com segurança depende de:

1. **Usuário sem privilégios** dedicado ao worker (`User=assinavelox-worker` na unit systemd), sem acesso a `.env`, chaves e a outros diretórios além de `storage/app/tmp` e `tools/pdftool`.
2. **Sem rede**: regra de firewall por UID (`iptables -A OUTPUT -m owner --uid-owner assinavelox-worker -j REJECT`, ou nftables equivalente) **ou** namespace de rede vazio para o processo (`systemd-run --property=PrivateNetwork=yes …`, ou `PrivateNetwork=yes` em um serviço que só roda conversões). Como `soffice` é chamado pelo PHP, a forma mais simples é aplicar `PrivateNetwork=yes` ao serviço do worker que executa a fila de conversão, mantendo e-mail/S3 em outra fila/serviço.
3. **Limites de CPU/memória** na unit do worker: `MemoryMax=1G`, `CPUQuota=100%`, `TasksMax=64`, `LimitNOFILE=1024`; opcionalmente `ProtectSystem=strict`, `ProtectHome=yes`, `ReadWritePaths=/var/www/assinavelox/storage`, `NoNewPrivileges=yes`, `PrivateTmp=yes`.
4. **Variável `SAL_USE_VCLPLUGIN=svp`** em servidores sem X11 (`PDFTOOL_LIBREOFFICE_ENV` não existe em `.env` por segurança; defina em `config/pdftool.php` → `libreoffice.env`), e fontes instaladas (`fc-list`) para fidelidade visual.

Documentos com macros são convertidos **sem executá-las**; formulários ActiveX/OLE são renderizados como estão. Se a política exigir recusar arquivos com macros, isso deve ser feito antes (inspeção do ZIP do DOCX por `vbaProject.bin` — não implementado nesta fase).

### Limitações conhecidas

- **LibreOffice não está instalado no ambiente de desenvolvimento local**: o adaptador foi verificado com um binário falso (`tests/Fixtures/fake-soffice.{bat,sh}`) que registra os argumentos e o ambiente recebidos e copia um PDF. O teste confirma os flags, `--outdir <tmp>/out`, `-env:UserInstallation=file:///<tmp>/profile` (comparado com `LibreOfficeConverter::fileUri()`, tolerando as aspas que o `cmd.exe` acrescenta no Windows), a existência do `registrymodifications.xcu` no perfil, a entrada `<tmp>/in/input.docx`, o ambiente mínimo e o timeout. O comportamento com o `soffice` real (incluindo a aceitação do `registrymodifications.xcu` pré-semeado e o filtro de exportação) **não foi verificado** e deve ser confirmado no servidor com `php artisan pdftool:selftest` e uma conversão de teste.
- A primeira execução com perfil novo é mais lenta (2–6 s); não há reuso de instância nem de perfil por decisão de isolamento.
- Fontes ausentes no servidor são substituídas silenciosamente; paginação pode divergir do Word.
- Apenas `docx` (e `doc`/`odt`/`rtf` pelo mesmo caminho) — planilhas/apresentações não estão no escopo.

## 5. Assinatura: perfil PAdES B-B e o que NÃO é afirmado

`PyHankoSigner` aplica **uma** assinatura `PAdES B-B` (`/ETSI.CAdES.detached`, SHA-256) como **atualização incremental**: os bytes anteriores do PDF são preservados e assinaturas anteriores continuam válidas (testado: segunda assinatura → 2 assinaturas íntegras, campos `AssinaVelox` e `AssinaVelox_2`). Antes de assinar, o pdftool recusa PDFs cifrados (`encrypted_pdf`) e corrompidos (`invalid_pdf`).

O que **não** é afirmado, nem pela ferramenta nem pela UI:

| Não afirmado                                 | Motivo                                                                                                                                                                                              |
| -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Carimbo do tempo (B-T)                       | sem TSA; `SignResult::timestamp` é sempre `null`. A data mostrada é a do servidor no momento da assinatura, não uma prova de tempo de terceiro. `TimestampProvider` está reservado para a Fase 2/3. |
| LTV / LTA / DSS                              | nenhuma informação de validação é embutida; a verificação futura depende de o certificado ainda ser verificável.                                                                                    |
| Revogação                                    | CRL/OCSP não consultados (sem rede). `revocation=not_checked` sempre.                                                                                                                               |
| Assinatura pessoal do signatário             | a assinatura é da **empresa operadora** (certificado A1 dela). O aceite de cada participante é o `SignatureAcceptance` com evidências; a UI usa exatamente esse vocabulário (arquitetura §2).       |
| Validade ICP-Brasil de certificados de teste | ver abaixo.                                                                                                                                                                                         |

O **hash final** do envelope é calculado **depois** da assinatura e gravado em `verification_records` — nunca dentro do próprio PDF (o hash de um arquivo não pode estar contido nele). Pela mesma razão estrutural, o **resultado técnico da validação** também não consta do PDF: ele é apurado sobre o arquivo já assinado, isto é, depois de a página de evidências existir dentro dele. Gerar a página depois da assinatura e anexá-la em seguida deixaria conteúdo fora da revisão assinada e destruiria a própria cobertura que a validação afirma. Os dois dados vivem na página de verificação, e o PDF diz onde encontrá-los (`docs/juridico/declaracao-de-aceite.md` §5.1 e §5.2).

**`all_intact` não é "o arquivo não mudou".** A `pdftool validate` responde, por assinatura, apenas sobre os bytes **cobertos** por ela. Um PDF com uma atualização incremental acrescentada depois da revisão assinada continua `intact`, `valid` e até `trusted`; o que denuncia o acréscimo é `coverage` cair de `ENTIRE_FILE` para `ENTIRE_REVISION` (e `modification_level`/`docmdp_ok` quando a alteração toca o catálogo). Por isso a ferramenta agrega `all_covering` e `all_docmdp_ok` ao lado de `all_intact`/`all_valid`, e a plataforma exige os quatro para publicar um arquivo como assinado e íntegro.

**`pdftool cert-info --pfx <arquivo> --pass-env <VAR>`** devolve os metadados públicos do certificado dentro de um PKCS#12 (titular, emissor, série, impressão digital SHA-256, validade, autoassinado ou não) sem assinar nem gravar nada, e sem expor chave nem senha. É o que permite **identificar o certificado que vai assinar antes de a assinatura existir** — a página de evidências imprime esse certificado em vez de adivinhá-lo pela última linha de `certificate_references`.

### Certificado `test` × `production`

`COMPANY_CERT_ENVIRONMENT` rotula o certificado configurado; `SignResult::environment` e `isTestCertificate()` propagam o rótulo para `certificate_references.environment` e para a UI.

- **`test`** (padrão; também assumido se o valor for inválido): certificado gerado por `pdftool gen-test-cert` (autoassinado, RSA-2048, CN com sufixo `TESTE`) ou qualquer A1 de homologação. A UI deve exibir "assinatura de teste" e **nunca** "ICP-Brasil". Útil para desenvolvimento, CI e homologação.
- **`production`**: certificado A1 (.pfx) emitido por AC da ICP-Brasil para a empresa operadora. Mesmo assim, o que se afirma é "assinatura digital da empresa operadora, PAdES B-B, sem carimbo do tempo".

Gerar um certificado de teste local:

```bash
export COMPANY_CERT_PASSWORD='senha-forte-local'        # Windows PowerShell: $env:COMPANY_CERT_PASSWORD='...'
cd tools/pdftool && .venv/bin/python -m pdftool gen-test-cert \
    --out-pfx ../../storage/app/private/certs/teste.pfx --pass-env COMPANY_CERT_PASSWORD \
    --out-pem ../../storage/app/private/certs/teste.pem --days 365
# .env: COMPANY_CERT_ENABLED=true
#       COMPANY_CERT_PFX_PATH=/abs/storage/app/private/certs/teste.pfx
#       COMPANY_CERT_PASSWORD_ENV=COMPANY_CERT_PASSWORD   # nome da variável; o valor fica só no ambiente
#       COMPANY_CERT_ENVIRONMENT=test
#       PDFTOOL_TRUST_ROOTS=/abs/storage/app/private/certs/teste.pem
```

Sem certificado (`COMPANY_CERT_ENABLED` diferente de `true`, `COMPANY_CERT_PFX_PATH` vazio/inexistente ou variável de senha indefinida), o container resolve `PdfSigner` para `NullPdfSigner`: `isConfigured()=false`, `sign()` lança `SignerNotConfiguredException` e **nenhum** arquivo "assinado" é produzido; o envelope conclui como _aceite eletrônico com evidências_ (`signature_status=none`). `validate()` continua disponível.

**Teste × produção, em resumo:** em desenvolvimento/CI use `COMPANY_CERT_ENABLED=true` + certificado de `gen-test-cert` + `COMPANY_CERT_ENVIRONMENT=test` (a UI rotula como teste); em produção, `COMPANY_CERT_ENVIRONMENT=production` **somente** com A1 emitido por AC ICP-Brasil, senha no ambiente real do serviço (não no `.env` quando há `config:cache`), `PDFTOOL_TRUST_ROOTS` com a cadeia da AC, e `pdftool:selftest` após cada deploy.

## 6. Fluxo de finalização (referência para o orquestrador)

```php
$client = app(PdfToolClient::class);
$plan = ComposePlan::source($consolidadoOrigem)
    ->addImage('fld_1', 1, FieldType::Signature, 0.10, 0.70, 0.30, 0.08, $pngAssinatura)
    ->addField('fld_2', 1, FieldType::Name, 0.10, 0.79, 0.30, 0.04, 'João da Silva', ['align' => 'left'])
    ->addCheckbox('fld_4', 2, 0.05, 0.05, 0.03, 0.03, true);
$compose = $client->compose($plan, $consolidado);            // ComposeResult
$paginas = $client->append($consolidado, $evidencias, $preAssinatura);
$signer = app(PdfSigner::class);
if ($signer->isConfigured()) {
    $sig = $signer->sign(new SignRequest($preAssinatura, $final, correlationId: $cid));   // SignResult
    $val = $signer->validate($final);                                                       // ValidationResult (raízes de config)
} else {
    copy($preAssinatura, $final);                             // signature_status = none
}
$hashFinal = hash_file('sha256', $final);                    // depois da assinatura, fora do PDF
```

Nunca manter transação de banco aberta durante essas chamadas (arquitetura §3.3).

## 7. Testes

`php artisan test --filter=Pdf` roda `tests/Feature/Pdf/*` (44 testes) contra o **pdftool real** (venv em `tools/pdftool/.venv`; se ausente, os testes são pulados com a instrução de instalação). Fixtures geradas em tempo de teste: PDFs com DOMPDF (1 e 2 páginas, cifrados com senha de proprietário e de usuário, corrompido), PNG/JPEG com GD, certificado com `gen-test-cert`. Estáticos em `tests/Fixtures/` (ver README de lá): PDF fake e binário falso do LibreOffice. Cobertura: inspect, compose, append, sign+validate (com/sem raiz, dupla assinatura, carimbo visível), vazamento da senha (variável ausente, senha errada, e registros de log capturados), `COMPANY_CERT_ENABLED=false` → `NullPdfSigner`, PDF corrompido, limpeza do diretório temporário após exceção, conversores (passthrough blocked para assinado/cifrado/corrompido; imagem PNG 4100×600 com pico de memória < 64 MB, JPEG sem canvas intermediário e sem EXIF, SVG, limite de 40 MP, recusa por `memory_limit` sem decodificar, corrompida; manager; fake; LibreOffice sem binário, com binário falso — argumentos/perfil/ambiente —, falha, sem saída e timeout), ambiente mínimo, selftest. A suíte passa com `memory_limit=512M` (o `phpunit.xml` do projeto usa 1G por causa de outras suítes).

## 8. `php artisan pdftool:selftest`

Uso operacional (deploy, monitoramento, suporte):

```bash
php artisan pdftool:selftest          # relatório legível
php artisan pdftool:selftest --json   # para automação; exit code 0 = ok
```

Mostra: interpretador e versões (Python, pdftool; pins de pypdf/pyHanko/reportlab/Pillow/cryptography lidos de `requirements.lock.txt`, pois o pdftool não expõe versões de bibliotecas em tempo de execução), diretórios e timeouts; resultado passo a passo do `selftest` do pdftool (compose, append, inspect, image2pdf, gen-test-cert, sign, validate com e sem raiz); estado dos adaptadores (conversor por tipo, LibreOffice configurado/resolvido, certificado A1 ligado (`COMPANY_CERT_ENABLED`)/configurado + ambiente + lista de problemas, raízes de confiança existentes). **Exit code 1** quando o pdftool está indisponível ou o selftest falha; **0** caso contrário (verificado localmente: 11 passos ok, LibreOffice e certificado como avisos). LibreOffice e certificado ausentes são **avisos** (são opcionais). O valor da senha nunca é exibido — apenas o nome da variável.

Rode após cada deploy e ao trocar de certificado. Se falhar: confira `PDFTOOL_PYTHON`/venv (seção 3), permissões de `storage/app/tmp`, e o log (`pdftool: comando executado` com `correlation_id`).

## 9. Variáveis de ambiente (`config/pdftool.php`)

Presentes no `.env.example`:

| Variável                    | Padrão                                                                          | Uso                                                                       |
| --------------------------- | ------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| `PDFTOOL_PYTHON`            | `tools/pdftool/.venv/bin/python` (Linux) / `.venv/Scripts/python.exe` (Windows) | interpretador do venv (vazio = padrão)                                    |
| `LIBREOFFICE_BIN`           | _(vazio = DOCX indisponível)_                                                   | caminho do `soffice`                                                      |
| `COMPANY_CERT_ENABLED`      | `false`                                                                         | liga a assinatura A1; `false` ⇒ `NullPdfSigner` mesmo com PFX configurado |
| `COMPANY_CERT_ENVIRONMENT`  | `test`                                                                          | `test` \| `production` (inválido ⇒ `test`)                                |
| `COMPANY_CERT_PFX_PATH`     | _(vazio = sem assinatura)_                                                      | arquivo PKCS#12 do certificado A1                                         |
| `COMPANY_CERT_PASSWORD_ENV` | `COMPANY_CERT_PASSWORD`                                                         | **nome** da variável que contém a senha do PKCS#12                        |
| `COMPANY_CERT_NAME`         | `Certificado da operadora`                                                      | rótulo exibido                                                            |

Lidas pela config mas **ainda não listadas** no `.env.example` (opcionais; integrador deve acrescentá-las):

| Variável                                                                | Padrão                                         | Uso                                                                                          |
| ----------------------------------------------------------------------- | ---------------------------------------------- | -------------------------------------------------------------------------------------------- |
| `PDFTOOL_TIMEOUT_SECONDS`                                               | `60`                                           | timeout por comando do pdftool                                                               |
| `PDFTOOL_SIGN_TIMEOUT_SECONDS`                                          | `120`                                          | timeout de `sign`/`gen-test-cert`/`selftest`                                                 |
| `PDFTOOL_TMP_PATH`                                                      | `storage/app/tmp/pdftool`                      | raiz dos diretórios temporários exclusivos                                                   |
| `PDFTOOL_TRUST_ROOTS`                                                   | _(vazio)_                                      | arquivos PEM/DER separados por `;` para `validate`                                           |
| `LIBREOFFICE_TIMEOUT_SECONDS`                                           | `120`                                          | timeout da conversão DOCX                                                                    |
| `COMPANY_CERT_REASON` / `COMPANY_CERT_LOCATION`                         | `Assinatura eletrônica AssinaVelox` / `Brasil` | metadados da assinatura (`/Reason`, `/Location`)                                             |
| `COMPANY_CERT_PASSWORD` (ou o nome dado em `COMPANY_CERT_PASSWORD_ENV`) | —                                              | **a senha em si**: só no ambiente do processo PHP (não commitar no `.env.example` com valor) |

`pdftool.converters` (mapa tipo → classe) e `pdftool.libreoffice.env` são fixos em config, não em `.env`.
