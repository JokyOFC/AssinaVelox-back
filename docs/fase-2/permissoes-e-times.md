# Fase 2 — Permissões configuráveis, funções personalizadas, times e acesso por pasta

> Roadmap §2.14 (primeira metade). Área B-PERM. Identificadores em inglês; prosa em português.
> Complementa `docs/autorizacao-e-isolamento.md` (Fase 1), que continua valendo para tudo o que não está descrito aqui.

## 1. Resumo

- **Sem `spatie/laravel-permission`.** Tabelas próprias sobre um catálogo em código (`App\Enums\Permission`).
- **Todas as Policies decidem por permissão** (`$membership->hasPermission(Permission::X)`), nunca pelo enum `MembershipRole`.
- **Papéis de sistema** (Proprietário, Administrador, Operador) reproduzem **exatamente** o que owner/admin/member podiam na Fase 1; nada muda para organizações existentes.
- **Funções personalizadas, times e acesso por pasta** só existem com a flag `custom_roles` (**desligada por padrão**). A flag liga a interface e os cadastros; a autorização continua nas Policies.
- **Visibilidade de envelopes** num único ponto: `App\Services\Organizations\EnvelopeVisibility`.

## 2. Catálogo de permissões (`App\Enums\Permission`)

Valores estáveis (gravados em `role_permissions` e enviados ao front). "Delegável" = pode entrar numa função personalizada.

| Grupo                       | Chave                 | Rótulo (UI)                            | Proprietário | Administrador | Operador | Delegável |
| --------------------------- | --------------------- | -------------------------------------- | :----------: | :-----------: | :------: | :-------: |
| Documentos                  | `create_envelopes`    | Criar documentos                       |      ✓       |       ✓       |    ✓     |     ✓     |
| Documentos                  | `send_envelopes`      | Enviar para assinatura                 |      ✓       |       ✓       |    ✓     |     ✓     |
| Documentos                  | `view_all_envelopes`  | Ver todos os documentos da conta       |      ✓       |       ✓       |    –     |     ✓     |
| Documentos                  | `manage_any_envelope` | Editar documentos de outros usuários   |      ✓       |       ✓       |    –     |     ✓     |
| Documentos                  | `cancel_any_envelope` | Cancelar documentos de outros usuários |      ✓       |       ✓       |    –     |     ✓     |
| Pastas, modelos e etiquetas | `manage_folders`      | Gerenciar pastas (e quem acessa)       |      ✓       |       ✓       |    –     |     ✓     |
| Pastas, modelos e etiquetas | `manage_templates`    | Gerenciar modelos                      |      ✓       |       ✓       |    –     |     ✓     |
| Pastas, modelos e etiquetas | `manage_tags`         | Gerenciar etiquetas                    |      ✓       |       ✓       |    –     |     ✓     |
| Relatórios e auditoria      | `view_reports`        | Ver relatórios                         |      ✓       |       ✓       |    ✓     |     ✓     |
| Relatórios e auditoria      | `export_data`         | Exportar dados                         |      ✓       |       ✓       |    ✓     |     ✓     |
| Relatórios e auditoria      | `view_audit_log`      | Ver registro de atividades da conta    |      ✓       |       ✓       |    –     |     ✓     |
| Usuários e acesso           | `manage_members`      | Convidar e remover usuários            |      ✓       |       ✓       |    –     |     ✓     |
| Usuários e acesso           | `manage_roles`        | Gerenciar funções                      |      ✓       |       ✓       |    –     |     ✓     |
| Usuários e acesso           | `manage_teams`        | Gerenciar times                        |      ✓       |       ✓       |    –     |     ✓     |
| Usuários e acesso           | `transfer_ownership`  | Transferir propriedade                 |      ✓       |       –       |    –     |     –     |
| Conta e cobrança            | `manage_settings`     | Alterar configurações da conta         |      ✓       |       ✓       |    –     |     ✓     |
| Conta e cobrança            | `manage_billing`      | Ver plano e faturamento                |      ✓       |       ✓       |    –     |     ✓     |
| Conta e cobrança            | `manage_integrations` | Acessar API e webhooks                 |      ✓       |       ✓       |    –     |     ✓     |
| Conta e cobrança            | `delete_organization` | Excluir a conta                        |      ✓       |       –       |    –     |     –     |

Regras do catálogo:

- `Permission::systemGrants(MembershipRole)` define os papéis de sistema: owner = tudo; admin = tudo o que é delegável; member = `create_envelopes`, `send_envelopes`, `view_reports`, `export_data` (o que um member já fazia: criar/enviar/cancelar os **próprios** documentos, ver o dashboard e exportar o que vê).
- Permissões novas (ex.: das áreas de modelos, etiquetas, relatórios) entram no enum e chegam aos papéis de sistema **sem migration** (as permissões de sistema não são gravadas em banco). Funções personalizadas só as recebem quando alguém concede.
- `transfer_ownership` e `delete_organization` são exclusivas do proprietário: nunca entram numa função personalizada (validação + filtro em `Role::grantedPermissions()`).

## 3. Modelo de dados (migrations `2026_09_11_1101xx`, só aditivas)

| Tabela / coluna                                   | Conteúdo                                                                                                                                                                                                     |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `roles`                                           | `organization_id`, `ulid`, `key` (owner/admin/member nos papéis de sistema; nulo nas personalizadas), `name`, `description`, `is_system`, `created_by_user_id`. Únicos: (org, key) e (org, name).            |
| `role_permissions`                                | (`role_id`, `permission`) único. **Linhas, não JSON**: unicidade no banco, consulta indexada "quem concede X", remoção de uma chave do catálogo com um DELETE; JSON no MySQL não indexa sem colunas geradas. |
| `memberships.role_id`                             | FK `roles` (nullOnDelete). **Só aponta para função personalizada**; nulo = papel de sistema indicado por `memberships.role`. Owner é sempre owner, qualquer que seja o `role_id`.                            |
| `membership_invitations.role_id`, `folder_access` | Função personalizada e pastas oferecidas no convite; aplicadas uma vez, no aceite.                                                                                                                           |
| `teams`, `team_memberships`                       | Time por organização (nome único) e participantes (memberships).                                                                                                                                             |
| `folder_permissions`                              | `organization_id`, `folder_id`, exatamente um sujeito (`role_id` \| `team_id` \| `membership_id`), `level` ∈ `view` \| `manage`, `granted_by_user_id`. Cascata ao apagar pasta, função, time ou membership.  |

Isolamento: `Role`, `Team` e `FolderPermission` usam `BelongsToOrganization` (escopo global + binding de rota por ulid restrito à organização corrente → 404 fora dela). Ids vindos do navegador (pastas, memberships, funções) são sempre resolvidos **dentro** da organização corrente; os de outra organização são descartados ou rejeitados.

### 3.1 Migração de dados das organizações existentes

`2026_09_11_110103_create_system_roles_for_existing_organizations` cria as três linhas de sistema em `roles` para **toda** organização (inclusive em carência de exclusão), de forma idempotente. Não toca em `memberships`: `role` continua sendo a fonte do papel e `role_id` fica nulo, então as permissões efetivas são idênticas às da Fase 1. Organizações novas recebem os papéis em `CreateOrganization` (`PermissionsSystemRoles::ensureFor`, também usado como rede de segurança pela tela de usuários).

## 4. Regra de visibilidade (ponto único)

`EnvelopeVisibility::constrain(Builder, Membership)` — usada por `envelopes()`, `recipients()`, `storageUsedBytes()` e `canSee()`:

1. com `view_all_envelopes` → todos os envelopes da organização corrente;
2. sem ela → os que a pessoa **criou** **OU** os de pastas a que tem acesso: direto (`membership_id`), pela função efetiva (`role_id` — personalizada ou a linha do papel de sistema) ou por um time (`team_id`). Vale o **maior** nível.

Consumidores (todos já passavam por `EnvelopeVisibility` e herdaram a regra sem mudança): listagem e abas de Documentos, contagens por pasta e armazenamento, busca global (documentos e signatários), contadores da sidebar/topbar (`counts`), dashboard (KPIs, pendentes, recentes), exportações CSV (`dashboard.export`, `recipients.export`), lote (`envelopes.bulk`), reenvio em lote (`ResendPendingInvitations`) e resumo diário (`DailyDigest`). A policy `EnvelopePolicy::view` usa `canSee`, então nem por ULID direto se abre um documento fora do escopo.

Sem grants, um Operador continua exatamente como na Fase 1 (só os próprios).

Níveis de pasta: `view` vê, baixa e duplica; `manage` também edita, envia, move, cancela e exclui.

## 5. Como as Policies decidem

`Policies\Concerns\ResolvesMembership::allows($user, Permission, $org)` (membership **ativa** na organização alvo). Resumo:

| Policy                       | Regra                                                                                                                                                                                                                                                                                                                                                                                      |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `EnvelopePolicy`             | `view`/`download`: `canSee`. `create`: `create_envelopes`. `duplicate`: vê + `create_envelopes`. `update`/`delete`/`move`: vê E (`manage_any_envelope` OU criador com `create_envelopes` OU pasta `manage`). `send`: vê E `send_envelopes` E (criador OU `manage_any_envelope` OU pasta `manage`). `cancel`: vê E (`cancel_any_envelope` OU `send_envelopes` como criador/pasta `manage`). |
| `FolderPolicy`               | ver: qualquer membro; criar/renomear/excluir e `grantAccess`: `manage_folders`.                                                                                                                                                                                                                                                                                                            |
| `MembershipPolicy`           | `viewAny`/`manage`: `manage_members`. `update`/`updateStatus`/`delete`: `manage_members`, nunca sobre si; owner só por owner e nunca o último owner ativo; demais: **função do alvo ⊆ permissões do ator**. `manageFolders`: `manage_folders`, nunca sobre si nem sobre owner. `transferOwnership`: `transfer_ownership`.                                                                  |
| `MembershipInvitationPolicy` | `manage_members`.                                                                                                                                                                                                                                                                                                                                                                          |
| `OrganizationPolicy`         | `updateSettings`: `manage_settings`; `manageBilling`: `manage_billing`; `delete`: `delete_organization`; genérico `permission` (`$user->can('permission', [$org, Permission::X])`).                                                                                                                                                                                                        |
| `RolePolicy`                 | `viewAny`: `manage_roles` ou `manage_members`; `create`: `manage_roles`; `update`/`delete`: `manage_roles`, só personalizada e só se o ator tem todas as permissões dela; `manageFolders`: `manage_folders` (não owner); `assign`: `manage_members` + todas as permissões da função, nunca owner.                                                                                          |
| `TeamPolicy`                 | `viewAny`: `manage_teams` ou `manage_members`; `create`/`update`/`delete`: `manage_teams`; `manageFolders`: `manage_folders`.                                                                                                                                                                                                                                                              |

Com os papéis de sistema, cada regra acima dá o mesmo resultado da Fase 1 (verificado em `tests/Feature/Phase2/Permissions/SystemRolesTest.php` e pelos testes de autorização existentes, inalterados).

### 5.1 Anti-escalada

Ninguém concede o que não tem:

- criar/editar função: toda permissão marcada precisa estar no conjunto do ator (`StoreRoleRequest`/`UpdateRoleRequest`); só se edita/exclui uma função cujas permissões o ator já tem;
- atribuir função (edição de membro ou convite): o ator precisa ter todas as permissões da função (`ResolvesTargetRole`);
- acesso por pasta que alcança o próprio ator (sua função, um time de que participa) só até o nível que ele já tem (`PermissionsFolderAccess::actorCovers`); ninguém altera o próprio acesso direto; ninguém entra num time que dá acesso a pasta que ainda não tem;
- papéis de sistema não são editáveis nem removíveis; o último owner continua protegido; propriedade só por transferência.

### 5.2 Cache e efeito imediato

As permissões são resolvidas por requisição (memorizadas na instância de `Membership`, nunca em cache compartilhado). Toda mudança de função, time, acesso por pasta ou função de um membro chama `Permissions::forgetCounts($orgId)` (ou `HandleInertiaRequests::forgetCounts`) para invalidar os contadores da sidebar — o acesso cai **na próxima requisição**, sem esperar o TTL.

## 6. Flag `custom_roles`

`Permissions::customRolesEnabled($organization)`: `plans.features.custom_roles` (booleano) decide por plano; sem a chave no plano vale `config('assinavelox.features.custom_roles')` (padrão `false`).

Com a flag **desligada**: `POST/PATCH/DELETE` de funções, times e `PUT …/pastas` → 403; a tela de usuários mostra só a matriz somente leitura dos papéis de sistema; `role_id` personalizado e `folders` no convite/edição são rejeitados (422); `organization.permissions` traz exatamente as 7 chaves da Fase 1. Membros que já tenham uma função personalizada (ex.: plano rebaixado) **mantêm** as permissões dela — a flag só esconde a interface.

Com a flag **ligada**: `organization.permissions` passa a trazer todas as chaves do catálogo (as 7 antigas continuam); a tela de usuários ganha matriz editável, times e "Pastas com acesso".

## 7. Rotas novas (grupo `app`, sem `org.role`)

| Método | URI                               | Nome              | Autorização                                                                                      |
| ------ | --------------------------------- | ----------------- | ------------------------------------------------------------------------------------------------ |
| POST   | `/usuarios/funcoes`               | `roles.store`     | flag + `RolePolicy::create`                                                                      |
| PATCH  | `/usuarios/funcoes/{role}`        | `roles.update`    | flag + `RolePolicy::update`                                                                      |
| DELETE | `/usuarios/funcoes/{role}`        | `roles.destroy`   | flag + `RolePolicy::delete`; recusada enquanto houver membros ou convites pendentes com a função |
| PUT    | `/usuarios/funcoes/{role}/pastas` | `roles.folders`   | flag + `RolePolicy::manageFolders`                                                               |
| POST   | `/usuarios/times`                 | `teams.store`     | flag + `TeamPolicy::create`                                                                      |
| PATCH  | `/usuarios/times/{team}`          | `teams.update`    | flag + `TeamPolicy::update` (+ `manageFolders` se enviar pastas)                                 |
| DELETE | `/usuarios/times/{team}`          | `teams.destroy`   | flag + `TeamPolicy::delete`                                                                      |
| PUT    | `/usuarios/{membership}/pastas`   | `members.folders` | flag + `MembershipPolicy::manageFolders`                                                         |

Contratos alterados (compatíveis): `PATCH /usuarios/{membership}` e `POST /usuarios/convites` aceitam `role` (admin/member) **ou** `role_id` (ulid da função); o convite aceita `folders[] = {folder: ulid, level}`. A página `members/index` ganhou `custom_roles_enabled`, `permission_catalog`, `role_catalog`, `teams`, `can` e, por membro, `role_id`, `teams`, `folders`, `can.manage_folders` (os campos da Fase 1 continuam iguais).

### 7.1 Integração pendente fora da área B-PERM (necessária antes de ligar a flag)

1. **`EnsureMembershipRole` (`org.role:*`)**: as rotas existentes de Usuários, Configurações, Cobrança, Pastas e Integrações ainda comparam o enum. Um membro com função personalizada tem `role = member` e é barrado nelas mesmo tendo a permissão. Troca de 1 linha: `Permissions::routeAllows($membership, $allowedRoles, $request->route()?->getName())` — para papéis de sistema o resultado é idêntico (testado para toda rota com `org.role` em `SystemRolesTest`); para funções personalizadas usa o mapa rota → permissão (`Permissions::requiredForRoute`), falhando fechado.
2. **`features.custom_roles`** na prop compartilhada `features` (dono de `HandleInertiaRequests::features()`); o teste `SharedPropsTest` compara `features` por igualdade exata. Enquanto isso, a página de usuários recebe `custom_roles_enabled`.
3. **Flags de UI ainda por papel**: `EnvelopeController@index` (`can.bulk_cancel`) e `RecipientController@index` (`can.resend_pending`) usam `$membership->role->canManageMembers()`; devem usar `Permissions::has($membership, Permission::CancelAnyEnvelope)` / `Permission::ManageAnyEnvelope`. O filtro "Criado por" (`EnvelopeController::creators`) só lista o próprio usuário quando não há `view_all_envelopes`, mesmo com acesso por pasta.
4. **E-mail de convite** (`MembershipInvitationNotification`) e aviso de aceite usam `role->label()`: com função personalizada dizem "Operador". Usar `PermissionsInvitationGrants::roleLabel($invitation)` / `$membership->roleLabel()`.
5. **`tests/Unit/Models/EnumCatalogTest`** fixa a contagem de `AuditEventType` (45); os 8 eventos abaixo (e os das outras áreas) exigem atualizar a contagem.

## 8. Trilha (`AuditEventType`, `envelope_id` nulo — `App\Support\PermissionsTrail`)

| Evento                    | Rótulo                     | Payload                                                                |
| ------------------------- | -------------------------- | ---------------------------------------------------------------------- |
| `role.created`            | Função criada              | `role` (ulid), `name`, `permissions`                                   |
| `role.updated`            | Função alterada            | `role`, `name`, `added`, `removed`                                     |
| `role.deleted`            | Função excluída            | `role`, `name`                                                         |
| `membership.role_changed` | Função de usuário alterada | `membership` (id), `from`, `to` (key de sistema ou ulid)               |
| `team.created`            | Time criado                | `team`, `name`, `members_count`, `folders_count`                       |
| `team.updated`            | Time alterado              | `team`, `name`, `members_changed`, `folders_changed`                   |
| `team.deleted`            | Time excluído              | `team`, `name`                                                         |
| `folder_access.updated`   | Acesso a pastas alterado   | `subject` (role/membership), `role` ou `membership`, `folders` (ulids) |

Sem e-mails nem dados pessoais no payload. Tom (`kind`): `info`.

## 9. API de autorização para as demais áreas

```php
use App\Enums\Permission;
use App\Support\Permissions;
use App\Services\Organizations\EnvelopeVisibility;

// Numa Policy (trait ResolvesMembership):
$this->allows($user, Permission::ManageTemplates, $template->organization_id);

// Com a membership em mãos (ex.: CurrentOrganization::instance()->membership()):
$membership->hasPermission(Permission::ExportData);          // ou Permissions::has($membership, …)
Permissions::hasAny($membership, Permission::ViewReports, Permission::ExportData);
Permissions::covers($actor, [$p1, $p2]);                      // anti-escalada

// Gate genérico por organização:
$user->can('permission', [$organization, Permission::ViewAuditLog]);

// Consultas de envelopes/signatários visíveis (NUNCA filtrar created_by à mão):
EnvelopeVisibility::envelopes($membership);        // Builder<Envelope>
EnvelopeVisibility::recipients($membership);       // Builder<Recipient>
EnvelopeVisibility::constrain($query, $membership); // qualquer Builder sobre `envelopes` (ex.: dentro de whereHas)
EnvelopeVisibility::canSee($membership, $envelope);

// Flag e props:
Permissions::customRolesEnabled($organization);
Permissions::sharedMap($membership);               // organization.permissions
Permissions::forgetCounts($organizationId);        // após mudar algo que afete visibilidade
```

Para uma permissão nova: acrescentar o caso em `Permission` (label, description, group, e `systemGrants` se o member deve tê-la), usar `allows()` na Policy e, no front, ler `organization.permissions.<chave>` (presente com a flag ligada; com ela desligada, exponha `can.*` na própria página).

## 10. Front

`resources/js/pages/members/index.tsx` + `resources/js/components/permissions/*`:

- abas **Membros · Funções e permissões · Times** (Times só com a flag);
- matriz fiel ao mock "App - Usuarios": colunas por função, papéis de sistema com cadeado (somente leitura), células de funções personalizadas clicáveis (só as permissões que quem edita tem); menu por coluna: Editar, Pastas com acesso, Excluir;
- diálogo de função (nome, descrição, permissões por grupo), diálogo de time (participantes e pastas), diálogo "Pastas com acesso" (por função, time ou pessoa, com nível Visualizar/Gerenciar);
- seletor de função na linha do membro e no convite com as funções atribuíveis; "Pastas com acesso" no convite em pílulas (travado em "Todas as pastas" quando a função já vê tudo).

## 11. Testes

`tests/Feature/Phase2/Permissions/`: `SystemRolesTest` (papéis de sistema = Fase 1, criação na abertura, migração de dados idempotente, mapa compartilhado, equivalência `org.role` ↔ permissão), `FolderVisibilityTest` (lista, busca, contagens, dashboard, exportações, ULID direto, níveis, times, acesso direto, remoção imediata com cache), `EscalationTest`, `IsolationTest`, `FeatureFlagTest`, `RolesAndTeamsTest` (CRUD, trilha, convite + aceite).
