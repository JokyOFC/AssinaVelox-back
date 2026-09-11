# Fase 2 §2.8 — Marca da organização, Reply-To e carimbo visual (C-BRAND)

> Área C-BRAND da onda B (canais e identidade). Classe **A** na matriz de viabilidade
> (logo, cores, `Reply-To`, `FieldType.stamp`) + parte **B** (remetente próprio, que depende
> da verificação de domínio no provedor de e-mail do proprietário — sem documentação).
> Precedência: `RECONCILIACAO.md` → `arquitetura.md` → `ROUTES_AND_PAGES.md` → roadmap §2.8.

## 1. Resumo

| Item                                                          | Estado                                                                                                                |
| ------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| Logo (PNG/JPEG, GD, sem SVG, sem metadados)                   | pronto, atrás da flag `branding`                                                                                      |
| Cores primária e de destaque com contraste mínimo             | pronto, recusa no servidor                                                                                            |
| Nome de exibição                                              | pronto                                                                                                                |
| Página pública do signatário (`signer-layout.tsx`)            | pronto no layout; **falta a prop** em `SignerPageProps` (§8)                                                          |
| E-mails ao participante (logo, cor, rodapé "via AssinaVelox") | pronto                                                                                                                |
| `Reply-To` da organização                                     | pronto                                                                                                                |
| Remetente próprio (`From`)                                    | contrato pronto; **desligado** até existir verificação de domínio real (§5.3)                                         |
| Cabeçalho da página de evidências                             | pronto no template; **falta a chave** em `EvidenceData` (§8)                                                          |
| Carimbo visual (`FieldType::Stamp`)                           | imagem, retrato congelado, composição e texto de evidência prontos; **falta ligar** editor, aceite e finalização (§8) |
| Teste de vocabulário (T1)                                     | `tests/Feature/Phase2/VocabularyTest.php`, passando                                                                   |

## 2. Flag

`branding` segue a regra das demais flags de organização (roadmap T8): vale só quando o
interruptor global `config('assinavelox.features.branding')` **e** `plans.features.branding`
do plano vigente dizem sim (`App\Services\Branding\BrandingFeature`). Nasce desligada.

**Regra de exibição:** a marca aparece quando a flag está ligada **e** a organização salvou uma
marca (`organization_brandings`). Sem linha, nada muda — nem com a flag ligada. Com a flag
desligada, o que foi salvo continua guardado e nada aparece.

| Com a flag desligada                                                    | Comportamento                           |
| ----------------------------------------------------------------------- | --------------------------------------- |
| `GET /configuracoes/marca`                                              | 200, estado "Fase 2" (`enabled: false`) |
| `PATCH /configuracoes/marca`, `POST`/`DELETE /configuracoes/marca/logo` | 403                                     |
| E-mails, página pública, evidências                                     | idênticos à Fase 1                      |
| `GET /marca/logo.png?v=…`                                               | PNG transparente 1×1                    |

## 3. Dados

Migration `2026_09_11_120201_create_organization_brandings_table` (aditiva, MySQL-compatível):
uma linha por organização (`UNIQUE organization_id`, FK `cascadeOnDelete`) com `display_name`,
`primary_color`, `accent_color` (`#RRGGBB`), `logo_path`, `logo_token` (40 hex, `UNIQUE`),
`logo_width`, `logo_height`, `logo_bytes`, `logo_sha256`, `reply_to_email`, `sender_email`,
`updated_by_user_id`.

- O logo fica no disco **privado** `documents`, em
  `orgs/{org_ulid}/branding/logo-{token}.png` — só identificadores opacos; o nome original
  nunca entra no caminho.
- Toda consulta é por `organization_id` explícito (`BrandingManager::find`), sem depender do
  escopo global: e-mail na fila, página pública e finalização não têm organização corrente.

## 4. Logo

`App\Services\Branding\LogoProcessor`, na ordem, **antes de decodificar**:

1. tamanho ≤ 1024 KB (`assinavelox.branding.logo_max_kb`);
2. MIME real (finfo). **SVG é recusado** — também quando chega com extensão `.png` (o começo do
   arquivo é inspecionado quando o finfo diz `text/*`/`application/xml`). Só PNG e JPEG;
3. dimensões do cabeçalho: lado ≤ 4000 px, ≤ 12 MP (bomba de descompressão), lado ≥ 32 px.

Depois: decodifica, aplica a orientação EXIF do JPEG, reduz para caber em 800×400 e
**reencoda do zero como PNG** com alfa. EXIF, XMP, ICC, comentários e chunks de texto somem. O
arquivo guardado é sempre esse PNG.

A cada envio, um token novo e um arquivo novo; o anterior é apagado **depois** que a nova linha
é gravada (falha na gravação não perde a marca anterior).

### Entrega pública

`GET /marca/logo.png?v={token}` (`branding.logo`, `throttle:300,1`). O logo precisa de URL sem
login para e-mails e página pública. A URL carrega **só** o token da versão do logo: igual para
todos os destinatários, sem nada da pessoa, da mensagem ou do envelope — não é pixel de
rastreamento. Cabeçalhos: `Cache-Control: public, max-age=604800, immutable`,
`X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`,
`Referrer-Policy: no-referrer`.

Token desconhecido, malformado, logo removido ou flag desligada: a mesma resposta — PNG
transparente 1×1, `Cache-Control: no-store`, status 200. A rota não vira oráculo de tokens e
e-mails antigos (de um logo trocado) não mostram imagem quebrada.

## 4.1 Cores

`App\Services\Branding\ColorContrast` (WCAG 2.x, luminância relativa sRGB):

| Cor      | Onde é usada                                             | Mínimo                              |
| -------- | -------------------------------------------------------- | ----------------------------------- |
| primária | fundo do botão dos e-mails, com **texto branco**         | 4,5:1 contra branco (WCAG 1.4.3 AA) |
| destaque | faixa do cabeçalho, moldura do carimbo — **nunca texto** | 3:1 contra branco (WCAG 1.4.11)     |

Formato aceito: `#RRGGBB` ou `#RGB` (normalizado para maiúsculas). Abaixo do mínimo, o
`BrandingUpdateRequest` recusa com a razão calculada ("o texto branco sobre #FFD700 fica com
1,4:1; o mínimo é 4,5:1"), arredondada para baixo — nunca aprova por arredondamento. A tela
mostra a mesma conta (`components/branding/contrast.ts`), mas quem decide é o servidor.

## 5. E-mails ao participante

### 5.1 Tema

`App\Notifications\Concerns\AppliesOrganizationBranding` entra em: convite (todos os papéis),
lembrete, código por e-mail, aviso de prazo, encerramento e cópia final ao participante. O aviso
de conclusão a quem enviou, a recusa ao remetente e os e-mails internos da conta **não** mudam.

Com a marca ativa, a mensagem troca só o template (`resources/views/mail/branded.blade.php`):

- cabeçalho com faixa na cor de destaque, logo, nome de exibição e **"via AssinaVelox"**
  (componente `vendor/mail/html/branded-header`);
- botão na cor primária (`vendor/mail/html/branded-button`);
- rodapé "{nome} · enviado via AssinaVelox" + o © da plataforma.

O **corpo é o mesmo** do `notifications::email`: saudação, linhas, botão, avisos ("não
encaminhe", prazo, código), assinatura e o link alternativo. Nenhuma linha de texto da
notificação é alterada — o teste confere cada aviso nos dois temas. A versão em texto puro
mantém "Revisar e assinar: {url}".

### 5.2 Reply-To

Com a marca ativa e `reply_to_email` salvo, o e-mail sai do remetente da plataforma com
`Reply-To: {nome} <{reply_to_email}>`. Validação: `email:rfc,strict`, até 191 caracteres, sem
CR/LF (injeção de cabeçalho), conferido de novo em `ParticipantMailSender` antes de usar.

### 5.3 Remetente próprio (`From`) — contrato com o C-CAN

`From` com o endereço da organização **só** quando o domínio de `sender_email` estiver
verificado: `App\Services\Branding\Contracts\VerifiedSenderDomains::isVerified($org, $domain)`.
Sem implementação ligada no container vale `NoVerifiedSenderDomains` (nenhum domínio verificado)
e o `From` continua o da plataforma. Inconclusivo (timeout, erro) deve responder `false`.

O C-CAN (`sender_domains`, `App\Models\SenderDomain`) liga a implementação real:
`$this->app->bind(VerifiedSenderDomains::class, …)`, respondendo `true` só para
`status = verified` e `is_simulated = false`.

O que falta para o remetente próprio funcionar é pendência da **operadora**, não do cliente,
e fica só neste documento (aqui e em §11), nunca na tela:

1. documentação da API do serviço de e-mail próprio — registros DNS exigidos (DKIM, SPF,
   Return-Path), consulta de status do domínio, autenticação, erros e limites;
2. verificação do domínio nesse serviço (C-CAN).

Na tela de Marca, enquanto o remetente próprio não estiver ativo, o bloco "Como funciona hoje"
(`sender.pending`) diz em linguagem simples, sem prazo: o envio com o endereço da organização
ainda não está disponível nesta instalação; os e-mails saem do endereço da plataforma, com as
respostas indo para o e-mail de Reply-To; e o endereço desejado já pode ser salvo, passando a ser
usado quando o recurso estiver disponível e o domínio for verificado. Sem jargão (DKIM, API) e
sem link para telas que não existem.

## 6. Página pública do signatário

`resources/js/layouts/signer-layout.tsx` aceita `sender.logo_url` e `sender.brand`
(`SignerBrand`: `display_name`, `logo_url`, `primary_color`, `accent_color`, `on_primary`). Com
`brand`: logo no lugar das iniciais, nome de exibição, faixa superior na cor de destaque e as
variáveis CSS `--brand-primary`, `--brand-accent`, `--brand-on-primary` no contêiner (para as
páginas usarem, se quiserem). **"via AssinaVelox"** continua no cabeçalho e o rodapé
"Documento processado pela AssinaVelox" não muda. Sem `brand`, o cabeçalho é o da Fase 1.

O logo vem do próprio domínio (rota `branding.logo`): nada de terceiros, como promete o aviso
de privacidade ao signatário.

## 7. Página de evidências

`resources/views/evidence/page.blade.php` ganhou, **só no cabeçalho**, um bloco com o logo
(`data:` URI — o DOMPDF roda sem rede), o nome de exibição e o rótulo "Organização remetente",
acima do título. A linha "Documento consolidado por {operadora}" continua: quem gera a página
é a operadora, a organização aparece como remetente, e nada sugere que ela emite certificado.
Sem logo (ou sem a chave `branding`), o cabeçalho é o da Fase 1. O teste compara o HTML com e
sem a marca: tirando o bloco, é idêntico.

Quando o documento tem campo de carimbo (chave `stamp`), a tabela de identificação ganha a linha
"Carimbo visual — Carimbo visual da organização — representação visual, não prova."

## 8. Carimbo visual (`FieldType::Stamp`)

- **Enum:** `FieldType::Stamp = 'stamp'`, rótulo "Carimbo visual". Não é captura do participante
  (`isImageBased()` continua só assinatura/rubrica).
- **Imagem:** `Stamp\StampRenderer` desenha 900×300 px, fundo transparente, moldura na cor de
  destaque, logo à esquerda e o nome na cor primária (DejaVu Sans Bold do dompdf; sem FreeType,
  fonte bitmap do GD). Sem hash, data ou código: não parece selo.
- **Retrato congelado:** `Stamp\StampImages::snapshot($envelope)` grava o PNG uma vez por
  conteúdo em `orgs/{org}/envelopes/{env}/stamp-{sha}.png` e devolve o caminho. Deve ser chamado
  no **aceite** do participante dono do campo, e o caminho vai para
  `signing_field_values.image_path` — trocar o logo no meio do fluxo não muda o que foi aceito.
- **PDF:** `Stamp\StampComposer::add($plan, $field, $local)` usa o caminho de imagem que o
  pdftool já tem (tipo `signature`: encaixa centralizado, sem distorcer, alfa em /SMask). Sem
  mudança no Python nem em `ComposePlan`. O teste compõe um PDF real e mede a caixa da imagem.
- **Evidência:** `Stamp\StampEvidence::forEnvelope()` → "Carimbo visual da organização —
  representação visual, não prova."
- **Front:** `field-types.ts` tem a entrada `stamp` (ícone, tamanho padrão 0,30×0,07, mínimo
  60×20 pt, dica) e `paletteFieldTypes(features)`; `components/branding/stamp-preview.tsx`
  desenha o mesmo carimbo em HTML.

### Pontos de integração (fora da área C-BRAND)

| Arquivo                                                             | O que falta                                                                                                                                                                     |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `config/assinavelox.php`                                            | `'branding' => filter_var(env('ASSINAVELOX_FEATURE_BRANDING', false), FILTER_VALIDATE_BOOLEAN)` no bloco `features`                                                             |
| `HandleInertiaRequests::features()`                                 | `'branding' => BrandingFeature::enabled($organization)`                                                                                                                         |
| `HandleInertiaRequests::currentOrganizationProps()`                 | `'logo_url' => app(BrandingPresenter::class)->logoUrl($organization)`                                                                                                           |
| `Settings\GeneralController::edit()` e `pages/settings/general.tsx` | `logo_url` do presenter; trocar o botão desabilitado por `<LogoUploadField>` (ou link para Configurações › Marca) com a flag                                                    |
| `layouts/settings/layout.tsx`                                       | item "Marca" → `settings.branding`, oculto sem `features.branding`                                                                                                              |
| `SignerPageProps::sender()`                                         | `$brand = app(BrandingPresenter::class)->forSigner($context->organization)`; `'logo_url' => $brand['logo_url'] ?? null, 'brand' => $brand` (e o tipo `SignShowProps['sender']`) |
| `EvidenceData::build()`                                             | `'branding' => app(BrandingPresenter::class)->forEvidence($organization)` e `'stamp' => StampEvidence::forEnvelope($envelope, $sentVersion->getKey())`                          |
| `FieldSync` / `SyncFieldsRequest`                                   | recusar `stamp` sem `BrandingFeature::enabled()` (hoje `FieldType::tryFrom` aceita qualquer caso do enum); sem fonte, sem `required` padrão verdadeiro                          |
| `FieldGeometry::MINIMUM_POINTS`                                     | `'stamp' => [60.0, 20.0]` (PHPStan acusa a falta; em execução, `stamp` sem mínimo gera erro)                                                                                    |
| `RecordAcceptance`                                                  | `resolveFieldValues`: `FieldType::Stamp => ['text' => null, 'bool' => null]`; `image_path`: `FieldType::Stamp => $stamp ??= app(StampImages::class)->snapshot($envelope)`       |
| `ConsolidationPlanner::addField()`                                  | ramo `FieldType::Stamp`: copiar `image_path` para o diretório temporário e `StampComposer::add()`                                                                               |
| `SignerPresentation`                                                | `stamp` como campo somente leitura, com a prévia (`StampPreview` + `sender.brand`)                                                                                              |
| `types/enums.ts`, `lib/labels.ts`                                   | `'stamp'` no `FieldType` e `fieldTypeLabels.stamp = 'Carimbo visual'`                                                                                                           |
| `wizard-step-fields.tsx`, `template-field-editor.tsx`               | paleta via `paletteFieldTypes(features)`                                                                                                                                        |
| `OrganizationPurge`                                                 | chamar `BrandingManager::purge($organization)` (apaga o arquivo do logo; a linha sai pela FK)                                                                                   |
| `AuditEventType` (opcional)                                         | `organization.branding_updated` com payload mínimo (campos alterados, sem valores)                                                                                              |

Os testes `SignerBrandingTest` ("a página pública … recebe a marca") e `StampTest` ("fluxo
completo") estão com `->skip()` até essa integração existir; o corpo do primeiro já é o teste
final.

## 9. Teste de vocabulário (T1)

`tests/Feature/Phase2/VocabularyTest.php` varre `resources/js`, `resources/views`, `lang`,
`app/Notifications`, `docs/juridico` e as props das respostas da verificação pública (índice,
envelope com e sem certificado, recusado, inexistente — JSON decodificado, para que acentos
escapados não escapem da busca). Termos: "assinatura (eletrônica) avançada/qualificada",
"reconhecimento de firma", "cartório", "biometria"/"biométrico", "liveness", "identidade
verificada", "validade jurídica garantida" e "ICP-Brasil" na mesma linha que TSA/carimbo do
tempo.

Exceções — lista explícita e comentada no próprio teste: o trecho **antes** do termo, na mesma
oração, contém uma negação (não, nem, sem, nunca, jamais; never/not/no em comentário de código)
ou cita a regra ("proibido"). Um teste de sanidade garante que o detector pega afirmações e que
a negação de uma frase não protege a frase seguinte. Há ainda um mapa de exceções por arquivo,
vazio hoje.

## 10. Testes

`tests/Feature/Phase2/Branding/`: `LogoUploadTest` (SVG, não imagem, GIF, gigante, pequena,
acima do tamanho, metadados de PNG e JPEG, troca e remoção, flag desligada, papéis),
`ContrastTest`, `BrandingIsolationTest`, `BrandedEmailTest` (flag desligada, ligada sem marca,
ligada com marca, OTP, remetente próprio só com domínio verificado), `SignerBrandingTest`
(props e entrega do logo), `EvidenceHeaderTest` (HTML idêntico fora do cabeçalho, PDF do
DOMPDF, linha do carimbo), `StampTest` (enum, imagem, retrato congelado, plano do pdftool e
posição medida no PDF composto com `Support/image_boxes.py`, que usa o pypdf do venv do pdftool).
Nenhum teste acessa a rede.

## 11. Pendências

1. Integração da tabela do §8.
2. Documentação da API do serviço de e-mail próprio para o remetente próprio (§5.3).
3. Revisão jurídica: o rótulo "Organização remetente" no cabeçalho da evidência e o texto do
   carimbo ("representação visual, não prova").
4. Decisão de produto: o Reply-To aceita qualquer endereço válido, sem confirmar a posse da
   caixa; se for exigido, entra um e-mail de confirmação antes de ativar.
