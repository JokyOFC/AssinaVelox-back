# Fase 2 — entrega consolidada (ondas A, B, C e D)

> Fechamento da Fase 2 (integração I-2D, 11/09/2026). Consolida os relatórios
> `onda-a-relatorio.md`, `onda-b-relatorio.md`, `onda-c-relatorio.md` e a onda D (§4 abaixo).
> Classificação A/B/C conforme `docs/fases-2-3-viabilidade.md` §0: **A** = implementado de
> verdade; **B** = contrato + simulador identificado, produção desabilitada; **C** = bloqueado, só
> documentado. Nada que é simulador está marcado como pronto. Nenhum commit foi feito nesta
> integração.

## 1. Estado por item do roadmap (§2.1 a §2.21)

Legenda do estado real: **Implementado** (código real, testado, atrás de flag); **Simulador**
(contrato + dublê identificado; produção desabilitada até o proprietário fornecer o que falta);
**Bloqueado** (nenhum adaptador escrito, por falta de documentação ou elegibilidade — T4).

| Item                                             | Onda | Classe | Estado real                                                                                                                                                                                                                                                                         | Flag(s)                                                                                       | Detalhe                                              |
| ------------------------------------------------ | ---- | ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| §2.1 Modelos com variáveis tipadas               | A    | A      | Implementado (DOCX/HTML/PDF; DOCX real exige LibreOffice no servidor)                                                                                                                                                                                                               | `templates`                                                                                   | `docs/fase-2/modelos.md`                             |
| §2.2 Formulário público                          | B    | A      | Implementado (confirmação por e-mail, anti-abuso; CAPTCHA não escolhido)                                                                                                                                                                                                            | `public_forms`                                                                                | `formulario-publico.md`                              |
| §2.3 Múltiplos documentos                        | A    | A      | Implementado                                                                                                                                                                                                                                                                        | `multi_document`                                                                              | `multi-documento-e-papeis.md`                        |
| §2.4 Testemunha, aprovador, visualizador         | A    | A      | Implementado                                                                                                                                                                                                                                                                        | `participant_roles`                                                                           | `multi-documento-e-papeis.md`                        |
| §2.5 Lembretes e envio agendado                  | A    | A      | Implementado (canal e-mail)                                                                                                                                                                                                                                                         | `reminders`                                                                                   | `lembretes-e-agendamento.md`                         |
| §2.6 Presencial em tablet                        | B    | A      | Implementado (anfitrião + código por e-mail)                                                                                                                                                                                                                                        | `in_person`                                                                                   | `presencial-e-lote.md`                               |
| §2.7 Assinatura em lote                          | B    | A      | Implementado (autorização item a item)                                                                                                                                                                                                                                              | `batch_signing`                                                                               | `presencial-e-lote.md`                               |
| §2.8 Logo, cores, remetente                      | B    | A + B  | Logo/cores/Reply-To: Implementado. Domínio de envio próprio: **Simulador**                                                                                                                                                                                                          | `branding`, `sender_domains`                                                                  | `branding.md`                                        |
| §2.9 SMS/WhatsApp e PIN                          | B    | B + A  | PIN do remetente: Implementado. Código por SMS/WhatsApp: **Simulador**                                                                                                                                                                                                              | `sms_whatsapp`, `pin_auth`                                                                    | `canais-e-pin.md`                                    |
| §2.10 Captura de foto                            | B    | A      | Implementado, **flag desligada até a decisão jurídica** (LGPD art. 11)                                                                                                                                                                                                              | `identity_capture`                                                                            | `identidade.md`                                      |
| §2.11 CNPJ e CPF                                 | B    | A + B  | Dígitos do CPF e consulta de CNPJ (Minha Receita): Implementado. Consulta cadastral de CPF: **Simulador** (driver `disabled` por padrão)                                                                                                                                            | `cpf_field`, `cnpj_lookup`, `cpf_lookup`                                                      | `identidade.md`                                      |
| §2.12 A1/PFX do participante                     | C    | A      | Implementado (pipeline incremental serializado); só certificados de **teste** usados até aqui                                                                                                                                                                                       | `participant_a1`                                                                              | `a1-do-participante.md`                              |
| §2.13 TSA da operadora + dossiê ZIP              | C    | A      | Dossiê: Implementado. TSA: implementada, **produção atrás do checklist** (HSM/KMS, NTP, OID, AC interna). Nunca rotulada ICP-Brasil; perfil anunciado continua PAdES-B-B                                                                                                            | `dossier_export`, `operator_tsa`, `pades_bt`                                                  | `carimbo-e-dossie.md`                                |
| §2.14 Permissões, times, tags, relatórios, admin | A    | A      | Implementado (tabelas próprias, sem spatie)                                                                                                                                                                                                                                         | `custom_roles`, `tags`, `reports`, `audit_log`, `admin_users`, `admin_audit`, `impersonation` | `permissoes-e-times.md`, `tags-relatorios-e-logs.md` |
| §2.15 API REST v1                                | D    | A      | Implementado (tokens Sanctum por organização, abilities fechadas, idempotência, RFC 9457, OpenAPI via Scramble)                                                                                                                                                                     | `api_integrations`                                                                            | `api-v1.md`                                          |
| §2.16 Webhooks de saída                          | D    | A      | Implementado (HMAC, retentativas, SSRF própria com pino de IP). **Pino provado em HTTP; HTTPS com pino não coberto** — teste de fumaça https real antes de ligar em produção                                                                                                        | `outbound_webhooks`                                                                           | `webhooks.md`                                        |
| §2.17 n8n / Zapier / Make                        | D    | A + B  | REST Hooks e guia no-code: Implementado. Apps publicados nos marketplaces: **não feitos** (classe B: conta de desenvolvedor e revisão do fornecedor). Receita "Drive → envelope → planilha" **não executada em conta real**                                                         | `rest_hooks` (exige `api_integrations` e `outbound_webhooks`)                                 | `integracoes-no-code.md`                             |
| §2.18 WhatsApp Business                          | B    | B      | **Simulador** (serviço próprio sem documentação)                                                                                                                                                                                                                                    | `sms_whatsapp`                                                                                | `canais-e-pin.md`                                    |
| §2.19 Retenção e preservação                     | C    | A      | Implementado                                                                                                                                                                                                                                                                        | `retention_policies`                                                                          | `retencao-e-preservacao.md`                          |
| §2.20 Pagamentos ampliados                       | D    | A + B  | Meios no Checkout Pro, cancelamento, estorno idempotente, contestação, merchant order, conciliação e painel interno: Implementado (testado com dublê e `Http::fake`; **nunca contra a conta real**). Assinaturas recorrentes (preapproval): **Simulador**, desabilitado em produção | `extended_payments`                                                                           | `pagamentos-e-fiscal.md`                             |
| §2.21 NFS-e                                      | D    | B      | **Simulador/contrato**; emissão real bloqueada (faltam dados, certificado e parecer da operadora). Cada pagamento mostra "não emitida — integração fiscal pendente"                                                                                                                 | `fiscal_invoices`                                                                             | `pagamentos-e-fiscal.md`, `docs/integracoes/nfse.md` |

Nenhum item da Fase 2 ficou em classe C pura. O que é C está na Fase 3 (API direta gov.br,
e-Notariado, CRM card do HubSpot).

## 2. Regras transversais mantidas

- **T1/T2/T3:** o vocabulário é conferido pelo teste de vocabulário (`tests/Feature/Phase2/VocabularyTest.php`).
  Nenhum perfil acima de PAdES-B-B é anunciado. O carimbo da TSA própria é sempre "carimbo do
  tempo da operadora — não é carimbo ICP-Brasil". A API e os webhooks usam o mesmo
  `SignatureStatus::label()` da verificação pública. Sem certificado da operadora, o perfil é nulo e
  o rótulo diz aceite eletrônico (a ponta a ponta da onda D confere isso).
- **T4:** nenhum endpoint inventado. SMS, WhatsApp, API de e-mail, CPF cadastral, NFS-e e
  preapproval existem só como contrato + simulador identificado.
- **T5:** estorno, webhooks e carimbo tratam tempo esgotado como resultado desconhecido: consultam
  antes de repetir e usam chave de idempotência nossa.
- **T8:** todas as flags nascem desligadas. Com elas desligadas, a suíte inteira passa (§6).
- **T10:** tokens de API só como hash; segredos de webhook cifrados e exibidos uma vez; nada de
  segredo em log, fila ou argv.

## 3. Flags e como ligá-las

Duas camadas: o **interruptor global** (`ASSINAVELOX_FEATURE_*` no `.env`) **e**, para as flags de
organização, a chave em `plans.features` do plano vigente. A flag só liga a interface; a
autorização continua nas Policies. `HandleInertiaRequests::features()` expõe todas ao front.

| Flag                                                                                                            | Camada                                                             | Onda | Condição antes de ligar em produção                                                                            |
| --------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ | ---- | -------------------------------------------------------------------------------------------------------------- |
| `templates`, `multi_document`, `participant_roles`, `reminders`, `custom_roles`, `tags`, `reports`, `audit_log` | global E plano                                                     | A    | LibreOffice no servidor (DOCX); revisão jurídica dos textos novos                                              |
| `admin_users`, `admin_audit`, `impersonation`                                                                   | global                                                             | A    | Consentimento de acesso do suporte nos Termos (Q15)                                                            |
| `public_forms`, `branding`, `in_person`, `batch_signing`, `cpf_field`, `pin_auth`                               | global E plano                                                     | B    | Revisão jurídica dos textos de aceite                                                                          |
| `cnpj_lookup`                                                                                                   | global (E plano com organização)                                   | B    | Aceitar a instância pública do Minha Receita, sem SLA                                                          |
| `sms_whatsapp`, `sender_domains`, `cpf_lookup`                                                                  | global E plano                                                     | B    | Documentação e credenciais dos serviços próprios (§5.1)                                                        |
| `identity_capture`                                                                                              | global E plano                                                     | B    | Decisão jurídica LGPD (base legal, RIPD, retenção)                                                             |
| `participant_a1`, `dossier_export`, `retention_policies`                                                        | global E plano                                                     | C    | Revisão do consentimento A1; prazos legais de guarda                                                           |
| `operator_tsa`, `pades_bt`                                                                                      | global                                                             | C    | Checklist da TSA (`php artisan tsa:status`); `pades_bt` não muda o perfil anunciado                            |
| `api_integrations`                                                                                              | global E plano                                                     | D    | — (depende só de engenharia)                                                                                   |
| `outbound_webhooks`                                                                                             | global E plano                                                     | D    | Teste de fumaça contra um endpoint https real (pino + SNI); fila no Horizon se não for `default`               |
| `rest_hooks`                                                                                                    | global E plano, **e** `api_integrations` **e** `outbound_webhooks` | D    | As duas anteriores ligadas                                                                                     |
| `extended_payments`                                                                                             | global                                                             | D    | Chave Pix na conta vendedora; primeira consulta de meios com a credencial real; política de estorno confirmada |
| `fiscal_invoices`                                                                                               | global                                                             | D    | Só mostra o status; a emissão real continua bloqueada (`ASSINAVELOX_FISCAL_PROVIDER=none`)                     |

`.env.example` lista todas, desligadas. Os dados de demonstração ligam as flags de plano na
Horizonte e as deixam desligadas na Vega (§5 do relatório de cada onda e §4.5 abaixo).

## 4. Onda D — integração (I-2D)

### 4.1 Fiação entre áreas (pendências que as áreas deixaram)

| Ponto                               | O que foi feito                                                                                                                                                                                                                                                                                                                             |
| ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `HandleInertiaRequests::features()` | `api_integrations` agora vem de `ApiFeature::enabled()` (era sempre `false`); entraram `outbound_webhooks`, `rest_hooks` (global E plano) e `extended_payments`, `fiscal_invoices` (plataforma). Tipos em `resources/js/types/index.ts`; os casts locais do front foram removidos.                                                          |
| Barra lateral                       | "Planos e faturamento" do painel interno deixa de ser placeholder quando `extended_payments` está ligada.                                                                                                                                                                                                                                   |
| Agendamento (`routes/console.php`)  | Conciliação diária (`ReconcilePaymentsJob`, 04:10); retenção dos logs da API (`model:prune` de `ApiRequestLog`, 04:40); varredura nova `rest-hooks:prune` (de hora em hora).                                                                                                                                                                |
| REST Hook de token vencido          | Achado do D-PLAT: um token só **vencido** (não revogado) continuava recebendo eventos. Agora o motor não cria nem tenta entrega para assinatura cujo token venceu, foi revogado ou apagado (`WebhookAccess::restHookTokenUsable`, no fan-out e em cada tentativa, que cancela com `api_token_inactive`), e a varredura remove a assinatura. |
| Aviso `webhook_failed`              | Entrou no catálogo de Configurações › Notificações (linha exibida só quando a organização tem endpoint de webhook) e em `DeliveryPurpose`. O aviso respeita a preferência de cada pessoa e sai pelo `TrackedMailChannel`, com registro em `delivery_attempts`. A linha oculta preserva a preferência salva.                                 |
| Registro de atividades              | `api_token.created` e `api_token.revoked` na categoria nova "API e integrações" (`AdminEventCatalog`).                                                                                                                                                                                                                                      |
| Detalhes do envelope                | Linha "Webhook" (roadmap §2.0): a última entrega do documento (situação, evento e hora), com link para o histórico só para quem tem `manage_integrations`. Só aparece com a flag ligada e endpoint ativo ou entrega existente; senão a prop nem existe.                                                                                     |
| `.env.example`                      | As cinco flags da onda D e as chaves operacionais (fila de webhooks, meios, iniciadores de estorno, preapproval, provedor fiscal).                                                                                                                                                                                                          |
| Documentos das áreas                | `api-v1.md`, `webhooks.md` e `pagamentos-e-fiscal.md` formatados (`vp fmt`) para `npm run check` passar; conteúdo inalterado.                                                                                                                                                                                                               |

### 4.2 Defeitos corrigidos

| Defeito                                                                                                                                                                                                                                                                      | Correção                                                                                                                                          |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Página do endpoint de webhook em branco** (QA no navegador). O último item da trilha de navegação não tinha `href`; o `AppTopbar` chamava `toUrl(undefined)` e a página inteira caía com `TypeError`. O `types:check` não pegava porque `.layout` era um literal sem tipo. | Item com `href` e `satisfies { breadcrumbs: BreadcrumbItem[] }`, para o compilador pegar a próxima vez. Nenhuma outra página tem item sem `href`. |
| 4 avisos de lint em arquivos novos (`integrations/types.ts`, `integrations/docs.tsx`)                                                                                                                                                                                        | União redundante `… \| string` e `String()` sobre valor não textual.                                                                              |
| PHPStan: comparação sempre falsa no presenter novo                                                                                                                                                                                                                           | Removida.                                                                                                                                         |

### 4.3 Asserções de testes existentes alteradas

| Teste                           | Mudança e justificativa                                                                                                                                                                  |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Organizations/SharedPropsTest` | `features` ganhou as quatro chaves novas da onda D, todas `false` — mesmo padrão das ondas A, B e C (roadmap T8). `api_integrations` já existia e continua `false` com a flag desligada. |

| `Phase2/PublicForms/PublicFormAntiAbuseTest` › "o carimbo emitido pela página funciona depois do tempo mínimo" | **Nenhuma asserção mudou.** O teste passou a congelar o relógio no início (`$this->freezeTime()`): dependia do relógio real e falhava de forma intermitente sob carga paralela (§6). |

Nenhuma outra asserção existente foi alterada nesta integração. (As áreas D-API e D-HOOK
estenderam `EnumCatalogTest` e `AllGetRoutesTest` só com acréscimos, justificados nos seus
relatórios.)

### 4.4 Ponta a ponta (`tests/Feature/EndToEnd/Phase2OndaDTest.php`)

Com as flags ligadas, pelas rotas HTTP reais, sem rede (DNS falso, `Http::fake`,
`Http::preventStrayRequests`) e com o pdftool real:

1. O proprietário cria uma chave de API **pela tela**, com abilities explícitas. A resposta é
   `no-store`, o banco guarda só o hash e a listagem nunca traz o texto.
2. O proprietário cadastra um endpoint de webhook **pela tela**, apontando para um receptor de
   teste cujo nome resolve para um endereço interno. **Recusado** até a configuração de teste
   liberar a faixa (`webhooks.testing_allowed_cidrs`, ignorada em produção). O segredo aparece uma vez.
3. Pela API: REST Hook para `envelope.completed` → documento gerado de um **modelo** (idempotente)
   → **anexo** enviado (multi-documento) → **participantes** (mantendo o do modelo e acrescentando
   outro) e **campos** por documento → **envio com `Idempotency-Key`**, repetido: a mesma resposta,
   um único `envelope.sent` na trilha e dois convites, não quatro.
4. Os dois participantes assinam pela **página pública** (código por e-mail, cada arquivo
   apresentado). A finalização roda com o pdftool.
5. O receptor recebe `envelope.sent`, os dois `recipient.signed` ("aceite eletrônico — não é
   assinatura com certificado") e `envelope.completed`, todos com **HMAC válido com o segredo
   dele** e inválido com o do REST Hook. O REST Hook recebe **só** a conclusão, uma vez, com o
   próprio segredo. O rótulo e o hash final são os da verificação pública. Nenhum corpo contém
   nome, e-mail, título, código, token ou segredo.
6. Pela API: registro de verificação sem e-mails; PDF final de **cada** arquivo baixado e
   conferido pelo SHA-256; logs de requisição sem o texto do token; linha "Webhook" nos
   Detalhes; revogar a chave remove o REST Hook dela e o token passa a receber 401.
7. Token **apenas vencido**: a assinatura dele deixa de receber e a varredura a remove.
8. Cobrança: estorno **parcial** (plano e cota inalterados) e estorno **total** repetido com a
   mesma chave (um só pedido ao provedor; o plano não renova, fica ativo até o fim do ciclo,
   sem inadimplência, e a cota usada não volta).

### 4.5 Dados de demonstração (`DemoOrganizationSeeder`)

- Plano da **Horizonte** com `api_integrations`, `outbound_webhooks` e `rest_hooks` ligados; o
  da **Vega**, desligados. `extended_payments` e `fiscal_invoices` são só do `.env`.
- Horizonte:
    - uma chave de API de exemplo (só o hash é gravado; o texto é descartado no seeder e nunca impresso);
    - um endpoint de webhook para `destino-invalido.invalid` (esse domínio nunca resolve), **pausado**;
    - um estorno parcial aprovado no pagamento aprovado e um pagamento de ciclo antigo estornado por inteiro.
- Conferido em `database/i2d.sqlite` (`migrate:fresh --seed`).

### 4.6 QA no navegador

Banco `database/i2d.sqlite`, `php -S` na porta 8137 com um roteador de QA do scratchpad. O
roteador acrescenta só uma rota de login sem senha e aponta o Vite para o build, sem tocar no
`public/hot` do proprietário. Flags da onda D ligadas. O processo foi encerrado ao fim, só pelo PID.

| Tela                                  | Resultado                                                                                                                                                      |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| API e integrações › Documentação      | Base URL, limites, referência OpenAPI 3.1, autenticação; abas Documentação/Chaves/Webhooks/Logs.                                                               |
| Chaves                                | Só o prefixo (`avk_…`), abilities, validade e "Nunca usada". Diálogo de criação com as permissões, a validade e o aviso "exibida uma única vez".               |
| Webhooks (lista)                      | Endpoint pausado com o motivo, orientação de HMAC, janela de 5 min e deduplicação, política de tentativas.                                                     |
| Webhooks (detalhe)                    | Estava em branco (§4.2). Corrigido: URL, motivo da pausa, eventos, responsável, segredo só com a pista, rotação, histórico vazio.                              |
| Logs                                  | Só metadados, 30 dias, filtros por período, resultado e chave.                                                                                                 |
| Plano e cobrança (proprietário)       | Meios aceitos, estorno parcial e total, cancelar pagamento pendente, "Nota fiscal: não emitida — integração fiscal pendente", recibo "não é documento fiscal". |
| Planos e faturamento (painel interno) | Aviso de gateway de desenvolvimento (dublê), receita líquida por moeda, abas, meios, política de estorno, recorrência e NFS-e desabilitadas com o que falta.   |
| Vega (plano sem as flags)             | Placeholder da Fase 1, igual ao de antes.                                                                                                                      |

Console sem erros nas telas acima, depois da correção.

**Limites honestos da QA:**

- **Nenhum formulário foi enviado pelo navegador:** criar chave, cadastrar URL bloqueada, estornar.
  A regra de segurança do agente exige autorização do proprietário para enviar formulários.
- Esses fluxos estão cobertos pelas mesmas rotas nos testes de feature e na ponta a ponta:
    - chave exibida uma vez;
    - URL interna recusada e depois liberada só pela configuração de teste;
    - estornos.
- As telas foram comparadas com os mocks só no conteúdo. Os mocks prometem coisas que não existem
  e **não** foram implementadas: sandbox `av_test_`, lista de IPs de origem, SDKs e a pílula
  "API operacional".

### 4.7 Revisão adversarial da onda D

Doze achados (três lentes: segurança da API; webhooks, REST Hooks e SSRF; pagamentos, fiscal e
produto), cada um com um teste em `tests/Feature/Review/Phase2D/` que falhava antes da correção.
Todos corrigidos na causa; nenhum teste de revisão foi afrouxado.

| #   | Sev.  | Achado                                                                                        | Correção                                                                                                                                                                                                                                                                                                                               |
| --- | ----- | --------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | alta  | Token ignorava a política "Exigir 2FA" da organização                                         | `ApiAuthenticate::resolve()` recusa (401) criador sem TOTP quando `OrganizationSettings::requireTwoFactor()`; `ApiTokenManager::issue()` recusa emitir na mesma situação. api-v1 §2.3/§2.4.                                                                                                                                            |
| 2   | média | `recipients:read` era decorativa: detalhe e trilha entregavam nome/e-mail dos participantes   | `RecipientResource` só entrega `name`, `email`, `phone_masked`, `auth_method` e `refusal_reason` com a ability (mesma forma, campos `null` sem ela) — vale para detalhe, `PUT …/recipients`, `send`, `cancel` e geração por modelo; `EventResource` anula `actor.name` de participante. Helper `ApiContext::allows()`. api-v1 §3/§5.1. |
| 3   | baixa | Repetição idempotente devolvia o documento a quem já não o via                                | `ApiIdempotency::reauthorize()`: antes do replay, `{envelope}` da rota e o envelope descrito na resposta passam por `ApiEnvelopeAccess::ensureVisible` (404), `{template}` pela `TemplatePolicy`. api-v1 §7.                                                                                                                           |
| 4   | baixa | Token seguia valendo com o e-mail do criador não verificado                                   | `ApiAuthenticate::resolve()` exige `hasVerifiedEmail()` (401). api-v1 §2.3/§2.4.                                                                                                                                                                                                                                                       |
| 5   | alta  | Integrador sem `view_all` redirecionava o endpoint do proprietário para a própria URL         | Nova policy `WebhookEndpointPolicy::redirect` (responsável pelo endpoint ou `view_all_envelopes`), exigida para trocar a URL (`UpdateWebhookEndpointRequest`) e rotacionar o segredo (`RotateWebhookSecretRequest`) → 403. Caso novo em `Phase2/Webhooks/IsolationTest`. webhooks §7/§9.                                               |
| 6   | média | Trecho da resposta no histórico reexpunha o envelope com o corpo oculto                       | `WebhookPresenter::deliveryDetail` anula `response_excerpt` e `remote_ip` de cada tentativa quando `payload_hidden`. webhooks §7/§9.                                                                                                                                                                                                   |
| 7   | baixa | Reenvio manual apagava a trava viva de um worker (checagem no modelo + `save()`)              | `WebhookEndpointManager::resend` reivindica por `UPDATE … WHERE locked_until IS NULL OR locked_until <= agora`; 0 linhas → "sendo tentada agora". webhooks §5.                                                                                                                                                                         |
| 8   | alta  | Estorno `in_process`/`authorized` ficava `pending` para sempre e bloqueava novos pedidos      | Novo `RefreshPendingRefunds`, chamado por `SyncPaymentFromGateway` (flag ligada, só com linha `pending`): `GET …/refunds` pelo `provider_refund_id`; senão, o total estornado da consulta do pagamento cobre a linha → `approved`. pagamentos §3.                                                                                      |
| 9   | alta  | Saldo estornável ignorava estornos aprovados cuja consulta falhou; "integral" inflava o total | `Payment::refundableCents()` = valor − max(`refunded_cents`, linhas aprovadas/abertas); "integral" só sem nada estornado por nenhuma fonte; a linha grava o valor **devolvido pelo provedor**. pagamentos §3.                                                                                                                          |
| 10  | média | "Receita líquida" contava contestação perdida e estorno feito fora do app                     | `AdminBillingReport::revenue()` por pagamento recebido no período: estornado = max(`refunded_cents`, aprovados aqui); novo `charged_back_cents` (charged_back sem cobertura); líquido = bruto − estornado − contestado. Painel mostra "contestado" na legenda. pagamentos §9.                                                          |
| 11  | média | Tela do cliente anunciava Pix/Boleto/Cartão como aceitos sem consulta à conta                 | `BillingController` envia `available` (`null` = não consultada); `settings/billing.tsx` diz "Meios configurados para o checkout: … (ainda não confirmados na conta do Mercado Pago)". pagamentos §2.                                                                                                                                   |
| 12  | baixa | Rótulo fiscal prometia "consultando antes de reemitir", mas nada consulta                     | Rótulo `FiscalInvoiceStatus::PENDING` = "emissão sem confirmação — em análise pela equipe; não será reemitida automaticamente"; o log da emissão inconclusiva leva `alert=fiscal_invoice_inconclusive`. pagamentos §11.                                                                                                                |

Asserções existentes alteradas nesta revisão (só acréscimos de chave, justificados pelos documentos):

| Teste                                                                       | Mudança e justificativa                                                                                                                                                                                                                   |
| --------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Phase2/Billing/AdminBillingTest` › "receita por moeda…"                    | Cada linha de `revenue` ganhou `'charged_back_cents' => 0`. Os valores de bruto, estornado e líquido não mudaram (o pagamento do teste é `approved`; a contestação dele não tem o pagamento em `charged_back`). Achado 10; pagamentos §9. |
| `Phase2/Billing/PaymentMethodsTest` › "a tela de cobrança mostra os meios…" | `extended.methods.0` e `.2` ganharam `'available' => null` (conta ainda não consultada). `offered` não mudou. Achado 11; pagamentos §2 ("a disponibilidade aparece como 'não consultada'").                                               |

Mudança de semântica documentada: a receita do painel interno passa a ser **coorte** — estornos e
contestações contam contra o pagamento recebido no período, não pela data de confirmação do
estorno. Com as flags desligadas nada disso é executado (a API, os webhooks e o `extended_payments`
continuam 404/sem efeito).

## 5. O que o proprietário precisa fornecer (lista consolidada da Fase 2)

### 5.1 Serviços próprios (documentação + credenciais)

1. **SMS:** endpoint, autenticação, erros, status, webhook assinado, limites, custo, remetente aprovado [§2.9].
2. **WhatsApp Business:** API de envio por template, templates pré-aprovados por finalidade,
   webhook de status assinado, limites e custo; decisão entre o número da operadora e o do cliente [§2.18, §2.9].
3. **Consulta cadastral de CPF:** endpoint, entradas exigidas, campos, códigos, SLA, custo; base
   legal e finalidade; decisão sobre o modo "estrito" [§2.11].
4. **API do serviço de e-mail:** cadastro e situação de domínio (DKIM, SPF, Return-Path),
   credenciais; confirmação do alinhamento do SMTP atual [§2.8].

### 5.2 Contratos, contas e credenciais de terceiros

5. **Mercado Pago:**
    - chave Pix cadastrada na conta vendedora;
    - primeira consulta de meios com a credencial real;
    - conta vendedora real para testar assinaturas recorrentes;
    - confirmar os webhooks `subscription_*`;
    - `MERCADOPAGO_SELLER_USER_ID`, se o `X-Caller-Id` for exigido (NÃO CONFIRMADO) [§2.20].
6. **NFS-e:**
    - CNPJ, município (IBGE) e regime;
    - cadastro no CNC;
    - confirmação do Emissor Nacional no município;
    - certificado digital da operadora para mTLS e XMLDSig;
    - escolha entre Sefin direto e provedor comercial.

    **Prazo externo:** a Resolução CGSN 191/2026 obriga ME/EPP do Simples a emitir pelo Emissor
    Nacional a partir de 1º/11/2026. O enquadramento é decisão contábil [§2.21].

7. **Zapier / Make:** contas de desenvolvedor (apps publicados); decisão sobre um nó próprio no
   n8n. A publicação só depois de congelar a API v1 [§2.17].
8. **Certificado A1 de produção da operadora** (a Fase 2 inteira só usou certificados de teste)
   [§2.12, finalização].

### 5.3 Infraestrutura operacional

9. **LibreOffice** no servidor de produção [§2.1].
10. **TSA da operadora:**
    - chave em HSM/KMS;
    - NTP monitorado;
    - OID de política próprio;
    - AC interna com EKU `timeStamping` crítica [§2.13].
11. **Rede de saída** do worker de finalização até as ACs (OCSP/CRL) [§2.12].
12. **Webhooks:**
    - teste de fumaça contra um endpoint **https** real (pino de IP + SNI; o teste local só cobre HTTP);
    - se `ASSINAVELOX_WEBHOOKS_QUEUE` for uma fila própria, incluí-la no Horizon [§2.16].
13. **Formulário público:** cache persistente (`database`/`redis`) para as marcas de uso em produção [§2.2].
14. **Scheduler** rodando (`schedule:run` a cada minuto): conciliação, retentativas de webhook,
    varreduras de REST Hook, retenção e limpezas dependem dele.

### 5.4 Decisões jurídicas

15. **Revisão jurídica:**
    - textos de aceite das ondas A e B;
    - consentimento A1 (`v1-a1-participante-2026-09-11`);
    - cláusula de acesso do suporte (Q15).
16. **LGPD da captura de foto:** base legal (art. 11), controlador × operador, RIPD e retenção [§2.10].
17. **Prazos legais de guarda,** inclusive do XML fiscal; janela de backup declarada na Política de Privacidade [§2.19, §2.21].
18. **Parecer contábil:** subitem LC 116, NBS, ISS, IBS/CBS [§2.21].
19. **Titular do certificado ≠ participante:** exigir CPF no participante que usa o próprio
    certificado, ou pelo menos um alerta nas evidências [§2.12].

### 5.5 Decisões de produto

20. **Política de estorno:** adotada a conservadora, a confirmar. Também decidir:
    - se o proprietário pode pedir estorno (`ASSINAVELOX_BILLING_REFUND_INITIATORS`);
    - o que fazer com pagamento aprovado depois de expirado ou cancelado;
    - se uma contestação vencida a favor reativa o envio sozinha [§2.20].
21. **Q20 × cancelamento automático** do Mercado Pago após 3 parcelas recusadas [§2.20].
22. **Permissões do token de API:** hoje o token vale a interseção das suas abilities com as
    permissões atuais de quem o criou. A decisão pendente do roadmap sobre um papel `integration`
    ficou em aberto de propósito: esse papel poderia dar ao token mais poder do que tem quem o criou [§2.15].
23. **Pendências já listadas no roadmap:**
    - posicionamento de campos em modelos DOCX/HTML;
    - aprovador em ordem paralela;
    - lote entre organizações;
    - PIN × OTP;
    - tombstone e preservação na exclusão da organização;
    - retenção de PFX;
    - regra CPF/CN × participante;
    - dossiê de envelope recusado, expirado ou cancelado.

## 6. Verificações (números reais)

| Verificação                                                                                   | Resultado                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| --------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan test --testsuite=Unit,Feature --parallel` (1ª execução)                          | **1.902 de 1.902** passando, 17.436 asserções, 262 s                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| `php artisan test --testsuite=Browser` encadeada logo depois (1ª tentativa)                   | **Travou antes do primeiro teste**: o servidor do Playwright ficou escutando, mas nunca abriu o navegador. pest e node estavam parados, com CPU zero, e o log sem escrita por mais de 15 min. A árvore de processos da própria execução foi encerrada pelo PID.                                                                                                                                                                                                                                                                        |
| `php artisan test --testsuite=Browser` (sozinha, logo em seguida)                             | **36 passando, 3 pulados** de 39 (os `->skip()` da Fase 1), 740 asserções, 45 s                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| Execução encadeada repetida: Unit+Feature (paralelo)                                          | **1.901 de 1.902**, 17.433 asserções, 231 s. A única falha foi `PublicFormAntiAbuseTest` › "o carimbo emitido pela página funciona depois do tempo mínimo" (onda B), por sensibilidade ao relógio real: sob carga paralela, o envio "rápido demais" chegou depois do tempo mínimo e foi aceito. Isolado, o arquivo passou 3 de 3 vezes (11 testes, 115 asserções). **Corrigido:** o teste congela o relógio (o `FillTimer` só usa `Carbon::now()`). Depois disso: 3 de 3 isolado e 52 de 52 na pasta `Phase2/PublicForms` em paralelo. |
| … seguida da Browser, encadeada                                                               | **36 passando, 3 pulados** de 39, 740 asserções, 45 s — sem travar                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| **Execução final encadeada** (depois da correção do teste): Unit+Feature (paralelo) → Browser | **1.902 de 1.902**, 17.436 asserções, 238 s → **36 passando, 3 pulados** de 39, 740 asserções, 45 s. Tudo verde, sem travar.                                                                                                                                                                                                                                                                                                                                                                                                           |
| `tests/Feature/EndToEnd/Phase2OndaDTest.php` (novo)                                           | **3 testes, 203 asserções**, pdftool real, ~13 s                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `tools/pdftool`: `pytest -q`                                                                  | **139 passando** (sem mudança em relação à onda C)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `vendor/bin/phpstan analyse` (projeto inteiro)                                                | **0 erros**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| `vendor/bin/pint --test`                                                                      | verde                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `npm run types:check`                                                                         | 0 erros                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| `npm run check`                                                                               | 316 arquivos formatados, 0 avisos de lint em 251 (os 4 avisos das telas novas foram corrigidos)                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| `npm run build`                                                                               | OK                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `php artisan wayfinder:generate --with-form`                                                  | OK                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `tests/Feature/Phase2/VocabularyTest.php`                                                     | verde (dentro da suíte)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| **Depois da revisão adversarial da onda D (§4.7)** — `tests/Feature/Review` inteiro           | **262 de 262**, 1.069 asserções (os 17 testes de `Review/Phase2D`: 16 falhavam antes da correção)                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| … `php artisan test --testsuite=Unit,Feature` (serial)                                        | **1.920 de 1.920**, 17.537 asserções, 1.626 s (+18 em relação à integração: 17 da revisão e 1 novo em `Phase2/Webhooks/IsolationTest`)                                                                                                                                                                                                                                                                                                                                                                                                 |
| … `php artisan test --testsuite=Browser` (logo em seguida)                                    | **36 passando, 3 pulados** de 39, 740 asserções, 44 s                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| … `tools/pdftool`: `pytest -q`                                                                | **139 passando**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| … `phpstan` / `pint --dirty` / `types:check` / `npm run check` / `npm run build`              | 0 erros / verde / 0 erros / formatação e lint sem avisos / OK                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |

Linha de base antes da integração: as áreas relataram de 1.851 a 1.899 testes Unit+Feature, com
quebras cruzadas já corrigidas por elas. A primeira execução desta integração correu enquanto um
arquivo era editado e não vale como linha de base.

## 7. Pendências de engenharia que continuam abertas

- **Trilha de auditoria:** gestão de endpoint de webhook e criação/remoção de REST Hook ficam só no
  log estruturado. Os eventos da API não carregam a correlação do log de requisição (só `{"channel":"api"}` em `envelope.created`).
- **API v1:**
    - sem PATCH de metadados do envelope e sem exclusão de rascunho;
    - o spec OpenAPI não é validado por um validador externo em CI;
    - o contrato só congela depois de decidido o item 22 acima.
- **Webhooks:** o caminho HTTPS com pino não tem teste automatizado (o servidor local não fala TLS).
- **Fiscal:** o `IntegrationsServiceProvider` continua aceitando só `fake` para o contrato
  `FiscalInvoiceProvider`. Os serviços da onda D escolhem o provedor por uma fábrica própria.
  Unificar quando houver provedor real.
- **Onda A:** recusa e aceite por arquivo; teste de navegador do fluxo a partir de modelo (limitação do plugin com multipart).
- **Onda B:** a origem da imagem é declarada pelo navegador; as marcas do formulário público ficam em cache volátil (§5.3 item 13).
