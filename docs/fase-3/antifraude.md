# Antifraude com revisão humana (Fase 3 §3.7 — P3-RISK)

> Identificadores em inglês; prosa em português. Classe **A** na viabilidade (§1.2): sem
> dependência externa. Nasce atrás da flag `antifraud`, **desligada** (roadmap T8).
> Fontes: `docs/roadmap.md` §3.7; `docs/fases-2-3-viabilidade.md` §1.2, §3.2 (onda H), §4.4 item 22,
> R9; `docs/integracoes/e-notariado-e-regulatorio.md` §5 (LGPD art. 20).

## 1. O que é, em uma frase

Um motor de regras **fechadas** sobre eventos que o domínio já grava produz **sinais** com
evidência mínima; a soma dos sinais muda o **estado de risco** da organização
(`normal` → `watch` → `restricted`); o único efeito automático possível é suspender o **envio de
novos envelopes**; uma **pessoa** da equipe decide cada caso, com motivo, e a organização pode
pedir revisão.

## 2. O que o sistema **nunca** faz

- Nunca invalida, altera ou apaga aceites, evidências, trilha (`audit_events`), versões de
  documento ou verificação pública.
- Nunca bloqueia leitura, download, aceite ou assinatura de envelopes **já enviados**, nem
  reenvios e lembretes deles, nem a criação e edição de rascunhos.
- Nunca libera (rebaixa o estado) sozinho: descer é sempre decisão humana.
- Nunca decide sem regra + evidência + revisor: toda transição e toda decisão ficam em
  `platform_audit_events`.
- Nunca executa regra vinda do usuário ou do banco: o catálogo é o enum
  `App\Services\Risk\RiskRule`; a configuração só ajusta números.
- Nunca guarda conteúdo de documento, e-mail, telefone, CPF/CNPJ ou IP completo no sinal; nunca
  usa selfie, vídeo ou qualquer dado sensível (LGPD art. 11) como sinal.
- Nunca mostra à organização os limiares e as pontuações (segredo comercial ressalvado no art. 20
  §1º), mas sempre mostra **o critério** de cada regra que a afetou.

## 3. Regras (conjunto fechado)

Todas disparam quando a contagem **atinge** o limiar (`>=`) e não disparam abaixo dele. Um sinal
da mesma regra, organização e sujeito dentro da mesma janela é **idempotente** (o envelope é só
contexto, não entra na chave). Valores padrão em `config/assinavelox.php` → `risk.rules`.

| `rule_code`                 | Fonte (evento já existente)                                | O que conta                                                                                                                                    | Padrão                                 | Pontos | Pode suspender envio?                        | Evidência gravada                                                                                                           |
| --------------------------- | ---------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------- | ------ | -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `new_org_send_spike`        | `audit_events` `envelope.sent`                             | envios da organização na janela, só se a conta tem até N dias                                                                                  | ≥ 30 em 24 h, conta ≤ 7 dias           | 40     | sim                                          | `sent_in_window`, `organization_age_days`, `window_minutes`, `threshold`                                                    |
| `delivery_failure_rate`     | `delivery_attempts` gravado como `failed`/`bounced`        | falhas ÷ tentativas de convite, reenvio e lembrete (OTP não conta)                                                                             | ≥ 30% com ≥ 20 tentativas em 24 h      | 30     | sim                                          | `attempts_in_window`, `failed_in_window`, `failure_rate`, `window_minutes`, `threshold`                                     |
| `code_brute_force`          | `audit_events` `challenge.failed` / `challenge.pin_failed` | falhas por **link** (participante) e por **IP** dentro da **mesma organização** (revisão I-3A: falhas de outras organizações não pontuam esta) | ≥ 10 por link ou ≥ 25 por IP em 60 min | 20     | **não** (a organização costuma ser a vítima) | `scope` (`link`/`ip`), `failures_in_window`, `recipient` (ULID) ou `ip_prefix` (/24 ou /48)                                 |
| `external_recipients_burst` | `audit_events` `envelope.sent` + `recipients`              | e-mails distintos (sem diferenciar maiúsculas) fora dos domínios dos membros ativos, em envelopes enviados na janela                           | ≥ 150 em 24 h                          | 50     | sim                                          | `distinct_external_recipients`, `envelopes_in_window`, `window_minutes`, `threshold`                                        |
| `payment_chargeback`        | criação de `payment_chargebacks`                           | contestações da organização na janela                                                                                                          | ≥ 1 em 90 dias                         | 40     | sim                                          | `chargebacks_in_window`, `window_days`, `payment` (ULID)                                                                    |
| `serial_signup`             | evento `Registered` do cadastro (Fortify)                  | cadastros da mesma rede (IP /24 ou /48) ou do mesmo **dispositivo declarado**                                                                  | ≥ 3 em 24 h                            | 30     | sim                                          | `scope` (`ip`/`device`), `signups_in_window`, `ip_prefix`                                                                   |
| `affiliate_self_referral`   | chamada do programa de afiliados (§3.10) ao contrato       | — (quem chama decide)                                                                                                                          | —                                      | 50     | **não**                                      | `affiliate`, `referral` (ULIDs), `match`, `same_user`, `same_ip`, `same_device`, `same_payment_method`, `same_email_domain` |

"Dispositivo declarado" é o identificador que o **cliente** envia no cadastro (cabeçalho
`X-Device-Id` ou campo `device_id`). A página de cadastro (fora da área P3-RISK) **ainda não
envia** esse valor; até ela enviar, só a contagem por rede funciona. O valor nunca é gravado —
só o HMAC.

## 4. Estado de risco e transições

`organizations.risk_status` ∈ `normal | watch | restricted` (+ `risk_status_changed_at`).

- **Pontuação pendente** = soma dos pontos dos sinais posteriores ao último caso decidido e dentro
  de `risk.lookback_days` (30).
- `normal → watch`: pontuação pendente ≥ `risk.thresholds.watch` (30).
- `* → restricted`: soma **só das regras que podem suspender envio** ≥ `risk.thresholds.restrict`
  (70) **e** `risk.auto_restrict` ligado. Com os padrões, nenhuma regra sozinha suspende o envio:
  é preciso combinação (ex.: pico de envios + rajada de destinatários externos = 90).
- Transição automática só **sobe**. Toda subida abre (ou reaproveita) um caso na fila e grava
  `risk.status_changed` (sem ator = automática) com `from`, `to`, `origin=automatic`, caso,
  regras e pontuação.
- Ao chegar em `restricted`, proprietários e administradores **ativos** recebem e-mail e aviso no
  sino (`OrganizationRiskNotice`) com o que foi suspenso, o que continua funcionando e o link
  para pedir revisão. Operadores não recebem.
- **Lista de confiança** (`risk.trusted_organizations`, ULIDs): sinais são gravados (servem para o
  relatório), mas a organização nunca muda de estado nem ganha caso automaticamente.
- **Modo observação** (`ASSINAVELOX_RISK_AUTO_RESTRICT=false`): o motor chega no máximo a `watch`.

### Onde o envio é barrado (decisão registrada)

No **serviço** `App\Services\Envelopes\Sending\SendEnvelope` (edição aditiva de uma linha, dentro
da transação e sob o lock do envelope), via `App\Services\Risk\SendingRestriction`, e **não** em
middleware. Motivo: o envio também sai do envio agendado (fila), do formulário público e da API;
um middleware só cobriria as rotas web. O erro é `SendingBlockedException` com
`errorCode = risk_restricted`, que todos esses caminhos já tratam:

| Caminho                                | Resultado                                                                            |
| -------------------------------------- | ------------------------------------------------------------------------------------ |
| Botão "Enviar"                         | flash de erro com a mensagem abaixo; envelope continua `ready`; plano não é debitado |
| API `POST /api/v1/envelopes/{id}/send` | 409 `sending-blocked` com `code = risk_restricted`                                   |
| Envio agendado                         | agendamento cancelado e remetente avisado com a mesma mensagem                       |
| Formulário público                     | tratado como os demais bloqueios de envio                                            |

Mensagem: _"O envio de novos documentos desta conta está suspenso até uma revisão de segurança da
equipe AssinaVelox. Documentos já enviados continuam disponíveis para leitura, assinatura e
download. Para saber o motivo e pedir a revisão, acesse /revisao-de-seguranca ou escreva para
{suporte}."_

## 5. Fila de revisão humana (painel interno)

Rotas no grupo `platform-admin` (flag desligada: 404; não admin: 403):

| Rota                                                                | Tela                                                                                                                                         |
| ------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /admin/antifraude` (`admin.risk.index`)                        | fila: organização, estado, regras, pontos, situação, pedido de revisão; filtros por situação e "só com pedido"                               |
| `GET /admin/antifraude/casos/{ulid}` (`admin.risk.show`)            | caso: sinais com regra, explicação e evidência; histórico explicável (`platform_audit_events`); pedido da organização; formulário de decisão |
| `POST /admin/antifraude/casos/{ulid}/decisao` (`admin.risk.decide`) | decisão                                                                                                                                      |
| `GET /admin/antifraude/precisao` (`admin.risk.precision`)           | relatório de precisão por regra, por mês                                                                                                     |

Decisões (`risk_reviews.decision`), sempre com **motivo obrigatório** (≥ 10 caracteres) e autor:

| Decisão                         | Caso fica   | Organização fica |
| ------------------------------- | ----------- | ---------------- |
| Liberar (`clear`)               | `cleared`   | `normal`         |
| Manter em observação (`watch`)  | `watching`  | `watch`          |
| Confirmar restrição (`confirm`) | `confirmed` | `restricted`     |

O roadmap previa `status ∈ open|cleared|confirmed`; `watching` foi acrescentado porque "manter em
observação" não é liberação nem confirmação e o relatório de precisão precisa distinguir os três.

A decisão grava `risk.review_decided` (ator = revisor; payload: caso, decisão, motivo, de/para,
regras, se houve pedido) e, se o estado mudar, `risk.status_changed` com `origin=review`. Ela
**fixa o intervalo** de sinais do caso (`through_signal_id`): sinais já decididos não voltam a
contar; um sinal novo abre um caso novo. Um caso decidido não pode ser decidido de novo.

Salvaguardas da decisão (revisão adversarial I-3A):

- **Só o que o revisor viu.** A página do caso envia `seen_through` (ULID do sinal mais recente
  exibido, obrigatório no POST). Se chegou sinal depois disso, a decisão é recusada ("o caso mudou,
  recarregue") — uma liberação nunca cobre evidência que ninguém revisou.
- **Separação de interesse.** Quem tem vínculo ativo com a organização do caso (dono,
  administrador ou operador) não decide o caso dela: a página mostra o aviso no lugar do formulário
  e o POST responde 403. O mesmo vale no programa de afiliados (a operadora não aprova, não muda a
  taxa, não suspende nem reativa a própria participação, nem libera indicação do próprio link).

## 6. Pedido de revisão pela organização (LGPD art. 20)

`GET /revisao-de-seguranca` (`risk.appeal.show`, qualquer membro) e `POST` (`risk.appeal.store`,
só owner/admin, 5 por hora). A página mostra o estado, **o critério de cada regra** que motivou a
análise (sem limiar nem pontuação), o prazo interno de resposta (`risk.appeal.response_days`) e o
formulário (20 a 2000 caracteres). Canal registrado: o pedido fica no caso
(`appeal_requested_at`, autor, texto) e na trilha (`risk.review_requested`, ator = quem pediu).
Sem estado `watch`/`restricted` não há o que pedir; um segundo pedido com o caso aberto é
recusado; depois de uma restrição confirmada, o pedido abre um caso novo (`trigger = appeal`).
Esse caso **herda o intervalo de sinais** do último caso que manteve o estado atual (restrição
confirmada ou observação mantida): o revisor julga o pedido com os mesmos sinais e evidências, e a
página da organização continua listando os critérios que explicam o estado (art. 20 §1º; revisão
adversarial I-3A — antes, o caso nascia vazio).
O e-mail de suporte continua como canal alternativo.

**No app** (roadmap §3.7, "mensagem clara"): com a conta em observação ou restrita, o
`HandleInertiaRequests` compartilha `risk` (só `status`, rótulo e o caminho da página — nunca
pontuação ou regra); com o envio suspenso, uma faixa persistente no topo do app ("Envio de novos
documentos suspenso… Ver motivo e pedir revisão") leva à página. O aviso de envio bloqueado
nomeia a página "Revisão de segurança da conta" em vez de um caminho de URL cru no toast. A página
informa o mínimo de 20 caracteres do pedido, ligado ao campo por `aria-describedby`.

A decisão de cada caso é comunicada por e-mail e sino aos proprietários e administradores.

## 7. Relatório de precisão

Por regra, no mês escolhido: sinais gravados, casos decididos em que a regra aparece
(confirmados, mantidos em observação, liberados), casos abertos e **precisão = confirmados ÷
(confirmados + liberados)** (nula sem decisões). Um caso com duas regras conta para as duas. Serve
para ajustar limiares e pontuações; mudar um valor é mudar `config`/`.env`, nunca código do usuário.

## 8. Dados, minimização e LGPD

| Tabela                                                | Conteúdo                                                                                                                                    | Observação                                                                              |
| ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| `risk_signals`                                        | organização, envelope (opcional), `rule_code`, `score`, `subject_key` (HMAC-SHA256), `fingerprint`, `evidence` (JSON mínimo), `occurred_at` | append-only (model recusa update/delete)                                                |
| `risk_reviews`                                        | caso: situação, origem, intervalo de sinais, pedido da organização, decisão, motivo, revisor                                                |                                                                                         |
| `risk_observations`                                   | `kind`, HMAC do sujeito, organização, data                                                                                                  | só para contar cadastros; podada após `observation_retention_days` (30) a cada gravação |
| `organizations.risk_status`, `risk_status_changed_at` | estado                                                                                                                                      | aditivo; padrão `normal`                                                                |

Minimização (`RiskEvidence::minimize`): lista fechada de chaves por regra; chaves com nome de dado
proibido (CPF, e-mail, telefone, documento, conteúdo, token, senha, PIN, código, nome, endereço)
são descartadas; só escalares de até 64 caracteres; valores que parecem e-mail, CPF/CNPJ, telefone
ou sequência de 8+ dígitos são descartados (ULIDs passam); `ip_prefix` é sempre truncado (/24
IPv4, /48 IPv6); o número de campos descartados fica em `_dropped`. O job de fila leva só IDs,
HMACs e o prefixo truncado (T10). O HMAC usa `ASSINAVELOX_RISK_SUBJECT_KEY` ou, na falta dela, uma
chave derivada da `APP_KEY`.

Base legal pretendida: **legítimo interesse** (segurança da plataforma e prevenção a fraude),
pendente da avaliação formal do proprietário (viabilidade §4.4 item 22).

## 9. Contrato para outros módulos

```php
App\Services\Risk\RiskSignals::record(
    string $ruleCode,
    App\Models\Organization $organization,
    array $evidence,
    ?App\Models\Envelope $envelope = null,
    ?string $subjectKey = null,
): App\Models\RiskSignal
```

- `$ruleCode` fora do catálogo → `InvalidArgumentException`.
- `$evidence` é minimizada (seção 8); para `affiliate_self_referral` as chaves aceitas estão na
  tabela da seção 3.
- `$subjectKey` é o sujeito **bruto** (ex.: `referral:{ulid}`); só o HMAC é gravado.
- Flag desligada → devolve um `RiskSignal` **não persistido** (`exists === false`).
- Mesmo sinal na mesma janela → devolve o já gravado.
- Uma falha na reavaliação do estado é registrada em log e não chega a quem chamou.
- Estático; também funciona por instância (`app(RiskSignals::class)->record(...)`).

## 10. Configuração

| Variável                                                                                | Padrão                       | Efeito                                |
| --------------------------------------------------------------------------------------- | ---------------------------- | ------------------------------------- |
| `ASSINAVELOX_FEATURE_ANTIFRAUD`                                                         | `false`                      | liga tudo (plataforma, sem plano)     |
| `ASSINAVELOX_RISK_AUTO_RESTRICT`                                                        | `true`                       | `false` = modo observação             |
| `ASSINAVELOX_RISK_WATCH_SCORE` / `_RESTRICT_SCORE`                                      | 30 / 70                      | limiares de estado                    |
| `ASSINAVELOX_RISK_LOOKBACK_DAYS`                                                        | 30                           | janela da pontuação pendente          |
| `ASSINAVELOX_RISK_SUBJECT_KEY`                                                          | (derivada da APP_KEY)        | chave do HMAC dos sujeitos            |
| `ASSINAVELOX_RISK_OBSERVATION_RETENTION_DAYS`                                           | 30                           | poda de `risk_observations`           |
| `ASSINAVELOX_RISK_TRUSTED_ORGANIZATIONS`                                                | vazio                        | ULIDs separados por vírgula           |
| `ASSINAVELOX_RISK_APPEAL_MAX_MESSAGE` / `_RESPONSE_DAYS`                                | 2000 / 5                     | pedido de revisão                     |
| `ASSINAVELOX_RISK_{SPIKE,DELIVERY,BRUTE_FORCE,BURST,CHARGEBACK,SIGNUP,SELF_REFERRAL}_*` | ver `config/assinavelox.php` | janela, limiar e pontos de cada regra |

## 11. Com a flag desligada

Os listeners saem na primeira linha (nenhuma consulta, nenhum job); `RiskSignals::record` não
grava; `SendingRestriction` não consulta nada — mesmo uma organização que ficou `restricted`
enquanto a flag esteve ligada volta a enviar; painel e pedido de revisão respondem 404. Nenhum
teste anterior mudou.

## 12. O que falta para ligar em produção

1. **Pré-requisito do roadmap**: Fase 2 em produção por um ciclo de cobrança.
2. **Decisão jurídica** (viabilidade §4.4 item 22): avaliação de legítimo interesse, RIPD e prazo
   interno de resposta às revisões (hoje `response_days = 5` é só um valor de tela). Incluir o
   tratamento na Política de Privacidade.
3. **Integração de interface fora da área P3-RISK**: item "Antifraude" em
   `resources/js/components/app-sidebar.tsx` (grupo admin, apontando para `admin.risk.index`) e
   a chave `antifraud` em `HandleInertiaRequests::features()`; link para `/revisao-de-seguranca`
   no aviso de envio bloqueado da tela do envelope (hoje a mensagem traz o caminho em texto).
   Até lá as telas funcionam por URL e têm abas próprias.
4. **Cadastro**: a página de registro enviar o dispositivo declarado (`X-Device-Id`), se o
   produto quiser a contagem por dispositivo.
5. **Operação**: worker de fila ativo (a avaliação roda em fila, depois do commit); calibrar em
   **modo observação** (`AUTO_RESTRICT=false`) por pelo menos um ciclo, acompanhando o relatório
   de precisão, antes de permitir a suspensão automática; definir a lista de confiança dos
   clientes de alto volume.
6. **Tráfego real**: os limiares padrão são estimativas; ajustá-los com o relatório.

> **Integração I-3A (2026-09-14).** Feito: a chave `antifraud` entrou em `HandleInertiaRequests::features()` (desligada)
> e o painel interno ganhou o item "Antifraude", visível só com a flag. O ponta a ponta
> `tests/Feature/EndToEnd/Phase3PartOneTest.php` leva uma organização a `restricted` por sinais reais, confirma que um
> envelope NOVO não é enviado (`risk_restricted`) e que o envelope já enviado conclui. O `DemoOrganizationSeeder` grava um
> sinal de taxa de falha de entrega (só contagens do catálogo) que deixa a Horizonte em `watch` com um caso aberto.
> Continua pendente: `affiliate_self_referral` lista `same_email_domain` no catálogo, mas o minimizador descarta toda
> chave com "email" no nome — decidir se a chave sai do catálogo ou muda de nome.

## 13. Testes

`tests/Feature/Phase3/Risk/` (eventos sintéticos):

- `RuleThresholdsTest` — cada regra dispara no limiar e não abaixo (pico em conta nova e conta
  antiga, destinatários externos com domínio da equipe e maiúsculas, força bruta por link e por
  rede, falha de entrega com volume mínimo e OTP ignorado, contestação, cadastro em série por rede
  e por dispositivo pelo formulário real, autoindicação pelo contrato).
- `StateTransitionsTest` — watch no limiar, nada abaixo, restricted por combinação com aviso só a
  owner/admin, regras limitadas a watch, modo observação, lista de confiança, nunca rebaixa,
  idempotência.
- `SendingRestrictionTest` — restricted barra o envio (flash, serviço), watch não barra; leitura,
  aceite, assinatura e download de envelope já enviado seguem; aceites e trilha anteriores intactos.
- `ReviewQueueTest` — painel só do platform admin; detalhe com evidência e histórico; motivo
  obrigatório; liberar/observar/confirmar com trilha; caso decidido não é redecidido; sinais
  liberados não voltam a contar; pedido de revisão (canal, sem limiares, sem duplicidade, só
  owner/admin, caso novo após confirmação); relatório de precisão.
- `EvidencePrivacyTest` — minimização (CPF, CNPJ, telefone, e-mail, conteúdo, IP), ULID mantido,
  HMAC do sujeito, sinais das regras sem dado proibido, fila sem IP completo, catálogo fechado,
  append-only.
- `FlagOffTest` — sem flag: nenhum job, sinal, observação ou caso; `record` não persiste; conta
  `restricted` volta a enviar; rotas 404.
