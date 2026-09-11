# Fase 2, onda A — relatório de integração (I-2A)

Integração das cinco áreas da onda A: vários documentos e papéis (B-DOM), permissões, funções e
times (B-PERM), lembretes e envio agendado (B-REM), modelos (B-TPL), etiquetas, relatórios e logs
(B-ORG) e o front do domínio (F-DOM). Data: 11/09/2026. Nenhum commit foi feito.

Todas as flags nascem **desligadas**. Com elas desligadas, o comportamento da Fase 1 não muda
(§3). A flag liga a interface; a autorização continua nas Policies.

## 1. Verificações (números reais)

| Verificação                                            | Resultado                                                                                     |
| ------------------------------------------------------ | --------------------------------------------------------------------------------------------- |
| `php artisan test --parallel --testsuite=Unit,Feature` | **1097 de 1097** passando (8 899 asserções)                                                   |
| `php artisan test --testsuite=Browser`                 | **36 passando, 3 pulados** de 39, 740 asserções, 37 s (os 3 `->skip()` já existiam na Fase 1) |
| `tools/pdftool`: `pytest -q`                           | **93 passando**                                                                               |
| `tests/Feature/EndToEnd/Phase2OndaATest.php` (novo)    | **1 teste, 116 asserções**, passando                                                          |
| `vendor/bin/phpstan analyse`                           | **0 erros**                                                                                   |
| `vendor/bin/pint --test`                               | verde                                                                                         |
| `npm run types:check`                                  | 0 erros                                                                                       |
| `npm run check` (`vp check`)                           | 227 arquivos formatados, 0 avisos em 176                                                      |
| `npm run build`                                        | OK                                                                                            |
| `php artisan wayfinder:generate --with-form`           | OK (helpers de `envelopes.schedule`, `schedule.cancel`, `reminders.update`)                   |

Linha de base antes da integração: 1096 execuções, 1090 passando, 1 falha (`EnumCatalogTest`) e
5 erros (`AllGetRoutesTest`, parâmetros das rotas de modelos). A suíte de navegador **travava**
para todos os agentes; ver §2.1.

Uma execução da suíte de navegador ficou parada no início (servidor do Playwright no ar, nenhum
teste rodando) enquanto outra suíte PHP rodava ao mesmo tempo; encerrei só a árvore do meu
processo e a execução seguinte, sozinha, passou em 37 s. A causa não foi confirmada: rode a suíte
de navegador sem outra suíte em paralelo.

Os 3 testes pulados da suíte de navegador (`PreparationTest`: dropzone e arrastar campo;
`SmokeTest`: página 404 do produto) são `->skip()` incondicionais com a causa documentada no
próprio teste desde a Fase 1 — não são regressão.

## 2. O que a integração corrigiu

### 2.1 Infraestrutura de teste

- **Suíte de navegador travando.** A causa era um `public/hot` obsoleto (apontando para
  `http://[::1]:5173`, sem Vite rodando): a aplicação inteira carregava os scripts de um servidor
  que não existia. Sem apagar o `public/hot` (instrução do proprietário), a aplicação passou a
  aceitar `VITE_HOT_FILE` (`config('assinavelox.vite_hot_file')`, aplicado em
  `AppServiceProvider::configureDefaults`) e o `phpunit.xml` aponta para um arquivo inexistente:
  os testes sempre usam o build, com ou sem `npm run dev` rodando. A suíte de navegador roda em
  ~40 s.

### 2.2 Flags e props compartilhadas

- `config/assinavelox.php` ganhou o bloco `features` (11 chaves, todas `false`, cada uma com
  `ASSINAVELOX_FEATURE_*`), `multi_document.max_documents`, `reminders.*` e `scheduled_send.*`
  (antes só existiam como padrão dentro do código).
- `HandleInertiaRequests::features()` virou closure por organização e passou a ler os
  resolvedores de cada área: `templates`, `reminders`, `multi_document`, `participant_roles`,
  `custom_roles`, `tags`, `reports`, `audit_log` (config global **E** plano) e `admin_users`,
  `admin_audit`, `impersonation` (só config global). As 7 chaves da Fase 1 continuam.
- **`custom_roles` não respeitava o interruptor global**: um plano com `custom_roles: true` ligava
  o recurso mesmo com a configuração desligada. Agora o global é obrigatório (T8); com ele ligado,
  o plano decide e, sem a chave no plano, vale o global. O teste `FeatureFlagTest` foi ajustado.
- Front: `Features` e `OrgPermissions` tipados com as chaves novas; sidebar com "Relatórios"
  (flag + `view_reports`), "Modelos" sem o selo "Fase 2" com a flag, itens do painel interno
  habilitados pelas flags; rail de Configurações com "Etiquetas" e "Registro de atividades".

### 2.3 Pendências que as áreas deixaram para a integração

- Rotas de lembretes/agendamento em `routes/web.php` + helpers Wayfinder; `phase2-routes.ts`
  passou a usá-los.
- Prop `reminders` em `EnvelopeController::edit/show`; filtro e chips de etiqueta em
  `EnvelopeController::index` (`EnvelopeTagIndex`).
- `org.role` → `Permissions::routeAllows` (resultado idêntico para papéis de sistema; função
  personalizada passa com a permissão equivalente).
- `can.bulk_cancel` e `can.resend_pending` por permissão (`cancel_any_envelope`,
  `manage_any_envelope`); filtro "Criado por" lista os autores do que a pessoa vê.
- E-mails de convite e de aceite de membro com o nome da função personalizada.
- Convite de destinatário com texto próprio para testemunha, aprovador e visualizador (o do
  signatário é idêntico ao da Fase 1).
- Dashboard: visualizador não conta como pendência.
- `Envelope`: casts de `scheduled_send_at`/`scheduled_send_audit_id`.
- `OrganizationPurge`: as tabelas novas entram na ordem de exclusão. Sem isso, `impersonations`
  (RESTRICT) impediria excluir a organização.
- `audit.evidence_tables`: `acceptance_documents` e `signing_session_documents` (só INSERT);
  `verification_record_documents` fica de fora porque a retentativa da finalização a reescreve.
  `approval.recorded` entrou na linha do tempo da página de evidências.

### 2.4 Defeitos encontrados na QA visual

- **Banner falso de "acessar como"** no painel interno: a página do cliente tinha uma prop própria
  chamada `impersonation`, o mesmo nome da prop compartilhada da sessão de suporte; o banner
  aparecia para o platform admin com "encerra em NaN min" e o botão "Encerrar". A prop da página
  virou `impersonation_options` e o banner só acende com o formato da sessão (`expires_at`).
- **Página 404/403 em branco** numa URL sem rota (defeito anterior à Fase 2): sem rota, o grupo
  `web` não roda, as props compartilhadas não chegam e `errors/404.tsx`/`403.tsx` liam
  `auth.user` de `undefined`. Agora tratam `auth` como opcional.

### 2.5 Asserções de testes antigos alteradas (todas legítimas)

| Teste                                                 | Mudança                                                                                                  |
| ----------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `Unit/Models/EnumCatalogTest`                         | +31 eventos da onda A listados por área; nada renomeado ou removido (45 → 76)                            |
| `Feature/Organizations/SharedPropsTest`               | `features` ganhou 8 chaves, todas `false`                                                                |
| `Feature/Smoke/AllGetRoutesTest`                      | parâmetro sintético e 404 esperado para `templates.edit/preview/source.show/picker` com a flag desligada |
| `Feature/Hardening/AppendOnlyEvidenceTest`            | duas tabelas de evidência novas no mapa de políticas                                                     |
| `Feature/Phase2/Permissions/FeatureFlagTest` (B-PERM) | interruptor global desligado vence o plano                                                               |

## 3. Não regressão da Fase 1

Com todas as flags desligadas (o padrão da configuração e o que os testes usam), os 930 testes
originais da Fase 1 passam **sem mudança de asserção**, exceto as quatro listadas em §2.5, que
só acrescentam itens novos (eventos, chaves de flag, rotas, tabelas) sem alterar nenhum valor da
Fase 1. `tests/Feature/Phase2/Org/FlagsOffTest`, `TemplateFeatureFlagTest`,
`Permissions/FeatureFlagTest`, os testes de lembretes com a flag desligada e
`MultiDocumentPreparationTest` cobrem o comportamento desligado de cada área.

## 4. Ponta a ponta (`tests/Feature/EndToEnd/Phase2OndaATest.php`)

Com todas as flags da onda A ligadas para a organização de teste, pelas rotas HTTP reais, com a
finalização rodando de verdade (fila `sync` + pdftool):

1. modelo HTML com variáveis tipadas e quatro papéis (aprovador, signatário, testemunha,
   visualizador);
2. envelope gerado pelo modelo (documento 1, DOMPDF + pipeline) e um PDF anexado (documento 2);
3. campos por arquivo (signatário nos dois, testemunha no anexo, aprovador sem assinatura);
4. lembretes (a cada 2 dias, até 3) e envio agendado para as 10:00 locais — nada sai antes;
5. disparo agendado às 10:01: versões congeladas por arquivo; aprovador e visualizador avisados,
   signatário e testemunha aguardam a vez;
6. dois dias depois, um lembrete automático só para quem está na vez, com link novo;
7. aprovador aprova (sem imagem), signatário e testemunha assinam; cada aceite cobre os dois
   arquivos (`acceptance_documents` = 6);
8. envelope `completed`, finais dos dois arquivos, `verification_record_documents` com dois
   hashes distintos; visualizador recebe a cópia final e não trava a conclusão;
9. verificação pública (sem login) mostra os dois hashes finais e `documents_count = 2`;
10. função personalizada com acesso à pasta vê o envelope (detalhe e lista); outra sem acesso
    recebe 403/404 e não o vê na lista;
11. relatório: owner vê 1 enviado e 1 concluído; a função sem acesso vê 0;
12. etiqueta aplicada filtra a lista (`?tag=`), e `features` compartilhado reflete as flags.

## 5. QA visual (servidor próprio, `database/i2a.sqlite`, flags ligadas)

Harness só no scratchpad (`php -S 127.0.0.1:8131`, flags ligadas em tempo de execução, build de
produção). **Limites honestos:** a sessão foi aberta por uma rota local do harness, sem digitar
senha, e **nenhum formulário foi enviado pelo navegador** (criar modelo, gerar envelope, enviar,
convidar, iniciar "acessar como" — este exige digitar a senha do admin). Esses caminhos estão
cobertos pelo teste de ponta a ponta (§4) e pelos testes de cada área. O console não mostrou
erros além dos listados.

| Tela                                      | Resultado                                                                                             |
| ----------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| Dashboard (owner Horizonte)               | OK; sidebar com Relatórios, Modelos sem selo "Fase 2"                                                 |
| Modelos (galeria)                         | OK; 2 modelos, filtros por categoria, Ativos/Arquivados, "Novo modelo"; fiel ao mock                  |
| Usar modelo (`documentos/nova?template=`) | OK; variáveis por tipo (CPF, moeda, data), participantes por papel, resumo                            |
| Wizard passo 1                            | OK; "Arquivos (1 de 10)", dropzone de vários arquivos, lembretes reais, seletor de modelos            |
| Wizard passo 2                            | OK; "Tipo de participante" por pessoa, com a frase do efeito                                          |
| Detalhe do documento                      | OK; selo "Aprovador" na aba Participantes. O PDF não abre porque o seeder não grava arquivos (Fase 1) |
| Usuários › Funções e permissões           | OK; matriz com os 3 papéis de sistema + "Gerente de locações"; abas Membros (5) e Times (1)           |
| Relatórios                                | OK; KPIs, série diária, por usuário e por time, "Exportar CSV"                                        |
| Configurações › Etiquetas                 | OK; 3 etiquetas com contagem                                                                          |
| Configurações › Registro de atividades    | OK; só eventos da organização, IP mascarado (eventos do seeder aparecem como "Sistema")               |
| Painel interno › Usuários da plataforma   | OK; KPIs e tabela com organizações e 2FA                                                              |
| Painel interno › Logs e auditoria         | OK; filtros e estado vazio                                                                            |
| Painel interno › Cliente                  | "Acessar como" por membro; **banner falso corrigido** (§2.4)                                          |
| URL inexistente                           | **404 em branco corrigido** (§2.4)                                                                    |
| Página pública do aprovador em 375 px     | OK; stepper "Confirmar identidade · Aprovar · Concluído", "Confirme sua identidade para aprovar"      |

## 6. Dados de demonstração (`DemoOrganizationSeeder`)

- Plano **Profissional** (Horizonte) com as 8 flags da onda A em `plans.features`; **Grátis**
  (Vega) com todas `false`. Como o interruptor global nasce desligado, a demonstração padrão (e os
  testes) é a da Fase 1; para ver a onda A, ligue `ASSINAVELOX_FEATURE_*` no `.env`.
- As duas organizações ganham as linhas dos papéis de sistema (como `CreateOrganization`).
- Horizonte: função "Gerente de locações" (sem "ver todos", com gerência da pasta Locações) para
  um usuário novo, `gerente@horizonte.demo`; time "Equipe comercial" (admin + gerente) com
  visualização da pasta Vendas; modelos HTML "Contrato de locação residencial" e "Termo de entrega
  de chaves"; etiquetas Residencial, Comercial e Urgente aplicadas a quatro documentos.
- `operador@horizonte.demo` fica fora de qualquer acesso por pasta: a regra "operador só vê o que
  criou" continua valendo na demonstração.

## 7. Pendências reais

1. **Revisão jurídica** dos textos de testemunha, aprovador e de vários documentos, e da cláusula
   de acesso de suporte nos Termos de Uso, antes de ligar `participant_roles`, `multi_document` e
   `impersonation` em produção.
2. **Acesso por pasta vale mesmo com `custom_roles` desligada**: `EnvelopeVisibility` aplica os
   registros de `folder_permissions` existentes sem consultar a flag. Com a flag desligada não se
   cria acesso novo, mas o que já existia continua valendo. Decidir se desligar a flag deve
   ignorar esses registros.
3. **[Resolvido — §9]** **Título da aba da página pública** diz "Assinar · …" também para aprovador e visualizador
   (T1: aprovar não é assinar). Ajuste pequeno em `pages/sign/show.tsx`.
4. **Lembretes emitem link novo** e revogam o anterior (desvio da decisão "reusar o link atual",
   justificado pelo B-REM: o token só existe como digest). Precisa de confirmação do produto.
5. `ScheduledSend::cancelBecauseEdited` não é chamado nos pontos de edição; o cancelamento por
   edição acontece na varredura de cada minuto (até 1 min de atraso).
6. **[Resolvido — §9]** Contagens de pendência em `EnvelopeNotifications`, `RecipientController` (KPIs) e
   `DailyDigest` ainda contam visualizadores (o Dashboard já foi corrigido).
7. `NotificationEvent` (TS) sem `scheduled_send_failed`; a tela de preferências de notificação não
   oferece esse aviso.
8. Recusa de um arquivo recusa o envelope inteiro, e há um aceite para o conjunto (sem checkbox
   por arquivo, divergência do roadmap §2.3 registrada pelo B-DOM/F-DOM).
9. Teste de navegador do fluxo "wizard a partir de modelo" (exigido pelo roadmap §2.0 para ligar
   `templates`) não existe; o plugin de navegador não envia multipart (mesma limitação do teste
   de dropzone da Fase 1).
10. `platform_audit_events` sem registro para "acessar como" recusado (`impersonation.denied`
    reservado).

## 8. Revisão adversarial

Correção dos 12 achados da revisão adversarial da onda A (5 altos, 7 médios). Cada um foi reproduzido
com o teste da revisão (`tests/Feature/Review/Phase2/`), corrigido na causa e verificado. Nenhum commit
foi feito. Com todas as flags desligadas, o comportamento da Fase 1 continua o mesmo.

### 8.1 Correções por achado

1. **Exportações CSV ignoravam `export_data`** (alto). `DashboardController::export` e
   `RecipientController::export` respondem 403 sem `export_data`. As duas páginas recebem
   `can.export` e escondem o botão. Owner, admin e Operador têm a permissão, então nada muda para eles.
   Teste: `ExportDataPermissionTest`.
2. **"Acessar como" saía da organização autorizada** (alto). Durante a impersonation, `org`
   (`EnsureCurrentOrganization::handleImpersonation`) resolve SÓ a organização da sessão, sem fallback.
   Sem membership ativa do alvo, encerra a sessão (`END_INVALID`), devolve o login ao admin e o leva ao
   cliente. A guarda (`EnforceImpersonationReadOnly`) faz a mesma checagem, para rotas sem `org`. De
   brinde, dois casos do mesmo teste: `org` não grava mais `users.current_organization_id` do alvo, e
   `ImpersonationManager::stop()` usa `logoutCurrentDevice()`, que não troca o `remember_token` do
   cliente. Teste: `ImpersonationScopeAndSideEffectsTest` (3 casos).
3. **Excluir função restrita promovia a Operador** (médio). `RoleController::destroy` recusa a exclusão
   (erro em `role`) enquanto houver membership ou convite pendente com a função, e diz o que fazer:
   mudar a função das pessoas, revogar os convites. Ninguém é convertido automaticamente. A trilha
   `role.deleted` perdeu `moved_members` (sempre seria 0). A tela de Usuários mostra a recusa em toast
   (`onError`), e o texto da confirmação foi atualizado. `permissoes-e-times.md` também. Teste:
   `RoleDeletionPrivilegeGainTest`.
4. **Função personalizada concedia permissões com `custom_roles` desligada** (médio).
   `Membership::customRole()` devolve null com a flag desligada (memorizada na instância), e a pessoa
   volta ao papel de sistema, como na Fase 1. A mesma regra vale para o acesso por pasta:
   `PermissionsFolderAccess::grantsQuery` não aplica `folder_permissions` com a flag desligada. Isso
   **resolve a pendência 2 do §7**. Teste: `CustomRoleWithFlagOffTest`.
5. **`DocxSafety` enganada por `&#73;` e por `instrText` dividido** (alto). A inspeção foi reescrita
   sobre DOMDocument (`LIBXML_NONET`, parte com `<!DOCTYPE>` ou malformada recusada), em TODAS as
   partes `.xml`. A instrução de cada campo é montada como o Word monta: `instrText` concatenados entre
   `begin` e `separate`, resultado de campo aninhado somado à instrução do campo de fora, e `w:instr`
   de `fldSimple`. Depois o texto é normalizado (sem espaços, maiúsculas) e comparado pelo início com
   INCLUDETEXT, INCLUDEPICTURE, INCLUDE (novo, sinônimo legado), DDEAUTO, DDE, IMPORT e LINK.
6. **`TargetMode='External'` com aspas simples** (alto). Os `.rels` são lidos como XML, com os
   atributos já resolvidos. Todo `External` que não seja hyperlink é recusado; o hyperlink do OOXML
   estrito também é aceito. Os `ContentType` de `[Content_Types].xml` também são lidos pelo parser.
7. **DOCX preenchido nunca reinspecionado** (médio). `TemplateDocumentRenderer::filledDocx` roda
   `DocxSafety::assertSafe` no arquivo gerado e recusa a geração (`filled_docx_unsafe`, erro em
   `template`). O marcador dentro de `instrText` continua aceito no modelo, como exige o próprio teste
   da revisão; a defesa fica na geração. Testes 5 a 7: `DocxTemplateSafetyBypassTest` (4 casos).
8. **Aviso de prazo ia ao visualizador e revogava o link** (médio). `ExpireEnvelopes::warn()` só
   seleciona papéis que participam, e a contagem enviada ao remetente segue a mesma regra. O mesmo
   filtro entrou em `ResendInvitations::eligible()` ("Lembrar pendentes", 2º caso do mesmo teste) e na
   contagem do "Lembrar todos" da tela Assinaturas. Isso cobre parte da pendência 6 do §7. Teste:
   `ViewerTreatedAsPendingSignerTest` (2 casos).
9. **Lembretes ignoravam `max_resends`** (médio). O roadmap §2.5 tem precedência sobre o documento da
   área. `ResendInvitations::resendCount` passa a somar reenvios manuais e lembretes enviados, e o
   lembrete é pulado com `max_resends_reached` ao atingir o limite. Sem a flag `reminders`,
   `envelope_reminders` fica vazia e o número é o da Fase 1. `lembretes-e-agendamento.md` §4.2 e §4.5
   foram atualizados. Teste: `ReminderMaxResendsTest`.
10. **Visualizador via "assina depois de você"** (médio). `SignerPageProps::others()` só calcula
    `signs_after_me` quando o destinatário atual participa; o visualizador vê "pendente". Teste:
    `ViewerParticipantsTurnTest`.
11. **Aviso de privacidade falso para visualizador e aprovador** (médio). `ConsentText` ganhou
    variantes por papel: resumo e aviso completo, com as versões `v1-viewer-2026-09-11` e
    `v1-approve-2026-09-11`. O visualizador lê "registrar sua visualização" e o aprovador "sua
    aprovação", sem imagem de assinatura. O texto do signatário e o da testemunha, que assina,
    continuam os da Fase 1. As variantes estão **pendentes de revisão jurídica**, como as demais da
    Fase 2 (pendência 1 do §7). Teste: `ParticipantPrivacyNoticeTest`.
12. **Fuso exibido como `America/Sao_Paulo`** (médio). `Timezones::humanLabel()` gera "horário de
    Brasília (GMT-3)" e alimenta `timezone_label` em `ReminderProps` e `ScheduledSend::present()`. O
    flash do agendamento, o cartão "Agendar envio" e o texto dos lembretes passaram a usar o rótulo. O
    IANA fica só nas contas de data. Teste: `ScheduleTimezoneLabelTest` (4 casos).

Também passaram três testes da revisão que não estavam na lista de achados e eram triviais:
`PluralMarkersPhase2Test` (textos de etiquetas e Relatórios sem "(s)"), `RolesAwareWizardCopyTest`
(aviso do passo 4 com papéis; placeholder do nome por papel) e `TemplateCardParticipantCountTest`
(cartão do modelo diz "participantes").

### 8.2 Testes antigos alterados (legítimos)

| Teste                                                | Mudança                                                                                                         |
| ---------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `Phase2/Permissions/RolesAndTeamsTest`               | Excluir uma função com alguém nela é recusado; a pessoa é reatribuída e só então a função sai (achado 3).       |
| `Phase2/Org/AuditLogTest` e `Phase2/Org/ReportsTest` | Ligam `custom_roles` antes de criar a função personalizada: sem a flag, ela não concede nada (achado 4, T1/T8). |

### 8.3 O que não foi corrigido

- **[Resolvido — §9]** `ViewerListPositionTest`: o visualizador volta ao topo da lista depois do autosave. Não estava na
  lista de achados e não é trivial. `RecipientSync` grava `order_index = 0` no visualizador, e
  `Envelope::recipients()` ordena por `order_index`. Corrigir exige uma posição de exibição separada
  da ordem de assinatura (coluna nova ou ordenação por outro critério), com efeito em todas as telas
  e na página pública. Fica para quem cuida de B-DOM/F-DOM.
- Contadores cacheados da sidebar (`counts:org:*`) não são invalidados quando a flag `custom_roles`
  muda por plano; valem até o TTL.
- **[Resolvido — §9]** Pendência 6 do §7: `EnvelopeNotifications`, os KPIs de `RecipientController` e `DailyDigest`
  ainda contam visualizadores.

### 8.4 Verificações (números reais, depois das correções)

| Verificação                                            | Resultado                                                                |
| ------------------------------------------------------ | ------------------------------------------------------------------------ |
| `php artisan test --parallel --testsuite=Unit,Feature` | 1126 testes: **1125 passando**, 1 falha (`ViewerListPositionTest`, §8.3) |
| `php artisan test --testsuite=Browser`                 | **36 passando, 3 pulados** (os `->skip()` da Fase 1), 740 asserções      |
| `tools/pdftool`: `pytest -q`                           | **93 passando**                                                          |
| `vendor/bin/phpstan analyse`                           | **0 erros**                                                              |
| `vendor/bin/pint --test`                               | verde                                                                    |
| `npm run types:check`                                  | 0 erros                                                                  |
| `npm run check`                                        | 227 arquivos formatados, 0 avisos em 176                                 |
| `npm run build`                                        | OK                                                                       |

## 9. Fechamento pelo orquestrador (2026-09-11)

Depois da correção adversarial, três pendências de produto e uma de infraestrutura de teste foram
fechadas antes do commit da onda.

### 9.1 Correções

| Pendência                                        | O que mudou                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | Teste                                                         |
| ------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| §7.6 e §8.3: visualizador contado como pendente  | O filtro de participantes (`RecipientRole::participatingValues()` / escopo `participating`) entrou na aba "Pendentes" e nos indicadores da tela Assinaturas, no aviso "Fulano assinou" (numerador e denominador) e no resumo diário. O visualizador acompanha; não tem aceite a dar.                                                                                                                                                                                                                                                               | `tests/Feature/Phase2/Domain/ViewerIsNotPendingTest.php` (4)  |
| §8.3: visualizador saltando para o topo da lista | Nova coluna `recipients.position` (migration `2026_09_11_110500`), preenchida para os dados existentes pela ordenação anterior (`order_index`, `id`), então nada muda na tela para quem já usa. A relação `Envelope::recipients()` ordena por `position`; `RecipientSync` grava a posição da lista e `reindex()` passou a reordenar por ela — o que também corrige a perda da ordem arrumada ao alternar entre paralelo e sequencial. `order_index` continua sendo a única fonte da vez de assinar. A factory espelha `order_index` em `position`. | `tests/Feature/Review/Phase2/ViewerListPositionTest.php`      |
| §7.3: título da aba                              | A tela de identificação usa "Aprovar", "Assinar como testemunha" ou "Documento" conforme o papel, em vez de "Assinar" para todos.                                                                                                                                                                                                                                                                                                                                                                                                                  | coberto pela revisão de tipos e pelo build; sem teste próprio |
| Travamentos da suíte de navegador                | `tests/BrowserTestCase.php` aponta o hot file do Vite para um caminho inexistente. Com um `public/hot` presente — aberto ou órfão —, as páginas carregavam scripts de um servidor de desenvolvimento, sem JavaScript, e cada asserção esperava o teto de 20 s: parecia travamento.                                                                                                                                                                                                                                                                 | suíte Browser inteira em 38 s                                 |

### 9.2 Incidente de ambiente

O servidor Vite de desenvolvimento do proprietário (porta 5173, processo 22328, iniciado em
2026-09-09) **não está mais em execução**, e o `public/hot` daquele servidor ficou para trás. Um
agente relatou ter encerrado apenas a própria árvore de processos de teste (PID 380, com o
Playwright PID 27132); não foi possível determinar quem encerrou o 22328. O `public/hot` foi
mantido porque não foi criado por nós: quem voltar a desenvolver deve rodar `npm run dev` de novo
ou apagar o arquivo.

### 9.3 Verificações finais (números reais)

| Verificação      | Resultado                                                             |
| ---------------- | --------------------------------------------------------------------- |
| Unit + Feature   | 1.130 testes, todos verdes, 9.005 asserções                           |
| Browser          | 39 testes: 36 verdes e 3 pulados (os `skip` da Fase 1), 740 asserções |
| pdftool (pytest) | 93 verdes                                                             |
| PHPStan          | 0 erros                                                               |
| Pint             | verde                                                                 |
| `types:check`    | 0 erros                                                               |
| `check`          | 227 arquivos formatados, sem avisos                                   |
| `build`          | OK                                                                    |

### 9.4 O que continua aberto

§7.1 revisão jurídica dos textos novos e da cláusula de acesso de suporte; §7.2 decidir se o
acesso por pasta deve ser ignorado quando `custom_roles` estiver desligada; §7.4 confirmação de
produto de que o lembrete emite link novo; §7.5 cancelamento do agendamento por edição feito pela
varredura de cada minuto; §7.7 aviso `scheduled_send_failed` nas preferências de notificação;
§7.8 recusa e aceite por arquivo; §7.9 teste de navegador do fluxo a partir de modelo (limitação do
plugin com multipart); §7.10 `impersonation.denied`; contadores da barra lateral não invalidados
quando `custom_roles` muda por plano (valem até o TTL de 60 s).
