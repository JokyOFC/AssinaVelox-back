# AssinaVelox — Segurança operacional

> Complementa `docs/arquitetura.md` §10, `docs/autorizacao-e-isolamento.md` (quem pode o quê),
> `docs/configuracao.md` (variáveis) e `docs/banco-de-dados.md` §4.3 (append-only).
> Escopo: o que a instalação faz para se defender, como conferir isso com comandos reais e —
> com igual destaque — **o que não está garantido**.

Índice: [1 Cabeçalhos](#1-cabeçalhos-e-política-de-conteúdo) · [2 Limites](#2-limites-de-requisição) ·
[3 Trilha](#3-trilha-imutável-na-prática) · [4 Segredos e chaves](#4-segredos-e-chaves) ·
[5 Armazenamento](#5-criptografia-em-repouso-do-armazenamento) · [6 Observabilidade](#6-observabilidade) ·
[7 Isolamento do conversor](#7-isolamento-do-conversor) · [8 Testes](#8-testes) ·
[9 O que é garantido e o que não é](#9-o-que-é-garantido-e-o-que-não-é)

---

## 1. Cabeçalhos e política de conteúdo

Middleware: `app/Http/Middleware/SecurityHeaders.php`, registrado com `append` **global** em
`bootstrap/app.php` — fora do grupo `web`. Isso é o que faz os cabeçalhos valerem em _toda_
resposta que sai da aplicação: página Inertia, stream de PDF, PNG de página, JSON do webhook,
download autorizado e as páginas de erro 403/404/500/503, inclusive quando a exceção nasce
dentro de outro middleware.

### 1.1 Cabeçalhos emitidos

| Cabeçalho                           | Valor                                 | Onde                          |
| ----------------------------------- | ------------------------------------- | ----------------------------- |
| `X-Content-Type-Options`            | `nosniff`                             | todas                         |
| `X-Frame-Options`                   | `DENY`                                | todas                         |
| `X-Permitted-Cross-Domain-Policies` | `none`                                | todas                         |
| `Cross-Origin-Opener-Policy`        | `same-origin-allow-popups`            | todas (configurável)          |
| `Cross-Origin-Resource-Policy`      | `same-origin`                         | todas (configurável)          |
| `Permissions-Policy`                | negação explícita (§1.2)              | todas (configurável)          |
| `Referrer-Policy`                   | `strict-origin-when-cross-origin`     | geral                         |
| `Referrer-Policy`                   | `no-referrer`                         | `/assinar/*` e `/verificar/*` |
| `X-Robots-Tag`                      | `noindex, nofollow, noarchive`        | `/assinar/*` e `/verificar/*` |
| `Strict-Transport-Security`         | `max-age=31536000; includeSubDomains` | requisições HTTPS             |
| `Content-Security-Policy`           | §1.3                                  | todas, com nonce por resposta |
| `X-Correlation-Id`                  | ULID da requisição                    | todas (§6.1)                  |

`Referrer-Policy: no-referrer` nas rotas de assinatura e verificação não é preciosismo: a URL
de `/assinar/{token}` **contém o segredo do convite**. Sem esse cabeçalho, qualquer recurso
externo carregado por aquela página levaria o token inteiro no `Referer` para um servidor de
terceiro. Junto com `X-Robots-Tag`, também tira essas páginas dos buscadores.

### 1.2 `Permissions-Policy`

Negação explícita de `accelerometer`, `ambient-light-sensor`, `autoplay`, `battery`,
`bluetooth`, `camera`, `display-capture`, `encrypted-media`, `geolocation`, `gyroscope`,
`hid`, `idle-detection`, `local-fonts`, `magnetometer`, `microphone`, `midi`, `payment`,
`picture-in-picture`, `screen-wake-lock`, `serial`, `usb` e `xr-spatial-tracking`.

Ficam liberados só para a própria origem: `fullscreen=(self)` (visualizador de PDF) e
`publickey-credentials-get=(self)` (passkeys, previstas para fase futura).

`payment=()` merece nota: o Checkout Pro acontece **no site do Mercado Pago**, nunca aqui.
Nenhuma tela nossa tem motivo para tocar a Payment Request API.

### 1.3 Content-Security-Policy

```
default-src 'self';
script-src 'self' 'nonce-<aleatório por resposta>';
style-src 'self' 'unsafe-inline';
img-src 'self' data: blob:;
font-src 'self' data:;
connect-src 'self';
worker-src 'self' blob:;
frame-src 'none'; frame-ancestors 'none'; object-src 'none';
base-uri 'self'; form-action 'self'
```

- **`script-src` não tem `unsafe-inline`.** O único script inline da página é o payload do
  Inertia, que recebe o nonce por `Vite::useCspNonce()`. O nonce é novo a cada resposta (há
  teste para isso: nonce reaproveitado é nonce nenhum).
- **`unsafe-inline` aparece uma única vez, em `style-src`.** Tailwind 4 e o Radix escrevem
  estilo inline para posicionar _popover_, _tooltip_ e barra de rolagem. Trocar isso por
  nonce de estilo exigiria reescrever a camada de componentes de terceiro; a decisão é
  consciente e está coberta por teste que conta as ocorrências.
- **`worker-src 'self' blob:` é obrigatório.** O PDF.js instancia o worker a partir de um
  `Blob`; sem isso o editor de campos e a tela do signatário simplesmente não abrem. Há teste.
- `frame-ancestors 'none'` + `X-Frame-Options: DENY` fecham o enquadramento (clickjacking
  sobre o botão de aceite).
- Em `local` com o Vite em modo _hot_, a origem do dev server e o WebSocket do HMR entram em
  `script-src`/`connect-src` automaticamente.

**Não emitimos `Cross-Origin-Embedder-Policy`.** `require-corp` obrigaria todo recurso de
terceiro a declarar CORP/CORS e não compra nada aqui: não usamos `SharedArrayBuffer` nem
medição isolada de memória.

### 1.4 Configuração

`ASSINAVELOX_CSP_ENABLED`, `ASSINAVELOX_CSP_REPORT_ONLY`, `ASSINAVELOX_CSP_EXTRA_SOURCES`,
`ASSINAVELOX_PERMISSIONS_POLICY`, `ASSINAVELOX_COOP`, `ASSINAVELOX_CORP`.

Ao subir para produção pela primeira vez: `ASSINAVELOX_CSP_REPORT_ONLY=true`, observe os
relatos por alguns dias, depois desligue. Um cabeçalho configurável com valor vazio deixa de
ser emitido (útil quando um proxy já o injeta).

---

## 2. Limites de requisição

Dois lugares definem limite: `routes/web.php` (`throttle:` na própria rota) e o mapa
`assinavelox.rate_limit_routes`, aplicado pelo middleware `ThrottleSensitiveRoutes` no grupo
`web`. O mapa existe porque **as rotas mais atacadas de qualquer SaaS — cadastro, "esqueci
minha senha" e redefinição — são registradas pelo pacote Fortify, que não traz limitador
nenhum nelas** e não expõe ponto de extensão por rota. Uma rota pode ter os dois limites; os
baldes são independentes e vale o mais restritivo.

### 2.1 Tabela de limites

| Rota                                          | Limitador              | Limite               | Chave                 |
| --------------------------------------------- | ---------------------- | -------------------- | --------------------- |
| `POST /login`                                 | `login` (Fortify)      | 5/min                | `e-mail` + IP         |
| `POST /two-factor-challenge`                  | `two-factor` (Fortify) | 5/min                | id da sessão de login |
| `POST /register`                              | `register`             | 5/10 min · 20/h      | IP · IP               |
| `POST /forgot-password`                       | `password-email`       | 3/10 min · 15/h      | `e-mail`+IP · IP      |
| `POST /reset-password`                        | `password-reset`       | 6/10 min · 30/h      | `e-mail`+IP · IP      |
| `POST /user/confirm-password`                 | `password-confirm`     | 6/min                | usuário               |
| `PUT /perfil/senha`                           | rota                   | 6/min                | usuário/IP            |
| `GET/POST /email/verify*`                     | Fortify                | 6/min                | usuário               |
| `GET/POST /convites/{token}`                  | `invitation`           | 20/min · 60/min      | IP+token · IP         |
| `GET /` `/termos` `/privacidade` `/verificar` | `public`               | 60/min               | IP                    |
| `GET /verificar/{code}`                       | rota                   | 20/min               | IP                    |
| `POST /verificar/{code}/conferir`             | rota                   | 10/min               | IP                    |
| `/assinar/{token}/*` (grupo)                  | `signer`               | 30/min · 120/min     | IP+token · IP         |
| `POST /assinar/{token}/codigo`                | `otp-send`             | 3/10 min · 15/10 min | token+IP · token      |
| `POST /assinar/{token}/codigo/verificar`      | `otp-verify`           | 5/10 min · 25/10 min | token+IP · token      |
| `POST /assinar/{token}/assinar`               | rota                   | 10/min               | IP                    |
| `GET /busca`                                  | `search`               | 120/min              | usuário               |
| `GET .../download/*`, `/evidencias`, recibo   | `download`             | 60/min · 600/h       | ator · IP             |
| exportações (dashboard, assinaturas, admin)   | `export`               | 10/10 min            | ator                  |
| `POST /webhooks/mercadopago`                  | `webhook`              | 300/min              | IP                    |

"ator" = usuário autenticado ou, no fluxo público, a sessão do signatário; IP como último
recurso.

### 2.2 Por que as chaves são compostas

Um limitador chaveado **só pela identidade da vítima** vira arma. Com a chave em `email`,
qualquer pessoa trancaria a recuperação de senha de outra repetindo o formulário com o
endereço alheio. Com `email|IP`, o atacante gasta o próprio balde e a vítima, vindo de outro
IP, continua conseguindo pedir o link — comportamento coberto por teste.

O erro simétrico é chavear **só pelo segredo**: com o token na chave, cada palpite estreia um
balde novo e o limitador não freia varredura nenhuma. Por isso `signer`, `otp-*` e
`invitation` têm sempre um segundo balde só por origem, que cobre volume.

O teto absoluto do código por e-mail **não** é o limitador: é
`auth_challenges.max_attempts` (5) mais "um código vivo por vez", que valem mesmo se o
atacante trocar de IP a cada tentativa.

### 2.3 Dependência de `TRUSTED_PROXIES`

Todo limite por IP vale o que valer o IP. Atrás de balanceador, configure
`TRUSTED_PROXIES` com a **lista de IPs/CIDRs** do balanceador. Nunca `*`: com curinga o
`X-Forwarded-For` passa a ser escrito pelo cliente e todos os limites por IP viram zero
(a aplicação, por isso, ignora o cabeçalho quando vê `*`). Ver `docs/configuracao.md` §2.1.

---

## 3. Trilha imutável na prática

### 3.1 O que já existe

- `audit_events` não tem `updated_at`; o model lança `LogicException` em `updating`/`deleting`.
- A aplicação não emite `UPDATE`/`DELETE` nas tabelas de evidência (§3.4).
- Envelopes e organizações usam exclusão lógica: `deleted_at`, nunca `DELETE`.

### 3.2 O que isso **não** garante

Diga-se claramente, porque a diferença aparece justamente quando alguém precisa da trilha:

- **Tabela append-only não impede alteração.** "Append-only" aqui é uma regra da aplicação.
  Quem tem `UPDATE`/`DELETE` no MySQL — ou acesso ao arquivo de dados do InnoDB, ou ao
  backup — reescreve a trilha sem deixar rastro.
- **Exclusão lógica não é retenção.** `deleted_at` some da consulta; a linha continua lá e
  pode ser apagada de verdade por quem tem privilégio.
- **Encadeamento guardado no mesmo banco também não impede nada.** Quem altera os eventos
  pode recalcular os elos.

O que protege de fato é a **combinação de duas coisas**: privilégio restrito no banco
(§3.3) e checkpoint copiado para fora do alcance de quem administra o banco (§3.5).

### 3.3 Privilégios recomendados no MySQL

Dois usuários: um para **migrations** (DDL, usado só no deploy) e um de **runtime**.

```sql
-- Usuário de migrations: só o deploy o usa.
CREATE USER 'assinavelox_ddl'@'10.0.%' IDENTIFIED BY '<segredo do cofre>';
GRANT ALL PRIVILEGES ON assinavelox.* TO 'assinavelox_ddl'@'10.0.%';

-- Usuário de runtime da aplicação (PHP-FPM, Horizon, cron).
CREATE USER 'assinavelox_app'@'10.0.%' IDENTIFIED BY '<segredo do cofre>';

-- Padrão: leitura e escrita completas.
GRANT SELECT, INSERT, UPDATE, DELETE ON assinavelox.* TO 'assinavelox_app'@'10.0.%';

-- E então a restrição, tabela a tabela. O MySQL não faz REVOKE parcial de um GRANT em
-- nível de banco: o privilégio de tabela é somado ao de banco, e não subtraído dele.
-- Portanto, para valer, o GRANT em nível de banco acima precisa ser trocado por GRANTs
-- por tabela. A forma explícita, recomendada:
REVOKE ALL PRIVILEGES ON assinavelox.* FROM 'assinavelox_app'@'10.0.%';

-- Evidência: só inserir e ler.
GRANT SELECT, INSERT ON assinavelox.audit_events          TO 'assinavelox_app'@'10.0.%';
GRANT SELECT, INSERT ON assinavelox.signature_acceptances TO 'assinavelox_app'@'10.0.%';
GRANT SELECT, INSERT ON assinavelox.document_versions     TO 'assinavelox_app'@'10.0.%';

-- Registro de verificação: sem DELETE, COM UPDATE (motivo em §3.4).
GRANT SELECT, INSERT, UPDATE ON assinavelox.verification_records TO 'assinavelox_app'@'10.0.%';

-- Demais tabelas: escrita completa (repita para cada uma).
GRANT SELECT, INSERT, UPDATE, DELETE ON assinavelox.organizations TO 'assinavelox_app'@'10.0.%';
GRANT SELECT, INSERT, UPDATE, DELETE ON assinavelox.users         TO 'assinavelox_app'@'10.0.%';
GRANT SELECT, INSERT, UPDATE, DELETE ON assinavelox.envelopes     TO 'assinavelox_app'@'10.0.%';
-- ... e assim por diante para as demais tabelas de `docs/banco-de-dados.md` §3,
--     incluindo as do framework: sessions, cache, jobs, failed_jobs, job_batches,
--     notifications, migrations (SELECT), audit_checkpoints (SELECT, INSERT).

FLUSH PRIVILEGES;
```

Gere a lista concreta a partir do próprio banco, para não esquecer nenhuma tabela:

```sql
SELECT CONCAT('GRANT SELECT, INSERT, UPDATE, DELETE ON assinavelox.', TABLE_NAME,
              " TO 'assinavelox_app'@'10.0.%';") AS stmt
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'assinavelox'
  AND TABLE_NAME NOT IN ('audit_events','signature_acceptances','document_versions',
                         'verification_records','audit_checkpoints');
```

**Antes de aplicar, confira dois pontos na sua versão do MySQL:**

1. `document_versions` desaparece por `ON DELETE CASCADE` quando um documento em rascunho é
   substituído (`DocumentIntake` apaga a linha de `documents`; o InnoDB apaga as versões).
   A aplicação não emite esse `DELETE`. O InnoDB executa a ação referencial sem consultar os
   privilégios do usuário na tabela filha — mas confirme no seu servidor:

    ```sql
    -- Como assinavelox_app, num banco de homologação:
    DELETE FROM documents WHERE id = <um documento de rascunho descartável>;
    -- Se retornar erro de privilégio em document_versions, mantenha DELETE nessa tabela
    -- e registre a exceção aqui.
    ```

2. `audit_checkpoints` precisa de `INSERT` (o comando grava o resumo) e de nenhum `UPDATE`
   nem `DELETE`.

### 3.4 `verification_records` não é append-only — e dizer que é seria mentira

A retentativa da finalização **reescreve** o registro quando o arquivo final precisou ser
reconstruído (`EnvelopeFinalizer`, passo `verification_record: rewritten`). O caso concreto:
o processo cai entre gravar o registro e concluir o envelope e, no intervalo, o certificado
da operadora sai do ar. A retentativa reconstrói o `final` **sem assinatura**; reaproveitar o
registro antigo publicaria `signature_status = company_a1` e o `final_sha256` de um arquivo
descartado sobre um PDF que não tem assinatura nenhuma — a página pública afirmaria
assinatura criptográfica onde não há.

Reescrever é o comportamento correto. Por isso a tabela recebe `UPDATE` e nunca `DELETE`. A
política por tabela está em `config/assinavelox.php` → `audit.evidence_tables` e é conferida
por teste que espiona o SQL realmente emitido durante um aceite:

| Tabela                  | Política              | A aplicação pode                         |
| ----------------------- | --------------------- | ---------------------------------------- |
| `audit_events`          | `no_update_no_delete` | `SELECT`, `INSERT`                       |
| `signature_acceptances` | `no_update_no_delete` | `SELECT`, `INSERT`                       |
| `document_versions`     | `no_update_no_delete` | `SELECT`, `INSERT` (+ cascata do InnoDB) |
| `verification_records`  | `no_delete`           | `SELECT`, `INSERT`, `UPDATE`             |

### 3.5 `audit:checkpoint`

```bash
php artisan audit:checkpoint                          # do fim do último checkpoint até agora
php artisan audit:checkpoint --since=2026-09-01 --until=2026-09-08
php artisan audit:checkpoint --verify                 # reconfere a cadeia contra o disco
php artisan audit:checkpoint --json                   # para monitoramento
```

O comando exporta os `audit_events` do período para um arquivo **JSON Lines** no disco
privado (`documents:audit-checkpoints/<ano>/<sequência>-<ulid>.jsonl`): a primeira linha é o
cabeçalho, as seguintes são um evento cada, em ordem de `id`, com serialização determinística
(chaves fixas, `payload` com chaves ordenadas) — o hash é sobre o texto, então a ordem
importa.

Depois grava em `audit_checkpoints` o resumo do lote:

```
chain_sha256 = sha256(
    "assinavelox.audit-checkpoint/1" | sequência | período_início | período_fim |
    quantidade | primeiro_id | último_id | events_sha256 | chain_sha256_anterior
)
```

O elo inclui o elo anterior: reescrever um evento antigo obriga a reescrever todos os
arquivos e todos os resumos posteriores. `--verify` percorre a cadeia conferindo, para cada
elo: sequência sem buraco, elo anterior correspondente, arquivo presente, `file_sha256`
íntegro e cabeçalho que reproduz o `chain_sha256` registrado.

Agendado semanalmente (`routes/console.php`, segundas às 02:40).

**O passo que o agendador não faz, e sem o qual nada disso vale como prova:** copiar o
`chain_sha256` do último lote para **fora deste sistema**. Guardado só aqui, ele pode ser
recalculado por quem alterou os dados. Opções, da mais simples à mais forte:

1. e-mail do elo para o responsável jurídico a cada checkpoint (fora do domínio
   administrativo de quem opera o banco);
2. cópia do arquivo `.jsonl` para um repositório de retenção com escrita única
   (S3 Object Lock em modo _compliance_, por exemplo);
3. carimbo de tempo de terceiro sobre o elo. **Atenção:** um carimbo de tempo público
   **não é ICP-Brasil** e não pode ser apresentado como tal (`arquitetura.md` §2).

### 3.6 Como detectar uma adulteração

```bash
# 1. A cadeia é internamente coerente?
php artisan audit:checkpoint --verify

# 2. A janela já fechada continua produzindo o mesmo hash?
php artisan audit:checkpoint --since=<period_start> --until=<period_end> --json --allow-empty
#    Compare `events_sha256` com o valor registrado em audit_checkpoints.

# 3. O elo mais recente bate com a cópia guardada fora do sistema?
```

Divergência no passo 2 ou 3 significa que a trilha foi reescrita depois de exportada.

---

## 4. Segredos e chaves

### 4.1 `assinavelox:doctor`

```bash
php artisan assinavelox:doctor          # relatório legível
php artisan assinavelox:doctor --json   # para monitoramento
php artisan assinavelox:doctor --strict # aviso também derruba o código de saída
```

**Regra absoluta: nenhum valor de segredo é impresso.** O comando responde "está definido?",
"é válido?", "vence quando?" — nunca "vale quanto". Chave da aplicação, senha do PKCS#12,
token do Mercado Pago e segredo do webhook aparecem só como `definido`/`ausente`, com o
**nome** da variável. Há teste que planta segredos na configuração e falha se algum aparecer
na saída, inclusive no `--json`.

Verifica: `APP_KEY`, `APP_URL` (HTTPS obrigatório em produção), `APP_DEBUG`, fuso, proxies
confiáveis, versão dos Termos, estado da CSP; conexão e migrations pendentes; disco
`documents` gravável e privado; criptografia em repouso (recibo do `storage:verify`);
conexão de fila, filas nomeadas, acúmulo e falhas; comandos agendados; transporte de e-mail e
remetente; `pdftool` (disponibilidade e selftest ponta a ponta) e diretório temporário;
LibreOffice; certificado A1 (§4.2); credenciais e ambiente do Mercado Pago; último checkpoint
da trilha.

Três estados: `ok`, `aviso` e `falha`. Um item **desligado de propósito é aviso, não falha** —
sem certificado A1 o envelope conclui como _aceite eletrônico com evidências_, que é o
comportamento documentado; marcar isso como falha ensinaria a ignorar falhas.

O que o doctor **não** verifica: se o cron chama `schedule:run` a cada minuto (só vê que há
comandos agendados), se o Horizon está de pé, se o e-mail realmente é entregue e se o backup
existe. Esses só se confirmam no servidor.

### 4.2 Ciclo de vida do certificado A1

O certificado é da **empresa operadora**. Ele identifica a AssinaVelox como quem lacrou o
documento — **não** é assinatura pessoal ICP-Brasil de cada participante (`arquitetura.md`
§2). Um certificado de teste é sempre rotulado como teste e nunca exibido como ICP-Brasil.

**Acesso mínimo.** O `.pfx` mora fora do repositório e fora de `public/`, com dono
`assinavelox` e modo `0400`. A senha **nunca** entra em config, em `argv`, em fila ou em log:
`COMPANY_CERT_PASSWORD_ENV` guarda o **nome** da variável; o valor fica só no ambiente do
processo PHP e é injetado, sob esse mesmo nome, no ambiente do processo filho do pdftool.
Com `config:cache` o `.env` não é lido — a variável precisa estar no ambiente real do serviço
(`EnvironmentFile` do systemd, pool do PHP-FPM). Quem pode ler o arquivo e a variável são
o serviço e o responsável pela chave; ninguém mais.

**Monitoramento de validade.** O doctor lê os metadados públicos via `pdftool cert-info` e
mostra `not_before`, `not_after` e **dias restantes**, com aviso a partir de
`ASSINAVELOX_HEALTH_CERT_WARNING_DAYS` (30 por padrão) e falha quando vencido ou ainda não
vigente. Nada de chave privada sai daí.

**Renovação.** Prazo típico do A1: 12 meses.

1. 60 dias antes do vencimento, solicite a renovação junto à AC.
2. Instale o novo `.pfx` **ao lado** do antigo e aponte `COMPANY_CERT_PFX_PATH` para ele,
   com a senha nova na variável de ambiente do serviço.
3. `php artisan assinavelox:doctor` — confira `subject`, `issuer`, impressão digital e dias
   restantes. `php artisan pdftool:selftest` para o caminho de assinatura.
4. Finalize um envelope de homologação e valide o PDF resultante (`pdftool validate`).
5. Só então reinicie os workers de produção (`php artisan horizon:terminate`).

**Rotação e comprometimento.** Se a chave vazar: revogue junto à AC, troque o arquivo e a
variável, reinicie os serviços e registre o incidente. Documentos já assinados **continuam
verificáveis** — a revogação afeta a confiança futura, não os bytes já assinados —, mas o
resultado de validação passa a refletir a revogação para quem a consulta.

**Preservação do material público.** Trocar o certificado **não pode** apagar a história.
Cada assinatura já feita continua carregando a cadeia de certificados dentro do próprio PDF,
e `certificate_references` guarda `subject`, `issuer`, `serial_number`,
`fingerprint_sha256`, `not_before`, `not_after` e `environment` — **nunca o segredo**, só
`secret_ref` (o nome da variável/arquivo). Regras:

- **nunca** apague nem reescreva uma linha de `certificate_references`; um certificado
  aposentado vira `is_active = false` e permanece;
- guarde o `.cer`/`.pem` **público** de cada certificado já usado junto do acervo de
  retenção. Sem ele, a verificação histórica de um documento antigo perde a raiz de
  comparação;
- `PDFTOOL_TRUST_ROOTS` deve continuar listando as raízes das ACs de todos os certificados
  ainda relevantes, não só o corrente. Sem raízes, `validate` afirma integridade e devolve
  `trusted=false` — **revogação nunca é verificada** por este pipeline.

### 4.3 Demais segredos

| Segredo                      | Onde vive                                                   | Nunca                                                                  |
| ---------------------------- | ----------------------------------------------------------- | ---------------------------------------------------------------------- |
| `APP_KEY`                    | ambiente do serviço                                         | em log; trocá-la invalida `organizations.tax_id` e os HMAC dos códigos |
| Senha do PKCS#12             | variável de ambiente nomeada em `COMPANY_CERT_PASSWORD_ENV` | config, `argv`, fila, log                                              |
| `MERCADOPAGO_ACCESS_TOKEN`   | ambiente do serviço                                         | repositório, front-end, log                                            |
| `MERCADOPAGO_WEBHOOK_SECRET` | ambiente do serviço                                         | idem — sem ele nenhuma notificação é aceita                            |
| Token de convite (32 bytes)  | só o `sha256` no banco                                      | o token em claro não é gravado em lugar nenhum                         |
| Código por e-mail            | só o HMAC (`auth_challenges.code_hash`)                     | banco em claro, log, trilha                                            |
| Credenciais do banco/SMTP    | ambiente do serviço                                         | repositório                                                            |

---

## 5. Criptografia em repouso do armazenamento

### 5.1 O Flysystem não cifra nada

Vale repetir porque a confusão é comum: `config/filesystems.php` configura **acesso** a um
backend. Ele não tem camada de cifra. `visibility => private` é permissão, não criptografia.
O que existe é a cifra **do backend** — e ela precisa ser verificada, não presumida.

### 5.2 `storage:verify`

```bash
php artisan storage:verify              # disco `documents`
php artisan storage:verify --disk=s3
php artisan storage:verify --json
php artisan storage:verify --keep-probe # mantém o objeto de prova para inspeção manual
```

Três conferências:

1. **Gravável e íntegro.** Grava um objeto de prova, lê de volta, compara o `sha256` e apaga.
   "O `put` não lançou exceção" não é "os bytes chegaram": em bucket com permissão parcial a
   escrita passa e a leitura não.
2. **Privado.** Em disco local: raiz fora de `public/`, sem link simbólico apontando para
   ela, `visibility=private`, `serve=false`. Em S3: visibilidade padrão privada e
   `GetPublicAccessBlock` com os quatro bloqueios ligados.
3. **Cifrado em repouso.**
    - **S3:** consulta ao próprio serviço — `GetBucketEncryption` (regra padrão do bucket,
      comparada com `ASSINAVELOX_STORAGE_S3_SSE`, `AES256` ou `aws:kms`) **e**
      `HeadObject` sobre o objeto de prova, para confirmar que o objeto realmente saiu com
      `x-amz-server-side-encryption`. Um bucket com regra padrão configurada mas objeto sem o
      cabeçalho é caso real (política sobrescrita, endpoint compatível que ignora a regra) —
      por isso as duas evidências. **Se o serviço não responder, o comando falha dizendo que
      não conseguiu verificar.** Não verificado nunca vira "ok".
    - **Disco local:** não há o que consultar a partir do PHP. A cifra é do **volume**
      (LUKS/dm-crypt/BitLocker) e o processo enxerga o sistema de arquivos já aberto. O
      comando reporta a **atestação do operador**
      (`ASSINAVELOX_STORAGE_ENCRYPTION_ATTESTED=true` +
      `ASSINAVELOX_STORAGE_ENCRYPTION_NOTE="LUKS2 /dev/vg0/documents, ticket OPS-431"`),
      **rotulada como atestação e nunca como verificação** (`verified` é sempre `false` nesse
      caminho), e falha em produção quando ela não existe ou não tem referência.

O resultado vira recibo em `storage/app/private/hardening/storage-verify.json`, que o doctor
lê para dizer quando foi a última conferência.

Serviços compatíveis com S3 que não implementam `GetPublicAccessBlock` (MinIO, B2, Wasabi)
produzem um **aviso de item não verificado**, com a instrução de conferir no painel do
provedor — nunca uma aprovação.

### 5.3 Recomendado em produção

`DOCUMENTS_DISK=s3`, bucket privado, SSE-KMS com chave própria, versionamento ligado,
bloqueio de acesso público completo e política negando `s3:GetObject` a qualquer principal
fora do papel da aplicação. Sem URL pública temporária (RECONCILIACAO Q23): todo download
passa por controller autorizado.

---

## 6. Observabilidade

### 6.1 Identificador de correlação

`App\Support\Correlation` guarda um ULID no `Context` do Laravel. O middleware global
`AssignCorrelationId` abre a unidade de trabalho no início da requisição e devolve o valor em
`X-Correlation-Id`.

A escolha do `Context` (em vez de uma propriedade estática) é o que faz o identificador
atravessar a fila: o Laravel desidrata o `Context` dentro do payload do job e o re-hidrata no
worker, então a conversão que roda três minutos depois, em outra máquina, registra o **mesmo**
identificador. O `ContextLogProcessor` copia tudo para o campo `extra` de cada linha de log.

Um `X-Correlation-Id` recebido do balanceador é aproveitado, mas só depois de higienizado
(`[A-Za-z0-9_-]{8,64}`): sem filtro, o cliente injetaria quebra de linha e texto arbitrário
dentro de cada linha do log. Há teste.

### 6.2 Canal estruturado

`config/logging.php` → canal `structured`: uma linha, um objeto JSON, com `datetime`,
`level_name`, `message`, `context` e `extra` (que traz o `correlation_id`).

```bash
LOG_STACK=single,structured
LOG_STRUCTURED_STREAM=/var/log/assinavelox/app.jsonl   # ou php://stderr em systemd
```

### 6.3 Mascaramento — redige por padrão

`App\Logging\RedactSensitiveData` é instalado em **todos** os canais de arquivo por
`App\Logging\RedactionTap`. Ele é pendurado no **handler**, e não no logger, de propósito:
processadores do logger rodam antes do `ContextLogProcessor` do Laravel. No handler, o redator
é a última transformação antes da escrita e enxerga o registro completo — nada escapa por ter
sido acrescentado tarde demais.

Redige:

- **por chave de contexto**, em qualquer profundidade: `password`, `token`, `secret`,
  `signature`, `x-signature`, `authorization`, `cookie`, `code`, `otp`, `code_hash`,
  `token_digest`, `two_factor_secret`, `pfx_password`, `tax_id`, `cpf`, `cnpj`… (lista em
  `assinavelox.observability.redaction.keys`);
- **por padrão no texto**: URL de `/assinar/{token}` e `/convites/{token}`, pares
  `token=`/`code=`/`password=`, cabeçalho `Bearer`, CPF e CNPJ com ou sem pontuação, endereço
  de e-mail (`m***@***.br`) e sequências longas que misturam maiúsculas, minúsculas e dígitos
  — a forma dos tokens de 32 bytes em base64url.

**O que continua legível, de propósito:** identificador de correlação, ULID de recurso, nome
de classe, caminho de arquivo, contadores e o `document_sha256`. O hash do documento é a
evidência que o produto publica na página de verificação; apagá-lo do log destruiria o rastro
que serve para conferir um arquivo com o cliente ao telefone. Os digests que **são** material
de autenticação (`token_digest`, `code_hash`) caem pela regra de chaves.

**Exceções por nome exato** (`assinavelox.observability.redaction.allow_keys`), conferidas
antes da lista de chaves: `exit_code`, `status_code`, `http_code`, `response_code`,
`failure_code`, `error_code`, `reason_code`.

A regra de chaves casa por **sufixo** — `_code` alcança `exit_code` —, o que é o comportamento
certo para `otp_code` e o oposto do certo para um código de diagnóstico. Sem a exceção, o log
saía com `"exit_code":"[REDIGIDO]"` justamente na linha que alguém abre para descobrir por que
a conversão de um documento falhou: o campo mais útil virava o único ilegível. Isso foi
observado no log real durante o fechamento da Fase 1 e corrigido ali. Todos os nomes da lista
carregam número ou rótulo de erro, nunca segredo nem dado pessoal; qualquer acréscimo a ela
precisa passar no mesmo teste.

Limitação honesta: isto reduz vazamento **acidental**; não é controle de acesso ao log. Quem
lê o arquivo continua vendo quais organizações e envelopes estiveram ativos. Restrinja o
acesso ao arquivo e ao coletor.

### 6.4 `assinavelox:health`

```bash
php artisan assinavelox:health          # legível
php artisan assinavelox:health --json   # monitoramento
php artisan assinavelox:health --log    # escreve no canal estruturado (agendado a cada 5 min)
```

Complementa a rota `/up` do Laravel, que só diz que o processo web está de pé. Este comando
olha o que acontece **fora** da requisição:

| Indicador                | O que acusa                                                                                                           |
| ------------------------ | --------------------------------------------------------------------------------------------------------------------- |
| `queue`                  | jobs acumulados acima do limiar, falhas nas últimas 24 h (driver `database`; com Redis, use o Horizon)                |
| `conversion`             | documentos presos em `converting` além do limiar; conversões que falharam                                             |
| `signature`              | envelopes presos em `finalizing`; conclusões com e sem assinatura da operadora nas últimas 24 h                       |
| `email_delivery`         | envios parados em `queued`; falhas e retornos; recibos inconclusivos (`unknown`)                                      |
| `payment_reconciliation` | recibos de webhook parados em `received`; recibos com assinatura inválida; **pagamentos aprovados sem plano ativado** |

Um indicador que não pôde ser lido responde **`desconhecido`**, nunca `ok`: painel verde por
ignorância é pior que painel nenhum. Código de saída 1 quando há indicador degradado — o
bastante para `OnFailure=` do systemd ou um cron que avisa por e-mail.

**É comando, não rota, por decisão deliberada.** Um endereço HTTP que enumere quantos
envelopes estão travados e quantos pagamentos ficaram sem aplicar é informação de negócio, e
precisaria de autenticação, limite e auditoria próprios. O alarme mora no coletor de logs,
que já sabe fazer isso. A rota `/up` continua disponível para o balanceador.

### 6.5 Erro interno não revela nada

Em produção (`APP_DEBUG=false`) as páginas 403/404/500/503 são componentes Inertia. A mensagem
exibida só aparece quando **a aplicação a escreveu** (`abort(404, '…')`, sempre em PT-BR);
mensagens do framework (em inglês, do tipo `No query results for model [App\Models\Envelope]
01K…`) são descartadas e a página usa a cópia padrão. Há teste que lança uma exceção com
texto sensível e verifica que ele não chega ao navegador. Conteúdo de documento nunca passa
por página de erro: downloads são `StreamedResponse` autorizados.

---

## 7. Isolamento do conversor

O que já existe no código (`docs/pdf-pipeline.md`): processo executado **sem shell** (binário

- argumentos em array), diretório temporário exclusivo por operação (`0700`, removido em
  `finally`), `TEMP`/`TMP`/`TMPDIR`/`HOME` do filho apontando para dentro dele, timeout por
  comando com morte do processo, ambiente mínimo e perfil de usuário do LibreOffice isolado
  por `-env:UserInstallation`.

Isso é o que a aplicação consegue garantir. **Contenção de verdade — CPU, memória, rede,
sistema de arquivos — é do sistema operacional.** Abaixo, as unidades systemd prontas para
Linux. Em Windows (ambiente de desenvolvimento) não há equivalente: não converta DOCX de
origem desconhecida em máquina de desenvolvimento.

### 7.1 Usuário dedicado e diretórios

```bash
sudo useradd --system --no-create-home --shell /usr/sbin/nologin assinavelox-convert
sudo install -d -o assinavelox-convert -g assinavelox-convert -m 0700 /var/lib/assinavelox/convert
sudo install -d -o assinavelox-convert -g assinavelox-convert -m 0700 /var/tmp/assinavelox-convert
```

O usuário do conversor **não** é o usuário do PHP-FPM e **não** tem acesso de leitura ao
`.pfx`, ao `.env` nem ao disco de documentos: o worker copia o arquivo de entrada para o
diretório temporário e lê o resultado de lá.

### 7.2 Bloqueio de macros no LibreOffice

Macro em DOCX é execução de código no seu servidor. Desligue no perfil da instalação:

```bash
sudo install -d -m 0755 /etc/libreoffice
sudo tee /etc/libreoffice/sofficerc >/dev/null <<'EOF'
[Bootstrap]
Logo=0
NativeProgress=false
EOF
```

E, no registro de configuração (`/etc/libreoffice/registry/`), fixe o nível de segurança de
macros em "muito alto" e desabilite a atualização automática de vínculos:

```xml
<!-- /etc/libreoffice/registry/assinavelox-hardening.xcd -->
<oor:data xmlns:oor="http://openoffice.org/2001/registry">
  <oor:component-data oor:name="Common" oor:package="org.openoffice.Office">
    <node oor:name="Security">
      <node oor:name="Scripting">
        <prop oor:name="MacroSecurityLevel" oor:type="xs:int" oor:finalized="true">
          <value>3</value> <!-- 3 = muito alto: nenhuma macro roda -->
        </prop>
        <prop oor:name="DisableMacrosExecution" oor:type="xs:boolean" oor:finalized="true">
          <value>true</value>
        </prop>
      </node>
    </node>
  </oor:component-data>
  <oor:component-data oor:name="Writer" oor:package="org.openoffice.Office">
    <node oor:name="Content">
      <node oor:name="Update">
        <prop oor:name="Link" oor:type="xs:int" oor:finalized="true">
          <value>2</value> <!-- nunca atualizar vínculos externos -->
        </prop>
      </node>
    </node>
  </oor:component-data>
</oor:data>
```

`oor:finalized="true"` impede que o perfil do usuário sobrescreva o valor.

### 7.3 Fatia de recursos comum

```ini
# /etc/systemd/system/assinavelox-convert.slice
[Unit]
Description=Fatia de recursos das conversões documentais do AssinaVelox

[Slice]
CPUAccounting=yes
MemoryAccounting=yes
TasksAccounting=yes
CPUQuota=150%
MemoryMax=2G
MemorySwapMax=0
TasksMax=256
```

### 7.4 Serviço do worker de conversão

O worker do Laravel é quem invoca LibreOffice e pdftool, então é nele que as restrições
precisam valer — elas são herdadas pelos processos filhos.

```ini
# /etc/systemd/system/assinavelox-worker-conversions.service
[Unit]
Description=AssinaVelox — worker da fila de conversões (LibreOffice e pdftool)
After=network.target mysql.service
PartOf=assinavelox.target

[Service]
Type=simple
User=assinavelox-convert
Group=assinavelox-convert
Slice=assinavelox-convert.slice
WorkingDirectory=/var/www/assinavelox
EnvironmentFile=/etc/assinavelox/worker.env
ExecStart=/usr/bin/php artisan queue:work --queue=conversions --sleep=1 --tries=3 --max-time=3600 --memory=512
Restart=always
RestartSec=5
TimeoutStopSec=60

# --- Sistema de arquivos: leitura apenas, com duas gravações declaradas ---
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=/var/lib/assinavelox/convert /var/www/assinavelox/storage
PrivateTmp=yes
TemporaryFileSystem=/var/tmp:size=512M,mode=0700

# --- Rede: NENHUMA. Um DOCX não tem por que chamar a internet ---
# Fecha exfiltração por vínculo remoto, XXE e SSRF a partir do documento convertido.
PrivateNetwork=yes
IPAddressDeny=any
RestrictAddressFamilies=AF_UNIX

# --- Privilégios ---
NoNewPrivileges=yes
PrivateDevices=yes
PrivateUsers=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
ProtectClock=yes
ProtectHostname=yes
ProtectProc=invisible
RestrictNamespaces=yes
RestrictRealtime=yes
RestrictSUIDSGID=yes
LockPersonality=yes
MemoryDenyWriteExecute=no
SystemCallArchitectures=native
SystemCallFilter=@system-service
SystemCallFilter=~@privileged @resources @obsolete @mount @debug @swap @reboot

# --- Recursos deste serviço ---
CPUQuota=100%
MemoryMax=1536M
TasksMax=128
LimitNOFILE=4096
LimitCORE=0

[Install]
WantedBy=multi-user.target
```

**`PrivateNetwork=yes` é o ponto mais importante desta unidade.** Com o banco em outra
máquina, um worker sem rede não funciona. Duas saídas honestas:

- rodar o **worker** com rede (para MySQL/Redis) e isolar apenas a **conversão**, invocando
  o LibreOffice por `systemd-run` (§7.5); **ou**
- manter `PrivateNetwork=yes` e usar socket UNIX para o MySQL local
  (`DB_SOCKET=/run/mysqld/mysqld.sock`, permitido por `RestrictAddressFamilies=AF_UNIX`),
  acrescentando `BindPaths=/run/mysqld`.

Escolha uma e registre a escolha; herdar as duas dá um serviço que não sobe.

### 7.5 Alternativa: isolar só o processo de conversão

Quando o worker precisa de rede, ponha as restrições no filho. Configure
`LIBREOFFICE_BIN=/usr/local/bin/soffice-jailed`, com:

```bash
#!/bin/sh
# /usr/local/bin/soffice-jailed — LibreOffice em unidade transitória sem rede.
exec systemd-run --quiet --pipe --wait --collect \
  --unit="soffice-$$" \
  --slice=assinavelox-convert.slice \
  --property=User=assinavelox-convert \
  --property=PrivateNetwork=yes \
  --property=IPAddressDeny=any \
  --property=PrivateTmp=yes \
  --property=ProtectSystem=strict \
  --property=ProtectHome=yes \
  --property=ReadWritePaths="$PDFTOOL_TMP_PATH" \
  --property=NoNewPrivileges=yes \
  --property=MemoryMax=1G \
  --property=CPUQuota=100% \
  --property=TasksMax=64 \
  --property=RuntimeMaxSec=180 \
  /usr/bin/soffice "$@"
```

`RuntimeMaxSec` é o cinto de segurança do timeout da aplicação: se o PHP morrer antes de
matar o filho, o systemd mata.

### 7.6 Serviço do worker de finalização

Igual ao de conversões, mas **precisa** ler o `.pfx` e a variável da senha, e é o único que
precisa. Diferenças:

```ini
# /etc/systemd/system/assinavelox-worker-finalization.service
# (mesmo bloco de endurecimento da §7.4, com estas trocas)
User=assinavelox
Group=assinavelox
ExecStart=/usr/bin/php artisan queue:work --queue=finalization --tries=3 --max-time=3600
EnvironmentFile=/etc/assinavelox/finalization.env   # contém COMPANY_CERT_PASSWORD, modo 0400
ReadOnlyPaths=/etc/assinavelox/certs
ReadWritePaths=/var/www/assinavelox/storage
# A assinatura é local (pyHanko, sem carimbo de tempo externo na Fase 1):
PrivateNetwork=yes
```

`/etc/assinavelox/finalization.env` com dono `root:assinavelox` e modo `0640`; o `.pfx` em
`/etc/assinavelox/certs/`, modo `0400`, dono `assinavelox`.

### 7.7 Conferência

```bash
systemd-analyze security assinavelox-worker-conversions.service
sudo -u assinavelox-convert curl -sS https://example.com   # deve falhar: sem rede
sudo -u assinavelox-convert cat /etc/assinavelox/certs/*.pfx  # deve falhar: sem permissão
php artisan pdftool:selftest
```

---

## 8. Testes

`tests/Feature/Hardening/` (`php artisan test --filter=Hardening`):

| Arquivo                           | Cobre                                                                                                                                                                                                                                                                                                                                                       |
| --------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SecurityHeadersCoverageTest.php` | linha de base em rota pública, autenticada, de erro e no JSON do webhook; `no-referrer`+`noindex` em `/assinar` e `/verificar`; CSP sem `unsafe-inline` em `script-src`; `worker-src blob:` (PDF.js); nonce novo a cada resposta e igual ao do Inertia; 500 fora de debug não revela a exceção; correlação ecoada e sanitizada                              |
| `RateLimitsTest.php`              | o mapa aponta para rotas e limitadores que existem; 429 em cadastro, recuperação, redefinição, convite, download, entrada e verificação pública; **a vítima de outro IP continua conseguindo pedir o próprio link**                                                                                                                                         |
| `LogRedactionTest.php`            | token, código, senha, e-mail e CPF/CNPJ redigidos em mensagem e contexto, em qualquer profundidade; ULID, classe, caminho e `document_sha256` preservados; **códigos de diagnóstico (`exit_code`, `failure_code`, …) preservados enquanto `otp_code` e `code` continuam redigidos**; tap instalado nos canais; linha JSON real sem segredo e com correlação |
| `AppendOnlyEvidenceTest.php`      | do convite ao aceite sem `UPDATE`/`DELETE` proibido; o detector acusa quando o comando proibido aparece; model recusa alteração; nenhuma tabela de evidência tem `updated_at`                                                                                                                                                                               |
| `AuditCheckpointTest.php`         | lote exportado e encadeado; reexportação acusa evento reescrito; `--verify` acusa arquivo alterado e checkpoint removido; lote vazio não é gravado sem `--allow-empty`; período invertido é recusado; a saída avisa que o elo precisa sair do sistema                                                                                                       |
| `OperationalCommandsTest.php`     | doctor não imprime segredo (nem em `--json`), falha no que é essencial, não confunde "desligado" com "quebrado"; `storage:verify` grava/lê/apaga a prova, nunca afirma cifra em disco local, reprova disco não privado e deixa recibo; health degrada e devolve `desconhecido` quando não consegue ler; comandos agendados                                  |

Fora dessa pasta, relacionados: `tests/Feature/Security/SecurityHeadersTest.php`,
`tests/Feature/Review/TrustedProxyIpSpoofingTest.php`,
`tests/Feature/Review/HostHeaderInjectionTest.php`,
`tests/Feature/Review/SignerOtpLockoutTest.php`,
`tests/Feature/Review/SignerPublicRateLimitTest.php`.

---

## 9. O que é garantido e o que não é

### Garantido (verificável por comando ou teste)

- Cabeçalhos de segurança e CSP com nonce em **toda** resposta, erros e downloads inclusive.
- `script-src` sem `unsafe-inline`; enquadramento bloqueado; sem `Referer` nas páginas que
  carregam o token do convite.
- Limite de requisição em toda rota sensível, com chave composta que não permite trancar a
  vítima.
- A aplicação não emite `UPDATE`/`DELETE` nas tabelas de evidência (exceto o `UPDATE`
  documentado em `verification_records`).
- Checkpoint encadeado que torna **detectável** a alteração da trilha.
- Nenhum comando operacional imprime segredo.
- Em S3, a criptografia do lado do servidor é **consultada ao backend**; o que não pôde ser
  consultado é relatado como não verificado.
- Token de convite e código por e-mail não existem em claro no banco nem no log.
- Erro interno não revela mensagem do framework, conteúdo de documento nem segredo.

### **Não** garantido

- **Imutabilidade da trilha.** Nenhum mecanismo aqui impede um administrador do banco. O
  encadeamento só vira prova quando o último elo é guardado **fora** deste sistema.
- **Criptografia em repouso em disco local.** Não é verificável pelo PHP; é atestação do
  operador sobre a cifra do volume.
- **Entrega de e-mail.** `sent` não é `delivered`. Sem confirmação do provedor, o recibo é
  `unknown`.
- **Validade jurídica universal.** A Fase 1 produz _aceite eletrônico com evidências_ e,
  quando há certificado, _assinatura criptográfica da empresa operadora_ (PAdES B-B alvo).
  Nada disso é assinatura pessoal ICP-Brasil de cada participante.
- **Revogação de certificado.** `pdftool validate` nunca consulta revogação. Sem raízes de
  confiança configuradas, devolve `trusted=false`.
- **Carimbo de tempo qualificado.** Não há. Um carimbo público não é ICP-Brasil e não pode
  ser apresentado como tal.
- **Cron e Horizon de pé.** O doctor vê que há comandos agendados; que o cron os chame só se
  confirma no servidor.
- **Contenção do conversor.** Depende das unidades systemd da §7 estarem instaladas. O
  código isola processo, diretório e timeout; CPU, memória e rede são do sistema operacional.
- **Backup e retenção.** Fora do escopo desta fase; combine com a política de retenção do
  cliente antes de prometer prazo de guarda.

---

## Apêndice A — variáveis introduzidas aqui

Complementam `docs/configuracao.md` §2. Nenhuma delas guarda segredo.

| Variável                                        | Padrão                           | Efeito                                                           |
| ----------------------------------------------- | -------------------------------- | ---------------------------------------------------------------- |
| `ASSINAVELOX_PERMISSIONS_POLICY`                | lista da §1.2                    | `Permissions-Policy`; vazio desliga o cabeçalho                  |
| `ASSINAVELOX_COOP`                              | `same-origin-allow-popups`       | `Cross-Origin-Opener-Policy`; vazio desliga                      |
| `ASSINAVELOX_CORP`                              | `same-origin`                    | `Cross-Origin-Resource-Policy`; vazio desliga                    |
| `ASSINAVELOX_CORRELATION_HEADER`                | `true`                           | devolve `X-Correlation-Id` na resposta                           |
| `ASSINAVELOX_LOG_REDACTION`                     | `true`                           | mascaramento no log; desligar só para depurar, nunca em produção |
| `LOG_STRUCTURED_STREAM`                         | `storage/logs/assinavelox.jsonl` | destino do canal `structured` (aceita `php://stderr`)            |
| `LOG_STRUCTURED_STACKTRACES`                    | `false`                          | inclui pilha de exceção nas linhas JSON                          |
| `ASSINAVELOX_AUDIT_CHECKPOINT_DISK`             | `documents`                      | disco do lote exportado                                          |
| `ASSINAVELOX_AUDIT_CHECKPOINT_PATH`             | `audit-checkpoints`              | prefixo dentro do disco                                          |
| `ASSINAVELOX_AUDIT_CHECKPOINT_CHUNK`            | `1000`                           | eventos lidos por página                                         |
| `ASSINAVELOX_STORAGE_ENCRYPTION_ATTESTED`       | `false`                          | atestação do operador para disco local (§5.2)                    |
| `ASSINAVELOX_STORAGE_ENCRYPTION_NOTE`           | vazio                            | referência da atestação (obrigatória quando atestado)            |
| `ASSINAVELOX_STORAGE_S3_SSE`                    | `AES256`                         | algoritmo SSE esperado (`AES256` ou `aws:kms`)                   |
| `ASSINAVELOX_HEALTH_QUEUE_BACKLOG`              | `100`                            | limiar de acúmulo na fila                                        |
| `ASSINAVELOX_HEALTH_FAILED_WINDOW_HOURS`        | `24`                             | janela de falhas de job consideradas                             |
| `ASSINAVELOX_HEALTH_CONVERSION_STUCK_MINUTES`   | `30`                             | documento preso em `converting`                                  |
| `ASSINAVELOX_HEALTH_FINALIZATION_STUCK_MINUTES` | `30`                             | envelope preso em `finalizing`                                   |
| `ASSINAVELOX_HEALTH_DELIVERY_STUCK_MINUTES`     | `60`                             | entrega parada em `queued`                                       |
| `ASSINAVELOX_HEALTH_WEBHOOK_STUCK_MINUTES`      | `30`                             | recibo de webhook parado em `received`                           |
| `ASSINAVELOX_HEALTH_CERT_WARNING_DAYS`          | `30`                             | antecedência do aviso de vencimento do A1                        |

## Apêndice B — rotina de operação

| Quando                             | Comando                                                                          |
| ---------------------------------- | -------------------------------------------------------------------------------- |
| A cada deploy                      | `php artisan assinavelox:doctor --strict` e `php artisan storage:verify`         |
| A cada 5 min (agendado)            | `php artisan assinavelox:health --log`                                           |
| Semanal (agendado, segundas 02:40) | `php artisan audit:checkpoint`                                                   |
| Semanal (**humano**)               | copiar o `chain_sha256` do último lote para fora do sistema (§3.5)               |
| Mensal                             | `php artisan audit:checkpoint --verify` e conferência do elo com a cópia externa |
| Mensal                             | `php artisan pdftool:selftest` e `systemd-analyze security` nos workers          |
| 60 dias antes do vencimento do A1  | renovação (§4.2)                                                                 |
