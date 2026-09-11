# Fase 2, onda B — relatório de integração (I-2B)

Integração das cinco áreas da onda B: canais SMS/WhatsApp, PIN do remetente e domínios de envio
(C-CAN), CPF, CNPJ e captura simples de foto (C-ID), marca da organização e carimbo visual
(C-BRAND), presencial em tablet e assinatura em lote (C-PRES), formulário público (C-FORM) e o
front das telas de canais e identidade (C-FRONT). Data: 11/09/2026. Nenhum commit foi feito.

Todas as flags nascem **desligadas**. Com elas desligadas, o comportamento é o da onda A (§3).
A flag liga a interface; a autorização continua nas Policies. Os serviços próprios do
proprietário (SMS, WhatsApp, API de e-mail, consulta de CPF) só existem como **simuladores
identificados**; em produção ficam desabilitados com a lista exata do que falta (§8).

## 1. Verificações (números reais, depois de todas as correções)

| Verificação                                            | Resultado                                                                                                                         |
| ------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan test --parallel --testsuite=Unit,Feature` | **1.362 de 1.362** passando (11.747 asserções), 0 pulados                                                                         |
| `php artisan test --testsuite=Browser` (sozinha)       | **36 passando, 3 pulados** de 39, 740 asserções, 43 s                                                                             |
| `tools/pdftool`: `pytest -q` (venv do projeto)         | **93 passando**                                                                                                                   |
| `tests/Feature/EndToEnd/Phase2OndaBTest.php` (novo)    | **3 testes, 174 asserções**, passando (finalização real com pdftool)                                                              |
| `vendor/bin/phpstan analyse`                           | **0 erros**                                                                                                                       |
| `vendor/bin/pint --test`                               | verde                                                                                                                             |
| `npm run types:check`                                  | 0 erros                                                                                                                           |
| `npm run check` (`vp check`)                           | 272 arquivos formatados, 0 avisos em 215                                                                                          |
| `npm run build`                                        | OK                                                                                                                                |
| `php artisan wayfinder:generate --with-form`           | OK (helpers de `settings.branding*`, `in_person.*`, `sign.batch.*`, `public_forms.*`, `form_fill.*`, `cnpj.lookup`, `webhooks.*`) |

Linha de base antes da integração: 1.354 testes Unit+Feature, 1.351 passando, 2 pulados (dois
testes do C-BRAND marcados "aguarda integração") e 1 falha (`SystemRolesTest`: rota
`settings.branding` sem permissão mapeada). PHPStan: 1 erro (`FieldGeometry.php:137`).

Os 3 testes pulados da suíte de navegador são os `->skip()` da Fase 1 (dropzone, arrastar
campo, página 404), sem relação com a onda B.

## 2. O que a integração corrigiu

### 2.1 Bloqueantes e defeitos

1. **Campo `cpf`/`stamp` forjado respondia 500** (C-ID e C-BRAND). `FieldGeometry::MINIMUM_POINTS`
   ganhou `cpf` (40×9 pt) e `stamp` (60×20 pt). Novo `App\Services\Envelopes\FieldTypeAvailability`:
   `FieldSync` e `TemplateDefinitionBuilder` recusam criar `cpf` sem a flag `cpf_field` e `stamp`
   sem a flag `branding` (422 no campo). Um campo já gravado continua aceito se a flag for
   desligada depois (não abandona rascunho). O CPF aceita `font_size` como os campos de texto.
   Teste: `tests/Feature/Phase2/FieldTypeFlagsTest.php` (4).
2. **CPF no PDF final quebraria a finalização**: o pdftool só conhece os tipos da Fase 1 e
   `ComposePlan` recusa `cpf`. `ConsolidationPlanner` desenha o CPF como texto e o carimbo pelo
   caminho de imagem existente (`StampComposer::add`, tipo `signature`). Coberto pela ponta a ponta.
3. **Carimbo de ponta a ponta** (C-BRAND): `RecordAcceptance` congela o carimbo no aceite
   (`StampImages::snapshot`, fora da transação) e grava em `signing_field_values.image_path`.
4. **`features.branding` não existia na configuração**: a flag só ligava em teste
   (`config()->set`). Criada com `ASSINAVELOX_FEATURE_BRANDING`. Achado na QA.
5. **`SystemRolesTest`**: `settings.branding`, `.update`, `.logo.store`, `.logo.destroy` →
   `Permission::ManageSettings` em `Permissions::ROUTE_PERMISSIONS`.
6. **Textos legais falsos para quem não usa só e-mail** (achado na QA). Para um participante com
   código por SMS, a tela dizia "código confirmado por e-mail", a declaração gravada dizia "código
   de uso único confirmado no e-mail acima" e o aviso de privacidade dizia "Não pedimos senha,
   CPF, foto ou localização" mesmo com PIN, campo CPF e selfie exigidos. `ConsentText` passou a
   receber o participante: canal (SMS/WhatsApp), PIN, CPF ("conferido apenas pelos dígitos") e
   fotos ("sem comparação de rostos… apagadas em N dias"). **Participante só por e-mail: texto e
   versão byte a byte iguais aos de antes** (a suíte inteira passou sem mudança de asserção).
   Com complemento, a versão ganha o sufixo `+b1` (`ConsentText::WAVE_B_VERSION_SUFFIX`; cabe
   nos 32 caracteres de `terms_version`, sem migration) e o texto resolvido completo continua em
   `consent_statement`. Vale para a página pública e para o presencial. **Pendente de revisão
   jurídica.** Teste: `tests/Feature/Phase2/ConsentTextWaveBTest.php` (2).
7. **Formulário público renderizado dentro da casca da conta** (achado na QA). No Inertia 3, o
   `layout = (page) => page` das páginas `public-forms/fill` e `confirm` é lido como resolvedor de
   props e caía no `AppLayout` (topbar com busca global). `app.tsx` devolve `null` para essas duas
   páginas. Presencial (`KioskShell`) e lote (`BatchShell`) já estavam certos.
8. **Câmera no presencial**: `CameraPermission` só liberava `sign.*`; agora aceita também
   `in_person.kiosk.show` e `in_person.kiosk.capture.*` quando o dispositivo marca o atributo
   (mesma regra do link individual). Coberto pela ponta a ponta (cabeçalho `camera=(self)`).
9. `SecurityHeaders`: `presencial`, `presencial/*` e `formulario/*` com `noindex` e `no-referrer`.

### 2.2 Pendências das áreas ligadas

| Ponto                               | Integração                                                                                                                                                                                                                                                                                                                   |
| ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `HandleInertiaRequests::features()` | `sms_whatsapp`, `pin_auth`, `sender_domains` (ChannelFeatures), `branding` (BrandingFeature), `cpf_field`, `cpf_lookup`, `cnpj_lookup` (sem organização: só o global), `identity_capture` (IdentityFeatures), `in_person`, `batch_signing` (PresenceFeatures), `public_forms`. `organization.logo_url` do BrandingPresenter. |
| `SignerPageProps`                   | `signer_auth` (nome escolhido pelo C-FRONT: `auth` sombreava `auth.user` compartilhado), `auth_methods` reais, `identity_capture`, `sender.brand`/`sender.logo_url`. `invalid()` com `signer_auth`/`identity_capture` nulos e **sem** `brand`.                                                                               |
| `EnvelopeController::edit`          | `channels` (`ChannelAvailability::wizardProps`) e `capture_requirements` (só com a flag). `RecipientWizardResource` com `phone`, `phone_masked`, `channel`, `auth_method`, `has_pin` (o PIN nunca volta).                                                                                                                    |
| Detalhe do documento                | `RecipientResource`: `channel` real e `auth_methods` com `sender_pin`. Atalhos "Assinatura presencial" (envelope em andamento) e "Enviar link de lote" (participante lembrável) com os componentes prontos do C-PRES.                                                                                                        |
| Evidências (tela)                   | `EvidenceDossier`: `auth_methods`, `auth_method_label`, `delivery_channel`, `identity_captures`, `in_person` por participante; página com `identity_capture_notice` e `in_person`. Front mostra "Modo: Presencial, na presença de …".                                                                                        |
| Evidências (PDF)                    | `EvidenceData`: `branding` (logo da remetente no cabeçalho), `stamp` ("representação visual, não prova"), método "Código por SMS + PIN do remetente" e linha do presencial.                                                                                                                                                  |
| Presencial                          | `InPersonKioskProps` com a marca da organização; aviso de privacidade por participante.                                                                                                                                                                                                                                      |
| Configurações                       | Rail "Marca" (flag + `manage_settings`); Geral mostra o logo e "Gerenciar marca"; texto corrigido para "PNG ou JPEG" (o servidor sempre recusou SVG).                                                                                                                                                                        |
| Sidebar                             | "Formulários" (flag + `manage_templates`) e "Presencial" (flag + `send_envelopes`).                                                                                                                                                                                                                                          |
| Agendamento                         | `identity:purge-captures` (diário, 04:40) e `public-forms:purge-submissions` (a cada hora).                                                                                                                                                                                                                                  |
| `OrganizationPurge`                 | 12 tabelas novas na ordem de exclusão (duas são RESTRICT na organização) e `BrandingManager::purge()`.                                                                                                                                                                                                                       |
| Log                                 | `pin` e `image` na lista de redação.                                                                                                                                                                                                                                                                                         |
| `AdminEventCatalog`                 | Categorias "Formulários públicos" e "Domínios de envio" (sem dado de signatário).                                                                                                                                                                                                                                            |
| `.env.example`                      | As 11 flags da onda B, drivers de SMS/WhatsApp e `ASSINAVELOX_CPF_LOOKUP_DRIVER=disabled`.                                                                                                                                                                                                                                   |
| `FakeCpfVerificationProvider`       | Revisado depois da sobrescrita relatada pelo C-CAN: cumpre o contrato usado por `CpfLookup` (`NAME`, `details.simulated`, `details.reason_code`), nunca devolve `valid` sem `simulate()`, loga só o CPF mascarado. Os 33 testes do C-ID passam.                                                                              |

### 2.3 Asserções de testes existentes alteradas (todas justificadas)

| Teste                                                   | Mudança                                                                                                                                                                                              |
| ------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Organizations/SharedPropsTest`                         | `features` ganhou 9 chaves da onda B, todas `false`.                                                                                                                                                 |
| `Unit/Models/EnumCatalogTest`, `Smoke/AllGetRoutesTest` | Alterados pelas áreas (eventos e rotas novas; só acréscimos).                                                                                                                                        |
| `Phase2/Branding/EvidenceHeaderTest` (C-BRAND)          | `EvidenceData::build()` agora já traz `branding`/`stamp`; a linha de base passou a ser `array_replace($data, ['branding' => null])` em vez de `$data + [...]` (que não sobrescreve chave existente). |
| `Phase2/Branding/SignerBrandingTest` (C-BRAND)          | `->skip('Aguarda integração…')` removido: passa.                                                                                                                                                     |
| `Phase2/Branding/StampTest` (C-BRAND)                   | Placeholder vazio pulado substituído por comentário: o fluxo completo está na ponta a ponta.                                                                                                         |
| `Phase2/Identity/IdentityCaptureTest` (C-ID)            | A checagem "a verificação pública não fala de captura" ignora o objeto `features` compartilhado (a chave `identity_capture` existe em toda página Inertia).                                          |

Nenhuma asserção da Fase 1 ou da onda A foi alterada.

## 3. Não regressão

Com todas as flags desligadas (o padrão e o que os testes usam), a suíte inteira passa. Os
testes que provam o desligado: `Phase2/Channels/FlagsOffTest`, `Phase2/Identity/FlagsOffTest`,
`Phase2/InPerson/PresenceFlagsOffTest`, `Phase2/PublicForms/PublicFormFeatureFlagTest`,
`Phase2/Branding/*` (flag desligada), `FieldTypeFlagsTest` e `ConsentTextWaveBTest` (texto só e-mail
idêntico). O `challenge.sent` do e-mail, a declaração e o aviso de privacidade da Fase 1 não mudam.

## 4. Ponta a ponta (`tests/Feature/EndToEnd/Phase2OndaBTest.php`)

Todas as flags da onda B ligadas (global E plano) e os simuladores:

1. **Formulário público → assinatura.** Modelo PDF com um signatário, formulário em fila de
   revisão; o público preenche sem login e confirma pelo e-mail (nenhum envelope antes da
   confirmação); o envelope nasce em rascunho pelo modelo. O remetente, pelo wizard: celular
   `(11) 91234-5678` → `+5511912345678`, convite "e-mail + SMS", código por SMS, PIN; campos
   assinatura + CPF + carimbo; selfie exigida. O wizard devolve `has_pin` sem o PIN. Aprovar na
   fila envia (e-mail + aviso por SMS simulado). O participante vê `signer_auth` com destino
   mascarado e "simulado", pede o código (SMS simulado), confirma — a tela fica na etapa do PIN —,
   informa o PIN, abre o documento, envia a selfie, preenche o CPF e aceita. A finalização roda
   de verdade (pdftool): carimbo congelado em `image_path`, CPF formatado no campo. Evidência do
   remetente: "Imagem capturada pelo participante", `['sms_otp','sender_pin']`, canal `sms`, nota
   "Não houve verificação de identidade"; PDF de evidências: "Código por SMS + PIN do remetente",
   logo da remetente e "Carimbo visual da organização — representação visual, não prova".
   Verificação pública sem o CPF (com ou sem máscara de pontuação), sem o PIN e sem o resumo da
   foto. Trilha e logs sem PIN, CPF, código e token.
2. **Presencial**: dois participantes no mesmo dispositivo, marca da organização no quiosque;
   o segundo com selfie exigida — câmera liberada no dispositivo (`camera=(self)`), aceite
   recusado sem a foto e aceito com ela; sessões e aceites separados; evidência "Presencial, na
   presença de …".
3. **Lote**: dois de três envelopes autorizados item a item, cada um com o próprio aceite e a
   própria sessão; o terceiro e o de outra organização continuam pendentes.

## 5. QA no navegador (banco `database/i2b.sqlite`, flags ligadas)

Servidor `php -S 127.0.0.1:8133 -t public` com um roteador de harness **só no scratchpad**
(`/__qa/login?email=` abre a sessão sem digitar senha), `MAIL_MAILER=log`, fila `sync`, build de
produção; encerrado ao final (só o PID iniciado por mim). Dados de QA extras criados por script no
scratchpad: envelopes com PDF real (SMS + PIN; SMS sem PIN) e um paralelo para o presencial.

**Limites honestos:** o PIN não foi digitado no navegador (é segredo de autenticação, ainda que de
teste) — o caminho do PIN está coberto pela ponta a ponta e por `PinTest`. Envio de arquivo
(logo, selfie) e câmera não são possíveis com as ferramentas do navegador; estão cobertos por
testes HTTP. O painel do navegador ficou oculto em parte da sessão, então a maioria das
verificações foi por texto/DOM, com capturas onde o painel desenhou.

| Tela                                              | Resultado                                                                                                                                                                                            |
| ------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Configurações › Marca                             | OK; logo, nome de exibição, cores com contraste calculado (6,4:1 e 3,3:1), prévia, Reply-To, "Remetente próprio — domínio não verificado" com o que falta, carimbo "representação visual, não prova" |
| Configurações › Geral                             | OK; logo e "Gerenciar marca" com a flag; "Fase 2" sem ela                                                                                                                                            |
| Sidebar                                           | "Formulários" e "Presencial" com as flags                                                                                                                                                            |
| Wizard passo 2                                    | OK; celular, "Enviar convite por" (Só e-mail / E-mail + SMS / E-mail + WhatsApp, "simulado"), método do código, "Não confirma quem ela é", PIN opcional, fotos como "captura simples"                |
| Página pública, identificar (SMS + PIN)           | OK; "+55 •••••••5678", selo "simulado", aviso de ambiente de testes, reenvio com contagem, etapa do PIN depois do código. **Texto "confirmado por e-mail" corrigido** (§2.1 item 6)                  |
| Página pública em 375 px                          | OK; sem rolagem horizontal                                                                                                                                                                           |
| Aviso de privacidade completo                     | OK depois da correção (SMS, PIN, CPF, foto, retenção)                                                                                                                                                |
| Página pública, assinar (SMS, CPF, foto, carimbo) | OK; campo CPF "conferido só pelos dígitos", etapa de fotos com a nota de não verificação, carimbo no documento, declaração versão `v1-2026-09-08+b1` com o método por SMS                            |
| Detalhe do documento                              | "E-mail + SMS", "Código por SMS", "PIN do remetente", "Assinatura presencial", "Enviar link de lote" (com um só documento: "use o reenvio do convite")                                               |
| Presencial › iniciar                              | OK; lista de envelopes em andamento, nome do dispositivo, "manter conectado" desmarcado                                                                                                              |
| Quiosque sem sessão (`/presencial`)               | OK; casca própria, sem topbar da conta                                                                                                                                                               |
| Formulários públicos (lista)                      | OK; formulário publicado da demonstração                                                                                                                                                             |
| Formulário público (preencher)                    | **Corrigido**: estava dentro da casca da conta (§2.1 item 7); agora casca própria, sem rolagem horizontal                                                                                            |
| Console                                           | Sem erros nas telas acima                                                                                                                                                                            |
| Vocabulário                                       | Nenhum termo proibido nas telas; `VocabularyTest` verde                                                                                                                                              |

Não cobertos por clique: fluxo completo do quiosque com dois participantes, lote pela interface,
envio do formulário público pelo navegador — cobertos pela ponta a ponta (§4) e pelos testes das
áreas.

## 6. Dados de demonstração (`DemoOrganizationSeeder`)

- Plano **Profissional** (Horizonte) com as flags da onda B em `plans.features` (`sms_whatsapp`,
  `pin_auth`, `sender_domains`, `branding`, `cpf_field`, `identity_capture`, `in_person`,
  `batch_signing`, `public_forms`); **Grátis** (Vega) com todas `false`. `cpf_lookup` e
  `cnpj_lookup` ficam fora (sem serviço configurado; a de CNPJ acessa a rede).
- Horizonte: marca "Horizonte Imóveis" (cores válidas, logo gerado com GD, Reply-To
  `atendimento@horizonte.demo`); formulário público **publicado** "Solicitação de termo de
  entrega de chaves" (modelo HTML, fila de revisão); no "Aditivo de reajuste — Contrato
  2024/118", o 1º participante com código por **SMS simulado** e o 2º com **PIN do remetente**
  `48291573` (`DemoOrganizationSeeder::DEMO_PIN`, só local).
- Como na onda A, nada aparece sem os interruptores globais `ASSINAVELOX_FEATURE_*`; os testes
  do seeder (`SeedersTest`, `SeederCrossOrganizationConsistencyTest`, `AllGetRoutesTest`) passam.

## 7. Pendências reais

1. **Revisão jurídica**: textos da onda B (`+b1` da declaração e do aviso; captura; presencial;
   e-mail de lote; aviso do formulário público `PrivacyNotice`; "Organização remetente" e "não
   prova" do carimbo); viabilidade §4.4 item 20 (base legal, controlador × operador, RIPD e
   retenção da captura) e item 21 (base legal e finalidade da consulta de CPF, modo estrito);
   aceite formal do uso da instância pública do Minha Receita.
2. **Tela de domínios de envio** (C-CAN): serviço, registro e verificador existem; faltam rotas e
   a tela "Configurações › Canais" que a tela de Marca já cita.
3. **Formulário público sem a marca da organização** na casca pública e e-mail de confirmação
   sem `delivery_attempts`/marca (C-FORM, pendência 5 da área).
4. `ChannelDelivery::refresh()` agendado para entregas "desconhecidas" — só quando existir
   provedor real.
5. Lembretes automáticos ainda não mandam o aviso por SMS/WhatsApp (reenvio manual e convite
   original mandam).
6. `DuplicateEnvelope`: decidir se copia telefone/canal e exigência de fotos (o PIN, de
   propósito, não é copiado).
7. Rótulos de plano: não há tela que liste `plans.features`; só o seeder.
8. Teste de navegador do presencial com dois participantes: não existe (plugin sem multipart;
   coberto pela ponta a ponta HTTP).
9. **Decisões de produto**: o PIN pode substituir o código quando `otp_required = false`? (hoje
   nunca); Reply-To exige confirmar a posse da caixa?; avisar quem preencheu o formulário quando a
   equipe recusa?; o lote autentica só por e-mail; sem recusa pelo dispositivo presencial.
10. A diretoria `tools/pdftool/%SystemDrive%` existe no disco (artefato de ambiente, não criado
    nesta integração); não foi removida.

## 8. O que o proprietário precisa fornecer (para sair do simulador)

**SMS (serviço próprio)** — documentação da API (envio e consulta de status); autenticação e
credenciais de homologação e produção; catálogo de erros e de status (aceito, entregue, falha,
devolvido); limites (por segundo, dia e destinatário) e custo por mensagem; formato do webhook de
status (cabeçalhos, algoritmo de assinatura, janela, reentrega); política de idempotência e o que
acontece em tempo esgotado; remetente aprovado (número curto/longo ou nome).

**WhatsApp Business (serviço próprio)** — documentação da API (envio por template e status);
templates pré-aprovados por finalidade (código, convite, reenvio) com nomes e parâmetros exatos;
autenticação e credenciais; catálogo de erros e status (aceito, entregue, lido, falha), limites e
custo por conversa; formato e assinatura do webhook; idempotência e tempo esgotado; decisão entre
o número da operadora e o do cliente como remetente.

**Consulta cadastral de CPF (serviço próprio)** — URL base e caminho do endpoint (homologação e
produção); autenticação e credenciais; entradas exigidas além do CPF e formato; formato da
resposta, campos e códigos de situação cadastral; códigos de erro e significado de tempo
esgotado, 4xx e 5xx; limites de taxa, SLA e janela de manutenção; custo por consulta; base legal
e finalidade aprovadas pelo jurídico e decisão sobre o modo "estrito".

**Domínios de envio (API do serviço de e-mail)** — documentação para cadastrar um domínio e
consultar a situação; registros DNS exigidos (DKIM, SPF, Return-Path, posse) e o formato em que a
API os devolve; autenticação e credenciais de homologação; códigos de situação e de erro e limites
de chamadas; confirmação de que o SMTP atual aceita remetente do domínio verificado (alinhamento
DKIM/SPF).

Até lá: produção com o canal indisponível e o motivo na tela; nenhum endpoint inventado; nenhuma
dependência de Evolution API, WPPConnect, Baileys, Gammu, Postal, Mailcow ou Mailu.

## 9. Revisão adversarial

Correção dos achados da revisão adversarial da onda B (testes em
`tests/Feature/Review/Phase2B/`). Com todas as flags desligadas nada muda; migrations: nenhuma.

| #   | Achado (gravidade)                                                             | Correção (causa-raiz)                                                                                                                                                                                                                                                                                                                                                                                                    |
| --- | ------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | PIN: bloqueio conferido fora do lock (alta)                                    | `SenderPins::verify()` reconfere `isBlocked()`/`isLocked()` **na linha travada** (`lockForUpdate`) antes de testar o PIN; tentativa que chega depois do bloqueio de outra recebe `pin_blocked`/`pin_locked`, fecha o portão e não zera contadores. Vale para o quiosque (mesmo caminho).                                                                                                                                 |
| 2   | Lote não conferia o e-mail de cada item (alta)                                 | `BatchItems::describe()` exige que o e-mail ATUAL do participante do item bata com `batch.email_digest`; senão o item fica "Indisponível" — `open()` e `authorize()` recusam.                                                                                                                                                                                                                                            |
| 3   | Código por SMS/WhatsApp apresentado como "Identidade confirmada" (alta)        | Flash "Código confirmado…", rótulo de `challenge.verified` "Código de confirmação validado", `SigningSessionStatus` "Código confirmado"/"Aguardando confirmação do código", selo da página pública "Código confirmado".                                                                                                                                                                                                  |
| 4   | CPF completo em `envelopes.show`, inclusive no "acessar como" (alta)           | `SigningFieldResource`: CPF sai só mascarado (`CpfNumber::mask`); durante a impersonation nenhum valor de campo sai.                                                                                                                                                                                                                                                                                                     |
| 5   | Aviso "conferido apenas pelos dígitos" com a consulta cadastral ligada (média) | Com `cpf_lookup`, o aviso informa o envio a um serviço de consulta cadastral, a finalidade e que ela não confirma a titularidade; a declaração cita o resultado da consulta. Versão `+b2`. **Pendente de revisão jurídica.**                                                                                                                                                                                             |
| 6   | Simulador de SMS/WhatsApp ativável em produção (média)                         | `SimulatedMessagingProvider::isConfigured()` exige `! app()->environment('production')` além do interruptor; mensagem própria para esse caso.                                                                                                                                                                                                                                                                            |
| 7   | Evidência dizia "Código por SMS" com o SMS simulado (média)                    | Novo `SimulatedChannelEvidence` (último `challenge.verified` → desafio → `delivery_attempts.meta.simulated`). Dossiê: `auth_method_simulated` + `auth_method_note` (exibido na página de evidências); PDF: sufixo "(envio simulado — nenhuma mensagem transmitida)"; declaração/aviso: nota "[envio por SMS simulado nesta instalação…]" e versão `+b2`.                                                                 |
| 8   | "Apagadas 0 dias depois" com a retenção desligada (média)                      | Com `retention_days <= 0` o aviso diz "guardadas enquanto o documento existir na conta da remetente" (versão `+b2`); a etapa de captura mostra o mesmo.                                                                                                                                                                                                                                                                  |
| 9   | Guarda de vocabulário com furos (média)                                        | Sem "no" na exceção em inglês; negações valem só depois do último ":" da oração (a citação "Proibido:" continua valendo); termos novos: identidade confirmada, prova de vida, reconhecimento facial; varre também `app/Services`, `app/Enums` e `app/Http`. Dois comentários reescritos para a negação ficar junto do termo.                                                                                             |
| 10  | PIN bloqueado sem saída para o remetente (alta)                                | `RecipientResource` ganha `pin_state` (active/locked/blocked), `auth_method` e `phone_masked`; o cartão do participante mostra "PIN do remetente · bloqueado" com orientação; o PATCH `envelopes.recipients.update` aceita `pin` (mesma validação do wizard, `SenderPins::set` zera contadores e bloqueio, evento `recipient.pin_updated` com `after_send`/`was_blocked`); o diálogo "Editar signatário" tem "PIN novo". |
| 11  | Celular errado / canal indisponível sem conserto (alta)                        | O mesmo PATCH aceita `phone` e `auth_method` (validação de `RecipientChannels::resolve`: E.164, canal disponível agora); a troca encerra sessões e códigos vivos e registra `recipients.updated` (sem o número). Diálogo com "Código de confirmação" e "Celular". Sem esses campos, o PATCH é o da Fase 1.                                                                                                               |
| 12  | "Enviar link de lote" com um clique (média)                                    | `ConfirmDialog` explicando que o e-mail leva todos os documentos pendentes do participante na conta, autorizados um a um, sem desfazer; botão "Enviar link".                                                                                                                                                                                                                                                             |
| 13  | "Remover logo" sem confirmação (média)                                         | `ConfirmDialog` destrutivo "Remover o logo?" dizendo onde ele some e que será preciso reenviar o arquivo.                                                                                                                                                                                                                                                                                                                |

**Asserções existentes alteradas (justificadas):**

- `tests/Browser/SignerFlowTest.php`: o selo esperado passa de "Identidade confirmada" para
  "Código confirmado" — arquitetura §2 e T1 (o código prova a posse do canal) têm precedência
  sobre o DESIGN_SYSTEM §6, que ainda nomeia o badge "Identidade confirmada" (docs/design não é
  editável por esta revisão: **ajuste pendente no DESIGN_SYSTEM**).
- `tests/Feature/Phase2/ConsentTextWaveBTest.php`: o SMS do teste sai pelo simulador; o texto
  agora diz isso → versão `+b2` (regra "mudou uma palavra, nova versão").
- `tests/Feature/EndToEnd/Phase2OndaBTest.php`: o rótulo do método no PDF de evidências ganha o
  sufixo de envio simulado (o fluxo usa o simulador).
- `tests/Feature/Phase2/VocabularyTest.php`: detector endurecido (casos novos no teste do detector).

**Fora do escopo desta correção (testes da revisão que continuam falhando, não atribuídos a esta
tarefa):** `BatchIdentifyPrivacyNoticeTest`, `BrandingSenderBacklogCopyTest`,
`CaptureUploadProvenanceTest`, `FakeCpfIgnoresSimulationSwitchTest`,
`KioskEndSessionNativeConfirmTest`, `PublicFormTimerReplayTest`.

**Verificações (números reais, 2026-09-11):**

- `php artisan test --exclude-testsuite=Browser`: 1.391 testes, 1.384 aprovados, 7 falhas. Uma falha
  era do `VocabularyTest` (um comentário que eu tinha reescrito). Depois de corrigir, `VocabularyTest` + `VocabularyGuardGapsTest`
  rodaram de novo: 9/9 aprovados. Estado final: **1.385 aprovados e 6 falhas**, todas nos seis testes de revisão
  fora do escopo listados acima. Os 13 testes de revisão desta tarefa (23 casos) passam.
- `php artisan test --testsuite=Browser`: 39 testes, 36 aprovados, 3 pulados, 0 falhas.
- pdftool (`tools/pdftool/run_tests.sh`): 93 aprovados.
- `npm run types:check`: sem erros. `npm run check`: sem avisos, depois de `vp check --fix` (só formatação).
  `npm run build`: ok.
- `vendor/bin/pint --dirty`: ok. `vendor/bin/phpstan analyse`: 0 erros.

## 10. Fechamento

Verificação independente (V-2B) depois das correções dos seis achados de gravidade baixa que ficaram
de fora do §9. Os 19 testes de revisão de `tests/Feature/Review/Phase2B/` passam. Nenhuma migration,
nenhum pacote novo e nenhum commit.

### 10.1 Os seis achados corrigidos

| #   | Teste de revisão                     | Causa-raiz                                                                                                                              | Correção                                                                                                                                                                                                                                                                                                                                                                                     |
| --- | ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 14  | `FakeCpfIgnoresSimulationSwitchTest` | Na reescrita acidental, `FakeCpfVerificationProvider::isConfigured()` passou a devolver `true` sempre, e a fábrica só olhava `APP_ENV`. | `isConfigured()` exige `assinavelox.channels.allow_simulated` ligado **e** ambiente fora de produção (mesma regra de `SimulatedMessagingProvider`). Desligado, `verify()` devolve `inconclusive`/`not_configured`. `CpfVerificationFactory` só entrega o simulador se `isConfigured()`; senão registra o motivo no log e vale `OwnServiceCpfVerificationProvider`.                           |
| 15  | `PublicFormTimerReplayTest`          | `FillTimer::check()` conferia só o formulário e o tempo; nada marcava o carimbo como usado.                                             | Estado `USED` e `FillTimer::consume()` (marca atômica com `Cache::add`, validade de 6 h + 1 min, identidade pelo IV e texto cifrado protegidos pelo MAC). `PublicFormIntake` consome depois de todas as barreiras e antes de gravar; envio recusado não consome. Limitação documentada: `cache:clear` apaga as marcas.                                                                       |
| 16  | `CaptureUploadProvenanceTest`        | `CaptureEvidence::forEnvelope()` descartava `identity_captures.source` e rotulava tudo "Imagem capturada pelo participante".            | Rótulo "Imagem enviada pelo participante — origem informada pelo navegador: câmera" (ou "…: arquivo do dispositivo", ou "origem não informada pelo navegador"); chaves `source`/`source_label`; o aviso diz que a origem é declarada e não verificada; `source` também no `fields_snapshot` e na trilha `identity_capture.recorded`. A página de evidências mostra a origem em cada legenda. |
| 17  | `BatchIdentifyPrivacyNoticeTest`     | `BatchPageProps::build()` montava o aviso genérico, sem participante.                                                                   | `BatchPageProps::noticeRecipient()` usa o aviso de `ConsentText` de um participante com item autorizável e campo CPF (versão, resumo e aviso). O lote só autoriza itens com código por e-mail, sem PIN e sem fotos; o resto vai para o link individual, com aviso próprio.                                                                                                                   |
| 18  | `KioskEndSessionNativeConfirmTest`   | "Encerrar sessão presencial" usava `window.confirm`.                                                                                    | `ConfirmDialog` destrutivo "Encerrar a sessão presencial?", explicando que a sessão termina para todos no dispositivo e não pode ser reaberta; sem dado de participante; fechamento bloqueado durante a requisição.                                                                                                                                                                          |
| 19  | `BrandingSenderBacklogCopyTest`      | `sender.pending` mostrava ao cliente o backlog da operadora (DKIM, SPF, API) e um link para uma tela inexistente.                       | Três frases para o cliente: o envio com o endereço da organização ainda não está disponível, os e-mails saem do endereço da plataforma com Reply-To, e o endereço já pode ser salvo. A lista técnica ficou em `branding.md` §5.3 e §11.                                                                                                                                                      |

Documentos alinhados ao código: `identidade.md` §2.2, §5.2, §5.4, §5.5 e §7;
`formulario-publico.md` §5 e §7; `presencial-e-lote.md` §2.2 e §3.5; `branding.md` §5.3.

### 10.2 Incidente do simulador de CPF

O arquivo `FakeCpfVerificationProvider.php` foi reescrito pela área C-CAN por cima do original do
C-ID. Conferido agora:

- O contrato usado por `CpfLookup` continua (`NAME = cpf_simulado`, `details.simulated`,
  `details.reason_code`). Sem `simulate()`, o simulador nunca devolve `valid`, e o log leva só o CPF mascarado.
- `identidade.md` §2.2 descreve exatamente o comportamento: `fake` só com o interruptor ligado e fora
  de produção; senão a fábrica recusa, registra no log e vale `own_service`/`not_configured`; resolvido
  direto do contêiner, o simulador responde `inconclusive`/`not_configured`.
- `Phase2/Identity/CpfFieldTest` liga o interruptor explicitamente no teste do simulador e confere a
  recusa em produção. `SimulatedChannelsInProductionTest` e `FakeCpfIgnoresSimulationSwitchTest`
  cobrem os dois lados. `phpunit.xml` não liga o interruptor, então o padrão dos testes é o desligado.

### 10.3 Auditoria das asserções alteradas nesta onda

Arquivos que já estavam no commit `HEAD` (`git diff HEAD --stat -- tests/`: 4 arquivos, 61 inserções, 3 remoções):

| Teste                                       | Mudança                                                                                                                          | Veredito                                                                                                                                                                                                                   |
| ------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Browser/SignerFlowTest.php`                | Selo "Identidade confirmada" → "Código confirmado".                                                                              | **Legítima.** Arquitetura §2 e T1: o código prova a posse do canal. O `DESIGN_SYSTEM.md` §6 já traz o badge renomeado para "Código confirmado", com a mesma justificativa, então a pendência anotada no §9 está resolvida. |
| `Feature/Organizations/SharedPropsTest.php` | `features` ganhou 9 chaves da onda B, todas `false`.                                                                             | **Legítima.** Só acréscimo, e o teste continua exigindo o objeto exato. Todas nascem desligadas (roadmap T8).                                                                                                              |
| `Feature/Smoke/AllGetRoutesTest.php`        | 4 rotas públicas novas na lista de 404 por token sintético; `public_forms.index`/`edit` com 404 esperado; parâmetros sintéticos. | **Legítima.** Só rotas novas da onda B. O 404 com token desconhecido ou flag desligada é a regra de `formulario-publico.md` §5 e `presencial-e-lote.md` §5. Nenhuma rota antiga saiu da verificação.                       |
| `Unit/Models/EnumCatalogTest.php`           | 31 eventos de auditoria novos em quatro grupos; a contagem soma os grupos.                                                       | **Legítima.** Só acréscimo. A checagem "os 35 da reconciliação continuam" não mudou.                                                                                                                                       |

Arquivos novos nesta onda cuja asserção foi trocada durante a revisão:

| Teste                                                                                                                                     | Mudança                                                                                                                                                                                     | Veredito                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| ----------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `Phase2/Identity/IdentityCaptureTest.php`                                                                                                 | Rótulo "Imagem capturada pelo participante" → "Imagem enviada pelo participante — origem informada pelo navegador: câmera", mais `source = camera`. O título do caso também foi atualizado. | **Legítima, e mais estrita** (confere também a origem). A regra T1 manda descrever o meio. Um upload da galeria não prova captura nem autoria. **Tensão com o roadmap:** o `roadmap.md` §2.10 diz que "as evidências dizem 'foto capturada'". O objetivo daquela frase é proibir "liveness", "biometria" e "identidade verificada", e o texto novo continua cumprindo isso. Nenhum dos dois rótulos (antigo e novo) usa literalmente "foto capturada". |
| `EndToEnd/Phase2OndaBTest.php`                                                                                                            | Mesma troca de rótulo na página de evidências; o sufixo de envio simulado no PDF (§9).                                                                                                      | **Legítimas.** A primeira reflete a mesma correção; a segunda cumpre T1, porque o fluxo usa o simulador.                                                                                                                                                                                                                                                                                                                                               |
| `Phase2/ConsentTextWaveBTest.php`, `Phase2/VocabularyTest.php`, `Phase2/Branding/*`, `Phase2/Identity/IdentityCaptureTest.php` (features) | Já auditadas no §2.3 e no §9.                                                                                                                                                               | **Legítimas.** Nenhuma afrouxa uma regra.                                                                                                                                                                                                                                                                                                                                                                                                              |

Nenhuma asserção da Fase 1 ou da onda A foi enfraquecida.

### 10.4 Números finais (2026-09-11, execução real)

| Verificação                                                                     | Resultado                                                                                                          |
| ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `php artisan test --testsuite=Unit,Feature` (serial)                            | **1.391 testes, 1.391 aprovados**, 0 falhas, 0 pulados, 11.883 asserções, 1.208 s                                  |
| `php artisan test --testsuite=Browser`                                          | **39 testes, 36 aprovados**, 0 falhas, 3 pulados (os `->skip()` da Fase 1), 740 asserções, 41 s                    |
| `tests/Feature/Review/Phase2B` + `Phase2/Identity` (depois do ajuste de título) | 62 aprovados, 481 asserções                                                                                        |
| `Phase2/Branding` + `Phase2/InPerson`, três rodadas seguidas                    | 54/54 nas três rodadas, 628 asserções. A falha única do `EvidenceHeaderTest` relatada por uma área não se repetiu. |
| `tools/pdftool`: `pytest -q`                                                    | **93 aprovados**                                                                                                   |
| `vendor/bin/pint --test`                                                        | verde                                                                                                              |
| `vendor/bin/phpstan analyse`                                                    | **0 erros**                                                                                                        |
| `php artisan wayfinder:generate --with-form`                                    | OK                                                                                                                 |
| `npm run types:check`                                                           | 0 erros                                                                                                            |
| `npm run check`                                                                 | verde, depois de `vp check --fix` (só formatação deste relatório)                                                  |
| `npm run build`                                                                 | OK                                                                                                                 |

### 10.5 Pendências reais

- **Revisão jurídica** dos textos de aceite e dos avisos com complemento (`+b1`/`+b2`), inclusive o aviso
  do lote com CPF (§9 item 5 e §10.1 item 17).
- **Carimbo do formulário público:** as marcas de uso ficam no cache. Um `cache:clear` faz carimbos
  ainda válidos voltarem a valer até vencer (no máximo 6 h). Para fechar isso é preciso guardar as marcas
  em um cache persistente (driver `database`/`redis`) em produção.
- **Origem da imagem é declarada:** um cliente adulterado pode enviar `source = camera` para um arquivo.
  O aviso e o rótulo já dizem que a origem não é verificada.
- **Roadmap §2.10:** decidir se a frase "foto capturada" deve ser atualizada para o vocabulário de
  origem declarada. O ajuste é só no documento; o código já segue T1.
- Tudo o que o §8 lista como dependente do proprietário (SMS, WhatsApp, API de e-mail, consulta de CPF).
