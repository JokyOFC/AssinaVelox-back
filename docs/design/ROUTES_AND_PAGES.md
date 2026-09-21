# AssinaVelox — Rotas, páginas Inertia e contratos de props

> Documento de arquitetura derivado dos mocks estáticos em `design-reference/*.dc.html` (índice: `Sistema - Mapa de telas.dc.html`).
> Stack: Laravel 13 (monolito) · Inertia 3 · React 19 + TypeScript · shadcn/ui · Tailwind 4 · Fortify · MySQL · Redis/Horizon · pyHanko (PAdES A1) · Mercado Pago Checkout Pro.
> Prosa em PT-BR, identificadores em inglês. Este documento é a fonte de verdade para quem implementa sem ver os mocks.

---

## 0. Convenções gerais

### 0.1 Nomenclatura de domínio (mock → backend)

| Termo no mock | Conceito no backend | Observação |
|---|---|---|
| "Documento" / "Solicitação" (AV-00148) | `Envelope` (um `Document` por envelope na Fase 1) | O usuário vê "documento"; o código usa `envelope`. O código visível `AV-00148` é `envelopes.public_code` (sequencial por plataforma, formato `AV-{00000}`), distinto de `envelopes.id` (ULID) e do `verification_code` (opaco, para a página pública de verificação). |
| "Signatário" | `Recipient` | Sempre pertence a um envelope. Uma pessoa que assina 2 envelopes = 2 recipients. |
| "Assinaturas" (tela) | Listagem de `Recipient` cross-envelope | Uma linha por recipient. |
| "Pasta" | `Folder` (por organização) | Sem hierarquia na Fase 1. |
| "Conta" / "Workspace" / "Cliente" (admin) | `Organization` | Multi-tenant por `organization_id`. |
| "Usuário da conta" / "Membro" | `Membership` (user ↔ organization + role) | Roles Fase 1: `owner`, `admin`, `member`. |
| "Assento" | Limite `plans.max_members` | Contado sobre memberships ativas + convites pendentes. |
| "Modelo" | `Template` | Fase 2. |
| "Trilha de auditoria" / "Evidências" | `EnvelopeEvent` (append-only) + página `Evidence` | |
| "Comprovante" | Recibo do signatário (`Sign/Show` fase `completed`) | |

### 0.2 Grupos de middleware (nomes usados na tabela)

| Alias na tabela | Middleware Laravel reais | Uso |
|---|---|---|
| `guest` | `web`, `guest` | Login, cadastro, recuperação de senha. |
| `auth` | `web`, `auth:sanctum` (Fortify), `verified` | Sessão autenticada com e-mail verificado. |
| `org` | `EnsureCurrentOrganization` (resolve `session('current_organization_id')`, cai para a primeira membership; 403 se o usuário não é membro ativo), `SubstituteOrganizationBindings` (escopa todo route-model-binding de `Envelope`, `Folder`, `Recipient`, `Membership`, `Invitation` pela org atual → 404 fora da org) | Todas as rotas do app. |
| `org.admin` | `EnsureMembershipRole:owner,admin` | Usuários, Configurações (exceto leitura do próprio perfil), Plano e cobrança. |
| `org.owner` | `EnsureMembershipRole:owner` | Excluir conta, transferir propriedade, cancelar assinatura do plano. |
| `signer` | `web` (sessão própria, cookie separado `assinavelox_signer_session`), `ResolveSignerToken` (hash SHA-256 do token na URL → `recipients.access_token_hash`; expira com o envelope), `throttle:signer` (30/min por IP+token) | Página pública de assinatura. |
| `signer.verified` | `signer` + `EnsureSignerVerified` (sessão contém `signer.verified.{recipient_id}` emitido após OTP, TTL 60 min) | Etapas que exibem o PDF e recebem a assinatura. |
| `public` | `web`, `throttle:public` (60/min por IP) | Verificação pública, páginas legais. |
| `platform-admin` | `auth` + `EnsurePlatformAdmin` (`users.is_platform_admin = true`) | Painel interno. **Não** passa por `org`. |
| `webhook` | `api` (sem CSRF), `VerifyMercadoPagoSignature` | Webhook do Mercado Pago. |

### 0.3 Props compartilhadas (Inertia `HandleInertiaRequests::share`)

Todas as páginas autenticadas recebem:

```ts
export interface SharedProps {
  auth: {
    user: AuthUser | null;
  };
  /** Organização ativa (null em rotas platform-admin e guest). */
  organization: CurrentOrganization | null;
  /** Organizações em que o usuário é membro ativo — alimenta o switcher. */
  organizations: OrganizationSummary[];
  /** Contadores para badges da sidebar/topbar. Cacheados 60s por org (Redis). */
  counts: {
    pending_envelopes: number;      // envelopes in_progress da org (badge "Documentos")
    unread_notifications: number;   // notificações não lidas do usuário na org
  };
  flash: {
    success?: string;
    error?: string;
    warning?: string;
    info?: string;
  };
  /** Feature flags por fase — placeholders leem estas flags. */
  features: {
    templates: false;          // Fase 2
    api_integrations: false;   // Fase 2
    reminders: false;          // Fase 2
    sms_whatsapp: false;       // Fase 2
    branding: false;           // Fase 2
    multi_document: false;     // Fase 2
  };
  /** Erros de validação (Inertia padrão). */
  errors: Record<string, string>;
}

export interface AuthUser {
  id: string;                          // ULID
  name: string;
  email: string;
  initials: string;                    // "AR" — calculado no backend
  avatar_url: string | null;           // null na Fase 1 (sem upload de avatar)
  email_verified_at: string | null;    // ISO-8601
  two_factor_enabled: boolean;         // two_factor_confirmed_at !== null
  is_platform_admin: boolean;          // exibe "Painel interno" no menu
  locale: 'pt_BR';
  timezone: string;                    // ex.: "America/Sao_Paulo"
}

export interface CurrentOrganization {
  id: string;
  name: string;                        // "Imobiliária Horizonte" (nome de exibição)
  legal_name: string | null;           // razão social
  initials: string;                    // "IH"
  logo_url: string | null;             // sempre null na Fase 1 (branding = Fase 2)
  role: MembershipRole;                // papel do usuário atual nesta org
  plan: {
    key: PlanKey;
    name: string;                      // "Profissional"
    status: SubscriptionStatus;
  };
  permissions: OrgPermissions;         // derivadas do role — evita lógica de role no front
}

export type MembershipRole = 'owner' | 'admin' | 'member';

export interface OrgPermissions {
  manage_members: boolean;       // owner, admin
  manage_settings: boolean;      // owner, admin
  manage_billing: boolean;       // owner, admin
  delete_organization: boolean;  // owner
  cancel_any_envelope: boolean;  // owner, admin (member cancela só os próprios)
  view_all_envelopes: boolean;   // owner, admin (member vê só os próprios — ver Q7)
  manage_folders: boolean;       // owner, admin
}

export interface OrganizationSummary {
  id: string;
  name: string;
  initials: string;
  plan_name: string;
  role: MembershipRole;
  is_current: boolean;
}
```

### 0.4 Formato de paginação

Todas as listagens paginadas usam `->paginate()` + `JsonResource::collection`, resultando em:

```ts
export interface Paginated<T> {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: {
    current_page: number;
    from: number | null;
    to: number | null;
    last_page: number;
    per_page: number;          // 10 | 25 | 50 (query `per_page`)
    total: number;
    path: string;
    links: { url: string | null; label: string; active: boolean }[]; // para o Pagination do shadcn
  };
}
```

Rodapé do mock "Mostrando 10 de 312 documentos" = `meta.to - meta.from + 1` de `meta.total`. Filtros são sempre query-string (`?status=&folder=&q=&page=&per_page=&sort=`) e preservados com `router.get(route, params, { preserveState: true, replace: true })`.

### 0.5 Enums centrais (backend `App\Enums\*`, espelhados em `resources/js/types/enums.ts`)

```ts
export type EnvelopeStatus =
  | 'draft'        // criado, sem documento pronto e/ou sem recipients/campos completos
  | 'preparing'    // documento em conversão/rasterização (job assíncrono)
  | 'ready'        // documento pronto; wizard pode avançar/enviar
  | 'in_progress'  // enviado; aguardando recipients
  | 'finalizing'   // todos assinaram; job de PAdES + evidência rodando
  | 'completed'    // PDF final assinado e evidência disponíveis
  | 'refused'      // algum recipient recusou
  | 'expired'      // prazo encerrado com pendências
  | 'canceled';    // cancelado pelo remetente

export type RecipientStatus =
  | 'waiting'      // (sequencial) ainda não é a vez — sem token emitido
  | 'sent'         // convite enviado, link não aberto
  | 'viewed'       // abriu o link (antes ou depois do OTP)
  | 'signed'
  | 'refused'
  | 'expired'      // envelope expirou/cancelado enquanto pendente
  | 'canceled';

export type RecipientRoutingMode = 'sequential' | 'parallel';   // envelopes.routing_mode

export type DocumentProcessingStatus = 'uploaded' | 'processing' | 'ready' | 'failed';

export type FieldType = 'signature' | 'initials' | 'name' | 'date' | 'text' | 'checkbox';
// Fase 2: 'cpf' | 'stamp'

export type SignatureMethod = 'draw' | 'type' | 'upload';

export type AuthMethod = 'email_otp';   // Fase 2: 'sms_otp' | 'whatsapp_otp' | 'selfie' | 'icp_brasil'
export type DeliveryChannel = 'email';  // Fase 2: 'whatsapp' | 'sms'

export type EnvelopeEventType =
  | 'envelope.created' | 'document.uploaded' | 'document.processed' | 'document.processing_failed'
  | 'envelope.sent' | 'recipient.notified' | 'recipient.viewed' | 'recipient.otp_sent'
  | 'recipient.otp_verified' | 'recipient.otp_failed' | 'recipient.signed' | 'recipient.refused'
  | 'recipient.resent' | 'recipient.updated' | 'envelope.finalizing' | 'envelope.completed'
  | 'envelope.expired' | 'envelope.canceled' | 'envelope.moved' | 'envelope.downloaded';

export type MembershipStatus = 'active' | 'suspended';
export type InvitationStatus = 'pending' | 'accepted' | 'expired' | 'revoked';

export type PlanKey = 'free' | 'professional' | 'enterprise';
export type SubscriptionStatus = 'trialing' | 'active' | 'past_due' | 'canceled';
export type PaymentStatus = 'pending' | 'approved' | 'rejected' | 'refunded' | 'cancelled';
// PaymentStatus espelha `status` do Mercado Pago (pending, approved, authorized→approved, in_process→pending,
// in_mediation→pending, rejected, cancelled, refunded, charged_back→refunded).
```

---

## 1. Tabela: tela do design → rota → página Inertia → controller → fase

Legenda de fase: **1** = MVP; **2** = extensão futura (placeholder na Fase 1); **1★** = Fase 1 com escopo reduzido descrito na coluna Observações.

### 1.1 Autenticação (mocks `App - Login`, `App - Criar conta`, `App - Recuperar senha`) — layout `AuthLayout`

| Tela | Método | URI | Nome da rota | Middleware | Página Inertia | Controller | Fase | Observações |
|---|---|---|---|---|---|---|---|---|
| Login | GET | `/login` | `login` | guest | `pages/auth/Login.tsx` | Fortify `AuthenticatedSessionController@create` (view via `Fortify::loginView`) | 1 | Botão "Entrar com certificado digital" → **Fase 2** (oculto; extension point `auth.certificate` em `features`). |
| Login (submit) | POST | `/login` | `login.store` (Fortify) | guest, throttle:login | — | Fortify | 1 | Payload `email`, `password`, `remember`. |
| Desafio 2FA | GET/POST | `/two-factor-challenge` | `two-factor.login` | guest | `pages/auth/TwoFactorChallenge.tsx` | Fortify | 1 | Não há mock: usar `AuthLayout` com o mesmo card (input de 6 dígitos ou recovery code). |
| Criar conta | GET | `/register` | `register` | guest | `pages/auth/Register.tsx` | Fortify `RegisteredUserController@create` | 1 | Cria `User` + `Organization` + `Membership(owner)` + `Subscription(free)` em transação (`CreateNewUser`). |
| Criar conta (submit) | POST | `/register` | `register.store` | guest | — | Fortify (`CreateNewUser` action) | 1 | |
| Verificar e-mail | GET | `/email/verify` | `verification.notice` | auth (sem verified) | `pages/auth/VerifyEmail.tsx` | Fortify | 1 | Sem mock; card verde "Verifique seu e-mail" reaproveitado do mock de recuperação. |
| Verificar e-mail (link) | GET | `/email/verify/{id}/{hash}` | `verification.verify` | auth, signed | — | Fortify | 1 | Redireciona para `dashboard`. |
| Reenviar verificação | POST | `/email/verification-notification` | `verification.send` | auth, throttle:6,1 | — | Fortify | 1 | |
| Recuperar senha | GET | `/forgot-password` | `password.request` | guest | `pages/auth/ForgotPassword.tsx` | Fortify `PasswordResetLinkController@create` | 1 | Estado "sent" = flash `status` do Fortify. |
| Recuperar senha (submit) | POST | `/forgot-password` | `password.email` | guest, throttle:6,1 | — | Fortify | 1 | `config('auth.passwords.users.expire') = 30` (o mock diz "válido por 30 minutos"). |
| Redefinir senha | GET | `/reset-password/{token}` | `password.reset` | guest | `pages/auth/ResetPassword.tsx` | Fortify | 1 | Sem mock; mesmo card com `password` + `password_confirmation`. |
| Redefinir senha (submit) | POST | `/reset-password` | `password.update` | guest | — | Fortify | 1 | |
| Sair | POST | `/logout` | `logout` | auth | — | Fortify | 1 | Item "Sair" do menu da conta (POST via `router.post`). |

### 1.2 Aplicação do cliente — layout `AppLayout` (sidebar `client`)

| Tela | Método | URI | Nome da rota | Middleware | Página Inertia | Controller | Fase | Observações |
|---|---|---|---|---|---|---|---|---|
| Dashboard | GET | `/dashboard` | `dashboard` | auth, org | `pages/dashboard/Index.tsx` | `DashboardController@index` | 1★ | Sem "Tempo médio para assinar" com delta mensal? Mantido. "Lembretes automáticos ativos" (rodapé do KPI) → texto fixo removido (Fase 2). Botão "Exportar" → `dashboard.export` (CSV). |
| Dashboard — exportar | GET | `/dashboard/exportar` | `dashboard.export` | auth, org | — (download CSV) | `DashboardController@export` | 1 | CSV dos envelopes do período. |
| Busca global ⌘K | GET | `/busca?q=` | `search.index` | auth, org | — (JSON) | `SearchController@index` | 1 | Retorna até 10 envelopes + 5 recipients; usado pelo `Command` do shadcn. |
| Notificações (sino) | GET | `/notificacoes` | `notifications.index` | auth, org | — (JSON, `Paginated<Notification>`) | `NotificationController@index` | 1★ | Popover do sino; notificações do `database` channel. |
| Notificações — marcar lidas | POST | `/notificacoes/ler` | `notifications.read` | auth, org | — | `NotificationController@markRead` | 1 | Payload `ids: string[]` ou vazio = todas. |
| Documentos (lista) | GET | `/documentos` | `envelopes.index` | auth, org | `pages/envelopes/Index.tsx` | `EnvelopeController@index` | 1 | Query: `status`, `folder`, `q`, `period_from`, `period_to`, `recipient`, `creator`, `sort`, `page`, `per_page`. Botão "Importar" → **removido na Fase 1** (mesmo fluxo da nova solicitação). |
| Nova solicitação (wizard) | GET | `/documentos/nova` | `envelopes.create` | auth, org | `pages/envelopes/Wizard.tsx` | `EnvelopeController@create` | 1 | Cria imediatamente um `Envelope(draft)` vazio e redireciona para `envelopes.edit` (garante autosave e ID). |
| Nova solicitação (draft) | GET | `/documentos/{envelope}/editar?step=1..4` | `envelopes.edit` | auth, org, `can:update,envelope` | `pages/envelopes/Wizard.tsx` | `EnvelopeController@edit` | 1 | Só para `draft/preparing/ready`; outros status redirecionam para `envelopes.show`. Passo 1 "Ou comece por um modelo" → **placeholder Fase 2** (card com estado vazio). |
| Wizard — metadados | PATCH | `/documentos/{envelope}` | `envelopes.update` | auth, org, can:update | — | `EnvelopeController@update` | 1 | `title`, `folder_id`, `expires_in_days`, `message`, `routing_mode`, `send_copy_to_all`. |
| Wizard — upload documento | POST | `/documentos/{envelope}/documento` | `envelopes.document.store` | auth, org, can:update | — | `EnvelopeDocumentController@store` | 1 | Multipart; dispara `ProcessDocument` job; envelope → `preparing`. |
| Wizard — remover documento | DELETE | `/documentos/{envelope}/documento` | `envelopes.document.destroy` | auth, org, can:update | — | `EnvelopeDocumentController@destroy` | 1 | Envelope volta a `draft`. Apaga campos. |
| Wizard — status do processamento | GET | `/documentos/{envelope}/documento/status` | `envelopes.document.status` | auth, org | — (JSON `DocumentProcessing`) | `EnvelopeDocumentController@status` | 1 | Polling a cada 2s enquanto `processing` (alternativa: Inertia partial reload de `document`). |
| Wizard — página renderizada | GET | `/documentos/{envelope}/documento/paginas/{page}.png` | `envelopes.document.page` | auth, org | — (imagem) | `EnvelopeDocumentController@page` | 1 | Miniaturas do rail. O canvas principal usa o PDF via PDF.js (`envelopes.download` type `original`). |
| Wizard — signatários | PUT | `/documentos/{envelope}/destinatarios` | `envelopes.recipients.sync` | auth, org, can:update | — | `EnvelopeRecipientController@sync` | 1 | Substitui a lista completa (com `id` para preservar). |
| Wizard — campos | PUT | `/documentos/{envelope}/campos` | `envelopes.fields.sync` | auth, org, can:update | — | `EnvelopeFieldController@sync` | 1 | Substitui a lista completa. |
| Wizard — enviar | POST | `/documentos/{envelope}/enviar` | `envelopes.send` | auth, org, can:send | — | `EnvelopeSendController@store` | 1 | Valida completude, verifica cota do plano, → `in_progress`, notifica recipients. Redireciona para `envelopes.show` com flash `sent=true` (tela de sucesso do wizard). |
| Wizard — salvar rascunho / sair | — | — | — | — | — | — | 1 | Apenas navegação para `envelopes.index`; o rascunho já está persistido (autosave por `PATCH`/`PUT` com debounce 800ms; header mostra `updated_at`). |
| Detalhe do documento | GET | `/documentos/{envelope}` | `envelopes.show` | auth, org, can:view | `pages/envelopes/Show.tsx` | `EnvelopeController@show` | 1★ | Abas Signatários / Trilha / Detalhes. Linha "Webhook" nos Detalhes → **Fase 2** (oculta). Botão "Adicionar signatário ou testemunha" após envio → **Fase 2** (oculto quando `status != draft`). |
| Detalhe — baixar | GET | `/documentos/{envelope}/download/{type}` | `envelopes.download` | auth, org, can:view | — (stream) | `EnvelopeDownloadController@show` | 1 | `type ∈ original \| signed \| evidence`. `signed`/`evidence` só quando `completed`. Registra evento `envelope.downloaded`. |
| Detalhe — página de evidências | GET | `/documentos/{envelope}/evidencias` | `envelopes.evidence` | auth, org, can:view | `pages/envelopes/Evidence.tsx` | `EnvelopeEvidenceController@show` | 1 | Link "Ver evidências" e "Relatório PDF" (este último = `envelopes.download` type `evidence`). |
| Detalhe — reenviar convite (1 recipient) | POST | `/documentos/{envelope}/destinatarios/{recipient}/reenviar` | `envelopes.recipients.resend` | auth, org, can:update | — | `EnvelopeRecipientController@resend` | 1 | Botões "Reenviar"/"Lembrar" = reenvio manual do convite por e-mail (não é lembrete automático). Throttle: 1 por recipient a cada 10 min. |
| Detalhe — lembrar todos pendentes | POST | `/documentos/{envelope}/reenviar` | `envelopes.resend` | auth, org, can:update | — | `EnvelopeRecipientController@resendAll` | 1 | Botão "Lembrar pendentes". |
| Detalhe — editar recipient pendente | PATCH | `/documentos/{envelope}/destinatarios/{recipient}` | `envelopes.recipients.update` | auth, org, can:update | — | `EnvelopeRecipientController@update` | 1 | Só `name`/`email` de recipients `waiting/sent/viewed`. Rotaciona o token e reenvia. |
| Detalhe — cancelar | POST | `/documentos/{envelope}/cancelar` | `envelopes.cancel` | auth, org, can:cancel | — | `EnvelopeController@cancel` | 1 | Payload `reason?`. Notifica pendentes. |
| Detalhe — excluir rascunho | DELETE | `/documentos/{envelope}` | `envelopes.destroy` | auth, org, can:delete | — | `EnvelopeController@destroy` | 1 | Só `draft/preparing/ready`. |
| Detalhe — duplicar | POST | `/documentos/{envelope}/duplicar` | `envelopes.duplicate` | auth, org, can:view | — | `EnvelopeController@duplicate` | 1 | Copia documento original + recipients + campos em novo `draft`; redireciona para `envelopes.edit`. |
| Detalhe — mover para pasta | PATCH | `/documentos/{envelope}/pasta` | `envelopes.move` | auth, org, can:update | — | `EnvelopeController@move` | 1 | `folder_id` nullable. Permitido em qualquer status. |
| Documentos — ações em lote | POST | `/documentos/lote/{action}` | `envelopes.bulk` | auth, org | — | `EnvelopeBulkController@store` | 1★ | `action ∈ move \| resend \| cancel`. `download` em lote (ZIP) → **Fase 2** (botão "Baixar" oculto quando >1 selecionado... ver Q12). Payload `ids: string[]`, `folder_id?`, `reason?`. |
| Pastas — criar | POST | `/pastas` | `folders.store` | auth, org, org.admin | — | `FolderController@store` | 1 | Ícone "+" da rail "Pastas". |
| Pastas — renomear | PATCH | `/pastas/{folder}` | `folders.update` | auth, org, org.admin | — | `FolderController@update` | 1 | |
| Pastas — excluir | DELETE | `/pastas/{folder}` | `folders.destroy` | auth, org, org.admin | — | `FolderController@destroy` | 1 | Envelopes voltam para `folder_id = null` ("Todos"). "Arquivados" do mock = pasta comum criada pelo usuário, não é status. |
| Assinaturas | GET | `/assinaturas` | `recipients.index` | auth, org | `pages/recipients/Index.tsx` | `RecipientController@index` | 1★ | Filtros "Canal"/"Autenticação" → ocultos na Fase 1 (valores únicos `email`/`email_otp`). KPI "Canal mais usado" → substituído por "Tempo médio para assinar". |
| Assinaturas — exportar CSV | GET | `/assinaturas/exportar` | `recipients.export` | auth, org | — (CSV) | `RecipientController@export` | 1 | Mesmos filtros da listagem. |
| Assinaturas — lembrar todos pendentes | POST | `/assinaturas/reenviar-pendentes` | `recipients.resend_pending` | auth, org, org.admin | — | `RecipientController@resendPending` | 1 | Job em fila; throttle global 1×/hora por org. |
| Modelos | GET | `/modelos` | `templates.index` | auth, org | `pages/templates/Index.tsx` | `TemplateController@index` | **2** | **Placeholder Fase 1**: mesma shell (título "Modelos de documentos", subtítulo do mock, botão "Novo modelo" desabilitado com tooltip), corpo = `EmptyState` "Modelos estarão disponíveis na Fase 2. Enquanto isso, duplique um documento existente." com link para `envelopes.index`. Extension point: `features.templates`; tabela `templates` + `template_fields` + `template_roles`; botão "Usar modelo" → `envelopes.create?template={id}`. |
| Usuários | GET | `/usuarios` | `members.index` | auth, org, org.admin | `pages/members/Index.tsx` | `MembershipController@index` | 1★ | Abas Membros / Funções e permissões. Matriz de permissões = estática, derivada de `OrgPermissions` para 3 roles (owner/admin/member). "Nova função personalizada" → **Fase 2** (oculto). "Pastas com acesso" no convite → **Fase 2** (oculto). |
| Usuários — alterar função | PATCH | `/usuarios/{membership}` | `members.update` | auth, org, org.admin | — | `MembershipController@update` | 1 | `role ∈ admin \| member` (owner só via transferência). |
| Usuários — suspender/reativar | PATCH | `/usuarios/{membership}/status` | `members.status` | auth, org, org.admin | — | `MembershipController@updateStatus` | 1 | `status ∈ active \| suspended` ("Inativo"). |
| Usuários — remover | DELETE | `/usuarios/{membership}` | `members.destroy` | auth, org, org.admin | — | `MembershipController@destroy` | 1 | Envelopes criados permanecem (creator_id preservado). |
| Usuários — transferir propriedade | POST | `/usuarios/{membership}/transferir-propriedade` | `members.transfer_ownership` | auth, org, org.owner | — | `MembershipController@transferOwnership` | 1 | Item do kebab, só para owner. |
| Convites — enviar | POST | `/usuarios/convites` | `invitations.store` | auth, org, org.admin | — | `InvitationController@store` | 1 | Modal "Convidar usuário". |
| Convites — reenviar | POST | `/usuarios/convites/{invitation}/reenviar` | `invitations.resend` | auth, org, org.admin | — | `InvitationController@resend` | 1 | |
| Convites — revogar | DELETE | `/usuarios/convites/{invitation}` | `invitations.destroy` | auth, org, org.admin | — | `InvitationController@destroy` | 1 | |
| Convite — aceitar (página) | GET | `/convites/{token}` | `invitations.accept` | web (guest ou auth) | `pages/invitations/Accept.tsx` | `InvitationAcceptController@show` | 1 | Se não logado, mostra cadastro/login com e-mail travado; se logado com o mesmo e-mail, botão "Entrar na conta". |
| Convite — aceitar (submit) | POST | `/convites/{token}` | `invitations.accept.store` | web | — | `InvitationAcceptController@store` | 1 | Cria membership; define `current_organization_id`. |
| API e integrações — documentação | GET | `/api-integracoes` | `integrations.index` | auth, org | `pages/integrations/Index.tsx` | `IntegrationController@index` | **2** | **Placeholder Fase 1**: shell com título "API e integrações", segmented control com "Documentação / Chaves e webhooks / Logs" desabilitados, `EmptyState` "A API pública, chaves e webhooks estarão disponíveis na Fase 2" + botão "Avisar-me" (mailto/CTA inerte). Extension point: `features.api_integrations`; tabelas `api_tokens` (Sanctum), `webhook_endpoints`, `webhook_deliveries`; prefixo `/api/v1`. |
| API — chaves e webhooks | GET | `/api-integracoes/chaves` | `integrations.keys` | auth, org, org.admin | `pages/integrations/Keys.tsx` | `IntegrationKeyController@index` | **2** | Na Fase 1 redireciona (302) para `integrations.index`. |
| API — logs | GET | `/api-integracoes/logs` | `integrations.logs` | auth, org, org.admin | `pages/integrations/Logs.tsx` | `WebhookDeliveryController@index` | **2** | Idem. |
| Configurações — Geral e segurança | GET | `/configuracoes` | `settings.general` | auth, org, org.admin | `pages/settings/General.tsx` | `Settings\GeneralController@edit` | 1★ | Card "Empresa" (sem upload de logo — **Fase 2 branding**; bloco de logo vira placeholder desabilitado). Card "Segurança": só "Exigir 2FA" e "Encerrar sessões após 12h" na Fase 1; SSO/IP → **Fase 2** (switch desabilitado + badge "Fase 2"). Danger zone Fase 1. |
| Configurações — salvar empresa | PATCH | `/configuracoes/empresa` | `settings.organization.update` | auth, org, org.admin | — | `Settings\GeneralController@updateOrganization` | 1 | |
| Configurações — salvar segurança | PATCH | `/configuracoes/seguranca` | `settings.security.update` | auth, org, org.admin | — | `Settings\GeneralController@updateSecurity` | 1 | |
| Configurações — solicitar exclusão | POST | `/configuracoes/excluir-conta` | `settings.organization.destroy` | auth, org, org.owner, password.confirm | — | `Settings\GeneralController@requestDeletion` | 1 | Marca `organizations.deletion_requested_at`; job apaga após 30 dias; cancela assinatura. |
| Configurações — Padrões de assinatura | GET | `/configuracoes/assinatura` | `settings.signing` | auth, org, org.admin | `pages/settings/Signing.tsx` | `Settings\SigningController@edit` | 1★ | Fase 1: prazo padrão, ordem padrão, "Rubrica automática em todas as páginas". Lembretes automáticos, chips de autenticação (só `email_otp`), canais WhatsApp/SMS → **Fase 2** (controles desabilitados com badge). |
| Configurações — salvar padrões | PATCH | `/configuracoes/assinatura` | `settings.signing.update` | auth, org, org.admin | — | `Settings\SigningController@update` | 1 | |
| Configurações — Notificações | GET | `/configuracoes/notificacoes` | `settings.notifications` | auth, org | `pages/settings/Notifications.tsx` | `Settings\NotificationController@edit` | 1★ | Preferências **por usuário** na org. Colunas Fase 1: E-mail e No app (WhatsApp → Fase 2, coluna oculta). Eventos Fase 1: `recipient_signed`, `envelope_completed`, `recipient_refused`, `envelope_expiring`, `daily_digest`, `invitation_accepted`, `product_news`. `webhook_failed` → Fase 2. |
| Configurações — salvar notificações | PATCH | `/configuracoes/notificacoes` | `settings.notifications.update` | auth, org | — | `Settings\NotificationController@update` | 1 | |
| Plano e cobrança | GET | `/configuracoes/plano` | `billing.index` | auth, org, org.admin | `pages/settings/Billing.tsx` | `BillingController@index` | 1★ | Mercado Pago Checkout Pro: **não** há cartão salvo nem "Forma de pagamento — Alterar" (card mostra o método do último pagamento aprovado, somente leitura). NF-e → **Fase 2** (botão oculto). "Cancelar renovação" Fase 1. |
| Planos (escolha) | GET | `/planos` | `plans.index` | auth, org, org.admin | `pages/settings/Plans.tsx` | `PlanController@index` | 1 | Mock `Planos.dc.html` não fornecido; derivar da tabela de planos (Grátis / Profissional / Empresarial) com toggle mensal/anual. |
| Checkout | POST | `/configuracoes/plano/checkout` | `billing.checkout` | auth, org, org.admin | — (redirect externo) | `BillingCheckoutController@store` | 1 | Cria `Payment(pending)` + preferência Checkout Pro; retorna `Inertia::location(init_point)`. |
| Retorno do checkout | GET | `/configuracoes/plano/retorno/{outcome}` | `billing.return` | auth, org | — (redirect → `billing.index` com flash) | `BillingCheckoutController@return` | 1 | `outcome ∈ success \| failure \| pending` (back_urls). Estado final vem sempre do webhook. |
| Cancelar renovação | POST | `/configuracoes/plano/cancelar` | `billing.cancel` | auth, org, org.owner, password.confirm | — | `BillingController@cancel` | 1 | `subscription.cancel_at_period_end = true`. |
| Dados de faturamento | PATCH | `/configuracoes/plano/faturamento` | `billing.profile.update` | auth, org, org.admin | — | `BillingController@updateProfile` | 1 | |
| Fatura — recibo PDF | GET | `/configuracoes/plano/pagamentos/{payment}/recibo` | `billing.payments.receipt` | auth, org, org.admin | — (PDF) | `PaymentReceiptController@show` | 1 | Botão "PDF" (recibo interno, não NF-e). |
| Webhook Mercado Pago | POST | `/webhooks/mercadopago` | `webhooks.mercadopago` | webhook | — | `Webhooks\MercadoPagoController@handle` | 1 | Idempotente por `data.id`; despacha `SyncMercadoPagoPayment` job. |
| Perfil e preferências | GET | `/perfil` | `profile.edit` | auth | `pages/profile/Edit.tsx` | `ProfileController@edit` | 1 | Sem mock (item do menu "Minha conta"). Cards: Dados pessoais, Senha, Autenticação em duas etapas (TOTP), Sessões. Usa rotas Fortify: `user-profile-information.update`, `user-password.update`, `two-factor.enable`, `two-factor.confirm`, `two-factor.disable`, `two-factor.qr-code`, `two-factor.secret-key`, `two-factor.recovery-codes`, `password.confirm`. |
| Trocar organização | POST | `/organizacoes/{organization}/ativar` | `organizations.switch` | auth | — | `OrganizationSwitchController@store` | 1 | Grava `current_organization_id` na sessão; redireciona para `dashboard`. |
| Criar organização | POST | `/organizacoes` | `organizations.store` | auth | — | `OrganizationController@store` | 1★ | Item "Criar nova organização" no switcher (ver Q5). |

### 1.3 Signatário (público) — layout `SignerLayout` (mock `Assinar - Pagina publica`)

| Tela / etapa | Método | URI | Nome da rota | Middleware | Página Inertia | Controller | Fase |
|---|---|---|---|---|---|---|---|
| Abrir convite (todas as fases) | GET | `/assinar/{token}` | `sign.show` | signer | `pages/sign/Show.tsx` | `Sign\SignerPageController@show` | 1 |
| Enviar/reenviar OTP | POST | `/assinar/{token}/codigo` | `sign.otp.send` | signer, throttle:otp-send (3/10min) | — | `Sign\OtpController@send` | 1 |
| Verificar OTP | POST | `/assinar/{token}/codigo/verificar` | `sign.otp.verify` | signer, throttle:otp-verify (5/10min) | — | `Sign\OtpController@verify` | 1 |
| PDF original (stream) | GET | `/assinar/{token}/documento` | `sign.document` | signer.verified | — (application/pdf, inline) | `Sign\DocumentController@show` | 1 |
| Miniatura de página | GET | `/assinar/{token}/paginas/{page}.png` | `sign.page` | signer.verified | — (imagem) | `Sign\DocumentController@page` | 1 |
| Assinar (aceite explícito) | POST | `/assinar/{token}/assinar` | `sign.complete` | signer.verified, throttle:10,1 | — | `Sign\SignatureController@store` | 1 |
| Recusar | POST | `/assinar/{token}/recusar` | `sign.refuse` | signer.verified | — | `Sign\RefusalController@store` | 1 |
| Baixar cópia (após concluído) | GET | `/assinar/{token}/download/{type}` | `sign.download` | signer.verified | — (stream) | `Sign\DownloadController@show` | 1 |
| Termos / Privacidade | GET | `/termos`, `/privacidade` | `legal.terms`, `legal.privacy` | public | `pages/legal/Terms.tsx`, `pages/legal/Privacy.tsx` | `LegalController@terms/@privacy` | 1 |
| Entrada do site | GET | `/` | `home` | public | — (redireciona) | `HomeController@index` | 1 — visitante → `login`; autenticado → dashboard (ou painel interno, para o administrador da plataforma sem organização). A home institucional foi retirada em 2026-09-21, a pedido do proprietário. |

### 1.4 Verificação pública (sem mock — contrato na seção 4)

| Tela | Método | URI | Nome da rota | Middleware | Página Inertia | Controller | Fase |
|---|---|---|---|---|---|---|---|
| Formulário de verificação | GET | `/verificar` | `verify.index` | public | `pages/verify/Index.tsx` | `VerificationController@index` | 1 |
| Resultado por código | GET | `/verificar/{code}` | `verify.show` | public, throttle:20,1 | `pages/verify/Show.tsx` | `VerificationController@show` | 1 |
| Conferir arquivo (hash) | POST | `/verificar/{code}/conferir` | `verify.check_file` | public, throttle:10,1 | — (retorna props `file_check`) | `VerificationController@checkFile` | 1★ (opcional; ver Q14) |

### 1.5 Painel interno (platform-admin) — layout `AppLayout` (sidebar `admin`)

| Tela | Método | URI | Nome da rota | Middleware | Página Inertia | Controller | Fase | Observações |
|---|---|---|---|---|---|---|---|---|
| Clientes | GET | `/admin/clientes` | `admin.organizations.index` | platform-admin | `pages/admin/organizations/Index.tsx` | `Admin\OrganizationController@index` | 1★ | Query `status`, `plan`, `segment`, `created_from`, `created_to`, `q`, `page`. "Segmento" → **Fase 2** (campo inexistente; filtro oculto). Botão "Nova conta" → **Fase 2** (oculto). |
| Clientes — exportar | GET | `/admin/clientes/exportar` | `admin.organizations.export` | platform-admin | — (CSV) | `Admin\OrganizationController@export` | 1 | |
| Cliente — detalhe | GET | `/admin/clientes/{organization}` | `admin.organizations.show` | platform-admin | `pages/admin/organizations/Show.tsx` | `Admin\OrganizationController@show` | 1★ | Sem mock: cabeçalho + KPIs da org + lista de membros + assinatura/pagamentos (somente leitura). Alvo do link no nome da empresa. |
| Cliente — "Acessar como" | POST | `/admin/clientes/{organization}/acessar-como` | `admin.organizations.impersonate` | platform-admin, password.confirm | — | `Admin\ImpersonationController@store` | **2** | Ver Q15. Na Fase 1 o botão fica oculto. Extension point: `impersonations` table + banner "Você está acessando como…" + `admin.impersonation.stop`. |
| Planos e faturamento | GET | `/admin/faturamento` | `admin.billing.index` | platform-admin | `pages/admin/Placeholder.tsx` | `Admin\PlaceholderController` | **2** | Item de sidebar renderizado desabilitado (como no mock, `href="#"`). |
| Usuários da plataforma | GET | `/admin/usuarios` | `admin.users.index` | platform-admin | idem | idem | **2** | idem |
| Logs e auditoria | GET | `/admin/auditoria` | `admin.audit.index` | platform-admin | idem | idem | **2** | idem |
| Configurações globais | GET | `/admin/configuracoes` | `admin.settings.index` | platform-admin | idem | idem | **2** | idem |
| Voltar ao app | — | link para `dashboard` | — | — | — | — | 1 | |

### 1.6 Componentes compartilhados (não são rotas)

| Mock | Componente React | Observações |
|---|---|---|
| `AppSidebar.dc.html` | `resources/js/layouts/app/AppSidebar.tsx` (`mode: 'client' \| 'admin'`) | Seção 5. |
| Topbar (todas as telas do app) | `layouts/app/AppTopbar.tsx` | Breadcrumb, busca ⌘K (`CommandSearch.tsx`), sino (`NotificationsPopover.tsx`), toggle sidebar (`SidebarTrigger`). |
| Split auth | `layouts/AuthLayout.tsx` | Painel navy + card 400px. |
| Público signatário | `layouts/SignerLayout.tsx` | Header com org remetente + stepper + "via AssinaVelox". |
| Badges de status | `components/status/EnvelopeStatusBadge.tsx`, `RecipientStatusBadge.tsx`, `SubscriptionStatusBadge.tsx`, `PaymentStatusBadge.tsx` | Mapeamentos da seção 6. |
| Estado vazio Fase 2 | `components/Phase2EmptyState.tsx` | Props `title`, `description`, `ctaHref?`. |

---

## 2. Contratos por página (Fase 1)

Convenções: todas as datas em ISO-8601 UTC (`string`); o front formata em pt-BR com `Intl`/`date-fns` e o `timezone` do usuário ("Hoje, 09:12", "Ontem, 17:25", "28 ago, 16:48"). Valores monetários em centavos (`number`, BRL). O backend também envia `*_label` quando a formatação depende de regra de negócio (ex.: `expires_label: "Expira em 15 set (12 dias)"`), para evitar duplicar regras no front.

Tipos comuns:

```ts
export interface UserRef { id: string; name: string; initials: string; email?: string }
export interface FolderRef { id: string; name: string }
export interface RecipientAvatar { id: string; name: string; initials: string; status: RecipientStatus }

export interface EnvelopeListItem {
  id: string;
  public_code: string;                 // "AV-00148"
  title: string;
  status: EnvelopeStatus;
  status_label: string;                // seção 6.1 (inclui regra Aguardando/Em andamento)
  folder: FolderRef | null;
  document: { pages: number | null; mime: 'application/pdf' | null; original_name: string | null } | null;
  recipients: RecipientAvatar[];       // até 5; `recipients_count` traz o total
  recipients_count: number;
  signed_count: number;                // "1 de 2"
  creator: UserRef;
  created_at: string;
  updated_at: string;                  // coluna "Atualizado" = último evento
  expires_at: string | null;
  can: { view: boolean; update: boolean; cancel: boolean; delete: boolean; download_signed: boolean };
}
```

### 2.1 `pages/auth/Login.tsx`

```ts
interface LoginProps {
  canResetPassword: true;
  status: string | null;   // flash do Fortify (ex.: após reset de senha)
}
```
- Form → `POST login`: `email` (required, email, max 255), `password` (required, string), `remember` (boolean, default true — "Manter conectado" vem marcado no mock).
- Estados: `processing` desabilita botão e mostra spinner; erro `errors.email` sob o campo ("Estas credenciais não correspondem aos nossos registros." pt-BR); throttle 5/min → mensagem `errors.email` com segundos restantes; sucesso → redirect `dashboard` (ou `two-factor.login` se 2FA ativo, ou `verification.notice` se e-mail não verificado). Toggle mostrar/ocultar senha é estado local. Botão "Entrar com certificado digital" oculto (Fase 2).

### 2.2 `pages/auth/Register.tsx`

```ts
interface RegisterProps { invitation?: { email: string; organization_name: string } } // preenchido se veio de /convites/{token}
```
- Form → `POST register`: `name` (required, 3–120), `email` (required, email, unique users), `organization_name` (required, 2–120; label "Empresa / Razão social"), `document_number` (nullable, CNPJ válido, máscara `00.000.000/0000-00`; aceitar também CPF para autônomos — ver Q3), `password` (required, min 8, `Password::defaults()->letters()->numbers()->symbols()`), `terms` (accepted).
- Medidor de força local: 4 segmentos = comprimento ≥ 8, letras, números, símbolo.
- Estados: erros por campo; sucesso → `verification.notice`. Se `invitation` presente: e-mail readonly, campo empresa oculto (não cria org; aceita convite após verificar).

### 2.3 `pages/auth/ForgotPassword.tsx` / `ResetPassword.tsx` / `TwoFactorChallenge.tsx` / `VerifyEmail.tsx`

```ts
interface ForgotPasswordProps { status: string | null }           // status !== null → card verde "Verifique seu e-mail" com o e-mail submetido (guardar em estado local)
interface ResetPasswordProps { token: string; email: string }
interface TwoFactorChallengeProps { }                               // form: code (6 dígitos) OU recovery_code
interface VerifyEmailProps { status: 'verification-link-sent' | null }
```
- ForgotPassword → `POST password.email` `{ email }`; "Reenviar link" repete o POST (throttle 6/min → `errors.email`).
- ResetPassword → `POST password.update` `{ token, email, password, password_confirmation }`.
- TwoFactorChallenge → `POST two-factor.login` `{ code? , recovery_code? }`; erro genérico "Código inválido".
- VerifyEmail → `POST verification.send`; sucesso mostra toast "Novo link enviado".

### 2.4 `pages/dashboard/Index.tsx`

```ts
interface DashboardProps {
  greeting: { first_name: string; date_label: string; pending_count: number }; // "Bom dia, Ana" (backend decide bom dia/tarde/noite pelo timezone do usuário)
  range: '30d' | '90d' | '12m';                                              // query `range`, default 30d
  kpis: {
    sent: { value: number; delta_pct: number | null; previous_value: number; previous_label: string };       // "vs. 132 em agosto"
    pending: { value: number; expiring_48h: number };
    completed: { value: number; completion_rate_pct: number | null; refused_or_expired: number };
    avg_time_to_complete: { minutes: number | null; delta_minutes: number | null };                         // do envio ao último signed
  };
  chart: {
    buckets: { date: string; sent: number; completed: number }[];   // 30 dias → 30 buckets diários; 90d → diários; 12m → mensais
    totals: { sent: number; completed: number };
    axis_labels: string[];                                           // "5 ago", "12 ago"… já formatados
  };
  pending_recipients: {                                              // até 4, ordenados por mais antigos
    id: string; envelope_id: string; name: string; initials: string;
    envelope_title: string; waiting_since: string;                   // front: "há 2 dias"
    can_resend: boolean;                                             // false se reenviado há < 10 min
  }[];
  pending_recipients_total: number;
  plan_usage: {
    plan_name: string;
    renews_at: string | null;                                        // "renova em 15 out" (null no free)
    envelopes: { used: number; limit: number | null };               // limit null = ilimitado
    members: { used: number; limit: number | null };
    storage: { used_bytes: number; limit_bytes: number | null };
  };
  recent_envelopes: EnvelopeListItem[];                              // 5, atualizados nos últimos 7 dias
  recent_total: number;                                              // "Mostrando 5 de 148 documentos"
}
```
- Ações: segmented "30 dias / 90 dias / 12 meses" → `router.get(route('dashboard'), { range }, { only: ['kpis','chart','range'], preserveState: true })`. "Exportar" → `window.location = route('dashboard.export', { range })`. "Lembrar" → `router.post(route('envelopes.recipients.resend', {envelope, recipient}))` → toast "Convite reenviado para {name}". Kebab da tabela: Abrir, Baixar (se completed), Cancelar (se pode).
- Estados: skeleton para KPIs/gráfico durante partial reload (`router.on('start')`); lista de pendências vazia → "Nenhum signatário pendente 🎉"; tabela vazia → "Você ainda não enviou documentos" + botão "Nova solicitação"; erro de reenvio (throttle) → toast destrutivo "Aguarde alguns minutos antes de reenviar".

### 2.5 `pages/envelopes/Index.tsx`

```ts
type EnvelopeTab = 'all' | 'awaiting' | 'in_progress' | 'completed' | 'drafts' | 'refused_expired';

interface EnvelopesIndexProps {
  filters: {
    status: EnvelopeTab;               // query `status`, default 'all'
    folder: string | null;             // folder id | null = Todos
    q: string;
    period_from: string | null; period_to: string | null;
    recipient: string;                 // e-mail ou nome (LIKE)
    creator: string | null;            // user id
    sort: 'updated_desc' | 'updated_asc' | 'created_desc' | 'title_asc' | 'expires_asc';
  };
  summary: { total: number; awaiting: number; storage_used_bytes: number };   // subtítulo
  tabs: Record<EnvelopeTab, number>;                                           // contagens já com filtro de pasta aplicado
  folders: { id: string | null; name: string; count: number }[];              // primeiro item {id:null,name:'Todos'}
  creators: UserRef[];                                                         // para o filtro "Criado por"
  envelopes: Paginated<EnvelopeListItem>;
  can: { create_folder: boolean; bulk_cancel: boolean };
}
```
- Mapeamento das abas → status: `awaiting` = `in_progress` com `signed_count = 0`; `in_progress` = `in_progress` com `signed_count > 0` **ou** `finalizing`; `completed` = `completed`; `drafts` = `draft|preparing|ready`; `refused_expired` = `refused|expired|canceled` (ver Q1).
- Ações: filtros → `router.get` com `preserveState`; seleção é estado local; barra em lote → `router.post(route('envelopes.bulk', {action}), { ids, folder_id?, reason? })`; "Nova pasta" → `Dialog` → `POST folders.store` `{ name }` (required, 2–60, unique por org); kebab da linha → Abrir, Editar (draft), Duplicar, Mover, Baixar original/assinado, Cancelar (AlertDialog), Excluir rascunho (AlertDialog).
- Validação bulk: `ids` array 1–100 de ULIDs da org; `move` exige `folder_id` nullable existente; `cancel` só afeta `in_progress` (ignora outros e relata `skipped` no flash); `resend` só `in_progress`.
- Estados: vazio filtrado → "Nenhum documento nesta combinação de pasta e status."; vazio absoluto (org sem envelopes) → ilustração + "Envie seu primeiro documento"; loading → linhas skeleton (10); toasts: "3 documentos movidos para Locação", "Convites reenviados (2)", "Documento cancelado".

### 2.6 `pages/envelopes/Wizard.tsx` (create/edit — passos 1–4)

```ts
interface WizardProps {
  envelope: {
    id: string; public_code: string; status: 'draft' | 'preparing' | 'ready';
    title: string; folder_id: string | null; expires_in_days: number; message: string;
    routing_mode: RecipientRoutingMode; send_copy_to_all: boolean;
    initials_on_all_pages: boolean;
    updated_at: string;                                             // "Rascunho salvo às 09:41"
  };
  step: 1 | 2 | 3 | 4;                                              // query `step`; backend rebaixa se passo anterior incompleto
  document: {
    id: string; original_name: string; size_bytes: number; mime: string;
    processing: { status: DocumentProcessingStatus; pages: number | null; error: string | null; progress_pct: number | null };
    pdf_url: string;                                                 // route('envelopes.download', {type:'original'})
    page_thumb_url_template: string;                                 // ".../paginas/{page}.png"
    page_sizes: { page: number; width_pt: number; height_pt: number }[]; // para converter coordenadas
  } | null;
  recipients: WizardRecipient[];
  fields: WizardField[];
  folders: FolderRef[];
  defaults: { expires_in_days: number; routing_mode: RecipientRoutingMode; initials_on_all_pages: boolean }; // de settings.signing
  role_suggestions: string[];                                        // ["Parte","Locatária","Locatário","Fiador","Testemunha","Representante"]
  plan: { envelopes_used: number; envelopes_limit: number | null; can_send: boolean; reason: string | null }; // "Consumo do plano"
  limits: { max_upload_bytes: number; accepted_mimes: string[] };    // 25 MB; pdf, docx, png, jpg
  completeness: { document: boolean; recipients: boolean; fields: boolean }; // habilita passos/CTA
}

interface WizardRecipient {
  id: string | null;                 // null = novo (client temp id em `client_id`)
  client_id: string;
  name: string; email: string; role: string;
  order: number;                      // 1..n (ignorado em parallel, mas persistido)
  color_index: 0 | 1 | 2 | 3;         // paleta do mock
  channel: 'email'; auth_methods: ['email_otp'];   // fixos na Fase 1 (UI mostra chips desabilitados)
}

interface WizardField {
  id: string | null; client_id: string;
  recipient_client_id: string;
  type: FieldType;
  page: number | 'all';               // 'all' só para initials
  x: number; y: number; w: number; h: number;   // frações 0–1 relativas à página (origem canto superior esquerdo)
  required: boolean;                  // default true (checkbox default false)
  label: string | null;               // texto livre: rótulo; checkbox: texto
  placeholder: string | null;         // "DD/MM/AAAA"
}
```
- Passo 1 ações: upload → `POST envelopes.document.store` (multipart `file`; rules: required, file, mimes pdf,docx,png,jpg,jpeg, max 25600 KB; 1 arquivo — multi-arquivo/junção → Fase 2); remover → `DELETE envelopes.document.destroy`; metadados → `PATCH envelopes.update` `{ title (required,3–160), folder_id (nullable,exists org), expires_in_days (int 1–90), message (nullable, max 1000), routing_mode, send_copy_to_all }`. Idioma → removido (só pt-BR). Lembretes automáticos → switch desabilitado + "Fase 2". Card de modelos → `Phase2EmptyState`.
- Passo 2 → `PUT envelopes.recipients.sync` `{ routing_mode, recipients: [{ id?, name (required 2–120), email (required, email, distinto entre recipients — ver Q6), role (nullable ≤ 40), order }] }`; mínimo 1, máximo 20 recipients. Campos celular/canal/auth ficam ocultos ou desabilitados. "Importar dos contatos" → Fase 2 (oculto). "Adicionar testemunha" = recipient com `role = 'Testemunha'`.
- Passo 3 → `PUT envelopes.fields.sync` `{ initials_on_all_pages, fields: [...] }`; regras: cada recipient deve ter ≥ 1 `signature` (erro "Todo signatário precisa de pelo menos um campo de assinatura"); `page` ≤ `document.pages`; `x,y,w,h` em [0,1] com `x+w ≤ 1`, `y+h ≤ 1`; máximo 200 campos. Editor sobre PDF.js: paleta de tipos arrastável, rail de miniaturas, lista "Campos inseridos". Tipos CPF/Carimbo → removidos da paleta.
- Passo 4 → `POST envelopes.send` (sem payload além de `_token`; mensagem/cópia já salvas via PATCH). Validação server: completude, `plan.can_send` (402 → flash error "Limite de documentos do plano atingido" + link `plans.index`), status `ready`. Sucesso → redirect `envelopes.show?sent=1` que renderiza a tela "Enviado para assinatura" (texto adapta a `routing_mode`).
- Estados: header "Rascunho salvo às HH:mm" (de `updated_at`) / "Salvando…"; upload com progress bar (`onProgress`); `preparing` → linha do arquivo mostra "Convertendo… {pct}%" com polling; `failed` → linha vermelha "Falha ao processar o arquivo. Envie um PDF válido." + botão remover; drag&drop hover; passo bloqueado → tooltip "Complete o passo anterior"; erros 422 por recipient/field exibidos inline (`errors['recipients.0.email']`).

### 2.7 `pages/envelopes/Show.tsx`

```ts
interface EnvelopeShowProps {
  envelope: {
    id: string; public_code: string; verification_code: string; title: string;
    status: EnvelopeStatus; status_label: string;
    signed_count: number; recipients_count: number;
    folder: FolderRef | null; creator: UserRef; created_at: string;
    expires_at: string | null; expires_label: string | null; expiring_soon: boolean;   // < 72h → cor de alerta
    sent_at: string | null; completed_at: string | null; canceled_at: string | null; cancel_reason: string | null;
    routing_mode: RecipientRoutingMode; message: string | null; send_copy_to_all: boolean;
    document: { original_name: string; size_bytes: number; pages: number; sha256_original: string; sha256_signed: string | null; pdf_url: string };
    downloads: { original: string; signed: string | null; evidence: string | null };
    can: { update: boolean; cancel: boolean; delete: boolean; resend: boolean; duplicate: boolean; move: boolean };
  };
  recipients: {
    id: string; name: string; email: string; role: string | null; order: number; initials: string; color_index: number;
    status: RecipientStatus; status_label: string;
    channel: 'email'; auth_methods: AuthMethod[];
    sent_at: string | null; viewed_at: string | null; signed_at: string | null; refused_at: string | null; refusal_reason: string | null;
    last_resent_at: string | null; can_resend: boolean; can_edit: boolean;
    evidence: { ip: string; user_agent_label: string; signature_method: SignatureMethod | null } | null; // só quando signed
  }[];
  fields: { id: string; recipient_id: string; type: FieldType; page: number | 'all'; x: number; y: number; w: number; h: number; value: string | null; signed: boolean }[];
  events: { id: string; type: EnvelopeEventType; kind: 'info' | 'ok' | 'warn'; title: string; meta: string; occurred_at: string }[]; // título/meta pré-formatados em pt-BR
  folders: FolderRef[];
  sent: boolean;                         // query ?sent=1 → tela de sucesso do wizard
  tab: 'signers' | 'audit' | 'details';  // query `tab`
}
```
- Ações: "Baixar" dropdown (original / assinado / relatório de evidências — desabilitados conforme `downloads`); "Lembrar pendentes" → `POST envelopes.resend`; "Reenviar" por recipient → `envelopes.recipients.resend`; "Editar" recipient → `Dialog` → `PATCH envelopes.recipients.update` `{ name, email }`; "Ver evidências" → `envelopes.evidence`; "Relatório PDF" → download `evidence`; "Mover para pasta" → `Dialog` com `Select` → `PATCH envelopes.move`; "Duplicar" → `POST envelopes.duplicate`; "Cancelar documento" → `AlertDialog` com motivo opcional → `POST envelopes.cancel`; kebab: Excluir rascunho, Copiar código de verificação, Abrir página pública de verificação.
- Detalhes (key/value): ID, Código de verificação, Status, Criado por, Criado em, Enviado em, Expira em, Pasta, Arquivo (nome · tamanho · páginas), Ordem, Mensagem, Cópia final para todos. "Modelo", "Lembretes", "Webhook" → omitidos (Fase 2).
- Estados: `finalizing` → banner azul "Gerando o PDF assinado e o relatório de evidências…" com polling (partial reload 5s); `completed` → banner verde com botão "Baixar PDF assinado"; `refused` → banner vermelho com motivo; `expired/canceled` → banner cinza; viewer PDF com skeleton; toasts de sucesso das ações; erros 409 (status inválido) → toast "Ação indisponível no status atual".

### 2.8 `pages/envelopes/Evidence.tsx`

```ts
interface EvidenceProps {
  envelope: Pick<EnvelopeShowProps['envelope'], 'id' | 'public_code' | 'verification_code' | 'title' | 'status' | 'status_label' | 'created_at' | 'sent_at' | 'completed_at' | 'document' | 'downloads' | 'routing_mode'>;
  organization: { name: string; legal_name: string | null; document_number_masked: string | null };
  recipients: {
    name: string; email: string; role: string | null; status: RecipientStatus; status_label: string;
    auth_methods: AuthMethod[]; signature_method: SignatureMethod | null; signature_image_url: string | null;
    sent_at: string | null; viewed_at: string | null; otp_verified_at: string | null; signed_at: string | null; refused_at: string | null; refusal_reason: string | null;
    ip: string | null; user_agent: string | null; geo_label: string | null;   // geo null na Fase 1
    consent_text: string | null;   // texto exato aceito
  }[];
  events: EnvelopeShowProps['events'];
  hashes: { original_sha256: string; signed_sha256: string | null; evidence_sha256: string | null };
  certificate: { subject: string; issuer: string; serial: string; valid_from: string; valid_to: string; policy: 'PAdES-B-LT' | 'PAdES-B-T' | 'PAdES-B-B' } | null; // do A1 da empresa
  verify_url: string;    // route('verify.show', code)
}
```
- Somente leitura; botão "Baixar relatório (PDF)" → `downloads.evidence` (o PDF é gerado pelo mesmo dataset via Blade + DomPDF/Browsershot no job de finalização).

### 2.9 `pages/recipients/Index.tsx` (Assinaturas)

```ts
type RecipientTab = 'all' | 'pending' | 'signed' | 'refused' | 'expired';

interface RecipientsIndexProps {
  filters: { status: RecipientTab; q: string; period_from: string | null; period_to: string | null; sort: 'recent' | 'oldest' };
  kpis: {
    signed_today: { value: number; delta_vs_yesterday: number };
    pending: { value: number; viewed: number };
    refused_30d: { value: number; pct_of_total: number | null };
    avg_minutes_to_sign: { value: number | null };
  };
  tabs: Record<RecipientTab, number>;
  recipients: Paginated<{
    id: string; envelope_id: string; name: string; initials: string; email: string; role: string | null;
    envelope: { public_code: string; title: string; status: EnvelopeStatus };
    channel: 'email'; auth_methods: AuthMethod[];
    status: RecipientStatus; status_label: string;
    when: string;                    // signed_at ?? refused_at ?? expired_at ?? viewed_at ?? sent_at
    note: string;                    // "Visualizou · reenviado às 09:00" | "Enviado · não visualizou" | "Motivo: “…”" | "Prazo encerrado em 04 set" | "IP 187.12.44.9 · iPhone (Safari)"
    can_resend: boolean;
  }>;
}
```
- Tabs → status: `pending` = `waiting|sent|viewed`; `signed`; `refused`; `expired` = `expired|canceled`.
- Ações: "Exportar CSV" → `recipients.export` com filtros; "Lembrar todos os pendentes" → `AlertDialog` → `POST recipients.resend_pending` → toast "Reenviando convites para {n} signatários pendentes"; kebab: Abrir documento, Reenviar convite, Ver evidências (se signed), Copiar e-mail.
- Estados: vazio → "Nenhuma assinatura encontrada para este filtro."; skeleton em reload.

### 2.10 `pages/members/Index.tsx` (Usuários)

```ts
interface MembersIndexProps {
  tab: 'members' | 'roles';
  seats: { used: number; limit: number | null; pending_invitations: number; available: number | null; plan_name: string };
  filters: { q: string; role: MembershipRole | null; status: 'active' | 'suspended' | 'invited' | null };
  members: {
    id: string; user: UserRef & { email: string }; role: MembershipRole; role_label: string;
    status: MembershipStatus; status_label: string;
    two_factor_enabled: boolean; last_seen_at: string | null; is_me: boolean;
    can: { change_role: boolean; suspend: boolean; remove: boolean; transfer_ownership: boolean };
  }[];
  invitations: { id: string; email: string; role: MembershipRole; role_label: string; sent_at: string; expires_at: string; invited_by: UserRef }[]; // exibidos na mesma tabela com status "Convite pendente"
  roles: { key: MembershipRole; label: string; description: string }[];      // Proprietário / Administrador / Membro
  permission_matrix: { key: keyof OrgPermissions | string; label: string; description: string; grants: Record<MembershipRole, boolean> }[];
  folders: FolderRef[];   // reservado (Fase 2 "Pastas com acesso")
}
```
- Convidar → `Dialog` → `POST invitations.store` `{ emails: string[] (1–20, cada e-mail válido, não membro, não convidado pendente), role: 'admin' | 'member' }`; erro 422 `seats` ("Sem assentos disponíveis — adicione assentos ao plano" com link `plans.index`); sucesso → toast "Convite enviado para {n} e-mail(s)". Expira em 7 dias (`invitations.expires_at`).
- Linha: select de função → `PATCH members.update { role }` (AlertDialog se rebaixar admin); kebab: Suspender/Reativar (`members.status`), Remover (`members.destroy`, AlertDialog), Transferir propriedade (owner), Reenviar convite / Revogar convite.
- Estados: rodapé "{n} usuários · {available} assentos disponíveis"; lista nunca vazia (há o próprio usuário); erro de assento; toasts.

### 2.11 `pages/invitations/Accept.tsx`

```ts
interface InvitationAcceptProps {
  invitation: { organization_name: string; organization_initials: string; role_label: string; email: string; invited_by: string; expires_at: string } | null; // null → estado inválido/expirado
  state: 'valid' | 'expired' | 'revoked' | 'accepted';
  auth_state: 'guest' | 'same_user' | 'other_user';   // logado com outro e-mail → botão "Sair e entrar com {email}"
}
```
- `POST invitations.accept.store` (sem payload quando `same_user`; quando guest, redireciona a `register?invitation=token` ou `login` com `intended`).

### 2.12 `pages/settings/General.tsx`

```ts
interface SettingsGeneralProps {
  organization: { legal_name: string | null; name: string; document_number: string | null; contact_email: string | null; initials: string; logo_url: null };
  security: { require_two_factor: boolean; session_idle_hours: 12 | null; sso_enabled: false; ip_allowlist_enabled: false };
  deletion: { requested_at: string | null; scheduled_for: string | null };
  can: { delete_organization: boolean };
}
```
- `PATCH settings.organization.update` `{ legal_name (nullable ≤160), name (required 2–120), document_number (nullable, CNPJ/CPF válido), contact_email (nullable email) }` → toast "Alterações salvas".
- `PATCH settings.security.update` `{ require_two_factor: boolean, session_idle_hours: 12 | null }`. Ao ativar `require_two_factor`, middleware `EnforceTwoFactor` redireciona membros sem 2FA para `profile.edit#2fa` com aviso.
- Danger zone → `AlertDialog` com confirmação digitada do nome da org + `password.confirm` → `POST settings.organization.destroy` → banner "Exclusão agendada para {date}" com botão "Cancelar exclusão" (`DELETE` na mesma rota).

### 2.13 `pages/settings/Signing.tsx`

```ts
interface SettingsSigningProps {
  defaults: { expires_in_days: number; routing_mode: RecipientRoutingMode; initials_on_all_pages: boolean; allow_drawn_signature: true; allow_typed_signature: boolean; allow_uploaded_signature: boolean };
  phase2: { reminders: false; auth_methods: ['email_otp']; channels: ['email'] };
}
```
- `PATCH settings.signing.update` `{ expires_in_days (1–90), routing_mode, initials_on_all_pages, allow_typed_signature, allow_uploaded_signature }` (desenhar é sempre permitido).

### 2.14 `pages/settings/Notifications.tsx`

```ts
type NotificationEvent = 'recipient_signed' | 'envelope_completed' | 'recipient_refused' | 'envelope_expiring' | 'daily_digest' | 'invitation_accepted' | 'product_news';
type NotificationChannel = 'mail' | 'database';   // "E-mail" | "No app"

interface SettingsNotificationsProps {
  events: { key: NotificationEvent; label: string; description: string; channels: Record<NotificationChannel, boolean>; locked: Partial<Record<NotificationChannel, boolean>> }[]; // ex.: daily_digest só mail
  digest_time_label: string;   // "08:00 (America/Sao_Paulo)"
}
```
- `PATCH settings.notifications.update` `{ preferences: Record<NotificationEvent, NotificationChannel[]> }` → toast "Preferências salvas". Persistência: `notification_preferences` (user_id, organization_id, event, channels JSON).

### 2.15 `pages/settings/Billing.tsx`

```ts
interface BillingProps {
  subscription: {
    plan: { key: PlanKey; name: string; price_cents_monthly: number; price_cents_yearly: number | null; features: string[] };
    status: SubscriptionStatus; status_label: string;
    interval: 'monthly' | 'yearly' | null;
    current_period_start: string | null; current_period_end: string | null;   // "03 set → 15 out"
    cancel_at_period_end: boolean; trial_ends_at: string | null;
  };
  usage: { envelopes: { used: number; limit: number | null }; members: { used: number; limit: number | null }; storage: { used_bytes: number; limit_bytes: number | null } };
  payment_method: { type: 'credit_card' | 'debit_card' | 'pix' | 'boleto' | 'account_money' | 'other'; label: string; last_four: string | null } | null; // do último pagamento aprovado (MP `payment_method_id` / `card.last_four_digits`)
  billing_profile: { legal_name: string; document_number: string; address_line: string; city: string; state: string; postal_code: string; email: string } | null;
  payments: Paginated<{ id: string; paid_at: string | null; created_at: string; description: string; amount_cents: number; status: PaymentStatus; status_label: string; receipt_url: string | null; mp_payment_id: string | null }>;
  can: { manage: boolean; cancel: boolean };
  pending_checkout: boolean;   // há Payment pending < 24h → banner "Aguardando confirmação do pagamento"
}
```
- "Alterar plano" → `plans.index`; "Cancelar renovação" → `AlertDialog` + `password.confirm` → `POST billing.cancel`; "Reativar" (quando `cancel_at_period_end`) → `POST billing.resume`; "Editar" dados → `Dialog` → `PATCH billing.profile.update` (todos required exceto complemento; CEP 8 dígitos; UF 2 letras); "PDF" → `receipt_url`.
- Estados: badge `past_due` → banner amarelo "Pagamento em atraso — regularize para continuar enviando documentos" + botão "Pagar agora" (`billing.checkout` com `plan` atual); lista de pagamentos vazia → "Nenhum pagamento ainda"; retorno do checkout via flash (`success`: "Pagamento aprovado! Seu plano foi atualizado." / `pending`: "Pagamento em análise…" / `failure`: "Pagamento não aprovado.").

### 2.16 `pages/settings/Plans.tsx`

```ts
interface PlansProps {
  current_plan: PlanKey; interval: 'monthly' | 'yearly';
  plans: { key: PlanKey; name: string; price_cents_monthly: number; price_cents_yearly: number | null; limits: { envelopes_per_month: number | null; members: number | null; storage_bytes: number | null }; features: string[]; highlighted: boolean; cta: 'current' | 'upgrade' | 'downgrade' | 'contact' }[];
}
```
- Escolher → `POST billing.checkout` `{ plan: PlanKey (≠ free), interval }` → `Inertia::location(init_point)`. Downgrade para `free` → `POST billing.cancel`. Estado: botão "Redirecionando para o Mercado Pago…".

### 2.17 `pages/profile/Edit.tsx`

```ts
interface ProfileEditProps {
  user: AuthUser & { created_at: string };
  two_factor: { enabled: boolean; confirmed: boolean; qr_code_svg: string | null; secret_key: string | null; recovery_codes: string[] | null }; // preenchidos só após enable (Fortify flash)
  sessions: { id: string; ip: string; agent_label: string; last_active_at: string; is_current: boolean }[];
  must_enable_two_factor: boolean;   // org exige 2FA e usuário não tem → banner
}
```
- Fortify: `PUT user-profile-information.update { name, email }` (mudança de e-mail reenvia verificação); `PUT user-password.update { current_password, password, password_confirmation }`; `POST two-factor.enable` → `POST two-factor.confirm { code }` → exibe recovery codes; `DELETE two-factor.disable`; `POST two-factor.recovery-codes` (regenerar); `DELETE other-browser-sessions.destroy { password }`. Todas com `password.confirm` (modal `ConfirmsPassword`).

### 2.18 `pages/sign/Show.tsx` (público) — ver também seção 3

```ts
type SignerScreen = 'identify' | 'sign' | 'completed' | 'refused' | 'expired' | 'canceled' | 'already_signed_pending_others' | 'invalid';

interface SignShowProps {
  screen: SignerScreen;
  sender: { organization_name: string; organization_initials: string; logo_url: null; user_name: string };
  envelope: { public_code: string; title: string; pages: number; sent_at: string; expires_at: string | null; status: EnvelopeStatus; completed_at: string | null };
  recipient: { first_name: string; name: string; role: string | null; email_masked: string; status: RecipientStatus; order: number };
  others: { name: string; role: string | null; order: number; status: RecipientStatus; signs_after_me: boolean }[];   // sem e-mails
  routing_mode: RecipientRoutingMode;
  otp: { sent_at: string | null; expires_at: string | null; resend_available_at: string | null; attempts_left: number } | null;   // só em identify
  document: { pdf_url: string; page_thumb_url_template: string; page_sizes: { page: number; width_pt: number; height_pt: number }[] } | null; // só em sign/completed
  my_fields: { id: string; type: FieldType; page: number | 'all'; x: number; y: number; w: number; h: number; required: boolean; label: string | null; placeholder: string | null; prefill: string | null }[]; // name/date prefilled
  other_fields: { recipient_name: string; role: string | null; type: FieldType; page: number; x: number; y: number; w: number; h: number; signed: boolean }[];
  signature_options: { draw: true; type: boolean; upload: boolean; fonts: ('caveat' | 'dancing_script' | 'homemade_apple')[] };
  consent_text: string;          // versão exata do texto legal (armazenada no evento)
  legal: { terms_url: string; privacy_url: string };
  receipt: { signed_at: string; verification_code: string; signed_sha256: string | null; ip: string; auth_label: string; download_url: string | null; final_pdf_available: boolean } | null;  // completed
  refusal: { refused_at: string; reason: string } | null;
}
```
- `POST sign.otp.send` (sem payload) → props `otp` atualizados (Inertia redirect back); throttle → `errors.otp = "Aguarde {s}s para reenviar"`.
- `POST sign.otp.verify { code: string (6 dígitos) }` → sucesso: sessão marcada, redirect `sign.show` (screen `sign`); erro: `errors.code = "Código inválido ou expirado ({n} tentativas restantes)"`; após 5 falhas o código é invalidado e é preciso reenviar.
- `POST sign.complete { signature: { method: 'draw'|'type'|'upload', image_base64?: string (PNG, ≤ 300 KB, quando draw/upload), text?: string (2–80, quando type), font?: string }, initials?: { method, image_base64?, text? } (obrigatório se houver campo initials), fields: { [field_id]: string | boolean }, consent: true }`. Regras: todos `my_fields.required` preenchidos; `date` em `YYYY-MM-DD` (default hoje, editável? → fixado na data do servidor, ver Q9); `checkbox` boolean; `text` ≤ 500; `consent` accepted. Sucesso → redirect `sign.show` (`completed`). 409 se recipient não está mais pendente.
- `POST sign.refuse { reason: string (10–500) }` → `refused`.
- Estados: PDF loading skeleton; botão "Assinar documento" desabilitado até campos obrigatórios + consent; erro de rede → toast; `expired/canceled` → card cinza "Este documento não está mais disponível para assinatura" com contato do remetente (nome, sem e-mail); `already_signed_pending_others` = card "Você já assinou. Aguardando {n} signatário(s)."; `invalid` → 404 com card "Link inválido".

### 2.19 `pages/verify/Index.tsx` e `pages/verify/Show.tsx`

```ts
interface VerifyIndexProps { code?: string; error?: string }   // form: code (required, regex ^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$, case-insensitive)

interface VerifyShowProps {
  found: boolean;
  result: {
    verification_code: string;
    status: 'completed' | 'in_progress' | 'refused' | 'expired' | 'canceled';   // draft/preparing/ready → found=false
    status_label: string;
    title: string;                            // ver Q13
    organization_name: string;
    created_at: string; sent_at: string | null; completed_at: string | null;
    pages: number;
    hashes: { original_sha256: string; signed_sha256: string | null };
    recipients: { name_masked: string; role: string | null; status: RecipientStatus; status_label: string; signed_at: string | null }[];   // "Maria A. S." — ver Q13
    certificate: { subject_cn: string; issuer_cn: string; valid_to: string; policy: string } | null;
    events_summary: { label: string; occurred_at: string }[];   // marcos: enviado, cada assinatura, concluído
  } | null;
  file_check: { matches: 'signed' | 'original' | 'none'; checked_sha256: string } | null;
}
```

### 2.20 `pages/admin/organizations/Index.tsx`

```ts
type AdminOrgTab = 'all' | 'active' | 'trialing' | 'past_due' | 'canceled';

interface AdminOrganizationsIndexProps {
  filters: { status: AdminOrgTab; plan: PlanKey | null; created_from: string | null; created_to: string | null; q: string };
  kpis: {
    active_accounts: { value: number; new_this_month: number };
    mrr_cents: { value: number; delta_pct: number | null };
    envelopes_today: { value: number; peak_hour: number | null; peak_count: number | null };
    trials_expiring_7d: { value: number; without_envelope: number };
    past_due: { value: number; overdue_cents: number };
  };
  tabs: Record<AdminOrgTab, number>;
  organizations: Paginated<{
    id: string; public_id: string;         // "acc_1042" = `organizations.public_id`
    name: string; initials: string;
    plan: { key: PlanKey; name: string };
    members: { used: number; limit: number | null };
    envelopes_cycle: { used: number; limit: number | null; pct: number | null };
    mrr_cents: number;
    subscription_status: SubscriptionStatus; status_label: string;
    last_seen_at: string | null; created_at: string;
    owner: UserRef & { email: string };
  }>;
}
```
- Linha: nome → `admin.organizations.show`; kebab: Ver detalhes, Copiar ID, Abrir Mercado Pago (link externo com `mp_preapproval_id` se houver). "Acessar como" oculto na Fase 1.
- Estados: vazio → "Nenhuma conta encontrada"; badge topo "Acesso restrito · ações são auditadas" (todas as rotas admin gravam `admin_audit_logs`).

### 2.21 Placeholders Fase 2 (`pages/templates/Index.tsx`, `pages/integrations/Index.tsx`, `pages/admin/Placeholder.tsx`)

```ts
interface Phase2PlaceholderProps { feature: 'templates' | 'api_integrations' | 'admin_billing' | 'admin_users' | 'admin_audit' | 'admin_settings'; title: string; subtitle: string }
```
- Renderizam a shell do mock (breadcrumb, título, subtítulo, botões primários desabilitados com `Tooltip` "Disponível na Fase 2") e `Phase2EmptyState`. Sem ações.

---

## 3. Fluxo público do signatário (mock `Assinar - Pagina publica`)

### 3.1 Emissão do link
- Ao `envelopes.send` (ou quando chega a vez em `sequential`), o job `NotifyRecipient` gera `token = random(48)` (base62), grava `recipients.access_token_hash = sha256(token)`, `token_issued_at`, e envia e-mail com `route('sign.show', token)`. O token em claro nunca é persistido. Recipients `waiting` **não** possuem token (link inexistente até a vez).
- Reenvio (`envelopes.recipients.resend`) reutiliza o mesmo token; edição de e-mail rotaciona o token (`recipient.updated` + `recipient.resent`).

### 3.2 Sequência (mapeada ao stepper "Confirmar identidade → Assinar → Concluído")

| # | Passo | Rota | Resultado / `screen` | Evento gravado |
|---|---|---|---|---|
| 1 | Abre o link | `GET sign.show` | `ResolveSignerToken`: hash não encontrado → 404 `invalid`. Envelope `expired/canceled` ou recipient `expired/canceled` → `expired`/`canceled`. Recipient `signed` e envelope `completed` → `completed` (recibo, download disponível). Recipient `signed` e envelope ainda `in_progress/finalizing` → `already_signed_pending_others`. Recipient `refused` → `refused`. Caso contrário (sent/viewed) → se sessão não verificada: `identify`; se verificada: `sign`. | `recipient.viewed` (primeira vez; status `sent→viewed`) |
| 2 | Envio automático do OTP | Na primeira renderização de `identify` sem `otp.sent_at`, o front dispara `POST sign.otp.send` (e o card mostra "Enviamos um código para m•••@gmail.com"). | Código 6 dígitos numéricos, `otp_codes` (recipient_id, code_hash bcrypt, expires_at = +10 min, attempts, consumed_at). Reenvio permitido após 60 s; máx. 3 envios/10 min. | `recipient.otp_sent` |
| 3 | Verifica OTP | `POST sign.otp.verify` | OK → `session(['signer.verified.'.$recipient->id => now()])`, redirect `sign.show` → `sign`. Falha → 422 + `attempts_left`; 5 falhas → código consumido, exige reenvio. | `recipient.otp_verified` / `recipient.otp_failed` |
| 4 | Lê e preenche | `sign` renderiza PDF.js com `sign.document`; overlays: `my_fields` (azul tracejado "Clique para assinar aqui") e `other_fields` (cinza "Carlos Mendes · assina depois de você" / verde se já assinado). Captura de assinatura: Desenhar (canvas → PNG), Digitar (nome em fonte manuscrita), Enviar imagem (PNG/JPG ≤ 2 MB → reprocessada para PNG ≤ 300 KB). "Certificado" (ICP-Brasil) → **oculto** (Fase 2). Botões "Baixar PDF" (original) e "Ampliar" (dialog fullscreen). | — |
| 5 | Aceite explícito | Checkbox de consentimento **desmarcado por padrão** (diferente do mock; exigência legal de manifestação ativa) + botão "Assinar documento" → `POST sign.complete`. | Server: valida, grava `recipient_field_values`, `signatures` (imagem PNG no storage privado, método, fonte), `signed_at`, `ip`, `user_agent`, `consent_text_version`; status `signed`. Se `sequential` notifica o próximo (`waiting→sent`). Se todos `signed` → envelope `finalizing` + job `FinalizeEnvelope` (estampa campos no PDF via pdf-lib/FPDI, assina PAdES com A1 da empresa via pyHanko, gera relatório de evidências, calcula hashes, `completed`, notifica todos + cópia final se `send_copy_to_all`). | `recipient.signed`, `envelope.finalizing`, `envelope.completed` |
| 6 | Recibo | Redirect `GET sign.show` → `completed` (ou `already_signed_pending_others`). Card "Documento assinado" com `receipt` (data/hora, código de verificação, hash quando disponível, autenticação "código por e-mail", IP). "Baixar cópia" → `sign.download/{type}`: `original` sempre; `signed` só quando envelope `completed` (até então botão desabilitado "Disponível quando todos assinarem"). "Concluir" → link para `home` (não reinicia). | `envelope.downloaded` (actor recipient) |

### 3.3 Caminho de recusa
- Link "Recusar assinatura" (visível em `sign`) → `Dialog` com textarea "Motivo" (10–500 chars, obrigatório) → `POST sign.refuse` → recipient `refused`, envelope `refused`, demais pendentes `canceled` (notificados "Documento encerrado"), remetente notificado com o motivo. Tela `refused`: card cinza "Você recusou assinar este documento em {data}" + motivo. Não reversível pelo signatário; remetente pode duplicar o envelope.

### 3.4 Estados terminais/anômalos (texto sugerido)
| `screen` | Título | Texto |
|---|---|---|
| `expired` | "Prazo encerrado" | "O prazo para assinar este documento terminou em {expires_at}. Entre em contato com {sender.user_name} ({organization_name})." |
| `canceled` | "Documento cancelado" | "{organization_name} cancelou esta solicitação de assinatura." |
| `already_signed_pending_others` | "Você já assinou" | "Sua assinatura foi registrada em {signed_at}. Aguardando {n} signatário(s). Você receberá o PDF final por e-mail." |
| `refused` | "Assinatura recusada" | motivo |
| `invalid` (404) | "Link inválido" | "Este link não existe ou foi substituído. Verifique o e-mail mais recente." |

### 3.5 Segurança
- Sessão do signatário isolada (`session.cookie` alternativo via middleware `SignerSession`), `SameSite=Lax`, 60 min de inatividade. A verificação OTP é por `recipient_id`; outro token na mesma sessão exige novo OTP.
- `sign.document` só após OTP (`signer.verified`) — o PDF nunca é servido por URL pública.
- Cabeçalhos `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer` em `/assinar/*`.
- Rate limiting por IP+token; eventos `otp_failed` alimentam a trilha.

---

## 4. Página pública de verificação (`/verificar/{code}`)

### 4.1 Contrato
- `verification_code`: 12 caracteres base32 sem ambíguos (`ABCDEFGHJKMNPQRSTUVWXYZ23456789`), exibido como `XXXX-XXXX-XXXX`, gerado ao criar o envelope, impresso no rodapé de cada página do PDF final ("Verifique em assinavelox.com.br/verificar · código XXXX-XXXX-XXXX") e no relatório de evidências. Não é sequencial e não deriva de `public_code`.
- Só responde para envelopes que já foram enviados (`in_progress|finalizing|completed|refused|expired|canceled`). Rascunhos → `found=false` (mesma resposta de código inexistente, evitando enumeração).
- Throttle 20/min por IP; captcha **não** na Fase 1 (ver Q14).

### 4.2 O que é exibido
- Código, `status_label` com selo (verde "Documento concluído e assinado digitalmente" / amarelo "Em andamento" / vermelho "Recusado" / cinza "Expirado" ou "Cancelado").
- Nome da organização remetente; título do documento (ver Q13); nº de páginas; datas: criado, enviado, concluído.
- Hashes SHA-256 do original e do PDF final (com botão copiar) + formulário "Conferir meu arquivo" (opcional, Q14).
- Lista de signatários com nome **parcialmente mascarado** ("Maria A. S."), papel, status, data/hora de assinatura.
- Dados do certificado A1 da empresa (CN do titular, emissor, validade, política PAdES) quando `completed`.
- Marcos da linha do tempo (enviado, assinado por X, concluído) sem IP/agent.

### 4.3 O que **nunca** é exibido
- E-mails, telefones, IPs, user agents, geolocalização, códigos OTP, tokens de acesso, imagens de assinatura.
- Conteúdo ou download do PDF (original ou assinado), miniaturas de páginas.
- Valores de campos preenchidos (texto, checkbox), mensagem do remetente, pasta, nome do usuário criador.
- Dados de faturamento/plano da organização; IDs internos (`id`, `public_code AV-…` — apenas o código de verificação).
- Qualquer indicação de existência para códigos de rascunho/inexistentes ("Nenhum documento encontrado com este código").

---

## 5. Modelo de navegação

### 5.1 Sidebar modo `client` (`AppSidebar mode="client"`)

| Grupo | Item (label) | Rota | Ativo quando (`route().current()`) | Badge | Fase |
|---|---|---|---|---|---|
| — | Switcher da organização | `organizations.switch` (POST) | — | — | 1 |
| — | CTA "Nova solicitação" | `envelopes.create` | — | — | 1 |
| Plataforma | Dashboard | `dashboard` | `dashboard*` | — | 1 |
| Plataforma | Documentos | `envelopes.index` | `envelopes.*` (inclui show/edit/evidence) | `counts.pending_envelopes` (âmbar; oculto se 0) | 1 |
| Plataforma | Assinaturas | `recipients.index` | `recipients.*` | — | 1 |
| Plataforma | Modelos | `templates.index` | `templates.*` | tag "Fase 2" (cinza) | 2 |
| Conta | Usuários | `members.index` | `members.*`, `invitations.*` | opcional: nº de convites pendentes (cinza) | 1 — **oculto para `member`** |
| Conta | API e integrações | `integrations.index` | `integrations.*` | tag "Fase 2" | 2 |
| Conta | Configurações | `settings.general` | `settings.*`, `billing.*`, `plans.*` | — | 1 — para `member` aponta para `settings.notifications` (única aba permitida) |

### 5.2 Sidebar modo `admin` (`platform-admin`)

| Grupo | Item | Rota | Ativo | Fase |
|---|---|---|---|---|
| — | Banner "Painel interno · Equipe AssinaVelox" | — | — | 1 |
| Operação | Clientes | `admin.organizations.index` | `admin.organizations.*` | 1 |
| Operação | Planos e faturamento | `admin.billing.index` | — | 2 (desabilitado, tooltip) |
| Operação | Usuários da plataforma | `admin.users.index` | — | 2 |
| Operação | Logs e auditoria | `admin.audit.index` | — | 2 |
| Sistema | Configurações globais | `admin.settings.index` | — | 2 |
| Sistema | Voltar ao app | `dashboard` | — | 1 |

### 5.3 Regras
- `mode` é derivado da rota: `route().current('admin.*') ? 'admin' : 'client'`. Não existe toggle explícito; a entrada é o item "Painel interno" do menu da conta (visível só se `auth.user.is_platform_admin`) e a saída é "Voltar ao app".
- Estado ativo: `SidebarMenuButton isActive` = `route().current(pattern)`; sem sub-itens na Fase 1.
- Switcher: `DropdownMenu` listando `organizations` (avatar iniciais, nome, plano); item selecionado com check; rodapé "Criar nova organização" (Q5). Seleção → `router.post(route('organizations.switch', id))`. Em rotas admin o switcher é substituído pelo banner.
- Menu da conta (rodapé): "Perfil e preferências" → `profile.edit`; "Plano e cobrança" → `billing.index` (oculto para `member`); "Painel interno" → `admin.organizations.index` (se platform admin); separador; "Sair" → `router.post(route('logout'))`.
- Topbar: breadcrumb = `organization.name` (link `dashboard`) › página atual (› subitem, ex.: pasta › título do envelope); busca ⌘K → `CommandDialog` consultando `search.index` (debounce 250 ms; resultados agrupados "Documentos" e "Signatários"; Enter abre `envelopes.show`); ícone de ajuda → link externo `https://ajuda.assinavelox.com.br` (o mock aponta para a API; substituído); sino → popover com `notifications.index` + "Marcar todas como lidas"; ponto vermelho quando `counts.unread_notifications > 0`.
- Sidebar colapsável: estado em `localStorage` (`sidebar_state`, como o shadcn `SidebarProvider` via cookie). Em < 1024 px vira `Sheet`.
- Guardas de rota no front: links para rotas `org.admin` são omitidos quando `organization.permissions` não permite (o backend continua sendo a autoridade — 403 → página `errors/403.tsx`).

---

## 6. Rótulos em português × valores de enum

### 6.1 Envelope (`EnvelopeStatus`)

| Rótulo no mock | Enum | Regra de exibição | Cores (bg/fg/border) |
|---|---|---|---|
| Rascunho | `draft`, `preparing`, `ready` | Tab "Rascunhos". `preparing` mostra sufixo "· processando"; `ready` sem sufixo. | `#f1f4f9 / #47536b / #e6eaf2` |
| Aguardando | `in_progress` com `signed_count = 0` | Sufixo "· 0 de N" no detalhe. Tab "Aguardando". Dashboard "N documentos aguardam assinatura" conta **todos** os `in_progress`. | `#fff4e0 / #9a5b00 / #f5dfae` |
| Em andamento | `in_progress` com `signed_count > 0`; `finalizing` | `finalizing` mostra "Em andamento · finalizando". | `#e8f0fd / #1257c9 / #c9dbf7` |
| Assinado / Concluído(s) | `completed` | Badge "Assinado"; tab "Concluídos". | `#e6f7ee / #12784a / #bfe9d1` |
| Recusado | `refused` | | `#fdeaea / #b42323 / #f5c2c2` |
| Expirado | `expired` | | `#f1f4f9 / #6b7891 / #e6eaf2` |
| Cancelado (não aparece no mock) | `canceled` | Incluído na tab "Recusados / expirados". | `#f1f4f9 / #6b7891 / #e6eaf2` |

### 6.2 Recipient (`RecipientStatus`)

| Rótulo no mock | Enum | Nota (`note`) típica |
|---|---|---|
| Pendente / Aguardando / "Aguardando assinatura" (campo no PDF) | `waiting` (sequencial, ainda não notificado → nota "Aguarda a vez · N.º na ordem"), `sent` ("Enviado · não visualizou"), `viewed` ("Visualizou em {dt}" · "reenviado às HH:mm") | Cor âmbar |
| Assinado / "✓ Assinado em …" | `signed` | "IP · dispositivo" |
| Recusado | `refused` | "Motivo: “…”" |
| Expirado | `expired` | "Prazo encerrado em {dt}" |
| — | `canceled` | "Documento cancelado" — exibido como "Cancelado" (cinza) |
| "assina depois de você" (página pública) | `waiting` do outro recipient | |

### 6.3 Processamento do documento (`DocumentProcessingStatus`)

| Rótulo | Enum |
|---|---|
| "enviado agora" (linha do arquivo, imediatamente após upload) | `uploaded` |
| "Convertendo…" / "Processando…" (não no mock; necessário) | `processing` (envelope `preparing`) |
| "Pronto" | `ready` (envelope `ready` se recipients+campos completos, senão `draft`) |
| "Falha ao processar" (não no mock) | `failed` (envelope permanece `draft`; arquivo pode ser removido) |

### 6.4 Usuário / membership / convite

| Rótulo | Enum |
|---|---|
| Ativo | `MembershipStatus.active` |
| Inativo | `MembershipStatus.suspended` |
| Convite pendente | `InvitationStatus.pending` (linha vem de `invitations`, não de `memberships`) |
| "Convite enviado 02 set" | `invitations.created_at` (ou `last_sent_at`) |
| 2FA "Ativa" / "Inativa" / "—" | `users.two_factor_confirmed_at !== null` / `=== null` / convite (sem usuário) |
| Administrador | `MembershipRole.admin` |
| Proprietário (não no mock) | `MembershipRole.owner` — exibido com badge "Proprietário" |
| Operador | `MembershipRole.member` |
| Gerente, Somente leitura | **Fase 2** (roles customizadas); na Fase 1 não existem |
| "(você)" | `is_me` |

### 6.5 Assinatura (plano), pagamento, plano

| Rótulo | Enum |
|---|---|
| Ativo (plano) | `SubscriptionStatus.active` |
| Trial / Em trial | `SubscriptionStatus.trialing` |
| Inadimplente | `SubscriptionStatus.past_due` |
| Cancelado / Canceladas | `SubscriptionStatus.canceled` |
| "Cancelar renovação" ativo → badge "Cancela em {dt}" | `active` + `cancel_at_period_end = true` |
| Paga | `PaymentStatus.approved` |
| Pendente (não no mock) | `PaymentStatus.pending` |
| Falhou / Recusado (não no mock) | `PaymentStatus.rejected` |
| Reembolsada (não no mock) | `PaymentStatus.refunded` |
| Cancelada (não no mock) | `PaymentStatus.cancelled` |
| Grátis | `PlanKey.free` |
| Profissional | `PlanKey.professional` |
| Empresarial | `PlanKey.enterprise` |

### 6.6 Outros enums visíveis

| Rótulo | Enum |
|---|---|
| Sequencial / "ordem sequencial" | `RecipientRoutingMode.sequential` |
| Todos ao mesmo tempo / "todos ao mesmo tempo" | `RecipientRoutingMode.parallel` |
| E-mail (canal) | `DeliveryChannel.email` — WhatsApp/SMS/Plataforma → Fase 2 |
| Token e-mail | `AuthMethod.email_otp` — Token SMS, Selfie, Certificado ICP-Brasil, "Assinatura desenhada" (no mock listado como auth; aqui é `SignatureMethod`) → Fase 2 |
| Desenhar / Digitar / (Enviar imagem) | `SignatureMethod.draw` / `type` / `upload`; "Certificado" → Fase 2 |
| Assinatura / Rubrica / Nome completo / Data / Texto livre / Caixa de seleção | `FieldType.signature` / `initials` / `name` / `date` / `text` / `checkbox`; CPF e Carimbo → Fase 2 |
| Evento de trilha "info / ok / warn" | `events[].kind` derivado do tipo: `*.signed`, `*.completed`, `otp_verified` → `ok`; `refused`, `expired`, `canceled`, `otp_failed`, `processing_failed` → `warn`; demais → `info` |
| Notificação "E-mail" / "No app" / "WhatsApp" | `NotificationChannel.mail` / `database` / Fase 2 |

---

## 7. Questões abertas e premissas recomendadas

| # | Ambiguidade nos mocks | Premissa recomendada |
|---|---|---|
| Q1 | "Aguardando" vs "Em andamento" são usados de forma inconsistente (AV-00148 com 1 de 2 assinados aparece como "Aguardando"; vistoria 2 de 3 como "Em andamento"). | Regra única: `in_progress` + `signed_count = 0` → "Aguardando"; `in_progress` + `signed_count > 0` ou `finalizing` → "Em andamento". Ambas as abas filtram sobre `in_progress`. Se o produto preferir, colapsar em uma única aba "Em andamento" é uma mudança de 1 linha. |
| Q2 | Espec. pede roles owner/admin/member; mock mostra Administrador/Gerente/Operador/Somente leitura e matriz de 8 permissões. | Fase 1 = 3 roles fixos; a matriz é renderizada a partir de `OrgPermissions` para owner/admin/member. Gerente/Somente leitura e "Nova função personalizada" viram Fase 2 (tabela `roles` + `role_permissions`). |
| Q3 | Cadastro pede "Empresa" e "CNPJ (opcional)"; freelancers (Studio Lumen) aparecem como clientes. | `document_number` aceita CNPJ **ou** CPF (validação por tamanho); `organization_name` obrigatório (para pessoa física, sugerir o próprio nome). |
| Q4 | Cadastro cria uma organização automaticamente ou o usuário pode ficar sem org? | Cadastro sempre cria `Organization` + membership `owner` + `Subscription(free, trialing? )`. Usuário convidado que se cadastra via `/convites/{token}` **não** cria org. |
| Q5 | Switcher de organização existe, mas não há tela de "criar organização". | Fase 1: item "Criar nova organização" no switcher abrindo `Dialog` simples (`name`) → `organizations.store`; cria plano free. Pode ser escondido por flag se o produto não quiser multi-org no MVP. |
| Q6 | Dois recipients podem ter o mesmo e-mail (ex.: mesma pessoa em dois papéis)? | Não na Fase 1 (unique por envelope) — simplifica OTP/sessão. |
| Q7 | Visibilidade: `member` vê todos os envelopes da org ou só os próprios? A matriz do mock diz "Ver todos os documentos da conta" só para Admin/Gerente. | `member` vê apenas envelopes onde `creator_id = me`; owner/admin veem todos. Contagens da sidebar/dashboard respeitam esse escopo. |
| Q8 | Trial: mocks do admin mostram status "Trial", mas o fluxo de cadastro diz "grátis, sem cartão". | Plano `free` permanente (limites baixos: 5 docs/mês, 1 assento) e status `trialing` **não** é usado na Fase 1 para novos cadastros; `trialing` fica no enum para trials do plano pago concedidos manualmente (Fase 2). Tab "Em trial" do admin existe, mas tende a zero. |
| Q9 | Campo "Data" é editável pelo signatário ou carimbado pelo servidor? | Carimbado pelo servidor na hora da assinatura (timezone do remetente), somente leitura na UI (mostra a data prevista). Evita fraude de data. |
| Q10 | Rubrica "em todas as páginas": aplicada automaticamente em posição padrão? | Sim: quando `initials_on_all_pages`, gera-se um campo `initials` virtual (`page = 'all'`) por recipient, estampado no rodapé direito (x 0.86, y 0.94, w 0.10, h 0.04) de todas as páginas, exceto onde já exista `initials` posicionado manualmente. |
| Q11 | "Lembrar"/"Reenviar"/"Lembrar pendentes" no mock são lembretes automáticos (Fase 2) ou reenvio manual? | Fase 1 = reenvio manual do convite (mesmo token), com throttle de 10 min por recipient. Lembretes automáticos (switch "a cada 2 dias") ficam desabilitados com badge Fase 2. |
| Q12 | Ação em lote "Baixar" para vários documentos. | Fase 2 (ZIP assíncrono). Na Fase 1 o botão só aparece com exatamente 1 selecionado (baixa o PDF assinado ou original). |
| Q13 | Página de verificação: exibir título do documento e nomes completos dos signatários? | Exibir título (o remetente controla o nome do arquivo) e nomes mascarados ("Maria A. S."). Opção por org `verification_shows_full_names` fica como extension point (default false). |
| Q14 | Verificação por upload do arquivo (comparar hash) e captcha na página pública. | Incluir "Conferir meu arquivo" (POST local, arquivo ≤ 25 MB, hash calculado em memória, nada é armazenado) — baixo custo. Captcha só se houver abuso (throttle por IP é suficiente no MVP). |
| Q15 | "Acessar como" (impersonation) no painel interno. | Fase 2: exige trilha (`impersonations`), banner persistente, bloqueio de ações financeiras e consentimento em Termos. Fase 1: botão oculto; `admin.organizations.show` somente leitura cobre o suporte. |
| Q16 | "Nova conta" no admin (criar org para cliente). | Fase 2. Clientes se cadastram sozinhos na Fase 1. |
| Q17 | Botão "Importar" em Documentos e "vários arquivos são unidos em um só envelope" no dropzone. | Removidos/ocultos: um arquivo por envelope (Fase 1). Texto do dropzone: "PDF, DOCX, PNG ou JPG · até 25 MB". Multi-documento = Fase 2 (`documents.position`). |
| Q18 | Conversão DOCX→PDF: qual motor? | LibreOffice headless em container dedicado, via job `ConvertDocumentToPdf` (fila `conversions`), timeout 120 s; imagens → PDF via Imagick. Falha → `failed` com mensagem amigável. |
| Q19 | Certificado A1 da empresa: um por plataforma (AssinaVelox) ou por organização? | Fase 1: **um certificado da plataforma** (AssinaVelox como carimbador — assinatura eletrônica avançada com evidências, Lei 14.063/2020 art. 4º II). Certificado próprio por organização = extension point (`organizations.certificate_id`, Fase 2). Isso deve constar nos Termos. |
| Q20 | Mercado Pago: assinatura recorrente (Preapproval) ou pagamento avulso por ciclo via Checkout Pro? | Espec. diz Checkout Pro → pagamento avulso por ciclo (mensal/anual). `subscriptions.current_period_end` é estendido a cada `approved`; 3 dias após vencer sem pagamento → `past_due` (bloqueia `envelopes.send`, não bloqueia leitura/download); 15 dias → `canceled` e volta a `free`. Migrar para Preapproval é extension point (`mp_preapproval_id` já reservado). |
| Q21 | Cartão salvo / "Alterar forma de pagamento" / NF-e. | Não há cartão salvo no Checkout Pro; o card mostra apenas o método do último pagamento. NF-e: Fase 2 (integração fiscal). "PDF" = recibo interno. |
| Q22 | Expiração do envelope: hora exata e fuso. | `expires_at = sent_at + expires_in_days` às 23:59:59 no fuso da organização (`organizations.timezone`, default America/Sao_Paulo). Job `ExpireEnvelopes` a cada 15 min. Aviso "expira em 48 h" via notificação. |
| Q23 | Retenção e armazenamento: onde e por quanto tempo ficam PDFs e imagens de assinatura? | S3-compatível privado (`private` disk), URLs sempre via controller autenticado (nunca temporárias públicas). Retenção indefinida enquanto a org existir; exclusão da org apaga após 30 dias. `storage.limit_bytes` do plano conta originais + finais + evidências. |
| Q24 | Idioma do signatário (select "Português (Brasil)"). | Removido na Fase 1 (só pt-BR). Extension point `recipients.locale`. |
| Q25 | Busca ⌘K aparece só no Dashboard no mock. | Global na topbar de todas as telas do app (cliente). No admin, busca a lista de clientes. |
| Q26 | Notificações in-app (sino) não estão especificadas. | Fase 1 mínima com `database` notifications para os eventos da matriz; sem página dedicada (popover). |
| Q27 | Sessão "encerrar após 12 h inativas" é por org, mas a sessão Laravel é por usuário. | Implementar como middleware que compara `last_activity` com o menor `session_idle_hours` entre as orgs do usuário que tenham a política ativa. |
| Q28 | `public_code` (AV-00148) é global ou por organização? | Global na plataforma (sequência única, `envelopes.public_code` unique). Evita colisões em suporte e no admin. |
| Q29 | Home institucional, Termos, Privacidade, Contato não têm mocks. | Páginas estáticas mínimas com `AuthLayout`-like; conteúdo jurídico fornecido pelo produto. `/contato` → mailto na Fase 1. |
| Q30 | Dark mode. | Não desenhado; Fase 1 apenas tema claro (tokens já preparados para futura variante). |
