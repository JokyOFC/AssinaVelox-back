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
