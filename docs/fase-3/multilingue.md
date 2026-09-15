# Página pública e e-mails multilíngues (Fase 3 §3.3 — F-I18N)

A página em que o participante assina e os e-mails que ele recebe podem sair em **português (Brasil), inglês ou espanhol**. Quem envia escolhe o idioma de cada participante. O participante ainda pode trocar o idioma de exibição na própria página.

O **texto jurídico** (declaração de aceite, rótulo da caixa, aviso de privacidade) em inglês e espanhol é **tradução de cortesia**. A versão de **referência** continua sendo a em português. É ela que o servidor confere, grava como evidência e leva ao PDF. Isso vale até a revisão profissional de cada idioma.

Tudo fica atrás da flag `multilingual`, **desligada por padrão** (roadmap T8).

## 1. Flag e o que muda

|                         | Flag desligada (padrão)                       | Flag ligada                                                                 |
| ----------------------- | --------------------------------------------- | --------------------------------------------------------------------------- |
| Página pública          | PT-BR, idêntica à de antes; nenhuma prop nova | No idioma do participante; seletor de idioma no cabeçalho; prop `i18n`      |
| E-mails ao participante | PT-BR, idênticos                              | No idioma gravado para o participante                                       |
| Wizard                  | Sem seletor                                   | Seletor "Idioma dos e-mails e da página" por participante                   |
| Aceite                  | `display_locale` nulo                         | `display_locale` = idioma exibido; o texto gravado continua o de referência |
| PDF de evidências       | Sem linha nova                                | Linha "Idioma da página: …" por participante (o PDF continua em PT-BR)      |
| Rotas novas             | 404                                           | Respondem                                                                   |

Liga-se como as demais flags da organização: `ASSINAVELOX_FEATURE_MULTILINGUAL=true` (global) **e** `plans.features.multilingual = true` no plano (`MultilingualFeature::enabled`).

Com a flag desligada, um idioma já gravado num participante continua no banco, mas não vale. Volta a valer se a flag for religada.

## 2. Configuração

`config/assinavelox.php`:

- `features.multilingual`: a flag.
- `multilingual.reference_locale`: `pt_BR`, informativo — o idioma de referência é fixo em `SignerLocale::reference()`.
- `multilingual.reviewed_legal_locales`: idiomas cuja tradução jurídica **já foi revisada**, via `ASSINAVELOX_MULTILINGUAL_REVIEWED`, separados por vírgula (ex.: `en,es`).
    - Enquanto um idioma não estiver na lista, a tela mostra **"Tradução de cortesia"** junto da declaração e da autorização de vídeo.
    - Depois da revisão, mostra "Tradução revisada".
    - Nos dois casos o texto de referência continua a um clique e continua sendo o gravado.

## 3. Modelo de dados (migrations `2026_09_14_160401` e `160402`, aditivas)

| Tabela                  | Coluna                             | Uso                                                                     |
| ----------------------- | ---------------------------------- | ----------------------------------------------------------------------- |
| `recipients`            | `locale` string(8), padrão `pt_BR` | Idioma escolhido por quem envia (e-mails e página)                      |
| `recipients`            | `timezone` string(64), nulo        | Fuso do participante; nulo = fuso da organização                        |
| `signature_acceptances` | `display_locale` string(8), nulo   | Idioma em que a página foi **exibida** no aceite; nulo = flag desligada |

A organização já tinha `organizations.locale`; nesta onda ela não é usada como padrão do participante (novos participantes nascem `pt_BR`).

**Lista fechada.** Os valores aceitos são só `pt_BR`, `en` e `es` (`App\Support\Locale\SignerLocale`). Qualquer valor vindo de fora passa por `SignerLocale::tryFromInput()`. O que não estiver na lista cai no idioma de referência. Nunca é usado para montar caminho de arquivo, nome de dicionário ou chave: os dicionários são lidos pelo `Loader` do Laravel a partir do valor do enum.

## 4. Qual idioma vale (`SignerLocales`)

1. Flag desligada para a organização → PT-BR.
2. O participante trocou o idioma **neste navegador** → a escolha da sessão (`signer_display_locale.{ulid}`).
3. Senão, `recipients.locale`.

**Troca pelo participante.** `POST assinar/{token}/idioma` (`sign.locale.update`) grava o idioma na sessão e gera na trilha `recipient.display_locale_changed` (de/para e o idioma registrado).

- **Não** altera `recipients.locale`: os e-mails continuam no idioma que quem envia escolheu.
- Não exige o código: quem abre o link precisa entender a tela antes de pedir o código.
- Limite: 20 trocas a cada 10 minutos por IP (`throttle:20,10,sign-locale`).

**Fuso.** As datas da página e dos e-mails usam o fuso do participante, se válido, ou o da organização. Os formatos seguem cada idioma:

| Idioma | Formato                                      |
| ------ | -------------------------------------------- |
| PT-BR  | "18/09/2026 às 17:30", exatamente o de antes |
| en     | "September 18, 2026 at 5:30 PM"              |
| es     | "18 de septiembre de 2026 a las 17:30"       |

## 5. Página pública

### 5.1 Camada de tradução do front (`resources/js/i18n`, sem pacote novo)

- `messages/{pt_BR,en,es}/*.ts`: dicionários por assunto (common, layout, sign, otp, consent, fields, receipt, refusal, capture, signature, pdf).
    - O PT-BR é a **referência** e guarda os textos originais, palavra por palavra. Com a flag desligada, a tela é idêntica à anterior.
    - `en` e `es` são tipados com `satisfies` contra o PT-BR. Uma chave faltando ou sobrando quebra o `tsc`.
- `index.tsx`:
    - `useI18n()` → `t(chave, {nome})`, `tp(chave, contagem)` para plural (`.one` quando 1, `.other` nos demais) e `rich()` para frases com negrito ou link.
    - Formatadores de data, número e bytes no idioma e no fuso.
    - `I18nProvider` para forçar um idioma num trecho da árvore.
    - `translate()` para código fora de componente, como o `layout` estático da página.
- O idioma vem da prop `i18n` da página. Sem ela, é PT-BR.
- Texto do remetente ou do participante (título, nomes, rótulos de campo, motivo) **nunca** é modelo: entra só como valor de um `{nome}` e aparece como texto (T6).

### 5.2 Textos que nascem no servidor: traduzidos na fronteira

As mensagens do fluxo público nascem em vários serviços, que não foram tocados:

- código e PIN (`Challenges`, `SenderPins`);
- aceite e recusa (`RecordAcceptance`, `RecordRefusal`);
- captura (`IdentityCaptures`, `CaptureImageNormalizer`, `IdentityVideos`, `VideoContainerInspector`);
- delegação (`DelegationService`);
- FormRequests e flashes dos controllers.

Em vez de alterar cada um, a tradução acontece na saída:

- **`SignerMessageCatalog`** (`lang/{en,es}/signer_messages.php`): o texto PT-BR exato é a chave. Partes variáveis são marcadas com `{nome}`. O valor capturado volta como texto; só `{list}` passa de novo pelo catálogo, porque traz rótulos nossos. Mensagem sem entrada sai em PT-BR, e o teste de navegador procura sentinelas para pegar esse caso.
- **`ApplySignerLocale`** (middleware do grupo `sign.*`, depois de `signer`): fora do PT-BR, traduz pelo catálogo:
    - os avisos da sessão (`success`, `info`…);
    - a sacola de erros (e o `errors` do Inertia);
    - as respostas JSON: `message`, `errors`, `identity_capture` e `identity_video`.
- **`SignerPropsLocalizer`** (chamado por `SignerPageController`): traduz as props escritas em PHP.
    - Rótulo e botão da ação, papel, aviso da cópia, rótulos do comprovante, avisos do canal.
    - Textos das etapas de foto e vídeo.
    - Aviso de privacidade (tradução de cortesia, com o original em `privacy.reference`).
    - Tradução de cortesia da declaração em `consent.translation`.

O **idioma da aplicação não é trocado** na requisição do participante. Na mesma requisição podem nascer coisas que precisam continuar em PT-BR, quando a fila é síncrona: a finalização (PDF de evidências) e os avisos a quem enviou.

### 5.3 Seletor de idioma

`components/sign/language-switcher.tsx`, no cabeçalho do `SignerLayout`, só quando a prop `i18n` traz `switch_url`. Os links para "Aviso de privacidade" e "Termos de uso" ganham "(in Portuguese)"/"(en portugués)": essas páginas só existem em português.

## 6. Texto jurídico, aceite e evidências

- **O que o servidor confere e grava não muda.** `consent.statement`, `consent_text`, `consent.checkbox_label` e as versões (`terms_version`, `privacy.version`) continuam sendo os de referência, gerados pelo `ConsentText`. O snapshot autorizado e o `consent_statement` gravado são os mesmos da página em PT-BR.
- **A tradução vai ao lado.** `consent.translation` (`checkbox_label`, `statement`, `reviewed`) é o que a caixa e o quadro da declaração exibem em `en`/`es`.
    - Um aviso diz "Tradução de cortesia — … a versão de referência, que fica registrada como evidência do seu aceite, é a em português (Brasil)".
    - O texto de referência abre num clique, marcado com `lang="pt-BR"`.
- **Mesma estrutura em todos os idiomas.** `CourtesyLegalText` monta os textos a partir de `lang/{idioma}/signer_legal.php`, com as mesmas variantes do `ConsentText`: papel, um ou vários documentos, certificado da operadora, canal do código, PIN, CPF, fotos e canal simulado. O teste `LegalParityTest` prova que, em PT-BR, o resultado é **idêntico** ao do `ConsentText`.
- **O registro do aceite** guarda o idioma exibido (`signature_acceptances.display_locale`) **e** o texto de referência (`consent_statement`, com o hash do documento e a versão). A autorização do vídeo continua versionada pelo SHA-256 do texto de referência (`VideoStep::consentVersion`).
- **O PDF de evidências continua em PT-BR.** Cada participante ganha a linha "Idioma da página: English — textos jurídicos exibidos em tradução de cortesia; o texto aceito e registrado é o de referência, em português (Brasil)". Ela só aparece quando o aceite registrou `display_locale`, ou seja, com a flag ligada.

## 7. E-mails ao participante

São cinco: convite e reenvio (`RecipientInvitationNotification`, todos os papéis), lembrete automático (`RecipientReminderNotification`), código (`SignerOtpNotification`), cancelamento (`EnvelopeCanceledNotification`) e prazo acabando (`EnvelopeExpiringNotification`).

- Os textos saem de `lang/{idioma}/signer_mail.php`. O trait `LocalizesRecipientMail` define `Notification::$locale` só com a flag ligada e idioma diferente do PT-BR; o Laravel troca o idioma apenas durante o envio daquela mensagem.
- Os textos são lidos com o idioma **explícito**.
- Nome da organização, título e mensagem do remetente continuam escapados para Markdown (`MailText::escape`) no corpo. O link sai intacto.
- O tema com marca (`mail/branded.blade.php`) traduz "enviado via". Os textos do tema padrão do Laravel ("If you're having trouble…") estão em `lang/es.json`; em inglês são o próprio texto do framework.
- Com a flag desligada, `$locale` fica nulo e os textos são os de sempre. A suíte de notificações existente passou sem mudança de asserção.
- **SMS e WhatsApp** (revisão adversarial da onda F): o código (`Challenges`) e o convite/lembrete (`ChannelInvitations`) por canal saem no idioma do participante (`ChannelMessages::otpText|invitationText` com `SignerLocales::forRecipient`), com os textos em `lang/{idioma}/signer_mail.php` → `channel.otp|invitation|reminder` (valores inseridos como texto, sem reinterpretar marcadores). Em PT-BR — e sempre com a flag desligada — o texto é exatamente o de antes. O **WhatsApp** usa template pré-aprovado pela Meta por finalidade (`channels.whatsapp.templates`): o campo `text` já sai traduzido, mas cada idioma exige um template **aprovado naquele idioma** — pendência do proprietário antes de ligar a flag com WhatsApp.

## 8. Wizard e trilha

- `components/envelopes/recipient-locale-control.tsx`: idioma e fuso por participante, no passo de participantes, ligado ao `wizard-step-recipients.tsx` por uma linha. Só antes do envio.
- `GET documentos/{envelope}/idiomas` (`envelopes.recipients.locales`): idiomas disponíveis e o de cada participante.
- `PUT documentos/{envelope}/participantes/{recipient}/idioma` (`envelopes.recipients.locale`): `locale` (lista fechada) e `timezone` (IANA ou nulo). Autorização `update` do envelope; 404 com a flag desligada ou fora da organização; 422 depois do envio.
- Eventos novos em `audit_events`, acrescentados no fim de `AuditEventType`:

| Evento                             | Quando                                    | Payload                                    |
| ---------------------------------- | ----------------------------------------- | ------------------------------------------ |
| `recipient.locale_updated`         | Quem envia define o idioma                | `recipient_ulid`, `from`, `to`, `timezone` |
| `recipient.display_locale_changed` | O participante troca o idioma de exibição | `from`, `to`, `stored`                     |

## 9. Testes

- `tests/Feature/Phase3/I18n/`, 56 testes:
    - `KeyParityTest`: chaves e marcadores iguais em pt_BR, en e es, no PHP, no catálogo, nos JSON e nos dicionários do front.
    - `MessageCatalogTest`: modelos, lista fechada e valores não avaliados.
    - `LegalParityTest`: PT-BR idêntico ao `ConsentText` e traduções sem marcador sobrando.
    - `PublicPageTest`: props traduzidas, referência preservada, avisos e erros traduzidos, troca de idioma com trilha.
    - `FlagOffTest`: nada muda com a flag desligada.
    - `EmailTest`: os cinco e-mails, papéis, escape, fuso e flag desligada.
    - `AcceptanceEvidenceTest`: `display_locale`, texto de referência e linha no PDF.
    - `RecipientLocaleEndpointTest`: flag, lista fechada, fuso, rascunho, isolamento e trilha.
- `tests/Feature/Phase2/VocabularyTest.php` ganhou os equivalentes proibidos em inglês e espanhol, nos mesmos contextos permitidos.
    - Inglês: advanced/qualified (electronic) signature, notarized, biometric, verified identity.
    - Espanhol: firma avanzada/cualificada, notarial, biometría, identidad verificada.
- `tests/Browser/SignerI18nTest.php` percorre, com a página em inglês, a identificação, o código, a assinatura e o comprovante, procurando palavras-sentinela em português. Também testa a troca de idioma pelo seletor.

## 10. O que falta para ligar em produção

1. **Revisão jurídica profissional** de `lang/en/signer_legal.php`, `lang/es/signer_legal.php` e das entradas de consentimento de vídeo em `signer_messages.php` (viabilidade §4.4 item 26). Depois, preencher `ASSINAVELOX_MULTILINGUAL_REVIEWED`.
2. **Termos de uso e aviso de privacidade completos** (`/termos`, `/privacidade`) só existem em português. A página diz isso nos links; traduzi-los é decisão do jurídico.
3. **Cartões da Fase 3, parte 1 na página pública**: certificado A1 do participante, assinatura A3 por componente local e devolução gov.br. Continuam em PT-BR. Só aparecem com as flags deles; traduzi-los é pré-requisito para ligar `multilingual` junto com essas flags.
4. **Dispositivo presencial e lote de assinatura** continuam em PT-BR (reaproveitam os componentes traduzidos, mas o servidor não envia idioma). Falta:
    - escolher o idioma na vez de cada participante no presencial;
    - traduzir `ParticipantSigningProps` e as props do lote.
5. **Validação padrão do framework** nas telas públicas: só as mensagens usadas hoje estão no catálogo, como as da delegação. Mensagem nova de validação aparece em PT-BR até ganhar entrada.
6. **Mensagens novas dos serviços** do fluxo público precisam de entrada em `signer_messages.php` (en e es). O `KeyParityTest` confere a paridade, e o teste de navegador pega sentinelas.
7. **Idioma padrão por organização** (usar `organizations.locale` para novos participantes) e cópia do idioma em "Duplicar", modelos, lote e API: fora desta onda.
8. **Webhooks e API v1** não expõem `recipients.locale` nesta onda.
9. **`<html lang>` no primeiro carregamento**: o `app.blade.php` é fixo em `pt-BR`; o `SignerLayout` põe `en`/`es` ao montar. Para quem lê sem JavaScript, falta levar o idioma ao Blade.

## 11. Ajustes da integração I-3F (docs/fase-3/onda-f-relatorio.md §3)

- **Dicas das caixas de campo**: `SignerPresentation::placeholder` escreve em PHP "Clique para assinar aqui" (e as de rubrica, nome, texto e testemunha). O QA encontrou esse texto em PT-BR na página em inglês. `SignerPropsLocalizer` agora traduz `my_fields[].placeholder` e o rótulo padrão "Testemunha" pelo catálogo (seis entradas novas em `lang/{en,es}/signer_messages.php`). O rótulo escrito pelo remetente continua saindo como veio.
- **Componente `Trans`** (`resources/js/i18n/index.tsx`): `<Trans k="chave">Texto PT-BR</Trans>` mostra o texto de referência em PT-BR e a tradução da chave nos demais idiomas. Usado no botão "Desfazer" do quadro de assinatura, para o teste de revisão de cópia (`SignerCopyAndLabelsTest`) continuar lendo o rótulo PT-BR na fonte.
- **Delegado**: herda `locale` e `timezone` do participante original (`DelegationExecutor`); convite, código e página saem no idioma escolhido para a posição, e o delegado pode trocar o idioma de exibição.
