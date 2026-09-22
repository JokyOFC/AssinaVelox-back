# Verificação facial com documento por provedor externo (Fase 4 §4.1)

Decisão do proprietário em 21/09/2026, que promove o item "liveness / face match" do backlog
(`docs/roadmap.md` §4) para uma fase própria. Provedor: **Verifiky**, integrada como no metta-bank —
contrato em [`docs/integracoes/verifiky.md`](../integracoes/verifiky.md). Base: a captura simples de
fotos (`docs/fase-2/identidade.md` §5). Identificadores em inglês; textos em PT-BR.

**Nasce desligado.** Com a flag `identity_verification` em `false` (o padrão), nada muda: nenhuma rota
nova responde (404), a página do participante recebe exatamente as mesmas props (a chave
`identity_verification` nem aparece), o aceite grava o mesmo snapshot, a declaração de aceite tem a
mesma versão e o PDF de evidências é o mesmo.

| Flag (`assinavelox.features.*` E `plans.features.*`) | Liga                                               | Classe                            |
| ---------------------------------------------------- | -------------------------------------------------- | --------------------------------- |
| `identity_verification`                              | exigir, enviar ao provedor e registrar o resultado | A, desligada até decisão jurídica |

Resolvedor: `IdentityFeatures::identityVerification($organization)` — interruptor global **e** plano
**e** `identity_capture` ligada (as fotos que o provedor compara são as da captura). Fora de
`IdentityFeatures::FLAGS`, como `identity_video`; prop compartilhada `features.identity_verification`
numa linha própria em `HandleInertiaRequests`.

## 1. O que é — e o que não é (T1, LGPD)

- **Quem compara é o provedor.** A plataforma envia ao provedor as três fotos da captura (rosto na hora,
  frente e verso do documento) e o tipo do documento; o provedor compara a foto tirada na hora com a do
  documento e responde. A plataforma **não compara rostos nem lê documento** — e a interface, a trilha
  e a evidência dizem isso, nomeando o provedor em toda frase: "Verifiky informou: aprovado".
- **Vocabulário proibido continua proibido.** "Identidade verificada", "biometria", "liveness",
  "prova de vida", "reconhecimento facial" não aparecem em UI, e-mail, evidência nem trilha
  (`tests/Feature/Phase2/VocabularyTest.php`). O produto afirma que um provedor nomeado comparou duas
  imagens e o que ele respondeu — só isso.
- **Inconclusivo nunca libera o aceite (T5)** e nunca gasta tentativa: rede, tempo esgotado, chave
  inválida, sem crédito, resposta ilegível são falhas técnicas, não reprovação. Só `approved` libera;
  `rejected` e `expired` contam como tentativa.
- **Consentimento explícito antes do envio.** A caixa cita o provedor e o que ele faz
  (`VerificationStep::CONSENT`); o servidor recusa sem `consent` (422), grava `consented_at` e
  `consent_version` (SHA-256 do texto exibido) na tentativa, no snapshot e na trilha. A cláusula das
  fotos na declaração de aceite muda (`+vf1`/`+vf2` no lugar de `+b1`/`+b2` — `ConsentText`).
- **O que sai da plataforma:** as três imagens, o tipo do documento e uma referência nossa (ULID).
  Nunca o documento assinado, o nome do envelope, o e-mail ou o nome do participante.
- **O que se guarda da resposta** (`identity_verifications.provider_result`): status do provedor, os
  booleanos da comparação, a pontuação quando vem, o motivo quando há. **Não** se guarda o que o
  provedor leu do documento (nome, CPF, número): a evidência registra o que ele concluiu.
- **Simulador identificado.** `driver = fake` aprova sem analisar nada e marca "(simulado) — nenhuma
  imagem foi analisada" em toda parte: tela, trilha, evidência, PDF.
- **Aviso da captura muda para quem tem a exigência.** `CaptureEvidence::NOTICE` e
  `CaptureStep::NOTICE` dizem que ninguém compara as imagens; com a verificação exigida, entra o aviso
  alternativo (as fotos vão ao provedor nomeado para a comparação). Os avisos originais continuam
  byte a byte iguais para quem não tem a exigência.

## 2. Exigência (remetente)

`PUT documentos/{envelope}/participantes/{recipient}/verificacao-facial` (rota
`envelopes.recipients.identity_verification`, `VerificationRequirementController`): `required` (bool).
Só no rascunho, só para quem registra aceite, 404 com a flag desligada. **Ligar a exigência liga as
três fotos** (`selfie`, `document_front`, `document_back`) na exigência de captura do participante —
o `CaptureRequirementController` recusa retirar qualquer uma delas enquanto a verificação estiver
exigida. Desligar deixa a exigência de fotos como está. Trilha:
`identity_verification.requirement_updated`.

No wizard (`verification-requirement-control.tsx`): uma caixa por participante, "Verificação facial com
documento pelo provedor {label}". Props do wizard: `verification_enabled`, `verification_requirements`
(`{ulid: true}`) e `verification_provider_label`.

A delegação (Fase 3 §3.3) copia a exigência ao delegado — nunca uma exigência menor. O widget embutido
(G-EMBED) recusa participantes com a exigência, como faz com o vídeo.

## 3. Página pública

Bloco `identity_verification` nas props (`VerificationStep::props`), só quando exigido; sem sessão (tela
`identify`) vem sem URLs. Depois das três fotos, a pessoa escolhe o tipo do documento (`rg`, `cnh`,
`passaporte`), marca a autorização e envia:

- `POST assinar/{token}/verificacao-facial` (`sign.identity_verification.store`): `document_type` +
  `consent`. Ordem das recusas: exigida? tipo válido? três fotos nesta sessão? nenhuma tentativa em
  andamento? tentativas restantes? consentimento? Códigos: `not_required` (404),
  `invalid_document_type`, `captures_missing`, `verification_in_progress` (409), `no_attempts_left`
  (409), `consent_required`. Lock por participante contra clique duplo.
- A tentativa nasce `queued`; o job `SubmitIdentityVerification` (fila
  `identity_verification.queue`, padrão `default`, despachado **depois do commit**) lê as três imagens
  cifradas do disco, monta o multipart e chama o provedor. Só o id viaja na fila; as imagens vivem na
  memória do job. `$tries = 1`: repetir criaria outra análise no provedor. Timeout = o do provedor + 60
  s, entre 90 e 600 s (abaixo do `retry_after` de 900 s).
- `GET assinar/{token}/verificacao-facial` (`sign.identity_verification.show`): estado atual; com a
  tentativa `pending` e protocolo, consulta o provedor no máximo a cada 10 s. A tela consulta a cada
  3 s enquanto `queued`/`pending`. Prazo: `pending_timeout_minutes` (20) — vencido, vira
  `inconclusive`/`timeout` e pode ser refeita.
- Resultado final também chega pelo webhook (`POST webhooks/verifiky`, §4 do documento da Verifiky).
  `applyResult()` é **idempotente**: tentativa conclusiva não muda com aviso repetido ou atrasado.
- **Tentativas**: `max_attempts` (3). Esgotadas, só o remetente destrava (novo convite ou retirar a
  exigência).
- **Fotos refeitas depois da aprovação**: a tentativa guarda os ULIDs das fotos enviadas; se a sessão
  tiver outras, o aceite é recusado ("As fotos foram refeitas…") e a tela pede novo envio
  (`captures_changed`). Sem isso a evidência afirmaria uma aprovação sobre outras imagens.

## 4. Aceite e evidência

- `RecordAcceptance`: `IdentityVerifications::assertApproved()` antes do aceite (código
  `identity_verification_required`), e `snapshotFor()` grava `fields_snapshot.identity_verification`
  (`verification_ulid`, `provider`, `provider_label`, `simulated`, `provider_verification_id`,
  `document_type`, `status`, `face_match`, `face_score` quando há, `completed_at`, `consent_version`)
  só quando exigido — sem a exigência o snapshot é byte a byte o de antes. A tentativa aprovada passa
  a pertencer ao aceite (`signature_acceptance_id`).
- PDF de evidências (`VerificationEvidence::pdfLines`): uma linha por participante — "Verificação
  facial com documento: Verifiky informou aprovado em 21/09/2026 14:32 (identificador 4821; documento:
  CNH)" — e o aviso "Quem comparou as imagens foi o provedor; a plataforma enviou as fotos da captura e
  registrou a resposta." Nunca imagem, dado lido do documento ou pontuação.
- Detalhe do envelope (`GET documentos/{envelope}/verificacoes-faciais`,
  `envelopes.identity_verifications.index`, painel `identity-verification-panel.tsx`): por
  participante, cada tentativa com status, provedor ("(simulado)" quando for), documento, data,
  protocolo e mensagem.

## 5. Trilha (T7 — só acréscimos no fim de `AuditEventType`)

| Evento                                      | Payload                                                                                                                                            |
| ------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `identity_verification.requirement_updated` | `required`, `capture_kinds`                                                                                                                        |
| `identity_verification.submitted`           | `verification_ulid`, `attempt`, `provider`, `document_type`, `captures` (ULID, tipo, SHA-256), `consent_version`                                   |
| `identity_verification.completed`           | `status`, `provider`, `simulated`, `provider_verification_id`, `document_type`, `reason_code`, `source` (`submit`/`webhook`/`poll`); ator `system` |

## 6. Banco, rotas e configuração

- `identity_verification_requirements` (`recipient_id` único) e `identity_verifications`
  (migrations `2026_09_21_120001` e `120002`): `reference` (ULID único, é o `user_reference` enviado ao
  provedor), `provider_verification_id`, `status`, `reason_code`, `provider_result` (JSON limpo),
  `captures` (ULID/tipo/SHA-256 das fotos), `attempt`, `consented_at`, `consent_version`,
  `submitted_at`, `completed_at`, `polled_at`, `correlation_id`. Expurgo de envelope e de organização
  incluem as duas tabelas.
- Configuração (`config/assinavelox.php` → `identity_verification`): `driver`, `queue`,
  `max_attempts`, `pending_timeout_minutes`, `document_types`, `verifiky.*`. Variáveis em
  `docs/integracoes/verifiky.md` §2.
- `php artisan assinavelox:doctor`, grupo "Verificação facial": flag, driver, chave, segredo do
  webhook, HMAC e TLS — sem imprimir valor nenhum.

## 7. Testes

`tests/Feature/Phase4/IdentityVerification/`: `VerifikyProviderTest` (pedido que sai, leitura das
respostas, T5, webhook, fábrica, simulador — sem rede), `VerificationRequirementTest`,
`VerificationSubmitTest`, `VerifikyWebhookTest`, `VerificationAcceptanceTest`, `VerificationDoctorTest`.
O `VocabularyTest` e o `FlagsOffTest` continuam valendo.

## 8. O que falta para ligar em produção

1. **Decisão jurídica**: base legal (LGPD art. 11), RIPD, revisão da cláusula de consentimento
   (`VerificationStep::CONSENT`, `signer_legal.photos_verification` — minutas), contrato de operador
   com a Verifiky, prazo de retenção das imagens lá.
2. Conta e chave na Verifiky (`VERIFIKY_API_KEY`), segredo e URL do webhook cadastrados, créditos.
3. Ligar `identity_capture` e `identity_verification` (global e no plano).
4. QA com fotos reais (documento inteiro no quadro, luz, RG/CNH/passaporte) e com a resposta real da
   Verifiky — o formato foi lido do código do metta-bank, não da documentação oficial.
5. **Presencial (tablet)** não tem como enviar a verificação: participantes com a exigência não
   concluem ali. Decidir se o quiosque ganha a etapa ou se a exigência fica fora do presencial.
