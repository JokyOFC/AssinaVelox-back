# Carimbo do tempo RFC 3161, ACT ICP-Brasil e PAdES de longo prazo

> Pesquisa técnica para o roadmap §2.13 (TSA própria RFC 3161 + dossiê) e §3.6 (carimbo ICP-Brasil via ACT credenciada + PAdES B-T/B-LT/B-LTA).
> Data da pesquisa: 2026-09-11. Versões locais conferidas em `tools/pdftool/.venv`: **pyHanko 0.37.0**, **pyhanko-certvalidator 0.32.0**, asn1crypto 1.5.1, cryptography 50.0.1, aiohttp 3.14.3.
> Convenção: cada fato tem a URL da fonte. "Código-fonte local" = arquivo do pacote instalado no venv do pdftool (a fonte primária da versão que usamos). **NÃO CONFIRMADO** = não achei em fonte oficial; não usar como fato.
> Regra do projeto repetida aqui: **TSA própria ou comercial ≠ carimbo ICP-Brasil** (roadmap T3). Só carimbo emitido por ACT credenciada pelo ITI recebe `tsa_kind=icp_brasil`.

---

## 1. RFC 3161 — o protocolo

### 1.1 Estruturas (RFC 3161, https://www.rfc-editor.org/rfc/rfc3161.html)

| Estrutura        | Campos (ASN.1 da RFC)                                                                                                                                                                                                    | Observação para o AssinaVelox                                                                                                                                     |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `TimeStampReq`   | `version v1(1)`, `messageImprint`, `reqPolicy TSAPolicyId OPTIONAL`, `nonce INTEGER OPTIONAL`, `certReq BOOLEAN DEFAULT FALSE`, `extensions [0] OPTIONAL`                                                                | Enviamos só o hash (nunca o documento). Sempre `nonce` e `certReq=true`.                                                                                          |
| `MessageImprint` | `hashAlgorithm AlgorithmIdentifier`, `hashedMessage OCTET STRING`                                                                                                                                                        | SHA-256 dos bytes carimbados.                                                                                                                                     |
| `TimeStampResp`  | `status PKIStatusInfo`, `timeStampToken OPTIONAL`                                                                                                                                                                        | O token é um CMS `SignedData` cujo conteúdo é `TSTInfo`.                                                                                                          |
| `PKIStatusInfo`  | `status`, `statusString OPTIONAL`, `failInfo OPTIONAL`                                                                                                                                                                   | `failInfo` inclui `badAlg`, `badRequest`, `badDataFormat`, `timeNotAvailable`, `unacceptedPolicy`, `unacceptedExtension`, `addInfoNotAvailable`, `systemFailure`. |
| `TSTInfo`        | `version`, `policy TSAPolicyId`, `messageImprint`, `serialNumber`, `genTime GeneralizedTime`, `accuracy OPTIONAL`, `ordering DEFAULT FALSE`, `nonce OPTIONAL`, `tsa [0] GeneralName OPTIONAL`, `extensions [1] OPTIONAL` | Colunas de `timestamp_tokens` (roadmap §2.13) mapeiam direto: `imprint`, `gen_time`, `serial`, `policy`.                                                          |
| `Accuracy`       | `seconds`, `millis [0] (1..999)`, `micros [1] (1..999)` — todos opcionais                                                                                                                                                | A TSA própria deve declarar a precisão que consegue garantir com o NTP monitorado.                                                                                |

Regras normativas relevantes (mesma URL):

- Transporte HTTP: requisição `Content-Type: application/timestamp-query` com o `TimeStampReq` em DER; resposta `application/timestamp-reply` com o `TimeStampResp` em DER.
- Certificado da TSA: exatamente uma extensão _extended key usage_, com `id-kp-timeStamping` (1.3.6.1.5.5.7.3.8), e ela **MUST be critical**.
- O identificador do certificado da TSA (ESSCertID) **deve** ir como atributo assinado dentro de `SigningCertificate`.
- `nonce`: se veio na requisição, a resposta deve trazer o mesmo valor, senão é rejeitada.
- `certReq=true` → o certificado da TSA **deve** vir no campo `certificates`; ausente/false → **não deve** vir.

Atualização RFC 5816 (https://www.rfc-editor.org/rfc/rfc5816.html): permite `ESSCertIDv2` dentro de `SigningCertificateV2`, que **deve** ser usado com qualquer hash diferente de SHA-1 (ambos podem coexistir por compatibilidade). A TSA própria deve usar `SigningCertificateV2` com SHA-256.

### 1.2 TSA própria em Python (asn1crypto + cryptography)

**Não existe servidor TSA pronto para produção no pyHanko.** O que existe:

- `pyhanko.sign.timestamps.DummyTimeStamper`. Docstring: "Timestamper that acts as its own TSA. It accepts all requests and signs them using the certificate provided. Used for testing purposes." Fontes: API docs https://docs.pyhanko.eu/en/latest/api-docs/pyhanko.sign.timestamps.html e código-fonte local `pyhanko/sign/timestamps/dummy_client.py`. Construtor: `DummyTimeStamper(tsa_cert, tsa_key, certs_to_embed=None, fixed_dt=None, include_nonce=True, override_md=None)`. O que o código local mostra e **impede o uso em produção**:
    - política fixa `1.3.6.1.4.1.4146.2.2`, que não é nossa (comentário no código: "this is a testing device anyway");
    - `serial_number` aleatório (`get_nonce()`), sem garantia de unicidade persistida;
    - só RSA ("Dummy timestamper is RSA-only.");
    - usa `as_signing_certificate` (ESSCertID com SHA-1), e não `as_signing_certificate_v2`, que existe em `pyhanko/sign/general.py` com SHA-256 por padrão;
    - pula a validação da requisição: aceita tudo e sempre responde `granted`.
    - Uso certo: **fake identificado** nos testes do pdftool (inclusive `fixed_dt` para relógio controlado).
- `DummyTimeStamper` serve de **modelo** para um servidor próprio. O código mostra a montagem completa com `asn1crypto.tsp` (`TSTInfo`, `TimeStampResp`, `PKIStatusInfo`), `asn1crypto.cms` (`SignedData` v3 com `encap_content_info` de tipo `tst_info`, atributos assinados `content_type`, `signing_time`, `signing_certificate`, `message_digest`) e `cryptography` para assinar.

Arquitetura proposta para a TSA do operador (`tsa_kind=operator`); é proposta nossa, não fonte externa:

| Peça            | Proposta                                                                                                                                                                                                                                                   | Base                                                                                                     |
| --------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Processo        | `tools/pdftool` ganha `pdftool tsa-serve` (ou um script separado, `tools/tsa`), com um endpoint HTTP que recebe `application/timestamp-query` e devolve `application/timestamp-reply`. Escuta só na rede interna; o Laravel é o único cliente.             | Transporte da RFC 3161 §3.4                                                                              |
| Parsing         | `tsp.TimeStampReq.load(body)`; rejeita versão ≠ 1, hash fora de {sha256, sha384, sha512} (`badAlg`), `reqPolicy` diferente da nossa (`unacceptedPolicy`) e extensões desconhecidas (`unacceptedExtension`).                                                | RFC 3161 §2.4.1/§2.4.2                                                                                   |
| `TSTInfo`       | `policy` = OID próprio da política da TSA do operador; `serialNumber` único, de sequência persistida (tabela/sequência MySQL ou arquivo com lock, **não** aleatório); `genTime` em UTC; `accuracy` declarada; `nonce` ecoado; `tsa` = nome do certificado. | RFC 3161; o bug do `openssl ts` com arquivo de serial sem lock (abaixo) mostra por que unicidade importa |
| Assinatura      | `SignedData` com `SigningCertificateV2` (SHA-256); chave RSA-3072+ ou ECDSA P-256 via `cryptography`; certificado com EKU `timeStamping` **crítica**.                                                                                                      | RFC 3161 + RFC 5816                                                                                      |
| Chave           | Arquivo com permissões restritas no início; HSM/KMS antes de clientes pagantes (roadmap §2.13). Senha por variável de ambiente (T10).                                                                                                                      | roadmap §2.13                                                                                            |
| Relógio         | NTP monitorado; se o desvio passar da `accuracy` declarada, a TSA responde `timeNotAvailable` em vez de emitir.                                                                                                                                            | RFC 3161 `PKIFailureInfo.timeNotAvailable`                                                               |
| Certificado     | Emitido por uma **AC interna do operador**. Nenhum validador público confia nela; por isso o rótulo é `operator`. Comprar certificado de TSA de AC comercial: **NÃO CONFIRMADO** (disponibilidade e preço não pesquisados).                                | —                                                                                                        |
| OID da política | Precisa de um arco próprio (ex.: número PEN da IANA). Processo e prazo de obtenção: **NÃO CONFIRMADO** nesta pesquisa.                                                                                                                                     | —                                                                                                        |

Alternativa pronta, só para dev: `openssl ts` (https://docs.openssl.org/3.0/man1/openssl-ts/) tem os modos `-query`, `-reply` e `-verify` e seção de configuração com `serial`, `signer_cert`, `signer_key`, `default_policy`, `digests`, `accuracy`, `ess_cert_id_alg`. Limitações documentadas: não tem transporte HTTP/TCP ("There is no support for sending the requests/responses automatically over HTTP or TCP yet"), e o arquivo de serial não tem lock quando várias instâncias rodam juntas (seção BUGS). Serve para **verificar** (`openssl ts -verify -data|-digest ... -in x.tsr -CAfile ...`, que é o critério de aceite do §2.13), não para ser a TSA de produção.

### 1.3 Cliente RFC 3161 no pyHanko 0.37

Código-fonte local `pyhanko/sign/timestamps/aiohttp_client.py`; API docs https://docs.pyhanko.eu/en/latest/api-docs/pyhanko.sign.timestamps.html:

- `HTTPTimeStamper(url, https=False, timeout=5, auth=None, headers=None, session=None)`. Em 0.37.0 foi "Reimplemented on top of aiohttp, and merged with the former AIOHttpTimeStamper". `auth` aceita `aiohttp.BasicAuth` ou um par `(user, password)`; `headers` inclui cabeçalhos extras. Existe o gancho `async_request_headers()`, que subclasses podem sobrescrever para derivar cabeçalhos de forma assíncrona (ex.: gerar um token Bearer).
- `RequestsHTTPTimeStamper(url, https=False, timeout=5, auth=None, headers=None)` é a variante com `requests` (`pyhanko/sign/timestamps/requests_client.py`).
- `set_tsp_headers` define `Content-Type: application/timestamp-query` e `Accept: application/timestamp-reply` (`common_utils.py`).
- A CLI do pyHanko diz que "only public time stamping servers are supported right now (i.e. those that do not require authentication)" (https://docs.pyhanko.eu/en/latest/cli-guide/signing.html). Isso vale **só para a CLI**; pela biblioteca, `auth`/`headers`/subclasse cobrem TSAs autenticadas.

---

## 2. Carimbo do tempo ICP-Brasil (ACT credenciada)

### 2.1 O que é e quem pode emitir

- DOC-ICP-11 v2.0 (Resolução nº 171, de 17/08/2020): "Visão Geral do Sistema de Carimbos do Tempo na ICP-Brasil" — https://www.gov.br/iti/pt-br/assuntos/legislacao/resolucoes/resolucoes-old/Resoluo171Dec10139Etapa1DOC11Compilada.pdf
    - §2.3.1: a AC Raiz credencia as ACTs que querem integrar a estrutura de carimbo do tempo da ICP-Brasil.
    - §2.5.3: formato das solicitações e respostas e protocolos de transporte devem seguir a **RFC 3161**.
    - §2.6.1: quem verifica deve conferir a identidade da ACT e do SCT (Servidor de Carimbo do Tempo), a validade dos certificados e a política sob a qual o carimbo foi emitido.
    - §2.7.2: "Somente são aceitos na ICP-Brasil carimbos do tempo emitidos por SCT com alvarás fornecidos por Sistemas de Auditoria e Sincronismo."
    - O sincronismo e a auditoria do tempo ficam com a EAT (Entidade de Auditoria do Tempo) (§2.4.1).
- **Consequência para nós:** o formato RFC 3161 é o mesmo, mas o carimbo só é ICP-Brasil se vier de SCT de ACT credenciada, auditado e sincronizado pela EAT. Uma TSA do operador ou uma TSA comercial estrangeira nunca atende o §2.7.2.

### 2.2 Lista oficial de ACTs credenciadas

Fonte: ITI — https://www.gov.br/iti/pt-br/assuntos/icp-brasil/autoridades-de-carimbo-do-tempo (página atualizada em 07/05/2025, conforme exibido)

| ACT           | Credenciamento | Processo             |
| ------------- | -------------- | -------------------- |
| ACT CAIXA     | 24/01/2013     | 00100.000124/2012-17 |
| ACT SERPRO    | 15/10/2013     | 00100.000036/2013-98 |
| ACT CERTISIGN | 29/01/2014     | 00100.000101/2013-85 |
| ACT VALID     | 14/04/2014     | 00100.000250/2013-44 |
| ACT BRY       | 15/09/2014     | 00100.000224/2013-16 |
| ACT QUICKSOFT | 15/10/2014     | 00100.000225/2013-61 |
| ACT SAFEWEB   | 16/12/2014     | 00100.000124/2014-71 |
| ACT SOLUTI    | 22/02/2019     | 00100.000204/2013-45 |
| ACT PRODESP   | 08/01/2021     | 00100.003914/2021-37 |

A página não traz status de descredenciamento. **Reconferir na data da contratação** (a lista muda). Se alguma dessas ACTs estiver descredenciada hoje: **NÃO CONFIRMADO**.

### 2.3 Normativos vigentes (página "Documentos Principais" do ITI)

Fonte: https://www.gov.br/iti/pt-br/assuntos/legislacao/documentos-principais

| Documento     | Versão | Ato           | Assunto                                                  |
| ------------- | ------ | ------------- | -------------------------------------------------------- |
| DOC-ICP-11    | 2.0    | Resolução 171 | Visão geral do sistema de carimbos do tempo              |
| DOC-ICP-11.01 | 1.1    | IN 2020/17    | Rede de carimbo do tempo — recursos técnicos             |
| DOC-ICP-11.02 | 1.0    | —             | Protocolos de auditoria e sincronismo do tempo           |
| DOC-ICP-12    | 2.1    | Resolução 172 | Requisitos mínimos para as DPCT das ACTs                 |
| DOC-ICP-12.01 | 2.0    | IN 14/2020    | Perfil do alvará do carimbo do tempo                     |
| DOC-ICP-13    | 1.2    | Resolução 173 | Requisitos mínimos para as Políticas de Carimbo do Tempo |
| DOC-ICP-14    | 1.2    | Resolução 174 | Procedimentos para auditoria do tempo                    |
| DOC-ICP-15    | 4.0    | Resolução 182 | Visão geral sobre assinaturas digitais                   |
| DOC-ICP-15.01 | 4.0    | IN 01/2021    | Requisitos para geração e verificação de assinaturas     |
| DOC-ICP-15.02 | 4.0    | IN 02/2021    | Perfil de uso geral para assinaturas                     |
| DOC-ICP-15.03 | 9.1    | IN 2021/03    | Requisitos das políticas de assinatura digital           |

### 2.4 O que o DOC-ICP-15.03 exige de um PAdES ICP-Brasil (impacta o §3.6)

Fonte: DOC-ICP-15.03 v9.1 — https://www.gov.br/iti/pt-br/assuntos/legislacao/documentos-principais/v9.1_IN2021_03_DOCICP15.03_compilada.pdf (texto extraído do PDF com pypdf; conferir as tabelas visualmente antes de implementar)

- Políticas PAdES e OIDs, com a versão mais alta listada no documento:
    - AD-RB (referência básica, PDF) até v1.3 = `2.16.76.1.7.1.11.1.3`
    - AD-RT (referência do tempo, PDF) até v1.3 = `2.16.76.1.7.1.12.1.3`
    - AD-RC (referências completas, PDF) até v1.4 = `2.16.76.1.7.1.13.1.4`
    - AD-RA (referências para arquivamento, PDF) até v1.4 = `2.16.76.1.7.1.14.1.4`
    - Quais versões estão hoje na LPA (Lista de Políticas de Assinatura Aprovadas) e qual hash (`sigPolicyHash`) usar: **NÃO CONFIRMADO**; obter a LPA no repositório do ITI.
- O atributo assinado `SignaturePolicyIdentifier` (`id-aa-ets-sigPolicyId`) aparece como **O (obrigatório)** em todas as colunas da tabela de atributos assinados. A mesma tabela exige `SigningCertificateV2` a partir da versão 2.1 das políticas.
- O carimbo do tempo de assinatura (`id-aa-signatureTimeStampToken`) aparece como **O** a partir de AD-RT. O controle de alterações registra que, na AD-RB, ele passou de opcional para "não deve".
- Tabela A.22 (dicionários PAdES): `DocTimeStamp` com `SubFilter ETSI.RFC3161`; DSS e _Document Time-stamp_ são P/P/O/O e VRI é P*/P*/O/O, sempre na ordem RB/RT/RC/RA. Nota: "Caso seja utilizado DSS para os formatos RB e RT, deve-se usar o VRI."
- **Inferência nossa (a validar no Verificador do ITI):** o `pdftool sign` atual (PAdES B-B, sem `SignaturePolicyIdentifier`) provavelmente **não** é reconhecido como AD-RB ICP-Brasil, mesmo com A1 ICP-Brasil. O "PAdES-B-B" que a UI exibe é o perfil ETSI, não uma política ICP-Brasil. Não chamar de AD-RB/AD-RT/AD-RA sem aprovação do Verificador (T1/T2).
- O pyHanko consegue **embutir** o atributo: `CAdESSignedAttrSpec.signature_policy_identifier` (código-fonte local `pyhanko/sign/ades/api.py`). A docstring avisa: "pyHanko does not 'understand' signature policies ... It is the API user's responsibility to make sure that all relevant..." — ou seja, cumprir a política (atributos, algoritmos, DSS/VRI) fica por nossa conta.

### 2.5 Contratação comercial

**SERPRO (ACT SERPRO)** é o único caso com documentação pública técnica encontrada:

- Página da ACT: https://www.serpro.gov.br/links-fixos-superiores/pss-serpro/actserpro. O solicitante é identificado e autenticado "através da apresentação de um certificado ICP-Brasil válido, previamente cadastrado"; requisição TSQ com SHA-256 e resposta TSR com a hora legal brasileira; 4 SCTs, até 200 req/s cada; precisão de 500 ms; DPCT/PCT disponíveis. Contato comercial: `ccd@serpro.gov.br`. A porta TCP 318 (transporte TCP da RFC 3161) aparece na DPCT (https://repositorio.serpro.gov.br/docs/dpctactserpro.pdf); vi isso só no resumo da busca, não abri o PDF → **NÃO CONFIRMADO** no detalhe.
- Serviço no gov.br: https://www.gov.br/pt-br/servicos/contratar-emissao-de-carimbo-do-tempo. Prestado pelo SERPRO a empresas privadas, órgãos públicos e demais segmentos; exige e-CNPJ; custo "valor contratado"; cita "Sistemas de Carimbo do Tempo (SCT) que são auditados e sincronizados com Sistemas de Auditoria e Sincronismo da ICP-Brasil".
- **API Carimbo do Tempo (gateway SERPRO)**: https://doc-apitimestamp.estaleiro.serpro.gov.br/
    - Autenticação (https://doc-apitimestamp.estaleiro.serpro.gov.br/quick_start/): OAuth2 `client_credentials`. `POST https://gateway.apiserpro.serpro.gov.br/token` com `Authorization: Basic base64(ConsumerKey:ConsumerSecret)` e `Content-Type: application/x-www-form-urlencoded`; responde `access_token` (Bearer) com `expires_in` na casa de 1 h (exemplo: 3295). Consumer Key/Secret ficam em https://cliente.serpro.gov.br.
    - Endpoints (https://doc-apitimestamp.estaleiro.serpro.gov.br/endpoints/):
        - `https://gateway.apiserpro.serpro.gov.br/apitimestamp/v1/stamps` — carimbo para um hash
        - `.../apitimestamp/v1/decoded-stamps` — carimbo para um hash, com resposta decodificada
        - `.../apitimestamp/v1/stamps-asn1` — carimbo para um "ASN.1 DER-encoded Time-Stamp Query message"
    - Método HTTP, `Content-Type`, formato exato do corpo e da resposta (DER puro ou JSON com base64), códigos de erro, limites e se a página de endpoints afirma explicitamente que o carimbo é da ACT SERPRO ICP-Brasil: **NÃO CONFIRMADO** (a página lida não detalha isso; existem subpáginas `codigos_erros/` e `demonstracao/` não lidas).
    - Contratação (https://doc-apitimestamp.estaleiro.serpro.gov.br/como_contratar/): pela loja https://www.loja.serpro.gov.br/carimbodetempo/; empresas privadas precisam de e-CNPJ ("É necessário o uso do certificado digital e-CNPJ"); sem e-CNPJ há um formulário manual mais demorado; chaves liberadas na Área do Cliente após a assinatura do contrato.
    - **Preço por carimbo: NÃO CONFIRMADO** (a loja respondeu 403 ao acesso automatizado).
- **Demais ACTs (CAIXA, Certisign, Valid, BRy, Quicksoft, Safeweb, Soluti, Prodesp)**: se têm API pública documentada, e com qual autenticação e preço: **NÃO CONFIRMADO**. A BRy anuncia uma "API de Carimbo do Tempo" só em blog próprio (https://www.bry.com.br/blog/api-de-carimbo-do-tempo/, material de marketing do fornecedor, sem documentação técnica); preço sob consulta. As PCTs da Certisign, Valid e Prodesp aparecem nos resultados de busca, mas não foram lidas.

Encaixe no pyHanko: o endpoint `stamps-asn1` parece o candidato natural para `HTTPTimeStamper(url, headers={"Authorization": "Bearer ..."})`, ou para uma subclasse com `async_request_headers()` que renova o token. Isso **só funciona se** o endpoint aceitar o TSQ em DER e devolver o `TimeStampResp` em DER. Se a resposta vier embrulhada em JSON, basta uma subclasse de `TimeStamper` que sobrescreva `async_request_tsa_response` (é o que o `DummyTimeStamper` faz). Qual das duas situações vale: **NÃO CONFIRMADO** até a homologação.

---

## 3. PAdES B-T, B-LT e B-LTA com pyHanko 0.37

Definições dos níveis: ETSI EN 319 142-1 V1.2.1 (2024-01), https://www.etsi.org/deliver/etsi_en/319100_319199/31914201/01.02.01_60/en_31914201v010201p.pdf. B-T acrescenta um token confiável provando que a assinatura existia em uma data; B-LT embute todo o material de validação; B-LTA acrescenta carimbos de documento para validação muito tempo depois. DSS seguido de _document time-stamp_.

### 3.1 Parâmetros (código-fonte local `pyhanko/sign/signers/pdf_signer.py`; guia https://docs.pyhanko.eu/en/latest/lib-guide/signing.html)

| Parâmetro                                                                    | Semântica (docstring da versão instalada)                                                                                                                                                                                                                                                                                                              | Nível      |
| ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------- |
| `PdfSigner(..., timestamper=...)` / `signers.sign_pdf(..., timestamper=...)` | Obtém o token RFC 3161 e o grava como carimbo da assinatura (atributo não assinado). O guia cita `HTTPTimeStamper`.                                                                                                                                                                                                                                    | **B-T**    |
| `PdfSignatureMetadata.subfilter=SigSeedSubFilter.PADES`                      | Já usado hoje no `sign.py`.                                                                                                                                                                                                                                                                                                                            | todos      |
| `embed_validation_info: bool = False`                                        | "Flag indicating whether validation info (OCSP responses and/or CRLs) should be embedded or not ... This flag requires `validation_context` to be set." Com `PADES`, a informação vai para o **DSS**; com `ADOBE_PKCS7_DETACHED`, vai dentro do CMS.                                                                                                   | **B-LT**   |
| `validation_context: ValidationContext`                                      | "If provided, the signer's certificate and any timestamp certificates will be validated before signing. This parameter is mandatory when `embed_validation_info` is True."                                                                                                                                                                             | B-LT/B-LTA |
| `use_pades_lta: bool = False`                                                | "the signer will append an additional document timestamp after writing the signature's validation information to the document security store (DSS)" — só com `PADES`.                                                                                                                                                                                  | **B-LTA**  |
| `timestamp_field_name`                                                       | Nome do campo do carimbo de documento (padrão: gerado com `uuid`).                                                                                                                                                                                                                                                                                     | B-LTA      |
| `dss_settings: DSSContentSettings`                                           | `include_vri=True` por padrão. A docstring chama o VRI de "relic of the past that is effectively deprecated in the current PAdES standards", mas o DOC-ICP-15.03 ainda o pede (§2.4) → **manter `include_vri=True`**. `placement` padrão: `TOGETHER_WITH_NEXT_TS`; `TOGETHER_WITH_SIGNATURE` gera B-LT numa revisão só, mas exige `include_vri=False`. | B-LT       |
| `cades_signed_attr_spec: CAdESSignedAttrSpec`                                | `signature_policy_identifier` (política ICP-Brasil); `timestamp_content` (carimbo de conteúdo **assinado**, diferente do carimbo de assinatura).                                                                                                                                                                                                       | ICP-Brasil |

Esboço de uso (não é código do projeto; nomes conforme a versão instalada):

```python
vc = ValidationContext(trust_roots=[...], allow_fetching=True)          # ou crls=/ocsps= pré-coletados
meta = signers.PdfSignatureMetadata(
    field_name=name, md_algorithm="sha256", subfilter=fields.SigSeedSubFilter.PADES,
    validation_context=vc, embed_validation_info=True, use_pades_lta=True,   # B-LTA
)
signers.PdfSigner(meta, signer, timestamper=HTTPTimeStamper(tsa_url, headers=...)).sign_pdf(writer, output=outf)
```

### 3.2 Carimbo de documento e re-carimbo (LTA)

Código-fonte local `pdf_signer.py`:

- `PdfTimeStamper(timestamper, field_name=None, ...)`.
- `timestamp_pdf(pdf_out, md_algorithm, validation_context=None, bytes_reserved=None, validation_paths=None, timestamper=None, *, in_place=False, output=None, dss_settings=TimestampDSSContentSettings(), ...)` → cria um _document timestamp_ numa revisão incremental; com `validation_context`, "This validation context will also be used to update the DSS".
- `update_archival_timestamp_chain(reader, validation_context, in_place=True, output=None, chunk_size=..., default_md_algorithm=...)` → "Validate the last timestamp in the timestamp chain on a PDF file, and write an updated version to an output stream." É a base do `RefreshArchiveTimestampJob` (roadmap §3.6).
- Validação posterior para adicionar LTV ("ltvfix"): `pyhanko.sign.validation.add_validation_info` / `async_add_validation_info(embedded_sig, validation_context, skip_timestamp=False, add_vri_entry=True, in_place=False, output=None, force_write=False, ...)` — "Add validation info (CRLs, OCSP responses, extra certificates) for a signature to the DSS of a document in an incremental update." (código-fonte local `pyhanko/sign/validation/dss.py`).
- Validação AdES/LTA existe no pacote (`pyhanko/sign/validation/ades.py`: `ades_basic_validation`, `ades_lta_validation`). O guia está em https://docs.pyhanko.eu/en/latest/lib-guide/validation/index.html (seção "The AdES validation engine").

**CLI:** desde 0.37.0, "pyHanko's CLI is no longer bundled together with the library. This functionality is now distributed separately as `pyhanko-cli`" (https://pypi.org/project/pyHanko/0.37.0/, release de 31/08/2026). `pyhanko-cli` 0.5.0 exige `pyhanko>=0.37.0,<0.38` (https://pypi.org/pypi/pyhanko-cli/json) e **não está instalado** no venv. Comandos documentados: `pyhanko sign addsig --timestamp-url ... --with-validation-info --use-pades`, `pyhanko sign ltaupdate --timestamp-url ...` (https://docs.pyhanko.eu/en/latest/cli-guide/signing.html), `pyhanko sign ltvfix --field Sig1` e `pyhanko sign validate --pretty-print` (https://docs.pyhanko.eu/en/latest/cli-guide/validation.html). **Decisão:** o pdftool usa a biblioteca; a CLI fica só como ferramenta de conferência manual, se instalada.

### 3.3 O que exige rede

| Operação                       | Rede?                                                                                                                                                                                                                                               | Fonte                                                                                                                                |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| B-T (carimbo de assinatura)    | Sim, até a TSA (HTTP). No teste: `DummyTimeStamper`, sem rede.                                                                                                                                                                                      | §1.3                                                                                                                                 |
| B-LT com `allow_fetching=True` | Sim, até os OCSP/CRL de **toda** a cadeia: signatário, cadeia da TSA e (no LTA) a TSA do carimbo de documento. `allow_fetching`: "if HTTP requests should be made to fetch CRLs and OCSP responses".                                                | código-fonte local `pyhanko_certvalidator/context.py`; guia de assinatura (https://docs.pyhanko.eu/en/latest/lib-guide/signing.html) |
| B-LT sem rede                  | Possível: `ValidationContext(crls=[...], ocsps=[...])` com CRL/OCSP pré-coletados (parâmetros documentados no mesmo `context.py`). Com `revocation_mode` essencial, `allow_fetching=False` e sem `crls`/`ocsps`, o construtor levanta `ValueError`. | código-fonte local                                                                                                                   |
| `revocation_mode`              | `"soft-fail"` (padrão: erro de busca ignorado), `"hard-fail"`, `"require"`. **Produção B-LT deve usar `hard-fail` ou `require`**; do contrário um OCSP fora do ar gera um "LT" sem revogação.                                                       | código-fonte local                                                                                                                   |
| B-LTA / re-carimbo             | Sim: TSA + revogação do último carimbo.                                                                                                                                                                                                             | §3.2                                                                                                                                 |

Implicações: o worker de finalização passa a fazer rede de saída (TSA e OCSP/CRL de ACs). Isso colide com a regra "Nada de transação aberta durante chamada externa" (`arquitetura.md` §3.3), que já é seguida, e exige timeout, retentativa idempotente e degradação explícita para B-B, registrada (roadmap §3.6 aceite). Teste automatizado sem rede: AC de teste própria + CRL gerada localmente passada em `crls=`, e `DummyTimeStamper` com `fixed_dt`.

---

## 4. Validação externa

| Ferramenta                             | O que é                                                                                                                                                                                                                                                                                                                                                                                                                                                   | Situação                                                                                                                                                                                                                                                                                        | Fonte                                                                                                                                                                                                                                                                                                     |
| -------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Verificador de Conformidade do ITI** | Serviço gratuito; verifica CAdES, XAdES e PAdES contra o DOC-ICP-15; resultados Aprovado / Reprovado / Indeterminado (ETSI EN 319 102-1); verificação simplificada ou completa (políticas, carimbos, atributos); informa que não armazena os arquivos.                                                                                                                                                                                                    | Web: (a) público. API: existe página "API Verificador de Conformidade" no ITI; **endpoint, contrato e limites NÃO CONFIRMADOS** (as páginas não abriram por DNS/conteúdo restrito; um resultado de busca cita `https://verificador.staging.iti.br/report` como endpoint POST, sem confirmação). | https://www.gov.br/pt-br/servicos/verificador-de-conformidade-de-assinaturas-digitais-icp-brasil ; https://www.gov.br/iti/pt-br/centrais-de-conteudo/aplicativos/verificador-de-conformidade-do-padrao-de-assinatura-digital-icp-brasil/api-verificador-de-conformidade ; https://verificador.iti.gov.br/ |
| **VALIDAR (ITI)**                      | Valida assinaturas ICP-Brasil e gov.br; para âncora desconhecida, diz que é "assinatura eletrônica, mas desconhece sua âncora de confiança" → "Assinatura Desconhecida"/indeterminado.                                                                                                                                                                                                                                                                    | Web público; API **NÃO CONFIRMADA**.                                                                                                                                                                                                                                                            | https://validar.iti.gov.br/duvidas.html                                                                                                                                                                                                                                                                   |
| **DSS (Comissão Europeia)**            | Biblioteca Java de código aberto (https://github.com/esig/dss) com webapp de demonstração: validação de assinatura e de documento (assinatura e carimbo), política de validação customizável, REST/SOAP. Aviso: "Usage of this demonstration should be limited to testing purposes only"; recomenda não enviar material sensível. Só referência técnica (ETSI); a confiança padrão vem das listas da UE, que não incluem a ICP-Brasil (inferência nossa). | Referência. Rodar o DSS localmente em CI é possível tecnicamente; licença e esforço **NÃO CONFIRMADOS**.                                                                                                                                                                                        | https://ec.europa.eu/digital-building-blocks/DSS/webapp-demo/home                                                                                                                                                                                                                                         |
| `openssl ts -verify`                   | Confere `.tsr` × hash × cadeia, localmente.                                                                                                                                                                                                                                                                                                                                                                                                               | Já é critério de aceite do §2.13.                                                                                                                                                                                                                                                               | https://docs.openssl.org/3.0/man1/openssl-ts/                                                                                                                                                                                                                                                             |

O que esperar de cada `tsa_kind` no Verificador do ITI (inferência, a confirmar com fixtures): carimbo `operator` ou `commercial` → no máximo Indeterminado/Reprovado para AD-RT, porque a âncora do carimbo não é ICP-Brasil. Por isso, o check de validação externa do §2.13 é `openssl ts -verify` + pyHanko, não o ITI.

---

## 5. Classificação de disponibilidade

| Item                                                                                             | Classe                                                                 | Observação                                                     |
| ------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------- | -------------------------------------------------------------- |
| RFC 3161 + RFC 5816 (implementar cliente/servidor)                                               | (a) padrão público                                                     | —                                                              |
| pyHanko `HTTPTimeStamper`, `DummyTimeStamper`, B-T/B-LT/B-LTA, `update_archival_timestamp_chain` | (a) biblioteca MIT documentada e instalada                             | CLI separada (`pyhanko-cli`).                                  |
| TSA própria do operador                                                                          | (a) tecnicamente; sem dependência externa                              | Exige chave protegida, NTP, OID e certificado com EKU crítica. |
| Carimbo ICP-Brasil — ACT SERPRO via API gateway                                                  | **(b)** documentação pública, mas exige contrato, e-CNPJ e credenciais | Formato exato do corpo/resposta e preço **NÃO CONFIRMADOS**.   |
| Carimbo ICP-Brasil — demais ACTs                                                                 | (b) presumido (serviço contratado)                                     | API pública **NÃO CONFIRMADA** para todas.                     |
| Verificador do ITI — web                                                                         | (a)                                                                    | Manual por release (roadmap §3.6 aceite).                      |
| Verificador do ITI — API                                                                         | (b)/(c) **NÃO CONFIRMADO**                                             | Não depender dela em CI até confirmar.                         |
| DSS da UE — demo                                                                                 | (a) só para teste                                                      | Nunca com documento real de cliente.                           |

---

## 6. Encaixe no AssinaVelox (proposta)

- `App\Integrations\TimestampProvider` (contrato reservado): `stamp(string $sha256Hex, string $idempotencyKey): TimestampResult` → `{status: granted|rejected|unknown, tsr_der, gen_time, serial, policy_oid, tsa_cert_fingerprint, tsa_kind}`. Timeout = `unknown` (T5): consulta/reenvio com o mesmo hash **não** cria duplicata semântica, porque cada token é uma prova independente; gravar só o primeiro `granted`.
- Implementações: `FakeTimestampProvider` (identificado; usa `DummyTimeStamper` via pdftool; `tsa_kind=operator`, `environment=test`), `Rfc3161TimestampProvider` (URL configurável: TSA do operador ou comercial; `tsa_kind` vem da **config**, nunca do conteúdo da resposta) e `IcpBrasilTimestampProvider` (SERPRO ou outra ACT; só esse grava `icp_brasil`, e só após a checagem da cadeia até a raiz ICP-Brasil).
- Dois caminhos distintos no pdftool:
    1. `.tsr` destacado sobre o **hash final** (§2.13). Não altera o PDF; entra no dossiê; verificação com `openssl ts -verify`.
    2. `pdftool sign --timestamp-url ... --ltv --lta` (§3.6), com a credencial da TSA por variável de ambiente (T10). Valores de `signature_profile` só depois do T2.
- Atenção à ordem: um _document timestamp_ ou uma atualização de DSS é revisão incremental. Deve ser a **última** etapa da finalização; no `crypto_mode=incremental` (§2.12), acontece depois da última assinatura de participante e da assinatura da operadora. O hash final continua sendo calculado **depois** (arquitetura §5.7). O re-carimbo LTA gera um novo arquivo → nova `document_version` (`kind=final`, `version_number+1`) e novo hash em `verification_records`. Isso afeta a página de verificação: ela deve aceitar a lista de hashes finais históricos, senão um arquivo baixado antes do re-carimbo passa a "não conferir". **Decisão pendente de produto.**

---

## 7. Itens NÃO CONFIRMADOS (consolidado)

1. Preço por carimbo do SERPRO e de qualquer outra ACT.
2. Formato exato (método, `Content-Type`, corpo, resposta DER vs JSON) dos endpoints `apitimestamp/v1/stamps*` do SERPRO, e se a página de endpoints declara emissão pela ACT SERPRO ICP-Brasil.
3. Existência e documentação de API pública das ACTs CAIXA, Certisign, Valid, BRy, Quicksoft, Safeweb, Soluti e Prodesp.
4. Status atual (ativa/descredenciada) de cada ACT depois de 07/05/2025.
5. Endpoint, autenticação e limites da API do Verificador de Conformidade do ITI (inclusive a URL de staging citada só em resultado de busca).
6. Versões das políticas PAdES ICP-Brasil hoje na LPA e seus `sigPolicyHash`; conferência visual das tabelas do DOC-ICP-15.03 (a extração foi por texto).
7. Obtenção de OID de política para a TSA do operador (PEN IANA ou outro arco) e compra de certificado de TSA de AC comercial.
8. Detalhe da porta TCP 318 e da autenticação por certificado ICP-Brasil no canal direto da ACT SERPRO (visto só no resumo da DPCT).
9. Licença e custo de rodar o DSS da UE localmente em CI.
10. Comportamento real do Verificador do ITI diante de carimbo `operator` e do B-B atual sem `SignaturePolicyIdentifier` (hipótese: não aprova).

---

## Decisão recomendada para o AssinaVelox

| Item                                                                                                 | Decisão                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     | Justificativa                                                                                                                                                                                                                                                                                                                                                                   |
| ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **§2.13 — `TimestampProvider` + TSA do operador (`tsa_kind=operator`) + `.tsr` destacado no dossiê** | **Implementar de verdade agora.** Produção atrás de flag até cumprir o checklist operacional (chave protegida, NTP monitorado com `timeNotAvailable`, OID de política, certificado com EKU `timeStamping` crítica, serial persistido).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | Tudo é padrão aberto (RFC 3161/5816) com bibliotecas já instaladas (asn1crypto, cryptography, pyHanko). Não depende de credencial externa. O aceite (`openssl ts -verify`) é local e automatizável. O `DummyTimeStamper` é o fake identificado dos testes, mas **não** serve como TSA de produção (política fixa alheia, serial aleatório, ESSCertID SHA-1, só RSA).            |
| **PAdES B-T / B-LT / B-LTA no `pdftool sign`**                                                       | **Implementar o código agora**, testado offline (`DummyTimeStamper` + AC de teste + `crls=` pré-coletadas, `revocation_mode="hard-fail"`). **UI e `signature_profile` continuam em `PAdES-B-B`** até o T2 passar com fixtures reais e validação externa.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | A API do pyHanko 0.37 cobre os três níveis (`timestamper`, `embed_validation_info` + `validation_context`, `use_pades_lta`, `update_archival_timestamp_chain`). O risco está na rede (OCSP/CRL, TSA) e na conformidade externa, não na biblioteca. B-T com TSA do operador é B-T no sentido ETSI, mas deve aparecer como "carimbo do tempo do operador", nunca como ICP-Brasil. |
| **§3.6 — carimbo ICP-Brasil via ACT credenciada**                                                    | **Contrato + fake identificado, com produção desabilitada** (`IcpBrasilTimestampProvider` responde erro "não configurado"; fake nunca grava `icp_brasil`). **Bloqueado para produção** até: (1) contrato com uma ACT da lista do ITI — o SERPRO é o caminho mais documentado; exige e-CNPJ e Consumer Key/Secret; (2) homologação confirmando o formato de `apitimestamp/v1/stamps-asn1` (ou subclasse de `TimeStamper` para JSON), com o token OAuth2 renovado a cada ~1 h e passado por variável de ambiente; (3) preço por carimbo, que define `timestamp_quota` por plano; (4) inclusão do `SignaturePolicyIdentifier` da política PAdES ICP-Brasil vigente na LPA (via `CAdESSignedAttrSpec`) e aprovação no Verificador de Conformidade do ITI com fixtures reais; (5) decisão de produto sobre re-carimbo LTA gerar novo hash final. | Há documentação oficial (T4 atendido parcialmente), mas o uso exige contrato e credenciais que não temos. Formato de resposta e preço não estão confirmados. Pelo DOC-ICP-11 §2.7.2, só SCT de ACT credenciada, auditado e sincronizado, produz carimbo aceito na ICP-Brasil: nenhuma TSA própria ou comercial pode ocupar esse papel, nem provisoriamente.                     |
