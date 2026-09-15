# Fase 3 — entrega consolidada (parte 1, onda F e onda G)

> Fechamento da Fase 3 (integração I-3G, 15/09/2026). Consolida `parte-1-relatorio.md` (ondas E e
> H, commit `f52db87`), `onda-f-relatorio.md` (commit `4bda1fd`) e `onda-g-relatorio.md` (esta
> integração, **sem commit**). Classificação conforme `docs/fases-2-3-viabilidade.md` §0:
> **A** = implementado de verdade; **B** = código real testado contra provedor simulado ou
> contrato + simulador identificado, produção desabilitada até o proprietário fornecer o que falta;
> **C** = bloqueado, só documentado. Nada que depende de simulador está marcado como pronto.

## 1. O que existe, por item do roadmap

| Item (roadmap)                                   | Parte | Classe | Estado real                                                                                                                                                    | Flag(s)                                                                   | Detalhe                                   |
| ------------------------------------------------ | ----- | ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- | ----------------------------------------- |
| §3.1 Geração documental em lote                  | F     | A      | Implementado (planilha CSV/XLSX segura, pré-validação, cota reservada, nunca envio automático com sugestão pendente)                                           | `bulk_generation` (exige `templates`)                                     | `geracao-em-lote.md`                      |
| §3.2 Âncoras de campo e OCR                      | F     | A + B  | Âncoras: Implementado (só **sugere**). OCR: código real; exige Tesseract no servidor — meta de ≥ 90 % **não medida**                                           | `field_anchors`, `ocr`                                                    | `ancoras-e-ocr.md`                        |
| §3.3 Etapas condicionais, delegação, multilíngue | F     | A      | Implementado (motor declarativo fechado; delegação auditada; pt_BR/en/es na página pública e e-mails — textos jurídicos em en/es **sem revisão profissional**) | `conditional_steps`, `delegation`, `multilingual`                         | `etapas-e-delegacao.md`, `multilingue.md` |
| §3.3 Vídeo curto no aceite                       | F     | A      | Implementado, **flag desligada até a decisão jurídica** (LGPD)                                                                                                 | `identity_video`                                                          | `captura-de-video.md`                     |
| §3.4 A3 por componente local                     | E     | B      | Contrato + **simulador** de componente local (certificado de teste); `NexuLocalSigner::PRODUCTION_ENABLED = false`                                             | `a3_signing` (+ `external_signing.enabled`)                               | `assinatura-externa-a3.md`                |
| §3.5 Devolução de PDF assinado no gov.br         | E     | A + B  | Fluxo e finalização integrados; sem fixture real do `assinador.iti.br` (conta prata/ouro)                                                                      | `govbr_return` (+ `govbr.return_enabled` e `govbr.finalizer_integration`) | `gov-br.md`                               |
| §3.5 API direta de assinatura gov.br             | G     | **C**  | Só documentação (órgão público cliente + aceite da SGD)                                                                                                        | —                                                                         | `trilha-bloqueada.md` §1                  |
| §3.6 PAdES de longo prazo                        | E     | A + C  | Código (LTV, re-carimbo) implementado e testado; produção depende da TSA da operadora e de rede até as ACs; nunca anunciado como ICP-Brasil                    | `pades_ltv`, `pades_ltv_advertise`                                        | `longo-prazo.md`                          |
| §3.7 Antifraude                                  | H     | A      | Implementado (regras com limiares, fila de revisão, modo observação)                                                                                           | `antifraud`                                                               | `antifraude.md`                           |
| §3.8 e-Notariado                                 | G     | **C**  | Só documentação (resposta formal do CNB-CF)                                                                                                                    | —                                                                         | `trilha-bloqueada.md` §2                  |
| §3.9 Widget iframe + `embed.js`                  | G     | A      | Implementado (sessão restrita pela API v1, sem cookie de terceiro, CSP só em `/embed/*`, confirmação visual anti-clickjacking)                                 | `embedded_signing` (exige `api_integrations`)                             | `widget-embutido.md`                      |
| §3.9 SDKs PHP, Node, Python                      | G     | A      | Gerados da OpenAPI por gerador próprio; testados contra servidor falso; **não publicados**                                                                     | —                                                                         | `sdks.md`                                 |
| §3.9 SSO OIDC e SAML                             | G     | B      | Código real (adaptador OIDC próprio, onelogin/php-saml), testado contra IdP simulado; "Em homologação" até o teste com IdP real                                | `sso_oidc`, `sso_saml`                                                    | `sso.md`                                  |
| §3.9 Google Drive e Dropbox                      | G     | B      | Código real, testado com `Http::fake`; sem apps registrados a tela diz "Aguardando app registrado pelo proprietário"                                           | `cloud_import`                                                            | `conectores.md` §3–§4                     |
| §3.9 App HubSpot (OAuth + ação de workflow)      | G     | B      | Código real, testado com `Http::fake`; sem app de desenvolvedor                                                                                                | `hubspot`                                                                 | `conectores.md` §5                        |
| §3.9 Cartão de CRM do HubSpot                    | G     | **C**  | Só documentação (disponibilidade para apps públicos)                                                                                                           | —                                                                         | `trilha-bloqueada.md` §3                  |
| §3.10 Afiliados                                  | H     | A      | Implementado (atribuição, comissão, repasse com dados de teste); tratamento tributário pendente                                                                | `affiliates`                                                              | `afiliados.md`                            |

A conferência de que nenhum código finge os serviços da classe C está em `trilha-bloqueada.md` §4.

## 2. Regras transversais mantidas

- **T1:** o login por SSO autentica o **usuário do painel**, nunca o signatário (a ponta a ponta
  da onda G confere que a sessão de quem entrou por SSO não abre o widget). O widget não muda o
  valor do aceite: mesmas regras e mesma trilha do fluxo público, com `channel: embedded`. A3 e
  gov.br aparecem cada um com o próprio rótulo; o teste de vocabulário continua verde.
- **T4:** nenhum endpoint inventado. Classe C sem código; classe B com provedor simulado
  identificado e produção desligada.
- **T7:** trilha só por acréscimo; eventos novos no fim de `AuditEventType`, com rótulo.
- **T8:** tudo nasce desligado, global **e** por plano; com as flags desligadas a suíte inteira passa
  sem mudança de asserção (exceto as listas fechadas de chaves e eventos, que só crescem).
- **T10 / segredos:** client secret de SSO, tokens OAuth e refresh token do HubSpot cifrados em
  repouso; token do Google só na sessão, por até 10 min, revogado ao terminar; token da sessão
  embutida só por digest; nada disso em log, trilha, fila, exceção, resposta ou argv.
- **SSRF:** discovery/JWKS OIDC, metadata SAML, Google, Dropbox e HubSpot passam pela mesma proteção
  dos webhooks de saída (resolução, classificação de IP, pino de IP, sem redirecionamento), com
  lista de hosts por provedor.

## 3. Flags e como ligá-las

Duas camadas: o interruptor global (`.env`) **e**, para as flags de organização, a chave em
`plans.features` do plano vigente. A flag só liga a interface; a autorização continua nas Policies.

| Flag                                                                  | Camada                                                                  | Parte | Condição antes de ligar em produção                                            |
| --------------------------------------------------------------------- | ----------------------------------------------------------------------- | ----- | ------------------------------------------------------------------------------ |
| `a3_signing`                                                          | `ASSINAVELOX_EXTERNAL_SIGNING_*` (global) E plano                       | E     | Componente local escolhido e piloto com tokens reais (§5)                      |
| `govbr_return`                                                        | `govbr.return_enabled` E `govbr.finalizer_integration` (global) E plano | E     | Fixture real do portal, raiz gov.br fixada, cláusula jurídica                  |
| `pades_ltv`, `pades_ltv_advertise`                                    | global                                                                  | E     | TSA da operadora em produção; rede até as ACs; validação externa               |
| `antifraud`, `affiliates`                                             | global                                                                  | H     | Base legal/RIPD e calibração (antifraude); tratamento tributário (afiliados)   |
| `bulk_generation`, `field_anchors`, `conditional_steps`, `delegation` | global E plano                                                          | F     | Workers das filas; política de delegação nos termos                            |
| `ocr`                                                                 | global E plano (exige `field_anchors`)                                  | F     | Tesseract 5.5 com `por.traineddata`                                            |
| `identity_video`                                                      | global E plano                                                          | F     | Decisão jurídica (LGPD art. 11)                                                |
| `multilingual`                                                        | global E plano                                                          | F     | Revisão jurídica de en/es; `ASSINAVELOX_MULTILINGUAL_REVIEWED`                 |
| `embedded_signing`                                                    | global E plano (exige `api_integrations`)                               | G     | HTTPS; validação manual por navegador; revisão do texto da confirmação         |
| `sso_oidc`, `sso_saml`                                                | global E plano (tela de login: só o global)                             | G     | IdP real testado → `ASSINAVELOX_SSO_HOMOLOGATED=true`; política de break-glass |
| `cloud_import`                                                        | global E plano                                                          | G     | Apps do Google e do Dropbox registrados (`GOOGLE_DRIVE_*`, `DROPBOX_APP_KEY`)  |
| `hubspot`                                                             | global E plano                                                          | G     | App de desenvolvedor HubSpot (`HUBSPOT_*`) e propriedade `assinavelox_status`  |

`.env.example` lista as chaves da onda G (desligadas, credenciais vazias); as das partes anteriores
da Fase 3 ainda não estão lá (pendência de engenharia, §7). Os dados de demonstração ligam as flags
de plano na **Horizonte** e as deixam desligadas na **Vega**.

## 4. Números (verificação real)

| Verificação                                      | Parte 1 (`f52db87`) | Onda F (`4bda1fd`)      | Onda G (esta integração)                                                     |
| ------------------------------------------------ | ------------------- | ----------------------- | ---------------------------------------------------------------------------- |
| Unit+Feature                                     | 2.136 / 2.136       | 2.386 verdes (em série) | **2.655 / 2.655**, 25.902 asserções (8 min em paralelo)                      |
| Navegador (sozinho, em série)                    | 36 + 3 pulados      | 38 + 3 pulados          | **46 testes — 43 passaram, 3 pulados** (os mesmos 3 de antes), 897 asserções |
| pdftool (`pytest`)                               | 182                 | 239                     | **239 passaram**                                                             |
| SDKs (PHP / Node / Python contra servidor falso) | —                   | —                       | 92 / 46 / 50 (via `SdkSuitesTest`, 12 testes Pest)                           |
| PHPStan                                          | 0 erros             | 0 erros                 | 0 erros                                                                      |
| Ponta a ponta da parte                           | `Phase3PartOneTest` | `Phase3WaveFTest` 2/2   | `Phase3WaveGTest` 2/2 + navegador 1/1                                        |

**Depois da revisão adversarial da onda G** (31 achados corrigidos — `onda-g-relatorio.md` §10):
Unit+Feature **2.685 / 2.685**, 26.075 asserções (paralelo, 6,3 min); navegador **46 testes — 43
passaram, 3 pulados**, 897 asserções; pdftool **239**; SDKs PHP ok / Node **46** / Python **50**, mais
os testes de revisão dos SDKs (Node 1/1, Python 2/2); `tests/Feature/Review/Phase3G` **30 / 30**;
PHPStan 0 erros; Pint, `types:check`, `check` e `build` passam.

## 5. O que o proprietário precisa fornecer (lista consolidada da Fase 3)

### 5.1 Contas, apps e credenciais de terceiros

1. **Google Cloud:** projeto com marca verificada, escopo `drive.file`, retorno
   `/integracoes/nuvem/google/retorno` → `GOOGLE_DRIVE_CLIENT_ID`, `_CLIENT_SECRET`, `_API_KEY`, `_APP_ID`.
2. **Dropbox:** app com os domínios do Chooser → `DROPBOX_APP_KEY`.
3. **HubSpot:** app de desenvolvedor + conta de teste, retorno `/api-integracoes/hubspot/retorno`,
   ação em `/webhooks/hubspot/acao` → `HUBSPOT_CLIENT_ID`, `_CLIENT_SECRET`, `_APP_ID`; propriedade
   `assinavelox_status` em negócios e contatos.
4. **IdP de teste real** para OIDC e SAML (depois dele, `ASSINAVELOX_SSO_HOMOLOGATED=true`).
5. **gov.br:** conta prata/ouro para a fixture real do `assinador.iti.br`.
6. **A3:** escolha do componente local (NexU com parecer da EUPL-1.2, Assinador Serpro ou
   Lacuna/BRy) e piloto com pelo menos dois modelos de token.
7. **SDKs:** decidir publicar; contas no Packagist, npm e PyPI; nomes, licença e CI com tokens.
8. **Classe C:** órgão público cliente + aceite da SGD (API gov.br direta); resposta formal do CNB-CF
   (e-Notariado); confirmação do HubSpot sobre cartão de CRM em app público.

### 5.2 Infraestrutura

- HTTPS em produção (widget; cookie SAML `SameSite=None; Secure`).
- Workers das filas (`bulk_generation`, `anchors`, `ocr`, HubSpot) e agendador (`sso:prune`,
  `affiliates:settle`, rotinas de LTV).
- Tesseract 5.5 com `por.traineddata`; LibreOffice para modelos DOCX.
- TSA da operadora em produção (HSM/KMS, NTP, OID, AC interna) e rede de saída até as ACs.
- Pino de IP em HTTPS: rodar `PinnedConnectionTest`/`HttpsPinTest` no ambiente-alvo e um teste de
  fumaça com endpoint https real.

### 5.3 Decisões jurídicas

- Vídeo curto (base legal, RIPD, retenção); antifraude (legítimo interesse, RIPD).
- Revisão profissional dos textos jurídicos em inglês e espanhol.
- Texto da confirmação do widget e a menção ao site integrado nas evidências.
- Política de delegação nos termos; cláusula de aceitação da devolução gov.br.
- Tratamento tributário e contratual dos repasses de afiliados.

### 5.4 Decisões de produto

- Quais planos recebem cada flag (hoje: todas na Horizonte/Profissional de demonstração; o
  `PlanSeeder` não foi alterado).
- Política de break-glass do SSO (quem são os owners de emergência e quem revisa os alertas).
- Publicar ou não em webhooks `recipient.delegated` e `envelope.step_skipped`.
- Limites de lote por plano; cota de armazenamento de vídeo; idioma padrão por organização.
- Monitorar ou não os erros `timeout`/`frame_too_small` do widget.

## 6. Riscos

- **Simulador tomado por pronto:** mitigado — SSO mostra "Em homologação", conectores mostram
  "Aguardando app registrado pelo proprietário", o simulador de nuvem é recusado em produção e
  marcado `simulated`, e o A3 tem trava de produção no código.
- **Clickjacking no widget:** confirmação visual com IntersectionObserver v2 no Chromium; no Firefox
  e no Safari vale uma heurística mais fraca (não detecta sobreposição) — documentado.
- **Pontos NÃO CONFIRMADOS nos conectores** (domínio do link do Dropbox, hosts do Picker/Chooser,
  `hub_id`, PKCE no HubSpot): validar no primeiro teste com os apps reais antes de ligar.
- **Refresh token do HubSpot persistido** (cifrado): necessário para atualizar o negócio sem ninguém
  logado — exceção documentada.
- **Textos jurídicos em en/es sem revisão**, e cartões da parte 1 ainda só em PT-BR: não ligar
  `multilingual` junto com A3/gov.br/presencial antes de traduzi-los.
- **Suíte longa:** Unit+Feature passa de 10 minutos por chamada (muitos testes abrem o pdftool); o
  navegador precisa rodar sozinho e em série.

## 7. Pendências de engenharia que continuam abertas

- Item "Login único" no menu de Configurações e categoria "Login corporativo" no registro de
  atividades (hoje se chega pela linha da tela Geral).
- Tecla Enter no campo "E-mail corporativo" não envia.
- Especificação OpenAPI descreve os erros como `{message}` em vez de RFC 9457 (`sdks.md` §4.3).
- SSO fora do escopo: re-verificação periódica do TXT, SLO, assertion cifrada, AuthnRequest
  assinado, `userinfo`, emissor `common` da Microsoft, SCIM.
- Da onda F: e-mail do pedido de delegação ao
  remetente, `<html lang>` no Blade, teste de navegador com câmera falsa.
- Da parte 1: integração do `LtvSigner` na finalização e agendamento; painel de conclusão do
  comprovante durante assinatura com token pendente.

## 8. Como rodar tudo

```bash
php artisan test --testsuite=Unit,Feature --parallel      # > 10 min; rode em primeiro plano e espere
php artisan test --testsuite=Browser                      # SOZINHO e em série, sem outra suíte
tools/pdftool/.venv/Scripts/python.exe -m pytest -q tools/pdftool   # (Linux: .venv/bin/python)
tools/pdftool/.venv/Scripts/python.exe tools/sdkgen/sdkgen.py all   # regenera spec + SDKs se a API mudar
vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress --memory-limit=2G
npm run types:check && npm run check && npm run build
php artisan wayfinder:generate --with-form
# Banco de QA próprio (apague ao terminar):
touch database/qa.sqlite && DB_CONNECTION=sqlite DB_DATABASE=database/qa.sqlite php artisan migrate:fresh --seed
```

Pontas a ponta de cada parte: `tests/Feature/EndToEnd/Phase3PartOneTest.php`,
`Phase3WaveFTest.php`, `Phase3WaveGTest.php` e `tests/Browser/Phase3WaveGTest.php`.
