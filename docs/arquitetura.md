# AssinaVelox — Arquitetura e modelo de domínio (Fase 1)

> Documento-contrato da implementação. Identificadores em inglês; prosa em português.
> Decisões do proprietário registradas em 2026-09-08: **MySQL** (não PostgreSQL) e **sem Docker**.

## 1. Stack e decisões

| Necessidade | Escolha | Observação |
|---|---|---|
| Framework | Laravel 13 (PHP ^8.3), monólito modular | starter kit oficial `laravel/react-starter-kit` (dev-main, como o instalador oficial) |
| Front | Inertia 3 + React 19 + TypeScript + Tailwind 4 + shadcn/ui (Radix) | fonte Exo 2 (Google Fonts), paleta do design |
| Autenticação interna | Fortify (sessão, TOTP, recuperação de senha, verificação de e-mail) | já incluído no starter kit |
| Banco | MySQL 8+/9 (InnoDB, utf8mb4) | local: MySQL Server 9.7 em 3306; testes: SQLite em memória + suíte MySQL opcional |
| Filas/cache/locks | dev: driver `database`; prod: Redis + Horizon | Horizon incluído e configurado; requer Redis em produção |
| Arquivos | Flysystem, disco privado `documents` | dev: `local` (storage/app/private); prod: S3 compatível com SSE verificado por comando `storage:verify` |
| PDF no navegador | PDF.js (pdfjs-dist) + camada própria de campos | editor desktop; assinatura responsiva |
| Captura de assinatura | signature_pad | também digitada e upload de imagem, normalizadas com GD |
| Conversão DOCX→PDF | LibreOffice headless via `PdfConverter` (processo isolado, sem shell) | **não instalado localmente**: adaptador + fake; imagens→PDF via pdftool |
| Composição/assinatura PDF | `tools/pdftool` (Python: pypdf, reportlab, pyHanko) chamado por processo controlado | PAdES **B-B** alvo; validação por pyHanko |
| Página de evidências | Blade → DOMPDF (`barryvdh/laravel-dompdf`) | QR via bacon/bacon-qr-code (dependência do Fortify) |
| Hash | `hash_file('sha256')` nativo | sempre indicando quais bytes |
| Pagamentos | Mercado Pago Checkout Pro via `PaymentGateway` (SDK oficial `mercadopago/dx-php`) | confirmação só por webhook autenticado + consulta à API |
| Testes | Pest + testes de navegador para fluxos críticos | — |

Não adotados: repositórios genéricos, microsserviços de negócio, event sourcing, Kubernetes, Docker (decisão do proprietário), Postal/Mailcow/Mailu/Gammu/Evolution/WPPConnect/Baileys.

## 2. Semântica de assinatura (vocabulário obrigatório na UI e no código)

- **Representação visual** (`SignatureKind`: drawn, typed, uploaded): imagem exibida no documento. Não prova nada por si.
- **Aceite eletrônico** (`SignatureAcceptance`): manifestação de vontade vinculada a `document_version_id`, campos apresentados (snapshot), método de autenticação (e-mail OTP), data do servidor (UTC), IP via proxy confiável, user-agent, versão dos termos.
- **Assinatura criptográfica da empresa operadora** (`VerificationRecord.signature_status = company_a1`): PAdES B-B aplicada por pyHanko com certificado A1 configurado. Identifica a empresa titular do certificado; **não** é assinatura pessoal ICP-Brasil de cada participante.
- **Sem certificado** (`signature_status = none`): o envelope conclui como *aceite eletrônico com evidências*; a UI diz exatamente isso. Nunca simular assinatura criptográfica.
- Página de evidências ≠ certificado digital. SHA-256 ≠ assinatura. Certificados de teste são rotulados `environment=test` e nunca exibidos como ICP-Brasil.

## 3. Modelo de domínio

Chaves: `id` BIGINT interno; `ulid` CHAR(26) público e opaco (nunca substitui autorização). Todos os agregados carregam `organization_id` (denormalizado onde necessário para constraints e escopo). Timestamps em UTC; exibição no fuso da organização (padrão `America/Sao_Paulo`). Dinheiro em centavos inteiros + `currency`.

### 3.1 Tabelas

**organizations**: id, ulid, name, legal_name (nullable), tax_id (CNPJ/CPF, criptografado, nullable), timezone, locale, settings JSON (default_expiration_days=30, otp_required=true, evidence_show_ip=masked|full|none, refusal_policy=close_envelope), created_by_user_id, timestamps, deleted_at.

**users** (starter kit + colunas): is_platform_admin BOOL, current_organization_id FK nullable, timezone nullable; colunas Fortify de TOTP (two_factor_*).

**memberships**: id, organization_id, user_id, role ENUM(owner, admin, member), created_at, updated_at. UNIQUE(organization_id, user_id). Pelo menos um `owner` por organização (invariante de serviço).

**membership_invitations**: id, ulid, organization_id, email, role, token_digest CHAR(64) UNIQUE, invited_by_user_id, expires_at, accepted_at, revoked_at, timestamps.

**folders**: id, ulid, organization_id, parent_id nullable (self FK), name, created_by_user_id, timestamps. UNIQUE(organization_id, parent_id, name).

**envelopes**: id, ulid, organization_id, folder_id nullable, created_by_user_id, title, message TEXT nullable, status ENUM(draft, preparing, ready, in_progress, finalizing, completed, refused, expired, canceled), signing_order ENUM(sequential, parallel), current_order INT default 1, expires_at, sent_at, completed_at, refused_at, expired_at, canceled_at, sent_document_version_id nullable, final_document_version_id nullable, verification_code CHAR(24) UNIQUE nullable (gerado no envio), finalization_key CHAR(26) nullable, terms_version VARCHAR(32), settings JSON (otp_required, expiration_days), timestamps, deleted_at. Índices: (organization_id, status), (organization_id, created_at), (organization_id, folder_id).

**documents**: id, ulid, envelope_id, organization_id, name, original_filename, source_type ENUM(pdf, docx, image), processing_status ENUM(uploaded, converting, ready, failed, blocked), failure_code, failure_message, current_version_id nullable, page_count, timestamps. Regra Fase 1: exatamente 1 documento por envelope (serviço + teste).

**document_versions**: id, ulid, document_id, organization_id, version_number INT, kind ENUM(original, converted, consolidated, evidence, final), storage_disk, storage_path, mime_type, size_bytes, sha256 CHAR(64), page_count, pages_meta JSON (por página: width_pt, height_pt, rotation, mediabox[4], cropbox[4]), is_encrypted BOOL, has_signatures BOOL, created_by_type, created_by_id, created_at. UNIQUE(document_id, version_number). Imutável após criação.

**recipients**: id, ulid, envelope_id, organization_id, name, email, phone nullable, role ENUM(signer) (extensível na Fase 2), order_index INT, status ENUM(pending, notified, viewed, signed, refused, canceled, expired), auth_method ENUM(email_otp), signed_at, refused_at, refusal_reason, notification_count INT, last_notified_at, timestamps. UNIQUE(envelope_id, email). Sem conta de usuário.

**recipient_access_links** (convite): id, ulid, recipient_id, envelope_id, document_version_id, organization_id, token_digest CHAR(64) UNIQUE, purpose ENUM(signing, download), expires_at, revoked_at, last_used_at, use_count, created_at. Token: 32 bytes aleatórios base64url; só o digest é gravado.

**signing_sessions**: id, ulid, recipient_id, envelope_id, document_version_id, access_link_id, organization_id, token_digest UNIQUE, status ENUM(pending_auth, authenticated, consumed, expired, revoked), authorization_token_digest nullable, authorization_expires_at, snapshot_hash CHAR(64) nullable, ip_address, user_agent, expires_at, authenticated_at, consumed_at, last_seen_at, created_at.

**auth_challenges**: id, ulid, signing_session_id, recipient_id, envelope_id, organization_id, channel ENUM(email, sms, whatsapp), code_hash CHAR(64) (HMAC-SHA256 com segredo derivado da APP_KEY sobre `ulid|code`), attempts INT, max_attempts INT (5), expires_at, consumed_at, delivery_attempt_id nullable, created_at. OTP 6 dígitos via `random_int`, validade 10 min, reenvio limitado (RateLimiter).

**signing_fields**: id, ulid, envelope_id, document_version_id, recipient_id, organization_id, type ENUM(signature, initials, name, date, text, checkbox), page INT (1-based), x, y, width, height DECIMAL(9,6) normalizados [0,1] em relação ao **CropBox** exibido, origem no canto superior esquerdo *já considerando a rotação da página*; box_type ENUM(cropbox, mediabox), page_width_pt, page_height_pt, page_rotation, required BOOL, label, options JSON (font_size, date_format, default), sort_order, timestamps. Validação backend: recipiente pertence ao envelope; página existe; 0 ≤ x, y; x+width ≤ 1; y+height ≤ 1; tamanhos mínimos.

**signing_field_values**: id, signing_field_id UNIQUE, recipient_id, signature_acceptance_id, envelope_id, organization_id, value_text nullable, value_bool nullable, image_path nullable (PNG normalizado), created_at.

**signature_acceptances**: id, ulid, recipient_id UNIQUE, envelope_id, document_version_id, signing_session_id, auth_challenge_id, organization_id, accepted_at (UTC servidor), ip_address, user_agent, auth_method, terms_version, consent_statement TEXT, document_sha256 (bytes da versão apresentada), fields_snapshot JSON (campos apresentados e valores), signature_kind ENUM(drawn, typed, uploaded), signature_image_path, typed_name, typed_font, created_at.

**audit_events** (append-only): id, ulid, organization_id, envelope_id nullable, recipient_id nullable, actor_type ENUM(user, recipient, system), actor_id nullable, event_type VARCHAR(64), payload JSON (minimizado, sem tokens/senhas), ip_address nullable, user_agent nullable, correlation_id CHAR(26), occurred_at. Índices (envelope_id, occurred_at), (organization_id, occurred_at). Sem updated_at; usuário MySQL da aplicação em produção sem UPDATE/DELETE nesta tabela (documentado) + comando de exportação com hash (checkpoint).

**delivery_attempts**: id, ulid, organization_id, envelope_id nullable, recipient_id nullable, channel ENUM(email, sms, whatsapp), provider VARCHAR, purpose ENUM(invitation, otp, resend, completed, refused, canceled, membership_invitation), to_address, status ENUM(queued, sent, delivered, failed, bounced, unknown), provider_message_id, error_message, correlation_id, queued_at, sent_at, delivered_at, meta JSON, timestamps. "sent" ≠ "delivered".

**verification_records**: id, code CHAR(24) UNIQUE (= envelopes.verification_code), envelope_id UNIQUE, organization_id, final_document_version_id, original_sha256, sent_sha256, consolidated_sha256, final_sha256, signature_status ENUM(none, company_a1), signature_profile VARCHAR (ex.: PAdES-B-B), certificate_reference_id nullable, validation_result JSON, validated_at, created_at, revoked_at.

**certificate_references**: id, organization_id nullable (null = certificado da operadora), name, kind ENUM(company_a1), environment ENUM(test, production), secret_ref VARCHAR (nome da variável/arquivo; nunca o segredo), subject, issuer, serial_number, fingerprint_sha256, not_before, not_after, is_active, timestamps.

**plans**: id, code UNIQUE, name, description, price_cents INT, currency CHAR(3) BRL, billing_period ENUM(monthly, yearly), envelope_quota INT nullable, user_quota INT nullable, features JSON, is_active, is_public, is_sandbox BOOL, sort_order, timestamps.

**subscriptions**: id, ulid, organization_id, plan_id, status ENUM(pending, active, past_due, canceled, expired), started_at, current_period_start, current_period_end, canceled_at, envelopes_used INT, envelopes_reserved INT, provider VARCHAR, timestamps. Uma ativa por organização (transação + lock; sem índice parcial no MySQL).

**plan_consumptions** (ledger): id, organization_id, subscription_id, envelope_id, idempotency_key VARCHAR(80) UNIQUE, quantity INT, status ENUM(reserved, committed, released), reserved_at, committed_at, released_at.

**payments**: id, ulid, organization_id, subscription_id nullable, plan_id, provider mercadopago, external_reference CHAR(26) UNIQUE (nosso), provider_preference_id, provider_payment_id VARCHAR UNIQUE nullable, status ENUM(pending, approved, authorized, in_process, in_mediation, rejected, cancelled, refunded, charged_back), status_detail, amount_cents, currency, payment_method_id, payer_email_masked, checkout_url, paid_at, activated_at (aplicação do plano, uma única vez), environment ENUM(sandbox, production), timestamps.

**payment_webhook_receipts**: id, provider, event_fingerprint VARCHAR(191) UNIQUE (type + data.id + action), topic, action, payload JSON, signature_header, signature_valid BOOL, received_at, processed_at, processing_status ENUM(received, processed, ignored, failed), error, timestamps.

### 3.2 Estados e transições

**Envelope**
- `draft` → `preparing` (upload iniciado / conversão em fila)
- `preparing` → `ready` (documento `ready`, ≥1 destinatário, campos obrigatórios válidos) | → `draft` (conversão falhou/bloqueada; documento marcado)
- `ready` → `draft` (edição invalida) — recomputado a cada alteração
- `ready` → `in_progress` (**enviar**: transação com `FOR UPDATE`: congela `sent_document_version_id`, gera `verification_code`, define `expires_at`, reserva consumo do plano; após commit despacha convites da ordem 1 ou de todos no paralelo)
- `in_progress` → `finalizing` (último aceite exigido, sob lock) → `completed` (arquivo final + hashes + registro de verificação persistidos)
- `in_progress` → `refused` (recusa; política padrão encerra) | → `expired` (scheduler *ou* validação no acesso) | → `canceled` (remetente)
- `draft|preparing|ready` → `canceled`
- Terminais: completed, refused, expired, canceled. `finalizing` não é cancelável; job idempotente com retentativa e alerta.

**Recipient**: pending → notified → viewed → signed | refused; qualquer não-terminal → canceled | expired. No sequencial, `notified` só quando `order_index == envelope.current_order`.

**Document.processing_status**: uploaded → converting → ready | failed | blocked (PDF protegido/assinado/inválido; original preservado).

### 3.3 Invariantes e concorrência
- Aceite: transação curta, `SELECT ... FOR UPDATE` no envelope; revalida status, expiração, turno, `document_version_id` da sessão = `sent_document_version_id`, sessão autenticada e token de autorização válido; UNIQUE(recipient_id) em acceptances impede duplicidade.
- Consumo do plano: `plan_consumptions.idempotency_key = envelope:{id}:send`; reserva → commit no envio bem-sucedido; release em falha de envio.
- Finalização: `finalization_key` por envelope; cada etapa verifica artefato existente antes de recriar; nunca `completed` antes de arquivo final validado e `verification_records` gravado.
- Nada de transação aberta durante conversão, chamada externa ou processo Python.

## 4. Fluxo do signatário (público, sem conta)

1. `GET /assinar/{token}`: valida link (digest, expiração, revogação, envelope `in_progress`, turno). **Não consome nada**; registra `invitation.opened` (abertura detectada, não leitura). Mostra remetente, título, e-mail mascarado, botão "Receber código".
2. `POST /assinar/{token}/codigo`: cria `AuthChallenge`, envia OTP por `EmailProvider` (fila, `DeliveryAttempt`). Limites: 1/min e 5/h por link.
3. `POST /assinar/{token}/codigo/verificar`: valida (≤5 tentativas, 10 min, consumo único) → cria `SigningSession` (cookie httpOnly/SameSite=Lax/secure com token; digest no banco; TTL 30 min) e emite token de autorização final (10 min) ligado ao `snapshot_hash`.
4. `GET /assinar/{token}/documento`: exige sessão; PDF via PDF.js (streaming autorizado), campos do destinatário, campos de terceiros indicados como pendentes, captura de assinatura, texto de aceite explícito.
5. `POST /assinar/{token}/aceitar`: exige sessão + token de autorização; revalida tudo sob lock; normaliza imagem (GD → PNG ≤ 1200×400, sem metadados); grava valores, `SignatureAcceptance` com snapshot, eventos; marca `signed`; avança ordem (notifica próximo) ou dispara finalização.
6. `POST /assinar/{token}/recusar`: motivo obrigatório → recipient `refused`, envelope `refused` (política padrão), notifica remetente.
7. `GET /assinar/{token}/comprovante`: comprovante do aceite; após conclusão, download autorizado do PDF final por link de download próprio (purpose=download, com expiração).

## 5. Pipeline documental

1. Upload → `document_versions(kind=original)` + sha256; job `ProcessDocumentUpload` valida MIME real (finfo), tamanho, páginas/dimensões.
2. PDF: `pdftool inspect` → páginas, boxes, rotação, criptografia, assinaturas existentes. Protegido/assinado/inválido → `blocked`/`failed` com mensagem clara; original preservado.
3. DOCX: `PdfConverter` (LibreOffice headless, processo sem shell, diretório temporário exclusivo, timeout, sem rede, macros bloqueadas) → `kind=converted`. Imagem: normalizada (GD) → PDF (`pdftool image2pdf`).
4. Editor: campos sobre a versão PDF exibida; geometria normalizada (§3.1).
5. Envio: congela `sent_document_version_id`; hash "sent" = bytes dessa versão.
6. Coleta de aceites (§4).
7. Finalização (`FinalizeEnvelopeJob`, idempotente): plano de renderização JSON → `pdftool compose` (campos autorizados achatados) → `kind=consolidated` + hash → evidências (Blade→DOMPDF) → `pdftool append` → arquivo pré-assinatura → se certificado ativo: `pdftool sign` (PAdES B-B, PFX + senha via variável de ambiente do processo, nunca argv/fila/log) → `pdftool validate` → `kind=final` → **hash final calculado depois da assinatura** e gravado em `verification_records` (nunca dentro do próprio PDF) → `completed` → notificações e download autorizado.

## 6. Verificação pública

`GET /verificar` (formulário) e `GET /verificar/{code}`: exibe estado, data de conclusão, quantidade de participantes, hashes (final e enviado), `signature_status`/perfil e resultado técnico de validação. Não exibe PDF, CPF, e-mail, IP nem dossiê. Comparação de arquivo local por SHA-256 calculado no navegador (WebCrypto); sem upload.

## 7. Papéis e autorização

- `owner`: tudo, incluindo cobrança, exclusão da organização, gestão de owners.
- `admin`: usuários (exceto owners), pastas, todos os envelopes, configurações.
- `member`: cria e gerencia os próprios envelopes; vê apenas os próprios.
- `is_platform_admin` (usuário interno): painel `/admin` somente leitura (clientes, planos, pagamentos) — não concede acesso a documentos.
- Escopo: middleware `EnsureOrganizationSelected` + escopo por `organization_id` da organização atual em sessão + Policies por recurso; jobs recebem `organization_id` e revalidam.

## 8. Adaptadores (`App\Integrations`)

Contratos Fase 1: `EmailProvider`, `PdfConverter`, `PdfSigner`, `PaymentGateway`. Implementações: `LaravelMailEmailProvider` (SMTP/HTTP do serviço próprio via mailer), `LogEmailProvider` (fake dev, identificado), `LibreOfficeConverter`, `ImageToPdfConverter`, `FakePdfConverter`, `PyHankoSigner`, `NullPdfSigner`, `MercadoPagoGateway`, `FakePaymentGateway`. Contratos reservados (interfaces documentadas, sem implementação): `SmsProvider`, `WhatsAppProvider`, `CpfVerificationProvider`, `CnpjLookupProvider`, `IdentityVerificationProvider`, `TimestampProvider`, `FiscalInvoiceProvider`. Cada adaptador: config própria, timeout, autenticação, tratamento de erro, `correlation_id`, política de repetição idempotente; resposta inconclusiva ≠ sucesso.

## 9. Incrementos

1. Base e isolamento: migrations, modelos, enums, factories, seeders seguros, organizações, papéis, policies, layout do design, auth (Fortify) e páginas de conta.
2. Preparação: upload, validação, conversão, inspeção PDF, editor de campos, versões.
3. Coleta: destinatários, envio, convites, links, OTP, sessão, tela pública, aceite, recusa, expiração, reenvio, trilha.
4. Finalização e verificação: pipeline, evidências, assinatura A1, registro e página de verificação, downloads.
5. Gestão e cobrança: dashboard, pastas, busca, usuários/convites, planos, Mercado Pago, consumo.
6. Endurecimento e documentação: CSP/cabeçalhos, rate limits, testes críticos, docs, roadmap.
