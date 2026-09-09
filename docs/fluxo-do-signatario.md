# Fluxo do signatário — página pública `/assinar/{token}`

> Incremento 3, módulo B-SIGN. Fontes: `docs/arquitetura.md` §2, §3.1, §3.3, §4;
> `docs/design/RECONCILIACAO.md` §1–§4; `docs/design/ROUTES_AND_PAGES.md` §1.3, §2.18, §3;
> `docs/juridico/declaracao-de-aceite.md`; `docs/juridico/aviso-de-privacidade-signatario.md`.
>
> Código: `app/Services/Signing/**`, `app/Http/Controllers/Sign/**`,
> `app/Http/Middleware/{ResolveSignerToken,EnsureSignerVerified}.php`,
> `app/Http/Requests/Sign/**`, `app/Notifications/Signing/SignerOtpNotification.php`,
> `app/Events/EnvelopeReadyForFinalization.php`. Testes: `tests/Feature/Sign/**`.

---

## 1. O que esta página é — e o que ela não é

A pessoa que abre este link **não tem conta**. O link é a única credencial que ela tem, e ela
o recebeu por e-mail. Tudo aqui é desenhado a partir dessas duas frases.

A página produz um **aceite eletrônico** (arquitetura §2): uma manifestação de vontade
vinculada à versão exata do documento, aos campos apresentados, ao método de autenticação, à
data do servidor em UTC, ao endereço IP e ao navegador. A imagem da assinatura — desenhada,
digitada ou enviada — é **representação visual** e não prova nada sozinha.

A página **nunca** produz uma assinatura criptográfica pessoal. Quando o envelope conclui com
o certificado A1 da operadora, a assinatura é da AssinaVelox e a interface diz isso com todas
as letras; sem certificado configurado, o envelope conclui como _aceite eletrônico com
evidências_ e o texto exibido é exatamente esse (§7). Nada é simulado, e SHA-256 sozinho não
é assinatura.

---

## 2. Sequência completa

| #   | Ação               | Rota                       | Exige                                 | Efeito                                                                |
| --- | ------------------ | -------------------------- | ------------------------------------- | --------------------------------------------------------------------- |
| 1   | Abrir o convite    | `GET sign.show`            | link válido                           | registra `invitation.opened` na primeira vez; `notified → viewed`     |
| 2   | Pedir o código     | `POST sign.otp.send`       | link válido, é a vez                  | cria `AuthChallenge`, envia e-mail, `challenge.sent`                  |
| 3   | Confirmar o código | `POST sign.otp.verify`     | código vivo                           | `challenge.verified` + `session.started`; sessão autenticada (30 min) |
| 4   | Ler e preencher    | `GET sign.document`        | sessão                                | transmite o PDF congelado no envio, inline, sem cache                 |
| 5   | Aceitar            | `POST sign.complete`       | sessão **e** autorização              | grava `SignatureAcceptance`, valores, `acceptance.recorded`; `signed` |
| 6   | Recusar            | `POST sign.refuse`         | sessão                                | `recipient.refused` (+ `envelope.refused` na política padrão)         |
| 7   | Comprovante        | `GET sign.download/{type}` | sessão viva **ou** janela de download | comprovante sempre; PDF final só quando existe                        |

Depois do passo 5: no **sequencial**, `current_order` avança e o módulo de envio convida quem
entrou na vez; quando ninguém mais falta, o envelope vai a `finalizing` e o evento
`EnvelopeReadyForFinalization` é despachado (§8).

---

## 3. Resolução do link (`ResolveSignerToken`)

Todo o grupo `sign.*` passa por este middleware. Ele é a única porta por onde o token bruto
entra na aplicação; nenhum controller consulta `recipient_access_links`.

A ordem das perguntas, e o motivo de cada uma:

1. **O token existe?** A busca é por `hash('sha256', $token)` na coluna UNIQUE
   `token_digest`, e a confirmação passa por `hash_equals`. O token em claro não está em
   lugar nenhum do banco.
2. **O link ainda vale?** Revogado (troca de e-mail, reenvio, encerramento do envelope) ou
   vencido → não abre.
3. **Link, destinatário, envelope e organização batem?** Uma linha inconsistente
   (importação, migração manual) não vira acesso.
4. **O envelope saiu do rascunho?** Link de envelope `draft/preparing/ready` não deveria
   existir; se existir, não abre.
5. **O prazo continua de pé?** Revalidação a cada acesso (§6).
6. **Qual é o estado desta pessoa?** Ver a tabela do §4.
7. **É a vez dela?** No sequencial, `order_index > current_order` não abre.

**Resposta a qualquer falha: 404 genérico e idêntico.** Token desconhecido, revogado,
vencido, de envelope em rascunho e fora da vez produzem exatamente o mesmo corpo — a página
`sign/show` com `screen: 'invalid'` e status 404 (as demais rotas do grupo abortam com 404
seco). Respostas diferentes por motivo transformariam a página em um oráculo: bastaria
comparar duas respostas para descobrir se um convite existe. Há teste que compara os corpos.

---

## 4. Telas (`screen`) e o que decide cada uma

O estado do **convite** é decidido sem olhar o navegador; a separação entre `identify` e
`sign` é a única que depende da sessão.

| `screen`                        | Quando                                                               | Observação                                            |
| ------------------------------- | -------------------------------------------------------------------- | ----------------------------------------------------- |
| `identify`                      | é a vez, ainda não confirmou o código                                | dado mínimo: nem PDF, nem campos, nem texto de aceite |
| `sign`                          | é a vez, sessão autenticada viva                                     | PDF, campos, texto de aceite, token de autorização    |
| `completed`                     | já aceitou **e** envelope `completed`                                | comprovante + download do arquivo final               |
| `already_signed_pending_others` | já aceitou, faltam outros                                            | comprovante; arquivo final indisponível               |
| `finalizing`                    | já aceitou, ninguém falta, envelope `finalizing`                     | comprovante; "o arquivo final está sendo preparado"   |
| `refused`                       | **esta pessoa** recusou                                              | motivo exibido                                        |
| `expired`                       | prazo encerrado (dela ou do envelope)                                | —                                                     |
| `canceled`                      | remetente cancelou, ou o envelope foi encerrado pela recusa de outro | —                                                     |
| `invalid`                       | qualquer falha de resolução (404)                                    | corpo idêntico em todos os casos                      |

O que aconteceu com **esta pessoa** manda sobre o que aconteceu com o envelope: quem já
aceitou vê o comprovante mesmo que o prazo tenha vencido depois; quem foi cancelado pela
recusa de outro vê **`canceled`**, nunca `refused` — dizer "você recusou" a quem não recusou
seria falsear a trilha na cara do usuário.

---

## 5. Os três tokens

Os três são bytes aleatórios (`random_bytes`, 32 bytes, base64url) e **de todos só o digest
SHA-256 é persistido**. Nenhum aparece em log, exceção ou evento de auditoria — há teste que
varre `audit_events` procurando cada um.

| Token                 | Nasce                           | Digest fica em                                | Vive                                                     | Viaja em                                                 | Morre quando                                                       |
| --------------------- | ------------------------------- | --------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------- | ------------------------------------------------------------------ |
| **Convite**           | envio/reenvio (módulo de envio) | `recipient_access_links.token_digest`         | prazo do envelope                                        | URL do e-mail                                            | revogação (troca de e-mail, reenvio, encerramento) ou prazo        |
| **Sessão**            | verificação do código           | `signing_sessions.token_digest`               | **30 min** (`signing_session.ttl_minutes`)               | sessão Laravel, chave `signer.sessions.{recipient_ulid}` | aceite (consumida), recusa, troca da versão apresentada, expiração |
| **Autorização final** | renderização da tela `sign`     | `signing_sessions.authorization_token_digest` | **10 min** (`signing_session.authorization_ttl_minutes`) | props da página → POST do aceite                         | uso, reemissão, expiração                                          |

**Por que três e não um.** O convite prova que a pessoa recebeu o e-mail — e só isso; um link
vazado não pode assinar. A sessão prova que ela confirmou o código _naquele navegador_, e a
chave é **por destinatário**: confirmar a identidade de uma pessoa não dá acesso ao convite de
outra na mesma aba (há teste). A autorização prova que o POST veio da tela que foi realmente
renderizada, e carrega o `snapshot_hash` do que foi apresentado — é o que impede assinar uma
coisa e registrar outra (§9).

O token da sessão fica na sessão do Laravel (cookie httpOnly do framework), **nunca** em
cookie próprio nem em `localStorage`. Ao confirmar o código o id de sessão é reemitido
(`session()->migrate(true)`), fechando fixação de sessão num fluxo que muda o nível de
privilégio do navegador.

---

## 6. Prazo revalidado a cada acesso

`ExpireEnvelopes` roda a cada 15 minutos (RECONCILIACAO Q22), mas o agendador pode estar
parado, atrasado ou ter falhado — e nesse intervalo um link continuaria assinando um documento
vencido. Por isso **toda** resolução de link revalida o prazo antes de decidir o que mostrar,
via o contrato `App\Services\Signing\Contracts\RevalidatesEnvelopeExpiration`.

- Implementação do módulo de envio, quando registrada no container: vale ela.
- Sem binding: vale `App\Services\Signing\EnvelopeExpirationGuard`, que produz a mesma
  transição (`in_progress → expired`, pendentes → `expired`, evento `envelope.expired`), em
  transação curta com `lockForUpdate` para que dois acessos simultâneos não gravem dois
  eventos.

A invariante não depende da ordem em que os módulos foram escritos nem de um binding presente.

---

## 7. Código por e-mail (OTP)

### Como o código é guardado

**Nunca em claro.** `auth_challenges.code_hash` recebe `HMAC-SHA256("{ulid}|{codigo}", segredo)`
com o segredo **derivado da APP_KEY** (`sha256('assinavelox:auth-challenge:v1|' . APP_KEY)`),
não a APP_KEY em si: um vazamento do banco não basta para testar códigos, e essa derivação não
se confunde com a chave usada em sessões e cookies. O `ulid` da linha entra no HMAC como sal,
então dois desafios com o mesmo código de seis dígitos têm hashes diferentes.

A conferência é `hash_equals` sobre o HMAC recalculado — nunca `===` sobre o código.

O código é gerado com `random_int(0, 999999)` e preenchido com zeros à esquerda: sortear a
partir de 100000 descartaria um décimo do espaço e daria um dígito de graça ao atacante.

### Limites

| Limite                 | Padrão | Chave de configuração         | Escopo  |
| ---------------------- | ------ | ----------------------------- | ------- |
| Intervalo entre envios | 60 s   | `otp.resend_interval_seconds` | link    |
| Envios por hora        | 5      | `otp.resend_limit`            | link    |
| Envios por hora        | 30     | `otp.ip_hourly_limit`         | IP      |
| Validade               | 10 min | `otp.ttl_minutes`             | desafio |
| Tentativas             | 5      | `otp.max_attempts`            | desafio |

Acima disso valem os limitadores de rota de `AppServiceProvider`: `throttle:otp-send`
(3/10 min por token) e `throttle:otp-verify` (5/10 min por token). Os limitadores por rota são
a primeira barreira; os do serviço são por **link e por IP** e sobrevivem a mudanças de rota.

Regras adicionais: **um código vivo por vez** — pedir outro invalida o anterior, senão dois
códigos válidos ao mesmo tempo dobrariam a superfície de adivinhação. Esgotar as 5 tentativas
**mata o código na hora**, mesmo dentro da validade: nem o código certo funciona depois.

### Envio

Pelo canal rastreado (`TrackedMailChannel` → contrato `EmailProvider`), com uma linha em
`delivery_attempts` de propósito `otp`. "Enviado" nunca é confundido com "entregue", e uma
resposta inconclusiva vira `unknown`, não sucesso.

**Compromisso registrado:** a notificação é enfileirada, então o código viaja no payload do
job enquanto ele espera na fila (segundos). É o mesmo compromisso já assumido pelo convite,
que carrega o link de assinatura em claro; a alternativa — enviar no ciclo da requisição —
prenderia a resposta da página pública ao tempo do SMTP. O código **não** entra em
`auth_challenges` (só o HMAC), nem em `delivery_attempts` (nem em `meta`, nem em `tags`), nem
em `audit_events`, nem em log — e o assunto do e-mail não o contém, porque assuntos aparecem
em notificação de tela bloqueada.

---

## 8. Aceite

### O que é revalidado sob lock

Tudo que a tela já tinha validado, de novo, dentro de `SELECT ... FOR UPDATE` no envelope:
status `in_progress`, prazo, vez no sequencial, sessão autenticada, `document_version_id` da
sessão igual a `sent_document_version_id`, token de autorização vivo, `snapshot_hash` igual ao
da tela e inexistência de aceite anterior. Entre a renderização e o clique podem ter passado
minutos, e nesse intervalo o remetente pode ter cancelado, o prazo pode ter vencido e outro
participante pode ter recusado. **A tela não é fonte de verdade; o banco sob lock é.**

Duplicidade tem duas defesas: a checagem sob lock e o `UNIQUE(recipient_id)` de
`signature_acceptances`. A segunda é a que vale quando dois processos passam pela primeira ao
mesmo tempo em um banco onde o lock não serializa — a violação vira 409, nunca dois aceites.

A transação **não** contém chamada externa nem processo: a imagem é normalizada e gravada no
disco antes de abrir a transação (arquitetura §3.3).

### O que é do servidor, não do cliente

- `accepted_at`: hora do servidor, UTC.
- Campos `date`: carimbados pelo servidor no fuso da organização (RECONCILIACAO Q9). O que o
  cliente mandar nesses campos é **descartado sem erro** — não é um valor inválido, é um valor
  que simplesmente não é dele. Há teste que envia `01/01/1999` e confere a data do servidor.
- `document_sha256`: dos bytes da versão apresentada, lido do banco.
- `consent_statement`: o texto resolvido no servidor, não o que voltou do formulário.
- IP e user-agent: da requisição. O IP depende de `assinavelox.trusted_proxies` — a
  configuração de proxy é parte da qualidade da evidência, não um detalhe de infraestrutura.

### Representação visual

Três métodos: **desenhar** (PNG do canvas), **digitar** (nome de 2 a 80 caracteres + fonte
manuscrita) e **enviar imagem**. Certificado ICP-Brasil é Fase 2 e fica oculto — nunca
simulado.

Toda imagem passa por `SignatureImages`, na ordem:

1. base64 decodificado em modo estrito (sem `strict` o PHP ignora lixo no meio silenciosamente);
2. tamanho **decodificado** conferido antes de olhar o conteúdo (`max_decoded_kb`, 3 MB);
3. tipo real por `finfo` sobre os bytes — só PNG, JPEG e WEBP. **SVG é recusado**: é XML, pode
   conter `<script>`, referência externa e entidades, e não existe "SVG seguro" que valha o
   risco dentro de um documento assinado;
4. largura e altura lidas do **cabeçalho** (`getimagesizefromstring`) e recusadas acima de 8 MP
   _antes_ de decodificar um pixel — é assim que uma PNG gigante de poucos KB morre sem
   consumir memória;
5. decodificação com GD, redimensionamento para caber em **1200×400** e **reencodagem do zero**
   como PNG. Reencodar é o que apaga EXIF, ICC, XMP e chunks `tEXt`/`zTXt`: o arquivo final tem
   apenas pixels. Há teste que injeta um chunk `tEXt` com um marcador e confere que ele sumiu.

O arquivo vai para o disco privado `documents`, em caminho feito só de identificadores opacos.

### O snapshot

`SignerPresentation::snapshot()` monta uma estrutura canônica e ordenada com: o ULID e o
SHA-256 da versão apresentada, o número de páginas, a versão do texto de aceite, o hash do
próprio texto e cada campo com tipo, página, geometria (6 casas) e obrigatoriedade. O
SHA-256 dessa estrutura é gravado em `signing_sessions.snapshot_hash` no momento em que a tela
é montada e reconferido no POST.

Se o remetente trocou o documento, moveu um campo ou mudou a redação nesse intervalo, o hash
não bate e o aceite é recusado com "recarregue a página e confira antes de assinar" — a pessoa
vê a tela nova antes de decidir de novo. É o que impede o clássico "assinei uma coisa e ficou
registrada outra". O snapshot inteiro, mais os valores preenchidos, vai para
`signature_acceptances.fields_snapshot`.

---

## 9. Recusa

Motivo obrigatório, de **10 a 500 caracteres**. O mínimo é deliberado: a recusa é uma
manifestação de vontade tanto quanto o aceite, vai para a trilha e é enviada ao remetente; um
"não" sem motivo obriga quem enviou a adivinhar.

Exige sessão autenticada, como o aceite: recusar em nome de outra pessoa é tão grave quanto
assinar por ela — encerra o documento e cancela os demais participantes. Na etapa `identify` a
única recusa possível é fechar a página, e o aviso de privacidade diz isso.

Política padrão (`organizations.settings.refusal_policy = close_envelope`), em uma transação
sob lock:

- destinatário → `refused` com motivo e `refused_at`;
- envelope → `refused`, com motivo e autor em `settings` para as telas do remetente;
- demais pendentes → **`canceled`**, não `refused`: eles não recusaram nada;
- **todos** os `recipient_access_links` do envelope revogados;
- sessões vivas revogadas.

Depois disso nenhum aceite entra, **inclusive de uma aba já aberta**: o link nem resolve, e o
aceite revalidaria o envelope sob lock e encontraria `refused`. Há teste que mantém a sessão e
o token de autorização e tenta assinar. A recusa é irreversível pelo signatário; o remetente
pode duplicar o envelope.

As **mensagens** (aviso ao remetente com o motivo, aviso de encerramento aos cancelados) são do
módulo de envio, pelo contrato `SignerNotifications` (§11).

---

## 10. Eventos de auditoria — o que cada um significa

Todos gravados com `actor_type = recipient` (ou `system` quando o sujeito é o envelope), IP e
user-agent da requisição e um `correlation_id` que amarra a linha da trilha à tentativa de
entrega.

| Evento                | Significa                                                    | **Não** significa                |
| --------------------- | ------------------------------------------------------------ | -------------------------------- |
| `invitation.opened`   | **abertura detectada**: alguém pediu esta URL                | que a pessoa leu, ou que era ela |
| `challenge.sent`      | um código foi gerado e despachado                            | que o e-mail chegou              |
| `challenge.verified`  | o código conferido bateu                                     | —                                |
| `challenge.failed`    | código errado, expirado, ausente ou com tentativas esgotadas | —                                |
| `session.started`     | sessão de assinatura autenticada                             | —                                |
| `acceptance.recorded` | **aceite eletrônico gravado**                                | assinatura criptográfica pessoal |
| `recipient.refused`   | esta pessoa recusou, com motivo registrado                   | —                                |
| `envelope.refused`    | o envelope foi encerrado pela recusa                         | que os demais recusaram          |
| `envelope.expired`    | o prazo venceu e a transição foi aplicada                    | —                                |
| `envelope.finalizing` | o último aceite entrou; a finalização foi anunciada          | que o envelope está concluído    |
| `envelope.downloaded` | comprovante ou arquivo final baixado                         | —                                |

### Abertura detectada ≠ leitura

Vale insistir, porque é a distinção que mais gente erra. O que o servidor sabe é que **alguém**
pediu a URL. Não sabe se era a pessoa, se ela estava na frente da tela, nem se leu uma linha do
documento. Filtros de segurança de e-mail, pré-visualizadores de link e antivírus corporativos
abrem todas as URLs de uma mensagem automaticamente, antes de a pessoa ver o e-mail.

Por isso: o evento se chama `invitation.opened`, o status vira `viewed` com o rótulo
"Visualizou", e nenhuma tela ou página de evidências afirma "leu". **Prova de leitura não
existe. Prova de aceite existe, e é o aceite.**

### O GET é inofensivo por construção

Um `GET sign.show` nunca consome o convite, nunca cria aceite e nunca invalida o link. Se
abrir o link consumisse o convite, um scanner deixaria o signatário sem acesso; se criasse
qualquer registro de vontade, um robô teria "assinado". `use_count`/`last_used_at` medem
abertura, não consumo, e são incrementados uma única vez — recarregar cem vezes não enche a
trilha. Há teste que abre quatro vezes e confere: um evento, um `use_count`, zero aceites,
link ainda utilizável.

### O que nunca entra no payload

Token de convite, token de sessão, token de autorização, código OTP e e-mail completo. O
motivo da recusa entra apenas como **medida** (`reason_length`): o texto integral fica em
`recipients.refusal_reason`, onde é útil, em vez de duplicado como texto livre de origem
pública dentro da trilha. Há testes que varrem `audit_events` procurando cada segredo.

---

## 11. Contratos publicados para o módulo de envio

Ambos ficam em `App\Services\Signing\Contracts` e são ligados por binding no
`AppServiceProvider`. A divisão combinada é simples: **quem muda estado é este módulo** (sob
lock, com trilha); **quem produz mensagem e emite link é o módulo de envio**. Um único escritor
por transição.

```php
interface SignerNotifications
{
    public function inviteRecipients(Envelope $envelope, array $recipients): void;      // chegou a vez
    public function notifySenderRefused(Envelope $envelope, Recipient $refusedBy): void; // alguém recusou
    public function notifyEnvelopeClosed(Envelope $envelope, array $canceled, string $reason): void;
}

interface RevalidatesEnvelopeExpiration
{
    public function revalidate(Envelope $envelope): Envelope;   // chamado a cada acesso
}
```

Sem implementação registrada, `SignerNotifier` grava um aviso no log (com ids, sem e-mail
completo, sem token) e segue. A escolha é deliberada: **um aceite gravado com sucesso não pode
ser desfeito porque o e-mail do próximo participante não saiu** — a manifestação de vontade já
aconteceu e está auditada. O que não pode acontecer é o silêncio.

---

## 12. Gancho da finalização

Quando o último aceite exigido entra, o serviço transiciona o envelope para `finalizing`,
gera `finalization_key` se ainda não houver, grava `envelope.finalizing` e despacha:

```php
App\Events\EnvelopeReadyForFinalization(
    int $envelopeId, int $organizationId, ?string $finalizationKey, ?string $correlationId
);
```

**O evento não conclui nada.** Ele anuncia. Quem escuta é a finalização (incremento 4), que vai
compor o PDF, gerar a página de evidências, aplicar — ou não — a assinatura criptográfica da
operadora, calcular os hashes e só então marcar `completed`. Enquanto ninguém escutar, o
envelope fica em `finalizing` e a interface diz exatamente isso ("Em andamento · finalizando"):
nenhuma tela afirma conclusão, nenhum arquivo final é inventado, nenhuma assinatura é simulada.
Há teste que confere `status = finalizing`, `completed_at = null`,
`final_document_version_id = null` e ausência de `envelope.completed`.

Só ids viajam no evento: um listener em fila precisa recarregar do banco sob lock, e passar o
model serializado convidaria a decidir com dados velhos.

Para ligar, no incremento 4:

```php
Event::listen(EnvelopeReadyForFinalization::class, FinalizeEnvelope::class);
```

---

## 13. Texto de aceite e privacidade

`App\Services\Signing\ConsentText` materializa `docs/juridico/declaracao-de-aceite.md`:

- `ACCEPTANCE_TERMS_VERSION = 'v1-2026-09-08'` — muda **sempre que uma palavra do texto muda**.
- `envelopes.terms_version` é congelado no envio; todos os signatários de um envelope veem o
  mesmo texto, mesmo que a constante mude durante a coleta (há teste).
- `signature_acceptances.consent_statement` guarda o **texto integral já resolvido**, como
  apareceu na tela — não uma referência a template.
- A escolha entre as variantes (i) e (ii) é feita **na renderização**, olhando se existe
  `certificate_references` da operadora (`organization_id` nulo) ativa e dentro da validade.
  Sem certificado, o texto diz "aceite eletrônico com evidências, sem assinatura
  criptográfica" e a interface diz o mesmo. Há teste para as duas variantes.
- A caixa de aceite **nunca** vem marcada: `consent` é validado como `accepted` e um POST sem
  ela é recusado.

O aviso de privacidade (`PRIVACY_NOTICE_VERSION`) vai nas props desde a etapa `identify`,
**antes** do botão "Receber código", com a linha-resumo e o texto completo — incluindo a frase
sobre a abertura do link ser registrada.

---

## 14. Segurança da página

| Medida                                                                                        | Onde                                                                         |
| --------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| `X-Robots-Tag: noindex, nofollow, noarchive` e `Referrer-Policy: no-referrer` em `/assinar/*` | `SecurityHeaders` (incremento 1) — inclusive nas respostas 404               |
| PDF só com sessão, `inline`, `no-store`, `nosniff`, sem URL pública nem assinada              | `Sign\DocumentController` + `DocumentStorage`                                |
| Rate limit por IP+token                                                                       | `throttle:signer` (30/min)                                                   |
| Rate limit do código                                                                          | §7                                                                           |
| CSRF                                                                                          | grupo `web` padrão                                                           |
| Reemissão do id de sessão ao confirmar identidade                                             | `Sign\OtpController@verify`                                                  |
| Isolamento entre organizações                                                                 | consultas por `envelope_id`/`recipient_id` do link resolvido; teste dedicado |

### Dado mínimo por etapa

- **`identify`**: remetente, título, código do documento, prazo, e-mail **mascarado** da
  própria pessoa, aviso de privacidade e estado do código. Sem PDF, sem campos, sem texto de
  aceite, sem IP.
- **`sign`**: acrescenta a URL do PDF (que ainda exige sessão para responder), os campos do
  próprio destinatário, a **indicação** dos campos de terceiros (nome, posição, se já
  assinaram) e o token de autorização.
- Em nenhuma etapa saem: e-mail de outro participante (nem mascarado), IP de outra pessoa,
  valores preenchidos por outro participante, token de terceiros, código OTP ou caminho de
  arquivo no disco.

O e-mail do próprio destinatário sai **mascarado** (`m****@exemplo.test`): a tela precisa
dizer para onde o código foi sem confirmar o endereço inteiro a quem porventura roubou o link.

---

## 15. Comprovante e download

`GET sign.download/{type}` com `type ∈ {evidence, signed}`.

- **`evidence`** — comprovante do aceite desta pessoa, em **texto**, gerado no servidor a
  partir das colunas de `signature_acceptances`. Traz documento, código de verificação,
  participante, e-mail mascarado, método de autenticação, data local e UTC, IP conforme
  `evidence_show_ip`, navegador, versão dos termos, o SHA-256 do documento apresentado, o
  SHA-256 do arquivo final (ou "ainda não publicado") e a declaração integral que foi aceita.
  Fecha com um bloco que diz o que ele **não** é: não é a página de evidências, não é
  certificado digital, não tem assinatura criptográfica, e um resumo SHA-256 não é assinatura.

    **Por que texto e por que agora:** a página de evidências formal, em PDF, depende de três
    hashes que só existem depois da finalização. Entregar antes um PDF parecido com ela seria
    fabricar um documento que a plataforma ainda não tem como sustentar.

- **`signed`** — o PDF final, só quando o envelope está `completed` **e**
  `final_document_version_id` aponta para uma versão `final` presente no disco. Enquanto isso,
  404 com "O arquivo final ainda não está disponível. Ele é gerado quando todos os
  participantes concluírem" — em vez de entregar o documento enviado fingindo ser o final.

Ambos emitem `envelope.downloaded` — **só quando o pedido está autorizado**, para que um
GET anônimo (pré-visualizador de link, antivírus corporativo) não fabrique evidência de que
o signatário baixou o arquivo.

**Divergência registrada em relação a ROUTES §1.3:** a tabela põe `sign.download` atrás de
`signer.verified`. Isso não funciona: o aceite **consome** a sessão (é o que impede um segundo
aceite na mesma aba), então no instante seguinte ao clique não existe mais sessão — e é
justamente aí que a pessoa quer o comprovante.

A autorização, então, é uma destas duas, **as duas presas ao navegador**:

- sessão de assinatura viva (`SignerSessions::current()`); ou
- **janela de download** aberta (`SignerDownloadGrants`) — o `recipient_access_links` com
  `purpose = download` e `expires_at` da arquitetura §4.7, emitido no aceite e na
  confirmação do código, válido por `signing_session.download_grant_minutes` (padrão 30).
  O token bruto fica na sessão Laravel; o banco guarda só o digest.

O que **não** autoriza é a mera existência de um aceite. Essa era a regra anterior e ela não
expirava nem olhava o navegador: quem tivesse a URL do convite — e-mail encaminhado, caixa
compartilhada, backup de mailbox — baixava o comprovante e, na conclusão, o PDF final
assinado, sem jamais ter recebido o código por e-mail.

Fora da janela a tela do comprovante **não** mostra os botões (`receipt.can_download`): ela
explica que o download vale para quem acabou de confirmar o código e que o arquivo final
chegará por e-mail. Emitir esse link de conclusão é trabalho do incremento 4 (arquitetura
§4.7); o mecanismo já existe.

---

## 16. Configuração

`config/assinavelox.php`, seções `otp` e `signing_session`:

```php
'otp' => [
    'ttl_minutes' => 10,              // ASSINAVELOX_OTP_TTL_MINUTES
    'max_attempts' => 5,              // ASSINAVELOX_OTP_MAX_ATTEMPTS
    'resend_limit' => 5,              // por link, por hora
    'resend_interval_seconds' => 60,  // intervalo mínimo entre envios
    'ip_hourly_limit' => 30,          // por IP, por hora
    'code_length' => 6,
],

'signing_session' => [
    'ttl_minutes' => 30,
    'authorization_ttl_minutes' => 10,
    'signature_image' => [
        'max_decoded_kb' => 3072,
        'max_source_pixels' => 8_000_000,
        'output_max_width' => 1200,
        'output_max_height' => 400,
    ],
    'max_text_field_length' => 500,
],
```

Middleware registrados em `bootstrap/app.php`: `signer` (`ResolveSignerToken`) e
`signer.verified` (`EnsureSignerVerified`).

---

## 17. Limitações honestas

- **Miniatura de página (`sign.page`)**: a rota existe e responde 404 com explicação. Gerar PNG
  no servidor exigiria um rasterizador (Ghostscript, poppler, pdfium) que o projeto decidiu não
  ter — o `pdftool` não rasteriza — e custaria um processo e um arquivo por página a cada
  visita. O rail é desenhado no navegador com PDF.js; `document.page_thumb_url_template` vem
  `null` nas props, como já acontece do lado do app.
- **Corrida real entre processos** não é exercitada: o SQLite em memória dos testes não
  serializa `lockForUpdate`. O teste cobre a segunda defesa — a violação do
  `UNIQUE(recipient_id)` — e a checagem sob lock é exercitada em sequência. Vale repetir a
  verificação contra MySQL.
- **O código do OTP passa pela fila** dentro do payload do job (§7). Compromisso deliberado e
  idêntico ao já assumido pelo link do convite.
- **Comprovante é texto, não PDF** (§15), até a finalização existir.
- **`fields_snapshot` guarda caminhos de arquivo**, não os bytes da imagem. Se a retenção
  apagar o PNG, o snapshot aponta para um arquivo ausente; o aceite continua válido pelo
  restante das evidências, mas a representação visual se perde. Política de retenção é assunto
  aberto.
- **Sem detecção de "leu o documento até o fim"**: não existe, e a plataforma não finge que
  existe (§10).
- **A tela `completed` não reabre o visualizador**: a sessão foi consumida no aceite, então
  `sign.document` responde 404 a partir dali. A cópia sai por `sign.download`. Trocar isso
  exigiria uma sessão de leitura separada da de assinatura.
