# AssinaVelox — Roadmap: Fases 2 e 3 e backlog futuro

> Documento de planejamento, não de implementação. Identificadores em inglês; prosa em português.
> Precedência: `docs/design/RECONCILIACAO.md` → `docs/arquitetura.md` → `docs/design/ROUTES_AND_PAGES.md` → este arquivo. Nada aqui altera o escopo da Fase 1 (`RECONCILIACAO.md` §5); aqui se descreve **como** cada item futuro se encaixa no que a Fase 1 já deixou preparado.
> Data de referência: 2026-09-08 (Fase 1 em implementação, incremento 1 de 6 — ver `arquitetura.md` §9).

## 0. Como ler este documento

Cada item das Fases 2 e 3 segue a mesma estrutura compacta:

| Campo | Significado |
|---|---|
| **Objetivo** | O que o item entrega ao cliente ou à operação, em uma frase. |
| **Extensão prevista** | Tabelas, colunas, enums, contratos (`App\Integrations`) e flags de `features` que a Fase 1 já reservou para isso. |
| **Novas entidades** | Tabelas/modelos/jobs a criar. Nomes são propostas; valem depois de registrados em `arquitetura.md`. |
| **Riscos e decisões pendentes** | O que precisa ser decidido pelo produto/jurídico ou validado tecnicamente antes de começar. |
| **Aceite** | Critérios resumidos para considerar o item pronto (sempre incluem testes automatizados). |

Flags de `features` (props compartilhadas, `ROUTES_AND_PAGES.md` §0.3) já existentes: `templates`, `api_integrations`, `reminders`, `sms_whatsapp`, `branding`, `multi_document`. Novas flags propostas aqui seguem o mesmo padrão (`snake_case`, derivadas de `plans.features` JSON **e** de configuração global; a flag só liga a UI — a autorização continua nas Policies).

Contratos reservados na Fase 1 (interfaces documentadas, sem implementação — `arquitetura.md` §8): `SmsProvider`, `WhatsAppProvider`, `CpfVerificationProvider`, `CnpjLookupProvider`, `IdentityVerificationProvider`, `TimestampProvider`, `FiscalInvoiceProvider`. Todo item que os usa recebe primeiro um **fake identificado** e só depois a implementação real.

## 1. Regras transversais (valem para todas as fases)

| # | Regra | Consequência prática |
|---|---|---|
| T1 | **Semântica de assinatura** (`arquitetura.md` §2) é vocabulário obrigatório. | Representação visual ≠ aceite eletrônico ≠ assinatura criptográfica. Cada novo método (A1 do usuário, A3, gov.br) ganha um valor próprio em `verification_records.signature_status` e um rótulo próprio na UI; nunca "assinatura digital" genérica. |
| T2 | **Nunca anunciar perfil PAdES não testado.** | `signature_profile` só recebe `PAdES-B-T`, `B-LT`, `B-LTA` depois de `pdftool validate` (pyHanko) **e** validação externa passarem em CI com fixtures reais. Até lá a UI diz `PAdES-B-B`. |
| T3 | **TSA pública/comercial ≠ carimbo ICP-Brasil.** | Carimbo RFC 3161 de TSA própria ou comercial é rotulado `tsa_kind=operator|commercial`; só ACT credenciada pelo ITI recebe `icp_brasil`. Textos legais e página de verificação refletem isso. |
| T4 | **Integrações só com documentação real.** | Enquanto não houver documentação oficial acessível (endpoint, autenticação, erros, limites), o item fica em "contrato + fake". Nunca engenharia reversa, scraping ou bibliotecas não oficiais (lista de não adotados em `arquitetura.md` §1). |
| T5 | **Idempotência e ambiguidade de timeout.** | Toda chamada externa com efeito (enviar mensagem, emitir nota, carimbar, cobrar) usa chave de idempotência nossa e trata timeout como *desconhecido*, não como falha: consulta antes de repetir; `delivery_attempts.status=unknown` é estado legítimo. |
| T6 | **Dados de documento são dados não confiáveis.** | Conteúdo de PDF/DOCX, variáveis de template, respostas de formulário público e payloads de webhook de entrada nunca são interpretados como código, Blade ou instrução. |
| T7 | **Trilha append-only preservada.** | Novos eventos entram em `audit_events.event_type` (catálogo em `RECONCILIACAO.md` §3) com payload minimizado. Nenhum item pode exigir `UPDATE`/`DELETE` nessa tabela em produção. |
| T8 | **Ativação por flag, nunca por deploy "big bang".** | Cada item nasce desligado (`features.*` = false), com migrations aditivas e Policies prontas; liga-se por plano/organização após os testes da fase. |
| T9 | **Testes por fase.** | Cada fase tem sua matriz (§5): unitário, feature HTTP, navegador para fluxos críticos, contrato de adaptador (fake + fixtures gravadas), segurança (SSRF, HMAC, rate limit) e validação criptográfica independente. Item sem teste não liga a flag. |
| T10 | **Nada de segredo em fila, log ou argv.** | Senhas de PFX, tokens de API de terceiros e códigos OTP seguem a regra da Fase 1: variável de ambiente do processo filho, `secret_ref` em banco, redaction nos logs. |

## 2. Fase 2 — Introdutória

### 2.0 Placeholders da Fase 1 e o que a ativação exige

A Fase 1 entrega estas telas/controles como placeholders (`Phase2EmptyState`, badge "Fase 2", controle desabilitado ou rota com redirect). A tabela lista o que cada ativação exige além do item correspondente.

| Placeholder na Fase 1 (rota/controle) | Item que o ativa | Exige na ativação |
|---|---|---|
| **Modelos** — `templates.index` → `pages/templates/Index.tsx`; card "Ou comece por um modelo" no passo 1 do wizard | §2.1 | `features.templates`; tabelas `templates`, `template_fields`, `template_roles`, `template_variables`; `envelopes.create?template={ulid}`; `TemplatePolicy`; botão "Novo modelo" habilitado; testes de navegador do wizard a partir de modelo. |
| **API e integrações** — `integrations.index` (segmented control desabilitado), `integrations.keys` e `integrations.logs` (302 na Fase 1) | §2.15, §2.16 | `features.api_integrations`; `personal_access_tokens` estendida, `webhook_endpoints`, `webhook_deliveries`; prefixo `/api/v1`; páginas `Keys.tsx` e `Logs.tsx` reais; documentação OpenAPI servida na aba "Documentação". |
| Linha "Webhook" nos Detalhes do envelope; evento `webhook_failed` em Configurações → Notificações | §2.16 | Exibir última entrega por envelope; coluna/evento ligados só quando a org tiver `webhook_endpoints` ativos. |
| Switch "Lembretes automáticos" (wizard passo 1 e `settings.signing`); rodapé "Lembretes automáticos ativos" do KPI | §2.5 | `features.reminders`; `envelopes.settings.reminders` + `reminder_schedules`; comando agendado; testes de parada em estado terminal. |
| Chips de autenticação SMS/WhatsApp; canais WhatsApp/SMS em `settings.signing`; campos celular/canal/auth no passo 2; filtros "Canal"/"Autenticação" em `recipients.index`; coluna WhatsApp em Notificações | §2.9, §2.18 | `features.sms_whatsapp`; `SmsProvider`/`WhatsAppProvider` reais; `AuthMethod` ganha `sms_otp`, `whatsapp_otp`; `recipients.phone` obrigatório e validado (E.164) quando canal ≠ e-mail. |
| Upload de logo em `settings.general`; `current_organization.logo_url` sempre `null` | §2.8 | `features.branding`; `organization_brandings`; e-mails e `SignerLayout` consumindo cores/logo; validação de imagem (GD) e limite de tamanho. |
| Dropzone "1 arquivo"; botão "Importar" removido; "Adicionar signatário ou testemunha" após envio oculto | §2.3, §2.4 | `features.multi_document`; `documents.position`; regra "exatamente 1 documento" removida do serviço e do teste; pipeline por documento. |
| "Nova função personalizada"; "Pastas com acesso" no convite; roles Gerente/Somente leitura do mock | §2.14 | `roles`, `role_permissions`, `folder_permissions`; matriz de `OrgPermissions` passa a ser dinâmica; Policies reescritas sobre permissões, não sobre enum `MembershipRole`. |
| "Baixar" em lote (ZIP) em `envelopes.bulk` (Q12) | §2.13 | `dossier_exports` assíncrono, link de download autorizado e expirável. |
| Botão "NF-e" em `billing.index` (Q21) | §2.21 | `fiscal_invoices`; rótulo correto **NFS-e**; recibo interno continua existindo. |
| Switches SSO / restrição de IP em `settings.general` | Fase 3 §3.9 (SSO); restrição de IP em §2.14 | `sso_connections`; `organizations.settings.allowed_ip_ranges` + middleware. |
| "Entrar com certificado digital" (login) e opção "Certificado" na página do signatário | §2.12 (A1 do usuário) e Fase 3 §3.4 (A3) | Login por certificado **não** está planejado para a Fase 2: só a assinatura com A1 do participante. O botão de login continua oculto até haver decisão. |
| Admin: "Acessar como" (`admin.organizations.impersonate`), "Nova conta", filtro "Segmento", `admin.billing.index`, `admin.users.index`, `admin.audit.index`, `admin.settings.index` | §2.14 (logs administrativos e relatórios), §2.20 (faturamento) | `impersonations` (trilha, banner persistente, bloqueio de ações financeiras, consentimento nos Termos — Q15); `organizations.segment`; páginas reais no lugar de `pages/admin/Placeholder.tsx`. |
| Campos `cpf` e `stamp` (`FieldType`, `ROUTES_AND_PAGES.md` §0.5) | §2.11 (`cpf`), §2.8 (`stamp` = carimbo visual com branding) | Validação de CPF no aceite; carimbo é representação visual, não prova. |
| Idioma do signatário (`recipients.locale`, Q24) | Fase 3 §3.3 | Traduções de e-mails, página pública e evidências. |

### 2.1 Templates com variáveis tipadas

- **Objetivo**: o remetente cria um modelo (DOCX ou HTML) com variáveis tipadas, papéis e campos pré-posicionados, e gera envelopes preenchendo um formulário em vez de reenviar o arquivo.
- **Extensão prevista**: `features.templates`; placeholder `templates.index`; `envelopes.duplicate` já copia documento + recipients + campos (mesma lógica de materialização); `PdfConverter` (LibreOffice) converte o DOCX preenchido; `envelopes.create?template=`.
- **Novas entidades**: `templates` (organization_id, ulid, name, source_type ∈ docx|html|pdf, current_version_id, status), `template_versions` (arquivo imutável + sha256), `template_variables` (name, type ∈ string|number|date|cpf|cnpj|money|boolean|enum, required, validation JSON), `template_roles` (nome do papel → recipient no momento do uso), `template_fields` (geometria normalizada igual a `signing_fields`, ligada a `template_role_id`).
- **Riscos e decisões**: DOCX via **PHPWord `TemplateProcessor`** (substituição de `${var}`), nunca macros; HTML só com substituição restrita de `{{var}}` sobre um dicionário tipado e escape obrigatório — **nunca Blade/PHP do cliente** e nunca `eval`; HTML→PDF por DOMPDF com CSS permitido em lista fechada (sem `@import`, sem URLs externas). Geometria dos `template_fields` depende do layout do PDF gerado; variação de tamanho de texto pode deslocar páginas → decisão pendente: campos posicionados só em PDF fixo, e em DOCX/HTML só por âncoras (Fase 3 §3.2) ou revisão manual obrigatória no editor.
- **Aceite**: criar modelo, preencher variáveis com validação por tipo, gerar envelope `draft` com documento `converted`, recipients e campos; variável ausente obrigatória bloqueia; conteúdo `{{...}}` vindo do cliente não executa nada (teste de injeção); modelo versionado não altera envelopes já gerados.

### 2.2 Formulário público que gera envelope

- **Objetivo**: link público onde um terceiro preenche seus dados, o sistema gera o envelope a partir de um modelo e o convida a assinar em seguida.
- **Extensão prevista**: depende de §2.1; grupos de middleware `public` e `throttle` já usados em `/verificar`; `delivery_attempts`, `plan_consumptions` (a cota é da organização dona do formulário).
- **Novas entidades**: `public_forms` (organization_id, template_id, ulid, slug, status, fields_schema JSON, settings JSON: require_email_confirmation, max_submissions_per_day, expires_at), `public_form_submissions` (payload validado, ip_address, user_agent, envelope_id nullable, status ∈ received|envelope_created|rejected).
- **Riscos e decisões**: anti-abuso é o núcleo — rate limit por IP e por formulário, confirmação de e-mail antes de gerar envelope (o envelope só nasce depois do clique no link), limite diário configurável, honeypot, e CAPTCHA acionável por flag quando houver abuso (Q14); consumo de cota da org é reservado só na criação do envelope, não na submissão; dados do formulário são não confiáveis (T6) e passam por validação tipada do modelo. Decisão pendente: se a submissão pode criar envelope com **múltiplos** signatários (o preenchedor + papéis fixos do modelo) já na primeira versão.
- **Aceite**: submissão válida → e-mail de confirmação → envelope `in_progress` com o preenchedor como signer; submissão sem confirmação expira sem consumir cota; throttles e honeypot cobertos por testes; payload malicioso não altera nada além dos campos previstos.

### 2.3 Envelope com múltiplos documentos

- **Objetivo**: um envelope carrega N documentos; o signatário aceita cada documento explicitamente; hashes e versões são por arquivo.
- **Extensão prevista**: `documents` já é 1:N com `envelopes` (a restrição "exatamente 1" é de serviço + teste, `arquitetura.md` §3.1); `document_versions` por documento; `signing_fields.document_version_id`; `signature_acceptances.document_sha256` (hoje um por recipient); `features.multi_document`; Q17 reserva `documents.position`.
- **Novas entidades/alterações**: `documents.position` INT; `document_acceptances` (signature_acceptance_id, document_id, document_version_id, document_sha256, accepted_at) — o aceite do envelope passa a agregar N aceites por documento; `envelopes.sent_document_version_id`/`final_document_version_id` migram para `documents.sent_version_id`/`final_version_id` com o envelope guardando só um `manifest_sha256` (hash do manifesto ordenado dos hashes por arquivo); `verification_records` ganha `documents_summary` JSON (por arquivo: nome, sent_sha256, final_sha256).
- **Riscos e decisões**: `UNIQUE(recipient_id)` em `signature_acceptances` continua (um aceite por participante) e a granularidade por documento vai para `document_acceptances`; a página de verificação passa a listar hashes por arquivo; finalização roda o pipeline (§5 da arquitetura) por documento, sob a mesma `finalization_key`, e só marca `completed` quando todos os finais existem. Decisão pendente: página de evidências única por envelope (recomendado) ou uma por documento.
- **Aceite**: upload de 2+ arquivos com ordenação; signatário vê e aceita cada documento (checkbox por arquivo + aceite final); recusa de um documento recusa o envelope (política padrão); verificação pública mostra N hashes; teste garante que remover um documento invalida `ready`.

### 2.4 Papéis: testemunha, aprovador, visualizador

- **Objetivo**: participantes que não assinam mas aprovam, testemunham ou apenas recebem cópia, com efeito distinto na máquina de estados.
- **Extensão prevista**: `recipients.role ENUM(signer)` declarado extensível (`RECONCILIACAO.md` §1: `witness`, `approver`, `viewer`); hoje o mock usa `role='Testemunha'` como texto livre no passo 2 — vira enum; `recipient_access_links.purpose` (`signing`, `download`) ganha `approval`, `view`.
- **Alterações**: `recipients.role` ∈ signer|witness|approver|viewer; `approval_decisions` (recipient_id, decision ∈ approved|rejected, reason, decided_at, ip, ua, snapshot_hash) para aprovador; testemunha assina como signer mas com campo obrigatório `signature` rotulado "Testemunha" e `consent_statement` próprio; visualizador não bloqueia conclusão e recebe o final.
- **Riscos e decisões**: aprovador **antes** dos signatários na ordem sequencial (rejeição encerra como `refused` com `refusal_reason` do aprovador) — decisão pendente sobre aprovador em ordem paralela (recomendado: sempre etapa própria); testemunha precisa de aceite completo (OTP + snapshot) — não é "cc"; visualizador não conta na cota nem em `signed_count`.
- **Aceite**: máquina de estados testada por papel (aprovador rejeita → `refused`; visualizador nunca impede `finalizing`); evidências listam papel de cada participante; rótulos PT-BR novos em `ROUTES_AND_PAGES.md` §6.

### 2.5 Lembretes automáticos e envio agendado

- **Objetivo**: o remetente define cadência de lembretes (ex.: a cada 2 dias, máximo N) e pode agendar o envio do envelope para data/hora futura.
- **Extensão prevista**: `features.reminders`; `envelopes.settings` JSON; `recipients.notification_count`/`last_notified_at`; `envelopes.recipients.resend` (reenvio manual, Q11) reaproveitado como ação do lembrete; `delivery_attempts.purpose` ganha `reminder`; Scheduler já roda `ExpireEnvelopes` a cada 15 min.
- **Novas entidades**: `reminder_schedules` (envelope_id, interval_hours, max_count, next_run_at, sent_count, stopped_at, stop_reason); `envelopes.scheduled_send_at` + status transitório `scheduled` (entre `ready` e `in_progress`) ou flag em `settings` — decisão pendente (recomendado: coluna + job `SendScheduledEnvelopes`, sem novo status, para não alterar o enum público).
- **Riscos e decisões**: lembrete **para em estado terminal** e quando o recipient já é `signed|refused` — verificação no job, não só no agendamento; respeita `settings.max_resends` (Q11) e o throttle de 10 min; envio agendado revalida cota e `ready` no momento do disparo (o envelope pode ter sido editado); fuso da organização para "às 9h".
- **Aceite**: teste com clock congelado cobre cadência, limite, parada em `completed|refused|expired|canceled`, e envio agendado que falha por cota volta a `ready` com notificação ao remetente.

### 2.6 Assinatura presencial em tablet

- **Objetivo**: várias pessoas assinam no mesmo dispositivo (balcão, vistoria) sem trocar de conta, cada uma com identidade e aceite próprios.
- **Extensão prevista**: `signing_sessions` (uma por recipient, com `token_digest` e `authorization_token_digest`); `auth_challenges.channel`; `signature_acceptances` com ip/ua; fluxo público §4 da arquitetura.
- **Novas entidades**: `in_person_sessions` (envelope_id, host_user_id, device_label, started_at, ended_at, current_recipient_id) e `auth_method` ∈ `in_person_host` (o remetente autenticado atesta a presença) combinado, quando possível, com OTP do próprio participante (SMS/WhatsApp, §2.9).
- **Riscos e decisões**: **nunca reutilizar a sessão de outro participante** — ao trocar de pessoa, a `signing_session` anterior é `consumed|revoked`, cookies/estado local limpos e nova sessão criada; o aceite grava `witnessed_by_user_id` e o participante recebe cópia por e-mail/telefone próprio; a prova é mais fraca que OTP remoto e a UI diz "presencial, atestado por {host}"; decisão pendente sobre exigir foto do documento (§2.10) no presencial.
- **Aceite**: teste de navegador com dois participantes em sequência no mesmo dispositivo, garantindo isolamento de sessão; evidências registram `in_person` e o host; um participante não consegue abrir o documento do outro após a troca.

### 2.7 Assinatura em lote

- **Objetivo**: um mesmo signatário com vários envelopes pendentes aceita cada um com um clique, sem repetir OTP por envelope.
- **Extensão prevista**: `recipients.email` como chave de agrupamento; `signing_sessions.authorization_token_digest` (token de autorização final, 10 min, ligado a `snapshot_hash`); `UNIQUE(recipient_id)` em `signature_acceptances`.
- **Novas entidades**: `batch_signing_sessions` (email_digest, otp challenge único, expires_at) que **emite uma `signing_session` por envelope**; página `/assinar/lote/{token}` lista os envelopes com o texto de aceite de cada um.
- **Riscos e decisões**: **autorização por item** — cada aceite exige o token de autorização daquele envelope e revalida sob lock tudo que o aceite unitário revalida (`arquitetura.md` §3.3); não existe "aceitar todos" com um POST único; campos obrigatórios por envelope precisam ser preenchidos individualmente (a assinatura desenhada pode ser reutilizada na sessão, o aceite não). Decisão pendente: lote só para envelopes da **mesma organização** remetente (recomendado) ou de qualquer remetente para o mesmo e-mail.
- **Aceite**: OTP único → N sessões; cada aceite gera `signature_acceptance` com seu próprio `snapshot_hash`; falha em um item não afeta os outros; teste de que um token de autorização não serve para outro envelope.

### 2.8 Branding (logo/cores) e remetente próprio

- **Objetivo**: e-mails, página do signatário e evidências exibem logo e cores da organização; e-mails saem "de" um domínio do cliente.
- **Extensão prevista**: `features.branding`; `current_organization.logo_url` (sempre `null` na Fase 1); `SignerLayout` mostra org remetente + "via AssinaVelox"; `LaravelMailEmailProvider` (SMTP/HTTP do serviço próprio de e-mail); `FieldType.stamp` reservado.
- **Novas entidades**: `organization_brandings` (logo_path, primary_color, accent_color, email_footer, updated_by); `sender_domains` (organization_id, domain, status ∈ pending|verified|failed, dkim_selector, verification_token, verified_at, provider_domain_id); `organizations.settings.sender_from_name`.
- **Riscos e decisões**: verificação de domínio **no provedor de e-mail já existente** (registros DKIM/SPF/Return-Path que o provedor exige; consulta de status pela API do provedor — T4: só com documentação); sem domínio verificado o remetente continua o da plataforma com `Reply-To` do cliente; logo validado com GD (PNG/SVG rasterizado, ≤ 1 MB, dimensões máximas) e servido por controller autorizado ou disco público com nome opaco; cores passam por validação de contraste mínimo (acessibilidade da página pública). O rodapé "via AssinaVelox" e o link de verificação **não** são removíveis (whitelabel é backlog §4).
- **Aceite**: e-mail de convite com logo/cores e `From` do domínio verificado; domínio não verificado degrada de forma explícita; snapshot de e-mail testado; upload de arquivo malicioso rejeitado.

### 2.9 OTP por SMS/WhatsApp e PIN do remetente

- **Objetivo**: além do e-mail, o código de confirmação pode ir por SMS ou WhatsApp; o remetente pode exigir um PIN combinado fora do sistema.
- **Extensão prevista**: `auth_challenges.channel ENUM(email, sms, whatsapp)` e `delivery_attempts.channel` já contemplam os canais; `AuthMethod` reservado para `sms_otp`, `whatsapp_otp`; contratos `SmsProvider`, `WhatsAppProvider`; `recipients.phone`; `features.sms_whatsapp`; `organizations.settings.otp_required`.
- **Novas entidades**: implementações `HttpSmsProvider`/`HttpWhatsAppProvider` para os **serviços próprios** já contratados (fakes até a documentação estar disponível — T4); `recipients.auth_method` passa a aceitar `sms_otp|whatsapp_otp|sender_pin`; `recipient_pins` (recipient_id, pin_hash HMAC como `code_hash`, attempts, max_attempts) — o PIN é definido pelo remetente no wizard, nunca enviado pelo sistema.
- **Riscos e decisões**: custo por mensagem → limites por org/plano e por link (mesmos limites do OTP por e-mail); número em E.164 validado (`libphonenumber`); PIN sozinho é fator fraco → sempre combinado com OTP de algum canal (decisão pendente se PIN pode substituir OTP para `otp_required=false`); WhatsApp depende de §2.18 para entrega; `status=unknown` em timeout (T5).
- **Aceite**: signatário escolhe canal disponível; desafio com canal SMS gera `delivery_attempt` correspondente; PIN incorreto conta tentativa e bloqueia após `max_attempts`; testes de contrato dos adaptadores com fixtures; evidências registram `auth_method` real.

### 2.10 Captura de selfie e documento

- **Objetivo**: opcionalmente, o signatário fotografa o rosto e um documento de identidade durante o aceite, anexados como evidência.
- **Extensão prevista**: `signature_acceptances` (snapshot, ip, ua); `IdentityVerificationProvider` reservado (não usado aqui); disco privado `documents`; normalização de imagem via GD já existente para assinaturas.
- **Novas entidades**: `identity_captures` (signature_acceptance_id, kind ∈ selfie|document_front|document_back, storage_path, sha256, captured_at, device_meta JSON minimizado); `envelopes.settings.require_identity_capture`.
- **Riscos e decisões**: é **captura simples** — a UI e as evidências dizem "foto capturada", **nunca "liveness", "biometria" ou "identidade verificada"** (isso é backlog §4 via provedor); dados biométricos são sensíveis (LGPD art. 11): base legal, retenção curta configurável (§2.19), criptografia em repouso, acesso só ao remetente com log; imagens reprocessadas (EXIF removido, tamanho máximo); mostrar a foto na página de evidências é decisão pendente (recomendado: só miniatura para o remetente, nunca no PDF de evidências público).
- **Aceite**: captura via `getUserMedia` com fallback de upload; arquivo salvo no disco privado com hash referenciado nas evidências; download só pelo remetente autorizado; exclusão pela política de retenção testada.

### 2.11 Consulta CNPJ e validação de CPF

- **Objetivo**: preencher razão social/endereço a partir do CNPJ no cadastro e no faturamento; validar CPF do signatário quando o remetente exigir.
- **Extensão prevista**: contratos `CnpjLookupProvider` e `CpfVerificationProvider`; `organizations.tax_id` (criptografado); `FieldType.cpf` reservado; `billing.profile.update`.
- **Novas entidades**: `BrasilApiCnpjLookup` ou `MinhaReceitaCnpjLookup` (a avaliar: disponibilidade, limites, termos de uso — só com documentação pública real, T4) com cache por CNPJ (TTL dias) em `cnpj_lookups`; `CpfVerificationProvider` implementado sobre o **serviço próprio** existente; `signing_field_values.value_text` do campo `cpf` validado por dígitos e, se exigido, pelo provedor; resultado `verified|not_verified|unavailable` gravado em `fields_snapshot`.
- **Riscos e decisões**: CNPJ público é dado cadastral (baixo risco); CPF é dado pessoal — só consultar com finalidade declarada e resposta minimizada (não persistir nome/nascimento retornados além do resultado); provedor indisponível **não bloqueia** o aceite, apenas registra `unavailable` (decisão de produto pendente para o modo "estrito"); fake em dev e testes.
- **Aceite**: cadastro preenche dados pela consulta com fallback manual; campo `cpf` rejeita dígitos inválidos no cliente e no servidor; evidências mostram o resultado da validação sem expor o CPF completo (mascarado como o e-mail).

### 2.12 Assinatura individual com A1/PFX do usuário

- **Objetivo**: participante que possui certificado A1 (PFX) assina criptograficamente com **o próprio certificado**, além do aceite eletrônico; a assinatura da operadora continua sendo a última.
- **Extensão prevista**: `certificate_references` (`organization_id` nulo = operadora; `kind ENUM(company_a1)` extensível; `environment`, `secret_ref`, `fingerprint_sha256`); `PdfSigner`/`PyHankoSigner` e `pdftool sign|validate`; `document_versions.kind` e `has_signatures`; `verification_records.signature_status ENUM(none, company_a1)`; `signature_profile`; Q19 (certificado por organização como extension point).
- **Novas entidades**: `certificate_references.kind` ganha `personal_a1`, `organization_a1`; `participant_signatures` (signature_acceptance_id, certificate_reference_id nullable, subject, issuer, serial, fingerprint, signed_document_version_id, revision_index, validation_result JSON, signed_at); `document_versions.kind` ganha `signed_incremental`; `signature_status` ganha `participants_a1`, `mixed` (participantes + operadora); `envelopes.crypto_mode ∈ none|company_only|incremental`.
- **Pipeline incremental serializado**: (1) no aceite, o participante envia o PFX + senha em uma requisição com **consentimento específico** ("autorizo o uso deste certificado para assinar este documento") — arquivo em disco temporário exclusivo, senha só em memória; (2) `pdftool inspect-cert` valida antes de qualquer uso: senha, cadeia até âncora confiável (ICP-Brasil ou outra, rotulada), validade, key usage (`digitalSignature`/`nonRepudiation`), revogação (CRL/OCSP quando disponível) e que o CPF/CN corresponde ao recipient (regra de correspondência a decidir); (3) o job `ApplyParticipantSignatureJob` é enfileirado por envelope com `WithoutOverlapping('envelope:{id}:sign')` + `ShouldBeUnique`, garantindo **um único worker por envelope**; (4) o job lê a revisão mais recente (`kind=signed_incremental` ou, na primeira, a `sent`), grava como *incremental update* os campos daquele participante (widgets de aparência, texto, data) e em seguida a assinatura PAdES do participante com `pdftool sign --incremental` — o arquivo anterior é copiado byte a byte e só se acrescenta ao final; (5) `pdftool validate` confirma que **todas** as revisões anteriores continuam válidas (ByteRange íntegro, sem modificações não permitidas segundo a análise de diferenças do pyHanko); (6) nova `document_version` com `version_number+1`, hash, `revision_index`; (7) o PFX temporário e a senha são destruídos ao final do job, com sucesso ou falha (retenção mínima; retenção do PFX para reuso é decisão pendente e, se aceita, exige criptografia com chave de KMS e TTL).
- **Por que participação paralela não permite dois workers na mesma revisão**: uma assinatura PAdES cobre, pelo `/ByteRange`, todos os bytes do arquivo até ela; a revisão N+1 só existe como *acréscimo* à revisão N. Dois workers partindo da mesma revisão N produziriam dois arquivos irmãos, cada um válido isoladamente, mas **impossíveis de fundir**: a assinatura do segundo não cobre os bytes acrescentados pelo primeiro e vice-versa, e qualquer tentativa de "juntar" invalida uma delas. Por isso o **aceite eletrônico continua paralelo** (cada participante aceita quando quiser, com seu `snapshot_hash`), mas a **aplicação criptográfica é serializada** na ordem de chegada dos aceites, sob lock por envelope, um job por vez, e cada job revalida a cadeia antes e depois de gravar.
- **Riscos e decisões**: com assinaturas incrementais, a página de evidências **não pode mais ser anexada** ao PDF final (acrescentar páginas é modificação não permitida sobre revisões assinadas) → em `crypto_mode=incremental` as evidências ficam como `kind=evidence` separado e no dossiê (§2.13); usar assinaturas de aprovação (não certificação) para permitir revisões seguintes; o bloqueio de PDF "já assinado" na preparação (`blocked`) precisa distinguir assinaturas externas de revisões próprias; A1 do participante que não seja ICP-Brasil é aceito mas rotulado pela cadeia real (T1/T3); falha em uma assinatura não desfaz aceites já registrados — o envelope fica em `finalizing` com alerta e retentativa idempotente.
- **Aceite**: dois participantes paralelos assinando com A1 geram exatamente duas revisões em ordem, ambas válidas no pyHanko e em validador externo; PFX inválido/expirado/revogado é recusado com mensagem clara e nada é gravado; nenhum segredo aparece em fila, log ou argv (teste de redaction); `verification_records` lista cada assinante criptográfico com cadeia e resultado; a UI diferencia "aceite eletrônico" de "assinado com certificado A1 de {nome} (emitido por {AC})".

### 2.13 TSA própria RFC 3161 e exportação de dossiê ZIP

- **Objetivo**: carimbo do tempo RFC 3161 emitido por TSA **operada pela AssinaVelox** (ou comercial) sobre o hash final, e download do dossiê completo do envelope em ZIP.
- **Extensão prevista**: `TimestampProvider` reservado; `verification_records` (hashes, `validation_result`, `signature_profile`); `document_versions.kind ∈ evidence|final`; `audit_events` com comando de exportação com hash (checkpoint); `envelopes.bulk` download em lote (Q12); `envelopes.download/{type}`.
- **Novas entidades**: `Rfc3161TimestampProvider` (cliente HTTP para TSA própria — ex.: TSA baseada em OpenSSL `ts` ou pyHanko `TimeStamper` — ou comercial), `timestamp_tokens` (envelope_id, document_version_id, tsa_kind ∈ operator|commercial|icp_brasil, tsa_url, tsr_path, hash_algorithm, imprint, gen_time, serial, tsa_cert_fingerprint); `dossier_exports` (envelope_id, requested_by, status, storage_path, sha256, expires_at) + job `BuildDossierJob`; `bulk_downloads` reutiliza `dossier_exports` com N envelopes.
- **Conteúdo do ZIP**: `manifest.json` (envelope, participantes com dados mascarados conforme `evidence_show_ip`, versões e hashes por arquivo, `verification_code`, versões dos termos), PDFs `original`/`sent`/`final`, PDF de evidências, `audit_trail.json` + CSV, `validation.json` (saída do pyHanko), `.tsr` dos carimbos, `README.txt` explicando como conferir cada hash. **Nunca**: tokens, digests de OTP, segredos, PFX, chaves de webhook, e-mails completos quando a política mascarar.
- **Riscos e decisões**: TSA própria prova apenas "a AssinaVelox atesta que este hash existia em T" — rótulo `tsa_kind=operator` na UI e nos Termos; **não é carimbo ICP-Brasil** (T3; isso é Fase 3 §3.6); carimbo embutido no PDF (`PAdES-B-T`) só depois de testado (T2) — até lá o `.tsr` fica destacado no dossiê e referenciado em `verification_records.validation_result`; proteção da chave da TSA (HSM/KMS ou arquivo com permissões restritas) e monitoramento de relógio (NTP) são pré-requisitos operacionais; ZIP gerado em fila com limite de tamanho e link de download autorizado com expiração (Q23).
- **Aceite**: `.tsr` validado contra o hash final por comando independente (`openssl ts -verify`); dossiê reproduzível (mesmo envelope → mesmos hashes internos); teste automatizado varre o ZIP procurando padrões de segredo; download em lote de 50 envelopes conclui em fila sem timeout HTTP.

### 2.14 Permissões configuráveis, times, tags, relatórios e logs administrativos

- **Objetivo**: funções personalizadas além de owner/admin/member, times com acesso por pasta, tags em envelopes, relatórios exportáveis e painel administrativo interno com auditoria.
- **Extensão prevista**: `memberships.role ENUM(owner, admin, member)` + `OrgPermissions` estático (Q2); `folders` + "Pastas com acesso" reservado no convite; `audit_events` (append-only, índices por org/data); `dashboard.export`/`recipients.export` (CSV); placeholders `admin.users.index`, `admin.audit.index`, `admin.settings.index`, `admin.billing.index`; `impersonations` (Q15); `organizations.segment` (filtro "Segmento").
- **Novas entidades**: avaliar **`spatie/laravel-permission`** com `team_id = organization_id` (teams habilitado) contra tabelas próprias `roles`/`role_permissions` — critério: cache por org, compatibilidade com Policies existentes e ausência de acoplamento ao guard do signatário; `teams` + `team_members` + `folder_permissions` (folder_id, team_id|membership_id, level ∈ view|edit|manage); `tags` + `envelope_tags` (UNIQUE por org e nome; filtro em `envelopes.index`); `report_exports` (tipo, filtros, período, status, arquivo) gerados em fila; `platform_audit_events` (ações de `is_platform_admin`, inclusive impersonation) separados dos eventos de negócio; `organizations.settings.allowed_ip_ranges` + middleware `EnsureIpAllowed` (switch "restrição de IP").
- **Riscos e decisões**: `owner` continua papel de sistema (não editável; invariante "≥1 owner"); `member` vê só os próprios envelopes (Q7) — permissão `envelopes.view_all` substitui essa regra fixa; escopo por pasta muda todas as queries de listagem e contagem (sidebar/dashboard) → precisa de escopo Eloquent único testado; impersonation exige `password.confirm`, banner persistente, bloqueio de ações financeiras e consentimento nos Termos; relatórios com dados pessoais respeitam mascaramento.
- **Aceite**: matriz de permissões dinâmica renderizada em `members.index`; teste garante que remover uma permissão bloqueia a rota correspondente; usuário de time sem acesso à pasta não vê o envelope nem por ULID direto; impersonation gera evento em `platform_audit_events` com início/fim; `admin.audit.index` lista e filtra sem `UPDATE`/`DELETE`.

### 2.15 API REST versionada

- **Objetivo**: clientes integram criação de envelopes, upload, recipients, campos, envio e consulta por API autenticada, com documentação OpenAPI.
- **Extensão prevista**: `features.api_integrations`; Sanctum (starter kit); placeholder `integrations.index`/`integrations.keys` reservando `api_tokens` e prefixo `/api/v1`; ULIDs públicos e opacos em todos os agregados; `Paginated<T>` (§0.4 de ROUTES_AND_PAGES) como base do formato; Policies por recurso e escopo por `organization_id`.
- **Novas entidades**: `personal_access_tokens` estendida com `organization_id`, `created_by_user_id`, `token_prefix` (exibição), `last_used_ip`, `expires_at`, `revoked_at`; abilities fechadas (`envelopes:read`, `envelopes:write`, `envelopes:send`, `documents:read`, `templates:read`, `webhooks:manage`, `organization:read`); `api_request_logs` (amostrado, sem corpo) para a aba "Logs"; `ApiVersion` middleware (`/api/v1`, depreciação por header `Sunset`); `Idempotency-Key` em POSTs com `api_idempotency_keys` (token_id, key, request_hash, response, expires_at).
- **Contrato**: JSON com `data`/`meta`/`links`, paginação por cursor, erros em RFC 9457 (`application/problem+json`) com `type` estável e `correlation_id`, limites por token e por org (`RateLimiter::for('api')`, cabeçalhos `RateLimit-*`), upload multipart ou por URL **não** (só multipart, evita SSRF), downloads por stream autorizado; OpenAPI 3.1 gerado a partir de atributos/Requests e validado em CI; SDKs gerados só na Fase 3 (§3.9).
- **Riscos e decisões**: token é da **organização** (não do usuário) mas com autor registrado — a autorização usa as permissões do autor no momento da chamada ou um papel próprio "integração"? (decisão pendente; recomendado: papel `integration` com permissões explícitas); versionamento por caminho é irreversível → congelar o contrato só depois de §2.3/§2.4 (multi-documento e papéis mudam os recursos); nunca expor `id` interno; webhooks (§2.16) são o par obrigatório para evitar polling agressivo.
- **Aceite**: fluxo completo por API (criar → upload → recipients → campos → enviar → consultar → baixar) coberto por testes de feature; token sem ability recebe 403 com problem details; idempotência devolve a mesma resposta; spec OpenAPI válida e publicada em `integrations.index`; rate limit testado.

### 2.16 Webhooks de saída

- **Objetivo**: notificar sistemas do cliente sobre eventos do envelope com entrega assinada, retentativas e histórico.
- **Extensão prevista**: catálogo `audit_events.event_type` (`RECONCILIACAO.md` §3) como fonte dos eventos publicáveis; placeholder `integrations.logs` reservando `webhook_endpoints`/`webhook_deliveries`; linha "Webhook" nos Detalhes; evento `webhook_failed` em Notificações; `correlation_id`.
- **Novas entidades**: `webhook_endpoints` (organization_id, url, secret criptografado, events JSON, is_active, consecutive_failures, disabled_at, created_by); `webhook_deliveries` (endpoint_id, envelope_id, event_type, delivery_id ULID único, payload JSON, attempt, status ∈ pending|delivered|failed|exhausted, response_code, response_ms, error, next_retry_at, delivered_at); job `DeliverWebhookJob` com backoff (1 min, 5 min, 30 min, 2 h, 12 h, 24 h; máx. 8 tentativas), reenvio manual em `integrations.logs`, desativação automática após N falhas consecutivas + notificação `webhook_failed`.
- **Assinatura**: cabeçalhos `X-AssinaVelox-Delivery-Id`, `X-AssinaVelox-Timestamp`, `X-AssinaVelox-Signature: v1=HMAC-SHA256(secret, "{timestamp}.{raw_body}")`; janela de tolerância de 5 min documentada; rotação de segredo com período de convivência (dois segredos válidos).
- **Bloqueio SSRF**: só `https://`; resolução DNS **antes** da conexão e rejeição de qualquer IP privado, loopback, link-local, multicast, reservado ou de metadados de nuvem; conexão pinada no IP resolvido (evita *DNS rebinding* entre validação e conexão); sem seguir redirecionamentos (ou revalidação por salto); timeouts curtos (5 s conexão, 10 s total); resposta lida só até um limite e nunca interpretada; revalidação a cada tentativa (o DNS pode mudar). Testes de segurança com hosts que resolvem para faixas internas.
- **Riscos e decisões**: payload mínimo (ULIDs, status, hashes, links autorizados) — nunca PDF nem dados pessoais completos; ordem de entrega não garantida (documentar `occurred_at`); `status=unknown` em timeout conta como falha para retentativa mas o cliente pode receber duplicado → `delivery_id` para deduplicar; retentativas param quando o endpoint é desativado ou removido.
- **Aceite**: entrega assinada verificável pelo exemplo da documentação; retentativa com backoff e parada testadas com clock congelado; SSRF bloqueado antes e após DNS (teste com resolvedor fake); reenvio manual cria nova tentativa com o mesmo `delivery_id`; página de logs filtra por endpoint/status.

### 2.17 Integração n8n / Zapier / Make

- **Objetivo**: usuários sem desenvolvedor conectam a AssinaVelox a outros sistemas por conectores prontos.
- **Extensão prevista**: depende inteiramente de §2.15 (API) e §2.16 (webhooks); autenticação por token de API com abilities.
- **Novas entidades**: endpoints de assinatura dinâmica de webhooks (`POST/DELETE /api/v1/webhook-subscriptions`, padrão *REST Hooks* usado por Zapier/Make); definição de app **Zapier** (Platform CLI, auth `api_key`, triggers por webhook + polling de fallback, actions "criar envelope a partir de modelo", "enviar", "baixar final"); app **Make** custom; para **n8n**, nó comunitário publicado ou receitas oficiais com o nó HTTP Request — decisão pendente sobre manter nó próprio.
- **Riscos e decisões**: a publicação em marketplaces exige o contrato da API congelado (mudança quebra apps de terceiros) e revisão dos vendors (Zapier exige app público com testes); endpoints de subscrição são webhooks comuns e herdam SSRF/HMAC de §2.16; contas de integração devem ter papel `integration` com permissões mínimas.
- **Aceite**: Zap/cenário de exemplo "PDF no Drive → envelope enviado → status no Sheets" funciona de ponta a ponta em conta de teste; documentação de conectores em `integrations.index`; testes de contrato dos endpoints de subscrição.

### 2.18 WhatsApp Business via serviço próprio

- **Objetivo**: convites, lembretes, OTP e cópia final entregues por WhatsApp usando o serviço de WhatsApp Business já contratado pela operadora.
- **Extensão prevista**: `WhatsAppProvider` reservado; `delivery_attempts.channel=whatsapp` e `status` (`sent` ≠ `delivered`); `auth_challenges.channel=whatsapp`; `recipients.phone`; `features.sms_whatsapp`; não adotados: Evolution/WPPConnect/Baileys (`arquitetura.md` §1).
- **Novas entidades**: `HttpWhatsAppProvider` sobre a API oficial documentada do serviço (T4), com **templates de mensagem pré-aprovados** por finalidade (convite, OTP, lembrete, concluído) em `messaging_templates` (provider_template_id, language, status); webhook de entrada `POST /webhooks/whatsapp` (assinatura verificada, idempotente por `message_id`) atualizando `delivery_attempts` para `delivered|read|failed`; `recipients.whatsapp_opt_in_at`.
- **Riscos e decisões**: opt-in do destinatário e janela de conversa são regras do canal — sem opt-in, cai para e-mail; custo por conversa → limites por plano; número da operadora vs número do cliente (decisão pendente; começa com o da operadora); mensagem nunca carrega o documento, só link com token de convite; entrega `unknown` em timeout (T5); *rate limits* do canal tratados com fila própria `messaging`.
- **Aceite**: convite por WhatsApp com fallback automático para e-mail após falha definitiva; status de entrega refletido em `recipients.index` (filtro "Canal" reativado); OTP por WhatsApp válido de ponta a ponta em teste de contrato; webhook de entrada rejeita assinatura inválida.

### 2.19 Retenção configurável e bloqueio de exclusão por preservação

- **Objetivo**: a organização define por quanto tempo guarda documentos e evidências; envelopes sob preservação legal não podem ser excluídos por ninguém até a liberação.
- **Extensão prevista**: `organizations.deletion_requested_at` (exclusão da org após 30 dias); `envelopes.deleted_at` (soft delete); disco privado `documents` (Q23); `document_versions` imutáveis; `signing_field_values.image_path`, `signature_acceptances.signature_image_path`; `audit_events` append-only sem PII além de IDs e e-mails mascarados.
- **Novas entidades**: `retention_policies` (organization_id, scope ∈ completed|terminal_other|draft, retain_days, action ∈ delete_files|delete_all, is_active); `preservation_holds` (envelope_id, reason, created_by, released_at, released_by); job `ApplyRetentionPoliciesJob` (diário, por lote, idempotente) + `PurgeEnvelopeJob`; `deletion_receipts` (envelope_id, what_was_deleted JSON de caminhos/hashes, deleted_at) para provar a exclusão sem guardar o conteúdo.
- **Propagação**: excluir um envelope apaga, na ordem, objetos de storage de todas as `document_versions`, imagens de assinatura e capturas (§2.10), miniaturas em cache, `dossier_exports` e `timestamp_tokens` derivados; os registros relacionais podem ficar como tombstone (hashes sem arquivo) para a página de verificação responder "excluído por política em {data}" — decisão pendente. **Backups**: não há exclusão seletiva em snapshots; a política declara a janela de rotação (ex.: 35 dias) na Política de Privacidade e o job só considera "excluído" após essa janela.
- **Riscos e decisões**: `audit_events` não pode receber `UPDATE`/`DELETE` em produção (T7) → a única forma de "esquecer" dados é nunca gravá-los lá (payload já minimizado); com hold ativo, `envelopes.destroy`, `settings.organization.destroy` e o job de retenção **recusam** (Policy + verificação no job); política mais curta que o prazo dos Termos/obrigações legais precisa de aviso explícito; `verification_records` pode permanecer (só hashes).
- **Aceite**: teste com clock congelado apaga arquivos e gera `deletion_receipt`; hold impede exclusão manual, em lote e por job, com mensagem clara; exclusão da organização respeita holds (bloqueia ou transfere — decisão pendente); `storage:verify` confirma que nenhum objeto órfão sobrou.

### 2.20 Pagamentos ampliados

- **Objetivo**: mais meios (Pix, cartão, boleto) conforme suporte do gateway, cancelamento, estorno, tratamento de inadimplência e conciliação diária.
- **Extensão prevista**: `PaymentGateway`/`MercadoPagoGateway` (Checkout Pro, SDK oficial); `payments.status` espelha o Mercado Pago (inclui `refunded`, `charged_back`, `cancelled`); `payment_webhook_receipts` idempotente; `subscriptions` (`past_due` após 3 dias, `expired` após 15 — Q20); `mp_preapproval_id` reservado; recibo interno `billing.payments.receipt`; `plan_consumptions` ledger.
- **Novas entidades**: `payment_refunds` (payment_id, provider_refund_id, amount_cents, status, reason, requested_by, created_at); `dunning_notices` (subscription_id, stage ∈ d_minus_3|d0|d_plus_3|d_plus_10, sent_at) com job `SendDunningNoticesJob`; `reconciliation_runs` + `reconciliation_items` (payment_id, local_status, provider_status, divergence) com job diário consultando a API de busca por `external_reference`; `payments.payment_method_type` (pix, credit_card, ticket) e `expires_at` para Pix/boleto pendentes.
- **Riscos e decisões**: Pix/boleto/cartão já são opções do Checkout Pro — "ampliar" é configurar `payment_methods` na preferência e tratar `pending` de longa duração (expirar `Payment` e a preferência); estorno só via API oficial e só por owner com `password.confirm`, refletido em `plan_consumptions` (quota já consumida não é devolvida — decisão de produto); `charged_back` suspende envio como `past_due`; **recorrência**: Preapproval do Mercado Pago só com cartão — Pix/boleto continuam avulsos por ciclo com link de pagamento a cada vencimento; conciliação não corrige status automaticamente, só marca divergência para revisão (o webhook + consulta continuam a fonte da verdade); relatórios do admin (`admin.billing.index`) leem essas tabelas.
- **Aceite**: cenários de webhook (aprovado, pendente→expirado, estornado, chargeback) cobertos com fixtures reais do sandbox; conciliação detecta pagamento aprovado sem webhook recebido; e-mails de inadimplência nos estágios corretos com clock congelado; nenhuma ativação de plano fora de `activated_at` único.

### 2.21 NFS-e automática (integração fiscal independente)

- **Objetivo**: emitir Nota Fiscal de Serviço eletrônica para cada pagamento aprovado, com PDF/XML disponíveis em `billing.index`.
- **Extensão prevista**: `FiscalInvoiceProvider` reservado; botão "NF-e" oculto em `billing.index` (rótulo será **NFS-e**); `billing.profile.update` (dados de faturamento); `payments` (`approved`, `amount_cents`, `payer_email_masked`); recibo interno **≠ documento fiscal** (Q21).
- **Novas entidades**: `fiscal_profiles` (organization_id, document_number, legal_name, address, municipality_code, email_for_invoice, is_foreign); `fiscal_invoices` (payment_id UNIQUE, provider, external_id, number, series, verification_code, status ∈ pending|issued|canceled|failed, pdf_path, xml_path, issued_at, canceled_at, error, idempotency_key); job `IssueFiscalInvoiceJob` disparado em `payments.approved`, idempotente por `payment_id`; job de cancelamento em estorno total.
- **Riscos e decisões**: **verificar se o Mercado Pago expõe API de emissão fiscal utilizável para o vendedor** — se não (hipótese provável), escolher provedor de NFS-e por `FiscalInvoiceProvider` (candidatos a avaliar com documentação pública: emissores integrados ao padrão nacional da NFS-e e/ou à prefeitura do município da operadora); **não usar NF-e modelo 55 (mercadorias) para serviço** sem análise contábil; regras municipais (ISS, código de serviço, Simples Nacional, retenções) são decisão contábil da operadora, não do software; certificado A1 da operadora pode ser exigido pelo emissor (reusar `certificate_references` com `kind=fiscal_a1`, `secret_ref`); timeout na emissão → consulta antes de reemitir (T5); guardar XML pelo prazo legal independentemente de §2.19.
- **Aceite**: pagamento aprovado → nota emitida uma única vez com PDF/XML baixáveis pelo owner/admin; falha do provedor gera retentativa e alerta no admin sem duplicar nota; estorno total cancela a nota quando o provedor permite; fake do provedor cobre todos os estados em teste.

## 3. Fase 3 — Avançada

Pré-requisitos gerais: Fase 2 ativa em produção há pelo menos um ciclo completo de cobrança; API congelada em `v1`; matriz de testes da Fase 2 verde. Cada item abaixo repete a estrutura da Fase 2 de forma mais resumida — o detalhamento entra em `arquitetura.md` quando o item for iniciado.

### 3.1 Geração documental em lote

- **Objetivo**: a partir de um modelo e de uma planilha (CSV/XLSX), gerar dezenas ou centenas de envelopes, cada um com seus signatários e variáveis.
- **Extensão prevista**: §2.1 (templates tipados), §2.5 (envio agendado), §2.15 (API), `plan_consumptions` (reserva por envelope), filas `conversions` (LibreOffice).
- **Novas entidades**: `bulk_generations` (template_id, source_file, row_count, status, created_count, failed_count, dry_run), `bulk_generation_rows` (row_index, payload, envelope_id, error); job orquestrador que enfileira `GenerateEnvelopeFromRowJob` por linha com limite de concorrência.
- **Riscos e decisões**: validação prévia de todas as linhas (dry run) antes de consumir cota; conversão DOCX em massa satura o LibreOffice → fila dedicada com `WithoutOverlapping` por worker e limite por plano; planilha é dado não confiável (T6: fórmulas e macros ignoradas; leitura por biblioteca sem avaliação).
- **Aceite**: 200 linhas geram 200 envelopes `ready` ou relatório de erros por linha; cota reservada só para linhas válidas; cancelar o lote interrompe os jobs pendentes sem afetar os já enviados.

### 3.2 Detecção de campos por âncoras (OCR para escaneados)

- **Objetivo**: posicionar campos automaticamente a partir de textos-âncora no documento (ex.: `{{assinatura:comprador}}` ou "Assinatura do locatário"), inclusive em PDFs escaneados via OCR.
- **Extensão prevista**: `signing_fields` (geometria normalizada ao CropBox com rotação, `arquitetura.md` §3.1); `pdftool inspect`; `template_fields` (§2.1); `pages_meta`.
- **Novas entidades**: `pdftool find-anchors` (extração de texto com coordenadas via pypdf/pdfplumber; para escaneados, OCR local — avaliar Tesseract por processo isolado) devolvendo caixas normalizadas; `field_anchor_rules` por template (pattern, field_type, offset, size); `documents.ocr_status`.
- **Riscos e decisões**: OCR em processo isolado, sem rede, com timeout, como o LibreOffice; texto extraído é não confiável (T6); precisão do OCR em escaneados de baixa qualidade → campos sugeridos sempre passam pela revisão no editor antes de `ready` (decisão: nunca envio automático sem revisão quando houver OCR); custo de CPU → fila própria e limite por plano.
- **Aceite**: fixture com âncoras em páginas rotacionadas posiciona corretamente; escaneado legível detecta ≥ 90% das âncoras da fixture; falha de OCR não bloqueia o fluxo manual.

### 3.3 Etapas condicionais, delegação auditada, multilíngue e aceite por vídeo/foto

- **Objetivo**: fluxos com ramificação (ex.: aprovador decide qual grupo assina), signatário que delega a outra pessoa com registro, interface e e-mails em outros idiomas, e aceite complementado por vídeo curto ou foto.
- **Extensão prevista**: `envelopes.signing_order`/`current_order` e `recipients.order_index`; papéis de §2.4; `recipients.locale` (Q24); capturas de §2.10; `audit_events`.
- **Novas entidades**: `signing_steps` (envelope_id, index, condition JSON avaliada por regras fechadas, não código) substituindo o inteiro `current_order`; `delegations` (from_recipient_id, to_recipient_id, reason, delegated_at, ip, ua, approved_by_sender_at) — o delegado é um novo `recipient` com convite próprio e o original fica `delegated` (novo `RecipientStatus`); traduções de e-mails, página pública, termos e evidências (`lang/{pt_BR,en,es}`), fuso/idioma por recipient; `identity_captures.kind` ganha `video`, com limite de duração/tamanho e transcodificação isolada (avaliar ffmpeg por processo controlado).
- **Riscos e decisões**: delegação exige política do remetente (`settings.allow_delegation`) e, por padrão, confirmação do remetente; o aceite do delegado é dele — nunca "em nome de" sem registro; vídeo é **captura**, não prova biométrica (T1/backlog §4); tradução jurídica dos termos exige revisão profissional por idioma; condições avaliadas por motor de regras declarativo (comparações simples sobre decisões/campos), sem expressões arbitrárias.
- **Aceite**: fluxo condicional testado com ambos os ramos; delegação aparece nas evidências com quem, quando e por quê; página pública em `en` completa; vídeo armazenado no disco privado, referenciado por hash, nunca embutido no PDF.

### 3.4 Certificado A3 via componente local

- **Objetivo**: participante com certificado em token/cartão (A3, chave privada não exportável) assina o PDF no navegador com apoio de um componente instalado localmente.
- **Extensão prevista**: pipeline incremental serializado de §2.12 (`ApplyParticipantSignatureJob`, `participant_signatures`, revisões); `pdftool` com pyHanko, que suporta **assinatura externa** (preparar `/ByteRange` e placeholder, receber o CMS pronto e embutir); `certificate_references`; T1/T3.
- **Novas entidades**: `pdftool prepare-external` (gera revisão pendente + digest a assinar, guardado em `pending_external_signatures` com TTL) e `pdftool embed-external` (recebe CMS/PKCS#7, embute, valida cadeia e ByteRange); componente local — **avaliar NexU** (open source, Java, expõe API HTTP em `localhost` para o navegador, usado por integradores europeus) contra alternativas ICP-Brasil; adaptador `LocalSignerBridge` no front (detecção, versão mínima, mensagens de instalação).
- **Limites de navegador/Laravel**: o navegador não acessa PKCS#11 nem o leitor; tudo passa pelo componente local via HTTP em `localhost` (CORS restrito à nossa origem, TLS local autoassinado — fricção de instalação); Laravel só vê o digest e o CMS — a chave nunca chega ao servidor; o digest tem validade curta e a revisão pendente é descartada se o CMS não chegar; usuário precisa de Java/driver do token; suporte por SO varia (Windows majoritário no público-alvo).
- **Riscos e decisões**: validação da cadeia contra âncoras ICP-Brasil (ACs raiz do ITI) com CRL/OCSP atualizadas por job — só então `signature_status` ganha `participant_icp_brasil`; certificado A3 fora da ICP-Brasil é aceito com o rótulo real; alternativa sem componente (assinatura em nuvem via PSC — prestador de serviço de confiança) é decisão futura e depende de T4; suporte ao usuário final é o maior custo operacional.
- **Aceite**: assinatura A3 de teste (token em ambiente controlado) gera revisão válida no pyHanko e em validador externo; CMS adulterado ou digest expirado é rejeitado; sem componente instalado a UI orienta e mantém o aceite eletrônico como caminho padrão.

### 3.5 Assinatura gov.br

- **Objetivo**: participante assina com a assinatura eletrônica avançada do gov.br (conta prata/ouro), sem certificado próprio.
- **Extensão prevista**: fluxo externo e revisões de §2.12/§3.4 (`pending_external_signatures`, `embed-external`); `auth_method` extensível; `verification_records.signature_status`.
- **Novas entidades**: `GovBrSignatureProvider` (OAuth2 do gov.br com escopo de assinatura → envio do hash → recebimento do PKCS#7), `external_signature_requests` (recipient_id, provider, expected_revision_sha256, state, status ∈ pending|completed|failed|expired, expires_at), `signature_status` ganha `participant_govbr`.
- **Riscos e decisões**: **elegibilidade e credenciais**: a API de assinatura gov.br exige credenciamento da aplicação junto ao governo e pode ter restrições de público — sem credencial oficial, item fica em contrato + fake (T4); a validação deve conferir (a) a cadeia do certificado emitido pela AC do gov.br, (b) que o conteúdo assinado é exatamente o digest da **revisão esperada** (`expected_revision_sha256`) e (c) que a revisão embutida continua íntegra; callback OAuth idempotente por `state`; a assinatura gov.br é "avançada" (Lei 14.063/2020), não "qualificada" — rótulo próprio (T1); janela de tempo entre preparar a revisão e receber o PKCS#7 concorre com outros participantes → o pipeline serializado precisa de "reserva" de revisão com expiração e reconstrução do digest se a base mudou.
- **Aceite**: fluxo completo em ambiente de homologação do gov.br; digest de revisão diferente da esperada é rejeitado; `state` reutilizado é rejeitado; UI e evidências dizem "assinatura gov.br (avançada)".

### 3.6 Carimbo ICP-Brasil via ACT credenciada e PAdES de longo prazo

- **Objetivo**: carimbos do tempo emitidos por Autoridade de Carimbo do Tempo credenciada pelo ITI e perfis PAdES B-T / B-LT / B-LTA para validade de longo prazo.
- **Extensão prevista**: `TimestampProvider`, `timestamp_tokens.tsa_kind` (§2.13), `verification_records.signature_profile`, `pdftool sign|validate` (pyHanko suporta B-T/LT/LTA), T2/T3.
- **Novas entidades**: `IcpBrasilTimestampProvider` (RFC 3161 com autenticação exigida pela ACT contratada, custo por carimbo → `timestamp_quota` por plano); job `RefreshArchiveTimestampJob` (re-carimbo antes da expiração do certificado da ACT/TSA, requisito do LTA); `verification_records.ltv_status` (revocation info embutida: sim/não, próxima renovação).
- **Riscos e decisões**: cada perfil só é anunciado após (1) `pdftool validate` passar, (2) validação em ferramenta externa independente (validador de conformidade do ITI e leitor PDF de referência) com fixtures reais e (3) teste de re-carimbo com clock avançado; LT exige coletar CRL/OCSP de todas as cadeias (participantes + operadora) no momento da assinatura — dependência de disponibilidade das ACs; custo do carimbo por documento entra no plano; TSA própria (§2.13) permanece como opção "operator" e nunca é apresentada como ICP-Brasil.
- **Aceite**: fixture `B-LTA` validada externamente em CI (quando o validador oferecer API/CLI) ou em checklist manual documentado por release; página de verificação mostra perfil, ACT e data do último re-carimbo; degradação para B-B quando a ACT está indisponível é explícita e registrada.

### 3.7 Antifraude baseado em eventos com revisão humana

- **Objetivo**: detectar padrões de abuso (spam de envelopes, phishing, força bruta de OTP, uso de cartões fraudados) e submeter casos a revisão humana antes de bloquear.
- **Extensão prevista**: `audit_events` (fonte de sinais), `delivery_attempts` (bounces), `auth_challenges.attempts`, `payments.status` (`charged_back`), `platform_audit_events` e admin de §2.14, rate limits da Fase 1.
- **Novas entidades**: `risk_signals` (organization_id, envelope_id nullable, rule_code, score, evidence JSON minimizado, occurred_at), `risk_reviews` (fila: status ∈ open|cleared|confirmed, reviewer_id, decision, notes), `organizations.risk_status ∈ normal|watch|restricted`; motor de regras declarativas (janelas de tempo, contagens, listas de domínios descartáveis) rodando em fila sobre eventos.
- **Riscos e decisões**: **nunca** bloqueio automático de aceites já registrados nem invalidação de evidências; ação automática máxima = suspender **envio** de novos envelopes (`restricted`) até revisão; explicabilidade obrigatória (regra + evidência) e registro da decisão humana; LGPD: legítimo interesse documentado, minimização (IP truncado, sem conteúdo de documento), avaliação de impacto; falsos positivos em clientes legítimos de alto volume → listas de confiança por org.
- **Aceite**: regras cobertas por testes com eventos sintéticos; fila de revisão no admin com decisão auditada; org `restricted` recebe erro claro em `envelopes.send`; relatório mensal de precisão da regra.

### 3.8 e-Notariado

- **Objetivo**: encaminhar atos que exigem reconhecimento/autenticação notarial para a plataforma oficial do notariado brasileiro.
- **Extensão prevista**: dossiê (§2.13), API (§2.15), `participant_signatures`.
- **Novas entidades**: apenas se houver **integração oficial documentada** (T4) do e-Notariado/CNB: adaptador `NotaryProvider` + `notary_requests` (envelope_id, status, external_id, ata/selo). Sem isso, o item se limita a exportar o dossiê e orientar o usuário.
- **Riscos e decisões**: não há garantia de API pública; qualquer automação de portal (scraping, automação de navegador) é proibida; papéis notariais exigem certificado ICP-Brasil dos envolvidos (§3.4) — pré-requisito duro; a AssinaVelox nunca se apresenta como cartório nem como substituta de ato notarial.
- **Aceite**: só é considerado "em andamento" quando existir contrato/homologação; até então o backlog registra "aguardando integração oficial".

### 3.9 Widget iframe, SDKs, Drive/Dropbox, HubSpot e SSO OIDC/SAML

- **Objetivo**: assinatura embutida no site do cliente, bibliotecas cliente para a API, importação de arquivos de nuvens, cartão no CRM e login corporativo.
- **Extensão prevista**: API `v1` e webhooks (§2.15/§2.16); `signing_sessions` (token bruto na sessão Laravel, `RECONCILIACAO.md` §1); cabeçalhos CSP e `X-Frame-Options` da Fase 1 (incremento 6); switch "SSO" desabilitado em `settings.general`; Fortify.
- **Novas entidades**: `embedded_signing_sessions` (recipient_id, api_token_id, allowed_origin, ttl curto, single-use URL) — **sessão restrita**: escopo só ao envelope, sem download de outros, sem "lembrar dispositivo"; `integration_settings.allowed_origins` por org; CSP `frame-ancestors` **dinâmico por sessão** (somente para rotas `/embed/*`; o resto do app continua `DENY`); mensagens `postMessage` tipadas (`assinavelox:ready|completed|refused|error`) com `targetOrigin` explícito e **validação de `event.origin`** nos dois lados; anti-clickjacking: além de `frame-ancestors`, o aceite final exige interação em elemento com confirmação visual e verificação de visibilidade (`IntersectionObserver`), e o widget recusa operar quando `document.visibilityState` indica oclusão suspeita; cookie da sessão embutida `SameSite=None; Secure; Partitioned` (restrições de cookies de terceiros → decisão pendente: token no fragmento da URL + memória como fallback); SDKs gerados da OpenAPI (PHP, Node, Python) + `embed.js`; conectores Drive/Dropbox (OAuth, escopo de leitura de arquivo selecionado, sem tokens persistentes além da importação); app HubSpot (OAuth, CRM card, ação de workflow "enviar para assinatura"); `sso_connections` (organization_id, protocol ∈ oidc|saml, issuer/metadata, client_id, secret criptografado, domain_hints, jit_provisioning, enforce) com Socialite para OIDC e biblioteca SAML a avaliar; `memberships.auth_via`.
- **Riscos e decisões**: cada conector depende de documentação e revisão do vendor (T4); SSO obrigatório por org precisa de "break-glass" para owners; JIT provisioning cria `member` por padrão; SAML é superfície de ataque conhecida (validação de assinatura XML, replay de assertion) → biblioteca mantida e testes negativos.
- **Aceite**: página embutida só carrega em origens permitidas (teste com origem não autorizada bloqueada por CSP); `postMessage` de origem inválida ignorado; SSO OIDC com provedor de teste e SAML com IdP de teste, incluindo assertion inválida; SDKs com testes gerados contra fake server.

### 3.10 Programa de afiliados

- **Objetivo**: parceiros indicam clientes e recebem comissão sobre pagamentos aprovados.
- **Extensão prevista**: `payments` (`approved`, `refunded`, `charged_back`), `subscriptions`, cadastro (`register`), admin de §2.14, relatórios de §2.20.
- **Novas entidades**: `affiliates` (user_id, code, commission_rate_bp, status, payout_details criptografado), `referrals` (affiliate_id, organization_id UNIQUE, source, attributed_at, expires_at), `commissions` (payment_id UNIQUE, affiliate_id, amount_cents, status ∈ pending|approved|paid|reversed, paid_at, payout_batch_id), cookie de atribuição assinado (janela a definir, ex.: 60 dias).
- **Riscos e decisões**: **o sistema calcula, não paga** — repasses são feitos fora da plataforma pela operadora e registrados manualmente (`payout_batches`); reversão automática em estorno/chargeback; autoindicação e contas duplicadas detectadas (§3.7); implicações tributárias e contratuais dos repasses são decisão do negócio; dados de afiliado são pessoais (LGPD).
- **Aceite**: atribuição → pagamento aprovado → comissão `pending` → aprovada após prazo de estorno; estorno reverte; relatório de afiliado exportável; auditoria de alteração de taxa.

## 4. Backlog futuro (explicitamente sem implementação)

Itens registrados para não serem esquecidos nem prometidos. Nenhum tem flag, migration ou placeholder; entram em fase só por decisão registrada em `arquitetura.md`.

| Item | Por que fica fora por enquanto | Condições para entrar em alguma fase |
|---|---|---|
| **IA para cláusulas e resumos** (Ollama/vLLM self-hosted) | O conteúdo do documento é **dado não confiável** (T6): resumos podem ser manipulados por texto embutido no PDF (injeção de instruções); risco de o cliente tratar sugestão como parecer jurídico; custo de GPU. | Inferência local (nenhum documento sai da infraestrutura), saída sempre rotulada "sugestão automática, pode conter erros", **nunca** aciona ações nem altera o documento, sem treinamento com dados de clientes, avaliação com conjunto de documentos adversariais. |
| **App mobile / assinatura offline** | O aceite eletrônico depende de data do servidor, sessão autenticada e `snapshot_hash` validado sob lock; aceite offline seria uma prova de qualidade inferior e criaria conflitos de estado. A página pública já é responsiva. | Só como cliente da API `v1` **online** (captura de assinatura, câmera e notificações); "offline" limitado a rascunhos do remetente, jamais ao aceite. |
| **Liveness / face match** pelo serviço de identidade já existente | Exige documentação e contrato do provedor (`IdentityVerificationProvider`, T4), base legal para biometria (LGPD art. 11), avaliação de impacto e resposta a falsos negativos. Até lá, §2.10 é apenas captura. | Provedor documentado com fake de contrato, resultado gravado como `provider_result` com nível declarado pelo provedor, UI que só afirma o que o provedor afirma, retenção mínima. |
| **Whitelabel, domínio próprio e subcontas** | Remove o "via AssinaVelox" e o link de verificação padrão, o que enfraquece a confiança pública na página de verificação; domínio próprio exige TLS automatizado e cookies por domínio; subcontas exigem hierarquia de organizações em todas as queries e na cobrança. | Decisão de produto sobre verificação pública em domínio de terceiro; `parent_organization_id` com escopo testado; automação de certificados; cobrança consolidada. |

## 5. Testes por fase

| Camada | Fase 1 (referência) | Fase 2 acrescenta | Fase 3 acrescenta |
|---|---|---|---|
| Unitário (Pest) | Enums, máquinas de estado, geometria de campos, hashes | Motor de lembretes com clock congelado; validação tipada de variáveis; HMAC de webhook; regras de retenção; cálculo de comissão não se aplica | Motor de regras (condicionais, antifraude); atribuição de afiliados |
| Feature HTTP | Rotas Inertia, Policies, escopo por org, fluxo público | API `v1` completa + problem details + idempotência; webhooks de entrada (WhatsApp, fiscal); formulário público com throttles; permissões dinâmicas | SSO (OIDC/SAML com assertions inválidas); rotas `/embed/*` com CSP dinâmico; callbacks gov.br |
| Navegador | Wizard, aceite, verificação | Presencial com dois participantes; lote; wizard a partir de modelo; página embutida em origem permitida/negada | Widget com `postMessage`; fluxo A3 com componente simulado |
| Contrato de adaptador | Fakes de e-mail, conversor, signer, gateway | `SmsProvider`, `WhatsAppProvider`, `CnpjLookupProvider`, `CpfVerificationProvider`, `TimestampProvider`, `FiscalInvoiceProvider` — fake + fixtures gravadas dos sandboxes | `GovBrSignatureProvider`, `IcpBrasilTimestampProvider`, `NotaryProvider`, conectores Drive/Dropbox/HubSpot |
| Segurança | Rate limits, tokens só como digest, CSP | SSRF pré e pós-DNS; redaction de segredos em fila/log; varredura do ZIP do dossiê; injeção em templates HTML/DOCX; upload de logo malicioso | Clickjacking (frame-ancestors), replay SAML, digest expirado em assinatura externa, adversarial OCR |
| Validação criptográfica | `pdftool validate` (pyHanko) do PAdES B-B da operadora | Revisões incrementais de participantes (todas válidas após cada job); `.tsr` verificado por `openssl ts`; PFX inválido rejeitado | B-T/B-LT/B-LTA em validador externo independente; re-carimbo LTA com clock avançado; cadeias ICP-Brasil/gov.br |
| Concorrência | Aceite sob `FOR UPDATE`; finalização idempotente | Dois workers disputando a mesma revisão (deve serializar); envio agendado × edição concorrente; retentativa de webhook duplicada | Reserva de revisão externa expirada enquanto outro participante assinou |
| MySQL | Suíte opcional em MySQL real | Obrigatória para migrations aditivas e índices de tags/permissões | Idem + volumes de geração em lote |

## 6. Ordem sugerida e dependências da Fase 2

Ondas sugeridas (podem sobrepor-se; cada item liga sua flag independentemente):

| Onda | Itens | Motivo |
|---|---|---|
| A — domínio | §2.4 papéis, §2.3 múltiplos documentos, §2.5 lembretes/agendado, §2.1 templates, §2.14 permissões | Mudam recursos centrais (recipient, document, envelope) que a API precisa congelar depois. |
| B — canais e identidade | §2.18 WhatsApp Business, §2.9 OTP SMS/WhatsApp + PIN, §2.8 branding, §2.11 CNPJ/CPF, §2.10 selfie/documento, §2.6 presencial, §2.7 lote, §2.2 formulário público | Dependem dos adaptadores reservados e de templates. |
| C — criptografia e custódia | §2.12 A1 do usuário, §2.13 TSA + dossiê, §2.19 retenção/preservação | Alteram o pipeline de finalização e a exclusão; exigem validação externa. |
| D — plataforma e receita | §2.15 API, §2.16 webhooks, §2.17 n8n/Zapier/Make, §2.20 pagamentos, §2.21 NFS-e | API só depois do domínio estável; fiscal só depois de pagamentos. |

```mermaid
flowchart TD
    classDef dom fill:#eef2f9,stroke:#3b4d6b,color:#1f2a3d
    classDef chan fill:#f4f8fe,stroke:#3b4d6b,color:#1f2a3d
    classDef crypto fill:#fff7ed,stroke:#9a5b13,color:#3d2a12
    classDef plat fill:#f0fdf4,stroke:#2f6b3a,color:#14331b

    ROLES["2.4 Papéis<br/>recipients.role"]:::dom
    MULTI["2.3 Múltiplos documentos<br/>features.multi_document"]:::dom
    REM["2.5 Lembretes e envio agendado<br/>features.reminders"]:::dom
    TPL["2.1 Templates tipados<br/>features.templates"]:::dom
    PERM["2.14 Permissões, times, tags,<br/>relatórios, logs admin"]:::dom

    WABIZ["2.18 WhatsApp Business<br/>WhatsAppProvider"]:::chan
    OTP2["2.9 OTP SMS/WhatsApp + PIN<br/>features.sms_whatsapp"]:::chan
    BRAND["2.8 Branding e remetente próprio<br/>features.branding"]:::chan
    CNPJ["2.11 CNPJ / CPF<br/>CnpjLookupProvider, CpfVerificationProvider"]:::chan
    SELFIE["2.10 Selfie e documento"]:::chan
    TABLET["2.6 Presencial em tablet"]:::chan
    BATCH["2.7 Assinatura em lote"]:::chan
    FORM["2.2 Formulário público"]:::chan

    A1USER["2.12 A1/PFX do usuário<br/>pipeline incremental serializado"]:::crypto
    TSA["2.13 TSA RFC 3161 própria<br/>+ dossiê ZIP"]:::crypto
    RET["2.19 Retenção e preservação"]:::crypto

    API["2.15 API REST v1<br/>features.api_integrations"]:::plat
    WEBHOOK["2.16 Webhooks de saída"]:::plat
    N8N["2.17 n8n / Zapier / Make"]:::plat
    PAY["2.20 Pagamentos ampliados"]:::plat
    NFSE["2.21 NFS-e automática<br/>FiscalInvoiceProvider"]:::plat

    TPL --> FORM
    TPL -.campos por papel.-> ROLES
    WABIZ --> OTP2
    WABIZ -.canal de lembrete.-> REM
    OTP2 -.OTP do participante.-> TABLET
    SELFIE -.foto no presencial.-> TABLET
    BRAND -.FieldType.stamp.-> TPL
    MULTI --> A1USER
    MULTI --> TSA
    A1USER -.evidências destacadas.-> TSA
    TSA --> RET
    MULTI --> RET
    SELFIE --> RET
    ROLES --> API
    MULTI --> API
    TPL --> API
    PERM --> API
    API --> WEBHOOK
    API --> N8N
    WEBHOOK --> N8N
    PERM -.papel integration.-> API
    PAY --> NFSE
    PERM -.relatórios admin.-> PAY
    A1USER -.crypto por item.-> BATCH
```

Legenda: setas cheias = dependência obrigatória (o item de origem precisa estar ativo); setas tracejadas = dependência opcional ou de conveniência (o item funciona sem, mas fica incompleto). Cores: azul = domínio (onda A), azul-claro = canais e identidade (onda B), laranja = criptografia e custódia (onda C), verde = plataforma e receita (onda D).
