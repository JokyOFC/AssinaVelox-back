# AssinaVelox

AssinaVelox é uma plataforma web brasileira de **preparo de documentos e aceite eletrônico com evidências**. A organização envia um PDF (ou um DOCX/imagem que a plataforma converte), posiciona os campos sobre as páginas, convida os signatários por e-mail e acompanha o processo; cada signatário abre um link público, confirma um código enviado ao seu e-mail, revisa o documento e registra o aceite. Ao final, a plataforma consolida o documento com os campos preenchidos, anexa uma **página de evidências** (linha do tempo, identificadores, hashes) e publica um **código de verificação pública** onde qualquer pessoa confere o arquivo. Quando há um certificado A1 configurado, o arquivo final recebe ainda uma **assinatura criptográfica da empresa operadora** no perfil **PAdES B-B** — sem carimbo do tempo e sem informação de validação de longo prazo.

**O que este produto não afirma.** Representação visual, aceite eletrônico, assinatura criptográfica da operadora e assinatura pessoal ICP-Brasil são quatro coisas diferentes, e a interface usa esse vocabulário exato (`docs/arquitetura.md` §2). Sem certificado configurado, o envelope conclui como _aceite eletrônico com evidências_ e a tela diz isso. A assinatura criptográfica, quando existe, é **da operadora**, não do signatário. Não há assinatura pessoal ICP-Brasil, não há carimbo do tempo de terceiro e **não há promessa de validade universal**: o que a plataforma entrega é um conjunto de evidências verificável, cuja força probatória depende do caso concreto.

---

## 1. Stack

| Camada        | Tecnologia                                                                   |
| ------------- | ---------------------------------------------------------------------------- |
| Backend       | PHP 8.3, Laravel 13, Fortify (autenticação), Horizon (filas em produção)     |
| Frontend      | Inertia 3 + React 19 + TypeScript, Tailwind 4, shadcn/ui, Vite 8 (vite-plus) |
| Rotas tipadas | Laravel Wayfinder (helpers TS gerados a partir de `routes/*.php`)            |
| Banco         | MySQL 8+ (produção e desenvolvimento); SQLite em memória nos testes          |
| PDF           | `tools/pdftool` (Python: pypdf, reportlab, pyHanko, Pillow) + DOMPDF         |
| DOCX → PDF    | LibreOffice headless (opcional; ausente ⇒ conversão de DOCX indisponível)    |
| Pagamentos    | Mercado Pago Checkout Pro (`mercadopago/dx-php`)                             |
| Testes        | Pest 4.7 (+ plugin de navegador com Playwright/Chromium), PHPStan 7, Pint    |

Sem Docker, por decisão do proprietário.

---

## 2. Requisitos

### Obrigatórios

| Item     | Versão   | Observação                                                                                                                 |
| -------- | -------- | -------------------------------------------------------------------------------------------------------------------------- |
| PHP      | 8.3+     | Com as extensões da tabela abaixo.                                                                                         |
| Composer | 2.x      | —                                                                                                                          |
| Node.js  | 22 LTS   | `vite-plus` exige `^20.19` ‖ `^22.18` ‖ `>=24.11`; Vite 8, `^20.19` ‖ `>=22.12`. Verificado em v22.22.2.                   |
| MySQL    | 8.0+     | Banco da aplicação. `utf8mb4` / `utf8mb4_unicode_ci`.                                                                      |
| Python   | **3.13** | Para `tools/pdftool`. O `requirements.lock.txt` foi fixado e verificado em 3.13.14; versões anteriores não foram testadas. |

**Extensões de PHP.** Todas presentes em uma instalação típica, mas confira: `intl` (formatação de datas e de moeda em pt-BR — sem ela a aplicação quebra), `gd` (normalização das imagens de assinatura e conversão imagem→PDF), `fileinfo` (validação de upload pelo conteúdo), `zip` (inspeção do pacote DOCX), `openssl`, `mbstring`, `curl`, `dom` + `xml` + `simplexml` (DOMPDF), `pdo_mysql`, `tokenizer`, `ctype`, `json`, `bcmath`. Em Linux, Horizon exige ainda `pcntl` e `posix`. Para rodar os **testes de navegador** é preciso `sockets` (exigência do `pestphp/pest-plugin-browser`); para filas em Redis, `redis` (phpredis).

Confira com `php -m`.

### Opcionais / por ambiente

| Item                     | Quando                                                                                                                                          |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| **LibreOffice** headless | Necessário para aceitar **DOCX**. Sem ele, `LIBREOFFICE_BIN` fica vazio, o conversor responde "não configurado" e só PDF e imagens são aceitos. |
| **Redis**                | **Só em produção** (fila + Horizon + cache). Em desenvolvimento, fila e cache usam o driver `database`.                                         |
| **Certificado A1**       | Opcional. Sem ele o envelope conclui como aceite eletrônico com evidências (`signature_status = none`).                                         |
| Playwright + Chromium    | Só para a suíte `Browser` (`npx playwright install chromium`).                                                                                  |

---

## 3. Ambiente local do zero

Reproduzível em uma máquina limpa. Os caminhos usam barra normal e funcionam em Git Bash no Windows e em qualquer shell POSIX.

```bash
# 1. Código e dependências
git clone <url-do-repositorio> assinavelox
cd assinavelox
composer install
npm install

# 2. Ambiente
cp .env.example .env
php artisan key:generate
```

**3. Banco.** Crie o banco e ajuste `DB_*` no `.env`:

```sql
CREATE DATABASE assinavelox CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan migrate --seed
```

O `--seed` roda `PlanSeeder` (sempre) e, em `local`/`testing`, `PlatformAdminSeeder` e `DemoOrganizationSeeder`, que criam contas de demonstração — todas com a senha `password`:

| Conta                     | Papel                                                               |
| ------------------------- | ------------------------------------------------------------------- |
| `admin@assinavelox.local` | administrador da plataforma (`/admin`), sem organização             |
| `owner@horizonte.demo`    | dono de "Imobiliária Horizonte Demo" (plano Profissional _sandbox_) |
| `admin@horizonte.demo`    | administrador da mesma organização                                  |
| `operador@horizonte.demo` | membro da mesma organização                                         |
| `owner@vega.demo`         | dono de "Consultoria Vega Demo" (plano Grátis)                      |

Se preferir não tocar no MySQL, use um SQLite próprio:

```bash
touch database/dev.sqlite
DB_CONNECTION=sqlite DB_DATABASE=database/dev.sqlite php artisan migrate:fresh --seed
```

**4. pdftool (Python).** Necessário para toda a manipulação de PDF (composição, assinatura, validação, imagem→PDF):

```bash
cd tools/pdftool
python -m venv .venv
# Windows
.venv/Scripts/python.exe -m pip install -r requirements.lock.txt
# Linux/macOS
.venv/bin/python  -m pip install -r requirements.lock.txt
cd ../..
php artisan pdftool:selftest      # deve terminar com exit code 0
```

`php artisan pdftool:selftest` roda o autoteste ponta a ponta do pdftool e mostra o estado dos adaptadores (conversores por tipo, LibreOffice, certificado A1, raízes de confiança). Ele **nunca imprime valores de variáveis de ambiente**. Com `--json`, serve para monitoramento.

**5. LibreOffice (opcional).** Só se quiser aceitar DOCX. Instale e aponte:

```dotenv
# Linux
LIBREOFFICE_BIN=/usr/bin/soffice
# Windows
LIBREOFFICE_BIN=C:\Program Files\LibreOffice\program\soffice.exe
```

**6. Certificado de teste (opcional).** Para exercitar a assinatura da operadora localmente — o certificado gerado é **autoassinado, tem `TESTE` no CN e nunca pode ser apresentado como ICP-Brasil**:

```bash
export COMPANY_CERT_PASSWORD='senha-forte-local'      # PowerShell: $env:COMPANY_CERT_PASSWORD='...'
cd tools/pdftool
.venv/Scripts/python.exe -m pdftool gen-test-cert \
    --out-pfx ../../storage/app/private/certs/teste.pfx --pass-env COMPANY_CERT_PASSWORD \
    --out-pem ../../storage/app/private/certs/teste.pem --days 365
```

```dotenv
COMPANY_CERT_ENABLED=true
COMPANY_CERT_PFX_PATH=/caminho/absoluto/storage/app/private/certs/teste.pfx
COMPANY_CERT_PASSWORD_ENV=COMPANY_CERT_PASSWORD       # o NOME da variável, nunca a senha
COMPANY_CERT_ENVIRONMENT=test
PDFTOOL_TRUST_ROOTS=/caminho/absoluto/storage/app/private/certs/teste.pem
```

**7. Testes de navegador (opcional).**

```bash
npx playwright install chromium
```

**8. Subir.**

```bash
php artisan serve                 # http://localhost:8000
npm run dev                       # Vite com HMR, em outro terminal
php artisan queue:work --queue=default,conversions,notifications,finalization,billing
```

Sem um _worker_ rodando, o upload de DOCX/imagem fica em "processando" e a finalização do envelope não avança — as duas etapas são assíncronas.

---

## 4. Comandos do dia a dia

```bash
# Servidor e front
php artisan serve                              # http://localhost:8000
npm run dev                                    # Vite (HMR)
npm run build                                  # build de produção em public/build
composer run dev                               # = php artisan dev (agrupa os processos de desenvolvimento;
                                               #   confira se a fila está incluída na sua versão)

# Fila (driver `database` em desenvolvimento; Horizon em produção)
php artisan queue:work --queue=default,conversions,notifications,finalization,billing
php artisan queue:failed                       # jobs que falharam
php artisan queue:retry all

# Agendador (em produção, cron de minuto em minuto — ver docs/implantacao.md)
php artisan schedule:run
php artisan schedule:list
php artisan envelopes:expire                   # a cada 15 min
php artisan envelopes:notify-expiring          # de hora em hora
php artisan billing:dunning                    # diário, 03:20

# Diagnóstico
php artisan assinavelox:doctor                 # 25 verificações da instalação; nunca imprime segredos
php artisan assinavelox:health                 # indicadores de fila, conversão, assinatura, e-mail, pagamento
php artisan storage:verify                     # disco de documentos: gravável, privado, cifrado em repouso
php artisan audit:checkpoint --verify          # confere os checkpoints da trilha de auditoria
php artisan pdftool:selftest                   # pipeline de PDF; --json para monitoramento
php artisan route:list --except-vendor
php artisan about

# Rotas tipadas (rodar depois de alterar routes/*.php)
php artisan wayfinder:generate --with-form

# Qualidade
php artisan test                               # suíte Pest completa (Unit + Feature + Browser)
php artisan test --filter=NomeDoTeste
php artisan test tests/Feature/Sending
composer test:browser                          # só a suíte de navegador (Playwright + Chromium)
composer test:pdftool                          # só a suíte Python do pdftool
composer test:all                              # Pest + pdftool
vendor/bin/pint --dirty                        # estilo PHP (apenas o que mudou)
vendor/bin/phpstan analyse --memory-limit=1G   # análise estática, nível 7
npm run types:check                            # tsc --noEmit
npm run check                                  # formatação + lint do front
composer run ci:check                          # tudo de uma vez

# pdftool (Python)
cd tools/pdftool && ./.venv/Scripts/python.exe -m pytest -q     # Windows
cd tools/pdftool && ./.venv/bin/python -m pytest -q             # Linux
```

Os testes usam SQLite em memória (`phpunit.xml`), fila `sync` e mailer `array` — não tocam no MySQL do `.env`.

---

## 5. Estrutura de pastas

```
app/
  Actions/          ações do Fortify (registro, senha, 2FA)
  Console/Commands/ envelopes:expire, envelopes:notify-expiring, billing:dunning, pdftool:selftest
  Enums/            estados e vocabulários do domínio (EnvelopeStatus, SignatureStatus, DeliveryStatus…)
  Http/             Controllers, FormRequests, Middleware (isolamento por organização, CSP, signatário)
  Integrations/     adaptadores externos com contrato + dublê: Email, Payments, Pdf
  Jobs/             ProcessDocumentUpload, FinalizeEnvelope, SyncMercadoPagoPayment…
  Models/           Eloquent (Organization, Envelope, Document, Recipient, VerificationRecord…)
  Notifications/    e-mails transacionais em PT-BR + canal que registra a tentativa de entrega
  Policies/         autorização por papel dentro da organização
  Services/         regras de negócio: Documents, Envelopes, Signing, Verification, Billing, Pdf
config/
  assinavelox.php   configuração do domínio (prazos, OTP, upload, evidências, cobrança)
  pdftool.php       Python, LibreOffice, certificado A1, raízes de confiança
database/
  migrations/       esquema; seeders/ planos + dados de demonstração (local/testing)
docs/               documentação do produto (índice abaixo)
lang/pt_BR/         traduções de validação, autenticação e paginação
resources/
  js/pages/         páginas Inertia (envelopes, sign, verify, settings, admin…)
  js/components/    componentes compartilhados e shadcn/ui
  views/            Blade mínimo: casca do Inertia e a página de evidências (DOMPDF)
routes/             web.php, settings.php, console.php (agendamento)
storage/app/
  documents/        disco privado dos documentos (DOCUMENTS_DISK=local)
  private/          arquivos privados diversos; certs/ para o PFX em desenvolvimento
  tmp/pdftool/      diretórios temporários exclusivos por operação de PDF
tests/
  Feature/          rotas, políticas, isolamento, fluxo público, cobrança, finalização
  Unit/             enums, geometria, hashes, máquinas de estado
  Browser/          Pest + Playwright
tools/pdftool/      CLI Python de PDF (inspect, compose, append, sign, validate, cert-info)
```

---

## 6. Documentação

### Produto e arquitetura

| Documento                                                              | Assunto                                                                                   |
| ---------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| [`docs/arquitetura.md`](docs/arquitetura.md)                           | Decisões de stack, modelo de domínio e a **semântica obrigatória de assinatura** (§2).    |
| [`docs/banco-de-dados.md`](docs/banco-de-dados.md)                     | Tabelas, chaves estrangeiras, `audit_events` append-only, privilégios MySQL recomendados. |
| [`docs/autorizacao-e-isolamento.md`](docs/autorizacao-e-isolamento.md) | Papéis, escopo por organização, _route model binding_ escopado, limitadores.              |
| [`docs/configuracao.md`](docs/configuracao.md)                         | Variáveis de ambiente, filas, disco privado, sessão, checklist de segurança.              |
| [`docs/frontend.md`](docs/frontend.md)                                 | Inertia + React, PDF.js, design system, convenções das páginas.                           |

### Pipeline documental

| Documento                                                              | Assunto                                                                                        |
| ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| [`docs/pdf-pipeline.md`](docs/pdf-pipeline.md)                         | Laravel × pdftool × LibreOffice, isolamento do processo, PAdES B-B e o que **não** é afirmado. |
| [`docs/preparacao-documental.md`](docs/preparacao-documental.md)       | Upload validado pelo conteúdo, conversão assíncrona, versões do documento.                     |
| [`docs/campos-e-geometria.md`](docs/campos-e-geometria.md)             | Campos sobre o PDF, coordenadas normalizadas, rubricas automáticas.                            |
| [`docs/envio-e-convites.md`](docs/envio-e-convites.md)                 | Envio, congelamento de versão, reserva de cota, convites, expiração, entrega de e-mail.        |
| [`docs/fluxo-do-signatario.md`](docs/fluxo-do-signatario.md)           | Página pública, código por e-mail, sessão curta, aceite e recusa com evidências.               |
| [`docs/finalizacao-e-evidencias.md`](docs/finalizacao-e-evidencias.md) | Consolidação, página de evidências, assinatura da operadora, hashes, idempotência.             |
| [`docs/verificacao-publica.md`](docs/verificacao-publica.md)           | `/verificar/{code}`, conferência de hash no navegador, linguagem correta por situação.         |

### Negócio, operação e entrega

| Documento                                                              | Assunto                                                                                       |
| ---------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| [`docs/cobranca.md`](docs/cobranca.md)                                 | Checkout Pro, webhook autenticado, consumo do plano, inadimplência, recibo interno.           |
| [`docs/integracoes/mercado-pago.md`](docs/integracoes/mercado-pago.md) | Contrato do provedor, itens confirmados e **não confirmados** na documentação oficial.        |
| [`docs/implantacao.md`](docs/implantacao.md)                           | **Implantação em Linux sem Docker**, segredos, backups e restauração testável.                |
| [`docs/seguranca-operacional.md`](docs/seguranca-operacional.md)       | Cabeçalhos e CSP, limitadores por rota, checkpoint da trilha, isolamento do conversor.        |
| [`docs/testes.md`](docs/testes.md)                                     | As cinco camadas de teste, como rodar cada suíte e o que cada uma **não** cobre.              |
| [`docs/operacao.md`](docs/operacao.md)                                 | **O que monitorar, como diagnosticar, como reprocessar com segurança.**                       |
| [`docs/entrega-fase-1.md`](docs/entrega-fase-1.md)                     | **Documento de entrega:** o que existe, o que não existe, o que foi verificado e o que falta. |
| [`docs/roadmap.md`](docs/roadmap.md)                                   | Fases 2 e 3, placeholders da Fase 1 e o que cada ativação exige; backlog explicitamente fora. |

### Relatórios de integração e minutas jurídicas

| Documento                                                                                              | Assunto                                                                                 |
| ------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------- |
| [`docs/incremento-1-relatorio.md`](docs/incremento-1-relatorio.md)                                     | Base, organizações e isolamento: o que foi integrado, corrigido e o que ficou pendente. |
| [`docs/incremento-2-3-relatorio.md`](docs/incremento-2-3-relatorio.md)                                 | Preparação documental e coleta de aceites.                                              |
| [`docs/incremento-4-5-relatorio.md`](docs/incremento-4-5-relatorio.md)                                 | Finalização, evidências, verificação pública e cobrança.                                |
| [`docs/juridico/termos-de-uso.md`](docs/juridico/termos-de-uso.md)                                     | **Minuta** — exige revisão jurídica antes de qualquer uso.                              |
| [`docs/juridico/politica-de-privacidade.md`](docs/juridico/politica-de-privacidade.md)                 | **Minuta** — exige revisão jurídica.                                                    |
| [`docs/juridico/declaracao-de-aceite.md`](docs/juridico/declaracao-de-aceite.md)                       | **Minuta** — texto do aceite e do rodapé carimbado no documento.                        |
| [`docs/juridico/aviso-de-privacidade-signatario.md`](docs/juridico/aviso-de-privacidade-signatario.md) | **Minuta** — aviso exibido ao signatário.                                               |

### Contratos de design (precedência, do mais forte para o mais fraco)

`docs/design/RECONCILIACAO.md` › `docs/arquitetura.md` › `docs/design/ROUTES_AND_PAGES.md` › `docs/design/DESIGN_SYSTEM.md` › `docs/design/mocks/*.dc.html`.

---

## 7. Convenções

- **Identificadores em inglês; interface, mensagens, e-mails e documentação em PT-BR.**
- Timestamps gravados em UTC; exibidos no fuso da organização (padrão `America/Sao_Paulo`).
- Dinheiro em centavos, com a moeda explícita (`BRL`).
- Segredos nunca vão para o repositório, para o banco, para a fila, para o log nem para argumentos de processo. O que fica em configuração é **referência** (caminho de arquivo, nome de variável de ambiente) — ver `docs/implantacao.md` §5.
- Nenhum adaptador externo é simulado como se fosse real: sem credencial, o adaptador diz que está desabilitado, e a tela diz isso ao usuário.
