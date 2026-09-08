> # ⚠️ MINUTA GERADA AUTOMATICAMENTE — REQUER REVISÃO JURÍDICA ANTES DE PUBLICAÇÃO. Não constitui aconselhamento jurídico.
>
> Rascunho v0.1 · 2026-09-08 · Fonte técnica: `docs/arquitetura.md` (§2, §4, §6) e `docs/design/RECONCILIACAO.md`.
> Exibição: página pública `/assinar/{token}` — (1) na etapa "Confirmar identidade", **antes** do botão "Receber código", e (2) na etapa "Assinar", **antes** da caixa de aceite. Sugestão de UI: linha-resumo sempre visível + conteúdo completo em componente recolhível ("Ver aviso completo"). O texto completo abaixo tem **menos de 350 palavras** (contado entre os marcadores).
> Variáveis de runtime: `{{ORGANIZACAO_REMETENTE}}` = `organizations.name` (ou `legal_name`); `{{RAZAO_SOCIAL}}`, `{{CNPJ}}`, `{{EMAIL_DPO}}`, `{{URL_PRIVACIDADE}}` = configuração da Operadora.

# Aviso de privacidade ao signatário

## Linha-resumo (sempre visível, acima do botão "Receber código")

> Este documento foi enviado por **{{ORGANIZACAO_REMETENTE}}**. Para registrar seu aceite, a AssinaVelox gravará data, IP, navegador, a versão exata do documento e o código confirmado por e-mail. [Ver aviso completo]

## Texto completo

<!-- INICIO_AVISO -->

### Como seus dados são usados nesta página

**Quem é responsável pelos seus dados.** Este documento foi enviado por **{{ORGANIZACAO_REMETENTE}}**, que decidiu solicitar a sua assinatura e é a **controladora** dos seus dados pessoais. A **AssinaVelox** ({{RAZAO_SOCIAL}}, CNPJ {{CNPJ}}) é a **operadora**: trata os dados apenas para executar a assinatura, seguindo as instruções da remetente.

**O que registramos e por quê.** Para que o seu aceite tenha valor como evidência, gravamos: seu nome e e-mail (informados pela remetente); o código de confirmação enviado ao seu e-mail (guardado apenas de forma irreversível); a data e a hora do servidor (UTC); seu endereço IP e a identificação do navegador; a versão exata do documento que você viu (resumo SHA-256); os campos exibidos e os valores que você preencher; a imagem da sua assinatura (desenhada, digitada ou enviada); e o texto de aceite que você marcar. Esses dados compõem a página de evidências anexada ao documento final, entregue à remetente e a você.

**A abertura deste link é registrada.** Ao abrir esta página, registramos data, IP e navegador como "abertura detectada". Isso não significa que você leu ou concordou com algo.

**Se você não quiser assinar.** Você pode simplesmente fechar esta página, ou usar "Recusar assinatura" e informar o motivo, que será enviado à remetente. Não solicitar o código não gera nenhum aceite.

**O que não fazemos.** Não pedimos senha, CPF, foto ou localização. Não usamos cookies de rastreamento nem anúncios nesta página; apenas cookies essenciais de sessão e segurança.

**Por quanto tempo.** As evidências são guardadas enquanto o documento existir na conta da remetente ou enquanto houver obrigação legal ou necessidade de comprovar o aceite.

**Seus direitos.** Para acessar, corrigir ou pedir informações sobre seus dados, contate primeiro a remetente. Você também pode escrever à AssinaVelox em {{EMAIL_DPO}}. Política completa: {{URL_PRIVACIDADE}}.
<!-- FIM_AVISO -->

---

## Notas de implementação (não exibir ao signatário)

- Registrar na trilha (`audit_events`) que o aviso foi exibido não é necessário; basta que o texto e sua versão constem no _snapshot_ da tela de aceite (`signature_acceptances.consent_statement` referencia `ACCEPTANCE_TERMS_VERSION`; sugere-se versionar o aviso junto: `PRIVACY_NOTICE_VERSION = 'v1-2026-09-08'`).
- O aviso deve aparecer **antes** do `POST /assinar/{token}/codigo` e novamente (recolhido) na tela de aceite, junto ao checkbox desmarcado por padrão.
- "Recusar assinatura" só está disponível após a autenticação (tela `sign`). Na etapa `identify`, "fechar a página" é a única recusa possível — por isso o texto menciona as duas opções.
- Se a Organização configurar `evidence_show_ip = none`, o IP continua sendo **registrado** (é evidência), apenas não é **exibido** na página de evidências; o aviso permanece verdadeiro.
- **[VALIDAR]** se o aviso deve incluir a informação de que os demais signatários do envelope receberão a página de evidências com os dados de aceite de todos (nome, data, método de autenticação e, conforme configuração, IP).
