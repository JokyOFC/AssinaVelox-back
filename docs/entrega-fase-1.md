# AssinaVelox — Entrega da Fase 1

> Documento de entrega. Diz o que **existe**, o que **não existe**, o que foi **verificado com
> comando executado** e o que **continua pendente porque depende de credencial ou de servidor
> real**. Nada aqui é marcado como concluído por estar esboçado.
>
> Data desta verificação: **2026-09-09**. Base: commit `29e28cc` mais o trabalho em curso da
> rodada de endurecimento (§3.4).

## Sumário

1. [O que a Fase 1 entrega](#1-o-que-a-fase-1-entrega)
2. [O que foi implementado, por incremento](#2-o-que-foi-implementado-por-incremento)
3. [Verificações executadas](#3-verificações-executadas)
4. [Pendências que dependem de credencial ou servidor real](#4-pendências-que-dependem-de-credencial-ou-servidor-real)
5. [Limitações conhecidas](#5-limitações-conhecidas)
6. [O que é Fase 2 ou 3](#6-o-que-é-fase-2-ou-3)
7. [Decisões que dependem de definição jurídica](#7-decisões-que-dependem-de-definição-jurídica)
8. [Decisões que dependem do proprietário](#8-decisões-que-dependem-do-proprietário)
9. [Critério de "pronto para produção"](#9-critério-de-pronto-para-produção)
10. [Revisão final da Fase 1](#10-revisão-final-da-fase-1)

---

## 1. O que a Fase 1 entrega

Um fluxo completo, ponta a ponta, de **preparo de documento e aceite eletrônico com evidências**:

uma organização se cadastra e convida sua equipe → envia um PDF (ou DOCX/imagem, convertidos) →
posiciona os campos sobre as páginas → convida os signatários por e-mail → cada signatário abre um
link público, confirma um código enviado ao seu e-mail, revisa o documento e registra o aceite ou
a recusa → o sistema consolida o documento, anexa uma página de evidências, assina com o
certificado A1 da operadora **se houver um configurado**, calcula o hash final e publica um código
de verificação pública → qualquer pessoa confere o arquivo em `/verificar/{código}` → a
organização paga o plano por Checkout Pro e o webhook autenticado ativa a assinatura.

**O que a entrega afirma, exatamente.** As quatro coisas continuam distintas e a interface usa esse
vocabulário (`docs/arquitetura.md` §2):

| Conceito                                    | O que é                                                                                                                                           | Estado                                                      |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| Representação visual                        | Imagem desenhada, digitada ou enviada. Não prova nada sozinha.                                                                                    | Entregue                                                    |
| Aceite eletrônico com evidências            | Manifestação de vontade vinculada à versão do documento, com autenticação por código no e-mail, data do servidor, IP, agente e versão dos termos. | Entregue                                                    |
| Assinatura criptográfica da **operadora**   | PAdES **B-B**, sem carimbo do tempo, sem LTV. Identifica a empresa, não o signatário.                                                             | Entregue, verificada **só com certificado de teste** (§4.1) |
| Assinatura pessoal ICP-Brasil do signatário | —                                                                                                                                                 | **Não existe** (Fase 2 §2.12 / Fase 3 §3.4)                 |

Sem certificado configurado, o envelope conclui como _aceite eletrônico com evidências_
(`signature_status = none`) e a interface, a página de evidências e a verificação pública dizem
exatamente isso. **Nenhuma assinatura é simulada.** Nenhum perfil PAdES além de B-B é anunciado.
Não há promessa de validade universal.

---

## 2. O que foi implementado, por incremento

### Incremento 1 — Base e isolamento

Cadastro e autenticação por Fortify (registro, verificação de e-mail, redefinição de senha, TOTP
com confirmação); organizações com papéis `owner` / `admin` / `member`; **isolamento por
organização** aplicado por escopo global, _route model binding_ escopado e Policies; política de
2FA obrigatório por organização; política de encerramento de sessão por inatividade; convites de
membros com token guardado apenas como digest; painel interno `/admin` para administrador de
plataforma; casca do design (Inertia + React + Tailwind + shadcn/ui); páginas de conta, perfil e
segurança; exclusão de conta.

Relatório: `docs/incremento-1-relatorio.md`.

### Incremento 2 — Preparação documental

Upload **validado pelo conteúdo** e não pela extensão (`finfo` + assinatura de bytes + inspeção do
ZIP do DOCX contra _zip bomb_ + cabeçalho da imagem); conversão assíncrona na fila `conversions`
(DOCX via LibreOffice, imagem via `pdftool image2pdf`, PDF direto); inspeção do PDF (páginas,
cifra, assinaturas existentes); versionamento do documento (`original`, `converted`,
`consolidated`, `evidence`, `final`) com `sha256` de cada versão; editor de campos sobre PDF.js
com coordenadas normalizadas; rubricas automáticas por página.

### Incremento 3 — Coleta de aceites

Destinatários e ordem de assinatura; envio com **congelamento da versão** do documento e reserva
de cota do plano; convites por token guardado apenas como resumo; página pública do signatário sem
conta; código de uso único enviado por e-mail (HMAC derivado da `APP_KEY`, nunca gravado em claro,
nunca em log); sessão curta do signatário e token de autorização do aceite; captura da
representação visual (desenho, digitada, imagem enviada — toda imagem reprocessada com GD e
reescrita como PNG sem metadados); aceite com evidências gravadas sob lock; recusa com motivo;
expiração no fuso da organização; reenvio manual com limites; trilha de auditoria _append-only_.

Relatório: `docs/incremento-2-3-relatorio.md`.

### Incremento 4 — Finalização, evidências e verificação pública

Pipeline de finalização **idempotente e retomável**: consolidação dos campos sobre o PDF → página
de evidências gerada em Blade → DOMPDF **antes** da assinatura e anexada ao documento → assinatura
PAdES B-B da operadora quando há certificado → **hash final calculado depois da assinatura** →
registro de verificação. Rodapé com a URL de verificação carimbado em todas as páginas. Página
pública `/verificar/{código}` com conferência de hash feita **no navegador do visitante**.
Downloads autorizados para a organização e para o signatário (janela de download emitida no
aceite). Linguagem correta para cada situação de assinatura.

### Incremento 5 — Gestão e cobrança

Dashboard, pastas, busca; gestão de usuários e convites; catálogo de planos; **Mercado Pago
Checkout Pro** com preferência criada pelo backend; webhook `POST /webhooks/mercadopago`
autenticado por assinatura do provedor, com ativação **idempotente** pela impressão do evento;
ledger de consumo do plano (reserva → confirmação → liberação); inadimplência (`past_due` após
carência, `expired` depois, volta ao plano Grátis); recibo interno em PDF — que **diz que não é
documento fiscal**.

Relatório: `docs/incremento-4-5-relatorio.md`.

### Incremento 6 — Endurecimento e documentação

Cabeçalhos de segurança e CSP com nonce por resposta; limitadores de requisição por rota sensível
com chave composta; trilha de auditoria com checkpoint encadeado (`audit:checkpoint`);
mascaramento de dados sensíveis no registro; identificador de correlação por requisição propagado
aos jobs; diagnóstico operacional (`assinavelox:doctor`, `assinavelox:health`, `storage:verify`,
`pdftool:selftest`); testes de navegador com Pest + Playwright; documentação de implantação,
operação, segurança operacional, testes e entrega.

**Integração final.** O ciclo inteiro foi percorrido à mão contra o **MySQL de desenvolvimento**
(`docs/testes.md` §6-B), e não só contra o SQLite da suíte. Três defeitos apareceram e foram
corrigidos aí:

| Defeito                                                                                                                                                        | Correção                                                                                                                   |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| O filtro do campo de código de verificação descartava a letra `L`, que o servidor sorteia — ~1 em cada 3 códigos ficava indigitável.                           | Alfabeto alinhado em `verification-code.tsx`; duas guardas em `VerificationContractTest` e o teste de navegador reativado. |
| `retry_after` da fila (90 s) menor que o `timeout` de `FinalizeEnvelope` (600 s) — a finalização era reenfileirada no meio.                                    | Padrão para 900 s; `supervisor-finalization` do Horizon de 300 para 600; `QueueTimeoutsTest` guarda a relação.             |
| A regra de mascaramento do log casava por sufixo e apagava `exit_code`, `failure_code` e afins — o campo mais útil para diagnosticar uma conversão que falhou. | Lista de exceções por nome exato (`redaction.allow_keys`), com teste provando que `otp_code` e `code` continuam redigidos. |

---

## 3. Verificações executadas

### 3.1 Números reais, obtidos rodando os comandos

Executados nesta máquina (Windows 11, PHP 8.3 com `intl` e `sockets` habilitadas, MySQL local para
desenvolvimento, SQLite em memória para a suíte) em **2026-09-09**, **no commit de fechamento** —
com a árvore inteira, incluindo o incremento 6 e as correções da integração:

| Verificação                    | Comando                                        | Resultado observado                                                                                                                             |
| ------------------------------ | ---------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| **Suíte Pest**                 | `php artisan test`                             | **880 testes, 877 passaram, 0 falharam, 3 pulados, 6 805 asserções**, 598,0 s — por suíte: `Unit` 13/261, `Feature` 828/5 804, `Browser` 39/740 |
| **Suíte do pdftool (Python)**  | `.venv/Scripts/python.exe -m pytest -q`        | **93 testes, 93 passaram**, 9,05 s                                                                                                              |
| **Estilo PHP**                 | `vendor/bin/pint --test`                       | **passou**                                                                                                                                      |
| **Análise estática (nível 7)** | `vendor/bin/phpstan analyse --memory-limit=1G` | **0 erros**                                                                                                                                     |
| **Tipos do front**             | `npm run types:check` (`tsc --noEmit`)         | **0 erros**                                                                                                                                     |
| **Formatação e lint do front** | `npm run check`                                | **181 arquivos formatados, 0 avisos em 145**                                                                                                    |
| **Build de produção**          | `npm run build`                                | **sucesso**, 16,02 s                                                                                                                            |
| **`composer.json`**            | `composer validate`                            | **válido**                                                                                                                                      |
| Rotas registradas              | `php artisan route:list`                       | 145 no total; 94 fora de Horizon/Inertia/health                                                                                                 |
| Cobertura do `.env.example`    | script sobre `config/*.php`                    | **245 de 245** variáveis lidas por `config/` estão listadas                                                                                     |

Os **3 pulados** são declarados e explicados no próprio arquivo de teste, não silenciosos: dois em
`tests/Browser/PreparationTest.php` (limitações do `pest-plugin-browser`, `docs/testes.md` §6) e um
em `tests/Browser/SmokeTest.php`.

Comparação com o fechamento anterior (`docs/incremento-4-5-relatorio.md`): os incrementos 4 e 5
fecharam em 752 testes / 5 269 asserções e a revisão adversarial que veio depois levou a **783**
(commit `29e28cc`). Daí para o fechamento da Fase 1: 783 → **880** testes.

Além disso, contra o **MySQL real** (banco `assinavelox`, `migrate:fresh --seed`):

| Verificação             | Resultado observado                                                                                                                                                                                     |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Migrations e seeders    | 27 migrations aplicadas, 3 seeders concluídos, sem erro                                                                                                                                                 |
| Conformidade do esquema | 34 tabelas, **todas InnoDB e `utf8mb4`**; 442 colunas, **nenhum `ENUM`/`SET` de SQL**; 159 índices, maior nome com **60 de 64** caracteres; 69 chaves estrangeiras; `audit_events` **sem `updated_at`** |
| `assinavelox:doctor`    | 25 verificações: **0 falhas, 7 avisos**, todos esperados sem credencial externa (certificado, Mercado Pago, LibreOffice, e-mail, checkpoint, cifra em repouso)                                          |
| `storage:verify`        | disco `documents` gravável, íntegro e privado; cifra em repouso **não atestada** e rotulada como tal, que é o correto em disco local                                                                    |
| Ciclo completo          | do upload à verificação pública, num navegador real — ver `docs/testes.md` §6-B                                                                                                                         |

### 3.2 O que a suíte cobre

| Camada                    | Onde                                              | O que exercita                                                                                                                                  |
| ------------------------- | ------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Unitário                  | `tests/Unit`                                      | Enums, máquinas de estado, geometria dos campos, hashes.                                                                                        |
| Feature HTTP              | `tests/Feature/{Auth,Organizations,Members,…}`    | Rotas Inertia, Policies, escopo por organização, limitadores.                                                                                   |
| Preparação                | `tests/Feature/{Documents,Envelopes}`             | Upload validado por conteúdo, conversão, versões, campos.                                                                                       |
| Coleta                    | `tests/Feature/{Sending,Sign}`                    | Envio, convites, código por e-mail, sessão, aceite, recusa.                                                                                     |
| Finalização e verificação | `tests/Feature/{Finalization,Verification,Pdf}`   | Pipeline, evidências, assinatura, registro público, downloads.                                                                                  |
| Cobrança                  | `tests/Feature/Billing`                           | Checkout, webhook, idempotência, ledger, inadimplência.                                                                                         |
| Ciclo completo            | `tests/Feature/EndToEnd/FullLifecycleTest.php`    | Do upload à verificação pública, com o pdftool real.                                                                                            |
| Segurança / revisão       | `tests/Feature/{Security,Review,Smoke,Hardening}` | CSP, cabeçalhos, limites, isolamento, mascaramento no log, `retry_after` × timeout dos jobs. `Hardening` sozinho: **56 testes, 454 asserções**. |
| Navegador                 | `tests/Browser`                                   | Pest + Playwright + Chromium: **39 testes (36 ativos, 3 pulados), 740 asserções**, 45,7 s.                                                      |
| pdftool                   | `tools/pdftool/tests`                             | `inspect`, `compose`, `append`, `sign`, `validate`, `cert-info`, `gen-test-cert`, `image2pdf`.                                                  |

### 3.3 O que a suíte **não** cobre

- **Não há teste unitário de front** (JavaScript/React): o projeto não tem runner JS. O que cobre o
  front é a suíte de navegador (Pest/Playwright) mais a inspeção manual registrada nos relatórios.
  Consequência concreta: o defeito do alfabeto do código de verificação (§2, incremento 6) morava
  numa função pura de 3 linhas em TypeScript e sobreviveu à Fase 1 inteira. A guarda que o pegou
  hoje é um teste **PHP** que lê o arquivo `.tsx` — contorno, não substituto.
- **`lockForUpdate` não faz nada no SQLite** usado pela suíte. A serialização real do aceite, do
  envio e da numeração de envelopes depende do MySQL; o que os testes garantem nesses cenários são
  os índices `UNIQUE`. O esquema e o fluxo já foram exercitados contra MySQL real
  (`docs/testes.md` §6-B), mas com **um usuário só**: **os cenários de corrida continuam pendentes.**
- **As páginas de erro Inertia** (`resources/js/pages/errors/{403,404,500}.tsx`) não são alcançadas
  por teste nenhum: `bootstrap/app.php` devolve resposta crua quando `app()->runningUnitTests()`.
  Decisão de projeto, não defeito — mas é um ponto cego real, e fechá-lo exigiria trocar esse guarda
  por uma chave de configuração que o teste possa ligar.
- **Duas rotas não são verificáveis pela suíte de navegador** — upload `multipart` e interação sobre
  a página do PDF.js — por limitações do servidor in-process do `pest-plugin-browser`, não da
  aplicação: as duas funcionam no navegador real (`docs/testes.md` §6).
- Nenhum teste executa LibreOffice, Mercado Pago, SMTP real ou um certificado de produção — todos
  usam dublês declarados (§4).

### 3.4 Honestidade sobre o estado da árvore

Os números do §3.1 são de **uma única árvore**, a do commit de fechamento, com todos os incrementos
dentro. Uma versão anterior deste documento trazia 784 testes e um `pint --test` falhando em 7
arquivos: eram de uma árvore intermediária, colhidos enquanto o incremento 6 ainda estava sendo
escrito. Foram substituídos, não corrigidos por estimativa — a suíte foi rodada de novo do zero.

O que vale registrar sobre como esses números foram obtidos:

- Cada verificação foi executada **depois** da última alteração de código, nesta ordem: suíte Pest,
  pdftool, Pint, PHPStan, tipos, formatação/lint, build.
- As três correções da integração (§2, incremento 6) entraram **antes** dessa rodada, e cada uma
  trouxe teste próprio. As guardas do alfabeto do código e do `retry_after` foram vistas
  **falhando** com os valores antigos antes de serem aceitas — um teste que nunca se viu falhar não
  prova nada.
- O ciclo manual contra MySQL (`docs/testes.md` §6-B) foi percorrido antes das correções; foi ele
  que revelou o defeito do `L` e a redação excessiva do `exit_code`. O fluxo em si não foi repetido
  depois — o que foi repetido, e passou, foi a suíte inteira mais a conferência no navegador do
  campo de código corrigido.

---

## 4. Pendências que dependem de credencial ou servidor real

Estas cinco não são bugs nem esquecimentos: são verificações que **não podem** ser feitas sem algo
que o projeto não tem. Cada uma está descrita com o que exatamente falta e como confirmar.

### 4.1 Certificado A1 de produção — **marco em aberto**

**Nada foi assinado com um A1 real de ICP-Brasil.** Toda a cobertura usa certificados gerados por
`pdftool gen-test-cert`: autoassinados, RSA-2048, com `TESTE` no CN, rotulados
`environment = test` em toda a cadeia.

Não foi exercitado: cadeia com AC intermediária; políticas de certificado da ICP-Brasil; um `.pfx`
com algoritmos ou parâmetros diferentes dos do autoassinado; a senha vindo do ambiente real do
serviço (`EnvironmentFile` do systemd, com `config:cache` aplicado); e a leitura do arquivo final
por um verificador oficial (ITI) ou por um leitor de PDF de terceiro.

**Como fechar o marco:** assinar um envelope real com um A1 emitido por AC da ICP-Brasil, com a
cadeia da AC em `PDFTOOL_TRUST_ROOTS`, e conferir o resultado em um verificador independente. Só
depois disso o marco pode ser dado como cumprido.

Detalhe: `docs/finalizacao-e-evidencias.md` §7; troca e vencimento: `docs/operacao.md` §6.

### 4.2 Mercado Pago — nunca falou com o provedor real

Todo o adaptador HTTP foi verificado com `Http::fake()` e o fluxo de negócio com o dublê. **Nenhuma
requisição real, nem de sandbox nem de produção, foi feita.**

Continuam pendentes de uma conta de teste: criação de preferência real, `init_point` real,
`back_urls` reais, entrega de webhook real, e a divergência de caixa do `data.id` na verificação da
assinatura. Continuam pendentes de conta de produção: credencial `live_mode`, segredo de webhook de
produção e um pagamento real ponta a ponta.

Comportamento atual, correto e explícito: sem `MERCADOPAGO_ACCESS_TOKEN` o adaptador responde
`isConfigured() = false` e **o checkout diz na tela que está desabilitado**; sem
`MERCADOPAGO_WEBHOOK_SECRET` o webhook responde 401 e não processa nada. Os planos pagos do seeder
são `is_sandbox = true` com **preços fictícios**.

Nove itens da documentação oficial permanecem **NÃO CONFIRMADOS** e nenhum deles foi tratado como
fato no código — lista em `docs/cobranca.md` §14.

### 4.3 Serviço de e-mail do proprietário

Não existe conta de e-mail transacional configurada. O adaptador
(`App\Integrations\Contracts\EmailProvider`) fala apenas com o `MailManager` do Laravel: em
produção o serviço do proprietário entra por configuração (`MAIL_MAILER=smtp` com host, porta e
credenciais, ou um transporte HTTP registrado em `config/mail.php`). **Nenhum endpoint foi
inventado no código.**

O que **não foi verificado**: entrega real a uma caixa de entrada, SPF/DKIM/DMARC do domínio,
reputação do IP de envio, comportamento de rejeição e limites do provedor.

Consequência já registrada: `DeliveryStatus::Delivered` **nunca é atingido na Fase 1** — não há
webhook de entrega configurado. O estado final observável é `sent`, `unknown` ou `failed`, e a
interface diz isso. "Enviado" não é "entregue".

### 4.4 LibreOffice em servidor real

O adaptador foi verificado com um **binário falso** (`tests/Fixtures/fake-soffice.{bat,sh}`) que
registra os argumentos e o ambiente recebidos e copia um PDF. Os testes confirmam os _flags_, o
`--outdir`, o `-env:UserInstallation=`, a existência do `registrymodifications.xcu` no perfil
isolado, o ambiente mínimo e o timeout.

**Não foi verificado com o `soffice` de verdade:** se ele aceita o `registrymodifications.xcu`
pré-semeado, o filtro de exportação, a fidelidade visual da conversão, o tempo real da primeira
execução com perfil novo, e o comportamento sem X11. Todo o ciclo de vida testado parte de PDF.

**Como confirmar:** instalar (`docs/implantacao.md` §7), rodar `php artisan pdftool:selftest` no
servidor e converter um DOCX de teste conferindo o PDF gerado.

Além disso, o isolamento de rede do processo de conversão (`PrivateNetwork=yes` na unit do worker,
ou regra de firewall por UID) **não está configurado** — sem ele, "a conversão não acessa a rede" é
uma expectativa, não uma garantia. `docs/pdf-pipeline.md` §4.

### 4.5 Criptografia em repouso do armazenamento

O Flysystem **não cifra nada sozinho**. Duas situações, ambas sem verificação neste projeto:

- **Disco local** (`DOCUMENTS_DISK=local`): a cifra é do volume (LUKS, BitLocker). Não há consulta
  possível a partir do PHP — só existe **atestação do operador**
  (`ASSINAVELOX_STORAGE_ENCRYPTION_ATTESTED`), e ela é registrada como atestação, jamais como
  verificação. **Nenhum volume cifrado foi montado nem atestado.**
- **S3** (`DOCUMENTS_DISK=s3`): a cifra é do lado do servidor e `storage:verify` a consulta ao
  backend (`GetBucketEncryption` + o cabeçalho de um objeto de prova). **Nenhum bucket real foi
  configurado nem consultado.**

Enquanto isso não for feito, não se pode afirmar ao cliente que os documentos estão cifrados em
repouso.

### 4.6 O sistema nunca foi implantado

Não existe servidor. `docs/implantacao.md` é o procedimento derivado do código e da configuração,
não a repetição de algo já feito. Nada de nginx, PHP-FPM, systemd, Redis, TLS ou permissões foi
executado. Itens da primeira implantação que só o servidor confirma estão marcados no checklist de
`docs/implantacao.md` §16 — em especial: `.mjs` servido como `text/javascript` (sem isso o editor
de campos não carrega), upload de 25 MB completando, e o ensaio de restauração.

---

## 5. Limitações conhecidas

Coisas que funcionam, mas funcionam assim.

### Assinatura e evidências

| Limitação                                                                                           | Consequência prática                                                                                                                                                                            |
| --------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Perfil **PAdES B-B** apenas: sem carimbo do tempo (B-T), sem LTV/LTA/DSS, sem consulta de revogação | A data mostrada é a do servidor, não uma prova de tempo de terceiro. A verificação futura depende de o certificado ainda ser verificável — guarde o `.pfx` e a cadeia.                          |
| `all_intact` não significa "o arquivo não mudou"                                                    | A validação responde sobre os bytes **cobertos** por cada assinatura. Por isso a plataforma exige também `all_covering` e `all_docmdp_ok` antes de publicar um arquivo como assinado e íntegro. |
| `recipients[].signature_image_url` é sempre `null` na página de evidências                          | Não há rota autorizada que sirva a imagem do disco privado. O PDF embute a imagem; a página web não a exibe.                                                                                    |
| Assinatura **digitada** sai na fonte padrão do PDF, não na fonte da tela                            | A fonte é carregada pelo navegador e não existe no servidor. `typed_font` continua gravado como evidência.                                                                                      |
| A composição usa Helvetica/WinAnsi                                                                  | Caracteres fora do WinAnsi em campos de texto viram `?`. Embutir um TTF é ponto de extensão.                                                                                                    |
| Um registro de verificação **revogado** responde "não encontrado"                                   | Igual a inexistente. Nada na Fase 1 revoga registros.                                                                                                                                           |

### Envio, entrega e conversão

| Limitação                                                                | Consequência prática                                                                                                                                                                                                        |
| ------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `delivered` / `bounced` nunca são atingidos                              | Sem webhook de entrega. O estado observável é `sent` / `unknown` / `failed`.                                                                                                                                                |
| O timeout de e-mail é o do transporte, não deste código                  | Impor um timeout por fora do `MailManager` exigiria reimplementar o transporte.                                                                                                                                             |
| **O token do convite viaja no payload da fila**                          | Com `QUEUE_CONNECTION=database`, o link e o código ficam em claro na tabela `jobs` até o job terminar. Decisão consciente, registrada em `docs/envio-e-convites.md` §11. Em produção (Redis) o alcance é menor, mas existe. |
| Falha no despacho deixa o envelope `in_progress` sem convites            | A cota não é cobrada; o remetente usa "Lembrar pendentes".                                                                                                                                                                  |
| `progress_pct` é sempre `null`                                           | Nem o LibreOffice nem o pdftool reportam progresso; a interface usa indicador indeterminado.                                                                                                                                |
| Sem OCR, sem detecção de página em branco, sem recusa de DOCX com macros | O gancho existe no `UploadInspector`; `vbaProject.bin` não é inspecionado. Documentos com macros são convertidos **sem executá-las**.                                                                                       |
| Um envelope tem **exatamente um documento**                              | Regra do serviço e dos testes. Múltiplos documentos é Fase 2 (§2.3).                                                                                                                                                        |

### Cobrança

| Limitação                                     | Consequência prática                                                                                                                            |
| --------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Sem recorrência automática                    | Não existe no Checkout Pro. Cada ciclo é um pagamento avulso.                                                                                   |
| Estorno e contestação são **gravados** apenas | `refunded` / `charged_back` chegam pelo webhook e ficam no pagamento. Encerrar o plano ou devolver cota **não está decidido nem implementado**. |
| Sem cobrança de excedentes                    | A cota esgotada **bloqueia** o envio; não gera cobrança extra. (O mock exibia "R$ 0,90 por documento".)                                         |
| Sem reembolso ou cancelamento pela interface  | Os endpoints do provedor existem; nenhuma tela os aciona.                                                                                       |
| Plano anual não tem catálogo                  | O seletor e o modelo suportam `yearly`; o seeder só traz planos mensais.                                                                        |
| Recibo interno **não é nota fiscal**          | E o PDF diz isso. NFS-e é Fase 2 (§2.21).                                                                                                       |

### Dados e operação

| Limitação                                                   | Consequência prática                                                                                                                                                                                                                                                                                          |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Nada é apagado automaticamente**                          | Não há purga nem retenção configurável (Fase 2 §2.19). Planeje o disco para crescer.                                                                                                                                                                                                                          |
| Busca por CNPJ não é suportada                              | `organizations.tax_id` é cifrado com a `APP_KEY` e não é indexável.                                                                                                                                                                                                                                           |
| Exclusão efetiva da organização após a carência não tem job | A solicitação é registrada; a exclusão física não acontece sozinha.                                                                                                                                                                                                                                           |
| Corridas de banco só são exercitadas em SQLite              | `lockForUpdate` não faz nada no SQLite da suíte. O aceite simultâneo, a numeração de envelope e a reserva de cota dependem do MySQL; o que os testes garantem nesses cenários são os índices `UNIQUE`. O ciclo manual em MySQL (`docs/testes.md` §6-B) foi de um usuário só e **não** exercitou concorrência. |
| Sem multi-servidor                                          | Os comandos agendados usam `onOneServer()`, mas nada mais foi projetado para mais de um nó.                                                                                                                                                                                                                   |

---

## 6. O que é Fase 2 ou 3

Nada abaixo está implementado. Detalhe, dependências e o que cada ativação exige:
**`docs/roadmap.md`** — em especial a §2.0, que lista cada _placeholder_ visível na Fase 1 (tela,
botão desabilitado ou rota que redireciona) e o item que o ativa.

**Fase 2 (introdutória).** Modelos com variáveis tipadas; formulário público que gera envelope;
envelope com múltiplos documentos; papéis de testemunha, aprovador e visualizador; lembretes
automáticos e envio agendado; assinatura presencial em tablet; assinatura em lote; identidade
visual própria e remetente próprio; código por SMS/WhatsApp e PIN do remetente; captura de selfie e
documento; consulta CNPJ e validação de CPF; **assinatura individual com A1 do participante**; TSA
própria RFC 3161 e exportação de dossiê ZIP; permissões configuráveis, times, tags e relatórios;
**API REST versionada**; webhooks de saída; integração n8n/Zapier/Make; WhatsApp Business;
retenção configurável; pagamentos ampliados; NFS-e.

**Fase 3 (avançada).** Geração documental em lote; detecção de campos por âncoras e OCR;
etapas condicionais, delegação auditada, multilíngue e aceite por vídeo; **certificado A3 via
componente local**; **assinatura gov.br**; **carimbo ICP-Brasil por ACT credenciada e PAdES de
longo prazo**; antifraude com revisão humana; e-Notariado; widget iframe, SDKs, conectores e SSO
OIDC/SAML; programa de afiliados.

**Backlog explicitamente sem implementação** (`docs/roadmap.md` §4), com o motivo de cada um: IA
para cláusulas e resumos; aplicativo móvel e assinatura offline; _liveness_ e comparação facial;
_whitelabel_, domínio próprio e subcontas.

---

## 7. Decisões que dependem de definição jurídica

Existem quatro documentos em `docs/juridico/`. **Todos são minutas geradas a partir da
documentação técnica e trazem, no topo, o aviso de que exigem revisão jurídica antes de qualquer
publicação.** Nenhum deles é aconselhamento jurídico e nenhum pode ir ao ar como está.

| Documento                            | O que é                                                          |
| ------------------------------------ | ---------------------------------------------------------------- |
| `termos-de-uso.md`                   | Minuta dos Termos (10 pontos marcados `[VALIDAR]`).              |
| `politica-de-privacidade.md`         | Minuta da Política de Privacidade (12 pontos `[VALIDAR]`).       |
| `declaracao-de-aceite.md`            | **Textos exatos** exibidos e gravados no aceite (3 `[VALIDAR]`). |
| `aviso-de-privacidade-signatario.md` | Aviso mostrado ao signatário (1 `[VALIDAR]`).                    |

### 7.1 Fatos da empresa que precisam ser preenchidos

As minutas contêm **48 marcadores `{{ }}`** — fatos que só o proprietário tem. Os que travam a
publicação: `{{RAZAO_SOCIAL}}`, `{{CNPJ}}`, `{{ENDERECO}}`, `{{URL_PLATAFORMA}}`,
`{{EMAIL_SUPORTE}}`, `{{NOME_ENCARREGADO}}` e `{{EMAIL_DPO}}` (encarregado de dados pela LGPD),
`{{FORO_CIDADE_UF}}`, `{{PROVEDOR_HOSPEDAGEM}}`, `{{PROVEDOR_EMAIL}}`,
`{{PROVEDOR_ARMAZENAMENTO}}` e `{{PAISES_TRANSFERENCIA}}`.

> **Ponto técnico que vira ponto jurídico:** `{{RAZAO_SOCIAL}}` aparece no texto jurídico exibido
> ao signatário e hoje é preenchido com `config('app.name')` — ou seja, "AssinaVelox", não a razão
> social real. Isso precisa ser corrigido antes de produção. As mesmas informações alimentam
> `ASSINAVELOX_OPERATOR_LEGAL_NAME` e `ASSINAVELOX_OPERATOR_TAX_ID`, impressos no recibo interno.
>
> Confirmado na tela durante o ciclo manual (`docs/testes.md` §6-B): a declaração de aceite trouxe
> a razão social da **organização** remetente corretamente ("Horizonte Negócios Imobiliários Demo
> Ltda."), e a da **operadora** como "a AssinaVelox (AssinaVelox)" — o nome fantasia duas vezes,
> onde deveria estar a razão social com CNPJ. É o texto que o signatário aceita.

### 7.2 Decisões de mérito que a assessoria precisa tomar

1. **Bases legais da LGPD.** A classificação de finalidades, bases legais e papéis
   (controlador × operador) nas minutas é **proposta técnica**, não parecer.
2. **Referências normativas.** Como posicionar o produto diante da Lei 14.063/2020 (níveis simples,
   avançada e qualificada) e da MP 2.200-2/2001. A posição técnica é conservadora: nada é anunciado
   como avançada ou qualificada.
3. **Prazos de retenção**: logs, logs de e-mail, backups, obrigação fiscal, e o que fica após a
   exclusão da conta.
4. **Prazo de resposta a pedidos de titular** (`{{PRAZO_RESPOSTA_DSR}}`).
5. **Transferência internacional de dados** — depende de onde a hospedagem e o e-mail ficarem.
6. **Limitação de responsabilidade** e adequação ao CDC quando o cliente for consumidor —
   marcada nas minutas como cláusula sensível.
7. **Política de reembolso** e prazos de carência/inadimplência (a implementação usa 3 e 15 dias).
8. **SLA**: se haverá compromisso nos planos pagos.
9. **Exclusão de envelopes já enviados**: hoje não é possível excluir individualmente, porque as
   evidências podem ser necessárias. Precisa ser confirmado como política.
10. **Exibição de IP na página de evidências**: o padrão implementado é mascarado. Confirmar.
11. **O que os demais signatários veem** na página de evidências (nome, data, método de
    autenticação dos outros participantes) — e se o aviso ao signatário deve dizer isso.
12. **Versionamento do texto de aceite**: manter um mapa `versão → template` para renderizar
    versões antigas está marcado como `[VALIDAR implementação]` e **não está implementado**.

---

## 8. Decisões que dependem do proprietário

Não são jurídicas nem técnicas: são de produto ou de negócio, e nada avança sem elas.

| Decisão                                                                                              | Por que é bloqueante                                                                                                                                                     |
| ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Contratar o certificado A1** da operadora junto a uma AC da ICP-Brasil                             | Sem ele, todo envelope conclui como aceite eletrônico com evidências — o que é honesto, mas é um produto diferente do que a página de vendas provavelmente vai anunciar. |
| **Abrir a conta do Mercado Pago** (teste e produção) e gerar o segredo de webhook por ambiente       | Sem isso não há como cobrar, e o §4.2 não fecha.                                                                                                                         |
| **Escolher e configurar o serviço de e-mail**, com SPF/DKIM/DMARC no domínio                         | O convite por e-mail é o único canal do signatário na Fase 1.                                                                                                            |
| **Definir os preços reais.** Os planos pagos do seeder são `is_sandbox = true` com valores fictícios | Nada pode ser cobrado com o catálogo atual.                                                                                                                              |
| **Decidir onde ficam os documentos**: disco local em volume cifrado ou S3 privado com SSE            | Determina §4.5 e o desenho do backup.                                                                                                                                    |
| **Definir a política de retenção**                                                                   | Hoje nada é apagado; o disco cresce para sempre.                                                                                                                         |
| **Decidir o que acontece com estorno e contestação**: encerra o plano? devolve cota?                 | Hoje só é gravado.                                                                                                                                                       |
| **Confirmar o rótulo do envelope concluído**                                                         | Já resolvido tecnicamente — "Assinado" só com `signature_status = company_a1`, "Concluído" com `none` —, mas vale confirmação de produto.                                |
| **Autorizar a janela sem assinatura** caso o certificado vença antes da renovação                    | Ver `docs/operacao.md` §6.4: segurar os envelopes ou concluir como aceite eletrônico com evidências. Decisão do proprietário, tomada antes do incidente.                 |

---

## 9. Critério de "pronto para produção"

A Fase 1 está **funcionalmente completa** e **não está pronta para produção**. Estes são os itens
que faltam, e nenhum deles é opcional:

- [x] Verificações do §3.1 refeitas no commit de fechamento, com `pint --test` passando.
- [x] Esquema e ciclo completo exercitados contra **MySQL real** (`docs/testes.md` §6-B).
- [ ] Cenários de **concorrência** (aceite simultâneo, envio, numeração de envelopes) repetidos
      contra MySQL real — `lockForUpdate` não faz nada no SQLite da suíte, e o percurso manual do
      §6-B foi de um usuário só.
- [ ] Servidor implantado conforme `docs/implantacao.md`, com o checklist §16 completo.
- [x] `retry_after` maior que o maior timeout de job — padrão corrigido para 900 s e guardado por
      `tests/Feature/Hardening/QueueTimeoutsTest.php`.
- [ ] Um DOCX convertido pelo `soffice` de verdade (§4.4).
- [ ] Um convite de e-mail entregue de verdade, com SPF/DKIM/DMARC verificados (§4.3).
- [ ] Um webhook real do Mercado Pago autenticado e processado, em sandbox e em produção (§4.2).
- [ ] Um envelope assinado com **A1 de produção** e conferido em verificador independente (§4.1).
- [ ] Criptografia em repouso configurada e **verificada ou atestada** (§4.5).
- [ ] Backup rodando **e um ensaio de restauração concluído** com o script de coerência em exit 0
      (`docs/implantacao.md` §14.4).
- [ ] Minutas jurídicas revisadas por assessoria, com os `{{ }}` preenchidos e os `[VALIDAR]`
      decididos (§7).
- [ ] Preços reais no catálogo, no lugar dos planos `is_sandbox` (§8).

Enquanto o item do A1 de produção não fechar, o produto pode ser vendido — mas **só** como
"aceite eletrônico com evidências, com assinatura criptográfica da empresa operadora quando
configurada". Não como assinatura ICP-Brasil do signatário, não com promessa de validade universal.
Essa fronteira é a mesma no código, na interface e no material comercial.

---

## 10. Revisão final da Fase 1

Uma revisão adversarial percorreu a Fase 1 inteira sob três lentes — segurança de ponta a ponta,
integridade de domínio/concorrência/recuperação e semântica/produto/experiência — e produziu 15
achados, cada um acompanhado de um teste que falhava. Esta seção registra o que foi corrigido, o
que mudou de forma e o que ficou de fora, com o motivo.

Todos os 15 achados foram corrigidos. Nenhum ficou sem correção.

### 10.1 Segurança de ponta a ponta

**A organização corrente vazava de um job para o processo do worker** (alta).
`ResendPendingInvitations::handle()` chamava `CurrentOrganization::instance()->set()` e nunca
restaurava o contexto — nem no caminho feliz, nem no `return` antecipado, nem em exceção. Como o
worker só descarta instâncias `scoped()`, o processo ficava carimbado com aquela organização e o
job seguinte, de outro inquilino, rodava com o escopo global apontando para o anterior.
Correção em três camadas: o job passou a usar `runAs()`, que restaura em `finally`;
`CurrentOrganization` foi registrado com `scoped()` em vez de `singleton()`, de modo que o worker o
descarte entre jobs; e `AppServiceProvider::configureQueueIsolation()` guarda e devolve o contexto
em `Queue::before` / `after` / `exceptionOccurred` — guardar e devolver, e não simplesmente limpar,
porque no driver `sync` (testes e desenvolvimento) o job roda dentro da requisição que o despachou.
Coberto por `tests/Feature/Review/QueueOrganizationContextLeakTest.php`.

**Código do segundo fator e token de convite em claro no armazenamento da fila** (alta).
`SignerOtpNotification`, `RecipientInvitationNotification` e `MembershipInvitationNotification`
passaram a implementar `Illuminate\Contracts\Queue\ShouldBeEncrypted`: o payload do job vai cifrado
com a APP_KEY para `jobs`, para `failed_jobs` e para o painel do Horizon. E `routes/console.php`
passou a agendar `queue:prune-failed --hours=168` e `queue:prune-batches --hours=168` — sem poda, o
registro de um job falho fica em disco indefinidamente, muito depois de o link ter sido revogado e o
desafio ter expirado. Sete dias são tempo de sobra para investigar uma falha de entrega, que também
fica registrada, sem segredo, em `delivery_attempts`.
Coberto por `tests/Feature/Review/QueuePayloadSecretsAtRestTest.php`. O caso de controle
`tests/Feature/Review/InvitationsAbuseTest.php` — que afirmava o defeito ("o token bruto é gravado
em claro") — foi invertido para afirmar a garantia.

**Injeção de Markdown no e-mail de convite** (média). "Mensagem para os signatários" e o título do
documento são texto livre do remetente, interpolados em `MailMessage::line()`. O Blade escapava o
HTML, mas a sintaxe Markdown continuava ativa: `[texto](url)` virava âncora de verdade, dentro de um
e-mail que sai do domínio da plataforma, com o SPF/DKIM/DMARC dela, no mesmo parágrafo do botão
legítimo. Qualquer conta — inclusive uma Grátis recém-criada — podia usar a reputação de envio da
AssinaVelox para entregar phishing. Criado `App\Support\MailText::escape()`, que põe contrabarra nos
caracteres que abrem marcação inline (contrabarra, colchetes, crase, asterisco e sublinhado),
aplicado a **todo** texto de terceiro que entra em linha de e-mail: título, mensagem, nome da
organização, nome de quem enviou, nome do signatário e motivo de recusa/cancelamento — nas sete
notificações. Parênteses ficaram de fora de propósito: sozinhos não formam link e são comuns em
português; escapá-los sujaria a versão em texto puro sem ganho. O assunto não passa por lá porque
assunto é texto puro, sem Markdown.
Coberto por `tests/Feature/Review/InvitationEmailMarkdownInjectionTest.php`.

### 10.2 Integridade de domínio, concorrência e recuperação

**`DocumentIntake::remove()` sem guarda de estado** (crítica). O serviço ia direto para a remoção
sem olhar o status do envelope; a única barreira era uma checagem de controller sobre o model do
route binding, fora de transação e sem lock. Uma chamada sobre um envelope `in_progress` ou
`completed` destruía, em cascata, os aceites eletrônicos, a versão congelada apresentada aos
signatários e o PDF final — banco e bytes — e devolvia sucesso. `remove()` passou a recusar de
imediato fora de `draft|preparing|ready` e, dentro da transação, a recarregar o envelope sob
`lockForUpdate()` (o mesmo que `PreparationGuard` faz em `FieldSync` e `RecipientSync`, com a recusa
no vocabulário de erro do passo 1 do wizard). O ramo de substituição de `store()` ganhou a mesma
trava sob lock. O controller passou a tratar a recusa como resposta de tela, não como erro 500.
Coberto por `tests/Feature/Review/DocumentIntakeSentEnvelopeGuardTest.php`.

**Cancelamento e recusa revogavam o link de quem já tinha assinado** (alta). A regra já existia na
expiração ("quem já assinou mantém o link: é por ele que a pessoa chega ao próprio comprovante"), mas
`CancelEnvelope` revogava tudo e `RecordRefusal` preservava apenas o link de quem recusou. Em um
envelope paralelo, bastava o remetente cancelar para o link de quem já tinha assinado virar 404: o
aceite continuava no banco como evidência contra a pessoa, e o acesso dela à prova sumia. Os três
encerramentos passaram a preservar os `signed`.
Nota de divergência: o achado sugeria unificar a revogação em `AccessLinks::revokeForEnvelope()`
também na recusa. Mantivemos a consulta direta em `RecordRefusal` porque `App\Services\Signing` não
depende de `App\Services\Envelopes\Sending` em lugar nenhum — a ponte entre os módulos é o contrato
`SignerNotifications`, e criar um acoplamento novo custaria mais do que a duplicação de uma cláusula.
Coberto por `tests/Feature/Review/SignedRecipientLinkSurvivesClosureTest.php`.

**`signing_order` mudava sem recalcular `order_index`** (alta). O autosave do passo 1 gravava
`envelopes.signing_order` sozinho, e quem grava `recipients.order_index` é o `RecipientSync` do passo 2. O resultado era um envelope `sequential` com todos os signatários na mesma vez: o dispatcher
convidava todo mundo de uma vez, qualquer um podia assinar a qualquer momento e as telas continuavam
anunciando "Assinatura em ordem". O inverso também quebrava: `parallel` com vezes 1..N faria o
dispatcher convidar só o primeiro e ninguém mais. Três correções: `RecipientSync::reindex()`
reaplica as vezes a partir da ordem gravada (mesma regra do sync, sob `PreparationGuard`) e é chamado
pelo `PATCH` sempre que `signing_order` vem no corpo; a coerência entrou na **definição única** de
completude (`Documents\EnvelopeReadiness::signingOrderIsCoherent()`), que grava o status e alimenta a
lista de pendências do passo 4; e, como `SendEnvelope::commitSend()` já relê as pendências sob lock,
o envio passa a parar sozinho diante de uma incoerência.
Coberto por `tests/Feature/Review/SigningOrderCoherenceTest.php`.

**Ledger de cota creditava ao ciclo errado** (média). `PlanLedger::release()` decrementava
`envelopes_used` sem olhar a qual ciclo o consumo pertencia, enquanto a renovação zera esse contador.
Cancelar, já no ciclo novo, um envelope enviado no ciclo anterior devolvia cota que aquele ciclo
nunca gastou — repetível a cada virada. A liberação passou a comparar `committed_at`/`reserved_at`
com `subscriptions.current_period_start` e só creditar o ciclo vigente. A reserva não tem o problema:
`envelopes_reserved` é recontado a partir do ledger na renovação, então o que está em trânsito
continua no contador vigente qualquer que seja o ciclo em que nasceu.
Coberto por `tests/Feature/Review/QuotaCycleAttributionTest.php`.

### 10.3 Semântica, produto e experiência

**A exclusão da organização era só uma data guardada** (crítica). A tela prometia "Remove todos os
usuários e documentos após 30 dias" e a política de privacidade publicada promete ao titular que "os
dados são apagados dos sistemas ativos" — e nenhum comando, job ou agendamento lia
`deletion_requested_at`. Implementados `App\Services\Organizations\OrganizationPurge` e o comando
`organizations:purge`, agendados em `routes/console.php`. A purga apaga, em ordem de integridade (as
FKs para `organizations` são RESTRICT de propósito), tudo o que é da organização — envelopes,
documentos e versões, campos, destinatários, convites, sessões, desafios, aceites, tentativas de
entrega, registros de verificação, pastas, membros, referências de certificado, consumo, pagamentos e
a assinatura — e a própria organização, em `forceDelete`. Os bytes saem por dois caminhos: o
diretório `orgs/{ulid}` do disco privado (layout de produção) e cada `storage_path` registrado,
colhido antes do commit. Usuários: só é apagado quem fica sem nenhuma organização e não é autor de
envelope sobrevivente nem administrador da plataforma.

Duas decisões explícitas, que antes não existiam:

- **trilha de auditoria**: `audit_events` da organização é eliminado junto. Mantê-la depois de apagar
  os documentos guardaria nome, e-mail e IP de signatários sem o objeto que os justificava, contra a
  promessa feita ao titular. O que permanece são os `audit_checkpoints`, que são resumos encadeados
  da plataforma inteira, e o **recibo de exclusão** — linha de log estruturado `organization.purged`
  com ULID, momento do pedido, momento da execução e contagens, sem dado pessoal;
- **agendamento em primeiro plano**: é a única rotina que apaga em definitivo, e roda no processo do
  `schedule:run` (sem `runInBackground()`) para que uma falha apareça na saída do cron.

Coberto por `tests/Feature/Review/OrganizationDeletionNeverExecutesTest.php` e por
`tests/Feature/Organizations/OrganizationPurgeTest.php`, que exercita a purga com envelope enviado,
aceite gravado e arquivo em disco.

**A tela Assinaturas ignorava `evidence_show_ip`** (alta). Era a quarta tela a exibir o IP do
signatário e a única fora do `IpDisplay`: mostrava o endereço inteiro mesmo com a organização em
`masked` (o padrão) ou `none`. `RecipientListItemResource` passou a usar `IpDisplay::for()` e, no modo
`none`, a omitir o trecho "IP …" por inteiro — esconder o dado e manter o rótulo seria dizer que ele
está ali.
Coberto por `tests/Feature/Review/RecipientsIndexIpMaskTest.php`.

**A home afirmava que todo documento concluído é assinado com o certificado A1** (alta). O card foi
reescrito para o vocabulário que o produto entrega: "Evidências e verificação pública — o documento
concluído recebe página de evidências e código de verificação pública. Quando há certificado A1 da
operadora ativo, o arquivo final também recebe a assinatura criptográfica."
Coberto por `tests/Feature/Review/HomeCertificatePromiseTest.php`.

**Os planos vendiam "Assinatura criptográfica da operadora" e nada lia a flag** (alta). Escolhida a
semântica (b) do achado — o item é do plano — e implementada nas duas pontas. Criado
`App\Services\Plans\PlanFeatures`: `isOffered()` responde se há certificado ativo na instalação e
governa o **anúncio** (sem certificado, nenhum plano lista o item, em nenhuma tela);
`allows()`/`allowsForEnvelope()` respondem se o plano vigente inclui o item e governam a **execução** —
`EnvelopeFinalizer::signsFor()` exige certificado **e** plano antes de assinar, e a mesma decisão vale
para o que a página de evidências afirma, para o arquivo final e para o reaproveitamento de um `final`
de execução anterior. O rótulo duplicado `company_a1` saiu do mapa do `PlanController`, que era um
caminho paralelo capaz de anunciar o item sem passar pela regra.
Efeito colateral assumido: uma organização no plano Grátis não recebe a assinatura da operadora nem
quando há certificado configurado — que é exatamente o que a tabela de preços diz. Os testes de
finalização que medem o certificado passaram a ligar a flag no plano vigente da organização
(`finalizationEnableCompanySignature()`), para medirem o certificado e não a política de plano.
Coberto por `tests/Feature/Review/PlanCompanySignatureFeatureTest.php`.

**"Certificado de conclusão"** (média). As duas telas que usavam a expressão passaram a dizer
"relatório de evidências", que é o nome do artefato em todo o resto do produto — e o que a página de
evidências e a verificação pública passam o tempo todo negando ser certificado.
Coberto por `tests/Feature/Review/CompletionCertificateWordingTest.php`.

**Quatro dos sete interruptores de Notificações não ligavam nada** (média). Os quatro ganharam
produtor real:

- `recipient_signed` — `RecipientSignedNotification`, emitida por `EnvelopeNotifications` pelo
  contrato `SignerNotifications` (método novo `notifySenderSigned`), chamada por `RecordAcceptance`
  depois da transação, como o convite do próximo;
- `invitation_accepted` — `InvitationAcceptedNotification`, emitida por `Invitations::accept()` no
  primeiro aceite do convite, para proprietários e administradores ativos (quem pode remover o acesso
  é quem precisa saber que ele existe); o próprio recém-chegado não recebe aviso de si mesmo;
- `daily_digest` — `App\Services\Organizations\DailyDigest` mais o comando
  `notifications:daily-digest`, agendado às 08:00 (America/Sao_Paulo), que é o horário que o rodapé da
  tela promete. Um e-mail por dia útil, só quando há pendência, respeitando a visibilidade por papel,
  com idempotência por dia em `memberships.daily_digest_sent_on` (coluna nova; não cabia em
  `notification_preferences`, que é reescrita inteira quando o usuário salva a tela);
- `product_news` — `ProductNewsNotification` mais o comando `notifications:product-news`. É o único
  evento do catálogo que não pode ter gatilho automático: um lançamento é decisão editorial. O canal
  `mail` continua travado no catálogo, porque novidade de produto não é mensagem transacional e a
  Fase 1 não coleta consentimento de marketing.

Coberto por `tests/Feature/Review/NotificationCatalogHasProducerTest.php` e
`tests/Feature/SettingsOrg/NotificationProducersTest.php`.

**O signatário sem conta caía na tela de erro do painel** (média). `errors/404.tsx` e `errors/403.tsx`
passaram a olhar `auth.user`: sem usuário autenticado, as ações são "Verificar documento" e "Página
inicial", nunca `/dashboard` — que só levava ao login, onde o signatário não tem credenciais. E o
middleware `EnsureSignerVerified` passou a cumprir o que o docblock prometia, na medida em que é
possível cumpri-lo: a **navegação de primeiro nível** (`Sec-Fetch-Mode: navigate` +
`Sec-Fetch-Dest: document`, que é o que o navegador envia quando a pessoa volta à aba aberta no
endereço do PDF) volta para `sign.show` com o aviso de sessão expirada; todo o resto continua em 404,
que é o comportamento seguro e o padrão para clientes que não mandam esses cabeçalhos.
Coberto por `tests/Feature/Review/SignerErrorScreenLeadsToDashboardTest.php`.

**Aceite gravado sem evidência de que o documento foi apresentado** (média). A declaração afirma "Li
integralmente o documento […], cujo conteúdo apresentado nesta tela corresponde ao resumo SHA-256 …"
e nada no fluxo conferia que os bytes tinham saído do servidor. Agora `sign.document` marca
`signing_sessions.document_presented_at` (coluna nova) e grava o evento `document.presented` na
trilha, uma vez por sessão; `RecordAcceptance` exige a marca antes de gravar o aceite; e o botão
"Assinar documento" só habilita com o visualizador em `ready` — com o PDF em erro, a tela orienta
"Tentar de novo"/"Baixar PDF" em vez de deixar a recusa como única saída. A trilha e a página de
evidências usam o mesmo cuidado de linguagem do "não comprova leitura": o evento registra a
**entrega**, não a leitura.
Coberto por `tests/Feature/Review/AcceptanceWithoutDocumentPresentationTest.php`.

### 10.4 Testes ajustados, com o motivo

Quatro casos de reprodução foram reescritos porque, uma vez corrigido o defeito, **contradiziam a si
mesmos ou à própria correção pedida pelo achado**. Cada ajuste está comentado no arquivo, com o
raciocínio:

| Teste                                       | Por que foi ajustado                                                                                                                                                                                                                                                                                                                                       |
| ------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SigningOrderCoherenceTest`                 | O caso "acusa a incoerência" nascia do mesmo `PATCH` do caso anterior. Com a reindexação, a incoerência deixa de existir ali. O caso passou a montá-la por fora do sync (como uma linha antiga faria) e ganhou dois casos novos: a incoerência inversa (`parallel` com vezes distintas) e a reindexação na volta para paralelo.                            |
| `OrganizationDeletionNeverExecutesTest`     | Exigia que `schedule:run` apagasse a organização. `Schedule::command()` roda o comando em **processo separado**, que não enxerga o SQLite `:memory:` do teste — a asserção nunca poderia passar, corrigido ou não o defeito. Passou a verificar as duas metades reais: o comando está agendado e o comando apaga.                                          |
| `SignerErrorScreenLeadsToDashboardTest`     | Um caso exigia 404 e outro exigia redirecionamento para o **mesmo** GET de `sign.document` — que é a rota que transmite bytes, e cujo 404 o próprio achado considera correto. O caso passou a exercitar a navegação de primeiro nível (com `Sec-Fetch-*`) e a confirmar que o resto continua 404.                                                          |
| `AcceptanceWithoutDocumentPresentationTest` | Exigia, ao mesmo tempo, que o aceite fosse gravado **sem** nenhum GET no documento e que a trilha trouxesse o evento de apresentação — um evento afirmando que os bytes saíram quando não saíram seria a própria mentira denunciada. Virou dois casos: sem apresentação o aceite é recusado; com apresentação ele é gravado e a trilha registra a entrega. |

Além desses, `InvitationsAbuseTest` teve o caso de controle invertido (ver §10.1) e os dublês de
`SignerNotifications` nos testes do signatário ganharam o método novo do contrato.

### 10.5 O que a revisão deixou registrado e não corrigiu

- **`docs/design/DESIGN_SYSTEM.md` continua dizendo "certificado de conclusão"** (§Configurações e a
  tabela de API do §"enviar"). A interface foi corrigida; a fonte de design não, porque
  `docs/design/*.md` está fora da área de edição desta rodada. Fica registrado aqui para a próxima
  passagem: a expressão contraria `docs/arquitetura.md` §2, que tem precedência pela ordem de
  `RECONCILIACAO.md`.
- **`evidence_show_ip` continua sem controle na interface.** A política existe no esquema, tem padrão
  `masked` e agora é respeitada nas quatro telas do remetente — mas a organização ainda não consegue
  apertá-la ou afrouxá-la sozinha. É trabalho de tela em Configurações › Geral e segurança, não
  correção de defeito, e foi deixado para a próxima rodada.
- **Três casos de revisão continuam vermelhos e não pertencem a esta rodada de correção**:
  `DashboardDurationFormatTest`, `DocumentPreviewRateLimitTest` e `WizardAbandonedDraftTest`. Já
  falhavam antes desta rodada, tratam de achados que não estavam na lista trabalhada aqui, e
  `DocumentPreviewRateLimitTest` contém asserções mutuamente exclusivas — exige que as 90 requisições
  devolvam **só** 200 e que a lista **contenha** 429. Precisam de uma decisão própria.

### 10.6 Verificações da revisão final

Executadas nesta máquina em **2026-09-09**, depois de todas as correções acima:

| Verificação                    | Comando                                        | Resultado                                                                                                                                            |
| ------------------------------ | ---------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Suíte Pest**                 | `php artisan test`                             | **930 testes, 921 passaram, 6 falharam, 3 pulados, 6 969 asserções**, 628,5 s — as 6 falhas são as três telas de revisão do §10.5, fora desta rodada |
| **Suíte do pdftool (Python)**  | `.venv/Scripts/python.exe -m pytest -q`        | **93 testes, 93 passaram**, 9,59 s                                                                                                                   |
| **Suíte de navegador**         | `php artisan test --testsuite=Browser`         | **39 testes, 36 passaram, 3 pulados, 740 asserções**, 47,2 s                                                                                         |
| **Estilo PHP**                 | `vendor/bin/pint --test`                       | **passou**                                                                                                                                           |
| **Análise estática (nível 7)** | `vendor/bin/phpstan analyse --memory-limit=1G` | **0 erros**                                                                                                                                          |
| **Tipos do front**             | `npm run types:check`                          | **0 erros**                                                                                                                                          |
| **Formatação e lint do front** | `npm run check`                                | **181 arquivos formatados, 0 avisos em 145**                                                                                                         |
| **Build de produção**          | `npm run build`                                | **sucesso**, 17,06 s                                                                                                                                 |

### 10.7 Migrations novas desta rodada

Duas, ambas aditivas e anuláveis — nenhuma reescreve dado existente:

| Migration                                                         | O que acrescenta                                                                                                    |
| ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `2026_09_11_100001_add_daily_digest_sent_on_to_memberships`       | `memberships.daily_digest_sent_on` (date, nulo) — idempotência do resumo diário, um envio por pessoa por dia local. |
| `2026_09_11_100002_add_document_presented_at_to_signing_sessions` | `signing_sessions.document_presented_at` (timestamp, nulo) — momento em que os bytes foram entregues àquela sessão. |

Sessões e memberships já existentes ficam com a coluna nula: nenhuma sessão antiga é reaproveitada
(30 min de validade) e o resumo diário simplesmente considera que ninguém recebeu nada ainda.
