# Consulta de CNPJ e validação de CPF — brief de integração (roadmap §2.11)

Data da pesquisa: 2026-09-11. Cada fato traz a URL de origem entre colchetes. Fonte de terceiro (não oficial) aparece marcada como **[terceiro]**. O que não pôde ser confirmado em fonte oficial está marcado como **NÃO CONFIRMADO**. Classificação de disponibilidade usada no documento:

- **(a)** existe API pública e documentada, sem contrato;
- **(b)** existe API, mas exige credenciamento, contrato ou elegibilidade;
- **(c)** não existe API pública.

Contexto do projeto: contratos reservados `CnpjLookupProvider` e `CpfVerificationProvider` (`arquitetura.md` §8), ainda sem implementação. Cada adaptador precisa de config própria, timeout, autenticação, tratamento de erro, `correlation_id` e repetição idempotente, e **resposta inconclusiva ≠ sucesso**. O roadmap §2.11 pede cache por CNPJ (`cnpj_lookups`, TTL em dias), resultado de CPF `verified|not_verified|unavailable` em `fields_snapshot` e fake em dev e testes.

---

## 1. Resumo executivo

| Item                                                | Classificação               | Quem opera                    | Custo                                  | Situação para o AssinaVelox                                             |
| --------------------------------------------------- | --------------------------- | ----------------------------- | -------------------------------------- | ----------------------------------------------------------------------- |
| BrasilAPI `GET /api/cnpj/v1/{cnpj}`                 | (a)                         | Comunidade (open source, MIT) | Gratuito                               | Usável, mas é **proxy do minhareceita.org**: não conta como redundância |
| Minha Receita `GET https://minhareceita.org/{cnpj}` | (a)                         | Comunidade (open source, MIT) | Gratuito (mantido por doações)         | Usável, sem SLA; também dá para auto-hospedar                           |
| Dados abertos do CNPJ (Receita Federal)             | Dataset, não é API          | Receita Federal               | Gratuito, Creative Commons Attribution | Fonte primária de tudo acima; importar por conta própria custa ~180 GB  |
| Situação cadastral de CPF: consulta web da Receita  | (c) para uso por máquina    | Receita Federal               | Gratuito para o cidadão                | Formulário com hCaptcha; **não pode ser automatizado**                  |
| CPF via Conecta gov.br (Cadastro Base do Cidadão)   | (b), só órgãos públicos     | Governo federal               | —                                      | **Inelegível** para empresa privada                                     |
| SERPRO Consulta CPF                                 | (b), contrato pago          | SERPRO                        | R$ 0,017 a R$ 0,6591 por consulta      | Viável só com contrato e e-CNPJ                                         |
| "Serviço próprio" de CPF do proprietário (roadmap)  | Sem documentação disponível | Proprietário                  | NÃO CONFIRMADO                         | Contrato + fake, produção desabilitada                                  |

---

## 2. CNPJ

### 2.1 BrasilAPI

- **Natureza**: projeto comunitário e open source, **não é governamental**. Licença MIT ("Copyright (c) 2020 Filipe Deschamps") [https://raw.githubusercontent.com/BrasilAPI/BrasilAPI/main/LICENSE]. O README se apresenta como uma iniciativa para "transformar o Brasil em uma API" e diz que roda em Next.js sobre a Vercel, com cache em CDN [https://raw.githubusercontent.com/BrasilAPI/BrasilAPI/main/README.md].
- **Endpoint**: `/cnpj/v1/{cnpj}`. A documentação descreve o retorno como informações completas da empresa ("dados cadastrais, situação, sócios e atividades econômicas") [https://raw.githubusercontent.com/BrasilAPI/BrasilAPI/main/pages/docs/doc/cnpj.json]. A página renderizada `https://brasilapi.com.br/docs` é uma SPA e não pôde ser lida sem JavaScript. O caminho completo `https://brasilapi.com.br/api/cnpj/v1/{cnpj}` segue a convenção das rotas Next.js do repositório (`pages/api/cnpj/v1/[cnpj].js`) [https://api.github.com/repos/BrasilAPI/BrasilAPI/contents/pages/api/cnpj/v1].
- **Campos principais**: `cnpj`, `razao_social`, `nome_fantasia`, `logradouro`, `numero`, `bairro`, `municipio`, `cep`, `uf`, `cnae_fiscal`, `cnae_fiscal_descricao`, `cnaes_secundarios`, `qsa`, `identificador_matriz_filial`, `situacao_cadastral`, `descricao_situacao_cadastral`, `data_situacao_cadastral`, `data_inicio_atividade`, `porte`, `regime_tributario`, `ddd_telefone_1`, `ddd_telefone_2`, `email` [https://raw.githubusercontent.com/BrasilAPI/BrasilAPI/main/pages/docs/doc/cnpj.json].
- **Erros documentados**: 400 ("CNPJ deve conter exatamente 14 caracteres.") e 404 ("CNPJ … não encontrado.") [https://raw.githubusercontent.com/BrasilAPI/BrasilAPI/main/pages/docs/doc/cnpj.json]. O handler repassa o 400/404 do serviço de origem e relança os demais erros [https://raw.githubusercontent.com/BrasilAPI/BrasilAPI/main/pages/api/cnpj/v1/%5Bcnpj%5D.js].
- **Fonte dos dados**: o serviço `services/cnpj.js` faz `GET https://minhareceita.org/{cnpj}` [https://raw.githubusercontent.com/BrasilAPI/BrasilAPI/main/services/cnpj.js]. Na prática, **a BrasilAPI de CNPJ é um proxy do Minha Receita**, que por sua vez vem dos dados abertos da Receita Federal. Consequência: usar BrasilAPI como fallback do Minha Receita (ou o contrário) **não dá redundância real**. Se o minhareceita.org cair, os dois caem.
- **Termos de uso e limites**: o README diz que o projeto está em beta e ainda preparando os Termos de Uso. Pede que ninguém use formas automatizadas para varrer a API e que o volume de consultas tenha "natureza de uma pessoa real" [https://raw.githubusercontent.com/BrasilAPI/BrasilAPI/main/README.md]. Limite numérico (req/s, req/dia): **NÃO CONFIRMADO** (não publicado). SLA: **NÃO CONFIRMADO** (não publicado).
- **CPF na BrasilAPI**: existe `/cpf/v1/{cpf}`, descrito como "Verifica o CPF e caso válido retorna sua região". O retorno tem `cpf`, `isValid`, `rf`, `ufs` [https://raw.githubusercontent.com/BrasilAPI/BrasilAPI/main/pages/docs/doc/cpf.json]. É **só validação de dígitos + região fiscal**, sem situação cadastral. Não vale a pena para o projeto, porque a validação local faz o mesmo sem depender de rede e sem mandar o CPF a terceiro.
- **Classificação**: (a).

### 2.2 Minha Receita (minhareceita.org)

- **Natureza**: projeto comunitário e open source, licença MIT ("Copyright (c) 2021 Eduardo Vicente Gonçalves") [https://codeberg.org/cuducos/minha-receita/raw/branch/main/LICENSE]. O repositório do GitHub foi arquivado em 2026-01-04 e diz "Moved to codeberg.org/cuducos/minha-receita" [https://github.com/cuducos/minha-receita] [https://raw.githubusercontent.com/cuducos/minha-receita/main/README.md]. O canônico agora é o Codeberg [https://codeberg.org/cuducos/minha-receita].
- **Endpoint**: basta acrescentar o CNPJ ao final da URL, por exemplo `https://minhareceita.org/33.683.111/0002-80` [https://docs.minhareceita.org/]. O formato `/<CNPJ>` responde 200 (encontrado), 400 (`{"message": "CNPJ foobar inválido."}`), 404 (não encontrado) e 405 (método errado) [https://codeberg.org/cuducos/minha-receita/raw/branch/main/docs/como-usar.md].
- **Endpoints auxiliares**: `/updated` (data da extração), `/healthz` e `/metrics` (Prometheus). Também há busca paginada por `uf`, `cnae_fiscal`, `cnae`, `municipio` e `natureza_juridica`, com `limit` de até 1.024 e cursor [https://codeberg.org/cuducos/minha-receita/raw/branch/main/docs/como-usar.md]. Na data da pesquisa, `GET https://minhareceita.org/updated` respondeu `{"message":"2026-08"}` [https://minhareceita.org/updated].
- **Garantias**: "A API web não tem nenhuma garantia de nível de serviço". A disponibilidade depende de contribuições mensais ou via Pix [https://codeberg.org/cuducos/minha-receita/raw/branch/main/docs/index.md]. A instância pública não coleta dados por requisição, "qual CNPJ foi consultado, ou o IP" [https://codeberg.org/cuducos/minha-receita/raw/branch/main/docs/index.md]. Limite de taxa numérico: **NÃO CONFIRMADO** (não publicado). Termos de uso formais: **NÃO CONFIRMADO** (não encontrados). O FAQ diz que a API limita os filtros disponíveis para proteger o desempenho [https://codeberg.org/cuducos/minha-receita/raw/branch/main/docs/faq.md].
- **Origem e atualização dos dados**: os dados vêm da Receita Federal e são servidos "tal como foram publicados", com exceções de privacidade. O projeto avisa que podem estar desatualizados, incorretos, incompletos ou inconsistentes, por ser fonte secundária [https://codeberg.org/cuducos/minha-receita/raw/branch/main/docs/sobre-os-dados.md]. A atualização "é manual e normalmente ocorre alguns dias depois de a Receita Federal liberar uma nova versão" [https://codeberg.org/cuducos/minha-receita/raw/branch/main/docs/faq.md].
- **Auto-hospedagem: possível.**
    - O repositório tem `Dockerfile`, `compose.yml` e `.env.sample` [https://codeberg.org/api/v1/repos/cuducos/minha-receita/contents].
    - Há três formas de instalar: imagem de container, compilação do código-fonte (Go, a doc cita "Go versão 1.27") ou compose [https://codeberg.org/cuducos/minha-receita/raw/branch/main/docs/servidor/instalacao.md].
    - Precisa de "cerca de 180 GB" de disco no total. O ETL usa 8 GB de download + 15 GB temporários + 7 GB de grafo, e o PostgreSQL usa 140 GB de tabelas + 10 GB de índices [https://codeberg.org/cuducos/minha-receita/raw/branch/main/docs/servidor/instalacao.md].
    - O banco de produção é PostgreSQL; MongoDB aparece só na configuração de testes [https://codeberg.org/cuducos/minha-receita/raw/branch/main/.env.sample].
    - Existe um modo `minha-receita up` que "não requer nenhum banco de dados externo (como PostgreSQL ou MongoDB)" nem Docker. Ele baixa os arquivos da Receita, transforma e sobe as APIs, mas "pode demorar várias horas" [https://codeberg.org/cuducos/minha-receita/raw/branch/main/docs/servidor/zero-dependencias.md].
    - Encaixe no AssinaVelox (sem Docker, banco MySQL): auto-hospedar exige um serviço Go à parte com PostgreSQL ou o modo sem dependências, mais ~180 GB de disco e a atualização mensal. **Não é MySQL**, e o esforço operacional é desproporcional a um autopreenchimento de cadastro na Fase 2.
- **Classificação**: (a) para a instância pública; auto-hospedagem possível (MIT).

### 2.3 Dataset oficial de dados abertos do CNPJ (Receita Federal)

- **Página oficial**: "Cadastro Nacional da Pessoa Jurídica - CNPJ" no Portal de Dados Abertos [https://dados.gov.br/dados/conjuntos-dados/cadastro-nacional-da-pessoa-juridica---cnpj]. A antiga página da Receita (`gov.br/receitafederal/.../dados-abertos/cadastros/cnpj`) redireciona (302) para ela [https://www.gov.br/receitafederal/pt-br/acesso-a-informacao/dados-abertos/cadastros/cnpj].
- **Metadados declarados** (lidos com a página renderizada) [https://dados.gov.br/dados/conjuntos-dados/cadastro-nacional-da-pessoa-juridica---cnpj]:
    - Licença: **Creative Commons Attribution**
    - Atualização: **Mensal** ("A periodicidade de atualização dos dados é mensal")
    - Data da última extração: 11/01/2026
    - Última alteração nos metadados: 09/02/2026
    - Última alteração em um arquivo: 05/07/2024
    - Formatos: PDF; ZIP
    - Área técnica: RFB
    - O selo da página marca o conjunto como "Desatualizado".
- **Inconsistência a registrar**: o portal diz "última extração 11/01/2026", mas o Minha Receita, que consome os arquivos da Receita, já serve a base "2026-08" [https://minhareceita.org/updated]. Isso sugere que os arquivos mensais são publicados fora do dados.gov.br, no repositório de arquivos da Receita (SERPRO+) em `https://arquivos.receitafederal.gov.br/` [https://arquivos.receitafederal.gov.br/]. A listagem de pastas não pôde ser lida sem JavaScript. O link de compartilhamento atual (`https://arquivos.receitafederal.gov.br/index.php/s/YggdBLfdninEJX9`) e a mudança de layout e caminhos no fim de janeiro de 2026 aparecem só em repositório de terceiro [terceiro: https://github.com/rictom/cnpj-sqlite]. **NÃO CONFIRMADO** em fonte oficial: a URL canônica atual dos arquivos mensais e a data do arquivo mais recente.
- **Divergência de licença**: o repositório de dados da RFB diz que o "conteúdo deste site" está sob Creative Commons Attribution-NoDerivatives 3.0 [https://www.gov.br/receitafederal/dados]. O dataset no dados.gov.br declara Creative Commons Attribution. Para o uso previsto (exibir e cachear dados cadastrais de uma empresa) as duas exigem **atribuição**. Recomenda-se exibir "Fonte: Receita Federal (dados abertos do CNPJ)" no autopreenchimento. Qual das duas prevalece para os arquivos: **NÃO CONFIRMADO**.
- **Layout e privacidade**: o documento de metadados ("Novo Layout para os DADOS ABERTOS do CNPJ") define as tabelas EMPRESAS, ESTABELECIMENTOS, SÓCIOS etc. Ele determina que o "CNPJ/CPF DO SÓCIO" e o "CPF DO REPRESENTANTE" sejam descaracterizados "por meio da ocultação dos três primeiros dígitos e dos dois dígitos verificadores", conforme o art. 129 § 2º da Lei nº 13.473/2017 [https://www.gov.br/receitafederal/dados/cnpj-metadados.pdf]. O dado público de sócios, portanto, já traz CPF parcial e nome, que são **dados pessoais**.
- **CPF não é dado aberto**: a seção "Cadastros" dos dados abertos da Receita lista CAFIR, CNPJ e CNO, mas **não o CPF** [https://www.gov.br/receitafederal/pt-br/acesso-a-informacao/dados-abertos/cadastros].
- **Classificação**: dataset público para download, não é API de consulta.

---

## 3. CPF

### 3.1 Existe consulta pública, gratuita e oficial de situação cadastral por API para empresa privada sem contrato?

**Não.** Evidências por canal:

1. **Consulta web da Receita ("Comprovante de Situação Cadastral no CPF")**: pede CPF + data de nascimento e é protegida por **hCaptcha** ("Widget contendo caixa de seleção para desafio de segurança hCaptcha", visto na página renderizada). O próprio formulário avisa que o comprovante "limita-se tão somente a comprovar a situação cadastral no CPF" [https://servicos.receita.fazenda.gov.br/servicos/cpf/consultasituacao/consultapublica.asp]. O serviço é "gratuito para o cidadão" e é oferecido como página web, sem menção a API [https://www.gov.br/pt-br/servicos/consultar-cadastro-de-pessoas-fisicas]. Automatizar essa página exigiria contornar CAPTCHA, o que está **fora de questão**. Classificação: **(c)** para uso por máquina.
2. **Conecta gov.br, Cadastro Base do Cidadão (CPF)**: API "para órgão Público Federal e Estadual", com certificado tipo A, login no acesso.gov.br e Termo de Responsabilidade [https://www.gov.br/conecta/catalogo/apis/cadastro-base-do-cidadao-cbc-cpf]. Classificação: **(b)**, e o AssinaVelox é **inelegível** por ser empresa privada.
3. **SERPRO Consulta CPF**: **existe, é oficial e é paga**. Detalhes em §3.2. Classificação: **(b)**.
4. **BrasilAPI `/cpf/v1`**: só validação de dígitos, sem situação cadastral (§2.1). Não resolve.

### 3.2 SERPRO Consulta CPF (confirmado: pago, com contrato)

- **O que é**: serviço HTTP REST de consulta às informações cadastrais de pessoas físicas. O interessado envia o número do CPF e a data de nascimento e recebe "informações cadastrais básicas do Contribuinte" [https://apicenter.estaleiro.serpro.gov.br/documentacao/consulta-cpf/]. A loja o descreve como "a solução oficial que conecta sua empresa diretamente à base da Receita Federal" [https://www.loja.serpro.gov.br/consultacpf].
- **Quem contrata**: "empresas de qualquer porte, entidades de classe ou grupos econômicos e instituições públicas" [https://apicenter.estaleiro.serpro.gov.br/documentacao/consulta-cpf/pt/como_contratar/].
- **Como contratar**:
    - A contratação online exige **certificado e-CNPJ**. O fluxo é: "Quero contratar", conta na Área do Cliente, assinatura do contrato e chaves liberadas em cliente.serpro.gov.br em ~10 minutos [https://apicenter.estaleiro.serpro.gov.br/documentacao/consulta-cpf/pt/como_contratar/].
    - Sem certificado, a empresa usa um formulário comercial, que é mais lento [https://apicenter.estaleiro.serpro.gov.br/documentacao/consulta-cpf/pt/como_contratar/].
    - O cancelamento pode ser feito a qualquer momento [https://apicenter.estaleiro.serpro.gov.br/documentacao/consulta-cpf/pt/como_contratar/].
    - O serviço no gov.br confirma que o custo mensal "depende da faixa de preços, conforme o seu volume de consumo" [https://www.gov.br/pt-br/servicos/obter-solucao-de-consulta-de-dados-de-cadastro-de-pessoa-fisica-cpf].
- **Preço** (aba "Preço" da loja, lida em 2026-09-11) [https://www.loja.serpro.gov.br/consultacpf]: pagamento conforme o consumo, com o número de consultas do mês multiplicado pelo valor unitário da faixa.

    | Faixa mensal        | Valor por consulta |
    | ------------------- | ------------------ |
    | até 999             | R$ 0,6591          |
    | 1.000 a 9.999       | R$ 0,5649          |
    | 10.000 a 49.999     | R$ 0,3557          |
    | 50.000 a 99.999     | R$ 0,2616          |
    | 100.000 a 249.999   | R$ 0,1779          |
    | …                   | …                  |
    | acima de 30.000.000 | R$ 0,017           |

    A tabela completa tem 16 faixas; confira na loja antes de orçar. Franquia mínima ou mensalidade fixa: **NÃO CONFIRMADO** (não aparece na aba).

- **Autenticação**: OAuth2 `client_credentials` em `POST https://gateway.apiserpro.serpro.gov.br/token`, com `Authorization: Basic base64(consumerKey:consumerSecret)`. O token Bearer vale 1 hora e deve ser renovado ao expirar ou ao receber 401 [https://apicenter.estaleiro.serpro.gov.br/documentacao/consulta-cpf/pt/quick_start/].
- **Endpoint**:
    - O guia rápido documenta o ambiente de demonstração `https://gateway.apiserpro.serpro.gov.br/consulta-cpf-df-trial/v3/` com o caminho `cpf/{cpf}/{dataNascimento}` (ex.: `cpf/40442820135/14111970`).
    - As versões listadas são `consulta-cpf-df-v3`, `consulta-cpf-df-v2`, `consulta-cpf-df` e `consulta-cpf`, e a doc traz uma lista de CPFs de teste por situação [https://apicenter.estaleiro.serpro.gov.br/documentacao/consulta-cpf/pt/quick_start/].
    - URL de produção exata da v3: **NÃO CONFIRMADO** (só o trial foi lido).
- **Campos e códigos** [https://apicenter.estaleiro.serpro.gov.br/documentacao/consulta-cpf/pt/tipos_retornados/]:
    - Campos: NI, Nome, Situação/Código, Situação/Descrição, Data de Nascimento, Ano de Óbito, Data de Inscrição, Nome Social.
    - Situação: `0` Regular, `2` Suspensa, `3` Titular Falecido, `4` Pendente de Regularização, `5` Cancelada por Multiplicidade, `8` Nula, `9` Cancelada de Ofício.
    - A nova versão com nome social e data de inscrição foi noticiada pelo SERPRO [https://www.serpro.gov.br/menu/noticias/noticias-2025/consulta-cpf-informa-nome-social]. Os detalhes da notícia não puderam ser lidos, e a mudança de preço entre versões fica **NÃO CONFIRMADO**.
- **Impacto de produto**: a consulta **exige a data de nascimento** do signatário, que é mais um dado pessoal a coletar na página pública. A resposta devolve **nome, nascimento e óbito**, que o roadmap proíbe persistir além do resultado.

### 3.3 Separação obrigatória de conceitos (vocabulário do projeto)

Estes quatro conceitos **não se substituem**, e a UI e as evidências devem nomear exatamente o que foi feito, no mesmo espírito de `arquitetura.md` §2.

| Conceito                      | O que prova                                                                                                          | O que **não** prova                                       | Onde roda                                                                                                    | Implementação                                                                                 |
| ----------------------------- | -------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------- |
| **Validação de dígitos**      | Que o número tem 11 dígitos, não é sequência repetida e os dois DVs (módulo 11) conferem                             | Que o CPF existe, está regular ou pertence a quem digitou | Local (cliente e servidor), sem rede                                                                         | `CpfNumber` / regra de validação Laravel + espelho no React; **implementar agora**            |
| **Consulta cadastral**        | Que o CPF existe na base da Receita e sua situação (e, conforme o provedor, que nome/nascimento informados conferem) | Que a pessoa do outro lado da tela é o titular            | Serviço externo: o **serviço próprio do proprietário** (roadmap) ou SERPRO                                   | `CpfVerificationProvider`; **sem documentação do serviço próprio → contrato + fake**          |
| **Prova de posse**            | Que o participante controla um canal (e-mail, celular, WhatsApp) naquele momento                                     | Que o canal pertence ao titular do CPF                    | OTP já existente (`auth_challenges`), §2.9                                                                   | Já existe para e-mail; SMS/WhatsApp em §2.9                                                   |
| **Biometria / prova de vida** | Correspondência facial com base oficial e _liveness_                                                                 | —                                                         | Provedor especializado (ex.: SERPRO Datavalid, listado na loja [https://www.loja.serpro.gov.br/consultacpf]) | Backlog §4 via `IdentityVerificationProvider`; a captura simples de §2.10 **não é** biometria |

Regras derivadas:

- DV inválido → rejeita no cliente e no servidor e **nunca chama o provedor**.
- `verified` quer dizer apenas "consulta cadastral positiva no provedor X em T". **Nunca** "identidade verificada".
- A evidência mostra o CPF mascarado e o resultado. Nome, nascimento e óbito devolvidos pelo provedor não são persistidos.

---

## 4. Desenho proposto (para o incremento §2.11)

### 4.1 `CnpjLookupProvider`

```php
interface CnpjLookupProvider
{
    /** @throws CnpjLookupUnavailable em timeout, 5xx, 429 ou resposta malformada */
    public function lookup(Cnpj $cnpj, string $correlationId): ?CnpjRecord; // null = 404 (não encontrado)
}
```

- `MinhaReceitaCnpjLookup` com `base_url` configurável (`https://minhareceita.org` por padrão). Isso permite apontar para uma instância auto-hospedada no futuro sem mudar código. Timeout curto (3–5 s) e sem retry em 400/404.
- `BrasilApiCnpjLookup` fica opcional. Como é proxy do Minha Receita (§2.1), **não serve como fallback de disponibilidade**. Se for implementado, que seja só como alternativa configurável.
- `FakeCnpjLookup` com fixtures, identificado como fake, para dev e testes.
- **Cache** `cnpj_lookups`: `cnpj` (PK), `payload_minimizado` JSON, `source`, `source_updated` (valor de `/updated`), `fetched_at`, `expires_at` (TTL de 7–30 dias; a base muda mensalmente). 404 com TTL curto (1 dia).
- **Minimização**: persistir só o necessário ao cadastro e ao faturamento (`razao_social`, `nome_fantasia`, endereço, `situacao_cadastral`, `cnae_fiscal`). **Não** persistir `qsa` (sócios com nome e CPF parcial, §2.3) nem `email` e telefones de terceiros.
- **Degradação**: indisponível → formulário manual, sem bloquear o cadastro (aceite do roadmap). Uso sob demanda, uma consulta por ação humana, alinhado ao pedido da BrasilAPI e sem varredura. Throttle por organização.
- Atribuição: "Fonte: Receita Federal — dados abertos do CNPJ, via Minha Receita".

### 4.2 `CpfVerificationProvider`

```php
interface CpfVerificationProvider
{
    /** Nunca lança em indisponibilidade: devolve CpfVerificationResult::unavailable(reason) */
    public function verify(Cpf $cpf, CpfVerificationContext $ctx): CpfVerificationResult;
    // CpfVerificationResult: status ∈ verified|not_verified|unavailable, provider, checked_at, reason_code
}
```

- `$ctx` carrega a finalidade declarada, o `correlation_id` e, se o provedor exigir, a data de nascimento informada pelo signatário.
- `OwnServiceCpfVerificationProvider` (serviço próprio do proprietário) fica como **esqueleto sem chamadas HTTP inventadas**. Endpoint, autenticação, campos e códigos: **NÃO CONFIRMADO / sem documentação**. Registrado em `config/services.php` com `enabled=false` em produção.
- `FakeCpfVerificationProvider`, identificado, com CPFs de fixture para `verified`, `not_verified` e `unavailable` (timeout simulado).
- Mapeamento sugerido, a validar com a doc do provedor real:
    - situação regular e dados informados conferem → `verified`;
    - não encontrado, divergência ou situação ≠ regular → `not_verified`, com `reason_code` genérico e sem gravar a situação textual se isso não for necessário;
    - timeout, 5xx, 429 ou resposta inconclusiva → `unavailable`, que **não bloqueia** o aceite (roadmap).
- Gravar em `fields_snapshot` só `{status, provider, checked_at, reason_code}` e o CPF mascarado.

---

## 5. Decisão recomendada para o AssinaVelox

| Parte                                                                                        | Decisão                                                                                                                                                                                                                                                                                                                                                                                                                                                               | Justificativa                                                                                                                                                                                                                                                                                                                              |
| -------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Validação de dígitos de CPF** (cliente + servidor)                                         | **Implementar de verdade agora**                                                                                                                                                                                                                                                                                                                                                                                                                                      | Algoritmo local e determinístico, sem dependência externa nem dado enviado a terceiros; atende o aceite "campo `cpf` rejeita dígitos inválidos no cliente e no servidor".                                                                                                                                                                  |
| **Consulta de CNPJ** via `MinhaReceitaCnpjLookup` (+ cache `cnpj_lookups` + fallback manual) | **Implementar de verdade agora**, atrás de `features.cnpj_lookup`                                                                                                                                                                                                                                                                                                                                                                                                     | API pública e documentada (a), sem credencial, código MIT, dados oficiais com licença CC Attribution. O risco de ficar sem SLA é contido pelo cache e pelo preenchimento manual, que nunca bloqueia. `base_url` configurável deixa aberta a auto-hospedagem futura. Não contar com a BrasilAPI como redundância, porque é o mesmo backend. |
| **Consulta cadastral de CPF** (`CpfVerificationProvider` sobre o serviço próprio)            | **Implementar contrato + fake identificado, com produção desabilitada**                                                                                                                                                                                                                                                                                                                                                                                               | Não existe API oficial gratuita para empresa privada: a consulta web tem hCaptcha (c), o Conecta é só para órgãos públicos (b, inelegível) e o SERPRO é pago com contrato e e-CNPJ (b). O serviço próprio indicado no roadmap **não tem documentação disponível**, então não se inventa endpoint (T4).                                     |
| **Produção da consulta de CPF**                                                              | **Bloqueado** até: (1) o proprietário entregar a documentação do serviço próprio (endpoint, autenticação, campos, códigos de erro, SLA, entradas exigidas como data de nascimento, custo por consulta) e credenciais de homologação; **ou** (2) decidir contratar o SERPRO Consulta CPF (e-CNPJ da operadora, contrato, orçamento pela tabela de §3.2); mais (3) base legal e finalidade LGPD aprovadas para a consulta e a decisão de produto sobre o modo "estrito" | Sem esses três itens, ligar em produção seria ou simular verificação, ou tratar dado pessoal sem finalidade definida.                                                                                                                                                                                                                      |
| **Prova de posse e biometria**                                                               | Fora do §2.11                                                                                                                                                                                                                                                                                                                                                                                                                                                         | Posse = OTP (§2.9); biometria = backlog §4 (`IdentityVerificationProvider`). Nenhum resultado de CPF pode ser rotulado como qualquer uma das duas.                                                                                                                                                                                         |
