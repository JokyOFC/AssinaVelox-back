# e-Notariado e referências regulatórias — brief para roadmap §3.8, §3.3 e §3.7

> Pesquisa técnica/regulatória, não parecer jurídico. Identificadores em inglês; prosa em português.
> Data da pesquisa: 2026-09-11. Itens do roadmap cobertos: §3.8 (e-Notariado), §3.3 (aceite por vídeo/foto), §3.7 (antifraude) e, por tabela, §2.10 (selfie/documento) e a regra T1 (`arquitetura.md` §2).
> Convenções: **(a)** API pública e documentada · **(b)** API existe, mas exige credenciamento/contrato/elegibilidade · **(c)** não há API pública. **NÃO CONFIRMADO** = não achei em fonte oficial; não usar como fato.

## 0. Método e limitações

- Textos legais extraídos do Planalto (`planalto.gov.br`) e do CNJ (`atos.cnj.jus.br`) por download direto. O WebFetch recebia `ECONNRESET`/`403` nesses domínios; o conteúdo foi lido do HTML/PDF oficial baixado.
- O Provimento CNJ 149/2023 (Código Nacional de Normas) foi lido na **versão compilada** publicada pelo CNJ em `https://atos.cnj.jus.br/files/compilado1806222023111665565a1e0fc83.pdf` (nome do arquivo sugere compilação de 16/11/2023). **NÃO CONFIRMADO** se alterações posteriores renumeraram ou mudaram os arts. 284–319 citados abaixo. Antes de citar em texto jurídico do produto, conferir a compilação vigente em `https://atos.cnj.jus.br/atos/detalhar/5243`.
- Artigos da base de conhecimento do CNB (`suporte.notariado.org.br`) são documentação oficial do operador da plataforma, mas mudam com frequência: as versões/datas vistas estão anotadas. As páginas `notariado.org.br/e-notariado/` e `notariado.org.br/e-not-assina/` retornaram `403` e não foram lidas.
- Textos de lei e de atos oficiais são citados literalmente. Não há direito autoral sobre eles: Lei 9.610/1998, art. 8º, IV.

---

## 1. e-Notariado

### 1.1 Base normativa

| Ato                                                                                | Situação                                                                                                                                                                                             | Fonte                                                                                                                              |
| ---------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Provimento CNJ 100, de 26/05/2020                                                  | **Revogado**. A ficha do CNJ diz "Situação: Revogado" e cita como revogador o "Provimento n. 149, de 30 de agosto de 2023"                                                                           | https://atos.cnj.jus.br/atos/detalhar/3334                                                                                         |
| Provimento CNJ 149, de 30/08/2023 (Código Nacional de Normas – Foro Extrajudicial) | Vigente; incorporou o conteúdo do Prov. 100 na Parte Geral, Livro sobre Tabelionato de Notas, "Seção II — Dos atos notariais eletrônicos por meio do e-Notariado" (arts. 284–319 na compilação lida) | https://atos.cnj.jus.br/atos/detalhar/5243 · PDF compilado: https://atos.cnj.jus.br/files/compilado1806222023111665565a1e0fc83.pdf |

Dispositivos que definem o espaço do produto (numeração do Prov. 149 compilado; entre parênteses, o artigo equivalente do Prov. 100):

- **Quem mantém a plataforma.** Art. 291 (art. 8º): "O Sistema de Atos Notariais Eletrônicos, e-Notariado, será implementado e mantido pelo Colégio Notarial do Brasil - Conselho Federal, CNB-CF [...]".
- **Requisitos do ato notarial eletrônico.** Art. 286 (art. 3º): "I — videoconferência notarial para captação do consentimento das partes sobre os termos do ato jurídico; II — concordância expressada pelas partes com os termos do ato notarial eletrônico; III — assinatura digital pelas partes, exclusivamente por meio do e-Notariado; IV — assinatura do tabelião de notas com a utilização de certificado digital ICP-Brasil; e V — uso de formatos de documentos de longa duração com assinatura digital."
- **Plataforma obrigatória.** Art. 287 (art. 4º): o notário lavra o ato eletrônico usando "a plataforma e-Notariado, por meio do link www.e-notariado.org.br".
- **Vedação.** Art. 318 (art. 36): "É vedada a prática de atos notariais eletrônicos ou remotos com recepção de assinaturas eletrônicas a distância sem a utilização do e-Notariado."
- **Certificado notarizado.** Art. 285, II (art. 2º, II): "certificado digital notarizado: identidade digital de uma pessoa física ou jurídica, identificada presencialmente por um notário a quem se atribui fé pública".
- **Acesso e outras plataformas.** Art. 292, caput e §4º (art. 9º): o acesso ao e-Notariado é feito "com assinatura digital, por certificado digital notarizado [...] ou, quando possível, por biometria". O certificado notarizado é fornecido gratuitamente "para uso exclusivo e por tempo determinado, na plataforma e-Notariado e nas demais plataformas autorizadas pelo Colégio Notarial Brasil-CF".
- **Exclusividade do tabelião.** Art. 23 do Prov. 100 (equivalente no Prov. 149 não localizado com certeza na compilação — **NÃO CONFIRMADO** o número): compete exclusivamente ao tabelião de notas "reconhecer as assinaturas eletrônicas apostas em documentos digitais".
- **Competência territorial absoluta.** Art. 289 (art. 6º).
- **Efeito.** Art. 312 (art. 29): atos notariais eletrônicos conferidos pelo e-Notariado "constituem instrumentos públicos para todos os efeitos legais".
- **Código-fonte e documentação.** Art. 317 (art. 34): são "de titularidade e propriedade do Colégio Notarial do Brasil - Conselho Federal".

Consequência direta: nenhuma plataforma privada pratica ato notarial, colhe assinatura para ato notarial fora do e-Notariado nem "reconhece firma". O que uma plataforma privada pode fazer é **encaminhar** documento ou fluxo ao e-Notariado pelas APIs abaixo, quando elegível.

### 1.2 APIs existentes e classificação

| Integração                                                              | O que faz                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     | Quem pode usar                                                                                                                                                                                                                                                                                                                                                                                                                                     | Classificação                                                                                                               | Fonte                                                                                                                                                                      |
| ----------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Fluxo de Assinaturas — criação automática pelo sistema do cartório**  | O sistema de gestão do cartório cria o fluxo de assinatura do ato notarial: sobe o PDF/A do ato do livro e o do traslado, define participantes (nome, CPF, e-mail), acompanha o status e baixa os assinados. Endpoints citados: `POST /api/uploads`, `POST /api/documents`, `GET /api/documents`, `GET /api/documents/{id}/ticket`, `PUT /api/documents/{id}/pending-transcript`, `GET /api/parent/cities`. Swagger: `https://assinatura-hml.e-notariado.org.br/swagger/index.html` (homologação) e `https://assinatura.e-notariado.org.br/swagger/index.html` (produção)                     | **Sistemas de gestão de cartório.** A empresa desenvolvedora "deverá previamente firmar um ACT" (Acordo de Cooperação Técnica) para testar em homologação. O acesso à homologação exige certificado digital notarizado ou ICP-Brasil. A API KEY de produção é obtida pelo responsável do cartório                                                                                                                                                  | **(b)** — e fora do escopo do AssinaVelox, que não é sistema de cartório                                                    | https://suporte.notariado.org.br/support/solutions/articles/43000585404-fluxo-de-assinaturas-criac%C3%A3o-autom%C3%A1tica-pelo-sistema-do-cart%C3%B3rio (v2.5, 28/04/2026) |
| **e-Not Assina — integração por API**                                   | Empresas e usuários que submetem documentos ao e-Not Assina (módulo de **reconhecimento de assinatura eletrônica**) criam os fluxos automaticamente a partir de sistema próprio. Endpoints no artigo: `GET /api/organizations/current-service-organization/{cpf}` (verifica se o CPF tem certificado notarizado), `POST /api/uploads`, `POST /api/documents/e-not-assina`, `GET /api/documents/{id}`, `GET /api/documents/{id}/ticket`, `PUT /api/documents/{documentId}/canceled`. Host `https://assinatura.e-notariado.org.br`. O changelog cita webhooks e cancelamento na v4 (24/10/2025) | Empresa **cadastrada** no e-Not Assina. A v4.2 (11/07/2026) diz que "não é mais necessário a empresa ser mensalista de um cartório, basta estar cadastrada no sistema". A chave de integração é gerada por organização no próprio e-Not Assina (Outras opções → Integração) e vai no header `Authorization` com o prefixo da empresa. **Requisito duro:** "É necessário que todos os signatários tenham emitido um certificado digital notarizado" | **(b)** — documentação pública, mas elegibilidade por cadastro e certificado notarizado de cada signatário                  | https://suporte.notariado.org.br/support/solutions/articles/43000699665-e-not-assina-empresas-mensalistas-integrac%C3%A3o-por-api                                          |
| **e-Not Assina — cadastro de mensalistas**                              | O tabelião cadastra a empresa (CNPJ) como mensalista em `cadastro.notariado.org.br`. O responsável indicado vira administrador e inclui usuários. Os fluxos são cobrados em fatura mensal consolidada                                                                                                                                                                                                                                                                                                                                                                                         | Empresas cadastradas por um tabelião                                                                                                                                                                                                                                                                                                                                                                                                               | Processo administrativo, não API                                                                                            | https://suporte.notariado.org.br/support/solutions/articles/43000663356-e-not-assina-cadastramento-de-mensalistas                                                          |
| **e-Not Assina — valores**                                              | O cliente paga os emolumentos por reconhecimento de cada assinatura eletrônica, informados pelo tabelião, mais despesas de cobrança conforme o meio de pagamento                                                                                                                                                                                                                                                                                                                                                                                                                              | —                                                                                                                                                                                                                                                                                                                                                                                                                                                  | Valores concretos **NÃO CONFIRMADOS** (dependem do tabelião/UF; a página não foi lida integralmente — só o resumo da busca) | https://suporte.notariado.org.br/support/solutions/articles/43000665062-e-not-assina-valores                                                                               |
| **Lavratura de escritura, ata notarial, procuração pública eletrônica** | Ato do tabelião com videoconferência notarial                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | Somente tabelião, no e-Notariado (arts. 286, 287 e 318 do Prov. 149)                                                                                                                                                                                                                                                                                                                                                                               | **(c)** para plataformas privadas                                                                                           | Prov. 149 compilado (link acima)                                                                                                                                           |

### 1.3 O que isso significa para o AssinaVelox

1. **O aceite eletrônico do AssinaVelox não é, nem vira, reconhecimento de firma.** O e-Not Assina exige que cada signatário assine no e-Notariado com certificado digital notarizado. Nosso aceite (e-mail OTP, `SignatureAcceptance`) e nossa assinatura PAdES da operadora (`company_a1`) são outra coisa e ficam fora desse fluxo.
2. **O único caminho documentado viável** é a API do e-Not Assina: enviar ao e-Notariado o PDF que o cliente quer com assinaturas reconhecidas, com os participantes, e acompanhar o status. A assinatura em si acontece **no e-Notariado**, não no AssinaVelox.
3. **Pontos NÃO CONFIRMADOS que bloqueiam o desenho do adaptador:**
    - Se uma **plataforma SaaS multi-tenant** pode consumir a API em nome dos clientes. Pelo artigo, a chave é gerada **por organização cadastrada**; o modelo compatível parece ser "cada organização cliente traz a própria chave" (BYO key), mas os termos de uso não foram localizados.
    - Existência de **sandbox/homologação** para o e-Not Assina. O artigo não publica host de homologação; a menção a `sandbox.parcelaexpress.com.br` refere-se a exemplos de pagamento PIX de terceiro.
    - Requisitos de formato do PDF enviado ao e-Not Assina (PDF/A? aceita PDF que já contém assinatura PAdES da operadora e revisões incrementais?).
    - Limites de taxa, catálogo de erros completo, autenticação/assinatura dos webhooks e política de idempotência (T5).
    - Procedimento para uma plataforma ser "autorizada pelo Colégio Notarial Brasil-CF" (art. 292, §4º) — não localizado.
    - Preços e emolumentos por UF.
4. **Riscos já previstos no roadmap e confirmados:** automação de portal é proibida (T4); o produto nunca se apresenta como cartório (art. 318 e art. 23 do Prov. 100). O pré-requisito do §3.8, "papéis notariais exigem certificado ICP-Brasil dos envolvidos", deve ser corrigido: o e-Not Assina exige **certificado digital notarizado** (emitido gratuitamente pelo notário, art. 292, §4º), não necessariamente ICP-Brasil do signatário. O tabelião, este sim, assina com ICP-Brasil (art. 286, IV).

---

## 2. Lei 14.063/2020 — níveis de assinatura eletrônica

Fonte: https://www.planalto.gov.br/ccivil_03/_ato2019-2022/2020/lei/l14063.htm

### 2.1 O que a lei diz

**Âmbito do capítulo que classifica as assinaturas** — art. 2º, parágrafo único: "O disposto neste Capítulo não se aplica: [...] II - à interação: a) entre pessoas naturais ou entre pessoas jurídicas de direito privado; [...]". O capítulo trata de interações **com entes públicos**.

**Classificação** — art. 4º:

> "Art. 4º Para efeitos desta Lei, as assinaturas eletrônicas são classificadas em:
> I - assinatura eletrônica simples:
> a) a que permite identificar o seu signatário;
> b) a que anexa ou associa dados a outros dados em formato eletrônico do signatário;
> II - assinatura eletrônica avançada: a que utiliza certificados não emitidos pela ICP-Brasil ou outro meio de comprovação da autoria e da integridade de documentos em forma eletrônica, desde que admitido pelas partes como válido ou aceito pela pessoa a quem for oposto o documento, com as seguintes características:
> a) está associada ao signatário de maneira unívoca;
> b) utiliza dados para a criação de assinatura eletrônica cujo signatário pode, com elevado nível de confiança, operar sob o seu controle exclusivo;
> c) está relacionada aos dados a ela associados de tal modo que qualquer modificação posterior é detectável;
> III - assinatura eletrônica qualificada: a que utiliza certificado digital, nos termos do § 1º do art. 10 da Medida Provisória nº 2.200-2, de 24 de agosto de 2001.
> § 1º Os 3 (três) tipos de assinatura [...] caracterizam o nível de confiança sobre a identidade e a manifestação de vontade de seu titular, e a assinatura eletrônica qualificada é a que possui nível mais elevado de confiabilidade [...]"

**Nível mínimo definido pelo ente público** — art. 5º, caput: "ato do titular do Poder ou do órgão constitucionalmente autônomo de cada ente federativo estabelecerá o nível mínimo exigido para a assinatura eletrônica em documentos e em interações com o ente público". O §4º manda o ente informar em seu site os requisitos para reconhecer a assinatura avançada.

**Qualificada obrigatória** — art. 5º, §2º: atos de chefes de Poder e Ministros (I), notas fiscais eletrônicas, exceto de pessoa física/MEI (III), "atos de transferência e de registro de bens imóveis" (IV, com ressalva) e "demais hipóteses previstas em lei" (VI). O art. 17-A (Lei 14.620/2023) admite avançada e qualificada nos instrumentos particulares com força de escritura pública das instituições de crédito imobiliário.

**MP 2.200-2/2001, art. 10** (https://www.planalto.gov.br/ccivil_03/mpv/antigas_2001/2200-2.htm):

> "§ 1º As declarações constantes dos documentos em forma eletrônica produzidos com a utilização de processo de certificação disponibilizado pela ICP-Brasil presumem-se verdadeiros em relação aos signatários [...]
> § 2º O disposto nesta Medida Provisória não obsta a utilização de outro meio de comprovação da autoria e integridade de documentos em forma eletrônica, inclusive os que utilizem certificados não emitidos pela ICP-Brasil, desde que admitido pelas partes como válido ou aceito pela pessoa a quem for oposto o documento."

**Referência governamental de apoio:** a página do Governo Digital classifica a assinatura gov.br como avançada ("É o caso da assinatura GOV.BR") — https://www.gov.br/governodigital/pt-br/identidade/assinatura-eletronica/saiba-mais-sobre-a-assinatura-eletronica

### 2.2 Por que o produto não deve se autoclassificar

1. **O nível é atributo do meio concreto usado em cada assinatura, não do software.** A avançada exige três características verificáveis (art. 4º, II, a–c). A alínea "b" — dados de criação que o signatário opera "com elevado nível de confiança, sob o seu controle exclusivo" — não é demonstrável de forma genérica para OTP por e-mail com imagem desenhada. Afirmar "avançada" seria uma alegação técnica sem prova.
2. **A avançada depende de terceiro.** Ela vale "desde que admitido pelas partes como válido ou aceito pela pessoa a quem for oposto o documento" (art. 4º, II; MP 2.200-2, art. 10, §2º). Essa aceitação é decisão da contraparte, não do produto.
3. **Perante ente público, quem decide o nível mínimo é o próprio ente** (art. 5º, caput e §4º). Um órgão pode recusar o que chamamos de "avançada".
4. **Entre particulares, o capítulo classificatório nem se aplica** (art. 2º, parágrafo único, II, "a"). Nesse caso a validade se apoia no Código Civil, art. 107, e na MP 2.200-2, art. 10, §2º (§3 abaixo), não num "selo" da Lei 14.063.
5. **A qualificada exige certificado ICP-Brasil do signatário** (art. 4º, III → MP 2.200-2, art. 10, §1º). O `company_a1` identifica a operadora, não o participante (`arquitetura.md` §2). Ele **não** torna qualificada a assinatura de ninguém.
6. **Não localizei procedimento oficial de acreditação** que certifique uma plataforma privada como emissora de assinatura "avançada" — **NÃO CONFIRMADO** que exista. Sem ele, qualquer rótulo de nível seria autodeclaração.

**Regra de produto (reforça T1):** UI, e-mails, termos, página de verificação e API **descrevem o meio** e **não afirmam nível**. O quadro abaixo mostra como descrever cada meio.

| `signature_status` / meio               | Texto permitido (descreve o meio)                                                                                                   | Proibido                                                                   |
| --------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| `none` + aceite OTP                     | "Aceite eletrônico com evidências (e-mail verificado por código)"                                                                   | "assinatura avançada", "assinatura digital", "com validade de qualificada" |
| `company_a1`                            | "Documento selado com certificado da empresa operadora [titular]"                                                                   | "assinado digitalmente pelos participantes", "ICP-Brasil do signatário"    |
| `participant_govbr` (§3.5, futuro)      | "Assinatura gov.br do participante" + referência factual: "o Governo Federal classifica a assinatura gov.br como avançada" com link | Afirmar por conta própria que é "avançada" em toda hipótese                |
| `participant_icp_brasil` (§3.4, futuro) | "Assinatura com certificado ICP-Brasil do participante [titular, AC]" — só após validar a cadeia                                    | "qualificada" sem validação da cadeia                                      |

---

## 3. Aceite por vídeo/foto (§3.3 e §2.10)

- **Norma específica para vídeo/foto como forma de aceite em contrato privado: NÃO CONFIRMADO — não localizada** em fonte oficial nas buscas desta pesquisa.
- **Base geral aplicável:**
    - Código Civil, art. 107: "A validade da declaração de vontade não dependerá de forma especial, senão quando a lei expressamente a exigir." — https://www.planalto.gov.br/ccivil_03/leis/2002/l10406compilada.htm
    - MP 2.200-2, art. 10, §2º (citado em §2.1).
- **Exceções que o produto precisa respeitar (a forma é exigida):**
    - Código Civil, art. 108: "a escritura pública é essencial à validade dos negócios jurídicos que visem à constituição, transferência, modificação ou renúncia de direitos reais sobre imóveis de valor superior a trinta vezes o maior salário mínimo vigente no País" (mesma URL).
    - Lei 14.063, art. 5º, §2º (qualificada obrigatória perante entes públicos).
    - Demais formas especiais previstas em lei.
- **Não confundir com a "videoconferência notarial"**, que é requisito do **ato notarial** (Prov. 149, art. 286, I e parágrafo único) e ato do tabelião. Um vídeo gravado no AssinaVelox **não** é videoconferência notarial.
- **Jurisprudência (apoio, blogs de terceiros, não oficial):** artigos de mercado relatam que tribunais aceitam selfie/biometria como manifestação de vontade quando acompanhada de outras provas, como IP e geolocalização. Exemplos: https://www.migalhas.com.br/depeso/366739/a-biometria-facial-pode-suprir-a-falta-de-assinatura-em-contratos e https://blog.zapsign.com.br/contrato-assinado-por-biometria-facial/ (blog de concorrente). **NÃO CONFIRMADO** em fonte oficial; não usar em copy de produto.

**Implicação:** vídeo e foto entram como **evidência complementar do aceite** (`identity_captures`), nunca como forma autônoma de assinatura. O vocabulário proibido de §2.10 do roadmap vale igualmente para vídeo: "biometria", "liveness", "identidade verificada", "videoconferência".

---

## 4. LGPD aplicada a selfie, vídeo e imagem de documento

Fonte: https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709compilado.htm

**Definição** — art. 5º, II: "dado pessoal sensível: dado pessoal sobre origem racial ou étnica, convicção religiosa, opinião política, filiação a sindicato ou a organização de caráter religioso, filosófico ou político, dado referente à saúde ou à vida sexual, dado genético ou biométrico, quando vinculado a uma pessoa natural".

**Bases legais** — art. 11: "O tratamento de dados pessoais sensíveis somente poderá ocorrer nas seguintes hipóteses:
I - quando o titular ou seu responsável legal consentir, de forma específica e destacada, para finalidades específicas;
II - sem fornecimento de consentimento do titular, nas hipóteses em que for indispensável para: [...]
d) exercício regular de direitos, inclusive em contrato e em processo judicial, administrativo e arbitral [...];
[...]
g) garantia da prevenção à fraude e à segurança do titular, nos processos de identificação e autenticação de cadastro em sistemas eletrônicos, resguardados os direitos mencionados no art. 9º desta Lei e exceto no caso de prevalecerem direitos e liberdades fundamentais do titular que exijam a proteção dos dados pessoais."

Art. 11, §1º: o artigo se aplica "a qualquer tratamento de dados pessoais que revele dados pessoais sensíveis e que possa causar dano ao titular".

**RIPD** — art. 38: a ANPD "poderá determinar ao controlador que elabore relatório de impacto à proteção de dados pessoais, inclusive de dados sensíveis".

**Estado da regulação:** a ANPD abriu tomada de subsídios sobre dados biométricos (02/06/2025 a 01/08/2025), no item 5 da Agenda Regulatória 2025–2026 — https://www.gov.br/participamaisbrasil/ts-dados-biometricos. **Não localizei regulamento ou guia final publicado** (NÃO CONFIRMADO que exista em 2026-09).

**Pontos NÃO CONFIRMADOS / decisões para o jurídico:**

- Se uma **selfie apenas armazenada** (sem template facial nem comparação automatizada) já é "dado biométrico". A LGPD não define o termo, e a ANPD ainda não concluiu a regulação. **Recomendação:** tratar como sensível por precaução. Como o vídeo contém rosto e voz, receberia o mesmo tratamento.
- **Qual base do art. 11 usar.** Candidatas: consentimento específico e destacado (I), exercício regular de direitos em contrato (II, "d") ou prevenção à fraude na identificação/autenticação (II, "g"). A escolha depende de quem é o controlador: provavelmente a organização cliente que exige a captura, com o AssinaVelox como operador. É decisão jurídica, a registrar antes de ligar a flag.
- Com ou sem determinação da ANPD, elaborar o **RIPD** antes de ativar §2.10/§3.3 é a postura prudente (art. 38).

**Requisitos de produto que decorrem disso** (coerentes com o roadmap §2.10):

- Captura desligada por padrão, habilitada por envelope.
- Aviso destacado antes da captura, com finalidade, quem acessa e retenção.
- Disco privado com criptografia em repouso; retenção curta configurável (§2.19); acesso só do remetente autorizado, com log.
- EXIF removido.
- **Nenhum** processamento de reconhecimento facial sem nova avaliação.
- Imagem nunca no PDF público de evidências; ali vai só o hash.

---

## 5. Antifraude: decisão automatizada e direito de revisão (§3.7)

Fonte: https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709compilado.htm

> "Art. 20. O titular dos dados tem direito a solicitar a revisão de decisões tomadas unicamente com base em tratamento automatizado de dados pessoais que afetem seus interesses, incluídas as decisões destinadas a definir o seu perfil pessoal, profissional, de consumo e de crédito ou os aspectos de sua personalidade. (Redação dada pela Lei nº 13.853, de 2019)
> § 1º O controlador deverá fornecer, sempre que solicitadas, informações claras e adequadas a respeito dos critérios e dos procedimentos utilizados para a decisão automatizada, observados os segredos comercial e industrial.
> § 2º Em caso de não oferecimento de informações de que trata o § 1º deste artigo baseado na observância de segredo comercial e industrial, a autoridade nacional poderá realizar auditoria para verificação de aspectos discriminatórios em tratamento automatizado de dados pessoais.
> § 3º (VETADO)."

**Leitura para o produto:**

- O texto vigente garante **direito de solicitar revisão** e **direito a explicação** (§1º). O §3º está vetado. **NÃO CONFIRMADO**, no texto lido, o conteúdo do dispositivo vetado. O texto vigente não diz que a revisão deve ser feita por pessoa natural. A revisão humana prevista no roadmap §3.7 é, portanto, escolha de produto **acima** do mínimo legal — e deve ser mantida.
- A regra de §3.7 (restringir automaticamente o envio `restricted` até revisão) é uma decisão tomada "unicamente com base em tratamento automatizado" que afeta os interesses dos usuários (pessoas naturais) da organização. Por isso:
    1. **Canal de pedido de revisão** acessível a partir da própria mensagem de erro de `envelopes.send`.
    2. **Explicação** com a regra (`rule_code`) e a evidência minimizada, sem expor limiares que viabilizem burla (o §1º ressalva segredo comercial/industrial).
    3. **Registro da decisão humana** (`risk_reviews`) e prazo de resposta definido internamente.
    4. Nunca invalidar aceites nem evidências já registradas (já é regra do roadmap).
- **Não usar dado sensível** (selfie/vídeo de §2.10/§3.3) como sinal antifraude sem nova base legal e sem RIPD. Se um dia for usado, a base candidata é o art. 11, II, "g", com as ressalvas do próprio dispositivo.

Apoio (governamental, não normativo): a página do Governo Digital orienta o cidadão a pedir revisão diretamente à empresa — https://www.gov.br/governodigital/pt-br/lgpd-pagina-do-cidadao/solicitar-a-revisao-de-decisoes-tomadas-com-base-em-tratamento-automatizado-de-dados-pessoais

---

## 6. Resumo NÃO CONFIRMADO

| #   | Item                                                                                              | Onde procurar para confirmar                         |
| --- | ------------------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| 1   | Se um SaaS multi-tenant pode usar a API do e-Not Assina em nome de clientes; termos de uso da API | Contato formal com o CNB-CF / suporte e-Notariado    |
| 2   | Sandbox/homologação do e-Not Assina                                                               | Idem                                                 |
| 3   | Formato aceito pelo e-Not Assina (PDF/A? PDF já assinado com PAdES e revisões incrementais?)      | Swagger de produção/homologação, após credenciamento |
| 4   | Limites de taxa, erros, autenticação de webhooks, idempotência                                    | Swagger / suporte                                    |
| 5   | Procedimento de "plataforma autorizada pelo CNB-CF" (Prov. 149, art. 292, §4º)                    | CNB-CF                                               |
| 6   | Valores e emolumentos do e-Not Assina por UF                                                      | Tabelião / página "e-Not Assina - Valores"           |
| 7   | Numeração vigente dos arts. 284–319 do Prov. 149 após alterações posteriores a 11/2023            | Compilação vigente em atos.cnj.jus.br                |
| 8   | Norma específica sobre aceite por vídeo/foto em contrato privado                                  | Não localizada                                       |
| 9   | Selfie simples = "dado biométrico"? Guia/regulamento final da ANPD sobre biometria                | ANPD (Agenda Regulatória 2025–2026, item 5)          |
| 10  | Existência de acreditação oficial de plataforma privada como "avançada"                           | Não localizada                                       |

---

## Decisão recomendada para o AssinaVelox

| Tema                                              | Decisão                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | Justificativa                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| ------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **§3.8 e-Notariado — adaptador `NotaryProvider`** | **Bloqueado.** Implementar agora só a parte sem integração: exportar o dossiê (§2.13) e orientar o usuário, com texto factual: "para reconhecimento de assinatura por tabelião, use o e-Notariado".                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | A API do e-Not Assina é documentada, mas é **(b)**: exige cadastro, chave por organização e certificado digital notarizado de **todos** os signatários. Não se confirmou que um SaaS possa operá-la em nome de clientes, se há sandbox, nem se o formato é compatível com nosso PDF já selado. A API de fluxo de cartório é para sistemas de cartório e exige ACT. Lavratura de ato é **(c)** e exclusiva do tabelião (Prov. 149, arts. 286, 287, 318). Um fake agora modelaria um contrato baseado em suposições (viola T4). |
| Desbloqueio do §3.8                               | (1) Resposta formal do CNB-CF sobre uso por plataforma SaaS (ou confirmação do modelo "chave da própria organização cliente"); (2) acesso a homologação/Swagger; (3) teste de envio de PDF com assinatura PAdES e revisões; (4) tabela de valores; (5) revisão jurídica do texto de produto. Com (1) e (2) → **contrato + fake identificado com produção desabilitada**, espelhando os endpoints documentados (`uploads`, `documents/e-not-assina`, `documents/{id}`, `ticket`, `canceled`, webhooks). Com (3) a (5) → implementação real atrás da flag. Corrigir no roadmap: o requisito do signatário é certificado **notarizado**, não necessariamente ICP-Brasil. | —                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| **Lei 14.063 / T1 (vocabulário)**                 | **Implementar de verdade agora.**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     | Não depende de terceiros. Adotar a tabela de §2.2 como regra de copy e acrescentar teste automatizado que falhe se UI, e-mails, termos ou página de verificação contiverem "assinatura avançada", "qualificada", "reconhecimento de firma", "cartório", "biometria", "liveness" ou "identidade verificada" fora de contextos permitidos.                                                                                                                                                                                      |
| **§3.3 / §2.10 aceite com vídeo/foto**            | **Implementar de verdade** quando a fase chegar, como **captura de evidência**, com a flag ligada só após decisão jurídica sobre controlador/operador e base do art. 11, e após o RIPD.                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | Não há norma específica que imponha ou proíba (NÃO CONFIRMADO que exista). A validade decorre da liberdade de forma (CC art. 107; MP 2.200-2, art. 10, §2º), com as exceções de forma especial (CC art. 108). O risco é LGPD (art. 5º, II; art. 11), não integração externa.                                                                                                                                                                                                                                                  |
| **§3.7 antifraude**                               | **Implementar de verdade** (motor interno), com canal de revisão, explicação da regra e registro da decisão humana desde o primeiro release.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | Não há dependência externa. O art. 20 da LGPD garante revisão e explicação. A revisão humana do roadmap supera o mínimo legal e deve ser mantida. Sinais antifraude não usam dado sensível.                                                                                                                                                                                                                                                                                                                                   |

## Fontes (índice)

- Lei 14.063/2020: https://www.planalto.gov.br/ccivil_03/_ato2019-2022/2020/lei/l14063.htm
- LGPD compilada: https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709compilado.htm
- MP 2.200-2/2001: https://www.planalto.gov.br/ccivil_03/mpv/antigas_2001/2200-2.htm
- Código Civil compilado: https://www.planalto.gov.br/ccivil_03/leis/2002/l10406compilada.htm
- Provimento CNJ 100/2020 (revogado): https://atos.cnj.jus.br/atos/detalhar/3334
- Provimento CNJ 149/2023: https://atos.cnj.jus.br/atos/detalhar/5243 · compilado: https://atos.cnj.jus.br/files/compilado1806222023111665565a1e0fc83.pdf
- e-Not Assina — integração por API: https://suporte.notariado.org.br/support/solutions/articles/43000699665-e-not-assina-empresas-mensalistas-integrac%C3%A3o-por-api
- Fluxo de Assinaturas — sistema do cartório: https://suporte.notariado.org.br/support/solutions/articles/43000585404-fluxo-de-assinaturas-criac%C3%A3o-autom%C3%A1tica-pelo-sistema-do-cart%C3%B3rio
- e-Not Assina — cadastramento de mensalistas: https://suporte.notariado.org.br/support/solutions/articles/43000663356-e-not-assina-cadastramento-de-mensalistas
- e-Not Assina — valores: https://suporte.notariado.org.br/support/solutions/articles/43000665062-e-not-assina-valores
- Governo Digital — níveis de assinatura: https://www.gov.br/governodigital/pt-br/identidade/assinatura-eletronica/saiba-mais-sobre-a-assinatura-eletronica
- Governo Digital — revisão de decisão automatizada: https://www.gov.br/governodigital/pt-br/lgpd-pagina-do-cidadao/solicitar-a-revisao-de-decisoes-tomadas-com-base-em-tratamento-automatizado-de-dados-pessoais
- ANPD — tomada de subsídios sobre biometria: https://www.gov.br/participamaisbrasil/ts-dados-biometricos
- Apoio (terceiros, não oficial): https://www.migalhas.com.br/depeso/366739/a-biometria-facial-pode-suprir-a-falta-de-assinatura-em-contratos · https://blog.zapsign.com.br/contrato-assinado-por-biometria-facial/
