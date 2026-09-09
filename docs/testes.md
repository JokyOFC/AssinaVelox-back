# Testes

Como o AssinaVelox é verificado: quais suítes existem, o que cada uma prova, como
rodar, como o banco de cada uma funciona, o que continua pendente por depender de
credencial externa e como ler uma falha.

Precedência dos documentos-fonte: `docs/design/RECONCILIACAO.md` →
`docs/arquitetura.md` → `docs/design/ROUTES_AND_PAGES.md` →
`docs/design/DESIGN_SYSTEM.md`.

---

## 1. A pirâmide

Cinco camadas, da mais barata para a mais cara. Cada uma responde a uma pergunta
diferente; nenhuma substitui a de baixo.

| Camada                       | Onde                                                                  | O que prova                                                                                                                                                                                         | Custo               |
| ---------------------------- | --------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------- |
| **Unidade**                  | `tests/Unit`                                                          | Regras puras: catálogo de enums, transições de status, rótulos. Sem banco, sem HTTP.                                                                                                                | milissegundos       |
| **Integração HTTP**          | `tests/Feature`                                                       | O contrato de cada rota com o banco real: autorização, isolamento por organização, validação, efeitos gravados, notificações disparadas, evidências escritas. É a camada mais densa (140 arquivos). | segundos            |
| **Contratos de adaptadores** | `tests/Feature/Pdf`, `tests/Feature/Sending`, `tests/Feature/Billing` | Que cada implementação de `App\Integrations\Contracts\*` cumpre o contrato — e que a aplicação se comporta corretamente quando o adaptador **não** está configurado.                                | segundos            |
| **Navegador**                | `tests/Browser`                                                       | O que só existe depois que o React monta: formulários, arrasto, canvas de assinatura, cálculo de SHA-256 no cliente, ausência de erro de JavaScript e de texto em inglês.                           | dezenas de segundos |
| **pdftool (Python)**         | `tools/pdftool/tests`                                                 | A ferramenta de PDF isolada da aplicação: inspeção, composição, geometria, imagem→PDF, assinatura e validação.                                                                                      | segundos            |

Regra prática ao escrever um teste novo: **suba de camada só quando a de baixo
não consegue responder**. Um erro de autorização é `Feature`; um erro de
`aria-label` é `Browser`; um erro de posicionamento de carimbo é `pdftool`.

**Números do fechamento da Fase 1** (2026-09-09, `php artisan test`):

| Suíte                       | Testes                          | Asserções | Tempo   |
| --------------------------- | ------------------------------- | --------- | ------- |
| `Unit`                      | 13                              | 261       | 0,7 s   |
| `Feature`                   | 828                             | 5 804     | 544,8 s |
| `Browser`                   | 39 (36 ativos, **3 pulados**)   | 740       | 46,9 s  |
| **Total Pest**              | **880 (877 ativos, 3 pulados)** | **6 805** | 598,0 s |
| `pdftool` (pytest, à parte) | 93                              | —         | 9,1 s   |

Os 3 pulados não são falhas escondidas: são duas limitações do
`pest-plugin-browser` (§6) e um caso do `SmokeTest`, todos com a explicação
completa na própria chamada `->skip(...)`. Nenhum teste é pulado em silêncio.

---

## 2. Como rodar

Tudo a partir da raiz do repositório. As extensões `intl` e `sockets` do PHP já
estão habilitadas — não é preciso contorno com `-d extension=...`.

```bash
# Tudo (Pint + PHPStan + as três suítes PHP + pdftool)
composer test:all

# O que a CI roda (lint e tipos do front + Pint + PHPStan + suítes PHP)
composer ci:check

# Pint + PHPStan + as três suítes PHP
composer test

# Uma suíte de cada vez
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
php artisan test --testsuite=Browser      # ou: composer test:browser

# Um arquivo, ou um teste pelo nome
php artisan test tests/Browser/SignerFlowTest.php
php artisan test --filter="assina o documento do começo ao fim"

# pdftool (cria o venv se faltar)
composer test:pdftool
# equivalente manual:
cd tools/pdftool && ./.venv/Scripts/python.exe -m pytest -q   # Windows
cd tools/pdftool && ./.venv/bin/python -m pytest -q           # Linux/macOS
```

Qualidade estática, à parte:

```bash
vendor/bin/pint --dirty                                   # formatação PHP
vendor/bin/phpstan analyse --no-progress --memory-limit=1G # tipos PHP (nível 7)
npm run check                                             # lint do front
npm run types:check                                       # TypeScript
npm run build                                             # necessário para a suíte Browser
```

> A suíte `Browser` serve os assets de `public/build`. **Rode `npm run build`
> antes**, ou as telas sobem sem CSS e sem JavaScript e praticamente todo teste
> falha. Alguns testes de `tests/Feature/Smoke` já se pulam sozinhos com essa
> mensagem quando o `manifest.json` não existe.
>
> E confira que **não** existe `public/hot`: esse arquivo faz a aplicação
> apontar para o servidor de desenvolvimento do Vite em vez do build. Ele é
> removido quando o `npm run dev` encerra normalmente, mas fica para trás quando
> o processo é morto à força — e aí tanto a suíte de navegador quanto
> `tests/Feature/Smoke/CspAndBuildAssetsTest` falham.

---

## 3. O banco de cada suíte

### Unit

Não toca no banco. `tests/Pest.php` só vincula `Feature` e `Browser` ao
`TestCase` com `RefreshDatabase`.

### Feature e Browser

Ambas usam o que o `phpunit.xml` define: `DB_CONNECTION=sqlite` com
`DB_DATABASE=:memory:`. O `RefreshDatabase` migra uma vez e envolve cada teste
numa transação, revertida no fim — nenhum teste vê os dados do outro e nada
sobra em disco.

**Os seeders não rodam.** `owner@horizonte.demo` e `admin@assinavelox.local`
existem para desenvolvimento (`php artisan migrate:fresh --seed`), não para os
testes: cada teste monta os próprios dados com fábricas e helpers. Depender de um
seeder de demonstração faria a suíte quebrar toda vez que a demonstração mudasse.

### Por que a suíte Browser **também** usa `:memory:`

Este é o ponto que costuma surpreender. O `pestphp/pest-plugin-browser` 4.x
**não sobe um processo de servidor separado**: o
`Pest\Browser\Drivers\LaravelHttpServer` é um servidor HTTP em Amp que roda
dentro do mesmo processo PHP do teste e entrega cada requisição ao `HttpKernel`
da aplicação que o teste já tem em mãos (`vendor/pestphp/pest-plugin-browser/src/Drivers/LaravelHttpServer.php`).

Consequências práticas:

- o banco em memória **é** compartilhado com o servidor, e a transação do
  `RefreshDatabase` continua valendo. Não é preciso banco de arquivo, nem
  migração separada, nem `database/browser-tests.sqlite`;
- um `Event::listen()` registrado no teste dispara dentro da requisição feita
  pelo navegador — é assim que a suíte lê o código por e-mail e a URL do convite,
  que só existem em claro dentro da notificação (`browserCaptureNotifications()`);
- o teste **não pode dormir**: enquanto o processo dorme, o servidor não atende
  ninguém. Toda espera passa por `browserWaitFor()`, que consulta a condição e
  cede o laço de eventos entre uma checagem e a seguinte.

### O que a suíte Browser precisou mudar: a sessão

O `phpunit.xml` usa `SESSION_DRIVER=array`, que guarda a sessão em memória e a
descarta no fim de cada requisição. Para os testes `Feature` isso não importa
(cada teste monta a sessão que quer com `withSession()`), mas para o navegador
é fatal: o cookie volta e encontra uma sessão vazia. Sem sessão persistente não
funcionam a organização corrente (`EnsureCurrentOrganization::SESSION_KEY`), a
sessão curta do signatário (`SignerSessions`, criada ao confirmar o código e
lida na tela de assinatura) nem as mensagens flash.

`tests/BrowserTestCase.php` resolve trocando o driver para `database` no
`setUp()`: a tabela `sessions` vive no mesmo banco em memória, é vista pelo
servidor e pelo teste, e o `RefreshDatabase` a limpa entre testes — sem deixar
arquivo nenhum para trás. O mesmo `setUp()` sobe o timeout do Playwright de 5 s
para 20 s, porque as telas pesadas desta aplicação demoram mais que o padrão.

### pdftool

Não usa banco. Cada teste escreve em `tmp_path` do pytest.

---

## 4. O que cada suíte cobre

### `tests/Unit`

Catálogo de enums (`EnumCatalogTest`) e as transições de `EnvelopeStatus` e
`RecipientStatus`. É onde mora a garantia de que os enums do PHP e os tipos do
TypeScript continuam falando a mesma língua.

### `tests/Feature`

Organizada por assunto. Os grupos que mais importam:

- `Auth`, `Members`, `Organizations` — cadastro, login, 2FA, convites de equipe,
  papéis e o isolamento por organização;
- `Documents`, `Pdf` — o pipeline documental: validação por conteúdo (não por
  extensão), conversão assíncrona, PDFs cifrados ou já assinados, e os
  adaptadores de conversão e assinatura;
- `Envelopes`, `Sending` — campos e geometria, congelamento da versão no envio,
  reserva de cota, convites por token guardado só como resumo, reenvio,
  expiração e cancelamento;
- `Sign` — a página pública do signatário pelo contrato HTTP: acesso por token,
  código por e-mail, sessão curta, aceite com evidências, recusa e download;
- `Finalization`, `Verification` — consolidação, página de evidências,
  assinatura PAdES B-B da operadora quando configurada, hash final calculado
  **depois** da assinatura, e a verificação pública por código;
- `Billing` — Checkout Pro, webhook autenticado e ativação idempotente;
- `Hardening`, `Security`, `Smoke` — cabeçalhos, limites de taxa, redação de
  log, trilha append-only, e uma varredura de todas as rotas GET;
- `EndToEnd` — os dois testes que percorrem o produto inteiro pela rota HTTP
  real, sem atalho de fábrica: `PreparationAndSigningTest` vai da criação até o
  gancho de finalização, e `FullLifecycleTest` continua até `completed` com PDF
  e certificado de teste reais;
- `Review` — 60 arquivos de regressão, cada um amarrando um defeito ou uma
  decisão específica já revisada.

### `tests/Browser`

| Arquivo                      | O que prova                                                                                                                                                                                                                                                                                                                                                                                                            |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SmokeTest.php`              | Todas as telas públicas e autenticadas montam sem erro de JavaScript e sem texto em inglês; mais o detalhe de um documento enviado, a página de evidências e a resposta a um documento inexistente. Quebra quando um import quebra, quando uma tradução some ou quando a tela de erro crua do framework aparece — falhas que um `assertOk()` não pega, porque o HTML chega íntegro e é o navegador que engasga depois. |
| `AuthenticationTest.php`     | Login válido chega ao painel; credencial errada mostra o erro em PT-BR; sair encerra a sessão de verdade (o navegador, com os cookies que ainda tem, perde o acesso ao painel).                                                                                                                                                                                                                                        |
| `PreparationTest.php`        | O wizard de quatro passos: cria a solicitação, mostra o arquivo processado, cadastra dois signatários com autosave, insere campos de assinatura, confirma que a geometria sobrevive a um recarregamento e envia — com o convite do primeiro signatário saindo.                                                                                                                                                         |
| `SignerFlowTest.php`         | O caminho inteiro do signatário: abrir o convite, pedir o código, digitá-lo, **desenhar a assinatura no canvas** com eventos de ponteiro, marcar o aceite, concluir e ver o comprovante. Mais a recusa com motivo, o código errado e a tela em **375 px**.                                                                                                                                                             |
| `PublicVerificationTest.php` | O campo de código em três blocos e a **conferência do arquivo pelo hash no navegador**: o caso que confere e o caso adulterado. O SHA-256 é calculado pelo WebCrypto na máquina de quem confere — `http://127.0.0.1` é contexto seguro no Chromium, a mesma condição de um domínio em HTTPS na produção.                                                                                                               |
| `Support/BrowserHelpers.php` | Os helpers da suíte. Não contém testes.                                                                                                                                                                                                                                                                                                                                                                                |

Os helpers que valem conhecer antes de escrever um teste novo:

- `browserLogin($page, $email)` — entra pela tela real. Não há atalho:
  `actingAs()` autentica o _cliente de teste_, não o navegador, que tem cookies
  próprios;
- `browserWaitFor($condição, $descrição)` — espera por condição, nunca por tempo
  fixo;
- `browserCaptureNotifications()` — devolve `codes` e `invites` preenchidos a
  partir do evento de envio, sem `Notification::fake()` (o canal rastreado
  precisa rodar de verdade para as linhas de `delivery_attempts` continuarem
  existindo);
- `browserDrawOnCanvas($page, $elementoJs)` — desenha no `signature_pad`
  simulando `pointerdown` no canvas e `pointermove`/`pointerup` na `window`, com
  `buttons: 1` durante o traço e um quadro de espera entre pontos (a biblioteca
  tem _throttle_ de 16 ms);
- `browserAttachFile($page, $elementoJs, $caminho)` — monta um `File` dentro da
  página e o coloca no `<input type=file>` por um `DataTransfer` (veja §6);
- `browserAssertNoEnglish($page, $tela)` — recusa uma lista curta de termos em
  inglês que costumam escapar (`Server Error`, `This field is required`,
  `Submit`…). `Cancel` ficou de fora de propósito: é prefixo de `Cancelar`.

### `tools/pdftool/tests`

`test_inspect`, `test_compose`, `test_append`, `test_geometry`,
`test_image2pdf`, `test_sign_validate`. Cobrem a ferramenta isolada, sem PHP no
caminho. 93 testes.

---

## 5. Verificações que dependem de credencial externa (pendentes)

Nada aqui é testado contra o serviço real; tudo é exercitado contra um duplo, e
o teste que provaria a integração de ponta a ponta continua **pendente** até
existir credencial de sandbox no ambiente de execução.

| Integração                                                                | O que já é testado                                                                                                                                                                                  | O que continua pendente                                                                                                                                                                                                                                    |
| ------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Mercado Pago** (`PaymentGateway`)                                       | Criação da preferência, assinatura HMAC do webhook, ativação idempotente e recusa de payload adulterado — tudo contra um duplo. Ver `docs/integracoes/mercado-pago.md`.                             | Uma ida real ao Checkout Pro de sandbox: preferência criada na API, pagamento aprovado e webhook chegando do servidor deles.                                                                                                                               |
| **Certificado da operadora** (`PdfSigner`)                                | Assinatura PAdES **B-B** com certificado de teste gerado por `pdftool gen-test-cert`, e o caminho "sem certificado configurado", em que o envelope conclui como _aceite eletrônico com evidências_. | Assinatura com um certificado ICP-Brasil de verdade, e a validação por um verificador de terceiro (ITI, Adobe). Enquanto isso não acontece, **o perfil anunciado continua sendo apenas B-B** e o certificado de teste nunca é apresentado como ICP-Brasil. |
| **Carimbo de tempo** (`TimestampProvider`)                                | Nada além do contrato: não há adaptador configurado.                                                                                                                                                | Tudo. E, quando houver: carimbo de tempo público **não** é ICP-Brasil e a interface não pode dizer que é.                                                                                                                                                  |
| **E-mail** (`EmailProvider`)                                              | O canal rastreado, as linhas de `delivery_attempts` e o conteúdo das notificações, com um provedor falso (`tests/Feature/Sending/Support/FakeEmailProvider.php`).                                   | Entrega real (SPF/DKIM/DMARC, bounce, reputação).                                                                                                                                                                                                          |
| **LibreOffice** (conversão de DOCX)                                       | `LibreOfficeConverter` contra um binário falso (`tests/Fixtures/fake-soffice.bat` / `.sh`), que registra os argumentos e o ambiente recebidos.                                                      | A conversão real de DOCX. Nesta máquina o LibreOffice não está instalado; os testes que precisariam dele se pulam sozinhos.                                                                                                                                |
| **SMS / WhatsApp / verificação de identidade / CPF / CNPJ / nota fiscal** | Só os contratos em `app/Integrations/Contracts`. São Fase 2.                                                                                                                                        | Tudo.                                                                                                                                                                                                                                                      |

Os testes que dependem do **venv do pdftool** se pulam sozinhos com a mensagem
`PdfFixtures::skipMessage()`, que já traz o comando de criação do venv. Os que
dependem do build do front se pulam com “rode `npm run build`”.

---

## 6. Limitações conhecidas da suíte de navegador

Duas coisas **não** dão para automatizar com o `pestphp/pest-plugin-browser`
4.3.1. Ambas têm um teste `skip` no repositório com a explicação completa em
`tests/Browser/PreparationTest.php`; o resumo:

**1. Upload de arquivo pelo navegador (multipart).**
`LaravelHttpServer::handleRequest()` monta a requisição com
`Request::create(..., [], ...)` — a lista de arquivos é literalmente vazia, com
o comentário `@TODO files...`. Na prática o XHR/`fetch` com
`multipart/form-data` nunca é concluído e o teste **trava** em vez de falhar (um
`fetch` GET na mesma página responde 200, então não é deadlock geral). O
`attach()` do plugin também não serve: manda `localPaths` ao Playwright, que
recusa com `localPaths are not allowed when the client is not local`, porque o
plugin sobe o Playwright em modo `run-server`.

_Como a suíte contorna:_ `browserAttachFile()` monta o `File` dentro da própria
página e o entrega ao input por um `DataTransfer` — funciona para inputs que o
JavaScript consome no cliente, como a conferência de arquivo da verificação
pública. Para o upload do wizard, que precisa chegar ao servidor, a suíte usa a
rota real pelo cliente de teste (`browserUploadDocument()`), e o upload em si
continua coberto por `tests/Feature/Documents/DocumentUploadTest.php`.

**2. Qualquer interação sobre a página renderizada pelo PDF.js.**
`DocumentStorage::stream()` devolve um `StreamedResponse`. Para esse tipo,
`getContent()` devolve `false` e o servidor do plugin captura a saída com
`ob_start()` e aplica **`mb_trim(ob_get_clean())`**. `mb_trim` trata bytes
binários como UTF-8 e descarta sequências inválidas **no meio do arquivo**: num
PDF de 1.127 bytes gerado pelo dompdf sobram 1.101, com a primeira divergência
no offset 325, sobre o BOM UTF-16 do campo `/Producer`. O corpo chega corrompido
e menor que o `Content-Length` declarado, e o navegador aborta com
`Failed to fetch`.

Efeito: o visualizador fica em “Carregando o documento…”, a camada de campos
(que só monta depois da primeira página rasterizada) nunca existe, e não há alvo
para arrastar nem para clicar. Por isso **o posicionamento de campo por arrasto
sobre o PDF continua sendo verificação manual**. O que cobre essa área hoje: a
geometria pura em `tests/Unit` e `resources/js/lib/geometry.ts`, o contrato de
`envelopes.fields.sync` em `tests/Feature/Envelopes`, e — no navegador — a
inserção de campo pela paleta com a conferência do retângulo gravado, que passa
pelo mesmo cálculo de coordenadas.

Nenhuma das duas é defeito da aplicação: as duas rotas funcionam no navegador
real e estão cobertas por testes HTTP. Reavaliar quando o plugin passar a
preencher `$request->files` e a parar de passar binário por `mb_trim`.

**Isso foi confirmado, não suposto.** No fechamento da Fase 1 o ciclo inteiro foi
percorrido à mão contra o MySQL de desenvolvimento, com `php artisan serve` e um
navegador de verdade (ver §6-B). Nesse ambiente:

- o upload `multipart` do wizard **funciona** — o PDF de 2.908 bytes subiu, foi
  validado pelo conteúdo, teve as 2 páginas detectadas pelo pdftool e chegou a
  `processing_status = ready`, com o `sha256` gravado igual ao do arquivo local;
- o PDF.js **rasteriza normalmente** — o documento apareceu com as miniaturas das
  duas páginas e o texto do contrato legível, e a camada de campos montou.

Ou seja: as duas limitações são do servidor in-process do plugin, e só dele. O que
continua sem automação é a **verificação** dessas duas rotas dentro da suíte de
navegador, não o comportamento delas.

### O alcance de `assertNoJavascriptErrors`

Vale saber o que essa asserção **não** cobre, para não confiar demais nela. O
plugin injeta um script de inicialização
(`vendor/pestphp/pest-plugin-browser/src/Playwright/InitScript.php`) que registra
um `window.addEventListener('error', ...)` e envolve `console.log`. Ou seja:

- pega erro de JavaScript **não capturado** — o caso comum de import quebrado,
  prop faltando ou acesso a `undefined` durante o render;
- **não** pega `unhandledrejection` (promessa rejeitada sem `catch`);
- **não** pega `console.error` nem `console.warn` — só `console.log` é
  interceptado, e por `assertNoConsoleLogs()`, que esta suíte não usa porque a
  aplicação legitimamente registra logs em desenvolvimento;
- **não** pega falha de carregamento de recurso (imagem, fonte, chunk), que não
  borbulha até a `window`. Para isso existe `assertNoBrokenImages()`.

Na prática isso é bem visível no caso do PDF.js descrito acima: o `fetch` do
documento falha, o componente trata o erro com `.catch()` e a asserção passa
tranquila — o teste que percebe o problema é o que espera a camada de campos
aparecer, não o de erro de JavaScript.

### Ponto cego: as páginas de erro do produto

`resources/js/pages/errors/{403,404,500}.tsx` **não aparecem em teste nenhum**,
nem no navegador. `bootstrap/app.php:136` devolve a resposta crua quando
`app()->runningUnitTests()` é verdadeiro — decisão deliberada e comentada no
próprio arquivo, para que os testes HTTP possam afirmar o status e a mensagem
originais em vez de uma tela renderizada.

O efeito colateral é que a suíte de navegador recebe a página de erro do
Laravel (“404 / Página não encontrada”) e nunca o componente Inertia com os
botões “Página inicial” e “Ir para o painel”. `SmokeTest` verifica o que dá
para verificar — que a resposta sai em PT-BR e sem vazar
`No query results for model [App\Models\Envelope] 01ARZ…` — e deixa o resto num
teste `skip`ado. Fechar o ponto cego exigiria uma chave de configuração que
ligasse a renderização Inertia dos erros só na suíte `Browser`; é decisão de
quem cuida de `bootstrap/app.php`.

---

## 6-A. Defeito encontrado pela suíte — **corrigido na integração da Fase 1**

**Os dois alfabetos do código de verificação divergiam, e a diferença era o `L`.**

O servidor sorteia o código com 32 caracteres (`ABCDEFGHJKLMNPQRSTUVWXYZ23456789`,
sem `0`, `1`, `O` e `I`); o `sanitizeVerificationCode` do formulário público
filtrava por uma lista de 31, **sem o `L`**, e descartava esse caractere em
silêncio. Em cerca de **32%** dos códigos (1 − (31/32)¹²) a pessoa digitava ou
colava o código correto, o campo nunca chegava aos doze caracteres, o botão
“Verificar” continuava desabilitado e a tela respondia “Informe os 12 caracteres
do código”. O alcance era só a **digitação** — link direto e QR sempre
funcionaram, porque a rota aceita `[A-Za-z0-9-]{12,24}` — mas digitar é
justamente o caminho impresso no rodapé de cada página do PDF concluído.

Correção aplicada: `resources/js/components/verification/verification-code.tsx`
passou a usar o alfabeto de 32 caracteres, idêntico ao do servidor. Verificado no
navegador contra o MySQL real: `ABLC2345WXYZ` agora entra inteiro e a tela navega.

Duas guardas impedem a divergência de voltar, e ambas foram vistas falhando com o
alfabeto antigo antes de serem aceitas:

- `tests/Feature/Verification/VerificationContractTest.php` lê o `.tsx` e compara
  o alfabeto do filtro com `Envelope::VERIFICATION_CODE_ALPHABET`, caractere a
  caractere; um segundo teste sorteia 200 códigos reais e exige que nenhum perca
  caracteres ao passar pelo filtro.
- `tests/Browser/PublicVerificationTest.php` mantém o teste
  `aceita um código de verificação com a letra L`, agora **ativo** (o `->skip(...)`
  foi removido).

`PublicVerificationTest` continua fixando o código do envelope do `beforeEach` em
`ABCD2345WXYZ`, mas por outro motivo: um código sorteado tornaria ambígua a causa
de uma falha futura. A cobertura do `L` é responsabilidade do teste dedicado.

O caminho estrutural — exportar o alfabeto do servidor para o front, como já é
feito com os enums — continua valendo como melhoria; as guardas acima cobrem o
risco enquanto as duas listas existirem.

---

## 6-B. Ciclo manual contra o MySQL real (fechamento da Fase 1)

Toda a suíte roda em **SQLite em memória**. Isso é rápido e determinístico, e
esconde duas classes de problema: o que depende do dialeto do MySQL (tipos,
colação, tamanho de nome de índice, ação referencial) e o que depende de um
servidor HTTP de verdade (upload `multipart`, `StreamedResponse`, PDF.js).

Por isso o fechamento da Fase 1 incluiu **uma passagem manual completa** contra o
banco `assinavelox` no MySQL local, com `php artisan migrate:fresh --seed`,
`php artisan serve` e um navegador real. Não substitui a suíte; cobre o que ela
estruturalmente não alcança. O que ficou provado:

| Etapa               | Evidência observada                                                                                                                                                                        |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Esquema no MySQL    | 34 tabelas, todas InnoDB e `utf8mb4`; 442 colunas, nenhum `ENUM`/`SET` de SQL; 159 índices, o maior nome com 60 de 64 caracteres; 69 chaves estrangeiras; `audit_events` sem `updated_at`. |
| Upload              | PDF de 2 páginas aceito, convertido e `ready`; `sha256` gravado idêntico ao do arquivo local.                                                                                              |
| Campos              | Assinatura inserida pela paleta, gravada em fração normalizada (`x=0,36 y=0,47 w=0,28 h=0,06`).                                                                                            |
| Envio               | Versão congelada, cota reservada e confirmada, convite disparado, 11 eventos na trilha.                                                                                                    |
| Signatário          | Código por e-mail, sessão curta, assinatura desenhada no canvas por eventos de ponteiro, aceite registrado com IP mascarado na tela.                                                       |
| Finalização         | Documento final com 5 páginas (2 do contrato + 3 de evidências), `signature_status = none`.                                                                                                |
| Integridade         | `sha256` do arquivo em disco = o de `document_versions` = o de `verification_records`.                                                                                                     |
| Verificação pública | Conferência de arquivo no navegador: “Confere” para o arquivo final, “Não confere” para um alterado.                                                                                       |

Dois achados vieram daí: o defeito do `L` (§6-A) e a redação excessiva de
`exit_code` no log estruturado.

O que **continua pendente** mesmo depois dessa passagem: os cenários de
**corrida** (aceite simultâneo, numeração de envelope, reserva de cota) contra
MySQL real. `lockForUpdate` não faz nada no SQLite da suíte, e um percurso manual
de um usuário só não exercita concorrência.

---

## 7. Como ler uma falha

### Suítes PHP

A saída do Pest aponta arquivo e linha. O que costuma estar por trás:

- **`Expected status code 200 but received 302`** — quase sempre autorização ou
  organização corrente: o teste esqueceu `actingAsMember($user, $organization)`
  ou o usuário não tem membership ativa;
- **`Expected status code 200 but received 403`** — a policy recusou. Confira o
  papel exigido pela rota em `routes/web.php` (`org.role:owner,admin`);
- **`SQLSTATE ... no such table`** — migração nova sem rodar. `RefreshDatabase`
  migra sozinho; se persistir, apague `.phpunit.cache`;
- **teste pulado com “venv do pdftool ausente”** — não é falha: crie o venv com
  o comando que a própria mensagem traz;
- **teste pulado com “rode `npm run build`”** — idem: falta o build do front;
- **`CspAndBuildAssetsTest` falha dizendo que o HTML não contém
  `/build/assets/app-`, e o HTML mostra `http://[::1]:5173/@vite/client`** —
  existe um `public/hot` **obsoleto**. Esse arquivo é escrito pelo servidor de
  desenvolvimento do Vite e removido quando ele encerra normalmente; se o
  processo for morto à força, ele fica para trás e a aplicação inteira passa a
  apontar para um servidor que não existe mais. Vale tanto para esta suíte
  quanto para a de navegador, que então sobe telas sem CSS nem JavaScript.
  Solução: `rm public/hot` (ou encerrar o `npm run dev` corretamente).

### Suíte de navegador

Quando uma asserção do plugin falha, ele **salva uma captura de tela** em
`tests/Browser/Screenshots/<nome do teste>` — abra a imagem antes de qualquer
outra coisa; ela normalmente responde a pergunta em dois segundos. (O diretório é
limpo a cada execução e está ignorado no Git por
`tests/Browser/Screenshots/.gitignore`.)

Padrões de falha e o que significam:

| Mensagem                                                           | Leitura                                                                                                                                                                                                                         |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Expected to see text [X] ... but it was not found or not visible` | O texto mudou na interface, ou a tela não chegou onde o teste pensa que chegou. A captura mostra qual das duas. Atenção: `assertSee` casa por **trecho** e ignora maiúsculas — `Confere.` também casa dentro de `Não confere.`. |
| `Expected element [X] to be present in the DOM`                    | O componente não montou. Se o seletor for `.shadow-pdf > div`, é o PDF.js — veja §6.                                                                                                                                            |
| `The following exception was thrown by the HTTP server: ...`       | O plugin repassa a exceção do servidor junto da falha. É a informação mais útil da saída: leia essa parte primeiro, não o rastro do Playwright.                                                                                 |
| `Tempo esgotado (Ns) esperando por: ...`                           | Veio de `browserWaitFor()`. A descrição diz qual condição não se cumpriu — normalmente um autosave que não disparou porque um campo obrigatório ficou vazio.                                                                    |
| O teste **trava** em vez de falhar                                 | Requisição multipart chegando ao servidor embutido (§6). Procure um `attach`/upload no caminho.                                                                                                                                 |
| `assertNoJavascriptErrors` falha                                   | Erro de JavaScript de verdade. Vale rodar com `--filter` e olhar a captura; o erro costuma ser um import quebrado ou uma prop faltando depois de mudança no backend.                                                            |

### Instabilidade

A suíte não usa espera por tempo fixo em lugar nenhum. Se um teste de navegador
ficar intermitente, a causa quase certa é uma sequência de interações num
componente **controlado** pelo React: escrever no campo seguinte antes de o React
ter processado o anterior descarta o que já foi digitado. O remédio é intercalar
uma asserção que só passa depois do reprocessamento — ou escolher uma interação
que não dependa do estado anterior — e nunca aumentar o tempo de espera.

O exemplo vivo disso está em `PublicVerificationTest`: mover o foco entre os
blocos do código é **imperativo** e acontece antes de o React confirmar o render
do pai, então ver o foco no bloco seguinte não prova que o valor do bloco
anterior já subiu. O teste digita o código inteiro no **primeiro** bloco, cujo
prefixo (`value.slice(0, 0)`) é sempre vazio — o resultado deixa de depender de
o React ter reprocessado coisa nenhuma.

---

## 8. Ao acrescentar um teste

- identificadores em inglês, interface e textos em PT-BR;
- monte os dados do teste com fábricas e helpers; não dependa dos seeders;
- prefira a camada mais baixa que responda à pergunta;
- em `tests/Browser`, espere por condição (`browserWaitFor`) ou por asserção do
  plugin — nunca por `sleep`;
- se algo não puder ser verificado de forma confiável, marque como `skip` com uma
  mensagem que diga **o que** foi medido e **quando** reavaliar, em vez de deixar
  um teste instável no repositório.
