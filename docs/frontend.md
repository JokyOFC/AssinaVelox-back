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
      pdf/                    use-pdf-document (hook), pdf-viewer, pdf-page-rail, pdf-zoom-controls
      envelopes/              field-layer, field-box, field-types, recipient-colors, document-dropzone,
                              use-wizard-autosave, wizard-step-{document,recipients,fields,review}
      sign/                   página pública do signatário: otp-card, signer-document, signer-field-layer,
                              field-checklist, consent-box, privacy-notice, legal-text, refusal-dialog,
                              receipt-card, terminal-card
      signature/              captura da representação visual: signature-capture, initials-capture,
                              signature-pad-canvas (signature_pad), signature-image (normalização PNG)
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
      pdf.ts                  PDF.js sob demanda, worker do bundler, fetch autorizado, erros em PT-BR, render no canvas
      geometry.ts             funções puras da geometria normalizada dos campos (ver "Visualizador de PDF e editor de campos")
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

| Módulo                            | Helper → rota Laravel                                                                                                                                                                                                                                                |
| --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `@/routes`                        | `home`, `login`, `logout`, `register`, `dashboard` (já gerados)                                                                                                                                                                                                      |
| `@/routes/login                   | register`                                                                                                                                                                                                                                                            | `store` → `login.store`, `register.store` (Fortify, já gerados) |
| `@/routes/password`               | `request`, `email`, `update` (Fortify); `@/routes/password/confirm` → `store` (já gerados)                                                                                                                                                                           |
| `@/routes/two-factor`             | `enable`, `confirm`, `disable`, `qrCode`, `secretKey`, `recoveryCodes`, `regenerateRecoveryCodes`; `@/routes/two-factor/login` → `store` (já gerados)                                                                                                                |
| `@/routes/verification`           | `send` (já gerado)                                                                                                                                                                                                                                                   |
| `@/routes/profile`                | `edit` → `profile.edit`; `@/routes/security` → `edit` → `security.edit` (starter kit, já gerados)                                                                                                                                                                    |
| `@/actions/.../Settings/*`        | `ProfileController.update/destroy`, `SecurityController.update` (já gerados)                                                                                                                                                                                         |
| `@/routes/dashboard`              | `exportMethod` → `dashboard.export`                                                                                                                                                                                                                                  |
| `@/routes/search`                 | `index` → `search.index`                                                                                                                                                                                                                                             |
| `@/routes/notifications`          | `index` → `notifications.index`, `read` → `notifications.read`                                                                                                                                                                                                       |
| `@/routes/envelopes`              | `index`, `create`, `edit(envelope)`, `show(envelope)`, `update(envelope)`, `send(envelope)`, `evidence(envelope)`, `download({envelope,type})`, `resend(envelope)`, `cancel(envelope)`, `destroy(envelope)`, `duplicate(envelope)`, `move(envelope)`, `bulk(action)` |
| `@/routes/envelopes/document`     | `store(envelope)`, `destroy(envelope)`, `status(envelope)`, `page({envelope,page})` → `envelopes.document.*`                                                                                                                                                         |
| `@/routes/envelopes/fields`       | `sync(envelope)` → `envelopes.fields.sync`                                                                                                                                                                                                                           |
| `@/routes/envelopes/recipients`   | `sync(envelope)` → `envelopes.recipients.sync`; `resend({envelope,recipient})`; `update({envelope,recipient})`                                                                                                                                                       |
| `@/routes/folders`                | `store` → `folders.store`                                                                                                                                                                                                                                            |
| `@/routes/recipients`             | `index`, `exportMethod` → `recipients.export`, `resend_pending` → `recipients.resend_pending`                                                                                                                                                                        |
| `@/routes/templates`              | `index` → `templates.index`                                                                                                                                                                                                                                          |
| `@/routes/integrations`           | `index` → `integrations.index`                                                                                                                                                                                                                                       |
| `@/routes/members`                | `index`, `update(membership)`, `status(membership)`, `destroy(membership)`, `transfer_ownership(membership)`                                                                                                                                                         |
| `@/routes/invitations`            | `store`, `resend(invitation)`, `destroy(invitation)`, `accept(token)`; `@/routes/invitations/accept` → `store(token)` → `invitations.accept.store`                                                                                                                   |
| `@/routes/settings`               | `general`, `signing`, `notifications` → `settings.general                                                                                                                                                                                                            | signing                                                         | notifications` |
| `@/routes/settings/organization`  | `update` → `settings.organization.update`, `destroy` → `settings.organization.destroy` (POST solicita; DELETE cancela)                                                                                                                                               |
| `@/routes/settings/security`      | `update` → `settings.security.update`                                                                                                                                                                                                                                |
| `@/routes/settings/signing`       | `update` → `settings.signing.update`                                                                                                                                                                                                                                 |
| `@/routes/settings/notifications` | `update` → `settings.notifications.update`                                                                                                                                                                                                                           |
| `@/routes/billing`                | `index`, `checkout`, `cancel`, `resume` → `billing.index                                                                                                                                                                                                             | checkout                                                        | cancel         | resume`                                                                                                                 |
| `@/routes/plans`                  | `index` → `plans.index`                                                                                                                                                                                                                                              |
| `@/routes/organizations`          | `store` → `organizations.store`, `switchMethod(organization)` → `organizations.switch`                                                                                                                                                                               |
| `@/routes/legal`                  | `terms`, `privacy` → `legal.terms                                                                                                                                                                                                                                    | privacy`                                                        |
| `@/routes/verify`                 | `index`, `show(code)` → `verify.index                                                                                                                                                                                                                                | show`                                                           |
| `@/routes/admin/organizations`    | `index`, `show(organization)`, `exportMethod` → `admin.organizations.index                                                                                                                                                                                           | show                                                            | export`        |
| `@/routes/admin/billing           | users                                                                                                                                                                                                                                                                | audit                                                           | settings`      | `index` → `admin.billing.index`, `admin.users.index`, `admin.audit.index`, `admin.settings.index` (placeholders Fase 2) |

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

## Visualizador de PDF e editor de campos

Telas envolvidas: `pages/envelopes/wizard.tsx` (passo 3), `pages/envelopes/show.tsx` (pré-visualização somente leitura) e, adiante, a página pública do signatário.

### Componentes

| Arquivo                                       | Papel                                                                                                                                                                           |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `lib/pdf.ts`                                  | Carrega o PDF.js sob demanda, configura o worker, baixa os bytes pela rota autorizada, traduz erros para PT-BR e renderiza uma página no `<canvas>`.                            |
| `lib/geometry.ts`                             | Funções **puras** de geometria: normalizado ↔ pixels, `clampRect`, `moveRect`, `resizeRect`, `snapRect`, `rectAroundPoint`. Nenhum componente faz conta de coordenada por fora. |
| `components/pdf/use-pdf-document.ts`          | Hook `usePdfDocument(url)` → `{ document, pageCount, status, error, reload }`. Uma instância por URL; destrói worker e cache ao trocar/desmontar.                               |
| `components/pdf/pdf-viewer.tsx`               | Card com barra (páginas, zoom, slot à direita), página centralizada sobre `bg-accent`, slot `overlay(size)` e estados de carregamento/erro/processamento.                       |
| `components/pdf/pdf-page-rail.tsx`            | Rail de miniaturas 72px renderizadas **no cliente**, com página atual e dot azul por página que tem campos.                                                                     |
| `components/pdf/pdf-zoom-controls.tsx`        | `−` / `100%` / `+`; níveis 50–200 %, clique no valor volta para "ajustar à largura".                                                                                            |
| `components/envelopes/field-layer.tsx`        | Camada sobre o canvas: arrastar, redimensionar, selecionar, excluir, duplicar, grade opcional e soltar tipos vindos da paleta.                                                  |
| `components/envelopes/field-box.tsx`          | Uma caixa de campo (variantes `editor`, `pending`, `signed`) com tag flutuante e alças nos quatro cantos.                                                                       |
| `components/envelopes/field-types.ts`         | Paleta de tipos, ícones, tamanhos padrão, placeholders, formatos de data e tamanhos de fonte.                                                                                   |
| `components/envelopes/recipient-colors.ts`    | Cor por signatário (azul, âmbar, verde, cinza) — as mesmas do mock, aplicadas como estilo inline por depender do índice em tempo de execução.                                   |
| `components/envelopes/document-dropzone.tsx`  | Dropzone com validação de tipo e tamanho **antes** do upload (`validateUploadFile`).                                                                                            |
| `components/envelopes/use-wizard-autosave.ts` | Autosave com debounce por grupo (`metadata`, `recipients`, `fields`) + `flush()`.                                                                                               |
| `components/envelopes/wizard-step-*.tsx`      | Os quatro passos do wizard.                                                                                                                                                     |

### Convenção de coordenadas (contrato com o backend)

`x`, `y`, `w`, `h` são frações em `[0, 1]` relativas ao **CropBox exibido** da página, origem no canto **superior esquerdo**, **já considerando a rotação** — ou seja, exatamente as coordenadas do canvas do PDF.js divididas pelas dimensões renderizadas em pixels CSS:

```
x = left_px / rendered_width_px      w = width_px  / rendered_width_px
y = top_px  / rendered_height_px     h = height_px / rendered_height_px
```

Como o PDF.js já aplica a rotação ao montar o `viewport`, não há inversão de eixo Y nem correção de ângulo no front. O navegador **não** envia dimensões de página: o backend revalida `0 ≤ x`, `0 ≤ y`, `x + w ≤ 1`, `y + h ≤ 1` e os tamanhos mínimos contra a versão do documento que ele carregou (`arquitetura.md` §3.1, tabela `signing_fields`). Os valores são arredondados em 6 casas (`DECIMAL(9,6)`) e o mínimo é 2 % da largura por 1,2 % da altura. Consequência prática verificada: a posição de um campo é idêntica em 50 %, 100 % e 200 % de zoom.

### Como o PDF.js é carregado

- `pdfjs-dist` entra por `import()` dinâmico dentro de `loadPdfjs()` — fica num chunk próprio (~430 kB), fora do bundle das telas que não mostram documento.
- O worker vem de `new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url)`. O Vite resolve o pacote e emite o arquivo em `public/build/assets/pdf.worker.min-*.mjs`; a CSP já libera `worker-src 'self' blob:`. Como o PDF.js sempre cria um _module worker_, o servidor precisa entregar `.mjs` com `Content-Type: text/javascript` (nginx: `types { text/javascript mjs; }`).
- O PDF **não** é baixado pelo PDF.js: `fetchPdfBytes` faz `fetch(url, { credentials: 'same-origin' })` na rota autorizada, converte o status HTTP em mensagem PT-BR (403 → "Você não tem permissão…", 404 → "O arquivo não está mais disponível.", 409 → "ainda está sendo processado") e só então entrega os bytes. `PasswordException` e `InvalidPDFException` viram mensagens próprias.
- O canvas usa a densidade da tela limitada a 2× (`devicePixelRatioCapped`); o tamanho **CSS** é o que a camada de campos enxerga.

### Estados do upload e do processamento

`document.processing.status` (`DocumentProcessingStatus`) governa a interface do passo 1 e do visualizador:

| Estado                  | Passo 1                                                       | Visualizador                        |
| ----------------------- | ------------------------------------------------------------- | ----------------------------------- |
| (sem arquivo)           | dropzone                                                      | "Nenhum arquivo enviado ainda."     |
| enviando                | barra de progresso (`onProgress` do Inertia, `forceFormData`) | —                                   |
| `uploaded`/`converting` | linha do arquivo com spinner + "Convertendo…"; polling de 3 s | "Convertendo o arquivo para PDF"    |
| `ready`                 | "Pronto" em verde, nome · tamanho · páginas                   | página renderizada                  |
| `failed`                | linha vermelha + "Remova-o e envie um PDF válido."            | "Falha ao processar o arquivo"      |
| `blocked`               | linha vermelha explicando senha/assinatura existente          | "Arquivo bloqueado para preparação" |

O polling é um `router.reload({ only: ['document', 'completeness', 'envelope'] })` a cada 3 s enquanto o estado for `uploaded` ou `converting`.

### Interação e acessibilidade

`Tab` percorre os campos; setas movem 0,5 % (2 % com `Ctrl`/`⌘`); `Shift` + setas redimensionam pelo canto inferior direito; `Delete`/`Backspace` remove; `Ctrl`/`⌘` + `D` duplica; `Esc` limpa a seleção. Cada caixa tem `aria-label` com o signatário, o tipo e a posição em porcentagem. A dropzone é um `role="button"` que responde a `Enter`/espaço.

### Autosave

`useWizardAutosave` agenda por chave com 800 ms de debounce: `metadata` → `PATCH envelopes.update`, `recipients` → `PUT envelopes.recipients.sync`, `fields` → `PUT envelopes.fields.sync`. Todos usam `preserveState` para não descartar a edição em andamento; o `client_id` viaja nos dois sentidos para que o front adote os ULIDs recém-criados sem perder o que o usuário digitou. O indicador "Rascunho salvo às HH:mm" / "Salvando…" fica no topbar via `setLayoutProps`.

## Página pública do signatário (`pages/sign/show.tsx`)

Uma única página Inertia cobre as oito telas do contrato (`ROUTES §2.18` e `§3`;
`DESIGN §6.12`), escolhidas pela prop `screen`. O layout é o `SignerLayout`
(`sender`, `documentTitle`, `step`, `privacyUrl`, `termsUrl`), **mobile-first**:
no celular o cabeçalho guarda só a organização e o título do documento, o
stepper vai para uma faixa própria abaixo e as duas colunas viram uma só; o
stepper sobe para o cabeçalho a partir de `lg`. Nenhum recurso de terceiro é
carregado nesta página — as fontes vêm do próprio build.

### Telas e props consumidas

| `screen`                        | O que a tela mostra                                                                                      | Props usadas além de `sender` / `envelope` / `recipient`                                                                         |
| ------------------------------- | -------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `identify`                      | `OtpCard` + documento bloqueado (`FileLock2`) — o PDF só existe depois do OTP                            | `otp`, `privacy`, `limits`, `legal`, `errors.code`/`errors.otp`                                                                  |
| `sign`                          | documento com campos clicáveis, captura de assinatura/rubrica, campos a preencher, aviso, aceite, recusa | `document`, `my_fields`, `other_fields`, `others`, `signature_options`, `consent`, `privacy`, `authorization`, `limits`, `legal` |
| `completed`                     | `ReceiptCard` (comprovante) + participantes                                                              | `receipt`, `others`                                                                                                              |
| `already_signed_pending_others` | mesmo comprovante, com "Aguardando N signatário"                                                         | `receipt`, `others`                                                                                                              |
| `finalizing`                    | mesmo comprovante, "Todos os participantes assinaram; o arquivo final está sendo preparado"              | `receipt`, `others`                                                                                                              |
| `refused`                       | "Assinatura recusada" + data e motivo                                                                    | `refusal`                                                                                                                        |
| `expired`                       | "Prazo encerrado" (texto exato de ROUTES §3.4)                                                           | `envelope.expires_at`, `sender.user_name`                                                                                        |
| `canceled`                      | "Documento cancelado"                                                                                    | `sender.organization_name`                                                                                                       |
| `invalid`                       | "Link inválido"                                                                                          | nenhuma (é o único caso em que `envelope` e `recipient` chegam `null`)                                                           |

Props que a página consome além do contrato de `ROUTES §2.18` — todas já
existem em `App\Services\Signing\SignerPageProps`:

- **`consent`** `{ version, checkbox_label, statement, completion_notice }` —
  textos de `declaracao-de-aceite.md` §2, §3 e §7 já resolvidos. `consent_text`
  continua existindo e repete `statement`; a página usa `consent.statement` e
  cai para `consent_text` se ele faltar.
- **`privacy`** `{ version, summary, notice }` — aviso ao signatário com as
  variáveis da Operadora substituídas. Sem ele a página exibe a linha-resumo
  local e um link para a política.
- **`authorization`** `{ token, expires_at }` — token de autorização final,
  **obrigatório** em `sign.complete` (`authorization` no payload). Sem ele o
  servidor responde "A tela expirou. Recarregue a página antes de assinar."
- **`limits`** — `otp_length`, `otp_ttl_minutes`, `otp_max_attempts`,
  `max_text_length`, `signature_image_max_kb`, `refusal_reason {min,max}`,
  `typed_name {min,max}`. Os componentes recebem esses números por prop em vez
  de repetir constantes.
- **`receipt`** — `signed_at`, `verification_code`, `document_sha256`,
  `signed_sha256`, `ip` (já ajustado por `evidence_show_ip`), `auth_label`,
  `terms_version`, `download_url` (**relatório de evidências**),
  `final_pdf_url` (**PDF final**), `final_pdf_available`, `pending_others` e
  `completion_notice`.
- **`signature_options.certificate`** — sempre `false` (certificado do
  signatário é Fase 2); a página não mostra esse modo.
- **`my_fields[]`** traz ainda `auto`, `server_filled` e `options`.

A página **não** deduz o modo de conclusão: ela imprime o
`completion_notice` que o servidor manda. É esse texto que diz "aceite
eletrônico com evidências" quando não há certificado da operadora ativo.

### Fluxo do aceite

1. **Confirmar identidade.** O código **não** é disparado sozinho: o signatário
   clica em "Receber código por e-mail" (`POST sign.otp.send`). É o que a
   arquitetura §4.1 descreve e o que o aviso de privacidade promete ("não
   solicitar o código não gera nenhum aceite") — divergência deliberada em
   relação a ROUTES §3.2, que sugeria envio automático na primeira renderização.
   O campo de 6 dígitos (`InputOTP`, DESIGN §4.21) envia ao completar e também
   pelo botão; o reenvio tem contagem regressiva a partir de
   `otp.resend_available_at` e as tentativas restantes aparecem abaixo de 3.
2. **Assinar.** `SignerDocument` abre o PDF uma única vez
   (`usePdfDocument(sign.document)`) e o compartilha com o diálogo "Ampliar".
   `SignerFieldLayer` desenha as caixas: as do signatário são `<button>`
   (tracejado azul "Clique para assinar aqui" → sólido verde quando
   preenchidas), as dos demais são estáticas ("assina depois de você" /
   "Aceite registrado"). "Próximo campo" na barra pula para o próximo
   obrigatório pendente, troca de página e foca o controle correspondente.
3. **Preencher.** Texto e marcação são editados no card lateral
   (`FieldChecklist`), não dentro da caixa sobre a página: digitar num
   retângulo de 2 cm é inviável no celular. Clicar no campo do documento
   destaca e foca a linha correspondente; `checkbox` alterna direto no clique.
   `name` e `date` são carimbados pelo servidor e aparecem como somente leitura.
4. **Aceitar.** `ConsentBox` — caixa **desmarcada por padrão**, nunca marcada
   por rolagem, com o rótulo da §2 ao lado e a declaração completa da §3 logo
   abaixo (com rolagem própria, nunca escondida atrás de um link). O botão
   "Assinar documento" só habilita com a caixa marcada **e** nenhum campo
   obrigatório pendente.
5. **Recusar.** `RefusalDialog` com motivo obrigatório (10–500 caracteres) →
   `POST sign.refuse`. O diálogo avisa que a recusa encerra o documento.

`POST sign.complete` envia
`{ authorization, signature, initials, fields: { [field_id]: string | boolean }, consent: true }`.
Cada imagem viaja como
`{ method: 'draw' | 'type' | 'upload', kind: 'drawn' | 'typed' | 'uploaded', image_base64, text, font }`:
`method` é o nome que o `StoreAcceptanceRequest` valida e `kind` é o enum
canônico (RECONCILIACAO §2), enviado junto para não depender de um acerto de
nomenclatura. `image_base64` é base64 **puro** (sem o prefixo
`data:image/png;base64,`) e `font` só pode ser uma das famílias de
`RecordAcceptance::FONTS`.

Todos os POSTs desta página usam `preserveState: true`. Sem isso o Inertia
remonta o componente a cada resposta (o padrão para POST) e o signatário perde
a assinatura desenhada quando o servidor recusa o aceite — foi um bug real,
reproduzido no navegador contra o backend e corrigido aqui.

### Captura da representação visual (`components/signature/`)

Três modos, todos produzindo **PNG com fundo transparente** recortado no traço:

| Modo          | Como funciona                                                                                                                                                                                                                                                                                                                                                          |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Desenhar      | `signature_pad@5` em import dinâmico (chunk próprio de ~15 kB), fundo transparente, traço `#0b1f42`, linha-base e dica do DESIGN §4.20, "Limpar" e "Desfazer" (remove o último traço). O canvas usa a densidade da tela limitada a 2× e reescala os traços quando o contêiner muda de largura (girar o celular). `touch-action: none` para o gesto não rolar a página. |
| Digitar       | Nome em fonte manuscrita com três estilos (`caveat`, `caveat_slanted`, `serif`). O PNG é gerado por `fillText` num canvas depois de `document.fonts.load`, para não cair numa família genérica. Só a Caveat está no build; famílias oferecidas pelo servidor que não existem localmente são ignoradas em vez de deixar o signatário sem opção.                         |
| Enviar imagem | PNG/JPG até 2 MB, **validados antes de qualquer leitura** (tipo pelo MIME e tamanho). Opção "Remover o fundo claro da foto" (ligada) transforma o papel branco em transparência por limiar de luminância, com meio-tom para não deixar borda dura.                                                                                                                     |

`signature-image.ts` concentra a normalização: recorte pelos pixels opacos,
margem de 8 px, redução para 1200×400 (assinatura) ou 400×200 (rubrica) e
`toDataURL('image/png')`. Antes de enviar, a página confere o tamanho contra os
300 KB do contrato e pede um traço mais simples se estourar. `InitialsCapture` é
o mesmo motor com moldura menor e as iniciais do nome como sugestão.

### Vocabulário

A imagem é sempre "representação visual" / "sua assinatura", nunca "assinatura
digital". Um campo de outro participante já concluído diz **"Aceite
registrado"**. O comprovante usa o texto da `declaracao-de-aceite.md` §4 (data
local **e** UTC, autenticação, código de verificação) e o selo de conclusão sai
da §7 daquele documento: sem certificado da operadora, "Concluído · aceite
eletrônico com evidências".

## Detalhe do documento — abas Signatários e Trilha

- **Signatários**: cabeçalho "Ordem de assinatura: sequencial · Lembretes:
  manuais" (com a flag `reminders`, a cadência real — ver "Fase 2 — onda A"). Cada card traz avatar com o tom
  do status, papel e posição na ordem, chips de canal e de autenticação, badge
  de status e um rodapé com a nota do estado. Ações por estado: `notified` →
  "Reenviar"; `viewed` → "Lembrar"; `pending` aguardando a vez no sequencial →
  botão desabilitado com a explicação (não existe link emitido ainda,
  RECONCILIACAO Q11); `signed` → "Ver evidências". "Editar" abre um diálogo com
  nome e e-mail (`PATCH envelopes.recipients.update`) que avisa, ao alterar o
  e-mail, que o link anterior é revogado na hora.
- **Trilha**: cabeçalho do DESIGN §6.4 com atalho "Relatório PDF" (desabilitado
  enquanto não há evidência gerada) e `Timeline markers="icon" showNotes`. O
  marcador vira o ícone do tipo de evento, mantendo o tom `ok|info|warn` do
  backend; a página de evidências continua numerada, que é o que se cita num
  dossiê impresso. `AUDIT_EVENT_NOTES` acrescenta a nota que impede a leitura
  errada: `invitation.opened` é **"abertura detectada — registra o acesso ao
  link, não comprova leitura"**, `acceptance.recorded` é aceite eletrônico com
  evidências e `envelope.signed_company_a1` identifica a operadora, não a pessoa.
  A mesma distinção aparece na nota do card do signatário ("Abertura detectada
  em …" em vez de "Visualizou em …").

## Fase 2 — onda A: vários documentos, papéis, lembretes e agendamento

Contratos do backend: `docs/fase-2/multi-documento-e-papeis.md` §8 e `docs/fase-2/lembretes-e-agendamento.md` §7. Regra de ouro (roadmap T8): **cada recurso só aparece com a sua flag**; com todas desligadas as telas, os textos e os payloads são os da Fase 1. A flag só liga a interface — quem autoriza continua sendo o servidor.

### De onde vem cada flag

| Recurso                           | Lido de                                                                                            | Onde aparece                       |
| --------------------------------- | -------------------------------------------------------------------------------------------------- | ---------------------------------- |
| Vários arquivos (§2.3)            | `domain_features.multi_document` (prop do wizard; cai em `features.multi_document`)                | wizard (passos 1, 3 e 4)           |
| Papéis de participante (§2.4)     | `domain_features.participant_roles` (cai em `features.participant_roles`, ainda não compartilhada) | wizard (passos 2, 3 e 4)           |
| Lembretes e envio agendado (§2.5) | `reminders.available` (prop `reminders` do wizard e do detalhe)                                    | wizard (passos 1 e 4), detalhe     |
| Início por modelo (§2.1)          | `features.templates`                                                                               | wizard, passo 1 (`TemplatePicker`) |

O **detalhe**, a **página pública**, as **evidências** e a **verificação** não olham flag: mostram o que o envelope tem (vários arquivos, papéis mistos). Um envelope enviado com três arquivos continua sendo exibido assim mesmo que a flag seja desligada depois — é a mesma regra do backend.

### Componentes novos

| Arquivo                                         | Papel                                                                                                                                                          |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `components/envelopes/wizard-document-list.tsx` | Lista de arquivos do passo 1: ordem com setas (↑/↓), estado do processamento por arquivo, remoção individual. Exporta `fileBadge` e `documentName`.            |
| `components/envelopes/reminders-control.tsx`    | Switch "Lembretes automáticos" real + primeiro lembrete após / repetir a cada / máximo, dentro dos `limits` do servidor, com a janela e o fuso da organização. |
| `components/envelopes/schedule-send-card.tsx`   | "Agendar envio" (`datetime-local` limitado por `scheduled_send_limits`, no fuso da organização), "Envio agendado para …", "Reagendar", "Cancelar agendamento". |
| `components/envelopes/phase2-routes.ts`         | URLs de `agendamento` e `lembretes` montadas a partir de `envelopes.show` enquanto as rotas não estão no Wayfinder; `reminderSummary`, `zonedInputValue`.      |
| `components/pdf/document-switcher.tsx`          | Navegação entre arquivos (editor de campos, detalhe, página pública). Rola na horizontal no celular.                                                           |
| `components/templates/template-picker.tsx`      | **Stub** criado por este agente só para compilar; o agente de modelos o substitui (contrato: `TemplatePicker({ onPicked? })`).                                 |

### Wizard

- **Passo 1 (vários arquivos).** A dropzone aceita vários arquivos de uma vez até `limits.max_documents`; se algum for inválido, nenhum sobe e a mensagem diz qual. O upload é uma fila (um `POST document.store` por arquivo, na ordem escolhida: "Enviando arquivo 2 de 3: anexo.pdf"). A ordem muda por `PATCH envelopes.update { document_order }` (autosave `documents`, com ordem otimista até a resposta). Remover um arquivo usa `DELETE document.destroy?document={ulid}` e tira do estado local só os campos daquele arquivo. O polling de processamento recarrega também `documents`.
- **Passo 1 (lembretes).** Com `reminders.available`, o switch "Fase 2" vira o `RemindersControl`; grava por `PUT documentos/{envelope}/lembretes` (autosave `reminders`). O resumo ("A cada 2 dias · até 3 lembretes") é calculado no cliente com a mesma fórmula do servidor, para não exibir o valor antigo durante o autosave. O selo "Padrão da organização" aparece enquanto `is_default`.
- **Passo 2 (papéis).** Cada linha ganha "Tipo de participante" (Signatário, Testemunha, Aprovador, Visualizador, rótulos de `participant_roles`) com uma frase sobre o efeito (`participantRoleDescriptions` em `lib/labels.ts`); o rótulo livre (`role`: "Locatária") continua ao lado. Visualizador aparece com o ícone de olho no lugar do número (não tem vez); a numeração conta só quem participa. Botões "Adicionar aprovador" e "Adicionar visualizador". O sync envia `participant_role` **só com a flag**. Trocar o papel remove do estado local os campos que deixaram de valer (tudo do visualizador; assinatura e rubrica do aprovador) e avisa com um toast.
- **Passo 3.** Seletor de arquivo acima do documento ("Posicionar campos no arquivo", com a contagem de campos de cada um); cada campo novo leva `document_id` e o sync envia `fields[].document_id` só com a flag. Visualizadores ficam fora de "Adicionar campo para"; com um aprovador ativo, Assinatura e Rubrica ficam desabilitadas (com a explicação). O painel "Por participante" soma os campos de cada um em todos os arquivos. O aviso "Sem campo de assinatura" considera só signatário e testemunha.
- **Passo 4.** Lista os arquivos (com campos por arquivo), mostra o tipo de cada participante, a linha "Lembretes" no resumo e o `ScheduleSendCard`. O agendamento só é oferecido sem pendências e com cota, e espera o autosave terminar (uma gravação depois de agendar cancelaria o agendamento). Com agendamento, o botão principal diz "Enviar agora". As pendências novas (por arquivo e por papel) vêm prontas do backend.

### Detalhe do documento

Com mais de um arquivo: seletor acima do visualizador (campos filtrados por `document_id`), lista "arquivos" abaixo com original / final / evidências de cada um, menu "Baixar" agrupado por arquivo, um `HashBox` por arquivo na aba Trilha e "Arquivos" em Detalhes. Com papéis: aba "Participantes", selo do papel no card, "Aprovação registrada em …" para o aprovador, visualizador sem "aguarda a vez" ("Cópia para acompanhamento enviada em …") e fora de "Lembrar pendentes". Com `reminders.available`: "Lembretes: a cada N dias …", "último lembrete automático …" no card e o cartão de envio agendado com "Cancelar agendamento".

### Página pública (`sign/show.tsx`)

- `action` decide o texto: "Sua assinatura" (signatário, igual à Fase 1), "Sua assinatura como testemunha", "Sua aprovação" (aprovador — sem captura da representação visual; o `POST sign.complete` vai **sem** `signature` e sem `initials`). O botão usa `action.button_label`; a recusa do aprovador é "Recusar aprovação". A declaração e a caixa de aceite vêm de `consent` (variantes por papel, já resolvidas no servidor). O stepper do cabeçalho vira "Aprovar" / "Acompanhar" via `steps` do `SignerLayout`.
- **`view` (visualizador).** Documento em leitura, "Cópia para acompanhamento", o `copy.notice` do servidor e os botões de cópia final por arquivo quando disponíveis. Sem aceite, sem recusa.
- **Vários arquivos.** `DocumentSwitcher` com o estado de cada arquivo ("Ainda não aberto", "N campos pendentes", "Aberto · sem pendências"). Cada arquivo é aberto pela sua `pdf_url` (é isso que registra a entrega à sessão); o aceite só habilita quando **todos** foram entregues — começando pelo `presented` do servidor, para um recarregamento não obrigar a abrir tudo de novo. "Próximo campo" atravessa arquivos. A tela diz "aberto", nunca "lido": a entrega não prova leitura.
- O comprovante (`ReceiptCard`) mostra `receipt.action_label`, "Aprovação registrada." para o aprovador e, com vários arquivos, o SHA-256 e a cópia final de cada um.

### Evidências e verificação pública

- **Evidências:** seção "Documentos deste envelope (N)" com os cinco resumos de cada arquivo e "Registros sobre este arquivo" (quem, qual registro, quando, SHA-256 da versão aceita); no participante, selo do papel, "Registro" e "Arquivos aceitos". A conferência local aceita o final, o enviado ou o consolidado de **qualquer** arquivo.
- **Verificação:** só quando `result.documents` existe (envelope com mais de um arquivo — lista fechada de chaves): "N arquivos" no cabeçalho e, em Integridade, enviado/final de cada arquivo. A conferência no navegador compara com todos; a manual (por resumo digitado) diz qual arquivo conferiu (`file_check.document`). O papel aparece só quando o servidor manda `participant_role_label`.

### Pendências fora do front (para o relatório de integração)

1. Rotas `envelopes.schedule`, `envelopes.schedule.cancel` e `envelopes.reminders.update` ainda não estão em `routes/web.php`: o front usa `phase2-routes.ts`. Depois de registrar, rodar `php artisan wayfinder:generate --with-form` e trocar pelos helpers do Wayfinder.
2. `EnvelopeController::edit/show` ainda não mescla a prop `reminders` — sem ela, lembretes e agendamento não aparecem (comportamento da Fase 1).
3. `HandleInertiaRequests::features()` ainda não compartilha `participant_roles` (o wizard usa `domain_features`) nem deriva `multi_document`/`reminders` das flags reais.

## Fase 2 — onda B: canais, PIN, CPF, captura simples e CNPJ

Contratos do backend: `docs/fase-2/canais-e-pin.md` §9 (C-CAN), `docs/fase-2/identidade.md` §7 (C-ID) e `docs/fase-2/branding.md` §6/§8 (C-BRAND). Mesma regra de ouro da onda A (roadmap T8): **cada recurso só aparece com a sua flag**; com todas desligadas, telas, textos e payloads são os de antes. A flag só liga a interface — quem autoriza e valida é o servidor.

### Vocabulário (T1)

- O código por SMS/WhatsApp **prova a posse do canal** (o celular que recebeu), não a identidade. A tela diz isso no wizard.
- O PIN é um **segredo combinado pelo remetente por fora** do AssinaVelox, pedido **depois** do código e nunca no lugar dele. O sistema nunca envia nem exibe de novo o PIN.
- O campo CPF é **conferido só pelos dígitos**; a tela diz que isso não confirma que a pessoa é a titular.
- A foto é **captura simples**: fica anexada ao registro do aceite e **não é verificação de identidade** (sem comparação de rostos, sem análise da imagem, sem leitura do documento).
- O carimbo visual é **representação visual, não prova**.
- `tests/Feature/Phase2/VocabularyTest.php` varre `resources/js` inteiro.

### De onde vem cada flag

| Recurso                            | Lido de                                                                | Onde aparece                                                 |
| ---------------------------------- | ---------------------------------------------------------------------- | ------------------------------------------------------------ |
| SMS/WhatsApp (§2.9)                | prop `channels.enabled` do wizard (`ChannelAvailability::wizardProps`) | wizard, passo 2 (celular, canal do convite, canal do código) |
| PIN do remetente (§2.9)            | `channels.pin.enabled`                                                 | wizard, passo 2                                              |
| Campo CPF (§2.11)                  | `features.cpf_field`                                                   | wizard, passo 3 (paleta)                                     |
| Carimbo visual (§2.8)              | `features.branding` (via `paletteFieldTypes` de `field-types.ts`)      | wizard, passo 3 (paleta)                                     |
| Captura simples (§2.10)            | `features.identity_capture` + prop `capture_requirements` do wizard    | wizard, passo 2                                              |
| Autopreenchimento por CNPJ (§2.11) | `features.cnpj_lookup`                                                 | Configurações › Geral e cadastro                             |

As chaves novas de `Features` (`pin_auth`, `sender_domains`, `cpf_field`, `cpf_lookup`, `cnpj_lookup`, `identity_capture`) são **opcionais** no tipo: ausente = desligada. A página pública, o detalhe e as evidências **não olham flag** — mostram o que o servidor mandar (`auth`, `identity_capture`, campo `cpf`/`stamp` num envelope já enviado).

### Componentes novos

| Arquivo                                                | Papel                                                                                                                                                                                                         |
| ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `components/envelopes/recipient-channel-fields.tsx`    | `RecipientChannelFields` (celular com máscara, "Enviar convite por", "Como … se autentica", motivos de indisponibilidade e selo "simulado") e `RecipientPinControl` (definir, gerar, trocar e remover o PIN). |
| `components/envelopes/capture-requirement-control.tsx` | Caixas "Rosto", "Documento (frente)", "Documento (verso)" por participante; grava por `fetch` JSON em `PUT envelopes.recipients.identity_capture`.                                                            |
| `components/envelopes/field-type-extras.ts`            | Extensão de `field-types.ts` (área C-BRAND) com o tipo `cpf`: ícone, tamanho, mínimo (40×9 pt), placeholder e dica; `editorPaletteTypes(features)`.                                                           |
| `components/identity/phone.ts`                         | Máscara "+55 11 91234-5678" e pré-validação de celular (DDD, 9 dígitos, estrangeiro por tamanho E.164). O servidor decide.                                                                                    |
| `components/identity/pin.ts`                           | Regras de PIN iguais às de `SenderPins` (tamanho, repetido, sequência com volta) e `generatePin` com `crypto.getRandomValues`.                                                                                |
| `components/identity/http.ts`                          | `postJson`/`requestJson` com XSRF, tempo-limite e "rede/tempo esgotado = desconhecido" (T5).                                                                                                                  |
| `components/identity/camera-capture-dialog.tsx`        | Uma foto: explicação da permissão, `getUserMedia` (câmera frontal espelhada só na prévia), escolher arquivo, pré-visualizar, "Usar esta foto", "Refazer".                                                     |
| `components/identity/capture-step.tsx`                 | `CaptureStepCard` (etapa na tela `sign`) e `CaptureStepPreview` (aviso na tela `identify`).                                                                                                                   |
| `components/identity/cnpj-lookup.tsx`                  | `useCnpjLookup`, `CnpjLookupButton`, `CnpjLookupNotice` (mensagem, atribuição da fonte, selo "simulado", "preencha manualmente").                                                                             |
| `components/sign/pin-card.tsx`                         | Etapa do PIN depois do código (`POST sign.pin.verify`).                                                                                                                                                       |

### Wizard

- **Passo 2.** Com `channels.enabled`: celular (obrigatório quando o convite ou o código usam SMS/WhatsApp), "Enviar convite por" (Só e-mail / E-mail + SMS / E-mail + WhatsApp — o e-mail sai sempre) e o canal do código a partir de `channels.auth_methods`. Canal indisponível fica desabilitado **com o motivo do servidor em texto visível** (não só em `title`); provedor simulado ganha o selo "simulado" e o `notice`. Sem a flag, o bloco é o da Fase 1 (chips "Código por e-mail" e "Token SMS · Fase 2").
- **Celular e gravação automática.** O sync só sai quando todo celular exigido está completo; um número pela metade num participante só por e-mail simplesmente não é enviado (`phone` viaja completo ou vazio). A pendência "Informe um celular válido…" aparece no passo 4.
- **PIN.** Com `channels.pin.enabled`: "Definir PIN" abre um diálogo com o campo, "Gerar" e "Copiar", e o aviso de que o PIN não é exibido de novo. O PIN fica **só no estado local** até o próximo `recipients.sync`, viaja uma única vez (`pin`) e é descartado quando a gravação volta; depois a tela só conhece `has_pin`. "Remover PIN" envia `remove_pin: true`. Enquanto há PIN a salvar, o envio fica bloqueado ("Aguarde o PIN ser salvo antes de enviar.").
- **Fotos.** Com `features.identity_capture`, cada participante que registra aceite (visualizador não) ganha as caixas de foto, habilitadas depois que a linha é salva (precisa do ULID). O verso só com a frente, como no servidor.
- **Payload.** `channel`, `auth_method` e `phone` só com `channels.enabled`; `pin`/`remove_pin` só com `channels.pin.enabled`. Enquanto o `RecipientWizardResource` não devolver os campos de canal (`has_pin` é o sinal), a resposta do sync é mesclada com o que o cliente já sabe, para o canal escolhido não "voltar" para e-mail.
- **Passo 3.** Paleta por `editorPaletteTypes({ branding, cpf_field })`. CPF entra depois de "Texto livre" com placeholder `000.000.000-00` (em `placeholder` e `options.placeholder`). Carimbo nasce **não obrigatório**, sem fonte, com a dica "representação visual, não prova". A camada aceita soltar `cpf` (`FieldLayer<T, D>` ganhou o parâmetro `D`, com padrão `FieldType` para o editor de modelos).
- **Passo 4.** Com canais ou PIN, a linha do participante diz "E-mail + SMS · código por sms + PIN" em vez de "e-mail com código de verificação".

### Página pública (`sign/show.tsx`)

- **Código por canal (`auth`).** "Receber código por SMS", "Enviamos um código por SMS para +55 •••••••5678", selo "simulado" e o aviso do simulador. Canal indisponível: caixa com o motivo do servidor e "Fale com {remetente}", sem botão. O reenvio mantém a contagem regressiva. Sem `auth`, os textos são exatamente os da Fase 1 ("Receber código por e-mail").
- **PIN (`auth.step === 'pin'`).** `PinCard` substitui o campo do código; erros em `errors.pin`; tentativas restantes abaixo de 3. `pin.locked_until`: contagem regressiva e os controles do código desabilitados até lá, depois "peça um novo código". `pin.blocked`: "PIN bloqueado — fale com {remetente}", sem controles.
- **CPF.** Máscara `000.000.000-00`, validação dos dígitos no cliente (CPF errado, mesmo opcional, bloqueia o envio), erro do servidor em `errors['fields.{id}']` e a frase "O CPF é conferido só pelos dígitos; isso não confirma que a pessoa é a titular."
- **Captura (`identity_capture`).** Na tela `identify`, só o aviso do que será pedido. Na tela `sign`, o cartão com cada foto: câmera ou arquivo, pré-visualização, refazer. O envio é `fetch` multipart (`image` + `source`) com `Accept: application/json`; a resposta 201 substitui o bloco local. Tempo esgotado/rede: "Não recebemos a confirmação do envio" (reenviar substitui a anterior). O aceite só habilita com `complete`.
- **Carimbo.** Nunca interativo e nunca pendente; mostra `StampPreview` com `sender.brand` (ou uma caixa "Carimbo · {organização}" sem marca).

### CNPJ

- **Configurações › Geral** (`POST settings.organization.cnpj`): botão "Preencher pelo CNPJ" ao lado do campo, habilitado com CNPJ completo e válido. Razão social vazia é preenchida; campo já preenchido com outro valor não é sobrescrito — a sugestão aparece com "Usar". O nome de exibição nunca muda sozinho.
- **Cadastro** (`POST cnpj.lookup`): consulta automática ao completar um CNPJ válido; preenche "Empresa" só se estiver vazio (ou ainda com a sugestão anterior).
- Qualquer desfecho mostra a mensagem do servidor, a atribuição da fonte e o selo "simulado"; o formulário **nunca é bloqueado**. Sem a flag, nenhuma chamada é feita.

### Evidências e detalhe

- **Evidências:** por participante, `identity_captures` com miniatura, tipo, data, dimensões e SHA-256, e a nota `identity_capture_notice` ("Não houve verificação de identidade…"). `auth_methods` aceita `sms_otp`, `whatsapp_otp` e `sender_pin` (rótulos em `authMethodLabels`).
- **Detalhe:** o chip do canal diz "E-mail + SMS"/"E-mail + WhatsApp" com o ícone do canal.

### Tipos

`FieldType` ganhou `stamp`. O CPF está em `SigningFieldType = FieldType | 'cpf'` (espelho completo de `App\Enums\FieldType`) porque `field-types.ts` indexa tabelas por `FieldType` e ainda não tem `cpf`; quando tiver, os dois voltam a ser um tipo só. `AuthMethod` ganhou `sms_otp` e `whatsapp_otp`; `SignerAuthMethod` soma `sender_pin`. Novos: `CaptureKind`, `WizardChannels`, `ChannelInfo`, `AuthMethodInfo`, `SignerAuth`, `IdentityCaptureStep`, `IdentityCaptureItem`, `IdentityCaptureEvidence`, `CnpjLookupResult`. `AuditEventType` ganhou os 11 eventos da onda B.

### Pendências fora do front (integração)

1. `HandleInertiaRequests::features()`: compartilhar `ChannelFeatures::forOrganization()` e `IdentityFeatures::forOrganization()` (e o global de `cnpj_lookup` sem organização, para o cadastro).
2. `EnvelopeController::edit`: props `channels` (`ChannelAvailability::wizardProps`) e `capture_requirements` (`IdentityCaptures::requirementsForEnvelope`); `RecipientWizardResource` com `RecipientChannels::wizardFields()`.
3. `SignerPageProps`: **`signer_auth`** (`SignerAuthProps::for`), `auth_methods` (`SignerAuthProps::authMethods`), `identity_capture` (`CaptureStep::props`) e `sender.brand`/`sender.logo_url`. **Não usar o nome `auth`** que o contrato do C-CAN propõe: `auth` já é a prop compartilhada `{ user }` do `HandleInertiaRequests`, e o Inertia mescla as compartilhadas nas da página. Foi um bug real nesta onda — lendo `props.auth`, a página pública recebia `{ user: null }`, tratava o canal como indisponível e escondia "Receber código por e-mail" (o `SignerFlowTest` travava). A página agora lê `signer_auth` e só aceita `auth` quando ele tem a forma de `SignerAuth` (`signerAuthOf`).
4. `EvidenceDossier`: `identity_captures` por participante e `identity_capture_notice`.
5. `field-types.ts` (C-BRAND): acrescentar `cpf` às tabelas; aí `SigningFieldType` e `FieldType` podem ser unificados e `field-type-extras.ts` encolhe.
6. `php artisan wayfinder:generate --with-form` depois das rotas finais (os helpers usados — `sign.pin.verify`, `sign.capture.store`, `envelopes.recipients.identity_capture`, `settings.organization.cnpj`, `cnpj.lookup` — já estavam gerados no ambiente).

## Fase 2 — onda C: certificado do participante, assinaturas, carimbo, dossiê e preservação

Contratos do backend: `docs/fase-2/a1-do-participante.md` §7 (K-A1), `docs/fase-2/carimbo-e-dossie.md` §7 (K-TSA) e `docs/fase-2/retencao-e-preservacao.md` §6 e §10 (K-RET). Mesma regra de ouro (roadmap T8): **cada recurso só aparece com a sua flag**; com tudo desligado, telas, textos e payloads são os de antes.

### Semântica (T1, T2, T3)

- Três coisas, três nomes: **aceite eletrônico** (todos os participantes, inalterado) ≠ **assinatura com o certificado do próprio participante** ("Assinado com certificado A1 de {nome} (emitido por {AC})", sempre o `label` do servidor) ≠ **assinatura da operadora** ("Certificado da operadora", que "não é a assinatura pessoal de nenhum participante").
- `SignatureStatus` ganhou `participants_a1` e `mixed`. `hasCryptographicSignature`, `hasOperatorSignature` e `hasParticipantSignatures` (`lib/labels.ts`) decidem "PDF assinado" e o selo verde; nenhuma tela compara mais com `'company_a1'` para isso.
- Perfil: a interface mostra o que o servidor registrou (`signature_profile`), com `PAdES-B-B` como padrão. Nenhum texto do front fala em B-T/B-LT/B-LTA.
- Carimbo do tempo: o rótulo é **sempre** o `label` do servidor ("Carimbo do tempo da operadora — não é carimbo ICP-Brasil"); o front não escreve rótulo próprio para o tipo do carimbo. Carimbo de teste mostra o `test_notice`.
- Certificado de teste: `TestCertificateNotice` com o `kind_label` do servidor ("Certificado de TESTE — não é ICP-Brasil e não tem validade jurídica") na prévia, nas evidências e na verificação.
- Revogação aparece sempre como **não verificada**; integridade, confiança da cadeia e revogação são linhas separadas.

### De onde vem cada recurso

| Recurso                                   | Lido de                                                                                         | Onde aparece                                            |
| ----------------------------------------- | ----------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| Assinar com o próprio certificado (§2.12) | `GET sign.certificate.show` (404 = desligado/não oferecido; **não** existe chave em `features`) | página pública (`sign`, comprovante)                    |
| Lista de assinaturas de participantes     | prop `participant_signatures` (evidências), `result.participant_signatures` (verificação)       | evidências, verificação, detalhe (se a prop vier)       |
| Carimbos do tempo (§2.13)                 | prop `timestamps` (evidências), `result.timestamps` (verificação) — **ainda não enviadas**      | evidências, verificação                                 |
| Dossiê ZIP (§2.13)                        | `features.dossier_export` (opcional; ausente = desligada)                                       | detalhe (painel de conclusão), evidências, lista (lote) |
| Preservação (§2.19)                       | prop `legal_hold` do detalhe **ou** `GET envelopes.legal_hold.show`                             | detalhe (selo + painel)                                 |
| Registro excluído pela retenção           | `result.retention.purged`                                                                       | verificação                                             |

### Componentes novos

| Arquivo                                                    | Papel                                                                                                                                                                                                     |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `components/certificates/participant-certificate-card.tsx` | Cartão "Assinar também com o seu certificado digital": estado por `stage`, intenção, desistência, arquivo + senha → prévia → consentimento → envio, acompanhamento da aplicação.                          |
| `components/certificates/certificate-facts.tsx`            | `CertificateFacts` (titular com CPF mascarado, emissor, validade, tipo, série, impressão digital) e `TestCertificateNotice`.                                                                              |
| `components/certificates/http.ts`                          | `certificateRequest` (GET/POST multipart com XSRF, `no-store`, tempo-limite; rede/tempo esgotado = desconhecido, T5).                                                                                     |
| `components/verification/crypto-signature-list.tsx`        | `ParticipantSignatureList` (evidências: por participante e por arquivo, com integridade/cadeia/revogação, perfil, SHA-256 da revisão) e `PublicParticipantSignatureList` (nome mascarado, sem CPF/série). |
| `components/verification/timestamp-list.tsx`               | `TimestampList` — carimbos com rótulo do servidor, horário local + UTC com milissegundos, série/política/emissor/resumo nas evidências, aviso de teste e a nota do servidor.                              |
| `components/dossier/use-dossier-export.ts`                 | Pedido (`postJson`) + polling de 2,5 s em `status_url` (teto ~20 min); erros 403/404/422/429 em PT-BR.                                                                                                    |
| `components/dossier/dossier-export-dialog.tsx`             | Estado da geração, "Baixar ZIP" com o `download_url` recebido, vencimento do link, SHA-256 do ZIP, rótulo do carimbo do manifesto e "O que vem no pacote".                                                |
| `components/dossier/dossier-buttons.tsx`                   | `DossierButton` (documento concluído + flag) e `BulkDossierButton` ("Baixar dossiês" na barra de seleção).                                                                                                |
| `components/envelopes/use-envelope-legal-hold.ts`          | Prop `legal_hold` ou `GET envelopes.legal_hold.show`; recarrega depois de cada visita Inertia bem-sucedida do detalhe (preservar/liberar voltam com `back()`).                                            |
| `types/signatures.ts`                                      | Espelhos dos contratos: estado/prévia/erros do certificado, assinaturas (evidências e pública), carimbos, `DossierExport`, `PublicRetentionNotice`.                                                       |

### Página pública (`sign/show.tsx`)

- **Onde.** Na tela `sign`, um cartão próprio abaixo do cartão do aceite — separado da representação visual e da caixa de aceite, que continuam exatamente como antes (o aceite não depende do certificado). No comprovante (`completed`, `finalizing`, `already_signed_pending_others`), abaixo do recibo. Nunca para aprovador e visualizador (o servidor também devolve 404).
- **Descoberta.** Um `GET sign.certificate.show` ao montar. 404 → o cartão não existe. Outros erros → "Não foi possível carregar…" com "Tentar de novo".
- **Antes de todos aceitarem** (`choose`, `awaiting_others`): "Quero assinar também com meu certificado" registra só a escolha (`intent`) — sem arquivo e sem senha — e a tela diz para voltar pelo link quando o documento estiver pronto. "Desistir" pede confirmação.
- **Conteúdo congelado** (`ready_to_upload`, `failed`): arquivo `.pfx`/`.p12` (validado no cliente por extensão e `max_upload_kb` antes de subir) + senha → "Conferir certificado" (`inspect`). A prévia mostra o `label`, o tipo (aviso destacado se for teste), titular com CPF mascarado, emissor, validade, série, impressão digital, os `warnings` do servidor, a regra de correspondência e o aviso quando o nome difere. O consentimento específico é a caixa `consent.checkbox_label` (desmarcada) com o `consent.statement` integral e a versão. "Autorizar e assinar com este certificado" envia `certificate`, `password`, `consent=1`, `consent_version` e o `fingerprint` da prévia (`store`, 202).
- **Senha.** Campo `type="password"` com `autoComplete="off"`, fora de `<form>` (nada de "salvar senha"), `data-*-ignore` para gerenciadores; só na memória do componente; apagada depois do envio, ao trocar o arquivo, em `wrong_passphrase` e em envio sem resposta. Nada em `localStorage`, URL ou log.
- **Erros.** Título curto por código (`participantCertificateErrorTitles`) + o `message` do servidor; o campo culpado fica `aria-invalid`. 429 mostra a espera; 403 `not_authenticated`, 409 e `certificate_changed` reconsultam o estado.
- **Depois do envio** (`queued`, `applying`): consulta o estado a cada 4 s (teto de 10 min, depois "Atualizar"); ao sair desse estado, recarrega `screen`/`receipt`/`others` para o comprovante mostrar o arquivo final. `applied`, `expired`, `withdrawn` e `closed` têm texto próprio, sempre dizendo que o aceite eletrônico continua valendo.
- Mobile-first: uma coluna, botões de largura total abaixo de `sm`, caixa de arquivo com área de toque de 44 px.

### Detalhe, evidências e verificação

- **Evidências.** "Situação da assinatura" ganhou a lista de assinaturas de participantes (por arquivo: revisão, integridade, cadeia, revogação, perfil, SHA-256), mantendo "Certificado da operadora" à parte ("assinou por último" no `mixed`). Nova seção "Carimbo do tempo" quando `timestamps.items` vier. "Baixar dossiê (ZIP)" no cabeçalho.
- **Verificação.** `PublicParticipantSignatureList` abaixo da declaração, "Resultado técnico da validação" para qualquer `signature_status` diferente de `none`, "Carimbo do tempo" quando `result.timestamps` vier. Com `result.retention.purged`, uma tela própria: "Removido por política de retenção em {data}", a mensagem do servidor e **só** o resumo final com a conferência local — sem selo de "concluído", participantes ou marcos.
- **Selo** (`seal.ts`): textos próprios para `participants_a1` e `mixed`.
- **Detalhe.** Painel de conclusão com os textos de `participants_a1`/`mixed`, "Baixar PDF assinado" para qualquer assinatura criptográfica, "Baixar dossiê (ZIP)", selo **Preservado** ao lado do status, `EnvelopeLegalHoldPanel` (preservar e liberar com motivo obrigatório, pelos diálogos da área de retenção) abaixo do conteúdo, "Excluir rascunho" oculto quando preservado. A lista completa de assinaturas fica nas evidências (o detalhe a mostra se o controller passar `participant_signatures`).
- **Lista.** "Baixar dossiês" na barra de seleção → `POST dossiers.bulk` com os ids, a mesma janela de acompanhamento e a nota de que só documentos concluídos entram.

### Pendências fora do front (integração)

1. `HandleInertiaRequests::features()`: `dossier_export` (`DossierFeature::enabled`), `retention_policies` (`RetentionFeature::enabled`), `operator_tsa`/`pades_bt` (`TimestampFeatures`). Sem `dossier_export`, os botões do dossiê não aparecem.
2. `EnvelopeEvidenceController`: `timestamps => TimestampEvidence::forEnvelope($envelope)`. `PublicVerification::result`: `timestamps => TimestampEvidence::forPublic($envelope)` (e a chave no `VerificationContractTest`). O front já as consome; sem elas, a seção não aparece.
3. `EnvelopeController::show` (opcional): `legal_hold => RetentionPresenter::forEnvelope(...)` evita o GET extra; `participant_signatures => ParticipantSignatureViews::forEvidence(...)` mostra a lista no próprio detalhe.
4. `php artisan wayfinder:generate --with-form` na integração (os helpers usados — `sign.certificate.show`, `envelopes.dossier.store`, `dossiers.bulk`, `envelopes.legal_hold.show` — já estavam gerados no ambiente).

## Fase 3, parte 1 (onda E): assinaturas externas e estado de longo prazo

Contratos do backend: `docs/fase-3/assinatura-externa-a3.md` §7 (P3-EXT, flag `a3_signing`), `docs/fase-3/gov-br.md` §6 (P3-GOV, flag `govbr_return` + trava `finalizer_integration`) e `docs/fase-3/longo-prazo.md` §8 (P3-LTV, flags `pades_ltv` e `pades_ltv_advertise`). Mesma regra de ouro: **cada recurso só aparece quando o servidor o oferece**. Com as flags desligadas, as rotas novas respondem 404, os cartões não existem e as props novas não chegam — telas, textos e payloads são os de antes.

### Semântica (T1, T2, T3)

- Cada meio tem nome próprio, sempre o **rótulo do servidor**: A3 por componente local (`participant_a3`) ≠ componente externo (`participant_external`) ≠ A1 por arquivo ≠ operadora ≠ aceite eletrônico. O front não escreve rótulo de tipo de assinatura.
- **Simulador**: selo "simulado" (`SimulatedTag`) em todo lugar onde aparece (escolha do componente, reserva, conclusão, evidências, verificação) e o texto "simulado — nenhum token foi usado" vindo do servidor. Nunca é chamado de token nem de A3. Na verificação pública, a integridade de uma assinatura simulada é "Íntegra — assinatura simulada" em cinza, nunca verde.
- **gov.br**: a tela mostra `trust.accepted_label` antes do envio ("Assinatura digital de terceiro, cadeia não verificada" enquanto não houver âncora) e `signature.label` depois do aceite. O aviso de cadeia não verificada é exibido em destaque sem âncora.
- **Perfil**: nenhum texto anuncia perfil além de PAdES-B-B. O estado de longo prazo mostra o `label` técnico do servidor, datas e o `notice` sempre visível; `level` (B-T/B-LT/B-LTA) **não é exibido** e o perfil mostrado é `announced_profile`, como veio.
- **ICP-Brasil** nunca é afirmado: cadeia "não verificada" ou "validada até uma âncora configurada", revogação "não verificada", sempre em linhas separadas.

### Componentes novos

| Arquivo                                                    | Papel                                                                                                                                                                                                                                                                                                                                                 |
| ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `components/external-signing/external-signing-card.tsx`    | Cartão "Assinar também com certificado em token (A3)": estado por `stage`, intenção/desistência, escolha do componente (simulador ou token neste computador), verificação do componente, leitura do certificado, preparo do resumo por arquivo, assinatura, acompanhamento e textos de conclusão. Exporta `WithdrawControl`.                          |
| `components/external-signing/govbr-return-card.tsx`        | Cartão "Assinar também no portal gov.br": intenção, reserva, por arquivo o passo a passo (baixar → abrir o portal em outra aba → enviar), motivo exato da recusa, rótulo honesto do resultado.                                                                                                                                                        |
| `components/external-signing/local-signer-bridge.ts`       | `LocalSignerBridge` do navegador (brief `a3-componente-local.md` §7): `detect()`, `getSigningCertificate()`, `signDigest()`; implementação NexU (`/v1/status`, `/v1/signing-certificate`, `/v1/sign`, HTTPS e depois HTTP em 127.0.0.1) com erros classificados (componente ausente, versão antiga, token ausente, PIN/cancelamento, tempo esgotado). |
| `components/external-signing/http.ts`                      | `signerRequest` (GET/POST JSON ou multipart, XSRF, `no-store`, tempo-limite; rede/tempo esgotado = desconhecido, T5).                                                                                                                                                                                                                                 |
| `components/external-signing/parts.tsx`, `reauth-note.tsx` | `CardShell`, `Note`, `ErrorAlert`, `StepList`, `SimulatedTag`; `ReauthNote` pede um novo código quando `authenticated` é falso (mesmo mecanismo do A1, `#certificate-reauth`).                                                                                                                                                                        |
| `components/verification/long-term-state.tsx`              | `LongTermState` (estado técnico de longo prazo) e `HashHistoryList` (resumos vigente e anteriores).                                                                                                                                                                                                                                                   |
| `types/external-signing.ts`                                | Espelhos de `ExternalSigningState`, `ExternalPending`, `SimulatorCertificateResponse`, `GovBrReturnState`, `LtvTechnicalState`, `HashHistoryEntry` e do corpo de erro.                                                                                                                                                                                |

### Página pública (`sign/show.tsx`)

- **Onde.** Na tela `sign` e no comprovante (`completed`, `finalizing`, `already_signed_pending_others`), abaixo do cartão do certificado A1, os dois cartões novos. Nunca para aprovador e visualizador (o servidor também devolve 404). Cada um é descoberto por um `GET` ao montar (`sign.external.show`, `sign.govbr.show`); 404 = o cartão não existe.
- **Token (A3), antes de todos aceitarem** (`choose`, `awaiting_others`): só a escolha (`intent`), sem componente nem certificado; "Desistir" pede confirmação.
- **Token, com o arquivo pronto** (`ready_to_sign`): três passos. (1) Componente: simulador (só quando `endpoints.simulator_sign` existe e o componente está disponível, com o selo "simulado") ou "Token ou cartão neste computador" — desabilitado com o motivo do servidor enquanto `production_enabled` for falso, que é o caso hoje. Com o NexU habilitado: instruções de instalação, "conecte o token", as `browser_notes` (permissão de rede local, TLS local, origem autorizada) e "Verificar componente". (2) Certificado: o componente devolve o certificado público (o simulador, pelo `GET …/simulador/certificado`). (3) Por arquivo: "Preparar resumo" (`POST prepare`, `mode: raw`) mostra o que o **servidor** leu do certificado (tipo, aviso de teste, titular com CPF mascarado, emissor, validade, impressão digital), a cadeia, a revogação e o vencimento do resumo; "Assinar com o token" chama `signDigest` (o PIN é digitado na janela do componente) e envia `signature`, `certificate`, `chain`, `signature_algorithm` (`POST submit`); "Assinar com o simulador" envia só `pending_id` ao servidor (o resumo nunca sai do cliente para o simulador).
- **Erros do token** (no navegador, nada é enviado): componente ausente ou bloqueado (instalação, permissão de rede local, TLS local), versão antiga, token ou cartão não encontrado, operação não concluída (PIN errado, token removido, cancelado — a distinção do NexU está NÃO CONFIRMADA no brief), tempo esgotado. **Erros do servidor**: título curto por código (`externalSigningErrorTitles`) + o `message`; resumo vencido, reutilizado, de outra revisão ou recusado some da memória e o estado é reconsultado ("Preparar de novo").
- **Celular**: a opção do token fica desabilitada com o aviso de que A3 exige um computador com o componente instalado e o token conectado.
- **gov.br**: "Quero assinar também no gov.br" (`intent`) → com `ready`, "Reservar versão para assinar no gov.br" (`reserve`) → por arquivo: vencimento da reserva, "Baixar arquivo para assinar" (`expected_revision.download_url`, com tamanho e SHA-256), "Abrir assinador.iti.br" (nova aba, `noopener`) com a orientação de não editar, converter nem salvar em outro programa, e o campo do PDF assinado (validado por extensão e `max_upload_mb` antes de subir; conferência de até 5 min). Recusa: título por código (`govbrReturnErrorTitles` — não é o arquivo esperado, alterado além da assinatura, mais de uma assinatura, certificado de outra pessoa…) + o motivo exato do servidor; a última recusa registrada no pedido (`failure`) continua visível com o número de envios. Aceite: `signature.label` + `description`, fatos do certificado e aviso de teste.
- **Segredos**: resumo (`pending.digest`) e identificador da chave (`keyHandle`) só na memória do cartão, descartados depois do envio, ao trocar de componente, ao desistir e na conclusão; nada em `localStorage`, URL ou log. Não há campo para chave, senha ou PIN.
- Todos os `stage` terminais (`applied`, `expired`, `withdrawn`, `closed`, `other_method`, `completed`) dizem que o aceite eletrônico continua valendo. Ao passar a `applied`/`completed`, a página recarrega `screen`/`receipt`/`others`.

### Detalhe, evidências e verificação

- `SignatureStatus` ganhou `participant_a3` e `participant_external`; `hasParticipantSignatures` passa a incluí-los e `hasExternalParticipantSignatures` é novo. Selo (`seal.ts`), painel de conclusão do detalhe e "Certificado da operadora (assinou por último)" têm textos próprios para esses valores, com a menção ao simulador quando alguma assinatura da lista é `simulated`.
- `ParticipantSignatureList` / `PublicParticipantSignatureList`: aceitam `kind` A3/externo, mostram o selo "simulado", o componente e o modo (`raw`/`cms`) e acrescentam à introdução que a chave não passou pela plataforma.
- **Evidências**: seção "Material de longo prazo (estado técnico)" quando chegar `ltv` (fora de `not_applicable`) ou `hash_history`.
- **Verificação**: "Resumos anteriores do arquivo final" só quando `result.hash_history` existir; a conferência local reconhece um resumo anterior (âmbar, "versão anterior do arquivo final… o conteúdo do documento não mudou") e a manual aceita `matches = 'signed_previous'`.

### Conferido no navegador (2026-09-14)

Uma sonda temporária da suíte de navegador (apagada depois) percorreu, contra o backend real e o pdftool real, com `a3_signing` e `govbr_return` ligadas: código por e-mail → os dois cartões na tela `sign` (gov.br com o aviso de cadeia não verificada) → "Quero assinar também com certificado em token" → aceite → a finalização congela a base e espera → no comprovante, simulador → certificado de teste → "Preparar resumo" (fatos lidos pelo servidor, aviso de TESTE, cadeia e revogação não verificadas) → "Assinar com o simulador" → "simulado — nenhum token foi usado" → envelope concluído → verificação pública com o selo externo, "Íntegra — assinatura simulada" e o selo "simulado". Sem erros de JavaScript. Não conferido no navegador: componente real (NexU), devolução gov.br até o aceite, estado de longo prazo e histórico de resumos (sem props do servidor ainda).

### Pendências fora do front (integração)

1. **CSP**: `connect-src` da página pública só tem `'self'`. Para o NexU funcionar, `https://127.0.0.1:9895` e `http://127.0.0.1:9795` precisam entrar nela (de preferência só em `assinar/*`) — hoje o navegador bloquearia a chamada e a tela diria "componente não encontrado".
2. **Componente real**: nada da ponte NexU foi exercido contra um componente de verdade (produção desabilitada, sem token). Falta o piloto da §8 do P3-EXT, incluindo Local Network Access (Chrome 142+), TLS local e o mapeamento dos erros do NexU.
3. **Modo CMS** (Serpro, possivelmente): o contrato aceita `mode: cms`, mas a ponte do navegador só implementa o modo `raw` (o único componente documentado devolve assinatura bruta).
4. **gov.br na finalização** (P3-GOV §8): enquanto a trava `finalizer_integration` estiver desligada o cartão não aparece; as evidências e a verificação ainda não recebem a devolução gov.br na lista de assinaturas (não há prop) — quando vier, os tipos já aceitam o `label` do servidor.
5. **Longo prazo** (P3-LTV §8): `EnvelopeEvidenceController` ainda não envia `ltv` nem `hash_history`; `PublicVerification` ainda não envia `hash_history` nem `file_check.matches = 'signed_previous'` (decisão de produto §6). O front já os consome; sem eles, nada aparece.
6. **Reconfirmação de identidade no comprovante** (`SignerPageProps`): as props `otp`/`signer_auth` só chegam ao comprovante quando o envio do certificado **A1** está aberto para a pessoa (`ParticipantCertificateService::awaitsReturningSigner`). Para o token (A3) e o gov.br isso não acontece: quem volta depois da janela de download não tem onde digitar o código. Por isso o cartão só oferece "Receber código" quando o comprovante trouxe `otp` (`reauthAvailable`) e, sem ele, diz que a tela ainda não consegue pedir um novo código. Na prática, hoje o participante assina com o token na mesma sessão em que aceitou (conferido no navegador, ver abaixo).
7. **Texto de conclusão no comprovante** (`consent.completion_notice`/`receipt.completion_notice`, servidor): enquanto a assinatura com token está pendente, o comprovante continua dizendo "será concluído como aceite eletrônico com evidências, sem assinatura criptográfica" — só depois da aplicação o texto muda. O front imprime o que o servidor manda.
8. **Texto jurídico**: os avisos exibidos vêm do servidor (`ExternalSignatureLabels::notices()`, `GovBrReturnService` `instructions`/`notices`) e dependem da revisão jurídica de cada item.

## Responsividade

- Sidebar: `SidebarProvider` + `collapsible="offcanvas"`; abaixo de `md` (768px) vira `Sheet`; estado persistido no cookie `sidebar_state` (prop compartilhada `sidebarOpen`).
- Topbar: breadcrumb pai oculto `< sm`; busca ⌘K oculta `< md`; pill "Acesso restrito" só `≥ lg`.
- Tabelas: `DataTable` envolve o grid em `overflow-x-auto` com `minWidth`.
- Rails de 200px (pastas, configurações) somem abaixo de `md` e viram `FilterChip`/`Select`.
- Editor de campos: o rail de páginas vira uma faixa horizontal rolável abaixo de `md` e o passo 3 mostra um aviso ("A preparação dos campos funciona melhor no computador") **sem bloquear** — leitura, navegação entre páginas e ajuste dos campos existentes continuam funcionando no celular.
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
| Página pública   | OTP por SMS + selfie de verificação                    | código por e-mail; selfie não aparece                     | RECONCILIACAO §6 (selfie/SMS = Fase 2)                                |
| Página pública   | modo "Certificado" (ICP-Brasil) na captura             | "Enviar imagem" no lugar                                  | RECONCILIACAO §5 (certificado do signatário = Fase 2)                 |
| Página pública   | caixa de aceite já marcada                             | desmarcada, com a declaração completa visível abaixo      | `declaracao-de-aceite.md` §2 (manifestação ativa)                     |
| Página pública   | OTP disparado ao abrir a tela (ROUTES §3.2)            | botão "Receber código por e-mail"                         | arquitetura §4.1 + aviso de privacidade ao signatário                 |
| Página pública   | "Você receberá o PDF final por WhatsApp"               | "por e-mail"                                              | Fase 1 usa só e-mail (RECONCILIACAO §2)                               |
| Página pública   | "Documento assinado" no comprovante                    | "Documento concluído" + selo do modo real de conclusão    | arquitetura §2 (não simular assinatura criptográfica)                 |
| Detalhe › Trilha | marcador numerado                                      | ícone por tipo de evento (evidências mantém o número)     | pedido do incremento 3; DESIGN §4.18 mantido na página de evidências  |

### Props de página × props compartilhadas (integração I1)

As props compartilhadas `organization` (CurrentOrganization) e `organizations` (switcher) são lidas pelo shell (`AppSidebar`, `OrgSwitcher`, `AccountMenu`, `SettingsLayout`). Uma prop de página com o mesmo nome **sombreia** a compartilhada e derruba o shell em runtime, mesmo com HTTP 200. Regras adotadas:

- `settings/general` e `envelopes/evidence` (ROUTES §2.12/§2.8 chamam a prop de `organization`): o backend **mescla** `HandleInertiaRequests::currentOrganizationProps()` com os campos da página — a prop tem, ao mesmo tempo, `plan/role/permissions` e `legal_name/tax_id/contact_email` (ou `tax_id_masked`).
- `admin/organizations/show` e `admin/organizations/index`: as props chamam-se **`customer`** e **`customers`** (em vez de `organization`/`organizations` do ROUTES §2.20), porque no painel interno a organização exibida não é a organização corrente do usuário.
- O smoke test `tests/Feature/Smoke/AllGetRoutesTest.php` falha se alguma página GET voltar a sombrear essas props.
