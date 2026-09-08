> # ⚠️ MINUTA GERADA AUTOMATICAMENTE — REQUER REVISÃO JURÍDICA ANTES DE PUBLICAÇÃO. Não constitui aconselhamento jurídico.
>
> Rascunho v0.1 · 2026-09-08 · Fonte técnica: `docs/arquitetura.md` (§2–§6) e `docs/design/RECONCILIACAO.md`.
> Trechos entre `{{ }}` são fatos a preencher pela empresa. Trechos marcados **[VALIDAR]** dependem de decisão jurídica ou de produto.
> Esta minuta descreve o que a Plataforma faz tecnicamente; **ela não afirma conformidade automática com a LGPD**. A conformidade depende de processos, contratos e governança fora do software.

# Política de Privacidade — AssinaVelox

**Última atualização:** 2026-09-08 (minuta)
**Quem somos:** {{RAZAO_SOCIAL}}, CNPJ {{CNPJ}}, {{ENDERECO}} ("**AssinaVelox**" ou "**nós**").
**Encarregado pelo tratamento de dados pessoais (DPO):** {{NOME_ENCARREGADO}} — {{EMAIL_DPO}}.

Esta Política explica como tratamos dados pessoais de (i) **Usuários** das organizações clientes, (ii) **Signatários** convidados a manifestar aceite sobre documentos, e (iii) **visitantes** das páginas públicas (verificação, termos, contato). Ela complementa os [Termos de Uso]({{URL_TERMOS}}) e se aplica à plataforma AssinaVelox ({{URL_PLATAFORMA}}) e às suas páginas públicas de assinatura ({{URL_PLATAFORMA}}/assinar/…) e de verificação ({{URL_VERIFICACAO}}).

Base normativa de referência: Lei nº 13.709/2018 (LGPD). **[VALIDAR]** A classificação de bases legais e de papéis indicada abaixo é uma proposta técnica e deve ser confirmada pela assessoria jurídica.

---

## 1. Papéis: quando somos controladora e quando somos operadora

| Conjunto de dados | Papel da AssinaVelox | Quem decide as finalidades |
|---|---|---|
| Dados de conta dos Usuários (nome, e-mail, senha, autenticação em duas etapas), dados da Organização (razão social, CNPJ/CPF) e dados de cobrança | **Controladora** | AssinaVelox |
| Dados de uso da Plataforma pelos Usuários (registros de acesso, trilha de ações administrativas) | **Controladora** | AssinaVelox |
| Dados dos **Signatários** (nome, e-mail, IP, navegador, imagem de assinatura, valores de campos, trilha do aceite) e **conteúdo dos documentos** | **Operadora** | A **Organização remetente**, que é a controladora |
| Dados de visitantes das páginas públicas (registros técnicos de acesso, limites de requisições) | **Controladora** | AssinaVelox |

Quando atuamos como operadora, tratamos os dados conforme as instruções da Organização, documentadas nos Termos de Uso e na configuração da Plataforma (por exemplo: prazo de expiração, exigência de código por e-mail, exibição de IP na página de evidências). Pedidos de Signatários sobre seus dados devem, em regra, ser dirigidos à Organização remetente; nós a auxiliamos e podemos ser contatados diretamente em {{EMAIL_DPO}}.

## 2. Quais dados tratamos

### 2.1 Usuários e Organizações

| Categoria | Dados | Origem |
|---|---|---|
| Identificação e acesso | Nome, e-mail, senha (armazenada apenas como *hash*), status de verificação do e-mail, segredo e códigos de recuperação da autenticação em duas etapas (criptografados), fuso horário | Informados pelo Usuário |
| Organização | Nome, razão social, CNPJ ou CPF (armazenado criptografado), fuso horário, idioma, configurações | Informados pelo `owner`/`admin` |
| Convites | E-mail do convidado, papel, quem convidou, datas de validade/aceite/revogação | Informados por quem convida |
| Uso e segurança | Endereço IP, navegador, datas de login e de ações relevantes (trilha de auditoria: criação, edição, envio, cancelamento de envelopes, downloads etc.) | Gerados pela Plataforma |
| Notificações | Preferências de notificação por e-mail e no aplicativo | Informados pelo Usuário |

### 2.2 Cobrança

| Dados | Observação |
|---|---|
| Plano, período, status da assinatura, contagem de envelopes usados/reservados | Gerados pela Plataforma |
| Identificadores do pagamento no Mercado Pago, status, valor, moeda, meio de pagamento (tipo), e-mail do pagador **mascarado**, data | Recebidos do provedor de pagamentos |
| Recibos de notificações (*webhooks*) do provedor, com carga útil e resultado da validação de assinatura | Recebidos do provedor |

**Não recebemos nem armazenamos número de cartão, código de segurança ou dados bancários.** O pagamento é realizado no ambiente do Mercado Pago, sujeito à política de privacidade daquele provedor.

### 2.3 Signatários (tratados como operadora)

| Categoria | Dados | Por que registramos |
|---|---|---|
| Identificação | Nome e e-mail, informados pela Organização; telefone, se informado (sem uso na versão atual) | Enviar o convite e o código; identificar o participante na página de evidências |
| Autenticação | Código de uso único enviado ao e-mail (armazenado apenas como *hash* com chave; **nunca em texto claro**), tentativas, validade, datas de envio e verificação | Comprovar que quem aceitou tinha acesso ao e-mail indicado |
| Sessão de assinatura | Identificador de sessão (apenas *digest*), IP, navegador, datas de início, autenticação e uso | Vincular o aceite a uma sessão autenticada |
| Aceite eletrônico | Data e hora do servidor (UTC), IP conforme informado pelo *proxy* confiável da Plataforma, navegador (*user-agent*), método de autenticação, versão e texto integral da declaração de aceite marcada, *hash* SHA-256 da versão do documento apresentada, retrato (*snapshot*) dos campos exibidos e dos valores preenchidos | Constituir a evidência do aceite e permitir sua verificação posterior |
| Representação visual | Imagem da assinatura (desenhada, digitada ou enviada), normalizada em PNG **sem metadados** (dimensões limitadas), nome digitado e fonte escolhida | Compor o documento consolidado |
| Valores de campos | Texto, marcações e imagens preenchidos nos campos atribuídos ao Signatário | Compor o documento consolidado e o retrato do aceite |
| Recusa | Motivo informado pelo Signatário, data | Comunicar a recusa à Organização e encerrar o envelope |
| Trilha de eventos | Abertura do link ("abertura detectada"), envio/verificação/falha do código, início de sessão, aceite, recusa, download — com data, IP e navegador quando aplicável | Reconstituir a sequência de fatos do envelope |
| Entregas de e-mail | Endereço destinatário, finalidade, status informado pelo provedor de e-mail ("enviado" ≠ "entregue"), identificador da mensagem, erro | Diagnosticar falhas de entrega e comprovar tentativas de notificação |

### 2.4 Conteúdo dos documentos

O conteúdo dos arquivos enviados (originais, convertidos, consolidados, página de evidências e arquivo final) pode conter dados pessoais de qualquer natureza, inclusive sensíveis, a critério exclusivo da Organização. **Não analisamos, indexamos nem extraímos** conteúdo dos documentos para finalidades próprias; o processamento se limita à conversão, inspeção técnica (páginas, dimensões, criptografia, assinaturas existentes), composição e cálculo de *hashes*.

### 2.5 Visitantes das páginas públicas

Endereço IP e navegador para limites de requisições e prevenção de abuso; código de verificação consultado; registros técnicos de acesso do servidor. Na verificação de arquivo local, o *hash* **é calculado no seu navegador** e **o arquivo não é enviado** aos nossos servidores.

## 3. Finalidades e bases legais **[VALIDAR]**

| Finalidade | Dados | Base legal sugerida (LGPD, art. 7º) |
|---|---|---|
| Criar e manter contas, autenticar Usuários, operar a Organização | Conta, Organização, convites | Execução de contrato (inc. V) |
| Cobrar planos, processar pagamentos e emitir documentos fiscais | Cobrança | Execução de contrato (inc. V); cumprimento de obrigação legal (inc. II) |
| Enviar convites, códigos de confirmação e notificações transacionais | Nome, e-mail, entregas | Execução de contrato / procedimentos preliminares (inc. V), por instrução da Organização controladora |
| Registrar o aceite eletrônico com evidências e a trilha de eventos | Aceite, sessão, trilha, representação visual, campos | Execução de contrato / procedimentos preliminares a pedido do titular (inc. V) e exercício regular de direitos (inc. VI), conforme definido pela Organização controladora |
| Gerar a página de evidências e o arquivo final; aplicar a assinatura criptográfica da Operadora quando configurada | Aceite, trilha, documento | Execução de contrato (inc. V); exercício regular de direitos (inc. VI) |
| Manter a página pública de verificação por código | Estado, datas, *hashes*, quantidade de participantes | Legítimo interesse (inc. IX) das partes e de terceiros em verificar integridade, com minimização (ver Seção 11) |
| Segurança, prevenção a fraude e abuso, limites de requisições | IP, navegador, registros | Legítimo interesse (inc. IX); cumprimento de obrigação legal quando aplicável |
| Suporte e comunicação operacional | Conta, registros | Execução de contrato (inc. V) |
| Comunicações de produto (novidades) | E-mail do Usuário | Consentimento (inc. I), revogável nas preferências |
| Atender autoridades e defender direitos | Quaisquer dos acima, conforme necessário | Obrigação legal (inc. II); exercício regular de direitos (inc. VI) |

**Observação importante sobre a caixa de aceite.** A caixa marcada pelo Signatário ("Li o documento e declaro que concordo…") é a **manifestação de vontade sobre o documento**, e o texto informa que os dados listados serão registrados como evidência. **Não tratamos essa marcação como "consentimento" para o tratamento de dados no sentido do art. 7º, I, da LGPD**: o tratamento das evidências decorre da relação entre o Signatário e a Organização remetente. **[VALIDAR]** esta caracterização.

## 4. Minimização: o que não coletamos e como reduzimos dados

- **Não coletamos** geolocalização, biometria, selfie, CPF ou documento de identidade do Signatário na versão atual; não há verificação de identidade civil.
- O código de confirmação **nunca é armazenado em texto claro**; tokens de link e de sessão são armazenados apenas como *digest*.
- As cargas úteis da trilha de auditoria **não contêm** tokens, senhas, códigos ou, quando evitável, e-mails completos.
- A imagem da assinatura é reprocessada em PNG com dimensões limitadas e **sem metadados** (por exemplo, dados EXIF do dispositivo).
- O e-mail do Signatário é exibido **mascarado** na página pública de assinatura.
- Na página de evidências, a exibição do IP segue a configuração da Organização (**mascarado**, completo ou oculto). **[VALIDAR]** padrão sugerido: mascarado.
- A página pública de verificação **não exibe** dados pessoais além do estritamente descrito na Seção 11.
- Os documentos ficam em armazenamento **privado**, acessível apenas por controladores autenticados e autorizados; **não geramos URLs públicas** para arquivos.

## 5. Com quem compartilhamos dados

Não vendemos dados pessoais e não os compartilhamos com anunciantes. Compartilhamos apenas com:

| Destinatário | Dados | Finalidade | Papel |
|---|---|---|---|
| Provedor de envio de e-mail transacional ({{PROVEDOR_EMAIL}}) — serviço contratado pela AssinaVelox | Nome, e-mail do destinatário, conteúdo do e-mail (convite, código, notificações) | Entregar mensagens | Suboperador |
| Mercado Pago | Dados necessários ao checkout; recebemos de volta identificadores e status do pagamento | Processar pagamentos de planos | Controlador independente para o pagamento **[VALIDAR]** |
| Provedor de armazenamento de arquivos ({{PROVEDOR_ARMAZENAMENTO}}) | Documentos, versões, imagens de assinatura, página de evidências | Armazenar arquivos em repositório privado com criptografia em repouso | Suboperador |
| Provedor de hospedagem e banco de dados ({{PROVEDOR_HOSPEDAGEM}}) | Todos os dados da Plataforma | Executar a Plataforma | Suboperador |
| Organização remetente | Todos os dados do envelope, incluindo evidências e imagens de assinatura | Ela é a controladora e destinatária do documento assinado | Controladora |
| Demais Signatários do mesmo envelope | Nome (e, na página de evidências, os dados de aceite de cada participante conforme configuração) | Todos os participantes recebem o arquivo final com a página de evidências | — |
| Autoridades públicas, judiciais ou regulatórias | Conforme exigido | Cumprir ordens legais | — |
| Sucessores em caso de reorganização societária | Conforme necessário, mediante aviso | Continuidade do serviço | — |

Os suboperadores são vinculados por contratos que impõem confidencialidade e segurança. A lista atualizada de suboperadores está disponível em {{URL_SUBOPERADORES}} **[VALIDAR se haverá página]**.

## 6. Transferência internacional **[VALIDAR]**

{{PAISES_TRANSFERENCIA}} — Indicar se os provedores de hospedagem, armazenamento ou e-mail processam dados fora do Brasil. Em caso positivo, a transferência será amparada em um dos mecanismos do art. 33 da LGPD (por exemplo, cláusulas contratuais padrão ou país com grau de proteção adequado), a ser identificado aqui. Se todo o processamento ocorrer no Brasil, declarar isso expressamente.

## 7. Por quanto tempo guardamos os dados

| Dados | Prazo |
|---|---|
| Conta de Usuário e Organização | Enquanto a conta existir; após solicitação de exclusão, ver abaixo |
| Envelopes, documentos, versões, imagens de assinatura, evidências e trilha | Enquanto a Organização existir. **Evidências do aceite são mantidas enquanto houver finalidade legítima ou obrigação legal**, pois servem à comprovação do ato para todas as partes |
| Exclusão da Organização | O `owner` solicita; a exclusão é agendada para **30 dias** depois e pode ser cancelada nesse prazo. Ao término, os dados são apagados dos sistemas ativos; cópias de segurança são sobrescritas em até {{PRAZO_BACKUP}} |
| Links de convite e sessões de assinatura | Expiram automaticamente (links conforme prazo do envelope; sessão de 30 minutos; código de confirmação com validade de 10 minutos); registros permanecem apenas na trilha, sem os segredos |
| Registros de pagamento e documentos fiscais | {{PRAZO_FISCAL}} (prazo legal fiscal/contábil), mesmo após a exclusão da conta |
| Registros técnicos de acesso e segurança (*logs*) | {{PRAZO_LOGS}} |
| Registros de entrega de e-mail | {{PRAZO_LOGS_EMAIL}} |
| Registro público de verificação (código, estado, *hashes*) | **[DECISÃO PENDENTE]** manter, anonimizar ou remover após a exclusão da Organização |

Após os prazos, os dados são eliminados ou anonimizados, salvo quando a lei exigir a conservação ou quando forem necessários ao exercício regular de direitos.

## 8. Direitos dos titulares

Nos termos do art. 18 da LGPD, você pode solicitar: confirmação da existência de tratamento; acesso; correção de dados incompletos, inexatos ou desatualizados; anonimização, bloqueio ou eliminação de dados desnecessários ou tratados em desconformidade; portabilidade; informação sobre compartilhamentos; informação sobre a possibilidade de não fornecer consentimento e suas consequências; revogação do consentimento; e oposição a tratamento baseado em outras hipóteses legais quando houver descumprimento da lei.

**Como exercer:**
- **Usuários:** diretamente na Plataforma (perfil, segurança, exclusão da Organização) ou por {{EMAIL_DPO}}.
- **Signatários:** preferencialmente junto à **Organização remetente**, controladora dos seus dados; você também pode nos escrever em {{EMAIL_DPO}} e nós encaminharemos e auxiliaremos.
- Responderemos em até {{PRAZO_RESPOSTA_DSR}} **[VALIDAR]**. Poderemos solicitar informações para confirmar sua identidade antes de atender ao pedido.

**Limites.** Pedidos de eliminação de evidências de aceite podem ser recusados, total ou parcialmente, quando a conservação for necessária ao cumprimento de obrigação legal ou ao exercício regular de direitos das partes do documento (LGPD, art. 16). Nesses casos, informaremos os motivos.

Você também pode apresentar reclamação à Autoridade Nacional de Proteção de Dados (ANPD).

## 9. Cookies e tecnologias semelhantes

Usamos **apenas cookies estritamente necessários**:

| Cookie (nome indicativo) | Finalidade | Duração |
|---|---|---|
| Sessão da aplicação (`{{NOME_COOKIE_SESSAO}}`) | Manter o Usuário autenticado e a sessão do Signatário durante o fluxo de assinatura | Sessão / até 30 min de inatividade no fluxo de assinatura |
| Token anti-CSRF (`XSRF-TOKEN`) | Proteger formulários contra requisições forjadas | Sessão |
| "Lembrar-me" (`remember_*`) | Manter o login quando o Usuário optar | {{DURACAO_REMEMBER}} |

**Na página pública de assinatura e na página de verificação não há rastreadores de terceiros, pixels, análise de audiência ou publicidade.** As páginas de assinatura são marcadas para não indexação por buscadores e não enviam o endereço da página a terceiros (*no-referrer*).

**[VALIDAR — ponto técnico]** Se a fonte tipográfica da interface for carregada de um servidor externo (por exemplo, Google Fonts), o endereço IP do visitante é transmitido a esse terceiro ao carregar a página. Recomendação técnica: servir as fontes localmente ao menos nas páginas públicas de assinatura e verificação, para que a afirmação acima seja integralmente verdadeira; caso contrário, declarar esse terceiro aqui.

## 10. Segurança

Adotamos medidas técnicas e administrativas proporcionais ao risco, entre elas:

- **Criptografia em trânsito** (HTTPS/TLS) em todas as páginas e e-mails com transporte seguro quando suportado pelo destinatário.
- **Controle de acesso** por Organização e por papel (`owner`, `admin`, `member`), verificado em cada requisição; equipe interna com painel **somente leitura** e **sem acesso ao conteúdo dos documentos**.
- **Autenticação em duas etapas** disponível para Usuários; senhas armazenadas apenas como *hash*.
- **Armazenamento privado** dos arquivos, sem URLs públicas; download apenas por controlador autenticado e autorizado; criptografia em repouso no provedor de armazenamento.
- **Segredos protegidos**: códigos de confirmação e tokens gravados apenas como *digest*; CNPJ/CPF criptografados; certificado A1 e sua senha fornecidos ao processo de assinatura por variável de ambiente, nunca em argumentos, filas ou registros.
- **Trilha de auditoria somente de acréscimo**, com restrição de alteração no banco de dados de produção.
- **Limites de requisições** para envio e verificação de códigos, acesso a links e consultas de verificação.
- **Cabeçalhos de segurança** (política de conteúdo, não indexação e *no-referrer* nas páginas de assinatura).
- **Isolamento de processos** de conversão e composição de PDF, sem acesso à rede e com tempo limite.

Nenhuma medida elimina totalmente o risco. Em caso de incidente de segurança que possa acarretar risco ou dano relevante aos titulares, comunicaremos a ANPD e os titulares (ou a Organização controladora, conforme o caso) na forma do art. 48 da LGPD **[VALIDAR procedimento]**.

## 11. O que a página pública de verificação exibe — e o que não exibe

Qualquer pessoa com o código de verificação (`XXXX-XXXX-XXXX`), impresso no rodapé do arquivo final, pode consultar {{URL_VERIFICACAO}}.

**Exibe:**
- o estado do envelope (em andamento, concluído, recusado, expirado ou cancelado) e a data de conclusão;
- a quantidade de participantes;
- os *hashes* SHA-256 do documento enviado para assinatura e do arquivo final;
- se há assinatura criptográfica da Operadora (`company_a1`) ou não (`none`), o perfil (por exemplo, PAdES-B-B) e o resultado técnico da validação;
- **[VALIDAR]** o nome da Organização remetente, o título do documento e os nomes dos participantes **parcialmente mascarados** (por exemplo, "Maria A. S."), conforme configuração de produto;
- um verificador de arquivo local, cujo cálculo de *hash* ocorre **no navegador**, sem envio do arquivo.

**Não exibe, em nenhuma hipótese:**
- o conteúdo do documento, miniaturas ou qualquer link de download;
- e-mails, telefones, CPF, endereços IP, navegadores, geolocalização;
- imagens de assinatura ou valores de campos preenchidos;
- códigos de confirmação, tokens ou identificadores internos;
- a mensagem do remetente, pastas, nome do Usuário que criou o envelope;
- dados de plano ou cobrança;
- qualquer indicação de existência para códigos inexistentes ou de rascunhos (a resposta é idêntica: "nenhum documento encontrado").

A página de verificação **não é um certificado** e um *hash* **não é uma assinatura**: a página permite conferir se um arquivo em seu poder é idêntico ao arquivo final gerado pela Plataforma e qual é o estado registrado do envelope.

## 12. Crianças e adolescentes

A Plataforma é destinada a pessoas maiores de 18 anos e a organizações. A Organização remetente é responsável por não convidar como Signatários pessoas que não possam validamente manifestar aceite, e por observar o art. 14 da LGPD quando documentos envolverem dados de crianças e adolescentes.

## 13. Alterações desta Política

Podemos alterar esta Política. Alterações relevantes serão comunicadas aos Usuários por e-mail e/ou aviso na Plataforma com antecedência razoável. A data de "última atualização" indica a versão vigente. Versões anteriores ficam disponíveis mediante solicitação.

## 14. Contato

- **Encarregado (DPO):** {{NOME_ENCARREGADO}} — {{EMAIL_DPO}}
- **Suporte:** {{EMAIL_SUPORTE}}
- **Endereço:** {{RAZAO_SOCIAL}}, CNPJ {{CNPJ}}, {{ENDERECO}}

---

## Anexo — Pontos a validar pela assessoria jurídica

1. Papéis controladora/operadora por conjunto de dados (Seção 1) e enquadramento do Mercado Pago (Seção 5).
2. Bases legais por finalidade (Seção 3) e a caracterização da caixa de aceite como manifestação de vontade, não como consentimento LGPD.
3. Prazos de retenção em aberto: logs, fiscal, backups, prazo de resposta a titulares (Seções 7 e 8).
4. Destino do registro público de verificação após a exclusão da Organização (Seção 7).
5. Transferência internacional e mecanismo do art. 33 (Seção 6).
6. Carregamento de fontes de terceiros nas páginas públicas (Seção 9).
7. Procedimento de comunicação de incidentes (Seção 10).
8. Itens exibidos na página de verificação que dependem de decisão de produto (Seção 11).
