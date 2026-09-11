# Pacotes e bibliotecas para as Fases 2 e 3

> Pesquisa técnica com data de referência **2026-09-11**. Identificadores em inglês; prosa em português.
> Escopo: roadmap §2.1 (PHPWord), §2.10 e §3.3 (captura de foto e vídeo), §2.13 (ZIP), §2.16 (SSRF), §3.2 (OCR e âncoras) e §3.9 (iframe, Drive, Dropbox, HubSpot, SSO).
> Regras aplicadas: T1 (semântica de assinatura), T4 (só documentação oficial), T6 (conteúdo de documento é dado não confiável) e T10 (nada de segredo em fila, log ou argv) — ver `docs/roadmap.md` §1.
> Legenda de disponibilidade: **(a)** API pública e documentada · **(b)** existe, mas exige credenciamento, contrato ou elegibilidade · **(c)** sem API pública.
> Tudo que não foi confirmado em fonte oficial está marcado como **NÃO CONFIRMADO**.

## 0. Ambiente verificado localmente (2026-09-11)

| Item               | Valor observado                                                                                                             | Fonte                                     |
| ------------------ | --------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------- |
| PHP                | 8.3.30 NTS x64; extensões `curl`, `dom`, `fileinfo`, `gd`, `intl`, `libxml`, `mbstring`, `openssl`, `zip`, `zlib` presentes | `php -v` / `php -m`                       |
| Node               | v22.22.2                                                                                                                    | `node -v`                                 |
| Python             | 3.13.14                                                                                                                     | `python --version`                        |
| Laravel            | `laravel/framework` v13.31.0                                                                                                | `composer.lock`                           |
| Guzzle             | **`guzzlehttp/guzzle` 8.2.0** (o framework aceita `^7.8.2 \|\| ^8.0`)                                                       | `composer.lock`                           |
| PHPWord            | `phpoffice/phpword` 1.4.0 + `phpoffice/math` 0.3.0                                                                          | `composer.lock`                           |
| QR                 | `bacon/bacon-qr-code` v3.1.1 (já instalado, dependência do Fortify)                                                         | `composer.lock`                           |
| Tesseract / ffmpeg | **não instalados**                                                                                                          | `tesseract --version`, `ffmpeg -version`  |
| Sessão             | `same_site` = `lax`, `partitioned` = `env('SESSION_PARTITIONED_COOKIE', false)`                                             | `config/session.php`                      |
| Cabeçalhos         | `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `Permissions-Policy` de negação explícita                                | `app/Http/Middleware/SecurityHeaders.php` |

### 0.1 Dois conflitos de dependência que afetam várias decisões

1. **Guzzle 8 × SDKs de terceiros.** O projeto já resolveu Guzzle **8.2.0**. A 8.0.0 saiu em 2026-07-20 e a 8.2.0 em 2026-09-06; o último 7.x é o 7.15.5, de 2026-08-24 ([packagist guzzlehttp/guzzle](https://repo.packagist.org/p2/guzzlehttp/guzzle.json)). Três SDKs exigem Guzzle 7, inclusive no branch de desenvolvimento:
    - `google/apiclient` exige `^7.4.5` ([packagist](https://repo.packagist.org/p2/google/apiclient.json), [dev](https://repo.packagist.org/p2/google/apiclient~dev.json));
    - `hubspot/api-client` exige `^7.3` ([packagist](https://repo.packagist.org/p2/hubspot/api-client.json), [dev](https://repo.packagist.org/p2/hubspot/api-client~dev.json));
    - `spatie/dropbox-api` exige `^6.2|^7.0` ([packagist](https://repo.packagist.org/p2/spatie/dropbox-api.json), [dev](https://repo.packagist.org/p2/spatie/dropbox-api~dev.json)).

    Instalar qualquer um deles força o Composer a **rebaixar o Guzzle para 7.x** — a restrição do framework permite, mas é um rebaixamento global. **Conclusão:** para Drive, Dropbox e HubSpot, usar o HTTP Client do Laravel contra a REST documentada, e não os SDKs.

2. **phpseclib 4 × 3.**
    - `laravel/socialite` v5.31.0 (2026-08-31) exige `phpseclib/phpseclib ^4.0`; a v5.30.0 (2026-08-13) foi a última com `^3.0` ([packagist](https://repo.packagist.org/p2/laravel/socialite.json)).
    - `jumbojett/openid-connect-php` v1.0.2 exige `phpseclib ^3.0.7` ([packagist](https://repo.packagist.org/p2/jumbojett/openid-connect-php.json)). Portanto **não convive com o Socialite atual**.
    - `robrichards/xmlseclibs` 4.0.0 (2026-08-22) passou a exigir `phpseclib ~3.0`, mas a linha 3.1.x só exige `ext-openssl` ([packagist](https://repo.packagist.org/p2/robrichards/xmlseclibs.json)). O `onelogin/php-saml` 4.3.2 pede `^3.1.5`, então fica na linha 3.1.x e **não** conflita.

---

## 1. PHPWord `TemplateProcessor` (já instalado, 1.4.0) — limites de segurança

**Fatos confirmados na fonte e na documentação**

- **XXE.**
    - O `TemplateProcessor` trata as partes do DOCX quase só como **string** (regex e substituição). O único parse DOM é em `transformSingleXml()`, com `DOMDocument::loadXML($xml)` **sem** `LIBXML_NOENT`/`LIBXML_DTDLOAD`. `libxml_disable_entity_loader(true)` só é chamado quando `PHP_VERSION_ID < 80000` ([TemplateProcessor.php](https://raw.githubusercontent.com/PHPOffice/PHPWord/master/src/PhpWord/TemplateProcessor.php)).
    - No PHP 8, a proteção vem do libxml: "As of libxml 2.9.0 entity substitution is disabled by default", e só é reativada com `LIBXML_NOENT`, `LIBXML_DTDVALID` ou `LIBXML_DTDLOAD` ([php.net](https://www.php.net/manual/en/function.libxml-disable-entity-loader.php)).
- **Histórico de XXE no ecossistema PHPOffice.**
    - CVE-2025-48882 (GHSA-42hm-pq2f-3r7m) atingia o `phpoffice/math` ≤ 0.2.0 (leitor MathML com `LIBXML_DTDLOAD`) e foi corrigido na 0.3.0 ([GitHub Advisory](https://github.com/advisories/GHSA-42hm-pq2f-3r7m)). O projeto tem `phpoffice/math` 0.3.0 no lock.
    - Que o PHPWord 1.2.0-beta.1+ fosse afetado e que a 1.4.0 traga a correção veio de resultado de busca, não do advisory. O advisory cita só o `phpoffice/math`, então essa ligação fica **NÃO CONFIRMADA** em fonte primária.
    - A página de segurança do PHPWord não tem advisory publicado nem `SECURITY.md` ([GitHub](https://github.com/PHPOffice/PHPWord/security)).
    - CVE-2018-14065 (XXE no `phpoffice/common` < 0.2.9) é antigo ([cvedetails](https://www.cvedetails.com/cve/CVE-2018-14065/) — agregador, marcado como apoio).
- **Escape de valores.** `setValue()` só escapa XML quando `Settings::isOutputEscapingEnabled()` for verdadeiro, e o padrão é **desligado**: "By default, the built-in mechanism is disabled for backward compatibility" ([PHPWord docs](https://phpoffice.github.io/PHPWord/usage/introduction.html), [fonte](https://raw.githubusercontent.com/PHPOffice/PHPWord/master/src/PhpWord/TemplateProcessor.php)).
- **Macros VBA.** O `TemplateProcessor` não lê nem trata `vbaProject.bin`, nem diferencia `.docm`. "Macro", para ele, é apenas o marcador `${var}` ([fonte](https://raw.githubusercontent.com/PHPOffice/PHPWord/master/src/PhpWord/TemplateProcessor.php)). Por isso um `.docm` enviado como modelo **preservaria** o projeto VBA no arquivo gerado.
- **Blocos e clonagem.**
    - `cloneBlock` é implementado por regex sobre `<w:p>`, entre `${bloco}` e `${/bloco}`, **sem suporte a blocos aninhados**.
    - `fixBrokenMacros` remove tags de marcadores quebrados por formatação do Word.
    - API: `cloneBlock`, `replaceBlock`, `deleteBlock`, `cloneRow`, `cloneRowAndSetValues`, `setComplexValue`/`setComplexBlock`, `setImageValue` e delimitadores configuráveis por `setMacroOpeningChars`/`setMacroClosingChars`/`setMacroChars` ([docs](https://phpoffice.github.io/PHPWord/usage/template.html), [fonte](https://raw.githubusercontent.com/PHPOffice/PHPWord/master/src/PhpWord/TemplateProcessor.php)).

**Regras recomendadas para §2.1** (derivadas dos fatos acima; decisão nossa, não do fornecedor)

1. Chamar `Settings::setOutputEscapingEnabled(true)` no boot do serviço de templates, com teste unitário que injeta `& < > " '` e `]]>`.
2. Aceitar só `.docx`. Rejeitar se `[Content_Types].xml` declarar o tipo `macroEnabled` ou se existir `word/vbaProject.bin`. Recusar `.docm`/`.dotm`.
3. Antes de abrir o ZIP, limitar número de entradas e tamanho descompactado total (proteção contra zip bomb; `ZipArchive` já disponível). Nunca aceitar `LIBXML_NOENT`/`DTDLOAD` em código próprio.
4. `setImageValue` recebe só caminhos gerados por nós (disco privado). Nunca um caminho ou URL vindo do cliente.
5. `setComplexValue`/`setComplexBlock` só com objetos construídos pelo servidor a partir de tipos fechados (`template_variables.type`).
6. Blocos: um nível apenas, validado no upload do modelo (detectar `${x}` dentro de `${y}...${/y}` e rejeitar), com limite de clonagens por bloco (ex.: 500) para evitar DOCX gigante.
7. Conversão para PDF continua no LibreOffice isolado (`PdfConverter`), sem macros habilitadas.

---

## 2. SSO — OIDC

| Candidato                          | Versão estável                        | Licença    | Compatibilidade                                                                                        | Manutenção                             | Fonte                                                                                                                                   |
| ---------------------------------- | ------------------------------------- | ---------- | ------------------------------------------------------------------------------------------------------ | -------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| `laravel/socialite`                | v5.31.0 (2026-08-31)                  | MIT        | `illuminate/* ^13.0`, `php ^8.1`, `guzzle ^6\|^7\|^8`, `firebase/php-jwt ^6.4\|^7.0`, `phpseclib ^4.0` | oficial Laravel                        | [packagist](https://repo.packagist.org/p2/laravel/socialite.json), [docs 13.x](https://laravel.com/docs/13.x/socialite)                 |
| `socialiteproviders/manager`       | 4.10.0 (2026-08-16)                   | MIT        | `php ^8.3`, `illuminate/support ^12\|^13`, `laravel/socialite ^5.29`                                   | comunidade (mencionado na doc oficial) | [packagist](https://repo.packagist.org/p2/socialiteproviders/manager.json)                                                              |
| `socialiteproviders/openidconnect` | **1.0.0 (2026-08-16) — única versão** | MIT        | `php ^8.2`, `ext-openssl`, `firebase/php-jwt ^7.0`, `illuminate/* ^11\|^12\|^13`, `manager ^4.4`       | comunidade, pacote novo                | [packagist](https://repo.packagist.org/p2/socialiteproviders/openidconnect.json), [docs](https://socialiteproviders.com/OpenIDConnect/) |
| `jumbojett/openid-connect-php`     | v1.0.2 (2024-09-13)                   | Apache-2.0 | `php >=7.0`, `phpseclib ^3.0.7` (**conflita** com Socialite 5.31)                                      | 98 issues e 39 PRs abertos             | [packagist](https://repo.packagist.org/p2/jumbojett/openid-connect-php.json), [GitHub](https://github.com/jumbojett/OpenID-Connect-PHP) |

**Fatos relevantes**

- O Socialite nativo cobre Facebook, X, LinkedIn, Google, GitHub, GitLab, Bitbucket e Slack. Outros provedores vêm do site comunitário Socialite Providers ([docs 13.x](https://laravel.com/docs/13.x/socialite)). Tem `Socialite::fake()` para testes ([docs](https://laravel.com/docs/13.x/socialite)).
- `socialiteproviders/openidconnect` ([docs](https://socialiteproviders.com/OpenIDConnect/)):
    - usa descoberta (`base_url`/`issuer`) e cada conexão vira um driver `oidc_{connection}`;
    - valida `signature`, `iss`, `aud`, `azp`, `exp`, `nonce` e `at_hash`, com PKCE ligado por padrão;
    - guarda discovery e JWKS em cache por `cache_ttl` de 3600 s e acompanha rotação de chaves.
- As conexões desse pacote são declaradas em `config/oidc.php`. Conexões **dinâmicas por organização**, lidas da tabela `sso_connections` em tempo de execução, estão **NÃO CONFIRMADAS** na documentação — validar antes de adotar.

**Recomendação:** `laravel/socialite` + `socialiteproviders/manager` + `socialiteproviders/openidconnect`, isolados atrás de um contrato `OidcProvider` nosso.

- Motivos: é a combinação compatível com Laravel 13 e phpseclib 4, e valida `id_token` corretamente segundo a documentação.
- Risco: o pacote tem uma única versão, de agosto de 2026. Mitigações:
    - fixar `1.0.*`;
    - testes de contrato com IdP de teste, incluindo `id_token` com assinatura inválida, `aud` errado, `nonce` repetido e `exp` vencido;
    - plano B documentado: driver próprio sobre `Laravel\Socialite\Two\AbstractProvider` + `firebase/php-jwt` (já dependência do Socialite).
- `jumbojett` descartado pelo conflito de phpseclib e pela manutenção fraca.

---

## 3. SSO — SAML 2.0

| Candidato                           | Versão estável                                                          | Licença               | Compatibilidade                                              | Observação                                                                                                     | Fonte                                                                                                                                                            |
| ----------------------------------- | ----------------------------------------------------------------------- | --------------------- | ------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `onelogin/php-saml` (SAML-Toolkits) | **4.3.2 (2026-05-07)**                                                  | MIT                   | `php >=7.3`, `robrichards/xmlseclibs ^3.1.5`                 | advisory crítico GHSA-5j8p-438x-rgg5 (CVE-2025-66475, _signature wrapping_, via xmlseclibs) corrigido em 4.3.1 | [packagist](https://repo.packagist.org/p2/onelogin/php-saml.json), [advisory](https://github.com/SAML-Toolkits/php-saml/security/advisories/GHSA-5j8p-438x-rgg5) |
| `robrichards/xmlseclibs`            | 3.1.5 (2026-03-13) na linha usada; 4.0.0 (2026-08-22) exige phpseclib 3 | BSD-3-Clause          | 3.1.x: `php >= 5.4`, `ext-openssl`                           | dependência do onelogin                                                                                        | [packagist](https://repo.packagist.org/p2/robrichards/xmlseclibs.json)                                                                                           |
| `24slides/laravel-saml2`            | 2.5.2 (2026-02-15)                                                      | MIT                   | **não** aceita `illuminate ^13`                              | repositório **arquivado**; mantido agora por Scaler Tech                                                       | [packagist](https://repo.packagist.org/p2/24slides/laravel-saml2.json), [README](https://raw.githubusercontent.com/24Slides/laravel-saml2/master/README.md)      |
| `scaler-tech/laravel-saml2`         | 2.7.2 (2026-03-20)                                                      | MIT                   | `php >=8.2`, `illuminate/* ^9…^13`, `onelogin/php-saml ^4.0` | fork mantido; _tenants_ em banco, namespace `Slides\Saml2`                                                     | [packagist](https://repo.packagist.org/p2/scaler-tech/laravel-saml2.json), [GitHub](https://github.com/24Slides/laravel-saml2)                                   |
| `simplesamlphp/saml2`               | v6.3.0 (2026-08-09)                                                     | **LGPL-2.1-or-later** | `php ^8.2`, várias libs `simplesamlphp/*`                    | baixo nível; v7 em RC exige PHP ^8.5                                                                           | [packagist](https://repo.packagist.org/p2/simplesamlphp/saml2.json)                                                                                              |

**Configuração obrigatória** (README oficial do toolkit — [README](https://raw.githubusercontent.com/SAML-Toolkits/php-saml/master/README.md))

- `strict = true` em produção ("MUST be set as true").
- Algoritmos sem SHA-1.
- `wantAssertionsSigned` e/ou `wantMessagesSigned`.
- `rejectUnsolicitedResponsesWithInResponseTo = true`, que vem desligado.
- **Replay é responsabilidade da aplicação.** O toolkit expõe `getLastRequestID()`, `getLastMessageId()` e `getLastAssertionId()` para guardar IDs já processados durante a janela de validade.

**Recomendação:** `onelogin/php-saml` **≥ 4.3.2** usado diretamente atrás de um adaptador nosso (`SamlServiceProvider`), com a configuração de cada IdP vinda de `sso_connections`.

- Complementos obrigatórios: tabela ou cache de IDs de assertion consumidos (TTL = `NotOnOrAfter`) e testes negativos para assertion sem assinatura, assinatura de outro certificado, _wrapping_, `InResponseTo` divergente, replay e `Destination`/`Audience` errados.
- `scaler-tech/laravel-saml2` é alternativa aceitável se quisermos rotas e comandos prontos. Mas ele traz tabela própria de _tenants_ que duplica `sso_connections` e tem histórico curto sob o novo mantenedor (36 issues e 10 PRs abertos, segundo a página do repositório).
- `simplesamlphp/saml2` descartado: LGPL e API de baixo nível, com mais código nosso sobre XML assinado.

---

## 4. Google Drive, Dropbox e HubSpot

### 4.1 Google Drive — disponibilidade (a), com verificação do app OAuth pelo Google

- **Escopo mínimo:** `https://www.googleapis.com/auth/drive.file`, classificado como **não sensível**. Ele dá acesso por arquivo aos arquivos que o usuário "open[s] with an app or that the user shares with an app while using the Google Picker API", e aos criados pelo app. `drive` e `drive.readonly` são **restritos** e exigem _security assessment_ se os dados forem armazenados ou transmitidos por servidor ([Drive scopes](https://developers.google.com/workspace/drive/api/guides/api-specific-auth)).
- **Verificação do app:** todo app passa por _brand verification_ (homepage, política de privacidade, domínio verificado, branding e contatos). Escopos sensíveis e restritos pedem ainda vídeo de demonstração e justificativa; os restritos exigem avaliação anual de segurança ([Google Cloud Help](https://support.google.com/cloud/answer/13464321)).
- **Importar:**
    - Google Picker no front, com API key, client ID e App ID (número do projeto) — [amostra oficial](https://developers.google.com/workspace/drive/picker/guides/sample). A amostra usa `drive.metadata.readonly`; nós pediríamos `drive.file`. A página do Picker diz que, para usar `drive.file`, "the user must be signed in while accessing the Google Picker" ([overview](https://developers.google.com/workspace/drive/picker/guides/overview)).
    - Servidor: `files.get` com `alt=media` para binários. Documentos Google Workspace saem via `files.export`, limitado a 10 MB ([downloads](https://developers.google.com/workspace/drive/api/guides/manage-downloads)).
- **Exportar** (PDF final/dossiê): upload _simple_ ou _multipart_ até 5 MB e _resumable_ para arquivos maiores ([uploads](https://developers.google.com/workspace/drive/api/guides/manage-uploads)). O tamanho máximo absoluto de upload está **NÃO CONFIRMADO** nessa página.
- **SDK oficial PHP:** `google/apiclient` v2.19.4 (2026-06-29), Apache-2.0, `php ^8.1`, `guzzle ^7.4.5` ([packagist](https://repo.packagist.org/p2/google/apiclient.json)). O README declara a biblioteca "complete and … in maintenance mode" e recomenda `Google\Task\Composer::cleanup` para não carregar mais de 200 serviços ([GitHub](https://github.com/googleapis/google-api-php-client)).
- **Recomendação:** **não** instalar o SDK (Guzzle 7, §0.1). Usar `Http::` com o token OAuth obtido pelo Socialite (driver `google`, nativo) com `setScopes(['openid','email','https://www.googleapis.com/auth/drive.file'])`. Access token só em memória ou sessão durante a importação/exportação, conforme o roadmap §3.9 ("sem tokens persistentes além da importação").

### 4.2 Dropbox — disponibilidade (a)

- **SDKs oficiais:** Swift, Objective-C, Python, .NET, Java, JavaScript e HTTP. **Não há SDK oficial PHP** ([Dropbox docs](https://www.dropbox.com/developers/documentation)). `spatie/dropbox-api` 1.25.0 (2026-07-26, MIT) é comunitário e exige Guzzle 6/7 ([packagist](https://repo.packagist.org/p2/spatie/dropbox-api.json)).
- **Importar sem OAuth** com o **Chooser** ([Chooser](https://www.dropbox.com/developers/chooser)):
    - exige _app key_ e registro dos domínios;
    - opções `linkType: "direct"`, `extensions`, `multiselect` e `sizeLimit` (bytes);
    - o link direto "will expire after four hours", então o servidor baixa imediatamente. Esse download passa pela mesma proteção de saída do §6, com host fixo na lista `*.dropboxusercontent.com` — o domínio exato do link direto está **NÃO CONFIRMADO**; validar em sandbox antes de fixar a lista.
- **Exportar com OAuth:**
    - _authorization code_ + PKCE, com `code_verifier` de 43 a 128 caracteres; `token_access_type=offline` só se precisarmos de refresh token (não precisamos); tokens de longa duração foram substituídos por tokens curtos ([OAuth guide](https://developers.dropbox.com/oauth-guide));
    - nível de acesso **App Folder** ("data within its app folder only") em vez de _Full Dropbox_.
- **Escopos por rota** ([files.stone](https://raw.githubusercontent.com/dropbox/dropbox-api-spec/master/files.stone)):
    - `download` e `get_temporary_link`: `files.content.read`;
    - `upload` e `upload_session/start`: `files.content.write`.
- **Limites:** `upload` "Do not use this to upload a file larger than 150 MB"; sessões de upload valem 48 h; `get_temporary_link` expira em 4 h ([files.stone](https://raw.githubusercontent.com/dropbox/dropbox-api-spec/master/files.stone)).
- **Recomendação:** Chooser para importar e `Http::` + PKCE + App Folder + `files.content.write` para exportar. Sem SDK.

### 4.3 HubSpot — disponibilidade (a) para API/OAuth; (b) para listagem no marketplace e _app cards_ em app público

- **SDK oficial PHP:** `hubspot/api-client` 14.1.0 (2026-05-12), Apache-2.0, `php >=8.1`, `guzzle ^7.3` ([packagist](https://repo.packagist.org/p2/hubspot/api-client.json)). O README confirma que é oficial, traz _middleware_ de retry para 429/5xx e informa limite de "100 requests every 10 seconds" para apps OAuth ([GitHub](https://github.com/HubSpot/hubspot-api-php)).
- **Escopos mínimos** para "exportar PDF e criar registro" ([Scopes](https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/scopes)):
    - `oauth`, adicionado por padrão;
    - `files`;
    - `crm.objects.contacts.write` e `crm.objects.contacts.read`;
    - opcionalmente `crm.objects.deals.write` e `crm.objects.deals.read`.
    - Evitar `*.sensitive.*` e `*.highly_sensitive.*`. Escopos opcionais vão em `optional_scope`.
- **Files API v3** ([Files API](https://developers.hubspot.com/docs/api-reference/files-files-v3/guide)):
    - `POST /files/v3/files` multipart com `file`, `folderPath` ou `folderId` e `options.access`;
    - usar **`PRIVATE`**, que "requires a signed URL";
    - limites de tamanho **NÃO CONFIRMADOS** (a página remete à base de conhecimento).
- **Registro no CRM:** `POST /crm/v3/objects/notes` com `hs_attachment_ids` (IDs separados por `;`) e associação ao contato ([Notes API](https://developers.hubspot.com/docs/api-reference/latest/crm/activities/notes/guide)). O `associationTypeId` correto de nota→contato está **NÃO CONFIRMADO**: o exemplo da busca mostra 202 e o resumo cita 201; confirmar na referência antes de codificar.
- **Tokens:**
    - access token expira em 30 min desde 2021 ([changelog](https://developers.hubspot.com/changelog/upcoming-expiration-of-oauth-access-tokens-is-changing));
    - endpoints OAuth v1 estão depreciados e ficam no ar até **2027-02-16**; os novos são `POST /oauth/2026-03/token`, `/token/introspect` e `/token/revoke`, com credenciais no corpo ([changelog](https://developers.hubspot.com/changelog/v1-oauth-api-deprecation)). **Implementar direto no v3.**
- **CRM card / UI extensions:** React em _developer projects_, com `crm.record.tab` ou `crm.record.sidebar`. App público exige backend próprio para OAuth ([UI extensions](https://developers.hubspot.com/docs/platform/ui-extensions-overview), [changelog early access](https://developers.hubspot.com/changelog/ui-extensions-for-public-apps)). A disponibilidade geral atual de UI extensions em apps públicos está **NÃO CONFIRMADA**. Parte das páginas encontradas está marcada como BETA ou _early access_.
- **Recomendação:** `Http::` direto contra os endpoints v3, com o retry do próprio HTTP Client. Não instalar o SDK (Guzzle 7).

---

## 5. OCR e extração de texto com posição (âncoras, §3.2)

| Componente                     | Versão                 | Licença                           | Compatibilidade                                                                       | Fonte                                                                                                                                  |
| ------------------------------ | ---------------------- | --------------------------------- | ------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| Tesseract                      | **5.5.3** (2026-07-24) | Apache-2.0 (Leptonica BSD-2)      | CLI; saídas txt, **hOCR**, PDF, **TSV**, **ALTO**, PAGE                               | [release](https://api.github.com/repos/tesseract-ocr/tesseract/releases/latest), [GitHub](https://github.com/tesseract-ocr/tesseract)  |
| Modelo `por` (`tessdata_fast`) | —                      | Apache-2.0                        | LSTM, Tesseract 4/5                                                                   | [tessdata_fast](https://github.com/tesseract-ocr/tessdata_fast), [Data files](https://tesseract-ocr.github.io/tessdoc/Data-Files.html) |
| `pdfplumber`                   | 0.11.10 (2026-06-15)   | MIT                               | Python 3.10–3.14; fixa `pdfminer.six==20260107`, `Pillow>=12.2.0`, `pypdfium2>=5.9.0` | [PyPI](https://pypi.org/pypi/pdfplumber/json), [GitHub](https://github.com/jsvine/pdfplumber)                                          |
| `pdfminer.six`                 | 20260107               | MIT                               | Python ≥3.10 (3.13 listado)                                                           | [PyPI](https://pypi.org/pypi/pdfminer.six/json)                                                                                        |
| `pypdfium2`                    | 5.13.0                 | Apache-2.0 ou BSD-3-Clause        | render de página para PIL                                                             | [PyPI](https://pypi.org/pypi/pypdfium2/json), [README](https://raw.githubusercontent.com/pypdfium2-team/pypdfium2/main/README.md)      |
| `pypdf` (já usado)             | 6.18.0                 | BSD-3-Clause                      | Python ≥3.9 (3.13 listado)                                                            | [PyPI](https://pypi.org/pypi/pypdf/json)                                                                                               |
| `pytesseract`                  | 0.3.13 (2024-08-16)    | Apache-2.0                        | classifiers só até 3.12 (3.13 **NÃO CONFIRMADO**)                                     | [PyPI](https://pypi.org/pypi/pytesseract/json)                                                                                         |
| `PyMuPDF`                      | 1.28.2                 | **AGPL-3.0 ou comercial Artifex** | Python ≥3.10                                                                          | [PyPI](https://pypi.org/pypi/PyMuPDF/json), [Artifex](https://artifex.com/licensing)                                                   |

**Fatos relevantes**

- **Instalação:**
    - Windows: instaladores da **UB Mannheim** (build de terceiros, não do projeto oficial); atual `tesseract-ocr-w64-setup-5.5.3.20260724.exe` ([wiki UB Mannheim](https://github.com/UB-Mannheim/tesseract/wiki), [Installation](https://tesseract-ocr.github.io/tessdoc/Installation.html)). Instalação silenciosa e seleção de idioma pelo instalador estão **NÃO CONFIRMADAS**; alternativa é copiar `por.traineddata` para `tessdata`.
    - Debian/Ubuntu: `tesseract-ocr` + `tesseract-ocr-por`; as distribuições empacotam `tessdata_fast` ([Installation](https://tesseract-ocr.github.io/tessdoc/Installation.html), [Data files](https://tesseract-ocr.github.io/tessdoc/Data-Files.html)).
- **CLI:** `tesseract img.png - -l por tsv` e `... hocr` escrevem em stdout; `--psm` controla a segmentação ([Command line](https://tesseract-ocr.github.io/tessdoc/Command-Line-Usage.html)). O TSV traz caixas por palavra, que viram âncoras.
- **pypdf:** `visitor_text(text, cm, tm, font_dict, font_size)`; recomenda usar `cm`. A própria documentação avisa que "in complicated PDF documents the coordinates given to the visitor functions may be wrong" ([pypdf docs](https://pypdf.readthedocs.io/en/stable/user/extract-text.html)).
- **pdfplumber:** `page.chars` (`x0`, `x1`, `top`, `bottom`, `doctop`), `extract_words()` e `page.search()` com caixas. "Works best on machine-generated, rather than scanned, PDFs" e não faz OCR ([GitHub](https://github.com/jsvine/pdfplumber)).
- **PyMuPDF:** a Artifex afirma que não se pode "deploy our open-source as part of a server-based application or service, without disclosing your own application's full source code under AGPL" ([Artifex](https://artifex.com/licensing)). **Rejeitado.**

**Recomendação:** novo subcomando `pdftool find-anchors`.

1. PDF nativo: `pdfplumber` (`search()` com regex da âncora), normalizando as caixas para o CropBox e a rotação que o `pdftool inspect` já usa.
2. Escaneado: `pypdfium2` rasteriza (`page.render(scale=…)`), e o **binário `tesseract` roda por `subprocess`**, sem shell, com timeout e `-l por`, saída `tsv`. Não usar `pytesseract`: sem ganho sobre `subprocess` e sem classifier 3.13.
3. O caminho do binário vem de configuração (`PDFTOOL_TESSERACT_PATH`), com _fake_ nos testes do PHP quando o Tesseract não estiver instalado — hoje **não está**.
4. Adicionar a `tools/pdftool/requirements.txt`: `pdfplumber==0.11.10` (que puxa `pdfminer.six==20260107` e `pypdfium2`). Isso exige `Pillow>=12.2.0`; o `requirements.txt` hoje não fixa versão do Pillow, então conferir o `requirements.lock.txt`.
5. **ffmpeg** (§3.3, vídeo): LGPL-2.1+ por padrão, GPL se compilado com `--enable-gpl` (ex.: libx264), e nunca proprietário ([FFmpeg legal](https://ffmpeg.org/legal.html)). Não instalado localmente. Preferir **não transcodificar**: guardar o arquivo capturado como veio e validar container e duração no servidor. Se transcodificar, usar build LGPL por processo isolado.

---

## 6. Proteção SSRF para webhooks de saída (§2.16)

| Candidato                            | Versão              | Licença | Situação                                                                                                                                       | Fonte                                                                                                                                                             |
| ------------------------------------ | ------------------- | ------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `cboxdk/laravel-ssrf`                | v1.4.0 (2026-08-05) | MIT     | bloqueia faixas privadas e metadados, fixa DNS e recusa redirect, mas **exige PHP ^8.4** (incompatível com 8.3); primeira versão em 2026-07-12 | [packagist](https://repo.packagist.org/p2/cboxdk/laravel-ssrf.json)                                                                                               |
| `j0k3r/httplug-ssrf-plugin`          | v3.0.1 (2025-01-06) | MIT     | HTTPlug (não o HTTP Client do Laravel); **só IPv4** (`gethostbynamel`); com `enablePinDns()` "the SSL certificate can't be validated"          | [packagist](https://repo.packagist.org/p2/j0k3r/httplug-ssrf-plugin.json), [README](https://raw.githubusercontent.com/j0k3r/httplug-ssrf-plugin/master/README.md) |
| `fin1te/safecurl` / `j0k3r/safecurl` | —                   | —       | substitutos de `curl_exec`, fora do HTTP Client                                                                                                | [packagist fin1te](https://packagist.org/packages/fin1te/safecurl), [packagist j0k3r](https://packagist.org/packages/j0k3r/safecurl)                              |

**Recomendação: implementação própria** (`App\Integrations\Http\OutboundUrlGuard` + `PinnedHttpClient`), sem pacote.

1. **Validar a URL:**
    - só `https`, com porta 443 ou lista explícita;
    - rejeitar _userinfo_ (`user@host`), hosts sem ponto e IPs literais em formatos alternativos (decimal, octal, hex): normalizar e só aceitar hostname.
2. **Resolver antes de conectar:** `dns_get_record($host, DNS_A | DNS_AAAA)` atrás de uma interface `DnsResolver`, para ter resolvedor _fake_ nos testes. **Todos** os IPs devem ser públicos:
    - `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)`, disponível desde o PHP 8.2 e que aceita só endereços com `Global = True` na RFC 6890 ([php.net filter constants](https://www.php.net/manual/en/filter.constants.php));
    - somar lista própria em CIDR para defesa em profundidade: `100.64.0.0/10`, `169.254.0.0/16`, `fd00:ec2::254`, `64:ff9b::/96`, `::ffff:0:0/96`, `224.0.0.0/4`, `ff00::/8`, `0.0.0.0/8`. Se `FILTER_FLAG_GLOBAL_RANGE` cobre cada uma dessas faixas está **NÃO CONFIRMADO** no texto do php.net, daí a lista explícita.
3. **Fixar a conexão no IP validado** sem perder a validação TLS: manter o hostname na URL e passar `'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$ip}"], CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]` via `Http::withOptions()` ([Laravel HTTP Client — Guzzle options](https://laravel.com/docs/13.x/http-client), [php.net curl_setopt](https://www.php.net/manual/en/function.curl-setopt.php)). Assim o SNI e o certificado continuam sendo do hostname — ao contrário do _pin_ do `httplug-ssrf-plugin`, que troca o host pelo IP.
4. **Sem redirecionamento:** `'allow_redirects' => false`, e 3xx conta como falha da entrega ([Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html)).
5. **Timeouts e limite de resposta:** `connectTimeout(5)` e `timeout(10)` ([Laravel HTTP Client](https://laravel.com/docs/13.x/http-client)); `'stream' => true` para ler só os primeiros N KB da resposta; opcionalmente `on_stats` para registrar o IP efetivamente usado ([Guzzle](https://docs.guzzlephp.org/en/stable/request-options.html)).
6. **Sem proxy de ambiente:** fixar `'proxy' => ''` nas opções. O comportamento exato com `HTTPS_PROXY` definido no ambiente está **NÃO CONFIRMADO**; cobrir com teste.
7. **Revalidar a cada tentativa**, porque o DNS muda.
8. **Testes:** `Http::fake()` + `Http::preventStrayRequests()` ([Laravel HTTP Client — testing](https://laravel.com/docs/13.x/http-client)) e resolvedor _fake_ retornando `127.0.0.1`, `10.0.0.5`, `169.254.169.254`, `::1`, `::ffff:127.0.0.1` e resposta mista (um IP público e um privado).

**Atenção a Guzzle 8.** As notas da 8.0.0 removem `Client::__call()`, a opção `handler` por requisição e o acesso a `CurlMultiHandler::$_mh`, além de tornar as classes do handler cURL `final` ([release 8.0.0](https://api.github.com/repos/guzzle/guzzle/releases/tags/8.0.0)). Elas **não** listam remoção da opção `curl`, mas a documentação estável consultada é da linha 7. A continuidade de `curl`/`CURLOPT_RESOLVE` na 8.x está **NÃO CONFIRMADA** em documentação e deve ser coberta por teste de integração que conecta a um servidor local com hostname fixado (`CURLOPT_RESOLVE` apontando para `127.0.0.1` com certificado de teste).

---

## 7. Captura de foto e vídeo no navegador (§2.10 e §3.3)

- **`getUserMedia`** ([MDN getUserMedia](https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getUserMedia)):
    - só funciona em contexto seguro (HTTPS ou `localhost`) e sempre pede permissão ao usuário;
    - é governado por `Permissions-Policy` (`camera`, `microphone`);
    - em iframe precisa de `allow="camera; microphone"`, e iframe com `sandbox` só funciona com `allow-same-origin`;
    - erros a tratar na UI: `NotAllowedError`, `NotFoundError`, `NotReadableError` e `OverconstrainedError`;
    - restrições úteis: `facingMode: "user"` (selfie), `{ exact: "environment" }` (documento) e `width`/`height` com `ideal`.
- **Foto:** desenhar o quadro do `<video>` num `<canvas>` e usar `canvas.toBlob(cb, "image/jpeg", 0.85)`; o padrão é `image/png`, e `quality` vai de 0 a 1 ([MDN toBlob](https://developer.mozilla.org/en-US/docs/Web/API/HTMLCanvasElement/toBlob)). O fallback é `<input type="file" accept="image/*" capture="user|environment">`, que no desktop cai no seletor de arquivos ([MDN capture](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Attributes/capture)).
- **Vídeo com `MediaRecorder`:**
    - opções `mimeType`, `videoBitsPerSecond`, `audioBitsPerSecond` e `bitsPerSecond`; tipo não suportado lança `NotSupportedError` ([MDN constructor](https://developer.mozilla.org/en-US/docs/Web/API/MediaRecorder/MediaRecorder));
    - escolher o formato com `MediaRecorder.isTypeSupported()`, Baseline desde 2021 ([MDN isTypeSupported](https://developer.mozilla.org/en-US/docs/Web/API/MediaRecorder/isTypeSupported_static)):
        - Safari grava MP4 com H.264/AAC ([WebKit](https://webkit.org/blog/11353/mediarecorder-api/));
        - desde o Safari 18.4 grava também WebM (VP8/VP9 + Opus) e MP4 fragmentado ([WebKit Safari 18.4](https://webkit.org/blog/16574/webkit-features-in-safari-18-4/));
    - os `Blob` de `dataavailable` com `timeslice` "will not necessarily be individually playable" — reunir os pedaços antes de enviar ([MDN MediaStream Recording](https://developer.mozilla.org/en-US/docs/Web/API/MediaStream_Recording_API)).
- **Tamanho (cálculo nosso):** limitar duração no cliente, por exemplo 15 s, com `videoBitsPerSecond` de cerca de 1 Mbps. Isso dá aproximadamente 1 Mbps × 15 s ÷ 8 ≈ 1,9 MB por captura. O servidor revalida tamanho máximo, _magic bytes_ (EBML para WebM, `ftyp` para MP4) e remove EXIF das fotos com GD (já usado).
- **Impacto no projeto:** a `Permissions-Policy` atual nega tudo. As rotas públicas de assinatura com captura ligada precisam de `camera=(self)`, e só `microphone=(self)` se houver vídeo com áudio — nunca global. No widget embutido (§8), o site cliente também precisa declarar `allow="camera"` no iframe.
- **Nenhuma biblioteca npm é necessária.** São APIs nativas do navegador. Rótulos na UI: "foto capturada" e "vídeo capturado", nunca "biometria" ou "liveness" (T1 e roadmap §2.10).

---

## 8. Iframe embutido seguro (§3.9)

- **`frame-ancestors`** ([MDN frame-ancestors](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/frame-ancestors)):
    - só vale como cabeçalho HTTP, **não** em `<meta>`;
    - não herda de `default-src`;
    - aceita host-source, scheme-source e `'self'`.
- **`X-Frame-Options`:** aceita só `DENY` e `SAMEORIGIN`. `ALLOW-FROM` está obsoleto e navegadores modernos ignoram o cabeçalho inteiro se o encontrarem ([MDN XFO](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/X-Frame-Options)).
    - Nas rotas `/embed/*`: **remover `X-Frame-Options`** e emitir `frame-ancestors https://origem-autorizada` dinâmico por sessão.
    - O resto do app mantém `DENY` + `'none'`, como o `SecurityHeaders` já faz.
    - A precedência exata de `frame-ancestors` sobre XFO quando os dois estão presentes está **NÃO CONFIRMADA** nesta pesquisa; por isso a recomendação é não enviar os dois nas rotas embutidas.
- **`postMessage`:** sempre `targetOrigin` exato, nunca `*`. Quem recebe valida `event.origin`, `event.source` **e** o formato da mensagem ([MDN postMessage](https://developer.mozilla.org/en-US/docs/Web/API/Window/postMessage)). Aplicar às mensagens tipadas `assinavelox:ready|completed|refused|error`.
- **Cookies em iframe de terceiro:**
    - Safari bloqueia todos os cookies de terceiros por padrão desde o Safari 13.1 e iOS 13.4, em março de 2020 ([WebKit](https://webkit.org/blog/10218/full-third-party-cookie-blocking-and-more/)).
    - O Chrome desistiu do prompt dedicado e mantém a escolha do usuário nas configurações, então usuários podem bloquear ([Privacy Sandbox](https://privacysandbox.com/news/privacy-sandbox-next-steps/)).
    - CHIPS (`Partitioned`): Baseline desde dezembro de 2025. Exige `Secure` e recomenda o prefixo `__Host-`, com exemplo `SameSite=None; Secure; Path=/; Partitioned` ([MDN CHIPS](https://developer.mozilla.org/en-US/docs/Web/Privacy/Guides/Privacy_sandbox/Partitioned_cookies)).
    - Storage Access API: exige gesto do usuário, e em iframe _sandboxed_ precisa de `allow-storage-access-by-user-activation`; as concessões expiram após cerca de 30 dias ([MDN Storage Access](https://developer.mozilla.org/en-US/docs/Web/API/Storage_Access_API)).
    - O `config/session.php` já expõe `partitioned`, mas a configuração é **global**. Ligar `SameSite=None` na sessão do app inteiro enfraqueceria a proteção CSRF do painel.
- **Recomendação:** o widget **não depende de cookie**.
    - Fluxo: URL de uso único com token no _fragment_ → `POST` de troca → token de sessão embutida curto, mantido **em memória** e enviado em cabeçalho. Escopo só do envelope e do recipient, TTL curto, vinculado a `allowed_origin`.
    - Cookie `__Host-…; SameSite=None; Secure; Partitioned` só como otimização opcional, em _middleware_ restrito a `/embed/*`, nunca na sessão principal.
    - Nenhuma biblioteca necessária. O `embed.js` é pequeno e próprio, com testes de navegador (origem permitida e negada), conforme o roadmap §5.

---

## 9. QR, ZIP e streaming de arquivos grandes

- **QR:** manter `bacon/bacon-qr-code` v3.1.1, já instalado (BSD-2-Clause, `php ^8.1`) — [packagist](https://repo.packagist.org/p2/bacon/bacon-qr-code.json). `endroid/qr-code` 6.x exige **PHP ^8.4** ([packagist](https://repo.packagist.org/p2/endroid/qr-code.json)); a linha 5.1.0 é de 2024. **Não adotar.**
- **ZIP do dossiê (§2.13):**
    - `ext-zip` (`ZipArchive`) já está disponível, e o dossiê é gerado **em fila e armazenado** com `sha256`, então não precisa de _streaming_;
    - para reprodutibilidade ("mesmo envelope → mesmos hashes internos"), fixar a data de cada entrada com `ZipArchive::setMtimeIndex()`, disponível desde o PHP 8.0 com libzip ≥ 1.0 ([php.net](https://www.php.net/manual/en/ziparchive.setmtimeindex.php)), e ordenar as entradas;
    - com isso, os hashes **internos** ficam estáveis. Byte a byte idêntico entre execuções está **NÃO CONFIRMADO**, porque depende da versão da libzip e da compressão — testar.
- **`maennchen/zipstream-php`** 3.2.2 (2026-04-11, MIT; `php-64bit ^8.3`, `ext-mbstring`, `ext-zlib`): gera ZIP em _stream_ sem arquivo temporário, com Zip64 e fontes PSR-7 ([packagist](https://repo.packagist.org/p2/maennchen/zipstream-php.json), [README](https://raw.githubusercontent.com/maennchen/ZipStream-PHP/main/README.md)). Só se o download em lote precisar ser montado na hora. Como o roadmap pede link expirável e hash do dossiê, isso **não é necessário agora**.
- **Downloads grandes:** usar `response()->streamDownload()` ou `response()->stream()`, que reduzem o uso de memória ([Laravel responses](https://laravel.com/docs/13.x/responses)), lendo do disco privado por stream do Flysystem.
- **Requisições de saída grandes** (importar do Drive ou Dropbox): Guzzle `sink` para arquivo temporário, com limite de tamanho verificado durante a leitura ([Guzzle](https://docs.guzzlephp.org/en/stable/request-options.html)).

---

## 10. Itens NÃO CONFIRMADOS (resumo)

1. Se o PHPWord 1.4.0 corrige diretamente algum XXE. O advisory cita só `phpoffice/math` ≤ 0.2.0, corrigido na 0.3.0, que já está instalada.
2. Suporte a conexões OIDC dinâmicas por organização, em tempo de execução, no `socialiteproviders/openidconnect`.
3. Continuidade da opção `curl` e de `CURLOPT_RESOLVE` no Guzzle 8.x (a documentação consultada é da 7).
4. Cobertura exata de `FILTER_FLAG_GLOBAL_RANGE` sobre CGNAT, NAT64 e endereços de metadados; comportamento com `HTTPS_PROXY` no ambiente.
5. Precedência entre `frame-ancestors` e `X-Frame-Options` quando ambos estão presentes.
6. Tamanho máximo de upload na Drive API; limites da Files API do HubSpot; `associationTypeId` nota→contato (201 ou 202); disponibilidade geral de UI extensions em apps públicos do HubSpot; domínio exato dos links diretos do Dropbox Chooser.
7. Suporte oficial do `pytesseract` ao Python 3.13; instalação silenciosa e seleção de idioma no instalador da UB Mannheim.
8. Reprodutibilidade byte a byte do ZIP gerado por `ZipArchive`.

---

## 11. Tabela final

| pacote                                                               | versão                                   | licença                                                                           | decisão                                                                         |
| -------------------------------------------------------------------- | ---------------------------------------- | --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| `phpoffice/phpword`                                                  | 1.4.0 (instalado; lançado em 2025-06-05) | LGPL-3.0-only ([packagist](https://repo.packagist.org/p2/phpoffice/phpword.json)) | manter; escape ligado, só `.docx` sem VBA, blocos de um nível, limites de ZIP   |
| `laravel/sanctum`                                                    | 4.3.3 (instalado)                        | MIT ([packagist](https://repo.packagist.org/p2/laravel/sanctum.json))             | manter (decidido)                                                               |
| `dedoc/scramble`                                                     | 0.13.43 (instalado)                      | MIT ([packagist](https://repo.packagist.org/p2/dedoc/scramble.json))              | manter (decidido)                                                               |
| `spatie/laravel-permission`                                          | —                                        | MIT                                                                               | **não usar** (decidido: papéis próprios)                                        |
| `laravel/socialite`                                                  | 5.31.0                                   | MIT                                                                               | **adotar** (OIDC e Google para o Drive)                                         |
| `socialiteproviders/manager`                                         | 4.10.0                                   | MIT                                                                               | **adotar**                                                                      |
| `socialiteproviders/openidconnect`                                   | 1.0.0                                    | MIT                                                                               | **adotar com cautela** (fixar `1.0.*`, testes negativos, plano B próprio)       |
| `jumbojett/openid-connect-php`                                       | 1.0.2                                    | Apache-2.0                                                                        | **rejeitar** (phpseclib 3 × Socialite, manutenção)                              |
| `onelogin/php-saml`                                                  | ≥ 4.3.2                                  | MIT                                                                               | **adotar** atrás de adaptador próprio, com proteção contra replay nossa         |
| `robrichards/xmlseclibs`                                             | 3.1.5 (transitiva)                       | BSD-3-Clause                                                                      | aceitar via onelogin; monitorar advisories                                      |
| `scaler-tech/laravel-saml2`                                          | 2.7.2                                    | MIT                                                                               | alternativa (não adotar agora)                                                  |
| `24slides/laravel-saml2`                                             | 2.5.2                                    | MIT                                                                               | **rejeitar** (arquivado, sem Laravel 13)                                        |
| `simplesamlphp/saml2`                                                | 6.3.0                                    | LGPL-2.1+                                                                         | rejeitar                                                                        |
| `google/apiclient`                                                   | 2.19.4                                   | Apache-2.0                                                                        | **não usar** (Guzzle 7, modo manutenção); HTTP Client + Picker + `drive.file`   |
| `spatie/dropbox-api`                                                 | 1.25.0                                   | MIT                                                                               | **não usar** (comunitário, Guzzle 7); Chooser + HTTP Client                     |
| `hubspot/api-client`                                                 | 14.1.0                                   | Apache-2.0                                                                        | **não usar** (Guzzle 7); HTTP Client + OAuth v3                                 |
| `cboxdk/laravel-ssrf`                                                | 1.4.0                                    | MIT                                                                               | **rejeitar** (PHP ^8.4)                                                         |
| `j0k3r/httplug-ssrf-plugin`                                          | 3.0.1                                    | MIT                                                                               | rejeitar (HTTPlug, só IPv4, _pin_ sem TLS)                                      |
| `OutboundUrlGuard` próprio                                           | —                                        | —                                                                                 | **implementar** (DNS antes, faixas bloqueadas, `CURLOPT_RESOLVE`, sem redirect) |
| Tesseract (binário)                                                  | 5.5.3                                    | Apache-2.0                                                                        | adotar como processo isolado (instalar no servidor; fake em dev)                |
| `tessdata_fast` `por`                                                | —                                        | Apache-2.0                                                                        | adotar                                                                          |
| `pdfplumber`                                                         | 0.11.10                                  | MIT                                                                               | **adotar** no pdftool (âncoras em PDF nativo)                                   |
| `pdfminer.six`                                                       | 20260107                                 | MIT                                                                               | transitiva de pdfplumber                                                        |
| `pypdfium2`                                                          | 5.13.0                                   | Apache-2.0 / BSD-3                                                                | adotar (rasterização para OCR)                                                  |
| `pypdf`                                                              | 6.18.0 (instalado)                       | BSD-3-Clause                                                                      | manter; `visitor_text` só como apoio                                            |
| `pytesseract`                                                        | 0.3.13                                   | Apache-2.0                                                                        | não usar (`subprocess` direto)                                                  |
| `PyMuPDF`                                                            | 1.28.2                                   | AGPL-3.0 / comercial                                                              | **rejeitar** (AGPL em SaaS)                                                     |
| ffmpeg (binário)                                                     | —                                        | LGPL-2.1+ (GPL se `--enable-gpl`)                                                 | adiar; só build LGPL e só se a transcodificação for exigida                     |
| `bacon/bacon-qr-code`                                                | 3.1.1 (instalado)                        | BSD-2-Clause                                                                      | manter                                                                          |
| `endroid/qr-code`                                                    | 6.1.3                                    | MIT                                                                               | rejeitar (PHP ^8.4)                                                             |
| `ext-zip` (`ZipArchive`)                                             | nativo                                   | PHP License                                                                       | **adotar** para o dossiê                                                        |
| `maennchen/zipstream-php`                                            | 3.2.2                                    | MIT                                                                               | opcional, só se houver ZIP montado na hora                                      |
| APIs nativas (`getUserMedia`, `MediaRecorder`, `postMessage`, CHIPS) | —                                        | —                                                                                 | adotar; nenhuma dependência npm nova                                            |

---

## Decisão recomendada para o AssinaVelox

| Frente                               | Decisão                                                                                                                        | Justificativa / o que falta                                                                                                                                                                                                                                                                                |
| ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PHPWord `TemplateProcessor` (§2.1)   | **Implementar de verdade agora**                                                                                               | Já instalado. Os riscos (escape desligado por padrão, `.docm` com VBA, blocos aninhados, zip bomb) são mitigáveis com as regras do §1 e testes de injeção.                                                                                                                                                 |
| SSRF de webhooks (§2.16)             | **Implementar de verdade agora** (código próprio)                                                                              | Não há pacote compatível com PHP 8.3 e Laravel HTTP Client. A abordagem com DNS antes, bloqueio de faixas e `CURLOPT_RESOLVE` usa só APIs documentadas do PHP, cURL, Guzzle e Laravel. Condição: teste de integração provando que o _pin_ funciona no Guzzle 8.2.                                          |
| ZIP do dossiê, QR, streaming (§2.13) | **Implementar de verdade agora**                                                                                               | `ext-zip`, `bacon/bacon-qr-code` e `streamDownload` já estão disponíveis. Nenhuma dependência nova.                                                                                                                                                                                                        |
| Captura de foto/vídeo (§2.10, §3.3)  | **Implementar de verdade agora** (foto na Fase 2; vídeo na Fase 3, sem transcodificação)                                       | APIs nativas documentadas. Falta ajustar a `Permissions-Policy` por rota e decidir, com o jurídico, a base legal (LGPD art. 11) e a retenção.                                                                                                                                                              |
| OCR e âncoras (§3.2)                 | **Implementar contrato + fake identificado com produção desabilitada** até o Tesseract estar instalado no servidor de produção | Âncoras em PDF nativo (`pdfplumber`) podem ir de verdade já na Fase 3. O OCR depende do binário, hoje ausente, e de fixture de escaneado com meta de ≥ 90%. PyMuPDF bloqueado por AGPL.                                                                                                                    |
| SSO OIDC (§3.9)                      | **Implementar contrato + fake identificado com produção desabilitada**; ativar após testes com IdP real                        | Pacotes compatíveis existem (Socialite 5.31 + openidconnect 1.0.0), mas o provider é novo e o suporte a conexões por organização está NÃO CONFIRMADO. Faltam um IdP de teste (ex.: Keycloak local, sem Docker) e os testes negativos.                                                                      |
| SSO SAML (§3.9)                      | **Implementar contrato + fake identificado com produção desabilitada**                                                         | `onelogin/php-saml` 4.3.2 compatível e corrigido (CVE-2025-66475). Faltam IdP de teste, armazenamento de IDs contra replay e a bateria de assertions inválidas antes de ligar `enforce`.                                                                                                                   |
| Google Drive (§3.9)                  | **Contrato + fake com produção desabilitada** → real após verificação do app OAuth                                             | API (a). Falta o projeto no Google Cloud com _brand verification_ (homepage, política de privacidade, domínio verificado). Com `drive.file` não há _security assessment_.                                                                                                                                  |
| Dropbox (§3.9)                       | **Contrato + fake com produção desabilitada** → real após criar o app                                                          | API (a). Falta app key e domínios registrados (Chooser) e app com App Folder + `files.content.write`.                                                                                                                                                                                                      |
| HubSpot (§3.9)                       | **Contrato + fake com produção desabilitada**; _CRM card_ **bloqueado**                                                        | API e OAuth (a): faltam app de desenvolvedor e conta de teste; implementar direto nos endpoints OAuth `2026-03`. O card em app público (b) depende de _developer projects_ e de disponibilidade para apps públicos NÃO CONFIRMADA. Desbloqueia com confirmação na documentação e aprovação no marketplace. |
| Widget iframe (§3.9)                 | **Implementar de verdade** quando a API `v1` estiver congelada                                                                 | Tudo por padrões Web documentados (`frame-ancestors`, `postMessage`, token em memória). Não depende de fornecedor. Pré-requisito é o roadmap §3 (API `v1` congelada).                                                                                                                                      |
