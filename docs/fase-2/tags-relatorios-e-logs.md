# Fase 2 — Etiquetas, relatórios, logs administrativos, usuários da plataforma e "acessar como"

> Área B-ORG (roadmap §2.14, segunda metade). Identificadores em inglês; prosa em português.
> Precedência: `docs/design/RECONCILIACAO.md` → `docs/arquitetura.md` → `docs/design/ROUTES_AND_PAGES.md` → `docs/roadmap.md` → este arquivo.
> Complementa `docs/fase-2/permissoes-e-times.md` (B-PERM), de onde vêm as permissões e a regra de visibilidade.

## 1. Resumo

| Item                                        | Flag (padrão `false`)            | Quem pode                                                                               | Onde                                                        |
| ------------------------------------------- | -------------------------------- | --------------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| Etiquetas                                   | `tags` (config **e** plano)      | ver: qualquer membro · gerir: `manage_tags` · aplicar: quem pode **editar** o documento | Configurações › Etiquetas; Documentos (filtro, chips, lote) |
| Relatórios                                  | `reports` (config **e** plano)   | `view_reports`; CSV exige também `export_data`                                          | `/relatorios`                                               |
| Registro de atividades (log da organização) | `audit_log` (config **e** plano) | `view_audit_log`                                                                        | Configurações › Registro de atividades                      |
| Usuários da plataforma                      | `admin_users` (config)           | platform admin                                                                          | Painel interno › Usuários                                   |
| Logs e auditoria (plataforma)               | `admin_audit` (config)           | platform admin                                                                          | Painel interno › Logs e auditoria                           |
| "Acessar como"                              | `impersonation` (config)         | platform admin, com senha e motivo                                                      | Painel interno › Cliente › Membros                          |

Com **todas as flags desligadas** a Fase 1 não muda: as páginas novas (GET) mostram o estado "Fase 2", as ações (POST/PATCH/DELETE) respondem 403 (ou 404 no painel interno), `admin.users.index` e `admin.audit.index` continuam o `admin/placeholder`, e os dois middlewares novos não fazem nada sem bloqueio nem impersonation na sessão.

### 1.1 Como uma flag liga

`App\Services\AdminLog\ToolFlags`:

- **Flags da organização** (`tags`, `reports`, `audit_log`): valem quando `config('assinavelox.features.{flag}') === true` **e** `plans.features.{flag} === true` no plano vigente — mesma regra de `DomainFeatures` (B-DOM).
- **Flags da plataforma** (`admin_users`, `admin_audit`, `impersonation`): só `config('assinavelox.features.{flag}')` — o painel interno não tem organização.

A flag liga a interface e as rotas; quem decide o acesso continua sendo a permissão (Policies/`Membership::hasPermission`) e o middleware `platform-admin`.

## 2. Modelo de dados (migrations `2026_09_11_1104xx`, só aditivas, compatíveis com MySQL)

| Migration                                         | Tabela/colunas                                                                                                                                                    | Observações                                                                                                                                                                                                                                                       |
| ------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `110401_create_tags_tables`                       | `tags` (ulid, organization_id, name, `name_key`, color, created_by_user_id) · `envelope_tag` (organization_id, envelope_id, tag_id, added_by_user_id, created_at) | `UNIQUE(organization_id, name_key)` — nome único por organização sem diferenciar maiúsculas, igual em MySQL e SQLite. `envelope_tag.organization_id` para filtrar a organização sem join. Cores: lista fechada (`TagColor`: blue, green, amber, red, gray, navy). |
| `110402_create_platform_audit_events_table`       | `platform_audit_events` (ulid, actor_user_id, action, target_type/id, organization_id, payload, ip, ua, correlation_id, occurred_at)                              | **Append-only** (model recusa update/delete; em produção só SELECT/INSERT para o usuário da aplicação). Separada de `audit_events` porque `audit_events.organization_id` é obrigatório.                                                                           |
| `110403_create_impersonations_table`              | `impersonations` (ulid, admin_user_id, organization_id, target_user_id, reason, started_at, expires_at, ended_at, end_reason, pages_viewed, ip, ua)               | `end_reason` ∈ `stopped` \| `expired` \| `logout` \| `invalid`.                                                                                                                                                                                                   |
| `110404_add_account_block_columns_to_users_table` | `users.blocked_at`, `blocked_reason`, `blocked_by_user_id`, `last_seen_at`                                                                                        | Colunas nulas: conta sem `blocked_at` = ativa (Fase 1).                                                                                                                                                                                                           |

Nenhuma migration altera ou remove dado existente.

## 3. Etiquetas

- **Cadastro** (`TagController`, `TagManager`): criar, renomear/recolorir e excluir exigem a flag e `manage_tags`. Nome limpo (espaços colapsados, sem caracteres de controle), até 40 caracteres, no máximo 100 etiquetas por conta. Excluir remove as atribuições; os documentos não mudam.
- **Aplicar/remover** (`EnvelopeTagController`, `TagAssignment`): não exige `manage_tags` — etiquetar é editar o documento. Só entram envelopes **visíveis** (`EnvelopeVisibility`); entre eles, só os que passam em `EnvelopePolicy::update`; os demais são contados como "ignorados". Etiqueta de outra organização falha na validação; envelope de outra organização simplesmente não é encontrado.
- **Lista de documentos** (`EnvelopeTagIndex`): filtro por etiqueta (só estreita o que a visibilidade já liberou), chips por linha (uma consulta por página) e opções da ação em lote "Adicionar etiqueta".
- **Trilha:** `tag.created`, `tag.updated`, `tag.deleted`, `tags.applied`, `tags.removed` — todos na trilha da **organização** (`envelope_id` nulo), para não alterar a trilha do documento nem a página de evidências. Payload: ULID e nome da etiqueta, lista de ULIDs de envelopes.

### 3.1 Integração pendente — `EnvelopeController::index` (fora da área B-ORG)

A página `envelopes/index.tsx` já lê `tagging` e `filters.tag`; sem essas props, nada de etiqueta aparece. A integração são três pontos em `index()`:

```php
$tagging = EnvelopeTagIndex::for($membership, $request->query('tag'));

$base = fn (): Builder => $tagging->constrain(
    $this->applyFilters(EnvelopeVisibility::envelopes($membership), $filters, $folder, withStatus: false)
);

// props:
'filters' => [...$filters, 'tag' => $tagging->tagUlid()],
'tagging' => $tagging->props($envelopes->getCollection()),
```

Com a flag desligada, `constrain()` não altera a consulta e `props()` devolve `enabled: false`.

## 4. Relatórios

`ReportController` + `EnvelopeReport` + `ReportFilters`.

- **Regra de ouro:** toda agregação parte de `EnvelopeVisibility::envelopes($membership)` — a mesma consulta da lista de documentos. Um envelope que a pessoa não pode abrir nunca entra em contagem, média, agrupamento por usuário/time, série diária nem CSV. Os filtros só estreitam; filtrar por "Criado por" = outra pessoa não fura a regra.
- **Período:** `from`/`to` em dias do fuso da organização, inclusivos; padrão últimos 30 dias; máximo 366 dias.
- **Filtros:** status atual, pasta, time (criador participa do time), criador, etiqueta. ULID de outra organização → relatório vazio. As opções mostradas vêm do que a pessoa já vê (pastas e criadores dos envelopes visíveis).
- **Indicadores:** enviados (`sent_at` no período) · concluídos, recusados, expirados, cancelados (o respectivo `*_at` no período) · pendentes (enviados no período ainda em andamento) · **tempo médio até concluir** (`completed_at − sent_at` dos concluídos no período) · **taxa de conclusão** (dos enviados no período, quantos já estão concluídos) · série diária · por usuário (criador) · por time (soma dos criadores de cada time; quem está em dois times conta nos dois; "Sem time" para os demais).
- **Uso do plano:** só para quem tem `manage_billing` (o consumo é da organização inteira); para os demais a seção não existe.
- **CSV** (`reports.export`, `throttle:export`): exige `view_reports` + `export_data`; linhas = envelopes com algum marco no período; colunas sem dado de signatário; toda célula passa por `App\Support\Csv::row` (proteção contra injeção de fórmula, CWE-1236). Cada exportação grava `report.exported` com os filtros (só ULIDs e datas).
- O cálculo é feito em PHP sobre as colunas mínimas, em lotes de 500 — idêntico em MySQL e SQLite.

## 5. Logs administrativos

### 5.1 Registro de atividades da organização (`settings.audit`)

`AuditLogController` + `OrganizationAuditLog` + `AdminEventCatalog`. Lê `audit_events` (somente SELECT), sempre `organization_id` da organização corrente e `envelope_id` nulo, e **só** os tipos do catálogo:

| Categoria                | Eventos                                                                                                               |
| ------------------------ | --------------------------------------------------------------------------------------------------------------------- |
| Usuários e funções       | `membership.role_changed`, `role.created/updated/deleted`                                                             |
| Times e pastas           | `team.created/updated/deleted`, `folder_access.updated`                                                               |
| Plano e cobrança         | `payment.*`, `subscription.*`                                                                                         |
| Modelos                  | `template.created/version_created/updated/duplicated/archived/restored` (`template.used` fica na trilha do documento) |
| Etiquetas                | `tag.*`, `tags.applied`, `tags.removed`                                                                               |
| Relatórios e exportações | `report.exported`                                                                                                     |
| Acessos do suporte       | `impersonation.started/ended/page_viewed`                                                                             |

**Sem dados de signatário:** eventos do ciclo do envelope (convite, código, aceite, recusa, download…) nunca entram — o lugar deles é a trilha do documento, com a autorização do documento. A tela não recebe o payload bruto: só as chaves de `AdminEventCatalog::visiblePayloadKeys()` (nome, plano, permissões → contagem, documentos → contagem, motivo, página…), com IP mascarado (`187.10.x.x`). Filtros: categoria, autor (membros da organização), período.

Categorias ainda sem evento emitido ("Configurações", "Exclusões", entrada/saída de membros): as telas da Fase 1 não gravam trilha para essas ações; quando passarem a gravar, basta acrescentar os tipos ao catálogo.

### 5.2 Painel interno

- **Usuários da plataforma** (`Admin\UserController`): busca por nome/e-mail, filtro (ativas, bloqueadas, equipe), organizações e função de cada conta, 2FA, último acesso (`users.last_seen_at`, gravado no máximo a cada 5 min pelo middleware `EnsureAccountNotBlocked` com a flag ligada; complementado por `sessions.last_activity` no driver `database`).
- **Bloqueio de conta** (`AccountBlocking`): `password.confirm` + motivo (5–500). Grava autor/motivo, derruba as sessões (`sessions`, driver database), troca o `remember_token`, encerra impersonations que tinham a conta como alvo e registra `user.blocked` / `user.unblocked`. Nunca bloqueia a si mesmo nem outro platform admin. O efeito vem de `EnsureAccountNotBlocked` (grupo `web`): conta bloqueada é deslogada antes e depois da rota — o que também desfaz um login recém-feito (senha, 2FA, passkey).
- **Logs e auditoria** (`Admin\AuditController`): lista `platform_audit_events` com filtros (ação, autor, período). Nenhuma rota faz UPDATE/DELETE.
- O platform admin continua **sem acesso ao conteúdo de documentos** (Termos §4.5).

## 6. "Acessar como" (impersonation — ROUTES Q15)

`Admin\ImpersonationController`, `ImpersonationManager`, `ReadOnlyRoutes`, middleware `EnforceImpersonationReadOnly`.

**Início** (`POST /admin/clientes/{organization}/acessar-como`, `admin.organizations.impersonate`, `throttle:6,1`):

- platform admin + flag `impersonation`;
- **senha digitada na hora** (regra `current_password`, mais forte que a janela de `password.confirm`) e **motivo** (10–500);
- alvo com membership **ativa** na organização, que **não** é platform admin, não está bloqueado e não é o próprio admin;
- a guarda de somente leitura precisa estar instalada no grupo `web` — sem ela o início falha fechado (503).

A sessão troca para o alvo (sem herdar senha confirmada nem organização do admin), guarda `session('impersonation') = {id, admin_id}` e expira em **30 minutos**.

**Somente leitura — lista de permitidos** (`ReadOnlyRoutes::ALLOWED_GET`): dashboard, busca, notificações, lista e detalhe de documentos, assinaturas, modelos, integrações (visão geral), usuários, configurações gerais/assinatura/notificações, etiquetas, registro de atividades e relatórios. **Tudo o mais é 403**, inclusive: qualquer método que não seja GET/HEAD; GETs que alteram estado (`envelopes.create`); conteúdo de documento (preview, páginas, downloads, evidências); exportações CSV; cobrança, planos e recibos; chaves de API; perfil, senha, 2FA, códigos de recuperação e passkeys. Por ser lista de permitidos, qualquer rota nova nasce bloqueada durante a impersonation. `POST /logout` encerra a sessão de suporte por completo; `POST /admin/acessar-como/encerrar` (`admin.impersonation.stop`, fora do grupo platform-admin) devolve o login ao admin.

**Validade a cada requisição:** registro encerrado, usuário trocado, admin rebaixado ou bloqueado → encerra e desloga; expirado → encerra, devolve o login ao admin e o leva à página do cliente.

**Trilha:** na organização alvo (`audit_events`, ator = o admin, `payload.impersonation` = ULID da sessão): `impersonation.started` (com o motivo e o nome do usuário acessado), `impersonation.page_viewed` (nome da rota e caminho — nunca a query string, que pode conter termos de busca; pré-carregamentos do Inertia não contam) e `impersonation.ended` (motivo do fim, páginas visitadas). Na plataforma (`platform_audit_events`): `impersonation.started` e `impersonation.ended`. A organização vê os acessos em Configurações › Registro de atividades › "Acessos do suporte".

**Banner:** a prop `impersonation` é compartilhada pelo próprio middleware; `ImpersonationBanner` (montado em `layouts/app-layout.tsx`) mostra "Você está acessando como … · Encerrar" com o tempo restante.

**Termos de Uso — consentimento obrigatório antes de ligar a flag.** Hoje `docs/juridico/termos-de-uso.md` §4.5 diz que a equipe da Operadora acessa um painel **somente leitura** de dados de clientes, planos e pagamentos, sem acesso ao conteúdo dos documentos. O "acessar como" mostra à equipe as telas da conta como o usuário as vê (títulos de documentos, nomes e e-mails de signatários na lista, membros, configurações). Minuta de cláusula a incluir (revisão jurídica pendente):

> 4.6. **Acesso de suporte.** Para atender a um chamado, colaborador autorizado da Operadora pode acessar a Plataforma na visão de um Usuário da Organização, em modo somente leitura, por até 30 minutos, mediante registro do motivo. Nesse modo não é possível alterar dados, enviar ou cancelar documentos, baixar arquivos de documentos, acessar cobrança ou credenciais. O início, o fim e cada página visitada ficam registrados e visíveis à Organização no registro de atividades.

## 7. Rotas

| Método | URI                                           | Nome                              | Proteção                                                             |
| ------ | --------------------------------------------- | --------------------------------- | -------------------------------------------------------------------- |
| GET    | `/configuracoes/etiquetas`                    | `settings.tags`                   | app · flag `tags` (desligada: estado Fase 2)                         |
| POST   | `/etiquetas`                                  | `tags.store`                      | app · `tags` · `manage_tags`                                         |
| PATCH  | `/etiquetas/{tag}`                            | `tags.update`                     | idem                                                                 |
| DELETE | `/etiquetas/{tag}`                            | `tags.destroy`                    | idem                                                                 |
| POST   | `/documentos/etiquetas`                       | `envelopes.tags.apply`            | app · `tags` · edição por envelope                                   |
| DELETE | `/documentos/{envelope}/etiquetas/{tag}`      | `envelopes.tags.detach`           | idem                                                                 |
| GET    | `/relatorios`                                 | `reports.index`                   | app · `reports` · `view_reports`                                     |
| GET    | `/relatorios/exportar`                        | `reports.export`                  | app · `reports` · `view_reports` + `export_data` · `throttle:export` |
| GET    | `/configuracoes/registro-de-atividades`       | `settings.audit`                  | app · `audit_log` · `view_audit_log`                                 |
| GET    | `/admin/usuarios`                             | `admin.users.index`               | platform-admin · `admin_users` (desligada: placeholder)              |
| POST   | `/admin/usuarios/{user}/bloquear`             | `admin.users.block`               | platform-admin · `admin_users` · `password.confirm`                  |
| POST   | `/admin/usuarios/{user}/desbloquear`          | `admin.users.unblock`             | idem                                                                 |
| GET    | `/admin/auditoria`                            | `admin.audit.index`               | platform-admin · `admin_audit` (desligada: placeholder)              |
| POST   | `/admin/clientes/{organization}/acessar-como` | `admin.organizations.impersonate` | platform-admin · `impersonation` · senha + motivo · `throttle:6,1`   |
| POST   | `/admin/acessar-como/encerrar`                | `admin.impersonation.stop`        | `auth` (encerra só a sessão corrente)                                |

Nenhuma rota nova usa `org.role`: a autorização é por permissão (funciona com funções personalizadas).

## 8. Props

- `reports/index`: `enabled`, `filters`, `options{folders,teams,creators,statuses,tags}`, `report{totals,series,by_user,by_team}`, `plan_usage|null`, `scope.all_envelopes`, `can.export`.
- `settings/tags`: `enabled`, `tags[]{id,name,color,envelopes_count,created_at}`, `colors[]`, `limits`, `can.manage`.
- `settings/audit`: `enabled`, `filters`, `categories[]`, `actors[]`, `events` (Paginated; `details[]` em vez de payload).
- `admin/users/index`: `filters`, `summary`, `users` (Paginated).
- `admin/audit/index`: `filters`, `actions[]`, `actors[]`, `events` (Paginated).
- `admin/organizations/show`: + `impersonation_options{enabled, ttl_minutes, eligible[]}` (integração I-2A: era `impersonation`, que colidia com a prop compartilhada abaixo e acendia o banner com "encerra em NaN min").
- `envelopes/index` (após a integração do §3.1): `tagging{enabled, can_manage, available[], by_envelope}` e `filters.tag`.
- Compartilhada durante a impersonation: `impersonation{id, admin_name, target_name, organization_name, expires_at}`.

## 9. Trilha — tipos novos em `AuditEventType`

| Tipo                                          | Rótulo                                       | Tom  |
| --------------------------------------------- | -------------------------------------------- | ---- |
| `tag.created` / `tag.updated` / `tag.deleted` | Etiqueta criada / alterada / excluída        | info |
| `tags.applied` / `tags.removed`               | Etiqueta aplicada a / removida de documentos | info |
| `report.exported`                             | Relatório exportado                          | info |
| `impersonation.started`                       | Acesso de suporte iniciado                   | warn |
| `impersonation.ended`                         | Acesso de suporte encerrado                  | info |
| `impersonation.page_viewed`                   | Página visitada pelo suporte                 | info |

Ações da plataforma (`platform_audit_events.action`, `App\Services\AdminLog\PlatformAction`): `user.blocked`, `user.unblocked`, `impersonation.started`, `impersonation.ended`, `impersonation.denied` (reservado).

## 10. Integrações pendentes fora da área B-ORG

1. **`EnvelopeController::index`** — filtro por etiqueta e chips (§3.1).
2. **`HandleInertiaRequests::features()`** — acrescentar `tags`, `reports`, `audit_log` a partir de `ToolFlags::forOrganization()` (o `SharedPropsTest` compara a prop por igualdade). Até lá as páginas recebem `enabled` próprio.
3. **Sidebar** (`components/app-sidebar.tsx`) — item "Relatórios" (visível com `features.reports` e `view_reports`); no painel interno, tirar `disabled/phase2` de "Usuários da plataforma" e "Logs e auditoria" quando `admin_users`/`admin_audit` estiverem ligadas.
4. **Rail de Configurações** (`layouts/settings/layout.tsx`) — itens "Etiquetas" e "Registro de atividades" (este só com `view_audit_log`).
5. **`EnumCatalogTest`** — a contagem fixa de `AuditEventType` (45) não inclui os tipos das Fases 2.
6. **Termos de Uso** — cláusula de acesso de suporte (§6) antes de ligar `impersonation`.
7. **`EnvelopeVisibility`/`Membership`** — nada a mudar; esta área só consome a API do B-PERM.

Edições fora da área, necessárias e mínimas: `app/Enums/AuditEventType.php` (tipos novos — regra T7), `bootstrap/app.php` (registro de `EnsureAccountNotBlocked` e `EnforceImpersonationReadOnly` no grupo `web`) e `resources/js/layouts/app-layout.tsx` (monta o banner).

## 11. Limitações conhecidas

- **Relatório síncrono.** O roadmap propõe `report_exports` em fila; aqui o CSV é transmitido na hora (`streamDownload`, lotes de 200) com o período limitado a 366 dias e `throttle:export`. Se o volume crescer, a exportação vai para fila sem mudar o contrato da tela.
- **Visita registrada antes da autorização da página.** Uma página permitida pela lista, mas negada pela Policy (ex.: documento que o alvo não vê), fica registrada como visita tentada.
- **Organização que exige 2FA e alvo sem 2FA.** O middleware `org.2fa` manda o alvo configurar o 2FA — tela bloqueada na impersonation; o suporte vê 403 e deve encerrar a sessão.
- **Último acesso.** Só é gravado com a flag `admin_users` ligada; antes disso, só o driver `database` de sessão fornece a informação. `MembershipResource.last_seen_at` (painel › Cliente) continua nulo — fora desta área.
- **Eventos que ainda não existem.** Entrada/saída de membros, alteração de configurações e pedidos de exclusão não geram trilha na Fase 1; o log administrativo não os mostra até que passem a gerar.
- **`impersonation.denied`.** Reservado; tentativas recusadas (senha errada, alvo inelegível) não são gravadas hoje — só respondem com erro de validação.

## 12. Testes (`tests/Feature/Phase2/Org`)

| Arquivo             | Cobre                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| ------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `TagsTest`          | flag desligada; CRUD com trilha e unicidade sem maiúsculas; `manage_tags`; isolamento entre organizações (binding, validação, aplicação); aplicação respeita a edição (operador e função personalizada com acesso só de leitura à pasta); `EnvelopeTagIndex` filtra dentro da visibilidade.                                                                                                                                                                                                                                                          |
| `ReportsTest`       | flag desligada; totais, tempo médio, taxa, série e por usuário; **papel restrito nunca agrega envelope invisível** (inclusive filtrando por outro criador e no CSV); função personalizada com acesso por pasta; `view_reports`/`export_data`; filtros por etiqueta, time, pasta de outra organização e período; **CSV sem injeção de fórmula** e trilha sem dado pessoal.                                                                                                                                                                            |
| `AuditLogTest`      | flag desligada; exige `view_audit_log` (operador 403; admin e função personalizada 200); só eventos administrativos da própria organização; **nenhum e-mail/nome/IP de signatário** na resposta; sem payload bruto; filtro por categoria.                                                                                                                                                                                                                                                                                                            |
| `ImpersonationTest` | flag desligada → 404; exige platform admin, senha correta e motivo; nunca impersona outro platform admin, alguém de fora da organização, conta bloqueada ou a si mesmo; **somente leitura** (POST, PATCH, DELETE, GET que cria rascunho, download de PDF, preview, evidências, exportação, cobrança, planos, checkout, chaves, perfil, códigos 2FA → 403, nada muda); trilha de início/visitas/fim na organização alvo com o admin como ator + trilha da plataforma; **expira em 30 min**; logout e admin rebaixado encerram tudo; guarda instalada. |
| `AdminPanelTest`    | placeholders com flags desligadas; `admin.users`/`admin.audit` só para platform admin; busca com organizações e 2FA; bloqueio exige senha confirmada e motivo, desloga e impede novo login, desbloqueio, trilha e filtro; não bloqueia a si mesmo nem platform admin; `platform_audit_events` append-only.                                                                                                                                                                                                                                           |
