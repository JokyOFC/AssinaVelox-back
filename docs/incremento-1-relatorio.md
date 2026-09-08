# Incremento 1 — Base e isolamento: relatório de integração (I1)

> Estado do repositório após a integração dos trabalhos B1 (dados), B2 (organizações/rotas), F1 (front) e A3 (pdftool PHP). Nenhum commit foi feito pelos agentes; o worktree contém todas as alterações.

## 1. O que foi integrado

| Área                        | Conteúdo                                                                                                                                                                                                                                      | Origem |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Dados                       | Migrations, enums, models, factories e seeders (`PlanSeeder`, `PlatformAdminSeeder`, `DemoOrganizationSeeder`) + migration `memberships.notification_preferences`                                                                             | B1, B2 |
| Autenticação e organizações | Fortify (registro cria User + Organization + Membership owner + Subscription free), middlewares `org`, `org.role`, `org.2fa`, `platform-admin`, `SecurityHeaders` (CSP com nonce), `ResetCurrentOrganization`, policies, props compartilhadas | B2     |
| Rotas e controllers         | 95 rotas (nomes exatos de `ROUTES_AND_PAGES.md` §1) — dashboard, documentos (index/show reais; wizard/ações como esqueletos), usuários e convites, configurações, cobrança (leitura), painel interno, páginas públicas                        | B2     |
| Front                       | Design system (tokens, shadcn), layouts (app/auth/settings/signer/public), componentes do shell e todas as páginas do incremento; build de produção em `public/build`                                                                         | F1     |
| PDF                         | Integração PHP do `tools/pdftool` (inspeção, composição, assinatura PAdES B-B, validação), `ImageNormalizer`, `ProcessEnvironment`, `pdftool:selftest`                                                                                        | A3     |
| Integração                  | Wayfinder regenerado (`resources/js/routes`, `resources/js/actions`), correção de tipos, PHPStan a zero, smoke tests de rotas, `.env.example`, formatação (`vp check`), documentação                                                          | I1     |

## 2. Verificações finais

| Comando                                                              | Resultado                                                                                                                                                                                                                                                                                                                                                |
| -------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php -d extension=intl artisan test`                                 | **268 testes, 268 passaram** (2 258 asserções) — inclui `tests/Feature/Smoke/*`                                                                                                                                                                                                                                                                          |
| `vendor/bin/phpstan analyse` (nível 7, Larastan)                     | **0 erros** (eram 88)                                                                                                                                                                                                                                                                                                                                    |
| `vendor/bin/pint --test`                                             | passou (repositório inteiro)                                                                                                                                                                                                                                                                                                                             |
| `npm run types:check` (`tsc --noEmit`)                               | 0 erros                                                                                                                                                                                                                                                                                                                                                  |
| `npm run check` (formatação + lint do vite-plus)                     | passou                                                                                                                                                                                                                                                                                                                                                   |
| `npm run build`                                                      | sucesso (`public/build`, não versionado)                                                                                                                                                                                                                                                                                                                 |
| `php artisan migrate:fresh --seed` (SQLite próprio)                  | ok — 24 migrations, 3 seeders                                                                                                                                                                                                                                                                                                                            |
| `php artisan wayfinder:generate --with-form`                         | 95 rotas → helpers TS; todos os imports `@/routes/**` do front resolvem                                                                                                                                                                                                                                                                                  |
| Navegação real (`php -S` + build, login como `owner@horizonte.demo`) | login, dashboard, documentos, detalhe do documento, usuários, configurações (geral/assinatura/notificações/plano), planos, assinaturas, modelos, API, evidências, criar organização, perfil, segurança, wizard; como `admin@assinavelox.local`: clientes, detalhe do cliente, placeholders. Nenhuma página com erro de runtime após as correções abaixo. |

## 3. Problemas encontrados e corrigidos na integração

1. **Props de página sombreando props compartilhadas (crash em runtime com HTTP 200).** `settings/general`, `envelopes/evidence` e `admin/organizations/show` enviavam uma prop `organization` sem `plan/role/permissions`; o shell (`OrgSwitcher`, `AccountMenu`, `AppSidebar`) quebrava (`Cannot read properties of undefined (reading 'name')`) em **todas** as páginas de Configurações e derivadas. Correções: `HandleInertiaRequests::currentOrganizationProps()` passou a ser público e é **mesclado** na prop `organization` de `settings/general` e `envelopes/evidence`; no painel interno as props foram renomeadas para `customer` (show) e `customers` (index) — ver `docs/frontend.md` › "Props de página × props compartilhadas". `AccountMenu` ficou resiliente (`permissions?.`). O smoke test falha se isso voltar a acontecer.
2. **`sign/show` quebrava com o esqueleto do backend** (`envelope: null`, `recipient: null` na tela `invalid`): props tipadas como anuláveis e renderização antecipada do card "Link inválido".
3. **`members/index`**: helpers Wayfinder tipam `{membership}` como `number` (chave inteira) e a página passava `string` — `Number(member.id)` nas quatro chamadas.
4. **Proxies confiáveis**: `env('TRUSTED_PROXIES')` em `bootstrap/app.php` devolvia `null` com `config:cache` (nenhum proxy confiável em produção → IP errado nos aceites). Movido para `AppServiceProvider::configureTrustedProxies()` lendo `config('assinavelox.trusted_proxies')`.
5. **PHPStan (88 → 0)**: `fopen` sem verificação de `false` nos exports CSV; `random_bytes` com tamanho não garantido; `findOrFail` com tipo ambíguo em `Invitations::accept`; parâmetros sem tipo; `?->` redundante sob `??`; `UserRefResource::ref` ganhou tipo de retorno condicional; `config/horizon.php` (`Str::slug` com `env()` não-string).
6. **`npm run check`** falhava por formatação de `docs/design/**` (mocks e contratos, não editáveis) — excluídos em `vite.config.ts` (`fmt`/`lint.ignorePatterns`); demais `docs/**`, `tests/Fixtures/README.md` e `tools/pdftool/README.md` formatados.
7. **`.env.example`**: chaves do pdftool listadas por A3 (`PDFTOOL_TIMEOUT_SECONDS`, `PDFTOOL_SIGN_TIMEOUT_SECONDS`, `PDFTOOL_TMP_PATH`, `PDFTOOL_TRUST_ROOTS`, `LIBREOFFICE_TIMEOUT_SECONDS`, `COMPANY_CERT_REASON`, `COMPANY_CERT_LOCATION`) com comentários em PT-BR; corrigido o comentário "ausentes → adaptadores fake" (não é exato).
8. `bootstrap/providers.php` registra `App\Integrations\IntegrationsServiceProvider`; `config/assinavelox.php` não duplica chaves de `config/pdftool.php` (conferido).

## 4. Smoke tests (`tests/Feature/Smoke`)

- `AllGetRoutesTest`: percorre **todas** as rotas GET registradas (exceto Horizon, `_inertia/*` e `storage/*`) com parâmetros dos seeders, para cinco papéis (convidado, owner, admin, member, platform admin), e confere o status por grupo de middleware (`public` / `guest` / `account` / `app` / `admin`) com exceções explícitas. Também verifica que nenhuma página sombreia `organization`/`organizations`/`auth`/`counts`/`flash`/`features`. Testes extras: member não vê documentos de terceiros (403); platform admin sem membership vai para `organizations.create`; papéis do seeder.
- `CspAndBuildAssetsTest`: `GET /` e `GET /login` com Vite real — cabeçalhos de segurança, CSP `script-src 'self' 'nonce-…'`, nonce em todas as `<script>` executáveis e assets vindos de `public/build` (pulado quando não há `manifest.json`).

## 5. Pendências reais (Wave B — incrementos 2 a 5)

Rotas que existem com o contrato de props correto mas respondem como esqueleto (`// TODO(Wave B/C)` no código):

- **Documentos**: upload/remoção/status/miniaturas do documento (`EnvelopeDocumentController`), campos (`EnvelopeFieldController`), destinatários (`EnvelopeRecipientController`), envio (`EnvelopeSendController`), download (`EnvelopeDownloadController` → 404), completude real do wizard, `envelopes.update/duplicate` completos, auditoria das ações.
- **Assinaturas** (`RecipientController`): listagem/KPIs/export reais e reenvio em lote.
- **Signatário público** (`Sign\*`): resolução do link por digest, OTP, sessão, visualizador, aceite, recusa, download.
- **Verificação pública** (`Public\VerificationController`): consulta a `verification_records` e conferência de hash.
- **Evidências**: certificado, hash da página de evidências, `signature_image_url`, `viewed_at`/`otp_verified_at`.
- **Cobrança**: checkout Mercado Pago, webhook, cancelar/reativar renovação, dados de faturamento, recibo PDF; exclusão efetiva da organização (job após carência) e cancelamento da assinatura.
- **Menores**: `recipients[].role` sempre `null` (papel livre do wizard); `last_seen_at` de membros `null` (sem coluna); `auth.user.id` é inteiro (contrato diz ULID; users não têm ulid); `organization.plan` envia `code` e o alias `key`; `billing.resume` registrado além do contrato §1.2 (usado pelo front).
- **Fora do escopo verificado**: LibreOffice real não instalado (adaptador validado só com binário fake); Redis/Horizon só em produção.

## 6. Credenciais de desenvolvimento (somente local/testing)

Senha de todos: `password`.

| Usuário                                  | Papel                                                              |
| ---------------------------------------- | ------------------------------------------------------------------ |
| `admin@assinavelox.local`                | platform admin (painel `/admin`; sem organização)                  |
| `owner@horizonte.demo`                   | owner de "Imobiliária Horizonte Demo" (plano Profissional sandbox) |
| `admin@horizonte.demo`                   | admin da mesma organização                                         |
| `operador@horizonte.demo`                | member da mesma organização                                        |
| `owner@vega.demo` / `operador@vega.demo` | owner / member de "Consultoria Vega Demo" (plano Grátis)           |

`DemoOrganizationSeeder` só roda em `local`/`testing`.

## 7. Comandos

```bash
# Dependências e chave
composer install && npm install
cp .env.example .env && php -d extension=intl artisan key:generate

# Banco (MySQL do .env) + seeders de demonstração
php -d extension=intl artisan migrate:fresh --seed

# Alternativa sem tocar no MySQL (SQLite próprio)
touch database/dev.sqlite
DB_CONNECTION=sqlite DB_DATABASE=database/dev.sqlite php -d extension=intl artisan migrate:fresh --seed

# Desenvolvimento
php -d extension=intl artisan serve            # http://localhost:8000
npm run dev                                    # Vite com HMR (ou `composer run dev` = `php artisan dev`)
php -d extension=intl artisan queue:work --queue=default,conversions,notifications,finalization,billing

# Build de produção do front (obrigatório para o smoke test de CSP e para servir sem `npm run dev`)
npm run build

# Helpers TS de rotas (rodar após alterar routes/*.php)
php -d extension=intl artisan wayfinder:generate --with-form

# Qualidade
php -d extension=intl artisan test
vendor/bin/pint --dirty
vendor/bin/phpstan analyse
npm run types:check && npm run check

# pdftool (Python) — autoteste
php -d extension=intl artisan pdftool:selftest
```

Observações: a extensão `intl` não está no `php.ini` local (por isso `-d extension=intl`); filas em dev usam o driver `database`; Horizon/Redis só em produção (`config/horizon.php`).
