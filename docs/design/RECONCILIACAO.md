# Reconciliação: `arquitetura.md` × `ROUTES_AND_PAGES.md` × `DESIGN_SYSTEM.md`

> Este arquivo resolve divergências entre os três documentos. **Em caso de conflito, vale o que está aqui.**
> Precedência geral: (1) este arquivo; (2) `docs/arquitetura.md` para nomes de entidades, tabelas, enums e regras de domínio; (3) `docs/design/ROUTES_AND_PAGES.md` para rotas, páginas, props e comportamento de UI; (4) `docs/design/DESIGN_SYSTEM.md` para tokens, componentes e cópia em português.

## 1. Nomes canônicos (backend e front)

| Conceito | Nome canônico | Nomes rejeitados |
|---|---|---|
| Trilha de auditoria | `AuditEvent` / tabela `audit_events` / prop `events` | `EnvelopeEvent` |
| Ordem de assinatura | `envelopes.signing_order` ∈ `sequential`, `parallel` | `routing_mode` |
| Código legível do envelope | `envelopes.number` (INT, sequência **por organização**, UNIQUE(organization_id, number)); exibido como `AV-{number:05d}` no accessor `display_code` | `public_code` global |
| Código de verificação pública | `envelopes.verification_code` (12 caracteres base32 sem `0/1/O/I`, gerado no envio, exibido `XXXX-XXXX-XXXX`) | — |
| Link de convite | `recipient_access_links` (token 32 bytes → `token_digest`; vários por recipient; revogação) | `recipients.access_token_hash` |
| Sessão do signatário | `signing_sessions` (linha no banco com `token_digest`, status, expiração, `authorization_token_digest`); o token bruto fica na sessão Laravel (`signer.sessions.{recipient_ulid}`), nunca em cookie próprio | chave `signer.verified.*` solta na sessão |
| Papel de recipient | `recipients.role` ∈ `signer` (Fase 2: witness, approver, viewer) | — |
| Status de membership | `memberships.status` ∈ `active`, `suspended` (adotado do ROUTES_AND_PAGES) | — |
| Tema | Somente claro na Fase 1 (`.dark` removido do CSS; `appearance` do kit desativado) | dark mode |

## 2. Enums canônicos (`App\Enums\*` ↔ `resources/js/types/enums.ts`)

```ts
export type EnvelopeStatus = 'draft' | 'preparing' | 'ready' | 'in_progress' | 'finalizing' | 'completed' | 'refused' | 'expired' | 'canceled';
export type RecipientStatus = 'pending' | 'notified' | 'viewed' | 'signed' | 'refused' | 'expired' | 'canceled';
//   pending  = criado; ainda não notificado (envelope não enviado OU aguarda a vez no sequencial) → rótulo "Aguarda a vez" quando envelope in_progress
//   notified = convite despachado (não implica entrega) → rótulo "Enviado · não visualizou"
//   viewed   = link aberto (abertura detectada, não prova leitura) → "Visualizou em {dt}"
export type SigningOrder = 'sequential' | 'parallel';
export type DocumentSourceType = 'pdf' | 'docx' | 'image';
export type DocumentProcessingStatus = 'uploaded' | 'converting' | 'ready' | 'failed' | 'blocked';
//   blocked = PDF protegido por senha, já assinado digitalmente ou inválido para preparação; original preservado
export type DocumentVersionKind = 'original' | 'converted' | 'consolidated' | 'evidence' | 'final';
export type FieldType = 'signature' | 'initials' | 'name' | 'date' | 'text' | 'checkbox';
export type SignatureKind = 'drawn' | 'typed' | 'uploaded';        // representação visual
export type AuthMethod = 'email_otp';                               // Fase 2: sms_otp, whatsapp_otp
export type DeliveryChannel = 'email' | 'sms' | 'whatsapp';         // Fase 1 usa só email
export type DeliveryStatus = 'queued' | 'sent' | 'delivered' | 'failed' | 'bounced' | 'unknown';
export type SigningSessionStatus = 'pending_auth' | 'authenticated' | 'consumed' | 'expired' | 'revoked';
export type MembershipRole = 'owner' | 'admin' | 'member';
export type MembershipStatus = 'active' | 'suspended';
export type InvitationStatus = 'pending' | 'accepted' | 'expired' | 'revoked';   // derivado de accepted_at/expires_at/revoked_at
export type PlanCode = 'free' | 'professional' | 'enterprise';      // seeders; preços/limites são configuração, não oferta
export type SubscriptionStatus = 'pending' | 'trialing' | 'active' | 'past_due' | 'canceled' | 'expired';
export type PaymentStatus = 'pending' | 'approved' | 'authorized' | 'in_process' | 'in_mediation' | 'rejected' | 'cancelled' | 'refunded' | 'charged_back'; // espelho fiel do Mercado Pago
export type PaymentDisplayStatus = 'paid' | 'pending' | 'failed' | 'refunded' | 'cancelled'; // agrupamento só para UI
export type SignatureStatus = 'none' | 'company_a1';                // verification_records.signature_status
export type ActorType = 'user' | 'recipient' | 'system';
```

Rótulos PT-BR: usar a seção 6 de `ROUTES_AND_PAGES.md` e a seção 5 de `DESIGN_SYSTEM.md`, aplicando os renomes acima (`waiting`→`pending`, `sent`→`notified`, `processing`→`converting`).

## 3. Tipos de evento de auditoria (`audit_events.event_type`)

`envelope.created`, `envelope.updated`, `document.uploaded`, `document.conversion_started`, `document.converted`, `document.processing_failed`, `document.blocked`, `document.removed`, `fields.updated`, `recipients.updated`, `envelope.sent`, `invitation.sent`, `invitation.resent`, `invitation.opened`, `challenge.sent`, `challenge.verified`, `challenge.failed`, `session.started`, `acceptance.recorded`, `recipient.refused`, `envelope.refused`, `envelope.expired`, `envelope.canceled`, `envelope.finalizing`, `envelope.consolidated`, `envelope.evidence_generated`, `envelope.signed_company_a1`, `envelope.completed`, `envelope.finalization_failed`, `envelope.downloaded`, `envelope.moved`, `envelope.duplicated`, `plan.consumption_reserved`, `plan.consumption_committed`, `plan.consumption_released`.

Payload mínimo por evento (sem tokens, senhas, códigos OTP, e-mails completos quando evitável). `kind` para a UI (`ok|info|warn`) é derivado no backend conforme ROUTES_AND_PAGES §6.6.

## 4. Decisões sobre as questões abertas (Q1–Q30 de ROUTES_AND_PAGES §7)

Aceitas como recomendadas, com estes ajustes:
- **Q4/Q8**: cadastro cria `Organization` + membership `owner` + `Subscription(status=active, plan=free)`. `trialing` fica reservado.
- **Q7**: `member` vê apenas envelopes próprios; contagens respeitam o escopo.
- **Q9**: campo `date` é carimbado pelo servidor no aceite (fuso da organização), somente leitura.
- **Q10**: rubrica em todas as páginas gera campos `initials` reais (um por página) no momento de salvar os campos, não um campo virtual; posição padrão x 0.86, y 0.94, w 0.10, h 0.04.
- **Q11**: "Lembrar"/"Reenviar" = reenvio manual; emite **novo** link (`recipient_access_links`) e revoga o anterior; throttle 10 min por recipient; máximo configurável (`settings.max_resends`, padrão 5).
- **Q18**: LibreOffice headless **sem Docker** (processo local via `PdfConverter`), fila `conversions`; imagens → PDF via `pdftool image2pdf` (Python), não Imagick.
- **Q19**: um certificado A1 **da operadora** (AssinaVelox) na Fase 1; `certificate_references.organization_id` nulo. Certificado por organização = extension point.
- **Q20**: pagamento avulso por ciclo via Checkout Pro; `past_due` após 3 dias do vencimento (bloqueia envio); `expired` após 15 dias (volta a `free`).
- **Q22**: `expires_at = fim do dia (23:59:59) no fuso da organização` do dia `sent_at + expiration_days`; `ExpireEnvelopes` a cada 15 min **e** revalidação a cada acesso.
- **Q23**: disco `documents` privado (`local` em dev, `s3` em produção); download sempre por controller autorizado; sem URLs públicas temporárias.
- **Q26**: notificações in-app via canal `database` apenas para eventos-chave (assinado, concluído, recusado, expirando); popover simples.
- **Q28**: `number` por organização (ver §1), não global.
- **Q30**: somente tema claro.

## 5. Escopo Fase 1 confirmado (o que fica fora)

Templates/Modelos, API pública + chaves + webhooks (Sanctum), múltiplos documentos, lembretes automáticos, SMS/WhatsApp, selfie/biometria, branding/logo, NF-e, impersonation ("Acessar como"), "Nova conta" no admin, download em lote (ZIP), roles customizadas, certificado por organização, login com certificado digital, dark mode. Cada um tem página placeholder ou controle desabilitado com badge "Fase 2", conforme `ROUTES_AND_PAGES.md`.

## 6. Divergências conhecidas do design em relação ao escopo

- A página pública do mock mostra "Token SMS + selfie" como autenticação: na Fase 1 a etapa "Confirmar identidade" usa **código por e-mail**; os demais métodos aparecem desabilitados com badge "Fase 2".
- O mock de Login mostra "Entrar com certificado digital": oculto na Fase 1.
- O sidebar do mock tem badge "23" fixo em Documentos: vem de `counts.pending_envelopes` (envelopes `in_progress` visíveis ao usuário); oculto quando 0.
- `--accent`: usar `#eef2f9` (hover de itens, conforme mocks), e `#f4f8fe` como `--accent-soft` (fundos de destaque).
