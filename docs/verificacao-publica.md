# Verificação pública, página de evidências e conferência de arquivo

> Incremento 4 · área B-VERIFY.
> Fontes: `docs/arquitetura.md` §2, §6 e §7 · `docs/design/ROUTES_AND_PAGES.md` §2.8, §2.19 e §4 ·
> `docs/design/RECONCILIACAO.md` §4 (Q13, Q14) · `docs/juridico/politica-de-privacidade.md` §11 ·
> `docs/juridico/declaracao-de-aceite.md` §5.
> Em caso de divergência entre estes documentos vale a ordem de precedência da `RECONCILIACAO.md`.

Duas páginas leem os mesmos fatos com públicos diferentes:

|                       | `/verificar/{code}`                                                      | `/documentos/{envelope}/evidencias`                                           |
| --------------------- | ------------------------------------------------------------------------ | ----------------------------------------------------------------------------- |
| Quem acessa           | qualquer pessoa com o código impresso no rodapé do PDF                   | quem já pode ver o envelope (`EnvelopePolicy@view`)                           |
| O que prova           | que existe um envelope naquele estado e qual é o resumo do arquivo final | a sequência completa de fatos do aceite                                       |
| Nomes                 | mascarados (`Maria A. S.`)                                               | por extenso                                                                   |
| E-mail, IP, navegador | **nunca**                                                                | e-mail e navegador sim; IP conforme `evidence_show_ip`                        |
| Resumos SHA-256       | enviado e final                                                          | os quatro (original, enviado, consolidado, final) + o da página de evidências |
| Arquivo               | nenhum link, nenhuma miniatura                                           | download por `envelopes.download` (rota do incremento 2)                      |

---

## 1. Verificação pública

### 1.1 O código

`envelopes.verification_code`: 12 caracteres do alfabeto base32 `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`
(sem `0`, `1`, `O` e `I`, que se confundem impressos), gerado no **envio** e exibido como
`XXXX-XXXX-XXXX`. A entrada é normalizada antes da consulta — hifens, espaços e caixa são
irrelevantes, de modo que `abcd efgh-jklm` e `ABCDEFGHJKLM` são o mesmo código.

### 1.2 Estados que respondem

Só envelopes **já enviados**: `in_progress`, `finalizing`, `completed`, `refused`, `expired` e
`canceled`. `finalizing` é reportado como `in_progress` — o estágio interno do pipeline não é
assunto de quem consulta de fora.

### 1.3 O que é exibido

- código de verificação e estado, com um rótulo que **corresponde ao que aconteceu**
  (§4 abaixo trata do caso `signature_status = none`);
- nome da organização remetente, título do documento, número de páginas;
- datas de criação, envio e conclusão;
- resumos SHA-256 do documento **enviado** e do arquivo **final**, com botão de copiar;
- situação da assinatura: `signature_status`, perfil (`PAdES-B-B`), dados do certificado da
  operadora quando `company_a1` (titular, emissor, validade, ambiente) e o resultado técnico da
  validação registrado na conclusão;
- participantes com nome mascarado, papel livre ("Locatária"), estado e data do aceite;
- marcos da linha do tempo (enviado, cada aceite, recusa, expiração, cancelamento, assinatura da
  operadora, conclusão).

### 1.4 O que **nunca** é exibido

E-mails, telefones, endereços IP, user-agents, geolocalização, códigos de uso único, tokens de
acesso, imagens de assinatura, valores de campos preenchidos, o texto integral do consentimento
gravado, a mensagem do remetente, a pasta, o nome de quem criou o envelope, identificadores
internos (`ulid`, `AV-00148`), dados de plano ou cobrança, e qualquer link para o PDF, para
miniaturas ou para download.

Também não saem daqui o resumo do arquivo **original** (antes da conversão) nem o
**consolidado**: são artefatos internos do pipeline e não ajudam quem quer conferir o arquivo que
tem em mãos.

Os marcos da linha do tempo são derivados dos **timestamps do domínio**, não de `audit_events`.
A trilha carrega IP, user-agent e payloads que não podem sair; reaproveitá-la aqui seria uma
porta permanentemente aberta para vazamento a cada evento novo.

Há um teste que trava esse contrato pelo **conjunto fechado de chaves** do resultado
(`tests/Feature/Verification/VerificationContractTest.php`). Uma lista de proibições não se
protege verificando só o que está presente: o campo indevido entra numa alteração futura e
ninguém percebe.

### 1.5 Por que a resposta é uniforme

Código inexistente, código de rascunho, envelope excluído e registro de verificação revogado
devolvem **exatamente a mesma** resposta: `found = false`, `result = null`, e a mesma cópia
("Nenhum documento encontrado com este código").

Sem isso a página vira um oráculo de enumeração: com respostas diferentes, quem varre códigos
descobre quais existem na plataforma — e a existência de um documento já é informação. A política
de privacidade §11 promete essa indistinguibilidade por escrito.

O caminho de execução também é o mesmo: **uma única consulta indexada** por
`envelopes.verification_code`, executada sempre, inclusive para códigos de formato impossível.
Não há atalho para o caso "não encontrado" nem consulta extra para "existe mas não é publicável"
— os dois terminam no mesmo `return null`, logo depois da mesma consulta.

Não se adiciona espera artificial para "igualar o tempo": ela não igualaria nada de verdade (o
custo dominante continua sendo a consulta) e transformaria uma rota anônima em amplificador de
negação de serviço.

### 1.6 Limites e cabeçalhos

- `throttle:public` (60/min por IP) no grupo, `throttle:20,1` em `verify.show` e
  `throttle:10,1` em `verify.check_file`. Sem captcha na Fase 1 (Q14).
- `X-Robots-Tag: noindex, nofollow, noarchive` e `Referrer-Policy: no-referrer` em `/verificar` e
  `/verificar/*`, aplicados por `App\Http\Middleware\SecurityHeaders` — **inclusive na resposta de
  "não encontrado"**, que é justamente a que um buscador ou um proxy encontraria.

### 1.7 Consulta pelo site institucional

O site em assinavelox.com.br oferece a mesma verificação em `GET /api/site/verificar/{code}`
(`Site\VerificationController`, `routes/site.php`): o `result` é o mesmo de
`PublicVerification::result()`, com a mesma resposta uniforme e os mesmos cabeçalhos
`noindex`/`no-referrer`. Nada é decidido lá sobre o que pode sair — ver
`docs/site-institucional.md` §2.2.

---

## 2. Conferência de arquivo

### 2.1 A comparação padrão é no navegador

O visitante escolhe o arquivo, a página calcula o SHA-256 com **WebCrypto** e compara com o
resumo publicado. O arquivo não sai do computador dele. É o que a arquitetura §6 determina
("comparação de arquivo local por SHA-256 calculado no navegador; sem upload") e o que a política
de privacidade §11 afirma por escrito: _"o hash é calculado no seu navegador e o arquivo não é
enviado"_.

O backend só precisa entregar o resumo correto — e entrega, em `result.hashes.final_sha256`
(e `sent_sha256`), sempre em minúsculas e no formato de 64 caracteres hexadecimais.

### 2.2 Decisão sobre a rota `POST /verificar/{code}/conferir`

A rota existe, mas **não aceita o arquivo**: aceita apenas um resumo SHA-256 já calculado
(`sha256`, 64 hexadecimais) e responde `signed` (confere com o arquivo final), `original`
(confere com a versão enviada aos signatários, não com o arquivo final) ou `none`.

A variante por upload prevista em Q14 foi avaliada e **recusada**, por três razões:

1. **tornaria falsa uma afirmação já publicada.** A política de privacidade e o aviso ao
   signatário dizem que o arquivo não é enviado. Um caminho de upload — mesmo opcional, mesmo
   descartando os bytes em seguida — faz da frase uma meia-verdade, e uma política de privacidade
   que precisa de asterisco não serve;
2. **não entrega nada que o cálculo local já não entregue.** O ganho seria a comodidade de
   arrastar o arquivo; o custo é receber, numa rota **anônima**, arquivos de até 25 MB sem dono,
   sem cota e sem trilha — memória, disco temporário e banda que qualquer pessoa da internet
   passa a poder consumir. É um alvo de negação de serviço criado para conveniência;
3. **o caso de uso restante não precisa de upload.** Quem não tem WebCrypto (contexto não seguro,
   navegador antigo, script) calcula o resumo com uma linha —
   `certutil -hashfile arquivo.pdf SHA256`, `sha256sum arquivo.pdf`,
   `Get-FileHash arquivo.pdf` — e cola o resultado. É exatamente o que a rota aceita.

Nada é persistido pela rota: ela compara com resumos que a própria página já publica e devolve
apenas o veredito. Por isso ela não revela nada que a consulta não tenha revelado, e um código
inexistente responde `none` — igual a uma conferência que não bate.

Se a variante por upload for reintroduzida um dia, ela exige, antes do código: alterar a política
de privacidade e o aviso ao signatário, limite de tamanho verificado **antes** de ler o corpo,
processamento em fluxo sem materializar o arquivo, descarte imediato, e um limite por IP bem mais
apertado do que 10/min.

---

## 3. Página de evidências (autenticada)

`GET /documentos/{envelope}/evidencias` → `Envelopes\EnvelopeEvidenceController@show`.

Autorização em duas camadas: o _route model binding_ é escopado por organização
(`BelongsToOrganization::resolveRouteBinding()`), então um envelope de outra organização devolve
**404 e não 403** — um 403 já confirmaria que o documento existe; e `Gate::authorize('view')`
aplica a policy (um `member` só vê os próprios envelopes).

O que a página monta:

- **identificação**: título, `AV-00148`, código de verificação, estado, datas, ordem de
  assinatura, arquivo, organização remetente (nome, razão social, CNPJ/CPF mascarado);
- **participantes**: nome, e-mail, papel, estado, método de autenticação e quando foi confirmado,
  quando o convite saiu, quando a abertura do link foi detectada, quando o aceite foi registrado,
  o tipo da representação visual, o resumo da versão que a pessoa viu, a versão dos termos, o
  texto integral da declaração aceita, o user-agent e o **IP conforme a política da organização**;
- **trilha de eventos** completa (`AuditEventResource`);
- **os quatro resumos** com a explicação de quais bytes cada um identifica (§4.2);
- **situação da assinatura** e o resultado técnico da validação (§4.1);
- **link para a verificação pública** com o código do envelope.

O PDF do relatório vem de `envelopes.download?type=evidence` (incremento 2). Esta página é a
versão navegável do mesmo conjunto de fatos e não gera arquivo nenhum.

### 3.1 `evidence_show_ip`

`organizations.settings.evidence_show_ip` vale `masked` (padrão), `full` ou `none` e é aplicado
por `App\Support\IpDisplay`, o mesmo ponto que a trilha e o card do signatário usam — as três
telas nunca discordam. Com `none` o endereço não aparece em canto nenhum da página, nem na
trilha. As props também devolvem `ip_policy`, para a tela poder explicar por que o valor está
mascarado ou ausente.

O user-agent **não** é governado por essa política: ele não é endereço, e a evidência precisa
citá-lo por extenso para ser conferível. Decisão registrada, não descuido — se a política mudar,
muda aqui.

### 3.2 Abertura detectada não é leitura

`invitation.opened` prova que **alguém** pediu aquela URL. Pode ter sido o filtro antivírus do
servidor de e-mail do destinatário, um pré-visualizador de link ou um proxy corporativo — todos
abrem as URLs de uma mensagem automaticamente, antes de qualquer humano ver o e-mail.

Por isso a página nomeia o fato como **"abertura do link detectada (não comprova leitura)"**,
entrega `opened_at` separado, diz explicitamente quando **não** houve abertura (em vez de omitir
a linha, o que se leria como ausência de dado) e traz a nota `notes.opened_vs_read`. Nenhuma tela
e nenhum PDF afirma "leu". A prova que existe é o **aceite**.

---

## 4. Linguagem correta para cada situação de assinatura

Montada em `App\Services\Verification\SignatureNarrative`, em um único lugar, para as duas
páginas e sem variação de vocabulário entre elas.

### 4.1 Os três estados

**(a) Envelope não concluído** (`in_progress`, `finalizing`, `refused`, `expired`, `canceled`) —
não existe arquivo final, logo não existe assinatura nem resumo final. O texto diz o motivo
(coleta em andamento, recusa, prazo encerrado, cancelamento) e nada mais. `certificate` é nulo e
a validação vem `available: false`.

**(b) `signature_status = none`** — _aceite eletrônico com evidências_:

> Concluído como aceite eletrônico com evidências, sem assinatura criptográfica. Nenhum
> certificado foi aplicado ao arquivo, e leitores de PDF não devem indicar nenhuma assinatura
> nele. […] a integridade do arquivo pode ser conferida comparando o resumo SHA-256 dele com o
> resumo final publicado nesta página.

O texto não contém — e um teste garante que não contenha — "assinatura digital", "assinado
digitalmente" nem "ICP-Brasil". O rótulo do estado também muda: `EnvelopeStatus::Completed`
rotula "Assinado", o que aqui seria falso, então a verificação usa **"Concluído · aceite
eletrônico com evidências"**.

**(c) `signature_status = company_a1`** — assinatura da **operadora**:

> O arquivo final recebeu uma assinatura criptográfica no perfil PAdES-B-B, aplicada pela
> operadora AssinaVelox com certificado digital de titularidade da própria operadora. Ela
> identifica quem consolidou e lacrou o arquivo e permite detectar alterações feitas depois do
> lacre. Não é a assinatura pessoal de nenhum participante nem um certificado emitido em nome
> deles […]

Com `certificate_references.environment = test`, o texto acrescenta o aviso obrigatório de
**ambiente de teste**, sem valor para uso real e sem relação com a ICP-Brasil. Um certificado de
teste jamais é apresentado como ICP-Brasil.

Nunca se anuncia B-T, B-LT ou B-LTA: a Fase 1 assina B-B, e o perfil exibido é o gravado em
`verification_records.signature_profile`.

### 4.2 Os resumos

`App\Services\Verification\HashLedger` publica os quatro com a descrição de
`declaracao-de-aceite.md` §5.1 — original (bytes como a organização enviou), enviado (versão
congelada e apresentada aos signatários; é o que cada declaração de aceite referencia),
consolidado (campos achatados, antes do relatório de evidências) e final (arquivo completo).

O **final** é calculado **depois** da assinatura e gravado **fora** do PDF, em
`verification_records`, e a descrição diz isso com todas as letras: um resumo não pode constar
dentro do arquivo que ele identifica. O rodapé impresso traz apenas a URL e o código.

Em ambas as páginas aparece o texto de apoio: _um resumo SHA-256 identifica um arquivo byte a
byte; ele não é uma assinatura — permite conferir se dois arquivos são idênticos, e nada mais._

### 4.3 O resultado técnico da validação

Vem de `verification_records.validation_result`, que é o resumo de
`App\Services\Pdf\Dto\ValidationResult::summary()` gravado pela finalização. Três garantias:

| Dimensão            | Só é afirmada quando                                                      | Caso contrário                                                                                      |
| ------------------- | ------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Integridade         | `all_intact` **e** `all_valid` **e** `all_covering` **e** `all_docmdp_ok` | "integridade não verificada", "conteúdo fora da revisão assinada" ou "integridade NÃO confirmada"   |
| Confiança da cadeia | `all_trusted` **e** `trust_roots_configured > 0`                          | "cadeia de certificação NÃO verificada por esta plataforma (nenhuma raiz de confiança configurada)" |
| Revogação           | o resultado disser outra coisa                                            | "revogação do certificado NÃO verificada" — a Fase 1 não consulta LCR nem OCSP                      |

Por que `all_covering` entra na integridade: `all_intact` fala **apenas** dos bytes cobertos pela
assinatura. Um arquivo com bytes acrescentados depois da revisão assinada continua íntegro e
válido, e o que denuncia o acréscimo é `coverage = ENTIRE_REVISION` em vez de `ENTIRE_FILE` (mais
`docmdp_ok = false` quando a alteração toca o catálogo). Sem ler esses campos, a página publicava
"Íntegro na conclusão: a validação não encontrou alteração no arquivo depois da assinatura" sobre
um arquivo que tinha exatamente isso. Nesse caso a página passa a dizer: _a assinatura está
íntegra, mas NÃO cobre o arquivo inteiro: há conteúdo fora da revisão assinada_.

Resultado ausente, vazio ou incompleto **nunca** vira sucesso: cai em `available: false` com o
motivo explícito. E a página avisa que o resultado é o registrado no momento da conclusão, não um
recálculo a cada visita, e que ela não é um certificado emitido por autoridade certificadora.

**O fato negativo é exibido, não só trafegado.** A página pública monta o mesmo
`<ValidationDetails>` da página interna de evidências sempre que `signature_status = company_a1`.
Antes ela consumia apenas `validation_summary`, que é `null` justamente no caso inconclusivo — e
o silêncio, ao lado do selo verde, era lido como confirmação.

**O marco da linha do tempo segue o resultado, não o carimbo.** "Assinatura criptográfica da
operadora aplicada **e validada**" só aparece quando a integridade foi de fato afirmada; nos
demais casos o marco continua visível como "Assinatura criptográfica da operadora aplicada".
`validated_at` diz apenas que a finalização passou pelo passo de validação.

---

## 5. Onde está o quê

| Arquivo                                                         | Papel                                                   |
| --------------------------------------------------------------- | ------------------------------------------------------- |
| `app/Http/Controllers/Public/VerificationController.php`        | `index`, `show`, `checkFile`                            |
| `app/Http/Controllers/Envelopes/EnvelopeEvidenceController.php` | dossiê autenticado                                      |
| `app/Services/Verification/PublicVerification.php`              | consulta uniforme e montagem do resultado público       |
| `app/Services/Verification/NameMask.php`                        | "Maria Aparecida da Silva" → "Maria S." / "Maria A. S." |
| `app/Services/Verification/SignatureNarrative.php`              | linguagem da assinatura e do resultado da validação     |
| `app/Services/Verification/HashLedger.php`                      | os quatro resumos e o que cada um identifica            |
| `app/Services/Verification/EvidenceDossier.php`                 | participantes do dossiê e as notas obrigatórias         |
| `app/Http/Resources/VerificationResultResource.php`             | recurso fino sobre `PublicVerification::result()`       |
| `tests/Feature/Verification/**`                                 | contrato, proibições, limites e autorização             |
