# Retenção configurável e preservação (Fase 2 §2.19, onda C — K-RET)

> Precedência: `docs/design/RECONCILIACAO.md` → `docs/arquitetura.md` → `docs/roadmap.md` §2.19 → `docs/fases-2-3-viabilidade.md` (§1.1 linha §2.19, §4.5 item 30). Identificadores em inglês; texto em PT-BR.
> Estado: implementado atrás da flag **`retention_policies`**, que nasce **desligada**. Com ela desligada nada muda (teste `tests/Feature/Phase2/Retention/FlagOffTest.php`).

## 1. O que existe

| Entrega                                              | Onde                                                                                         |
| ---------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| Política de retenção por organização (6 categorias)  | `retention_policies`, `App\Services\Retention\RetentionPolicies`                             |
| Mínimos legais da operadora                          | `RetentionConfig::minimumDays()` (config `assinavelox.retention.minimum_days.*`)             |
| Job agendado, em lotes, idempotente e retomável      | `retention:apply` (diário, 04:25) → `RetentionRunner` → `EnvelopePurger` / `CategorySweeper` |
| Recibo de exclusão sem dado pessoal                  | `retention_deletions` (o `deletion_receipts` do roadmap)                                     |
| Bloqueio de exclusão por preservação ("legal hold")  | `legal_holds`, `App\Services\Retention\LegalHolds`                                           |
| Trilha da retenção e da preservação (append-only)    | `retention_events`, `RetentionTrail`                                                         |
| Verificação pública depois da exclusão (decisão §6)  | `App\Services\Verification\RetentionTombstones` + `PurgedEnvelope`                           |
| Propagação à exclusão da organização                 | `OrganizationPurge` (bloqueio + tabelas novas + derivados)                                   |
| Tela Configurações › Retenção e preservação          | `resources/js/pages/settings/retention.tsx`, `resources/js/components/retention/**`          |
| Contrato do detalhe do documento (selo "Preservado") | `GET envelopes.legal_hold.show` + `EnvelopeLegalHoldPanel` (§10)                             |
| Marcas de uso único do formulário público no banco   | `public_form_timer_marks`, `FillTimer`, `public-forms:prune-timer-marks` (§11)               |

Migrations (todas aditivas, MySQL 8): `2026_09_11_130201` `retention_policies`, `130202` `legal_holds`, `130203` `retention_deletions`, `130204` `retention_events`, `130205` `retention_runs`, `130206` `public_form_timer_marks`.

## 2. Flag e autorização

- **Flag** `retention_policies` (`RetentionFeature`): interruptor global `assinavelox.features.retention_policies` **e** `plans.features.retention_policies` do plano — a mesma regra de `DomainFeatures`.
    - Desligada: a tela mostra o estado "Fase 2"; gravar a política e criar preservações respondem **404**; o job não seleciona nenhuma organização.
    - **Uma preservação já criada continua valendo mesmo se a flag for desligada depois.** Um bloqueio legal não pode evaporar por um interruptor de interface. Consultar e liberar continuam possíveis sem a flag, para que nenhum bloqueio fique impossível de liberar.
- **Configurar a política**: `manage_settings` (Policy `updateSettings`), como Marca e Padrões de assinatura.
- **Preservar e liberar** (`manage_legal_holds`): capacidade própria, resolvida em `RetentionAuthorization::canManageHolds()`. Hoje vale para o **proprietário** e para quem tem **ao mesmo tempo** `manage_settings` **e** `view_all_envelopes` (Administrador por padrão; função personalizada só se receber as duas). Operador não pode. Ver o contrato do catálogo em §9.

## 3. Categorias

Prazo em dias; **vazio = não apagar automaticamente** naquela categoria. O que é apagado e o que fica são listas fechadas em `RetentionCategory::deletes()/preserves()` — a mesma redação que a tela mostra.

| Categoria (`key`)                                   | Conta a partir de                           | Mínimo de partida | Apaga                                                                                                             | Preserva                                                                                  |
| --------------------------------------------------- | ------------------------------------------- | ----------------- | ----------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| Documentos concluídos (`completed`)                 | `completed_at`                              | 1825 dias         | Envelope inteiro: todas as versões e derivados no disco, imagens, fotos, participantes, aceites, registro público | Recibo sem dado pessoal; aviso público conforme §6; trilha pelo prazo da categoria trilha |
| Recusados, expirados, cancelados (`terminal_other`) | `refused_at` / `expired_at` / `canceled_at` | 180 dias          | Idem                                                                                                              | Idem                                                                                      |
| Rascunhos abandonados ou excluídos (`draft`)        | `deleted_at` (se excluído) ou `updated_at`  | 30 dias           | O rascunho inteiro (inclusive os já excluídos manualmente, que até aqui ficavam no disco)                         | Recibo                                                                                    |
| Fotos da captura de identidade (`identity_capture`) | `captured_at`                               | 7 dias            | O arquivo de cada foto                                                                                            | A linha, com `purged_at` e o resumo SHA-256 (a evidência do aceite continua coerente)     |
| Dossiês gerados (`dossier`)                         | `created_at` do pedido                      | 1 dia             | O ZIP e a linha do pedido                                                                                         | Nada do arquivo                                                                           |
| Trilha de auditoria sem documento (`audit_trail`)   | `occurred_at` de cada evento                | 1825 dias         | Eventos de `audit_events` **sem** envelope (envelopes já excluídos e eventos gerais da conta)                     | Eventos de documentos que ainda existem nunca saem por aqui                               |

Regras de validação (`RetentionPolicies::save()`, conferidas no servidor):

1. prazo nulo ou inteiro entre o **mínimo da operadora** e 36.500 dias;
2. a trilha não sai antes dos documentos que ela prova: se definida, `audit_trail >= completed` e `>= terminal_other`;
3. a trilha só aceita prazo se a operadora permitir apagá-la (`allow_audit_trail_deletion`, §8);
4. **reduzir** um prazo (ou passar de "não apagar" para um prazo) e **ativar** a exclusão automática exigem a frase `REDUZIR PRAZOS` — a tela abre um diálogo com a lista do que muda e pede a frase digitada; o servidor recusa sem ela;
5. mesmo gravado, o prazo nunca é aplicado abaixo do mínimo vigente (`effectiveDays()` usa o maior dos dois): se a operadora subir um mínimo, ele vale na hora.

Envelopes `in_progress` e `finalizing` **nunca** são apagados (a finalização ou uma assinatura incremental pode estar gravando revisões). Envio agendado para o futuro não conta como rascunho abandonado.

## 4. Mínimos legais — para revisão jurídica

Os mínimos são da **operadora** (config), não da organização. Os valores de partida são conservadores e **não foram validados juridicamente**:

- concluídos 5 anos (prazo prescricional quinquenal frequente em relações de consumo e cobranças — o geral do Código Civil é de 10 anos, art. 205);
- encerrados sem conclusão 180 dias; rascunhos 30 dias;
- fotos da captura 7 dias (dado sensível, LGPD art. 11 — o prazo curto é o objetivo; o mínimo só evita apagar antes de uma contestação imediata);
- trilha 5 anos.

**Pendência do proprietário**: fixar os mínimos com o jurídico (inclusive o XML fiscal de §2.21, que fica fora desta política e segue o prazo legal próprio).

## 5. Preservação (bloqueio de exclusão)

### 5.1 Escopos e vigência

`legal_holds`: quem (`created_by_user_id`), motivo (`reason`, até 1000 caracteres), desde quando (`starts_at`), até quando (`ends_at`, opcional), liberação (`released_at`, `released_by_user_id`, `release_reason`). **Ativo** = não liberado, já iniciado e sem `ends_at` vencido.

| Escopo         | Cobre                                                                                |
| -------------- | ------------------------------------------------------------------------------------ |
| `envelope`     | aquele documento                                                                     |
| `folder`       | os documentos que **estão** na pasta ou numa subpasta no momento de cada verificação |
| `organization` | tudo da organização, inclusive a própria organização                                 |

### 5.2 Quem respeita

Uma regra só (`LegalHolds`), consultada por todos os caminhos que apagam:

| Caminho                            | Comportamento com bloqueio ativo                                                                                                      |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `retention:apply` (envelopes)      | preservados saem da consulta (não "entopem" o lote); o purgador confere de novo sob lock; evento `retention.skipped_by_hold` (1x/dia) |
| `retention:apply` (fotos, dossiês) | fotos e dossiês de envelopes/pastas preservados ficam fora da consulta                                                                |
| Preservação da organização         | o job não faz nada na organização (registrado 1x/dia)                                                                                 |
| Exclusão manual do rascunho        | `EnvelopeController::destroy` chama `guardEnvelope()`: volta com a mensagem e registra `legal_hold.blocked_deletion` com o autor      |
| Exclusão definitiva da organização | `OrganizationPurge::due()` deixa a organização de fora (continua agendada); `purge()` direto lança `LegalHoldActiveException`         |

Toda tentativa barrada entra em `retention_events` (`legal_hold.blocked_deletion`, com o contexto). Tentativa de pessoa: sempre; automática: no máximo uma por dia por assunto.

### 5.3 Liberar

Exige a capacidade `manage_legal_holds` e um motivo; é idempotente e fica na trilha (`legal_hold.released`). Depois dela, o que já venceu pode ser apagado na próxima execução diária — o diálogo avisa.

### 5.4 Exclusão da organização com preservação — decisão

Decisão pendente do roadmap ("bloqueia ou transfere"): **bloqueia**. Transferir documentos preservados para outra conta mudaria o controlador dos dados sem base contratual. A organização continua com a exclusão agendada; quando a última preservação é liberada, a exclusão segue o curso normal. **Contrato para a tela Geral** (`GeneralController`, fora da área): avisar no pedido de exclusão que há preservações ativas (`LegalHolds::anyActive()`).

### 5.5 Limitações conhecidas (honestas)

- **Pasta**: a cobertura é avaliada no momento. Mover um documento para fora da pasta tira a cobertura, e excluir a pasta (que devolve os documentos para "Todos") também. Para litígio, prefira preservar **o documento** ou a organização. Contrato para `FolderController::destroy` e `EnvelopeController::move`/`EnvelopeBulkController` (fora da área): recusar com `LegalHolds::coveringHold()`/`holdsCovering()`.
- **Retenção global das fotos** (`identity:purge-captures`, `App\Services\Identity\CapturePurge`, fora da área) ainda **não** consulta os bloqueios. Contrato: excluir da consulta `envelope_id` coberto por `LegalHolds::snapshot()`.
- **Expiração própria dos dossiês** (`dossier_exports.expires_at`/`purged_at`, K-TSA) é do outro item; se ela apagar arquivos, deve consultar os bloqueios pelo mesmo contrato.

## 6. Verificação pública depois da exclusão — decisão (viabilidade §4.5 item 30)

| Opção (`verification_after_purge`)                 | O que a página responde para o código de um envelope expurgado                                                           |
| -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `hidden`                                           | Idêntico a código inexistente. Nada é guardado para a página.                                                            |
| `notice`                                           | "Registro removido por política de retenção" + data. Sem título, organização, participantes, linha do tempo nem resumos. |
| **`notice_with_final_hash`** (padrão, recomendado) | O aviso acima + **só** o(s) resumo(s) SHA-256 do(s) arquivo(s) final(is), sem nomes de arquivo.                          |

**Por que o recomendado.** A verificação existe para que um terceiro que recebeu o arquivo final confira que ele é o emitido. Depois da exclusão, quem **guardou a cópia** continua podendo fazer isso (calcula o resumo no navegador e compara), e quem não tem a cópia não aprende nada: um SHA-256 não é revertível e não identifica pessoa nenhuma. Título, organização, participantes, resumo da versão enviada, linha do tempo e IPs somem. `hidden` destruiria essa utilidade; `notice` a reduziria a "existiu".

Detalhes:

- É **decisão do proprietário**: configurável em `assinavelox.retention.verification_after_purge`. A regra vigente é o **teto**: trocar para `notice` esconde resumos já guardados; trocar para `hidden` esconde tudo. O recibo só guarda resumos quando a regra da hora da exclusão permitia.
- Só envelopes **já publicáveis** (enviados, com código) ganham o registro. Rascunho expurgado continua indistinguível de código inexistente.
- Para não tocar o controller nem a página (fora da área), `PublicVerification::lookup()` devolve um `PurgedEnvelope` transitório e `result()`/`checkHash()` respondem pelo `RetentionTombstones`, **no mesmo formato** da resposta atual (título "Registro removido por política de retenção", `organization_name` vazio, `recipients` vazio, só `hashes.final_sha256`) mais a chave `retention: {purged, purged_at, mode, message, final_hashes_count}`. **Contrato para `resources/js/pages/verify/show.tsx`** (fora da área): quando `result.retention?.purged`, mostrar um estado próprio ("Este registro foi removido em {purged_at} pela política de retenção da organização") em vez do cartão normal, com a conferência de arquivo só se `final_hashes_count > 0`.
- A conferência por resumo digitado (`verify.check_file`) compara só com os resumos finais mantidos.

## 7. Propagação

Ordem de `EnvelopePurger` (um envelope por vez):

1. lock `retention:envelope:{id}` — nunca dois workers no mesmo envelope;
2. relê o envelope; `in_progress`/`finalizing` saem sem nada;
3. bloqueio ativo → para, registra;
4. colhe os caminhos no disco — `document_versions.storage_path` (original, convertida, enviada, consolidada, evidências, final e revisões assinadas), `signature_acceptances.signature_image_path`, `signing_field_values.image_path`, `identity_captures.storage_path` e os derivados configurados (`timestamp_tokens`, `dossier_exports`) — e grava o **recibo `pending`** com essa lista, os resumos finais (conforme §6) e as contagens;
5. apaga as linhas numa transação curta — derivados (carimbos, dossiês, assinaturas de participante, pedidos de assinatura), `verification_records` e filhas, filhos com `envelope_id`, versões, documentos e o envelope. **Nenhuma chamada externa dentro da transação**;
6. **depois do commit**, apaga os arquivos e o diretório `orgs/{org}/envelopes/{envelope}`; o recibo vira `completed`. Arquivo que resiste deixa o recibo `pending` e a próxima execução termina (`resumePending()`).

O que **fica**: o recibo (`retention_deletions`, sem título, nome, e-mail, IP ou caminho depois de concluído); `plan_consumptions` (a FK anula o envelope; é ledger de cobrança); `audit_events` — a FK `envelope_id` é `nullOnDelete`, então os eventos ficam, sem o vínculo, até o prazo da categoria trilha. **A aplicação não emite UPDATE em `audit_events`** (T7); quem anula a coluna é a FK.

**Caches e índices**: o contador da barra lateral é esquecido (`Permissions::forgetCounts()`); não há miniaturas nem páginas renderizadas em cache no disco; os índices do banco saem com as linhas.

**Tabelas de evidência**: a exclusão por retenção **apaga** `signature_acceptances`, `document_versions`, `acceptance_documents`, `signing_session_documents` e `verification_records` do envelope — é a finalidade da retenção. Isso exige DELETE nessas tabelas para o usuário que roda o job, exatamente como a exclusão da organização já exigia. Se a operadora aplicar o REVOKE de `docs/seguranca-operacional.md` §3, rode `retention:apply` com uma conexão de privilégio próprio (a mesma do `organizations:purge`).

**Exclusão da organização** (`OrganizationPurge`): bloqueada por preservação (§5.4); apaga também `retention_events`, `retention_deletions`, `legal_holds` (já liberados), `retention_policies` e as tabelas derivadas que existirem; colhe também imagens de campo, fotos e arquivos derivados fora de `orgs/{ulid}`.

### 7.1 Backups — texto para a Política de Privacidade (PARA REVISÃO JURÍDICA)

> Não há exclusão seletiva em cópias de segurança. O que a política declara é a **janela de rotação** (`assinavelox.retention.backup_window_days`, padrão 35 dias, que precisa bater com `docs/implantacao.md` §14.1: banco 30 dias, binlog 14, arquivos 90 — **hoje os arquivos ficam 90 dias**; alinhe os números antes de publicar).

Texto sugerido:

> "Quando um documento é excluído — por pedido, pela política de retenção definida pela organização ou pelo encerramento da conta —, ele é removido imediatamente dos sistemas em uso. Cópias de segurança não permitem apagar um documento isoladamente: elas são substituídas em ciclo e, em até **{N} dias** depois da exclusão, nenhuma cópia ativa contém mais o documento. Nesse intervalo as cópias ficam cifradas, com acesso restrito à equipe de operação, e só são usadas para recuperação de desastres. Se uma cópia for restaurada, as exclusões feitas depois dela são reaplicadas antes de o sistema voltar ao ar."

Compromisso operacional que o texto cria: depois de restaurar um backup, rodar `retention:apply` e `organizations:purge` **antes** de abrir o sistema (os recibos em `retention_deletions` dizem o que precisa sair de novo). **Pendência**: registrar esse passo no roteiro de restauração de `docs/implantacao.md` §14 (fora da área deste item).

## 8. Configuração — contrato para `config/assinavelox.php` (fora da área)

Hoje os valores têm padrão no código (`RetentionConfig`), como o bloco opcional de `PublicFormsConfig`. Para ligar por ambiente, acrescentar:

```php
// features
'retention_policies' => filter_var(env('ASSINAVELOX_FEATURE_RETENTION_POLICIES', false), FILTER_VALIDATE_BOOLEAN),

// bloco novo
'retention' => [
    'verification_after_purge' => env('ASSINAVELOX_RETENTION_VERIFICATION', 'notice_with_final_hash'), // hidden | notice | notice_with_final_hash
    'allow_audit_trail_deletion' => filter_var(env('ASSINAVELOX_RETENTION_ALLOW_AUDIT_TRAIL_DELETION', false), FILTER_VALIDATE_BOOLEAN),
    'batch_size' => (int) env('ASSINAVELOX_RETENTION_BATCH_SIZE', 100),
    'backup_window_days' => (int) env('ASSINAVELOX_RETENTION_BACKUP_WINDOW_DAYS', 35),
    'minimum_days' => [
        'completed' => (int) env('ASSINAVELOX_RETENTION_MIN_COMPLETED', 1825),
        'terminal_other' => (int) env('ASSINAVELOX_RETENTION_MIN_TERMINAL', 180),
        'draft' => (int) env('ASSINAVELOX_RETENTION_MIN_DRAFT', 30),
        'identity_capture' => (int) env('ASSINAVELOX_RETENTION_MIN_CAPTURE', 7),
        'dossier' => (int) env('ASSINAVELOX_RETENTION_MIN_DOSSIER', 1),
        'audit_trail' => (int) env('ASSINAVELOX_RETENTION_MIN_AUDIT_TRAIL', 1825),
    ],
    // 'derived_artifacts' => [...] — só se precisar sobrescrever o padrão de RetentionConfig.
],
```

`allow_audit_trail_deletion` fica **desligado**: a trilha é append-only (T7). Ligar é decisão consciente da operadora, que passa a precisar de DELETE em `audit_events` para o job.

## 9. Contratos para o que está fora da área

| Arquivo (dono)                                                                            | O que falta                                                                                                                                                                           |
| ----------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `HandleInertiaRequests::features()`                                                       | chave `retention_policies` = `RetentionFeature::enabled($organization)`                                                                                                               |
| `resources/js/layouts/settings/layout.tsx`                                                | item "Retenção e preservação" → `settings.retention`, oculto sem `features.retention_policies` ou sem `permissions.manage_settings` (a tela hoje só é acessível pela URL)             |
| `resources/js/types/index.ts` (`Features`)                                                | `retention_policies?: boolean`                                                                                                                                                        |
| `app/Enums/Permission.php`                                                                | caso `ManageLegalHolds = 'manage_legal_holds'` (grupo `account`, concedível); depois trocar `RetentionAuthorization::canManageHolds()` por `hasPermission()` — rotas e tela não mudam |
| `app/Enums/AuditEventType.php` + RECONCILIACAO §3                                         | espelhar os eventos de `retention_events` (`legal_hold.placed/released/blocked_deletion`, `retention.envelope_purged`, `retention_policy.updated`) na trilha da conta                 |
| `resources/js/pages/envelopes/show.tsx`                                                   | §10                                                                                                                                                                                   |
| `resources/js/pages/verify/show.tsx`                                                      | estado próprio para `result.retention?.purged` (§6)                                                                                                                                   |
| `FolderController::destroy`, `EnvelopeController::move`, `EnvelopeBulkController` (mover) | recusar com `LegalHoldActiveException` quando a pasta/documento está preservado (§5.5)                                                                                                |
| `GeneralController::requestDeletion`                                                      | avisar que há preservações ativas (§5.4)                                                                                                                                              |
| `App\Services\Identity\CapturePurge`                                                      | pular fotos de envelopes preservados (§5.5)                                                                                                                                           |
| `docs/implantacao.md` §14                                                                 | passo "reaplicar exclusões depois de restaurar" (§7.1)                                                                                                                                |

Único arquivo fora da lista da área que precisou mudar: **`EnvelopeController::destroy`** (três linhas: a guarda da preservação), porque a exclusão manual vencida pela preservação é requisito explícito e não havia outro ponto de entrada.

## 10. Contrato do detalhe do documento (selo "Preservado")

Sem editar `envelopes/show.tsx` (outro agente), o contrato é:

**Dados** — escolha uma:

- `GET /documentos/{envelope}/preservacao` (`envelopes.legal_hold.show`, JSON, só `view`), ou
- como prop do detalhe: `legal_hold => app(RetentionPresenter::class)->forEnvelope($envelope, CurrentOrganization::instance()->membership())`.

```ts
// resources/js/components/retention/types.ts → EnvelopeLegalHold
{
  feature_enabled: boolean;
  preserved: boolean;                       // há bloqueio ativo cobrindo o documento
  holds: LegalHoldRow[];                    // ativos que o cobrem (documento, pasta/ancestral, organização)
  can: { place: boolean; release: boolean };
  retention: { category, category_label, policy_active, eligible_at };  // quando a política o alcança
  endpoints: { show: string; store: string };
}
```

**Interface** — pronta em `resources/js/components/retention/`:

- `<PreservedBadge since until />` — selo "Preservado" para o cabeçalho do documento (ao lado do status);
- `<EnvelopeLegalHoldPanel legalHold={...} />` — bloco com o selo, as preservações, "quando a retenção alcança", **Preservar documento** (`POST envelopes.legal_hold.store`: `reason` obrigatório 5–1000, `ends_at` opcional futura) e **Liberar** (`POST legal_holds.release`: `reason` obrigatório). Sem a flag e sem preservação, não renderiza nada.
- Ação "Excluir rascunho" com documento preservado: esconder/desabilitar quando `preserved`; se chamada, o servidor volta com a mensagem de erro (flash `error`).

## 11. Formulário público — marcas de uso único no banco

As marcas do carimbo de tempo mínimo saíram do cache (`docs/fase-2/formulario-publico.md` §7): tabela `public_form_timer_marks` com `token_digest` único e `expires_at`; consumo por INSERT com unicidade (`insertOrIgnore`, atômico); limpeza de hora em hora (`public-forms:prune-timer-marks`). O comportamento visível não mudou (os testes de PublicForms seguem verdes) e **esvaziar o cache não reabre carimbo** (teste novo). A ressalva da §5 de `docs/implantacao.md` foi removida.

## 12. Concorrência e idempotência

- Lock por envelope no purgador; `ApplyRetentionPoliciesJob` (versão enfileirável) é `ShouldBeUnique`; o agendamento usa `withoutOverlapping` + `onOneServer`.
- Nenhuma transação aberta durante operação de disco; arquivos só depois do commit.
- Recibo único por envelope (`subject_type` + `subject_ulid`); rodar de novo não duplica recibo nem evento; recibo pendente é retomado.
- Um envelope que falha não interrompe os demais (log `retention.purge.failed`, resumo `failed`, comando sai com código 1).

## 13. Testes

`tests/Feature/Phase2/Retention/` — 32 casos:

- `RetentionApplyTest` — apaga exatamente o vencido (disco e banco), recibo sem dado pessoal, trilha preservada; idempotência; retomada de recibo pendente; recusados/rascunhos excluídos, nunca em andamento; mínimo vale sobre prazo gravado; `--dry-run`; isolamento entre organizações.
- `LegalHoldTest` — preservação vence a retenção (e as fotos), depois da liberação apaga; pasta cobre subpastas, organização cobre tudo, data final vencida não cobre; vence a exclusão manual (com autor na trilha); vence a exclusão da organização e, liberada, a exclusão leva as tabelas novas; permissão própria (operador não pode), motivo obrigatório; pela tela de configurações; isolamento.
- `VerificationAfterPurgeTest` — padrão (aviso + resumo final, conferência por resumo funciona, nada vaza), `notice`, `hidden` (idêntico a inexistente), regra vigente como teto.
- `PropagationTest` — fotos (arquivo sai, linha fica, preservada fica), dossiês (arquivo e linha; preservado fica), derivados do envelope fora do diretório, trilha só com permissão da operadora e só sem documento.
- `RetentionPolicyTest` — tela com categorias e mínimos, operador sem acesso, mínimo legal, confirmação forte, trilha >= documentos, mínimo elevado depois, isolamento.
- `FlagOffTest` — flag desligada = comportamento atual.
- `tests/Feature/Phase2/PublicForms/FillTimerDatabaseTest.php` — 3 casos (cache esvaziado não reabre; atômico e sem guardar o carimbo; limpeza).

## 14. Pendências do proprietário

1. Mínimos legais por categoria (§4) — jurídico.
2. Confirmar a decisão da verificação pós-exclusão (§6) — recomendado `notice_with_final_hash`.
3. Janela de backup e texto da Política de Privacidade (§7.1) — jurídico + operação (alinhar com §14.1 de implantação).
4. Se a trilha de auditoria pode ter prazo (`allow_audit_trail_deletion`) — jurídico + segurança.
5. Integrações listadas em §9.
