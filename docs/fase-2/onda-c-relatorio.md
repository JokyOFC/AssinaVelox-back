# Fase 2, onda C — relatório de integração (I-2C)

Integração das três áreas da onda C — assinatura com o certificado A1 do próprio participante
(K-A1, §2.12), TSA da operadora e dossiê ZIP (K-TSA, §2.13), retenção e preservação (K-RET,
§2.19) — e do front delas (K-FRONT). Data: 11/09/2026. Nenhum commit foi feito.

Todas as flags nascem **desligadas**. Com elas desligadas o comportamento é o da onda B (§3).
Nada é anunciado além de **PAdES-B-B**; o carimbo da TSA própria é sempre "carimbo do tempo da
operadora — não é carimbo ICP-Brasil"; certificado de teste é sempre dito teste.

## 1. Verificações (números reais, depois de todas as correções)

| Verificação                                                   | Resultado                                                                    |
| ------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| `php artisan test --testsuite=Unit,Feature --parallel`        | **1.494 de 1.494** passando (13.022 asserções), 0 pulados, 206 s             |
| `php artisan test --testsuite=Browser` (3 vezes seguidas)     | 36 passando, 3 pulados de 39 (740 asserções) em **43 s, 43 s e 45 s**        |
| Suíte de navegador **encadeada logo depois** da de integração | 36 passando, 3 pulados, **44 s** — sem travar                                |
| `tests/Feature/EndToEnd/Phase2OndaCTest.php` (novo)           | **1 teste, 233 asserções**, passando (~59 s, pdftool real)                   |
| `tests/Feature/Phase2/OndaCIntegrationTest.php` (novo)        | **10 testes, 74 asserções**, passando                                        |
| `tools/pdftool`: `pytest -q`                                  | **139 passando** (93 originais + 21 do K-A1 + 24 do K-TSA + 1 da integração) |
| `vendor/bin/phpstan analyse`                                  | **0 erros**                                                                  |
| `vendor/bin/pint --test`                                      | verde                                                                        |
| `npm run types:check`                                         | 0 erros                                                                      |
| `npm run check` (`vp check`)                                  | 294 arquivos formatados, 0 avisos em 234                                     |
| `npm run build`                                               | OK                                                                           |
| `php artisan wayfinder:generate --with-form`                  | OK                                                                           |
| `tests/Feature/Phase2/VocabularyTest.php`                     | verde (dentro da suíte)                                                      |

Linha de base antes da integração: 1.483 testes Unit+Feature, **todos** passando (as falhas de
`AllGetRoutesTest`, `EnumCatalogTest` e `VocabularyTest` citadas nos relatórios das áreas já
tinham sido corrigidas por elas), 138 no pytest, PHPStan 0, `npm run check` falhando na
formatação de três documentos das áreas.

## 2. O que a integração ligou e corrigiu

### 2.1 Fiação entre áreas (pendências listadas pelas áreas)

| Ponto                               | O que foi feito                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| ----------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `HandleInertiaRequests::features()` | `dossier_export` e `retention_policies` (global E plano), `operator_tsa` e `pades_bt` (só a chave da plataforma). `participant_a1` **não** entra: a página pública descobre o recurso pelo `GET sign.certificate.show` (404 = desligado), como o K-A1 desenhou.                                                                                                                                                                                     |
| `config/assinavelox.php`            | flag `retention_policies` e bloco `retention` (os mesmos padrões de `RetentionConfig` — declarar não muda nada). `.env.example` com as cinco flags da onda C e as chaves da TSA (a **senha** nunca: só o nome da variável).                                                                                                                                                                                                                         |
| Página de evidências                | prop `timestamps` (`TimestampEvidence::forEnvelope`), só quando há carimbo.                                                                                                                                                                                                                                                                                                                                                                         |
| Verificação pública                 | chave `timestamps`, só quando há carimbo e **nunca o do manifesto do dossiê** (decisão da integração, §4.1). A lista fechada de chaves continua a mesma quando não há carimbo (`VerificationContractTest` inalterado).                                                                                                                                                                                                                              |
| Detalhe do documento                | props `legal_hold` (`RetentionPresenter::forEnvelope`, o front deixa de fazer um GET extra) e `participant_signatures` (só quando há pedidos).                                                                                                                                                                                                                                                                                                      |
| Preservação × pastas e mover        | `LegalHolds::guardMove()` e `guardFolderDeletion()`: mover um documento para fora de uma pasta preservada e excluir uma pasta preservada (ou com conteúdo sob pasta acima preservada) são **recusados**, com a tentativa em `retention_events`. O mover em lote pula o documento protegido. Regra conservadora: mesmo com bloqueio do próprio documento, sair da pasta preservada é recusado — quem preservou a pasta decide sobre o conteúdo dela. |
| Preservação × fotos                 | `CapturePurge` (retenção global das fotos, fora do K-RET) pula fotos de envelopes preservados.                                                                                                                                                                                                                                                                                                                                                      |
| Exclusão da organização             | o pedido avisa quando há preservação ativa (a exclusão definitiva já era bloqueada pelo K-RET).                                                                                                                                                                                                                                                                                                                                                     |
| Agendamento                         | `PurgeExpiredDossierExports` de hora em hora (rede de segurança; cada montagem já agenda a própria limpeza) e o comando novo `participant-a1:maintain` a cada 15 min: apaga material selado órfão (PFX + senha cifrados que nenhum worker consumiu) e despacha o prazo vencido dos envelopes em `finalizing` — com o driver `sync` o prazo só seria conferido quando a finalização rodasse de novo (pendências 5 e 6 do K-A1).                      |
| Menu de Configurações               | item "Retenção e preservação" (flag + `manage_settings`); antes a tela só abria pela URL.                                                                                                                                                                                                                                                                                                                                                           |
| Dossiê de envelope da onda C        | nomes em PT-BR para as versões novas (`vN-base-para-assinatura.pdf`, `vN-revisao-assinada.pdf`) e descrição própria de cada uma no manifesto.                                                                                                                                                                                                                                                                                                       |
| pdftool                             | `certs.describe_pkcs12` usava `InputRejected` sem importar (`NameError` com o PFX da operadora ausente, achado do K-A1): importado, com teste de regressão (`tests/test_certs_describe.py`).                                                                                                                                                                                                                                                        |
| Documentos das áreas                | formatados (`vp fmt`) para `npm run check` passar; conteúdo inalterado.                                                                                                                                                                                                                                                                                                                                                                             |

### 2.2 Asserções de testes existentes alteradas

| Teste                           | Mudança e justificativa                                                                                 |
| ------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `Organizations/SharedPropsTest` | `features` ganhou as quatro chaves da onda C, todas `false` (mesmo padrão das ondas A e B; roadmap T8). |

Nenhuma outra asserção existente foi alterada. `AllGetRoutesTest` e `EnumCatalogTest` já tinham
sido estendidos pelas áreas (só acréscimos).

## 3. Não regressão

Com todas as flags desligadas (o padrão e o que a suíte usa), a suíte inteira passa sem mudança
de asserção além da citada. Provas específicas do "desligado": `ParticipantA1PipelineTest`
("com a flag desligada nada muda"), `Phase2/Retention/FlagOffTest`, `Phase2/Timestamp/PadesBtFlagTest`,
`Phase2/Dossier/DossierAccessTest` (404 sem a flag) e `OndaCIntegrationTest` (flags compartilhadas
desligadas; chaves de carimbo ausentes sem carimbo; `legal_hold.feature_enabled = false`).
Preservação criada continua valendo com a flag desligada (decisão do K-RET), e os guardas novos
só agem quando existe bloqueio — sem bloqueio, mover, excluir pasta e a retenção das fotos são
os de antes.

## 4. Validação criptográfica de ponta a ponta (`tests/Feature/EndToEnd/Phase2OndaCTest.php`)

pdftool real, certificados A1 de **teste** de participante (AC descartável), certificado de
teste da operadora e TSA de **teste** da operadora. Envelope com **dois documentos** e **dois
participantes** (Maria e Henrique) que optam por assinar também com o próprio certificado.

1. **Pipeline.** Aceites paralelos → a finalização congela uma base (`pre_signature`) por
   documento e espera → Maria confere o certificado (prévia com `is_test`, `icp_brasil_validated:
false`) e envia → aplicada nos dois documentos → Henrique envia → aplicada → a operadora assina
   **por último** → `completed`, `signature_status = mixed`, `signature_profile = PAdES-B-B`,
   `timestamp = null`, `long_term_validation = false`. Quatro `participant_signatures` (2 × 2) e
   nenhum resto no diretório temporário nem no selado.
2. **Para cada documento final**, com o pdftool:
    - três assinaturas, na ordem Maria → Henrique → operadora, todas **íntegras e válidas** e,
      com as raízes de teste, **confiáveis**;
    - cobertura: as duas dos participantes `ENTIRE_REVISION` (cada uma cobre a própria revisão),
      seguidas só de alteração permitida (`NONE`/`FORM_FILLING`); a da operadora, a última,
      `ENTIRE_FILE`; `IncrementalChain::analyse` ok;
    - revisões preservadas **byte a byte**: base ⊂ revisão 1 ⊂ revisão 2 ⊂ final;
    - a revisão 1 isolada valida sozinha (1 assinatura, cobrindo o arquivo inteiro dela);
    - o **hash publicado** (verificação pública, por documento) e o da versão final **conferem
      com os bytes**; um byte adulterado no fim do final não confere na verificação e não passa
      no pdftool.
3. **Dossiê** com `operator_tsa` ligada: pronto, `timestamp_status = granted`, SHA-256 do ZIP =
   o registrado. **Cada arquivo do manifesto confere** (SHA-256 e tamanho) com os bytes do ZIP;
   **todas** as versões guardadas de cada documento entram (original, consolidado, evidências,
   base, as duas revisões e o final) e cada uma confere com o banco (`matches_record`); os finais
   do ZIP são exatamente os validados no passo 2; a revalidação na montagem vê 3 assinaturas
   íntegras em cada final. O **token RFC 3161 confere com o SHA-256 do `manifest.json`**
   (pyHanko, independente do código de emissão; e `openssl ts -verify` quando o OpenSSL está no
   PATH) e **não** confere com um manifesto alterado; `carimbo.json` e `timestamp_tokens` dizem
   `operator` e "Carimbo do tempo da operadora — não é carimbo ICP-Brasil". Nenhuma senha (dos
   participantes, da TSA) nem chave privada no ZIP.
4. **Retenção** (política de 1.825 dias; os dois envelopes com conclusão há 2.000 dias):
    - o envelope da onda C, **preservado**, não perde nada: linhas, os 14 arquivos das versões,
      o `.tsr` e o ZIP do dossiê continuam;
    - **outro**, não preservado, é apagado, e a verificação pública segue a decisão padrão
      (`notice_with_final_hash`): aviso de remoção, só o resumo final, sem organização nem
      participantes;
    - liberada a preservação, o envelope da onda C sai **inteiro** — versões, assinaturas de
      participante, pedidos, o `.tsr` do carimbo e o ZIP do dossiê (propagação K-RET × K-TSA ×
      K-A1) —, com recibo `completed`; a verificação publica só os **dois** resumos finais e a
      conferência de um arquivo final guardado ainda responde "confere".

## 5. Confiabilidade da suíte de navegador

### 5.1 Causa (reproduzida, não suposta)

Duas partes, ambas no plugin `pestphp/pest-plugin-browser` 4.3 — detalhes em
`docs/testes.md` §6-C:

1. o cliente do Playwright espera a resposta num `while (true)` sem teto; se o WebSocket fecha,
   vira **espera ativa infinita**;
2. quando o processo PHP morre de forma anormal, o `playwright run-server` (cmd → node →
   navegador) fica **órfão segurando o stdout herdado**: quem esperava a saída — o terminal, o
   script encadeado — parece travado. Sonda da integração: o PHP morreu em 35 s, o comando só
   voltou em **376–495 s**, quando o órfão foi morto à mão. Um gancho de desligamento não
   resolve (o laço de eventos volta a rodar no desligamento e o `hard_timeout` aborta o
   processo antes) — também testado.

### 5.2 O que mudou (`tests/BrowserTestCase.php`, sem tocar no vendor, sem desabilitar teste)

- **teto por chamada**: `Playwright::setTimeout(20_000)` já era enviado em toda chamada
  (navegação inclusive);
- **vigia por teste** (`BROWSER_TEST_TIMEOUT`, padrão 90 s): temporizador do laço de eventos que
  interrompe a espera com uma exceção dizendo o teste — sonda com teto de 5 s: **falhou em
  5,0 s** (comando em 9 s) em vez de travar;
- **teto de processo** (`set_time_limit`) renovado a cada teste, para a espera ativa;
- **guarda externa contra órfão** (`tests/Browser/Support/playwright-orphan-guard.php`):
  disparada uma vez por processo; se o processo de teste sumir com o servidor do Playwright vivo
  — e só se o PID for mesmo o `playwright run-server` —, encerra essa árvore. Sonda de espera
  ativa: o comando voltou em **38 s**, sem processo órfão (antes: 376–495 s);
- **limpeza entre testes**: vigia desarmado e relógio congelado solto no `tearDown`, além do
  reset de contextos do plugin.

### 5.3 Medições

| Execução                            | Resultado                              | Tempo |
| ----------------------------------- | -------------------------------------- | ----- |
| Navegador #1                        | 36 passando, 3 pulados                 | 43 s  |
| Navegador #2                        | 36 passando, 3 pulados                 | 43 s  |
| Navegador #3                        | 36 passando, 3 pulados                 | 45 s  |
| Integração (Unit+Feature, paralelo) | 1.494 de 1.494 (13.022 asserções)      | 189 s |
| Navegador **encadeado** logo depois | 36 passando, 3 pulados (740 asserções) | 44 s  |

Nenhum travamento nas execuções medidas e nenhum servidor do Playwright nem guarda sobrando
depois delas (conferido por linha de comando). Como a causa é intermitente, a garantia não é
"não trava mais", e sim **"se travar, falha em no máximo ~2 min e não deixa órfão"**.

## 6. Dados de demonstração (`DemoOrganizationSeeder`)

- Plano da **Horizonte** com `participant_a1`, `dossier_export` e `retention_policies`
  ligados; o da **Vega**, desligados. A interface só aparece com os interruptores globais
  (`ASSINAVELOX_FEATURE_*`) ligados — `operator_tsa`/`pades_bt` são só do `.env`.
- Horizonte: **política de retenção ativa** (concluídos 1.825 d, encerrados 365 d, rascunhos 90 d,
  fotos 30 d, dossiês 7 d, trilha sem prazo — nada da demonstração vence) e **um documento
  concluído preservado** (motivo de demonstração).
- Arquivos de **teste** em `storage/app/private/demo/onda-c/`: certificado A1 de teste de
  participante (`participante-teste.pfx` + AC) e uma TSA de teste da operadora (`tsa/`). As
  senhas são as constantes `DEMO_PARTICIPANT_PFX_PASSWORD` e `DEMO_TSA_PASSWORD` do seeder
  (dados de demonstração como a senha `password`, só locais; nunca impressas). Gerados fora da
  transação e **não** gerados quando o seeder roda dentro da suíte de testes. Para ligar a TSA
  de teste localmente: `ASSINAVELOX_TSA_PFX_PATH=…/demo/onda-c/tsa/tsa.pfx`,
  `ASSINAVELOX_TSA_PASSWORD_ENV=<nome da variável>` com a senha nessa variável,
  `ASSINAVELOX_TSA_CHAIN_PEM` e `ASSINAVELOX_TSA_TRUST_ROOTS` para `chain.pem`/`root.pem`.

## 7. QA no navegador

Banco `database/i2c.sqlite` (`migrate:fresh --seed`), servidor na porta 8135 (`php -S` com o
roteador de QA do scratchpad, que acrescenta só uma rota de login sem senha e uma de emissão de
link; o PID foi encerrado ao fim), flags da onda C ligadas. Envelope de QA com dois documentos
reais e dois participantes, montado com os mesmos helpers da ponta a ponta.

| Tela                                                        | Resultado                                                                                                                                                                                                                                                                                                                               |
| ----------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Página pública, passo do certificado (desktop e **375 px**) | cartão próprio, separado do aceite; "o que é e o que não é"; arquivo + senha + "Conferir certificado"; depois da aplicação: "Assinado com certificado A1 de … (emitido por AC TESTE …)", "Certificado de TESTE — não é ICP-Brasil e não tem validade jurídica", e o comprovante diz que a operadora não assinou. Uma coluna no celular. |
| Detalhe do documento                                        | selo e declaração "assinado com certificado dos participantes"; lista de assinaturas criptográficas com o aviso de teste; "Baixar dossiê (ZIP)".                                                                                                                                                                                        |
| Dossiê                                                      | janela "Pronto para baixar", 15,2 MB, vencimento, SHA-256 do ZIP, "Manifesto com carimbo do tempo da operadora — não é carimbo ICP-Brasil".                                                                                                                                                                                             |
| Evidências                                                  | seção "Carimbo do tempo" com o rótulo da operadora e o aviso; as três menções a ICP-Brasil na página são todas negações.                                                                                                                                                                                                                |
| Verificação pública                                         | nome do titular mascarado ("Ana B. R. T."), sem CPF, série ou impressão digital; revogação "NÃO verificada"; **sem** o carimbo do manifesto do dossiê.                                                                                                                                                                                  |
| Verificação de registro apagado pela retenção               | tela própria: "Registro removido por política de retenção em …" e só o resumo final, sem título, organização nem participantes.                                                                                                                                                                                                         |
| Documento preservado                                        | selo "Preservado" ao lado do status, painel de preservação, nenhuma ação de excluir.                                                                                                                                                                                                                                                    |
| Configurações › Retenção e preservação                      | item novo no menu; política ativa e a preservação de demonstração listadas.                                                                                                                                                                                                                                                             |

Console: **sem erros** em todas as telas da onda C. O único erro visto (404) é do visualizador de
PDF pedindo o arquivo de um documento de **demonstração** — o seeder nunca gravou os PDFs dos
envelopes de demonstração (conferido: um envelope intocado também tem 0 de 4 arquivos no disco);
não é da onda C e a retenção não apagou nada do preservado.

**Limite honesto da QA:** a senha do certificado **não foi digitada no navegador** — a regra de
segurança do agente proíbe digitar senha em campo. O envio foi feito pela **mesma rota HTTP**
(`sign.certificate.store`), no mesmo servidor, por script, e o navegador conferiu os estados
antes e depois. A prévia (`inspect`) e o envio pelo formulário estão cobertos pelos testes de
feature e pela ponta a ponta, não pela QA manual.

## 8. Achados e riscos para o proprietário

1. **Titular do certificado ≠ participante.** Na QA, o certificado de teste de "Ana Beatriz
   Rocha" foi aceito para a participante "Maria Alves Souza": pela regra do K-A1 (§11), o nome é
   só conferido e exibido; bloqueia apenas CPF divergente quando os dois lados têm CPF. As
   páginas mostram o titular real ("Assinado com certificado A1 de Ana …" na linha da Maria), mas
   a regra precisa de decisão jurídica antes de ligar a flag — recomendação: exigir CPF no
   participante quando ele optar pelo certificado, ou pelo menos um alerta explícito "titular
   diferente do participante" nas evidências.
2. **Janela curta com o documento "pronto".** Um participante que não optou antes do último
   aceite vê o passo "pronto para enviar" enquanto o envelope estiver em `finalizing`; sem
   pedidos, a finalização conclui em segundos e o envio posterior recebe `envelope_closed`. Não
   quebra nada, mas a tela pode oferecer algo que some logo.
3. **Carimbo do manifesto fora da verificação pública** (decisão da integração): publicar a hora
   do carimbo diria a qualquer um com o código quando o dossiê foi baixado. Continua nas
   evidências e no dossiê.
4. **Guarda de pasta conservadora** (§2.1): quem precisar mover um documento preservado pela
   pasta tem de liberar a preservação da pasta antes.

## 9. Pendências

**Do proprietário (jurídico e operação):**

- Revisão jurídica do consentimento `v1-a1-participante-2026-09-11` antes de ligar
  `participant_a1`, e decisão sobre a regra titular × participante (§8 item 1).
- Mínimos legais da retenção, janela de backup (35 d na política × 90 d de arquivos em
  `docs/implantacao.md` §14.1 — alinhar antes de publicar), confirmação de
  `notice_with_final_hash` e se a trilha pode ter prazo.
- Decisão sobre dossiê de envelope recusado, expirado ou cancelado (hoje só concluídos).
- Contrato com ACT credenciada antes de existir carimbo ICP-Brasil (hoje: contrato + simulador
  que nunca grava `icp_brasil`).

**Checklist de produção da TSA da operadora** (`php artisan tsa:status` lista o que falta):

1. chave em **HSM/KMS** (hoje PKCS#12 em arquivo; exige um assinador PKCS#11/KMS no pdftool);
2. **NTP monitorado**, com a TSA respondendo `timeNotAvailable` fora da precisão declarada (o
   software não mede o relógio);
3. **OID de política próprio** (o padrão é um OID de exemplo, só teste);
4. **AC interna** emitindo o certificado da TSA com EKU `timeStamping` crítica, raiz publicada
   para quem confere dossiês;
5. texto dos Termos com o rótulo "carimbo do tempo da operadora — não é carimbo ICP-Brasil".

**O que falta para anunciar PAdES-B-T** (`PadesProfilePolicy::checklist()`; hoje o código de B-T
existe atrás de `pades_bt`, **não** está ligado à finalização e o perfil anunciado continua B-B):

1. `pdftool validate` com fixtures **reais** (A1 real + TSA de produção);
2. **validação externa independente** (DSS da Comissão Europeia com política confiando na AC
   interna, e um leitor PDF de referência), registrada por release;
3. TSA de produção (checklist acima);
4. ligar o `PadesBtSigner` na finalização, com degradação explícita e registrada para B-B;
5. decisão registrada em `arquitetura.md`.

**Técnicas:**

- Validação externa de A1 real de participante, revogação (LCR/OCSP) e âncoras ICP-Brasil: não
  feitas (sem rede, sem raízes fixadas).
- E-mail avisando o participante de que o documento está pronto para o envio do certificado.
- Permissão própria `manage_legal_holds` no catálogo e espelho dos eventos de `retention_events`
  em `AuditEventType`: não feitos de propósito — acrescentar uma permissão muda a matriz de
  funções mostrada a todos, mesmo com a flag desligada. Hoje a capacidade é derivada
  (`RetentionAuthorization`).
- Eventos próprios de auditoria do dossiê (hoje `envelope.downloaded` com `type=dossier`).
- Custódia persistente do PFX: desenho em `a1-do-participante.md` §6.3, **não implementada**.

## 10. Arquivos da integração

Novos: `app/Console/Commands/ParticipantA1MaintainCommand.php`,
`tests/Feature/EndToEnd/Phase2OndaCTest.php`, `tests/Feature/Phase2/OndaCIntegrationTest.php`,
`tests/Browser/Support/playwright-orphan-guard.php`, `tools/pdftool/tests/test_certs_describe.py`,
este relatório.

Alterados: `app/Http/Middleware/HandleInertiaRequests.php`,
`app/Http/Controllers/Envelopes/{EnvelopeController,EnvelopeEvidenceController,EnvelopeBulkController}.php`,
`app/Http/Controllers/{FolderController,Settings/GeneralController}.php`,
`app/Services/Retention/LegalHolds.php`, `app/Services/Identity/CapturePurge.php`,
`app/Services/Timestamp/TimestampEvidence.php`, `app/Services/Verification/PublicVerification.php`,
`app/Services/Dossier/DossierBuilder.php`, `config/assinavelox.php`, `.env.example`,
`routes/console.php`, `resources/js/layouts/settings/layout.tsx`,
`database/seeders/DemoOrganizationSeeder.php`, `tests/BrowserTestCase.php`,
`tests/Feature/Organizations/SharedPropsTest.php`, `tools/pdftool/pdftool/certs.py`,
`docs/testes.md` (§6-C) e a formatação de `docs/fase-2/{a1-do-participante,carimbo-e-dossie,retencao-e-preservacao}.md`;
helpers do Wayfinder regenerados.

## 11. Revisão adversarial

Dezenove achados (três lentes: custódia e criptografia; ciclo de vida, retenção e preservação;
produto, semântica e design), cada um com teste em `tests/Feature/Review/Phase2C/`. Todos
reproduziram antes da correção: 22 testes falhando em 17 arquivos. Depois da correção, os 22
passam. Nenhum teste de revisão ficou fora do JSON.

### 11.1 Custódia e criptografia

| Achado                                                              | Correção (causa-raiz)                                                                                                                                                                                                                                                                                                                            |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Redação da senha corrompia o stdout (fatos públicos do certificado) | `ParticipantCertificateTool::run()` não redige mais o stdout, que é o JSON público e que o pdftool nunca usa para imprimir a senha. A redação continua no stderr e na mensagem de erro. `TsaToolRunner` já não redigia o stdout.                                                                                                                 |
| PFX decifrado de worker morto ficava no tmp                         | `participant-a1:maintain` (a cada 15 min) apaga `a1-apply-*`, `a1-upload-*` e `a1-proc-*` do `pdftool.tmp_path` mais velhos que `max(lock_seconds, $timeout do job, tempos limite do pdftool, sealed_ttl)` + 5 min. Usa o arquivo mais recente do diretório, então nunca apaga o de um worker vivo. Documentado em `a1-do-participante.md` §6.1. |
| `tsa.chain_pem` copiado byte a byte para o dossiê                   | `OperatorTsaConfig::chainCertificatesPem()` reescreve só os blocos `CERTIFICATE`, e o dossiê grava isso. `tsa:status` mostra erro (e `chain_pem_contains_private_key` no JSON) quando o arquivo traz chave.                                                                                                                                      |
| Carimbo com certificado de TESTE exibido como produção              | Nova coluna `timestamp_tokens.test_certificate` (migration aditiva `2026_09_11_130104`), gravada por `recordOperator()`. `TimestampToken::isTest()` passa a ser `environment !== 'production' \|\| test_certificate`, a mesma regra do dossiê.                                                                                                   |
| CPF completo no `validacao.json`                                    | Função comum `CertificateInspection::maskSignatureSummary()`, usada no `validation_result` publicado e na revalidação do dossiê.                                                                                                                                                                                                                 |
| Exceção da TSA carregava a senha (via `Process::getEnv()`)          | `TsaToolRunner` e `PdfToolClient` não encadeiam mais a exceção do Symfony Process. Registram só a classe e o stderr redigido.                                                                                                                                                                                                                    |

**Não recusado, por decisão:** a emissão com TSA de teste em `environment=production` e o PEM
da cadeia com chave continuam funcionando. O teste de revisão exige a emissão concedida nesses
cenários, e a chave nunca sai: o dossiê só leva certificados e a tela mostra teste. Os dois
casos aparecem como aviso e erro em `tsa:status`. Transformar isso em recusa (`missing()`)
fica como decisão do proprietário.

### 11.2 Ciclo de vida, retenção e preservação

| Achado                                                                 | Correção                                                                                                                                                                                                                                                                                                                                                                                  |
| ---------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Remover ou substituir o arquivo de um rascunho preservado apagava tudo | `DocumentIntake::remove()` e a substituição em `store()` (flag `multi_document` desligada) chamam `LegalHolds::guardEnvelope()` antes de qualquer trabalho (`document_remove` / `document_replace`), com o autor na trilha. A exceção se renderiza como resposta de tela.                                                                                                                 |
| A expiração própria do dossiê apagava o ZIP preservado                 | `PurgeExpiredDossierExports` carrega `LegalHolds::snapshot()` por organização e pula o pedido coberto (documento, pasta ou organização), com `recordBlocked` no máximo 1 vez por dia. O link assinado vence sozinho; o arquivo sai na primeira execução depois da liberação.                                                                                                              |
| A categoria "Dossiês" apagava o lote com documento preservado          | `CategorySweeper::dossiers()` confere `envelope_ids` linha a linha com o novo `LegalHolds::coveringAny()`, inclusive por pasta e no _dry-run_.                                                                                                                                                                                                                                            |
| O lote sobrevivia à exclusão do envelope                               | `EnvelopePurger` inclui no recibo (caminhos e contagem `bulk_dossier_exports`) os lotes da organização que contêm o envelope. O ZIP sai e a linha vira `expired`, sem caminho e sem a referência ao envelope. **Exceção:** se outro envelope do mesmo lote está preservado, o ZIP fica (`bulk_dossier_exports_kept_by_hold` no recibo) e a expiração própria o apaga depois da liberação. |
| Notificações do sino sobreviviam                                       | `EnvelopePurger::deleteRows()` apaga as linhas de `notifications` cujo `data` contém o ULID do envelope (em `envelope_ulid` ou na URL) e soma `notifications` no recibo. A exclusão da organização continua apagando as notificações dos usuários removidos. Não verifiquei se ela passa pelo `EnvelopePurger` para os usuários que sobrevivem.                                           |
| Recusar envio do formulário excluía rascunho preservado                | `PublicFormReview::reject()` chama `guardEnvelope(..., 'public_form_reject', $user)` antes de mudar o envio, que continua em revisão.                                                                                                                                                                                                                                                     |

### 11.3 Produto, semântica e design

| Achado                                                 | Correção                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| ------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Beco sem saída de quem volta para enviar o certificado | `ParticipantCertificateService::awaitsReturningSigner()` vale quando a pessoa já aceitou, o recurso é oferecido, o envio está aberto e o pedido está ausente, registrado ou com falha. Enquanto isso, `Challenges` aceita pedir e conferir o código, e `SenderPins` o PIN, **só para reabrir a janela de download**: nenhuma sessão de assinatura é autenticada e o evento leva `purpose: participant_certificate`. O comprovante traz `otp` e `signer_auth`, e a página mostra o formulário do código. O cartão troca a nota sem ação por um botão "Receber código". |
| Comprovante prometia "sem assinatura criptográfica"    | `ConsentText::completionNotice(..., $participantCertificateOffered)` passa a dar uma previsão condicional quando o recurso é oferecido à pessoa, no aviso antes do aceite e no comprovante. Sem o recurso, o texto não muda.                                                                                                                                                                                                                                                                                                                                          |
| Selo verde "Íntegra e válida" com certificado de teste | No público, o selo fica neutro com "Íntegra — certificado de teste" e a linha diz que "o certificado é de teste e não tem validade jurídica". Certificados reais mantêm o rótulo. A página de evidências (`forEvidence`) não foi alterada.                                                                                                                                                                                                                                                                                                                            |
| Retenção sem prévia do que será apagado                | `RetentionRunner::preview()` conta sem apagar e respeita a preservação. As props trazem `deletion_preview` (contagem por categoria, preservados, total, `next_run_at` às 04:25). Nova rota `GET settings.retention.preview` refaz a conta com os prazos do formulário, e a confirmação mostra os números e a próxima execução.                                                                                                                                                                                                                                        |
| Marcador "(s)"                                         | Ternário por contagem, incluindo "revisão incremental" no singular.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| Rótulo citava PAdES-B-T                                | "Carimbo do tempo da assinatura". O perfil (B-B) fica só no texto fixo (T2).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| Seletor do certificado sem foco visível                | O input fica `peer sr-only` e o label usa `peer-focus-visible:border-primary` e o anel.                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |

### 11.4 Asserções alteradas

- `tests/Feature/Review/Phase2C/ParticipantPasswordRedactionCorruptsFactsTest.php`, linha 54:
  `toBeInstanceOf(Illuminate\Support\Carbon)` passou a `CarbonInterface`. A aplicação usa
  `Date::use(CarbonImmutable::class)` (`AppServiceProvider`), então o cast devolve
  `CarbonImmutable`. O teste falhava por isso depois da correção real, e o que ele quer provar
  (data válida, ano certo) continua conferido.
- `tests/Feature/Smoke/AllGetRoutesTest.php`: **acréscimo** de `settings.retention.preview`
  aos overrides (404 com a flag `retention_policies` desligada, 403 sem `manage_settings`).
  Nenhuma linha existente mudou.
- Nenhuma outra asserção existente foi alterada. Com as flags desligadas, os textos da Fase 1
  ("sem assinatura criptográfica" em `SignerConsentTest`/`FullLifecycleTest`) não mudam.

### 11.5 Verificações depois da revisão

| Verificação                                               | Resultado                                                                  |
| --------------------------------------------------------- | -------------------------------------------------------------------------- |
| `tests/Feature/Review/Phase2C`                            | **22 de 22** (95 asserções)                                                |
| `php artisan test --testsuite=Unit,Feature --parallel`    | **1.516 de 1.516** (13.117 asserções)                                      |
| `php artisan test --testsuite=Browser`                    | 36 passando, 3 pulados de 39 (740 asserções), os mesmos 3 pulados de antes |
| `tools/pdftool`: `pytest -q`                              | 139 passando                                                               |
| `vendor/bin/phpstan analyse`                              | 0 erros                                                                    |
| `vendor/bin/pint --dirty`                                 | verde                                                                      |
| `npm run types:check` / `npm run check` / `npm run build` | OK / OK / OK                                                               |
| `php artisan wayfinder:generate --with-form`              | OK (rota nova)                                                             |

Arquivos novos: `database/migrations/2026_09_11_130104_add_test_certificate_to_timestamp_tokens_table.php`.
Alterados, fora os já citados: `DocumentIntake`, `PublicFormReview`, `PurgeExpiredDossierExports`,
`CategorySweeper`, `EnvelopePurger`, `LegalHolds` (`coveringAny`), `RetentionRunner`,
`RetentionPresenter`, `Settings/RetentionController`, `routes/web.php`, `Challenges`,
`SenderPins`, `OtpController`, `SignerPageProps`, `ParticipantCertificateService`, `ConsentText`,
`ParticipantSignatureViews`, `ParticipantSignatureNarrative`, `ParticipantSignatureStage`,
`CertificateInspection`, `OperatorTsaConfig`, `TsaStatusCommand`, `TsaToolRunner`,
`PdfToolClient`, `ParticipantCertificateTool`, `TimestampToken`, `TimestampTokens`,
`resources/js/{pages/sign/show,pages/settings/retention,components/certificates/participant-certificate-card,components/verification/crypto-signature-list,components/retention/types}.tsx|ts`.
