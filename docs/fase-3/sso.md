# Login corporativo por OIDC e SAML 2.0 (Fase 3 §3.9 — G-SSO)

A equipe do painel pode entrar pelo **provedor de identidade da empresa** (Microsoft Entra ID, Google Workspace, Okta, Keycloak, ADFS…), por **OpenID Connect** ou **SAML 2.0**.

O login corporativo autentica o **usuário do painel**. Ele **nunca** autentica o signatário de um envelope e não muda o valor do aceite eletrônico (roadmap T1). Quem assina documentos continua no fluxo de sempre: link, código e aceite.

Classificação na viabilidade: **classe B**. O código é real e está coberto por testes contra provedores **simulados** (chaves, JWKS, certificados e respostas SAML gerados no próprio teste). O proprietário ainda não registrou um provedor de teste real. Por isso tudo nasce **desligado**, e a tela avisa que o recurso está "em homologação".

## 1. Flags

| Flag       | Onde vale                        | Desligada (padrão)                                                         |
| ---------- | -------------------------------- | -------------------------------------------------------------------------- |
| `sso_oidc` | global **e** plano (Empresarial) | rotas OIDC 404; nenhuma conexão OIDC autentica; a obrigatoriedade não vale |
| `sso_saml` | global **e** plano (Empresarial) | rotas SAML (ACS e metadata) 404; nenhuma conexão SAML autentica            |

- Liga-se com `ASSINAVELOX_FEATURE_SSO_OIDC=true` / `ASSINAVELOX_FEATURE_SSO_SAML=true` **e** `plans.features.sso_oidc` / `sso_saml = true` no plano (`App\Services\Sso\SsoFeature`).
- Na prop compartilhada `features`, **sem organização** (tela de login, cadastro) vale só o interruptor global, como `cnpj_lookup`. É isso que faz a tela de login oferecer "Entrar com SSO". O plano é conferido quando o domínio do e-mail leva à organização.
- Com as duas flags desligadas: `/sso/*` e `/configuracoes/sso*` respondem 404, a tela de login não muda, **Configurações › Geral** continua com a chave "Login único (SSO / SAML)" desabilitada da Fase 1 e o middleware de obrigatoriedade não faz nada — mesmo que exista conexão obrigatória gravada no banco.
- O seeder de planos **não** foi alterado: ligar a flag no plano Empresarial é decisão comercial do proprietário.

`config/assinavelox.php` › `sso`:

| Chave                           | Padrão                                   | Uso                                                                                   |
| ------------------------------- | ---------------------------------------- | ------------------------------------------------------------------------------------- |
| `homologated`                   | `false`                                  | `true` só depois do teste com um provedor real; tira o aviso "em homologação" da tela |
| `flow_ttl_minutes`              | 10                                       | validade do `state`/`nonce` (OIDC) e do AuthnRequest pendente (SAML)                  |
| `clock_leeway_seconds`          | 60                                       | tolerância de relógio do id_token (o SAML usa a do toolkit, 180 s)                    |
| `id_token_max_age_seconds`      | 600                                      | idade máxima do `iat`                                                                 |
| `require_at_hash`               | `false`                                  | `at_hash` é conferido sempre que vier; `true` passa a exigi-lo                        |
| `oidc_algorithms`               | RS256, RS384, RS512, PS256, ES256, ES384 | algoritmos que uma conexão pode fixar (nunca `none`, nunca HS*)                       |
| `discovery_cache_minutes`       | 60                                       | cache do discovery e do JWKS                                                          |
| `jwks_refresh_cooldown_seconds` | 60                                       | `kid` desconhecido força no máximo uma recarga do JWKS por janela                     |
| `http.*`                        | 5 s / 10 s / 512 KB                      | tempos-limite e teto de resposta das chamadas ao provedor                             |
| `saml.max_response_kb`          | 256                                      | resposta SAML maior é recusada antes do parse                                         |
| `saml.replay_margin_minutes`    | 10                                       | o ID da assertion fica guardado até NotOnOrAfter + margem                             |
| `saml.binding_cookie`           | `av_sso_saml`                            | cookie que liga o pedido SAML ao navegador que o iniciou                              |
| `domains.max_per_organization`  | 10                                       | teto de domínios                                                                      |

## 2. Pacotes

- **OIDC: adaptador próprio** sobre o HTTP Client do Laravel e `firebase/php-jwt` 7.x. O Socialite foi rejeitado porque exige Guzzle 6/7 (viabilidade §7 item 1); a fixação de IP contra SSRF foi provada no Guzzle 8.2.
- **SAML: `onelogin/php-saml` 4.3.2** (com `robrichards/xmlseclibs` 3.1.5), usado diretamente atrás do nosso adaptador (`App\Integrations\Sso\Saml\SamlAdapter`).
- Nenhum pacote novo foi instalado nesta onda.

## 3. Conexão (`sso_connections`, migration `2026_09_14_170201`)

Uma conexão **por organização** (`UNIQUE organization_id`). O domínio do e-mail leva à organização e dela à conexão, sem ambiguidade. Para trocar de protocolo, remove-se a conexão e cria-se outra.

| Coluna                                          | Uso                                                                                                       |
| ----------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| `protocol` (`oidc`\|`saml`), `name`, `status`   | `status` ∈ `draft` (em configuração), `active`, `disabled`                                                |
| `oidc_issuer`, `oidc_client_id`                 | emissor (o discovery é lido de `{issuer}/.well-known/openid-configuration`) e cliente                     |
| `oidc_client_secret`                            | **cifrado** (cast `encrypted`, APP_KEY), fora de `toArray()`; nunca em prop, log, evento, fila ou exceção |
| `oidc_id_token_alg`                             | algoritmo **fixado** pela conexão                                                                         |
| `saml_idp_entity_id`, `saml_idp_sso_url`        | entityID e URL de login (HTTP-Redirect) do IdP                                                            |
| `saml_idp_certificates`                         | JSON com até 3 certificados **públicos** de assinatura (rotação); chave privada colada é recusada         |
| `saml_metadata_url`, `saml_allow_idp_initiated` | metadata de onde se importou; IdP-initiated **desligado** por padrão                                      |
| `jit_provisioning`, `jit_role`                  | provisionamento no primeiro login; `member` por padrão, no máximo `admin`, **nunca** `owner`              |
| `enforce`                                       | login corporativo obrigatório (só vale com a conexão ativa)                                               |
| `two_factor_policy`                             | `keep` (padrão) ou `trust_idp` (§8.1)                                                                     |
| `last_test_*`, `last_login_at`                  | resultado do último "Testar conexão" e última entrada                                                     |

Regras (`App\Services\Sso\SsoConnectionManager`):

- O protocolo precisa estar ligado para a organização (flag global **e** plano).
- O emissor OIDC passa pela proteção contra SSRF **já no cadastro**: host público, https, sem IP literal e sem credenciais na URL. A proteção se repete a cada uso.
- Trocar **qualquer** dado do provedor (emissor, cliente, segredo, algoritmo, entityID, URL, certificados) faz três coisas: volta a conexão para "em configuração", desliga a obrigatoriedade e apaga o resultado do teste. Nada autentica com configuração não testada.
- **Só o owner** (revisão adversarial da onda G): conecta o provedor (cria a conexão), muda os dados do provedor, a política de 2FA (`two_factor_policy`), o papel do JIT (`jit_role`) e o login iniciado pelo provedor (`saml_allow_idp_initiated`), e **ativa** a conexão. Quem controla esses campos controla quem entra — inclusive na conta do owner, que o vínculo por e-mail alcança. O admin muda o nome, liga ou desliga o JIT, testa, verifica domínios, desativa e remove. A recusa é `owner_only` no campo, com a mensagem `SsoConnectionManager::OWNER_ONLY_MESSAGE`; a tela deixa esses campos travados para o admin.
- Emissor OIDC ou entityID SAML novo **invalida** os vínculos pelo sujeito do IdP antigo: a linha de `sso_identities` fica, mas o hash vira um marcador que nunca casa (`SsoIdentity::INVALIDATED_PREFIX`). Um sujeito igual no IdP novo é outra pessoa e não entra na conta antiga; o próximo login da pessoa certa refaz o vínculo pelo e-mail (reaproveitando a linha). O evento `sso.connection_updated` leva `identities_invalidated`.
- **Ativar** exige o último teste bem-sucedido.
- **SSO obrigatório** exige a conexão ativa e o **2FA ativo na conta do owner que liga** — é o acesso de emergência dele (`enforce_requires_two_factor`); só o owner liga ou desliga, e a tela pede confirmação ao ligar.
- Desativar ou remover a conexão é permitido a owner e admin, e a obrigatoriedade cai junto (nunca tranca). A tela diz isso ao admin e pede confirmação ao desativar com a obrigatoriedade ligada.

## 4. Domínios verificados (`sso_domains`, migration `170202`)

Os `domain_hints` do roadmap viraram tabela própria:

- O domínio só vale depois de **verificado** por um registro TXT: `_assinavelox-sso.{domínio}` com o valor `assinavelox-sso={token}`.
- A consulta é real (`App\Services\Sso\Domains\TxtRecordResolver` → `SystemTxtRecordResolver`, `dns_get_record(DNS_TXT)`), com resolvedor falso nos testes.
- `verified_domain` repete o domínio **só quando verificado** e é `UNIQUE`: um domínio verificado pertence a **uma única** organização. Outra organização pode ter a reivindicação pendente, mas nunca consegue verificá-la.
- Só entra pelo SSO quem tem e-mail de um domínio verificado da organização da conexão. Remover o domínio desliga a entrada por ele na hora.
- A re-verificação periódica do TXT não existe ainda (pendência §12).

## 5. SSO obrigatório e acesso de emergência ("break-glass")

`App\Services\Sso\Middleware\EnforceOrganizationSso` fica no grupo `web`. A lista de prioridade o põe **depois** do `org` (`bootstrap/app.php`). Ele só age em rota com organização corrente e quando existe conexão **ativa** com `enforce` e flag valendo.

- A sessão precisa ter entrado **por esta conexão**: `session('sso.authenticated')[org] === conexão`. A sessão SSO de uma organização não vale para outra.
- **Operador e administrador** sem sessão SSO vão para `sso.required` ("Esta organização exige o login corporativo"), que segue para o provedor com o e-mail como `login_hint`. Em JSON, a resposta é 403.
- **Owner** mantém o acesso por **senha + 2FA**. Cada sessão que usa esse acesso gera:
    - o evento `sso.break_glass_used` (tom de alerta);
    - uma linha `sso.break_glass_used` no log (sem dado pessoal além do id);
    - a notificação `SsoBreakGlassNotification` (sino) aos demais owners e admins.
- Owner **sem 2FA** é mandado ativar o 2FA: senha sozinha nunca basta.
- O break-glass confere o 2FA **digitado nesta sessão**, não só o 2FA ativado na conta: uma sessão aberta pelo `trust_idp` de outra organização (§8.1) volta ao desafio do Fortify antes (revisão adversarial da onda G).
- Continuam acessíveis: sair, trocar de organização, criar organização e as telas da própria conta (perfil e segurança não passam pelo `org`).
- **Nunca tranca**: desativar ou remover a conexão, desligar a obrigatoriedade ou desligar a flag suspendem a exigência na hora. Durante o "acessar como" do suporte, o middleware não age.
- Tokens da API v1 não são afetados: autenticam por token, sem sessão.

## 6. OIDC (`App\Integrations\Sso\Oidc\*`, `App\Services\Sso\OidcLoginFlow`)

### 6.1 Ida

1. **Discovery** pela proteção contra SSRF, com cache. O `issuer` publicado precisa ser **exatamente** o configurado. `token_endpoint` e `jwks_uri` também passam pela proteção; o `authorization_endpoint`, que só o navegador abre, precisa ser https.
2. Para provedor **conhecido** (Google, Microsoft, Okta, Auth0), os hosts do discovery, do JWKS e do token precisam estar na lista do provedor (`KnownOidcProviders`). Para provedor próprio, vale só a proteção contra SSRF.
3. Se o provedor anunciar `code_challenge_methods_supported`, precisa incluir `S256`. Se anunciar os algoritmos do id_token, precisa incluir o da conexão.
4. `state` (32 bytes), `nonce` (32 bytes) e `code_verifier` (48 bytes) aleatórios ficam na **sessão** (`sso.oidc.flows`, até 5 fluxos, 10 min). O `code_challenge` é o S256 do verifier.
5. A `redirect_uri` é **por conexão** (`/sso/oidc/{conexão}/retorno`), para mitigar o "mix-up" entre provedores.

### 6.2 Volta

- O `state` é **retirado** da sessão (uso único). Precisa ser desta conexão e estar no prazo. `state` trocado, repetido, vencido ou de outra conexão é recusado **antes** de qualquer chamada ao provedor.
- `error` do provedor vira só um código (`provider_error:access_denied`); a descrição não é repassada.
- `iss` na volta (RFC 9207), se vier, precisa ser o emissor.
- Troca do código com `code_verifier` e autenticação do cliente por `client_secret_basic` (form-urlencoded, RFC 6749 §2.3.1) ou `client_secret_post`, conforme o discovery.

### 6.3 Validação do id_token (`IdTokenValidator`)

1. O `alg` do cabeçalho é **exatamente** o fixado pela conexão: nunca `none`, nunca HS*. As chaves do JWKS são criadas presas a esse algoritmo e só entram chaves assimétricas (`kty` RSA/EC, `use` ≠ `enc`). Assim, a troca RS256→HS256 com a chave pública como segredo não encontra chave.
2. A assinatura é conferida pela chave do `kid`. Um `kid` desconhecido recarrega o JWKS **uma vez** por janela (rotação).
3. `iss` idêntico ao do discovery. `aud` contém o client_id; com várias audiências, `azp` é obrigatório; `azp`, se vier, é o client_id.
4. `exp` e `iat` obrigatórios, com tolerância de 60 s e idade máxima do `iat`; `nbf` respeitado. O relógio é o do Laravel.
5. `nonce` idêntico ao do fluxo (`hash_equals`).
6. `at_hash` conferido sempre que vier (metade esquerda do hash do access_token). Não é **exigido** por padrão (`require_at_hash = false`): no fluxo Authorization Code ele é opcional (OIDC Core §3.1.3.6), e o id_token vem do token endpoint por TLS, com IP fixado e autenticação do cliente. Decisão mantida na revisão adversarial; ligar `require_at_hash` é possível para provedores que sempre o enviam.
7. `sub` obrigatório (até 255 caracteres).
8. O e-mail só vale com `email_verified` igual a `true` (ou `"true"`). Ausente conta como não verificado. O domínio precisa estar entre os verificados (§4).
9. A consulta ao `userinfo` **não** é usada: o e-mail precisa vir no id_token (pendência §12).

## 7. SAML 2.0 (`App\Integrations\Sso\Saml\*`, `App\Services\Sso\SamlLoginFlow`)

Configuração do toolkit (`SamlSettingsFactory`), seguindo o README oficial:

- `strict = true`, validação do XML pelo schema e `destinationStrictlyMatches`.
- `wantAssertionsSigned = true`: a **assertion** precisa vir assinada com o certificado configurado. Response assinada sozinha não basta.
- `rejectUnsolicitedResponsesWithInResponseTo = true`.
- SHA-256.
- O SP não tem chave privada: o AuthnRequest sai sem assinatura (opcional no perfil Web SSO) e assertion cifrada é recusada.

O que é código **nosso** (o toolkit não faz):

- **Destination obrigatório e igual ao ACS**. O toolkit aceita qualquer prefixo da URL corrente.
- **Recusa de SHA-1** e de qualquer algoritmo de assinatura ou digest fora da lista (`SamlSignaturePolicy`), antes da validação. O toolkit 4.3.2 ainda aceita RSA-SHA1.
- **Replay**: o ID de cada assertion aceita vai para `sso_consumed_assertions` (hash, `UNIQUE(conexão, hash)`). A inserção é a checagem; ela é atômica. O ID fica guardado até o **maior** entre `Conditions@NotOnOrAfter` e `SubjectConfirmationData@NotOnOrAfter`, mais a margem: o toolkit aceita SubjectConfirmationData sem o atributo e confere o tempo só pelas Conditions, então guardar só pelo SCD (ou por "1 dia") deixava a assertion voltar depois do `sso:prune` (revisão adversarial da onda G). Assertion sem nenhum dos dois atributos é recusada (`saml_invalid`).
- **InResponseTo ligado ao pedido e ao navegador**. A resposta chega por POST **entre sites**, e o cookie de sessão (SameSite=Lax) não vem. Por isso:
    - o AuthnRequest emitido fica em `sso_saml_requests`, com o ID só como hash, 10 min de validade e uso único por `UPDATE … WHERE consumed_at IS NULL`. O pedido só é **gasto depois** de a assinatura e as condições conferirem (`SamlLoginFlow::consume`): uma resposta inválida com o InResponseTo certo não queima o login legítimo que vier depois;
    - o pedido é ligado ao navegador por um cookie próprio (`av_sso_saml`, `HttpOnly`, caminho `/sso/saml/`, `SameSite=None; Secure` em https). O pedido guarda o hash do valor desse cookie.
    - InResponseTo desconhecido, vencido, já usado ou vindo de outro navegador é recusado.
- **IdP-initiated** (sem InResponseTo) só com `saml_allow_idp_initiated`, desligado por padrão. Sem um pedido nosso, não há como provar que o navegador pediu o login. O replay continua valendo.
- O toolkit calcula a "URL corrente" a partir de `$_SERVER`. Durante a validação ela é fixada no ACS derivado de `APP_URL` e restaurada depois, para não depender do cabeçalho Host.
- O XML passa pelo `Utils::loadXML` do toolkit, que recusa DOCTYPE e entidades (XXE). Há teto de tamanho antes do parse.
- A leitura do e-mail tenta, nesta ordem:
    1. os atributos `email`, `mail`, `emailaddress`, a claim do WS-Fed e `urn:oid:0.9.2342.19200300.100.1.3`;
    2. o NameID, se tiver formato de e-mail.
- Com NameID transitório, o e-mail vira o sujeito do vínculo.
- O SAML não tem `email_verified`. O que liga o e-mail à organização é o domínio verificado por TXT (§4).
- O **metadata do SP** é público em `/sso/saml/{conexão}/metadata`: o entityID é a própria URL, o ACS usa HTTP-POST e o metadata declara `WantAssertionsSigned`.
- A **importação do metadata do IdP** (URL ou XML colado) usa o `IdPMetadataParser::parseXML`. A busca por URL passa pela proteção contra SSRF. O `parseRemoteXML` do toolkit **não** é usado, porque segue redirecionamentos e não valida o destino.

## 8. Sessão

| Chave                        | Conteúdo                                                                     |
| ---------------------------- | ---------------------------------------------------------------------------- |
| `sso.oidc.flows`             | fluxos OIDC pendentes por `state` (conexão, nonce, verifier, modo, validade) |
| `sso.authenticated`          | organização → conexão pela qual esta sessão entrou                           |
| `sso.pending_2fa`            | login por SSO aguardando o código do 2FA (política `keep`), 5 min            |
| `sso.break_glass`            | organizações em que o acesso de emergência já foi registrado nesta sessão    |
| `sso.two_factor_trusted_for` | organização cujo `trust_idp` dispensou o 2FA da conta nesta sessão (§8.1)    |

O login por SSO chama `Auth::login` e **regenera** a sessão (proteção contra fixação). Também grava a organização corrente.

### 8.1 Vínculo, JIT e 2FA — vale para OIDC e SAML (`App\Services\Sso\SsoLoginCompleter`)

- **Vínculo pelo sujeito** do IdP (`sso_identities`, migration `170203`). O sujeito é guardado só como SHA-256 com o id da conexão. Sem vínculo, a busca é pelo e-mail, com duas condições:
    - só vincula conta local **já confirmada** (`email_verified_at`); isso evita o sequestro de uma conta criada antes, e não confirmada, com o e-mail de outra pessoa;
    - nunca vincula conta que já esteja ligada a **outro** sujeito desta conexão (e-mail reaproveitado no IdP). A exceção é o vínculo **invalidado** pela troca de emissor/entityID (§3): ele é reaproveitado com o sujeito novo.
    - Decisão registrada (revisão adversarial): a conta de quem é owner **continua** podendo ser vinculada pelo e-mail. A proteção contra a tomada dessa conta é que só o owner muda o provedor, a política de 2FA e ativa a conexão (§3), e que a política `keep` exige o 2FA da conta dele.
- **JIT** (`jit_provisioning`):
    - sem conta, cria o usuário já confirmado, com senha aleatória que ninguém conhece;
    - sem membership, cria a membership com o papel da conexão (`member`, no máximo `admin`, **nunca** `owner`), respeitando os assentos do plano (`SeatUsage`).
- Sem JIT, conta ou membership inexistente é recusada.
- Membership **suspensa** e conta **bloqueada** nunca entram.
- Nada muda em **outra** organização: o dono de outra conta continua dono lá.
- `memberships.auth_via` (migration `170206`):
    - nulo = senha ou convite (o padrão da Fase 1);
    - `sso` = membership criada pelo JIT, ou último acesso pelo login corporativo;
    - `last_sso_login_at` guarda esse último acesso.
- **2FA do usuário**:
    - `keep` (padrão): quem ativou o 2FA na conta digita o código **depois** do provedor. O login termina no desafio do Fortify (`login.id` na sessão). Só a POST `two-factor.login.store`, para o mesmo usuário e no prazo, promove a sessão a "entrou pelo SSO". Uma tentativa de login por senha descarta o pendente.
    - `trust_idp`: a organização decide confiar no segundo fator do próprio provedor. A decisão fica gravada na conexão e no evento `sso.connection_created`/`updated`. Cada entrada registra `two_factor = trusted_idp` em `sso.login_succeeded`. Só o owner escolhe esta política (§3).
    - **A dispensa vale só para a organização da conexão** (revisão adversarial da onda G). O login é global, então a sessão guarda `sso.two_factor_trusted_for = organização`. Ao abrir **outra** organização da mesma pessoa (troca de organização), `EnforceOrganizationSso` devolve o login ao desafio do Fortify (`login.id` na sessão, o mesmo contrato do login por senha), e só o código digitado libera. O break-glass (§5) também exige o 2FA digitado nesta sessão. Todo `Login` novo limpa a marca.
    - A política "Exigir autenticação em duas etapas" da organização (`org.2fa`) continua valendo como antes. Com ela ligada, quem não tem TOTP ainda é mandado ativar.

## 9. Trilha (`audit_events`, `envelope_id` nulo — T7)

Eventos novos (lista fechada no `EnumCatalogTest`):

- `sso.connection_created`, `sso.connection_updated`, `sso.connection_deleted`, `sso.connection_tested`;
- `sso.domain_added`, `sso.domain_verified`, `sso.domain_removed`;
- `sso.login_succeeded`, `sso.login_failed`;
- `sso.user_provisioned`, `sso.identity_linked`;
- `sso.break_glass_used`.

O payload leva ULID da conexão, protocolo, **domínio** (nunca o e-mail completo), código do motivo, nomes dos campos alterados e booleanos. Nunca leva token, código, assertion, claim bruta, segredo ou certificado.

Os motivos de recusa são códigos estáveis, por exemplo:

- OIDC: `state_invalid`, `flow_expired`, `id_token_alg_not_allowed`, `id_token_signature_invalid`, `id_token_audience`, `id_token_issuer`, `id_token_expired`, `id_token_nonce`, `id_token_at_hash`, `email_not_verified`;
- SAML: `saml_unsigned_assertion`, `saml_signature_invalid`, `saml_weak_algorithm`, `saml_destination`, `saml_request_unknown`, `saml_browser_mismatch`, `saml_replay`, `saml_idp_initiated_disabled`;
- ambos: `domain_not_allowed`, `url_blocked:blocked_address`.

A mensagem ao usuário é genérica e em PT-BR (`SsoFailure::userMessage`).

## 10. Rotas, tela e contratos

| Rota (nome)                                                            | Método            | Middleware / limitador                               | Uso                                                          |
| ---------------------------------------------------------------------- | ----------------- | ---------------------------------------------------- | ------------------------------------------------------------ |
| `sso.login.start` `/sso/entrar`                                        | POST              | `throttle:sso-login`                                 | e-mail → domínio verificado → provedor (`Inertia::location`) |
| `sso.oidc.callback` `/sso/oidc/{conexão}/retorno`                      | GET               | `throttle:sso-callback`                              | volta do OIDC                                                |
| `sso.saml.acs` `/sso/saml/{conexão}/acs`                               | POST              | `throttle:sso-callback`, **sem CSRF**                | ACS (POST do IdP)                                            |
| `sso.saml.metadata` `/sso/saml/{conexão}/metadata`                     | GET               | `throttle:public`                                    | metadata do SP                                               |
| `sso.required` `/sso/obrigatorio`                                      | GET               | `auth`                                               | página `auth/sso-required`                                   |
| `sso.required.start`                                                   | POST              | `auth`, `throttle:sso-login`                         | segue para o provedor da organização                         |
| `settings.sso` `/configuracoes/sso`                                    | GET               | `auth, verified, org, org.2fa, org.role:owner,admin` | página `settings/sso`                                        |
| `settings.sso.connections.{store,update,status,test,metadata,destroy}` | POST/PATCH/DELETE | idem + `throttle:sso-settings`                       | cadastro, situação, teste, importação do metadata            |
| `settings.sso.domains.{store,verify,destroy}`                          | POST/DELETE       | idem + `throttle:sso-settings`                       | domínios                                                     |

Os limitadores são **nomeados** (`SsoServiceProvider`):

- `sso-login`: 10/min por IP e 5/min por e-mail;
- `sso-callback`: 30/min por IP;
- `sso-settings`: 30/min por usuário.

Detalhes da tela e dos ganchos:

- **Props de `settings/sso`**: `enabled {oidc, saml}`, `homologated`, `can.manage_enforce`, `can.manage_connection` (owner: provedor, 2FA, JIT, IdP-initiated, ativar), `can.two_factor_enabled` (2FA da conta de quem vê — sem ele o owner não liga a obrigatoriedade), `connection` (ou `null`), `domains[]`, `limits`, `algorithms`. O tipo está em `resources/js/components/sso/types.ts`. O client secret nunca vem; só `has_client_secret`.
- **Tela**: aviso "Em homologação" enquanto `homologated = false`. Sem conexão, a tela diz "Ainda não disponível para esta organização: nenhum provedor de identidade foi conectado". A tela também tem:
    - o card da conexão (situação, último teste, "Testar conexão", ativar/desativar, remover);
    - os dados para cadastrar no provedor: redirect URI, ou entityID/ACS do SP;
    - os domínios, com a instrução do TXT;
    - o formulário do provedor, com JIT e política de 2FA;
    - a importação do metadata;
    - a obrigatoriedade.
- **"Testar conexão"** faz o fluxo completo (assinatura, emissor, audiência, validade, domínio) e **não** entra, não vincula e não cria nada: só grava `last_test_*` e o evento `sso.connection_tested`.
- **Tela de login**: `<SsoLoginEntry />` (`resources/js/components/sso/sso-login-entry.tsx`) aparece só com `features.sso_oidc || features.sso_saml`.
- **Configurações › Geral**: com a flag, a linha "Login único (SSO / SAML)" vira `<SsoSecurityRow />`, com o botão "Configurar". Sem a flag, continua a chave desabilitada.
- **`features`** ganhou `sso_oidc` e `sso_saml` (`HandleInertiaRequests`, `resources/js/types/index.ts`).

## 11. Testes (`tests/Feature/Phase3/Sso`)

IdP **simulado** gerado no teste:

- chaves RSA e EC e certificado autoassinado (no Windows, com o `openssl.cnf` do pacote do PHP);
- discovery, JWKS e token por `Http::fake` + `Http::preventStrayRequests`;
- id_token assinado com php-jwt;
- respostas SAML assinadas com xmlseclibs;
- DNS (A/AAAA e TXT) por resolvedores falsos.

| Arquivo           | Cobre                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `OidcLoginTest`   | ida com PKCE/state/nonce, ES256, assinatura inválida, `alg none`, RS256→HS256, `aud`/`azp`/`iss`, expirado, sem `exp`, nonce trocado e reutilizado, state reutilizado/trocado/de outra conexão/vencido, `at_hash` errado e certo, `email_verified` falso/"false"/ausente, domínio não verificado, rotação de chave, `kid` desconhecido, discovery/JWKS/token em endereço interno, issuer do discovery divergente, erro do provedor, JIT (member, admin, nunca owner, sem JIT), dono de outra organização, conta não confirmada, sujeito diferente, suspenso/bloqueado, conexão desativada, 2FA `keep`/`trust_idp` |
| `SamlLoginTest`   | SP-initiated completo, AuthnRequest, metadata do SP, assertion sem assinatura, outro certificado, Response assinada sem assertion assinada, assinatura só na Response com assertion trocada, conteúdo alterado, dois tipos de wrapping (XSW), replay, pedido reutilizado, InResponseTo desconhecido, outro navegador, pedido vencido, Destination errado e prefixo, SHA-1, IdP-initiated desligado/ligado, Audience, Issuer, Recipient, NotOnOrAfter, domínio, NameID transitório, XML inválido, XXE                                                                                                              |
| `EnforcementTest` | membro mandado ao SSO (e 403 em JSON), liberado depois do SSO, sair acessível, break-glass com 2FA (trilha uma vez e alerta aos admins), owner sem 2FA, desativar/flag desligada/sem obrigatoriedade nunca trancam, sessão de outra organização não vale, só owner muda a obrigatoriedade, obrigatoriedade exige conexão ativa, admin desativa e a obrigatoriedade cai                                                                                                                                                                                                                                            |
| `SettingsTest`    | acesso owner/admin, aviso honesto, segredo cifrado e ausente das props e da trilha, emissor interno/IP literal/localhost bloqueado no cadastro, JIT owner recusado, HS256/none recusados, troca de dado do provedor exige novo teste, ativar exige teste, teste OIDC sem login, falha de teste por SSRF, SAML com certificado (chave privada recusada), importação do metadata e bloqueio de URL interna, verificação TXT, domínio único entre organizações, domínios inválidos, remoção, mensagens honestas na tela de login                                                                                     |
| `FlagOffTest`     | chaves `false`, só o global liga a entrada no login, 404 em todas as rotas, plano sem a flag, obrigatoriedade inerte, tela Geral inalterada                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |

Também foram atualizados, só com acréscimos: `SharedPropsTest` (chaves `sso_oidc`/`sso_saml` = `false`), `AllGetRoutesTest` (4 GET novas, 404 com a flag desligada) e `EnumCatalogTest` (12 eventos).

## 12. O que falta para ligar em produção (proprietário)

1. **Provedor de identidade de teste real** para OIDC e SAML, por exemplo um tenant de desenvolvedor do Entra ID, Okta ou Google Workspace, ou um Keycloak próprio. Com ele:
    - rodar o fluxo completo nos dois protocolos, incluindo a rotação de chave/certificado;
    - ligar `ASSINAVELOX_SSO_HOMOLOGATED=true`.
2. **Política de break-glass** aprovada: quem são os owners de emergência, exigência de 2FA e revisão dos alertas `sso.break_glass_used`.
3. Ligar as flags globais e `plans.features.sso_oidc` / `sso_saml` no plano Empresarial (decisão comercial).
4. HTTPS em produção. O cookie de ligação do SAML precisa de `SameSite=None; Secure`, e o IdP precisa alcançar `APP_URL`.
5. Agendador rodando (`sso:prune` diário). Ele limpa pedidos SAML vencidos e IDs de assertion expirados.
6. Pendências de engenharia conhecidas, fora do escopo desta onda:
    - re-verificação periódica do TXT dos domínios;
    - SLO (logout único);
    - assertion SAML cifrada (exigiria chave privada do SP, guardada cifrada);
    - AuthnRequest assinado;
    - consulta ao `userinfo` quando o provedor não põe o e-mail no id_token;
    - emissor "multi-tenant" (ex.: `login.microsoftonline.com/common`): o discovery devolve um `issuer` com `{tenantid}` e é recusado pela comparação exata — hoje é preciso usar o emissor do tenant da empresa;
    - SCIM (desprovisionamento automático);
    - categoria "Login corporativo" no registro de atividades da organização (`AdminEventCatalog`, área de outro agente);
    - item no menu de Configurações (`resources/js/layouts/settings/layout.tsx`, fora desta área). Hoje se chega pela linha da tela Geral.
