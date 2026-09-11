# Integração: Assinatura eletrônica gov.br (ITI) — roadmap §3.5

> Pesquisa técnica para a Fase 3 (`docs/roadmap.md` §3.5). Data da pesquisa: 2026-09-11.
> Regras aplicadas: `roadmap.md` T1 (semântica), T4 (só com documentação oficial), `arquitetura.md` §2 e §8.
> Convenção: **[OFICIAL]** = fonte do governo/fornecedor; **[TERCEIRO]** = apoio não oficial; **NÃO CONFIRMADO** = não achei em fonte oficial.

## 1. Resumo executivo

1. **Existe uma API pública e documentada**: a "API de Assinatura Eletrônica gov.br", com OAuth2 no CAS do ITI e dois endpoints REST (`certificadoPublico`, `assinarPKCS7`). Ela assina um **hash SHA-256** e devolve um **pacote PKCS#7**.
2. **O acesso exige credenciamento, e o credenciamento é restrito**. Só podem pedir órgãos e entidades públicas, e só para sistemas que sejam **serviço público**. Para a produção ainda são exigidos: integração prévia com o Login Único e hospedagem em **domínio oficial de governo** (`gov.br`, `jus.br` etc.). Um SaaS privado como o AssinaVelox, no domínio próprio, **não é elegível** para a integração direta. Pela classificação pedida, a situação é **(b), com elegibilidade que exclui o AssinaVelox**.
3. **Fluxo alternativo possível**: o participante baixa o PDF, assina no portal `assinador.iti.br` (conta prata/ouro) e devolve o arquivo. Isso não depende de credencial nossa. O PDF assinado pode ser validado estruturalmente e criptograficamente pelo pyHanko (`pdftool validate`).
4. **Pontos em aberto do fluxo alternativo**:
   - não há fonte **do ITI** que publique a cadeia do gov.br com os nomes das ACs;
   - o validador oficial `validar.iti.gov.br` **não tem API** de validação documentada (só uso manual).

## 2. Fontes oficiais consultadas

| Tema | URL | Tipo |
|---|---|---|
| Manual de integração da API (versão atual) | https://manual-integracao-assinatura-eletronica.servicos.gov.br/pt-br/latest/iniciarintegracao.html | [OFICIAL] |
| Introdução do manual | https://manual-integracao-assinatura-eletronica.servicos.gov.br/pt-br/latest/introducao.html | [OFICIAL] |
| Fonte do manual (repositório, licença CC0-1.0) | https://github.com/servicosgovbr/manual-integracao-assinatura-eletronica (arquivo `iniciarintegracao.rst`) | [OFICIAL] |
| Serviço de integração aos produtos de identidade digital gov.br | https://www.gov.br/governodigital/pt-br/estrategias-e-governanca-digital/transformacao-digital/servico-de-integracao-aos-produtos-de-identidade-digital-gov.br | [OFICIAL] |
| Assinatura eletrônica para órgãos | https://www.gov.br/governodigital/pt-br/identidade/assinatura-eletronica/assinatura-eletronica-para-orgaos | [OFICIAL] |
| Assinatura eletrônica (cidadão) | https://www.gov.br/governodigital/pt-br/identidade/assinatura-eletronica | [OFICIAL] |
| Carta de serviço "Assinatura eletrônica" | https://www.gov.br/pt-br/servicos/assinatura-eletronica | [OFICIAL] |
| Saiba mais (natureza jurídica) | https://www.gov.br/governodigital/pt-br/identidade/assinatura-eletronica/saiba-mais-sobre-a-assinatura-eletronica | [OFICIAL] |
| Importar certificados gov.br no Adobe | https://www.gov.br/governodigital/pt-br/identidade/assinatura-eletronica/saiba-como-importar-os-certificados-do-gov-br-no-adobe-acrobat-reader | [OFICIAL] |
| Portaria SGD/MGI 7.076/2024 (regras de integração) | https://www.in.gov.br/en/web/dou/-/portaria-sgd/mgi-n-7.076-de-2-de-outubro-de-2024-%2a-589504963 (citada pelo manual; fetch do DOU falhou; conteúdo confirmado pelo manual e pela busca) | [OFICIAL] |
| Portaria SGD/MGI 11.230/2025 (níveis de conta × tipo de assinatura) | https://www.in.gov.br/en/web/dou/-/portaria-sgd/mgi-n-11.230-de-12-de-dezembro-de-2025-675511285 (citada pelo manual); texto lido em https://www.legisweb.com.br/legislacao/?id=487859 | [OFICIAL] / leitura via [TERCEIRO] |
| Lei 14.063/2020 | http://www.planalto.gov.br/ccivil_03/_ato2019-2022/2020/lei/l14063.htm (fetch falhou, conexão resetada; conteúdo confirmado pela página "Saiba mais" do gov.br) | [OFICIAL] |
| Decreto 10.543/2020 | https://www.gov.br/conarq/pt-br/legislacao-arquivistica/decretos-federais/decreto-no-10-543-de-13-de-novembro-de-2020 | [OFICIAL] |
| Validador VALIDAR (FAQ e guia do desenvolvedor) | https://validar.iti.gov.br/duvidas.html · https://validar.iti.gov.br/guia-desenvolvedor.html | [OFICIAL] |
| Repositório ITI (só ICP-Brasil) | https://www.gov.br/iti/pt-br/assuntos/repositorio | [OFICIAL] |
| pyHanko: validação | https://docs.pyhanko.eu/en/latest/lib-guide/validation/general-api.html | [OFICIAL do projeto] |

## 3. Quem pode usar a API (elegibilidade)

- **Público-alvo declarado.** O serviço de integração é para "Órgãos da administração pública, autárquica e fundacional e Empresas Públicas, da União, Estados e Municípios". Servidores municipais precisam antes da adesão do município à Rede Nacional de Governo Digital. Fonte: página do *Serviço de integração* (§2).
- **Restrição de finalidade.** Cada pedido é analisado individualmente, e o acesso é concedido "apenas para casos em que o sistema seja um SERVIÇO PÚBLICO". A mesma página nega a integração a sistemas em mercado concorrencial (exemplo dado: serviços bancários).
- **Página "Assinatura eletrônica para órgãos".** Diz: "Qualquer órgão público das esferas federal, estadual e municipal, desde que tenha normativo próprio que possibilite o uso". O pedido é feito por agente público, mediante formulário. O serviço é gratuito.
- **O próprio repositório do manual.** O README declara como público "Entidades públicas interessadas em integrar suas aplicações clientes".
- **Requisitos de produção (manual + Portaria SGD/MGI 7.076/2024, arts. 3º e 5º):**
  - integração prévia com o **Login Único** (Conta gov.br);
  - hospedagem em **domínio oficial de governo**: `gov.br`, `mil.br`, `edu.br`, `jus.br`, `leg.br`, `def.br`, `mp.br`, `tc.br`;
  - `redirect_uri` "exatamente idêntico" ao cadastrado. Divergência gera 401.
- **Âmbito da Portaria 7.076/2024.** Ela trata da integração de serviços públicos digitais "no âmbito dos órgãos da administração pública federal direta, autárquica e fundacional". O art. 13 prevê "integrações disponibilizadas a diferentes órgãos ou entidades públicas", informadas pelo órgão gestor à SGD.
- **Versões antigas do manual.** A busca indexou trechos de versões antigas (ex.: 2.3) que falavam em "gestores públicos e privados". A versão atual e a página de serviço **não** repetem isso: o texto vigente é restritivo. **NÃO CONFIRMADO** que em algum momento entes privados tenham recebido credenciais.
- **Pedido de acesso:**
  - formulário: http://solicitacao.servicos.gov.br/processos/iniciar?codServico=13119
  - e-mail de contato: `integracaoid@gestao.gov.br`
  - prazos: homologação em até 3 dias úteis; produção em até 5 dias úteis após análise sem pendências.

**Conclusão de elegibilidade.** O AssinaVelox é SaaS privado, comercial, em domínio próprio e em mercado concorrencial. Com isso, **não atende** a dois requisitos:
- **Finalidade:** o sistema precisa ser um serviço público.
- **Técnico:** o `redirect_uri` precisa estar em domínio de governo.

Existe um único cenário teórico: o AssinaVelox ser implantado para um órgão cliente, sob o domínio desse órgão e com a credencial dele. **NÃO CONFIRMADO** que a SGD aceite um produto de terceiro nesse arranjo; o texto oficial não trata do assunto. Ainda assim, isso seria um projeto por cliente, não uma funcionalidade do SaaS.

## 4. Base legal e níveis de conta

- **Natureza jurídica.** Lei 14.063/2020, art. 4º, II: assinatura eletrônica **avançada** é a que "utiliza certificados não emitidos pela ICP-Brasil ou outro meio de comprovação", desde que admitida pelas partes ou aceita por quem recebe o documento.
  - A página oficial "Saiba mais" diz textualmente que "É o caso da assinatura GOV.BR".
  - Portanto **não é qualificada** e **não é ICP-Brasil**.
- **Âmbito da lei.** A Lei 14.063/2020 regula interações com entes públicos. Entre particulares, vale a aceitação pelas partes: MP 2.200-2/2001, art. 10, §2º, referida pela página "Saiba mais".
  - A carta de serviço afirma que o documento assinado "tem a mesma validade de um documento com assinatura física", citando o Decreto 10.543/2020.
  - Esse decreto é de **âmbito federal** e trata do nível mínimo de assinatura nas interações com o ente público.
- **Níveis de conta × tipo de assinatura** (Portaria SGD/MGI 11.230/2025, publicada em 16/12/2025; revoga a Portaria SEDGG/ME 2.154/2021 e a SGD/MGI 10.864/2025):
  - **simples**: contas bronze, prata e ouro (art. 3º);
  - **avançada**: somente prata e ouro (art. 4º);
  - **qualificada**: exige certificado ICP-Brasil (MP 2.200-2), não é feita com conta gov.br (art. 5º).
- **Comportamento da API.**
  - O manual diz que conta **bronze** não assina e deve ver a mensagem "É necessário possuir conta prata ou ouro", com link para `https://confiabilidades.acesso.gov.br/`.
  - O manual lista 403 para conta bronze e para CPF cancelado, nulo ou de falecido.
- **Implicação para o AssinaVelox (T1).** O rótulo é "assinatura gov.br (avançada)". Nunca "qualificada", "ICP-Brasil" ou "assinatura digital" genérica. Em documento entre particulares, a validade depende de as partes a admitirem. Esse aceite deve constar dos termos do envelope.

## 5. Fluxo técnico da API (para referência — ver elegibilidade)

Fonte de tudo nesta seção: `iniciarintegracao.rst` / manual atual (§2).

### 5.1 Ambientes

| Recurso | Homologação (staging) | Produção |
|---|---|---|
| OAuth authorize | `https://cas.staging.iti.br/oauth2.0/authorize` | `https://cas.iti.br/oauth2.0` (base) — confirmada na versão 5.8 do manual: https://manual-integracao-assinatura-eletronica.servicos.gov.br/pt-br/5.8/iniciarintegracao.html |
| OAuth token | `https://cas.staging.iti.br/oauth2.0/token` | idem (base acima) |
| API de assinatura | `https://assinatura-api.staging.iti.br/externo/v2/` | `https://assinatura-api.iti.br/externo/v2` (versão 5.8 do manual) |
| Login Único (staging) | `https://sso.staging.acesso.gov.br/` | — |
| Validador | `https://validar.staging.iti.br`, `https://verificador.staging.iti.br/` | `https://validar.iti.gov.br` |

Os caminhos completos de produção (`/oauth2.0/authorize`, `/oauth2.0/token`) seguem o padrão do staging. A versão atual do manual só lista a base, então o caminho exato fica **NÃO CONFIRMADO** até recebermos credenciais.

### 5.2 Autorização (OAuth2 authorization code)

1. **Redirecionar o usuário** para `GET /oauth2.0/authorize` com:
   - `response_type=code`;
   - `client_id`;
   - `scope`;
   - `redirect_uri` (idêntico ao cadastrado);
   - `state`;
   - `nonce`.

   O manual **não** menciona PKCE (`code_challenge`).
2. **Escolher os escopos** (separados por espaço):
   - `sign`: autoriza **um** hash; o token vale para uma única assinatura, por até 10 min;
   - `signature_session`: autoriza vários hashes; lote de **até 100 arquivos**;
   - `sign` e `signature_session` são **mutuamente exclusivos**;
   - `govbr`: certificado avançado gov.br;
   - `icp_brasil`: certificado ICP-Brasil em nuvem de PSC. Com só `icp_brasil`, a assinatura é ICP-Brasil.
3. **Trocar o código por token:** `POST /oauth2.0/token`, `application/x-www-form-urlencoded`, com `code`, `client_id`, `client_secret`, `grant_type=authorization_code` e `redirect_uri`.
   - Resposta: `{"access_token": "<JWT>", "token_type": "bearer", "expires_in": 600}`.
   - Um `code` reutilizado ou inválido retorna 400.

### 5.3 Endpoints de assinatura

| Método | Caminho | Entrada | Saída |
|---|---|---|---|
| GET | `/externo/v2/certificadoPublico` | `Authorization: Bearer <token>` | Certificado do usuário em PEM |
| POST | `/externo/v2/assinarPKCS7` | `Authorization: Bearer <token>`, JSON `{"hashBase64": "<SHA-256 em Base64>"}` | "arquivo contendo o pacote PKCS#7 com a assinatura digital do hash SHA256-RSA e com o certificado público do usuário" |

- **Erros.** Os exemplos mostram JSON com `timestamp`, `status`, `error`, `message` e `path`; também aparece `error=invalid_request` (400).
- **Content-Type da resposta do `assinarPKCS7`:** **NÃO CONFIRMADO**.
- **Estrutura do CMS:** se é destacado (sem conteúdo encapsulado) e quais atributos assinados ele traz fica **NÃO CONFIRMADO**. Como a entrada é só o hash, o esperado é CMS destacado; isso precisa ser verificado com uma resposta real de homologação.

### 5.4 PAdES (assinatura envelopada) versus `.p7s` (destacada)

O manual descreve os dois formatos:
- **Destacada:** SHA-256 de **todo o PDF**. Resultado: PDF original + `.p7s`; os dois arquivos são necessários para validar.
- **Envelopada (PAdES):** o integrador **prepara** o PDF (atualização incremental com placeholder e `/ByteRange`, ISO 32000-1). Em seguida calcula o SHA-256 dos *byte ranges*, envia via `assinarPKCS7` e embute o PKCS#7 devolvido. O manual recomenda que a primeira assinatura use DocMDP com `P=2`, para permitir novas assinaturas e preenchimento de formulário.
- **Exemplos oficiais:**
  - PHP: `downloadFiles/exemploApiPhp.zip`, `exemploApiPhpITI.zip`;
  - Java e iText: https://kb.itextpdf.com/itext/examples.

**Consequência.** Quem monta o PAdES é o integrador. No AssinaVelox isso cai exatamente no fluxo externo de §3.4: `pdftool prepare-external` gera o digest da revisão reservada e `embed-external` embute o CMS. A capacidade do pyHanko de fazer assinatura externa/interrompida é premissa do roadmap §3.4 e **não foi revalidada nesta pesquisa**.

### 5.5 Homologação, limites e termos

- **Homologação**
  - Credenciais separadas.
  - Contas prata ou ouro de teste; tutorial oficial em `arquivos/Tutorial.pdf` no repositório do manual.
  - Em staging, o código SMS `12345` é aceito.
  - A homologação do sistema exige **vídeo** mostrando a URL do navegador em todas as etapas:
    - bloqueio de conta bronze, com a mensagem obrigatória;
    - fluxo completo com prata/ouro: login → assinatura → download → logout;
    - login pelo Login Único antes de assinar;
    - na assinatura destacada, orientação para baixar os dois arquivos.
- **Limites**
  - 100 arquivos por `signature_session`;
  - token de 600 s (10 min);
  - token `sign` de uso único.
  - Limites de taxa (rate limit) por aplicação ou usuário: **NÃO CONFIRMADO** (não documentados).
- **Termos e custos**
  - Serviço gratuito (páginas de integração).
  - Obrigações da Portaria 7.076/2024: Login Único, domínio oficial e informes de adesão pelo órgão gestor (art. 13).
  - Termo de uso próprio da API (contrato ou SLA): **NÃO CONFIRMADO**; não encontrado.
  - O manual traz uma seção de "validação PGP" com chaves dentro da validade. Sua finalidade exata no processo de credenciamento não foi aprofundada: **NÃO CONFIRMADO**.

## 6. Fluxo alternativo: o participante assina no portal e devolve o PDF

### 6.1 O que o portal faz (fontes oficiais)

- **Acesso:** `https://assinador.iti.br` ou app gov.br (Android/iOS).
- **Conta:** prata ou ouro.
- **Arquivos:** `.DOC`, `.DOCX`, `.ODT`, `.JPG`, `.PNG` e `.PDF`, com até **100 MB**. A assinatura "fica incorporada no arquivo".
- **Confirmação:** o usuário recebe um código de autorização no app e depois baixa o documento assinado. Fonte: https://www.gov.br/governodigital/pt-br/identidade/assinatura-eletronica.
- **Carimbo visual:** fontes [TERCEIRO] (ex.: https://www.portalsei.ufscar.br/duvidas-frequentes/assinaturas/como-utilizar-o-assinador-digital-iti-da-conta-gov-br, TOTVS) descrevem um carimbo visual posicionado pelo usuário, com data e link para o verificador. Detalhes do carimbo: **NÃO CONFIRMADO** em fonte oficial.
- **Estrutura técnica do PDF gerado:** **NÃO CONFIRMADO** em fonte oficial. Não foram confirmados:
  - o `SubFilter` (`adbe.pkcs7.detached` ou `ETSI.CAdES.detached`);
  - a presença de carimbo do tempo;
  - DocMDP;
  - se o portal faz atualização incremental sobre o PDF recebido ou regrava o arquivo.

  Isso deve ser medido com uma fixture real (§6.4).

### 6.2 Validador oficial (VALIDAR)

- **O que valida.** O `validar.iti.gov.br` valida assinaturas ICP-Brasil (`.p7s`, `.xml`, `.pdf`) e assinaturas avançadas gov.br (**somente `.pdf`**). As assinaturas gov.br aparecem no relatório de conformidade. Fonte: https://validar.iti.gov.br/duvidas.html.
- **Categoria (c): sem API pública de validação.** O guia do desenvolvedor (https://validar.iti.gov.br/guia-desenvolvedor.html) só especifica o formato de QR Code para prescrições de saúde (`_format=application/validador-iti+json`, `_secretCode`). Não há endpoint, autenticação nem limites para validação programática.
- **Uso no AssinaVelox:** apenas **manual**. Serve como checklist de release e para criar fixtures (T2), nunca como etapa automática do pipeline. Automatizar pela interface web seria scraping e viola T4.

### 6.3 Cadeia de confiança do gov.br

- **Não é ICP-Brasil.** Confirmado na página "Saiba mais". O repositório do ITI (https://www.gov.br/iti/pt-br/assuntos/repositorio) lista **somente** cadeias ICP-Brasil; não encontrei nele a cadeia do gov.br.
- **Onde está o download.** A página oficial "Importar certificados gov.br no Adobe" fala da "AC Raiz do gov.br". Porém o link de download aponta para um arquivo hospedado em **servidor da UFSC** (`https://arquivos.ufsc.br/f/011077a80362419997c3/?dl=1`), não do ITI. Guias de terceiros chamam o arquivo de `Cadeia_GovBr-der.p7b` (ex.: https://e.ufsc.br/como-importar-os-certificados-da-gov-br-no-adobe/ [TERCEIRO/universidade]).
- **Nomes das ACs** (raiz e intermediárias; ex.: "AC Final do Governo Federal do Brasil"): **NÃO CONFIRMADO** em fonte oficial. Também não constam do manual da API nem do repositório dele (sem arquivos `.cer`/`.p7b`).
- **Procedimento recomendado**
  - Extrair a cadeia do próprio CMS de uma assinatura real (o PKCS#7 traz o certificado do usuário; intermediárias, se houver).
  - Conferir essa cadeia contra o arquivo do link oficial e contra o resultado do VALIDAR para o mesmo PDF.
  - Registrar a raiz por **impressão digital SHA-256** em configuração versionada: `config/signature.php` → `govbr.trust_roots`, com fingerprint e data de verificação.
  - Não baixar âncora em tempo de execução.
- **Revogação (CRL/OCSP) da AC gov.br:** **NÃO CONFIRMADO**, sem documentação encontrada. De qualquer forma, o `pdftool validate` atual roda offline: `allow_fetching=False` e `revocation: not_checked` (`tools/pdftool/pdftool/validate.py`).

### 6.4 O que o pyHanko consegue validar (e o que falta)

`pdftool validate` já usa `validate_pdf_signature` com um `ValidationContext` de raízes configuráveis (https://docs.pyhanko.eu/en/latest/lib-guide/validation/general-api.html). Para cada assinatura, ele já informa:
- `intact`: os bytes cobertos não mudaram;
- `valid`: a criptografia confere;
- `trusted`: só quando há `--trust` com a raiz gov.br; sem raiz, o resultado é `no_trust_roots_configured`;
- `coverage`: `ENTIRE_FILE` ou `ENTIRE_REVISION`;
- `modification_level`, `docmdp_ok` e modificações suspeitas;
- os agregados `all_covering` e `all_docmdp_ok`.

Checagens adicionais que o AssinaVelox precisa fazer, alinhadas com o roadmap §3.5 (a)–(c):
1. **Vínculo com a revisão esperada.** A primeira revisão do PDF devolvido precisa ser byte a byte o documento que entregamos: o prefixo do arquivo até o fim da revisão original deve ter o SHA-256 igual a `expected_revision_sha256`. Isso só funciona se o portal fizer **atualização incremental** e não regravar o arquivo (**NÃO CONFIRMADO**). Se regravar, o vínculo passa a depender de comparação de conteúdo renderizado, mais fraca, e o item precisa ser reavaliado.
2. **Uma nova assinatura exatamente**, com `coverage=ENTIRE_FILE` e `modification_level` aceitável: no máximo o carimbo visual e as anotações do próprio portal, e só na revisão da assinatura.
3. **Identidade.** O certificado do signatário precisa corresponder ao participante: nome e CPF. O formato do *subject* dos certificados gov.br (onde fica o CPF) é **NÃO CONFIRMADO**. Ler de fixture real antes de codificar.
4. **Cadeia e confiança.** `trusted=true` contra a raiz gov.br fixada em §6.3. Só então `signature_status = participant_govbr`.
5. **Concorrência.** Enquanto o participante assina fora, outro participante pode avançar a base. O roadmap já prevê "reserva" de revisão com expiração (`external_signature_requests.expires_at`). No fluxo alternativo, o PDF devolvido é **a** nova revisão, e as assinaturas seguintes se empilham sobre ela. Por isso o fluxo precisa ser serializado: um participante gov.br por vez, ou ordem fixa.

## 7. Encaixe no AssinaVelox (proposta)

- **`App\Integrations\GovBrSignatureProvider`** (contrato do roadmap §3.5), com os métodos:
  - `authorizationUrl(state, nonce)`;
  - `exchangeCode(code)`;
  - `publicCertificate(token)`;
  - `signHash(token, sha256)`.

  Implementações:
  - `FakeGovBrSignatureProvider`: fake identificado, assina com certificado de teste `environment=test` e nunca é exibido como gov.br real;
  - `IticasGovBrSignatureProvider`: fica **desabilitada**, sem credencial.
- **Novo fluxo de upload** (`external_signature_requests.provider = govbr_portal`):
  - o participante baixa a revisão esperada, assina no portal e faz upload;
  - `pdftool validate --trust <raiz gov.br>` e as checagens de §6.4 aprovam ou rejeitam o arquivo;
  - só depois ele entra como nova `document_version`.
- **Eventos novos** em `audit_events`, payload minimizado, sem CPF completo:
  - `govbr_signature_requested`, `govbr_signature_uploaded`, `govbr_signature_verified`, `govbr_signature_rejected`.
- **Rótulos (T1):**
  - "Assinatura gov.br (avançada)" quando `trusted=true` e o vínculo de revisão confere;
  - caso contrário o upload é **rejeitado**; não existe estado "gov.br não verificado" exibível.
- **Testes:**
  - fixture real assinada no portal por conta de teste, validada também manualmente no VALIDAR (registro em checklist);
  - rejeição de PDF com revisão base diferente, com modificação pós-assinatura, sem assinatura, com assinatura de outra cadeia e com CPF divergente.

## 8. Itens NÃO CONFIRMADOS (consolidado)

- Aceitação, pela SGD, de SaaS privado ou de produto de terceiro implantado sob domínio de órgão cliente. O texto oficial restringe a serviço público de órgão.
- Caminhos completos de produção `https://cas.iti.br/oauth2.0/authorize|token`. Só a base de produção está confirmada.
- Content-Type e estrutura CMS (destacado, atributos assinados) da resposta de `assinarPKCS7`.
- Rate limits, SLA e termo de uso próprio da API.
- Finalidade exata da seção de validação PGP no credenciamento.
- Nomes oficiais das ACs da cadeia gov.br e fonte do ITI para download. O link oficial aponta para arquivo hospedado na UFSC.
- Existência e endereços de CRL/OCSP da AC gov.br.
- Estrutura do PDF gerado pelo `assinador.iti.br`:
  - `SubFilter`;
  - carimbo do tempo;
  - DocMDP;
  - atualização incremental ou regravação;
  - forma do carimbo visual.
- Formato do *subject* do certificado gov.br (onde aparecem nome e CPF).
- Qualquer API de validação do VALIDAR. O que foi confirmado é que **não há** API documentada.

## 9. Decisão recomendada para o AssinaVelox

| Frente | Decisão | Justificativa |
|---|---|---|
| **API de assinatura gov.br (integração direta, OAuth + `assinarPKCS7`)** | **Bloqueado** | A API existe e está documentada, mas a credencial só é concedida a órgão público para sistema de serviço público, com Login Único e `redirect_uri` em domínio oficial de governo. O AssinaVelox não é elegível. Desbloqueio exigiria: (1) um órgão público cliente que peça a credencial para um serviço público dele; (2) implantação sob domínio oficial desse órgão, com Login Único integrado; (3) confirmação por escrito da SGD (`integracaoid@gestao.gov.br`) de que um produto de terceiro nesse arranjo é aceito. Até lá, no máximo o **contrato `GovBrSignatureProvider` + fake identificado com produção desabilitada**, útil só para exercitar `prepare-external`/`embed-external` (compartilhado com o A3 de §3.4). Não criar UI de "Assinar com gov.br" via API. |
| **Fluxo alternativo (assinar no `assinador.iti.br` e devolver o PDF)** | **Implementar contrato + fake identificado agora; produção desabilitada até a fixture real** | Não depende de credencial e usa só o que já temos (pyHanko/`pdftool validate`). Antes de ligar a flag faltam: (1) uma fixture real assinada no portal com conta de teste, conferida no VALIDAR; (2) raiz gov.br fixada por fingerprint, com origem verificada (§6.3); (3) confirmação de que o portal faz atualização incremental, pré-requisito do vínculo com `expected_revision_sha256`; (4) mapeamento do CPF no certificado. Com esses quatro itens, a implementação vira real, com `signature_status = participant_govbr` e o rótulo "Assinatura gov.br (avançada)". Sem eles, a flag fica desligada (T2/T8). |
| **Validação automática pelo VALIDAR (ITI)** | **Bloqueado (não existe API)** | O VALIDAR não oferece API documentada; automatizar a interface seria scraping (T4). Usar apenas como checklist manual por release e para produzir fixtures. Desbloqueio: o ITI publicar uma API de validação. |
