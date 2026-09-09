# Campos de assinatura: geometria, tipos e completude

> Contrato do incremento 2 para destinatários, campos e prontidão do envelope.
> Fontes: `docs/arquitetura.md` §3.1/§3.2, `docs/design/RECONCILIACAO.md` §4 (Q9, Q10),
> `docs/design/ROUTES_AND_PAGES.md` §2.6/§2.7/§2.9 e a seção "Convencao de coordenadas" de
> `tools/pdftool/README.md`.
> Código: `app/Services/Envelopes/{FieldGeometry,PageBox,FieldSync,RecipientSync,EnvelopeReadiness,DuplicateEnvelope}.php`.

---

## 1. A convenção de coordenadas

Um campo é um retângulo em **frações de [0, 1] da página como ela é EXIBIDA**, com a
**origem no canto superior esquerdo** e `y` crescendo **para baixo** — exatamente como um
`<div>` posicionado sobre o canvas do PDF.js.

"Como exibida" significa: o **CropBox** da página **depois** de aplicar `/Rotate`. Assim, o
mesmo par de números descreve o que o preparador vê no editor, o que o signatário vê na
página pública e onde o `pdftool compose` vai desenhar o campo no PDF final.

```
            página EXIBIDA (CropBox após /Rotate)
   (0,0) ┌───────────────────────────────────────┐
         │                                       │
         │        x ──────────►                  │
         │   y    ┌───────────────┐              │
         │   │    │               │ h            │   x, y  = canto superior esquerdo
         │   ▼    │    campo      │              │   w, h  = largura e altura
         │        └───────────────┘              │   todos em fração de [0,1]
         │               w                       │
         │                                       │
         └───────────────────────────────────────┘ (1,1)

   Invariantes gravadas: 0 ≤ x,  0 ≤ y,  x + w ≤ 1,  y + h ≤ 1,  w > 0,  h > 0
```

O espaço do usuário do PDF é diferente em três aspectos: a origem fica no canto **inferior
esquerdo**, a página **não** está rotacionada e o CropBox **não** começa necessariamente em
`(0, 0)`. A conversão é `FieldGeometry::toPdfRect()`, espelho exato de
`normalized_to_pdf_rect` em `tools/pdftool/pdftool/geometry.py` — as duas implementações são
testadas com os mesmos casos (4 rotações × CropBox deslocado).

### 1.1 Ponto exibido → espaço do usuário

Com o CropBox `(x0, y0, x1, y1)` em pontos e o ponto exibido `(dx, dy)` também em pontos:

| `/Rotate` | dimensão exibida | ponto no espaço do usuário |
| --------- | ---------------- | -------------------------- |
| `0`       | `(x1−x0, y1−y0)` | `(x0 + dx, y1 − dy)`       |
| `90`      | `(y1−y0, x1−x0)` | `(x0 + dy, y0 + dx)`       |
| `180`     | `(x1−x0, y1−y0)` | `(x1 − dx, y0 + dy)`       |
| `270`     | `(y1−y0, x1−x0)` | `(x1 − dy, y1 − dx)`       |

Em `90` e `270` a **largura e a altura exibidas trocam de lugar** — é por isso que
`pdftool inspect` já devolve `width_pt`/`height_pt` **exibidos** por página, e é isso que o
back-end grava em `signing_fields.page_width_pt` / `page_height_pt`.

O retângulo devolvido por `toPdfRect()` é sempre normalizado (`llx ≤ urx`, `lly ≤ ury`),
qualquer que seja a rotação.

### 1.2 Canvas ↔ normalizado

O editor mede o elemento que exibe a página (o PDF.js já aplica a rotação, então as
dimensões em pixels CSS já são as **exibidas**):

```
x = left / canvasWidth          left  = x * canvasWidth
y = top  / canvasHeight   ⇄     top   = y * canvasHeight
w = boxW / canvasWidth          boxW  = w * canvasWidth
h = boxH / canvasHeight         boxH  = h * canvasHeight
```

`FieldGeometry::fromCanvas()` e `FieldGeometry::toCanvas()` implementam esse par (úteis para
testes e para qualquer cálculo do lado do servidor). **O zoom do editor não entra na conta**:
as frações são invariantes à escala, que é justamente a razão de guardá-las normalizadas.

### 1.3 Precisão

As colunas são `DECIMAL(9,6)`, então `FieldGeometry::normalize()` arredonda para 6 casas e
recorta o resultado para preservar `x + w ≤ 1` (o arredondamento sozinho poderia estourar o
limite por 1e-7). As comparações usam `FieldGeometry::EPSILON = 1e-6`.

---

## 2. De onde vem cada dado de página

`signing_fields` guarda, além da geometria, um retrato da página em que o campo foi
posicionado:

| Coluna                            | Origem                                                  | Nunca vem de  |
| --------------------------------- | ------------------------------------------------------- | ------------- |
| `x`, `y`, `width`, `height`       | navegador (normalizado e validado no servidor)          | —             |
| `page`                            | navegador (validado contra `page_count`)                | —             |
| `box_type`                        | fixo `cropbox` (o box exibido)                          | navegador     |
| `page_width_pt`, `page_height_pt` | `document_versions.pages_meta` (dimensões **exibidas**) | **navegador** |
| `page_rotation`                   | `document_versions.pages_meta`                          | **navegador** |
| `document_version_id`             | `documents.current_version_id`                          | navegador     |

`pages_meta` é produzido por `pdftool inspect` no pipeline documental. `PageBox::fromPageMeta()`
lê `cropbox`/`mediabox`/`rotation`, recorta o CropBox pelo MediaBox (ISO 32000-1 14.11.2),
reordena retângulos invertidos e normaliza qualquer `/Rotate` para 0/90/180/270. Sem boxes,
reconstrói a caixa a partir de `width_pt`/`height_pt` desfazendo a troca de 90/270. Se a
página não tem meta nenhuma, o fallback é A4 retrato — **jamais** os números do cliente.

Por que isso importa: se o navegador pudesse declarar o tamanho da página, um cliente
malicioso reposicionaria os campos no PDF final (o `compose` usa esses números) sem que a
interface mostrasse nada de diferente.

---

## 3. Tipos de campo e opções

| Tipo        | Rótulo PT-BR     | Preenchido por                             | `options` aceitas          | Mínimo (pt) |
| ----------- | ---------------- | ------------------------------------------ | -------------------------- | ----------- |
| `signature` | Assinatura       | signatário (imagem)                        | `placeholder`              | 56 × 20     |
| `initials`  | Rubrica          | signatário (imagem)                        | `placeholder`              | 22 × 14     |
| `name`      | Nome completo    | signatário (texto)                         | `font_size`, `placeholder` | 40 × 9      |
| `date`      | Data             | **servidor**, no aceite (RECONCILIACAO Q9) | `date_format`, `font_size` | 40 × 9      |
| `text`      | Texto livre      | signatário (texto)                         | `font_size`, `placeholder` | 18 × 9      |
| `checkbox`  | Caixa de seleção | signatário (booleano)                      | `default`                  | 8 × 8       |

- `font_size`: 6 a 24 pontos.
- `date_format`: `d/m/Y` (padrão), `d/m/Y H:i`, `d \d\e F \d\e Y`.
- `default` (checkbox): booleano; o campo `required` de um checkbox tem padrão **false**, o
  dos demais tipos, **true**.
- `label` (≤ 120 caracteres) é o rótulo mostrado no campo; para checkbox é o texto ao lado.
- Fase 2: `cpf`, `stamp` — fora da paleta.

O mínimo é definido em **pontos** e convertido em fração pela dimensão exibida da página:
o que importa é o campo ser legível e clicável no papel, independentemente do tamanho da
página. Um piso absoluto de `0,001` impede geometrias que não sobrevivem ao `DECIMAL(9,6)`.

---

## 4. Validação no servidor (`PUT /documentos/{envelope}/campos`)

Ordem das checagens em `FieldSync::handle()`; qualquer falha devolve **422** com a chave
`fields.{índice}.{atributo}`, para que o wizard destaque o campo certo:

1. Envelope em `draft | preparing | ready` (senão flash "Ação indisponível no status atual").
2. Documento com versão exibível (`documents.current_version_id`) e `page_count ≥ 1`.
3. Pelo menos um destinatário no envelope.
4. Por campo:
    - `recipient_id` (ou `recipient_client_id`) é o ULID de um destinatário **deste** envelope;
    - `type` pertence ao enum;
    - `page` é inteiro em `1..page_count` — ou a string `"all"`, aceita **somente** para `initials`;
    - `0 ≤ x`, `0 ≤ y`, `x + w ≤ 1`, `y + h ≤ 1`, `w > 0`, `h > 0`;
    - `w` e `h` atendem o mínimo do tipo naquela página;
    - `options` conforme a tabela acima.
5. No máximo 200 campos (`FieldSync::MAX_FIELDS`), já contando as rubricas automáticas.

A sincronização **substitui a lista inteira**: campos cujo `id` (ULID) veio no payload são
atualizados, os demais são criados e os ausentes são apagados. Trocar de documento ou remover
um destinatário elimina os campos correspondentes (`ON DELETE CASCADE` em
`signing_fields.recipient_id`).

Cada sync grava um evento `fields.updated` na trilha e chama o recálculo de prontidão.

---

## 5. Rubrica automática em todas as páginas

Quando `initials_on_all_pages` está ligado (padrão da organização em
`Configurações › Padrões de assinatura`, sobrescrito por envelope), o servidor grava campos
`initials` **reais** — não um campo virtual (RECONCILIACAO §4, Q10):

- um por **página** e por **destinatário**;
- posição padrão `x 0.86`, `y 0.94`, `w 0.10`, `h 0.04` (`FieldGeometry::AUTO_INITIALS`) para o
  **primeiro** destinatário; os demais são deslocados para a esquerda em passos de
  `w + 0.01` e, quando a linha enche, para a linha de cima
  (`FieldGeometry::autoInitialsSlot()`). A constante de Q10 fixa UMA posição; aplicá-la
  igual para todo mundo empilhava as rubricas de todos os signatários no mesmo retângulo;
- **pula** as páginas em que já existe uma rubrica posicionada **à mão** para aquele
  destinatário — a manual prevalece;
- os gerados carregam `options.auto = true`.

No próximo `sync`, os campos com `options.auto = true` (ou marcados `auto` no payload) são
descartados e regerados a partir do estado atual, de modo que o vaivém entre servidor e
editor não multiplica rubricas. Desmarcar a opção apaga todas as automáticas e preserva as
manuais.

O editor do passo 3 **desenha** as rubricas automáticas como caixas somente leitura (camada
própria, sem alças e sem eventos): elas não entram no payload do `sync`, mas quem prepara
precisa ver onde vão cair antes do envio — depois disso o documento está bloqueado para
edição.

---

## 6. Destinatários (`PUT /documentos/{envelope}/destinatarios`)

Substitui a lista inteira, preservando os `id` enviados. Regras:

- 1 a 20 destinatários; nome de 2 a 120 caracteres; e-mail válido; papel livre ≤ 40 caracteres
  (`recipients.role_label`, apenas rótulo — `recipients.role` continua o enum `signer`);
- e-mail **único por envelope**, comparado sem diferenciar maiúsculas
  (`UNIQUE(envelope_id, email)` é a rede de segurança);
- só em `draft | preparing | ready`;
- `signing_order = sequential` → `order_index` recebe a **posição na lista** (1..n);
  `parallel` → todos ficam em `order_index = 1`, porque no paralelo não há vez (a regra
  "notifica quem está em `envelope.current_order`" vale só no sequencial);
- trocar e-mails entre dois destinatários funciona: a transação libera os endereços antes de
  reatribuí-los;
- quem sai da lista é apagado, junto com os campos dele;
- grava `recipients.updated` e recalcula a prontidão.

### 6.1 Editar destinatário pendente depois do envio

`PATCH /documentos/{envelope}/destinatarios/{recipient}` altera **apenas nome e e-mail** de
quem está em `pending | notified | viewed`. Se o e-mail mudar, **todos os
`recipient_access_links` ativos são revogados na hora** — quem tinha o endereço anterior
perde o acesso. A emissão do novo link e o reenvio do convite são do envio, através do ponto
de extensão `App\Services\Envelopes\Contracts\RotatesInvitations`: se houver implementação
registrada no container, ela é chamada e a interface diz "novo convite enviado"; se não
houver, a interface avisa que o link foi revogado e o convite precisa ser reenviado.

---

## 7. Completude e estado do envelope

Um envelope só chega a `ready` quando:

1. existe documento com `processing_status = ready` **e** `current_version_id` definido;
2. existe pelo menos **um** destinatário;
3. **todo** destinatário tem pelo menos um campo `signature` **obrigatório** na versão
   exibível corrente;
4. todos os campos têm geometria válida, apontam para páginas existentes e para
   destinatários do envelope.

Quem **decide e grava** o status é `App\Services\Documents\EnvelopeReadiness::recompute()`
(contrato público do pipeline documental, chamado também ao fim da conversão) — um único
escritor. `App\Services\Envelopes\EnvelopeReadiness` é a camada do wizard: delega a decisão
e acrescenta a lista de **pendências em PT-BR** exibida no passo 4 (prop `issues`).

Transições possíveis no preparo (arquitetura §3.2): `draft ⇄ preparing ⇄ ready`. Envelopes
enviados ou terminais nunca são recalculados.

Mensagens de pendência:

| Situação                         | Texto                                                                              |
| -------------------------------- | ---------------------------------------------------------------------------------- |
| Sem documento                    | "Envie o documento que será assinado."                                             |
| `uploaded` / `converting`        | "O documento ainda está sendo processado."                                         |
| `failed`                         | "Falha ao processar o arquivo. Envie um PDF válido."                               |
| `blocked`                        | "O arquivo está protegido por senha ou já assinado digitalmente. Envie outro PDF." |
| Sem destinatários                | "Adicione pelo menos um signatário."                                               |
| Nenhum signatário com assinatura | "Todo signatário precisa de pelo menos um campo de assinatura."                    |
| Alguns sem assinatura            | "Sem campo de assinatura: Carlos e Ana."                                           |
| Campo em página inexistente      | "Há campos posicionados em páginas que não existem no documento."                  |
| Campo fora dos limites           | "Há campos fora dos limites da página. Reposicione-os no passo 3."                 |

O recálculo roda depois de **toda** alteração de preparo: metadados (`PATCH envelopes.update`),
documento (pipeline documental), destinatários e campos.

---

## 8. Duplicar

`POST /documentos/{envelope}/duplicar` cria um **rascunho independente**:

- copia metadados, o documento **original** (linha, versão `original` e os bytes no disco em
  um novo caminho), os destinatários e os campos;
- **não** copia número, código de verificação, datas de envio/conclusão, aceites, valores de
  campo, links, sessões nem a trilha;
- todo destinatário volta a `pending`, com `notification_count = 0` e sem `signed_at`;
- os campos passam a apontar para a nova versão do documento;
- o estado do novo envelope é recalculado do zero (normalmente `ready`, já que documento,
  destinatários e campos vieram completos);
- grava `envelope.duplicated` no original e `envelope.created` na cópia.

---

## 9. Fronteiras com os outros agentes

| Assunto                                                                                                                             | Dono                        |
| ----------------------------------------------------------------------------------------------------------------------------------- | --------------------------- |
| Upload, conversão, `pages_meta`, `DocumentResource`, `EnvelopeReadiness::recompute()`                                               | pipeline documental (B-DOC) |
| Envio, convites, reenvio (`resend`, `resendAll`, `recipients.resend_pending`, lote `resend`), implementação de `RotatesInvitations` | envio (B-SEND)              |
| Editor de campos no navegador (PDF.js + camada de campos)                                                                           | front                       |
| Destinatários, campos, geometria, completude, wizard, listagem de Assinaturas                                                       | este documento              |
