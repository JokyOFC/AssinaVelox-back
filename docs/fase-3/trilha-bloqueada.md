# Fase 3 — trilha bloqueada (classe C): API direta do gov.br, e-Notariado e cartão de CRM do HubSpot

> Viabilidade §0 (classe **C**: "só documentar o que falta; não se escreve adaptador, porque ele modelaria um contrato
> inventado" — T4), §1.2, §3.2 ("Trilha bloqueada (sem código)") e §4; roadmap §3.5, §3.8 e §3.9. Onda G, papel G-CONN.
> Identificadores em inglês, texto em português. Data de referência: 2026-09-14.

Este documento registra, para cada item bloqueado: **por que** está bloqueado, **o que já existe** no produto em volta
dele, **o que falta** e **quem desbloqueia**. Nenhum dos três tem código que finja chamar o serviço — §4 diz como isso
foi conferido.

## 1. API direta de assinatura gov.br (roadmap §3.5)

**Situação**: bloqueada. O AssinaVelox **não é elegível** à credencial.

- **Por quê** (`integracoes/gov-br-assinatura.md` §3; viabilidade §1.2 e §6.2): a "API de Assinatura Eletrônica gov.br"
  (OAuth2 no CAS do ITI + `certificadoPublico` + `assinarPKCS7`) é documentada, mas a credencial só é concedida a **órgão
  ou entidade pública**, para sistema que seja **serviço público**, com **Login Único** integrado e `redirect_uri` em
  **domínio oficial de governo** (Portaria SGD/MGI 7.076/2024). Um SaaS privado, comercial e em domínio próprio não
  atende à finalidade nem ao requisito técnico.
- **O que existe hoje**:
    - o contrato documentado `App\Integrations\GovBr\GovBrSignatureProvider` — **só a interface**, com
      `AVAILABILITY = 'blocked_class_c'`, sem implementação, sem fake e sem binding no container. Um teste
      (`tests/Feature/Phase3/GovBr/GovBrReturnRulesTest.php`) garante que nenhuma classe a implementa, que ela não está
      no container e que a pasta `app/Integrations/GovBr` tem só esse arquivo;
    - o fluxo **alternativo** de devolução (classe B, flag `govbr_return`, docs/fase-3/gov-br.md): o participante assina
      no `assinador.iti.br` e devolve o PDF; o servidor aceita só o arquivo que estende a revisão reservada.
- **O que falta / quem desbloqueia** (todos necessários): (1) um **órgão público cliente** que peça a credencial para um
  serviço público dele; (2) implantação sob o **domínio oficial** desse órgão, com o Login Único; (3) **aceite por
  escrito da SGD** de que um produto de terceiro nesse arranjo é admitido (NÃO CONFIRMADO no texto oficial). Mesmo assim,
  seria um projeto por cliente, não uma funcionalidade do SaaS. Quem move: o proprietário, na negociação com o órgão.
- **Rótulo**: se um dia existir, "assinatura gov.br (avançada)", nunca "qualificada" (T1).

## 2. e-Notariado (roadmap §3.8)

**Situação**: bloqueado. Não há integração oficial disponível para o nosso caso (regra fixa 3 da viabilidade).

- **Por quê** (`integracoes/e-notariado-e-regulatorio.md` §1):
    - a API de "Fluxo de Assinaturas" é para **sistemas de gestão de cartório**, com Acordo de Cooperação Técnica — o
      AssinaVelox não é sistema de cartório;
    - a API do **e-Not Assina** (reconhecimento de assinatura eletrônica) exige empresa **cadastrada** e que **todos os
      signatários tenham certificado digital notarizado**; a chave é gerada por organização cadastrada;
    - não se confirmou se uma plataforma **SaaS multi-tenant** pode operá-la em nome dos clientes (o modelo compatível
      parece ser "cada organização traz a própria chave"), nem se há **homologação/sandbox**, nem se aceita PDF que já
      contém a assinatura PAdES da operadora e revisões incrementais;
    - lavrar ato notarial é exclusivo do tabelião (classe c para plataforma privada).
- **O que existe hoje**: o dossiê ZIP (§2.13, flag `dossier_export`) que o cliente pode levar ao tabelião, e o
  vocabulário que impede o produto de se apresentar como substituto de ato notarial (teste de vocabulário, T1).
- **O que falta / quem desbloqueia**: resposta **formal do CNB-CF** sobre o uso do e-Not Assina por SaaS (ou a
  confirmação do modelo "cada organização traz a própria chave"), acesso à homologação, tabela de valores e revisão
  jurídica (pendência 16 da viabilidade §4.2). Quem move: o proprietário, junto ao Colégio Notarial do Brasil. Com isso o
  item passa a classe B (contrato + fake identificado) — antes disso, nenhum adaptador.
- **Correção registrada** (viabilidade §6.3): o requisito é **certificado notarizado** do signatário, não ICP-Brasil;
  quem assina com ICP-Brasil é o tabelião.

## 3. Cartão de CRM do HubSpot (app card / UI extension em app público — roadmap §3.9)

**Situação**: bloqueado. A disponibilidade geral de _UI extensions_ em **apps públicos** está NÃO CONFIRMADA na
documentação do HubSpot (páginas marcadas como beta ou _early access_ — `integracoes/pacotes-fase-2-3.md` §4.3).

- **O que existe hoje** (classe B, flag `hubspot`, docs/fase-3/conectores.md §5): conexão OAuth por organização, a ação
  de workflow "Enviar para assinatura" (assinatura v3, idempotente) e a gravação do estado do envelope numa propriedade
  do negócio/contato. Isso cobre "enviar a partir do CRM" e "ver o estado no CRM" sem o cartão.
- **O que o cartão acrescentaria**: um painel React dentro do registro (`crm.record.tab`/`crm.record.sidebar`) com a
  lista de documentos e um botão de envio — hoje o estado aparece como propriedade, e a lista, na tela
  "Integrações → HubSpot" do AssinaVelox.
- **O que falta / quem desbloqueia**: (1) a documentação do HubSpot confirmar a disponibilidade geral de UI extensions
  para apps públicos (ou o proprietário decidir por um app privado por cliente, que é outro produto); (2) o app de
  desenvolvedor registrado (pendência 11); (3) um _developer project_ com o backend OAuth que já existe. Quem move: o
  HubSpot (disponibilidade) e o proprietário (registro do app).
- **Na tela**: "Integrações → HubSpot" diz que o cartão ainda não está disponível e por quê. Nada finge um cartão.

## 4. Conferência de que nenhum código finge esses serviços

Busca feita em `app/`, `routes/`, `resources/js/`, `config/` e `database/migrations/` em 2026-09-14:

| Busca                                                                 | Resultado                                                                                                                                          |
| --------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `e-notariado`, `enotariado`, `notary`, `e-Not Assina`, `CNB`          | Só uma citação do brief regulatório num comentário de `app/Services/Risk/RiskAppeals.php` (LGPD, art. 20). Nenhum adaptador, rota, tabela ou tela. |
| `crm.record`, `app card`, `UI extension`, `crm card`, "cartão de CRM" | Só o comentário de `app/Services/HubSpot/HubSpotFeature.php` que declara o cartão como classe C, sem código.                                       |
| `GovBrSignatureProvider`                                              | Só a interface (sem implementação/binding) e o teste que garante isso.                                                                             |

Para repetir a conferência:

```bash
grep -rniE "e-?notariado|notary|e-not assina|cnb" app routes resources/js config database/migrations
grep -rniE "crm\.record|app ?card|ui.?extension|crm card|cartão de crm" app routes resources/js config
php artisan test --filter=GovBrReturnRulesTest
```

Se algum dia a busca encontrar um adaptador, uma rota ou uma tela para um destes três itens sem a mudança de classe
registrada na viabilidade (§7) e no roadmap, é regressão.
