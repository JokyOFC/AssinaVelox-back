# Front-end AssinaVelox — guia de implementação

> Inertia 3 + React 19 + TypeScript + Tailwind 4 + shadcn/ui (new-york). Fonte visual: `docs/design/DESIGN_SYSTEM.md`; contratos de props: `docs/design/ROUTES_AND_PAGES.md` com os renomes de `docs/design/RECONCILIACAO.md`.

## Como rodar

| Comando | O que faz |
|---|---|
| `npm run dev` | Vite em modo dev (HMR). Rode junto com `php artisan serve` (ou `composer run dev`). |
| `npm run build` | Build de produção (gera `public/build` e baixa/serve a fonte Exo 2 localmente via `laravel-vite-plugin/fonts`). |
| `npm run types:check` | `tsc --noEmit` (TypeScript estrito). |
| `npm run check` / `check:fix` | Lint + formatação via `vite-plus` (`vp check`). |
| `php artisan wayfinder:generate` | Regenera os helpers de rota em `resources/js/routes` e `resources/js/actions` (não edite à mão). |

## Estrutura

```
resources/
  css/app.css                 tokens (light-only), @theme inline, utilitários focus-ring/tabular
  views/app.blade.php         shell HTML (pt-BR, @fonts, @vite, sem dark mode)
  js/
    app.tsx                   createInertiaApp: resolução de layouts por prefixo da página
    layouts/
      app-layout.tsx          shell autenticado: Sidebar + topbar + conteúdo p-6 gap-5
      auth-layout.tsx         split navy 44% + formulário 400px (auth/*, invitations/*)
      settings/layout.tsx     rail 200px (Perfil, Segurança, Geral, Padrões, Notificações, Plano)
      signer-layout.tsx       casca da página pública do signatário (sign/*)
      public-layout.tsx       casca de páginas públicas (verify/*, legal/*, marketing/*, errors/*)
    components/
      ui/                     primitivos shadcn (restilizados para os tokens do design)
      status/                 EnvelopeStatusBadge, RecipientStatusBadge, SubscriptionStatusBadge, PaymentStatusBadge
      app-sidebar.tsx         sidebar client|admin (modo derivado da URL /admin/*)
      app-topbar.tsx          header sticky: trigger, breadcrumb, ⌘K, ajuda, sino, slot extra
      org-switcher.tsx        switcher de organização + dialog "Criar nova organização"
      account-menu.tsx        menu da conta (Perfil, Plano, Painel interno, Sair)
      command-search.tsx      CommandDialog ⌘K consultando search.index (debounce 250 ms)
      notifications-popover.tsx  sino → notifications.index / notifications.read
      data-table.tsx          tabela CSS grid (header 38px, linhas ≥52px, seleção, skeleton) + TitleCell/BulkActionBar
      table-pagination.tsx    rodapé "Mostrando X–Y de N" + linhas por página + pager (Paginated.meta)
      filter-bar.tsx          SearchInput (debounce), FilterChip/FilterMultiChip (tracejados), SelectableChip
      segmented-control.tsx   SegmentedControl, UnderlineTabs (com contagem), RailNavButton
      kpi-card.tsx            KpiCard (default 30px | compact 26px) + KpiGrid
      progress-meter.tsx      "148 / 500" + barra (âmbar ≥ 90 %)
      stepper.tsx             wizard (horizontal) | pills (público)
      timeline.tsx            trilha de auditoria (kind ok|info|warn) + HashBox
      avatar-initials.tsx     avatar quadrado, paleta i % 4, tons semânticos, AvatarStack
      empty-state.tsx / phase2-empty-state.tsx
      confirm-dialog.tsx      AlertDialog com confirmação digitada opcional
      copy-button.tsx, kbd.tsx, page-header.tsx, heading.tsx, input-error.tsx, password-input.tsx, text-link.tsx
      flash-toaster.tsx       converte flash.{success,error,warning,info} em toasts (sonner)
    hooks/                    use-flash, use-current-url, use-mobile, use-clipboard, use-initials, use-two-factor-auth
    lib/
      utils.ts                cn(), toUrl()
      labels.ts               enum → rótulo PT-BR + tom de badge (ROUTES §6 / DESIGN §5)
      format.ts               datas pt-BR no fuso da organização (Intl), BRL a partir de centavos, bytes, máscaras CPF/CNPJ, e-mail mascarado
    types/
      enums.ts                espelho exato de RECONCILIACAO §2
      models.ts               Envelope, Recipient, Membership, Invitation, Plan, Subscription, Payment, AuditEvent…
      index.ts                SharedProps, AuthUser, CurrentOrganization, OrgPermissions, Paginated<T>, Flash, Features
      global.d.ts             augmenta `sharedPageProps` do Inertia com SharedProps
    pages/                    uma pasta por domínio (auth, dashboard, envelopes, recipients, members, settings, admin, sign, verify, legal, marketing, errors)
    routes/, actions/, wayfinder/   GERADOS pelo Wayfinder — nunca editar
```

## Convenções

- **Identificadores em inglês, textos em pt-BR.** Rótulos de enum vêm de `lib/labels.ts`; nunca hardcode um rótulo de status numa página.
- **Datas**: o backend envia ISO-8601 UTC; formate com `lib/format.ts` (`formatRelativeDateTime` → "Hoje, 09:12", `formatDateMedium` → "03 set 2026"). O `AppLayout` chama `setTimeZone(organization.timezone ?? user.timezone)`.
- **Dinheiro**: sempre centavos inteiros → `formatCurrency(cents)`.
- **Rotas**: importe helpers do Wayfinder (`import { index } from '@/routes/envelopes'`, `show(id).url`, `store.form()`). Nomes com `-` viram camelCase; nomes reservados ganham sufixo `Method` (`organizations.switch` → `switchMethod`, `dashboard.export` → `exportMethod`); nomes com `_` ficam iguais (`members.transfer_ownership`).
- **Formulários**: `useForm` (estado controlado, máscaras) ou `<Form {...route.form()}>` (Fortify e casos simples). Erros via `InputError`; `aria-invalid` no campo.
- **Filtros/listas**: estado na query-string (`router.get(route.url({ query }), {}, { preserveState, preserveScroll, replace })`); paginação via `TablePagination`.
- **Ações destrutivas**: `ConfirmDialog` (AlertDialog). Botões destrutivos são outline vermelho — não existe botão vermelho sólido no design.
- **Toasts**: flash do backend é exibido automaticamente pelo `FlashToaster`; toasts locais com `toast.success('…')` do sonner.
- **Permissões**: leia `organization.permissions.*` (nunca `role`) para ocultar links/ações; o backend continua sendo a autoridade.
- **Fase 2**: recursos fora do escopo aparecem desabilitados com `Badge variant="phase"` ("Fase 2") e tooltip, ou com `Phase2EmptyState`.
- Sem dark mode na Fase 1 (`.dark` e `@custom-variant dark` removidos); sem passkeys.

## Layouts e props de layout

`app.tsx` escolhe o layout pelo prefixo do nome da página. Cada página define props de layout estaticamente ou em função das props:

```tsx
Page.layout = { breadcrumbs: [{ title: 'Documentos', href: envelopesIndex() }] };
Page.layout = (props: Props) => ({ breadcrumbs: [...], topbarExtra: <span>…</span>, hideSearch: true });
```

`AppLayout` aceita `breadcrumbs`, `topbarExtra`, `hideSearch`, `fullBleed`. `AuthLayout` aceita `title`, `description`, `hideHeader`. `SignerLayout` aceita `sender`, `step`. `PublicLayout` aceita `maxWidth`, `fullBleed`. Para props dinâmicas dentro do componente use `setLayoutProps()` do Inertia.

## Como adicionar uma página

1. Crie `resources/js/pages/<dominio>/<nome>.tsx` exportando o componente default e uma `interface <Nome>Props` fiel ao contrato de `ROUTES_AND_PAGES.md` (aplicando os renomes da RECONCILIACAO).
2. Defina `Page.layout` com os breadcrumbs (a raiz "organização" é adicionada pela topbar).
3. Use `PageHeader` para o cabeçalho, cards `rounded-xl border border-border bg-card shadow-card`, tabelas via `DataTable` + `TablePagination`.
4. Importe rotas do Wayfinder; se o helper ainda não existe, rode `php artisan wayfinder:generate` após o backend criar a rota.
5. Rode `npm run types:check` e `npm run check`.

## Tokens (resumo)

Paleta em `resources/css/app.css` (`:root` + `@theme inline`): `--primary #1257c9`, `--foreground/--navy #0b1f42`, `--text-secondary #47536b`, `--muted-foreground #8a96ad`, `--background #fbfcfe`, `--sidebar #f7f9fc`, `--accent #eef2f9` (hover), `--accent-subtle #f4f8fe`, `--border #e6eaf2`, `--input #d5dce9`, semânticas `success|warning|danger|info|neutral` com `-bg` e `-border`, sombras `shadow-card|primary|segment|popover|dialog|pdf`, raios `--radius 0.5rem` (`rounded-lg` controles, `rounded-xl` cards, `rounded-2xl` modais). Classes utilitárias extras: `tabular`, `focus-ring`, `animate-sign-wipe`.

Fonte: Exo 2 (400–800, normal e itálico) via `bunny()` em `vite.config.ts` — servida localmente no build (sem chamadas ao Google Fonts). Caveat (assinatura manuscrita) será adicionada quando a captura de assinatura entrar (Wave B).

## Responsividade

- Sidebar: `SidebarProvider` + `collapsible="offcanvas"`; abaixo de `md` vira `Sheet`.
- Tabelas: `DataTable` envolve o grid em `overflow-x-auto` com `minWidth`.
- Rails de 200px (pastas, configurações) somem abaixo de `md` e viram `FilterChip`/`Select`.
- Auth: aside navy `hidden lg:flex`; formulário `max-w-[400px]`.
