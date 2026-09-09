# Incrementos 2 e 3 — relatório de integração

> Fecha os incrementos **2 (preparação documental)** e **3 (coleta de aceites)**, entregues em
> paralelo por cinco agentes (B-DOC, B-FIELDS, F-EDITOR, B-SEND, B-SIGN, F-SIGN) e integrados
> em seguida. Este documento registra o que existe hoje, o que foi verificado com comando e
> navegador de verdade, o que foi corrigido na integração e o que fica pendente.
>
> Precedência de contratos: `docs/design/RECONCILIACAO.md` › `docs/arquitetura.md` ›
> `docs/design/ROUTES_AND_PAGES.md` › `docs/design/DESIGN_SYSTEM.md` › mocks.

---

## 1. Verificação — números reais

Todos os comandos abaixo foram executados no fechamento, nesta máquina (Windows 11, PHP 8.3
com `-d extension=intl`, SQLite em memória para a suíte).

| Comando                                                                         | Resultado                                                                  |
| ------------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| `php -d extension=intl artisan test`                                            | **565 testes, 4.102 asserções, 0 falhas** (§9: 596 / 4.208 após a revisão) |
| `php -d extension=intl -d memory_limit=1G vendor/bin/phpstan analyse` (nível 7) | **0 erros**                                                                |
| `vendor/bin/pint --test`                                                        | **passed**                                                                 |
| `npm run types:check`                                                           | **0 erros**                                                                |
| `npm run check` (`vp fmt` + `vp lint`)                                          | **150 arquivos formatados, 0 avisos, 0 erros de lint**                     |
| `npm run build`                                                                 | **sucesso**                                                                |
| `php -d extension=intl artisan wayfinder:generate --with-form`                  | gerado sem diferenças pendentes                                            |
| `php artisan route:list`                                                        | **145 rotas** (93 do contrato + as do incremento 1 + 1 nova, §5)           |

Quebra por módulo (`artisan test tests/Feature/<dir>`):

| Módulo                        | Testes | Asserções |
| ----------------------------- | ------ | --------- |
| `Documents` (B-DOC)           | 46     | 282       |
| `Envelopes` (B-FIELDS)        | 67     | 683       |
| `Sending` (B-SEND)            | 67     | 278       |
| `Sign` (B-SIGN)               | 57     | 397       |
| `EndToEnd` (integração, novo) | 4      | 150       |

Ponto de partida da integração: 558 testes verdes. Fechamento: 565 — 4 do teste ponta a ponta,
1 do novo caso de consentimento sem adaptador de assinatura e 2 do novo caso de mascaramento
de IP.

---

## 2. O que existe agora

### 2.1 Incremento 2 — preparação documental

- **Upload validado pelo conteúdo** (`UploadInspector`): magic number → tipo real, `finfo`,
  extensão × conteúdo, inspeção do ZIP do DOCX (zip bomb, `word/document.xml`), limites de
  imagem por cabeçalho. Nada é gravado antes de passar.
- **Pipeline** (`DocumentIntake` + job `ProcessDocumentUpload` na fila `conversions`):
  `Document(uploaded)` + `DocumentVersion(kind=original, sha256)` → inspeção pelo `pdftool`
  → `ready`, `failed` ou `blocked` (PDF com senha, já assinado, corrompido). DOCX e imagem
  ganham `kind=converted`; o PDF exibível é a própria `original` (bytes não duplicados).
- **Editor de campos** sobre PDF.js: geometria normalizada [0,1] sobre a CropBox exibida,
  mínimos por tipo em pontos, rubrica automática em todas as páginas, arrastar/redimensionar/
  teclado, rail de miniaturas renderizado no cliente.
- **Geometria nunca vem do cliente**: `page_width_pt`, `page_height_pt`, `page_rotation` e
  `box_type` saem sempre de `document_versions.pages_meta`.
- **Prontidão** (`EnvelopeReadiness`): um único escritor de `draft | preparing | ready`, com a
  lista de pendências em PT-BR para o passo 4.

### 2.2 Incremento 3 — coleta

- **Envio em duas fases**: transação curta com `lockForUpdate` (revalida completude, congela
  `sent_document_version_id`, gera `verification_code`, calcula `expires_at`, reserva o plano),
  convites despachados **fora** dela. Idempotência dupla: o lock e o
  `plan_consumptions.idempotency_key = envelope:{id}:send`.
- **Convites**: `recipient_access_links` com token de 32 bytes, só o digest no banco; reenvio
  emite link novo e revoga o anterior; `sent ≠ delivered`.
- **Página pública**: três tokens separados (convite / sessão 30 min / autorização 10 min),
  OTP de 6 dígitos por e-mail com HMAC derivado da APP_KEY, `snapshot_hash` do que foi
  apresentado, 404 genérico e idêntico para toda falha de resolução.
- **Aceite** sob lock, com `UNIQUE(recipient_id)` como segunda defesa; recusa com motivo
  obrigatório encerra o envelope e cancela (não "recusa") os demais.
- **Expiração** por agendador (`envelopes:expire`, 15 min) **e** revalidação a cada acesso.

### 2.3 O que deliberadamente **não** existe

O envelope termina em **`finalizing`**, nunca em `completed`. A finalização — consolidação,
página de evidências, assinatura da operadora, `verification_records` — é o incremento 4 e
ainda não tem ouvinte para `EnvelopeReadyForFinalization`. Nenhuma tela afirma conclusão,
nenhum arquivo final é inventado, nenhuma assinatura é simulada.

---

## 3. Teste ponta a ponta

`tests/Feature/EndToEnd/PreparationAndSigningTest.php` — **4 testes, 150 asserções**.

Nenhum atalho de fábrica: cada passo passa pela rota HTTP real, como o navegador faz.

1. **Fluxo sequencial completo.** `envelopes.create` → upload de um PDF real de 2 páginas
   (gerado com DOMPDF) pela rota do wizard, processado pelo job → `recipients.sync` com papel
   livre → `fields.sync` com geometria → `envelopes.send`. Verifica que só a ordem 1 é
   convidada; abre o link (GET **não** consome e não autentica: a tela é `identify`, sem
   documento nem campos); pede e confere o código lido da notificação; assina desenhando;
   confirma que o segundo passou a `notified` e que o primeiro **não** consegue assinar de
   novo (a sessão foi consumida); o segundo assina; o envelope vai para **`finalizing`** e
   **não** para `completed`, sem `completed_at` e sem `final_document_version_id`. Confere que
   os dois aceites apontam para a mesma versão congelada e que a trilha tem os 12 tipos de
   evento do fluxo, sem `envelope.completed`.
2. **Nenhum segredo persistido.** Concatena tudo que a aplicação grava sobre o fluxo
   (`audit_events`, `delivery_attempts`, `signing_sessions`, `auth_challenges`,
   `recipient_access_links`, `signature_acceptances`) e afirma que o token do convite, o
   código OTP, o token de autorização e o e-mail completo **não** aparecem em lugar nenhum.
3. **Variante paralela.** Todos convidados de uma vez; o segundo da lista assina primeiro; o
   último aceite dispara a finalização.
4. **Variante com recusa.** O envelope encerra em `refused`, ninguém mais assina, nenhum
   aceite é gravado, a trilha registra `recipient.refused` + `envelope.refused` e **não**
   `envelope.finalizing`.

---

## 4. QA visual — o que o navegador mostrou

Servidor real (`artisan serve --port=8125`) sobre um SQLite próprio semeado, `MAIL_MAILER=log`,
`QUEUE_CONNECTION=sync`. Percurso completo como `owner@horizonte.demo`, e depois como os dois
signatários pelos links do convite lidos do log de e-mail. Console verificado em todas as
telas: **nenhum erro**.

| Tela                                    | Veredito          | O que se viu                                                                                                                                                                                                                                                                                                                                                                    |
| --------------------------------------- | ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Login                                   | ✔                 | Split com o painel de marca à esquerda e o formulário à direita, como o mock.                                                                                                                                                                                                                                                                                                   |
| Dashboard                               | ✔                 | KPIs, gráfico "Assinaturas por dia", pendências com botão "Lembrar", uso do plano.                                                                                                                                                                                                                                                                                              |
| Wizard §1 Documento                     | ✔                 | Dropzone; PDF de 3 páginas enviado e processado dentro da requisição; card "contrato-locacao.pdf · 3,1 KB · 3 páginas · ✓ Pronto"; toast "Estamos preparando o documento". "Ou comece por um modelo" com badge Fase 2.                                                                                                                                                          |
| Wizard §2 Signatários                   | ✔                 | Linhas com número colorido, papel ("Locatária"), nome, e-mail, "Enviar por E-mail", "✓ Código por e-mail" e "Token SMS · Fase 2" desabilitado.                                                                                                                                                                                                                                  |
| Wizard §3 Campos                        | ✔                 | PDF.js renderiza o contrato de verdade; rail de 3 miniaturas com marcador nas páginas com campo; paleta por destinatário; inserção no centro; **arraste medido**: −40 px / −72 px pedidos, −40 / −72 aplicados; teclado (Ctrl+seta = 0,02) confirmado; "Rubrica em todas as páginas" gerou 6 campos `initials` reais no servidor, todos em x 0,860 y 0,940 (RECONCILIACAO Q10). |
| Wizard §4 Revisar                       | ✔                 | "8 campos em 3 páginas + rubricas", ordem, validade, "Consumo do plano 1 documento (9 / 500)" e o aviso com o vocabulário certo ("o aceite eletrônico é vinculado à versão exata do arquivo").                                                                                                                                                                                  |
| Envio                                   | ✔                 | Toast "Documento enviado. 1 convite foi despachado." (sequencial).                                                                                                                                                                                                                                                                                                              |
| Detalhe do documento                    | ✔                 | Badge "Aguardando · 0 de 2", código AV-00014, prazo; PDF exibido pela rota de preview; abas Signatários / Trilha / Detalhes.                                                                                                                                                                                                                                                    |
| Aba Signatários                         | ✔ (após correção) | "Maria Alves Souza · Locatária · 1º", chips E-mail / Token e-mail, "✓ Assinado", "Aceite registrado em … · IP 127.0._**.**_ · Windows (Chrome)", "Ver evidências".                                                                                                                                                                                                              |
| Aba Trilha                              | ✔                 | 32 eventos com ícone por tipo, ator e IP; notas honestas: "Abertura detectada — registra o acesso ao link, não comprova leitura" e "Aceite eletrônico com evidências (data, IP, navegador, código confirmado por e-mail)".                                                                                                                                                      |
| Pública `identify`                      | ✔                 | Documento bloqueado à esquerda ("O documento é exibido depois que você confirmar o código"), cartão à direita, e-mail mascarado, aviso de privacidade, botão "Receber código por e-mail". Depois do clique: 6 caixas, "Código válido por 10 min", "Reenviar em 49s".                                                                                                            |
| Pública `sign`                          | ✔                 | PDF com os campos sobrepostos: o próprio em verde ("ASSINATURA · VOCÊ") e o de terceiro tracejado ("Henrique · assina depois de você"). Desenho com eventos de ponteiro reais apareceu no campo do documento na hora. Texto correto: "A imagem é a **representação visual** da sua assinatura; o que registra sua vontade é o aceite abaixo."                                   |
| Pública `sign` — aviso final            | ✔ (após correção) | "Ao final, este documento será concluído como aceite eletrônico com evidências, sem assinatura criptográfica."                                                                                                                                                                                                                                                                  |
| Pública — aceite                        | ✔                 | Botão desabilitado sem a caixa marcada; marcado, habilita; POST gravou o aceite.                                                                                                                                                                                                                                                                                                |
| Pública `already_signed_pending_others` | ✔ (após correção) | "Você já assinou", hora local **e** UTC, e-mail mascarado, código `592G-7VJQ-FWJC`, "Aguardando 1 signatário", comprovante com "Aceite eletrônico", e **"Relatório de evidências"** disponível.                                                                                                                                                                                 |
| Comprovante em texto                    | ✔                 | 3.717 bytes, com a seção "O QUE ESTE COMPROVANTE É — E O QUE NÃO É": "NÃO é a página de evidências, NÃO é um certificado digital e NÃO possui assinatura criptográfica. Um resumo SHA-256 não é assinatura."                                                                                                                                                                    |
| Mobile 375 × 812                        | ✔                 | `identify` com o cartão antes do documento; `sign` com o documento antes do cartão; stepper em duas linhas; `scrollWidth == clientWidth` (sem rolagem horizontal). Segundo aceite feito inteiro nesse viewport.                                                                                                                                                                 |
| Página de evidências                    | ⚠                 | Renderiza e mascara o IP, mas afirma "Este documento **foi concluído**…" com o envelope em `finalizing`. Pendência do incremento 4 (§7.1).                                                                                                                                                                                                                                      |

**Comparação com os mocks.** As divergências previstas pela RECONCILIACAO já estavam
implementadas e documentadas em `docs/frontend.md` (SMS/selfie → código por e-mail;
"Certificado ICP-Brasil" → "Enviar imagem"; "Documento assinado" → "Você já assinou";
WhatsApp → e-mail; CPF/Carimbo fora da paleta; lembretes automáticos com badge Fase 2).
Divergências adicionais que encontrei e **não** corrigi, por serem escolha de escopo e não
defeito: o mock do wizard tem "Idioma dos signatários" e "Importar dos contatos" (ambos
ausentes — Fase 1 é só PT-BR e não há agenda), e uma tela de sucesso pós-envio com "Ver
documento / Criar outra solicitação" (o app vai direto ao detalhe, com toast e faixa); o mock
público tem o rodapé promocional "Quer assinar seus próprios documentos?" (ausente).

---

## 5. Correções feitas na integração

Onze correções. As quatro primeiras são as que mudam o que o produto **afirma**.

### 5.1 A interface prometia uma assinatura criptográfica que não seria aplicada

`ConsentText::operatorCertificateActive()` decidia entre as duas variantes do texto de aceite
olhando **só** para uma linha em `certificate_references`. Essa tabela é metadado: ela não
assina nada. Quem assina é o adaptador, e o contêiner só entrega o `PyHankoSigner` quando
`isConfigured()` (variáveis `COMPANY_CERT_*`, PKCS#12 no disco, senha no ambiente, `pdftool`
disponível); senão entrega o `NullPdfSigner`.

No ambiente verificado as duas coisas divergiam — o seeder cria o certificado de demonstração
e nenhuma variável `COMPANY_CERT_*` existe. Resultado: a tela pública dizia ao signatário
"Ao final, a AssinaVelox aplicará ao arquivo uma assinatura criptográfica…", **e essa frase
era gravada dentro do `consent_statement`**, isto é, dentro da declaração jurídica que a
pessoa aceitou. É exatamente a afirmação que a arquitetura §2 proíbe.

A função agora exige as duas condições. Teste novo:
`SignerConsentTest` › "com o registro do certificado mas o adaptador desligado, não promete
assinatura criptográfica".

### 5.2 Quem recusava recebia "Link inválido"

`RecordRefusal::closeEnvelope()` revogava **todos** os links do envelope, inclusive o de quem
recusou. Como o `sign.refuse` redireciona para `sign.show`, a pessoa clicava em "Recusar",
confirmava o motivo e caía no 404 genérico, como se o sistema tivesse quebrado — e a tela
`refused` exigida por ROUTES §3.3 ("Você recusou assinar este documento em {data}" + motivo),
implementada pelo front, era código morto.

O link de quem recusou passa a sobreviver; os dos demais continuam revogados na hora. Não há
vazamento: `stateFor()` devolve o estado terminal, a sessão de assinatura já foi revogada,
`Challenges::send()` recusa novo código fora do estado ativo e `sign.download` exige aceite ou
sessão viva — nenhum dos dois existe ali. Os dois testes de `SignerRefusalTest` que afirmavam
o comportamento antigo foram reescritos para o contrato, mantendo (e reforçando) as
asserções de segurança.

### 5.3 A faixa de "finalizando" fazia três afirmações falsas

O detalhe do documento anunciava "Gerando o PDF **assinado** e o relatório de evidências… Isso
costuma levar **menos de um minuto**. A página **atualiza automaticamente**." Nenhuma das três
é verdade neste build: não há certificado configurado, não há ouvinte de
`EnvelopeReadyForFinalization` e a página não faz polling. Texto trocado por um que só
descreve o estado ("Todos assinaram. Finalizando o documento… Recarregue a página para
acompanhar."), com o porquê registrado em comentário.

### 5.4 O IP do signatário aparecia inteiro em duas das três telas

`organizations.settings.evidence_show_ip` vale `masked` por padrão (arquitetura §3.1) e deve
valer para todas as telas. Estava assim:

| Tela                         | Antes                                                                                                                           | Agora                  |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------- | ---------------------- |
| Trilha de auditoria          | mascarava 2 octetos, mas lendo a chave de `config/` — uma organização que escolhesse `full` ou `none` via o oposto do que pediu | respeita a organização |
| Card do signatário (detalhe) | **IP inteiro**, sem olhar para nada                                                                                             | respeita a organização |
| Página de evidências         | **IP inteiro**, sem olhar para nada                                                                                             | respeita a organização |

Na mesma página o operador via `127.0.***.***` num lugar e `127.0.0.1` no outro. A decisão
agora mora em `App\Support\IpDisplay` (`mode()`, `for()`, `mask()`) e as três telas delegam a
ela; `none` deixa de exibir endereço em qualquer uma. `tests/Feature/Review/EvidenceIpExposureTest.php`,
que documentava a falha, passou a afirmar o comportamento correto e ganhou dois casos (`full`
e `none` concordando entre as telas). O **user agent** continua sendo entregue por extenso: ele
não é endereço, a página de evidências precisa citá-lo, e `evidence_show_ip` não fala dele —
decisão registrada em teste, para não voltar como suspeita de descuido.

### 5.5 O comprovante de evidências estava escondido do signatário

`receipt-card.tsx` condicionava o botão "Relatório de evidências" a `final_pdf_available`, que
só é verdade depois da finalização. Mas o backend serve `sign.download/evidence` a partir do
instante em que o aceite existe (e assim está documentado). Quem acabava de assinar ficava sem
nenhuma forma de guardar a prova do que fez. Agora o botão aparece com o aceite.

### 5.6 O papel do signatário sumia depois do envio

`RecipientResource::roleLabel()` devolvia `null` com um comentário dizendo que o papel livre
era "Wave B" — mas a coluna `recipients.role_label` foi criada no incremento 2 e o wizard já a
grava ("Locatária", "Fiador"). A aba Signatários perdia um dado que o próprio passo 4 mostra.
Também acrescentei `role_label` a `Recipient::$fillable` e ao factory (pendência que B-FIELDS
deixou explícita: ela gravava por `forceFill`).

### 5.7 `pdf_url` do detalhe apontava para os bytes originais

`EnvelopeDetailResource.document.pdf_url` usava `envelopes.download?type=original`, que para
DOCX e imagem não é PDF — o PDF.js não abriria. Passou a apontar para
`envelopes.document.preview`, a versão exibível. (O front já contornava chamando a rota
diretamente; agora o contrato está certo dos dois lados.)

### 5.8 Trilha e busca do wizard vazavam para o detalhe depois do envio

`router.post` do envio não passava `preserveState: false`; o Inertia mantinha o estado do
wizard no swap e, com ele, os `setLayoutProps` daquela página. O detalhe abria com a trilha
"Nova solicitação" e sem a busca no topo, até a próxima navegação completa. Verificado antes e
depois no navegador.

### 5.9 Injeção de fórmula em CSV nas duas exportações restantes

`App\Support\Csv` já existia e era usado só em `recipients.export`. `dashboard.export` e
`admin.organizations.export` continuavam escrevendo células cruas: um título de documento
começando com `=` vira fórmula no Excel/LibreOffice (CWE-1236). As duas passaram a usar
`Csv::row()`. `tests/Feature/Review/CsvFormulaInjectionTest.php`, que documentava a falha,
agora afirma a neutralização.

### 5.10 `typed_font` podia gravar evidência falsa

`RecordAcceptance::FONTS` aceitava `dancing_script` e `homemade_apple`, famílias que o build
não carrega (`vite.config.ts` empacota Exo 2 e Caveat). `typed_font` é evidência — diz em que
família a representação visual foi desenhada; aceitar uma família ausente registraria como
"Dancing Script" um traço que o navegador desenhou numa fonte genérica do sistema. A lista
ficou em `['caveat']`, que é o que os dois estilos do front realmente enviam.

### 5.11 Diversos

- `App\Enums\DeliveryPurpose` ganhou `case Expiring` (aditivo, sem migration: a coluna é
  `string(32)`); os avisos de prazo usavam `resend` + `meta.reason`.
- Os quatro helpers globais de `tests/Feature/Sending/` ganharam guarda `function_exists` — sem
  ela, um arquivo de teste futuro que reutilizasse um dos nomes (`sentEnvelope`,
  `completedEnvelope`, `outboundEmail`, `expiredDeadlineEnvelope`) derruba a suíte com erro
  fatal. Foi o que quase aconteceu entre B-SEND e B-SIGN.
- A interface `WizardProps.limits` no TypeScript passou a declarar `max_fields`,
  `max_recipients` e `field_minimums`, que o backend já enviava.
- Quatro documentos dos outros agentes foram formatados (`vp fmt`) — só formatação.

---

## 6. Divergências e decisões que ficam registradas

- **Rota nova**: `GET documentos/{envelope}/documento/preview` → `envelopes.document.preview`,
  acrescentada por B-DOC. Sem ela não há como transmitir o PDF **exibível**: a rota de download
  entrega o arquivo como foi enviado. As duas coexistem. Total: 145 rotas.
- **Miniatura PNG no servidor** (`envelopes.document.page` e `sign.page`) responde 404 com
  explicação: gerar PNG exigiria um rasterizador que o projeto decidiu não ter. O rail é
  renderizado no navegador com PDF.js e `page_thumb_url_template` vem `null`.
- **`sign.download` sem `signer.verified`**: o aceite consome a sessão, e é logo depois que a
  pessoa quer o comprovante. Autorização: link + aceite registrado, ou link + sessão viva.
- **Mínimos de campo duplicados**: `FieldGeometry::MINIMUM_POINTS` (servidor) e
  `FIELD_MIN_SIZE_PT` (front) têm hoje os mesmos valores, e o servidor também os publica em
  `limits.field_minimums`. O editor ainda usa a constante local. Se as tabelas divergirem, o
  `fields.sync` devolve 422 — o comentário na interface avisa.
- **`role_label` × `role`**: `recipients.role` continua sendo o enum de domínio (Fase 1:
  sempre `signer`); "Locatária", "Fiador", "Testemunha" são rótulos livres. O botão
  "Adicionar testemunha" do wizard é um atalho que preenche o rótulo — não cria um papel novo.
- **Arraste em automação**: `left_click_drag` do driver não move os campos porque o
  `useEffect` que registra os listeners de `pointermove` só roda depois do commit do React, e
  a automação dispara `down`/`move`/`up` sem ceder o event loop. Com temporização humana
  (≥16 ms entre eventos) o arraste funciona e foi medido ao pixel. Não é defeito do editor.

---

## 7. Pendências

### 7.1 Para o incremento 4 (finalização, evidências, verificação)

1. **Ligar a finalização**: `Event::listen(EnvelopeReadyForFinalization::class, FinalizeEnvelope::class)`.
   Sem isso o envelope fica em `finalizing` para sempre. Depois disso, revisar a faixa do
   detalhe (§5.3) — ela pode voltar a prometer atualização automática se a página passar a
   fazer polling.
2. **Página de evidências**: hoje afirma "Este documento foi concluído…" mesmo com o envelope
   em `finalizing`, e `hashes.evidence_sha256` é `null`. O controller está marcado
   `// TODO(Wave C)`.
3. **Rubricas de vários signatários se sobrepõem.** RECONCILIACAO Q10 fixa uma posição única
   (x 0,86 / y 0,94). Com dois signatários, as duas rubricas de cada página ficam exatamente
   uma sobre a outra — visível no editor e na página pública. A composição do PDF final vai
   carimbar duas imagens no mesmo retângulo. Precisa de uma regra de deslocamento por índice
   do signatário (ou de uma decisão explícita de que só o primeiro rubrica).
4. **O editor não desenha as rubricas automáticas.** O servidor as cria e as devolve, o front
   as filtra do envio (correto: o servidor as regera) mas também não as exibe; "Campos
   inseridos" mostrava 2 enquanto o banco tinha 8. O texto do painel explica onde elas ficam,
   o que atenua, mas quem posiciona não vê o resultado.
5. `sign.download/signed` e o botão "Baixar cópia" dependem de `final_document_version_id`.
6. Página de verificação pública `/verificar/{code}` — depende de `verification_records`.

### 7.2 Para o incremento 5 (cobrança) e depois

7. **Cota**: o ledger reserva/comita/libera corretamente, mas `past_due` e `expired` ainda não
   têm o caminho de pagamento que os produz.
8. **Webhook de entrega**: `delivered` nunca é atingido — o método e o formato da evidência
   existem, nada os chama. O estado observável é `sent`/`unknown`/`failed`.
9. **Token na fila**: com `QUEUE_CONNECTION=database`, o link do convite e o código OTP ficam
   em claro na tabela `jobs` até o job terminar. Decisão consciente, registrada em
   `docs/envio-e-convites.md` §11.

### 7.3 Verificações que só o servidor de produção resolve

10. **LibreOffice não está instalado aqui**: o caminho DOCX real nunca foi exercitado contra o
    `soffice` de verdade; os testes usam um binário falso. Rodar `php artisan pdftool:selftest`
    no servidor.
11. **`lockForUpdate` não faz nada no SQLite dos testes.** A serialização real do aceite e do
    envio depende do MySQL; nos testes a garantia que sobra são os índices `UNIQUE`. Repetir
    os cenários de corrida contra MySQL.
12. **Certificado A1 da operadora**: definir `COMPANY_CERT_*`, conferir que
    `ConsentText::operatorCertificateActive()` passa a devolver `true` e que a interface muda
    de variante — o teste de §5.1 cobre os dois lados.
13. **`{{RAZAO_SOCIAL}}`** do texto jurídico usa `config('app.name')`; precisa da razão social
    real antes de produção.
14. **Worker do PDF.js é _module worker_**: o servidor precisa entregar `.mjs` como
    `text/javascript` (nginx: `types { text/javascript mjs; }`).

### 7.4 Pequenas

15. O painel de propriedades do editor não expõe `options.default` do checkbox.
16. O polling da conversão usa `router.reload({only:[…]})` a cada 3 s em vez da rota JSON
    `envelopes.document.status` — troca de uma linha.
17. `audit_events` de `envelope.sent` grava `document_version` como id interno numérico,
    enquanto os demais eventos usam ULID.
18. Sem OCR, sem detecção de página em branco, sem recusa de DOCX com macros (`vbaProject.bin`
    não é inspecionado; o gancho existe no `UploadInspector`).
19. `progress_pct` é sempre `null` — nem o LibreOffice nem o `pdftool` reportam progresso; a
    interface usa indicador indeterminado.

---

## 8. Onde ler mais

| Assunto                                                   | Documento                               |
| --------------------------------------------------------- | --------------------------------------- |
| Pipeline documental, limites, o que é bloqueado e por quê | `docs/preparacao-documental.md`         |
| Geometria dos campos, mínimos, rubrica automática         | `docs/campos-e-geometria.md`            |
| Envio, links, reenvio, expiração, ledger do plano         | `docs/envio-e-convites.md`              |
| Fluxo público, tokens, OTP, aceite e recusa               | `docs/fluxo-do-signatario.md`           |
| Visualizador de PDF, editor, página pública (front)       | `docs/frontend.md`                      |
| Texto de aceite e versionamento                           | `docs/juridico/declaracao-de-aceite.md` |

---

## 9. Revisão adversarial (fechamento)

Uma revisão independente varreu os incrementos 2 e 3 por três lentes — segurança do fluxo de
assinatura, integridade documental/concorrência/domínio e fidelidade ao design — e produziu
uma bateria de testes de reprodução em `tests/Feature/Review/`. Todos os achados abaixo foram
reproduzidos com teste antes de qualquer correção, e todos os testes citados passam agora.

Números depois das correções: **596 testes, 4.208 asserções, 0 falhas**; PHPStan 0 erros;
Pint verde; `npm run types:check`, `npm run check` e `npm run build` sem erro.

### 9.1 Segurança do fluxo de assinatura

**A posse do link valia por identidade confirmada em `sign.download` (alto).**
A autorização era "existe um aceite deste destinatário **ou** existe sessão viva". O primeiro
ramo não olhava o navegador e não expirava: depois que a pessoa assinava, qualquer um com a
URL do convite baixava o comprovante de aceite e, na conclusão, o PDF final assinado — sem
nunca ter recebido o código por e-mail. Incoerente com a rota vizinha: `sign.document`, que
serve o PDF **não** assinado, exige sessão. Cada GET anônimo ainda gravava
`envelope.downloaded` na trilha com o destinatário como ator.
_Correção_: nova `App\Services\Signing\SignerDownloadGrants` — o `recipient_access_links` com
`purpose = download` e `expires_at` que a arquitetura §4.7 já previa, emitido no aceite e na
confirmação do código, token bruto na sessão Laravel e só o digest no banco, válido por
`signing_session.download_grant_minutes` (padrão 30). `sign.download` passa a exigir sessão
viva **ou** janela aberta, e `envelope.downloaded` só é gravado depois de autorizado. A prop
`receipt.can_download` esconde os botões fora da janela e a tela explica que o arquivo final
chegará por e-mail — emitir esse link de conclusão é trabalho do incremento 4.
Teste: `Review/SignerDownloadRequiresSessionTest.php`.

**Quem só tinha o link trancava o signatário legítimo (médio).**
`otp-send` (3/10 min) e `otp-verify` (5/10 min) eram chaveados só pelo token do convite, o
que transformava os limites em recurso compartilhado entre o signatário e quem tivesse a URL:
gastas as tentativas, o signatário — em outro navegador e outro IP, com o código correto —
recebia 429 para verificar **e** para pedir outro código, indefinidamente.
_Correção_: os dois limitadores passam a ter dois baldes simultâneos, um por `token|ip` com o
teto atual e um por token com teto alto (15 envios / 25 verificações a cada 10 min). Separar
por origem não reabre a adivinhação: o teto absoluto por código continua sendo
`auth_challenges.max_attempts` (5) mais `invalidateLiveChallenges()`.
_Ajuste no teste_: a versão original mandava as duas rodadas do mesmo `REMOTE_ADDR`, e nenhum
limitador distingue duas pessoas atrás do mesmo IP; o teste agora usa origens diferentes, que
é o cenário descrito no próprio achado ("em outro navegador e outro IP").
Teste: `Review/SignerOtpLockoutTest.php`.

**`/assinar/*` não tinha freio por IP (médio).**
`throttle:signer` era `30/min` por **IP + token**: como o token entra na chave, cada palpite
caía num balde novo e uma varredura anônima com tokens diferentes passava sem teto.
_Correção_: dois limites — o de IP+token continua e entra um de 120/min por IP puro, como
`throttle:public` nas demais rotas públicas. Teste: `Review/SignerPublicRateLimitTest.php`.

**A revalidação de prazo "a cada acesso" era inalcançável (médio).**
`AccessLinks::issue()` grava `expires_at = envelope.expires_at`, e o resolver recusava o link
em `isUsable()` **antes** de revalidar o prazo. No minuto seguinte ao vencimento o signatário
recebia o 404 genérico "link inválido" em vez da tela `expired`, o envelope ficava
`in_progress` até o agendador passar (RECONCILIACAO Q22 nunca rodava por esse caminho) e quem
já tinha assinado perdia o acesso ao próprio comprovante.
_Correção_: `SignerLinkResolver` recusa só a **revogação** antes de resolver; revalida o
prazo do envelope e só então aplica o `expires_at` próprio do link — que continua recusando
um link de vida mais curta que a do envelope. E `ExpireEnvelopes::expire()` deixa de revogar
os links de quem **já assinou** (`AccessLinks::revokeForEnvelope(..., exceptRecipientIds:)`):
o prazo do envelope não pode apagar, para essa pessoa, a prova do que ela fez.
Teste: `Review/SignerExpiredLinkScreenTest.php`.

**A imagem manuscrita ficava órfã no disco quando o aceite era recusado (médio).**
`RecordAcceptance` grava o PNG antes da transação (para não segurar o lock durante I/O) e,
quando a revalidação sob lock recusava, o arquivo ficava para sempre: dado pessoal sem
nenhum registro que permitisse auditá-lo ou apagá-lo a pedido, e um caminho barato para
encher o disco (10 imagens de até 3 MB por minuto com o `throttle:10,1`).
_Correção_: `SignatureImages::discard()` e um `catch` em `RecordAcceptance::handle()` que
apaga a imagem de assinatura e a de rubrica quando a persistência falha.
Teste: `Review/SignerRejectedAcceptanceImageTest.php`.

### 9.2 Integridade documental, concorrência e domínio

**Duplicar envelope de origem DOCX/imagem gravava bytes não-PDF como versão exibível
(crítico).** `DuplicateEnvelope::copyDocument()` copiava sempre a versão `original` e a
gravava como `current_version_id` da cópia, mantendo `processing_status = ready`. Para DOCX e
imagem a `original` **não** é PDF: a pré-visualização servia JPEG com `Content-Type:
application/pdf`, a cópia podia ser enviada, o envio congelava essa versão em
`sent_document_version_id`, o signatário a recebia como PDF e o `document_sha256` da
declaração de aceite passava a apontar para um DOCX/PNG; sem `pages_meta`, a geometria dos
campos caía no A4 de fallback.
_Correção_: a cópia leva a versão **exibível** como `current_version_id` e, quando ela é
`converted`, leva também a `original` (duas linhas, como no envelope de origem). Sem versão
exibível a cópia nasce fora de `ready` e sem campos, forçando novo upload. `copyBytes()`
passou a usar `DocumentStorage::pathFor()`, eliminando o único caminho do projeto que expunha
ids internos (`organizations/{id}/documents/{id}/…`).
Teste: `Review/DuplicateDisplayableVersionTest.php`.

**`FieldSync` e `RecipientSync` apagavam aceites e valores em cascata (crítico).**
Os dois serviços documentavam que só rodam em `draft|preparing|ready`, mas nenhum verificava
o status; a única barreira era um `if` no controller, sobre o model do route binding, fora de
transação e sem lock. Como os relacionamentos são `ON DELETE CASCADE`, rodar os serviços
sobre um envelope `in_progress` destruía o aceite de quem já tinha assinado
(`signature_acceptances.recipient_id`) e os valores de campo daquele aceite
(`signing_field_values.signing_field_id`). A guarda do controller também era um TOCTOU real,
porque o wizard salva os campos sozinho.
_Correção_: nova `App\Services\Envelopes\PreparationGuard`, chamada no início da transação
dos dois serviços: recarrega o envelope sob `lockForUpdate()` e lança `ValidationException`
quando o status não é `draft-like` — exatamente o que `SendEnvelope::commitSend()` já fazia e
que este projeto adota como referência. A guarda do controller fica como resposta amigável.
Teste: `Review/PreparationServicesStateGuardTest.php`.

**Duas implementações divergentes da regra "todo signatário precisa de campo de assinatura"
(alto).** `Documents\EnvelopeReadiness` (que grava o status) aceitava qualquer campo
`signature`; `Envelopes\EnvelopeReadiness` (que monta a lista de pendências da tela) exigia
`required`. Um único campo de assinatura com `required: false` deixava o envelope `ready`
(botão "Enviar" habilitado) e com a pendência listada na mesma tela — e `commitSend()`, que
só olhava o status, enviava.
_Correção_: (1) `everyRecipientHasSignatureField()` passou a exigir `required = true`;
(2) `FieldSync` força `required = true` para `signature` e `initials` — um campo de
assinatura opcional não existe no domínio; (3) `commitSend()` bloqueia quando
`EnvelopeReadiness::issues()` não está vazio, além de olhar o status.
_Ajuste no teste_: com (2) o estado divergente deixou de ser alcançável pela rota, então o
segundo caso passou a reproduzi-lo direto no banco (dado legado / escrita fora do serviço)
para exercitar a defesa do envio. Teste: `Review/ReadinessSignatureFieldRuleTest.php`.

**Substituir o documento apagava o anterior antes de gravar o novo (alto).**
`DocumentIntake::store()` removia registro, bytes e todos os `signing_fields` — commitado —
e só então gravava o substituto. Uma falha de `putFile()` (disco cheio, S3 fora, permissão)
deixava o envelope sem documento algum e sem os campos posicionados, com um 500 sem mensagem.
_Correção_: ordem invertida. Os bytes novos são gravados primeiro; a remoção do anterior
entra na **mesma transação** do novo documento (`purgeDocumentRecords()`), e os bytes antigos
só saem depois do commit (`purgeVersionFiles()`). A falha de gravação virou
`UploadRejectedException` com mensagem PT-BR ("o documento atual do envelope continua no
lugar"). Teste: `Review/DocumentReplacementAtomicityTest.php`.

**O guarda de zip bomb do DOCX confiava no tamanho declarado (alto).**
`inspectDocx()` media a expansão por `ZipArchive::statIndex()['size']` — número escolhido por
quem monta o arquivo. Um DOCX de ~60 KB declarando 533 bytes e expandindo 60 MB passava pelos
três limites, era gravado no disco e entregue ao conversor.
_Correção_: as checagens declaradas ficam como corte barato e entra
`assertDocxExpansionWithinLimits()`, que abre cada entrada com `ZipArchive::getStream()`, lê
em blocos de 256 KB e aborta no primeiro byte acima do menor teto entre `max_uncompressed_mb`
e `size * max_compression_ratio`. Teste: `Review/DocxDeclaredSizeZipBombTest.php`.

**Falha no despacho deixava o envelope vivo com o consumo `released` (médio).**
A fase 1 do envio já estava commitada quando `dispatchInitial()` falhava: o envelope ficava
`in_progress`, o consumo ia para `released`, e o remetente entregava os convites por
"Lembrar pendentes" — ciclo inteiro sem nunca descontar a cota, porque `commit()` só age
sobre `reserved` e `reserve()` devolvia a linha `released` como se fosse consumo válido.
_Correção_: o envio é **desfeito**. `SendEnvelope::revertSend()` volta o envelope para
`ready` sob lock (limpa `sent_at`, `expires_at`, `sent_document_version_id`, `current_order`,
preserva o `verification_code`), revoga os links já emitidos e devolve a `pending` quem
chegou a ser notificado; e `PlanLedger::reserve()` passa a **reabrir** uma linha `released`
em vez de devolvê-la. Com o envelope de volta a `ready`, `ResendInvitations` recusa (ele
exige `in_progress`) e um novo clique em "Enviar" refaz tudo, agora cobrando.
Teste: `Review/PlanLedgerReleasedConsumptionTest.php`.

**Teste que passava por acidente: KPI "assinados hoje" dependia do relógio (médio).**
`RecipientsIndexTest` criava `signed_at = now()->subHours(2)` e afirmava `signed_today = 1`;
como o KPI recorta o dia no fuso da organização (America/Sao_Paulo), entre 03:00 e 05:00 UTC
o registro caía no dia anterior e o teste falhava. O controller estava certo.
_Correção_: o teste original congela o relógio (`travelTo` 2026-06-10 15:00 UTC) no
`beforeEach`. O teste de revisão foi reescrito para guardar as duas pontas: o recorte no fuso
da organização (quem assinou às 00:10 locais entra, quem assinou 22:30 de ontem não) e a
presença do `travelTo` no teste original.
Teste: `Review/RecipientsKpiClockDependencyTest.php`.

### 9.3 Fidelidade ao design e experiência do signatário

**A declaração de aceite mostrava um e-mail que não era o do signatário (alto).**
`Recipient::maskEmail()` produzia `m**********@exemplo.test`; o `Emphasis` de
`components/sign/legal-text.tsx` fazia `text.split('**')` e consumia a máscara como marcação
de negrito. O signatário lia e concordava com "identificado(a) … pelo e-mail
`m@exemplo.test`" — endereço válido, diferente do dele e diferente do que fica gravado em
`signature_acceptances.consent_statement`.
_Correção_: (1) a máscara passou a usar `•`, o mesmo glifo que o front já usava em
`lib/format.ts`; (2) `Emphasis` casa apenas pares **balanceados e não vazios**, de modo que
qualquer sequência de asteriscos no texto jurídico apareça literalmente, como o docblock do
componente promete.
Testes existentes de máscara (`Models/RecipientTest`, `Sign/SignerAccessTest`) foram
atualizados para o novo glifo. Teste: `Review/ConsentStatementEmphasisTest.php`.

**As rubricas automáticas de todos os signatários ficavam empilhadas (alto).**
`FieldGeometry::AUTO_INITIALS` era aplicada igual a todo destinatário: com dois signatários,
duas caixas 100 % sobrepostas em cada página — e o editor do passo 3 filtrava
`field.auto !== true`, então quem preparava lia "+ 12 rubricas automáticas" e não via caixa
nenhuma.
_Correção_: `FieldGeometry::autoInitialsSlot($index)` desloca a posição por destinatário
(passo `w + 0.01` para a esquerda, quebrando para a linha de cima quando a linha enche,
sempre com `x+w ≤ 1` e `y+h ≤ 1`); a posição de Q10 continua sendo a do primeiro. E o editor
desenha as rubricas automáticas numa camada **somente leitura** (sem alças, sem eventos),
para que a colisão — e a posição — sejam visíveis antes do envio.
Teste: `Review/AutoInitialsOverlapTest.php`.

**A tela "Enviado para assinatura" nunca aparecia (médio).**
`EnvelopeSendController` gravava `->with('sent', true)` (flash de sessão) e
`EnvelopeController@show` lia `sent` da **entrada** da requisição: a prop chegava sempre
`false` e o banner de DESIGN §6.5 era código morto.
_Correção_: o redirect passou a carregar `?sent=1`, que é o contrato literal de ROUTES §2.6 e
ainda deixa a confirmação recarregável e compartilhável.
Teste: `Review/WizardSuccessScreenTest.php`.

**Duas máscaras diferentes para o mesmo IP (médio).**
`SignerRequestFacts::displayIp()` tinha máscara própria (um octeto escondido) enquanto as
telas do remetente usavam `IpDisplay` (dois octetos) — e a minimização mais fraca era
justamente a da tela que o titular do dado vê.
_Correção_: `displayIp()` delega para `IpDisplay::mask()`; a máscara própria foi apagada.
Teste: `Review/SignerIpMaskConsistencyTest.php`.

**Quem assinava por último era informado de algo que já acontecera (médio).**
Com o envelope em `finalizing` e `pending_others = 0`, o comprovante ainda dizia "você
receberá o arquivo final quando todos os participantes concluírem".
_Correção_: novo estado `finalizing` em `SignerContext`/`SignerLinkResolver` (o resolver o
decide por `EnvelopeStatus::Finalizing`), incluído nas props com comprovante; o
`receipt-card.tsx` escolhe entre três frases — concluído, em finalização e realmente
pendente — e o título vira "Aceites concluídos" quando não falta ninguém.
Teste: `Review/SignerReceiptStateTest.php`.

**Cópia e rótulos (médio).** "Refazer" no botão que desfaz o último traço virou "Desfazer"
(o ícone `Undo2` já estava certo); `authMethodLabels.email_otp` no TypeScript virou "Código
por e-mail", igual ao enum PHP (ROUTES §6.6); os `(s)` improvisados de `show.tsx` e
`otp-card.tsx` passaram pelo helper `plural()`; e o `aria-label` do `FieldLayer` deixou de
anunciar "Setas movem, Shift com setas redimensiona, Delete remove" nas caixas
`readOnly`/`role="img"`. Teste: `Review/SignerCopyAndLabelsTest.php`.

**~179 KB de fontes woff2 baixados e descartados em toda página (médio).**
O provedor devolve duas regras `@font-face` por face (woff2 e woff) com a mesma família,
peso, estilo e `unicode-range`; pela cascata o navegador usava sempre o `.woff`, enquanto o
`<link rel="preload">` apontava para o `.woff2` — dez arquivos baixados à toa por página,
inclusive na página pública de assinatura.
_Correção_: um plugin de build em `vite.config.ts` (`mergeFontFaceFormats`, hook
`writeBundle`) une as faces duplicadas numa única regra com os dois formatos no mesmo `src` e
o woff2 na frente. O `crossorigin` do preload já vinha do manifesto do framework.
Teste: `Review/FontDeliveryTest.php` (pula quando não há `public/build`).

### 9.4 O que NÃO foi feito, e por quê

- **`restrictOnDelete` (ou soft delete) em `signature_acceptances.recipient_id` e
  `signing_field_values.signing_field_id`.** É a defesa em profundidade certa — nenhum
  caminho de aplicação deveria conseguir apagar um aceite por cascata — mas exige alterar
  migrations já aplicadas no MySQL de produção, o que está fora do alcance desta sessão (não
  se roda `migrate` contra esse banco). A invariante ficou garantida em serviço
  (`PreparationGuard`), com teste. **Fica como pendência de banco.**
- **Reautenticação do signatário depois de assinar.** `Challenges::send()` exige
  `context.isActive()`, então quem já assinou não consegue pedir um novo código; a janela de
  download cobre o período útil e a tela explica o resto. O acesso de longo prazo ao arquivo
  final é o link `purpose=download` no e-mail de conclusão (arquitetura §4.7), trabalho do
  **incremento 4** — o mecanismo (`SignerDownloadGrants`) já está pronto.
- **`challenge.failed` de tentativas de terceiros na trilha do destinatário.** O achado nota,
  com razão, que palpites de quem só tem o link sujam a evidência e fazem o remetente ver o
  signatário "errando o código". Não há como distinguir origem legítima de ilegítima com
  confiança suficiente para omitir o evento — e omitir evento de auditoria é pior do que
  registrar de mais. Fica registrado como decisão consciente; uma alternativa futura é marcar
  no payload que a tentativa veio de origem diferente da que pediu o código.
