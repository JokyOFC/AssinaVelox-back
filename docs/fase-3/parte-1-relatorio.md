# Fase 3, parte 1 (ondas E e H) — relatório de integração I-3A

> Data: 2026-09-14. Integração dos entregáveis P3-EXT (assinatura por componente local, §3.4), P3-GOV (devolução do
> PDF assinado no portal gov.br, §3.5), P3-LTV (PAdES de longo prazo, §3.6), P3-RISK (antifraude, §3.7), P3-AFF
> (afiliados, §3.10), P3-PIN (pino de IP em HTTPS) e P3-FRONT. Nenhum commit foi feito.
> Fontes: `docs/roadmap.md` §1 e §3, `docs/fases-2-3-viabilidade.md` §3.2, §4, §5, §6 e §7, e os documentos de cada item
> em `docs/fase-3/`.

## 1. Resumo

Tudo nasce **desligado**. Com as flags desligadas nada muda, e a suíte existente segue verde. A única asserção existente
alterada é a lista fechada de chaves de `features` em `SharedPropsTest`: ganhou as quatro chaves novas da plataforma,
todas `false`, como cada onda da Fase 2 fez (roadmap T8).

A peça que faltava para a parte 1 funcionar como produto era a **devolução gov.br entrar na finalização**. O P3-GOV deixou
isso atrás de uma trava porque os arquivos eram de outra área. Agora está integrada: um envelope com assinatura por
componente local, devolução pelo portal, A1 e operadora conclui, e cada assinatura aparece com o próprio rótulo (T1).

O ponta a ponta encontrou **um defeito real no longo prazo**: o re-carimbo desligava a própria renovação em qualquer
arquivo com assinaturas de participantes. Está corrigido e coberto por teste (§3.3).

## 2. Números

| Verificação                                                                 | Resultado                                                                       |
| --------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Unit + Feature, antes das mudanças da integração (só com o smoke corrigido) | 2.101 / 2.101                                                                   |
| Unit + Feature, depois da integração                                        | 2.106 / 2.106 (19.256 asserções) — +2 ponta a ponta, +3 do estado LTV           |
| Suíte de navegador (em série)                                               | 36 passam, 3 pulados (os pulos já existentes de PDF.js/`skip`), 0 falhas        |
| pdftool (`pytest -q`)                                                       | 182 / 182 (os 139 anteriores + `test_external`, `test_incremental`, `test_ltv`) |
| phpstan (projeto inteiro)                                                   | 0 erros (rodada final, depois de todas as mudanças)                             |
| pint `--test`                                                               | passa                                                                           |
| `npm run types:check`                                                       | 0 erros                                                                         |
| `npm run check` (formatação + lint, 351 arquivos)                           | passa                                                                           |
| `npm run build`                                                             | passa                                                                           |
| `route:list`                                                                | 328 rotas, sem erro                                                             |
| Teste de vocabulário (T1/T3)                                                | 3 / 3                                                                           |
| Pastas-alvo depois da integração                                            | Phase3 173 · A1 15 · Finalization 24 · Verification 49 · EndToEnd 19 · Ltv 22   |

## 3. Correções e integrações feitas

### 3.1 Devolução gov.br na finalização (docs/fase-3/gov-br.md §8, itens 1–5)

- `ParticipantSignatureStage` recebe `GovBrReturnStage`. A mudança fica toda nele, e o `EnvelopeFinalizer` não mudou:
    - pedido gov.br conta como pedido ativo;
    - `awaiting()` roda as duas esperas, sem curto-circuito;
    - `signatureCount()` soma as devoluções aceitas, que entram na quantidade esperada da cadeia;
    - `resetDocument()` reabre as devoluções quando a base é refeita.
- `SignatureStatus` ganhou `participant_govbr` e `participant_external_unverified`, com rótulos próprios:
    - só gov.br: `participant_govbr` quando **todas** têm a cadeia validada até a âncora fixada, senão
      `participant_external_unverified` (nunca dito gov.br);
    - componente local **e** devolução no mesmo arquivo: `participant_external`, o valor mais genérico ("feita fora da
      plataforma"). A lista por assinatura traz o meio de cada uma, e o texto do selo e do detalhe cita os dois meios.
- Listas das páginas: `GovBrSignatureViews` põe a devolução na lista `participant_signatures` das evidências e da
  verificação pública, no mesmo formato. Na página pública vão o nome mascarado, sem CPF, série, impressão digital ou
  e-mail. `ExternalSignatureNarrative` descreve a devolução.
- Front: tipos, rótulos, selo (`seal.ts`), painel de conclusão do detalhe e lista de assinaturas conhecem os dois
  valores novos.
- A trava `assinavelox.govbr.finalizer_integration` continua existindo, com padrão `false`, e agora pode ser ligada.
- **Continua pendente** (itens 6–9 do §8):
    - o A1 não espera uma reserva gov.br vigente: o participante gov.br perde a reserva com `base_changed`, e isso é seguro;
    - os eventos próprios em `audit_events`;
    - o estado gov.br dobrado em `SignerPageProps`;
    - a convergência das duas reservas.

### 3.2 Teste de fumaça e pendências herdadas

- `AllGetRoutesTest`: as entradas de `sign.govbr.show` e `sign.govbr.download` (404 com a flag desligada). As do
  P3-RISK já tinham entrado pelo P3-AFF; conferi.
- `routes/web.php`: os controllers do P3-EXT viraram `use`, e o pint passa.
- `PinnedConnectionTest.php`: o comentário do topo agora aponta o `HttpsPinTest` para o caminho HTTPS.
- `AffiliatesServiceProvider` registrado em `bootstrap/providers.php`. O gancho e o comando não fazem nada com a flag
  desligada, e a suíte inteira seguiu verde.
- `HandleInertiaRequests::features()` ganhou `antifraud`, `affiliates`, `pades_ltv` e `pades_ltv_advertise`, todas
  desligadas. `a3_signing` e `govbr_return` ficam de fora de propósito: a página pública descobre os recursos pelo
  `GET` de cada um, como o A1.
- Menu:
    - "Programa de afiliados" (conta);
    - "Antifraude" e "Afiliados" (painel interno);
    - todos visíveis só com a flag.
- Evidências: `ltv` (`LtvState::view`) e o histórico de resumos só com `pades_ltv` ligada. A verificação pública e o
  `checkHash` **não** foram alterados, porque a decisão de produto 29 continua pendente.

### 3.3 Defeito corrigido — `LtvState::apply()` desligava o re-carimbo

- **O problema:** no relatório do `ltv-refresh`, `effective_level` é o **menor** nível entre as assinaturas. Num arquivo
  com assinaturas de participantes sem carimbo próprio ele é B-B, então o estado caía para `not_applicable` e
  `ltv_next_refresh_at` era apagado. O arquivo nunca mais seria renovado. Isso vale para qualquer envelope da Fase 2/3
  com assinatura de participante.
- **A correção:** com cadeia de carimbos de documento válida e carimbo de arquivamento presente, o estado continua
  `b_lta`. Os rótulos de `LtvStatus` já descrevem a camada da operadora.
- **Testes:** `tests/Feature/Phase3/Ltv/LtvStateRefreshTest.php` (3 casos, incluindo cadeia inválida). O ponta a ponta
  confirma que o `ltv-validate` do arquivo continua dizendo **B-B**, porque as assinaturas dos participantes não ganham
  carimbo. Nada é anunciado acima de PAdES-B-B.

### 3.4 Semeadura (`DemoOrganizationSeeder`)

- **Plano:** `a3_signing` e `govbr_return` ligados no plano da Horizonte e desligados no da Vega.
- **Antifraude, pelo motor real:** um sinal de taxa de falha de entrega, só com as contagens do catálogo, leva a
  Horizonte a `watch` com um caso aberto. `watch` não suspende envio. Na primeira versão a evidência usava chaves fora do
  catálogo, que o minimizador descartou (`_dropped: 3`); corrigido e conferido numa semeadura limpa.
- **Afiliados, pelo razão real:** a parceira "Paula Parceira Demo" (`PARCDEMO`, 10%), com dados de repasse de TESTE e a
  indicação da Horizonte; a comissão pendente é calculada pelo `CommissionLedger`.
- **Arquivos (fora dos testes unitários):** o PKCS#12 de TESTE do simulador vai para `storage/app/private/demo/fase-3`.
  A senha é a constante de demonstração `DEMO_A3_SIMULATOR_PASSWORD`, e o seeder imprime as variáveis do `.env`.
- **Nenhum segredo real.** As flags da plataforma são ligadas só durante a semeadura e voltam ao valor do `.env`.

### 3.5 Formatação

`vp check --fix` formatou os documentos que os relatórios anteriores apontavam: `docs/fase-2/webhooks.md` e os docs da
fase 3.

## 4. Ponta a ponta (`tests/Feature/EndToEnd/Phase3PartOneTest.php`)

Primeiro teste (119 asserções no conjunto), pdftool **real** e as flags `a3_signing`, `govbr_return` (com
`finalizer_integration`) e `participant_a1` ligadas.

1. **Três participantes, três meios:**
    - Maria: componente local, pelo **simulador**;
    - João: devolução "assinada no portal", produzida pelo simulador de portal do P3-GOV com certificado de TESTE;
    - Ana: A1 por arquivo.
    - A finalização congela a base e espera os três.
2. **Antifraude:** a organização vai a `restricted` por sinais reais (pico de envio + rajada de destinatários). Um
   envelope **novo** é barrado com `risk_restricted` e continua `ready`.
3. **Maria assina pelo simulador.** O envelope continua esperando.
4. **João:** reserva, baixa os bytes exatos (SHA-256 conferido) e devolve. Aceito como
   `participant_external_unverified`. O envelope **continua** esperando a Ana; antes da integração ele teria concluído
   sem a devolução, ou recusado o arquivo.
5. **Ana assina com A1.** O envelope **conclui** com a organização ainda `restricted`:
    - `participant_external`, `PAdES-B-B`;
    - `incremental_chain.ok`, 4 assinaturas.
6. **pdftool:** 4 assinaturas íntegras, válidas e confiáveis com as raízes de TESTE. Cada revisão começa, byte a byte,
   pela anterior (base ⊂ simulador ⊂ devolução ⊂ A1 ⊂ final), e a última cobre o arquivo inteiro.
7. **Páginas:**
    - a verificação pública lista A1, externo simulado e "assinatura digital de terceiro, cadeia não verificada";
    - nada de "Assinatura gov.br", `participant_a3` ou e-mail;
    - as evidências não têm `ltv` nem histórico com a flag desligada.
8. **Longo prazo:**
    - o `LtvSigner` aplica B-LTA ao final (declarado PAdES-B-B, sem degradação, `icp_brasil: false`), como a integração
      faria;
    - o relógio da plataforma avança além do próximo re-carimbo e o agendador despacha o `RefreshArchiveTimestamp`: nova
      versão final, prefixo preservado, 2 carimbos de documento e cadeia válida;
    - estado `b_lta`, perfil exibido **PAdES-B-B**, histórico de 2 resumos nas evidências (o anterior substituído e o
      vigente);
    - a verificação pública não menciona B-LT/B-LTA;
    - "relógio avançado" aqui é o da plataforma. O teste com o relógio do pdftool avançado, em que o certificado da TSA
      vence, é o `test_ltv.py` do P3-LTV.

Segundo teste, afiliados: indicação → comissão pendente (10%, BRL) → nada aprovado no dia 29 → aprovada depois do prazo
(`affiliates:settle`) → estorno gera lançamento negativo de −R$ 20,00. Nada é pago: não há `paid_at`.

## 5. QA no navegador

Banco `database/i3a.sqlite` (semeado e depois apagado), servidor `php -S` na porta 8139, com o PID encerrado ao fim.
Rotas públicas reais; login dos usuários de demonstração pela rota local de QA, sem senha digitada. O fluxo:

- **Página pública:**
    - código por e-mail e aceite dos dois participantes;
    - antes do aceite aparecem os três cartões: A1, token (A3) e portal. O do portal traz o aviso "ainda não verifica a
      cadeia";
    - Maria registrou a escolha do token ("Escolha registrada", esperando os outros);
    - o João reservou pela interface: vencimento e SHA-256 conferem com o banco, e o botão de download **não** foi
      clicado;
    - a assinatura no portal e a devolução foram feitas pelo **endpoint HTTP real**, com a sessão do próprio participante:
      201, `completed`, "Assinatura digital de terceiro, cadeia não verificada — certificado de TESTE";
    - o cartão do João passou a mostrar o rótulo aceito e não oferece mais reserva.
    - Na primeira tentativa de devolução a sessão foi montada no formato errado (419); o formato do projeto é JSON e a
      segunda tentativa passou.
- **Simulador:**
    - o componente aparece com o selo SIMULADO; "Token ou cartão neste computador" fica desabilitado ("ainda não foi
      habilitado");
    - o resumo preparado mostra certificado de TESTE "não é ICP-Brasil", "cadeia não verificada", revogação não
      verificada e vencimento;
    - "Assinar com o simulador" conclui o envelope: `participant_external`, 2 assinaturas, cadeia íntegra;
    - pdftool `validate` no arquivo final: 2 íntegras e válidas; revisões encadeadas byte a byte.
- **Comprovante, detalhe, evidências e verificação pública:** o texto cita o simulador ("nenhum token foi usado"), a
  devolução sem cadeia verificada ("não se afirma que seja assinatura gov.br") e que a operadora não assinou. O QA
  mostrou que o painel de conclusão do detalhe e o selo das evidências e da verificação **só citavam o componente**
  neste caso misto. Corrigido: passam a citar também a devolução. A verificação pública traz nomes mascarados e as duas
  cadeias `not_verified`; no conteúdo da página, zero ocorrências de "Assinatura gov.br", `participant_a3`, e-mail,
  B-LT e B-LTA.
- **Painel de risco (platform admin):**
    - a fila mostra o caso da Horizonte "Em observação";
    - o caso mostra sinal, pontos, histórico automático e a decisão com motivo obrigatório, mais "O que o sistema nunca
      faz".
    - No banco de QA o sinal aparece com "campos descartados na minimização 3": é a semeadura anterior à correção da §3.4.
- **Revisão de segurança (owner):** estado, critério sem limiares nem pontos, pedido de revisão humana (LGPD art. 20) e
  a informação de que o envio continua liberado.
- **Afiliados:**
    - painel interno: totais em BRL, "o sistema calcula… o repasse é feito fora da plataforma", a parceira com taxa de 10%;
    - portal da parceira: link e código, dados de repasse **mascarados**, comissão pendente "libera em 14/10/2026",
      exportação CSV.
- **Menu:** "Antifraude" e "Afiliados" no painel interno e "Programa de afiliados" na conta, só com as flags.
- **Console:** sem erros de JavaScript. Houve um único 404 de recurso, e ele veio de uma URL errada que eu mesmo abri
  (`/envelopes/...`; o caminho do produto é `/documentos/...`). A contagem não mudou nas oito páginas seguintes.

## 6. Semântica conferida (T1, T2, T3)

- **Cada meio tem valor e rótulo próprios:**
    - o simulador é sempre "simulado — nenhum token foi usado", e nunca `participant_a3`;
    - a devolução sem âncora é "assinatura digital de terceiro, cadeia não verificada";
    - "Assinatura gov.br (avançada)" só com cadeia validada até a âncora fixada; esse caso está coberto pelos testes do
      P3-GOV.
- **Perfil:** exibido sempre PAdES-B-B; `pades_ltv_advertise` continua desligada e com checklist.
- **Carimbo:** o do longo prazo é da TSA da operadora, `icp_brasil: false`.
- **API gov.br:** continua classe C. `GovBrSignatureProvider` é só interface, sem implementação.

## 7. O que o proprietário precisa fornecer, por item

- **A3 por componente local (§3.4):**
    - piloto com pelo menos dois modelos de token no Windows;
    - a escolha do componente (fork do NexU com parecer sobre a EUPL-1.2, Assinador Serpro ou Lacuna/BRy);
    - a CSP liberando o `connect-src` do componente;
    - a confirmação de PIN errado × cancelado no componente escolhido;
    - as âncoras fixadas por impressão digital;
    - hoje `NexuLocalSigner::PRODUCTION_ENABLED = false`.
- **Devolução gov.br (§3.5):**
    - uma conta prata/ouro para produzir a fixture real no `assinador.iti.br` e conferi-la no VALIDAR;
    - a raiz gov.br fixada por SHA-256;
    - a confirmação de que o portal faz atualização incremental;
    - onde o certificado traz o CPF;
    - a cláusula jurídica de aceitação do meio (pendência 26).
    - Depois disso: os itens 6–9 do §8 de gov-br.md.
- **Longo prazo (§3.6):**
    - TSA da operadora em produção (HSM/KMS, NTP, OID, AC interna);
    - rede de saída até as ACs com `hard-fail`, ou CRL/OCSP entregues;
    - ACT ICP-Brasil contratada, se algum dia o anúncio mencionar ICP-Brasil;
    - validação externa independente das fixtures;
    - a decisão de produto 29 (histórico de resumos na página pública e `checkHash`);
    - a integração do `LtvSigner` na finalização e o agendamento em `routes/console.php`.
- **Antifraude (§3.7):**
    - legítimo interesse, RIPD e prazo de resposta às revisões (decisão 22);
    - calibração dos limiares em modo observação;
    - o `X-Device-Id` no cadastro;
    - a decisão sobre a chave `same_email_domain` do catálogo.
- **Afiliados (§3.10):**
    - o tratamento tributário e contratual dos repasses (decisão 27);
    - as 8 decisões de `afiliados.md` §8;
    - a janela de atribuição.
- **Pino de IP em HTTPS:** rodar `PinnedConnectionTest` e `HttpsPinTest` no ambiente-alvo, cujo libcurl/TLS pode ser
  outro, e um teste de fumaça com endpoint https real de homologação.
- **Transversal:** a Fase 2 em produção por um ciclo de cobrança antes de ligar qualquer item da Fase 3 (roadmap §3).

## 8. Suítes finais e pendências conhecidas

- Suíte de navegador (em série, depois do QA e com o servidor de QA encerrado): 36 passam, 3 pulados, 0 falhas.
- phpstan final: 0 erros.
- Unit + Feature final (depois do ajuste do seeder, do selo e do painel): 2.106 / 2.106, 19.256 asserções.
- `storage/app/documents/orgs`: removi a pasta da organização do banco de QA. Outras pastas de hoje vêm das suítes e das
  semeaduras de verificação, não dá para atribuí-las com certeza, e ficaram onde estavam.
- **O painel de conclusão do comprovante** (texto do servidor) enquanto a assinatura com token está pendente, a
  reautenticação para token/gov.br depois da janela de download (P3-FRONT, itens 1 e 2) e o modo `cms` no navegador
  continuam como o P3-FRONT relatou.

## 9. Revisão adversarial

22 achados, em três frentes (assinatura externa, gov.br e longo prazo; antifraude, afiliados e LGPD; produto,
semântica e design). Cada um tem um teste em `tests/Feature/Review/Phase3A/` (29 casos) ou em
`tools/pdftool/tests/test_review_cms_attributes.py` (2). Todos terminam verdes, e nenhum teste de revisão foi alterado.

### 9.1 Assinatura externa, gov.br e longo prazo

| Achado                                                                                              | Correção                                                                                                                                                                                                                                                                                                                                                                                                                                |
| --------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Duas devoluções gov.br simultâneas: a perdedora voltava o pedido concluído a `requested` (alta)     | Sob o lock do envelope, `GovBrReturnService::assertStillReserved()` relê o pedido e exige a MESMA reserva (pending, mesma revisão e resumo). Se ele já está concluído, a resposta é 409 `already_completed` sem tocar no pedido. `GovBrReturnStage::releaseReservation()` passou a ser condicional (`WHERE status = pending AND expected_document_version_id = ?`) e nunca desfaz um pedido concluído; `attempts` sobe por `increment`. |
| `LtvState::apply()` promovia a `b_lta` um arquivo de nível efetivo B-B (média)                      | Dois fatos separados. `ltv_status` é o nível do ARQUIVO: `validated_level` no `ltv-sign`, `effective_level` no `ltv-refresh`. A camada de arquivamento (`ltv_archive_expires_at` e `ltv_next_refresh_at`, `LtvState::archiveLayer()`) é que decide a renovação; o agendador renova por ela. "Revogação embutida" só vale com nível B-LT ou acima. A visão interna ganhou `archive_layer` e um rótulo próprio.                           |
| O re-carimbo quebrava a conferência pública do arquivo entregue (média)                             | `PublicVerification::checkHash()` consulta `VerificationHashHistory::match()` e responde `signed_previous`, com um ou vários documentos. O front já tratava esse resultado. O item do checklist foi marcado como cumprido, e a dependência de `pades_ltv` está documentada em `longo-prazo.md` §2 e §6.                                                                                                                                 |
| Sem campo CPF, a devolução assinada por outra pessoa virava `participant_govbr` (média)             | Sem CPF informado, o nome do titular precisa corresponder ao do participante (`GovBrReturnDecision::namesCorrespond`); sem isso, a recusa é `holder_name_mismatch` ou `holder_name_not_found`. A decisão está registrada em `gov-br.md` §4.                                                                                                                                                                                             |
| `embed-external` (modo CMS) aceitava CMS sem ESS signing-certificate-v2 ou com signing-time (baixa) | `_check_pades_baseline_attrs()` em `external.py` exige ESS v2 (ou v1) com o hash do certificado anunciado e recusa `signing-time`. No PHP não há mais perfil padrão: sem `profile` devolvido pelo pdftool, a resposta é `cms_invalid`.                                                                                                                                                                                                  |

### 9.2 Antifraude, afiliados e LGPD

| Achado                                                                          | Correção                                                                                                                                                                                                                                                                                    |
| ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Pedido de revisão depois de uma restrição confirmada abria um caso vazio (alta) | `RiskAppeals::baseline()`: o caso do pedido herda o `after_signal_id` do último caso `confirmed`/`watching`. O revisor vê os mesmos sinais, e a organização continua vendo os critérios.                                                                                                    |
| Afiliado que administra a organização indicada recebia comissão (alta)          | `CommissionLedger::holdIfAffiliateAdministers()`, ao criar e ao aprovar cada comissão: com vínculo ativo de owner/admin, a indicação vai para `held` com o motivo novo `same_member`, sem comissão, e com trilha e sinal ao antifraude. Uma liberação humana que já viu o motivo prevalece. |
| Platform admin aprovava a própria candidatura e liberava o próprio caso (média) | `AffiliateProgram::assertNotSelf()` (aprovar, taxa, suspender, reativar) e `AffiliateReferralController::review` respondem 403. `RiskReviewDecisions::hasConflict()` (membro ativo da organização) dá 403 no POST, e a página substitui o formulário por um aviso.                          |
| A decisão cobria sinais que o revisor não viu (média)                           | A página envia `seen_through` (ULID do último sinal exibido, obrigatório no POST). Havendo sinal novo, a recusa é `case_changed` ("recarregue").                                                                                                                                            |
| Comissão de pagamento em disputa entrava no lote (média)                        | `PayoutBatches::withoutDisputed()`, em `build` e `preview`: comissão e ajuste só entram com o pagamento em `approved`, `refunded` ou `charged_back`. Com `in_mediation`, o lançamento fica para o próximo lote.                                                                             |
| Autoindicação contornável trocando o IPv6 temporário (média)                    | `IpFingerprint` faz o HMAC do /64 para IPv6; IPv4 continua inteiro. Não precisou de migration (a flag nasce desligada; hashes IPv6 antigos deixam de coincidir, o que está documentado).                                                                                                    |
| Força bruta por IP pontuava a organização errada (baixa)                        | O ramo por IP de `RiskDetector::challengeFailed()` conta só as falhas da própria organização.                                                                                                                                                                                               |
| `duplicate_ip` punha a organização indicada em observação (baixa)               | O sinal é gravado com pontuação 0 (`RiskSignals::record(..., $score)` só reduz, nunca aumenta). O efeito continua sendo segurar a comissão.                                                                                                                                                 |
| Estorno saía como texto no CSV (baixa)                                          | As colunas Base, Taxa e Valor do extrato do afiliado saem como número quando casam com `-?\d+,\d{2}`; as colunas de texto continuam neutralizadas.                                                                                                                                          |
| Portal e CSV revelavam o chargeback do indicado (baixa)                         | `Commission::affiliateReasonLabel()` mostra "Pagamento revertido" ao afiliado; o rótulo detalhado fica só no painel (`commissionRows(..., forOperator: true)`).                                                                                                                             |

### 9.3 Produto, semântica e design

| Achado                                                                                      | Correção                                                                                                                                                                                                                                                                                                            |
| ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Devolução sem cadeia verificada afirmava o "portal do governo" (alta)                       | Narrativa do servidor (`govBrOnlyStatement`, caso misto via `govBrWhere()`), `seal.ts`, `crypto-signature-list.tsx` e `envelopes/show.tsx`: sem cadeia conferida, o texto diz "devolveu a versão reservada com uma assinatura digital acrescentada"; "portal gov.br" só com `govbr_trusted` ou `participant_govbr`. |
| O token era oferecido sem componente disponível, e a intenção segurava a finalização (alta) | `can_request` e `can_prepare` exigem algum componente com `available: true`. Sem nenhum, `POST intent` responde 409 `component_unavailable`.                                                                                                                                                                        |
| A organização restrita não achava o motivo no app; o toast trazia um caminho cru (média)    | Nova prop compartilhada `risk` (só o estado e o caminho da página) e a faixa `RiskRestrictionBanner` no `AppLayout`. O toast agora nomeia a página "Revisão de segurança da conta".                                                                                                                                 |
| O afiliado aprovado não via como recebe (média)                                             | Cartão "Como você recebe" no ramo aprovado, com `ProgramRules` (fora da plataforma, saldo mínimo) e a explicação de "Libera em".                                                                                                                                                                                    |
| "revisão nº" sem número na devolução gov.br (baixa)                                         | `GovBrSignatureViews` calcula `revision_index` pela ordem das `signed_incremental`, e o componente só imprime o rótulo quando o número vem.                                                                                                                                                                         |
| Marcadores de plural nas telas do antifraude (baixa)                                        | Trocados por `plural()`.                                                                                                                                                                                                                                                                                            |
| O mínimo de 20 caracteres do pedido de revisão não aparecia (baixa)                         | Texto de ajuda com contador, ligado ao campo por `aria-describedby`.                                                                                                                                                                                                                                                |

### 9.4 Asserções existentes alteradas (com justificativa)

- `tests/Feature/Phase3/Ltv/LtvStateRefreshTest.php`, 1º caso: esperava `b_lta` para um re-carimbo com `effective_level`
  B-B. Agora espera `not_applicable` + `archiveLayer()` + próxima renovação agendada + revogação não afirmada.
  Justificativa: roadmap T2 (nenhum perfil acima do nível real) e o achado 2.
- `tests/Feature/EndToEnd/Phase3PartOneTest.php`: esperava `LtvStatus::BLta` para o arquivo com assinaturas de
  participantes, depois do `ltv-sign` e depois do re-carimbo. Agora espera `NotApplicable` + `archiveLayer()`. O próprio
  teste já dizia que o `effective_level` do arquivo é B-B. Justificativa: T2.
- `tests/Feature/Phase3/Risk/ReviewQueueTest.php`: o caso do pedido esperava `after_signal_id = through_signal_id` do caso
  confirmado, que era justamente o defeito. Agora espera o `after_signal_id` do caso confirmado e 2 sinais. Justificativa:
  LGPD art. 20 §1º (critérios da decisão), `antifraude.md` §6. As outras chamadas de `admin.risk.decide` desse arquivo
  passaram a enviar `seen_through` (entrada da tela, sem mudança de asserção).
- `tests/Feature/Phase3/Risk/SendingRestrictionTest.php`: esperava o caminho `/revisao-de-seguranca` dentro do toast.
  Agora espera o nome da página e a ausência do caminho cru. Justificativa: roadmap §3.7, "mensagem clara ao usuário".
  O link está na faixa do app.

### 9.5 Contagem final

- Revisão (`tests/Feature/Review/Phase3A`): 29 / 29; `test_review_cms_attributes.py`: 2 / 2.
- Unit + Feature: **2.135 / 2.135** (19.379 asserções).
- Navegador (em série): 36 passam, 3 pulados, 0 falhas.
- pdftool (pytest): **184 / 184** (os 139 da Fase 2, mais os da parte 1, mais os 2 de revisão).
- `npm run types:check`, `npm run check`, `npm run build`, `pint --dirty` e `phpstan` (0 erros): verdes.
- Migrations: nenhuma nova. A regra por /64 do IPv6 está no próprio HMAC (`IpFingerprint`).

### 9.6 Limitações que continuam

- **Nome × CPF (gov.br):** a comparação por nome aceita homônimos. A conferência forte continua sendo o campo CPF,
  recomendado nos envelopes com devolução gov.br.
- **LTV:** a decisão de produto 29 continua pendente sobre o texto do `signed_previous` e a exibição do histórico, embora a
  conferência já funcione.
- **Separação de interesse:** vale para decisões sobre a própria organização, a própria participação e, desde a
  verificação final antes do commit, para o registro de pagamento: quem tem comissão num lote recebe 403 ao marcá-lo como
  pago, e outra pessoa da equipe registra (`PayoutBatches::markPaid`, teste em `Affiliates/PayoutTest`). Montar e cancelar
  o lote continuam livres, porque nenhum dos dois declara que dinheiro saiu.
