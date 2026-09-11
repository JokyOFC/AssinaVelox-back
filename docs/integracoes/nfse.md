# NFS-e automática — pesquisa de integração (roadmap §2.21)

> Pesquisa técnica feita em 2026-09-11. Toda afirmação tem URL. O que não foi confirmado em fonte
> oficial está marcado **NÃO CONFIRMADO**. Blogs de terceiros aparecem só como apoio e marcados
> como tal. Conteúdo de sites foi tratado como dado, não como instrução.
>
> Legenda de disponibilidade:
> **(a)** API pública e documentada · **(b)** existe, mas exige credenciamento/contrato/elegibilidade ·
> **(c)** não existe API pública.

Contexto no projeto: `docs/roadmap.md` §2.21 (objetivo, entidades `fiscal_profiles` /
`fiscal_invoices`, `IssueFiscalInvoiceJob`), `docs/arquitetura.md` §8 (contrato reservado
`FiscalInvoiceProvider`, fake identificado antes da implementação real; "resposta inconclusiva ≠
sucesso") e §2 (a semântica de assinatura: o certificado A1 da operadora usado no PAdES
**não** é o mesmo uso que o certificado fiscal — ver §6.3).

---

## 1. Premissas que não mudam

### 1.1 Recibo de pagamento não é documento fiscal

- O recibo interno do AssinaVelox (`docs/cobranca.md` §9) já diz que **não é documento fiscal
  e não substitui a NFS-e**. Isso continua valendo: o recibo prova só o pagamento registrado no
  gateway. A obrigação de emitir documento fiscal de serviço é da **operadora** (prestadora do
  serviço), conforme a legislação do município e as regras nacionais descritas abaixo.
- O próprio Mercado Pago, em conteúdo editorial (blog, **não** é documentação de
  desenvolvedor), diferencia "invoice" (documento comercial sem validade fiscal no Brasil) de
  nota fiscal. [https://www.mercadopago.com.br/blog/o-que-e-invoice]
- O comprovante de pagamento do Mercado Pago também não é nota fiscal: nenhum endpoint da API de
  pagamentos emite documento fiscal (ver §2).

### 1.2 NF-e (modelo 55) não serve para serviços

- A NF-e modelo 55 foi instituída pelo **Ajuste SINIEF 07/2005** (CONFAZ) para substituir a Nota
  Fiscal modelo 1/1-A e a de Produtor modelo 4, e é usada por contribuintes de **IPI ou ICMS**,
  isto é, operações com mercadorias (e o que está no campo do ICMS).
  [https://www.confaz.fazenda.gov.br/legislacao/ajustes/2005/AJ007_05]
- Serviço de software/assinatura eletrônica é serviço sujeito ao ISS (municipal), documentado por
  **NFS-e**. A NFS-e de padrão nacional é gerida pelo Comitê Gestor da NFS-e (CGNFS-e) e
  documentada em [https://www.gov.br/nfse/pt-br].
- Consequência para o código: o rótulo do botão em `billing.index` é **NFS-e**, nunca "NF-e"
  (já previsto no roadmap §2.21). Qual **subitem da lista de serviços** (LC 116/2003) e qual
  **código NBS** se aplicam ao SaaS da operadora é **decisão contábil**, não do software —
  **NÃO CONFIRMADO** para o AssinaVelox (ver a tabela NBS/lista nacional no Anexo B, §3.2).

---

## 2. Mercado Pago — há API de NFS-e para o vendedor?

**Resultado: (c) não existe API pública do Mercado Pago para emitir NFS-e.** Confirmado de novo.

| Fato                                                                                                                                                                                                                                                                                                                                                       | Fonte                                                                                                                                                                                         |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| O índice oficial da documentação de desenvolvedores do MP não tem nenhuma página sobre nota fiscal, NF-e, NFS-e, emissão fiscal ou "Sistema de Gestão" (verificado de novo em 2026-09-11).                                                                                                                                                                  | [https://www.mercadopago.com.br/developers/pt/docs/llms.txt]                                                                                                                                  |
| A página de produto do **Sistema de Gestão** anuncia "notas fiscais ilimitadas grátis", sugestão de dados fiscais e impressão pela Point; funciona "no site do Mercado Pago, na versão para computador" (parte no app/Point). Preço anunciado: R$ 79/mês, grátis acima de R$ 10.000/mês em vendas, teste de dois meses. A página **não** menciona API para emissão fiscal. | [https://www.mercadopago.com.br/ferramentas-para-vender/sistema-de-gestao]                                                                                                                    |
| Conteúdo editorial do MP (blog, **não** é doc de desenvolvedor) diz que dá para emitir **NFS-e** pelo Sistema de Gestão, desde que a conta seja **pessoa jurídica**, a empresa esteja **credenciada na prefeitura** e o **certificado A1** esteja instalado na plataforma; a emissão é feita no painel de vendas, selecionando a transação e confirmando os dados do tomador. | [https://www.mercadopago.com.br/blog/como-emitir-nfs-e-pelo-app-mercado-pago] (a página retornou HTTP 403 ao fetch direto; conteúdo lido pelo trecho indexado na busca)                      |
| Outro texto do blog cita a integração do Sistema de Gestão com **NF-e e NFC-e**; sobre NFS-e, diz que o cadastro é feito na prefeitura.                                                                                                                                                                                                                    | [https://www.mercadopago.com.br/blog/integracao-nota-fiscal-eletronica-sistema-pagamento] (já citado em `docs/integracoes/mercado-pago.md` §9)                                                |
| A API fiscal que existe no ecossistema é do **Mercado Livre** (Faturador, notas de vendas no marketplace: `SALE`, `SALE_RETURN`, `DEVOLUTION`) — mercadorias, fora do escopo.                                                                                                                                                                              | [https://developers.mercadolivre.com.br/pt_br/api-fiscal-faturamento-de-venda], [https://developers.mercadolivre.com.br/pt_br/obtendo-nota-fiscal]                                             |

**NÃO CONFIRMADO**:

- Se o Sistema de Gestão emite NFS-e **automaticamente** para cada pagamento aprovado (o blog
  descreve ação manual por transação).
- Quais municípios o Sistema de Gestão atende para NFS-e e se ele usa o Sistema Nacional NFS-e ou
  os webservices municipais.
- Se existe qualquer API (mesmo privada/parceiro) do Sistema de Gestão.

Conclusão: o Sistema de Gestão é uma **ferramenta de painel** para o próprio vendedor. Não é
integrável ao `IssueFiscalInvoiceJob`. Pode servir como **plano manual de contingência** da
operadora, fora do software.

---

## 3. Sistema Nacional NFS-e (padrão nacional)

### 3.1 Componentes

| Componente                               | O que é                                                                                                                                                                  | Fonte                                                                                                                                                                        |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Emissor Público Nacional (Sefin Nacional)** | Emissor gratuito (web e API). Recebe a **DPS** (Declaração de Prestação de Serviço) em XML e gera a NFS-e de forma **síncrona**.                                          | [https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-emissor-publico-api-sistema-nacional-nfs-e-v1-2-out2025.pdf]           |
| **ADN — Ambiente de Dados Nacional**     | Repositório que recebe/compartilha as NFS-e e eventos (inclusive de emissores municipais próprios). Contribuinte consulta DF-e em que figura (emitente, tomador, intermediário). | [https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-apis-adn-sistema-nacional-nfse.pdf]                                    |
| **CNC — Cadastro Nacional de Contribuintes** | Cadastro de contribuintes NFS-e usado nas validações da DPS.                                                                                                             | [https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao]                                                                                 |
| **Parâmetros Municipais**                | Convênio, alíquotas, regimes especiais, retenções e benefícios por município/serviço.                                                                                     | Manual do Emissor Público (acima), §1.1–1.2                                                                                                                                  |
| **DANFSe**                               | Documento auxiliar (representação em PDF) da NFS-e.                                                                                                                        | [https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao]                                                                                 |

### 3.2 Documentação oficial

Página índice: [https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual]

- *API – Manual de Contribuintes – Emissor Público*, v1.2, out/2025 (o PDF traz no histórico
  "1.0 17/03/2025").
- *API – Manual de Contribuintes – Guia de Utilização das APIs do ADN*, v1.0 de 12/02/2026.
- Esquemas **XSD v1.01** (2026-02-09); **Anexo I** (leiaute e regras da DPS/NFS-e, v1.01
  2026-02-09); **Anexo II** (eventos, v1.01 2026-01-22); **Anexo A** (municípios IBGE / países
  ISO2); **Anexo B** (NBS × lista de serviço nacional, v1.01 2026-01-22); **Anexo C** (indicador de
  operação IBS/CBS).
- Homologação (produção restrita) com leiaute RTC (grupos `IBSCBS`), incluindo a **Nota Técnica
  SE/CGNFS-e nº 004 v2.0**: [https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita]

### 3.3 Endpoints documentados (Emissor Público Nacional — Sefin Nacional)

Transcritos do manual oficial
[https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-emissor-publico-api-sistema-nacional-nfs-e-v1-2-out2025.pdf]
(texto extraído localmente do PDF com pypdf):

| Método e caminho                                             | Função (resumo do manual)                                                                                                                                                                    |
| ------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /parametros_municipais/{codigoMunicipio}/convenio`      | Parâmetros do convênio do município.                                                                                                                                                         |
| `GET /parametros_municipais/{codigoMunicipio}/{codigoServico}` | Alíquotas, regimes especiais e deduções por subitem da lista de serviço.                                                                                                                     |
| `GET /parametros_municipais/{codigoMunicipio}/{CPF/CNPJ}`    | Retenções e benefícios municipais do contribuinte (o manual lista o mesmo caminho para os dois usos).                                                                                          |
| `POST /nfse`                                                 | **Geração síncrona** da NFS-e a partir da DPS; devolve o XML da NFS-e ou a rejeição com o motivo. Uma DPS que traz a chave de uma NFS-e anterior gera **cancelamento por substituição**.       |
| `GET /nfse/{chaveAcesso}`                                    | Consulta a NFS-e pela chave de acesso.                                                                                                                                                       |
| `GET /dps/{id}`                                              | Devolve a chave de acesso a partir do **identificador da DPS** (município IBGE + tipo de inscrição + CPF/CNPJ + série + número). Só para ator da nota (prestador, tomador, intermediário), pelo certificado. |
| `HEAD /dps/{id}`                                             | Diz apenas se a NFS-e daquela DPS foi gerada (qualquer certificado válido).                                                                                                                  |
| `POST /nfse/{chaveAcesso}/eventos`                           | Pedido de registro de evento (ex.: **cancelamento**), processamento síncrono; mensagens em JSON, DF-e em XML **assinado** pelo autor do evento.                                               |
| `GET /nfse/{chaveAcesso}/eventos[/{tipoEvento}[/{numSeqEvento}]]` | Consulta de eventos.                                                                                                                                                                         |

ADN — contribuintes (manual do ADN, mesma fonte da §3.2): `GET /DFe/{NSU}` (distribuição de
DF-e por NSU) e `GET /NFSe/{ChaveAcesso}/Eventos`. A consulta aceita certificado de **mesmo CNPJ
raiz** do contribuinte.

Consequências de desenho (derivadas do manual, não inventadas):

- O `GET/HEAD /dps/{id}` é o mecanismo oficial de **"consultar antes de reemitir"** exigido pelo
  roadmap (T5): em timeout do `POST /nfse`, o job consulta pela identificação da DPS antes de
  tentar de novo. A numeração **série + número da DPS** é, na prática, a `idempotency_key` de
  `fiscal_invoices`.
- Cancelamento em estorno total = evento via `POST /nfse/{chaveAcesso}/eventos`, sujeito às regras
  de negócio e prazos do município (Anexo II) — **NÃO CONFIRMADO** o prazo aplicável ao município
  da operadora.

**NÃO CONFIRMADO** (não aparece literalmente no manual lido): o envelope JSON exato do `POST /nfse`
(p.ex. se a DPS vai compactada GZip + Base64 dentro do JSON, como afirmam blogs de terceiros como
[https://notagateway.com.br/blog/api-nfse-nacional/]), códigos de erro, limites de taxa e tamanho.
A fonte autoritativa são os Swaggers (§3.4) e o Anexo I; o Swagger da Sefin retornou HTTP 403 ao
fetch anônimo nesta pesquisa.

### 3.4 Ambientes e URLs oficiais

Fonte: [https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao]
(página atualizada em 20/08/2026).

| API                        | Produção restrita (homologação)                                   | Produção                                              |
| -------------------------- | ----------------------------------------------------------------- | ----------------------------------------------------- |
| Sefin Nacional (emissão)   | `https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional/docs/index` | `https://sefin.nfse.gov.br/SefinNacional/docs/index`  |
| ADN contribuintes          | `https://adn.producaorestrita.nfse.gov.br/contribuintes/docs/index.html` | `https://adn.nfse.gov.br/contribuintes/docs/index.html` |
| ADN (core)                 | `https://adn.producaorestrita.nfse.gov.br/docs/index.html`        | `https://adn.nfse.gov.br/docs/index.html`             |
| Parâmetros municipais      | `https://adn.producaorestrita.nfse.gov.br/parametrizacao/docs/index.html` | `https://adn.nfse.gov.br/parametrizacao/docs/index.html` |
| DANFSe                     | `https://adn.producaorestrita.nfse.gov.br/danfse/docs/index.html` | `https://adn.nfse.gov.br/danfse/docs/index.html`      |
| CNC                        | `https://adn.producaorestrita.nfse.gov.br/cnc/docs/index.html`    | `https://adn.nfse.gov.br/cnc/docs/index.html`         |

- A produção restrita com os grupos `IBSCBS` está aberta "para todas as empresas e municípios"
  que queiram testar (desde 10/12/2025). [https://www.gov.br/nfse/pt-br/noticias/nfs-e-nova-nota-tecnica]
- Esses são **caminhos de documentação (Swagger)**. A URL base de cada operação deve ser tirada do
  próprio Swagger — **NÃO CONFIRMADO** nesta pesquisa (acesso anônimo bloqueado).

### 3.5 Autenticação

- As consultas dependem da **identificação do certificado digital da conexão**: o `GET /dps/{id}`
  só responde se o titular do certificado for ator da nota; o `HEAD` aceita "qualquer usuário desde
  que realize a consulta com um certificado digital válido". Ou seja, **TLS mútuo com certificado
  digital** do contribuinte. [manual do Emissor Público, §1.4]
- A DPS e os pedidos de evento são XML com **assinatura digital** do emitente. [manual, §1.3 e §1.5]
- A API do ADN valida o **CNPJ raiz** entre o parâmetro e o certificado da conexão. [manual do ADN, §1.1]
- **NÃO CONFIRMADO** em fonte oficial lida: tipos aceitos (e-CNPJ A1/A3, e-CPF) e se a cadeia
  precisa ser ICP-Brasil. Blogs de terceiros afirmam mTLS com certificado ICP-Brasil
  [https://notagateway.com.br/blog/api-nfse-nacional/] — plausível, mas conferir no Swagger/FAQ oficial.
  Na interface web, o acesso também pode ser por gov.br ou usuário/senha, segundo blog de terceiro
  (mesma URL) — irrelevante para integração automática.

### 3.6 Adesão dos municípios

- A página oficial informa **5.571 municípios aderentes** (100% da população e da arrecadação de
  serviços), com planilha datada de 08/09/2026 e painel de acompanhamento.
  [https://www.gov.br/nfse/pt-br/municipios/municipios-aderentes]
- Aderir não significa que **todo** município emite pelo Emissor Nacional: pela LC 214/2025
  (§3.7), o município pode manter **emissor próprio** e só compartilhar os documentos com o ADN.
  Se o município da operadora usa emissor próprio, a emissão via Sefin Nacional pode **não** estar
  disponível — conferir com `GET /parametros_municipais/{codigoMunicipio}/convenio` e com a
  prefeitura. **NÃO CONFIRMADO** para o município da operadora (ainda não definido).

### 3.7 O que é obrigatório — LC 214/2025 e Simples Nacional

| Regra                                                                                                                                                                                                                                                                                  | Quem está obrigado                                  | Fonte                                                                                                                                                                                                                                                                                                                                                         |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **LC 214/2025, art. 62, § 1º**: a partir de 1º/01/2026, Municípios e DF devem autorizar a emissão da NFS-e de padrão nacional no ambiente nacional **ou**, se tiverem emissor próprio, compartilhar os documentos com o ADN no leiaute padronizado.                                    | **Municípios e DF** (não diretamente a empresa).     | Texto oficial: [https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp214.htm] (**o fetch falhou nesta pesquisa**; o texto do § 1º foi lido em transcrição de terceiro [https://modeloinicial.com.br/lei/LCP-214-2025/lei-complementar-214/art-62] e no alerta do TCE-PI [https://www.tcepi.tc.br/tce-pi-alerta-municipios-tem-ate-1o-de-janeiro-de-2026-para-adequacao-ao-padrao-nacional-da-nfs-e/]) — **conferir no Planalto antes de citar em documento jurídico**. |
| **NT SE/CGNFS-e nº 004 v2.0** (10/12/2025): leiaute com grupos `IBSCBS` vale em produção desde 1º/01/2026, mas as regras de **obrigatoriedade** desses grupos foram desligadas; notas sem `IBSCBS` são autorizadas. Se o emitente **informar** o grupo, as validações dele se aplicam. | Emitentes de NFS-e.                                  | [https://www.gov.br/nfse/pt-br/noticias/nfs-e-nova-nota-tecnica], [https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita/nt-004-se-cgnfse-novo-layout-rtc-v2-00-20251210.pdf]                                                                                                                                                         |
| **CGNFS-e (07/08/2026)**: a falta de IBS/CBS na NFS-e **até 31/12/2026 não rejeita** a nota, mas demonstra descumprimento e expõe o emitente a sanções; o texto traz um cronograma por tipo de serviço (a partir de 1º/10/2026 e 1º/12/2026, este último incluindo subitens 1.03, 1.05, 1.09 e 16.01 e plataformas digitais) e 1º/01/2027 para optantes do Simples que escolheram destacar IBS/CBS. | Emitentes de NFS-e, por categoria.                   | [https://www.gov.br/nfse/pt-br/noticias/cgnfs-e-orienta-sobre-os-prazos-para%20destaque-de-ibs-cbs-nas-notas-fiscais-de-servico] — cronograma lido por resumo automático; **conferir a data exata aplicável ao serviço da operadora na própria página**.                                                                                                                  |
| **Resolução CGSN nº 191, de 04/08/2026**: **ME e EPP optantes do Simples Nacional** que prestam serviços sujeitos à NFS-e ficam obrigadas a emitir a NFS-e de padrão nacional a partir de **1º/11/2026**, "por meio do Emissor Nacional da NFS-e, seja pela aplicação web, seja por integração via API". Revogou a Resolução CGSN nº 189/2026 (que previa 1º/09/2026). IBS/CBS no Simples só a partir de 1º/01/2027. | ME/EPP do Simples Nacional.                          | [https://www.gov.br/receitafederal/pt-br/assuntos/noticias/2026/agosto/simples-nacional-nfs-e-nacional-sera-obrigatoria-para-me-e-epp-a-partir-de-1o-de-novembro-de-2026]                                                                                                                                                                                  |
| Flexibilização Receita/CGIBS (01/08/2026) sobre rejeição por falta de campos de CBS/IBS lista NF-e, NFC-e, CT-e, CT-e OS, GTV-e, BP-e, NF3e e NFCom — **não cita NFS-e**.                                                                                                                  | —                                                    | [https://www.gov.br/receitafederal/pt-br/assuntos/noticias/2026/julho/receita-federal-e-cgibs-flexibilizarao-obrigatoriedade-de-informacoes-em-documentos-fiscais]                                                                                                                                                                                        |

Implicações para o AssinaVelox:

- Se a operadora for **ME/EPP do Simples**, a partir de 1º/11/2026 a emissão passa a ser pelo
  **Emissor Nacional (web ou API)** — o caminho da §3.3 é o oficial, não um entre vários.
- O `FiscalInvoiceProvider` precisa modelar os grupos `IBSCBS` como **opcionais configuráveis**
  em 2026 e obrigatórios depois, sem hardcode de data: quem decide é a contabilidade da operadora.
- Regime tributário, subitem de serviço, NBS, alíquota de ISS, retenções e se destaca IBS/CBS são
  **dados do `fiscal_profiles` / configuração da operadora**, validados contra
  `/parametros_municipais`, nunca inferidos pelo software (roadmap §2.21).

---

## 4. Provedores comerciais de API fiscal (apenas lista — sem recomendação)

Todos são **comerciais** (contrato, planos pagos; preços **NÃO CONFIRMADOS** nesta pesquisa) e,
portanto, categoria **(b)**. Emitem em nome da operadora e, via de regra, pedem o certificado A1
dela. Listados só como alternativas a avaliar; nenhum foi testado.

| Provedor                    | Documentação oficial                                                                                   | Observações confirmadas                                                                                                                       |
| --------------------------- | ------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------- |
| Focus NFe                   | [https://doc.focusnfe.com.br/reference/introducao], [https://doc.focusnfe.com.br/reference/nfse-nacional] | Documenta NFS-e e "NFSe nacional"; autenticação por token; URLs por ambiente em página própria.                                                |
| NFE.io                      | [https://nfe.io/docs/]                                                                                 | Documenta emissão de NFS-e "em diversas prefeituras". Ambientes/autenticação não verificados.                                                   |
| PlugNotas (TecnoSpeed)      | [https://docs.plugnotas.com.br/], [https://atendimento.tecnospeed.com.br/hc/pt-br/articles/38360053945367-Documenta%C3%A7%C3%A3o-T%C3%A9cnica-Padr%C3%A3o-NFS-e-Nacional] | Existe documentação do padrão NFS-e Nacional; conteúdo do portal não pôde ser lido (SPA).                                                        |
| eNotas Gateway              | [https://developer.enotasgw.com.br/docs/v1-Sobre-a-API---NFS-e-v1/]                                   | API v1 para NFS-e (per resultado indexado); autenticação por API key (**não verificado** na página).                                            |
| Nuvem Fiscal                | [https://dev.nuvemfiscal.com.br/docs/nfse/]                                                            | Resultado de busca indica que o serviço seria **desativado em 31/07/2026**; o domínio não resolveu nesta pesquisa. **NÃO CONFIRMADO** — tratar como indisponível. |

---

## 5. Comparação dos caminhos

| Caminho                                             | Categoria | Automático por pagamento? | Observação                                                                                                                                                         |
| --------------------------------------------------- | --------- | ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Mercado Pago (API)                                  | (c)       | —                         | Não existe.                                                                                                                                                        |
| Mercado Pago Sistema de Gestão (painel)             | (b)       | Não (manual)              | Exige conta PJ, credenciamento na prefeitura, A1 no painel; pago. Contingência manual.                                                                             |
| Sistema Nacional NFS-e — Sefin Nacional (API direta) | (b)       | Sim                       | API **documentada e gratuita**, mas exige: CNPJ da operadora, município que emite pelo Emissor Nacional, cadastro no CNC, certificado digital para mTLS e assinatura XML. |
| Provedor comercial                                  | (b)       | Sim                       | Contrato e custo; cobertura municipal própria; outro terceiro com o certificado da operadora.                                                                       |

---

## 6. Esboço técnico para o `FiscalInvoiceProvider` (sem implementação)

### 6.1 Contrato (identificadores em inglês)

```php
interface FiscalInvoiceProvider
{
    public function issue(FiscalInvoiceRequest $request): FiscalInvoiceResult;   // idempotente por idempotency_key (série+número da DPS)
    public function findByIdempotencyKey(string $key): ?FiscalInvoiceResult;   // GET/HEAD /dps/{id} — "consultar antes de reemitir"
    public function fetch(string $externalId): FiscalInvoiceResult;            // GET /nfse/{chaveAcesso}
    public function cancel(string $externalId, string $reason): FiscalInvoiceResult; // POST /nfse/{chaveAcesso}/eventos
    public function downloadPdf(string $externalId): string;                   // DANFSe
}
```

`FiscalInvoiceResult::status` ∈ `issued | rejected | pending | inconclusive` — `inconclusive`
(timeout, 5xx) **nunca** vira `issued`; o job consulta por `findByIdempotencyKey` antes de nova
tentativa (arquitetura §8).

### 6.2 Implementações previstas

- `FakeFiscalInvoiceProvider` — **identificado** (`provider = fake`, número com prefixo `FAKE-`,
  PDF com tarja "SEM VALIDADE FISCAL — AMBIENTE DE TESTE"), cobrindo todos os estados do aceite
  do roadmap (emitida, rejeitada, timeout → consulta, cancelada, cancelamento recusado).
- `SefinNacionalFiscalInvoiceProvider` — só depois do desbloqueio (§7), primeiro contra a
  **produção restrita**; produção atrás de flag desligada por padrão.

### 6.3 Certificado

- O Emissor Nacional autentica a conexão pelo certificado do contribuinte e exige XML assinado.
  Reusar `certificate_references` com `kind=fiscal_a1` e `secret_ref` (roadmap §2.21), **separado**
  do A1 usado no PAdES (`signature_status = company_a1`, arquitetura §2), ainda que seja o mesmo
  arquivo: finalidades, logs e rotação são distintos.
- Assinatura XMLDSig da DPS: o `tools/pdftool` (pyHanko) assina PDF, **não** XML. Seria preciso
  um componente de XMLDSig — **NÃO CONFIRMADO** qual biblioteca (PHP ou Python) atende o perfil
  exigido pelo Anexo I; avaliar na hora de implementar.

### 6.4 Armazenamento

Guardar o **XML da NFS-e** retornado (é o documento fiscal; o PDF/DANFSe é auxiliar) pelo prazo
legal, independente da retenção configurável (roadmap §2.19). Prazo exato: `{{PRAZO_FISCAL}}`,
decisão contábil/jurídica — **NÃO CONFIRMADO**.

---

## 7. Decisão recomendada para o AssinaVelox

**Implementar contrato + fake identificado com produção desabilitada** agora; a emissão real
fica **bloqueada** até os itens abaixo.

Justificativa:

1. **Mercado Pago não resolve**: não há API de NFS-e (categoria c). O Sistema de Gestão é painel
   manual e não se integra ao `IssueFiscalInvoiceJob`.
2. **O caminho oficial existe e é documentado** (Sefin Nacional + ADN, gratuito, síncrono,
   com consulta por DPS que dá a idempotência pedida no roadmap), e desde a Resolução CGSN
   191/2026 é o caminho obrigatório para ME/EPP do Simples a partir de 1º/11/2026. Isso permite
   desenhar o contrato de forma estável agora.
3. **Mas a emissão real depende de dados que o software não pode decidir**: CNPJ e regime da
   operadora, município (e se ele emite pelo Emissor Nacional ou por emissor próprio),
   subitem/NBS, ISS, retenções, destaque de IBS/CBS. Emitir nota errada gera obrigação fiscal real.
4. A produção restrita está aberta a empresas, mas exige o **certificado digital da operadora**
   — sem ele não dá sequer para gravar fixtures reais.

O que falta para desbloquear a implementação real:

- [ ] CNPJ, razão social, município (código IBGE) e regime tributário da operadora.
- [ ] Parecer contábil: subitem da LC 116/2003, código NBS, alíquota/retenções de ISS e
      tratamento de IBS/CBS em 2026/2027.
- [ ] Confirmação de que o município da operadora emite pelo Emissor Nacional
      (`/parametros_municipais/{codigoMunicipio}/convenio`) e cadastro da operadora no CNC.
- [ ] Certificado digital da operadora para mTLS e assinatura de XML (tipo aceito a confirmar
      no Swagger/FAQ oficial), registrado em `certificate_references` (`kind=fiscal_a1`).
- [ ] Escolha da biblioteca de XMLDSig e validação contra o XSD v1.01.
- [ ] Rodada completa em **produção restrita** com fixtures gravadas (emissão, rejeição,
      timeout → consulta, cancelamento) antes de ligar a flag de produção.
- [ ] Decisão de produto: Sefin Nacional direto **ou** provedor comercial (§4) — os dois cabem
      atrás do mesmo `FiscalInvoiceProvider`.

Até lá: `billing.index` mantém o botão **NFS-e** oculto e o recibo interno continua dizendo que
não é documento fiscal.
