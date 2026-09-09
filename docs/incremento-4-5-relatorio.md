# Incrementos 4 e 5 — relatório de integração

> **Documento histórico.** Registra o fechamento dos incrementos 4 e 5 e os números daquela
> rodada: 752 testes / 5 269 asserções no fechamento dos incrementos, 783 depois da revisão
> adversarial registrada no fim deste documento. Os números atuais e o estado consolidado da Fase 1
> estão em **`docs/entrega-fase-1.md`**; a implantação em **`docs/implantacao.md`** e a
> operação em **`docs/operacao.md`**. O contorno `-d extension=intl` dos comandos **não é mais
> necessário** (a extensão foi habilitada).

> Escopo: finalização do envelope, página de evidências, verificação pública, downloads
> autorizados (incremento 4) e planos, cobrança e Mercado Pago (incremento 5).
> Este documento fecha os dois incrementos costurando o que cinco agentes entregaram em
> paralelo. Ele registra o que foi **verificado com comando ou navegador**, o que foi
> **corrigido na integração** e o que continua **pendente**.

## 1. Estado verificado

Todos os números abaixo vêm de execuções reais, não de estimativa.

| Verificação                | Comando                                                                                 | Resultado                                            |
| -------------------------- | --------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| Suíte completa             | `php -d extension=intl artisan test`                                                    | **752 testes, 752 verdes, 5.269 asserções** (~8 min) |
| Análise estática           | `php -d extension=intl -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G` | **0 erros**                                          |
| Estilo PHP                 | `vendor/bin/pint --test`                                                                | passou                                               |
| Tipos do front             | `npm run types:check`                                                                   | **0 erros**                                          |
| Formatação e lint do front | `npm run check`                                                                         | 174 arquivos formatados, 145 sem avisos              |
| Build de produção          | `npm run build`                                                                         | OK (~19 s)                                           |
| Rotas tipadas              | `php -d extension=intl artisan wayfinder:generate --with-form`                          | gerado sem divergência                               |

Partida: 747 testes verdes no início desta rodada. Os 5 novos são o ciclo de vida completo
(§3). Nenhum teste existente precisou ser alterado para acomodar as correções.

## 2. O que foi corrigido na integração

Seis defeitos reais, cada um na costura entre duas entregas paralelas.

### 2.1 O detalhe do envelope não sabia se havia assinatura criptográfica

`EnvelopeDetailResource` não publicava `signature_status` nem `certificate`, mas
`resources/js/pages/envelopes/show.tsx` já os lia. Sem o dado, o painel de conclusão caía
num terceiro estado neutro ("Documento concluído", manda o leitor às evidências) — honesto,
mas inútil: a tela nunca conseguia dizer o que de fato aconteceu.

Correção: o resource passou a publicar os dois campos, derivados de
`SignatureNarrative::for()` e **`null` enquanto não houver registro de verificação** — a
distinção entre "não há assinatura" (`none`) e "ainda não se sabe" é preservada de
propósito. `EnvelopeController@show` carrega `verificationRecord.certificateReference`.

Efeito visível (QA §4): o envelope com certificado passou a dizer "Concluído e assinado
digitalmente pela operadora", exibir o aviso de certificado de teste e rotular o botão
"Baixar PDF assinado"; o envelope sem certificado diz "Concluído com aceite eletrônico e
evidências" e "Baixar arquivo final".

### 2.2 O resultado técnico da validação era lido do nível errado do JSON

**O defeito mais sério encontrado.** A finalização grava `verification_records.validation_result`
como um envelope com os fatos negativos explícitos no topo e o resultado bruto da
`pdftool validate` aninhado em `result` (`OperatorSignature::validationPayload()`):

```json
{"signed": true, "profile": "PAdES-B-B", "environment": "test", "timestamp": null,
 "long_term_validation": false, "revocation": "not_checked", "reason": null,
 "result": {"signature_count": 1, "all_intact": true, "all_valid": true,
            "all_trusted": true, "trust_roots_configured": 1, ...}}
```

`SignatureNarrative::validation()` lia `all_intact`, `all_valid`, `all_trusted` e
`trust_roots_configured` no **nível de cima**, onde eles não existem. Consequência: toda
conclusão assinada aparecia na página pública e na página de evidências como
_"integridade não verificada"_ e _"cadeia de certificação não verificada"_ — mesmo com a
validação tendo confirmado ambas.

O erro era para o lado conservador (subafirmava, nunca superafirmava), mas era erro: o
produto deixava de mostrar a única evidência técnica que possui.

Correção: a leitura passa por `result` quando ele existe, com alternativa para linhas
achatadas antigas; quando nem uma nem outra traz os campos de integridade, o caminho
honesto de "resultado não registrado" continua valendo. `revocation` é lida do nível mais
específico. Pinado por asserção em `FullLifecycleTest` para não regredir.

### 2.3 `password.confirm` num POST era um beco sem saída

Reproduzido em teste antes de corrigir: `POST billing.cancel` sem senha confirmada grava
`url.intended` = **página anterior**, não a rota do POST — `Redirector::guest()` só guarda a
URL corrente quando a requisição interrompida é um GET. O usuário confirmava a senha,
voltava à tela de cobrança, e o cancelamento simplesmente não acontecia, sem aviso nenhum.
O mesmo valia para "Voltar para o Grátis" e para a solicitação de exclusão da organização.

Correção: `resources/js/components/confirms-password.tsx` — a senha é pedida **antes** da
ação. O modal consulta `GET /user/confirmed-password-status` e, se preciso, chama
`POST /user/confirm-password` (ambos do Fortify, ambos com resposta JSON quando o `Accept`
pede JSON), e só então dispara o POST original. O middleware continua nas rotas: ele é a
garantia do servidor; o modal é a ergonomia do cliente. Ligado em `settings/billing.tsx`,
`settings/plans.tsx` e `settings/general.tsx`.

Verificado no navegador (§4.5): senha errada → "A senha informada está incorreta."; senha
certa → o cancelamento é aplicado na mesma interação, com toast e botão virando "Reativar
renovação".

### 2.4 O comprovante prometia arquivo final a quem assinou um envelope que expirou

Quem já tinha assinado mantém o link e continua vendo o comprovante — é a intenção
declarada de `SignerLinkResolver::stateFor()`. Mas o `screen` continuava
`already_signed_pending_others`, e o comprovante lia _"você foi o último a assinar … o
arquivo final está sendo preparado"_ (com zero pendentes) ou _"você receberá o arquivo
final quando todos concluírem"_. Nos dois casos falso: a coleta encerrou e nenhum arquivo
final virá. É exatamente o mesmo problema que motivou a criação de `STATE_FINALIZING`.

Correção: `SignerPageProps::receipt()` passou a publicar `collection_closed`
(`expired | canceled | refused | null`), e `receipt-card.tsx` ganhou o quarto estado —
título "Coleta encerrada", texto que explica o encerramento e afirma que o aceite continua
registrado, e o botão desabilitado passa a dizer "Sem arquivo final" em vez de "Disponível
quando todos assinarem". Nenhuma tela nova, nenhum enum novo. Coberto por asserção no
cenário de expiração de `FullLifecycleTest`.

### 2.5 A verificação pública afirmava participantes pendentes quando não havia

Um envelope em `finalizing` é publicado como `in_progress` (decisão deliberada de
`docs/verificacao-publica.md` §1: o estágio interno do pipeline não é assunto público). O
selo de `in_progress` em `seal.ts` dizia "Ainda há participantes pendentes" — falso para
`finalizing`, em que todos já assinaram. O ramo `finalizing` do próprio `seal.ts` era código
morto, porque o servidor nunca envia esse valor.

Correção: o texto de `in_progress` passou a ser "O documento ainda não foi concluído. Os
resumos do arquivo final serão publicados na conclusão." — verdadeiro nos dois casos. O
ramo `finalizing` ficou, documentado como defensivo. A lista de participantes logo abaixo
continua mostrando quem assinou e quem não, que é onde o detalhe pertence.

### 2.6 Outros dois

- **Ordenação dos pagamentos.** `BillingController@index` ordenava por `id` mas a tabela
  exibe `paid_at ?? created_at`, deixando as datas visíveis fora de ordem. Passou a ordenar
  por `COALESCE(paid_at, created_at) DESC, id DESC` — o valor exibido.
- **`validation_result` do seeder de demonstração.** Gravava `{"valid": true, "validator":
"pyhanko"}`, forma que nenhum registro real tem. Passou a gravar o envelope real com
  `result: null` **de propósito**: os bytes do envelope de demonstração são de fábrica e
  nenhuma `pdftool validate` rodou sobre eles; fabricar `all_intact = true` faria a página
  pública afirmar uma integridade que ninguém verificou.

Também corrigida a formatação de `docs/cobranca.md` e `docs/finalizacao-e-evidencias.md`,
que faziam `npm run check` falhar no repositório inteiro, e atualizado o comentário de
`PreparationAndSigningTest` que ainda dizia que o incremento 4 não existia.

## 3. Teste ponta a ponta — `tests/Feature/EndToEnd/FullLifecycleTest.php`

Cinco cenários, 253 asserções, ~58 s. Estende `PreparationAndSigningTest`, que parava em
`finalizing`, e vai até onde o produto termina. Regra do arquivo: **nada de atalho de
fábrica no caminho do usuário** — criar, subir o PDF, posicionar campos, enviar, autenticar,
aceitar, baixar e verificar passam pelas rotas HTTP reais; só o certificado da operadora é
montado direto, porque o usuário não faz isso. A finalização roda de verdade (com
`QUEUE_CONNECTION=sync` o `EnvelopeReadyForFinalization` despacha `FinalizeEnvelope` dentro
da requisição do último aceite — o mesmo código do worker).

### 3.1 Ciclo completo com certificado A1 de teste

Dois signatários, ordem sequencial, PDF real de 2 páginas, três campos por pessoa
(assinatura desenhada e texto na página 1, data na página 2). O que é afirmado:

- envelope em `completed`, com `completed_at`, `final_document_version_id` e a versão
  congelada no envio **inalterada**; dois `SignatureAcceptance`, ambos apontando para ela;
- as quatro versões na ordem do pipeline: `original → consolidated → evidence → final`;
- **cada valor autorizado dentro do retângulo do seu campo, na página dele** — conferido
  por extração de texto com coordenadas, convertidas por `FieldGeometry::toPdfRect`;
- a data é a do **servidor**: o teste envia `01/01/1970` de propósito e essa string não
  aparece no PDF;
- rodapé `Verifique em … · código XXXX-XXXX-XXXX` presente; página de evidências anexada,
  com os dois nomes e a tabela de resumos;
- `pdftool inspect` acha **uma** assinatura; `pdftool validate` com o certificado de teste
  como raiz confiável devolve íntegra, válida, `trusted`, cobertura `ENTIRE_FILE`, titular
  com "TESTE";
- `verification_records` com os **quatro** resumos conferindo com as versões
  correspondentes, `final_sha256 == hash_file('sha256', arquivo baixado)`, e esse resumo
  **ausente de dentro do próprio PDF**;
- o resultado técnico chega à página pública como `integrity = intact`,
  `chain_trust = trusted`, `revocation = not_checked` (a asserção que pina §2.2);
- a página pública encontra o código e **não vaza** e-mail, nome completo, user-agent, IP,
  valor de campo, identificação interna `AV-…` nem o resumo consolidado;
- download: anônimo não passa, usuário de outra organização recebe **404**, o dono recebe
  200 e os bytes conferem com `final_sha256`; o relatório de evidências também baixa;
- **cota confirmada uma única vez**: um `plan_consumption` em `committed`,
  `envelopes_used = 1`, `envelopes_reserved = 0`;
- trilha contendo `envelope.sent`, `acceptance.recorded`, `envelope.finalizing`,
  `envelope.consolidated`, `envelope.evidence_generated`, `envelope.signed_company_a1`,
  `envelope.completed`, `plan.consumption_committed`, e **sem** `envelope.finalization_failed`.

### 3.2 Ciclo completo sem certificado

Ordem paralela. Conclui com `signature_status = none`, `signature_profile` nulo,
`certificate_reference_id` nulo, `final_sha256` conferindo com o arquivo, e
`pdftool inspect` achando **zero** assinaturas — nada é simulado.

O teste então varre **quatro superfícies** (detalhe, evidências, verificação pública e o
texto do PDF final) e afirma que nenhuma delas contém "assinado digitalmente", "assinatura
digital" ou "ICP-Brasil". As três telas dizem, cada uma com suas palavras, _aceite
eletrônico com evidências_.

### 3.3 Recusa

Envelope em `refused`, sem arquivo final, **sem registro de verificação**, com a única
versão sendo a `original`. Um único `plan_consumption` (não conta duas vezes). A página
pública responde `refused`, sem hash final, sem o motivo da recusa e sem dado pessoal.

### 3.4 Expiração

Uma pessoa assina, o prazo vence, `php artisan envelopes:expire` encerra. Envelope
`expired`, sem arquivo final, sem registro de verificação; **o aceite já gravado
permanece** — expirar não apaga evidência. Quem não assinou vê `invalid`, não `expired`:
`ExpireEnvelopes` revoga os links de quem não assinou e revogação é, por decisão de
`SignerLinkResolver`, indistinguível de token desconhecido. Quem assinou mantém o link e o
comprovante, agora com `collection_closed = 'expired'` (§2.4).

### 3.5 Conferência pelo resumo

O arquivo baixado tem exatamente o resumo publicado; a rota de conferência responde
`signed`. Um byte alterado muda o resumo, a conferência responde `none` e a
`pdftool validate` deixa de confirmar a integridade.

## 4. QA visual (navegador real)

Servidor local em `http://127.0.0.1:8127` sobre `database/i3.sqlite`
(`migrate:fresh --seed`), encerrado ao fim. **Nota:** `php artisan serve` **não** repassa
`DB_CONNECTION`/`DB_DATABASE` ao subprocesso (o `ServeCommand` filtra o ambiente quando há
`.env`); a primeira tentativa consultou o banco do `.env`. Foi trocado por
`php -d variables_order=EGPCS -S 127.0.0.1:8127 …/resources/server.php` a partir de
`public/`, que recebe as variáveis. Fica o registro para quem for repetir.

Além do seeder, dois envelopes foram **finalizados de verdade** (pipeline completo, PDF
real, certificado de teste ligado e desligado), porque os envelopes do seeder têm bytes de
fábrica e não permitiriam conferir download, hash nem assinatura.

| Tela                                     | O que foi conferido                                                                                                            | Resultado                                                                                                                                                       |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Detalhe, **com** certificado             | painel de conclusão, aviso de certificado de teste, rótulo do botão, código de verificação                                     | "Concluído e assinado digitalmente pela operadora"; "Baixar PDF assinado"; aviso de teste presente — todos dependem da correção §2.1                            |
| Detalhe, **sem** certificado             | mesma tela                                                                                                                     | "Concluído com aceite eletrônico e evidências"; "Baixar arquivo final"; nenhum aviso de certificado                                                             |
| Evidências                               | identificação, participantes, trilha, situação da assinatura, certificado, resultado técnico, 5 resumos, conferência local, QR | tudo presente e coerente; **"Integridade: Íntegro na conclusão"** e **"Cadeia validada até uma raiz de confiança"** — a correção §2.2 visível                   |
| Evidências — conferência de arquivo      | o PDF final real (2 MB) injetado no `input[type=file]`                                                                         | **"Confere."** com SHA-256 `3ac7b5b…4b8a`, idêntico ao registrado. Caminho WebCrypto exercitado ponta a ponta                                                   |
| Verificação pública, `company_a1`        | selo, estado, participantes mascarados, resumos, certificado, resultado técnico                                                | correto; certificado rotulado "Teste (sem valor para uso real)" e "não é ICP-Brasil"                                                                            |
| Verificação pública, `none`              | as mesmas seções                                                                                                               | "Concluído com aceite eletrônico e evidências"; sem bloco de certificado; nenhuma menção a assinatura digital                                                   |
| Verificação pública — arquivo certo      | mesmo PDF injetado                                                                                                             | "Confere."                                                                                                                                                      |
| Verificação pública — arquivo alterado   | 1 byte invertido no meio do arquivo                                                                                            | "Não confere.", com texto que pede conferir o arquivo antes de concluir adulteração — redação cuidadosa, sem acusar                                             |
| Verificação pública — `finalizing`       | envelope do seeder                                                                                                             | "Em andamento" com o texto corrigido em §2.5                                                                                                                    |
| Verificação pública — código inexistente | `ZZZZ-ZZZZ-ZZZZ`                                                                                                               | "Nenhum documento encontrado", com a dica do alfabeto sem `0/1/O/I`                                                                                             |
| Plano e cobrança                         | faixa de sandbox, card do plano, uso do ciclo, forma de pagamento, dados de faturamento, pagamentos                            | tudo presente; pagamentos agora em ordem de data visível (§2.6); avisos "não é documento fiscal" e "não guardamos cartão" no lugar                              |
| Cancelar renovação                       | senha errada e senha certa                                                                                                     | senha errada recusa com mensagem; senha certa aplica o cancelamento na hora (§2.3)                                                                              |
| Planos                                   | aviso de preço de desenvolvimento, selo Sandbox por card, rodapé de como a cobrança funciona                                   | presente nos três lugares; Empresarial como "Sob consulta" desabilitado                                                                                         |
| **375 px**                               | verificação pública, evidências, detalhe, cobrança, planos                                                                     | nenhuma página rola na horizontal; a tabela de pagamentos (640 px) rola dentro do próprio contêiner `overflow-x: auto` de 341 px, que é o comportamento correto |
| Console                                  | todas as telas acima                                                                                                           | limpo. O único erro registrado foi o `422` do teste deliberado de senha errada                                                                                  |

### 4.1 PDF de evidências

Não foi possível **renderizar** o PDF no painel do navegador (ele força download; e o venv
do pdftool não tem rasterizador). A verificação foi estrutural, com `pypdf`, e é mais forte
que uma captura de tela:

- 3 páginas, **cada uma** com o rodapé `Verifique em …` e o código `68GP-9YVU-SDTZ`;
- **cada uma** com uma imagem 148×148 — o QR;
- o QR extraído da página 1 foi comparado pixel a pixel com o QR regerado por
  `QrCode::dataUri()` para a URL pública com o código: **21.904 pixels, zero divergentes**.
  O QR aponta mesmo para a página de verificação daquele documento;
- no PDF final (5 páginas = 2 do documento + 3 de evidências), a imagem da assinatura
  desenhada é invocada **exatamente duas vezes na página 1** (uma por signatário) e
  **zero vezes na página 2**, que só tem campos de data e texto. A presença do XObject no
  dicionário de recursos da página 2 é artefato do reportlab, não desenho.

## 5. Pendências reais

### 5.1 Marco em aberto: certificado A1 de **produção**

**Nada foi assinado com um A1 real de ICP-Brasil.** Tudo o que existe foi exercitado com
`gen-test-cert` — autoassinado, CN com "TESTE", rotulado `environment = test` em toda a
cadeia. Não foram exercitados: cadeia com AC intermediária, políticas de certificado
ICP-Brasil, leitura por verificador oficial (ITI), nem a senha vindo do ambiente real do
serviço. O marco só pode ser dado como cumprido depois disso.

### 5.2 Mercado Pago nunca falou com o provedor real

Todo o adaptador HTTP foi verificado com `Http::fake()` e o fluxo de negócio com o dublê.
Continuam pendentes de uma conta de teste: preferência real, `init_point` real, `back_urls`
reais, entrega de webhook real e a divergência de caixa do `data.id`. Sem
`MERCADOPAGO_ACCESS_TOKEN` o adaptador responde `isConfigured() = false` e o checkout diz na
tela que está desabilitado; sem `MERCADOPAGO_WEBHOOK_SECRET` o webhook responde 401 e não
processa nada. Os planos pagos continuam `is_sandbox` com preço fictício.

### 5.3 LibreOffice não está instalado

A conversão DOCX→PDF tem adaptador e dublê, mas nenhum caminho de conversão real foi
exercitado nesta rodada. Todo o ciclo de vida testado parte de PDF. Imagem→PDF passa pelo
`pdftool image2pdf`, que é real.

### 5.4 `EnvelopeStatus::Completed->label()` é "Assinado" — decisão de produto em aberto

Num envelope com `signature_status = none`, o badge diz **"Assinado"** logo acima de um
painel que diz "o arquivo final não tem assinatura criptográfica". É uma contradição
aparente na mesma tela, visível no QA.

**Não alterei**, e por um motivo: `ROUTES_AND_PAGES.md` §6.1 prescreve explicitamente o
badge "Assinado" para `completed`, `RECONCILIACAO.md` não o revoga, e a leitura "todos os
participantes assinaram" é defensável — o mesmo rótulo vale para `RecipientStatus::Signed`,
onde é incontroverso. Mudar o enum ecoaria em listas, abas, filtros e testes. A decisão é de
produto. Recomendação: usar "Concluído" para o envelope (a própria tabela do documento já
oferece "Assinado / Concluído(s)") e deixar "Assinado" só para o participante. Enquanto isso
não se decide, `SignatureNarrative::statusLabel()` já garante que a **verificação pública**
diga "Concluído · aceite eletrônico com evidências".

> **Resolvido na revisão adversarial** (§7.4, achado _l_): o rótulo passou a derivar de
> `SignatureNarrative::completedLabel()` — "Assinado" só com `signature_status = company_a1`,
> "Concluído" com `none` — no detalhe, na lista, na busca e na página de evidências. O enum
> continua como estava; a mudança é das camadas que enxergam o `VerificationRecord`.

### 5.5 Menores

- `recipients[].signature_image_url` continua `null` na página de evidências: não existe
  rota autorizada para servir a imagem do disco privado. O PDF do relatório embute a imagem.
- Assinatura **digitada** sai na fonte padrão do PDF, não na Caveat (a fonte é carregada
  pelo navegador e não existe no servidor). `typed_font` continua gravado como evidência.
- O `compose` usa Helvetica/WinAnsi. Caracteres fora do WinAnsi em campos de texto virariam
  `?`; embutir um TTF é ponto de extensão de `ComposePlan::font()`.
- Estorno (`refunded`/`charged_back`) é gravado mas não tem consequência de negócio;
  encerrar o plano ou devolver cota não está decidido nem implementado.
- Registro de verificação **revogado** responde "não encontrado", igual a inexistente. Nada
  na Fase 1 revoga registros.
- Não há teste automatizado de front (o projeto não tem infraestrutura de teste JS); a
  verificação do front é a do §4, manual e no navegador.
- Ficou no disco `database/i3.sqlite` com os dados de QA (ignorado pelo git). Os PDFs
  temporários copiados para `public/` durante o QA foram removidos — `public/` está limpo.

## 6. Como reproduzir

```bash
# suíte, estática e build
php -d extension=intl artisan test
php -d extension=intl -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pint --test
npm run types:check && npm run check && npm run build

# só o ciclo de vida completo (exige o venv do pdftool)
php -d extension=intl artisan test tests/Feature/EndToEnd/FullLifecycleTest.php

# banco de QA e servidor que realmente usa esse banco
touch database/i3.sqlite
DB_CONNECTION=sqlite DB_DATABASE=database/i3.sqlite \
  php -d extension=intl -d variables_order=EGPCS artisan migrate:fresh --seed
cd public && DB_CONNECTION=sqlite DB_DATABASE=<caminho absoluto>/database/i3.sqlite \
  php -d extension=intl -d variables_order=EGPCS -S 127.0.0.1:8127 \
  ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

Credenciais de demonstração: `owner@horizonte.demo` / `password`.
Certificado de teste da operadora: `docs/finalizacao-e-evidencias.md` §7.

---

## 7. Revisão adversarial

Uma segunda leva de revisores atacou os incrementos 4 e 5 por três lentes — integridade
criptográfica e semântica de assinatura; cobrança, idempotência e concorrência; fidelidade ao
design e experiência — e trouxe **17 achados confirmados**, cada um com um teste que falhava.
Esta seção registra o que foi corrigido, como, e o que foi decidido de outro jeito.

### 7.1 O que os achados tinham em comum

Onze dos dezessete são a mesma família de erro: **a plataforma afirmando o que pretendia
fazer em vez do que fez**. A página de evidências dizia "este arquivo recebeu uma assinatura
digital" porque o certificado estava ligado quando ela foi renderizada; o comprovante do
signatário descrevia a conclusão pela configuração do momento da visita; a linha do tempo
pública dizia "e validada" porque existia um carimbo de data; a validação dizia "nada mudou
depois da assinatura" olhando um campo que não fala sobre isso; a tela de login prometia
validade jurídica e certificado A1 como características fixas. Em todos, o dado honesto já
existia em algum lugar do código — só não era ele o consultado.

### 7.2 Integridade criptográfica e semântica de assinatura

**(a) O PDF final sem assinatura afirmava, dentro dele, ter recebido assinatura digital**
(crítico). A página de evidências é gerada **antes** da assinatura (para ficar coberta por
ela) e escrevia o bloco 5 a partir de `signature->isConfigured()` — a intenção. Numa retomada,
`FinalizationArtifacts::existing()` reaproveitava a página sem conferir se a configuração
continuava a mesma. Caminho real: a assinatura falha com o certificado ligado, o operador
desliga `COMPANY_CERT_ENABLED` para destravar o envelope, e a retentativa conclui com
`signature_status = none` e **zero assinaturas no PDF**, entregando ao cliente um arquivo que
afirma o contrário — a única superfície em que ele confia offline.

Correção: a variante impressa (situação de assinatura + impressão digital do certificado) é
gravada no evento `envelope.evidence_generated` da trilha; na retomada o finalizador compara e
descarta a página quando ela descreve outra execução. Uma consolidação refeita também a
invalida (os hashes impressos são daquele consolidado), e uma página refeita invalida o `final`
que a embutia. É a mesma guarda que `reusableFinal()` já tinha, estendida à etapa (b) e
encadeada.
_Teste: `tests/Feature/Review/EvidencePageSignatureClaimTest.php`._

**(b) O certificado impresso era um palpite** (alto). `configuredCertificateHint()` escolhia a
linha mais recente e ativa de `certificate_references` — tabela cujas linhas só nascem **depois**
de uma assinatura bem-sucedida. Na primeira finalização com um certificado novo (rotação anual,
troca de teste para produção ou o inverso) o arquivo saía identificando o certificado anterior, e
o aviso obrigatório "Certificado de ambiente de teste" seguia o ambiente do certificado
**palpitado**: um arquivo assinado por certificado de teste podia sair com identidade ICP-Brasil
de produção impressa e sem o aviso.

Correção: novo comando `pdftool cert-info --pfx --pass-env`, que devolve os metadados **públicos**
do certificado dentro do PKCS#12 configurado (titular, emissor, série, impressão digital,
validade) sem assinar, sem gravar e sem expor chave ou senha — a senha continua sendo lida pelo
processo filho a partir do NOME da variável de ambiente.
`OperatorSignature::configuredCertificate()` casa por impressão digital: havendo linha, ela é
reaproveitada; não havendo, o modelo é montado em memória **sem ser persistido** (a linha
administrativa continua nascendo só depois de uma assinatura real). Não conseguindo identificar,
a página **não imprime certificado nenhum** — imprimir outro seria pior.
_Teste: `tests/Feature/Review/EvidenceCertificateIdentityTest.php`._

**(c) A validação ignorava a cobertura da assinatura** (alto). `all_intact` responde apenas "os
bytes **cobertos** pela assinatura foram alterados?". Um PDF com uma atualização incremental
acrescentada depois da revisão assinada continua `intact`, `valid` e até `trusted`; o que
denuncia o acréscimo é `coverage` cair de `ENTIRE_FILE` para `ENTIRE_REVISION` (mais
`docmdp_ok = false` quando a alteração toca o catálogo). O `validate.py` coletava esses campos
por assinatura e ninguém os lia — e a página pública publicava "Íntegro na conclusão: a
validação não encontrou alteração no arquivo depois da assinatura".

Correção em quatro pontos: `validate.py` agrega `all_covering` e `all_docmdp_ok` (sem tocar em
`all_intact`, que tem outro significado); `ValidationResult` os expõe, com derivação
conservadora a partir das assinaturas quando a chave falta; `OperatorSignature::assertPublishable()`
exige os quatro para publicar um arquivo como assinado — na assinatura recém-aplicada **e** na
recuperação de um `final` de execução anterior; e `SignatureNarrative` só diz `intact` com
cobertura confirmada, com um texto próprio para o caso "assinatura íntegra, mas há conteúdo fora
da revisão assinada".
_Teste: `tests/Feature/Review/SignedRevisionIntegrityTest.php`._

**(d) O resumo publicado não era recalculado dos bytes** (médio). `existing()` só perguntava se o
arquivo existia; o `VerificationRecord` era montado com o `sha256` da **coluna** enquanto o
download entrega os **bytes**. Divergindo os dois entre a queda e a retentativa, a plataforma
publicava um resumo que não identifica o arquivo que serve — e a conferência "Conferir meu
arquivo" responderia "Não confere" para o arquivo verdadeiro.

Correção: `existing()` recalcula o `sha256` em streaming (`DocumentStorage::sha256()`) e descarta
o artefato quando diverge. Bytes ilegíveis não são apagados (isso destruiria a única cópia de
algo talvez recuperável): o artefato é apenas ignorado e refeito.
_Teste: `tests/Feature/Review/SignedRevisionIntegrityTest.php`._

**(e) Finalização retomada publicava assinatura que o arquivo não tinha** (crítico).
`verificationRecord()` devolvia o registro existente sem conferir se ele descrevia o arquivo
final daquela execução. Queda entre a etapa (f) e a (g) + certificado fora do ar ⇒ `final`
reconstruído sem assinatura, registro antigo reaproveitado dizendo `company_a1`,
`PAdES-B-B` e o `final_sha256` de um arquivo descartado.

Correção: o registro só é reaproveitado quando descreve a execução corrente (mesma versão final,
mesmo resumo, mesma situação de assinatura, mesmo perfil, mesmo certificado). Divergindo, é
**reescrito** com os valores correntes (`steps.verification_record = rewritten`, com `warning`
no log).
_Teste: `tests/Feature/Review/StaleVerificationRecordTest.php`._

**(f) A varredura "nenhuma superfície afirma assinatura digital" era decorativa** (médio).
`Pest\Expectation::toContain()` é **variádico**: o segundo argumento não é mensagem de falha, é
outra agulha, e sob `not` a expectativa passa assim que qualquer uma faltar. As três linhas mais
importantes da semântica de assinatura no teste de ponta a ponta passavam com a agulha proibida
presente.

Correção: `FullLifecycleTest` passou a usar `assertStringNotContainsString`, que aceita mensagem
de verdade, uma agulha por asserção. E `VacuousNegativeAssertionTest` deixou de ser só uma
demonstração: ele agora **varre a suíte inteira** atrás do padrão `not->toContain($a, $b)`
(ignorando comentários e vírgula final) e falha se aparecer de novo em qualquer teste.

### 7.3 Cobrança, idempotência e concorrência

**(g) A cota do plano Grátis nunca renovava** (alto). "5 documentos/mês" era, na prática, uma
cota vitalícia de 5: `applyCycle()` zera o consumo só por pagamento aprovado (e o Grátis não
gera pagamento), `markPastDue()` filtra `price_cents > 0`, e o `PlanLedger` só soma. Depois do
quinto envelope a organização ficava bloqueada com "cota esgotada" indefinidamente, com a tela
prometendo o contrário.

Correção: `SubscriptionLifecycle::renewFreeCycles()`, executado por `billing:dunning`, avança o
período de ciclo em ciclo até o corrente (uma organização parada por meses não ganha um ciclo por
execução), zera `envelopes_used` e **reconta** `envelopes_reserved` a partir das reservas ainda
abertas no ledger — a mesma regra de `applyCycle()`; zerar às cegas perderia envelopes em
trânsito. Idempotente por `where(status = active)` + `where(current_period_end = <lido>)`. Cada
renovação grava `subscription.renewed` na trilha.
_Teste: `tests/Feature/Review/FreePlanQuotaRenewalTest.php`._

**(h) Aviso de webhook cujo job se perde nunca era reprocessado** (alto). `shouldProcess()` só
reprocessava recibo `failed`; um recibo em `received` era tratado como "já em processamento"
para sempre, e todas as reentregas do Mercado Pago — a única rede de segurança que ele oferece —
respondiam `200 {"duplicate": true}`. Havia dois caminhos reais para o job sumir sem passar por
`failed()`: `PendingDispatch::shouldDispatch()` descartando **em silêncio** a mensagem de um job
`ShouldBeUnique` com o lock tomado (exatamente o cenário `payment.created` + `payment.updated`
que a unicidade existia para tratar), e worker morto entre a retirada da fila e o `handle()`.

Correção, nas duas pontas: `SyncMercadoPagoPayment` deixou de ser `ShouldBeUnique` — a
serialização que a unicidade dava agora é um lock de cache tomado **dentro** do `handle()`, que
devolve o job à fila em vez de descartá-lo —, e `shouldProcess()` passou a reprocessar recibos
`received`, com `warning` (`alert = billing_webhook_receipt_stuck`) para os que passam de 5
minutos sem desfecho. Reprocessar custa uma consulta `GET /v1/payments/{id}` e é seguro
(`ActivateSubscription` é idempotente por `activated_at` sob lock); perder o aviso deixava o
cliente pago e sem plano, sem nada em `failed` para alertar.
_Teste: `tests/Feature/Review/WebhookReceiptStuckTest.php`._

### 7.4 Fidelidade ao design, semântica e experiência

**(i) A verificação pública dizia "aplicada e validada" sem ter validado nada** (alto). O marco
vinha de `validated_at !== null`, sem olhar o conteúdo de `validation_result`. Agora o rótulo vem
do resultado: "e validada" só quando a integridade foi de fato afirmada; nos demais casos o marco
continua visível como "Assinatura criptográfica da operadora aplicada".

**(j) A página pública recebia o fato negativo da validação e não o exibia** (alto). Ela consumia
apenas `validation_summary`, que é `null` justamente no caso inconclusivo; o componente
`ValidationDetails` — montado pela página interna de evidências — nunca era montado ali. Agora é,
sempre que `signature_status = company_a1`, com `integrity_label`, `chain_trust_label` e
`revocation_label`.
_Teste (i) e (j): `tests/Feature/Review/PublicValidationSilenceTest.php`._

**(k) O comprovante do signatário descrevia a conclusão pela configuração atual** (alto). No
estado de comprovante, `completion_notice` vinha de `ConsentText::completionNotice()`, resolvido
pela configuração **no momento da visita** — falando no futuro ("Ao final, este documento será
concluído como…") numa tela intitulada "Documento concluído", e passando a prometer assinatura
criptográfica sobre um arquivo sem nenhuma assim que o certificado fosse ligado. Agora, com o
envelope em estado terminal, o texto é o `statement` de `SignatureNarrative::for()` — o mesmo que
alimenta as outras três superfícies. `completionNotice()` fica onde a conclusão de fato ainda não
aconteceu.
_Teste: `tests/Feature/Review/SignerReceiptCompletionNoticeTest.php`._

**(l) Badge "Assinado" em envelope concluído sem assinatura criptográfica** (médio). Isto estava
registrado no §5.4 como decisão de produto em aberto; a revisão mostrou a contradição na mesma
dobra da tela ("Assinado" a ~40 px de "o arquivo final não tem assinatura criptográfica"), e
`RECONCILIACAO.md` fixa a precedência de arquitetura §2 sobre ROUTES §6.1. Resolvido:
`SignatureNarrative::completedLabel()` devolve **"Assinado"** para `company_a1` e **"Concluído"**
para `none`, e alimenta o detalhe, a lista, a busca e o bloco Identificação da página de
evidências. `EnvelopeStatus::Completed->label()` **não** mudou (o enum é usado em contextos sem
registro de verificação), e "Assinado" continua sendo o rótulo do **participante**
(`RecipientStatus::Signed`), onde ROUTES §6.2 o exige e ele é verdadeiro.
_Teste: `tests/Feature/Review/CompletedWithoutSignatureBadgeTest.php`._

**(m) "Validade jurídica" na porta de entrada** (médio). O aside de `AuthLayout` e o hero da
home exibiam quatro selos, dois dos quais o produto não sustenta: a aceitação jurídica universal
é exatamente o que `docs/juridico/termos-de-uso.md` §3.5 nega ("Sem garantia de validade jurídica
universal"), e o A1 era anunciado como característica fixa mesmo sem certificado configurado. Os
dois foram trocados por afirmações que a plataforma cumpre sempre: "Aceite eletrônico com
evidências" e "Verificação pública por código". Divergência consciente de `DESIGN_SYSTEM.md`
§6.1, que prescreve os selos originais (inclusive o de ICP-Brasil), pela precedência de
`RECONCILIACAO.md`.
_Teste: `tests/Feature/Review/LegalValidityClaimTest.php`._

**(n) QR com metade da zona de silêncio da norma** (médio). A ISO/IEC 18004 exige 4 módulos;
a configuração e o padrão de `QrCode::png()` eram 2. Agora o padrão é 4, `QrCode` **eleva**
qualquer configuração menor (a norma é piso, não sugestão) e a célula do blade passou a 32 mm
para a margem sobreviver à impressão.
_Teste: `tests/Feature/Review/EvidenceQrQuietZoneTest.php`._

**(o) Contagem regressiva de prazo em documentos terminados** (médio). `expiresLabel()` produzia
"Expira em 02 out (23 dias)" em envelopes `completed`, `refused` e `canceled` — o marcador âmbar
de urgência ao lado do banner de conclusão. Agora devolve `null` em status terminal;
`Expired` mantém "Prazo encerrado em {data}".
_Teste: `tests/Feature/Review/TerminalEnvelopeExpiryLabelTest.php`._

**(p) O mesmo aceite com dois horários no seeder de demonstração** (médio). Carbon é mutável:
`$signedAt->setTimezone(...)` na montagem do campo `date` mudava o próprio `$signedAt`, e tudo o
que era gravado depois (valores de campo, sessão, link, evento `acceptance.recorded`) saía três
horas atrás do aceite — visível lado a lado na página de evidências e invertendo a ordem da linha
do tempo no PDF. Corrigido com `copy()`.
_Teste: `tests/Feature/Review/DemoSeedAcceptanceTimestampTest.php`._

### 7.5 O achado que foi resolvido de outra forma

**O relatório de evidências nunca imprime o "Resultado técnico da validação" exigido pela
declaração de aceite §5.2** (médio). O achado está correto no diagnóstico: o `@if` do template
era código morto por construção, `EvidenceData::build()` sempre recebia `validationResult: null`,
e **nenhum** PDF final trouxe o parágrafo — nem no caminho feliz com certificado.

A correção sugerida ("gerar a página de evidências depois da assinatura") **não é possível**, e é
importante dizer por quê: a página de evidências é anexada ao consolidado **antes** de assinar,
justamente para ficar coberta pela assinatura. Gerá-la depois exigiria anexá-la depois, o que
deixaria conteúdo fora da revisão assinada — `coverage = ENTIRE_REVISION` — e destruiria a
própria cobertura que o resultado técnico afirmaria. Seria trocar o achado (e) pelo achado (c),
no mesmo arquivo. É a mesma impossibilidade estrutural já reconhecida em §5.1 do documento
jurídico para o hash final: um dado apurado sobre o arquivo pronto não cabe dentro do arquivo.

Decisão tomada, com o registro exigido: **§5.2 de `docs/juridico/declaracao-de-aceite.md` foi
alterada**. No lugar da frase impossível, o bloco passa a dizer onde o resultado técnico é
publicado e por que ele não pode estar ali — texto que agora aparece em **todo** PDF final
assinado, ao contrário do parágrafo anterior, que não apareceu em nenhum. A justificativa
completa está registrada no próprio documento jurídico, logo abaixo do texto novo. O template
manteve o ramo positivo do `@if` para o caso de a apuração um dia acontecer antes da renderização.
_Teste: `tests/Feature/Review/EvidencePageSignatureClaimTest.php` (segundo caso) — passa com o
texto novo, sem precisar de alteração._

### 7.6 Testes que precisaram ser ajustados

Dois arquivos de teste da revisão foram reescritos porque a correção tornou o cenário
irreproduzível pelo caminho original — em ambos os casos a invariante testada foi **preservada**,
e o que saiu foi uma suposição intermediária:

- **`SignedRevisionIntegrityTest`**: o cenário original alterava os bytes do `final` no disco e
  esperava que a plataforma **servisse** esses bytes com o resumo antigo. Com a conferência de
  `sha256` em `existing()`, o artefato incoerente passa a ser descartado e refeito. O primeiro
  teste agora fixa a invariante que interessa (`final_sha256` = resumo dos bytes entregues) e
  ainda verifica que nem o resumo antigo nem os bytes alterados sobreviveram. O segundo teste
  passou a exercitar a **segunda barreira** de forma isolada: a alteração também acerta a coluna
  `sha256` (bucket adulterado com acesso ao banco, restauração inconsistente), a barreira do
  resumo não vê nada, e quem tem de segurar é a leitura de `coverage` — a finalização recusa
  publicar, o envelope fica em `finalizing` e nada é publicado. Um terceiro teste leva o resultado
  técnico real do arquivo alterado até a página pública e confirma que ela não o chama de íntegro
  nem diz "validada".
- **`VacuousNegativeAssertionTest`**: o teste entregue exigia que a forma variádica falhasse com a
  agulha presente — comportamento do Pest, que é vendor e não se corrige aqui. Ele passou a fixar
  esse comportamento (para que uma mudança futura do Pest apareça) e a varrer a suíte inteira
  atrás do padrão, que é o defeito de fato corrigível e o que impede a regressão.

O helper `validationSummary()` de `tests/Feature/Verification/Support/VerificationHelpers.php`
ganhou `all_covering`, `all_docmdp_ok` e `coverage`/`docmdp_ok` por assinatura: ele representa o
que a `pdftool validate` produz, e sem esses campos passaria a descrever uma saída que a
ferramenta não produz mais.

### 7.7 O que mudou fora do PHP

- `tools/pdftool/pdftool/validate.py`: agrega `all_covering` e `all_docmdp_ok`.
- `tools/pdftool/pdftool/certs.py` + `cli.py`: novo comando `cert-info`.
- `tools/pdftool/README.md`: documenta o comando novo, os agregados novos e o aviso de que
  `all_intact` não significa "o arquivo não mudou".
- `resources/views/evidence/page.blade.php`: variante do bloco 5 sem `validation_summary`,
  célula do QR em 32 mm e as regras de quebra de tabela (§7.9).
- `resources/js/pages/verify/show.tsx`: monta `<ValidationDetails>`.
- `resources/js/layouts/auth-layout.tsx` e `pages/marketing/home.tsx`: selos de confiança.
- `docs/juridico/declaracao-de-aceite.md` §5.2 (decisão registrada), `docs/pdf-pipeline.md`,
  `docs/finalizacao-e-evidencias.md`, `docs/verificacao-publica.md` e `docs/cobranca.md`.

### 7.8 O que **não** foi feito

- **Nenhum banco de produção foi tocado** e nenhuma migration nova foi criada: as correções
  couberam nas colunas existentes. A marca da variante da página de evidências vive no payload do
  evento `envelope.evidence_generated`, que é append-only e já era o registro de nascimento do
  artefato.
- **`EnvelopeStatus::Completed->label()` continua "Assinado"**. O rótulo honesto é aplicado nas
  camadas que têm acesso ao `VerificationRecord` (detalhe, lista, busca, evidências, verificação
  pública). Mudar o enum afetaria contextos onde o registro não está disponível e onde a
  distinção não pode ser feita — trocar uma afirmação falsa por outra imprecisa não é ganho.
- **`SearchController` e `EnvelopeResource` passaram a depender de `verificationRecord`** para o
  rótulo. Onde a relação não estiver carregada, o resource faz uma consulta pontual (só para
  envelopes `completed`). As três rotas que usam o resource já foram ajustadas com eager loading;
  qualquer uso novo deve fazer o mesmo.
- **Nada foi commitado** (instrução do escopo).

### 7.9 Dois achados que apareceram durante a correção

**O teste de ponta a ponta passou a apontar uma frase legítima.** Consertada a asserção
vazia (achado _f_), a varredura das quatro superfícies passou a valer de verdade — e
imediatamente pegou o próprio relatório de evidências, que na variante sem certificado diz
"nenhuma indicação de 'assinatura digital' deve ser esperada em leitores de PDF". A frase é
exatamente o que a semântica exige, e é uma **negação**. A varredura passou a removê-la antes
de procurar as agulhas, e a **conferir que ela está presente** antes de removê-la, para que a
remoção não possa mascarar uma regressão. Essa é a diferença entre a asserção antiga e a nova:
a antiga não teria notado nem a frase honesta nem uma desonesta.

**`tests/Feature/Review/EvidencePdfTableBreakTest.php` já estava vermelho.** Ele é de uma
revisão anterior, não consta da lista de achados desta rodada, e cobra duas regras de CSS que o
template não tinha: `thead { display: table-header-group; }` (é assim que o DOMPDF repete o
cabeçalho da tabela nas páginas seguintes) e `page-break-inside: avoid` nas linhas. Sem elas, as
tabelas longas do relatório — participantes, linha do tempo e resumos — atravessavam a página
deixando as colunas órfãs do próprio cabeçalho. Corrigido junto, porque a suíte tinha de ficar
verde e a correção é de três linhas de folha de estilo.

### 7.10 Estado final verificado

| Verificação                | Comando                                                                                 | Resultado                      |
| -------------------------- | --------------------------------------------------------------------------------------- | ------------------------------ |
| Suíte completa             | `php -d extension=intl artisan test`                                                    | **783 testes, 783 verdes**     |
| Análise estática           | `php -d extension=intl -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G` | **0 erros**                    |
| Estilo PHP                 | `vendor/bin/pint --test`                                                                | passou                         |
| Tipos do front             | `npm run types:check`                                                                   | **0 erros**                    |
| Formatação e lint do front | `npm run check`                                                                         | 175 formatados, 145 sem avisos |
| Build de produção          | `npm run build`                                                                         | OK (~21 s)                     |
| `pdftool` (comando novo)   | `python -m pdftool cert-info --pfx … --pass-env …`                                      | JSON com os metadados públicos |

Partida desta rodada: 752 testes. Os 31 novos são os testes da revisão adversarial (as três
lentes), incluindo três que rodam o pipeline real com pyHanko e certificado de teste.
