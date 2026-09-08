# Front-end AssinaVelox — guia de implementação

> Inertia 3 + React 19 + TypeScript + Tailwind 4 + shadcn/ui (new-york). Fonte visual: `docs/design/DESIGN_SYSTEM.md`; contratos de props: `docs/design/ROUTES_AND_PAGES.md` com os renomes de `docs/design/RECONCILIACAO.md` (que prevalece em conflito).

## Como rodar

| Comando                          | O que faz                                                                                                    |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `npm run dev`                    | Vite em modo dev (HMR). Rode junto com `php artisan serve` (ou `composer run dev`).                          |
| `npm run build`                  | Build de produção (gera `public/build`; a fonte Exo 2 é baixada no build e servida localmente via `@fonts`). |
| `npm run types:check`            | `tsc --noEmit` (TypeScript estrito).                                                                         |
| `npm run check` / `check:fix`    | Lint + formatação via `vite-plus` (`vp check`). Para formatar só o front: `npx vp fmt resources --write`.    |
| `php artisan wayfinder:generate` | Regenera os helpers de rota em `resources/js/routes` e `resources/js/actions` (não edite à mão).             |

Enquanto o backend não expõe todas as rotas, `npm run types:check` e `npm run build` falham **apenas** com `Cannot find module '@/routes/...'` (ver "Helpers de rota assumidos"). Depois de `php artisan wayfinder:generate` com as rotas de `ROUTES_AND_PAGES.md §1` os dois comandos devem passar sem outros erros — isso foi verificado com stubs tipados dos 58 helpers ausentes (`tsc` limpo).

## Estrutura

```
resources/
  css/app.css                 tokens (light-only), @theme inline, utilitários focus-ring/tabular
  views/app.blade.php         shell HTML (lang pt-BR, título AssinaVelox, favicon, @fonts, @vite; sem dark mode, sem rastreadores)
  js/
    app.tsx                   createInertiaApp: título "Página · AssinaVelox", layouts por prefixo do nome da página, Toaster
    layouts/
      app-layout.tsx          shell autenticado: Sidebar (offcanvas, Sheet < md) + topbar sticky + conteúdo p-6 gap-5
      auth-layout.tsx         split navy 44% (min 360px, oculto < lg) + formulário 400px (auth/*, invitations/*)
      settings/layout.tsx     rail 200px (Minha conta: Perfil, Segurança da conta · Organização: Geral e segurança,
                              Padrões de assinatura, Notificações, Plano e cobrança); vira Select < md
      signer-layout.tsx       casca da página pública do signatário (sign/*): remetente + stepper em pills + "via AssinaVelox"
      public-layout.tsx       casca de páginas públicas (verify/*, legal/*, marketing/*, errors/*)
    components/
      ui/                     primitivos shadcn restilizados para os tokens (sem variantes dark:)
      status/                 EnvelopeStatusBadge, RecipientStatusBadge, SubscriptionStatusBadge, PaymentStatusBadge
      app-sidebar.tsx         sidebar client|admin (modo derivado da URL /admin/*), 256px, grupos, badge âmbar, tags Fase 2
      app-topbar.tsx          header sticky translúcido: trigger, breadcrumb, ⌘K, ajuda, sino, slot extra, pill admin
      org-switcher.tsx        switcher de organização + dialog "Criar nova organização" (POST organizations.store)
      account-menu.tsx        menu da conta (Perfil e preferências, Plano e cobrança, Painel interno, Sair destrutivo)
      command-search.tsx      CommandDialog ⌘K consultando search.index (debounce 250 ms)
      notifications-popover.tsx  sino → notifications.index (JSON) / notifications.read
      data-table.tsx          tabela CSS grid (header 38px, linhas ≥52px, seleção, skeleton) + TitleCell/SecondaryCell/BulkActionBar
      table-pagination.tsx    rodapé "Mostrando X–Y de N" + linhas por página + pager (Paginated.meta)
      filter-bar.tsx          SearchInput (debounce), FilterChip/FilterMultiChip (tracejados), SelectableChip, ClearFiltersButton
      segmented-control.tsx   SegmentedControl, UnderlineTabs (com contagem), RailNavButton
      kpi-card.tsx            KpiCard (default 30px | compact 26px) + KpiGrid
      progress-meter.tsx      "148 / 500" + barra (âmbar ≥ 90 %)
      stepper.tsx             wizard (horizontal) | pills (público)
      timeline.tsx            trilha de auditoria (kind ok|info|warn) + HashBox
      avatar-initials.tsx     avatar quadrado, paleta i % 4, tons semânticos, AvatarStack
      empty-state.tsx / phase2-empty-state.tsx
      confirm-dialog.tsx      AlertDialog com confirmação digitada opcional
      copy-button.tsx, kbd.tsx, page-header.tsx, heading.tsx, input-error.tsx, password-input.tsx, text-link.tsx, app-logo.tsx
      flash-toaster.tsx       converte flash.{success,error,warning,info} em toasts (sonner)
      manage-two-factor.tsx, two-factor-setup-modal.tsx, two-factor-recovery-codes.tsx, delete-user.tsx (Fortify)
    hooks/                    use-flash, use-current-url, use-mobile (< 768px), use-clipboard, use-initials, use-two-factor-auth
    lib/
      utils.ts                cn(), toUrl()
      labels.ts               enum → rótulo PT-BR + tom de badge (ROUTES §6 / DESIGN §5)
      format.ts               datas pt-BR no fuso da organização (Intl), BRL a partir de centavos, bytes, máscaras CPF/CNPJ,
                              e-mail mascarado, código de verificação XXXX-XXXX-XXXX, display_code AV-00000
    types/
      enums.ts                espelho exato de RECONCILIACAO §2 (+ AuditEventType §3, NotificationEvent/Channel)
      models.ts               Envelope, Recipient, Membership, Invitation, Plan, Subscription, Payment, AuditEvent…
      index.ts                SharedProps, AuthUser, CurrentOrganization, OrgPermissions, Paginated<T>, Flash, Features
      global.d.ts             augmenta `sharedPageProps` do Inertia com SharedProps
      navigation.ts           BreadcrumbItem, NavItem
    pages/                    uma pasta por domínio (ver "Nomes das páginas")
    routes/, actions/, wayfinder/   GERADOS pelo Wayfinder — nunca editar
public/images/logo.png        logo horizontal oficial (842×297); public/favicon.svg|ico, apple-touch-icon.png
```

## Nomes das páginas (o backend deve usar exatamente estes em `Inertia::render`)

Os arquivos ficam em minúsculas/kebab-case (não PascalCase como no `ROUTES_AND_PAGES.md`). O layout é escolhido em `app.tsx` pelo prefixo.

| Layout                         | Componentes Inertia                                                                                                                                                                                                                                                            |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `AuthLayout`                   | `auth/login`, `auth/register`, `auth/forgot-password`, `auth/reset-password`, `auth/two-factor-challenge`, `auth/verify-email`, `auth/confirm-password`, `invitations/accept`                                                                                                  |
| `AppLayout`                    | `dashboard`, `envelopes/index`, `envelopes/wizard`, `envelopes/show`, `envelopes/evidence`, `recipients/index`, `members/index`, `organizations/create`, `templates/index`, `integrations/index`, `admin/organizations/index`, `admin/organizations/show`, `admin/placeholder` |
| `AppLayout` + `SettingsLayout` | `settings/profile`, `settings/security`, `settings/general`, `settings/signing`, `settings/notifications`, `settings/billing`, `settings/plans`                                                                                                                                |
| `SignerLayout`                 | `sign/show`                                                                                                                                                                                                                                                                    |
| `PublicLayout`                 | `marketing/home`, `verify/index`, `verify/show`, `legal/terms`, `legal/privacy`, `errors/403`, `errors/404`, `errors/500`                                                                                                                                                      |

Cada página exporta a `interface <Nome>Props` fiel ao contrato de `ROUTES_AND_PAGES.md §2` com os renomes da RECONCILIACAO (`public_code` → `display_code`, `routing_mode` → `signing_order`, `document_number` → `tax_id`, `waiting/sent` → `pending/notified`, `EnvelopeEvent` → `AuditEvent`).

## Helpers de rota assumidos (Wayfinder)

O front importa os helpers abaixo de `@/routes/**`. Nomes com `-` viram camelCase; nomes reservados ganham sufixo `Method` (`organizations.switch` → `switchMethod`, `dashboard.export`/`recipients.export`/`admin.organizations.export` → `exportMethod`); nomes com `_` ficam iguais; quando um nome é folha **e** namespace (`invitations.accept` + `invitations.accept.store`) o Wayfinder gera `accept` em `@/routes/invitations` e `store` em `@/routes/invitations/accept` (mesmo padrão de `password.confirm`).

| Módulo                            | Helper → rota Laravel                                                                                                                                                                                        |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `@/routes`                        | `home`, `login`, `logout`, `register`, `dashboard` (já gerados)                                                                                                                                              |
| `@/routes/login                   | register`                                                                                                                                                                                                    | `store` → `login.store`, `register.store` (Fortify, já gerados) |
| `@/routes/password`               | `request`, `email`, `update` (Fortify); `@/routes/password/confirm` → `store` (já gerados)                                                                                                                   |
| `@/routes/two-factor`             | `enable`, `confirm`, `disable`, `qrCode`, `secretKey`, `recoveryCodes`, `regenerateRecoveryCodes`; `@/routes/two-factor/login` → `store` (já gerados)                                                        |
| `@/routes/verification`           | `send` (já gerado)                                                                                                                                                                                           |
| `@/routes/profile`                | `edit` → `profile.edit`; `@/routes/security` → `edit` → `security.edit` (starter kit, já gerados)                                                                                                            |
| `@/actions/.../Settings/*`        | `ProfileController.update/destroy`, `SecurityController.update` (já gerados)                                                                                                                                 |
| `@/routes/dashboard`              | `exportMethod` → `dashboard.export`                                                                                                                                                                          |
| `@/routes/search`                 | `index` → `search.index`                                                                                                                                                                                     |
| `@/routes/notifications`          | `index` → `notifications.index`, `read` → `notifications.read`                                                                                                                                               |
| `@/routes/envelopes`              | `index`, `create`, `edit(envelope)`, `show(envelope)`, `evidence(envelope)`, `download({envelope,type})`, `resend(envelope)`, `cancel(envelope)`, `destroy(envelope)`, `duplicate(envelope)`, `bulk(action)` |
| `@/routes/envelopes/recipients`   | `resend({envelope,recipient})` → `envelopes.recipients.resend`                                                                                                                                               |
| `@/routes/folders`                | `store` → `folders.store`                                                                                                                                                                                    |
| `@/routes/recipients`             | `index`, `exportMethod` → `recipients.export`, `resend_pending` → `recipients.resend_pending`                                                                                                                |
| `@/routes/templates`              | `index` → `templates.index`                                                                                                                                                                                  |
| `@/routes/integrations`           | `index` → `integrations.index`                                                                                                                                                                               |
| `@/routes/members`                | `index`, `update(membership)`, `status(membership)`, `destroy(membership)`, `transfer_ownership(membership)`                                                                                                 |
| `@/routes/invitations`            | `store`, `resend(invitation)`, `destroy(invitation)`, `accept(token)`; `@/routes/invitations/accept` → `store(token)` → `invitations.accept.store`                                                           |
| `@/routes/settings`               | `general`, `signing`, `notifications` → `settings.general                                                                                                                                                    | signing                                                         | notifications` |
| `@/routes/settings/organization`  | `update` → `settings.organization.update`, `destroy` → `settings.organization.destroy` (POST solicita; DELETE cancela)                                                                                       |
| `@/routes/settings/security`      | `update` → `settings.security.update`                                                                                                                                                                        |
| `@/routes/settings/signing`       | `update` → `settings.signing.update`                                                                                                                                                                         |
| `@/routes/settings/notifications` | `update` → `settings.notifications.update`                                                                                                                                                                   |
| `@/routes/billing`                | `index`, `checkout`, `cancel`, `resume` → `billing.index                                                                                                                                                     | checkout                                                        | cancel         | resume`                                                                                                                 |
| `@/routes/plans`                  | `index` → `plans.index`                                                                                                                                                                                      |
| `@/routes/organizations`          | `store` → `organizations.store`, `switchMethod(organization)` → `organizations.switch`                                                                                                                       |
| `@/routes/legal`                  | `terms`, `privacy` → `legal.terms                                                                                                                                                                            | privacy`                                                        |
| `@/routes/verify`                 | `index`, `show(code)` → `verify.index                                                                                                                                                                        | show`                                                           |
| `@/routes/admin/organizations`    | `index`, `show(organization)`, `exportMethod` → `admin.organizations.index                                                                                                                                   | show                                                            | export`        |
| `@/routes/admin/billing           | users                                                                                                                                                                                                        | audit                                                           | settings`      | `index` → `admin.billing.index`, `admin.users.index`, `admin.audit.index`, `admin.settings.index` (placeholders Fase 2) |

## Convenções

- **Identificadores em inglês, textos em pt-BR.** Rótulos de enum vêm de `lib/labels.ts`; nunca hardcode um rótulo de status numa página. Isso vale também para os componentes de `components/ui` (shadcn): traduza os textos de leitor de tela ("Fechar", "Mais", "Alternar menu lateral", "Carregando") ao adicionar um novo primitivo.
- **Badge × nota do signatário** (ROUTES §6.2 / DESIGN §5.2): o badge de `pending`, `notified` e `viewed` é sempre "Pendente" (âmbar) — vem de `recipientStatusLabels` e do espelho em PHP `RecipientStatus::label()`. O detalhe ("Enviado · não visualizou", "Visualizou em {dt}", "Aguarda a vez") é uma linha separada abaixo do badge (`recipientStatusNotes` / `RecipientStatus::note()`), nunca o texto do badge.
- **Sem jargão de iteração na interface.** Nomes internos de onda/incremento ("Wave B") não podem aparecer em texto visível, flash ou tooltip: use "Disponível em breve" para o que ainda vem na Fase 1 e o padrão "Fase 2" só para o que está fora do escopo.
- **Datas**: o backend envia ISO-8601 UTC; formate com `lib/format.ts` (`formatRelativeDateTime` → "Hoje, 09:12", `formatDateMedium` → "03 set 2026"). O `AppLayout` chama `setTimeZone(organization.timezone ?? user.timezone)`.
- **Dinheiro**: sempre centavos inteiros → `formatCurrency(cents)`; `formatCurrencyCompact` ("R$ 49"), `formatCurrencyShort` ("R$ 96,4 mil").
- **Rotas**: importe helpers do Wayfinder (`import { index } from '@/routes/envelopes'`, `show(id).url`, `store.form()`); nunca monte URLs à mão.
- **Formulários**: `useForm` (estado controlado, máscaras, payloads transformados) ou `<Form {...route.form()}>` (Fortify e casos simples). Erros via `InputError`; `aria-invalid` no campo. Payload do cadastro: `name`, `email`, `organization_name`, `organization_tax_id`, `password`, `password_confirmation`, `terms`.
- **Filtros/listas**: estado na query-string (`router.get(route.url({ query }), {}, { preserveState, preserveScroll, replace })`); paginação via `TablePagination`.
- **Ações destrutivas**: `ConfirmDialog` (AlertDialog). Botões destrutivos são outline vermelho — não existe botão vermelho sólido no design.
- **Toasts**: flash do backend é exibido automaticamente pelo `FlashToaster`; toasts locais com `toast.success('…')` do sonner.
- **Permissões**: leia `organization.permissions.*` (nunca `role`, exceto para o atalho de Configurações do `member`) para ocultar links/ações; o backend continua sendo a autoridade (403 → `errors/403`).
- **Fase 2**: recursos fora do escopo aparecem desabilitados com `Badge variant="phase"` ("Fase 2") e tooltip "Disponível na Fase 2", ou com `Phase2EmptyState`.
- **Vocabulário**: "assinatura eletrônica"/"aceite eletrônico"; a assinatura criptográfica é "certificado A1 da operadora" — nunca sugerir ICP-Brasil pessoal do signatário (arquitetura §2).
- Sem dark mode na Fase 1 (`.dark`, `@custom-variant dark`, script de tema e classes `dark:` dos primitivos removidos); sem passkeys; sem appearance.

## Layouts e props de layout

`app.tsx` escolhe o layout pelo prefixo do nome da página. Cada página define props de layout estaticamente ou em função das props:

```tsx
Page.layout = { breadcrumbs: [{ title: 'Documentos', href: envelopesIndex() }] };
Page.layout = (props: Props) => ({ breadcrumbs: [...], topbarExtra: <span>…</span>, hideSearch: true });
```

`AppLayout` aceita `breadcrumbs`, `topbarExtra`, `hideSearch`, `fullBleed`, `contentClassName`. `AuthLayout` aceita `title`, `description`, `hideHeader`, `maxWidth`. `SignerLayout` aceita `sender`, `step`, `steps`. `PublicLayout` aceita `maxWidth`, `fullBleed`. Para props dinâmicas dentro do componente use `setLayoutProps()` do Inertia (ex.: `auth/two-factor-challenge`).

## Como adicionar uma página

1. Crie `resources/js/pages/<dominio>/<nome>.tsx` exportando o componente default e uma `interface <Nome>Props` fiel ao contrato de `ROUTES_AND_PAGES.md` (aplicando os renomes da RECONCILIACAO).
2. Defina `Page.layout` com os breadcrumbs (a raiz "organização" / "Painel interno" é adicionada pela topbar).
3. Use `PageHeader` para o cabeçalho, cards `rounded-xl border border-border bg-card shadow-card`, tabelas via `DataTable` + `TablePagination`, KPIs via `KpiCard`/`KpiGrid`.
4. Importe rotas do Wayfinder; se o helper ainda não existe, rode `php artisan wayfinder:generate` após o backend criar a rota e registre o nome esperado na tabela acima.
5. Rode `npm run types:check`, `npx vp fmt resources --write` e `npm run check`.

## Tokens (resumo)

Paleta em `resources/css/app.css` (`:root` + `@theme inline`): `--primary #1257c9` (hover `#0f4bb0`), `--foreground/--navy #0b1f42`, `--text-secondary #47536b`, `--muted-foreground #8a96ad`, `--background #fbfcfe`, `--sidebar #f7f9fc`, `--accent #eef2f9` (hover de nav/ghost), `--accent-subtle #f4f8fe` (hover de outline, linha selecionada), `--row-hover #f7f9fd`, `--border #e6eaf2`, `--input #d5dce9`, `--border-dashed #c9d4e6`, semânticas `success|warning|danger|info|neutral` com `-bg`, `-border` e `-solid`, texto sobre navy `on-navy-muted|secondary|subtle`, sombras `shadow-card|primary|segment|card-hover|pdf|popover|dialog`, raios `--radius 0.5rem` (`rounded-md` 6px badges, `rounded-lg` 8px controles, `rounded-[10px]` popovers, `rounded-xl` 12px cards, `rounded-2xl` 14px modais). Classes utilitárias extras: `tabular`, `focus-ring`, `animate-sign-wipe`, `animate-dialog-in`.

Tipografia: Exo 2 (400–800, normal e itálico) e Caveat (600) via `bunny()` em `vite.config.ts` — ambas servidas localmente no build (sem chamadas ao Google Fonts). Base 14px. Caveat responde por `--font-hand` / `font-hand` (assinatura manuscrita: hero do login, pad "Digitar", campo assinado no PDF) e é carregada sem `preload`, com `font-display: swap`, por aparecer em poucas telas. DESIGN_SYSTEM §1.2 usa só o peso 600 — é o mesmo recorte do mock, então o elemento não precisa declarar `font-weight`.

## Responsividade

- Sidebar: `SidebarProvider` + `collapsible="offcanvas"`; abaixo de `md` (768px) vira `Sheet`; estado persistido no cookie `sidebar_state` (prop compartilhada `sidebarOpen`).
- Topbar: breadcrumb pai oculto `< sm`; busca ⌘K oculta `< md`; pill "Acesso restrito" só `≥ lg`.
- Tabelas: `DataTable` envolve o grid em `overflow-x-auto` com `minWidth`.
- Rails de 200px (pastas, configurações) somem abaixo de `md` e viram `FilterChip`/`Select`.
- Auth: aside navy `hidden lg:flex`; formulário `max-w-[400px]`; logo aparece acima do formulário `< lg`.

## Divergências deliberadas em relação aos mocks

| Onde             | Mock                                                   | Implementado                                              | Motivo                                                                |
| ---------------- | ------------------------------------------------------ | --------------------------------------------------------- | --------------------------------------------------------------------- |
| Aside do login   | "Plataforma de assinatura digital"; badge "ICP-Brasil" | "…assinatura eletrônica"; "Certificado A1 da operadora"   | arquitetura §2 (não sugerir assinatura ICP-Brasil pessoal)            |
| Login            | "Entrar com certificado digital"                       | oculto                                                    | RECONCILIACAO §6 (Fase 2)                                             |
| Cadastro         | "CNPJ (opcional)"                                      | "CNPJ ou CPF (opcional)" + confirmação de senha           | ROUTES Q3 (autônomos) + Fortify exige `password_confirmation`         |
| Sidebar          | badge "23" fixo                                        | `counts.pending_envelopes`, oculto se 0                   | RECONCILIACAO §6                                                      |
| Usuários         | 4 funções (Admin/Gerente/Operador/Somente leitura)     | owner/admin/member (Proprietário/Administrador/Operador)  | ROUTES §6.4 (roles customizadas = Fase 2); "Pastas com acesso" oculto |
| Dashboard        | dropdown "Últimos 30 dias" no cabeçalho                | segmented 30 dias / 90 dias / 12 meses no card do gráfico | ROUTES §2.4                                                           |
| Dashboard KPI    | "Lembretes automáticos ativos"                         | "Reenvie convites pelo detalhe do documento"              | lembretes automáticos = Fase 2                                        |
| Configurações    | logo, SSO, IP, WhatsApp/SMS, lembretes                 | desabilitados com badge "Fase 2"                          | ROUTES §1.2                                                           |
| Cobrança         | cartão salvo "Alterar", NF-e                           | método do último pagamento (leitura); recibo PDF interno  | Checkout Pro sem cartão salvo; NF-e = Fase 2                          |
| Admin › Clientes | "Acessar como", "Nova conta", filtro Segmento          | botão desabilitado com tooltip; ocultos                   | RECONCILIACAO §5                                                      |
| Notificações     | coluna WhatsApp ativa                                  | coluna presente, desabilitada, badge "Fase 2"             | ROUTES §1.2                                                           |

### Props de página × props compartilhadas (integração I1)

As props compartilhadas `organization` (CurrentOrganization) e `organizations` (switcher) são lidas pelo shell (`AppSidebar`, `OrgSwitcher`, `AccountMenu`, `SettingsLayout`). Uma prop de página com o mesmo nome **sombreia** a compartilhada e derruba o shell em runtime, mesmo com HTTP 200. Regras adotadas:

- `settings/general` e `envelopes/evidence` (ROUTES §2.12/§2.8 chamam a prop de `organization`): o backend **mescla** `HandleInertiaRequests::currentOrganizationProps()` com os campos da página — a prop tem, ao mesmo tempo, `plan/role/permissions` e `legal_name/tax_id/contact_email` (ou `tax_id_masked`).
- `admin/organizations/show` e `admin/organizations/index`: as props chamam-se **`customer`** e **`customers`** (em vez de `organization`/`organizations` do ROUTES §2.20), porque no painel interno a organização exibida não é a organização corrente do usuário.
- O smoke test `tests/Feature/Smoke/AllGetRoutesTest.php` falha se alguma página GET voltar a sombrear essas props.
