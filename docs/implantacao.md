# AssinaVelox — Implantação em servidor Linux (sem Docker)

> Procedimento do zero, reproduzível, para um servidor Ubuntu 22.04/24.04 LTS (ou Debian 12).
> Nenhum comando aqui foi executado em um servidor real: **o projeto nunca foi implantado**.
> Isto é o procedimento derivado do código e da configuração que existem hoje — trate a
> primeira implantação como uma verificação, não como uma repetição.
>
> Fontes: `config/*.php`, `bootstrap/app.php`, `routes/console.php`, `docs/configuracao.md`,
> `docs/pdf-pipeline.md`, `docs/banco-de-dados.md`, `docs/cobranca.md`.

## Sumário

1. [Topologia e decisões](#1-topologia-e-decisões)
2. [Pacotes do sistema](#2-pacotes-do-sistema)
3. [Usuários, diretórios e permissões](#3-usuários-diretórios-e-permissões)
4. [MySQL](#4-mysql)
5. [Redis](#5-redis)
6. [pdftool (Python)](#6-pdftool-python)
7. [LibreOffice](#7-libreoffice)
8. [PHP-FPM](#8-php-fpm)
9. [nginx e TLS](#9-nginx-e-tls)
10. [Código, `.env` e build](#10-código-env-e-build)
11. [systemd: Horizon e agendador](#11-systemd-horizon-e-agendador)
12. [Rotação de logs](#12-rotação-de-logs)
13. [Segredos](#13-segredos)
14. [Backups e restauração](#14-backups-e-restauração)
15. [Atualização (deploy) e retorno seguro](#15-atualização-deploy-e-retorno-seguro)
16. [Checklist de primeira implantação](#16-checklist-de-primeira-implantação)

---

## 1. Topologia e decisões

Um único servidor cobre a Fase 1. Tudo roda como serviços do sistema:

```
                    ┌─────────────────────────────────────────┐
   Internet ──443──▶│ nginx  (TLS, arquivos estáticos, proxy) │
                    └──────────────┬──────────────────────────┘
                                   │ fastcgi
                    ┌──────────────▼──────────────┐
                    │ php-fpm  (pool assinavelox) │
                    └──────────────┬──────────────┘
                                   │
        ┌──────────────┬───────────┴───────────┬──────────────────┐
        ▼              ▼                       ▼                  ▼
   ┌─────────┐   ┌───────────┐   ┌───────────────────────┐  ┌───────────┐
   │  MySQL  │   │   Redis   │   │ horizon.service       │  │ storage/  │
   │  8.x    │   │ (fila +   │   │ (workers das filas)   │  │ documents │
   └─────────┘   │  cache)   │   │ schedule.timer        │  └───────────┘
                 └───────────┘   └───────────────────────┘
                                            │ subprocessos
                                            ▼
                              tools/pdftool/.venv/bin/python
                                     /usr/bin/soffice
```

Decisões que valem para o resto do documento:

- **Sem Docker.** Serviços do sistema, `systemd`, `nginx`, `php-fpm`.
- **Dois usuários UNIX.** `assinavelox` é dono do código (não escreve nele em execução) e
  `assinavelox-worker` roda as filas. Os subprocessos de PDF e LibreOffice herdam o usuário do
  worker, que é o de menor privilégio (`docs/pdf-pipeline.md` §4).
- **Redis é obrigatório em produção.** `QUEUE_CONNECTION=redis` + Horizon, `CACHE_STORE=redis`.
  Sessões continuam em banco (`SESSION_DRIVER=database`) para sobreviverem a um reinício do Redis.
- **Sem URL pública para documentos.** O disco `documents` é privado (`serve=false` no driver
  local, `visibility=private` no S3) e todo download passa por um controller autorizado.

---

## 2. Pacotes do sistema

```bash
sudo apt-get update
sudo apt-get install -y software-properties-common ca-certificates curl gnupg lsb-release

# PHP 8.3 (Ubuntu 24.04 já traz; em 22.04 use o PPA ondrej/php)
sudo add-apt-repository -y ppa:ondrej/php && sudo apt-get update    # só no 22.04
sudo apt-get install -y \
  php8.3-fpm php8.3-cli \
  php8.3-mysql php8.3-redis \
  php8.3-intl php8.3-gd php8.3-zip php8.3-mbstring php8.3-curl php8.3-xml php8.3-bcmath \
  php8.3-opcache
```

`fileinfo`, `openssl`, `tokenizer`, `ctype`, `json`, `pcntl` e `posix` vêm compilados no
`php8.3-cli`/`php8.3-fpm` do Ubuntu. Confirme **todas** de uma vez:

```bash
php -m | tr 'A-Z' 'a-z' | sort > /tmp/mods
for m in intl gd zip fileinfo openssl mbstring curl dom simplexml pdo_mysql tokenizer ctype json bcmath pcntl posix redis; do
  grep -qx "$m" /tmp/mods || echo "FALTANDO: $m"
done
```

`pcntl`/`posix` são exigidas pelo Horizon; `intl` e `gd` são exigidas pela aplicação (moeda em
pt-BR, normalização das imagens de assinatura); `zip` e `fileinfo` pela validação de upload.

```bash
# Servidores e utilitários
sudo apt-get install -y nginx mysql-server redis-server
sudo apt-get install -y python3 python3-venv python3-pip
sudo apt-get install -y git unzip logrotate rsync

# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Node 22 LTS (NodeSource)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt-get install -y nodejs
node --version    # v22.18 ou mais novo (exigência do vite-plus)

# TLS
sudo apt-get install -y certbot python3-certbot-nginx
```

> **Node só é necessário para o build.** Se você compila os assets em outra máquina (CI) e envia
> `public/build` pronto, o servidor não precisa de Node.

---

## 3. Usuários, diretórios e permissões

```bash
sudo adduser --system --group --home /var/www/assinavelox --shell /usr/sbin/nologin assinavelox
sudo adduser --system --ingroup assinavelox --no-create-home --shell /usr/sbin/nologin assinavelox-worker

sudo mkdir -p /var/www/assinavelox
sudo chown assinavelox:assinavelox /var/www/assinavelox
```

Depois de o código estar no lugar (§10), aplique as permissões:

```bash
cd /var/www/assinavelox

# Código: dono lê, ninguém escreve em execução
sudo chown -R assinavelox:assinavelox .
sudo find . -type d -exec chmod 750 {} \;
sudo find . -type f -exec chmod 640 {} \;
sudo chmod 750 artisan

# Escrita: só storage/ e bootstrap/cache/ — e o grupo (worker + php-fpm) também escreve
sudo chmod -R 770 storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;   # novos arquivos herdam o grupo

# Documentos e temporários do pdftool
sudo -u assinavelox mkdir -p storage/app/documents storage/app/tmp/pdftool storage/app/private/certs
sudo chmod 770 storage/app/documents storage/app/tmp/pdftool
sudo chmod 700 storage/app/private/certs

# nginx precisa ler public/
sudo chmod 755 /var/www/assinavelox /var/www/assinavelox/public
sudo find public -type d -exec chmod 755 {} \;
sudo find public -type f -exec chmod 644 {} \;
```

O `.env` é o arquivo mais sensível do servidor:

```bash
sudo chown assinavelox:assinavelox .env
sudo chmod 600 .env
```

`assinavelox-worker` **não** deve conseguir ler o `.env`. Ele recebe o que precisa pelo
`EnvironmentFile` da unit systemd (§11 e §13). Se o worker precisar do `.env` porque você não
rodou `config:cache`, você tem um problema de configuração, não de permissão — rode
`php artisan config:cache`.

---

## 4. MySQL

```bash
sudo mysql_secure_installation
```

Banco, com conjunto de caracteres explícito:

```sql
CREATE DATABASE assinavelox
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

**Dois usuários, com privilégios diferentes** (`docs/banco-de-dados.md` §4.3). O usuário de
runtime não pode alterar nem apagar linhas de `audit_events`, que é _append-only_; o usuário de
migrations é o único com DDL e só é usado no deploy.

```sql
-- 1) Usuário de MIGRATIONS (DDL). Usado apenas por `php artisan migrate`, no deploy.
CREATE USER 'assinavelox_ddl'@'localhost' IDENTIFIED BY '<senha-forte-1>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES
  ON assinavelox.* TO 'assinavelox_ddl'@'localhost';

-- 2) Usuário de RUNTIME (a aplicação). Sem DDL.
CREATE USER 'assinavelox_app'@'localhost' IDENTIFIED BY '<senha-forte-2>';
GRANT SELECT, INSERT, UPDATE, DELETE ON assinavelox.* TO 'assinavelox_app'@'localhost';

-- audit_events é append-only: retire UPDATE e DELETE dessa tabela.
-- O MySQL não faz REVOKE parcial de um GRANT em nível de banco; o grant por tabela
-- é mais específico e prevalece na avaliação.
REVOKE ALL PRIVILEGES ON assinavelox.audit_events FROM 'assinavelox_app'@'localhost';
GRANT SELECT, INSERT ON assinavelox.audit_events TO 'assinavelox_app'@'localhost';

FLUSH PRIVILEGES;
```

Confira que a restrição pegou (deve falhar):

```sql
-- conectado como assinavelox_app
DELETE FROM assinavelox.audit_events LIMIT 1;   -- espera-se ERROR 1142
```

> A aplicação já bloqueia isso no model (`AuditEvent` lança `LogicException` em `updating`/`deleting`).
> O privilégio no banco é a segunda camada, a que sobrevive a um bug no código.

Ajustes de `/etc/mysql/mysql.conf.d/assinavelox.cnf`:

```ini
[mysqld]
character-set-server        = utf8mb4
collation-server            = utf8mb4_unicode_ci
default-time-zone           = '+00:00'      ; a aplicação grava tudo em UTC
max_allowed_packet          = 64M           ; payloads de fila e JSON grandes
innodb_file_per_table       = ON            ; permite recuperar/otimizar tabela a tabela
sql_mode                    = STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION
; Ajuste ao servidor: regra de bolso, 50–70% da RAM em máquina dedicada ao banco.
innodb_buffer_pool_size     = 1G
; Durabilidade: 1 = cada commit vai para o disco. NÃO reduza em um sistema de evidências.
innodb_flush_log_at_trx_commit = 1
; Binlog: exigido para PITR (§14) e para replicação futura.
log_bin                     = /var/log/mysql/mysql-bin.log
binlog_expire_logs_seconds  = 1209600       ; 14 dias
binlog_format               = ROW
```

```bash
sudo systemctl restart mysql
```

**Fusos.** A aplicação grava tudo em UTC (`config/app.php`) e exibe no fuso da organização.
`default-time-zone = '+00:00'` mantém o banco coerente com isso; não mude o servidor para
`America/Sao_Paulo` — a conversão é responsabilidade da aplicação.

---

## 5. Redis

Redis é usado para **fila (Horizon)** e **cache**. Não guarda nada que precise sobreviver a uma
perda total, mas perder a fila significa perder jobs enfileirados: habilite a persistência.

`/etc/redis/redis.conf`:

```conf
bind 127.0.0.1 -::1
port 6379
requirepass <senha-forte-3>
maxmemory 512mb
# noeviction: a fila NUNCA pode ser descartada por pressão de memória.
# Se faltar memória, o Redis recusa escritas (erro visível) em vez de perder jobs em silêncio.
maxmemory-policy noeviction
appendonly yes
appendfsync everysec
```

```bash
sudo systemctl enable --now redis-server
redis-cli -a '<senha-forte-3>' ping    # PONG
```

No `.env`: `REDIS_HOST=127.0.0.1`, `REDIS_PASSWORD=<senha-forte-3>`, `REDIS_PORT=6379`.

> Cache e fila compartilham a instância mas usam bancos diferentes (`REDIS_DB=0`,
> `REDIS_CACHE_DB=1`), então `php artisan cache:clear` não toca na fila.

---

## 6. pdftool (Python)

Sem o pdftool **nada** funciona: composição do documento final, imagem→PDF, assinatura e
validação passam todos por ele.

```bash
cd /var/www/assinavelox
sudo -u assinavelox python3 -m venv tools/pdftool/.venv
sudo -u assinavelox tools/pdftool/.venv/bin/python -m pip install --upgrade pip
sudo -u assinavelox tools/pdftool/.venv/bin/python -m pip install -r tools/pdftool/requirements.lock.txt
```

Use **sempre** `requirements.lock.txt` (congelamento completo, com as versões transitivas), nunca
`requirements.txt`, que não fixa Pillow nem pytest. O `.venv` está no `.gitignore` e precisa ser
recriado em máquina nova e sempre que o lock mudar.

Verificação:

```bash
sudo -u assinavelox-worker tools/pdftool/.venv/bin/python -m pdftool selftest   # a partir de tools/pdftool
sudo -u assinavelox php artisan pdftool:selftest
```

O usuário do worker precisa de **leitura + execução** em `tools/pdftool` e **escrita** em
`storage/app/tmp`. Rode o autoteste **como o usuário do worker**, não como root: é ele quem vai
executar em produção.

> `python3 --version` deve ser 3.13. O lock foi fixado e verificado nessa série; versões
> anteriores não foram testadas e o `pip install` pode resolver para rodas incompatíveis.

---

## 7. LibreOffice

Só é necessário se você quer aceitar **DOCX**. Sem ele a aplicação continua funcionando com PDF
e imagens; o conversor responde `isConfigured() = false` e o upload de DOCX é recusado com
mensagem clara.

```bash
sudo apt-get install -y --no-install-recommends \
  libreoffice-writer libreoffice-core fonts-dejavu fonts-liberation
which soffice     # /usr/bin/soffice
```

```dotenv
LIBREOFFICE_BIN=/usr/bin/soffice
LIBREOFFICE_TIMEOUT_SECONDS=120
```

Cada conversão roda com **perfil de usuário isolado** (`-env:UserInstallation=` em diretório
temporário exclusivo), com macros desligadas (`MacroSecurityLevel=3`,
`DisableMacrosExecution=true`) e proxy manual sem host. Isso é gerado pelo próprio adaptador
(`LibreOfficeConverter::profileRegistry()`) — você não precisa criar perfil nenhum.

**O perfil isolado não substitui controles do sistema operacional.** Para poder afirmar que uma
conversão não acessa a rede, a unit do worker de conversões precisa de `PrivateNetwork=yes`
(§11) — o que exige separar a fila `conversions` das filas que usam rede (e-mail, S3, Mercado
Pago). Em servidores sem X11, defina `SAL_USE_VCLPLUGIN=svp` em `config/pdftool.php` →
`libreoffice.env` (não há variável de `.env` para isso, por segurança).

Teste real, depois do deploy — este caminho **nunca foi exercitado com o `soffice` de verdade**
(os testes usam um binário falso):

```bash
sudo -u assinavelox-worker php artisan pdftool:selftest
# e um upload de DOCX pela interface, conferindo o PDF gerado
```

---

## 8. PHP-FPM

O limite de upload precisa ser **coerente com a aplicação**: `ASSINAVELOX_MAX_UPLOAD_MB` (padrão 25) é o limite validado no `FormRequest`. PHP e nginx devem aceitar **um pouco mais** que isso —
caso contrário o upload morre no servidor web e o usuário vê um erro genérico em vez da mensagem
da aplicação ("O arquivo excede o limite de 25 MB.").

`/etc/php/8.3/fpm/conf.d/99-assinavelox.ini`:

```ini
; Upload: aplicação valida em 25 MB (ASSINAVELOX_MAX_UPLOAD_MB).
; Deixe folga para o overhead do multipart.
upload_max_filesize = 32M
post_max_size       = 40M
max_file_uploads    = 5

memory_limit        = 256M
; Uploads grandes em conexões lentas + conversão síncrona de nada: 120 s basta para o request.
max_execution_time  = 120
max_input_time      = 120

; Nunca exibir erro ao usuário; o log vai para o journal/arquivo.
display_errors      = Off
log_errors          = On
expose_php          = Off

; OPcache
opcache.enable                  = 1
opcache.memory_consumption      = 192
opcache.max_accelerated_files   = 20000
opcache.validate_timestamps     = 0   ; produção: exige reload do FPM a cada deploy
opcache.interned_strings_buffer = 16

date.timezone = UTC
```

> `opcache.validate_timestamps=0` é o que torna o deploy rápido — e o que obriga a
> `systemctl reload php8.3-fpm` no deploy (§15). Se esquecer, o servidor continua servindo o
> código antigo.

Pool dedicado, `/etc/php/8.3/fpm/pool.d/assinavelox.conf`:

```ini
[assinavelox]
user  = assinavelox
group = assinavelox
listen = /run/php/php8.3-fpm-assinavelox.sock
listen.owner = www-data
listen.group = www-data
listen.mode  = 0660

pm = dynamic
pm.max_children      = 20
pm.start_servers     = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests      = 500

; Variáveis de ambiente do pool. Com `config:cache` o Laravel NÃO lê o .env,
; então segredos que precisam existir em tempo de execução entram aqui.
; O arquivo deve ser 640 root:www-data. Ver §13.
; env[COMPANY_CERT_PASSWORD] = ...   <-- NÃO coloque o valor aqui se puder evitar;
;                                        prefira o EnvironmentFile do systemd no worker.
php_admin_value[error_log] = /var/log/php8.3-fpm-assinavelox.log
php_admin_flag[log_errors] = on
```

Desative o pool `www` padrão se nada mais o usa:

```bash
sudo mv /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/www.conf.disabled
sudo systemctl restart php8.3-fpm
```

**A assinatura acontece na fila, não no request.** O certificado A1 e sua senha só precisam
existir no ambiente do **worker**. Manter `COMPANY_CERT_PASSWORD` fora do pool do PHP-FPM reduz a
superfície: um problema no processo web não expõe a senha do certificado.

---

## 9. nginx e TLS

Certificado primeiro:

```bash
sudo certbot certonly --nginx -d app.assinavelox.com.br
```

`/etc/nginx/sites-available/assinavelox`:

```nginx
# .mjs precisa ser servido como JavaScript: o worker do PDF.js é um *module worker*
# e o navegador recusa o arquivo se o Content-Type estiver errado (docs/frontend.md).
types {
    text/javascript  mjs;
}

# --- HTTP: só redireciona (e deixa o ACME passar) ---
server {
    listen 80;
    listen [::]:80;
    server_name app.assinavelox.com.br;

    location /.well-known/acme-challenge/ { root /var/www/html; }
    location / { return 301 https://$host$request_uri; }
}

# --- HTTPS ---
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name app.assinavelox.com.br;

    root /var/www/assinavelox/public;
    index index.php;
    charset utf-8;

    ssl_certificate     /etc/letsencrypt/live/app.assinavelox.com.br/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.assinavelox.com.br/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;
    ssl_stapling on;
    ssl_stapling_verify on;

    # --- Upload ---
    # A aplicação valida 25 MB (ASSINAVELOX_MAX_UPLOAD_MB). Aceite um pouco mais aqui para
    # que o erro venha da aplicação, com mensagem em PT-BR, e não um 413 cru do nginx.
    client_max_body_size 32m;
    client_body_timeout  300s;   # conexão lenta enviando 25 MB
    client_body_buffer_size 256k;

    # --- Tempo limite conversando com o PHP ---
    # Requests longos: upload grande e download de PDF consolidado.
    fastcgi_read_timeout 300s;
    fastcgi_send_timeout 300s;

    # --- Cabeçalhos ---
    # A CSP e o HSTS são emitidos pela própria aplicação (middleware SecurityHeaders),
    # com nonce por resposta. NÃO duplique Content-Security-Policy aqui: dois cabeçalhos
    # se somam de forma restritiva e você vai quebrar a página sem entender por quê.
    add_header X-Content-Type-Options   "nosniff"        always;
    add_header Referrer-Policy          "same-origin"    always;
    add_header X-Frame-Options          "DENY"           always;
    add_header Permissions-Policy       "camera=(), microphone=(), geolocation=(), payment=()" always;
    server_tokens off;

    # --- Assets versionados do Vite: nome com hash, cache longo ---
    location ^~ /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
        try_files $uri =404;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    # Health check do Laravel (bootstrap/app.php: health: '/up')
    location = /up {
        access_log off;
        try_files $uri /index.php?$query_string;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.3-fpm-assinavelox.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT   $realpath_root;
        fastcgi_param HTTPS on;
        fastcgi_hide_header X-Powered-By;
        internal;
    }

    # Nenhum outro .php é executável, e nada oculto é servido.
    location ~ \.php$      { return 404; }
    location ~ /\.(?!well-known).* { deny all; }

    error_page 404 /index.php;
    access_log /var/log/nginx/assinavelox-access.log;
    error_log  /var/log/nginx/assinavelox-error.log;
}
```

```bash
sudo ln -s /etc/nginx/sites-available/assinavelox /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

**Se houver balanceador ou CDN na frente**, preencha `TRUSTED_PROXIES` com a **lista de IPs/CIDRs**
do balanceador. Nunca use `*`: isso faria o `X-Forwarded-For` ser escrito pelo próprio cliente, o
que forjaria o IP registrado nos aceites e zeraria os limitadores por IP. Por segurança a
aplicação **ignora** o `X-Forwarded-For` quando `TRUSTED_PROXIES=*`. `X-Forwarded-Host` nunca é
confiado — as URLs absolutas vêm de `APP_URL`.

**Três coisas que só o servidor real confirma** e que devem ser testadas na primeira implantação:

1. `.mjs` chega com `Content-Type: text/javascript` (senão o editor de campos não carrega);
2. um upload de 25 MB completa (nginx + PHP + tempo de conexão);
3. o download de um PDF consolidado grande não estoura `fastcgi_read_timeout`.

---

## 10. Código, `.env` e build

```bash
cd /var/www/assinavelox
sudo -u assinavelox git clone --branch main <url-do-repositorio> .

sudo -u assinavelox composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
sudo -u assinavelox npm ci
sudo -u assinavelox npm run build          # gera public/build (não versionado)
```

`--no-dev` remove Pest, PHPStan, Pint, Faker e Collision. Consequência: **não é possível rodar a
suíte de testes no servidor de produção**, e não deve ser.

### `.env` de produção

Copie o `.env.example` e preencha. O modelo abaixo mostra as chaves **obrigatórias em produção**;
a lista completa e comentada está em `.env.example`.

```dotenv
# --- Aplicação ---------------------------------------------------------------
APP_NAME=AssinaVelox
APP_ENV=production
APP_KEY=                       # php artisan key:generate (§13)
APP_DEBUG=false                # NUNCA true em produção
APP_URL=https://app.assinavelox.com.br    # base de TODOS os links de e-mail; só este host é aceito
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=en

# --- Log ---------------------------------------------------------------------
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning              # em produção; `debug` só para investigar, e por pouco tempo
LOG_DAILY_DAYS=14

# --- Banco -------------------------------------------------------------------
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=assinavelox
DB_USERNAME=assinavelox_app    # runtime, SEM DDL e SEM update/delete em audit_events
DB_PASSWORD=                   # segredo

# --- Sessão, cache e fila ----------------------------------------------------
SESSION_DRIVER=database        # sobrevive a reinício do Redis
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=                # segredo

# --- Proxy ------------------------------------------------------------------
# Lista de IPs/CIDRs do balanceador, separada por vírgula. Vazio se o nginx é o borda.
# NUNCA "*": define o IP gravado nos aceites e a chave dos limitadores.
TRUSTED_PROXIES=

# --- E-mail (serviço do proprietário) ---------------------------------------
ASSINAVELOX_EMAIL_PROVIDER=laravel
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_SCHEME=tls                # STARTTLS obrigatório (traduzido por SmtpTransportOptions)
MAIL_USERNAME=                 # segredo
MAIL_PASSWORD=                 # segredo
MAIL_FROM_ADDRESS=nao-responda@assinavelox.com.br
MAIL_FROM_NAME="${APP_NAME}"

# --- Documentos --------------------------------------------------------------
DOCUMENTS_DISK=local           # ou s3, com bucket PRIVADO
# DOCUMENTS_S3_KEY / _SECRET / _REGION / _BUCKET / _ENDPOINT  <- segredos

# --- Ferramentas de PDF ------------------------------------------------------
PDFTOOL_PYTHON=                # vazio = tools/pdftool/.venv/bin/python
LIBREOFFICE_BIN=/usr/bin/soffice
PDFTOOL_TMP_PATH=              # vazio = storage/app/tmp/pdftool

# --- Certificado A1 da operadora (opcional) ----------------------------------
COMPANY_CERT_ENABLED=false     # true só com o PFX no lugar e a senha no ambiente do worker
COMPANY_CERT_ENVIRONMENT=production      # 'production' SOMENTE com A1 de AC da ICP-Brasil
COMPANY_CERT_PFX_PATH=/var/lib/assinavelox/certs/operadora.pfx
COMPANY_CERT_PASSWORD_ENV=COMPANY_CERT_PASSWORD    # o NOME da variável, nunca o valor
PDFTOOL_TRUST_ROOTS=/var/lib/assinavelox/certs/cadeia-ac.pem

# --- Mercado Pago ------------------------------------------------------------
MERCADOPAGO_DRIVER=mercadopago            # 'auto' cairia no fake sem credencial; em produção seja explícito
MERCADOPAGO_ENVIRONMENT=production
MERCADOPAGO_ACCESS_TOKEN=                 # segredo
MERCADOPAGO_WEBHOOK_SECRET=               # segredo; sem ele o webhook responde 401
MERCADOPAGO_NOTIFICATION_URL=https://app.assinavelox.com.br/webhooks/mercadopago

# --- Segurança ---------------------------------------------------------------
ASSINAVELOX_CSP_ENABLED=true
ASSINAVELOX_CSP_REPORT_ONLY=false         # valide com true antes de bloquear

# --- Operadora no recibo interno (não é documento fiscal) --------------------
ASSINAVELOX_OPERATOR_NAME=AssinaVelox
ASSINAVELOX_OPERATOR_LEGAL_NAME=          # razão social real — obrigatório antes de faturar
ASSINAVELOX_OPERATOR_TAX_ID=              # CNPJ
```

### Migrations e caches

```bash
# Migrations com o usuário de DDL, só no deploy
sudo -u assinavelox env DB_USERNAME=assinavelox_ddl DB_PASSWORD='<senha-forte-1>' \
  php artisan migrate --force

# Planos (idempotente). NÃO rode db:seed sem --class em produção:
# os seeders de demonstração só rodam em local/testing, mas seja explícito.
sudo -u assinavelox php artisan db:seed --class=PlanSeeder --force

# Caches de produção
sudo -u assinavelox php artisan config:cache
sudo -u assinavelox php artisan route:cache
sudo -u assinavelox php artisan view:cache
sudo -u assinavelox php artisan event:cache
```

> **`config:cache` faz o Laravel parar de ler o `.env` em tempo de execução.** Todo segredo que
> precisa existir em execução — a senha do PFX, principalmente — tem de estar no **ambiente do
> processo** (unit systemd, pool do PHP-FPM), não apenas no arquivo. Repita os quatro `*:cache`
> a cada deploy; eles são a diferença entre uma resposta em 40 ms e uma em 300 ms.

`storage:link` **não** é necessário: nada da aplicação é servido do disco público.

---

## 11. systemd: Horizon e agendador

### Horizon

`/etc/systemd/system/assinavelox-horizon.service`:

```ini
[Unit]
Description=AssinaVelox — Horizon (workers das filas)
After=network.target mysql.service redis-server.service
Requires=redis-server.service

[Service]
Type=simple
User=assinavelox-worker
Group=assinavelox
WorkingDirectory=/var/www/assinavelox

# Segredos do worker (senha do PFX, credenciais). 600, dono root. Ver §13.
EnvironmentFile=/etc/assinavelox/worker.env

ExecStart=/usr/bin/php /var/www/assinavelox/artisan horizon

# Recarga sem perder job: SIGTERM faz o Horizon parar de aceitar trabalho novo e
# esperar os jobs em andamento terminarem ("graceful"). `systemctl reload` envia
# horizon:terminate, que tem o mesmo efeito e devolve o controle imediatamente.
ExecReload=/usr/bin/php /var/www/assinavelox/artisan horizon:terminate
KillSignal=SIGTERM
# Maior que o timeout do supervisor mais lento (finalization: 600 s) + folga.
TimeoutStopSec=720

Restart=always
RestartSec=3

# Endurecimento
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=/var/www/assinavelox/storage /var/www/assinavelox/bootstrap/cache
ProtectKernelTunables=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
LockPersonality=yes
MemoryMax=1G
TasksMax=256
LimitNOFILE=8192

StandardOutput=journal
StandardError=journal
SyslogIdentifier=assinavelox-horizon

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now assinavelox-horizon
sudo systemctl status assinavelox-horizon
```

**`reload` × `restart`.** No deploy use sempre `systemctl reload assinavelox-horizon`: o
`horizon:terminate` deixa cada worker terminar o job atual antes de sair, e o `Restart=always`
levanta o processo já com o código novo. `restart` mata mais cedo e pode interromper uma
finalização no meio — o pipeline é idempotente e retomável (`docs/finalizacao-e-evidencias.md` §6),
mas não há razão para exercitar isso a cada deploy.

**Isolamento de rede para conversões.** Se você quiser poder afirmar que o LibreOffice não acessa
a rede (`docs/pdf-pipeline.md` §4), separe a fila `conversions` em uma segunda unit com
`PrivateNetwork=yes`, deixando as filas que usam rede (`notifications`, `billing`) na unit
principal. Isso exige um Horizon com supervisores divididos entre dois processos, ou trocar essa
unit por um `queue:work --queue=conversions`. **Não está configurado por padrão**; registre a
decisão que você tomar.

### Agendador

Duas opções. **Prefira o timer** — ele é observável (`systemctl list-timers`), tem log no journal
e não depende de o cron do usuário existir.

`/etc/systemd/system/assinavelox-schedule.service`:

```ini
[Unit]
Description=AssinaVelox — agendador (schedule:run)

[Service]
Type=oneshot
User=assinavelox-worker
Group=assinavelox
WorkingDirectory=/var/www/assinavelox
EnvironmentFile=/etc/assinavelox/worker.env
ExecStart=/usr/bin/php /var/www/assinavelox/artisan schedule:run

NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=/var/www/assinavelox/storage /var/www/assinavelox/bootstrap/cache
SyslogIdentifier=assinavelox-schedule
```

`/etc/systemd/system/assinavelox-schedule.timer`:

```ini
[Unit]
Description=AssinaVelox — dispara o agendador a cada minuto

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Persistent=false

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now assinavelox-schedule.timer
systemctl list-timers assinavelox-schedule.timer
```

**Alternativa com cron**, se preferir:

```bash
sudo crontab -u assinavelox-worker -e
```

```cron
* * * * * cd /var/www/assinavelox && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

O cron do usuário **não** lê o `EnvironmentFile` do systemd. Se a senha do PFX precisar existir
para algum comando agendado, o cron precisa dela por outro caminho — mais um motivo para
preferir o timer.

O que o agendador roda (`routes/console.php`; todos com `withoutOverlapping()` e `onOneServer()`):

| Comando                     | Frequência      | O que faz                                                      |
| --------------------------- | --------------- | -------------------------------------------------------------- |
| `envelopes:expire`          | 15 em 15 min    | Expira envelopes vencidos (lote de 200 por execução).          |
| `envelopes:notify-expiring` | de hora em hora | Avisa signatário e remetente 48 h antes do prazo.              |
| `billing:dunning`           | diário, 03:20   | `past_due` após a carência; `expired` depois; volta ao Grátis. |

**Sem o agendador rodando, envelopes vencidos continuam abertos e assinaturas inadimplentes
continuam ativas.** É a primeira coisa a conferir depois de um incidente no servidor.

---

## 12. Rotação de logs

O canal `daily` do Laravel já apaga sozinho (`LOG_DAILY_DAYS=14`). Falta o resto.

`/etc/logrotate.d/assinavelox`:

```
/var/www/assinavelox/storage/logs/*.log {
    daily
    rotate 14
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
    su assinavelox assinavelox
    create 0640 assinavelox assinavelox
}

/var/log/nginx/assinavelox-*.log {
    daily
    rotate 30
    missingok
    notifempty
    compress
    delaycompress
    create 0640 www-data adm
    sharedscripts
    postrotate
        [ -f /run/nginx.pid ] && kill -USR1 $(cat /run/nginx.pid)
    endscript
}

/var/log/php8.3-fpm-assinavelox.log {
    weekly
    rotate 8
    missingok
    notifempty
    compress
    delaycompress
    create 0640 root adm
    postrotate
        /usr/lib/php/php8.3-fpm-reopenlogs
    endscript
}
```

`copytruncate` no log do Laravel evita ter de reiniciar o FPM e o worker a cada rotação; o preço é
uma janela de milissegundos em que uma linha pode se perder. Para logs de aplicação, é o acordo
certo.

Limite o journal também:

```bash
sudo sed -i 's/^#\?SystemMaxUse=.*/SystemMaxUse=1G/' /etc/systemd/journald.conf
sudo systemctl restart systemd-journald
```

**Não rotacione `storage/app/documents`.** São os documentos dos clientes, não logs.

---

## 13. Segredos

### Regra

Segredo é qualquer valor que, vazado, permite a alguém agir como a plataforma, ler documentos de
clientes ou assinar em nome da operadora. **Nenhum vai para o repositório.** Em configuração ficam
apenas **referências**: caminho de arquivo, nome de variável de ambiente.

O código já foi construído em torno disso: `COMPANY_CERT_PASSWORD_ENV` guarda o _nome_ da
variável, não o valor; `certificate_references.secret_ref` guarda a referência, não o segredo; o
cliente do pdftool redige qualquer ocorrência do valor no stderr registrado; nenhuma credencial
aparece em argumento de processo, em payload de fila ou em log.

### Onde guardar

Três lugares, em ordem de preferência:

1. **Cofre** (HashiCorp Vault, AWS Secrets Manager, 1Password Connect) que **exporta variáveis de
   ambiente** no start do serviço. É o alvo; não está implementado nem exigido.
2. **`EnvironmentFile` do systemd** — o que este documento assume. Um arquivo por perfil de
   processo, fora do diretório do código:

    ```bash
    sudo mkdir -p /etc/assinavelox
    sudo chmod 750 /etc/assinavelox
    sudo touch /etc/assinavelox/worker.env
    sudo chown root:root /etc/assinavelox/worker.env
    sudo chmod 600 /etc/assinavelox/worker.env
    ```

    ```dotenv
    # /etc/assinavelox/worker.env — lido pelo systemd, nunca versionado.
    # Senha do PKCS#12 do certificado A1. O NOME desta variável é o que fica em
    # COMPANY_CERT_PASSWORD_ENV; o valor existe só aqui e no ambiente do processo.
    COMPANY_CERT_PASSWORD=...
    ```

3. **`.env` com `chmod 600`** — aceitável para o resto (banco, SMTP, Redis, Mercado Pago), desde
   que `config:cache` esteja aplicado, porque aí o arquivo só é lido no deploy.

**O que nunca vai para o repositório:** `.env`, `.env.production`, qualquer `.pfx`/`.p12`/`.pem` de
chave privada, `auth.json` do Composer, dumps de banco, `storage/*.key`, `storage/app/documents/**`,
`/etc/assinavelox/*.env`. O `.gitignore` já cobre `.env*`, `/storage/*.key` e `/auth.json` —
confira antes do primeiro `git add` em um servidor.

### Inventário completo

| Segredo                        | Onde vive                                  | Quem precisa    | O que acontece se vazar                                               |
| ------------------------------ | ------------------------------------------ | --------------- | --------------------------------------------------------------------- |
| `APP_KEY`                      | `.env`                                     | web + worker    | Decifra `organizations.tax_id` e forja os HMAC dos códigos OTP.       |
| `DB_PASSWORD` (runtime)        | `.env`                                     | web + worker    | Leitura e escrita de todos os dados (menos alterar `audit_events`).   |
| Senha do usuário de DDL        | Cofre / digitada no deploy                 | só o deploy     | Alteração de esquema; destruição de dados.                            |
| `REDIS_PASSWORD`               | `.env`                                     | web + worker    | Leitura da fila — onde trafegam links de convite e códigos OTP.       |
| `MAIL_PASSWORD` / SMTP         | `.env`                                     | worker (e web)  | Envio de e-mail em nome do domínio: convites falsos, phishing.        |
| **Senha do PFX (A1)**          | `/etc/assinavelox/worker.env`              | **só o worker** | Com o `.pfx`, permite assinar em nome da operadora.                   |
| **Arquivo `.pfx` do A1**       | `/var/lib/assinavelox/certs/`, `chmod 400` | **só o worker** | Idem. Segredo e arquivo devem ficar em lugares e backups diferentes.  |
| `MERCADOPAGO_ACCESS_TOKEN`     | `.env`                                     | web + worker    | Acesso à conta de pagamentos: consultar, reembolsar, criar cobranças. |
| `MERCADOPAGO_WEBHOOK_SECRET`   | `.env`                                     | web             | Permite forjar notificação de pagamento e ativar plano sem pagar.     |
| `DOCUMENTS_S3_KEY` / `_SECRET` | `.env`                                     | web + worker    | Acesso a **todos** os documentos dos clientes.                        |
| Certificado TLS (chave)        | `/etc/letsencrypt/live/…`                  | nginx           | Interceptação do tráfego.                                             |

### Rotação

**Antes de qualquer rotação:** `sudo systemctl stop assinavelox-schedule.timer` para não pegar um
comando agendado no meio da troca.

| Segredo                      | Procedimento                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **`APP_KEY`**                | **A mais perigosa.** Ela decifra `organizations.tax_id` (cast `encrypted`) e deriva o HMAC dos códigos OTP. Trocar sem cuidado torna o CNPJ das organizações ilegível para sempre. Procedimento: backup completo → gerar a nova chave, manter a antiga em `APP_PREVIOUS_KEYS` (o Laravel decifra com as antigas e recifra com a nova) → `php artisan config:cache` → recifrar os valores existentes (ler e regravar cada `organizations.tax_id`) → só então remover a chave antiga. Os desafios OTP em aberto (validade de 10 min) são invalidados: aceitável em janela de manutenção. |
| `DB_PASSWORD`                | `ALTER USER 'assinavelox_app'@'localhost' IDENTIFIED BY '<nova>';` → `.env` → `config:cache` → `systemctl reload php8.3-fpm assinavelox-horizon`. Sem indisponibilidade se feito nessa ordem: conexões abertas seguem válidas.                                                                                                                                                                                                                                                                                                                                                         |
| `REDIS_PASSWORD`             | `CONFIG SET requirepass <nova>` + `redis.conf` → `.env` → `config:cache` → **`restart`** do Horizon (a conexão persistente não renegocia).                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| `MAIL_PASSWORD`              | Gerar no provedor → `.env` → `config:cache` → `reload` do Horizon. Verifique com um envio de convite de teste antes de revogar a antiga.                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| **Senha do PFX**             | A senha protege o arquivo, e trocá-la significa **reexportar o PKCS#12** com a nova senha (`openssl pkcs12 -in antigo.pfx -nodes …                                                                                                                                                                                                                                                                                                                                                                                                                                                     | openssl pkcs12 -export -out novo.pfx`). O certificado e a chave são os mesmos — nada assinado antes é invalidado. Substitua o arquivo, atualize `/etc/assinavelox/worker.env`, `systemctl restart assinavelox-horizon`, `php artisan pdftool:selftest`. |
| **Certificado A1 (troca)**   | É emissão nova, não rotação de segredo: ver `docs/operacao.md` §7. Documentos já assinados continuam válidos com o certificado antigo; guarde o `.pfx` antigo e a cadeia mesmo depois de vencido — sem eles a assinatura antiga fica inverificável.                                                                                                                                                                                                                                                                                                                                    |
| `MERCADOPAGO_ACCESS_TOKEN`   | Painel do Mercado Pago → nova credencial → `.env` → `config:cache` → `reload`. Revogue a antiga **depois** de confirmar um checkout com a nova. `MERCADOPAGO_ENVIRONMENT` precisa continuar batendo com o `live_mode` da credencial, senão a aplicação recusa.                                                                                                                                                                                                                                                                                                                         |
| `MERCADOPAGO_WEBHOOK_SECRET` | Gerado no painel, por ambiente. Enquanto não estiver no `.env`, **todo webhook responde 401** — o Mercado Pago repete, então a janela curta é recuperável, mas faça em horário de baixo movimento e confira `payment_webhook_receipts` depois.                                                                                                                                                                                                                                                                                                                                         |
| `DOCUMENTS_S3_*`             | Criar chave nova → `.env` → `config:cache` → `reload` → validar um download → revogar a antiga.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| TLS                          | `certbot renew` (timer automático) + `systemctl reload nginx`. Monitore o vencimento (`docs/operacao.md` §1).                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |

Depois de qualquer rotação: `php artisan config:cache` (senão o valor antigo continua no cache) e
uma verificação funcional — não confie no fato de o serviço ter subido.

---

## 14. Backups e restauração

> **Backup sem teste de restauração não é backup.** Um dump que ninguém restaurou é uma hipótese.
> Este sistema guarda evidências de aceite: se o banco e os arquivos ficarem incoerentes, o
> registro de verificação pública aponta para um documento que não existe mais, e a prova morre.
> Agende o teste do §14.4 no calendário; se ele não roda, o backup não conta.

### 14.1 O que precisa de cópia

| Item                                          | Por quê                                                                                      | Frequência           | Retenção          |
| --------------------------------------------- | -------------------------------------------------------------------------------------------- | -------------------- | ----------------- |
| **Banco MySQL** (completo)                    | Envelopes, aceites, `audit_events`, `verification_records`, cobrança. Sem ele nada se prova. | Diário               | 30 dias           |
| **Binlog do MySQL**                           | Recuperação a um ponto no tempo entre dois dumps.                                            | Contínuo             | 14 dias           |
| **`storage/app/documents/`**                  | Os documentos em si: originais, versões convertidas, arquivos finais assinados.              | Diário (incremental) | 90 dias           |
| **Certificado A1 (`.pfx`) + cadeia**          | Sem ele, assinaturas antigas ficam inverificáveis e não se assina mais.                      | A cada troca         | **Permanente**    |
| **`APP_KEY`**                                 | Decifra `organizations.tax_id`. Perder = perder o dado.                                      | A cada troca         | **Permanente**    |
| **`.env` e `/etc/assinavelox/*.env`**         | Reconstrução do ambiente.                                                                    | A cada mudança       | Últimas 5 versões |
| Configuração de sistema (nginx, systemd, php) | Encurta a reconstrução de horas para minutos.                                                | A cada mudança       | 30 dias           |

**Não precisa de backup:** `vendor/`, `node_modules/`, `public/build/`, `storage/framework/cache`,
`storage/app/tmp/`, Redis (fila e cache são reconstruíveis — jobs perdidos são reprocessáveis pelo
`docs/operacao.md` §4).

**Guarde a chave e o cofre em lugares diferentes.** O backup do `.pfx` junto com o backup da senha,
no mesmo bucket, com a mesma credencial, é um único ponto de falha. E o backup precisa ser
**cifrado em repouso** — ele contém documentos de clientes.

### 14.2 Rotina

`/usr/local/bin/assinavelox-backup.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

DEST=/var/backups/assinavelox
DATE=$(date -u +%Y%m%dT%H%M%SZ)
APP=/var/www/assinavelox

mkdir -p "$DEST/db" "$DEST/documents" "$DEST/config"

# --- Banco. --single-transaction dá um dump consistente sem travar o InnoDB.
#     --source-data=2 grava a posição do binlog no dump: é o que permite o PITR.
mysqldump --defaults-file=/etc/assinavelox/mysqldump.cnf \
  --single-transaction --quick --source-data=2 --routines --events \
  --databases assinavelox \
  | gzip -9 > "$DEST/db/assinavelox-$DATE.sql.gz"

# --- Documentos. Incremental com link rígido: cada snapshot parece completo,
#     mas arquivos que não mudaram não ocupam espaço novo.
rsync -a --delete \
  --link-dest="$DEST/documents/latest" \
  "$APP/storage/app/documents/" "$DEST/documents/$DATE/"
ln -sfn "$DEST/documents/$DATE" "$DEST/documents/latest"

# --- Configuração (contém segredos: o destino PRECISA ser cifrado)
tar czf "$DEST/config/config-$DATE.tar.gz" \
  -C / etc/assinavelox etc/nginx/sites-available/assinavelox \
       etc/systemd/system/assinavelox-*.service \
       etc/systemd/system/assinavelox-*.timer \
       etc/php/8.3/fpm/pool.d/assinavelox.conf \
  --exclude='*.sock'
install -m 600 "$APP/.env" "$DEST/config/env-$DATE"

# --- Retenção
find "$DEST/db"     -name '*.sql.gz' -mtime +30 -delete
find "$DEST/config" -mtime +30 -delete
ls -1dt "$DEST/documents"/2* | tail -n +91 | xargs -r rm -rf

# --- Cópia externa, cifrada. Sem isto, um incêndio no servidor leva o backup junto.
# rclone sync "$DEST" remoto-cifrado:assinavelox --transfers 4
```

`/etc/assinavelox/mysqldump.cnf` (600, root) guarda a credencial de leitura do dump, para que ela
não apareça em `ps`:

```ini
[mysqldump]
user=assinavelox_backup
password=<senha>
host=127.0.0.1
```

```sql
CREATE USER 'assinavelox_backup'@'localhost' IDENTIFIED BY '<senha>';
GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER, RELOAD, REPLICATION CLIENT
  ON *.* TO 'assinavelox_backup'@'localhost';
```

Timer diário:

```ini
# /etc/systemd/system/assinavelox-backup.timer
[Unit]
Description=AssinaVelox — backup diário
[Timer]
OnCalendar=*-*-* 02:30:00
Persistent=true
RandomizedDelaySec=300
[Install]
WantedBy=timers.target
```

**Ordem importa:** o dump do banco roda **antes** do rsync dos documentos. Assim o conjunto pode
conter arquivos que o banco ainda não conhece (inofensivo — um documento órfão), mas nunca uma
linha de banco apontando para um arquivo que o backup não copiou (fatal — evidência quebrada).

### 14.3 Restauração

Em servidor limpo, com §2–§11 já feitos:

```bash
# 1. Manutenção (se o servidor está de pé)
sudo -u assinavelox php artisan down --retry=60
sudo systemctl stop assinavelox-horizon assinavelox-schedule.timer

# 2. Banco
gunzip -c /var/backups/assinavelox/db/assinavelox-<DATE>.sql.gz \
  | sudo mysql --user=assinavelox_ddl -p

# 3. Documentos
sudo rsync -a --delete \
  /var/backups/assinavelox/documents/<DATE>/ \
  /var/www/assinavelox/storage/app/documents/
sudo chown -R assinavelox:assinavelox /var/www/assinavelox/storage/app/documents
sudo chmod -R 770 /var/www/assinavelox/storage/app/documents

# 4. Configuração e segredos
sudo tar xzf /var/backups/assinavelox/config/config-<DATE>.tar.gz -C /
sudo install -o assinavelox -g assinavelox -m 600 \
  /var/backups/assinavelox/config/env-<DATE> /var/www/assinavelox/.env

# 5. Certificado A1 (do cofre, não do backup de configuração)
sudo install -o assinavelox-worker -g assinavelox -m 400 \
  <origem>/operadora.pfx /var/lib/assinavelox/certs/operadora.pfx

# 6. Caches e serviços
cd /var/www/assinavelox
sudo -u assinavelox php artisan config:cache
sudo -u assinavelox php artisan route:cache
sudo -u assinavelox php artisan view:cache
sudo -u assinavelox php artisan event:cache
sudo systemctl daemon-reload
sudo systemctl start assinavelox-horizon assinavelox-schedule.timer
sudo systemctl reload php8.3-fpm nginx

# 7. VERIFICAÇÃO (§14.4) — antes de sair da manutenção
sudo -u assinavelox php artisan pdftool:selftest

# 8. Voltar
sudo -u assinavelox php artisan up
```

**Recuperação a um ponto no tempo** (entre dois dumps), usando a posição gravada por
`--source-data=2`:

```bash
zcat assinavelox-<DATE>.sql.gz | head -40 | grep 'CHANGE MASTER\|CHANGE REPLICATION'
sudo mysqlbinlog --start-position=<POS> --stop-datetime='2026-09-09 14:00:00' \
  /var/log/mysql/mysql-bin.00000* | sudo mysql --user=assinavelox_ddl -p
```

Documentos criados depois do último rsync **não voltam** — o binlog recupera o banco, não os
arquivos. Isso é exatamente o tipo de incoerência que o §14.4 detecta.

### 14.4 Verificação da restauração (obrigatória)

Restaurou? Prove que banco e arquivos concordam. O que torna isso possível é
`document_versions` guardar `storage_disk`, `storage_path`, `size_bytes` e **`sha256`** de cada
arquivo: dá para conferir cada byte contra o que o banco afirma.

Salve como `scripts/verificar-restauracao.php` (ou rode via `php artisan tinker`):

```php
<?php
// Uso: php scripts/verificar-restauracao.php
// Confere, para cada versão de documento: o arquivo existe, o tamanho bate e o
// SHA-256 bate. Depois confere que todo registro de verificação pública aponta
// para um arquivo final que existe.

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DocumentVersion;
use App\Models\VerificationRecord;
use Illuminate\Support\Facades\Storage;

$faltando = $tamanho = $hash = $ok = 0;

DocumentVersion::query()->orderBy('id')->chunk(200, function ($versoes) use (&$faltando, &$tamanho, &$hash, &$ok) {
    foreach ($versoes as $v) {
        $disco = Storage::disk($v->storage_disk);

        if (! $disco->exists($v->storage_path)) {
            $faltando++;
            fwrite(STDERR, "AUSENTE   id={$v->id} {$v->storage_disk}:{$v->storage_path}\n");
            continue;
        }
        if ((int) $disco->size($v->storage_path) !== (int) $v->size_bytes) {
            $tamanho++;
            fwrite(STDERR, "TAMANHO   id={$v->id} banco={$v->size_bytes} disco=".$disco->size($v->storage_path)."\n");
            continue;
        }
        // Streaming: um PDF de 25 MB não precisa ir inteiro para a memória.
        $ctx = hash_init('sha256');
        $fh = $disco->readStream($v->storage_path);
        while (! feof($fh)) { hash_update($ctx, (string) fread($fh, 1 << 20)); }
        fclose($fh);

        if (! hash_equals((string) $v->sha256, hash_final($ctx))) {
            $hash++;
            fwrite(STDERR, "HASH      id={$v->id} {$v->storage_path}\n");
            continue;
        }
        $ok++;
    }
});

$semArquivo = VerificationRecord::query()
    ->whereNull('final_document_version_id')
    ->count();

echo "\n=== Coerência banco × arquivos ===\n";
echo "versões íntegras ......... {$ok}\n";
echo "arquivos ausentes ........ {$faltando}\n";
echo "tamanho divergente ....... {$tamanho}\n";
echo "hash divergente .......... {$hash}\n";
echo "registros de verificação sem documento final ... {$semArquivo}\n";

exit(($faltando + $tamanho + $hash + $semArquivo) === 0 ? 0 : 1);
```

Interpretação:

| Resultado                                       | Significado                                                                                                                                             |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Tudo zero, exit 0                               | Restauração coerente. Pode sair da manutenção.                                                                                                          |
| **Arquivos ausentes**                           | O backup de arquivos é mais antigo que o do banco. Restaure um rsync mais novo, ou aceite a perda **e registre quais envelopes**.                       |
| **Hash divergente**                             | Corrupção silenciosa (disco, transferência). O documento não pode ser apresentado como prova. Investigue o meio de armazenamento.                       |
| **Registro de verificação sem documento final** | Envelope concluído cujo arquivo final não foi vinculado. Normal para envelopes em finalização no instante do dump; anormal para os concluídos há tempo. |

Complemente com três verificações manuais, que nenhum script substitui:

1. Abrir `/verificar/{code}` de um envelope concluído conhecido e conferir o hash pelo navegador.
2. Baixar o PDF final desse envelope e conferir que abre e tem a página de evidências.
3. `php artisan pdftool:selftest` — o pipeline de PDF depende do venv, não do backup do banco.

**Faça esse ensaio a cada trimestre**, em servidor de teste, a partir dos backups reais. Um
restore que só acontece no dia do incidente é um restore que nunca aconteceu.

---

## 15. Atualização (deploy) e retorno seguro

### Antes

```bash
sudo /usr/local/bin/assinavelox-backup.sh          # backup imediato, não o de ontem
cd /var/www/assinavelox && git rev-parse HEAD > /tmp/deploy-anterior.txt
```

Anote o commit atual. É o seu caminho de volta.

### Procedimento

```bash
cd /var/www/assinavelox

# 1. Manutenção. --secret dá uma URL para você mesmo continuar navegando no site.
#    Sai a página 503 padrão do Laravel: o projeto NÃO tem uma view Blade própria de
#    manutenção (as páginas de erro 403/404/500 são componentes Inertia, que não servem
#    para o modo de manutenção — ele responde antes de a aplicação subir). Para uma
#    página própria, crie resources/views/errors/503.blade.php e passe --render.
sudo -u assinavelox php artisan down --retry=60 --secret="$(openssl rand -hex 16)"

# 2. Parar o que consome fila e agenda
sudo systemctl stop assinavelox-schedule.timer
sudo -u assinavelox php artisan horizon:terminate     # espera os jobs em andamento

# 3. Código
sudo -u assinavelox git fetch --all
sudo -u assinavelox git checkout <tag-ou-commit>

# 4. Dependências
sudo -u assinavelox composer install --no-dev --optimize-autoloader --no-interaction
sudo -u assinavelox npm ci
sudo -u assinavelox npm run build

# 5. pdftool, se o lock mudou
git diff --name-only <anterior> HEAD | grep -q 'tools/pdftool/requirements.lock.txt' && \
  sudo -u assinavelox tools/pdftool/.venv/bin/python -m pip install -r tools/pdftool/requirements.lock.txt

# 6. Migrations (usuário de DDL)
sudo -u assinavelox env DB_USERNAME=assinavelox_ddl DB_PASSWORD='<senha>' \
  php artisan migrate --force

# 7. Caches
sudo -u assinavelox php artisan config:cache
sudo -u assinavelox php artisan route:cache
sudo -u assinavelox php artisan view:cache
sudo -u assinavelox php artisan event:cache

# 8. Recarregar processos. OPcache com validate_timestamps=0 exige o reload do FPM.
sudo systemctl reload php8.3-fpm
sudo systemctl reload assinavelox-horizon
sudo systemctl start assinavelox-schedule.timer

# 9. Fumaça, ainda em manutenção (use a URL do --secret)
sudo -u assinavelox php artisan pdftool:selftest
curl -sf https://app.assinavelox.com.br/up && echo " health OK"
sudo -u assinavelox php artisan about | head -30

# 10. Sair da manutenção
sudo -u assinavelox php artisan up
```

Nos 15 minutos seguintes, acompanhe:

```bash
sudo journalctl -u assinavelox-horizon -f
sudo tail -f /var/www/assinavelox/storage/logs/laravel-$(date +%Y-%m-%d).log
sudo -u assinavelox php artisan queue:failed
```

### Retorno seguro

**Sem migration nova** — reversão limpa, minutos:

```bash
cd /var/www/assinavelox
sudo -u assinavelox php artisan down
sudo -u assinavelox git checkout $(cat /tmp/deploy-anterior.txt)
sudo -u assinavelox composer install --no-dev --optimize-autoloader
sudo -u assinavelox npm ci && sudo -u assinavelox npm run build
sudo -u assinavelox php artisan config:cache && sudo -u assinavelox php artisan route:cache
sudo -u assinavelox php artisan view:cache  && sudo -u assinavelox php artisan event:cache
sudo systemctl reload php8.3-fpm assinavelox-horizon
sudo -u assinavelox php artisan up
```

**Com migration nova** — pare e pense. `migrate:rollback` executa o `down()` da migration, que pode
apagar coluna com dado novo escrito nos últimos minutos. Em um sistema de evidências isso é pior
que a falha original. Ordem de preferência:

1. **Corrigir para a frente.** Se a falha é de aplicação e o esquema novo é compatível com o
   código antigo (migration aditiva: coluna nova _nullable_, tabela nova), volte só o código e
   deixe o esquema novo. É o caso da maioria das migrations deste projeto.
2. **`migrate:rollback --step=1`** apenas se a migration for comprovadamente aditiva e ninguém
   tiver escrito nas colunas novas. Confira o `down()` antes.
3. **Restaurar o backup** (§14.3) se houve escrita destrutiva. Custo: as transações desde o dump,
   recuperáveis pelo binlog.

Em todos os casos, depois de voltar: rode o script do §14.4 e confira `queue:failed`. Um deploy
revertido costuma deixar jobs enfileirados com um payload que o código antigo não entende — eles
falham, ficam registrados e devem ser inspecionados antes de qualquer `queue:retry all`.

---

## 16. Checklist de primeira implantação

Marque um a um. Os itens em **negrito** nunca foram exercitados em servidor real — a primeira
implantação é a verificação deles.

**Sistema**

- [ ] `php -m` mostra todas as extensões do §2, incluindo `intl`, `gd`, `zip`, `pcntl`, `posix`.
- [ ] `php -v` é 8.3+; `node --version` é 22.x; `python3 --version` é 3.13.
- [ ] Usuários `assinavelox` e `assinavelox-worker` existem; `.env` é 600 e o worker **não** o lê.

**Banco**

- [ ] `assinavelox_app` não consegue `DELETE FROM audit_events` (erro 1142).
- [ ] `SELECT @@global.time_zone` devolve `+00:00`.
- [ ] `log_bin` ativo e o dump grava a posição (`--source-data=2`).

**Aplicação**

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `APP_URL` com HTTPS e host correto.
- [ ] Os quatro `*:cache` aplicados; `php artisan about` mostra config/rotas/eventos em cache.
- [ ] `TRUSTED_PROXIES` preenchido com a lista de IPs (nunca `*`) ou vazio se o nginx é o borda.
- [ ] `ASSINAVELOX_CSP_ENABLED=true` — validado antes com `REPORT_ONLY=true`.
- [ ] `ASSINAVELOX_OPERATOR_LEGAL_NAME` e `_TAX_ID` preenchidos (aparecem no recibo interno).

**Web**

- [ ] `curl -I https://…/up` responde 200.
- [ ] **Um `.mjs` de `public/build/assets/` chega com `Content-Type: text/javascript`.**
- [ ] **Upload de um arquivo de 25 MB completa** (e um de 30 MB é recusado pela aplicação, com
      mensagem em PT-BR, não por um 413 do nginx).
- [ ] Download de um PDF consolidado grande completa sem estourar o timeout.

**Filas e agenda**

- [ ] `systemctl status assinavelox-horizon` ativo; painel `/horizon` acessível só para quem deve.
- [ ] `systemctl list-timers` mostra `assinavelox-schedule.timer` disparando de minuto em minuto.
- [ ] `php artisan schedule:list` mostra os três comandos com os horários esperados.

**PDF**

- [ ] `sudo -u assinavelox-worker php artisan pdftool:selftest` termina com exit 0.
- [ ] **Uma conversão DOCX→PDF real completa** (se LibreOffice foi instalado).
- [ ] **Um envelope é assinado com o A1 de produção** e o resultado abre em um verificador
      independente — este marco continua **em aberto** (`docs/entrega-fase-1.md` §4).

**Integrações**

- [ ] Um convite de e-mail chega de verdade na caixa do destinatário (não só `sent` na tabela).
- [ ] **Um webhook real do Mercado Pago chega, é autenticado e vira `processed`** em
      `payment_webhook_receipts`.

**Backup**

- [ ] Timer de backup ativo; o primeiro dump existe e descompacta.
- [ ] Cópia externa cifrada configurada.
- [ ] **Ensaio de restauração feito em servidor de teste, com o script do §14.4 em exit 0.**
