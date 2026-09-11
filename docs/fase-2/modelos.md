# Fase 2 — Modelos com variáveis tipadas (roadmap §2.1)

> Área B-TPL. Identificadores em inglês; texto em PT-BR. Precedência: `docs/design/RECONCILIACAO.md` → `docs/arquitetura.md` → `docs/design/ROUTES_AND_PAGES.md` → `docs/roadmap.md`.
> Estado: implementado atrás da flag `templates` (desligada por padrão). Com a flag desligada nada da Fase 1 muda.

## 1. O que o recurso entrega

O remetente cadastra um **modelo** (PDF fixo, Word/DOCX ou texto HTML) com **variáveis tipadas**, **participantes nomeados** ("Locatário", "Locador", "Testemunha 1") e — no PDF fixo — **campos pré-posicionados por participante**. Para enviar, escolhe o modelo, preenche um formulário e recebe um **envelope em rascunho** já com documento, destinatários e campos. O wizard abre no primeiro passo com pendência, para revisão e envio normais.

| Fonte  | Conteúdo                           | Variáveis no corpo | Campos no modelo        | Como vira documento                                                                                 |
| ------ | ---------------------------------- | ------------------ | ----------------------- | --------------------------------------------------------------------------------------------------- |
| `pdf`  | PDF fixo enviado                   | não                | sim, por participante   | bytes do modelo (conferidos por sha256) viram a versão original e exibível; campos gravados na hora |
| `html` | HTML escrito no editor, sanitizado | `{{chave}}`        | não (passo 3 do wizard) | motor restrito preenche → DOMPDF trancado gera o PDF → `DocumentIntake` (pipeline normal)           |
| `docx` | Arquivo do Word                    | `${chave}`         | não (passo 3 do wizard) | PHPWord preenche em uma passada → `DocumentIntake` → conversão LibreOffice                          |

**Decisão pendente do roadmap resolvida:** campos pré-posicionados só existem no PDF fixo. Em DOCX/HTML o texto preenchido muda o tamanho das páginas, então as posições do modelo não seriam confiáveis. Nesses modelos os campos são posicionados no passo 3 do documento gerado (âncoras por texto ficam para a Fase 3, §3.2).

## 2. Flag, autorização e isolamento

- **Flag `templates`** (`App\Services\Templates\TemplatesFeature`): liga só quando **as duas fontes** dizem sim, a configuração global `assinavelox.features.templates` (padrão `false`) e `plans.features.templates === true` do plano vigente. É a mesma regra de `DomainFeatures::enabled()`.
    - Desligada: `templates.index` é o placeholder da Fase 1, com as mesmas props `feature/title/subtitle`. Todas as outras rotas de modelos respondem **404**, pelo middleware do próprio controller, antes de FormRequest e Policy. `envelopes.create?template=` **ignora** o parâmetro e cria o rascunho vazio, exatamente como na Fase 1.
    - A flag só liga interface e rotas; quem pode o quê é decidido pela Policy.
- **`TemplatePolicy`** (API de permissões do B-PERM, `ResolvesMembership::allows`):

| Ação                                                                                       | Regra                                            |
| ------------------------------------------------------------------------------------------ | ------------------------------------------------ |
| ver galeria/modelo, seletor                                                                | membro ativo da organização do modelo            |
| criar, editar (nova versão), trocar arquivo, duplicar, arquivar, restaurar, baixar arquivo | `manage_templates`                               |
| usar (gerar envelope)                                                                      | `create_envelopes` **e** modelo ativo com versão |

Nos papéis de sistema, proprietário e administrador têm `manage_templates` e o operador não (vê e usa). Funções personalizadas seguem o que foi concedido.

- **Isolamento**:
    - `Template` e as tabelas filhas usam `BelongsToOrganization`, com escopo global e binding de rota escopado.
    - O formulário "Usar modelo" resolve o ULID dentro da organização corrente, então um modelo de outra organização dá 404.
    - Os arquivos ficam em `orgs/{org_ulid}/templates/{template_ulid}/{version_ulid}.{ext}` e saem junto no `OrganizationPurge`.
    - `organization_id` nunca vem do navegador.

## 3. Variáveis tipadas

Chave `[a-z][a-z0-9_]{0,63}`, única na versão. Tipos (`App\Services\Templates\VariableType`), todos validados **no servidor** (`VariableValues`) e formatados em PT-BR (`VariableFormatter`):

| Tipo           | Validação                                                                    | Opções                                                       | Inserido como                           |
| -------------- | ---------------------------------------------------------------------------- | ------------------------------------------------------------ | --------------------------------------- |
| `text`         | até 500 (ou `max_length`), sem quebras                                       | `max_length`                                                 | texto                                   |
| `long_text`    | até 5000 (ou `max_length`), mantém quebras                                   | `max_length`                                                 | texto com quebras (`<br>` / `<w:br/>`)  |
| `number`       | número (aceita `1.234,5`), casas decimais, limites                           | `min`, `max`, `decimals` (0–6, padrão 2)                     | `1.234,50`                              |
| `currency`     | reais ≥ 0, até 2 casas                                                       | `min_cents`, `max_cents` (ou `min`/`max` em reais no editor) | `R$ 1.234,56`                           |
| `date`         | data existente (dd/mm/aaaa ou aaaa-mm-dd), limites                           | `min`, `max`                                                 | `05/09/2026`                            |
| `cpf` / `cnpj` | dígitos verificadores (`App\Support\TaxId`, as mesmas regras de `app/Rules`) | —                                                            | `529.982.247-25` / `11.222.333/0001-81` |
| `email`        | e-mail válido                                                                | —                                                            | minúsculas                              |
| `phone`        | DDD + 8/9 dígitos, com ou sem +55                                            | —                                                            | `(11) 98765-4321`                       |
| `select`       | um dos valores de `choices`                                                  | `choices` (1–50)                                             | o valor escolhido                       |
| `boolean`      | sim/não                                                                      | —                                                            | `Sim` / `Não`                           |

- Obrigatória ausente bloqueia a geração (`values.{chave}` com mensagem PT-BR).
- O valor padrão também é validado ao salvar o modelo e vale quando a chave não é enviada.

## 4. Segurança do conteúdo (T6)

- **Motor restrito** (`PlaceholderEngine`): troca `{{chave}}` (HTML) ou `${chave}` (DOCX) por texto, **numa passada só**. Não há expressão, filtro, condição, laço, inclusão nem chamada.
    - `{{ 7*7 }}`, `{{ $x }}` e `{{ nome|upper }}` não são marcadores. Ao salvar, o editor os acusa como inválidos.
    - Um valor que contenha `{{outra}}` ou `${outra}` é inserido literalmente e **nunca é reprocessado**.
    - O valor sai escapado (HTML ou XML). Nenhum conteúdo de modelo ou valor passa por Blade, PHP ou `eval`.
- **HTML** (`HtmlSanitizer`): lista fechada aplicada sobre a árvore DOM.
    - Saem com o conteúdo: `script`, `style`, `iframe`, `object`, `embed`, `svg`, `math`, formulários, `link`, `meta`, `base`, `img` e mídia.
    - Tags desconhecidas, inclusive `a`, são desembrulhadas.
    - Atributos: só `style` (propriedades em lista fechada, sem `url(`/`expression`/`@import`/barra invertida/comentário, funções só rgb/rgba/hsl/hsla) e alguns numéricos de tabela.
    - Comentários saem. O HTML gravado na versão já é o sanitizado, e ele é sanitizado **de novo** ao gerar o documento.
- **DOMPDF trancado** (`HtmlPdfRenderer::lockDown`), com as opções aplicadas antes de carregar o HTML:
    - `isRemoteEnabled=false`, `isPhpEnabled=false`, `isJavascriptEnabled=false`;
    - protocolos: `data://` livre e `file://`/`http://`/`https://` com uma regra que recusa tudo. As chaves precisam existir: sem elas, `Css\Stylesheet` do DOMPDF lançava exceção em vez de recusar;
    - `chroot` num diretório vazio próprio; CSS de página nosso.
- **DOCX** (`DocxSafety` + `UploadInspector`): antes de abrir, a mesma inspeção do upload de documentos (tipo real, tamanho, zip bomb medida em streaming, caminhos inválidos). Depois, recusa:
    - macros (`vbaProject.bin`, `vbaData.xml`, tipo de conteúdo `macroEnabled`);
    - ActiveX e objetos incorporados (`word/activeX/`, `word/embeddings/`);
    - relacionamentos externos que não sejam hyperlink, como `attachedTemplate` remoto, imagem vinculada ou OLE;
    - campos `INCLUDETEXT`, `INCLUDEPICTURE`, `DDE`, `DDEAUTO`, `IMPORT` e `LINK`.

    O PHPWord só lê e troca marcadores (`RestrictedTemplateProcessor`). `setValue` do PHPWord não é usado, porque troca uma variável por vez e reexpandiria marcadores vindos de valores. Na geração o arquivo é conferido pelo sha256 gravado na versão.

- **PDF**: `pdftool inspect` recusa PDF protegido por senha, já assinado ou ilegível. As páginas (`pages_meta`) viram a base da geometria.

## 5. Versões e rastreabilidade

- `template_versions` é **imutável**: o model lança `LogicException` em qualquer `update`, e as tabelas filhas também.
- Editar o conteúdo (variáveis, participantes, campos, HTML, ordem de assinatura) ou trocar o arquivo grava uma **versão nova** e move `templates.current_version_id`. Salvar sem mudança de conteúdo **não** cria versão; a comparação usa `definition_hash`, o sha256 da definição canônica mais o sha256 do arquivo. Nome, descrição e categoria são do cadastro e não geram versão.
- O envelope gerado é uma **cópia** (documento, destinatários e campos próprios). A versão usada fica em `template_usages` (uma linha por envelope) e no evento `template.used`. Alterar ou arquivar o modelo depois não muda nada no envelope, e há teste para isso.
- Os valores preenchidos **não** são guardados fora do documento: nem em `template_usages`, nem na trilha.

## 6. Gerar envelope ("Usar modelo")

`GET envelopes.create?template={ulid}` (flag ligada) → página `templates/use`. `POST templates.use` → `CreateEnvelopeFromTemplate`:

1. revalida tudo antes de criar qualquer coisa: modelo ativo, versão atual, flag `participant_roles` se houver papel diferente de signatário, título, valores por tipo, participante (nome 2–120, e-mail RFC, sem repetição) por papel;
2. em uma transação: cria o envelope com os mesmos padrões de "Nova solicitação" (`EnvelopeCreated` com `template` e `template_version`), gera o documento conforme a fonte (§1), sincroniza os destinatários com **`RecipientSync`** (o nome do papel vira `role_label`, `participant_role` vira `recipients.role`) e, no PDF fixo, grava os campos com **`FieldSync`**, com a mesma geometria, validação contra `pages_meta` e rubrica automática da organização;
3. grava `template_usages` e `template.used`, recalcula a prontidão e redireciona para `envelopes.edit?step=4`. O wizard rebaixa para o primeiro passo com pendência.
4. Se algo falha, a transação desfaz tudo e os bytes gravados na pasta do envelope são apagados.

**DOCX sem LibreOffice:** esta máquina não tem LibreOffice. O caminho real foi verificado só com o conversor falso dos testes (`tests/Fixtures/fake-soffice.*`). Sem conversor configurado, o documento gerado fica `failed` com a mensagem honesta do pipeline ("A conversão de arquivos DOCX não está disponível nesta instalação…"). Galeria, criação e formulário de uso avisam antes, com `conversion.available = false`. Nada é simulado.

## 7. Rotas e props

Todas no grupo `app` (`auth`, `verified`, `org`, `org.2fa`), prefixo `/modelos`, `scopeBindings`:

| Nome                                      | Método e URL                                        | Controller                                                 | Observação                                                               |
| ----------------------------------------- | --------------------------------------------------- | ---------------------------------------------------------- | ------------------------------------------------------------------------ |
| `templates.index`                         | GET `/modelos`                                      | `TemplateController@index`                                 | placeholder com a flag desligada                                         |
| `templates.picker`                        | GET `/modelos/seletor?q=`                           | `Templates\TemplatePickerController@index`                 | JSON; throttle 60/min                                                    |
| `templates.store`                         | POST `/modelos`                                     | `TemplateController@store`                                 | throttle 30/min                                                          |
| `templates.edit`                          | GET `/modelos/{template}/editar`                    | `TemplateController@edit`                                  | `manage_templates`                                                       |
| `templates.update`                        | PUT `/modelos/{template}`                           | `TemplateController@update`                                | nova versão se o conteúdo mudou                                          |
| `templates.duplicate`                     | POST `/modelos/{template}/duplicar`                 | `TemplateController@duplicate`                             |                                                                          |
| `templates.archive` / `templates.restore` | POST `/modelos/{template}/arquivar` e `/restaurar`  | `TemplateController`                                       |                                                                          |
| `templates.source.show`                   | GET `/modelos/{template}/arquivo?version=`          | `Templates\TemplateSourceController@show`                  | download do DOCX/PDF                                                     |
| `templates.source.update`                 | POST `/modelos/{template}/arquivo`                  | `Templates\TemplateSourceController@update`                | troca o arquivo (nova versão)                                            |
| `templates.preview`                       | GET `/modelos/{template}/pre-visualizacao?version=` | `Templates\TemplateSourceController@preview`               | PDF inline: o próprio PDF ou HTML com valores de **exemplo**; DOCX → 404 |
| `templates.use`                           | POST `/modelos/{template}/usar`                     | `Templates\TemplateUseController@store`                    | throttle 20/min                                                          |
| `envelopes.create`                        | GET `/documentos/nova?template={ulid}`              | `EnvelopeController@create` → `TemplateUseController@form` | só o método `create` foi alterado                                        |

Props (em `App\Services\Templates\TemplatePresenter`; tipos TS em `resources/js/components/templates/types.ts`):

- **`templates/index`**:
    - sempre: `feature`, `title`, `subtitle`;
    - com a flag ligada: `enabled: true`, `templates[]` (`id, name, description, category, source_type, source_label, status, version, roles_count, variables_count, fields_count, page_count, uses, last_used_at, updated_at, usable`), `categories[]`, `filters.status`, `counts{active,archived}`, `source_types[]`, `limits.max_upload_bytes`, `conversion`, `can{create,use}`.
- **`templates/edit`**:
    - `template` (resumo + `supports_variables/supports_fields/requires_file`);
    - `version` (`id, number, created_at, original_filename, size_bytes, page_count, pages[{page,width_pt,height_pt,rotation,box}], placeholders[]`);
    - `definition` (`html_body, signing_order, variables[], roles[{ref,name,participant_role}], fields[{id,role_ref,type,page,x,y,w,h,required,label,options}]`), no mesmo formato aceito por `PUT templates.update`;
    - `versions[]`;
    - `options` (`variable_types`, `participant_roles[{value,label,enabled}]`, `signing_orders`, `date_formats`), `limits`, `conversion`, `can{update,use}`.
- **`templates/use`**: `template` (resumo + `version, version_id, fields_count, page_count`), `variables[]`, `roles[{id,name,participant_role,participant_role_label}]`, `defaults.title`, `conversion{required,available,message}`.
    - POST: `{title?, values{chave: string}, participants{role_ulid: {name,email}}}`.
    - Erros: `values.{chave}`, `participants.{ulid}.name|email`, `title`, `template`.
- **Seletor (JSON)**: `{templates: [{id,name,category,source_type,source_label,roles_count,variables_count}], can_use}`. Traz até 8 modelos ativos da organização corrente.

### Contrato do `TemplatePicker`

`resources/js/components/templates/template-picker.tsx`:

```ts
export function TemplatePicker(props: {
    onPicked?: (templateUlid: string) => void;
}): JSX.Element;
```

- Mostra uma lista curta com busca, alimentada por `templates.picker`.
- Ao escolher, chama `onPicked` (opcional) e navega para `envelopes.create?template={ulid}`.
- Com a flag desligada a rota dá 404 e o componente mostra só um aviso. Quem o usa (card "Ou comece por um modelo" do wizard) deve renderizá-lo apenas com `features.templates` ligado.

## 8. Trilha de auditoria (novos `AuditEventType`)

Payload minimizado: ULIDs e contagens, nunca o conteúdo do modelo nem os valores preenchidos.

| Evento                                    | Rótulo                              | Onde                | Payload                                                                                      |
| ----------------------------------------- | ----------------------------------- | ------------------- | -------------------------------------------------------------------------------------------- |
| `template.created`                        | Modelo criado                       | organização         | `template, source_type, version` (+ `duplicated_from`)                                       |
| `template.version_created`                | Nova versão do modelo               | organização         | `template, version, previous_version` (+ `source_replaced, fields_dropped, variables_added`) |
| `template.updated`                        | Dados do modelo alterados           | organização         | `template, changed[]` (nome/descrição/categoria)                                             |
| `template.duplicated`                     | Modelo duplicado                    | organização         | `template, copy`                                                                             |
| `template.archived` / `template.restored` | Modelo arquivado / restaurado       | organização         | `template`                                                                                   |
| `template.used`                           | Documento gerado a partir de modelo | **envelope gerado** | `template, version, template_version, variables, participants` (contagens)                   |

No envelope gerado, `envelope.created` ganha `template` e `template_version`. No PDF fixo, `document.uploaded` ganha `template_version`. Todos os eventos novos têm `kind = info`.

## 9. Banco (migrations aditivas, MySQL 8)

- `2026_09_11_110301`: `templates`, `template_versions` e a FK `templates.current_version_id`.
- `2026_09_11_110302`: `template_variables`, `template_roles`, `template_fields` (mesma geometria de `signing_fields`).
- `2026_09_11_110303`: `template_usages` (`envelope_id` único).

As migrations não usam ENUM de SQL nem DEFAULT em JSON/TEXT, e os nomes de índice são explícitos e têm menos de 64 caracteres.

## 10. Pendências fora da área B-TPL

1. **`HandleInertiaRequests::features()`**: a chave `templates` deve vir de `TemplatesFeature::enabled($organização corrente)`. Hoje é `false` fixo, então sidebar e wizard não sabem que a flag ligou. `SharedPropsTest` fixa a lista atual.
2. **`config/assinavelox.php`**: declarar `features.templates` (padrão `false`). Hoje o código usa o padrão `false` de `config()`.
3. **Wizard (card "Ou comece por um modelo")**: importar `TemplatePicker` de `@/components/templates/template-picker` com `features.templates`.
4. **`tests/Unit/Models/EnumCatalogTest`**: a contagem de `AuditEventType` precisa incluir os 7 eventos de modelos.
5. **`tests/Feature/Smoke/AllGetRoutesTest`** (Fase 1, fora da área): o teste monta a URL de TODA rota GET e não tem fallback para parâmetros desconhecidos. As rotas GET novas precisam de duas entradas:
    - em `smokeRouteParameters`: `'templates.edit', 'templates.preview', 'templates.source.show' => ['template' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']`;
    - em `SMOKE_OVERRIDES`, com a flag desligada (404): `'templates.edit'`, `'templates.preview'`, `'templates.source.show'` e `'templates.picker'` com `['owner' => 404, 'admin' => 404, 'member' => 404]`.

    Sem isso, o teste falha nos 5 papéis.

6. **Teste de navegador** do wizard a partir de modelo (roadmap §2.0): ainda não existe. A suíte `Browser` é de outra área e está instável nesta máquina.
7. **Rótulo do evento `template.used` na linha do tempo das evidências**, se o produto quiser mostrá-lo (`assinavelox.evidence.timeline_events`).

## 11. Limitações conhecidas

- A conversão de DOCX depende do LibreOffice, ausente nesta máquina; está verificada só com o binário falso.
- DOCX e HTML não têm campos pré-posicionados (§1); a pré-visualização de DOCX não existe no servidor.
- Marcadores DOCX quebrados pela formatação do Word (parte do marcador em negrito, por exemplo) são recusados com orientação; o PHPWord só junta os casos simples.
- Os valores de exemplo da pré-visualização HTML são o valor padrão ou `[Rótulo]`; nenhum dado real é usado.
