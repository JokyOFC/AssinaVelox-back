# Programa de afiliados — Fase 3 §3.10 (P3-AFF)

> Onda H (risco e receita), depois do antifraude (§3.7). Classe **A** na viabilidade: **o sistema calcula, não paga**.
> Flag `features.affiliates` — **da plataforma** (só o interruptor global; o programa é da operadora, não de um plano) — **nasce desligada**.
> Documentos-fonte: `docs/roadmap.md` §3.10 e §1 (T7, T8, T10); `docs/fases-2-3-viabilidade.md` §3.2 (onda H), §4 itens 27 e 30; `docs/integracoes/e-notariado-e-regulatorio.md` (LGPD art. 20); `docs/fase-2/pagamentos-e-fiscal.md` (estados do pagamento).

## 1. O que existe

| Parte | Onde | Resumo |
| --- | --- | --- |
| Afiliados | `affiliates`, `App\Models\Affiliate`, `App\Services\Affiliates\AffiliateProgram` | Candidatura pelo portal → aprovação pela operadora (código único de 8 caracteres, sem 0/O/1/I) → suspensão/reativação. Taxa em **pontos-base** por afiliado. Dados de repasse (chave PIX + titular) **cifrados em repouso** (`encrypted:array`) e **nunca exibidos por inteiro**. |
| Atribuição | `referrals`, `Attribution`, `ReferralLinkController`, `AttributeReferralOnRegistration` | Link `/indicacao/{código}` → cookie de atribuição → cadastro. `referrals.organization_id` **UNIQUE**: uma organização é atribuída uma única vez. |
| Autoindicação e contas duplicadas | `Attribution::selfReferralReasons/duplicateReasons`, `AffiliateRiskSignals` | Autoindicação → indicação `rejected`, **sem comissão**, sinal de risco. Possível conta duplicada → `held` (comissão segurada até revisão humana), sinal de risco. |
| Comissões | `commissions`, `CommissionLedger`, `SyncCommissionsOnPaymentStatus` | Só sobre pagamentos **aprovados**; centavos com moeda; pendentes até o prazo de estorno; revertidas em estorno/contestação (antes **e** depois da aprovação); idempotentes por pagamento. |
| Repasses | `payout_batches`, `PayoutBatches` | Lotes montados e marcados como pagos **manualmente** (quem, quando, referência externa); CSV protegido contra fórmula e com repasse mascarado. |
| Trilha | `affiliate_events`, `AffiliateTrail` | Append-only (só `INSERT`): candidatura, aprovação, recusa, suspensão, **alteração de taxa (antes → depois, motivo, quem)**, dados de repasse atualizados (sem o valor), atribuição, pedido e resultado de revisão humana, lotes (montado, pago, cancelado, exportado). |
| Telas | `resources/js/pages/affiliates/index.tsx`, `resources/js/pages/admin/affiliates/{index,show}.tsx`, `resources/js/pages/admin/affiliates/payouts/{index,show}.tsx`, `resources/js/components/affiliates/*` | Portal do afiliado e painel interno, PT-BR, shadcn/ui. |
| Tarefa diária | `affiliates:settle` (`App\Services\Affiliates\Console\SettleCommissionsCommand`), agendada às 05:20 | Varredura idempotente dos pagamentos das organizações indicadas + aprovação das pendentes vencidas. |

Migrations (aditivas, MySQL-compatíveis): `2026_09_11_150401` (`affiliates`), `150402` (`referrals`), `150403` (`payout_batches`), `150404` (`commissions`), `150405` (`affiliate_events`). Todas as chaves estrangeiras para `users`, `organizations` e `payments` são `nullOnDelete`: a exclusão de conta e o expurgo de organização continuam funcionando e o razão financeiro não some.

## 2. Rotas

| Método | URI | Nome | Proteção |
| --- | --- | --- | --- |
| GET | `/indicacao/{código}` | `affiliates.link` | pública, `throttle:public` |
| GET | `/afiliados` | `affiliates.index` | `auth` + `verified` (sem organização) |
| POST | `/afiliados` | `affiliates.apply` | idem + `throttle:10,1` |
| PUT | `/afiliados/repasse` | `affiliates.payout.update` | idem + `password.confirm` |
| POST | `/afiliados/indicacoes/{indicação}/revisao` | `affiliates.referrals.review` | idem; só a própria indicação (senão 404) |
| GET | `/afiliados/comissoes/exportar` | `affiliates.commissions.export` | idem (CSV) |
| GET | `/admin/afiliados` | `admin.affiliates.index` | `platform-admin` |
| GET | `/admin/afiliados/{afiliado}` | `admin.affiliates.show` | `platform-admin` |
| POST | `/admin/afiliados/{afiliado}/aprovar` · `/suspender` · `/reativar` | `admin.affiliates.approve` · `suspend` · `reactivate` | `platform-admin` + `password.confirm` |
| POST | `/admin/afiliados/{afiliado}/recusar` | `admin.affiliates.reject` | `platform-admin` |
| PUT | `/admin/afiliados/{afiliado}/taxa` | `admin.affiliates.rate.update` | `platform-admin` + `password.confirm` + motivo |
| POST | `/admin/afiliados/indicacoes/{indicação}/revisar` | `admin.affiliates.referrals.review` | `platform-admin` + justificativa |
| GET/POST | `/admin/afiliados/lotes` | `admin.affiliates.payouts.index` / `store` | `platform-admin` |
| GET | `/admin/afiliados/lotes/{lote}` · `/exportar` | `admin.affiliates.payouts.show` / `export` | `platform-admin` |
| POST | `/admin/afiliados/lotes/{lote}/pago` | `admin.affiliates.payouts.paid` | `platform-admin` + `password.confirm` |
| POST | `/admin/afiliados/lotes/{lote}/cancelar` | `admin.affiliates.payouts.cancel` | `platform-admin` + motivo |

Com a flag desligada **todas** respondem 404.

## 3. Atribuição

1. **Clique.** `/indicacao/{código}` responde **igual** para código válido, desconhecido, suspenso ou recusado (redireciona para o cadastro), para a URL não servir de oráculo de códigos. Só o código de um afiliado aprovado grava o cookie `av_affiliate_ref`, com o código e a **hora do clique** (`{"c": "...", "t": unix}`). O cookie é cifrado **e autenticado** pelo `EncryptCookies` do Laravel (equivale a cookie assinado), `HttpOnly`, `SameSite=Lax`, `Secure` conforme `session.secure`.
2. **Janela.** `attribution_window_days` (padrão **60**, o exemplo do roadmap). A validade é conferida pela hora gravada no cookie, não pela expiração do navegador.
3. **Cadastro.** O listener `AttributeReferralOnRegistration` (evento `Registered` do Fortify, descoberto automaticamente) atribui a organização **criada pelo próprio cadastro** (`organizations.created_by_user_id` = novo usuário). Cadastro por convite não gera indicação. É uma captura **aditiva**: `CreateNewUser` **não foi alterado**; o listener roda depois do cadastro, não faz nada com a flag desligada e nunca lança. O cookie é removido na resposta do cadastro.
4. **Uma única vez.** `referrals.organization_id` é UNIQUE. Uma indicação barrada também ocupa a vaga, para que a organização não seja "reindicada" por outro link.
5. **Período de comissão.** `referrals.expires_at` = atribuição + `commission_months` (padrão **12**; `0` = sem prazo). Pagamentos aprovados depois disso não geram comissão.

### Regra de toque: **primeiro toque** (padrão)

O roadmap e a viabilidade deixam a janela e a regra em aberto (viabilidade §4 item 30). Escolhemos **primeiro toque dentro da janela** — um cookie ainda válido **não** é substituído pelo link de outro afiliado — porque:

- é coerente com `referrals.organization_id UNIQUE` do roadmap, que já faz "vale o primeiro" no nível da organização;
- reduz o sequestro de atribuição por _cookie stuffing_ (um link aberto por último, às vezes sem o usuário perceber, não toma a indicação de quem realmente apresentou o produto);
- é a leitura mais conservadora enquanto não há contrato com os afiliados.

Vencida a janela, um novo clique grava um novo cookie. `last_touch` existe como configuração (`ASSINAVELOX_AFFILIATES_ATTRIBUTION_MODEL=last_touch`) se o proprietário decidir o contrário.

## 4. Autoindicação e contas duplicadas (com o antifraude §3.7)

Regras avaliadas no cadastro (cada código fica em `referrals.block_reasons`):

| Código | Regra | Efeito |
| --- | --- | --- |
| `same_user` | o usuário é o próprio afiliado | autoindicação → `rejected` |
| `same_email` | e-mail igual ao do afiliado (normalizado: minúsculas, sem `+tag`, sem pontos no Gmail) | autoindicação → `rejected` |
| `same_domain` | mesmo domínio **corporativo** (domínios públicos de `public_email_domains` não contam) | autoindicação → `rejected` |
| `same_ip` | IP do cadastro igual ao IP da candidatura do afiliado ou ao último IP de uso do portal, **visto dentro da janela** | autoindicação → `rejected` |
| `duplicate_ip` | IP do cadastro igual ao de outra indicação do mesmo afiliado dentro da janela | possível conta duplicada → `held` |

- **Nenhum IP é guardado em claro**: só HMAC-SHA256 com a `APP_KEY` (`IpFingerprint`). E-mails de indicados não são copiados para as tabelas do programa.
- **Sinal de risco**: `App\Services\Risk\RiskSignals::record('affiliate_self_referral', $organizaçãoIndicada, $evidência, null, 'affiliate:{ulid}')` via `AffiliateRiskSignals`. A evidência só leva as chaves que o antifraude aceita para essa regra (`affiliate`, `referral`, `match`, `same_user`, `same_ip`, `same_email_domain`) — ULIDs e booleanos. Conta duplicada entra na mesma regra com `match=duplicate_ip` (o catálogo fechado do antifraude não tem regra própria para isso). A ponte nunca lança.
- **O que o programa faz sozinho**: só barra (`rejected`) ou segura (`held`) a **comissão**. Nunca invalida aceite, evidência ou envelope; qualquer ação sobre a organização é decisão do antifraude (no máximo restringir **envio** até revisão humana).
- **Revisão humana (LGPD art. 20)**: o portal mostra ao afiliado a regra que barrou/segurou a indicação e oferece "Pedir revisão humana". No painel, a fila "Indicações para revisão" permite **liberar** (→ `active`; comissões de pagamentos já aprovados são calculadas na hora e seguem o prazo normal) ou **manter como não elegível** (→ `rejected`; pendentes revertidas e aprovadas/pagas estornadas no próximo lote). Toda revisão exige justificativa e fica na trilha com a regra, a decisão e o revisor.

## 5. Comissões

Gancho `eloquent.saved` de `Payment` → `SyncCommissionsOnPaymentStatus` → `CommissionLedger::syncPayment()` **depois do commit** da transação da cobrança, sem nunca lançar. A varredura diária `affiliates:settle` reaplica tudo (idempotente) e cobre qualquer transição que não tenha passado pelo gancho.

| Estado do pagamento | Sem comissão | Comissão pendente | Comissão aprovada / paga |
| --- | --- | --- | --- |
| `approved` | cria `pending` (se elegível) | recalcula sobre o líquido (estorno parcial) | estorno parcial → `adjustment` com a diferença |
| `refunded` | — | → `reversed` | cria `reversal` **negativa**, `approved`, sem lote (entra no próximo) |
| `charged_back` | — | → `reversed` | cria `reversal` **negativa**, `approved`, sem lote |
| `in_mediation`, `pending`, … | — | continua pendente (não aprova) | — |

- **Elegível**: indicação não `rejected`; afiliado `approved`; pagamento com `paid_at` entre a atribuição e `expires_at`; ambiente `production` (sandbox só com `include_sandbox_payments`).
- **Cálculo**: base = `amount_cents − refunded_cents`; comissão = ⌊base × `rate_bp` ÷ 10 000⌋ (para baixo, em centavos). A taxa usada é a do afiliado **no momento** (gravada em `commissions.rate_bp`); alterar a taxa vale para os próximos pagamentos.
- **Prazo de estorno**: `available_at = paid_at + approval_hold_days` (padrão **30**). `approveDue()` aprova quando o prazo passou, o pagamento continua `approved` e a indicação está `active`.
- **Idempotência**: `commissions.idempotency_key` UNIQUE — `payment:{id}:commission` (no máximo uma comissão por pagamento; equivale ao `payment_id UNIQUE` do roadmap, que precisou virar chave porque um estorno após a aprovação gera uma segunda linha do mesmo pagamento), `payment:{id}:reversal`, `payment:{id}:adjust:{líquido}`. Linhas travadas (`FOR UPDATE`) por pagamento; corrida de inserção cai na chave única e é ignorada.

## 6. Repasses — o sistema calcula, não paga

- **Montar lote** (moeda + data de corte): entram lançamentos `approved` sem lote até o corte — comissões, ajustes e estornos negativos. Por afiliado, só entra quem está **aprovado** (suspenso fica retido), tem **dados de repasse** e saldo líquido ≥ `min_payout_cents` (padrão R$ 50,00). O resto (inclusive saldo negativo, que abate as próximas comissões) fica para o próximo lote.
- **Marcar como pago**: manual, com **senha confirmada**, data do repasse (não futura) e **referência externa** obrigatória (ex.: identificador E2E do PIX). Grava `paid_by_user_id`, `paid_at`, `marked_paid_at`; os lançamentos viram `paid`. Nenhuma chamada a banco, PIX ou gateway sai do sistema.
- **Cancelar** (só lote aberto, com motivo): os lançamentos voltam para o próximo lote.
- **CSV** (`;`, BOM UTF-8): uma linha por afiliado, dados de repasse **mascarados**, células com `= + - @ TAB CR` neutralizadas por `App\Support\Csv` (CWE-1236). Cada exportação fica na trilha.
- **Dados de repasse nunca inteiros**: nem nas telas, nem no CSV, nem na trilha, nem em log. Hoje, portanto, a operadora precisa obter a chave completa por um canal fora do sistema — ver decisão pendente 6 em §8.

## 7. Telas

- **Portal do afiliado** (`/afiliados`, `pages/affiliates/index.tsx`): regras do programa e candidatura (chave PIX + titular + aceite dos termos); depois de aprovado, código e link com "Copiar", comissões por estado (pendentes, a receber, pagas, revertidas), indicados (**só o nome da organização**, datas, estado e — quando barrada ou segurada — a regra e o botão "Pedir revisão humana"), extrato de lançamentos com filtro por estado e exportação CSV, dados de repasse mascarados com atualização protegida por senha.
- **Painel interno** (`/admin/afiliados`): KPIs, lista de afiliados (pendentes primeiro) com aprovar (taxa sugerida editável), recusar, suspender, reativar; fila de revisão humana de indicações; detalhe do afiliado com **alterar taxa (motivo + senha)** e a trilha; **lotes de repasse** (prévia do que entraria agora, montar, detalhe, marcar como pago, cancelar, exportar CSV).
- **Navegação**: `resources/js/components/app-sidebar.tsx` está fora da área deste item, então as telas ainda **não têm entrada no menu** (acesso pela URL). Ver §9.

## 8. Decisões pendentes do proprietário (condição para ligar em produção)

1. **Taxas**: taxa padrão (hoje 10% = 1000 bp, provisória), teto (50%), se haverá faixas por volume, se a taxa vale sobre o valor bruto ou líquido de tributos da operadora (hoje: sobre o valor cobrado menos estornos).
2. **Janela de atribuição** (hoje 60 dias) e **regra de toque** (hoje primeiro toque — §3). Viabilidade §4 item 30.
3. **Prazo de estorno / aprovação da comissão** (hoje 30 dias). O Mercado Pago aceita reembolso até 180 dias e contestações podem chegar depois; um prazo curto aumenta o volume de estornos negativos compensados em lotes seguintes.
4. **Período de comissão** por organização indicada (hoje 12 meses) e se renovações anuais contam.
5. **Tratamento tributário dos repasses** (viabilidade §4 item 27): natureza do pagamento (comissão/intermediação a PF ou PJ), retenções (IRRF, INSS de contribuinte individual, ISS), exigência de nota fiscal do afiliado PJ, recibo de PF, informes anuais. O sistema **não calcula tributo** nenhum: o valor do lote é a comissão bruta.
6. **Contrato do programa**: texto dos termos (a versão aceita fica em `affiliates.terms_version`; hoje `afiliados-rascunho-2026-09`), regras de marca e publicidade, o que acontece com comissões de afiliado suspenso ou encerrado (hoje: calculadas, mas **retidas** fora dos lotes enquanto suspenso), saldo mínimo (hoje R$ 50,00), periodicidade dos lotes, e **como a operadora obtém a chave PIX completa** para pagar (hoje o sistema nunca a exibe; alternativa possível: revelação pontual com senha + trilha, a decidir).
7. **LGPD**: base legal para os dados do afiliado (execução de contrato) e para o HMAC de IP (legítimo interesse — prevenção a fraude), prazo de retenção de `affiliates`, `referrals` e `affiliate_events`, texto de transparência sobre as regras automáticas e o canal de revisão (art. 20).
8. **Reembolso parcial depois da aprovação** gera ajuste negativo proporcional — confirmar que é a regra desejada.

## 9. Condições de ativação em produção

1. **Registrar o provider** `App\Services\Affiliates\AffiliatesServiceProvider` em `bootstrap/providers.php` (fora da área deste item; uma linha). Sem ele, o gancho de pagamento e o comando `affiliates:settle` **não existem** — a atribuição no cadastro funciona (listener descoberto), mas nenhuma comissão é calculada. Os testes registram o provider explicitamente (`registerAffiliatesProvider()`), então continuam válidos depois dessa linha.
2. **Entrada no menu** do painel interno ("Afiliados") e do app ("Programa de afiliados") em `app-sidebar.tsx` (fora da área), condicionada a uma prop de flag — hoje a flag não é exposta em `HandleInertiaRequests::features()` (também fora da área).
3. Fase 2 em produção por um ciclo de cobrança (pré-requisito do roadmap para a Fase 3) e a flag do antifraude (§3.7) avaliada — os sinais de autoindicação só são **gravados** com ela ligada.
4. Decisões 1–7 de §8 registradas; texto dos termos publicado e `ASSINAVELOX_AFFILIATES_TERMS_VERSION` apontando para ele.
5. `ASSINAVELOX_AFFILIATES_INCLUDE_SANDBOX=false` (padrão) em produção.
6. Rodar a suíte `tests/Feature/Phase3/Affiliates` também em MySQL (roadmap §5: migrations aditivas).
7. Ligar: `ASSINAVELOX_FEATURE_AFFILIATES=true`.

## 10. Configuração (`config('assinavelox.affiliates')`)

| Chave | Env | Padrão |
| --- | --- | --- |
| `cookie_name` | `ASSINAVELOX_AFFILIATES_COOKIE` | `av_affiliate_ref` |
| `attribution_window_days` | `ASSINAVELOX_AFFILIATES_WINDOW_DAYS` | 60 |
| `attribution_model` | `ASSINAVELOX_AFFILIATES_ATTRIBUTION_MODEL` | `first_touch` |
| `commission_months` | `ASSINAVELOX_AFFILIATES_COMMISSION_MONTHS` | 12 (0 = sem prazo) |
| `approval_hold_days` | `ASSINAVELOX_AFFILIATES_HOLD_DAYS` | 30 |
| `default_rate_bp` / `max_rate_bp` | `ASSINAVELOX_AFFILIATES_DEFAULT_RATE_BP` / `_MAX_RATE_BP` | 1000 / 5000 |
| `min_payout_cents` | `ASSINAVELOX_AFFILIATES_MIN_PAYOUT_CENTS` | 5000 |
| `include_sandbox_payments` | `ASSINAVELOX_AFFILIATES_INCLUDE_SANDBOX` | `false` |
| `currencies` | — | `['BRL']` |
| `terms_version` | `ASSINAVELOX_AFFILIATES_TERMS_VERSION` | `afiliados-rascunho-2026-09` |
| `public_email_domains` | — | lista de provedores públicos |

## 11. Testes (`tests/Feature/Phase3/Affiliates`)

| Arquivo | Cobre |
| --- | --- |
| `AttributionTest.php` | cookie só para código aprovado e resposta igual para os demais; dentro e fora da janela (e janela configurável); primeiro toque × último toque; cookie vencido substituído; organização atribuída uma única vez (serviço e UNIQUE no banco); sem cookie, convite e organização alheia; afiliado suspenso depois do clique |
| `SelfReferralTest.php` | mesmo e-mail (+tag), mesmo domínio corporativo × domínio público, mesmo IP dentro × fora da janela, mesmo usuário; IP nunca em claro; conta duplicada segurada até revisão; pedido de revisão pelo afiliado; revisão que libera e que rejeita (reversão e estorno negativo); ponte real do antifraude não lança; revisão exige justificativa e equipe da plataforma |
| `CommissionTest.php` | só aprovado gera, em centavos e moeda, prazo gravado; pendente → aprovada só depois do prazo (serviço e comando); prazo configurável; estorno e contestação antes e depois da aprovação (inclusive depois de paga); idempotência por pagamento; `in_mediation` segura; estorno parcial (pendente e aprovada); taxa do momento; período, organização não indicada, afiliado suspenso, sandbox; caminho real `SyncPaymentFromGateway`; indicação segurada não aprova |
| `PayoutTest.php` | montagem por afiliado com exclusões (suspenso, sem dados, pendente, abaixo do mínimo); estorno abate; baixa manual com senha, referência, data e trilha; lote pago não muda; cancelamento devolve lançamentos; CSV com BOM, fórmula neutralizada e repasse mascarado; acesso só da equipe; telas |
| `ProgramTest.php` | candidatura com dados cifrados em repouso; validação de chave/CPF; mascaramento; aprovação com código e taxa, suspensão com motivo e trilha; taxa com senha, motivo, teto e trilha antes → depois; portal sem dados pessoais de indicados; atualização de repasse sem valor na trilha; CSV do afiliado; isolamento entre usuários |
| `FlagOffTest.php` | flag nasce desligada; todas as rotas 404; cadastro com cookie idêntico ao de sempre; pagamento aprovado sem comissão e comando sem efeito |
