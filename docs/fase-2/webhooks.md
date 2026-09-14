# Fase 2 — Webhooks de saída e proteção contra SSRF

> Roadmap §2.16 (e base de §2.17, REST Hooks). Onda D, área **D-HOOK**. Identificadores em inglês; prosa em português.
> Documentos-fonte: `docs/roadmap.md` §1 (T1, T5, T7, T8, T9, T10) e §2.16; `docs/fases-2-3-viabilidade.md` (§2.16 classe A, risco **R6**); `docs/integracoes/pacotes-fase-2-3.md` §6.

## 1. Resumo

- A organização cadastra **endpoints** (URL https, eventos assinados, descrição). A plataforma gera o **segredo** (`whsec_…`, 32 bytes aleatórios), mostra **uma vez** e guarda **cifrado** (cast `encrypted`, APP_KEY).
- Cada evento publicável da **trilha de auditoria** (`audit_events`) vira uma **entrega** por endpoint assinante: corpo JSON mínimo, assinado com HMAC-SHA256 sobre `"{timestamp}.{corpo bruto}"`.
- Entregas saem **pela fila**, com **retentativas** (1 min → 24 h, teto de 8), **histórico** por tentativa, **reenvio manual**, **pausa automática** após N falhas seguidas (com aviso a quem gerencia integrações) e **idempotência** por (endpoint, tipo, id do evento).
- Toda chamada passa pela **proteção contra SSRF** (`App\Support\Http\OutboundUrlGuard`, código próprio): só https, DNS resolvido antes, todos os endereços públicos, **conexão pinada no IP validado** (`CURLOPT_RESOLVE`), sem redirecionamento, sem proxy de ambiente, porta restrita — no cadastro **e** a cada tentativa.
- **Flag `outbound_webhooks`** (global E plano), **desligada por padrão**. Desligada: nenhuma entrega é criada, nenhuma chamada sai, o gancho nem consulta o banco, a varredura não faz nada e as rotas de gestão respondem 404.
- **Condição de ativação (R6):** os testes de integração do pino (`tests/Feature/Phase2/Webhooks/PinnedConnectionTest.php`, em HTTP, e `HttpsPinTest.php`, em HTTPS com SNI e certificado — §6.5) precisam estar verdes no ambiente-alvo. Ele está verde nesta máquina com Guzzle 8.2 / libcurl 8.16 / PHP 8.3 (Windows). Ver §6.4 — o teste encontrou e corrigiu um caso em que o pino **não** era aplicado.

## 2. Catálogo de eventos

A fonte é a trilha (`AuditEventType`). Os nomes são **contrato público**: nunca renomear; significado novo = tipo novo. `*` assina todos (inclusive os que forem criados depois).

| Tipo                         | Evento da trilha             | Quando                                                              | `data`                              |
| ---------------------------- | ---------------------------- | ------------------------------------------------------------------- | ----------------------------------- |
| `envelope.sent`              | `envelope.sent`              | O envelope saiu para os participantes                               | `envelope`                          |
| `recipient.viewed`           | `invitation.opened`          | **Abertura detectada** do convite (não é prova de leitura)          | `envelope`, `recipient` (`meaning`) |
| `recipient.signed`           | `acceptance.recorded`        | **Aceite eletrônico** registrado (não é assinatura com certificado) | `envelope`, `recipient` (`action`)  |
| `recipient.approved`         | `approval.recorded`          | Aprovador aprovou                                                   | `envelope`, `recipient`             |
| `recipient.refused`          | `recipient.refused`          | Participante recusou (o motivo **não** vai)                         | `envelope`, `recipient`             |
| `envelope.refused`           | `envelope.refused`           | Envelope encerrado por recusa                                       | `envelope`                          |
| `envelope.completed`         | `envelope.completed`         | Concluído; arquivo final disponível                                 | `envelope`, `signature`             |
| `envelope.expired`           | `envelope.expired`           | Prazo terminou com pendências                                       | `envelope`                          |
| `envelope.canceled`          | `envelope.canceled`          | Remetente cancelou                                                  | `envelope`                          |
| `document.processing_failed` | `document.processing_failed` | O arquivo não pôde ser processado                                   | `envelope`, `document`              |
| `webhook.ping`               | — (botão "Enviar teste")     | Teste manual; **não assinável**                                     | `endpoint`, `message`               |

Qualquer outro evento da trilha é ignorado. Ordem de entrega **não é garantida**: use `occurred_at`.

## 3. Formato da entrega

```http
POST /seu/endpoint HTTP/1.1
Content-Type: application/json
User-Agent: AssinaVelox-Webhooks/1.0
X-AssinaVelox-Delivery-Id: 01K7Q3N6X4W9ZB2C5D8E1F0G3H
X-AssinaVelox-Event: recipient.signed
X-AssinaVelox-Event-Id: 01K7Q3N6T2S8R4P1M0L9K7J5H3
X-AssinaVelox-Timestamp: 1789142400
X-AssinaVelox-Attempt: 1
X-AssinaVelox-Signature: v1=5f2b…c9e1
```

```json
{
    "id": "01K7Q3N6T2S8R4P1M0L9K7J5H3",
    "type": "recipient.signed",
    "version": 1,
    "occurred_at": "2026-09-11T15:00:00Z",
    "organization": { "id": "01K5…" },
    "data": {
        "envelope": {
            "id": "01K6…",
            "code": "AV-000123",
            "status": "in_progress",
            "status_label": "Em andamento",
            "signing_order": "sequential",
            "sent_at": "2026-09-11T14:00:00Z",
            "expires_at": "2026-09-21T02:59:59Z",
            "completed_at": null,
            "refused_at": null,
            "expired_at": null,
            "canceled_at": null,
            "participants": { "total": 2, "concluded": 1 }
        },
        "recipient": {
            "id": "01K6…",
            "role": "signer",
            "role_label": "Signatário",
            "status": "signed",
            "order": 1,
            "action": "electronic_acceptance",
            "action_label": "Aceite eletrônico registrado (não é assinatura com certificado)"
        }
    }
}
```

- `id` = id do **evento** (o mesmo para todos os endpoints); `X-AssinaVelox-Delivery-Id` = id da **entrega** (um por endpoint; **igual em todas as tentativas e no reenvio manual**). Deduplique pelo id da entrega.
- `envelope.completed` traz `data.signature` = `{status, label, profile, sent_sha256, final_sha256}` com **o mesmo rótulo da verificação pública** (`SignatureStatus::label()`, T1) — por exemplo `none` → "Aceite eletrônico com evidências (sem assinatura criptográfica)". Nunca "assinatura digital" genérica.
- `document.processing_failed` traz `data.document` = `{id, failure_code}` (nunca a mensagem, que pode ter caminho ou nome).

**Política de privacidade (a mesma da API e da verificação pública).** Só ULIDs públicos — nunca o `id` interno. **Não vão**: nome, e-mail, telefone ou CPF de participante; título ou mensagem do envelope (texto livre, pode ter dado pessoal); motivo de recusa; código OTP, PIN, token, senha; código de verificação; imagem de assinatura ou captura; caminho de arquivo; o segredo. Os resumos SHA-256 e o perfil já são mostrados pela verificação pública. Para detalhes, o receptor consulta a API (§2.15) com um token com as abilities certas.

## 4. Assinatura e validação no receptor

`X-AssinaVelox-Signature: v1=<hex(HMAC-SHA256(segredo, "{X-AssinaVelox-Timestamp}.{corpo bruto}"))>`

- A chave do HMAC é o **segredo inteiro, como mostrado** (inclui `whsec_`).
- Assine/valide sobre os **bytes brutos** do corpo, antes de qualquer parse de JSON.
- **Janela de tempo:** recuse se `|agora − timestamp| > 300 s` (5 min) — protege contra replay. Mantenha o relógio sincronizado (NTP).
- Compare em **tempo constante**.
- **Rotação:** durante a janela de convivência o cabeçalho traz **duas** assinaturas (`v1=<novo>, v1=<anterior>`). Aceite se **qualquer** `v1` conferir. Fora da janela, só a do segredo novo. Nunca há mais de dois segredos válidos.
- Responda **2xx rápido** (em até 10 s) e processe depois; qualquer outra resposta, redirecionamento ou tempo esgotado conta como falha.

### PHP

```php
function assinaveloxWebhookValido(string $segredo, string $corpoBruto, string $timestamp, string $assinaturas, int $tolerancia = 300): bool
{
    if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $tolerancia) {
        return false;
    }

    $esperado = hash_hmac('sha256', $timestamp.'.'.$corpoBruto, $segredo);

    foreach (explode(',', $assinaturas) as $parte) {
        [$versao, $valor] = array_pad(explode('=', trim($parte), 2), 2, '');

        if ($versao === 'v1' && hash_equals($esperado, $valor)) {
            return true;
        }
    }

    return false;
}

$corpo = file_get_contents('php://input');
$valido = assinaveloxWebhookValido(
    getenv('ASSINAVELOX_WEBHOOK_SECRET'),
    $corpo,
    $_SERVER['HTTP_X_ASSINAVELOX_TIMESTAMP'] ?? '',
    $_SERVER['HTTP_X_ASSINAVELOX_SIGNATURE'] ?? '',
);
```

A mesma lógica está em `App\Services\Webhooks\WebhookSignature::verify()` e é exercitada pelos testes.

### Node.js (Express)

```js
const crypto = require('node:crypto');

function webhookValido(
    segredo,
    corpoBruto /* Buffer */,
    timestamp,
    assinaturas,
    tolerancia = 300,
) {
    if (!/^\d+$/.test(timestamp ?? '')) return false;
    if (
        Math.abs(Math.floor(Date.now() / 1000) - Number(timestamp)) > tolerancia
    )
        return false;

    const esperado = crypto
        .createHmac('sha256', segredo)
        .update(`${timestamp}.`)
        .update(corpoBruto)
        .digest();

    return (assinaturas ?? '').split(',').some((parte) => {
        const [versao, valor = ''] = parte.trim().split('=');
        if (versao !== 'v1' || !/^[0-9a-f]{64}$/.test(valor)) return false;
        return crypto.timingSafeEqual(esperado, Buffer.from(valor, 'hex'));
    });
}

// O corpo precisa chegar BRUTO: express.raw, não express.json.
app.post(
    '/webhooks/assinavelox',
    express.raw({ type: 'application/json' }),
    (req, res) => {
        const ok = webhookValido(
            process.env.ASSINAVELOX_WEBHOOK_SECRET,
            req.body,
            req.get('X-AssinaVelox-Timestamp'),
            req.get('X-AssinaVelox-Signature'),
        );
        if (!ok) return res.sendStatus(400);
        res.sendStatus(204);
        // processe JSON.parse(req.body) depois; deduplique por req.get('X-AssinaVelox-Delivery-Id')
    },
);
```

### Python

```python
import hashlib
import hmac
import time


def webhook_valido(segredo: str, corpo_bruto: bytes, timestamp: str, assinaturas: str, tolerancia: int = 300) -> bool:
    if not timestamp.isdigit() or abs(int(time.time()) - int(timestamp)) > tolerancia:
        return False

    esperado = hmac.new(segredo.encode(), timestamp.encode() + b"." + corpo_bruto, hashlib.sha256).hexdigest()

    for parte in assinaturas.split(","):
        versao, _, valor = parte.strip().partition("=")
        if versao == "v1" and hmac.compare_digest(esperado, valor):
            return True

    return False

# Flask: webhook_valido(os.environ["ASSINAVELOX_WEBHOOK_SECRET"], request.get_data(),
#                       request.headers.get("X-AssinaVelox-Timestamp", ""),
#                       request.headers.get("X-AssinaVelox-Signature", ""))
```

## 5. Entrega, retentativas e histórico

| Situação                                  | Resultado da tentativa             | Estado da entrega                                                                                |
| ----------------------------------------- | ---------------------------------- | ------------------------------------------------------------------------------------------------ |
| 2xx                                       | `succeeded`                        | `delivered`                                                                                      |
| 3xx (o `Location` **nunca** é seguido)    | `failed` (`redirect_not_followed`) | `failed` + retentativa                                                                           |
| 4xx / 5xx                                 | `failed` (`http_NNN`)              | `failed` + retentativa                                                                           |
| Conexão recusada                          | `failed` (`connection_failed`)     | `failed` + retentativa                                                                           |
| **Tempo esgotado**                        | **`unknown`** (`timeout`)          | `failed` + retentativa — o receptor **pode** ter recebido (T5); ele deduplica pelo id da entrega |
| Proteção de rede recusou (DNS mudou…)     | `blocked` (`ssrf_…`)               | `failed` + retentativa; **nenhum byte saiu**                                                     |
| Endpoint pausado/removido, flag desligada | — (não tenta)                      | `canceled`                                                                                       |

- **Backoff** (espera depois da n-ésima falha): 1 min, 5 min, 30 min, 2 h, 12 h, 24 h, 24 h. Na **8ª** tentativa falha a entrega vira **`exhausted`** (≈ 2 dias e 15 h no total). Configurável (`webhooks.backoff_seconds`, `webhooks.max_attempts`).
- As retentativas **não** usam `delay()` da fila: a varredura `webhooks:retry` (a cada minuto) despacha o que venceu. Ela também é a rede de segurança da primeira tentativa (se o job inicial se perder, sai depois de 60 s).
- **Idempotência:** único `(webhook_endpoint_id, event_type, event_id)` — o mesmo evento nunca vira duas entregas para o mesmo endpoint. Cada tentativa **reivindica** a entrega com `UPDATE` condicional (status aberto, sem trava vigente, gatilho adequado): dois jobs para a mesma entrega nunca geram duas tentativas simultâneas; uma trava vencida (worker que morreu) é retomada.
- **Outbox:** a entrega é criada na **mesma transação** que gravou o evento da trilha; o job só é despachado **depois do commit**. Operação desfeita = entrega desfeita.
- **Reenvio manual** (tela): nova tentativa com o **mesmo** id de entrega e o **mesmo** corpo (timestamp e assinatura do momento). Vale para entregue, falha, esgotada ou cancelada; recusado com o endpoint pausado ou removido. O reenvio também **reivindica por `UPDATE` condicional** (sem trava vigente); se um worker pegou a entrega entre a leitura da tela e o clique, o reenvio é recusado ("sendo tentada agora") e a trava viva não é apagada. Se falhar e ainda houver orçamento (`attempts < max`), volta ao backoff; senão, `exhausted`.
- **Pausa automática:** após `webhooks.pause_after_consecutive_failures` (padrão **20**) tentativas falhas **seguidas** no endpoint (qualquer entrega; um sucesso zera). O endpoint fica `paused_reason = consecutive_failures`; entregas abertas são canceladas quando a vez delas chega; eventos novos não geram entrega. Quem tem `manage_integrations` recebe a notificação **`webhook_failed`** (e-mail + sino). Reativar revalida a URL e zera a contagem.
- **Teste** (`webhook.ping`): uma tentativa, sem retentativa, não conta para a pausa, funciona com o endpoint pausado (para diagnosticar antes de reativar).
- **Histórico** (`webhook_deliveries.history`, até 30 tentativas): tentativa, gatilho (`automatic`/`manual`), resultado, código HTTP, duração (ms), código do erro, IP efetivamente conectado e **trecho da resposta** — no máximo 512 bytes, sem caracteres de controle, com o segredo, as assinaturas desta tentativa, qualquer `whsec_…` e números com forma de CPF **redigidos**. Nunca cabeçalhos, segredo ou assinatura.
- **Retenção:** `webhooks:prune` (diário) apaga entregas encerradas com mais de 30 dias e endpoints removidos há mais de 30 dias. `audit_events` nunca é tocada (T7).

## 6. Proteção contra SSRF (`OutboundUrlGuard` + `WebhookTransport`)

Não há pacote compatível com PHP 8.3 (`cboxdk/laravel-ssrf` exige 8.4; `j0k3r/httplug-ssrf-plugin` é só IPv4 e fixa sem TLS). É código próprio, aplicado **no cadastro, na alteração da URL, ao reativar e a cada tentativa**.

### 6.1 Validação da URL

1. Recusa espaço, caractere de controle e barra invertida (diferenças entre o parser do PHP e o do cURL); URL sem esquema ou host; **usuário/senha** na URL.
2. **Só `https`.** `http` apenas **fora de produção** e com `webhooks.allow_http` (padrão: ligado em `local`/`testing`). Em produção é recusado qualquer que seja a configuração.
3. **IP literal nunca** — em qualquer grafia que o cURL aceitaria: decimal (`2130706433`), octal (`0177.0.0.1`), hexadecimal (`0x7f.1`, `0x7f000001`), curta (`127.1`), IPv6 entre colchetes (com zona), IPv4 mapeado. Literal interno → `blocked_address`; literal público → `ip_literal` (exige nome). Literal impossível (`256.1.1.1`) → inválido. Nada disso consulta o DNS.
4. Host sem ponto e sufixos internos (`localhost`, `.local`, `.internal`, `.localdomain`, `.home.arpa`) são recusados sem DNS. IDN é convertido para ASCII; o último rótulo precisa começar com letra.
5. **Porta:** 443 (e 80 só com http fora de produção); outras só se listadas em `webhooks.allowed_ports`.
6. A URL usada é **reconstruída** a partir das partes analisadas (sem fragmento, host normalizado).

### 6.2 Resolução e faixas bloqueadas

Resolve **A e AAAA** (`App\Support\Http\DnsResolver`; nos testes, um resolvedor falso). **Todos** os endereços precisam ser públicos — basta um interno para recusar. Duas camadas (`IpClassifier`): lista explícita + `FILTER_FLAG_GLOBAL_RANGE`.

- IPv4: `0.0.0.0/8`, `10/8`, `100.64/10` (CGNAT), `127/8`, `169.254/16` (link-local, **inclui 169.254.169.254** de metadados), `172.16/12`, `192.0.0/24`, `192.0.2/24`, `192.31.196/24`, `192.52.193/24`, `192.88.99/24`, `192.168/16`, `192.175.48/24`, `198.18/15`, `198.51.100/24`, `203.0.113/24`, `224/4` (multicast), `240/4`, `255.255.255.255`.
- IPv6: `::`, `::1`, `::/96`, `100::/64`, `2001::/23`, `2001:db8::/32`, `3fff::/20`, `5f00::/16`, `64:ff9b:1::/48`, `fc00::/7` (**inclui `fd00:ec2::254`**), `fe80::/10`, `fec0::/10`, `ff00::/8`.
- **IPv6 que embute IPv4** (`::ffff:a.b.c.d`, `::ffff:0:a.b.c.d`, `64:ff9b::/96`, `2002::/16`) é **sempre** recusado, com o rótulo da faixa do IPv4 (ex.: `ipv4_embedded:loopback`).

A mensagem ao usuário é **a mesma** para "não resolve" e "resolve para endereço interno" — a tela de cadastro não vira oráculo do DNS interno. O detalhe (endereço e faixa) vai só para o log.

### 6.3 Conexão

- **Pino de IP:** `CURLOPT_RESOLVE => ["host:porta:endereço-validado"]` (IPv6 entre colchetes). O cURL não consulta o DNS de novo — um _DNS rebinding_ entre a checagem e a conexão não tem efeito. O host continua na URL, então **SNI, cabeçalho Host e validação do certificado continuam sendo do nome**.
- `allow_redirects => false` (3xx = falha), `protocols => [esquema validado]`, `proxy => ''` (desliga `HTTP(S)_PROXY`/`ALL_PROXY` do ambiente — um proxy resolveria o nome por conta própria), `verify => true`.
- Tempos: conexão 5 s, total 10 s. Resposta num `sink` limitado (`BoundedResponseBuffer`: guarda 512 bytes, descarta o resto sem acumular) e nunca interpretada.

### 6.4 Risco R6 — o que o teste de integração provou (e corrigiu)

`PinnedConnectionTest` sobe `php -S 127.0.0.1:<porta livre>` e entrega para **`pinned-receiver.invalid`** — TLD reservado (RFC 2606) que **nenhum resolvedor do sistema resolve**. O DNS falso da aplicação diz `127.0.0.1` (permitido só por `webhooks.testing_allowed_cidrs`, ignorado em produção). Resultados:

1. **O pino funciona** com Guzzle 8.2.0 + libcurl 8.16.0: a entrega chega ao servidor, o `Host` recebido é o nome, a assinatura confere e o IP efetivo (`primary_ip`) é o pinado. Controle negativo: sem o pino, o mesmo nome não chega ao servidor.
2. O endereço da conexão é **o do pino**: pinado em `127.0.0.2` (sem servidor), a tentativa falha e o servidor não recebe nada.
3. **Achado:** a primeira versão usava `stream => true` para ler a resposta aos poucos. Com essa opção o Guzzle despacha pelo **StreamHandler**, que não é cURL. No Guzzle 8.2 a requisição falha ("the stream handler ignores cURL options"); **no Guzzle 7 a opção seria ignorada em silêncio e a entrega sairia sem pino** — exatamente o risco R6. Corrigido: sem `stream`, com `sink` limitado. **Regra:** nunca usar `stream => true` num cliente que dependa de `CURLOPT_RESOLVE`.
4. Redirecionamento real (302 para `127.0.0.1/internal`) é recusado e o destino interno **não recebe** requisição.
5. Tempo esgotado real vira `unknown`.
6. Com `http_proxy`, `HTTPS_PROXY` e `ALL_PROXY` apontando para uma porta morta, a entrega continua saindo direto (proxy de ambiente ignorado).

### 6.5 Pino de IP em HTTPS — o que `HttpsPinTest` provou (viabilidade §7 item 3)

A lacuna acima (o servidor embutido do PHP não fala TLS) foi fechada por `tests/Feature/Phase2/Webhooks/HttpsPinTest.php`, com TLS real e sem sair da máquina:

- **Montagem.** Em tempo de teste, `tests/Support/Https/make_certs.py` (o `cryptography` do venv do pdftool) gera uma **AC de teste** e quatro certificados de servidor: `good` (SAN DNS `hooks.assinavelox.test`), `wrong` (SAN DNS `outro.assinavelox.test`), `iponly` (só SAN IP `127.0.0.1`, sem nome) e `rogue` (nome certo, emitido por **outra** AC, não confiável). `tests/Support/Https/https_server.py` (módulo `ssl` do Python) sobe um servidor HTTPS em `127.0.0.1:<porta livre>`, registra o **SNI** de cada ClientHello e cada requisição, e é encerrado no teardown pelo PID que o teste iniciou (`HttpsTestServer::stop()`; a árvore do processo cai junto). O nome usa o TLD reservado `.test`, que o DNS do sistema **não resolve**: se a conexão acontece, foi pelo pino. `testing_allowed_cidrs` libera `127.0.0.1/32` só nesse teste (ignorado em produção).
- **Confiança sem desligar a verificação.** O transporte fixa `verify => true` e o Guzzle 8.2 recusa `CURLOPT_CAINFO` dentro de `curl`. O teste instala um middleware global **só de teste** que troca `verify: true` pelo caminho da AC de teste — `VERIFYPEER` e `VERIFYHOST=2` continuam ligados, só a âncora muda — e registra as opções recebidas, provando que o transporte pediu `verify: true` e pinou `nome:porta:127.0.0.1`. O código de produção **não mudou**.

Resultados (Guzzle 8.2.0, libcurl 8.16.0 com OpenSSL 3.0.18, PHP 8.3, Windows 11; estável em 3 execuções seguidas):

1. **Entrega HTTPS real pelo pino:** chega ao servidor, SNI recebido = `hooks.assinavelox.test`, `Host` = nome:porta, assinatura confere, `primary_ip` = `127.0.0.1`. Controle negativo: sem pino o nome não conecta.
2. **O DNS muda entre a validação e a conexão:** o guard valida (`127.0.0.1`), o DNS passa a dizer `127.0.0.2`, onde há um **segundo servidor com certificado válido para o nome**. A requisição pinada vai para `127.0.0.1`; o segundo servidor não recebe nem um ClientHello. Controle: revalidando, a conexão vai para `127.0.0.2` e é aceita — só o pino o mantinha de fora.
3. **Verificação pelo NOME, não pelo IP:** o certificado `good` não tem SAN de IP e é aceito; o `iponly` (válido para o IP da conexão, sem o nome) é **recusado**. Com `wrong` e `rogue` também. Nos três casos o servidor recebe o ClientHello com SNI = nome, mas **nenhum byte HTTP** (cabeçalhos, corpo, assinatura) é enviado; o resultado é `failed`/`connection_failed` (cURL 60) e, na entrega real, fica agendada a retentativa — nunca uma nova tentativa sem verificação.
4. **Nada disso reabre SSRF:** sem a exceção de teste o mesmo nome (→ `127.0.0.1`) é recusado (`blocked_address`); IP literal em `https://127.0.0.1:<porta>` é recusado; rebinding para `10.0.0.5` depois do cadastro é bloqueado a cada tentativa antes de qualquer conexão (o servidor não vê nem o handshake); 302 HTTPS para `https://127.0.0.1/internal` não é seguido.

**Nenhuma correção foi necessária:** `CURLOPT_RESOLVE` com o nome mantido na URL faz o libcurl conectar no IP pinado usando o nome para SNI e para a verificação do certificado. **Continua valendo para ligar em produção:** rodar `PinnedConnectionTest` e `HttpsPinTest` verdes no ambiente-alvo (a versão do libcurl/TLS de lá pode ser outra) e fazer um teste de fumaça com um endpoint https real de homologação e AC pública.

## 7. Isolamento e mínimo privilégio

- Endpoints e entregas pertencem à organização (`BelongsToOrganization`): binding escopado por ULID → **404** fora da organização corrente; a entrega é resolvida **dentro** do endpoint (`scopeBindings`).
- Toda ação exige **`manage_integrations`** (`App\Policies\WebhookEndpointPolicy`, papéis de sistema: proprietário e administrador; delegável a função personalizada). Sem ela: **403**.
- O endpoint age com as permissões de **quem responde por ele** (`created_by_user_id`):
    - se essa pessoa perder `manage_integrations` ou sair, o endpoint é **pausado** (`creator_without_access`) e os administradores são avisados; quem reativa passa a responder por ele;
    - o endpoint só recebe eventos de envelopes que essa pessoa **pode ver** — a mesma regra da interface (`EnvelopeVisibility`). Uma função personalizada com `manage_integrations` e sem `view_all_envelopes` só recebe eventos dos envelopes que criou ou das pastas a que tem acesso.
- **Redirecionar um endpoint** — trocar a URL ou rotacionar o segredo (que aparece para quem rotaciona) — exige a policy `redirect`: ser o **responsável** pelo endpoint ou ter **`view_all_envelopes`**. Sem isso, **403**. Motivo: o endpoint continua agindo com a visibilidade do responsável; uma função com `manage_integrations` e visão restrita que apontasse o endpoint do proprietário para a própria URL receberia eventos de envelopes que a interface esconde dela. Vale também para REST Hooks: só o criador do token ou quem vê tudo. Eventos, descrição, pausa, reativação, teste e reenvio seguem com `manage_integrations` (não mudam o destino).
- Na tela, o vínculo com o envelope e o corpo da entrega só aparecem para quem pode ver aquele envelope. Com o corpo oculto, o **trecho da resposta** e o **IP conectado** de cada tentativa também saem `null` no histórico (receptores que ecoam o corpo recebido reexporiam o envelope).
- Fila, log e notificação nunca carregam o segredo (T10): o job leva só o id interno da entrega; os logs levam ULIDs, host, códigos e `correlation_id`.

## 8. Modelo de dados (migrations `2026_09_11_1401xx`, só aditivas)

| Tabela               | Colunas principais                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `webhook_endpoints`  | `ulid`, `organization_id`, `created_by_user_id` (responsável), `url`, `description`, `events` (JSON; `["*"]` = todos), `secret` e `previous_secret` (**cifrados**), `secret_hint` (`…abcd`), `previous_secret_expires_at`, `secret_rotated_at`, `is_active`, `paused_at`, `paused_reason`, `consecutive_failures`, `last_success_at`, `last_failure_at`, `source` (`web`/`api`/`rest_hook`), `api_token_id` (sem FK), soft delete                    |
| `webhook_deliveries` | `ulid` (id da entrega), `organization_id`, `webhook_endpoint_id`, `envelope_id`, `event_id`, `event_type`, `payload` (corpo bruto), `status` (`pending`/`delivered`/`failed`/`exhausted`/`canceled`), `is_test`, `attempts`, `next_retry_at`, `locked_until`, `last_response_code`, `last_duration_ms`, `last_error`, `last_attempt_at`, `delivered_at`, `history` (JSON), `correlation_id`. **Único** `(webhook_endpoint_id, event_type, event_id)` |

Remover um endpoint: soft delete, entregas abertas canceladas, **segredo sobrescrito** por um valor aleatório; a limpeza apaga de vez depois da retenção.

## 9. Contrato para as telas (a cargo do front)

Todas no grupo autenticado da organização, nomes `integrations.webhooks.*`, 404 com a flag desligada, 403 sem `manage_integrations`. Parâmetros por ULID.

| Método | Caminho                                            | Nome                     | Resposta                                                                                         |
| ------ | -------------------------------------------------- | ------------------------ | ------------------------------------------------------------------------------------------------ |
| GET    | `/api-integracoes/webhooks`                        | `index`                  | Inertia `integrations/webhooks/index`                                                            |
| POST   | `/api-integracoes/webhooks`                        | `store`                  | 302 → `show` com o segredo no flash (`url`, `events[]`, `description?`); erros em `url`/`events` |
| GET    | `/api-integracoes/webhooks/{webhookEndpoint}`      | `show`                   | Inertia `integrations/webhooks/show` (`?status=`, `?event=`, `?page=`)                           |
| PATCH  | `/api-integracoes/webhooks/{webhookEndpoint}`      | `update`                 | 302 back (`url?`, `events?`, `description?`)                                                     |
| DELETE | `/api-integracoes/webhooks/{webhookEndpoint}`      | `destroy`                | 302 → `index`                                                                                    |
| POST   | `…/{webhookEndpoint}/pausar`                       | `pause`                  | 302 back                                                                                         |
| POST   | `…/{webhookEndpoint}/reativar`                     | `resume`                 | 302 back (`flash.error` se a URL deixou de passar na proteção)                                   |
| POST   | `…/{webhookEndpoint}/segredo/rotacionar`           | `secret.rotate`          | 302 → `show` com o segredo novo no flash (`overlap_hours?` 0–168; padrão 24)                     |
| POST   | `…/{webhookEndpoint}/segredo/encerrar-anterior`    | `secret.expire_previous` | 302 back                                                                                         |
| POST   | `…/{webhookEndpoint}/testar`                       | `test`                   | 302 back (enfileira `webhook.ping`)                                                              |
| GET    | `…/{webhookEndpoint}/entregas/{delivery}`          | `deliveries.show`        | **JSON** (gaveta), `Cache-Control: no-store`                                                     |
| POST   | `…/{webhookEndpoint}/entregas/{delivery}/reenviar` | `deliveries.resend`      | 302 back (`flash.success` ou `flash.error`)                                                      |

Limites: criar/alterar/reativar/reenviar 30/min; rotacionar e testar 10/min.

**Props de `index`:** `endpoints: Endpoint[]`, `catalog: {value, label, description}[]`, `limits`, `revealed_secret: {endpoint, secret} | null`.
**Props de `show`:** `endpoint: Endpoint`, `deliveries: Paginated<Delivery>` (25 por página, formato `{data, links, meta}`), `filters: {status, event}`, `catalog`, `statuses: {value, label}[]`, `limits`, `revealed_secret`.

- `Endpoint`: `id`, `url`, `host`, `description`, `events`, `all_events`, `status` (`active`/`paused`), `status_label`, `paused_at`, `paused_reason`, `paused_reason_label`, `consecutive_failures`, `last_success_at`, `last_failure_at`, `secret_hint`, `secret_rotated_at`, `previous_secret_expires_at` (não nulo = rotação em convivência), `source`, `created_by`, `created_at`.
- `Delivery`: `id`, `event_id`, `event_type`, `event_label`, `status`, `status_label`, `is_test`, `attempts`, `max_attempts`, `last_response_code`, `last_duration_ms`, `last_error`, `last_error_label`, `next_retry_at`, `last_attempt_at`, `delivered_at`, `created_at`, `envelope: {id, code} | null` (null se quem olha não vê o envelope), `can_resend`.
- Detalhe JSON = `Delivery` + `payload` (objeto, ou `null` com `payload_hidden: true`), `history[]` (`attempt`, `trigger`, `outcome`, `outcome_label`, `response_code`, `duration_ms`, `error`, `error_label`, `response_excerpt`, `remote_ip`, `attempted_at`; com `payload_hidden: true`, `response_excerpt` e `remote_ip` saem `null`) e `headers` (nomes dos cabeçalhos, para a documentação na tela).
- `PATCH update` com `url` diferente da atual e `secret.rotate` respondem **403** para quem não é o responsável nem tem `view_all_envelopes` (§7).
- `limits`: `max_endpoints`, `max_attempts`, `backoff_seconds[]`, `pause_after_consecutive_failures`, `signature_tolerance_seconds`, `secret_rotation_overlap_hours`, `max_rotation_overlap_hours`, `timeout_seconds`, `retention_days`.
- **Segredo "mostrado uma vez":** chega em `revealed_secret` **só** na primeira renderização de `show` depois de `store`/`secret.rotate` (flash de uma requisição; recarregar já não traz). A tela deve oferecer "copiar" e avisar que não será mostrado de novo. Fora disso, só `secret_hint`. Observação: o flash passa pela sessão; com `SESSION_ENCRYPT=false` ele fica em claro no armazenamento da sessão até a próxima requisição.
- Mocks de referência: `App - Integracoes.dc.html` (a lista usa nomes antigos como `document.completed`/`signer.refused`; o contrato é o catálogo da §2).

## 10. Contrato para REST Hooks (§2.17 — a cargo de D-API)

Reaproveitar o motor; **não** duplicar lógica de rede, segredo ou entrega.

- `POST /api/v1/webhook-subscriptions` `{target_url, event}` (ou `events[]`):
    1. token com ability `webhooks:manage` **e** `Gate::forUser($autorDoToken)->allows('create', WebhookEndpoint::class)`;
    2. `app(WebhookEndpointManager::class)->create($organizacao, $autorDoToken, $targetUrl, [$event], null, WebhookEndpoint::SOURCE_REST_HOOK, $tokenId)` → `['endpoint' => …, 'secret' => …]`;
    3. responder `201` com `{id: endpoint.ulid, target_url, events, secret}` (o segredo só nesta resposta);
    4. `App\Support\Http\BlockedOutboundUrl` → `422` problem+json (`detail = $e->userMessage()`, nunca `$e->detail`); `App\Services\Webhooks\WebhookActionRefused` → `409`/`422` com a mensagem.
- `DELETE /api/v1/webhook-subscriptions/{id}`: `WebhookEndpoint::forOrganization($org)->where('ulid', $id)->firstOrFail()` + policy `delete` com o autor do token (recomendado: restringir a `source = rest_hook` e ao mesmo `api_token_id`) → `$manager->delete($endpoint)` → `204`.
- O autor do token vira o **responsável**: a regra de visibilidade (§7) vale automaticamente; se o autor perder `manage_integrations`, o endpoint é pausado.
- Ao revogar um token, sugerido remover (ou pausar) os endpoints com o mesmo `api_token_id`.
- Payload, assinatura, retentativas, pausa e histórico são idênticos aos da web. Para o "teste" dos conectores, `$manager->sendTest($endpoint)`.

## 11. Operação

| Item      | Valor                                                                                                                                                                                                                                                                                                                                                        |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Comandos  | `webhooks:retry` (a cada minuto; retentativas vencidas + limpeza de segredos anteriores vencidos), `webhooks:prune` (diário 04:55)                                                                                                                                                                                                                           |
| Fila      | `ASSINAVELOX_WEBHOOKS_QUEUE` (padrão `default`, processada por qualquer worker). Recomenda-se uma fila própria `webhooks` — **se trocar, inclua-a na supervisão do Horizon**, senão nada sai.                                                                                                                                                                |
| Variáveis | `ASSINAVELOX_FEATURE_OUTBOUND_WEBHOOKS`, `ASSINAVELOX_WEBHOOKS_ALLOW_HTTP`, `…_ALLOWED_PORTS` (padrão `443`), `…_CONNECT_TIMEOUT` (5), `…_TIMEOUT` (10), `…_RESPONSE_EXCERPT_BYTES` (512), `…_MAX_ATTEMPTS` (8), `…_PAUSE_AFTER_FAILURES` (20), `…_ROTATION_OVERLAP_HOURS` (24), `…_MAX_ENDPOINTS` (10), `…_RETRY_BATCH_SIZE` (200), `…_RETENTION_DAYS` (30) |
| Logs      | `webhooks.endpoint_created/updated/resumed/deleted`, `webhooks.secret_rotated`, `webhooks.delivery_attempt` (info), `webhooks.delivery_blocked`, `webhooks.endpoint_paused` (warning), `webhooks.delivery_internal_error`, `webhooks.fan_out_failed` (error) — sempre com `correlation_id`, nunca segredo                                                    |
| Ativar    | global `ASSINAVELOX_FEATURE_OUTBOUND_WEBHOOKS=true` **e** `plans.features.outbound_webhooks = true` no plano; antes, rodar `php artisan test tests/Feature/Phase2/Webhooks` no ambiente-alvo (R6)                                                                                                                                                            |

## 12. Pendências de integração (fora da área D-HOOK)

1. `HandleInertiaRequests::features()` — expor `outbound_webhooks` a partir de `WebhooksFeature::enabled($organization)` (e `resources/js/types` / `SharedPropsTest`).
2. Trilha da gestão de endpoints: acrescentar em `AuditEventType` (ex.: `webhook_endpoint.created/updated/paused/resumed/deleted`, `webhook_endpoint.secret_rotated`) e gravar pela `OrganizationTrail`. Hoje a gestão fica só no log estruturado.
3. Notificação `webhook_failed`: incluir no catálogo de `NotificationPreferences` e em `DeliveryPurpose`, e trocar o canal `mail` por `TrackedMailChannel` respeitando a preferência. Hoje vai por `mail` + `database` para todos com `manage_integrations`.
4. Tocados fora da área, o mínimo necessário: `bootstrap/providers.php` (uma linha, `WebhooksServiceProvider`) e `tests/Feature/Smoke/AllGetRoutesTest.php` (as três rotas GET novas em `SMOKE_OVERRIDES` com 404 e ULIDs sintéticos em `smokeRouteParameters`, mesmo padrão de `public_forms.*`). Arquivos novos: `app/Policies/WebhookEndpointPolicy.php` e `app/Http/Controllers/Integrations/Middleware/EnsureOutboundWebhooksFeature.php` (classe e não closure: o smoke test e `Permissions::requiredForRoute` leem o middleware como nome).
5. Linha "Webhook" nos Detalhes do envelope (roadmap §2.0): última entrega por `envelope_id` (índice já existe).
6. Plano de demonstração (`DemoOrganizationSeeder`) e Wayfinder (`php artisan wayfinder:generate --with-form`) quando as telas existirem.

## 13. Testes

`tests/Feature/Phase2/Webhooks/` — nenhum acessa a rede (DNS falso + `Http::fake()` + `preventStrayRequests`), exceto `PinnedConnectionTest` e `HttpsPinTest`, que conectam só em loopback (`127.0.0.1`/`127.0.0.2`).

| Arquivo                     | Cobre                                                                                                                                                                                                                                                                                                                                                                       |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SsrfGuardTest.php`         | cada faixa bloqueada (IPv4, IPv6, IPv4 embutido/mapeado), nome que resolve para interno, resposta mista, IP literal em decimal/octal/hex/curto/IPv6/zona, host interno, credenciais, barra invertida/controle, porta, esquemas, http e exceção de teste recusados em produção, mensagem sem oráculo, IDN, recusa no cadastro, revalidação a cada entrega                    |
| `HttpsPinTest.php`          | pino em **HTTPS** real (AC e certificados gerados no teste): SNI/Host = nome, DNS que muda após a validação não desvia a conexão, certificado de outro nome / só-IP / AC estranha recusado sem enviar bytes HTTP, SSRF continua fechado (§6.5)                                                                                                                              |
| `PinnedConnectionTest.php`  | **R6**: pino real via `CURLOPT_RESOLVE`, controle negativo, endereço da conexão = pino, redirecionamento real não seguido, timeout real = `unknown`, proxy de ambiente ignorado                                                                                                                                                                                             |
| `SignatureTest.php`         | HMAC confere e muda com 1 byte, janela de 5 min, formato do segredo, entrega real verificável, segredo cifrado e fora da serialização, segredo antigo só dentro da janela, encerrar convivência, no máximo dois segredos                                                                                                                                                    |
| `DeliveryLifecycleTest.php` | entrega enfileirada (job sem segredo/URL), histórico, backoff e teto de 8 com relógio congelado, timeout = desconhecido, 3xx não seguido, pausa automática + aviso, sucesso zera falhas, reenvio manual com o mesmo id, reenvio recusado com endpoint pausado, idempotência (gancho e job duplicados), trava, dois endpoints, filtro de eventos, endpoint removido, limpeza |
| `PayloadPrivacyTest.php`    | forma estável, nenhum dado proibido em nenhum evento, rótulos honestos, `signature` igual à verificação pública, documento com falha, trecho de resposta truncado e redigido                                                                                                                                                                                                |
| `IsolationTest.php`         | 404 entre organizações em todas as rotas, binding escopado da entrega, 403 sem permissão, admin gerencia, eventos não cruzam organizações, responsável que perde acesso, função personalizada sem `view_all`, corpo oculto na tela para quem não vê o envelope                                                                                                              |
| `ManagementTest.php`        | criar com segredo mostrado uma vez, validação, limite, alterar com revalidação, pausar/reativar, teste, rotação pela tela, remover, detalhe JSON, filtros do histórico                                                                                                                                                                                                      |
| `FlagOffTest.php`           | flag nasce desligada; global desligado = nenhuma entrega, chamada ou consulta; plano sem a flag; flag desligada depois de enfileirar cancela; rotas 404; placeholders da Fase 1 intactos                                                                                                                                                                                    |
