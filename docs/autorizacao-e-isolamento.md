# AssinaVelox — Autorização e isolamento por organização (Fase 1)

> Complementa `docs/arquitetura.md` §7 e `docs/banco-de-dados.md` §4.5. Identificadores em inglês; prosa em português.
> Implementado no incremento B2 (organizações, autenticação, rotas, policies, props compartilhadas).

## 1. Modelo

| Conceito             | Implementação                                                                                                                                                                                                                                                                                       |
| -------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Organização corrente | `App\Support\CurrentOrganization` (singleton do container) guarda a **Organization** e a **Membership** do usuário autenticado.                                                                                                                                                                     |
| Quem define          | Middleware `org` (`App\Http\Middleware\EnsureCurrentOrganization`). Nenhum outro código escreve no singleton em requisições HTTP.                                                                                                                                                                   |
| Quem limpa           | Middleware global `ResetCurrentOrganization` (primeiro da pilha): limpa antes e depois de cada requisição — nada vaza entre requisições (testes, Octane, filas `sync`).                                                                                                                             |
| Escopo de dados      | Escopo global `OrganizationScope` (trait `BelongsToOrganization`) filtra por `organization_id` quando há organização corrente. Sem organização corrente nada é filtrado — por isso rotas fora de `org` **devem** usar `Model::forOrganization($org)` / `withoutOrganizationScope()` explicitamente. |
| Jobs / comandos      | Recebem `organization_id` e usam `CurrentOrganization::instance()->runAs($organization, fn () => ...)` ou `forOrganization()`.                                                                                                                                                                      |

### 1.1 Resolução da organização corrente (`org`)

1. `session('current_organization_id')` → membership **ativa** do usuário nessa organização;
2. fallback: `users.current_organization_id`; depois a primeira membership ativa (`ORDER BY id`);
3. sem membership ativa → redireciona para `organizations.create` (JSON: 403).

Ao resolver, o middleware grava a organização na sessão e sincroniza `users.current_organization_id` (`saveQuietly`). Memberships `suspended` nunca são usadas.

### 1.2 Troca de organização

`POST /organizacoes/{organization}/ativar` (`organizations.switch`): exige membership **ativa** do usuário na organização alvo; caso contrário **403**. Grava a sessão e `users.current_organization_id` e redireciona para `dashboard`.

## 2. Papéis e permissões

| Papel               | Pode                                                                                                         |
| ------------------- | ------------------------------------------------------------------------------------------------------------ |
| `owner`             | tudo: cobrança, exclusão da organização, transferir propriedade, gerir owners                                |
| `admin`             | usuários (exceto owners), pastas, **todos** os envelopes, configurações, cobrança (visualizar/alterar plano) |
| `member`            | criar e gerir os **próprios** envelopes; vê apenas os próprios (RECONCILIACAO Q7); notificações pessoais     |
| `is_platform_admin` | painel `/admin` **somente leitura**; não concede acesso a documentos nem passa por `org`                     |

`App\Support\Permissions::forRole()` deriva o mapa `OrgPermissions` enviado ao front (`organization.permissions`): `manage_members`, `manage_settings`, `manage_billing` (owner/admin), `delete_organization` (owner), `cancel_any_envelope`, `view_all_envelopes`, `manage_folders` (owner/admin). O front **nunca** decide por `role`; o backend continua sendo a autoridade (403).

### 2.1 Middlewares (aliases em `bootstrap/app.php`)

| Alias                  | Classe                            | Efeito                                                                                                                                        |
| ---------------------- | --------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `org`                  | `EnsureCurrentOrganization`       | resolve a organização corrente (§1.1)                                                                                                         |
| `org.role:owner,admin` | `EnsureMembershipRole`            | papel fora da lista → **403**                                                                                                                 |
| `org.2fa`              | `EnforceTwoFactorForOrganization` | organização com `require_two_factor` e usuário sem TOTP → redireciona para `security.edit` com aviso (rotas de conta/logout/switch liberadas) |
| `platform-admin`       | `EnsurePlatformAdmin`             | `users.is_platform_admin = false` → **403**                                                                                                   |
| `password.confirm`     | `RequirePassword` (Laravel)       | exclusão da organização, cancelar renovação                                                                                                   |

**Ordem**: `verified` e `org` foram inseridos na lista de prioridade **antes** de `SubstituteBindings` (`Middleware::prependToPriorityList`). Sem isso o route model binding rodaria antes da organização estar resolvida e os bindings escopados falhariam fechados (404) em toda requisição.

### 2.2 Policies (`App\Policies`, registradas em `AuthServiceProvider`)

| Policy                       | Regras                                                                                                                                                                                                                                                                                            |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `EnvelopePolicy`             | `viewAny/create`: membro ativo. `view/duplicate/download`: owner/admin **ou** criador. `update/send/cancel/delete/move`: criador ou owner/admin. Regras de **status** (409 "Ação indisponível no status atual") ficam nos controllers/serviços, não na policy.                                    |
| `FolderPolicy`               | ver: qualquer membro; criar/renomear/excluir: owner/admin.                                                                                                                                                                                                                                        |
| `MembershipPolicy`           | `manage/viewAny`: owner/admin. `update/updateStatus/delete`: owner/admin, nunca sobre si mesmo; admin não toca em owner; ninguém rebaixa/suspende/remove o **último owner ativo** (`MembershipPolicy::isLastActiveOwner`). `transferOwnership`: só owner, para membership ativa de outro usuário. |
| `MembershipInvitationPolicy` | criar/reenviar/revogar: owner/admin da organização do convite.                                                                                                                                                                                                                                    |
| `OrganizationPolicy`         | `updateSettings`, `manageBilling`: owner/admin; `delete`: owner; `switchTo`: membership ativa.                                                                                                                                                                                                    |

As policies resolvem a membership pelo trait `Policies\Concerns\ResolvesMembership`: reutiliza a membership do singleton quando a organização coincide, senão consulta o banco. Só memberships **ativas** contam.

## 3. Route model binding escopado

| Model                                                                            | Chave        | Como é escopado                                                                                                                                                                                                        |
| -------------------------------------------------------------------------------- | ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Envelope`, `Folder`, `Recipient`, `Payment`, demais com `BelongsToOrganization` | `ulid`       | `BelongsToOrganization::resolveRouteBinding()` exige `CurrentOrganization::id()`; sem organização corrente devolve `null` → **404**; com organização, filtra `organization_id` explicitamente (além do escopo global). |
| `Recipient` dentro de `{envelope}`                                               | `ulid`       | grupo `documentos/*` usa `scopeBindings()`: o recipient é resolvido pela relação `envelope->recipients()` → recipient de outro envelope = **404**.                                                                     |
| `Membership` (sem escopo global)                                                 | `id` inteiro | `Route::bind('membership')` em `AuthServiceProvider`: `organization_id = corrente` → **404** fora dela.                                                                                                                |
| `MembershipInvitation` (sem escopo global)                                       | `ulid`       | `Route::bind('invitation')`: idem.                                                                                                                                                                                     |
| `Organization` (switch, admin)                                                   | `ulid`       | binding padrão; a autorização é feita pelo controller/policy (switch → 403 sem membership ativa).                                                                                                                      |

Ajustes feitos por B2 na camada de dados de B1 (documentados aqui, conforme combinado):

- `BelongsToOrganization::resolveRouteBinding()` (fecha o binding sem organização corrente);
- `CurrentOrganization` passou a guardar também a **Membership** (`membership()`, `role()`, `runAs(..., $membership)`);
- `AppServiceProvider` registra `CurrentOrganization` como singleton;
- migration `2026_09_08_200001_add_notification_preferences_to_memberships_table` (coluna JSON `memberships.notification_preferences`; o model `Membership` não declara cast — a (de)serialização fica em `App\Services\Organizations\NotificationPreferences`).

## 4. Fluxos de organização

- **Cadastro** (`CreateNewUser`): `User` + `Organization` + `Membership(owner, active)` + `Subscription(free, active)` em transação (`App\Services\Organizations\CreateOrganization`); grava `terms_accepted_at`/`terms_version`. Campos: `name`, `email`, `password(+confirmation)`, `organization_name` (obrigatório), `organization_tax_id` (opcional, `CpfOrCnpj`), `terms` (accepted). Cadastro com `invitation={token}` válido **não** cria organização; o token fica em `session('invitation.pending_token')` e o aceite ocorre em `invitations.accept.store` após verificar o e-mail.
- **Criar nova organização** (`organizations.create/store`): mesmo serviço; a nova organização vira corrente.
- **Convites** (`App\Services\Organizations\Invitations`): token de 32 bytes (base64url) → `token_digest` SHA-256; `expires_at = now + assinavelox.invitations.expires_in_days`; e-mail `MembershipInvitationNotification` (fila `notifications`) com link `/convites/{token}`. Reenviar gera **novo** token; revogar marca `revoked_at`. Estados da página de aceite: `valid|expired|revoked|accepted` × `guest|same_user|other_user` (token desconhecido é tratado como `revoked`, sem revelar existência). O aceite exige usuário autenticado **com o mesmo e-mail** e e-mail verificado; cria/reativa a membership e define a organização corrente. Assentos: memberships ativas + convites pendentes ≤ `plans.user_quota` (erro 422 em `seats`).
- **Exclusão da organização**: `POST /configuracoes/excluir-conta` (owner + `password.confirm`) grava `settings.deletion_requested_at`; `DELETE` na mesma rota cancela. A exclusão efetiva após `assinavelox.organization_deletion_grace_days` e o cancelamento da assinatura pertencem ao incremento de cobrança. (Não há coluna própria no schema de B1 — decisão registrada aqui.)

## 5. Props compartilhadas (`HandleInertiaRequests::share`)

Todos os valores dependentes da organização são **closures** (o Inertia resolve ao renderizar, depois do middleware `org`):

- `auth.user`: `id` (inteiro), `name`, `email`, `initials`, `avatar_url` (null), `email_verified_at`, `two_factor_enabled`, `is_platform_admin`, `locale`, `timezone` (do usuário ou da organização);
- `organization` (null fora de `org`): `id` (ulid), `name`, `legal_name`, `initials`, `logo_url` (null), `timezone`, `role`, `plan{code, key, name, status}`, `permissions`;
- `organizations[]`: memberships **ativas** do usuário (`id`, `name`, `initials`, `plan_name`, `role`, `is_current`);
- `counts{pending_envelopes, unread_notifications}`: `Cache::remember` por org+usuário (`assinavelox.counts_cache`, 60 s); `pending_envelopes` respeita a visibilidade do papel; `unread_notifications` filtra `data->organization_id`;
- `flash{success,error,warning,info,status}`, `features{...: false}`, `sidebarOpen`.

Divergências conhecidas página ↔ contrato: `auth.user.id` é inteiro (contrato TS diz ULID — usuários não têm ulid no schema); `organization.plan` traz `code` (canônico) **e** `key` (alias lido pelo front); na página `admin/organizations/index` a prop de página `organizations` (Paginated) sobrescreve a prop compartilhada homônima (o switcher não aparece em rotas admin).

## 6. Painel interno (`/admin`)

Middleware `auth` + `verified` + `platform-admin`, **sem** `org`. `Admin\OrganizationController` usa `Organization::query()` (sem escopo) e `Envelope::forOrganization()`, `DocumentVersion::forOrganization()`, `Payment::forOrganization()`, `Subscription::withoutOrganizationScope()`. Somente leitura; "Acessar como" é Fase 2.

## 7. Cabeçalhos de segurança

Middleware global `SecurityHeaders`: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Permissions-Policy`, `Referrer-Policy: strict-origin-when-cross-origin` (em `/assinar/*` e `/verificar/*`: `no-referrer` + `X-Robots-Tag: noindex, nofollow, noarchive`), HSTS em HTTPS e **CSP com nonce** (`Vite::useCspNonce()`; `script-src 'self' 'nonce-…'`; `'unsafe-inline'` apenas em `style-src`; `frame-ancestors 'none'`; `object-src 'none'`; `base-uri 'self'`; `form-action 'self'`; `worker-src 'self' blob:` para o PDF.js). Em desenvolvimento com Vite "hot" a origem do dev server e o WebSocket do HMR são liberados. Config: `assinavelox.security_headers.{csp_enabled, csp_report_only, csp_extra_sources}`.

## 8. Limitadores (`AppServiceProvider`)

`public` 60/min por IP · `signer` 30/min por IP+token · `otp-send` 3/10 min · `otp-verify` 5/10 min · `search` 120/min por usuário · `webhook` 300/min · Fortify `login` 5/min · `verify.show` 20/min · `verify.check_file` 10/min · `sign.complete` 10/min.

## 9. Testes (tests/Feature)

`Auth/RegistrationTest`, `DashboardTest`, `Organizations/{IsolationTest, CurrentOrganizationTest, SharedPropsTest}`, `Members/{MembershipManagementTest, InvitationTest}`, `Security/SecurityHeadersTest`, `Envelopes/EnvelopeIndexShowTest`, `Admin/AdminOrganizationsTest`, `SettingsOrg/OrganizationSettingsTest`. Helpers em `tests/Feature/Support/OrganizationHelpers.php`. Os testes chamam `$this->withoutVite()` porque `public/build` não versiona todas as páginas.
