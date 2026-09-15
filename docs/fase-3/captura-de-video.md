# Aceite complementado por vídeo curto (F-VIDEO)

Roadmap §3.3; viabilidade §1.2, §3.2 (onda F), §4.4 item 20 e R9; pacotes §5 e §7; captura simples
de fotos em `docs/fase-2/identidade.md` §5 (a base deste item). Data: 14/09/2026. Identificadores em
inglês; textos em PT-BR.

**Nasce desligado.** Com a flag `identity_video` em `false` (o padrão), nada muda: nenhuma rota nova
responde (404), a página do participante recebe exatamente as mesmas props (a chave `identity_video`
nem aparece), o aceite grava o mesmo snapshot, o PDF de evidências é o mesmo e a câmera continua
negada pela Permissions-Policy.

| Flag (`assinavelox.features.*` E `plans.features.*`) | Liga                                            | Classe                            |
| ---------------------------------------------------- | ----------------------------------------------- | --------------------------------- |
| `identity_video`                                     | exigir, gravar, enviar e reproduzir vídeo curto | A, desligada até decisão jurídica |

Resolvedor: `IdentityFeatures::identityVideo($organization)` — interruptor global **e** plano da
organização (sem organização: desligada). Fica fora de `IdentityFeatures::FLAGS` e de
`forOrganization()` de propósito: o contrato das quatro chaves da Fase 2 não muda. A prop
compartilhada `features.identity_video` vem de uma linha própria em `HandleInertiaRequests`.

## 1. O que o vídeo é — e o que não é (T1, LGPD)

- É **captura**: um arquivo que alguém, com a sessão do participante, enviou. Não é prova biométrica,
  não é verificação de identidade e nunca recebe esses rótulos. A plataforma não compara rostos, não
  analisa o vídeo e não confere quem aparece nele.
- **Sem som.** A gravação pede só a câmera (`audio: false`); a Permissions-Policy continua com
  `microphone=()` em toda resposta.
- **Explicação antes da câmera.** O diálogo mostra para que serve (`VideoStep::PURPOSE`), quem vê
  (`VideoStep::AUDIENCE`: quem enviou o documento e quem tem acesso a ele na conta; nunca no PDF
  assinado nem na verificação pública), por quanto tempo fica guardado e o aviso de que não é
  verificação (`VideoStep::NOTICE`).
- **Consentimento explícito antes de ligar a câmera.** Os botões "Ligar a câmera" e "Enviar vídeo do
  aparelho" só habilitam com a caixa marcada (`VideoStep::CONSENT`). O servidor recusa o envio sem
  `consent` aceito (422, `errors.consent`), grava `consented_at` e `consent_version` (SHA-256 do texto
  exibido) na linha, no snapshot do aceite e na trilha.
- **Alternativa clara** (`VideoStep::FALLBACK`): sem `MediaRecorder`/`getUserMedia` ou com a câmera
  negada, a pessoa pode enviar um vídeo gravado pela câmera do aparelho (`<input type="file"
capture="user">`, origem declarada `upload`) ou abrir o link em outro aparelho; se preferir não
  enviar, fala com quem enviou ou recusa o documento. O aceite não é liberado sem o vídeo exigido — a
  exigência é do remetente.
- **Dado não confiável (T6).** O arquivo, o nome, o `Content-Type`, a origem (`camera`/`upload`) e a
  duração informados pelo navegador são dados declarados: nada é executado, decodificado ou
  transcodificado. A origem e a duração declaradas ficam gravadas com esse rótulo ("informada pelo
  navegador, não verificada").

## 2. Exigência (remetente)

- Por participante, só no rascunho, só para quem registra aceite (visualizador não), em tabela própria
  `identity_video_requirements` (`recipient_id` único, `max_seconds` nulo = padrão). A exigência de
  fotos da Fase 2 (`identity_capture_requirements`) não é lida nem alterada: `video` não é tipo de foto
  (a lista de fotos é `CaptureKind::photoCases()`; `PUT …/captura` com `video` continua 422).
- `PUT documentos/{envelope}/participantes/{recipient}/video` (`envelopes.recipients.identity_video`),
  corpo `{ required: bool, max_seconds?: 3…teto }`. Resposta `{ recipient_id, required, max_seconds }`.
  422 fora do rascunho, para visualizador ou fora dos limites; 404 com a flag desligada ou de outra
  organização (binding escopado). Trilha `identity_video.requirement_updated`.
- Duração máxima: padrão `capture_video.max_seconds` (10 s), escolha do remetente até
  `capture_video.max_seconds_ceiling` (30 s).
- **Delegação (integração I-3F):** a exigência passa ao delegado com a mesma duração máxima
  (`DelegationExecutor::copyVideoRequirement`) — o vídeo gravado é do delegado, com consentimento
  próprio. Participante com vídeo exigido conta como autenticação reforçada
  (`DelegationPolicy::hasStrongerAuthentication`): delegar sempre exige a confirmação de quem enviou.
- Interface: `VideoRequirementControl` (`resources/js/components/identity/video-requirement-control.tsx`)
  no cartão de cada participante do passo 2 do wizard; lê o estado de
  `GET envelopes.identity_videos.index` (uma consulta por envelope) e grava por `fetch`.

## 3. Validação no servidor (sem executar nada)

`VideoContainerInspector::inspect()` lê só bytes, com limite de 4 096 elementos por arquivo e sobre um
conteúdo já limitado pelo tamanho máximo:

| Contêiner       | Assinatura                                                                                       | Duração (quando legível)                                                              | Trilha de vídeo exigida          |
| --------------- | ------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------- | -------------------------------- |
| WebM / Matroska | cabeçalho EBML `1A 45 DF A3` com `DocType` `webm` ou `matroska`, seguido de `Segment`            | `Info/Duration × TimecodeScale` (o WebM do MediaRecorder costuma não trazer → `null`) | `TrackEntry` com `TrackType = 1` |
| MP4 (ISO BMFF)  | primeira caixa `ftyp` com marca ISO de vídeo (`isom`, `iso2…9`, `mp41`, `mp42`, `avc1`, `dash`…) | `moov/mvhd` (timescale/duration) ou `mvex/mehd` no fragmentado                        | `trak/mdia/hdlr` = `vide`        |

Recusados com a mesma mensagem genérica (`invalid_video`, 422, sem ecoar conteúdo): extensão `.webm`
com bytes de PNG, HTML disfarçado, EBML truncado, `DocType` desconhecido, áudio puro, HEIC/AVIF (usam
`ftyp`, mas com marcas de imagem). Também:

- **tamanho**: validação `max:` do Laravel (`capture_video.max_upload_kb`, 8 MB) e nova conferência no
  serviço (`video_too_large`);
- **duração**: a lida do arquivo, a **estimada pelos blocos** e a informada pelo navegador, cada uma
  quando existe, não podem passar de `max_seconds` + `duration_tolerance_ms` (1,5 s) → `video_too_long`.
  A estimativa (revisão adversarial da onda F) vale para o WebM sem `Duration`: varredura linear dos
  `Cluster/Timecode` e do tempo relativo de cada `SimpleBlock`/`Block` × `TimecodeScale`, sem decodificar
  nada e com teto de elementos; serve só para o limite, nunca é gravada como duração do arquivo. Sem
  nenhuma das três, vale um **teto de bytes proporcional**: o dobro de (duração pedida ×
  `video_bits_per_second`), mais 256 KB de folga (≈ 2,8 MB para 10 s), nunca acima do limite do
  envio — antes, omitir `duration_ms` permitia guardar até 8 MB (~60 s) quando o remetente pediu 10 s;
- **limite de envios**: `capture_video.max_uploads_per_hour` (10) por participante → 429.

O `Content-Type` guardado e servido é **fixo pelo contêiner** (`video/webm`, `video/x-matroska`,
`video/mp4`), nunca o declarado.

## 4. Envio (página pública)

`POST assinar/{token}/captura-video` (`sign.capture.video.store`, `signer.verified`), multipart:
`video`, `consent` (obrigatório), `source` (`camera` | `upload`, opcional, declarada) e `duration_ms`
(opcional, medida pelo navegador). 404 sem distinguir motivo com a flag desligada, papel sem aceite ou
vídeo não exigido.

- Guardado **como veio** (sem transcodificar), **cifrado** com a chave da aplicação, no disco privado
  `documents`: `orgs/{org}/envelopes/{env}/identity/video-{ulid}.bin`. A linha em `identity_captures`
  (`kind = video`) guarda SHA-256 dos bytes, contêiner, MIME fixo, dimensões declaradas no contêiner
  (0 quando ausentes), tamanho, duração lida e declarada, origem declarada, consentimento e momento.
- Refazer antes do aceite substitui o anterior (arquivo antigo apagado depois do commit).
- JSON (`Accept: application/json`): `201 { capture: {id, kind, captured_at, container, duration_ms,
size_bytes}, identity_video: <bloco atualizado> }`; erro `422/429 { message, errors: { video | consent
}, code }`. Sem JSON: redirect para `sign.show`.
- O aceite (`RecordAcceptance`, sem mudança) chama `IdentityCaptures::assertComplete()` e
  `snapshotFor()`, que agora consideram foto **ou** vídeo: sem o vídeo exigido,
  `errors.signature = "Antes de concluir, envie: Vídeo curto."`; com ele, o `fields_snapshot` ganha em
  `identity_captures` um item `{capture_ulid, kind: 'video', sha256, width, height, captured_at, source,
container, mime_type, size_bytes, duration_ms, declared_duration_ms, consent_version}` (depois das
  fotos, quando também exigidas). Nunca caminho nem bytes.

**Prop `identity_video`** (`VideoStep::props()`, em `SignerPageProps` só quando não é `null`):

```ts
identity_video?: {
  required: true; complete: boolean; title: string; label: string; instructions: string
  purpose: string; audience: string; notice: string
  consent_label: string; consent_version: string; fallback: string
  facing_mode: 'user' | 'environment'; audio: false
  max_seconds: number; max_upload_kb: number; video_bits_per_second: number
  accept: ['video/webm', 'video/mp4']; retention_days: number
  captured: boolean; captured_at: string | null; duration_ms: number | null; size_bytes: number | null
  upload_url: string | null   // null antes do código
}
```

Tipos em `resources/js/components/identity/video-types.ts`. Componente: `VideoCaptureStepCard` +
`useVideoStep()` (`video-capture-step.tsx`), ligado em `pages/sign/show.tsx` (cartão depois das fotos,
`canSubmit` exige `videoCapture.ready`, aviso "Antes de assinar, envie o vídeo curto.").

Gravação: `MediaRecorder` nativo com o primeiro tipo suportado entre `video/webm;codecs=vp9`, `vp8`,
`video/webm`, `video/mp4;codecs=avc1`, `video/mp4`; `videoBitsPerSecond` do servidor; para sozinho no
limite; a câmera nunca fica ligada fora do diálogo.

## 5. Remetente: reprodução, download e evidência

- `GET documentos/{envelope}/videos` (`envelopes.identity_videos.index`, JSON, `view` no envelope — o
  mesmo público da página de evidências, onde ficam as fotos):
  `{ notice, items: IdentityVideoItem[], requirements: {[recipientUlid]: {max_seconds}}, limits:
{default_seconds, ceiling_seconds, max_upload_kb} }`. Cada vídeo vinculado a aceite traz
  `play_url`/`download_url` **assinadas** (`URL::temporarySignedRoute`), válidas por
  `capture_video.playback_url_ttl_seconds` (120 s), presas ao usuário que pediu (`u`) e com um `n`
  aleatório por URL.
- `GET documentos/{envelope}/videos/{video}/arquivo` (`envelopes.identity_videos.file`): flag → 404;
  assinatura inválida, vencida ou adulterada → 403; URL de outro usuário → 403; outra organização →
  404 (binding); vídeo apagado → 404. Resposta com `Content-Type` fixo de vídeo, `Content-Disposition`
  (`inline` para reproduzir, `attachment` para baixar, nome `video-{ulid}.{webm|mkv|mp4}`),
  `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store` e
  `Cross-Origin-Resource-Policy: same-origin`. Cada URL servida grava **um**
  `identity_video.accessed` (`mode = play | download`), mesmo que o player peça o arquivo de novo.
- Interface: `IdentityVideoPanel` (`identity-video-panel.tsx`) no detalhe do envelope (sem a flag ou
  sem vídeo não renderiza nada). Cada clique em "Reproduzir"/"Baixar" busca URLs novas.
- **PDF de evidências**: `VideoEvidence::pdfLines()` → `EvidenceData` acrescenta
  `identity_video_label` ao participante (só quando há vídeo) e o Blade imprime uma linha:
  "Vídeo enviado pelo participante — origem informada pelo navegador: câmera (WebM, 6,0 s) — SHA-256
  … O vídeo fica guardado à parte e não faz parte deste PDF." O vídeo **nunca** é embutido.
- A lista de **fotos** da página de evidências (`CaptureEvidence`) não inclui vídeos (sem miniatura
  "indisponível"). A **verificação pública** não fala de vídeo nem expõe o resumo.

## 6. Trilha (T7 — só acréscimos no fim de `AuditEventType`)

| Evento                               | Quando                          | Payload                                                                                                                                     |
| ------------------------------------ | ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `identity_video.requirement_updated` | remetente exige/deixa de exigir | `recipient_ulid`, `required`, `max_seconds`                                                                                                 |
| `identity_video.recorded`            | participante envia (ator: ele)  | `capture_ulid`, `kind`, `sha256`, `container`, `size_bytes`, `duration_ms`, `declared_duration_ms`, `source`, `consent_version`, `replaced` |
| `identity_video.accessed`            | cada URL de reprodução/download | `capture_ulid`, `recipient_ulid`, `mode`, `sha256`                                                                                          |

Nunca o vídeo nem o caminho no disco. O expurgo reaproveita `identity_capture.purged` (contagem e
motivo), como as fotos.

## 7. Retenção e expurgo

Iguais aos da foto, porque o vídeo é uma linha de `identity_captures`: `CapturePurge::run()` apaga o
**arquivo** `capture.retention_days` (180) depois da captura e mantém a linha com `purged_at` e o
SHA-256 (o aceite continua citando o resumo); vídeo sem aceite sai inteiro depois de
`capture.orphan_retention_hours` (48). Preservação legal, política de retenção por categoria
(`identity_capture`), exclusão de envelope (`EnvelopePurger`) e de organização valem igualmente.

## 8. Banco, rotas e configuração

- Migrations (aditivas, MySQL 8): `2026_09_14_160301_create_identity_video_requirements_table`,
  `2026_09_14_160302_add_video_columns_to_identity_captures_table` (`container`, `duration_ms`,
  `declared_duration_ms`, `consented_at`, `consent_version`, todas nuláveis). `identity_captures.kind`
  já era texto: `video` não exige migração.
- Rotas: `POST sign.capture.video.store`; `PUT envelopes.recipients.identity_video`;
  `GET envelopes.identity_videos.index`; `GET envelopes.identity_videos.file` (as duas GET no
  `AllGetRoutesTest`, 404 com a flag desligada).
- `assinavelox.capture_video.*`: `max_seconds`, `max_seconds_ceiling`, `duration_tolerance_ms`,
  `max_upload_kb`, `video_bits_per_second`, `max_uploads_per_hour`, `playback_url_ttl_seconds`, com
  `ASSINAVELOX_CAPTURE_VIDEO_*` no `.env`; flag `ASSINAVELOX_FEATURE_IDENTITY_VIDEO`.

## 9. Testes

`tests/Feature/Phase3/Video/` (`VideoCaptureTest`, `VideoRequirementTest`, helpers com WebM e MP4
**sintéticos** — só a estrutura do contêiner): envio válido e vínculo ao aceite; MP4, WebM sem duração
e Matroska; contêiner falso (PNG com `.webm`, HTML disfarçado, só áudio, HEIC, EBML truncado);
tamanho e duração acima do limite (lida e declarada) e dentro da folga; sem consentimento; flag
desligada (404 nas quatro rotas, props e aceite idênticos, câmera negada); outra organização e linha
adulterada; URL vencida, adulterada e de outro usuário; trilha de acesso única por URL; expurgo e
órfão; evidência só citada (HTML do relatório sem bytes nem `<video>`) e verificação pública sem
vídeo; foto + vídeo juntos; vocabulário.

## 10. O que falta para ligar em produção

1. **Decisão jurídica** (viabilidade §4.4 item 20, R9): base legal (LGPD art. 11 por precaução),
   controlador × operador, RIPD e prazo de retenção para vídeo. O texto de consentimento
   (`VideoStep::CONSENT`) e os avisos precisam da revisão do jurídico.
2. **Aviso de privacidade do participante** (`ConsentText::privacyNotice`/`privacySummary`, área de
   assinatura): hoje cita só as fotos. Com o vídeo ligado, acrescentar a finalidade, quem vê e a
   retenção do vídeo — integração pendente (fora da área F-VIDEO).
3. **Rever a gravação antes de enviar**: a CSP atual (`default-src 'self'`, sem `media-src`) bloqueia
   `blob:` em `<video>`, então o diálogo mostra duração e tamanho, sem reprodução prévia. Para permitir,
   acrescentar `media-src 'self' blob:` em `SecurityHeaders` (fora da área).
4. **Dispositivo presencial** (`in_person.kiosk.*`): não grava vídeo. Um participante presencial com
   vídeo exigido fica com o aceite bloqueado ("Antes de concluir, envie: Vídeo curto."). Integrar o
   `VideoCaptureStepCard` ao quiosque ou impedir a exigência para o fluxo presencial.
5. **Navegadores reais**: validar em Chrome/Edge (WebM), Firefox (WebM) e Safari iOS/macOS (MP4
   fragmentado) — o teste de navegador com câmera falsa ainda não existe.
6. **Custo de armazenamento**: ~1,3 MB por vídeo de 10 s; o arquivo é cifrado (base64 + envelope do
   `Crypt`, ~1,4×). Avaliar cota por plano.
7. Transcodificação (ffmpeg LGPL) segue fora: só se virar requisito (pacotes §5).

## 11. Arquivos fora da área F-VIDEO editados (aditivos)

- `app/Services/Signing/SignerPageProps.php`: acrescenta `identity_video` só quando não é `null` —
  sem essa linha a página pública não recebe a etapa.
- `app/Services/Envelopes/Finalization/EvidenceData.php` e `resources/views/evidence/page.blade.php`:
  a linha de citação do vídeo no PDF (chave só existe com vídeo).
- `resources/js/components/envelopes/wizard-step-recipients.tsx`: uma linha que monta o
  `VideoRequirementControl` (o componente se esconde com a flag desligada).
- `tests/Unit/Models/EnumCatalogTest.php`: registro dos três eventos novos no catálogo (T7), como cada
  onda fez.
- Compartilhados previstos: `routes/web.php`, `config/assinavelox.php`, `AuditEventType`,
  `HandleInertiaRequests`, `SharedPropsTest`, `AllGetRoutesTest`, `pages/sign/show.tsx`,
  `pages/envelopes/show.tsx`, `types/index.ts`.
