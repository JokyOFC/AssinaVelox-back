> # ⚠️ MINUTA GERADA AUTOMATICAMENTE — REQUER REVISÃO JURÍDICA ANTES DE PUBLICAÇÃO. Não constitui aconselhamento jurídico.
>
> Rascunho v0.1 · 2026-09-08 · Fonte técnica: `docs/arquitetura.md` (§2, §4–§6) e `docs/design/RECONCILIACAO.md`.
> Trechos entre `{{ }}` são fatos a preencher pela empresa. Trechos marcados **[VALIDAR]** dependem de decisão jurídica ou de produto.

# Termos de Uso — Plataforma AssinaVelox

**Última atualização:** 2026-09-08 (minuta)
**Operadora da plataforma:** {{RAZAO_SOCIAL}}, inscrita no CNPJ sob o nº {{CNPJ}}, com sede em {{ENDERECO}} ("**AssinaVelox**", "**Operadora**" ou "**nós**").

Estes Termos de Uso ("**Termos**") regulam o acesso e o uso da plataforma AssinaVelox, disponível em {{URL_PLATAFORMA}} ("**Plataforma**"), por pessoas jurídicas ou físicas que criam uma conta para preparar documentos e coletar aceites eletrônicos ("**Organização**", "**Cliente**" ou "**você**"). Ao criar uma conta, aceitar um convite para uma Organização ou utilizar a Plataforma, você declara que leu, compreendeu e concorda com estes Termos e com a [Política de Privacidade]({{URL_PRIVACIDADE}}).

Se você aceita estes Termos em nome de uma pessoa jurídica, declara ter poderes para vinculá-la.

---

## 1. Definições

| Termo                                     | Significado                                                                                                                                                                              |
| ----------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Plataforma**                            | O software AssinaVelox, suas páginas web, e-mails transacionais e demais recursos operados pela Operadora.                                                                               |
| **Organização**                           | A conta de cliente (empresa ou pessoa) titular dos envelopes, documentos e usuários vinculados.                                                                                          |
| **Usuário**                               | Pessoa natural com login na Plataforma, vinculada a uma ou mais Organizações por um papel (`owner`, `admin` ou `member`).                                                                |
| **Signatário**                            | Pessoa indicada pela Organização para manifestar aceite sobre um documento. Não possui conta; acessa por link enviado ao seu e-mail.                                                     |
| **Envelope**                              | Unidade de envio: um documento, seus campos, seus Signatários, sua ordem de assinatura, prazo e trilha de eventos. Cada envelope recebe um código de verificação público ao ser enviado. |
| **Documento**                             | Arquivo enviado pela Organização (PDF, DOCX ou imagem) e suas versões geradas pela Plataforma (convertida, consolidada, de evidências e final).                                          |
| **Representação visual da assinatura**    | Imagem (desenhada, digitada ou enviada) posicionada no documento. Por si só, não comprova nada.                                                                                          |
| **Aceite eletrônico com evidências**      | Manifestação de vontade do Signatário registrada pela Plataforma junto com evidências técnicas (ver Cláusula 3).                                                                         |
| **Assinatura criptográfica da Operadora** | Assinatura digital no padrão PAdES aplicada ao arquivo final com certificado A1 de titularidade da Operadora, quando configurado. Identifica a Operadora, não o Signatário.              |
| **Página de evidências**                  | Relatório gerado pela Plataforma e anexado ao arquivo final, descrevendo os eventos, os participantes e os resumos criptográficos (hashes) do envelope.                                  |
| **Código de verificação**                 | Código público, aleatório, exibido no formato `XXXX-XXXX-XXXX`, que permite consultar o estado e os hashes de um envelope em {{URL_VERIFICACAO}}.                                        |

## 2. Objeto

2.1. A Plataforma oferece ferramentas para: (a) enviar e preparar documentos; (b) posicionar campos de assinatura e de preenchimento; (c) convidar Signatários por e-mail; (d) autenticar o acesso do Signatário por código enviado ao seu e-mail; (e) registrar o aceite eletrônico com evidências; (f) consolidar o documento final com página de evidências; (g) quando houver certificado configurado, aplicar a assinatura criptográfica da Operadora ao arquivo final; e (h) disponibilizar uma página pública de verificação por código.

2.2. A Plataforma é uma ferramenta de apoio. **A Operadora não é parte dos documentos, não revisa o seu conteúdo e não presta assessoria jurídica.** A escolha do meio de assinatura adequado a cada negócio é responsabilidade da Organização e, se for o caso, de seus assessores.

## 3. Natureza do serviço e o que a Plataforma efetivamente registra

Esta cláusula é central para o uso correto do serviço. A Plataforma distingue, na interface, nos relatórios e nestes Termos, quatro coisas diferentes:

**3.1. Representação visual da assinatura.** O desenho feito na tela, o nome digitado em fonte manuscrita ou a imagem enviada pelo Signatário é apenas uma **representação gráfica**. Ela é posicionada no documento para leitura humana e **não constitui prova de autoria por si só**.

**3.2. Aceite eletrônico com evidências.** O que a Plataforma registra como manifestação de vontade é o conjunto dos seguintes elementos, gravados no momento em que o Signatário marca a declaração de aceite e confirma:

- a versão exata do documento que foi apresentada ao Signatário, identificada pelo seu resumo criptográfico SHA-256;
- os campos apresentados ao Signatário e os valores por ele preenchidos (retrato, ou _snapshot_, do que foi exibido);
- o método de autenticação utilizado — na versão atual, **código de uso único enviado ao e-mail indicado pela Organização**;
- a data e a hora do servidor, em UTC;
- o endereço IP do Signatário, conforme informado pela infraestrutura de rede confiável da Plataforma, e a identificação do navegador (_user-agent_);
- a versão do texto de aceite exibido e o texto integral aceito;
- os eventos da trilha de auditoria (abertura do link, envio e verificação do código, início da sessão, aceite ou recusa).

**3.3. Assinatura criptográfica da Operadora.** Quando houver certificado digital A1 configurado e ativo, a Plataforma aplica ao arquivo final uma assinatura digital no perfil PAdES (alvo: PAdES B-B), com certificado **de titularidade da Operadora** ({{RAZAO_SOCIAL}}). Essa assinatura:

- identifica a **Operadora** como quem lacrou o arquivo final e permite detectar alterações posteriores ao arquivo;
- **não é assinatura pessoal de nenhum Signatário** e **não é assinatura ICP-Brasil do Signatário**;
- não substitui a análise das evidências descritas em 3.2, que permanecem a base do aceite.

**3.4. Ausência de assinatura criptográfica.** Se não houver certificado configurado, ou se ele estiver expirado, revogado ou desativado, o envelope é concluído **apenas como aceite eletrônico com evidências**. A interface, a página de evidências e a página pública de verificação informam exatamente esse estado (`signature_status = none`). A Plataforma **nunca simula** assinatura criptográfica. Certificados de ambiente de teste são rotulados como tal e nunca são apresentados como certificados de produção.

**3.5. Sem garantia de validade jurídica universal.** A Operadora **não garante** que o aceite eletrônico coletado pela Plataforma seja aceito por qualquer autoridade, contraparte, cartório, órgão público ou tribunal, nem que seja equivalente a uma assinatura com certificado ICP-Brasil do próprio Signatário. A aptidão do meio para cada tipo de ato depende da legislação aplicável, da forma exigida para o negócio e do acordo entre as partes.

**3.6. Referências normativas [VALIDAR].** A Lei nº 14.063/2020 define níveis de assinatura eletrônica (simples, avançada e qualificada) para determinadas interações, e a Medida Provisória nº 2.200-2/2001 instituiu a ICP-Brasil e trata de outros meios de comprovação de autoria e integridade admitidos pelas partes. **Estes Termos não afirmam o enquadramento do aceite eletrônico da Plataforma em qualquer desses níveis**; a avaliação deve ser feita pela assessoria jurídica da Organização e da Operadora, caso a caso.

**3.7. Página de evidências e hashes.** A página de evidências não é um certificado digital. Um resumo SHA-256 não é uma assinatura: ele permite verificar se dois arquivos são idênticos byte a byte, e nada mais. A Plataforma registra quatro resumos, sempre indicando de quais bytes cada um foi calculado (ver `declaracao-de-aceite.md`, seção "Rodapé da página de evidências").

## 4. Conta, Organização e papéis

4.1. **Cadastro.** O cadastro exige nome, e-mail válido e senha. A verificação do e-mail é obrigatória. Ao se cadastrar, o Usuário cria uma Organização, da qual se torna `owner`, com plano gratuito ativo.

4.2. **Papéis.**

- `owner`: acesso total, incluindo cobrança, gestão de outros owners e solicitação de exclusão da Organização;
- `admin`: gestão de usuários (exceto owners), pastas, todos os envelopes e configurações;
- `member`: cria e gerencia apenas os próprios envelopes.

Toda Organização mantém ao menos um `owner`.

4.3. **Convites.** Usuários são adicionados por convite enviado ao e-mail indicado, com prazo de validade. A Organização responde pelos atos de todos os seus Usuários.

4.4. **Segurança da conta.** O Usuário é responsável por manter a confidencialidade de sua senha e de seus códigos de recuperação, e por ativar a autenticação em duas etapas quando disponível. Comunique imediatamente à Operadora ({{EMAIL_SUPORTE}}) qualquer suspeita de acesso indevido.

4.5. **Equipe interna da Operadora.** Colaboradores da Operadora com perfil administrativo acessam um painel **somente leitura** de dados de clientes, planos e pagamentos, para suporte e operação. **Esse perfil não concede acesso ao conteúdo dos documentos** da Organização.

## 5. Planos, cobrança e limites de uso

5.1. **Planos.** Os planos, seus preços em reais, periodicidade (mensal ou anual), limites de envelopes, de usuários e de armazenamento estão descritos em {{URL_PLATAFORMA}}/planos. O plano gratuito possui limites reduzidos e pode ser alterado mediante aviso prévio.

5.2. **Contagem do uso.** O limite de envelopes é contado **por envelope enviado**. O consumo é reservado no momento do envio e confirmado quando o envio é concluído com sucesso; em falha técnica de envio, a reserva é liberada. **[VALIDAR]** Envelopes enviados que sejam posteriormente recusados, expirados ou cancelados **continuam contando** no período, pois o serviço de envio e de coleta foi prestado.

5.3. **Pagamento via Mercado Pago.** Os planos pagos são cobrados por ciclo (mensal ou anual), por meio do Mercado Pago Checkout Pro, conforme os termos do próprio provedor. A Operadora **não armazena** dados de cartão. **Um pagamento só é considerado confirmado quando a Plataforma recebe do provedor uma notificação autenticada e a confirma por consulta à API do provedor.** O retorno do navegador após o checkout ("sucesso", "pendente" ou "falha") é apenas informativo e não ativa o plano.

5.4. **Renovação e inadimplência.** **[VALIDAR prazos]** Ao fim do ciclo sem pagamento confirmado, a Organização dispõe de um prazo de carência de 3 dias. Após esse prazo, a assinatura passa a "em atraso": o **envio de novos envelopes é bloqueado**, mas leitura, acompanhamento e download continuam disponíveis. Após 15 dias sem pagamento, a assinatura é encerrada e a Organização retorna ao plano gratuito, sujeita aos seus limites.

5.5. **Cancelamento e reembolso.** A Organização pode cancelar a renovação a qualquer tempo, mantendo o plano até o fim do ciclo pago. **[VALIDAR]** Reembolsos de ciclos já iniciados seguem {{POLITICA_REEMBOLSO}} e a legislação aplicável, inclusive o direito de arrependimento quando cabível.

5.6. **Preços e tributos.** Os preços podem ser atualizados mediante aviso com ao menos {{PRAZO_AVISO_PRECOS}} de antecedência; a alteração vale a partir do ciclo seguinte. Documentos fiscais são emitidos conforme a legislação; a emissão automatizada de nota fiscal pela Plataforma poderá não estar disponível na versão atual.

## 6. Responsabilidades da Organização

6.1. **Conteúdo dos documentos.** A Organização é a única responsável pelo conteúdo, licitude, veracidade, forma e adequação dos documentos que envia, e por ter o direito de tratá-los e de compartilhá-los com os Signatários indicados. A Operadora não modera conteúdo preventivamente.

6.2. **Identificação dos Signatários.** A Organização é responsável por indicar **corretamente o nome e o e-mail** de cada Signatário e por avaliar se a autenticação por código enviado ao e-mail é suficiente para o ato pretendido. A Plataforma autentica **a posse da caixa de e-mail indicada no momento do aceite**; ela **não verifica identidade civil**, CPF, documentos ou biometria na versão atual. Se o e-mail indicado for incorreto, compartilhado ou estiver comprometido, o aceite poderá ser prestado por pessoa diversa da pretendida, sem que a Operadora possa detectar isso.

6.3. **Base para o tratamento de dados dos Signatários.** A Organização é a **controladora** dos dados pessoais dos Signatários e do conteúdo dos documentos, e garante possuir base legal adequada para submetê-los à Plataforma. A Operadora atua como **operadora** desses dados, conforme a Política de Privacidade.

6.4. **Uso adequado do resultado.** A Organização se compromete a não apresentar o aceite eletrônico coletado como assinatura ICP-Brasil do Signatário, nem a assinatura criptográfica da Operadora como assinatura pessoal de qualquer participante.

6.5. **Guarda das cópias.** A Organização deve baixar e guardar suas próprias cópias dos arquivos finais e das páginas de evidências. A Plataforma não é um serviço de arquivamento de longo prazo (ver Cláusula 11).

## 7. Responsabilidades da Operadora

7.1. Prestar o serviço com diligência, empregando medidas técnicas e administrativas razoáveis de segurança (criptografia em trânsito, controle de acesso por Organização e papel, armazenamento privado, trilha de auditoria apenas de acréscimo).

7.2. Não alterar o conteúdo dos documentos, exceto pelas operações inerentes ao serviço e descritas na Plataforma: conversão de formato, achatamento dos campos preenchidos na versão consolidada, acréscimo da página de evidências e do rodapé de verificação, e, quando aplicável, aplicação da assinatura criptográfica da Operadora.

7.3. Calcular e registrar os resumos SHA-256 das versões do documento e mantê-los disponíveis na página pública de verificação enquanto o envelope existir.

7.4. Manter a Plataforma disponível em regime de melhores esforços. **[VALIDAR]** Não há compromisso de nível de serviço (SLA) no plano gratuito; para planos pagos, aplica-se {{SLA_PLANOS_PAGOS}}, se houver. Manutenções programadas serão comunicadas com antecedência razoável quando possível.

7.5. Comunicar à Organização, sem demora indevida, incidentes de segurança que possam acarretar risco relevante aos seus dados, na forma da legislação.

## 8. Certificado digital e assinatura criptográfica da Operadora

8.1. Na versão atual, existe **um único certificado A1, de titularidade da Operadora**, aplicável a todos os envelopes concluídos enquanto ativo. Certificado próprio por Organização não está disponível.

8.2. A Operadora pode, a seu critério e sem que isso configure inadimplemento, operar temporária ou permanentemente **sem certificado ativo** (por exemplo, durante renovação, revogação ou incidente). Nesse caso, aplica-se a Cláusula 3.4: os envelopes são concluídos como aceite eletrônico com evidências, e o estado é informado com clareza.

8.3. A Organização reconhece que a assinatura criptográfica da Operadora não a identifica nem identifica os Signatários; ela atesta apenas que o arquivo final foi lacrado pela Plataforma e não sofreu alteração desde então, nos limites técnicos da validação apresentada.

8.4. O resultado técnico da validação da assinatura (por exemplo, validade da cadeia no momento da conclusão) é exibido como informação, sem garantia de aceitação por terceiros.

## 9. Signatários externos

9.1. Signatários não precisam de conta. Eles acessam por link enviado ao e-mail indicado pela Organização, recebem um código de uso único e, após autenticação, visualizam o documento, preenchem os campos a eles atribuídos e podem **aceitar** ou **recusar** (com motivo).

9.2. A abertura do link é registrada como evento de trilha ("abertura detectada"), o que **não comprova leitura** do documento.

9.3. Antes de solicitar o código e antes do aceite, o Signatário recebe um aviso de privacidade indicando a Organização como controladora e a Operadora como operadora (ver `aviso-de-privacidade-signatario.md`).

9.4. A recusa de um Signatário encerra o envelope, conforme a política padrão, e é comunicada à Organização com o motivo informado.

9.5. Links de convite expiram, podem ser revogados e são substituídos a cada reenvio. O envelope expira ao fim do prazo definido pela Organização.

## 10. Propriedade intelectual e licença

10.1. A Plataforma, seu código, marca, layout e documentação pertencem à Operadora ou a seus licenciantes. Concede-se à Organização uma licença limitada, não exclusiva, intransferível e revogável de uso da Plataforma durante a vigência destes Termos.

10.2. Os documentos e dados enviados permanecem de titularidade da Organização (ou de quem de direito). A Organização concede à Operadora licença limitada para hospedá-los, processá-los, convertê-los e transmiti-los **exclusivamente para prestar o serviço**.

10.3. É vedado copiar, modificar, descompilar, fazer engenharia reversa, revender ou sublicenciar a Plataforma, salvo permissão legal expressa.

## 11. Retenção, exportação e exclusão

11.1. **Durante a vigência.** Documentos, versões, imagens de assinatura, evidências e trilhas são mantidos enquanto a Organização existir, observados os limites de armazenamento do plano.

11.2. **Exclusão de envelopes.** Rascunhos podem ser excluídos pela Organização. **[VALIDAR]** Envelopes já enviados não são excluídos individualmente na versão atual, pois suas evidências podem ser necessárias ao exercício regular de direitos das partes; eles podem ser cancelados e organizados em pastas.

11.3. **Exclusão da Organização.** O `owner` pode solicitar a exclusão da Organização. A solicitação é confirmada com senha e agenda a exclusão para **30 dias** depois; durante esse prazo, a solicitação pode ser cancelada e os arquivos podem ser baixados. Ao término, documentos, versões, imagens, campos, trilhas e dados de Usuários exclusivos da Organização são apagados dos sistemas ativos. A assinatura de plano é cancelada.

11.4. **Registros mantidos após a exclusão [VALIDAR].** A Operadora poderá conservar, pelo prazo legal, registros mínimos necessários ao cumprimento de obrigações legais e regulatórias (por exemplo, registros de pagamento e faturamento) e à defesa em processos, na forma da Política de Privacidade. **Decisão pendente:** se o registro público de verificação (código, hashes e estado) deve ser mantido, anonimizado ou removido após a exclusão da Organização.

11.5. **Cópias de segurança.** Cópias de segurança (_backups_) são sobrescritas em ciclo de até {{PRAZO_BACKUP}} após a exclusão.

## 12. Uso aceitável

É proibido utilizar a Plataforma para: (a) fins ilícitos, fraudulentos ou que violem direitos de terceiros; (b) enviar documentos a pessoas sem relação legítima com o ato, ou praticar envio em massa não solicitado; (c) fazer-se passar por outra pessoa ou organização; (d) transmitir código malicioso ou tentar acessar dados de outras Organizações; (e) contornar limites de plano, mecanismos de autenticação, limites de requisições ou controles de segurança; (f) representar o aceite eletrônico coletado como algo diverso do que ele é (Cláusula 3); (g) sobrecarregar ou interferir na infraestrutura.

## 13. Suspensão e encerramento

13.1. A Operadora pode suspender, total ou parcialmente, o acesso da Organização ou de um Usuário, mediante aviso quando viável, em caso de: violação destes Termos; inadimplência (Cláusula 5.4); suspeita fundada de fraude ou de uso abusivo; ordem de autoridade competente; ou risco à segurança da Plataforma ou de terceiros.

13.2. Durante a suspensão, a Organização em geral mantém acesso de leitura e download, salvo quando a própria natureza da medida exigir bloqueio total.

13.3. A Organização pode encerrar o uso a qualquer tempo, cancelando a renovação e, se desejar, solicitando a exclusão (Cláusula 11.3).

13.4. A Operadora pode descontinuar a Plataforma mediante aviso prévio de ao menos {{PRAZO_AVISO_DESCONTINUACAO}}, garantindo à Organização a possibilidade de exportar seus arquivos durante esse período.

## 14. Limitação de responsabilidade

**[VALIDAR — cláusula sensível; adequar ao CDC quando aplicável e à natureza do cliente.]**

14.1. A Operadora não responde por: (a) validade, eficácia ou executoriedade dos negócios documentados; (b) conteúdo dos documentos; (c) indicação incorreta de Signatários ou de e-mails; (d) comprometimento da caixa de e-mail ou do dispositivo do Signatário ou do Usuário; (e) falhas de provedores de e-mail, de pagamento, de hospedagem ou de conectividade fora do seu controle razoável; (f) não aceitação do aceite eletrônico por terceiros; (g) caso fortuito ou força maior.

14.2. Na máxima extensão permitida pela lei, a responsabilidade total da Operadora por danos decorrentes destes Termos fica limitada a {{LIMITE_RESPONSABILIDADE}} (sugestão: o valor efetivamente pago pela Organização nos 12 meses anteriores ao evento), excluídos lucros cessantes e danos indiretos.

14.3. Nada nestes Termos exclui responsabilidades que não possam ser excluídas por lei.

## 15. Privacidade e proteção de dados

15.1. O tratamento de dados pessoais pela Plataforma é descrito na [Política de Privacidade]({{URL_PRIVACIDADE}}), que integra estes Termos.

15.2. Em relação aos dados de conta, cobrança e uso da Plataforma pelos Usuários, a Operadora atua como **controladora**. Em relação aos dados dos Signatários e ao conteúdo dos documentos, a Operadora atua como **operadora**, tratando-os segundo as instruções da Organização, documentadas nestes Termos e na configuração da Plataforma.

15.3. A Operadora assiste a Organização, na medida do razoável, no atendimento de solicitações de titulares relacionadas aos envelopes, e a Organização se compromete a encaminhar pedidos de Signatários que dependam de ação técnica da Operadora.

## 16. Alterações destes Termos

A Operadora pode alterar estes Termos. Alterações relevantes serão comunicadas por e-mail ao `owner` e/ou por aviso na Plataforma com ao menos {{PRAZO_AVISO_ALTERACOES}} de antecedência, salvo quando exigido de imediato por lei ou por segurança. O uso continuado após a vigência da nova versão implica concordância. Versões anteriores ficam disponíveis mediante solicitação.

## 17. Comunicações

Comunicações da Operadora são feitas pelo e-mail cadastrado e por avisos na Plataforma. Comunicações à Operadora: {{EMAIL_SUPORTE}} (suporte) e {{EMAIL_DPO}} (proteção de dados). Endereço: {{ENDERECO}}.

## 18. Disposições gerais

18.1. Estes Termos são regidos pelas leis da República Federativa do Brasil.

18.2. A nulidade de qualquer cláusula não afeta as demais.

18.3. A tolerância quanto ao descumprimento de qualquer cláusula não implica renúncia.

18.4. A Organização não pode ceder estes Termos sem anuência da Operadora; a Operadora pode cedê-los em caso de reorganização societária, mediante aviso.

18.5. **Foro [VALIDAR].** Fica eleito o foro da comarca de {{FORO_CIDADE_UF}} para dirimir controvérsias, ressalvado o foro do domicílio do consumidor quando a relação for de consumo e a lei assim determinar.

---

## Anexo A — Quadro-resumo da semântica de assinatura (para leitura rápida)

| O que você vê                                                                                       | O que é                               | O que prova                                                                                                  |
| --------------------------------------------------------------------------------------------------- | ------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Desenho, nome estilizado ou imagem no documento                                                     | Representação visual                  | Nada, por si só.                                                                                             |
| Registro de aceite com data UTC, IP, navegador, hash do documento, campos e autenticação por e-mail | Aceite eletrônico com evidências      | Que alguém com acesso ao e-mail indicado, na data registrada, aceitou aquela versão exata do documento.      |
| "Assinado digitalmente por {{RAZAO_SOCIAL}}" (PAdES) no arquivo final                               | Assinatura criptográfica da Operadora | Que a Plataforma lacrou o arquivo final e que ele não foi alterado depois. **Não identifica o Signatário.**  |
| "Concluído como aceite eletrônico com evidências (sem assinatura criptográfica)"                    | Ausência de certificado configurado   | Somente o aceite eletrônico com evidências; integridade conferível pelo hash final na página de verificação. |
| Hash SHA-256                                                                                        | Resumo criptográfico                  | Que dois arquivos são (ou não) idênticos byte a byte. Não é assinatura.                                      |

## Anexo B — Pontos a validar pela assessoria jurídica

1. Enquadramento (ou não) do aceite eletrônico nos níveis da Lei nº 14.063/2020 e relação com a MP nº 2.200-2/2001 (Cláusula 3.6).
2. Regra de contagem de cota para envelopes recusados/expirados/cancelados (5.2).
3. Prazos de carência e rebaixamento (5.4), política de reembolso e direito de arrependimento (5.5).
4. Não exclusão individual de envelopes enviados (11.2) e destino do registro público de verificação após exclusão da Organização (11.4).
5. Limitação de responsabilidade e teto (14), especialmente para clientes pessoa física/consumidores.
6. Foro e cláusula de consumidor (18.5).
7. SLA para planos pagos (7.4).
