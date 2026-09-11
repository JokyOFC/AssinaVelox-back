# Certificado A3 por componente local — brief de integração (roadmap §3.4)

Data da pesquisa: 2026-09-11. Escopo: avaliar o NexU (originalmente da Nowina) como componente local para o participante assinar com certificado A3 (token/cartão, chave não exportável), como encaixá-lo no fluxo "hash no servidor → assinatura no navegador via componente local → montagem PAdES no servidor" com o `tools/pdftool` (pyHanko 0.37), e comparar com as alternativas usadas no Brasil. Cada fato traz a URL de origem entre colchetes. O que não pôde ser confirmado em fonte oficial está marcado como **NÃO CONFIRMADO**. Fonte de terceiro aparece marcada como **[terceiro]**.

Vocabulário (conforme `arquitetura.md` §2 e roadmap T1): o que este documento descreve é a **assinatura criptográfica do participante** com certificado A3. Isso é diferente de representação visual, de aceite eletrônico e da assinatura `company_a1` da operadora. Enquanto a cadeia não for validada contra as âncoras ICP-Brasil, o rótulo deve dizer o que realmente foi verificado. Nunca usar "assinatura digital" genérica.

---

## 1. Resumo executivo

| Pergunta                                         | Resposta curta                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| O repositório oficial da Nowina existe?          | **Não está acessível.** `github.com/nowina-solutions/nexu` e `api.github.com/repos/nowina-solutions/nexu` respondem 404, e a organização `nowina-solutions` lista hoje só `xmlrpc` e `ESPD-Service`. A página do produto `nowina.lu/solutions/java-less-browser-signing-nexu/` também responde 404. A data e o motivo da remoção estão **NÃO CONFIRMADOS**.                                                                                                                                                                           |
| Existe versão mantida?                           | Existe **um fork comunitário mantido por uma pessoa física** (`p4535992/nexu`, EUPL-1.2, v1.25.0 de 2026-07-31, Java 21, Windows e Linux). Não é da Nowina e não tem suporte comercial.                                                                                                                                                                                                                                                                                                          |
| API local                                        | HTTP `127.0.0.1:9795` / HTTPS `:9895`. Endpoints modernos: `GET /v1/status`, `POST /v1/signing-certificate`, `POST /v1/sign`, que assina um **digest pré-calculado**. Endpoints legados: `/rest/*`. Confirmado no código-fonte do fork.                                                                                                                                                                                                                                                                                  |
| pyHanko monta o CMS a partir de assinatura bruta? | **Sim, documentado.** Os passos são `ExternalSigner` + `signed_attrs()` → assinatura remota sobre `signed_attrs.dump()` → `async_sign_prescribed_attributes()` → `PdfTBSDocument.async_finish_signing()`.                                                                                                                                                                                                                                                                                                 |
| Alternativas no Brasil                           | Lacuna Web PKI (**comercial**, licença por domínio, sem preço público). BRy "Assinatura Digital no Navegador" (extensão + módulo nativo, "All Rights Reserved"; licenciamento para integradores **NÃO CONFIRMADO**). Assinador Serpro (desktop **gratuito**, integração por WebSocket; licença e condições de uso por terceiros **NÃO CONFIRMADAS**).                                                                                                                                                                         |
| Decisão                                          | Implementar de verdade agora o **lado servidor** (`prepare-external` / `embed-external`), testável com um assinador local simulado. O **componente local** entra como contrato + fake identificado, com produção desabilitada, até que um piloto com token real e a escolha do componente sejam feitos (§8).                                                                                                                                                                                                                  |

---

## 2. Por que o navegador e o Laravel não acessam o token diretamente

### 2.1 Navegador

- A Web Cryptography API do W3C declara fora de escopo o acesso a smart cards. Em §4.3 ("Out of scope"): "This API, while allowing applications to generate, retrieve, and manipulate keying material, does not specifically address the provisioning of keys in particular types of key storage, such as secure elements or smart cards." A especificação também "does not deal with or address the discovery of cryptographic modules". [https://www.w3.org/TR/WebCryptoAPI/]
  - Consequência: não existe API web padrão para enumerar os certificados de um token PKCS#11 ou do repositório do Windows (MSCAPI/CNG) e pedir uma assinatura com a chave não exportável.
- Os applets Java, que eram o caminho histórico, dependiam de NPAPI. O Chromium anunciou a remoção do NPAPI [https://blog.chromium.org/2013/09/saying-goodbye-to-our-old-friend-npapi.html], e o cronograma final (bloqueio por padrão, desativação no Chrome 42 e remoção total em setembro de 2015) está em [https://blog.chromium.org/2014/11/the-final-countdown-for-npapi.html]. As alternativas oficiais indicadas incluem Native Messaging [https://www.chromium.org/developers/npapi-deprecation/].
  - Por isso todas as soluções atuais usam uma de duas formas: (a) processo local que expõe HTTP/WebSocket em `localhost` (NexU, Assinador Serpro) ou (b) extensão de navegador + módulo nativo (BRy, e, até onde se pôde ver, Lacuna Web PKI).
- **Local Network Access (Chrome):** requisições "from the public network to a local network or loopback destination" passam a exigir permissão do usuário. Isso inclui `localhost`. O post é de 2025-06-09 e informa: "The Local Network Access permission prompt is launching in Chrome 142". O post também descreve a opção `fetch(..., { targetAddressSpace: "local" })` e uma política corporativa prevista. [https://developer.chrome.com/blog/local-network-access]
  - O tutorial oficial do Assinador Serpro já avisa: "A partir das versões mais recentes dos navegadores será obrigatória a concessão de permissão de acesso à rede local". Ele orienta clicar em "Permitir" e, se a permissão for bloqueada, reativar "Acesso à rede local" ao lado da barra de endereço. [https://tutorial.assinadorserpro.estaleiro.serpro.gov.br/html/demo_53.html]
  - Impacto: o fluxo A3 terá sempre um prompt de permissão extra no Chrome, e a UI precisa orientar sobre ele.
  - O comportamento equivalente no Firefox e no Safari: **NÃO CONFIRMADO**.

### 2.2 Laravel (servidor)

- A chave privada A3 fica no hardware do participante e não é exportável. Esse é o motivo de a assinatura web exigir operação no front-end. A documentação da Lacuna (fornecedor comercial) descreve o mesmo raciocínio em "Assinaturas web" [https://docs.lacunasoftware.com/pt-br/articles/pki-guide/web-signatures/index.html]. Porém a página respondeu 404 na consulta direta e o conteúdo só foi visto em trecho de resultado de busca; a redação literal está **NÃO CONFIRMADA**.
- O servidor nunca vê o token. Ele só produz o digest e recebe de volta a assinatura (valor bruto ou CMS). Isso já está no roadmap §3.4 e é compatível com o pyHanko (§4).
- O suporte a PKCS#11 do pyHanko serve para tokens **conectados à máquina que roda o pyHanko**. No nosso caso isso seria o servidor, que não tem o token do participante; portanto não se aplica a este fluxo. (Inferência de arquitetura, não citação.)

---

## 3. NexU — estado, licença, SO, drivers, API

### 3.1 Origem e estado de manutenção

- **Repositório original indisponível:**
  - `https://github.com/nowina-solutions/nexu` → HTTP 404.
  - `https://api.github.com/repos/nowina-solutions/nexu` → HTTP 404.
  - `https://api.github.com/orgs/nowina-solutions/repos` lista apenas `nowina-solutions/xmlrpc` (Apache-2.0, push 2023-12-16) e `nowina-solutions/ESPD-Service` (push 2019-11-02).
  - A página do produto `https://nowina.lu/solutions/java-less-browser-signing-nexu/` → HTTP 404.
  - O snapshot histórico do repositório no Wayback Machine não pôde ser consultado.
  - Portanto a **licença original, o último release oficial da Nowina e a data de descontinuação** estão **NÃO CONFIRMADOS**.
- O NexU original existia e era distribuído pela Nowina: uma issue de 2021 no repositório oficial das demonstrações DSS cita o download em `nowina.lu/solutions/java-less-browser-signing-nexu/` e a demo `lab.nowina.solutions/nexu-demo/`. A issue continua aberta e sem resposta visível de mantenedor. [https://github.com/esig/dss-demonstrations/issues/25]
- A documentação atual do DSS (Comissão Europeia) **não menciona o NexU**. Ela cita suporte a tokens "PKCS#11, MS-CAPI, Apple, PKCS#12" no próprio framework DSS. [https://ec.europa.eu/digital-building-blocks/DSS/webapp-demo/doc/dss-documentation.html]
- **Fork comunitário `p4535992/nexu`** [https://api.github.com/repos/p4535992/nexu]:
  - `fork: true`, `parent: hello-earth-gh/nexu`, `source: nowheresly/nexu`. A rede de forks aponta como raiz um repositório de terceiro sem licença declarada e sem push desde 2016 [https://api.github.com/repos/nowheresly/nexu]. Como o original sumiu, a proveniência do código é **indireta**.
  - Descrição: "Community-maintained NexU 1.25 local signing agent for smart cards, PKCS#11, Windows certificate store and JKS/PKCS#12 keystores. Java 21, Spring Boot 4.1, DSS 6.4."
  - Licença `EUPL-1.2`, confirmada no arquivo `LICENSE` ("EUROPEAN UNION PUBLIC LICENCE v. 1.2") [https://raw.githubusercontent.com/p4535992/nexu/master/LICENSE].
  - Popularidade e responsável: `owner.type: User`, 3 estrelas, 1 fork, 0 issues abertas; `pushed_at` 2026-07-31.
  - O README afirma: "This repository is the actively maintained community continuation of NexU after the original Nowina repository became unavailable." [https://raw.githubusercontent.com/p4535992/nexu/master/README.md]. Isso é declaração do próprio mantenedor, não da Nowina.
  - **Releases** [https://api.github.com/repos/p4535992/nexu/releases?per_page=10]:
    - Estáveis: `v1.25.0` e `v1.24.0`. Pré-releases: `v1.25.0-rc.1..3` e `v1.24.0-rc.18`.
    - **Todas com `published_at` em 2026-07-31.**
    - Artefatos: EXE, MSI, RPM, DEB, TAR.GZ, JAR, com SHA256.
  - **Commits recentes** [https://api.github.com/repos/p4535992/nexu/commits?per_page=10]:
    - Os 10 últimos são de 2026-07-31, do mesmo autor, mais `github-actions[bot]`.
    - Mensagens incluem "Recommend DSS Standalone when NexU is not required" e "Document DSS Standalone alternative".
  - Leitura crítica: a concentração de todos os releases e commits num único dia sugere republicação em lote, e não um histórico de manutenção contínua. Mantenedor único, sem organização, sem SLA.
- Existe um wiki de terceiro (Esup-Portail) sobre o uso do NexU no esup-signature. As URLs encontradas em busca responderam 404 no momento da consulta. **[terceiro]** [https://www.esup-portail.org/wiki/display/SIGN/Application+NexU]

### 3.2 Sistemas operacionais e runtime (fork)

- Pacotes nativos para **Windows e Linux**. **macOS ausente** do README e dos artefatos de release. [https://raw.githubusercontent.com/p4535992/nexu/master/README.md] [https://api.github.com/repos/p4535992/nexu/releases?per_page=10]
- Java 21. Os pacotes nativos "include a private runtime"; o JAR avulso exige JDK/JRE instalado. [https://raw.githubusercontent.com/p4535992/nexu/master/README.md]
- Na demo DSS de 2021, um usuário relatou falha em Mac com Chrome, mesmo com o NexU da época. [https://github.com/esig/dss-demonstrations/issues/25]

### 3.3 Drivers de token / smartcard

- Fontes suportadas: "smart cards through PC/SC, minidriver/KSP or vendor PKCS#11 middleware; Windows certificate-store keys; JKS files; PKCS#12 files". [https://raw.githubusercontent.com/p4535992/nexu/master/README.md]
- O código contém:
  - `WindowsKeystorePlugin.java` (repositório do Windows, isto é, MSCAPI);
  - `Pkcs11ParamsController.java` (UI para informar a biblioteca PKCS#11 do fabricante);
  - `KeystorePlugin.java`.
  - [https://api.github.com/repos/p4535992/nexu/git/trees/master?recursive=1]
- Como configurar a biblioteca PKCS#11 (caminho da `.dll`/`.so`) e a lista de tokens homologados: o README não detalha — **NÃO CONFIRMADO**. Testar com os tokens mais comuns no Brasil (SafeNet/eToken, ePass, GD) está **NÃO CONFIRMADO** e deve fazer parte do piloto.

### 3.4 API REST local (fork 1.25, confirmada no código-fonte)

**Rede** [https://raw.githubusercontent.com/p4535992/nexu/master/nexu-app/src/main/resources/nexu-config.properties]:

- Bind em `127.0.0.1`, portas **9795 (HTTP)** e **9895 (HTTPS)**.
- Certificado TLS **autoassinado** gerado por instalação em `config/localhost.crt` e `config/localhost.key`, com SAN `localhost` e `127.0.0.1`. O usuário precisa confiar nele manualmente no navegador. [https://raw.githubusercontent.com/p4535992/nexu/master/README.md]
- O filtro recusa requisições cuja origem de rede não seja loopback (`isLoopback(request.getRemoteAddr())`). [https://raw.githubusercontent.com/p4535992/nexu/master/nexu-app/src/main/java/lu/nowina/nexu/springboot/server/NexuLoopbackCorsFilter.java]

**Endpoints modernos** (`NexuModernController`) [https://raw.githubusercontent.com/p4535992/nexu/master/nexu-app/src/main/java/lu/nowina/nexu/springboot/server/NexuModernController.java]:

| Método e rota                  | Request (campos)                                                                                    | Response (campos)                                                                                                                                                                       |
| ------------------------------ | --------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /v1/status`               | —                                                                                                   | `applicationVersion` (string), `protocolVersion` (string), `features` (string[])                                                                                                        |
| `POST /v1/signing-certificate` | `closeToken?` (bool), `certificatePurpose?` (string), `nonRepudiation?` (bool)                      | `certificate` (Base64 DER), `certificateChain` (Base64[]), `encryptionAlgorithm`, `supportedDigests` (string[]), `preferredDigest`, `keyHandle` { `tokenId`, `keyId` }                  |
| `POST /v1/sign`                | `keyHandle` { `tokenId`, `keyId` }, `hash` (Base64 do digest), `hashFunction` (ex.: `SHA256`), `clearToken?` (bool) | `signature` (Base64, valor bruto), `signatureAlgorithm`, `certificate`, `certificateChain`                                                                                              |

- Erros: `400` (entrada inválida, Base64 inválido, algoritmo não suportado), `422` (falha na operação, por exemplo usuário cancelou ou PIN errado; mapeamento exato **NÃO CONFIRMADO**) e `500`. Nomes de digest normalizados: maiúsculas, sem hífen. [mesma URL]
- `/v1/sign` assina **digest pronto, sem re-hash**. Javadoc do `SignDigestOperation`: "Signs a digest that was prepared by the remote signing application. This is deliberately separate from SignOperation, which hashes the supplied data before signing it." A chamada é `token.signDigest(digest, key)`. [https://raw.githubusercontent.com/p4535992/nexu/master/nexu-core/src/main/java/lu/nowina/nexu/flow/operation/SignDigestOperation.java]
- O controller não tem autenticação, sessão nem token de pareamento. A proteção é o loopback + CORS + a interação do usuário no próprio NexU (escolha do certificado e PIN do token). [mesma URL do controller]

**Cliente JS servido pelo próprio NexU:** `GET /nexu-v2.js` expõe [https://raw.githubusercontent.com/p4535992/nexu/master/nexu-app/src/main/resources/nexu-v2.ftl.js]:

- `NexU.status()`;
- `NexU.getSigningCertificate(options)` → `{certificate, keyHandle}`;
- `NexU.sign(certificate, hash, hashFunction, options)` → `{signature, signatureAlgorithm, certificate, certificateChain}`.

**Endpoints legados:**

- `/rest/certificates` (`{closeToken}`);
- `/rest/sign` (`{tokenId:{id}, keyId, toBeSigned:{bytes}, digestAlgorithm, doClearCache}`);
- `/rest/logout`;
- `/nexu-info`;
- funções JS `nexu_get_certificates`, `nexu_sign_with_token_infos`, `nexu_sign`.

[mesmas URLs]

**CORS** [https://raw.githubusercontent.com/p4535992/nexu/master/nexu-app/src/main/java/lu/nowina/nexu/springboot/server/NexuLoopbackCorsFilter.java]:

- `/v1/**` exige allowlist explícita de origem (`cors_allowed_origin=https://sign.example.org`). O curinga é **recusado** para `/v1` (`if (modernApi) { return false; }`).
- Os legados `/rest/*` mantêm `Access-Control-Allow-Origin: *` quando a configuração é permissiva, que é o padrão do `nexu-config.properties` distribuído.
- O cabeçalho `Access-Control-Allow-Private-Network` **não é emitido**. A interação com o Local Network Access do Chrome 142+ está, portanto, **NÃO CONFIRMADA** e precisa ser testada.
- **Consequência prática:** a allowlist fica no `nexu-config.properties` **da máquina do participante**. Com o pacote padrão, a origem da AssinaVelox não está autorizada em `/v1`. Para usar a API moderna seria preciso:
  - pedir ao usuário que edite a configuração (inviável para o público-alvo); ou
  - redistribuir um instalador próprio já configurado, o que implica cumprir as obrigações da EUPL-1.2 sobre obra derivada e distribuir o código-fonte — análise jurídica **NÃO CONFIRMADA**.
- Usar os legados `/rest/*` com `*` funciona sem configurar, mas **qualquer site** pode então pedir operações ao NexU do usuário. Continuam valendo a escolha do certificado e o PIN, mas isso é fraco como controle.

---

## 4. Fluxo com o pyHanko: hash no servidor → assinatura no componente local → PAdES no servidor

### 4.1 O que o pyHanko documenta

- Seção "Interrupted signing": cenário em que "pyHanko prepares a document for signing, computes the digest, sends it off to somewhere else for signing, and finishes the signing process once the response comes in". [https://docs.pyhanko.eu/en/latest/lib-guide/signing.html#interrupted-signing]
- Trechos do exemplo oficial (fonte: `docs/lib-guide/signing.rst`) [https://raw.githubusercontent.com/MatthiasValvekens/pyHanko/master/docs/lib-guide/signing.rst]:

```python
prep_digest, tbs_document, output_handle = \
    await pdf_signer.async_digest_doc_for_signing(w)

signed_attrs = await ext_signer.signed_attrs(
    prep_digest.document_digest, 'sha256', use_pades=True
)

sig_value = await sign_remotely(signed_attrs.dump())

sig_cms = await ext_signer.async_sign_prescribed_attributes(
    'sha256', signed_attrs=signed_attrs,
    timestamper=timestamps.HTTPTimeStamper(TSA_URL)
)

await PdfTBSDocument.async_finish_signing(
    output_handle, prepared_digest=prep_digest,
    signature_cms=sig_cms, post_sign_instr=psi,
    validation_context=build_vc()
)
```

- Comentário no código oficial: "Here, assume sig_value is the signed digest of the signed_attrs bytes, obtained from some remote signing service". [https://docs.pyhanko.eu/en/latest/lib-guide/signing.html#basic-interrupted-signing-example]
- Pontos do exemplo: a assinatura remota é feita sobre `signed_attrs.dump()`, isto é, o DER dos atributos assinados. Isso é assinatura **bruta**, e o CMS é montado do lado do pyHanko. Em outra variante da mesma página, o serviço remoto devolve um CMS pronto (`call_external_service(prep_digest.document_digest)`). O NexU se encaixa no **primeiro** caso. [https://docs.pyhanko.eu/en/latest/lib-guide/signing.html]
- O guia manda persistir entre as fases "prep_digest, signed_attrs and psi somewhere. The output stream can also be stored in a temporary file". [https://docs.pyhanko.eu/en/latest/lib-guide/signing.html]
- `ExternalSigner`: "Class to help formatting CMS objects for use with remote signing. It embeds a fixed signature value into the CMS, set at initialisation. Intended for use with Interrupted signing." Parâmetros:
  - `signing_cert`, `cert_registry`;
  - `signature_value` ("The value of the signature as a byte string, a placeholder length, or None");
  - `signature_mechanism`, `prefer_pss`, `embed_roots`.
  - [https://docs.pyhanko.eu/en/latest/api-docs/pyhanko.sign.signers.html#pyhanko.sign.signers.pdf%5Fcms.ExternalSigner]
- `Signer.async_sign_prescribed_attributes`: "Start the CMS signing process with the prescribed set of signed attributes." [https://docs.pyhanko.eu/en/latest/api-docs/pyhanko.sign.signers.html#pyhanko.sign.signers.pdf%5Fcms.Signer.async%5Fsign%5Fprescribed%5Fattributes]
- Quando o certificado não é conhecido de antemão: `ExternalSigner` com `signing_cert=None`, `md_algorithm` explícito e `bytes_reserved` obrigatório ("Since estimation is disabled without a certificate available, bytes_reserved becomes mandatory"). [https://docs.pyhanko.eu/en/latest/lib-guide/signing.html#interrupted-signing-when-the-signer-s-certificate-is-not-known-a-priori]
- API de baixo nível alternativa: `PdfCMSEmbedder().write_cms()` → `SigObjSetup` → `SigIOSetup` → envio dos bytes CMS. [https://docs.pyhanko.eu/en/latest/lib-guide/signing.html#the-low-level-pdfcmsembedder-api]
- Versão: pyHanko `0.37.0` no PyPI (2026-08-31), licença MIT, Python >= 3.10. [https://pypi.org/pypi/pyHanko/json]

### 4.2 Sequência proposta para o AssinaVelox

1. **Navegador → NexU** `POST /v1/signing-certificate` (via `NexU.getSigningCertificate`).
   - O participante escolhe o certificado e o NexU devolve `certificate`, `certificateChain`, `encryptionAlgorithm`, `supportedDigests` e `keyHandle`.
   - O certificado precisa vir **antes**, porque os atributos PAdES (`signing-certificate-v2`) o referenciam. Isso evita o caminho `signing_cert=None`.
2. **Navegador → Laravel** envia o certificado e a cadeia (Base64). O Laravel chama `pdftool prepare-external`, que:
   - roda `async_digest_doc_for_signing`;
   - gera `signed_attrs` com `ExternalSigner(signing_cert=cert, cert_registry=chain, signature_value=<placeholder de tamanho>)`;
   - calcula `SHA-256(signed_attrs.dump())`;
   - persiste em `pending_external_signatures`, com TTL curto: a revisão pendente, `prep_digest`, `signed_attrs` em DER, `psi` e o hash do certificado.
   - Devolve só o digest.
   - O formato de serialização de `prep_digest`/`psi`/`output_handle` entre processos (o pyHanko diz apenas "somewhere") está **NÃO CONFIRMADO**. Validar no spike se é preciso guardar o PDF intermediário + `signed_attrs` DER e reconstruir o resto.
3. **Navegador → NexU** `POST /v1/sign` com `{keyHandle, hash: <digest Base64>, hashFunction: "SHA256"}`. O usuário digita o PIN no token, e o NexU devolve `signature` bruta.
4. **Navegador → Laravel** envia `signature`. O Laravel chama `pdftool embed-external`, que:
   - confere se o certificado devolvido é o mesmo do passo 2;
   - cria `ExternalSigner(..., signature_value=sig)`;
   - roda `async_sign_prescribed_attributes` e depois `PdfTBSDocument.async_finish_signing`;
   - revalida a assinatura no PDF final (ByteRange e integridade);
   - descarta a pendência.
   - Digest expirado, certificado diferente ou assinatura inválida → rejeitar e descartar a revisão pendente.
5. **Ajuste necessário no roadmap §3.4:** o texto prevê que `embed-external` "recebe CMS/PKCS#7". Com o NexU, e com qualquer componente que assine digest, o servidor recebe **assinatura bruta + certificado** e monta o CMS. Os dois modos devem ser aceitos: `raw_signature` e `cms`.
6. `signature_mechanism` precisa casar com `encryptionAlgorithm` (RSA PKCS#1 v1.5, RSA-PSS ou ECDSA). Qual mecanismo o NexU usa para RSA (v1.5 ou PSS): **NÃO CONFIRMADO**. Validar no spike com `signatureAlgorithm` retornado.

---

## 5. Alternativas usadas no Brasil

| Solução                                                  | Forma                                                                                                                                                                                              | Licença / custo                                                                                                                                                                                                                                                                                                                                                        | SO / navegadores                                                                                                                                                  | API para integrador                                                                                                                                                                                                                                                                                                               |
| -------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **NexU (fork p4535992)**                                 | Processo local Java com HTTP(S) em `localhost`                                                                                                                                                     | EUPL-1.2, gratuito, sem suporte comercial (§3.1)                                                                                                                                                                                                                                                                                                                       | Windows e Linux; sem macOS (§3.2)                                                                                                                                  | (a) Pública e documentada no código-fonte (§3.4). Allowlist CORS configurada na máquina do usuário.                                                                                                                                                                                                                                                             |
| **Lacuna Web PKI**                                       | Componente no navegador; instalação guiada na primeira vez [https://docs.lacunasoftware.com/pt-br/articles/web-pki/get-started.html]                                                             | **Comercial.** Grátis só em `localhost` (qualquer porta) e em IPs privados para homologação; produção exige licença de uso vinculada a domínios (`allowedDomains`/`homologDomains`); dispensada quando usado com o Rest PKI em nuvem [https://docs.lacunasoftware.com/pt-br/articles/web-pki/licensing.html]. **Preço: NÃO CONFIRMADO** (não publicado nas páginas lidas). | Chrome 44+, Firefox 50+, Edge 42+, IE 9+; Windows 7 SP2+, Linux (Debian/RedHat/Slackware), macOS 10.12+ [https://docs.lacunasoftware.com/pt-br/articles/web-pki/browser-support.html] | (b) Documentada, mas exige licença comercial para produção. As definições TypeScript oficiais declaram `signHash(args: { thumbprint: string, hash: string, digestAlgorithm: string }): Promise<string>`, que devolve os bytes da assinatura em Base64. Declaram também `readCertificate({ thumbprint }): Promise<string>` (DER em Base64) e a licença passada em `init({ ready, license?: string \| Object })` [https://raw.githubusercontent.com/LacunaSoftware/docs/master/typedocs/lacuna-web-pki.d.ts]. Ou seja, o modelo é o mesmo do NexU `/v1/sign` (assinatura bruta sobre digest pronto) e encaixa no fluxo do §4.2 sem mudança no servidor.                                                                                                                                                                              |
| **BRy — "Assinatura Digital no Navegador"**              | Extensão + módulo nativo instalado em segundo passo [https://addons.mozilla.org/en-US/firefox/addon/assinatura-digital-navegador/]                                                               | Extensão "All Rights Reserved", publicada por BRy Tecnologia, atualizada em julho/2026 [mesma URL]. Termos e custo para integrador terceiro: **NÃO CONFIRMADO** (a base de conhecimento da BRy respondeu 403).                                                                                                                                                              | Chrome e Firefox [https://addons.mozilla.org/en-US/firefox/addon/assinatura-digital-navegador/]; SO: **NÃO CONFIRMADO**                                          | O blog do próprio fornecedor lista seis APIs em nuvem (assinatura digital, assinatura médica, em grupo, diploma digital, certificado em nuvem, carimbo do tempo). O portal de desenvolvedor oferece "10 créditos" e certificado de teste. Nenhuma API cita a extensão ou o A3 local [https://www.bry.com.br/blog/apis-da-bry/]. API pública para integrar a extensão a um site de terceiro: **NÃO CONFIRMADO**. Contrato e preço: **NÃO CONFIRMADO**.                                                                                                                                                               |
| **Assinador Serpro (desktop)**                           | Aplicação desktop; a página se comunica com ela por WebSocket [https://www.assinadorserpro.estaleiro.serpro.gov.br/minimalista/tutorial/index.html]                                               | "Ferramenta gratuita que possibilita assinatura digital com certificados emitidos na ICP-Brasil" [https://artefatos-assinador.serpro.gov.br/]. Licença de software e permissão de uso por SaaS privado: **NÃO CONFIRMADO**.                                                                                                                                                | Windows 11, Linux, macOS Intel e Apple Silicon; exige permissão de administrador na instalação [https://artefatos-assinador.serpro.gov.br/]                     | (a/b) Existe tutorial para desenvolvedores com comandos `sign`, `verify`, `attached` e exemplo "Assinar Hash" [https://www.assinadorserpro.estaleiro.serpro.gov.br/minimalista/tutorial/index.html]. Endereço do WebSocket: `wss://127.0.0.1:65156/signer/`. Exemplos oficiais de mensagem: `{"command": "sign","type": "text", "inputData": "teste"}`, a variante com `"attached": "true"` e `{"command": "attached", "inputSignature": "..."}`, que responde `attachedContent` em Base64 [https://assinadorserpro.estaleiro.serpro.gov.br/minimalista/tutorial/websocket.html]. Os parâmetros do comando de hash e o formato de retorno (assinatura bruta vs CMS): **NÃO CONFIRMADO**. Requer a permissão Local Network Access (§2.1). |
| **DSS Standalone** (Comissão Europeia)                    | Aplicação desktop do framework DSS                                                                                                                                                                 | O fork do NexU passou a recomendá-lo "when NexU is not required" [https://api.github.com/repos/p4535992/nexu/commits?per_page=10]. Licença e distribuição: **NÃO CONFIRMADO** nesta pesquisa.                                                                                                                                                                         | **NÃO CONFIRMADO**                                                                                                                                                | Não expõe API para página web (pelo que se sabe) — **NÃO CONFIRMADO**. Útil só como assinador desktop, fora do fluxo web.                                                                                                                                                                                                         |
| **Assinatura em nuvem via PSC** (sem componente local)   | Fora do escopo desta pesquisa; decisão futura (roadmap §3.4, depende de T4)                                                                                                                         | Comercial por natureza                                                                                                                                                                                                                                                                                                                                                 | —                                                                                                                                                                 | —                                                                                                                                                                                                                                                                                                                                 |

Observação: não se encontrou componente local oficial do ITI/ICP-Brasil com API web pública; a conclusão de que ele não existe é **NÃO CONFIRMADA**.

---

## 6. Riscos específicos levantados

1. **Proveniência e continuidade:** o upstream da Nowina sumiu. O único fork ativo é de uma pessoa, tem 3 estrelas e releases concentrados em um único dia. Adotá-lo significa assumir a manutenção, ou pelo menos a auditoria, de um agente que roda na máquina do usuário final.
2. **CORS e instalação:** a API `/v1` segura exige allowlist local. A alternativa `/rest/*` com `*` expõe o token do usuário a qualquer site. Isso empurra para um instalador próprio, o que traz obrigações de distribuição e suporte.
3. **TLS local autoassinado e Local Network Access:** são duas fricções diferentes (confiar no certificado e conceder a permissão), em público majoritariamente não técnico.
4. **macOS sem suporte** no NexU; o Assinador Serpro cobre macOS.
5. **Mecanismo de assinatura** (RSA v1.5/PSS/ECDSA) e **serialização do estado intermediário** do pyHanko: pontos a validar no spike (§4.2, itens 2 e 6).
6. **ICP-Brasil:** `participant_icp_brasil` só pode ser concedido após validação contra as ACs raiz do ITI, com CRL/OCSP atualizadas (roadmap §3.4). Fora disso, usar o rótulo real do certificado.

---

## 7. Contratos sugeridos (sem implementação de produção)

- Front: `LocalSignerBridge` com os métodos:
  - `detect(): {available, component, version}`;
  - `getSigningCertificate(): {certificate, chain, keyAlgorithm, keyHandle}`;
  - `signDigest(keyHandle, digestB64, hashFunction): {signature, signatureAlgorithm, certificate}`.
- Implementações do `LocalSignerBridge`:
  - `NexuLocalSigner` (API `/v1`);
  - `FakeLocalSigner`: identificado na UI como **simulado**, assina com um PKCS#12 de teste `environment=test` e **nunca** é exibido como A3 nem como ICP-Brasil.
- Back: contrato `ExternalSignatureAssembler` (em `App\Integrations`, seguindo `arquitetura.md` §8), com `prepare(document_version, certificate_chain): PendingExternalSignature` e `embed(pending_id, raw_signature | cms): SignedRevision`.
  - Implementação real: `PyHankoExternalAssembler` (`pdftool prepare-external` / `embed-external`).
  - Cada chamada leva `correlation_id`, timeout e política idempotente. Uma resposta inconclusiva nunca conta como sucesso.

---

## 8. Decisão recomendada para o AssinaVelox

**Implementar contrato + fake identificado com produção desabilitada** para o componente local. Em paralelo, **implementar de verdade agora** o lado servidor.

- **O que implementar de verdade agora:**
  - `pdftool prepare-external` e `pdftool embed-external`, aceitando assinatura bruta e CMS;
  - `pending_external_signatures` com TTL;
  - o `ExternalSignatureAssembler`.
  - Justificativa: o fluxo "interrupted signing" com `ExternalSigner` + `async_sign_prescribed_attributes` + `async_finish_signing` está documentado oficialmente no pyHanko 0.37 (MIT). Dá para testar ponta a ponta com o `FakeLocalSigner`, que usa uma chave de software de teste, inclusive os casos do critério de aceite (CMS adulterado, digest expirado).
- **O que fica com produção desabilitada (flag):** o `NexuLocalSigner` e a UI A3. Motivos:
  1. O repositório e o site oficiais da Nowina estão indisponíveis. O único código mantido é um fork individual, sem suporte e com histórico de releases pouco confiável.
  2. A API `/v1` exige allowlist de origem **na máquina do usuário**. Os endpoints legados com `*` são inseguros.
  3. Não há suporte a macOS.
  4. A interação com o Local Network Access do Chrome 142+ não foi testada.
  5. Não há ainda validação de cadeia ICP-Brasil nem teste com token físico.
- **O que falta para desbloquear a produção:**
  - **(a)** Piloto em Windows com pelo menos dois modelos de token A3 comuns no Brasil, cobrindo Chrome e Firefox, com o prompt de Local Network Access e o TLS local.
  - **(b)** Decisão de componente entre:
    - NexU-fork com instalador próprio configurado para a nossa origem (exige parecer sobre EUPL-1.2 e plano de manutenção);
    - Assinador Serpro (confirmar licença e uso por SaaS privado, os parâmetros do comando de hash em `wss://127.0.0.1:65156/signer/` e se o retorno é bruto ou CMS; o `embed-external` aceita os dois modos);
    - Lacuna Web PKI ou BRy (contrato comercial e preço).
  - **(c)** Job de âncoras ITI + CRL/OCSP para liberar `participant_icp_brasil`.
  - **(d)** Validação da assinatura gerada em validador externo (critério de aceite do §3.4).
- Até lá, a UI mantém o **aceite eletrônico com evidências** como caminho padrão e orienta quando não há componente, conforme o roadmap §3.4.
