# Fase 3, parte 2 — onda F (produtividade documental): relatório de integração (I-3F)

> Consolida as cinco áreas da onda F: geração em lote (§3.1), âncoras e OCR (§3.2), etapas
> condicionais e delegação (§3.3), vídeo curto no aceite (§3.3) e página pública e e-mails
> multilíngues (§3.3). Precedência: `docs/design/RECONCILIACAO.md` → `docs/arquitetura.md` →
> `docs/roadmap.md` → `docs/fases-2-3-viabilidade.md` → este arquivo.
> A onda G (widget, SDKs, SSO, conectores) não foi começada.

## 1. Resumo

As sete flags novas nascem **desligadas**, globais **e** por plano (`config/assinavelox.php`
`features.*` + `plans.features.*`): `bulk_generation`, `field_anchors`, `ocr`,
`conditional_steps`, `delegation`, `identity_video` e `multilingual`. Com todas desligadas, nada
muda: a suíte existente passa sem mudança de asserção (a única exceção aceita, T8, é a lista
fechada de chaves do `SharedPropsTest`, que ganhou as sete chaves com `false`; o
`EnumCatalogTest` ganhou os eventos novos no fim, como em toda onda — T7).

| Área                  | Documento                           | Flag(s)                                        | Migrations                   |
| --------------------- | ----------------------------------- | ---------------------------------------------- | ---------------------------- |
| Geração em lote       | `docs/fase-3/geracao-em-lote.md`    | `bulk_generation` (exige `templates`)          | `2026_09_14_160001`–`160002` |
| Âncoras e OCR         | `docs/fase-3/ancoras-e-ocr.md`      | `field_anchors`, `ocr` (exige `field_anchors`) | `160101`–`160104`            |
| Etapas e delegação    | `docs/fase-3/etapas-e-delegacao.md` | `conditional_steps`, `delegation`              | `160201`–`160204`            |
| Vídeo curto no aceite | `docs/fase-3/captura-de-video.md`   | `identity_video`                               | `160301`–`160302`            |
| Multilíngue           | `docs/fase-3/multilingue.md`        | `multilingual`                                 | `160401`–`160402`            |

Todas as migrations são aditivas e MySQL-compatíveis (nenhuma altera ou remove coluna existente).

## 2. Verificação (números reais desta integração)

| Verificação                                                          | Resultado                                                                                                             |
| -------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| Linha de base (antes da integração) Unit+Feature                     | 2.353 testes, 2.352 passaram, **1 falha** (`Review/SignerCopyAndLabelsTest`, quebrado pela camada de tradução — §3.1) |
| Unit+Feature depois das correções (paralelo)                         | **2.355 / 2.355**, 23.199 asserções                                                                                   |
| Unit+Feature final (depois das correções do QA)                      | **2.355 / 2.355**, 23.224 asserções                                                                                   |
| Navegador (`--testsuite=Browser`, sozinho e em série)                | **38 passaram + 3 pulados** (41), 846 asserções                                                                       |
| Ponta a ponta da onda (`tests/Feature/EndToEnd/Phase3WaveFTest.php`) | 2 / 2, 201 asserções                                                                                                  |
| I18n + vocabulário + ponta a ponta (depois da correção do QA)        | 62 / 62, 2.165 asserções                                                                                              |
| pdftool (`pytest -q`)                                                | **235 passaram** (184 anteriores + 51 do `find-anchors`)                                                              |
| PHPStan (projeto inteiro)                                            | **0 erros**                                                                                                           |
| Pint (`--test`)                                                      | passa                                                                                                                 |
| `npm run types:check` (tsc)                                          | 0 erros                                                                                                               |
| `npm run check` (vp: formato + lint)                                 | passa (425 arquivos formatados, 0 avisos)                                                                             |
| `npm run build`                                                      | concluído                                                                                                             |
| `php artisan wayfinder:generate --with-form` / `route:list`          | sem erro                                                                                                              |

### 2.1 Números finais

- Unit+Feature final (paralelo, depois de todas as correções): **2.355 testes, 2.355 passaram**,
  23.224 asserções, 0 falhas.
- Navegador (sozinho, em série, sem outra suíte e sem o servidor de QA): **41 testes — 38 passaram,
  3 pulados** (os mesmos 3 da parte 1), 846 asserções. Os 2 a mais que a parte 1 são do
  `tests/Browser/SignerI18nTest.php` (F-I18N).

## 3. Correções e ajustes feitos na integração

### 3.1 Teste existente vermelho: `SignerCopyAndLabelsTest` ("Desfazer")

A camada de tradução trocou o texto literal do botão de desfazer por `t('signature.pad.undo')`, e o
teste de revisão de cópia (que lê a fonte e exige o rótulo "Desfazer") ficou vermelho. Sem mudar a
asserção: `resources/js/i18n/index.tsx` ganhou o componente `Trans`
(`<Trans k="signature.pad.undo">Desfazer</Trans>`) — em PT-BR mostra o texto de referência escrito no
JSX, nos demais idiomas a tradução da chave. `signature-pad-canvas.tsx` usa-o no botão.

### 3.2 Delegação × vídeo curto × idioma (lacuna entre F-FLOW, F-VIDEO e F-I18N)

- `DelegationExecutor::copyVideoRequirement`: a exigência de vídeo curto do original passa ao
  delegado com a mesma duração máxima (antes só a de fotos passava — F-FLOW §10 registrava a lacuna).
- `DelegationPolicy::hasStrongerAuthentication` conta o vídeo exigido como autenticação reforçada:
  delegar um participante com vídeo exigido **sempre** precisa da confirmação de quem enviou, mesmo com
  a política "sem confirmação" (o remetente pediu o vídeo de uma pessoa; agora será de outra).
- O delegado herda `locale` e `timezone` do original (o idioma foi escolhido por quem enviou para a
  posição): convite, código e página saem no mesmo idioma, e o delegado pode trocar o idioma de
  exibição na página. Sem a flag `multilingual`, os campos não valem.

### 3.3 Texto em PT-BR na página em inglês (achado do QA)

A dica da caixa de assinatura ("Clique para assinar aqui") nasce em PHP
(`SignerPresentation::placeholder`) e não passava pelo localizador. `SignerPropsLocalizer` agora
traduz `my_fields[].placeholder` (sempre texto nosso) e o rótulo padrão "Testemunha" pelo catálogo;
`lang/{en,es}/signer_messages.php` ganharam as seis entradas (paridade de chaves conferida pelo
`KeyParityTest`). Rótulos escritos pelo remetente continuam saindo como vieram (T6). O ponta a ponta
confere `Click to sign here` nas props da delegada.

### 3.4 Limites de requisição com prefixo próprio

O `throttle:N,M` sem nome do Laravel divide um contador por usuário entre todas as rotas que o usam
(inclusive o link de verificação de e-mail, `throttle:6,1`) — foi a causa dos 429 do
`AllGetRoutesTest` relatados pelas áreas. As rotas de F-FLOW já tinham prefixo; o F-ANCHOR tirou o
throttle dos GET. Na integração, os POST/PUT de lote e de âncoras ganharam prefixos
(`bulk-store`, `bulk-mapping`, `bulk-confirm`, `anchors-detect`, `anchors-accept-all`,
`anchors-suggestion`, `anchors-rules-update`, `anchors-rules-test`), com os mesmos limites.

### 3.5 Contratos entre props e tipos

- `resources/js/types/index.ts` (`Features`): faltavam `conditional_steps` e `delegation`
  (enviadas pelo `HandleInertiaRequests`); acrescentadas.
- `routes/web.php`: imports ordenados e FQCN das rotas de âncoras trocados por `use` (Pint); nenhuma
  rota mudou de nome ou URI. `app/Services/Envelopes/EnvelopeReadiness.php`: só formatação (Pint).
- Documentos das áreas formatados pelo `vp` (o `npm run check` acusava seis arquivos).

### 3.6 Conferência dos arquivos compartilhados

Nenhuma edição apagou a de outra área: `AuditEventType` tem os 12 casos novos no fim (7 F-FLOW,
3 F-VIDEO, 2 F-I18N) com rótulos; `HandleInertiaRequests::features()` tem as sete chaves;
`config/assinavelox.php` tem as sete flags e as seções `flow`, `delegation`, `multilingual`,
`field_anchors`, `ocr`, `bulk_generation` e `capture_video`; `AllGetRoutesTest` tem as 11 rotas GET
novas com 404 esperado; as páginas `wizard.tsx`, `envelopes/show.tsx` e `sign/show.tsx` têm os ganchos
de F-FLOW, F-VIDEO e F-I18N convivendo. `CreateEnvelopeFromTemplate` recebeu os ganchos do F-BULK
(versão fixada, contexto de auditoria, celular) e do F-ANCHOR (regras do modelo) sem conflito.

## 4. Ponta a ponta (`tests/Feature/EndToEnd/Phase3WaveFTest.php`)

**Com as sete flags ligadas** (global E plano), pelas rotas HTTP reais:

1. modelo PDF (aprovação → compradora → jurídico) com regra de âncora "data abaixo de _Contrato de
   teste_" para a compradora;
2. lote de 5 linhas; a 3ª tem e-mail inválido e fórmula no título → recusada na pré-validação, que
   não cria envelope nem reserva cota; a confirmação reserva **4** unidades (a cota do plano é 4) e
   devolve cada uma quando o rascunho nasce; o relatório CSV traz coluna e mensagem, nunca o valor
   digitado nem a fórmula;
3. os quatro envelopes nascem em **rascunho** com a sugestão pendente (âncoras só sugerem; nenhum
   convite sai); o OCR ligado sem Tesseract responde "OCR indisponível neste servidor";
4. num deles a sugestão é confirmada e o campo salvo pelo editor (`PUT envelopes.fields.sync`) → pronto;
5. etapas (a 2ª condicionada a "aprovou", a 3ª a "recusou"), delegação sem confirmação na política,
   idioma inglês e vídeo curto para a compradora; envio;
6. a aprovadora aprova → etapa 2 ativa, convite **em inglês** para a compradora;
7. a compradora delega → o pedido fica pendente (**o vídeo força a confirmação**); quem enviou confirma;
   a delegada herda etapa, campos (assinatura + data da âncora), idioma e exigência de vídeo; o link da
   compradora deixa de abrir;
8. a delegada abre a página em inglês (declaração de referência em PT-BR, tradução de cortesia ao
   lado, dica traduzida), tenta aceitar sem vídeo (recusado), envia o vídeo e aceita;
9. a etapa 3 é **pulada com registro** (regra e valor observado; o jurídico fica `canceled` /
   `step_skipped`, nunca notificado); a finalização conclui com o pdftool real e o certificado de
   **teste** da operadora; o `pdftool validate` confirma 1 assinatura íntegra, válida e confiável,
   cobrindo o arquivo inteiro;
10. as evidências (dados e PDF) mostram "Delegações", "Etapas do fluxo", "Idioma da página: English"
    e o SHA-256 do vídeo; o PDF não tem arquivo embutido e usa "em nome de" só para negar.

**Com as sete flags desligadas**, o mesmo modelo percorre o fluxo antigo: rotas novas 404 (lote,
âncoras, fluxo, etapas, delegação, idiomas, vídeo, troca de idioma), nenhuma linha nas oito tabelas
novas, convites em PT-BR, `display_locale` nulo e evidências sem chave nova; o arquivo final continua
assinado pela operadora (teste) e sem as seções novas.

## 5. Dados de demonstração (seeders)

`DemoOrganizationSeeder` — a constante `PHASE3_WAVE_F_PLAN_FEATURES` liga as sete chaves no plano da
**Horizonte** e as desliga no da **Vega**. `seedHorizonteWaveF`, pelos serviços reais e sem segredo:

- regra de âncora no modelo "Contrato de locação residencial" (data do locatário abaixo de "Locatário");
- "Termo de vistoria de entrada — Sala 302" (pronto): duas etapas, delegação com confirmação (o 1º
  participante é pessoal) e vídeo exigido do 2º;
- "Aditivo de reajuste — Contrato 2024/118" (em andamento): 1º participante em inglês (fuso de Nova
  York), com vídeo exigido e delegação com confirmação; 2º em espanhol.

Os interruptores globais são ligados só durante a semeadura. Os envelopes escolhidos não são os que
o `AllGetRoutesTest` usa (e ele passou). Obs.: os envelopes de demonstração da Fase 1 não têm bytes
de PDF no disco (limitação antiga do seeder) — para ver o documento na página pública use um envelope
criado pela interface ou pelo lote.

## 6. QA no navegador (banco `database/i3f.sqlite`, `php -S 127.0.0.1:8151`, flags ligadas)

| Fluxo                                                                  | Resultado                                                                                                                                                                                                                                                           |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Gerar em lote (planilha com 1 linha inválida)                          | Mapeamento sugerido; pré-validação 3/2/1 com coluna e mensagem, sem o valor nem a fórmula; confirmação "Serão reservados 2 dos 491"; 2 documentos "Pronto para enviar"; console limpo                                                                               |
| Detectar campos no editor (PDF com `{{assinatura:signatario_1}}` etc.) | 4 sugestões tracejadas "Sugerido", cada uma no participante do marcador; aviso "o documento só pode ser enviado depois que todas as sugestões forem confirmadas ou descartadas"; "Confirmar as 4 sugestões do texto" virou 4 campos; console limpo                  |
| Editor de etapas e delegação (passo 4)                                 | "Fluxo por etapas" (com etapa por participante) e "Delegação" renderizam; o JSON `/fluxo` responde 200                                                                                                                                                              |
| Participantes (passo 2)                                                | "Exigir vídeo curto antes do aceite" e "Idioma dos e-mails e da página" + fuso por participante                                                                                                                                                                     |
| Página pública em inglês (desktop e 375 px)                            | Tudo em inglês, `lang="en"`, sem rolagem horizontal (largura 375); declaração com "Courtesy translation" e referência em PT-BR a um clique; termos "(in Portuguese)"; e-mails de convite e código em inglês                                                         |
| Página pública em espanhol (seletor; desktop e 375 px)                 | Tudo em espanhol, `lang="es"`, datas em 24 h; troca registrada                                                                                                                                                                                                      |
| Delegação na página pública                                            | Formulário em inglês (validação do cliente em inglês); pedido pendente "the participation remains yours"; no detalhe do documento, "Confirmar delegação" / "Recusar" para quem enviou                                                                               |
| Vídeo curto                                                            | Diálogo com consentimento obrigatório e aviso de tradução de cortesia; o painel de navegador do QA **bloqueia a câmera**: a tela mostra "Camera permission was denied…" e oferece "Upload video from the device" (alternativa conferida; gravação real não testada) |
| Console                                                                | Sem erros de script. Restam 404 de recurso **esperados**: `certificado`, `externa`, `gov-br` (descoberta das flags da Fase 3, parte 1, desligadas) e, só nos envelopes do seeder da Fase 1, `documento` (sem bytes no disco)                                        |

Achado corrigido durante o QA: §3.3. Bancos e arquivos de QA criados por esta integração
(`database/i3f.sqlite`, `storage/app/documents/orgs/<org do QA>`) foram apagados; o servidor de QA
foi encerrado pelo PID dele.

## 7. Contratos consolidados (para quem vem depois)

- **Rotas novas** (todas 404 com a flag desligada): ver os documentos de cada área —
  `bulk_generations.*` (10), `anchors.*` (8), `envelopes.flow.show`, `envelopes.steps.update`,
  `envelopes.delegation.update`, `envelopes.delegations.approve|reject`, `sign.delegation.show|store`,
  `sign.capture.video.store`, `envelopes.recipients.identity_video`,
  `envelopes.identity_videos.index|file`, `envelopes.recipients.locales|locale`, `sign.locale.update`.
- **Props compartilhadas**: `features.{bulk_generation, field_anchors, ocr, conditional_steps,
delegation, identity_video, multilingual}`. Página pública: `i18n` (só com `multilingual`),
  `identity_video` (só com vídeo exigido), `consent.translation`, `privacy.reference`.
- **Eventos de auditoria novos**: `signing_steps.updated`, `envelope.step_started`,
  `envelope.step_skipped`, `delegation.policy_updated`, `delegation.requested`,
  `recipient.delegated`, `delegation.rejected`, `identity_video.requirement_updated`,
  `identity_video.recorded`, `identity_video.accessed`, `recipient.locale_updated`,
  `recipient.display_locale_changed`. O lote e as âncoras não criaram eventos (o lote grava
  `bulk_generation`/`bulk_row` no payload de `envelope.created`/`template.used`).
- **Componente novo de i18n**: `Trans` (`resources/js/i18n`), para rótulos curtos com a cópia PT-BR
  no JSX.

## 8. Pendências — o que o proprietário precisa fornecer ou decidir, por item

**Geração em lote (`bulk_generation`)**

1. Limites por plano em `plans.features.bulk_generation_limits` e ligar a flag nos planos.
2. Worker ouvindo `bulk_generation.queue` (padrão `default`); na fila `sync` tudo roda na requisição.
3. LibreOffice em produção para modelos DOCX (concorrência baixa nesse caso).
4. Retenção para lotes não confirmados (ainda sem rotina de descarte) e varredura de linha presa em
   `processing`.
5. Janela conhecida de cota entre devolver a unidade do lote e o `SendEnvelope` reservar a do envelope.
6. Eventos de auditoria do lote (`bulk_generation.confirmed|canceled|completed`), se o produto quiser.
7. Com regras de âncora no modelo, "enviar ao gerar" deixa os documentos "Gerado, não enviado" até a
   revisão das sugestões — é o comportamento correto (nunca envio automático com sugestão pendente).

**Âncoras e OCR (`field_anchors`, `ocr`)**

1. Worker da fila `anchors`; QA com PDFs reais de clientes.
2. OCR: instalar Tesseract 5.5 com `por.traineddata`, `ASSINAVELOX_OCR_TESSERACT_PATH`, worker da fila
   `ocr` dimensionado para CPU e limite de custo por plano.
3. A meta de ≥ 90 % em escaneado legível **não foi medida** (exige Tesseract e fixture real; roteiro na
   §12 de `ancoras-e-ocr.md`).
4. Confirmar uma sugestão devolve o campo ao editor, que o salva pelo caminho de sempre; se o editor
   não salvar (aba fechada no instante), a sugestão fica confirmada sem campo — o envelope segue
   pedindo campo de assinatura, então não há envio errado, mas vale um teste de navegador.

**Etapas e delegação (`conditional_steps`, `delegation`)**

1. Política de delegação padrão e texto dos termos sobre delegação (jurídico).
2. E-mail ao remetente do pedido de delegação (hoje só o sino; falta a preferência) e texto próprio no
   convite do delegado.
3. Publicar `recipient.delegated` e `envelope.step_skipped` em webhooks (catálogo sensível à flag ou
   decisão de publicar para todos).
4. Cosméticos: "X de Y assinaram" conta quem delegou/foi pulado no denominador; `signs_after_me` não
   marca "assina depois" no paralelo com etapas.

**Vídeo curto (`identity_video`)**

1. Decisão jurídica (viabilidade §4.4 item 20): base legal, RIPD, retenção e revisão dos textos.
2. Aviso de privacidade do participante (`ConsentText`) ainda cita só fotos.
3. Rever a gravação antes de enviar exige `media-src 'self' blob:` na CSP.
4. Dispositivo presencial não grava vídeo: participante presencial com vídeo exigido fica bloqueado.
5. Validar em Chrome, Firefox e Safari reais e criar teste de navegador com câmera falsa (o painel de
   QA bloqueia câmera).
6. Armazenamento (~1,3 MB por 10 s) e eventual cota por plano.

**Multilíngue (`multilingual`)**

1. Revisão jurídica profissional de `lang/{en,es}/signer_legal.php` e das entradas de consentimento de
   vídeo; depois, `ASSINAVELOX_MULTILINGUAL_REVIEWED`.
2. Termos de uso e aviso de privacidade completos só em português.
3. Cartões da Fase 3, parte 1 (A1 do participante, A3, gov.br), presencial e lote de assinatura
   continuam em PT-BR — traduzi-los é pré-requisito para ligar `multilingual` junto com essas flags.
4. Mensagens novas de serviços públicos precisam de entrada no catálogo (en e es).
5. Idioma padrão por organização e cópia do idioma em duplicar, modelos, lote e API; API/webhooks não
   expõem `recipients.locale`.
6. `<html lang>` do `app.blade.php` é fixo em `pt-BR`; a página pública corrige no cliente (o
   `SignerLayout` põe `en`/`es` ao montar). Para quem lê sem JavaScript, falta levar o idioma ao Blade.

## 9. Arquivos editados pela integração (fora das áreas)

`app/Services/Envelopes/Delegation/{DelegationExecutor,DelegationPolicy}.php`,
`app/Support/Locale/SignerPropsLocalizer.php`, `lang/{en,es}/signer_messages.php`,
`resources/js/i18n/index.tsx`, `resources/js/components/signature/signature-pad-canvas.tsx`,
`resources/js/types/index.ts`, `routes/web.php` (prefixos de throttle e imports),
`database/seeders/DemoOrganizationSeeder.php`, `tests/Feature/EndToEnd/Phase3WaveFTest.php` (novo),
`docs/fase-3/{etapas-e-delegacao,captura-de-video,multilingue}.md` (notas da integração) e este
relatório. Nenhum commit foi feito; nenhum pacote foi instalado.

## 10. Revisão adversarial

Vinte e dois achados em três lentes (fluxo e concorrência; entrada não confiável, segurança e LGPD; produto, semântica e design), de todas as severidades. Todos foram corrigidos na causa. Os testes da revisão ficam em `tests/Feature/Review/Phase3F/` (11 arquivos dos achados, mais `ReviewFixesTest.php`, com 9 casos de regressão das correções) e `tools/pdftool/tests/test_review_{anchor_line_quadratic,wave_f_fixes}.py`.

### 10.1 Fluxo, domínio e concorrência

| Achado                                                                                                                                                                                        | Correção                                                                                                                                                                                                                                                                                                                                                                        |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| (alta) Recusa de aprovador lida por etapa posterior virava documento **concluído** quando outro participante da mesma etapa ainda estava pendente (o resultado dependia da ordem dos cliques) | A recusa fica em suspenso (`envelopes.settings.refusal_pending_close`) e é decidida no fim da etapa por `StepProgression::deferredRefusal`, dentro do `RecordAcceptance::advance`: nenhuma etapa posterior se aplicou → `RecordRefusal::closeEnvelope` (agora público, com `deferred_until_step_end` na trilha); alguma se aplicou → a marca sai. `etapas-e-delegacao.md` §2.4. |
| (média) Rascunho com etapas gravadas continuava por etapas depois de a flag ser desligada, e o remetente não conseguia desfazer                                                               | `SigningStepsOnSend` desfaz as etapas no envio quando a flag está desligada (como o "desligar etapas" do wizard, com `signing_steps.updated` `reason: feature_disabled_before_send`); o `PUT envelopes.steps.update` com `enabled: false` passa sem a flag quando há etapas. Envelope já enviado continua.                                                                      |
| (média) Lote: a unidade reservada na confirmação voltava antes de o envio reservar a sua, e um envio concorrente podia tomá-la                                                                | "Enviar ao gerar": `BulkGenerationQuota::handOver` entrega a unidade ao envelope e o `SendEnvelope`, na mesma transação e com a assinatura travada, troca-a pela reserva do envio sem nova checagem de cota (`assertCanSend(…, 0)`). Se o envio não acontece, a unidade volta. Agendamento continua devolvendo antes (o disparo pode cair em outro ciclo).                      |
| (baixa) `+alias` passava pela regra "para si mesmo"                                                                                                                                           | `DelegationPolicy::mailboxKey` (minúsculas, sem `+…`, sem pontos no Gmail) em `self`, `participant` e `recipient_exists`.                                                                                                                                                                                                                                                       |
| (baixa) Documento concluído sem nenhuma assinatura quando todas as etapas com signatário eram puladas                                                                                         | Regra de produto registrada em `etapas-e-delegacao.md` §2.4: nunca "concluído" sem assinatura. O envelope é encerrado pelo sistema como cancelado, com o motivo `RecordAcceptance::NO_SIGNATURE_REASON`.                                                                                                                                                                        |
| (baixa) Pedido de delegação pendente nunca era encerrado                                                                                                                                      | `DelegationVoider::voidStale` nas transições (aceite, recusa, etapa pulada, conclusão, cancelamento, expiração), com o evento novo `delegation.voided` (acrescentado no fim de `AuditEventType`).                                                                                                                                                                               |
| (baixa) Limite diário de delegações por organização sem o lock da organização                                                                                                                 | A contagem roda com a linha da organização travada. Não é reproduzível em SQLite: a correção foi verificada por leitura, e a suíte de delegação passa.                                                                                                                                                                                                                          |

### 10.2 Entrada não confiável, segurança e LGPD

| Achado                                                                                            | Correção                                                                                                                                                                                                                                                                                                                    |
| ------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| (média) `CellSanitizer::text` cortava UTF-8 (`trim` por byte) e derrubava a pré-validação com 500 | Aparar com regex Unicode e recusar UTF-8 inválido como célula não suportada. A pré-validação transforma uma falha de serialização em erro da linha, nunca em 500. `RowValidator` continua devolvendo o texto bruto já saneado: a geração revalida, e trocar pelo normalizado mudaria o `payload` gravado de todos os tipos. |
| (alta) XLSX de poucos KB com célula na coluna XFD prendia o PHP por minutos                       | Antes do OpenSpout, cada aba é lida em streaming (XMLReader, `LIBXML_NONET`), somando a largura real das linhas. Acima do orçamento (`max(200 000, (linhas + 1) × colunas)` células), a planilha é recusada com `too_wide`. Planilha comum com poucas células perdidas à direita continua sendo lida.                       |
| (média) `find-anchors`: agrupamento O(n²) e orçamento ignorado dentro da página                   | Soma acumulada por linha, relógio conferido a cada 512 glifos, página com mais de 60 000 glifos recusada (`too_many_glyphs`). "Testar regras" roda com orçamento curto (`field_anchors.test_time_budget_seconds`, 15 s; processo morto em 45 s).                                                                            |
| (baixa) Enumerar e-mails de participantes pela resposta "já participa"                            | Tentativas recusadas (`self`, `participant`) contam num limite próprio por participante (`delegation.max_refused_attempts_per_recipient`, 6 em 24 h), conferido antes de qualquer resposta.                                                                                                                                 |
| (baixa) Duração do vídeo não imposta sem declaração                                               | O WebM sem `Duration` tem a duração estimada pelos tempos dos blocos. Sem nenhuma duração, vale um teto de bytes proporcional à duração pedida.                                                                                                                                                                             |
| (baixa) Tesseract órfão / timeout do PHP menor que o pior caso                                    | O timeout do processo é orçamento + 30 s + `page_timeout`; o tesseract recebe `min(page_timeout, orçamento restante)`, roda em sessão própria (POSIX) e morre com SIGTERM ao Python.                                                                                                                                        |
| (baixa) Lote não confirmado guardava planilha e `payload` sem prazo                               | `bulk-generations:prune-unconfirmed` (diário, 04:50) descarta lote em rascunho ou validado sem alteração há mais de `bulk_generation.unconfirmed_retention_days` (7). A tela do lote avisa o prazo.                                                                                                                         |

### 10.3 Produto, semântica e design

| Achado                                                                                  | Correção                                                                                                                                                              |
| --------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| (alta) Exemplo do motivo da delegação sugeria procuração nos três idiomas               | "Ex.: quem cuida deste contrato na empresa é a pessoa indicada." (en/es equivalentes).                                                                                |
| (média) Código e convite por SMS/WhatsApp em PT-BR para participante em inglês/espanhol | `ChannelMessages::otpText                                                                                                                                             | invitationText` recebem o idioma (`SignerLocales::forRecipient`); textos em `lang/{idioma}/signer_mail.php`→`channel.*`. Em PT-BR, e com a flag desligada, o texto é o de antes. Template de WhatsApp por idioma registrado como pendência em `multilingue.md` §7. |
| (média) Editor de delegação não citava o vídeo curto                                    | Dica, docblock da `DelegationPolicy` e `etapas-e-delegacao.md` §3.2 atualizados.                                                                                      |
| (média) Consentimento do vídeo citava um prazo que o diálogo não mostrava               | O estágio `intro` do `VideoRecorderDialog` repete o aviso (`step.notice`) e o prazo de guarda junto da caixa.                                                         |
| (média) "Confirmar delegação" valia na hora, sem dizer a consequência                   | `AlertDialog` antes do POST: o link do original deixa de valer, a pessoa indicada recebe convite e código próprios e registra o próprio aceite; não há como desfazer. |
| (baixa) Fuso do participante como identificador IANA                                    | Rótulo legível em PT-BR com deslocamento ("Horário de Brasília (UTC−3) — Sao Paulo"), grupo "Mais usados" no topo.                                                    |
| (baixa) Linha "Rascunho com pendência" sem dizer a pendência                            | `outcome_message` recebe a primeira pendência do documento ("Antes de enviar: há 1 campo sugerido aguardando revisão…").                                              |
| (baixa) Diálogo de delegação não avisava que a pessoa indicada também grava o vídeo     | `video_required` no estado da delegação (`GET sign.delegation.show`) e a frase `delegation.dialog_video` nos três idiomas.                                            |

### 10.4 Asserções existentes alteradas (com justificativa)

- `tests/Unit/Models/EnumCatalogTest.php`: a lista fechada de eventos de auditoria ganhou `delegation.voided` (T7: só acréscimo no fim do enum).
- `tests/Feature/Review/Phase3F/BulkReservationGapTest.php`: `pluck('outcome')` devolve instâncias do enum (cast), então a comparação com `['sent', 'sent']` nunca passaria, nem com o código certo. Agora compara `->value`.
- `tests/Feature/Review/Phase3F/VideoConsentRetentionTest.php`: o `toContain` do Pest é variádico, e a mensagem passada como segundo argumento virava outra agulha, o que fazia o teste falhar sempre. Ficou só a agulha `step.notice`.

Nenhuma outra asserção existente mudou.

### 10.5 Contratos novos

- Evento `delegation.voided` (payload: `delegation`, `from`, `reason` = `closed|already_acted|step_skipped`).
- `envelopes.settings.refusal_pending_close` (ULID do aprovador; interno).
- `GET sign.delegation.show` ganha `video_required: bool`; `BulkDetail.discard_after_days?: number`.
- `PUT envelopes.steps.update` com a flag desligada: 200 `{steps: {enabled: false}}` só para `enabled: false` com etapas gravadas; o resto continua 404.
- Config nova: `delegation.max_refused_attempts_per_recipient`, `field_anchors.test_time_budget_seconds`, `bulk_generation.unconfirmed_retention_days`. Comando `bulk-generations:prune-unconfirmed` (agendado).
- Textos públicos novos: `delegation.dialog_video` (pt_BR/en/es), `signer_mail.channel.{otp,invitation,reminder}` (pt_BR/en/es), placeholder do motivo da delegação (3 idiomas), diálogo "Confirmar a delegação para {nome}?", aviso de retenção do lote, motivo "Nenhuma etapa com signatário se aplicou…".

### 10.6 Verificação

Números reais depois das correções:

- Antes: `tests/Feature/Review/Phase3F` com 20 de 22 casos vermelhos; `test_review_anchor_line_quadratic.py` com 1 de 3 vermelho.
- `tests/Feature/Review/Phase3F`: **31 / 31** (22 dos achados + 9 de `ReviewFixesTest`), 161 asserções.
- Unit+Feature (`--testsuite=Unit,Feature`): **2.386 testes, 2.386 passaram**, 23.390 asserções.
- Navegador (`--testsuite=Browser`, sozinho e em série): **41 — 38 passaram, 3 pulados** (os mesmos da parte 1), 846 asserções.
- pdftool (`pytest -q`): **239 passaram** (236 + 3 de `test_review_wave_f_fixes.py`).
- PHPStan: 0 erros. Pint: passa. `npm run types:check`: 0 erros. `npm run check`: passa. `npm run build`: concluído.

### 10.7 Limitações que continuam

- A leitura da planilha continua síncrona na requisição (envio e pré-validação). O custo agora é limitado pelo orçamento de células, mas levar a leitura para a fila é trabalho de uma rodada futura.
- A trava da organização no limite diário de delegações não é provável em SQLite.
- O envio agendado de lote não carrega a reserva da confirmação: o disparo consome pela regra de sempre, podendo cair em outro ciclo.
- WhatsApp em inglês/espanhol exige template aprovado pela Meta por idioma. É pendência do proprietário.
- A estimativa de duração do WebM depende de `Cluster/Timecode`. Arquivo sem blocos legíveis cai no teto de bytes.
- O descarte automático de lote não confirmado fica registrado só no log da aplicação: o registro do lote é apagado, como no descarte manual.
