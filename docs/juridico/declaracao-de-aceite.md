> # ⚠️ MINUTA GERADA AUTOMATICAMENTE — REQUER REVISÃO JURÍDICA ANTES DE PUBLICAÇÃO. Não constitui aconselhamento jurídico.
>
> Rascunho v0.1 · 2026-09-08 · Fonte técnica: `docs/arquitetura.md` (§2, §4–§6) e `docs/design/RECONCILIACAO.md`.
> Este arquivo contém **textos exatos** a serem exibidos e gravados pela Plataforma. Qualquer alteração de redação em um texto versionado exige **nova versão** (ver §1).

# Declaração de aceite eletrônico e textos de evidência

## 1. Versionamento

```php
// Versão do texto de aceite exibido junto ao checkbox e gravado em consent_statement.
// Formato: v{n}-{AAAA-MM-DD}. Mudou uma palavra do texto → nova versão.
const ACCEPTANCE_TERMS_VERSION = 'v1-2026-09-08';
```

Regras:

1. `envelopes.terms_version` recebe `ACCEPTANCE_TERMS_VERSION` **no envio** do envelope e não muda depois. Todos os signatários de um mesmo envelope veem o mesmo texto, mesmo que a constante mude durante a coleta. **[VALIDAR implementação]** manter um mapa `versão → template` para renderizar versões antigas.
2. `signature_acceptances.terms_version` recebe a versão efetivamente exibida; `signature_acceptances.consent_statement` recebe o **texto integral já resolvido** (variáveis substituídas, variante escolhida), exatamente como apareceu na tela.
3. O `fields_snapshot` e o `document_sha256` gravados no aceite são os mesmos referenciados no texto.
4. A escolha entre as variantes (i) e (ii) da §3 é feita **no momento da renderização**, com base na existência de `certificate_references` ativa da Operadora (`organization_id` nulo, `is_active`, dentro da validade). A página de evidências informa o **resultado real** da finalização.

Variáveis de runtime usadas nos textos:

| Variável                                                                                                     | Origem                                                   |
| ------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------- |
| `{{NOME_SIGNATARIO}}`                                                                                        | `recipients.name`                                        |
| `{{EMAIL_MASCARADO}}`                                                                                        | `recipients.email` mascarado (ex.: `m•••@gmail.com`)     |
| `{{TITULO_DOCUMENTO}}`                                                                                       | `envelopes.title`                                        |
| `{{ORGANIZACAO_REMETENTE}}`                                                                                  | `organizations.name` (ou `legal_name`)                   |
| `{{HASH_DOCUMENTO_APRESENTADO}}`                                                                             | `document_versions.sha256` da `sent_document_version_id` |
| `{{RAZAO_SOCIAL}}`                                                                                           | Configuração da Operadora                                |
| `{{CODIGO_VERIFICACAO}}`                                                                                     | `envelopes.verification_code` formatado `XXXX-XXXX-XXXX` |
| `{{URL_VERIFICACAO}}`                                                                                        | Configuração (ex.: `assinavelox.com.br/verificar`)       |
| `{{TITULAR_CERTIFICADO}}`, `{{EMISSOR_CERTIFICADO}}`, `{{VALIDADE_CERTIFICADO}}`, `{{AMBIENTE_CERTIFICADO}}` | `certificate_references`                                 |
| `{{HASH_ORIGINAL}}`, `{{HASH_ENVIADO}}`, `{{HASH_CONSOLIDADO}}`, `{{HASH_FINAL}}`                            | `verification_records`                                   |

---

## 2. Rótulo do checkbox (exibido ao lado da caixa, **desmarcada por padrão**)

Texto exato (uma única frase, sem truncar):

> Li o documento **{{TITULO_DOCUMENTO}}** e declaro que concordo com seu conteúdo e que os dados aqui registrados — data e hora, endereço IP, navegador, código confirmado por e-mail, a versão exata do documento e os campos que preenchi — constituem evidência do meu aceite eletrônico.

Regras de UI:

- O botão "Assinar documento" permanece desabilitado até a caixa ser marcada.
- A caixa **nunca** vem pré-marcada, nem é marcada automaticamente ao rolar o documento.
- Abaixo do rótulo, exibir a declaração completa da §3 em texto corrido (pode estar dentro de um bloco com rolagem, mas **não** oculto por padrão).

---

## 3. Declaração completa (exibida abaixo do checkbox e gravada em `consent_statement`)

### 3.1 Variante (i) — com certificado da Operadora ativo no momento da renderização

> **Declaração de aceite eletrônico — versão v1-2026-09-08**
>
> Eu, **{{NOME_SIGNATARIO}}**, identificado(a) nesta solicitação pelo e-mail **{{EMAIL_MASCARADO}}**, declaro que:
>
> 1. Li integralmente o documento **"{{TITULO_DOCUMENTO}}"**, enviado por **{{ORGANIZACAO_REMETENTE}}**, cujo conteúdo apresentado nesta tela corresponde ao resumo SHA-256 `{{HASH_DOCUMENTO_APRESENTADO}}`, e **concordo com o seu conteúdo**.
> 2. Os campos que preenchi nesta tela e a imagem de assinatura que forneci (desenhada, digitada ou enviada) são de minha autoria. Reconheço que essa imagem é uma **representação visual** e que a minha manifestação de vontade é este aceite.
> 3. Estou ciente de que a AssinaVelox registrará, como evidência deste aceite: a data e a hora do servidor (UTC), o meu endereço IP, a identificação do meu navegador, o método de autenticação (código de uso único confirmado no e-mail acima), a versão exata do documento, os campos apresentados e os valores preenchidos, e esta declaração.
> 4. Estou ciente de que, ao final da coleta de todos os aceites, a AssinaVelox ({{RAZAO_SOCIAL}}) consolidará o documento com uma página de evidências e **aplicará ao arquivo final uma assinatura criptográfica com certificado digital de sua própria titularidade**. Essa assinatura **identifica a AssinaVelox como operadora da plataforma e permite detectar alterações posteriores no arquivo; ela não é a minha assinatura pessoal nem um certificado digital emitido em meu nome.**
> 5. Estou ciente de que a validade e os efeitos deste aceite dependem da legislação aplicável e do acordo entre as partes, e que a AssinaVelox não garante sua aceitação por terceiros.
> 6. Li o aviso de privacidade exibido nesta página e sei que posso recusar a assinatura informando um motivo.

### 3.2 Variante (ii) — sem certificado da Operadora ativo no momento da renderização

Itens 1, 2, 3, 5 e 6 idênticos à variante (i). Item 4 substituído por:

> 4. Estou ciente de que, ao final da coleta de todos os aceites, a AssinaVelox ({{RAZAO_SOCIAL}}) consolidará o documento com uma página de evidências e o concluirá como **aceite eletrônico com evidências, sem assinatura criptográfica**. A integridade do arquivo final poderá ser conferida pelo seu resumo SHA-256, publicado na página de verificação **{{URL_VERIFICACAO}}** com o código **{{CODIGO_VERIFICACAO}}**.

### 3.3 O que este texto **não** afirma (guia para revisão)

- Não afirma que o aceite é "assinatura eletrônica avançada" ou "qualificada" (Lei nº 14.063/2020), nem que equivale a assinatura ICP-Brasil (MP nº 2.200-2/2001). Essas referências ficam para avaliação jurídica; o texto descreve apenas fatos registrados.
- Não usa a expressão "assinado digitalmente por {{NOME_SIGNATARIO}}".
- Não trata a marcação da caixa como consentimento LGPD para tratamento de dados; trata como manifestação de vontade sobre o documento, com ciência das evidências (ver `politica-de-privacidade.md`, §3).

---

## 4. Confirmação pós-aceite (tela `completed` / `already_signed_pending_others`)

> **Aceite registrado.** Sua manifestação foi gravada em {{DATA_HORA_LOCAL}} ({{DATA_HORA_UTC}} UTC), autenticada por código enviado ao e-mail {{EMAIL_MASCARADO}}. Código de verificação: **{{CODIGO_VERIFICACAO}}**. Você receberá o arquivo final quando todos os participantes concluírem.

---

## 5. Rodapé da página de evidências

A página de evidências é gerada em HTML → PDF e anexada ao final do documento consolidado. O bloco abaixo é impresso **ao final da página de evidências**, após a lista de participantes e a linha do tempo.

### 5.1 Bloco "Como ler os resumos criptográficos (SHA-256)"

> **Como ler os resumos criptográficos (SHA-256)**
>
> Um resumo SHA-256 (_hash_) é uma sequência de 64 caracteres que identifica um arquivo byte a byte: qualquer alteração no arquivo, por menor que seja, produz um resumo completamente diferente. **Um resumo não é uma assinatura**: ele permite conferir se dois arquivos são idênticos, e nada mais. Esta página registra quatro resumos, cada um calculado sobre bytes distintos:
>
> | Resumo                                 | Do que foi calculado                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
> | -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
> | **Original** `{{HASH_ORIGINAL}}`       | Dos bytes do arquivo exatamente como foi enviado pela organização remetente à plataforma (PDF, DOCX ou imagem), antes de qualquer conversão.                                                                                                                                                                                                                                                                                                                                                    |
> | **Enviado** `{{HASH_ENVIADO}}`         | Dos bytes da versão em PDF que foi congelada no envio e **apresentada a todos os signatários**. É este resumo que cada declaração de aceite referencia. Se o original já era um PDF sem conversão, pode coincidir com o resumo original.                                                                                                                                                                                                                                                        |
> | **Consolidado** `{{HASH_CONSOLIDADO}}` | Dos bytes do PDF gerado após a coleta, com os campos preenchidos e as representações visuais de assinatura incorporados às páginas ("achatados"), **antes** do acréscimo desta página de evidências.                                                                                                                                                                                                                                                                                            |
> | **Final** `{{HASH_FINAL}}`             | Dos bytes do arquivo final completo (documento consolidado + esta página de evidências + assinatura criptográfica da operadora, quando aplicada). **Este resumo é calculado depois de o arquivo estar pronto e, por isso, não pode constar dentro do próprio arquivo.** Ele é publicado exclusivamente na página de verificação {{URL_VERIFICACAO}}, sob o código {{CODIGO_VERIFICACAO}}. Para conferir o arquivo que você tem em mãos, calcule o SHA-256 dele e compare com o valor publicado. |

### 5.2 Bloco "Sobre a assinatura criptográfica deste arquivo" — variante `signature_status = company_a1`

> **Sobre a assinatura criptográfica deste arquivo**
>
> Este arquivo recebeu uma assinatura digital no perfil {{PERFIL_ASSINATURA}} (PAdES), aplicada pela **AssinaVelox ({{RAZAO_SOCIAL}})** com certificado digital de **sua própria titularidade** (titular: {{TITULAR_CERTIFICADO}}; emissor: {{EMISSOR_CERTIFICADO}}; validade: {{VALIDADE_CERTIFICADO}}). Essa assinatura tem duas funções: identificar a AssinaVelox como a operadora que consolidou e lacrou este arquivo, e permitir que leitores de PDF detectem qualquer alteração feita depois do lacre.
>
> **Ela não é a assinatura pessoal de nenhum dos participantes e não é um certificado digital emitido em nome deles.** A manifestação de vontade de cada participante é o **aceite eletrônico** descrito acima, sustentado pelas evidências desta página (data, IP, navegador, autenticação por código enviado ao e-mail, versão do documento e campos preenchidos).
>
> **Resultado técnico da validação:** ele é apurado sobre o arquivo já pronto e assinado — isto é, depois de esta página existir — e por isso, tal como o resumo **Final**, não pode constar de dentro do próprio arquivo. Ele é publicado na página de verificação {{URL_VERIFICACAO}}, sob o código {{CODIGO_VERIFICACAO}}, com o que foi e o que não foi verificado (integridade, cadeia de certificação e revogação). A verificação da cadeia de certificação por terceiros depende das ferramentas e das políticas de confiança que eles utilizarem.

> **Decisão registrada (revisão adversarial dos incrementos 4 e 5).** A redação anterior era «O resultado técnico da validação da assinatura no momento da conclusão foi: {{RESULTADO_VALIDACAO}}.» — uma frase impossível de cumprir. A página de evidências é **anexada ao documento consolidado antes** da assinatura, e a validação é feita **sobre o arquivo assinado**; imprimir aqui o resultado exigiria gerar esta página depois da assinatura e anexá-la em seguida, o que deixaria conteúdo fora da revisão assinada e destruiria a própria cobertura que a validação afirma (`coverage = ENTIRE_REVISION` em vez de `ENTIRE_FILE`). É a mesma impossibilidade estrutural já reconhecida em §5.1 para `{{HASH_FINAL}}`, e a solução é a mesma: o dado vive na página de verificação, e o arquivo diz onde encontrá-lo. Enquanto a frase antiga esteve no documento, o `@if` correspondente do template era código morto e **nenhum** PDF final trouxe o parágrafo.

Se `{{AMBIENTE_CERTIFICADO}} = test`, acrescentar em destaque:

> ⚠️ **Certificado de ambiente de teste.** A assinatura deste arquivo foi aplicada com um certificado de teste, sem valor para uso em produção. Este arquivo não deve ser utilizado para fins reais.

### 5.3 Bloco "Sobre a assinatura criptográfica deste arquivo" — variante `signature_status = none`

> **Sobre a assinatura criptográfica deste arquivo**
>
> **Este arquivo não possui assinatura criptográfica.** O envelope foi concluído como **aceite eletrônico com evidências**: a manifestação de vontade de cada participante está registrada nesta página (data, IP, navegador, autenticação por código enviado ao e-mail, versão do documento e campos preenchidos), e a integridade do arquivo pode ser conferida comparando o seu resumo SHA-256 com o resumo **Final** publicado em {{URL_VERIFICACAO}} sob o código {{CODIGO_VERIFICACAO}}. Nenhum certificado digital foi utilizado, e nenhuma indicação de "assinatura digital" deve ser esperada em leitores de PDF.

### 5.4 Linha de encerramento (ambas as variantes)

> Esta página de evidências foi gerada automaticamente pela AssinaVelox em {{DATA_HORA_GERACAO_UTC}} (UTC). Ela **não é um certificado digital** e não substitui a análise das partes ou de seus assessores sobre a validade do ato documentado. Referências normativas a serem avaliadas caso a caso: Lei nº 14.063/2020 e MP nº 2.200-2/2001. Verifique este documento em **{{URL_VERIFICACAO}}** · código **{{CODIGO_VERIFICACAO}}**.

---

## 6. Rodapé impresso em cada página do PDF final

Texto exato (uma linha, fonte pequena, margem inferior, todas as páginas do documento consolidado e da página de evidências):

```
Verifique em {{URL_VERIFICACAO}} · código {{CODIGO_VERIFICACAO}}
```

Exemplo renderizado:

```
Verifique em assinavelox.com.br/verificar · código K7QM-3XRT-9BZW
```

Variante opcional com segunda linha **[VALIDAR com design]**, quando `signature_status = none`:

```
Verifique em {{URL_VERIFICACAO}} · código {{CODIGO_VERIFICACAO}}
Aceite eletrônico com evidências · sem assinatura criptográfica
```

Notas de implementação:

- O rodapé é carimbado na etapa de composição (`pdftool compose` / `append`), **antes** de `pdftool sign`, para que fique coberto pela assinatura criptográfica quando ela existir.
- **Nunca** imprimir o hash final no PDF (é calculado depois do arquivo pronto). Imprimir apenas URL e código.
- O código é `envelopes.verification_code` (12 caracteres base32 sem `0/1/O/I`), exibido em grupos de 4 separados por hífen.
- Não incluir nomes, e-mails ou IPs no rodapé.

---

## 7. Rótulos curtos para a página pública de verificação (`/verificar/{code}`)

| `signature_status`       | Selo                                                    | Texto de apoio                                                                                                                                                                   |
| ------------------------ | ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `company_a1` (concluído) | "Concluído · com assinatura criptográfica da operadora" | "O arquivo final foi lacrado pela AssinaVelox com certificado de sua titularidade (perfil {{PERFIL_ASSINATURA}}). Essa assinatura não é a assinatura pessoal dos participantes." |
| `none` (concluído)       | "Concluído · aceite eletrônico com evidências"          | "Este envelope não possui assinatura criptográfica. Confira a integridade do arquivo pelo resumo SHA-256 final abaixo."                                                          |
| qualquer (em andamento)  | "Em andamento"                                          | "Ainda há participantes pendentes. Os resumos do arquivo final serão publicados na conclusão."                                                                                   |

**[VALIDAR com design]** O selo verde do mock ("Documento concluído e assinado digitalmente") **não deve** ser usado quando `signature_status = none`; usar o rótulo da segunda linha.

---

## Anexo — Pontos a validar pela assessoria jurídica

1. Redação do rótulo do checkbox (§2) e da declaração (§3), em especial os itens 4 e 5.
2. Caracterização da marcação como manifestação de vontade e não como consentimento LGPD (§3.3).
3. Se a variante (i)/(ii) deve ser escolhida na renderização (estado atual do certificado) ou se deve haver texto único neutro ("poderá aplicar") — a proposta atual é escolher na renderização e informar o resultado real na página de evidências.
4. Menções à Lei nº 14.063/2020 e à MP nº 2.200-2/2001 na linha de encerramento (§5.4): manter como referência ou remover.
5. Texto do aviso de certificado de teste (§5.2).
