# AssinaVelox — Design System e Especificação de Telas

> Fonte única de verdade para o front-end (React + Vite + Inertia + shadcn/ui + Tailwind v4).
> Extraído diretamente dos mocks `.dc.html` em `design-reference/` (Claude Design). Todo valor abaixo (hex, px, peso, texto) foi lido do markup — nada foi inventado. Quando algo não está nos mocks, está marcado como **[não desenhado]** ou **[ambíguo]**.
>
> Convenção: prosa em pt-BR, identificadores (tokens, classes, enums, nomes de componentes) em inglês.
> **O design é light-only.** Nenhum mock define paleta escura; não implemente `dark:` por enquanto.

---

## 0. Como ler os mocks (referência rápida)

| Construção no `.dc.html` | Significado |
|---|---|
| `<x-dc>…</x-dc>` | template HTML com estilos inline (a verdade visual) |
| `{{ expr }}` | binding contra `props` + `renderVals()` do `<script data-dc-script>` |
| `<sc-if value="{{ x }}">` | render condicional (estado ativo, modal, empty state) |
| `<sc-for list="{{ rows }}" as="r">` | repetição (linhas de tabela, cards) |
| `style-hover="…"` / `style-focus="…"` | estado `:hover` / `:focus` → mapear para `hover:` / `focus-visible:` |
| `<dc-import name="AppSidebar" active="…" mode="…">` | componente compartilhado (a sidebar) |
| `data-props` JSON | props com `default` (ex.: `view`, `tab`, `active`, `mode`) |
| `class Component extends DCLogic { state = {…}; renderVals(){…} }` | máquina de estados + dados de exemplo + enums |

Arquivos: `AppSidebar`, `App - Login`, `App - Criar conta`, `App - Recuperar senha` (os 3 de auth são o **mesmo template** com `view` diferente), `App - Dashboard`, `App - Documentos`, `App - Documento`, `App - Nova solicitacao`, `App - Assinaturas`, `App - Modelos`, `App - Usuarios`, `App - API`, `App - Integracoes`, `App - Configuracoes`, `App - Cobranca` (idêntico a Configurações com `tab=cobranca`), `Assinar - Pagina publica`, `Admin - Clientes`, `Sistema - Mapa de telas` (índice de design, não é tela de produto), `support.js` (runtime do editor, ignorar), `assets/logo.png`, `uploads/*.png`.

---

## 1. Tokens

### 1.1 Paleta completa (todos os hex observados)

Contagem de ocorrências entre parênteses indica frequência nos mocks (ajuda a saber o que é "core" e o que é pontual).

#### Neutros / superfícies

| Token proposto | Hex | Uso observado |
|---|---|---|
| `--navy` / `--foreground` | `#0b1f42` (312) | texto principal, títulos, links default, avatar da organização, banner admin, card "Plano atual", aside do login, bloco de código escuro, página ativa da paginação, badge de plano "Empresarial", chip "VISA" |
| `--text-secondary` | `#47536b` (230) | subtítulos, texto de tabela, labels de KPI, item de nav inativo, tab/segmento inativo, botão ícone default, badge "Rascunho"/"Grátis" fg |
| `--muted-foreground` | `#8a96ad` (235) | placeholders, meta, eyebrows, cabeçalho de tabela, labels de grupo da sidebar, breadcrumb pai, ícones chevron/kebab, "—" de 2FA, chave revogada |
| `--muted-foreground-2` | `#6b7891` (5) | fg dos badges neutros "Expirado", "Inativo", "Pausado", "Cancelado" |
| `--background` | `#fbfcfe` (55) | fundo da página do app, fundo do cabeçalho de tabela, header translúcido `rgba(251,252,254,.9)`, dropzone, campo do outro signatário, área de preview do certificado |
| `--card` | `#ffffff` (342) | cards, inputs, botões outline, tab ativa de segmented control, página do PDF |
| `--sidebar` | `#f7f9fc` (7) | fundo da sidebar, fundo do `kbd` ⌘K, caixa do hash SHA-256, painel OTP, comprovante, campo do segredo de webhook |
| `--surface-2` / `--accent` | `#eef2f9` (117) | hover de item de nav e de botão ícone, trilho do segmented control, trilho de progress bar, fundo do visualizador de PDF, linhas skeleton, linha da timeline, fundo do preview do card de modelo, fundo da **página pública de assinatura**, segmento vazio do medidor de senha |
| `--surface-3` / `--accent-subtle` | `#f4f8fe` (66) | hover de botão outline, hover de item do popover da conta, hover do switcher de workspace, tile do ícone de documento (36px), linha selecionada da tabela, hover do dropzone/cards de modelo, hover de botões "Lembrar" |
| `--row-hover` | `#f7f9fd` (14) | hover de linha de tabela |
| `--muted` | `#f1f4f9` (102) | divisores de linhas de tabela, chip neutro (canal/autenticação), pill de contagem em tab inativa, célula "–" da matriz de permissões, bg dos badges "Rascunho"/"Expirado"/"Inativo"/"Pausado"/"Cancelado"/"Grátis", inline code, 4ª cor da paleta de avatares |
| `--border` | `#e6eaf2` (155) | bordas de cards, header, sidebar, divisores, borda do `kbd`, borda dos badges neutros, dot pendente do stepper público |
| `--input` | `#d5dce9` (131) | borda de inputs, botões outline, chips não selecionados, circle de passo futuro do stepper, linha-base do signature pad, campo tracejado do outro signatário |
| `--border-dashed` | `#c9d4e6` (37) | bordas tracejadas (dropzone, filtros facetados, botões "Adicionar…"), chevron do breadcrumb, ícone de paginação desabilitado, texto do "Voltar" desabilitado, switch OFF, hover de borda do card de modelo |

#### Marca (azul)

| Token | Hex | Uso |
|---|---|---|
| `--primary` | `#1257c9` (250) | botão primário, links ativos/hover, item de nav ativo (fg), tab ativa (fg + underline), fill de progress, barras "Concluídas", checkbox/switch ON, foco de input, avatar de usuário fg, campo de assinatura do signatário 1, `accent-color` |
| `--primary-hover` | `#0f4bb0` (28) | hover do botão primário |
| `--primary-soft` | `#e8f0fd` (41) | bg de item de nav ativo, pill de contagem em tab ativa, bg de avatares de signatários, callout informativo, barra de ações em lote, badge "Em andamento"/"Trial"/"Produção", chip selecionado, tile de ícone (upload/selfie), hover do botão branco sobre navy |
| `--primary-soft-border` | `#c9dbf7` (8) | borda do badge "Em andamento"/"Trial", borda inferior da barra de lote, borda do callout informativo |
| `--primary-light` (chart) | `#dbe6fb` (8) | barras "Enviadas" no gráfico, texto do bloco de código escuro |
| `--primary-bright` | `#2e7bef` (5) | palavra "minutos" no hero do login, ícone shield do banner admin, link no card sandbox escuro; glow radial `rgba(46,123,239,.35)` |
| `--ring` | `rgba(18,87,201,.18)` (40) | anel de foco `0 0 0 3px` (18,87,201 = #1257c9) |
| — | `rgba(18,87,201,.08)` / `.06` | fill do campo de assinatura do signatário 1 no editor de campos / preview de modelo |

#### Texto sobre navy (aside do login, card plano, card sandbox, código)

| Token | Hex | Uso |
|---|---|---|
| `--on-navy-muted` | `#6f87b8` (25) | eyebrows, rótulo "ASSINATURA", links do rodapé do aside, comentários em código |
| `--on-navy-secondary` | `#b9c7de` (11) | texto secundário no card de plano/sandbox, trust badges |
| `--on-navy-subtle` | `#8fa3c7` (6) | subtítulo do banner admin, header do bloco de código, toggle de linguagem inativo |
| `--code-string` | `#9be7b6` (71) | strings em blocos de código |
| glass | `rgba(255,255,255,.06)` bg, `.1` borda, `.14` divisor tracejado, `.2` borda de botão ghost, `.08` borda de header de código |

#### Semânticas (badges: fg / bg / border)

| Semântica | fg | bg | border | Extras |
|---|---|---|---|---|
| **success** | `#12784a` (47) | `#e6f7ee` (35) | `#bfe9d1` (21) | `#1fb865` (23) = verde sólido (dot do stepper concluído, campo assinado, ícone de sucesso, badge "Ativo" do plano, segmentos do medidor de senha, "Assinado ✓" no hero) |
| **warning** | `#9a5b00` (30) | `#fff4e0` (20) | `#f5dfae` (9) | `#d59b2a` (11) = âmbar sólido (campo pendente no PDF, cor do signatário 2, barra ≥90%); `#fff8e8` (2) = bg do campo pendente no PDF |
| **danger** | `#b42323` (26) | `#fdeaea` (17) | `#f5c2c2` (11) | `#e5484d` (4) = ponto de notificação do sino |
| **info** | `#1257c9` | `#e8f0fd` | `#c9dbf7` | mesmo que primary-soft |
| **neutral** | `#6b7891` (ou `#47536b` no "Rascunho") | `#f1f4f9` | `#e6eaf2` | |

#### Sombras (valores exatos)

| Token | Valor | Uso |
|---|---|---|
| `--shadow-card` | `0 1px 2px rgba(11,31,66,.04)` | cards, inputs de auth, botões outline |
| `--shadow-primary` | `0 1px 2px rgba(11,31,66,.1)` | botão primário |
| `--shadow-segment` | `0 1px 2px rgba(11,31,66,.08)` | tab ativa do segmented control |
| `--shadow-card-hover` | `0 8px 24px rgba(11,31,66,.08)` | hover de card de modelo / card do mapa |
| `--shadow-pdf` | `0 8px 30px rgba(11,31,66,.12)` (app) / `.1` (público) | página de PDF |
| `--shadow-popover` | `0 12px 32px rgba(11,31,66,.14)` | popover "Minha conta" |
| `--shadow-dialog` | `0 24px 60px rgba(11,31,66,.25)` | modal |
| `--shadow-knob` | `0 1px 2px rgba(0,0,0,.2)` | knob do switch |
| overlay | `rgba(11,31,66,.45)` + `backdrop-filter: blur(2px)` | fundo do modal |
| header | `rgba(251,252,254,.9)` + `backdrop-filter: blur(8px)` | header sticky do app |
| preview de modelo | `0 -2px 12px rgba(11,31,66,.08)` | folha no card de modelo |

### 1.2 Tipografia

- **Família:** `'Exo 2'`, Google Fonts — `https://fonts.googleapis.com/css2?family=Exo+2:ital,wght@0,400;0,500;0,600;0,700;0,800;1,800&family=Caveat:wght@600&display=swap`
- **Fallback:** `'Exo 2', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif` (mocks usam só `sans-serif`).
- **Manuscrita:** `'Caveat', cursive` peso 600 — apenas para renderizar assinaturas (24px no campo do PDF, 34px no hero do login, 40px no pad/preview; rotação `-2deg`/`-3deg`).
- **Mono:** `ui-monospace, 'SF Mono', Menlo, Consolas, monospace` — hash, chaves de API, URLs de webhook, IPs, código.
- **Base:** `14px`, cor `#0b1f42`, `box-sizing: border-box`, `a { text-decoration: none }`.
- **Números:** `font-variant-numeric: tabular-nums` em IDs, datas, valores, contadores.

Escala observada (px / peso / letter-spacing / line-height):

| Papel | Tamanho | Peso | Observações |
|---|---|---|---|
| Hero (login aside) | `clamp(36px,4vw,56px)` | 800 italic uppercase | ls `-.015em`, lh `.95` |
| H1 página (mapa) | 30 | 800 italic uppercase | ls `-.01em`, lh 1 |
| Valor KPI grande (Dashboard) | 30 | 700 | ls `-.02em`, lh 1, tabular; sufixo unidade 16/600 `#8a96ad` |
| H1 auth / sucesso do wizard / nome do plano | 26 | 700 (plano: 800 italic uppercase) | ls `-.01em` |
| Valor KPI (Assinaturas, Admin) | 26 | 700 | ls `-.02em`, lh 1 |
| H1 de página do app | 24 | 700 | ls `-.01em`, lh 1.2 |
| H1 detalhe do documento | 22 | 700 | ls `-.01em` |
| H1 da página pública / título do card de sucesso | 20 | 700 | lh 1.25 |
| Título de modal / h2 da doc API | 18 | 700 | |
| Título de card | 15 | 600 | |
| Nome de card de modelo / body auth / inputs | 14 | 600 / 400 | |
| Body padrão, tabs, botões, subtítulos, breadcrumb | 13.5 | 500–600 | |
| Labels de campo, células, filtros, botões pequenos | 13 | 500–600 | |
| Tabs segmentadas, meta, helper, rodapé de tabela, KPI label | 12.5 | 500–600 | |
| Badges, header de tabela, meta secundária, timestamps | 12 | 600 | |
| Pill de contagem, chips, subtítulos da sidebar, avatar 34px | 11.5 | 600–700 | |
| Eyebrow, badge de nav, chips de evento, code badge | 11 | 700 | eyebrow uppercase ls `.16em`–`.18em`(dash) / `.24em` (auth) |
| Label de grupo da sidebar / TOC | 10.5 | 700 | uppercase ls `.14em` |
| Avatar 26px, clause heading no PDF, "PDF" badge | 10 | 700–800 | |
| Rótulo micro ("ASSINATURA") | 9.5 | 700 | uppercase ls `.16em` |
| Tag de campo no PDF | 9 | 700 | uppercase ls `.08em`–`.1em` |
| Carimbo de rodapé do PDF | 8 / 8.5 | 400 | |

Line-heights de parágrafo: `1.5` (labels/checkbox), `1.55` (descrições), `1.6`–`1.65` (prosa longa/API), `1.7` (endereço), `1.8` (lista de IPs).

### 1.3 Raios

| Valor | Uso |
|---|---|
| `2px`–`4px` | inline code (4), skeleton (4), kbd (4), thumbnail de página (4) |
| `3px` | topo das barras do gráfico, quadrado da legenda |
| `5px` | chip de autenticação pequeno (Assinaturas), chip de evento |
| `6px` | badges, botões pequenos (28–30px), item de popover, tabs internas do segmented, célula da matriz, avatar-check, chip "VISA" |
| `7px` | avatar empilhado de signatário (26px) |
| `8px` | **padrão de controles**: botões, inputs, item de nav, avatar 32–36px, segmented control (trilho), pills de paginação (6), callout pequeno |
| `10px` | popover, card de signatário/avatar 40px, caixa OTP, linhas com borda interna (upload, template, logo), CTA da página pública (44px), botões tracejados de adicionar |
| `12px` | **cards**, bloco de código, dropzone, glass card do login, tile de 44px |
| `14px` | modal, card de fase da página pública, ícone de sucesso 52px |
| `18px` | ícone de sucesso 64px |
| `999px` | pills (stepper público, chips de autenticação, categoria de modelo, switch, progress) |

### 1.4 Espaçamento, dimensões e ritmo

| Item | Valor |
|---|---|
| Sidebar | `256px` de largura, `min-height: 100vh`, sticky |
| Header do app | `56px`, `padding: 0 24px`, `gap: 12px`, sticky `z-index: 10` |
| Header público | `60px`, bg branco sólido |
| Padding do conteúdo | `24px`; gap vertical entre blocos `20px` |
| Largura máxima do conteúdo | livre (fluido) no app; `1040px` centralizado no wizard; `820px` na coluna de conteúdo de Configurações; `1200px` na página pública; `1100px` no mapa; `400px` a coluna do formulário de auth |
| Cards | `padding: 20px` (padrão), `16px 18px` (KPI compacto), `20px 20px 18px` (KPI dashboard), `18px 20px` (card com header), `16px` (card de signatário), `22px` (fase pública), `24px` (modal, card sucesso auth); gap interno `12`–`14px` |
| Grid de KPIs | `repeat(auto-fit, minmax(140px|150px, 1fr))`, gap 16 |
| Grid de formulários | `repeat(auto-fit, minmax(220px, 1fr))` gap 12 (200px na aba Assinatura; 180px no wizard) |
| Grid de cards | `repeat(auto-fill, minmax(240px, 1fr))` gap 16 (modelos) / 12 (mapa) |
| Colunas duplas | `flex-wrap` com bases: gráfico `2 1 380px` + coluna `1 1 280px`; documento `1.4 1 420px` + `1 1 340px`; página pública `1.5 1 380px` + `1 1 320px` (max 420); integrações `2 1 520px` + `1 1 280px`; API `1 1 300px` + `1 1 360px` |
| Rail de pastas / nav de settings / TOC | `200px` fixo (`flex: 0 0 200px`) |
| Rail de thumbnails de página | `72px` |
| Alturas de botão | `44px` (CTA público), `40px` (auth), `38px` (botões tracejados "Adicionar"), `36px` (ações de página, CTA sidebar, modal footer), `34px` (secundários em cards, filtros, back button), `32px` (ações de card, barra de lote, ícones outline 32×32), `30px` (toolbar de doc, "Relatório PDF", paginação), `28px` ("Lembrar", "Reenviar", "Editar", "Acessar como", "PDF"/"NF-e", chip de função), `26px` ("Limpar" no pad), `22px` (botão + de pasta) |
| Alturas de input | `40px` (auth, digitar assinatura), `38px` (formulários de settings/modal), `34px` (busca em toolbar), `48px` (OTP) |
| Linhas de tabela | header `38px` (40px na matriz de permissões); body `padding: 9px 16px` (documentos, assinaturas), `10px 16px` (usuários, admin), `10px 20px` (dashboard, pendências), `11px 20px` (notificações, faturas, permissões, detalhes) |
| Item de nav | `34px`, `padding: 0 10px`, `gap: 10px`, ícone 16px stroke 2; grupos com label de `28px` e gap 14 entre grupos, 2 entre itens |
| Tabs underline | `38px`, `padding: 0 12px`, borda inferior `2px`, `margin-bottom: -1px` |
| Segmented control | trilho `padding: 3px; gap: 2px; radius 8`; item `padding: 4–6px 10–12px; radius 6` |
| Badge | `padding: 2px 8px` (3px 9px no header do documento), radius 6, 12px/600, dot `6px` `currentColor` |
| Pill de contagem | `padding: 1px 7px`, 11.5px/600, radius 6 |
| Avatar iniciais | 26px (empilhado, radius 7, `border: 2px #fff`, `margin-left: -6px`), 32px (sidebar, pendências), 34px (org público, linha de assinaturas), 36px (tabela), 40px (card de signatário, radius 10), 56px (logo empresa, radius 10, 18px/800) |
| Switch | `40×22`, knob `18px`, `top: 2px`, `left: 2px` (off) / `20px` (on), ON `#1257c9`, OFF `#c9d4e6`, `transition: left .15s ease` |
| Checkbox | `15–16px`, `accent-color: #1257c9` |
| Progress | `8px` (planos), `5px` (admin), radius 999, trilho `#eef2f9`, fill `#1257c9` (`#d59b2a` se ≥ 90%) |
| Ícones | Lucide, `stroke-width: 2` (2.5 no "+" e checks), 14–17px em botões, 16 na nav, 15 em CTAs |

---

## 2. Bloco Tailwind v4 / shadcn (pronto para colar em `resources/css/app.css`)

```css
@import "tailwindcss";
@import "tw-animate-css";

/* Fonte da marca (ou self-host via @fontsource/exo-2) */
@import url("https://fonts.googleapis.com/css2?family=Exo+2:ital,wght@0,400;0,500;0,600;0,700;0,800;1,800&family=Caveat:wght@600&display=swap");

/* O design é LIGHT-ONLY. Não há paleta escura nos mocks; não declare @custom-variant dark por enquanto. */
:root {
  /* shadcn/ui core */
  --background: #fbfcfe;
  --foreground: #0b1f42;
  --card: #ffffff;
  --card-foreground: #0b1f42;
  --popover: #ffffff;
  --popover-foreground: #0b1f42;
  --primary: #1257c9;
  --primary-foreground: #ffffff;
  --secondary: #f1f4f9;
  --secondary-foreground: #47536b;
  --muted: #f1f4f9;
  --muted-foreground: #8a96ad;
  --accent: #eef2f9;              /* hover de nav/ghost, trilho de segmented control */
  --accent-foreground: #0b1f42;
  --destructive: #b42323;
  --destructive-foreground: #ffffff;
  --border: #e6eaf2;
  --input: #d5dce9;
  --ring: #1257c9;                /* usar com /18 → rgba(18,87,201,.18), anel de 3px */
  --radius: 0.5rem;               /* 8px: botões e inputs; cards usam rounded-xl (12px) */

  /* shadcn sidebar */
  --sidebar: #f7f9fc;
  --sidebar-foreground: #47536b;
  --sidebar-primary: #1257c9;
  --sidebar-primary-foreground: #ffffff;
  --sidebar-accent: #e8f0fd;      /* item ativo */
  --sidebar-accent-foreground: #1257c9;
  --sidebar-border: #e6eaf2;
  --sidebar-ring: #1257c9;

  /* charts */
  --chart-1: #1257c9;             /* concluídas */
  --chart-2: #dbe6fb;             /* enviadas */
  --chart-3: #12784a;
  --chart-4: #d59b2a;
  --chart-5: #b42323;

  /* extensões AssinaVelox */
  --navy: #0b1f42;
  --primary-hover: #0f4bb0;
  --primary-soft: #e8f0fd;
  --primary-soft-border: #c9dbf7;
  --primary-bright: #2e7bef;
  --text-secondary: #47536b;
  --muted-foreground-2: #6b7891;
  --accent-subtle: #f4f8fe;       /* hover de botão outline, linha selecionada, tile de ícone */
  --row-hover: #f7f9fd;
  --border-dashed: #c9d4e6;
  --success: #12784a;  --success-bg: #e6f7ee;  --success-border: #bfe9d1;  --success-solid: #1fb865;
  --warning: #9a5b00;  --warning-bg: #fff4e0;  --warning-border: #f5dfae;  --warning-solid: #d59b2a;  --warning-bg-soft: #fff8e8;
  --danger: #b42323;   --danger-bg: #fdeaea;   --danger-border: #f5c2c2;   --danger-solid: #e5484d;
  --info: #1257c9;     --info-bg: #e8f0fd;     --info-border: #c9dbf7;
  --neutral: #6b7891;  --neutral-bg: #f1f4f9;  --neutral-border: #e6eaf2;
  --on-navy-muted: #6f87b8;
  --on-navy-secondary: #b9c7de;
  --on-navy-subtle: #8fa3c7;
  --code-string: #9be7b6;
  --code-text: #dbe6fb;
}

@theme inline {
  --font-sans: "Exo 2", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  --font-hand: "Caveat", cursive;
  --font-mono: ui-monospace, "SF Mono", Menlo, Consolas, monospace;

  --color-background: var(--background);
  --color-foreground: var(--foreground);
  --color-card: var(--card);
  --color-card-foreground: var(--card-foreground);
  --color-popover: var(--popover);
  --color-popover-foreground: var(--popover-foreground);
  --color-primary: var(--primary);
  --color-primary-foreground: var(--primary-foreground);
  --color-secondary: var(--secondary);
  --color-secondary-foreground: var(--secondary-foreground);
  --color-muted: var(--muted);
  --color-muted-foreground: var(--muted-foreground);
  --color-accent: var(--accent);
  --color-accent-foreground: var(--accent-foreground);
  --color-destructive: var(--destructive);
  --color-destructive-foreground: var(--destructive-foreground);
  --color-border: var(--border);
  --color-input: var(--input);
  --color-ring: var(--ring);
  --color-sidebar: var(--sidebar);
  --color-sidebar-foreground: var(--sidebar-foreground);
  --color-sidebar-primary: var(--sidebar-primary);
  --color-sidebar-primary-foreground: var(--sidebar-primary-foreground);
  --color-sidebar-accent: var(--sidebar-accent);
  --color-sidebar-accent-foreground: var(--sidebar-accent-foreground);
  --color-sidebar-border: var(--sidebar-border);
  --color-sidebar-ring: var(--sidebar-ring);
  --color-chart-1: var(--chart-1);
  --color-chart-2: var(--chart-2);
  --color-chart-3: var(--chart-3);
  --color-chart-4: var(--chart-4);
  --color-chart-5: var(--chart-5);

  --color-navy: var(--navy);
  --color-primary-hover: var(--primary-hover);
  --color-primary-soft: var(--primary-soft);
  --color-primary-soft-border: var(--primary-soft-border);
  --color-primary-bright: var(--primary-bright);
  --color-text-secondary: var(--text-secondary);
  --color-muted-foreground-2: var(--muted-foreground-2);
  --color-accent-subtle: var(--accent-subtle);
  --color-row-hover: var(--row-hover);
  --color-border-dashed: var(--border-dashed);
  --color-success: var(--success);           --color-success-bg: var(--success-bg);
  --color-success-border: var(--success-border); --color-success-solid: var(--success-solid);
  --color-warning: var(--warning);           --color-warning-bg: var(--warning-bg);
  --color-warning-border: var(--warning-border); --color-warning-solid: var(--warning-solid);
  --color-warning-bg-soft: var(--warning-bg-soft);
  --color-danger: var(--danger);             --color-danger-bg: var(--danger-bg);
  --color-danger-border: var(--danger-border); --color-danger-solid: var(--danger-solid);
  --color-info: var(--info);                 --color-info-bg: var(--info-bg);   --color-info-border: var(--info-border);
  --color-neutral: var(--neutral);           --color-neutral-bg: var(--neutral-bg); --color-neutral-border: var(--neutral-border);
  --color-on-navy-muted: var(--on-navy-muted);
  --color-on-navy-secondary: var(--on-navy-secondary);
  --color-on-navy-subtle: var(--on-navy-subtle);
  --color-code-string: var(--code-string);
  --color-code-text: var(--code-text);

  --radius-sm: calc(var(--radius) - 2px);   /* 6px  badges, botões pequenos */
  --radius-md: var(--radius);               /* 8px  botões, inputs */
  --radius-lg: calc(var(--radius) + 2px);   /* 10px popover, linhas com borda */
  --radius-xl: calc(var(--radius) + 4px);   /* 12px cards */
  --radius-2xl: calc(var(--radius) + 6px);  /* 14px modal */

  --shadow-card: 0 1px 2px rgba(11,31,66,.04);
  --shadow-primary: 0 1px 2px rgba(11,31,66,.1);
  --shadow-segment: 0 1px 2px rgba(11,31,66,.08);
  --shadow-card-hover: 0 8px 24px rgba(11,31,66,.08);
  --shadow-pdf: 0 8px 30px rgba(11,31,66,.12);
  --shadow-popover: 0 12px 32px rgba(11,31,66,.14);
  --shadow-dialog: 0 24px 60px rgba(11,31,66,.25);
}

@layer base {
  * { @apply border-border; }
  body { @apply bg-background text-foreground font-sans text-[14px] antialiased; }
  ::placeholder { color: var(--muted-foreground); }
  a { text-decoration: none; }
  @keyframes signWipe { from { clip-path: inset(0 100% 0 0); } to { clip-path: inset(0 -8% 0 0); } }
  @keyframes dialogIn { from { opacity: 0; transform: translateY(8px) scale(.98); } to { opacity: 1; transform: none; } }
}

/* Utilitário: anel de foco padrão dos mocks */
@utility focus-ring {
  &:focus-visible { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(18,87,201,.18); }
}
```

Notas de mapeamento:
- `--accent` = `#eef2f9` cobre o hover de itens de nav/ghost e o trilho do segmented control; o `Sistema - Mapa de telas` sugeria `--accent: #f4f8fe`, mas nos mocks `#f4f8fe` é usado só em hover de botão outline/linha selecionada — por isso virou `--accent-subtle`. **[ambíguo]** Escolha consciente; ajuste se preferir seguir o mapa.
- `--radius: 0.5rem` bate com o mapa (`--radius 0.5rem`) e com os 203 usos de `8px`.
- `--destructive` do shadcn é `#b42323` (texto/borda dos botões destrutivos são outline nos mocks: `border #f5c2c2`, `hover:bg #fdeaea`). Não existe botão vermelho sólido em nenhum mock.

---

## 3. App shell

### 3.1 Sidebar (`AppSidebar`, `<Sidebar>` do shadcn, `w-64`)

`<aside>` 256px, `bg-sidebar (#f7f9fc)`, `border-r border-border`, flex column, `min-h-screen`, `position: sticky; top: 0; height: 100vh`.

Props: `active: 'dashboard'|'documentos'|'assinaturas'|'modelos'|'usuarios'|'api'|'configuracoes'|'clientes'` (default `dashboard`), `mode: 'client'|'admin'` (default `client`). Derive `mode` do papel do usuário autenticado e `active` da rota atual.

**Ordem vertical (modo client):**
1. **Logo** — `padding: 18px 16px 10px`; `<img src="logo.png" alt="AssinaVelox" class="h-[30px]">` linkando para o Dashboard.
2. **Workspace switcher** — `padding: 6px 12px 4px`; botão full-width branco, `border 1px #e6eaf2`, `rounded-lg`, `padding: 7px 8px`, `hover:bg-[#f4f8fe]`. Avatar 32×32 `rounded-lg bg-navy text-white font-bold text-[12px]` com iniciais `IH`; nome `13px/600` `#0b1f42` ellipsis + subtítulo `11.5px #8a96ad` ("Plano Profissional"); ícone `chevrons-up-down` 14px `#8a96ad`. → `DropdownMenu`.
3. **CTA "Nova solicitação"** — `padding: 6px 12px 10px`; link-botão `h-9 (36px) rounded-lg bg-primary hover:bg-primary-hover text-white text-[13.5px] font-semibold shadow-primary`, ícone `plus` 15px stroke 2.5. → `Button asChild` + `<Link>`.
4. **Nav** — `flex-1 overflow-auto padding: 4px 12px`, grupos com `gap 14px`. Label de grupo: `h-7 (28px) px-[10px] text-[10.5px] font-bold uppercase tracking-[.14em] text-muted-foreground`. Itens: `h-[34px] px-[10px] gap-[10px] rounded-lg text-[13.5px]`, ícone 16px. Ativo: `font-semibold text-primary bg-[#e8f0fd]`; inativo: `font-medium text-[#47536b]`; hover: `bg-[#eef2f9]`.
   - Grupo **Plataforma**: Dashboard (`LayoutDashboard`), Documentos (`FileText`, com badge `23` à direita: `11px/700 text-[#9a5b00] bg-[#fff4e0] rounded-md px-[7px] py-px`), Assinaturas (`PenLine`), Modelos (`LayoutTemplate`).
   - Grupo **Conta**: Usuários (`Users`), API e integrações (`Code`), Configurações (`SlidersHorizontal`).
5. **Rodapé** — `padding: 12px; border-top 1px #e6eaf2; position: relative`. Botão de conta transparente full-width (`padding: 6px 8px; rounded-lg; hover:bg-[#eef2f9]`): avatar 32×32 `bg-[#e8f0fd] text-primary font-bold 12px` `AR`; nome `13px/600`; e-mail `11.5px #8a96ad` ellipsis; `chevrons-up-down` 14px.
   - **Popover "Minha conta"** (estado `menuOpen`): `position: absolute; left/right: 12px; bottom: 64px; z-index 30`, branco, `border #e6eaf2`, `rounded-[10px]`, `shadow-popover`, `padding: 6px`. Header `11px/700 uppercase tracking-[.12em] #8a96ad` "Minha conta"; itens `padding: 8px 10px; rounded-md; 13.5px; ícone 15px; hover:bg-[#f4f8fe]`: "Perfil e preferências" (`UserRound`) → Configurações; "Plano e cobrança" (`CreditCard`) → Configurações/Cobrança; "Painel interno" (`Shield`) → Admin Clientes (mostrar só para staff); divisor `1px #e6eaf2 my-1`; "Sair" (`LogOut`) `text-[#b42323] hover:bg-[#fdeaea]` → logout. → `DropdownMenu` com `DropdownMenuLabel`, `DropdownMenuSeparator`, item `variant="destructive"`.

**Modo admin:** substitui switcher + CTA por um **banner** (`margin: 6px 12px 10px; padding: 8px 10px; rounded-lg; bg-navy text-white; gap 10px`): ícone `ShieldCheck` 16px stroke `#2e7bef`; "Painel interno" `12.5px/600`; "Equipe AssinaVelox" `11px #8fa3c7`. Nav:
- **Operação**: Clientes (`Building2`, único com estado ativo), Planos e faturamento (`CreditCard`, `href="#"`), Usuários da plataforma (`Users`, `#`), Logs e auditoria (`History`, `#`).
- **Sistema**: Configurações globais (`SlidersHorizontal`, `#`), Voltar ao app (`ArrowLeft`) → Dashboard.
- Rodapé idêntico ao modo client.

### 3.2 Header sticky (todas as telas do app)

`<header class="sticky top-0 z-10 h-14 flex items-center gap-3 px-6 border-b border-border" style="background: rgba(251,252,254,.9); backdrop-filter: blur(8px)">`
1. `SidebarTrigger`: botão 32×32 `rounded-lg text-[#47536b] hover:bg-[#eef2f9] hover:text-foreground`, ícone `PanelLeft` 17px, `title="Recolher menu"`. No mock, alternar `sidebarOpen` simplesmente remove a sidebar (sem modo ícone).
2. Divisor vertical `w-px h-[18px] bg-border`.
3. `Breadcrumb` `13.5px nowrap`: pai(s) em `#8a96ad` (link), separador `ChevronRight` 14px stroke `#c9d4e6`, atual `font-semibold #0b1f42` (com ellipsis quando longo). Exemplos: "Imobiliária Horizonte › Dashboard"; "Documentos › Locação › Contrato de locação — Apto 302"; "Documentos › Nova solicitação"; "API e integrações › Chaves e webhooks"; "Painel interno › Clientes".
4. Cluster direito (`margin-left: auto; gap 6px`), varia por tela **[ambíguo — não é consistente]**:
   - Dashboard: busca global `h-[34px] w-[clamp(160px,24vw,260px)] px-[10px] rounded-lg border-input bg-white` com ícone `Search` 15px, placeholder "Buscar...", `<kbd>` "⌘K" (`11px #8a96ad border-border rounded bg-[#f7f9fc] px-[5px] py-px`) → abre `CommandDialog`; ícone ajuda `HelpCircle` 34×34 (→ API docs); sino `Bell` 34×34 com dot `7px #e5484d` `border 2px #fbfcfe` em `top: 7px; right: 8px`.
   - Documentos, Assinaturas: ajuda + sino. Documento, Usuários, Configurações, Modelos: só sino ou nada. API: pill de status "API operacional · v1.8" (`h-[30px]? text-[12px]/600 bg-[#e6f7ee] text-[#12784a]` com dot `#1fb865`). Nova solicitação: "Rascunho salvo às 09:41" (`12.5px #8a96ad`) + botão outline "Sair" (32px). Admin: pill âmbar "Acesso restrito · ações são auditadas" (`h-[30px] px-[10px] rounded-md bg-[#fff4e0] text-[#9a5b00] 12px/600`, ícone `Shield` 13px).
   - **Recomendação:** padronizar busca ⌘K + ajuda + sino em todas as telas do app; manter os indicadores específicos (autosave, API status, acesso restrito) como slot adicional.

### 3.3 Cabeçalho de página

`<div class="flex items-end justify-between gap-4 flex-wrap">`: esquerda opcional eyebrow (`11px/700 uppercase tracking-[.18em] #8a96ad`, só no Dashboard "VISÃO GERAL"), `h1 text-2xl (24px) font-bold tracking-[-.01em] leading-[1.2]`, subtítulo `mt-1.5 text-[13.5px] text-[#47536b]`. Direita: grupo de botões `gap-2 flex-wrap` (outline 36px + primário 36px). Tela de detalhe usa botão "Voltar" 34×34 outline à esquerda do h1 (22px) e badge de status inline.

### 3.4 Layout de página e responsividade

- Wrapper: `<div class="min-h-screen flex items-stretch bg-background">` → `Sidebar` + `<main class="flex-1 min-w-0 flex flex-col">`.
- Conteúdo: `<div class="p-6 flex flex-col gap-5">`.
- Todos os layouts multi-coluna dos mocks usam `flex-wrap` com `flex-basis` (ver §1.4) — colunas empilham naturalmente abaixo de ~800px. Tabelas em CSS grid devem ficar em contêiner `overflow-x-auto` no mobile.
- **[não desenhado] Mobile:** recomendação — `SidebarProvider` com `collapsible="offcanvas"`: abaixo de `md` a sidebar vira `Sheet` (drawer à esquerda) acionado pelo `SidebarTrigger`; acima de `md`, o trigger alterna visível/oculto como no mock (ou `collapsible="icon"` se quiser modo compacto). Header mantém trigger + breadcrumb truncado; cluster direito reduz a sino. Rails de 200px (pastas, settings nav, TOC) viram `Select`/`Tabs` horizontais roláveis no mobile. Página pública já é mobile-first (aside desce abaixo do documento).
- Auth: aside navy `flex: 0 0 44%; min-width: 360px` → `hidden lg:flex`; formulário centralizado `max-w-[400px]`.

---

## 4. Inventário de componentes

Cada item: spec visual exata → componente shadcn → classes Tailwind.

### 4.1 Botões (`Button`)

| Variante | Spec | Classes |
|---|---|---|
| **primary** (default) | bg `#1257c9`, hover `#0f4bb0`, texto branco 13.5px/600, radius 8, shadow `0 1px 2px rgba(11,31,66,.1)`, ícone 15px à esquerda gap 8 | `h-9 px-3.5 rounded-lg bg-primary text-primary-foreground text-[13.5px] font-semibold shadow-primary hover:bg-primary-hover` |
| **outline** (secundário) | bg branco, `border 1px #d5dce9`, texto `#0b1f42` 13.5px/**500** (600 em botões ≤32px), shadow-card, hover bg `#f4f8fe` (e `border-primary` no botão de certificado) | `h-9 px-3.5 rounded-lg border border-input bg-white text-[13.5px] font-medium shadow-card hover:bg-accent-subtle` |
| **outline-sm** | 28–32px, radius 6–8, 12–13px/600; hover `border-primary text-primary` ("Lembrar", "Reenviar", "Editar", "Acessar como", "Testar") ou `bg-[#f4f8fe]` | `h-7 px-2.5 rounded-md border-input text-[12px] font-semibold hover:border-primary hover:text-primary` |
| **ghost icon** | transparente, 30–34px, radius 6–8, cor `#47536b`/`#8a96ad`, hover `bg-[#eef2f9] text-foreground` | `size-8 rounded-lg text-text-secondary hover:bg-accent hover:text-foreground` |
| **ghost text** | sem borda, `text-primary` 600 ("Limpar seleção", "Alterar", "Editar") ou `text-[#47536b]` ("Salvar rascunho") | `variant="link"` sem underline |
| **destructive-outline** | branco, `border #f5c2c2`, texto `#b42323` 13px/600, hover bg `#fdeaea` ("Cancelar", "Cancelar documento", "Solicitar exclusão"; "Revogar/Excluir" só no hover) | `border-danger-border text-danger hover:bg-danger-bg` |
| **dashed / add** | transparente, `border 1px dashed #c9d4e6`, radius 10, h 38, `#47536b` 13px/600, ícone 15; hover `border-primary text-primary bg-[#f4f8fe]` | `h-[38px] rounded-[10px] border border-dashed border-border-dashed text-text-secondary hover:…` |
| **white-on-navy** | bg branco, texto navy 13px/600, h 34, hover `#e8f0fd` ("Alterar plano") | |
| **ghost-on-navy** | `border 1px rgba(255,255,255,.2)`, texto branco ("Cancelar renovação") | |
| **success-outline** | `border #bfe9d1 text-[#12784a]` 34px ("Reenviar link") | |
| **CTA público** | h 44, radius 10, 14px/600, full-width | `h-11 rounded-[10px] w-full` |
| **disabled look** | "Voltar" no passo 1: texto `#c9d4e6`; paginação prev: ícone `#c9d4e6` | `disabled:text-border-dashed` |

Dropdown triggers acrescentam `ChevronDown` 14px stroke `#8a96ad` ("Últimos 30 dias", "Baixar", "Linhas por página 10", chip de função).

### 4.2 Inputs (`Input`, `Label`, `Textarea`)

- Input: `h-10` (auth) / `h-[38px]` (settings) / `h-[34px]` (busca), `px-3`, `rounded-lg`, `border border-input bg-white text-[14px] text-foreground`, placeholder `#8a96ad`, `outline-none`; foco: `border-primary` + `shadow-[0_0_0_3px_rgba(18,87,201,.18)]`. Inputs de auth têm `shadow-card` em repouso.
- Label: `text-[13px] font-semibold` acima, `gap 6px`; sufixo opcional `(opcional)` em `font-normal text-muted-foreground`.
- Helper: `text-[12px] text-muted-foreground`.
- Busca com ícone: wrapper `flex items-center gap-2 h-[34px] px-[10px] rounded-lg border-input bg-white text-muted-foreground`, `Search` 15px, input `flex-1 border-0 bg-transparent text-[13.5px]`.
- Password: wrapper relativo, input `pr-11`, botão ghost 32×32 `absolute right-1 top-1 rounded-md text-muted-foreground hover:bg-accent hover:text-foreground` (`Eye`/`EyeOff` 16px, `title="Mostrar senha"`).
- Textarea: `rows=4`, mesmas bordas/foco, `p-3 text-[13.5px]`.
- **[não desenhado]** estados de erro/disabled: usar `border-danger` + `text-danger text-[12px]` para `InputError`; `disabled:opacity-60`.

### 4.3 Select (`Select`)

Trigger idêntico ao input (`h-[38px]` ou `h-[30px]` no chip de função de usuário / `h-[30px]` no "Linhas por página"), `flex justify-between`, `ChevronDown` 14px stroke `#8a96ad`. Valores vistos: "Locação", "15 dias (até 18 set)", "Português (Brasil)", "15 dias", "A cada 2 dias", "Sequencial", papéis "Locatária | Fiador | Parte | Testemunha", "10".

### 4.4 Checkbox e Switch

- `Checkbox`: 15–16px, `accent-color #1257c9` (nativo nos mocks) → shadcn `Checkbox` com `data-[state=checked]:bg-primary border-input`. Label ao lado `13px #47536b leading-[1.5]`.
- `Switch`: `w-10 h-[22px] rounded-full`, ON `bg-primary`, OFF `bg-[#c9d4e6]`, thumb `size-[18px] bg-white shadow-[0_1px_2px_rgba(0,0,0,.2)] translate-x-[2px]/[20px]`, `transition .15s`.

### 4.5 Tabs

**a) Underline tabs com contagem** (Documentos, Assinaturas, Admin): strip `flex gap-0.5 px-3 pt-2 border-b border-border overflow-x-auto`; trigger `h-[38px] px-3 text-[13.5px] border-b-2 -mb-px whitespace-nowrap` — ativo `text-primary font-semibold border-primary`, inativo `text-text-secondary font-medium border-transparent hover:text-foreground`; pill `text-[11.5px] font-semibold px-[7px] py-px rounded-md` — ativa `bg-primary-soft text-primary`, inativa `bg-muted text-muted-foreground`. → `Tabs` + `TabsList variant="line"` (ou classes custom) + `Badge`.

**b) Segmented control** (Documento, Usuários, API, Integrações, Dashboard "30 dias", wizard "Sequencial | Todos ao mesmo tempo" e "E-mail | WhatsApp | SMS", público "Desenhar | Digitar | Certificado"): trilho `inline-flex bg-accent rounded-lg p-[3px] gap-0.5`; item `px-3 py-[5px] rounded-md text-[12.5px]` — ativo `bg-white shadow-segment font-semibold text-foreground`, inativo `font-medium text-text-secondary hover:text-foreground`. → `Tabs` com `TabsList` estilizado ou `ToggleGroup type="single"`.

**c) Vertical nav tabs** (Configurações): botões `h-9 px-3 rounded-lg text-[13.5px] text-left` — ativo `font-semibold text-primary bg-primary-soft`, inativo `font-medium text-text-secondary hover:bg-accent`. Mesmo estilo dos itens de pasta (Documentos) e do TOC (API, `13px`).

**d) Pills de categoria** (Modelos): `h-8 px-3 rounded-full text-[12.5px] font-semibold` — ativo `border-primary bg-primary-soft text-primary`, inativo `border-input bg-white text-text-secondary`.

### 4.6 Badge de status (`Badge`)

`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[12px] font-semibold border whitespace-nowrap` + dot `size-1.5 rounded-full bg-current` (ou ícone `Check` 12px stroke 2.5 nos "Assinado" de signatário). Variantes → ver tabela completa em §5. Sem dot: badge de plano (11.5px), badge de ambiente, HTTP code (11.5px/700, sem borda), chips de evento (11px, radius 5).

### 4.7 Chips / tags

- **Chip neutro** (canal/autenticação em cards de signatário): `inline-flex gap-[5px] text-[11.5px] font-semibold text-text-secondary bg-muted rounded-md px-[7px] py-0.5` + ícone 12px (`MessageCircle` WhatsApp, `Smartphone` Token SMS, `UserRound` Selfie, `Mail` E-mail, `Shield` ICP-Brasil). Versão menor na lista de assinaturas: `11px px-1.5 rounded-[5px]`.
- **Chip selecionável (multi)**: `h-[30px] px-[10px] rounded-full border text-[12.5px] font-semibold` — selecionado `border-primary bg-primary-soft text-primary` com prefixo "✓ "; não selecionado `border-input bg-white text-text-secondary`. → `ToggleGroup type="multiple"`.
- **Chip de filtro facetado**: `h-[34px] px-[10px] rounded-lg border border-dashed border-border-dashed bg-white text-[13px] font-medium text-text-secondary hover:bg-accent-subtle hover:text-foreground` + ícone 14px. → `Button variant="outline"` abrindo `Popover`+`Command` (padrão data-table do shadcn).
- **Chip de evento** (webhook): `text-[11px] font-semibold text-primary bg-primary-soft rounded-[5px] px-1.5 py-px font-mono`.
- **Inline code**: `font-mono text-[12px] bg-muted rounded px-1`.

### 4.8 Tabela (CSS grid nos mocks)

- Contêiner: card branco `rounded-xl border shadow-card`.
- Header: `h-[38px] grid items-center px-4 (16px) bg-background border-y border-muted text-[12px] font-semibold text-muted-foreground`.
- Linha: `grid items-center px-4 py-[9px]|[10px] border-b border-muted text-[13.5px] hover:bg-row-hover`; selecionada `bg-accent-subtle`.
- Célula título: tile 36×36 `rounded-lg bg-accent-subtle text-primary` com `FileText` 17px + título link `font-semibold ellipsis` + meta `text-[12px] text-muted-foreground tabular-nums`.
- Célula secundária: `text-[13px] text-text-secondary`.
- Kebab: ghost 30×30 `MoreHorizontal` 16px `text-muted-foreground`.
- Colunas (grid-template-columns) por tela: Dashboard recentes `minmax(0,2.4fr) 1.3fr 1fr 1fr 48px`; Documentos `36px minmax(0,2.6fr) 1fr 1.2fr 1.1fr 1fr 1fr 44px`; Assinaturas `minmax(0,1.6fr) minmax(0,1.8fr) .9fr 1.3fr 1fr 1.1fr 44px`; Usuários `minmax(0,2.2fr) 1.2fr 1.2fr .9fr 1.1fr 44px`; Permissões `minmax(0,2.4fr) repeat(4,minmax(0,1fr))` (header 40px); Notificações `minmax(0,2.4fr) repeat(3,90px)`; Faturas `1fr minmax(0,2fr) 1fr 1fr 100px`; Admin `minmax(0,2.2fr) 1fr .9fr 1.3fr 1fr 1.1fr 1fr 120px`; API keys `minmax(0,1.6fr) 1.4fr .9fr 1.2fr 1fr .8fr 80px`; Entregas `minmax(0,1.4fr) minmax(0,2fr) .8fr .8fr 1.1fr 80px`; Endpoints `70px 1.4fr 2fr`; Erros `60px 1fr 2fr`.
- → `Table` do shadcn + TanStack (seleção, paginação server-side via Inertia) **ou** manter CSS grid para fidelidade de células de duas linhas. Rodapé: `flex justify-between px-4 py-3 text-[12.5px] text-muted-foreground`.

### 4.9 Rodapé de tabela / Paginação (`Pagination`)

Esquerda: "Mostrando {n} de {total} {entidade}". Direita: "Linhas por página" + select 30px mostrando `10`; pager: prev/next 30×30 `rounded-md border-input` (prev desabilitado com ícone `#c9d4e6`), páginas `h-[30px] min-w-[30px] px-2 rounded-md font-semibold` — ativa `bg-navy text-white`, outras `border-input bg-white`, elipse "…", última página (32 / 129). Admin omite "Linhas por página".

### 4.10 Cards e stat tiles (`Card`)

- Card: `bg-card border border-border rounded-xl shadow-card p-5`; header com título `15px/600` + descrição `13px` ou `12.5px text-muted-foreground mt-1`, ação à direita (link `13px/600 text-primary` ou botão outline 32px).
- **KPI (Dashboard)**: `p-5 pb-[18px] flex flex-col gap-2.5`; linha 1 label `13px/500 text-text-secondary` + badge delta (`11.5px/600 px-[7px] py-0.5 rounded-md` success/warning com ícone `ArrowUpRight` 11px); valor `30px/700 tracking-[-.02em] leading-none tabular-nums` (sufixo `16px/600 text-muted-foreground ml-1`); rodapé `12.5px text-muted-foreground`.
- **KPI compacto (Assinaturas, Admin)**: `px-[18px] py-4 gap-1.5`; label `12.5px/500 #47536b`; valor `26px/700`; caption `12px` colorida (`#12784a`/`#9a5b00`/`#b42323` 600 ou `#8a96ad`).
- **Card escuro** (Plano atual, Sandbox): `bg-navy text-white rounded-xl p-5`, eyebrow `11px/700 uppercase tracking-[.16em] text-on-navy-muted`, texto `13px|13.5px text-on-navy-secondary`.
- **Danger zone**: card com `border-danger-border`, título `15px/600 text-danger`.
- **Card de link** (SDK, mapa): `hover:border-primary` (+ `hover:shadow-card-hover`).

### 4.11 Callouts / Alert (`Alert`)

- Info: `bg-primary-soft border border-primary-soft-border text-primary text-[12.5px] rounded-[10px] p-3 leading-[1.5]` (tip de pastas sem borda; resumo do wizard com borda).
- Warning: `bg-warning-bg border-warning-border text-warning` com ícone `Info` (API).
- Success banner: `bg-success-bg border-success-border rounded-[10px]` texto `#12784a` 600 + botão "Fechar" + code block + "Copiar" (Integrações); card de sucesso auth `p-6 rounded-xl` com ícone 40×40 `bg-success-solid text-white rounded-[10px]`.
- Caixa neutra: `bg-[#f7f9fc] border-border rounded-lg p-3 text-[12px]` (hash, comprovante, OTP panel).

### 4.12 Stepper

**Wizard (horizontal, clicável)**: card branco `px-4 py-3 overflow-x-auto`, cada passo é botão: círculo 28px `rounded-full border-[1.5px] text-[12.5px]/700` — concluído `bg-primary text-white border-primary` com "✓"; atual `bg-white text-primary border-primary`; futuro `bg-white text-muted-foreground border-input`; título `13px/600` (`#0b1f42` atual/concluído, `#8a96ad` futuro) + subtítulo `11.5px #8a96ad`; conector `h-0.5 flex-1` `bg-primary` (concluído) / `bg-border`; último sem conector. Passos: Documento/"Upload ou modelo", Signatários/"Quem assina e como", Campos/"Posição das assinaturas", Revisar/"Mensagem e envio".

**Público (pills no header)**: `h-[30px] px-[10px] rounded-full text-[12px]/600 gap-1.5` com dot 16px `rounded-full text-[10px]` — atual `bg-primary-soft text-primary`, dot `bg-primary text-white` com número; concluído `text-success`, dot `bg-success-solid text-white` "✓"; pendente `text-muted-foreground`, dot `bg-border text-muted-foreground`. Passos: "Confirmar identidade", "Assinar", "Concluído".

### 4.13 Dropzone

`border-[1.5px] border-dashed border-border-dashed rounded-xl bg-background px-5 py-9 flex flex-col items-center gap-2.5 text-center cursor-pointer hover:border-primary hover:bg-accent-subtle`; tile 44×44 `rounded-xl bg-primary-soft text-primary` com `Upload` 20px; texto `13.5px/600` "Arraste o arquivo aqui ou <span class="text-primary">selecione no computador</span>"; helper `12.5px text-muted-foreground` "PDF ou DOCX · até 25 MB · vários arquivos são unidos em um só envelope". Linha de arquivo enviado: `border border-border rounded-[10px] p-3 flex gap-3` — badge "PDF" 36×36 `bg-danger-bg text-danger text-[10px]/800 rounded-lg`, nome `13.5px/600`, meta `12px #8a96ad` "412 KB · 6 páginas · enviado agora", status `Check` + "Pronto" `12.5px/600 text-success`, botão lixeira ghost `hover:bg-danger-bg hover:text-danger`.

### 4.14 Modal / Dialog (`Dialog`)

Overlay `fixed inset-0 bg-[rgba(11,31,66,.45)] backdrop-blur-[2px] z-50 p-6 grid place-items-center`; painel `w-full max-w-[460px] bg-white rounded-2xl (14px) shadow-dialog p-6 flex flex-col gap-4 animate-[dialogIn_.18s_ease-out]`; header título `18px/700` + descrição `13px text-text-secondary` + X ghost 30×30; footer `flex justify-end gap-2 pt-1` com outline 36px "Cancelar" + primary 36px. Ações destrutivas ("Cancelar documento", "Solicitar exclusão", "Revogar", "Rotacionar segredo") → `AlertDialog` **[não desenhado]**, mesmo estilo.

### 4.15 Toast

**[não desenhado]** — usar `sonner` com `richColors` desligado e classes: fundo branco, `border-border rounded-[10px] shadow-popover text-[13.5px]`; ícone/acento por semântica com as cores de §1.1. Mensagens sugeridas: "Copiado", "Alterações salvas", "Lembrete enviado".

### 4.16 Empty states

Único desenhado: Documentos → `py-12 px-5 text-center text-[13.5px] text-muted-foreground` "Nenhum documento nesta combinação de pasta e status.". **[não desenhado]** para as demais listas: reutilizar o padrão do card de sucesso (ícone 52–64px em `bg-primary-soft text-primary rounded-[14px]`, título 20px/700, texto 13.5px, CTA primário).

### 4.17 Avatar de iniciais (`Avatar` + `AvatarFallback`)

Quadrado arredondado (não circular). Paleta cíclica por índice `i % 4`: `[#e8f0fd/#1257c9]`, `[#e6f7ee/#12784a]`, `[#fff4e0/#9a5b00]`, `[#f1f4f9/#47536b]` (usuários, admin, signatários no wizard; pendências usam a mesma paleta). Em Assinaturas e no detalhe do documento a cor segue o **status** do signatário (pendente âmbar, assinado verde, recusado vermelho, expirado cinza `fg #47536b`). Organização: `bg-navy text-white`. Usuário logado: `bg-primary-soft text-primary`. Tamanhos e raios em §1.4. Grupo empilhado: 26px, `rounded-[7px] ring-2 ring-white -ml-1.5`, texto `10px/700`, seguido de progresso "1 de 2" `12.5px text-text-secondary`.

### 4.18 Timeline / auditoria

Linhas `grid grid-cols-[20px_1fr] gap-3`; marcador 20px `rounded-full text-[10px]/700` numerado sequencialmente, cor por tipo: info `bg-primary-soft text-primary`, ok `bg-success-bg text-success`, warn `bg-warning-bg text-warning`; conector `w-0.5 bg-accent my-1 flex-1`; conteúdo `pb-4`: título `13.5px/600 leading-[1.3]`, meta `12px text-muted-foreground tabular-nums mt-[3px]`. Caixa de hash ao final (§4.11).

### 4.19 Visualizador de PDF

Card `rounded-xl border overflow-hidden`. Toolbar `px-3.5 py-2.5 border-b text-[13px] text-text-secondary flex justify-between flex-wrap`: prev/next 28×28 outline `rounded-md`, "Página **1** de 6" tabular; zoom `−` / `100%` (min-w 40 centrado) / `+`; divisor; link "Certificado" (`ShieldCheck` 14px, `13px/600 text-primary`). Área `bg-accent p-6 flex justify-center`; página `w-full max-w-[560px] aspect-[1/1.3] bg-white shadow-pdf p-[8%_9%]`. Público: página `max-w-[680px]`, sombra `.1`, toolbar em card `rounded-[10px]` com "Baixar PDF" e "Ampliar" (30px). Editor de campos: página `max-w-[520px]` em contêiner `bg-accent rounded-xl p-5` + rail de thumbnails 72px (`aspect-[1/1.3] border-2 rounded` — atual `border-primary`, dot azul 8px quando tem campos, número `9px` no canto).

**Campos sobrepostos:** `border-[1.5px] rounded-md p-2 relative`; tag flutuante `absolute -top-[9px] left-2 text-[9px]/700 uppercase tracking-[.1em] px-1` com bg igual ao campo.
- Assinado: `border-solid border-success-solid bg-success-bg`, tag `text-success`, assinatura em Caveat 24px `rotate-[-2deg]`, "✓ Assinado em 02/09/2026 14:32" `9px text-success`.
- Pendente (app): `border-dashed border-warning-solid bg-warning-bg-soft`, tag e nome `text-warning` (`11px/600`), "Aguardando assinatura" `9px`.
- Seu campo (público, não assinado): `border-dashed border-primary bg-primary-soft`, tag `text-primary`, "Clique para assinar aqui" `10px/600 text-primary`.
- Outro signatário (público): `border-dashed border-input bg-background`, tag `text-muted-foreground`, "Carlos Mendes · assina depois de você" `10px`.
- Editor: caixas arrastáveis `cursor-move`, cor por signatário (`#1257c9` / `rgba(18,87,201,.08)`; `#d59b2a` / `rgba(213,155,42,.1)`), tag `9px/700 uppercase tracking-[.08em]` "Maria · Assinatura", alça de resize no canto inferior direito; placeholders "DD/MM/AAAA", "000.000.000-00".
- Carimbo rodapé `absolute right-3 bottom-2.5 text-[8px] text-muted-foreground` "AV-00148 · pág. 1/6".

### 4.20 Signature pad

Contêiner `relative h-[150px] border-[1.5px] border-dashed border-border-dashed rounded-[10px] bg-background cursor-crosshair grid place-items-center`; linha-base `absolute left-4 right-4 bottom-7 h-px bg-input`; hint `absolute left-4 bottom-2.5 text-[11px] text-muted-foreground` "Desenhe com o dedo ou mouse"; botão "Limpar" `absolute right-2.5 top-2.5 h-[26px] px-2 rounded-md border-input text-[11.5px]/600 text-text-secondary`. Traço em `#0b1f42`. Modo "Digitar": input 40px + preview `h-[100px] border-border rounded-[10px] bg-background` com Caveat 40px. Modo "Certificado": caixa neutra com título `#0b1f42` bold, texto `13px #47536b leading-[1.55]`, botão outline 32px "Selecionar certificado". → lib `signature_pad`.

### 4.21 OTP (`InputOTP`)

6 inputs `flex-1 h-12 text-center text-[20px]/700 rounded-lg border-input bg-white tabular-nums`, `maxLength=1`, foco padrão; dentro de painel `bg-[#f7f9fc] border-border rounded-[10px] p-3.5 gap-2.5` com linha `Smartphone` 16px `stroke-primary` + "Enviamos um código por SMS para **•••• ••••-1234**" (13px) e rodapé `12px text-muted-foreground` "Código válido por 10 min" / link "Reenviar código".

### 4.22 Progress (`Progress`)

Linha label `flex justify-between text-[12.5px] text-text-secondary` com "**148** / 500" (valor em `text-foreground` bold, tabular); trilho `h-2 rounded-full bg-accent mt-1.5 overflow-hidden`; indicador `bg-primary`. Admin: `h-[5px]`, indicador `#d59b2a` se ≥ 90%.

### 4.23 Gráfico de barras (Dashboard)

30 barras `flex-1 gap-1 h-[190px]`; barra externa altura `sentPct%` `bg-[#dbe6fb] rounded-t-[3px]`; interna absoluta `bottom-0 h-[donePct%] bg-primary rounded-t-[3px]`; `title="{s} enviados · {d} concluídos"`; legenda quadrados 10px radius 3; eixo x `border-t border-muted text-[11.5px] text-muted-foreground flex justify-between px-5 py-2 pb-4`. → shadcn `Chart` (Recharts `BarChart` com duas séries sobrepostas) ou divs.

### 4.24 Bloco de código (API)

`bg-navy rounded-xl overflow-hidden`; header `px-3.5 py-2 border-b border-white/[.08] text-[12px]/600 text-on-navy-subtle flex justify-between` + toggle de linguagem (`bg-white/[.06] rounded-md p-0.5`; ativo `bg-white text-foreground`, inativo `text-on-navy-subtle`); `<pre>` `p-4 text-[12.5px] leading-[1.6] font-mono text-code-text overflow-x-auto`; strings `text-code-string`, comentários `text-on-navy-muted`. Resposta clara: `bg-background border rounded-xl`, rótulo "201 Created" `text-success`.

### 4.25 Matriz de permissões / notificações

Linhas `py-[11px] px-5 border-b border-muted hover:bg-row-hover`; nome `13.5px/500` + descrição `12px text-muted-foreground`; células centradas: permissão concedida = quadrado 22px `rounded-md bg-success-bg text-success text-[12px]/700` "✓", negada = `bg-muted text-muted-foreground` "–"; notificações = `Checkbox` 16px.

### 4.26 Popover de conta, kbd, divisores

Ver §3.1. `kbd`: `text-[11px] text-muted-foreground border border-border rounded px-[5px] py-px bg-sidebar`. Divisor "ou": `flex items-center gap-3 text-[12px] text-muted-foreground` com linhas `h-px bg-border flex-1`.

---

## 5. Vocabulário de status (PT-BR ↔ cores)

Formato `fg / bg / border`. Enum sugerido para o backend na coluna da direita.

### 5.1 Documento (envelope)

| Label | Chave no mock | fg / bg / border | Enum sugerido | Onde aparece |
|---|---|---|---|---|
| Aguardando | `wait` | `#9a5b00 / #fff4e0 / #f5dfae` | `awaiting_signature` | Dashboard, Documentos, header do detalhe ("Aguardando · 1 de 2") |
| Em andamento | `prog` | `#1257c9 / #e8f0fd / #c9dbf7` | `in_progress` | Dashboard, Documentos (≥1 assinou, faltam outros) |
| Assinado | `done` | `#12784a / #e6f7ee / #bfe9d1` | `completed` | badge; tab chama-se **"Concluídos"** |
| Recusado | `ref` | `#b42323 / #fdeaea / #f5c2c2` | `refused` | tab "Recusados / expirados" |
| Expirado | `exp` | `#6b7891 / #f1f4f9 / #e6eaf2` | `expired` | idem |
| Rascunho | `draft` | `#47536b / #f1f4f9 / #e6eaf2` | `draft` | tab "Rascunhos" |
| Cancelado | — | **[não desenhado]** sugerir `#6b7891 / #f1f4f9 / #e6eaf2` | `cancelled` | ação "Cancelar documento"/API `document.cancelled` existem, badge não |

Rótulos de tab: Todos, Aguardando, Em andamento, Concluídos, Rascunhos, Recusados / expirados. Progresso "x de y" acompanha o badge. **[ambíguo]** distinção Aguardando × Em andamento: o mock mostra "Aguardando" com 1 de 2 assinaturas e "Em andamento" com 2 de 3 — presumir Aguardando = ninguém assinou ou próximo da ordem pendente; Em andamento = já há ≥1 assinatura. Definir regra no backend.

### 5.2 Signatário

| Label | fg / bg / border | Enum | Onde |
|---|---|---|---|
| Pendente | `#9a5b00 / #fff4e0 / #f5dfae` | `pending` | lista Assinaturas, KPI "Pendentes"; avatar âmbar |
| Aguardando | idem | `pending` | card de signatário no detalhe do documento **[ambíguo: mesmo estado, label diferente — padronizar em "Pendente" ou "Aguardando"]** |
| Assinado | `#12784a / #e6f7ee / #bfe9d1` (com ícone check) | `signed` | avatar verde |
| Recusado | `#b42323 / #fdeaea / #f5c2c2` | `refused` | nota "Motivo: “valores divergentes”" |
| Expirado | `#6b7891 / #f1f4f9 / #e6eaf2` (avatar fg `#47536b`) | `expired` | nota "Prazo encerrado em 04 set" |
| Identidade confirmada | `#12784a / #e6f7ee` sem borda | `authenticated` (página pública) | |

Canais: E-mail, WhatsApp, SMS, Plataforma (`email | whatsapp | sms | platform`). Autenticação: Token e-mail, Token SMS, Selfie, Certificado ICP-Brasil (lista: "ICP-Brasil"; nota "Certificado A3"), Assinatura desenhada (`token_email | token_sms | selfie | icp_brasil | drawn`). Papéis vistos: Locatária, Locatário, Fiador, Parte, Testemunha, Compradora, Representante, Vistoriador, Proprietário. Ordem: "Sequencial" / "Todos ao mesmo tempo" (`sequential | parallel`). Tipos de campo: Assinatura, Rubrica, Nome completo, CPF, Data, Texto livre, Caixa de seleção, Carimbo (`signature | initials | name | cpf | date | text | checkbox | stamp`).

### 5.3 Usuário da conta

| Label | fg / bg / border | Enum |
|---|---|---|
| Ativo | `#12784a / #e6f7ee / #bfe9d1` | `active` |
| Convite pendente | `#9a5b00 / #fff4e0 / #f5dfae` | `invited` |
| Inativo | `#6b7891 / #f1f4f9 / #e6eaf2` | `inactive` |
| 2FA "Ativa" | texto `#12784a` 600 | `two_factor: true` |
| 2FA "Inativa" | texto `#b42323` 600 | `two_factor: false` |
| 2FA "—" | texto `#8a96ad` | convite pendente |

Funções: Administrador, Gerente, Operador, Somente leitura (`admin | manager | operator | viewer`). Sufixo "(você)" para o usuário atual.

### 5.4 Conta / cliente (admin)

| Label | fg / bg / border | Enum |
|---|---|---|
| Ativo | `#12784a / #e6f7ee / #bfe9d1` | `active` |
| Trial | `#1257c9 / #e8f0fd / #c9dbf7` | `trial` |
| Inadimplente | `#b42323 / #fdeaea / #f5c2c2` | `past_due` |
| Cancelado | `#6b7891 / #f1f4f9 / #e6eaf2` | `cancelled` |

Planos (badge sem dot, 11.5px): Empresarial `#ffffff / #0b1f42`; Profissional `#1257c9 / #e8f0fd`; Grátis `#47536b / #f1f4f9` (`enterprise | professional | free`). Limites implícitos: Grátis 1 usuário / 5 docs; Profissional 10 / 500 (R$ 49); Empresarial 50 / 3.000 (R$ 399).

### 5.5 Cobrança

| Label | Estilo | Enum |
|---|---|---|
| Ativo (plano) | pill sólida `bg #1fb865 text white 11px/700 rounded-md px-2 py-0.5` | `subscription.active` |
| Paga (fatura) | `#12784a / #e6f7ee / #bfe9d1` | `invoice.paid` |
| Pendente / Falhou / Cancelada | **[não desenhado]** usar warning / danger / neutral | `pending | failed | void` |

### 5.6 API e webhooks

| Label | Estilo | Enum |
|---|---|---|
| Produção | `#1257c9 / #e8f0fd` | `live` (prefixo `av_live_`) |
| Sandbox | `#9a5b00 / #fff4e0` | `test` (`av_test_`) |
| Ativa (chave) | dot + texto `#12784a` | `active` |
| Revogada | dot + texto `#8a96ad` | `revoked` |
| Ativo (endpoint) | `#12784a / #e6f7ee / #bfe9d1` | `enabled` |
| Pausado | `#6b7891 / #f1f4f9 / #e6eaf2` | `paused` |
| HTTP 2xx | `#12784a / #e6f7ee` (11.5px/700) | |
| HTTP 3xx–4xx | `#9a5b00 / #fff4e0` | |
| HTTP 5xx | `#b42323 / #fdeaea` | |
| Método GET | `#1257c9 / #e8f0fd` | |
| Método POST | `#12784a / #e6f7ee` | |
| Método DELETE | `#b42323 / #fdeaea` | |
| Erros 400/401/422 | texto `#b42323`; 402/409/429 `#9a5b00`; 404 `#47536b` | |

Eventos de webhook: `document.sent`, `signer.viewed`, `signer.authenticated`, `signer.signed`, `signer.refused`, `document.completed`, `document.expired`, `document.cancelled`. **[ambíguo]** o detalhe do documento mostra "document.signer_signed" — usar a nomenclatura da doc da API (`signer.signed`).

### 5.7 Deltas de KPI

Positivo: `#12784a / #e6f7ee / #bfe9d1` (badge) ou texto `#12784a` 600; atenção: `#9a5b00 / #fff4e0 / #f5dfae`; negativo: texto `#b42323` 600; neutro: texto `#8a96ad`.

---

## 6. Especificação tela a tela

Convenções: **Layout** em bullets; **Copy** = texto exato a reutilizar; ações indicam o componente/rota alvo.

### 6.1 Autenticação — `App - Login` / `App - Criar conta` / `App - Recuperar senha`

**Propósito:** um único `AuthLayout` com três páginas (`view: login | cadastro | recuperar`). Os três arquivos são o mesmo template.

**Layout**
```
┌──────────── 44% (min 360px) ────────────┬───────────── flex 1 ─────────────┐
│ aside navy #0b1f42, padding 40px 48px   │ main centraliza coluna 400px     │
│  ┌ logo (34px, invertido p/ branco)     │  h1 26px + subtítulo 14px        │
│  │ eyebrow · hero italic 800 · glass    │  campos (gap 16 / 14)            │
│  │ card assinatura · trust badges       │  CTA primário 40px               │
│  └ links rodapé (Termos·Privacidade·Sup)│  rodapé "Não tem conta? …"       │
└─────────────────────────────────────────┴──────────────────────────────────┘
```
Aside: glow radial `420×420` em `right:-120px; top:-120px` (`radial-gradient(circle, rgba(46,123,239,.35), rgba(46,123,239,0) 70%)`), `overflow: hidden`; eyebrow "Plataforma de assinatura digital" (`11px/600 uppercase .24em #6f87b8`); h2 "Assine documentos<br>em **minutos**" (`clamp(36,4vw,56)px 800 italic uppercase lh .95`, "minutos" `#2e7bef`); glass card (`max-w-[360px] mt-9 bg-white/[.06] border-white/[.1] rounded-xl px-5 pt-[18px] pb-3.5`): "ASSINATURA" `9.5px/700 .16em #6f87b8`, "Maria A. Souza" Caveat 34px branco `rotate(-2deg)` `animation: signWipe 1.6s ease-out .6s both`, rodapé tracejado `rgba(255,255,255,.14)` `12px #b9c7de` "Contrato de locação · Apto 302" + "Assinado ✓" `#1fb865 700`; trust badges `12.5px/600 #b9c7de gap 10px 24px mt-7`: "✓ Validade jurídica", "✓ Conforme LGPD", "✓ ICP-Brasil", "✓ Trilha de auditoria"; rodapé `12px #6f87b8`: "Termos de uso", "Privacidade", "Suporte".

**Login** (`view=login`): h1 "Entrar"; "Acesse sua conta AssinaVelox."; Label "E-mail" (placeholder "voce@empresa.com.br"); "Senha" (placeholder "Sua senha") com toggle olho; linha `13px`: checkbox marcado "Manter conectado" + link 600 "Esqueci minha senha"; CTA "Entrar"; divisor "ou"; outline 40px com `ShieldCheck` stroke primary "Entrar com certificado digital" (hover `bg #f4f8fe border-primary`; fluxo **[não definido]**); rodapé `13.5px #47536b` "Não tem conta? **Criar conta grátis**".

**Criar conta** (`view=cadastro`): h1 "Criar conta"; "Grátis, sem cartão de crédito. Leva menos de 2 minutos."; campos (gap 14): "Nome completo" (ph "Seu nome"), "E-mail corporativo", grid 2 col: "Empresa" (ph "Razão social") | "CNPJ (opcional)" (ph "00.000.000/0000-00", máscara); "Senha" (ph "Mínimo de 8 caracteres") + toggle + medidor de força 4 segmentos `h-1 rounded-full` (`#1fb865` preenchido / `#eef2f9` vazio; mock 3/4) + helper "Use letras, números e um símbolo."; checkbox "Li e aceito os **Termos de uso** e a **Política de Privacidade**."; CTA "Criar conta grátis"; rodapé "Já tem conta? **Entrar**".

**Recuperar senha** (`view=recuperar`): link `13px/600 #47536b` com `ArrowLeft` "Voltar para o login"; estado `notSent`: h1 "Recuperar senha"; "Informe o e-mail da sua conta. Enviaremos um link para redefinir a senha, válido por 30 minutos."; campo "E-mail"; CTA "Enviar link de redefinição". Estado `sent`: card verde (`p-6 border #bfe9d1 bg #e6f7ee rounded-xl gap-4`): ícone 40×40 `bg #1fb865 rounded-[10px]` check branco; h1 20px "Verifique seu e-mail"; texto `14px #12784a lh 1.55` "Enviamos um link para **ana@horizonte.com.br**. Se não aparecer em alguns minutos, confira a pasta de spam."; botão 34px `border #bfe9d1 text #12784a` "Reenviar link".

**Estados:** `showPass`, `sent`. **[não desenhado]** loading, erros de validação, throttle. Rotas Inertia: `Auth/Login`, `Auth/Register`, `Auth/ForgotPassword`. Config `auth.passwords.users.expire = 30`.

### 6.2 `App - Dashboard`

**Propósito:** visão geral do tenant: KPIs, gráfico 30 dias, pendências, uso do plano, documentos recentes.

**Layout**
```
[Sidebar active=dashboard] │ header (trigger · Imobiliária Horizonte › Dashboard · busca ⌘K · ? · 🔔)
                           │ p-6 gap-5:
                           │  eyebrow VISÃO GERAL / h1 "Bom dia, Ana" / subtítulo   [Últimos 30 dias ▾] [Exportar]
                           │  KPI ×4 (auto-fit 140px)
                           │  ┌ gráfico (2 1 380px) ────────┐ ┌ Pendências (1 1 280px) ┐
                           │  │ segmented 30 dias|90|12 m   │ │ lista 4 + link         │
                           │  │ legenda · 30 barras · eixo  │ ├ Uso do plano ─────────┤
                           │  └─────────────────────────────┘ │ 3 progress            │
                           │  Documentos recentes (tabela 5 linhas + rodapé)
```
**Copy/dados:** subtítulo "Quarta-feira, 3 de setembro de 2026 · 23 documentos aguardam assinatura". KPIs: "Documentos enviados" 148, badge ↗ "12%", "vs. 132 em agosto"; "Aguardando assinatura" 23, badge âmbar "6 vencem em 48h", "Lembretes automáticos ativos"; "Concluídos" 119, "92% de conclusão", "8 recusados ou expirados"; "Tempo médio para assinar" 42 **min**, "−8 min", "Do envio à última assinatura". Gráfico: "Assinaturas por dia" / "Enviadas × concluídas nos últimos 30 dias"; legenda "Concluídas **186**" (`#1257c9`), "Enviadas **214**" (`#dbe6fb`); eixo "5 ago · 12 ago · 19 ago · 26 ago · 2 set". Pendências: "Signatários sem resposta", badge 23 (`12px/700 #9a5b00/#fff4e0/#f5dfae`), linhas nome + "{doc} · há N dias" + botão "Lembrar" (28px); link "Ver todas as pendências →" (→ Assinaturas). Uso do plano: "Profissional · renova em 15 out", link "Gerenciar" (→ Cobrança); "Documentos 148 / 500", "Usuários 6 / 10", "Armazenamento 2,4 GB / 10 GB". Tabela: "Documentos recentes" / "Atualizados nos últimos 7 dias", botão "Ver todos" (32px); colunas Documento | Signatários | Status | Atualizado | ⋯; rodapé "Mostrando 5 de 148 documentos" + "Abrir lista completa".

**Ações:** seletor de período (DropdownMenu), Exportar, Lembrar (POST remind), kebab por linha. **Estados:** `sidebarOpen`; segmented control estático no mock. **[não desenhado]** loading/skeleton, estado vazio (novo tenant).

### 6.3 `App - Documentos`

**Propósito:** biblioteca de documentos/envelopes por pasta e status, busca, filtros, seleção em lote, paginação.

**Layout**
```
header: Imobiliária Horizonte › Documentos            ? 🔔
h1 "Documentos" / "312 documentos · 23 aguardando assinatura · 2,4 GB usados"   [↑ Importar] [+ Nova solicitação]
┌ rail 200px ───────────┐ ┌ card ────────────────────────────────────────────────┐
│ PASTAS            (+)  │ │ tabs underline: Todos 312 · Aguardando 23 · Em andamento 12 · Concluídos 251 · Rascunhos 8 · Recusados / expirados 18
│ Todos 312              │ │ toolbar: [🔍 Buscar por nome, signatário ou ID] [Período] [Signatário] [Criado por]   [⇅ Atualização recente]
│ Locação 148  (ativo)   │ │  — ou — barra de lote azul: "N documentos selecionados" [Baixar] [Mover para pasta] [Reenviar lembrete] [Cancelar]  Limpar seleção
│ Vendas 64 …            │ │ grid: ☐ | Documento | Pasta | Signatários | Status | Criado por | Atualizado | ⋯
│ tip azul               │ │ empty: "Nenhum documento nesta combinação de pasta e status."
└────────────────────────┘ │ rodapé: Mostrando N de 312 documentos · Linhas por página [10 ▾] · ‹ 1 2 3 … 32 ›
```
Pastas: Todos 312, Locação 148, Vendas 64, Administrativo 52, Jurídico 31, Arquivados 17; botão `+` `title="Nova pasta"`; tip: "**Dica:** arraste documentos para uma pasta para organizá-los. Pastas podem ter permissões por usuário." Barra de lote: `bg #e8f0fd border-b #c9dbf7 px-4 py-3`, label `13.5px/600 text-primary` ("1 documento selecionado" / "{n} documentos selecionados"), botões 32px `border #c9dbf7 hover:border-primary`, "Cancelar" destrutivo, "Limpar seleção" ghost primary. Linha: checkbox 15px, ID "AV-00148 · PDF · 6 págs", pasta, avatares + "1 de 2", badge, dono, "Hoje, 09:12".

**Estados:** `tab`, `folder`, `selected[]`, `allChecked`, `isEmpty`, `noneSelected/anySelected` (toolbar × barra de lote são mutuamente exclusivas). Filtros e ordenação são estáticos no mock → `Popover+Command`, `DropdownMenu`. Query params sugeridos: `status, folder, q, period, signer, owner, sort, page, per_page`.

### 6.4 `App - Documento` (detalhe)

**Propósito:** acompanhar um envelope: PDF com campos, signatários, trilha de auditoria, detalhes e ações.

**Layout**
```
header: Documentos › Locação › Contrato de locação — Apto 302                🔔
[←] h1 22px "Contrato de locação — Apto 302" [● Aguardando · 1 de 2]      [↓ Baixar ▾] [↻ Lembrar pendentes] [⋯]
    AV-00148 · Pasta Locação · Criado por Ana Ribeiro em 1 set 2026 · Expira em 15 set (12 dias)  ← este último #9a5b00 600
┌ PDF viewer (1.4 1 420px) ─────────────┐ ┌ (1 1 340px) ──────────────────────────────┐
│ ‹ Página 1 de 6 ›   − 100% +  | Certificado │ segmented: Signatários | Trilha de auditoria | Detalhes
│ página com campos assinado/pendente   │ painel da aba ativa                          │
└───────────────────────────────────────┘ └──────────────────────────────────────────────┘
```
**Aba Signatários:** linha `12.5px #47536b` "Ordem de assinatura: **sequencial**" / "Lembretes: **a cada 2 dias**". Card por signatário (`p-4 gap-3`): avatar 40px; nome 600 + "Locatária · 1ª" (`11.5px #8a96ad`); contato `12.5px #47536b` "maria.souza@gmail.com · +55 11 98877-1234"; chips canal/auth; badge status à direita. Rodapé (`border-t #f1f4f9 pt-2.5 text-[12px] #8a96ad`): assinado → "02 set 2026, 14:32 · IP 187.12.44.9 · iPhone (Safari)" + link "Ver evidências"; pendente → "Visualizou em 03 set, 08:51 · último lembrete hoje, 09:00" + botões 28px "Reenviar" / "Editar". Botão tracejado "Adicionar signatário ou testemunha" (`UserPlus`).
**Aba Trilha de auditoria:** card `px-5 py-[18px]`: "Trilha de auditoria" / "Todos os eventos, com carimbo de tempo e IP" + outline 30px "Relatório PDF"; 7 eventos (ver §4.18) — "Documento criado por Ana Ribeiro" (01 set 2026, 10:04 · IP 200.155.8.21 · Chrome / Windows), "Enviado para Maria A. Souza via WhatsApp e para Carlos Mendes via e-mail" (· Ordem sequencial), "Maria A. Souza visualizou o documento", "Maria A. Souza autenticou com token SMS e selfie" (ok; · selfie validada), "Maria A. Souza assinou (Locatária)" (ok; hash 4b0d…7c1e · geolocalização São Paulo/SP), "Carlos Mendes visualizou o documento", "Lembrete automático enviado para Carlos Mendes" (warn; · e-mail entregue); caixa "Hash SHA-256 do original" + hash mono.
**Aba Detalhes:** card `px-5 py-1.5`, linhas `grid-cols-[140px_1fr] py-[11px] border-b text-[13px]` chave `#8a96ad` / valor 500: ID, Status ("Aguardando · 1 de 2 assinaturas"), Criado por, Criado em ("01 set 2026, 10:04"), Expira em ("15 set 2026, 23:59"), Pasta, Modelo ("Contrato de locação residencial v3"), Arquivo ("contrato-apto-302.pdf · 412 KB · 6 páginas"), Lembretes ("Automáticos, a cada 2 dias"), Mensagem (entre aspas), Webhook ("document.signer_signed · 200 OK · 02 set 14:32"). Rodapé: outline 32px "Mover para pasta", "Duplicar" (→ wizard pré-preenchido), destrutivo "Cancelar documento" (→ AlertDialog).

**Estados:** `tab: signers|audit|details`. Baixar ▾ → menu (PDF assinado / original / certificado, conforme API `download?type=`). "Certificado", "Ver evidências", "Relatório PDF" são `#` no mock.

### 6.5 `App - Nova solicitacao` (wizard)

**Propósito:** criar e enviar um envelope em 4 passos; termina em tela de sucesso.

**Layout:** sidebar `active=documentos`; header com breadcrumb "Documentos › Nova solicitação", à direita "Rascunho salvo às 09:41" + outline 32px "Sair" (→ Documentos). Conteúdo `p-6 max-w-[1040px] mx-auto gap-5`: título "Nova solicitação de assinatura" / "Envie o documento, defina quem assina, posicione os campos e revise antes de enviar." (oculto quando `sent`); stepper (§4.12); corpo do passo; footer `border-t pt-4 pb-2 flex justify-between`: outline "← Voltar" (desabilitado no passo 1) | "Salvar rascunho" (ghost `#47536b`) + primário "Continuar →" (passo 4: "Enviar para assinatura").

**Passo 1 — Documento** (col `1.3 1 380px` + `1 1 280px`):
- Card upload: dropzone (§4.13) + linha do arquivo "contrato-apto-302.pdf".
- Card "Informações do documento": "Nome do documento" (valor "Contrato de locação — Apto 302"); grid `minmax(180px,1fr)`: selects "Pasta" = Locação, "Prazo para assinatura" = "15 dias (até 18 set)", "Idioma dos signatários" = "Português (Brasil)"; linha com borda `rounded-[10px]`: "Lembretes automáticos" / "Reenviar a cada 2 dias para quem ainda não assinou" + Switch ON.
- Card direito "Ou comece por um modelo" / "Campos e signatários já configurados": 4 botões-card (`border rounded-[10px] p-3 gap-3 hover:border-primary hover:bg-accent-subtle`, tile 32px `LayoutTemplate`): "Contrato de locação residencial v3 · 2 signatários · 5 campos", "Proposta comercial · 2 signatários · 3 campos", "Termo de vistoria · 3 signatários · 8 campos", "Procuração simples · 1 signatário · 2 campos"; link "Ver todos os modelos →".

**Passo 2 — Signatários** (coluna única):
- Card barra `px-5 py-3.5`: "Ordem de assinatura" + hint ("Cada signatário recebe o documento após o anterior assinar." | "Todos recebem o documento imediatamente.") + segmented "Sequencial | Todos ao mesmo tempo".
- Card por signatário (`px-5 py-[18px]`): tile numerado 28px (paleta por índice), heading = nome ou "Signatário N", select de papel 30px (Locatária/Fiador/Parte/Testemunha), lixeira `title="Remover"`; grid `minmax(200px,1fr)`: "Nome completo" (ph "Nome do signatário"), "E-mail" (ph "email@exemplo.com"), "Celular (WhatsApp / SMS)" (ph "+55 (11) 90000-0000"); "Enviar por" segmented E-mail | WhatsApp | SMS; "Como o signatário se autentica" chips multi: Token e-mail, Token SMS, Selfie, Certificado ICP-Brasil, Assinatura desenhada.
- Botões tracejados: "Adicionar signatário" (`UserPlus`), "Adicionar testemunha" (`Eye`; adiciona com role Testemunha), "Importar dos contatos" (`Users`). Novo signatário default: `role Parte, channel E-mail, auth [Token e-mail]`.

**Passo 3 — Campos** (rail 72px + canvas `1.5 1 380px` + painel `1 1 260px`):
- Canvas página 6 de 6 com "CLÁUSULA 12ª — DAS DISPOSIÇÕES FINAIS", "São Paulo, ____ de ____________ de 2026.", campos "Maria · Assinatura", "Carlos · Assinatura", "Maria · Data" (DD/MM/AAAA), "Carlos · CPF" (000.000.000-00).
- Painel "Adicionar campo para": pills de signatário (ativa `bg-primary text-white` com dot branco; inativa outline com dot `#d59b2a`, hover borda `#d59b2a`); helper "Arraste um tipo de campo para a página ou clique para inserir na posição padrão."; grid 2 col de 8 botões 38px `cursor-grab` com dot azul: Assinatura, Rubrica, Nome completo, CPF, Data, Texto livre, Caixa de seleção, Carimbo.
- Card "Campos inseridos" (contagem 5): linhas dot colorido + label + "pág. 6" / "todas"; checkbox marcado "Rubrica em todas as páginas".

**Passo 4 — Revisar** (`1.3 1 380px` + `1 1 280px`):
- Card "Documento" + link "Editar" (→ passo 1): badge PDF 40px, "Contrato de locação — Apto 302", "contrato-apto-302.pdf · 6 páginas · Pasta Locação · expira em 18 set 2026".
- Card "Signatários · ordem sequencial" (ou "· todos ao mesmo tempo") + "Editar" (→ passo 2): linhas tile numerado, "Nome · Papel", "Canal · Auth1 + Auth2" ("sem autenticação extra"; "(sem nome)").
- Card "Mensagem aos signatários": textarea 4 linhas "Olá! Segue o contrato do Apto 302 para assinatura. Qualquer dúvida, estou à disposição."; checkbox marcado "Enviar cópia do documento assinado para todos ao concluir".
- Card "Resumo" (kv `13px border-t #f1f4f9`): Campos "5 em 1 página + rubricas"; Lembretes "A cada 2 dias"; Validade "15 dias"; Consumo do plano "1 documento (149 / 500)". Callout info: "Ao enviar, os signatários recebem um link exclusivo. O documento fica bloqueado para edição e cada evento passa a ser registrado na trilha de auditoria."

**Sucesso** (`sent`): coluna `max-w-[560px] mt-10 text-center`: ícone 64px `rounded-[18px] bg-success-bg text-success-solid`; h1 26px "Enviado para assinatura"; "Maria A. Souza recebeu o link por WhatsApp. Carlos Mendes será notificado por e-mail assim que Maria assinar (ordem sequencial). Você acompanha tudo pelo detalhe do documento."; primário "Ver documento" + outline "Criar outra solicitação"; meta "ID AV-00149 · 3 set 2026, 09:44".

**Estados:** `step 1–4`, `sent`, `order seq|par`, `signers[]` (name, email, phone, role, channel, auth[]), foco em inputs, hover em cards/dropzone, thumbnails com dot de campos. Persistir rascunho (autosave). **[não desenhado]** validações, upload em progresso, erro de conversão DOCX.

### 6.6 `App - Assinaturas`

**Propósito:** uma linha por signatário×documento; reenviar, ver evidências, baixar certificado.

**Layout:** header "Imobiliária Horizonte › Assinaturas" + 🔔; h1 "Assinaturas" / "Cada linha é um signatário em um documento. Reenvie, veja evidências ou baixe o certificado." + [↓ Exportar CSV] [↻ Lembrar todos os pendentes]; KPIs ×4 compactos: "Assinadas hoje" 14 / "+4 vs. ontem" (verde); "Pendentes" 31 / "9 já visualizaram" (âmbar); "Recusadas (30 dias)" 5 / "2,1% do total"; "Canal mais usado" WhatsApp / "58% das assinaturas · 31 min em média". Card: tabs Todas 412 · Pendentes 31 · Assinadas 358 · Recusadas 5 · Expiradas 18; toolbar busca "Buscar por signatário, e-mail ou documento" + chips "Canal", "Autenticação", "📅 Últimos 30 dias"; grid Signatário | Documento | Canal | Autenticação | Status | Data | ⋯; rodapé "Mostrando N de 412 assinaturas" + ‹ 1 2 3 ›.

**Célula Signatário:** avatar 34px (cor por status) + nome 600 + contato `12px #8a96ad`. **Documento:** título link `13px/500 hover:text-primary` + "AV-00148 · Fiador". **Canal:** texto `12.5px #47536b`. **Autenticação:** chips 11px. **Data:** "Hoje, 08:51" + nota `11.5px #8a96ad` ("Visualizou · lembrete às 09:00", "Enviado · não visualizou", "IP 187.12.44.9 · iPhone (Safari)", "Motivo: “valores divergentes”", "3 lembretes enviados", "Prazo encerrado em 04 set", "Certificado A3"). Kebab → menu: Reenviar, Ver evidências, Baixar certificado.

**Estados:** `tab all|pend|ok|ref|exp`. **[não desenhado]** empty/loading.

### 6.7 `App - Modelos`

**Propósito:** galeria de modelos reutilizáveis por categoria.

**Layout:** header "Imobiliária Horizonte › Modelos"; h1 "Modelos de documentos" / "Documentos com campos e signatários pré-configurados. Use-os para enviar em segundos." + [+ Novo modelo]; toolbar: busca "Buscar modelo" + pills Todos · Locação · Vendas · Administrativo · Jurídico; grid `auto-fill minmax(240px,1fr) gap-4` de cards + card tracejado final.

**Card de modelo:** `rounded-xl border overflow-hidden shadow-card hover:border-border-dashed hover:shadow-card-hover`; preview `h-[120px] bg-accent flex items-end justify-center px-6 pt-4 relative` com folha branca (`rounded-t shadow-[0_-2px_12px_rgba(11,31,66,.08)]`, barra título `bg-navy/80 w-[60%]`, linhas `#eef2f9 h-1`, dois placeholders de campo tracejados azul `rgba(18,87,201,.06)` e âmbar `rgba(213,155,42,.08)`); badge de categoria `absolute top-2.5 left-3 text-[11px]/600 bg-white text-text-secondary border rounded-md px-2 py-0.5`; corpo `px-4 pt-3.5 pb-3`: nome `14px/600 lh 1.3`, meta `12.5px #8a96ad` "2 signatários · 5 campos · 6 págs", rodapé `12px #47536b mt-auto` "Usado 148 vezes · atualizado há 3 dias"; footer `border-t #f1f4f9 px-3 pt-2.5 pb-3 flex gap-2`: primário 32px "Usar modelo" (→ wizard com template) + outline 32×32 `Pencil` "Editar" + outline 32×32 `MoreHorizontal`.
**Card criar:** `min-h-[280px] border-[1.5px] dashed rounded-xl text-text-secondary hover:border-primary hover:text-primary hover:bg-accent-subtle`; tile 44px `Upload` 20px; "Criar modelo a partir de um PDF" 14px/600; "Defina campos e papéis uma vez, reutilize sempre" 12.5px.
Dados: Contrato de locação residencial v3 (Locação, 148 usos), Termo de vistoria de entrada, Proposta comercial, Autorização de venda com exclusividade, Contrato de administração de imóvel, Aditivo contratual — reajuste, Procuração simples, Acordo de confidencialidade (NDA). **Estado:** `cat`. **[não desenhado]** busca ativa, empty.

### 6.8 `App - Usuarios`

**Propósito:** membros da conta, funções/permissões, convite.

**Layout:** header "Imobiliária Horizonte › Usuários"; h1 "Usuários da conta" / "6 de 10 assentos do plano Profissional em uso · 1 convite pendente" + [👤+ Convidar usuário]; segmented "Membros (6) | Funções e permissões"; card da aba.
**Membros:** toolbar busca "Buscar por nome ou e-mail" + chips "Função", "Status"; grid Usuário | Função | Status | 2FA | Último acesso | ⋯; linhas: avatar 36 (paleta i%4), nome 600 + "(você)" `500 #8a96ad`, e-mail; chip de função 28px com chevron (Select inline); badge status; 2FA texto; último acesso ("Agora", "Hoje, 08:20", "Ontem, 18:02", "01 set, 16:40", "12 jul, 09:15", "Convite enviado 02 set"); kebab. Rodapé "6 usuários · 4 assentos disponíveis" + link "Adicionar assentos ao plano" (→ Cobrança).
**Funções e permissões:** header "Funções e permissões" / "Funções padrão do sistema. Crie funções personalizadas no plano Empresarial." + outline 32px "Nova função personalizada"; matriz (§4.25) colunas Permissão | Administrador | Gerente | Operador | Somente leitura; linhas (nome / descrição / [A,G,O,L]): "Criar e enviar documentos" / "Upload, modelos e solicitações" [1,1,1,0]; "Cancelar documentos" / "Inclusive de outros usuários" [1,1,0,0]; "Ver todos os documentos da conta" / "Sem restrição por pasta" [1,1,0,0]; "Gerenciar pastas e modelos" / "Criar, editar e excluir" [1,1,0,0]; "Convidar e remover usuários" / "E alterar funções" [1,0,0,0]; "Acessar API e webhooks" / "Gerar chaves e ver logs" [1,0,0,0]; "Ver plano e faturamento" / "Notas fiscais e uso" [1,0,0,0]; "Exportar trilha de auditoria" / "Relatórios em PDF/CSV" [1,1,1,1].
**Modal "Convidar usuário":** subtítulo "O convite expira em 7 dias. Você tem 4 assentos disponíveis."; campo "E-mails" 38px (ph "nome@horizonte.com.br, outro@horizonte.com.br", helper "Separe vários e-mails por vírgula."); "Função" radio-cards 2×2 (`p-2.5 rounded-lg border`; selecionado `border-primary bg-primary-soft`): Administrador / "Acesso total, inclusive cobrança e API"; Gerente / "Gerencia documentos, pastas e modelos"; Operador / "Cria e envia documentos nas pastas permitidas" (default); Somente leitura / "Visualiza e exporta, sem editar"; "Pastas com acesso" chips 28px: "✓ Todas as pastas" (selecionado), Locação, Vendas, Jurídico; footer "Cancelar" / "Enviar convite".
**Estados:** `tab members|roles`, `inviteOpen`, `role`. Overlay click fecha.

### 6.9 `App - API` (Documentação)

**Propósito:** referência REST in-app (aba "Documentação" da área API e integrações).

**Layout:** header "Imobiliária Horizonte › API e integrações" + pill "API operacional · v1.8"; h1 "Documentação da API" / "REST · JSON · HTTPS. Integre a assinatura ao seu sistema em poucas chamadas." + segmented "Documentação | Chaves e webhooks | Logs" (links); TOC sticky 200px (`top: 80px`) grupos "Começando" (Introdução, Autenticação, Erros e limites), "Documentos" (Criar documento, Adicionar signatários, Enviar para assinatura, Consultar e baixar), "Eventos" (Webhooks, SDKs); conteúdo `flex-1` seções `gap-7`, h2 18px/700, prosa `14px lh 1.65 #47536b`.

**Seções e copy:**
- `#intro` cards: "Base URL" `https://api.assinavelox.com.br/v1` / "Sandbox: https://sandbox.assinavelox.com.br/v1"; "Sua chave de produção" `av_live_••••••••••••4f2a` + botão copiar (`title="Copiar"`, `bg-muted hover:bg-primary-soft hover:text-primary`) + "Gerenciar chaves →"; "Limites" "600 req/min · arquivos até 25 MB" / "Cabeçalhos X-RateLimit-Remaining em toda resposta". Parágrafo: "A API da AssinaVelox segue REST. Todas as requisições e respostas usam JSON em UTF-8 e exigem HTTPS. Identificadores começam com um prefixo do recurso (`doc_`, `sig_`, `evt_`). Use o ambiente sandbox para testar sem consumir documentos do plano."
- `#auth`: "Envie a chave no cabeçalho Authorization como Bearer token. Chaves av_test_ operam no sandbox e av_live_ em produção. Nunca exponha a chave no front-end: faça as chamadas pelo seu back-end Laravel." Callout warning: "Chaves comprometidas podem ser revogadas a qualquer momento em Chaves e webhooks. A revogação é imediata." Código "Requisição autenticada · cURL".
- `#criar` POST /documents: "Cria um documento em rascunho a partir de um PDF (base64 ou URL pública) ou de um modelo. O documento só consome o plano quando enviado." Params (nome · tipo · obrigatório/opcional): name string obrigatório "Nome exibido aos signatários e na lista de documentos."; file_url "URL pública do PDF/DOCX. Alternativa: file_base64 ou template_id."; template_id "Cria a partir de um modelo com campos e signatários pré-configurados."; folder_id "Pasta de destino. Padrão: raiz da conta."; expires_in_days integer "Prazo para assinatura (1–90). Padrão: 30."; reminders object "{ enabled, every_days }. Lembretes automáticos aos pendentes."; message "Mensagem incluída nos convites de assinatura."; metadata "Chaves e valores livres (até 20) devolvidos nos webhooks." Código com toggle cURL / PHP / JavaScript; "Resposta" 201 Created (id `doc_9f2a7c1e`, status draft, pages 6, expires_at `2026-09-18T23:59:59-03:00`).
- `#signatarios` POST /documents/{id}/signers: "Adiciona um signatário e define canal de envio, métodos de autenticação e campos. Os campos usam coordenadas relativas (0–1) por página, ou âncoras de texto como {{assinatura_locataria}} no PDF." Params: name (obrig.) "Nome completo do signatário."; email "Obrigatório quando channel = email ou auth inclui token_email."; phone "E.164. Obrigatório para whatsapp, sms ou token_sms."; role "Papel exibido no documento: Locatária, Fiador, Testemunha…"; channel enum (obrig.) "email · whatsapp · sms"; auth array (obrig.) "token_email · token_sms · selfie · icp_brasil · drawn (um ou mais)."; order integer "Ordem sequencial. Omitido = assina em paralelo."; fields array (obrig.) "Campos: signature, initials, name, cpf, date, text, checkbox. Posição por x/y (0–1) e page, ou anchor."
- `#enviar` tabela: POST /documents/{id}/send "Envia os convites e bloqueia edições. Consome 1 documento do plano."; GET /documents/{id} "Status geral, signatários e eventos resumidos."; GET /documents "Lista paginada. Filtros: status, folder_id, created_after, q."; GET /documents/{id}/download?type= "PDF assinado (signed), original ou certificado de conclusão (certificate)."; POST …/signers/{signer_id}/remind "Reenvia o convite pelo canal configurado."; POST /documents/{id}/cancel "Cancela o documento e notifica os signatários pendentes."; DELETE /documents/{id} "Exclui rascunhos. Documentos enviados só podem ser cancelados."
- `#webhooks`: "Cadastre uma URL HTTPS em Chaves e webhooks. Cada evento é enviado como POST com o cabeçalho X-AssinaVelox-Signature (HMAC SHA-256 do corpo com o segredo do endpoint). Respondemos com novas tentativas por até 24 horas se seu servidor não retornar 2xx." Eventos: document.sent "Convites enviados a todos ou ao primeiro da ordem"; signer.viewed "Signatário abriu o link do documento"; signer.authenticated "Token, selfie ou certificado validados"; signer.signed "Signatário concluiu a assinatura"; signer.refused "Signatário recusou, com motivo em data.reason"; document.completed "Todos assinaram; PDF final e certificado disponíveis"; document.expired "Prazo encerrado com pendências"; document.cancelled "Cancelado pela conta ou via API". Payload exemplo `evt_01j7x9`.
- `#erros`: 400 bad_request "JSON inválido ou parâmetro com tipo incorreto."; 401 unauthorized "Chave ausente, inválida ou revogada."; 402 plan_limit_reached "Documentos do plano esgotados no ciclo atual."; 404 not_found "Recurso inexistente ou de outra conta."; 409 invalid_state "Ação incompatível com o status atual (ex.: editar documento enviado)."; 422 validation_error "Campos obrigatórios ausentes; detalhes em errors[]."; 429 rate_limited "Acima de 600 req/min. Aguarde Retry-After segundos."
- `#sdks`: "PHP / Laravel" `composer require assinavelox/laravel` "Facade, jobs de webhook e validação de assinatura"; "Node.js" `npm i @assinavelox/sdk` "Tipado em TypeScript"; "Python" `pip install assinavelox` "Cliente síncrono e assíncrono"; "Coleção Postman" "OpenAPI 3.1" "Todos os endpoints com exemplos".

**Estados:** `lang curl|php|js`; TOC ativo estático (implementar scroll-spy). Conteúdo estático → constantes TS.

### 6.10 `App - Integracoes` (Chaves e webhooks)

**Propósito:** gerir chaves de API, endpoints de webhook, entregas, segredo, IPs, sandbox.

**Layout:** header "API e integrações › Chaves e webhooks"; h1 "Chaves e webhooks" / "Credenciais de acesso à API e endpoints que recebem eventos da sua conta." + segmented (aba ativa); duas colunas: principal `2 1 520px` (3 cards gap-5) e lateral `1 1 280px` (3 cards gap-4).

**Card "Chaves de API":** helper "A chave completa é exibida uma única vez, na criação."; primário 34px "+ Nova chave"; banner condicional (`newKeyOpen`): "Chave criada — copie agora. Ela não será exibida novamente." + "Fechar" + código `av_live_7Kq2mN9pXw4Rt8Lz3VbY6Hc1Fd5Gj0Sa` + botão "Copiar" verde outline; grid Nome | Chave | Ambiente | Criada | Último uso | Status | ação: "Integração ERP" (Leitura e escrita) `av_live_••••4f2a` Produção "12 mar 2026 · Ana" "Hoje, 09:31" Ativa [Revogar]; "Site — formulários" (Somente criar documentos) `av_live_••••9c11` Produção "02 jun 2026 · Bruno" "Ontem, 22:10" Ativa; "Testes locais" `av_test_••••77b0` Sandbox "28 ago 2026 · Diego" "Há 3 dias" Ativa; "Antiga (rotacionada)" `av_live_••••0a3e` Produção "10 jan 2026 · Ana" "15 fev 2026" Revogada [Excluir]. Botão de ação: outline 28px, hover destrutivo.
**Card "Endpoints de webhook":** helper "Receba eventos em tempo real. Novas tentativas por até 24 h."; outline "+ Adicionar endpoint"; linhas: tile 36px `Webhook`, URL mono bold + chips de evento ("todos os eventos", "document.completed", "signer.refused", "sandbox · todos"), taxa "99,9% sucesso" + "último envio há 4 min" / "pausado em 30 ago", badge Ativo/Pausado, outline "Testar", kebab. URLs: `https://erp.horizonte.com.br/webhooks/assinavelox`, `https://hooks.zapier.com/hooks/catch/1183/av-docs`, `https://staging.horizonte.com.br/hooks/assinavelox`.
**Card "Entregas recentes"** (`id=logs`): helper "Últimas 24 horas · 1.284 eventos · 99,8% entregues"; outline "Ver todos os logs"; grid Evento | Endpoint | Resposta | Tentativa | Quando | [Reenviar]; eventos `signer.viewed`, `document.sent`, `document.completed`, `signer.refused`; ids `evt_01j7xa` etc.; códigos 200 / 503; tentativas "1ª"/"2ª"; "Hoje, 09:36", "Ontem, 11:03", "Há 3 dias".
**Lateral:** "Segredo dos webhooks" / "Usado para assinar o corpo (HMAC SHA-256) no cabeçalho X-AssinaVelox-Signature." + campo mono `whsec_••••••••••••••••b7e2` + botão olho `title="Revelar"` + outline "Rotacionar segredo"; "IPs de origem" / "Libere no firewall se seu endpoint restringe acesso." lista mono `52.67.10.0/24`, `18.231.44.0/24`, `177.71.200.16/28`; card navy eyebrow "AMBIENTE SANDBOX": "Documentos de teste não consomem o plano e os signatários recebem um aviso de ambiente de testes. Chaves av_test_ só funcionam em sandbox.assinavelox.com.br." + link `#2e7bef` "Ler a documentação →".
**Estados:** `newKeyOpen`. Revogar/Excluir/Rotacionar → AlertDialog.

### 6.11 `App - Configuracoes` e `App - Cobranca`

**Propósito:** configurações da conta em 4 abas verticais; `Cobranca` é a mesma tela com `tab=cobranca` (rota sugerida `/configuracoes?tab=cobranca` ou `/configuracoes/cobranca`).

**Layout:** header "Imobiliária Horizonte › Configurações"; h1 "Configurações" / "Conta, padrões de assinatura, notificações e plano."; nav 200px: "Geral e segurança", "Padrões de assinatura", "Notificações", "Plano e cobrança"; coluna `1 1 480px max-w-[820px] gap-4`.

**Aba Geral e segurança:**
- Card "Empresa" / "Aparece nos convites, no certificado de conclusão e nas notas fiscais.": inputs 38px "Razão social" (Horizonte Negócios Imobiliários Ltda.), "Nome de exibição" (Imobiliária Horizonte), "CNPJ" (12.345.678/0001-90), "E-mail de contato" (contato@horizonte.com.br); linha logo (`border rounded-[10px] p-3.5`): avatar 56px "IH", "Logo da empresa" / "PNG ou SVG, fundo transparente, mínimo 200×200. Usado nos e-mails e na página de assinatura.", outline 32px "Enviar logo"; primário 36px à direita "Salvar alterações".
- Card "Segurança" / "Políticas aplicadas a todos os usuários da conta.": 4 linhas com Switch (`py-3 border-t #f1f4f9`, título 13.5/600, desc 12.5 #8a96ad): "Exigir autenticação em duas etapas" / "Todos os usuários precisam configurar 2FA no próximo acesso" (ON); "Login único (SSO / SAML)" / "Disponível no plano Empresarial" (OFF, plan-gated); "Encerrar sessões após 12 h inativas" / "Recomendado para computadores compartilhados" (ON); "Restringir acesso por IP" / "Somente a partir dos endereços da empresa" (OFF).
- Danger zone: "Excluir conta" / "Remove todos os usuários e documentos após 30 dias. Documentos assinados continuam válidos para quem os baixou." + destrutivo 34px "Solicitar exclusão".

**Aba Padrões de assinatura:**
- Card "Padrões de novas solicitações" / "Podem ser alterados em cada envio.": selects "Prazo para assinatura" = 15 dias; "Lembretes automáticos" = A cada 2 dias; "Ordem de assinatura" = Sequencial; "Autenticação padrão dos signatários" chips multi (default Token e-mail + Token SMS); helper "Certificado ICP-Brasil e selfie aumentam a robustez jurídica; token é o mínimo recomendado."
- Card "Canais e recursos": switches "Envio por e-mail" / "Convites e lembretes por e-mail" (ON); "Envio por WhatsApp" / "Requer celular do signatário · R$ 0,25/mensagem além da franquia" (ON); "Envio por SMS" / "Alternativa ao WhatsApp" (ON); "Permitir assinatura desenhada" / "O signatário desenha a assinatura no celular ou computador" (ON); "Rubrica automática em todas as páginas" / "Adiciona campo de rubrica ao criar solicitações" (OFF).

**Aba Notificações:** card "Notificações" / "Escolha como quer ser avisado sobre cada evento. Notificações no app aparecem no sino."; matriz Evento | E-mail | WhatsApp | No app (checkbox 16px): "Signatário assinou" / "A cada assinatura concluída" [1,0,1]; "Documento concluído" / "Todos assinaram; PDF final disponível" [1,1,1]; "Signatário recusou" / "Inclui o motivo informado" [1,1,1]; "Documento expira em 48 h" / "Ainda com pendências" [1,0,1]; "Resumo diário de pendências" / "Um e-mail por dia útil" [1,0,0]; "Convite de usuário aceito" / "Novo membro entrou na conta" [1,0,1]; "Falha em webhook" / "Após a 3ª tentativa sem sucesso" [1,0,1]; "Novidades do produto" / "Lançamentos e melhorias, no máximo 1×/mês" [0,0,1]. Rodapé "Resumo diário de pendências enviado às 08:00 (America/Sao_Paulo)." + primário 34px "Salvar preferências".

**Aba Plano e cobrança** (= `App - Cobranca`):
- Grid `minmax(260px,1fr)`: card navy "PLANO ATUAL" + pill "Ativo"; "Profissional" (26px 800 italic uppercase) "R$ 49/mês"; "500 documentos/mês · 10 usuários · WhatsApp e SMS · API e webhooks · suporte prioritário"; botões "Alterar plano" (branco, → Planos) e "Cancelar renovação" (ghost). Card "Uso no ciclo" + "03 set → 15 out": progress "Documentos **148** / 500", "Usuários **6** / 10", "Mensagens WhatsApp/SMS **212** / 1.000"; nota "Excedentes: R$ 0,90 por documento · R$ 0,25 por mensagem."
- Grid: card "Forma de pagamento" + link "Alterar": linha com chip "VISA" 44×30 navy, "•••• •••• •••• 4242", "Validade 08/28 · próxima cobrança em 15 out"; nota "Também aceitamos boleto e Pix no plano anual." Card "Dados de faturamento" + "Editar": "Horizonte Negócios Imobiliários Ltda. / CNPJ 12.345.678/0001-90 / Av. Paulista, 1000 · cj. 1201 · São Paulo/SP · 01310-100 / financeiro@horizonte.com.br".
- Card "Faturas" + "NF-e emitida automaticamente": grid Data | Descrição | Valor | Status | [PDF] [NF-e]; linhas: 15 ago 2026 · Plano Profissional · ago/26 · R$ 49,00; 15 jul 2026 · … jul/26 + 12 documentos excedentes · R$ 59,80; 15 jun 2026 · R$ 49,00; 15 mai 2026 · R$ 49,00; 15 abr 2026 · … abr/26 + 2 assentos extras · R$ 69,00 — todas "Paga".

**Estados:** `tab`, `sec{tfa,sso,sessions,ipAllow}`, `ch{email,whatsapp,sms,drawn,initials}`, `auth[]`, `notif[8][3]`. Formatação pt-BR ("R$ 49,00", "1.000", "15 ago 2026").

### 6.12 `Assinar - Pagina publica` (signatário, sem login)

**Propósito:** fluxo público do signatário via link exclusivo: confirmar identidade (OTP SMS + selfie) → assinar (desenhar / digitar / certificado) → comprovante. Marcada "via AssinaVelox".

**Layout**
```
header 60px branco: [IH] Imobiliária Horizonte / "solicita sua assinatura"   ● Confirmar identidade · 2 Assinar · 3 Concluído   | via [logo 18px]
body bg #eef2f9, max-w 1200px, padding 20px 24px 40px, flex-wrap gap 20:
┌ documento (1.5 1 380px) ────────────────┐ ┌ aside sticky top 80px (1 1 320px, max 420) ┐
│ toolbar card: 📄 Contrato… · 6 páginas   │ │ card da fase (rounded 14, p-22)            │
│              [Baixar PDF] [Ampliar]     │ │ …                                          │
│ página 680px com campos                 │ │ "Criptografia TLS · Trilha de auditoria ·  │
│ "Locatária · você" / "Fiador"           │ │  Privacidade"                              │
└─────────────────────────────────────────┘ └────────────────────────────────────────────┘
```
**Fase 1 — auth:** eyebrow "Olá, Maria"; h1 20px "Confirme sua identidade para assinar"; "Ana Ribeiro (Imobiliária Horizonte) enviou este documento para você em 1 set. Prazo: **15 set 2026**."; painel OTP (§4.21); "Também será solicitado" (`12.5px/600 #47536b`) + item `border rounded-[10px] p-2.5 gap-3` tile 34px `UserRound`: "Selfie de verificação" / "Uma foto do seu rosto, comparada ao documento. Não é armazenada em redes sociais."; CTA 44px "Confirmar código e continuar"; legal `12px #8a96ad center` "Ao continuar você concorda com os **termos de assinatura eletrônica**. Seus dados são tratados conforme a LGPD."
**Fase 2 — sign:** badge "Identidade confirmada"; h1 "Sua assinatura"; "Escolha como quer assinar. Ela será aplicada em 1 campo e nas rubricas."; segmented "Desenhar | Digitar | Certificado" (`flex-1` cada); pad / input+preview / caixa certificado ("Certificado digital ICP-Brasil" — "Conecte seu token/cartão (A3) ou use o certificado em nuvem. O AssinaVelox Signer será aberto para concluir." + "Selecionar certificado"); checkbox marcado `12.5px #47536b` "Declaro que li o documento e concordo em assiná-lo eletronicamente, com a mesma validade de uma assinatura manuscrita (Lei 14.063/2020)."; CTA "Assinar documento"; link `12.5px/600 #8a96ad center` "Recusar assinatura" (→ modal com motivo **[não desenhado]**).
**Fase 3 — done:** ícone 52px `rounded-[14px] bg-success-bg text-success-solid`; h1 "Documento assinado"; "Sua assinatura foi registrada em 03/09/2026 às 09:52. Você receberá o PDF final por WhatsApp quando Carlos Mendes também assinar."; caixa "Comprovante" — "ID AV-00148 · hash 9f2a…c41e" / "Autenticação: token SMS + selfie · IP 187.12.44.9"; botões `flex-1 h-10 rounded-[10px]` outline "Baixar cópia" + primário "Concluir"; "Quer assinar seus próprios documentos? **Conheça a AssinaVelox**".
**Documento:** campo próprio muda de tracejado azul ("Clique para assinar aqui") para verde assinado (Caveat + "✓ Assinado em 03/09/2026 09:52"); campo do fiador "Carlos Mendes · assina depois de você"; rodapé "pág. 6/6". Links globais nesta página: `a { color: #1257c9 } a:hover { color: #0b1f42 }` (inverso do app).
**Estados:** `phase auth|sign|done` (deve vir do servidor: `pending_auth | ready_to_sign | signed`), `mode draw|type|cert`. "Concluir" reinicia só no mock. Rota `/assinar/{token}`. **[não desenhado]** selfie (câmera), OTP inválido/expirado, link expirado/recusado, loading.

### 6.13 `Admin - Clientes` (painel interno)

**Propósito:** lista de todas as contas da plataforma para a equipe AssinaVelox, com KPIs e impersonação ("Acessar como").

**Layout:** sidebar `mode=admin active=clientes`; header "Painel interno › Clientes" + pill âmbar "Acesso restrito · ações são auditadas"; h1 "Clientes" / "Todas as contas da plataforma. Acesse como cliente para dar suporte (registrado na trilha)." + [Exportar] [+ Nova conta]; KPIs ×5 (`minmax(150px,1fr)`): "Contas ativas" 1.284 / "+38 este mês"; "MRR" R$ 96,4 mil / "+6,1% vs. agosto"; "Documentos hoje" 4.812 / "pico às 10h · 612/h"; "Trials expirando (7 d)" 27 / "9 sem documento enviado"; "Inadimplentes" 14 / "R$ 2.140 em atraso". Card: tabs Todas 1.284 · Ativas 1.109 · Em trial 96 · Inadimplentes 14 · Canceladas 65; toolbar busca (max 340px) "Buscar por empresa, CNPJ, e-mail ou ID da conta" + chips "Plano", "Segmento", "Criada em"; grid Empresa | Plano | Usuários | Documentos no ciclo | MRR | Status | Último acesso | ações(120px); rodapé "Mostrando 8 de 1.284 contas" + páginas 1 2 3 … 129.

**Linha:** avatar 36 (paleta i%4), nome link 600 + "acc_1042 · Imobiliária", badge de plano, "6 / 10", "148 / 500" + barra 5px (âmbar ≥ 90%: RH Conecta 468/500), "R$ 49" 600, badge status, "Hoje, 09:41", outline 28px "Acessar como" (POST impersonate, auditado) + kebab 28px. Contas: Imobiliária Horizonte, Contábil Prisma, RH Conecta, Mendes & Vieira Advogados, Studio Lumen (freelancer), Transportadora Andrade, Escola Viva, Nova Barra Incorporações; segmentos: Imobiliária, Contabilidade, Recursos humanos, Jurídico, Freelancer, Logística, Educação, Construção civil.
**Estados:** `tab all|on|trial|due|off`. Query params: `status, q, plan, segment, created_at, page`.

### 6.14 `Sistema - Mapa de telas` (meta)

Índice de design (não é tela de produto): lista 16 telas em 4 grupos (Autenticação 3; Aplicação do cliente 11; Signatário público 1; Painel interno 1) com tags "tela", "tela + estado", "tela + filtros", "tela + abas", "wizard", "tela + dialog", "fluxo"; nota para o front com tokens (`--primary #1257c9, --foreground #0b1f42, --muted-foreground #47536b, --border #e6eaf2, --accent #f4f8fe, --sidebar #f7f9fc, --radius 0.5rem`) e componentes shadcn usados (Sidebar, Breadcrumb, Card, Table, Badge, Tabs, Button, Input, Checkbox, Switch, Dialog, DropdownMenu, Progress, Separator, Command). Útil como checklist de rotas Inertia: `Auth/Login`, `Auth/Register`, `Auth/ForgotPassword`, `Dashboard`, `Documents/Index`, `Documents/Show`, `Documents/Create`, `Signatures/Index`, `Templates/Index`, `Users/Index`, `Api/Docs`, `Api/Integrations`, `Settings/Index` (+ `?tab=`), `Sign/Public`, `Admin/Clients`. Opcional reproduzir como rota dev `/design`.

---

## 7. Assets

| Arquivo | O que é | Como usar |
|---|---|---|
| `assets/logo.png` | Logo horizontal oficial, **842×297 px, RGBA transparente, válido**. Ícone "A" em fita azul (navy `#002060`→ azul `#0080f0`) com check; wordmark "ASSINA" preto / "VELOX" gradiente azul (`#1E90FF`→`#0060C0`); tagline "ASSINATURA DIGITAL" fina, preta, tracking largo. | Copiar para `public/images/logo.png`. Usos nos mocks: sidebar `h-[30px]`, login `h-[34px]` com `filter: brightness(0) invert(1)` (vira branco sobre navy), página pública `h-[18px]` ("via"), mapa `h-[34px]`. `alt="AssinaVelox"`. Em 18–34px a tagline fica ilegível → pedir/gerar SVG e uma variante compacta (só o ícone "A") para favicon, sidebar colapsada e app icon. Fundo transparente + texto preto = só funciona sobre superfícies claras; para navy usar o filtro ou um PNG/SVG branco dedicado. |
| `uploads/b1aaaba0-…-Photoroom.png` | Mesma logo em canvas **1080×1080 RGBA transparente** (recorte Photoroom), logo ocupa faixa central (~linhas 424–693). **Arquivo truncado** (196.608 bytes, sem IEND) pelo limite de 256 KiB da exportação — decodifica mas não deve ir para produção. | Fonte para versão quadrada/ícone. Re-exportar do projeto de design ou re-salvar via Pillow (`LOAD_TRUNCATED_IMAGES`) e recortar as margens transparentes. |
| `uploads/pasted-1787950571672-0.png` | Mesma logo, **1080×1080 RGB com fundo branco opaco**. **Truncado** (só 695 de 1080 linhas decodificam; tagline cortada). Preview parcial em `uploads/pasted-1787950571672-0_partial_preview.png` (1080×695). | Não usar. Serve só de referência de cores (`#0376f7` azul claro, `#023d9b` navy do ícone). |

Cores da marca no logo × tokens da UI: o azul do logo (`~#0376f7`/`#0070f0`) é mais vivo que o `--primary #1257c9` da UI; os mocks usam deliberadamente o `#1257c9` (mais escuro, melhor contraste em texto) e reservam `#2e7bef` para acentos sobre navy. Manter essa separação: logo = imagem, UI = tokens.

Lucide icons usados: `PanelLeft, ChevronRight, ChevronDown, ChevronsUpDown, ChevronLeft, Search, HelpCircle, Bell, Calendar, Download, Upload, Plus, ArrowUpRight, ArrowRight, ArrowLeft, ArrowUpDown, FileText, MoreHorizontal, LayoutDashboard, PenLine, LayoutTemplate, Users, User, UserRound, UserPlus, Code, SlidersHorizontal, Building2, CreditCard, History, ShieldCheck, Shield, LogOut, Folder, RefreshCw, XCircle, X, Check, Eye, EyeOff, Trash2, Pencil, Webhook, Copy, Info, Mail, MessageCircle, Smartphone`.

---

## 8. Lacunas e ambiguidades (para decidir antes de codar)

1. **Label do signatário pendente**: "Pendente" (lista Assinaturas) × "Aguardando" (card no detalhe do documento) — mesma cor, texto diferente. Sugestão: "Pendente" para signatário, "Aguardando" para documento.
2. **Aguardando × Em andamento** (documento): regra de transição não explicitada (§5.1).
3. **Status "Cancelado" de documento** e faturas "Pendente/Falhou": sem badge desenhado; usar neutral / warning / danger.
4. **`--accent`**: mapa sugere `#f4f8fe`; mocks usam `#eef2f9` para hover de nav e `#f4f8fe` para hover de outline. Documento adota `#eef2f9` como `--accent` e `#f4f8fe` como `--accent-subtle`.
5. **Cluster direito do header** varia por tela (busca/ajuda/sino só no Dashboard). Padronizar.
6. **Nome do evento de webhook**: "document.signer_signed" (detalhe) × "signer.signed" (API). Usar a API.
7. **Sidebar admin**: 4 itens são placeholders `href="#"` sem tela desenhada (Planos e faturamento, Usuários da plataforma, Logs e auditoria, Configurações globais). Tab "Logs" da API também aponta só para a âncora `#logs`.
8. **Login com certificado digital**: botão sem fluxo. **Selfie** na página pública: só descrita, sem UI de câmera. **Recusar assinatura**: sem modal de motivo.
9. **Colapso da sidebar**: o mock só oculta/mostra; comportamento mobile/ícone é recomendação (§3.4). Dark mode inexistente.
10. **Dois tamanhos de KPI** (30px/`p-5` no Dashboard × 26px/`px-[18px] py-4` em Assinaturas/Admin) — ambos válidos; usar o compacto quando houver ≥5 cards.
11. **Uploads da logo estão truncados**; apenas `assets/logo.png` é utilizável. Falta variante branca/ícone/SVG.
12. **Mock "Concluir"** da página pública volta para `auth` — não replicar.
13. **Estados de loading, erro, vazio (exceto Documentos), disabled e toasts** não foram desenhados em nenhuma tela — seguir §4.15–4.16 e shadcn (`Skeleton`, `sonner`).
14. **Fonte Exo 2 via Google Fonts**: considerar self-host (`@fontsource/exo-2`) por LGPD/latência; Caveat só é necessária onde há assinatura renderizada.
