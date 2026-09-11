# Fase 2 — Formulário público que gera envelope (roadmap §2.2)

> Área C-FORM, onda B. Identificadores em inglês; texto em PT-BR. Precedência: `docs/design/RECONCILIACAO.md` → `docs/arquitetura.md` → `docs/design/ROUTES_AND_PAGES.md` → `docs/roadmap.md`.
> Estado: implementado atrás da flag `public_forms` (desligada por padrão). Com a flag desligada, nada muda no que já existe e todas as rotas do recurso respondem 404.
> Classificação na viabilidade: **A** (sem dependência externa). Nenhum CAPTCHA foi adotado: o provedor não foi pesquisado (viabilidade §2.2).

## 1. O que o recurso entrega

Quem gerencia os modelos publica um **formulário** a partir de um modelo da onda A (docs/fase-2/modelos.md). Qualquer pessoa com o link abre a página, sem login, e preenche o nome, o e-mail e as variáveis marcadas como públicas. Em seguida recebe um **link de confirmação por e-mail**.

Até a confirmação, **nada** é criado: nenhum envelope e nenhum consumo de cota. Depois dela, o envelope é gerado pelo mesmo serviço do "Usar modelo". A partir daí há dois caminhos:

- **envio automático**: o envelope sai para assinatura na hora, e quem preencheu é o signatário;
- **fila de revisão**: o envelope fica em rascunho até alguém da organização aprovar.

```
público                         servidor                                  organização
───────                         ────────                                  ───────────
abre /formulario/{token} ─────▶ carimbo de tempo cifrado
envia respostas ──────────────▶ limites · isca · tempo · validação tipada
                                cota (aviso) · grava envio cifrado
◀──────────── e-mail com link   (nenhum envelope, nenhuma cota)
abre o link (GET: só a tela)
clica "Confirmar" (POST) ─────▶ reivindica o envio (UPDATE condicional)
                                revalida formulário · limite · cota
                                CreateEnvelopeFromTemplate (onda A)
                                apaga o payload
                                ├─ envio automático → SendEnvelope ─────▶ convite ao público
                                └─ fila de revisão → rascunho ──────────▶ aprovar / recusar
```

## 2. Flag, autorização e isolamento

- **Flag `public_forms`** (`App\Services\PublicForms\PublicFormsFeature`) segue a mesma regra das flags da onda A (`DomainFeatures::enabled`): vale quando a configuração global `assinavelox.features.public_forms` (`ASSINAVELOX_FEATURE_PUBLIC_FORMS`, padrão `false`) **e** `plans.features.public_forms` do plano vigente dizem sim.
    - Como o formulário depende de modelos (§2.2 depende de §2.1), exige também a flag **`templates`** ligada.
- **Desligada, 404 em tudo**:
    - nas rotas internas, pelo middleware do controller, antes de FormRequest e Policy;
    - nas públicas, pela checagem do próprio controller, com a mesma resposta de token inexistente.
    - Um link já compartilhado passa a se comportar como link inexistente.
- **`PublicFormPolicy`**:

| Ação                                                                                     | Regra                                     |
| ---------------------------------------------------------------------------------------- | ----------------------------------------- |
| ver a lista e a configuração, criar, salvar, publicar, pausar, revogar, recusar um envio | `manage_templates`                        |
| aprovar um envio da fila (é o que envia o documento)                                     | `manage_templates` **e** `send_envelopes` |

Nos papéis de sistema, proprietário e administrador têm as duas permissões; o operador não gerencia modelos e recebe 403.

- **Isolamento**:
    - `PublicForm` e `PublicFormSubmission` usam `BelongsToOrganization`, com escopo global e binding escopado, então formulário ou envio de outra organização dá 404.
    - Na criação, o modelo é resolvido dentro da organização corrente.
    - A rota pública resolve o formulário **só** pelo token e usa a organização dele. `organization_id` nunca vem do navegador.

## 3. Situações do formulário

`draft` → `active` ⇄ `paused`, e qualquer situação → `revoked` (definitivo).

| Situação                            | Página pública                                             | Envio    |
| ----------------------------------- | ---------------------------------------------------------- | -------- |
| rascunho (`draft`)                  | 404, igual a token inexistente                             | 404      |
| publicado (`active`)                | formulário                                                 | aceito   |
| pausado (`paused`)                  | "Formulário pausado"                                       | recusado |
| encerrado (`expires_at` no passado) | "Formulário encerrado"                                     | recusado |
| com pendência                       | "Temporariamente indisponível", sem expor o motivo         | recusado |
| revogado (`revoked`)                | 404; os envios que aguardavam confirmação são **apagados** | 404      |

Pendências (`PublicFormSchema::issues`), mostradas na tela interna:

- modelo arquivado;
- **modelo com versão nova**: o formulário guarda a versão contra a qual foi salvo e fica indisponível até alguém salvá-lo de novo;
- configuração incompleta, como participante fixo faltando;
- papel de testemunha, aprovador ou visualizador com a flag `participant_roles` desligada;
- **responsável sem permissão**.

Publicar exige zero pendências.

**Responsável.** Quem salva o formulário vira o responsável. Os envelopes são criados em nome dele, porque `envelopes.created_by_user_id` é obrigatório. Se ele deixar a organização ou perder `create_envelopes` (ou `send_envelopes`, no envio automático), o formulário fica indisponível até outro usuário salvá-lo.

## 4. Configuração

A configuração é salva contra a **versão atual** do modelo (`PUT public_forms.update`) e validada em `PublicFormSchema::build`.

- **Variáveis**: cada variável é **preenchida pelo público** (`public_variables`) ou recebe um **valor fixo** (`fixed_values`).
    - O valor fixo passa pela mesma validação tipada do modelo (`VariableValues`).
    - Variável obrigatória sem valor fixo e sem padrão precisa ser pública.
- **Papéis**: quem preenche ocupa **um** papel, que precisa ser de **signatário** (`filler_role`). Cada um dos demais papéis tem um participante fixo (`fixed_participants`, nome e e-mail).
- **Decisão pendente do roadmap resolvida**: sim, o envelope pode ter vários participantes já na primeira versão, mas **só o preenchedor vem do público**. Um e-mail digitado por terceiros e não confirmado não pode receber convite em nome da organização, e ninguém confirmaria os demais endereços.
- **Destino**: `review` (padrão) ou `auto_send`.
    - `auto_send` só é aceito em modelo **PDF fixo** com um campo de assinatura obrigatório para cada papel que assina, porque o envelope precisa nascer pronto.
    - Em Word/HTML os campos são posicionados no editor depois de gerar, então nesses modelos só há fila de revisão.
- **Limite de respostas confirmadas por período**: 1 a 10.000 (padrão 50) por dia, semana ou mês. A janela é **móvel** (últimas 24 horas, 7 dias ou 30 dias), sem "virada" que libere tudo de uma vez.
- **Encerramento** (`expires_at`): fim do dia escolhido, no fuso da organização.
- **Título do documento**: título configurável + " — " + nome de quem preencheu, com no máximo 160 caracteres.

## 5. Ciclo do envio

`public_form_submissions.status`: `pending_confirmation` → `processing` → `pending_review` | `sent` | `failed`, e `pending_review` → `sent` | `rejected`.

1. **Envio** (`POST form_fill.submit`, `PublicFormIntake`), nesta ordem:
    1. limites;
    2. campo-isca e tempo mínimo;
    3. forma e tamanho;
    4. validação tipada;
    5. limite do período e cota (**só aviso**, nada é reservado);
    6. links pendentes por e-mail;
    7. consumo do carimbo de tempo (uso único, §7).

    Depois grava o envio com o payload (`encrypted:array`), o HMAC do e-mail e o HMAC do token de confirmação, e envia o e-mail.

2. **Confirmação** (`PublicFormConfirmation`):
    - O GET mostra só a tela, então clientes de e-mail e antivírus que pré-carregam links não confirmam nada. O POST confirma.
    - O envio é **reivindicado** por um UPDATE condicional (`pending_confirmation` dentro da validade → `processing`). Duplo clique, duas abas ou link reutilizado geram um único envelope; os demais recebem "link já usado".
    - Formulário disponível, limite do período e cota são conferidos **de novo**. Se algo impede, a reivindicação é desfeita e o link continua valendo até vencer.
    - O envelope é gerado por `CreateEnvelopeFromTemplate` **sem nenhuma alteração** no serviço (§6), dentro de `CurrentOrganization::runAs()` da organização do formulário. O **payload é apagado** em seguida: os valores já estão no documento.
    - Envio automático: `SendEnvelope` reserva e confirma a cota como qualquer envio. Se o envio não sair (cota esgotada numa corrida, pendência de preparo), o rascunho vai para a **fila de revisão** com o motivo em `failure_reason`. Nunca some.
    - Se o serviço de modelos recusar os dados (por exemplo, a configuração mudou no meio do caminho), o envio vira `failed` com o motivo e o payload apagado. O log registra só ULIDs e nomes de campo.
3. **Link vencido** (60 minutos por padrão): recusado. O envio não confirmado é **apagado inteiro** (linha, payload, IP e navegador) pela limpeza `SubmissionPurge`, que roda:
    - a cada envio, no formulário em questão;
    - ao abrir a tela interna, na organização;
    - ao revogar.

**Cota.** O aviso de cota antes de gravar serve só para não fazer a pessoa preencher à toa. A reserva de verdade é a do envio do envelope (`PlanLedger` via `SendEnvelope`), depois da confirmação. A mensagem ao público é clara e não expõe o plano: "a organização atingiu o limite de documentos do plano".

## 6. Dados não confiáveis (T6)

- Só as chaves das variáveis marcadas como públicas são lidas. Qualquer outra chave enviada é ignorada, inclusive a de uma variável com valor fixo e campos como `organization_id`, `status` e `destination`.
- Os valores passam pela validação tipada do modelo (`VariableValues`). O preenchimento é o **motor restrito da onda A** (`PlaceholderEngine` e `RestrictedTemplateProcessor`): uma passada, valor escapado, sem Blade, PHP ou `eval`.
    - Blade, `<script>`, `{{ 7*7 }}`, `{!! !!}`, `@php`, fórmula de planilha (`=HYPERLINK(…)`) e marcadores do próprio modelo (`${cpf}`) aparecem **literais** no documento.
    - Há teste ponta a ponta com DOCX (XML do arquivo gerado) e com HTML (corpo antes do DOMPDF).
- O nome digitado vira o nome do destinatário como texto. Nos e-mails, o que vem de terceiros passa por `MailText::escape`.
- **Nenhum arquivo** é aceito do público na Fase 2, só variáveis. Anexos do preenchedor ficam como extensão futura, com a inspeção de upload já existente.

## 7. Antiabuso sem CAPTCHA

| Defesa                  | Onde                     | Padrão                                                                                                                                     |
| ----------------------- | ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------ |
| teto geral por IP       | rota (`throttle:public`) | 60/min                                                                                                                                     |
| IP no formulário        | `PublicFormIntake`       | 10 tentativas / 10 min (toda tentativa conta, válida ou não)                                                                               |
| IP na plataforma        | `PublicFormIntake`       | 30 / 60 min                                                                                                                                |
| volume do formulário    | `PublicFormIntake`       | 120 / 10 min, de qualquer origem                                                                                                           |
| campo-isca              | `website`                | invisível, fora da tabulação, `autocomplete=off`                                                                                           |
| tempo mínimo            | `FillTimer`              | 3 s entre abrir e enviar; carimbo cifrado (APP_KEY) com formulário e instante, **de uso único**; página aberta há mais de 6 h pede recarga |
| tamanho                 | validação                | 5.000 caracteres por valor, ou menos, conforme o tipo; nome ≤ 120; e-mail ≤ 255                                                            |
| links pendentes         | por e-mail (HMAC)        | 3 por formulário na última hora                                                                                                            |
| confirmação obrigatória | —                        | nenhum envelope sem ela                                                                                                                    |
| limite do período       | configurável             | respostas **confirmadas**; envio não confirmado não bloqueia o formulário de ninguém                                                       |

- **Carimbo de uso único** (revisão adversarial da onda B). Cada exibição da página emite um carimbo próprio; o envio **aceito** o consome (`FillTimer::consume()`, marca atômica `Cache::add` no cache padrão, que expira quando o carimbo venceria de qualquer jeito). Reenviar com o mesmo carimbo, sem abrir a página de novo, é recusado com "Esta página já foi usada para um envio…". Sem isso, uma leitura da página servia para envios ilimitados por 6 h e a barreira de tempo só valia no primeiro.
    - A identidade do carimbo vem do vetor de inicialização e do texto cifrado (cobertos pelo MAC), não da string enviada: reformatar o JSON ou o base64 não gera um carimbo "novo".
    - Envio **recusado** (rápido demais, campo inválido, limite, e-mail com links pendentes) não consome: a pessoa corrige e reenvia da mesma página. Toda tentativa continua contando nos limites por IP e por formulário.
    - O consumo acontece depois de todas as barreiras e antes de gravar; a confirmação por e-mail não muda.
    - Limitação: limpar o cache (`cache:clear`) apaga as marcas; carimbos emitidos antes disso voltam a valer até vencer (6 h).
- Todos os limites têm padrão no código (`PublicFormsConfig`) e podem ser sobrescritos em `config('assinavelox.public_forms.*')`, um bloco opcional ainda não declarado no arquivo de configuração.
- **CAPTCHA como extensão**: o ponto de entrada é `PublicFormIntake::submit`, entre o tempo mínimo e a validação.
    - A flag seria `public_forms_captcha`, com o contrato em `App\Integrations\Contracts`.
    - Nenhum provedor foi adotado, porque nenhum foi pesquisado (viabilidade §2.2 e Q14).

## 8. Rotas, telas e props

### Públicas (sem login; `throttle:public`; `X-Robots-Tag: noindex`)

| Nome                     | Método e URL                                        | Tela / efeito                                         |
| ------------------------ | --------------------------------------------------- | ----------------------------------------------------- |
| `form_fill.show`         | GET `/formulario/{token}`                           | `public-forms/fill`                                   |
| `form_fill.submit`       | POST `/formulario/{token}`                          | grava o envio e manda o link; redireciona para `show` |
| `form_fill.confirm.show` | GET `/formulario/{token}/confirmar/{confirmation}`  | `public-forms/confirm` (só mostra)                    |
| `form_fill.confirm`      | POST `/formulario/{token}/confirmar/{confirmation}` | confirma e gera o envelope                            |

- `token` tem 40 caracteres alfanuméricos aleatórios (cerca de 238 bits) e é **diferente** do ULID interno, que carrega o instante de criação. `confirmation` tem 48 caracteres; o banco guarda só o HMAC.
- `public-forms/fill`: `screen` ∈ `form | submitted | paused | expired | unavailable | not_found`, mais `message`.
    - Com `form`: `form{title, instructions, organization_name, role_name, fields[], max_length, confirmation_ttl_minutes}`, `antiabuse{timer_field, timer, honeypot_field}` e `privacy{version, summary, sections[]}`.
    - Com `submitted`: `submitted{email_hint, ttl_minutes}`.
    - Os valores fixos, os participantes fixos e o ULID do formulário **não** vão para a página.
- `public-forms/confirm`: `screen` ∈ `confirm | done | used | expired | invalid | unavailable | not_found`, mais `form{title, organization_name}`, `email_hint` (mascarado), `outcome` (`sent | review | failed`) e `message`. Erro em `errors.confirmation`.
- Leves e sem rastreadores: só o bundle próprio, sem fonte, script ou pixel de terceiros. Mobile-first, com casca própria (`PublicFormShell`).
- **Aviso de privacidade** (`PrivacyNotice`, versão `v1-public-form-2026-09-11`) com linha-resumo e texto completo recolhível. É baseado em `docs/juridico/aviso-de-privacidade-signatario.md` e está **pendente de revisão jurídica**. A versão fica no envio e no evento de confirmação.

### Internas (grupo `app`, prefixo `/formularios`)

| Nome                               | Método e URL                                                    |
| ---------------------------------- | --------------------------------------------------------------- |
| `public_forms.index`               | GET `/formularios` — lista e fila de revisão                    |
| `public_forms.store`               | POST `/formularios` — `{template, title?}` → rascunho           |
| `public_forms.edit`                | GET `/formularios/{publicForm}/editar`                          |
| `public_forms.update`              | PUT `/formularios/{publicForm}`                                 |
| `public_forms.activate`            | POST `/formularios/{publicForm}/publicar` (publicar ou retomar) |
| `public_forms.pause`               | POST `/formularios/{publicForm}/pausar`                         |
| `public_forms.revoke`              | POST `/formularios/{publicForm}/revogar`                        |
| `public_forms.submissions.approve` | POST `/formularios/envios/{submission}/aprovar`                 |
| `public_forms.submissions.reject`  | POST `/formularios/envios/{submission}/recusar`                 |

- `public-forms/index`: `forms[]` (`id, title, template{id,name}, status, status_label, destination, destination_label, public_url, expires_at, expired, updated_at, pending_review_count, sent_count, issues_count`), `queue[]`, `templates[]` e `can{create, approve}`.
- `public-forms/edit`: `form`, `template{…, outdated}`, `variables[]`, `roles[]`, `issues[]`, `options{destinations[{value,label,description,available,reason}], periods[]}`, `submissions[]` (as 30 mais recentes já confirmadas) e `can{update, approve}`.
- Envio da fila (`SubmissionRow`): `id, form{id,title}, status, status_label, failure_reason, failure_message, confirmed_at, reviewed_at, filler{name,email}, envelope{id,title,status,status_label,draft}`.
- Tipos TS em `resources/js/components/public-forms/types.ts`.

**Fila de revisão.**

- **Aprovar** envia pelo `SendEnvelope`: mesma reserva de cota e mesmas pendências de preparo. Modelo Word/HTML sem campos posicionados recusa a aprovação e aponta para o editor.
- **Recusar** exclui o rascunho (soft delete, como "Excluir rascunho"). A pessoa não é avisada.
- Um rascunho enviado ou excluído pelo editor é acertado na fila na leitura seguinte (`PublicFormReview::reconcile`).

## 9. Banco e trilha

**Migrations aditivas** (MySQL 8, sem ENUM de SQL, sem DEFAULT em JSON/TEXT, índices com nome ≤ 64):

- `2026_09_11_120401_create_public_forms_table`;
- `2026_09_11_120402_create_public_form_submissions_table` (`payload` TEXT cifrado; `email_digest`; `confirmation_digest` único; `envelope_id` com `nullOnDelete`).

`public_form_submissions` não é tabela de evidência: envios não confirmados são apagados. A trilha do que foi confirmado fica em `audit_events`.

**Eventos novos** (`AuditEventType`). Payload só com ULIDs, destino, contagens e motivo. Nunca e-mail, nome, respostas, token público ou token de confirmação.

| Evento                             | Rótulo                                                      | Onde        | kind |
| ---------------------------------- | ----------------------------------------------------------- | ----------- | ---- |
| `public_form.created`              | Formulário público criado                                   | organização | info |
| `public_form.updated`              | Formulário público alterado (`changed[]`)                   | organização | info |
| `public_form.activated`            | Formulário público publicado (`resumed`)                    | organização | info |
| `public_form.paused`               | Formulário público pausado                                  | organização | info |
| `public_form.revoked`              | Formulário público revogado (`discarded_unconfirmed`)       | organização | warn |
| `public_form.submission_confirmed` | Documento gerado por formulário público (e-mail confirmado) | envelope    | ok   |
| `public_form.submission_approved`  | Resposta de formulário público aprovada e enviada           | envelope    | ok   |
| `public_form.submission_rejected`  | Resposta de formulário público recusada                     | envelope    | warn |

O envelope gerado também tem os eventos normais do serviço de modelos (`envelope.created` com `template`, `template.used`) e do envio. O ator é **Sistema** na confirmação pública e o usuário na revisão.

## 10. Pendências fora da área C-FORM

1. **`HandleInertiaRequests::features()`**: acrescentar `'public_forms' => PublicFormsFeature::enabled($organização)`, o tipo `Features.public_forms?` no TS e a chave em `SharedPropsTest`.
2. **Navegação**: um item "Formulários públicos" na barra lateral, com a flag e `manage_templates`, e um atalho na tela de Modelos. Hoje as telas são alcançadas só pela URL `/formularios`.
3. **`SecurityHeaders::isPublicSensitivePath`**: incluir `formulario/*`, para `Referrer-Policy: no-referrer` e `X-Robots-Tag`. O `X-Robots-Tag` já é posto pelo controller; o `Referrer-Policy` continua `strict-origin-when-cross-origin`, que não vaza o caminho para outra origem.
4. **Agendamento da limpeza**: um comando (por exemplo, `public-forms:purge`) chamando `SubmissionPurge::run()` a cada 15 minutos em `routes/console.php`. Hoje a limpeza é oportunista (§5).
5. **Rastreio e marca do e-mail de confirmação**:
    - um propósito `public_form_confirmation` em `DeliveryPurpose` e o `TrackedMailChannel`, para ter linhas em `delivery_attempts`. Hoje o canal é o `mail` simples, na fila e cifrado;
    - `AppliesOrganizationBranding` (C-BRAND) no mesmo e-mail.
6. **`OrganizationPurge`**: incluir `public_form_submissions` e `public_forms` na ordem de exclusão, se a rotina enumerar tabelas. As FKs para `organizations` são `cascadeOnDelete`.
7. **Planos e catálogos**:
    - `public_forms` em `plans.features` (`PlanSeeder`, `DemoOrganizationSeeder`) e nos rótulos de plano;
    - os 8 eventos em `AdminEventCatalog`;
    - opcionalmente, `public_form.submission_confirmed` na linha do tempo da página de evidências (`assinavelox.evidence.timeline_events`).
8. **Revisão jurídica** do aviso de privacidade (`PrivacyNotice`) e menção ao formulário público na Política de Privacidade e nos Termos, antes de ligar a flag em produção.
9. **Decisão de produto**: avisar ou não o preenchedor quando a organização recusa o envio. Hoje ninguém é avisado.
10. **Teste de navegador** do fluxo público: o plugin de navegador tem as limitações de `docs/testes.md`; o fluxo está coberto por testes de feature.

## 11. Testes (`tests/Feature/Phase2/PublicForms`)

| Arquivo                       | Cobre                                                                                                                                                                                                                                                |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PublicFormFeatureFlagTest`   | global desligado, plano sem o recurso ou `templates` desligada → 404 em todas as rotas, internas e públicas; ligada → rotas existem, com `noindex`                                                                                                   |
| `PublicFormAntiAbuseTest`     | campo-isca; tempo mínimo (rápido, adulterado, de outro formulário, velho); limite por IP e por formulário; tamanho máximo; validação tipada; payload malicioso ignorado; teto por e-mail; página sem dado fixo                                       |
| `PublicFormConfirmationTest`  | envio automático ponta a ponta (envelope `in_progress`, preenchedor como signatário, cota só na confirmação, trilha sem PII); sem confirmação nada existe e a limpeza apaga; vencida, reutilizada e de outro formulário recusadas; limite do período |
| `PublicFormUntrustedDataTest` | Blade/script/fórmula/marcador literal no DOCX gerado e no corpo HTML; valor fixo inválido recusado                                                                                                                                                   |
| `PublicFormStateAndQuotaTest` | pausado, revogado, expirado, rascunho, modelo com versão nova e responsável sem acesso recusam; link pendente de formulário pausado; revogar apaga pendentes; cota esgotada no envio e na confirmação, com mensagem clara                            |
| `PublicFormReviewQueueTest`   | rascunho sem convite nem cota; aprovar envia e consome; recusar exclui; HTML sem campos aponta para o editor; operador 403; envio pelo editor sai da fila                                                                                            |
| `PublicFormManagementTest`    | criação com token imprevisível; validação da configuração; publicar exige zero pendências; pausar, retomar e revogar na trilha; operador 403; outra organização 404 e fora da lista                                                                  |

Testes que geram documento usam o pdftool (e pulam sem ele, como os de modelos). Nenhum teste acessa a rede.
