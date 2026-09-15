# Fase 3 — Geração documental em lote (roadmap §3.1)

> Área F-BULK (Fase 3, parte 2, onda F). Identificadores em inglês; texto em PT-BR.
> Precedência: `docs/design/RECONCILIACAO.md` → `docs/arquitetura.md` → `docs/roadmap.md` → `docs/fases-2-3-viabilidade.md`.
> Estado: implementado atrás da flag `bulk_generation` (desligada por padrão). Com a flag desligada nada muda.

## 1. O que o recurso entrega

O remetente escolhe um **modelo publicado** (§2.1), envia uma **planilha CSV ou XLSX** com um documento por linha e recebe **um envelope por linha válida**, cada um com os participantes e os valores da própria linha. O caminho tem cinco etapas:

1. **Arquivo**: "Gerar em lote" no cartão do modelo. A tela explica as colunas e oferece a planilha-modelo (CSV).
2. **Colunas**: o servidor lê o cabeçalho e sugere o mapeamento pelos nomes. O remetente confere.
3. **Pré-validação** (obrigatória): relatório por linha (ok, ou erros por coluna), sem criar envelope e sem tocar na cota.
4. **Confirmação**: o remetente escolhe o destino (revisar, enviar ou agendar). A cota das linhas **válidas** é reservada; se não couber, nada é criado.
5. **Geração**: um job por linha, com limite de concorrência por organização. O progresso aparece na tela, que se atualiza sozinha. O cancelamento e o relatório CSV ficam disponíveis a qualquer momento.

A versão do modelo é **fixada no envio da planilha**. Editar o modelo depois não muda o lote. Arquivar o modelo faz as linhas pendentes falharem com motivo claro.

## 2. Flag, autorização e isolamento

- **Flag `bulk_generation`** (`App\Services\BulkGeneration\BulkGenerationFeature`): liga quando `assinavelox.features.bulk_generation` (env `ASSINAVELOX_FEATURE_BULK_GENERATION`, padrão `false`) **e** `plans.features.bulk_generation === true` dizem sim. O lote gera a partir de modelos, então **`templates` também precisa estar ligada**.
    - Desligada: todas as rotas respondem **404** (middleware do controller, antes de FormRequest e autorização), o botão "Gerar em lote" e o atalho "Lotes" não aparecem e a prop `features.bulk_generation` é `false`.
    - Desligada **no meio** de um lote: as linhas ainda não geradas falham com "A geração em lote não está disponível no plano atual da organização." e a cota delas volta.
- **Permissões** (`BulkGenerationAccess`):

| Ação                                      | Regra                                                                                        |
| ----------------------------------------- | -------------------------------------------------------------------------------------------- |
| gerar a partir de um modelo (tela, envio) | a mesma de "Usar modelo": `TemplatePolicy::use` (`create_envelopes` + modelo ativo)          |
| listar lotes                              | `create_envelopes` (vê os próprios) ou `view_all_envelopes`/`manage_any_envelope` (vê todos) |
| ver um lote e o relatório                 | quem criou, ou `view_all_envelopes`/`manage_any_envelope`                                    |
| mapear, validar, confirmar, descartar     | quem criou (ou `manage_any_envelope`), com `create_envelopes`                                |
| confirmar com envio ou agendamento        | também `send_envelopes`                                                                      |
| cancelar                                  | quem criou, ou `cancel_any_envelope`/`manage_any_envelope`                                   |

- **Isolamento**: `BulkGeneration` e `BulkGenerationRow` usam `BelongsToOrganization`, com binding escopado. Um lote de outra organização responde **404**. A planilha fica em `orgs/{org}/bulk/{lote}/planilha.{ext}`, no disco privado `documents`, e sai no `OrganizationPurge` com o diretório da organização.

## 3. A planilha é dado não confiável (T6)

`App\Services\BulkGeneration\Spreadsheet\SpreadsheetReader`:

- **Formato pelo conteúdo**, conferido com a extensão:
    - `.xlsx` precisa ser um pacote ZIP OOXML de planilha (`[Content_Types].xml` e `xl/workbook.xml`);
    - `.csv`/`.txt` precisa ser texto sem bytes nulos.
    - São recusados com motivo claro: `.xls` antigo e XLSX protegido por senha (contêiner OLE), XLSM com `vbaProject.bin`, DOCX, PDF ou imagem renomeados, ZIP enviado como CSV e CSV em UTF-16.
- **CSV** com funções nativas (`fgetcsv`, escape RFC 4180):
    - BOM UTF-8 removido;
    - arquivo que não é UTF-8 é lido como Windows-1252 (o "CSV" do Excel em português);
    - separador `;` ou `,` detectado no cabeçalho, fora das aspas.
- **XLSX** por streaming (OpenSpout 5.3, `LIBXML_NONET`):
    - vale a **primeira aba visível**; abas ocultas são ignoradas;
    - antes de abrir, o pacote é medido descompactando em streaming (teto `max_uncompressed_bytes` e razão 200:1, contra zip bomb).
- **Fórmulas nunca são avaliadas**:
    - célula de fórmula no XLSX é **recusada**; nem a fórmula nem o valor calculado em cache são usados;
    - texto que começa com `=` ou `@` é recusado;
    - texto que começa com `+` ou `-` só passa se o resto for numérico (telefone `+55 11…`, número `-12,5`). `+cmd|' /C calc'!A0` e `-2+3+cmd|…` são recusados.
    - A célula recusada vira erro **da coluna** se ela estiver mapeada. Coluna não mapeada é ignorada.
- **Tipos do XLSX**:
    - número vira texto com ponto decimal;
    - data vira `aaaa-mm-dd`;
    - booleano vira `true`/`false`;
    - erro de planilha (`#DIV/0!`) e duração são recusados.
- **Limites** (globais, e o plano pode apertar): linhas de dados, tamanho do arquivo, colunas, caracteres por célula e 10.000 linhas vazias toleradas.
- **E-mails** validados pelo mesmo validador de `email:rfc` do resto do sistema. Nomes: 2–120 caracteres. Variáveis: `VariableValues::normalize` (a validação por tipo de "Usar modelo").
    - "sim"/"não" são aceitos para sim ou não.
    - CPF/CNPJ que perderam 1–2 zeros à esquerda numa célula numérica são completados; os dígitos verificadores decidem.
- **Minimização**:
    - `bulk_generation_rows.payload` guarda só as colunas mapeadas da linha válida, **cifrado** (`encrypted:array`), e é apagado quando o envelope nasce ou a linha é cancelada;
    - linhas inválidas guardam só `errors` (coluna, cabeçalho e mensagem), **nunca o valor digitado**;
    - a planilha sai do disco quando o lote termina, é cancelado ou descartado. O `source_sha256` fica.
- **CSV de saída** (relatório e planilha-modelo) sempre por `App\Support\Csv` (neutraliza `= + - @`), com BOM, `;` e `Cache-Control: no-store`.

## 4. Mapeamento de colunas

Destinos (`ColumnMapping`):

- `title`: título do documento (opcional; padrão "Modelo — nome do primeiro participante");
- `role:{ulid do papel}:name|email|phone`: participante de cada papel. `phone` só existe com o canal SMS/WhatsApp (`sms_whatsapp`) ligado para a organização;
- `var:{chave}`: variável do modelo.

A sugestão compara o cabeçalho, sem acento, caixa ou pontuação, com o rótulo e a chave de cada destino. Exemplos reconhecidos: "Locatário — e-mail", "E-mail do Locatário", "cpf_do_locatario" e, em modelo com um só papel, "Nome" e "E-mail". A planilha-modelo usa exatamente os nomes reconhecidos.

Validação do mapeamento:

- todo papel precisa de nome e e-mail;
- toda variável obrigatória sem valor padrão precisa de coluna;
- um destino não pode estar em duas colunas.

Em linha, uma variável opcional em branco usa o valor padrão do modelo.

## 5. Geração, idempotência e concorrência

- **Orquestrador** `StartBulkGenerationJob`: despachado na confirmação, chama o `BulkGenerationPump`.
- **Limite de concorrência por organização** (`concurrency_per_organization`, padrão 3): nunca há mais linhas da organização em `queued|processing` do que o limite, somando todos os lotes. A vaga é a própria linha no banco, reivindicada por UPDATE condicional sob o lock da organização. Cada linha, ao terminar, chama o pump de novo. Não depende de Redis.
- **`GenerateEnvelopeFromRowJob`** (único por linha; payload na fila = só o id da linha, T10). O `RowGenerator` trabalha em duas fases:
    1. **Criação**: em transação com a linha bloqueada, o envelope nasce pelo **mesmo** `CreateEnvelopeFromTemplate` de "Usar modelo", com a versão fixada. A linha recebe `envelope_id` (UNIQUE) **na mesma transação**, e reexecutar não duplica.
    2. **Destino**: fora da transação, a unidade de cota do lote é devolvida e, conforme a confirmação:
        - `review`: fica pronto (ou rascunho com pendência);
        - `send`: `SendEnvelope`;
        - `schedule`: `ScheduledSend::schedule`.
    - Uma linha `created` sem `outcome` é "criada, destino pendente": a reexecução só refaz esse passo, e os dois serviços também são idempotentes.
- **Envelope pronto ou rascunho**: nasce pronto quando o modelo é **PDF com campo de assinatura obrigatório para cada papel que assina**. Modelos HTML/DOCX não têm campos posicionados: os envelopes ficam em rascunho para posicionar os campos no editor. Pedir envio desses documentos resulta em "Gerado, não enviado" com a pendência, e a tela avisa antes (`direct_send`).
- **Falhas**: toda falha fica na linha com mensagem PT-BR e devolve a cota. Nenhuma exceção sobe (na fila `sync` ela derrubaria a requisição). Exemplos: modelo arquivado depois da confirmação, quem confirmou saiu da organização, valor recusado na revalidação. Job perdido (tempo esgotado) → `failed()` marca a linha.
- **Encerramento**: sem linhas pendentes e sem destino pendente, o lote vai para `completed` e a planilha é apagada.

## 6. Cota do plano

Tudo acontece sobre o **mesmo ledger** do envio (`plan_consumptions` + `subscriptions.envelopes_reserved`):

- **Confirmar** reserva **uma unidade por linha válida**, com a chave `bulk:{lote}:row:{linha}` (UNIQUE: reconfirmar não reserva de novo). As linhas inválidas não reservam nada.
- **Cota insuficiente**: nada é reservado nem criado, e a mensagem diz quantos cabem: "A cota do plano comporta mais 2 documentos neste ciclo, e o lote tem 3 linhas válidas. Nada foi criado. Remova linhas da planilha ou amplie o plano."
- Cada unidade volta **exatamente uma vez** (`PlanLedger::release` é idempotente):
    - quando a linha falha ou é cancelada;
    - quando o envelope nasce para revisão (o envio posterior consome pela regra de sempre);
    - **antes do agendamento**: o envio agendado pode cair semanas depois, em outro ciclo, e consome pela regra de sempre na hora do disparo;
    - no modo **"enviar ao gerar"**, a unidade **não volta antes do envio**: `BulkGenerationQuota::handOver` a entrega ao envelope (`plan_consumptions.envelope_id`) e o `SendEnvelope`, na mesma transação e com a assinatura travada, troca-a pela reserva `envelope:{id}:send` sem nova checagem de cota (`assertCanSend(…, 0)`: a situação da assinatura continua valendo) e devolve a do lote (`bulk_row_handed_over`). Se o envio não acontece por qualquer motivo, a unidade do lote volta logo em seguida (`bulk_row_not_sent`).
- **Sem janela** (revisão adversarial da onda F): antes, a unidade voltava numa transação e o envio reservava a sua em outra; um envio concorrente da organização podia tomá-la e a linha confirmada terminava "não enviada" por cota.
- A renovação do ciclo reconta `envelopes_reserved` a partir das reservas abertas, então as unidades de um lote em andamento continuam contadas.

## 7. Cancelar

`POST lotes/{lote}/cancelar` (só lote `running`):

- as linhas `pending|queued` sem envelope viram `canceled`, com o payload apagado;
- a cota delas é devolvida e a planilha sai do disco;
- envelopes já criados, enviados ou agendados **não são tocados**;
- um job já na fila encontra a linha cancelada e não faz nada;
- uma linha que já estava em `processing` também não gera: o job vê o lote cancelado;
- um envelope criado cujo destino ainda não rodou fica para revisão, sem envio ("Lote cancelado antes do envio; o documento ficou para revisão.").

## 8. Rotas e props

Todas no grupo `app` (`auth`, `verified`, `org`, `org.2fa`), controller `BulkGenerations\BulkGenerationController`:

| Nome                       | Método e URL                                               | Observação                                                                        |
| -------------------------- | ---------------------------------------------------------- | --------------------------------------------------------------------------------- |
| `bulk_generations.index`   | GET `/lotes`                                               | lista paginada (20)                                                               |
| `bulk_generations.create`  | GET `/modelos/{template}/lotes/novo`                       | página `bulk-generations/create`                                                  |
| `bulk_generations.sample`  | GET `/modelos/{template}/lotes/planilha-modelo`            | CSV com o cabeçalho reconhecido                                                   |
| `bulk_generations.store`   | POST `/modelos/{template}/lotes`                           | `file` (csv/xlsx/txt); throttle 10/min                                            |
| `bulk_generations.show`    | GET `/lotes/{bulkGeneration}?linhas=problems\|all&pagina=` | página `bulk-generations/show`                                                    |
| `bulk_generations.mapping` | PUT `/lotes/{bulkGeneration}/mapeamento`                   | `mapping{coluna: destino}` → pré-validação                                        |
| `bulk_generations.confirm` | POST `/lotes/{bulkGeneration}/confirmar`                   | `mode` review\|send\|schedule, `scheduled_for` `Y-m-d\TH:i` (fuso da organização) |
| `bulk_generations.cancel`  | POST `/lotes/{bulkGeneration}/cancelar`                    |                                                                                   |
| `bulk_generations.destroy` | DELETE `/lotes/{bulkGeneration}`                           | só lote não confirmado                                                            |
| `bulk_generations.report`  | GET `/lotes/{bulkGeneration}/relatorio`                    | CSV: Linha; Situação; Coluna; Mensagem; Documento                                 |

Erros de formulário: `file`, `template`, `mapping`, `batch`, `mode`, `scheduled_for`.

Props (`BulkGenerationPresenter`; tipos TS em `resources/js/components/bulk-generations/types.ts`):

- **`bulk-generations/index`**: `batches[]` (`BulkSummary`) e `pagination`.
- **`bulk-generations/create`**: `template`, `columns[{label,group,required}]`, `direct_send{available,reason}`, `conversion`, `limits`.
- **`bulk-generations/show`**: `batch` (`BulkDetail`), `template`, `headers[{column,label,letter}]`, `mapping{coluna: destino}`, `targets[]`, `counts`, `rows{filter,current_page,last_page,total,data[]}`, `quota{remaining,needed,fits}`, `options{can_send,can_schedule,direct_send,schedule}` e `can{manage,cancel}`. A página faz polling (`usePoll`, 2,5 s) enquanto o lote está `running`.
- Prop compartilhada: `features.bulk_generation`.

Interface:

- `BulkGenerateButton` fica no cartão do modelo da galeria, para quem pode usar modelos;
- `BulkGenerationsLink` fica no cabeçalho de Modelos. Os dois não renderizam nada com a flag desligada.

## 9. Trilha de auditoria

**Nenhum `AuditEventType` novo.** O teste `tests/Unit/Models/EnumCatalogTest` fixa a lista exata de eventos, e a regra desta onda proíbe mudar asserção de teste existente. A rastreabilidade fica assim:

- em cada envelope gerado, `envelope.created` e `template.used` levam `bulk_generation` (ULID do lote) e `bulk_row` (linha da planilha), com o **usuário que confirmou** o lote como ator;
- os fatos do lote ficam nas colunas do próprio registro: `created_by_user_id`, `dry_run_at`, `confirmed_at`/`confirmed_by_user_id`, `canceled_at`/`canceled_by_user_id`, `finished_at`, `source_sha256`.

Proposta para quem fizer a integração: eventos de organização `bulk_generation.confirmed`, `bulk_generation.canceled` e `bulk_generation.completed`, acompanhados da atualização do `EnumCatalogTest`.

## 10. Banco (migrations aditivas, MySQL 8)

- `2026_09_14_160001`: `bulk_generations` (FKs para organização, modelo e versão; `status` string; contadores; sha256).
- `2026_09_14_160002`: `bulk_generation_rows` (`envelope_id` UNIQUE, `(bulk_generation_id, row_index)` UNIQUE, `payload` longText cifrado, `errors` JSON).

As migrations não usam ENUM de SQL nem DEFAULT em JSON/TEXT, e os índices têm nomes explícitos.

## 11. Ganchos fora da área (registrados)

- `App\Services\Templates\CreateEnvelopeFromTemplate::handle()` ganhou dois parâmetros **opcionais**:
    - `?TemplateVersion $pinnedVersion`: versão fixada, conferida contra o modelo;
    - `array $auditContext`: somado ao payload de `envelope.created`/`template.used`.
    - `participants` também aceita `phone` (repassado ao `RecipientSync`, que valida o E.164).
    - Sem os novos argumentos, o comportamento é idêntico.
- `config/assinavelox.php`: `features.bulk_generation` e a seção `bulk_generation`.
- `HandleInertiaRequests::features()`: chave `bulk_generation`.
- `routes/web.php`: as rotas acima.
- `resources/js/types/index.ts`: `Features.bulk_generation?`.
- `resources/js/pages/templates/index.tsx`: botão no cartão e atalho no cabeçalho, uma linha cada.
- Testes compartilhados: `SharedPropsTest` (nova chave `false`) e `AllGetRoutesTest` (5 rotas GET com 404 e a flag desligada).

## 12. Configuração

Seção `assinavelox.bulk_generation`:

| Chave                          | Env                                           | Padrão  |
| ------------------------------ | --------------------------------------------- | ------- |
| `max_rows`                     | `ASSINAVELOX_BULK_MAX_ROWS`                   | 1000    |
| `max_file_bytes`               | `ASSINAVELOX_BULK_MAX_FILE_BYTES`             | 5 MB    |
| `max_concurrent_batches`       | `ASSINAVELOX_BULK_MAX_CONCURRENT_BATCHES`     | 2       |
| `concurrency_per_organization` | `ASSINAVELOX_BULK_CONCURRENCY`                | 3       |
| `max_columns`                  | `ASSINAVELOX_BULK_MAX_COLUMNS`                | 100     |
| `max_cell_chars`               | `ASSINAVELOX_BULK_MAX_CELL_CHARS`             | 5000    |
| `max_uncompressed_bytes`       | `ASSINAVELOX_BULK_MAX_UNCOMPRESSED_BYTES`     | 50 MB   |
| `queue`                        | `ASSINAVELOX_BULK_QUEUE`                      | default |
| `unconfirmed_retention_days`   | `ASSINAVELOX_BULK_UNCONFIRMED_RETENTION_DAYS` | 7       |

Por plano: `plans.features.bulk_generation_limits = {max_rows, max_file_bytes, max_concurrent_batches, concurrency}`. Vale o **menor** entre o plano e o global.

## 13. O que falta para ligar em produção

1. **Decisão de produto**: limites de linhas, lotes simultâneos e concorrência por plano, preenchendo `plans.features.bulk_generation_limits` e `bulk_generation`.
2. **Worker de fila** ouvindo a fila configurada em `bulk_generation.queue` (padrão `default`). Na fila `sync`, a confirmação gera tudo dentro da requisição, o que só serve para testes.
3. **LibreOffice** em produção para modelos DOCX (a conversão em massa satura o conversor: considerar concorrência 1–2 para organizações com modelo DOCX). Nesta máquina a conversão só foi verificada com o conversor falso.
4. **Retenção de lotes não confirmados** (feito na revisão adversarial): `bulk-generations:prune-unconfirmed`, diário às 04:50, descarta — como o botão "Descartar" (arquivo, linhas com `payload` cifrado e registro) — lote em rascunho ou validado sem alteração há mais de `unconfirmed_retention_days` (padrão **7**), relendo o lote sob lock antes; registro no log da aplicação (ULID, organização, prazo). A tela do lote avisa o prazo (`batch.discard_after_days`). Falta o proprietário **confirmar o prazo** (LGPD art. 15/16).
5. **Eventos de auditoria do lote** (§9), se o produto quiser vê-los na trilha da organização.
6. **Recuperação de worker derrubado**: o job tem 2 tentativas e `failed()` marca a linha. Uma linha presa em `processing` sem worker (processo morto sem retry) fica pendente até um comando de varredura, que ainda não existe.
7. Teste de navegador do fluxo (upload → validar → confirmar) ainda não existe.

## 14. Testes

`tests/Feature/Phase3/Bulk/` (Pest):

- `FlagOffTest`: flag desligada = 404 em todas as rotas, com e sem plano, sem `templates`, e em lote existente; prop compartilhada.
- `SpreadsheetSafetyTest`: XLSX **gerado no teste** com fórmulas `=1+1`, `=HYPERLINK(...)` e `=cmd|...` recusadas (nem cache); CSV com `= + - @` e TAB; abas ocultas; limites; XLSX disfarçado (texto, PDF, OLE, ZIP quebrado, DOCX, macro, ZIP como CSV); zip bomb; BOM/Windows-1252/vírgula/UTF-16; ponta a ponta sem vazar valores.
- `DryRunTest`: sugestão de mapeamento, relatório por linha sem envelope nem cota, payload cifrado e mínimo, mapeamento inválido, revalidação, planilha-modelo, recusa no envio, descarte e props.
- `QuotaTest`: reserva só das válidas, cota insuficiente com quantos cabem, reservas alheias contam, reconfirmação, lote sem validação, lotes simultâneos e limites do plano.
- `GenerationTest`: **200 linhas → 200 envelopes prontos**, versão fixada, envio, agendamento, idempotência, queda antes e depois da criação, concorrência, modelo arquivado, flag desligada no meio e modelo HTML.
- `CancelTest`: cancelamento no meio com fila controlada, linha em processamento, destino pendente e estados inválidos.
- `AccessTest`: isolamento entre organizações, operador × dono × administrador, modelo arquivado e relatório protegido.
