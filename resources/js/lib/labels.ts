/**
 * Mapas enum → rótulo PT-BR e variante de badge.
 * Fonte: ROUTES_AND_PAGES §6 e DESIGN_SYSTEM §5, com os renomes da RECONCILIACAO.
 */
import type {
    AcceptanceAction,
    AuditEventKind,
    CaptureKind,
    DeliveryChannel,
    DocumentProcessingStatus,
    EnvelopeStatus,
    InvitationStatus,
    MembershipRole,
    MembershipStatus,
    NotificationChannel,
    ParticipantRole,
    PaymentDisplayStatus,
    PaymentStatus,
    PlanCode,
    RecipientStatus,
    SignatureKind,
    SignatureStatus,
    SignerAuthMethod,
    SigningFieldType,
    SigningOrder,
    SubscriptionStatus,
} from '@/types/enums';
import type { ExternalDocumentStatus } from '@/types/external-signing';
import type { CryptoIntegrity, DossierExportStatus } from '@/types/signatures';

/** Variantes semânticas de badge (cores em DESIGN §1.1 "Semânticas"). */
export type BadgeTone =
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'neutral'
    | 'draft';

export interface StatusPresentation {
    label: string;
    tone: BadgeTone;
}

// ---------------------------------------------------------------------------
// Envelope
// ---------------------------------------------------------------------------

export const envelopeStatusLabels: Record<EnvelopeStatus, string> = {
    draft: 'Rascunho',
    preparing: 'Rascunho · processando',
    ready: 'Rascunho',
    in_progress: 'Aguardando',
    finalizing: 'Em andamento · finalizando',
    completed: 'Assinado',
    refused: 'Recusado',
    expired: 'Expirado',
    canceled: 'Cancelado',
};

export const envelopeStatusTones: Record<EnvelopeStatus, BadgeTone> = {
    draft: 'draft',
    preparing: 'draft',
    ready: 'draft',
    in_progress: 'warning',
    finalizing: 'info',
    completed: 'success',
    refused: 'danger',
    expired: 'neutral',
    canceled: 'neutral',
};

/**
 * Regra "Aguardando × Em andamento" (ROUTES §6.1): `in_progress` com ≥ 1
 * assinatura é "Em andamento" (azul); sem assinaturas é "Aguardando" (âmbar).
 */
export function envelopeStatusPresentation(
    status: EnvelopeStatus,
    signedCount = 0,
    labelOverride?: string | null,
): StatusPresentation {
    if (status === 'in_progress' && signedCount > 0) {
        return { label: labelOverride ?? 'Em andamento', tone: 'info' };
    }

    return {
        label: labelOverride ?? envelopeStatusLabels[status],
        tone: envelopeStatusTones[status],
    };
}

export const envelopeTabLabels = {
    all: 'Todos',
    awaiting: 'Aguardando',
    in_progress: 'Em andamento',
    completed: 'Concluídos',
    drafts: 'Rascunhos',
    refused_expired: 'Recusados / expirados',
} as const;

// ---------------------------------------------------------------------------
// Recipient
// ---------------------------------------------------------------------------

export const recipientStatusLabels: Record<RecipientStatus, string> = {
    pending: 'Pendente',
    notified: 'Pendente',
    viewed: 'Pendente',
    signed: 'Assinado',
    refused: 'Recusado',
    expired: 'Expirado',
    canceled: 'Cancelado',
    delegated: 'Delegado',
};

export const recipientStatusTones: Record<RecipientStatus, BadgeTone> = {
    pending: 'warning',
    notified: 'warning',
    viewed: 'warning',
    signed: 'success',
    refused: 'danger',
    expired: 'neutral',
    canceled: 'neutral',
    delegated: 'neutral',
};

/** Nota curta padrão por status (o backend pode enviar `note` mais rica). */
export const recipientStatusNotes: Record<RecipientStatus, string> = {
    pending: 'Aguarda a vez',
    notified: 'Enviado · não visualizou',
    viewed: 'Visualizou',
    signed: 'Assinado',
    refused: 'Recusou',
    expired: 'Prazo encerrado',
    canceled: 'Documento cancelado',
    delegated: 'Delegou a outra pessoa',
};

// ---------------------------------------------------------------------------
// Papéis de participante (Fase 2 §2.4)
//
// Vocabulário (arquitetura §2): nenhum papel produz "assinatura digital".
// Signatário e testemunha registram aceite eletrônico com representação
// visual; o aprovador registra aprovação eletrônica SEM representação visual;
// o visualizador só recebe cópia. Rótulos espelham `RecipientRole::label()`.
// ---------------------------------------------------------------------------

export const participantRoleLabels: Record<ParticipantRole, string> = {
    signer: 'Signatário',
    witness: 'Testemunha',
    approver: 'Aprovador',
    viewer: 'Visualizador',
};

/** Explicação curta do efeito de cada papel (seletor do passo 2). */
export const participantRoleDescriptions: Record<ParticipantRole, string> = {
    signer: 'Registra aceite eletrônico com a representação visual da assinatura. Precisa de pelo menos um campo de assinatura.',
    witness:
        'Registra aceite eletrônico como testemunha, com declaração própria: não se torna parte do documento. Precisa de um campo de assinatura.',
    approver:
        'Aprova o conteúdo por aceite eletrônico, sem representação visual de assinatura. Não recebe campo de assinatura nem rubrica.',
    viewer: 'Só acompanha: recebe o documento e a cópia final, sem assinar, aprovar ou receber campos. Não entra na ordem.',
};

/** Rótulo do registro feito pelo participante (`AcceptanceAction::label()`). */
export const acceptanceActionLabels: Record<AcceptanceAction, string> = {
    sign: 'Aceite eletrônico',
    witness: 'Aceite eletrônico como testemunha',
    approve: 'Aprovação eletrônica',
};

/** O papel é diferente do padrão da Fase 1 (merece rótulo na tela)? */
export function isSpecialRole(
    role: ParticipantRole | null | undefined,
): boolean {
    return role !== undefined && role !== null && role !== 'signer';
}

export const recipientTabLabels = {
    all: 'Todas',
    pending: 'Pendentes',
    signed: 'Assinadas',
    refused: 'Recusadas',
    expired: 'Expiradas',
} as const;

// ---------------------------------------------------------------------------
// Documento
// ---------------------------------------------------------------------------

export const documentProcessingLabels: Record<
    DocumentProcessingStatus,
    string
> = {
    uploaded: 'Enviado agora',
    converting: 'Convertendo…',
    ready: 'Pronto',
    failed: 'Falha ao processar',
    blocked: 'Arquivo bloqueado',
};

export const documentProcessingTones: Record<
    DocumentProcessingStatus,
    BadgeTone
> = {
    uploaded: 'info',
    converting: 'info',
    ready: 'success',
    failed: 'danger',
    blocked: 'danger',
};

export const fieldTypeLabels: Record<SigningFieldType, string> = {
    signature: 'Assinatura',
    initials: 'Rubrica',
    name: 'Nome completo',
    date: 'Data',
    text: 'Texto livre',
    checkbox: 'Caixa de seleção',
    // Fase 2 §2.8 e §2.11 (espelho de `FieldType::label()`).
    stamp: 'Carimbo visual',
    cpf: 'CPF',
};

export const signingOrderLabels: Record<SigningOrder, string> = {
    sequential: 'Sequencial',
    parallel: 'Todos ao mesmo tempo',
};

export const signatureKindLabels: Record<SignatureKind, string> = {
    drawn: 'Desenhada',
    typed: 'Digitada',
    uploaded: 'Imagem enviada',
};

/**
 * Espelho de `AuthMethod::label()`. Cada código prova a posse do canal (a caixa de e-mail
 * ou o celular), não a identidade. `sender_pin` é o PIN combinado pelo remetente, sempre
 * DEPOIS do código (Fase 2 §2.9).
 */
export const authMethodLabels: Record<SignerAuthMethod, string> = {
    email_otp: 'Código por e-mail',
    sms_otp: 'Código por SMS',
    whatsapp_otp: 'Código por WhatsApp',
    sender_pin: 'PIN do remetente',
};

export const deliveryChannelLabels: Record<DeliveryChannel, string> = {
    email: 'E-mail',
    sms: 'SMS',
    whatsapp: 'WhatsApp',
};

/** Canal do convite no wizard: o e-mail sempre sai; SMS/WhatsApp somam um aviso com o link. */
export const inviteChannelLabels: Record<DeliveryChannel, string> = {
    email: 'Só e-mail',
    sms: 'E-mail + SMS',
    whatsapp: 'E-mail + WhatsApp',
};

/** "por e-mail" / "por SMS" — para frases ("Receber código por SMS"). */
export const channelPhraseLabels: Record<DeliveryChannel, string> = {
    email: 'e-mail',
    sms: 'SMS',
    whatsapp: 'WhatsApp',
};

/**
 * Captura simples (Fase 2 §2.10), espelho de `CaptureKind::label()`. É a imagem que o
 * participante enviou: não há comparação de rostos nem leitura do documento.
 */
export const captureKindLabels: Record<CaptureKind, string> = {
    selfie: 'Foto do rosto',
    document_front: 'Foto do documento (frente)',
    document_back: 'Foto do documento (verso)',
};

/** Rótulo curto para as caixas de seleção do wizard. */
export const captureKindShortLabels: Record<CaptureKind, string> = {
    selfie: 'Rosto',
    document_front: 'Documento (frente)',
    document_back: 'Documento (verso)',
};

export const auditKindTones: Record<AuditEventKind, BadgeTone> = {
    ok: 'success',
    info: 'info',
    warn: 'warning',
};

// ---------------------------------------------------------------------------
// Membros, convites
// ---------------------------------------------------------------------------

export const membershipRoleLabels: Record<MembershipRole, string> = {
    owner: 'Proprietário',
    admin: 'Administrador',
    member: 'Operador',
};

export const membershipRoleDescriptions: Record<MembershipRole, string> = {
    owner: 'Acesso total, inclusive cobrança e exclusão da conta',
    admin: 'Gerencia usuários, pastas, documentos e configurações',
    member: 'Cria e envia documentos; vê apenas os próprios',
};

export const membershipStatusLabels: Record<MembershipStatus, string> = {
    active: 'Ativo',
    suspended: 'Inativo',
};

export const membershipStatusTones: Record<MembershipStatus, BadgeTone> = {
    active: 'success',
    suspended: 'neutral',
};

export const invitationStatusLabels: Record<InvitationStatus, string> = {
    pending: 'Convite pendente',
    accepted: 'Aceito',
    expired: 'Expirado',
    revoked: 'Revogado',
};

export const invitationStatusTones: Record<InvitationStatus, BadgeTone> = {
    pending: 'warning',
    accepted: 'success',
    expired: 'neutral',
    revoked: 'neutral',
};

// ---------------------------------------------------------------------------
// Plano, assinatura, pagamento
// ---------------------------------------------------------------------------

export const planLabels: Record<PlanCode, string> = {
    free: 'Grátis',
    professional: 'Profissional',
    enterprise: 'Empresarial',
};

export const subscriptionStatusLabels: Record<SubscriptionStatus, string> = {
    pending: 'Pendente',
    trialing: 'Trial',
    active: 'Ativo',
    past_due: 'Inadimplente',
    canceled: 'Cancelado',
    expired: 'Expirado',
};

export const subscriptionStatusTones: Record<SubscriptionStatus, BadgeTone> = {
    pending: 'warning',
    trialing: 'info',
    active: 'success',
    past_due: 'danger',
    canceled: 'neutral',
    expired: 'neutral',
};

export const adminOrgTabLabels = {
    all: 'Todas',
    active: 'Ativas',
    trialing: 'Em trial',
    past_due: 'Inadimplentes',
    canceled: 'Canceladas',
} as const;

/** Agrupa o status bruto do Mercado Pago no status de exibição. */
export function paymentDisplayStatus(
    status: PaymentStatus,
): PaymentDisplayStatus {
    switch (status) {
        case 'approved':
        case 'authorized':
            return 'paid';
        case 'pending':
        case 'in_process':
        case 'in_mediation':
            return 'pending';
        case 'rejected':
            return 'failed';
        case 'refunded':
        case 'charged_back':
            return 'refunded';
        case 'cancelled':
            return 'cancelled';
    }
}

export const paymentDisplayLabels: Record<PaymentDisplayStatus, string> = {
    paid: 'Paga',
    pending: 'Pendente',
    failed: 'Falhou',
    refunded: 'Reembolsada',
    cancelled: 'Cancelada',
};

export const paymentDisplayTones: Record<PaymentDisplayStatus, BadgeTone> = {
    paid: 'success',
    pending: 'warning',
    failed: 'danger',
    refunded: 'neutral',
    cancelled: 'neutral',
};

// ---------------------------------------------------------------------------
// Notificações
// ---------------------------------------------------------------------------

export const notificationChannelLabels: Record<NotificationChannel, string> = {
    mail: 'E-mail',
    database: 'No app',
};

// ---------------------------------------------------------------------------
// Assinatura criptográfica, carimbo e dossiê (Fase 2, onda C)
// ---------------------------------------------------------------------------

/**
 * Espelho de `SignatureStatus::shortLabel()`. Três coisas diferentes, cada uma com o seu
 * nome (roadmap T1): certificado do participante ≠ certificado da operadora ≠ aceite.
 */
export const signatureStatusLabels: Record<SignatureStatus, string> = {
    none: 'Sem certificado',
    company_a1: 'Certificado A1 da operadora',
    participants_a1: 'Certificado A1 dos participantes',
    mixed: 'Certificados A1 dos participantes e da operadora',
    // Fase 3 §3.4 (espelho de `SignatureStatus::shortLabel()`).
    participant_a3: 'Certificado A3 de participante',
    participant_external: 'Certificado de participante (componente externo)',
    // Fase 3 §3.5 (espelho de `SignatureStatus::shortLabel()`).
    participant_govbr: 'Assinatura gov.br (avançada) de participante',
    participant_external_unverified:
        'Assinatura de terceiro, cadeia não verificada',
};

/** O arquivo final tem alguma assinatura criptográfica (da operadora ou de participante)? */
export function hasCryptographicSignature(
    status: SignatureStatus | null | undefined,
): boolean {
    return status != null && status !== 'none';
}

/** O arquivo final tem a assinatura da operadora? */
export function hasOperatorSignature(
    status: SignatureStatus | null | undefined,
): boolean {
    return status === 'company_a1' || status === 'mixed';
}

/**
 * O arquivo final tem assinatura de participante com o próprio certificado (A1 por arquivo
 * ou, na Fase 3, feita fora da plataforma por componente)?
 */
export function hasParticipantSignatures(
    status: SignatureStatus | null | undefined,
): boolean {
    return (
        status === 'participants_a1' ||
        status === 'mixed' ||
        hasExternalParticipantSignatures(status)
    );
}

/**
 * Fase 3 §3.4: há assinatura de participante feita FORA da plataforma (A3 por componente
 * local real, ou componente externo — inclusive o simulador)? Espelho de
 * `SignatureStatus::hasExternalParticipantSignatures()`.
 */
export function hasExternalParticipantSignatures(
    status: SignatureStatus | null | undefined,
): boolean {
    return (
        status === 'participant_a3' ||
        status === 'participant_external' ||
        isGovBrReturn(status)
    );
}

/**
 * Fase 3 §3.5: documento devolvido pelo portal gov.br (com ou sem cadeia verificada). Espelho
 * de `SignatureStatus::isGovBrReturn()`.
 */
export function isGovBrReturn(
    status: SignatureStatus | null | undefined,
): boolean {
    return (
        status === 'participant_govbr' ||
        status === 'participant_external_unverified'
    );
}

/** Resultado técnico de UMA assinatura no arquivo final. */
export const cryptoIntegrityLabels: Record<CryptoIntegrity, string> = {
    intact: 'Íntegra e válida',
    broken: 'Não confirmada',
    unknown: 'Sem resultado registrado',
};

export const cryptoIntegrityTones: Record<CryptoIntegrity, BadgeTone> = {
    intact: 'success',
    broken: 'danger',
    unknown: 'neutral',
};

/** Tom do selo do pedido do participante (`ParticipantSignatureRequestStatus`). */
export function participantRequestTone(status: string): BadgeTone {
    switch (status) {
        case 'applied':
            return 'success';
        case 'failed':
            return 'danger';
        case 'requested':
        case 'queued':
        case 'applying':
            return 'warning';
        default:
            return 'neutral';
    }
}

/**
 * Título curto de cada recusa do certificado (docs/fase-2/a1-do-participante.md §7). O
 * texto explicativo é sempre o `message` do servidor; aqui só o título do alerta.
 */
export const participantCertificateErrorTitles: Record<string, string> = {
    wrong_passphrase: 'Senha incorreta',
    invalid_pkcs12: 'Arquivo de certificado inválido',
    pkcs12_too_large: 'Arquivo grande demais',
    pkcs12_without_key: 'Arquivo sem a chave privada',
    pkcs12_without_certificate: 'Arquivo sem certificado',
    key_certificate_mismatch: 'Chave e certificado não correspondem',
    certificate_is_ca: 'Certificado de autoridade certificadora',
    certificate_expired: 'Certificado vencido',
    certificate_not_yet_valid: 'Certificado ainda não está válido',
    certificate_not_for_signing: 'Certificado sem uso para assinatura',
    test_certificate_not_accepted: 'Certificado de teste não aceito',
    holder_mismatch: 'Titular diferente do participante',
    certificate_changed: 'Certificado diferente do conferido',
    not_ready: 'Ainda não é possível enviar',
    already_queued: 'Certificado já recebido',
    already_applied: 'Assinatura já aplicada',
    window_closed: 'Prazo encerrado',
    envelope_closed: 'Documento encerrado',
    cannot_withdraw: 'Não é possível desistir agora',
    not_requested: 'Nenhuma escolha registrada',
    not_authenticated: 'Confirme sua identidade',
    certificate_check_unavailable: 'Conferência indisponível no momento',
};

// ---------------------------------------------------------------------------
// Fase 3, onda E — assinatura por componente local e devolução pelo portal gov.br
// ---------------------------------------------------------------------------

/**
 * Situação de cada arquivo na assinatura por componente
 * (`docs/fase-3/assinatura-externa-a3.md` §7, `documents[].status`).
 */
export const externalDocumentStatusLabels: Record<
    ExternalDocumentStatus,
    string
> = {
    to_sign: 'Pronto para assinar',
    reserved: 'Resumo preparado',
    busy: 'Em uso por outro participante',
    waiting_base: 'Aguardando o arquivo consolidado',
    signed: 'Assinado',
};

export const externalDocumentStatusTones: Record<
    ExternalDocumentStatus,
    BadgeTone
> = {
    to_sign: 'info',
    reserved: 'warning',
    busy: 'neutral',
    waiting_base: 'neutral',
    signed: 'success',
};

/**
 * Título curto de cada recusa da assinatura por componente (§7). O texto explicativo é
 * sempre o `message` do servidor; aqui só o título do alerta.
 */
export const externalSigningErrorTitles: Record<string, string> = {
    not_ready: 'Ainda não é possível assinar',
    document_reserved: 'Arquivo em uso por outro participante',
    busy: 'O documento está ocupado',
    waiting_base: 'Arquivo ainda não consolidado',
    already_signed: 'Arquivo já assinado',
    already_consumed: 'Resumo já utilizado',
    expired: 'Resumo vencido',
    pending_closed: 'Preparação encerrada',
    stale_revision: 'O documento mudou depois da preparação',
    pending_missing: 'Preparação indisponível',
    request_closed: 'Pedido encerrado',
    envelope_closed: 'Documento encerrado',
    window_closed: 'Prazo encerrado',
    other_method_chosen: 'Outro meio já escolhido',
    component_unavailable: 'Componente indisponível',
    simulator_only: 'Preparação do simulador',
    not_simulated: 'Preparação que não é do simulador',
    cannot_withdraw: 'Não é possível desistir agora',
    not_requested: 'Nenhuma escolha registrada',
    signature_invalid: 'Assinatura não confere',
    certificate_mismatch: 'Certificado diferente do anunciado',
    digest_mismatch: 'Assinatura de outro conteúdo',
    cms_invalid: 'Pacote de assinatura inválido',
    cms_too_large: 'Pacote de assinatura grande demais',
    certificate_invalid: 'Certificado ilegível',
    certificate_expired: 'Certificado vencido',
    certificate_not_yet_valid: 'Certificado ainda não está válido',
    certificate_not_for_signing: 'Certificado sem uso para assinatura',
    certificate_is_ca: 'Certificado de autoridade certificadora',
    unsupported_key_algorithm: 'Tipo de chave não aceito',
    test_certificate_not_accepted: 'Certificado de teste não aceito',
    holder_mismatch: 'Titular diferente do participante',
    chain_not_trusted: 'Cadeia não validada',
    chain_too_long: 'Cadeia grande demais',
    invalid_encoding: 'Conteúdo mal codificado',
    input_too_large: 'Conteúdo grande demais',
    mode_mismatch: 'Modo diferente do preparado',
    mode_not_supported: 'Modo não aceito',
    component_unknown: 'Componente desconhecido',
    not_authenticated: 'Confirme sua identidade',
    pending_not_found: 'Preparação não encontrada',
    document_not_found: 'Arquivo não encontrado',
    embed_failed: 'A assinatura não foi incorporada',
    prepare_failed: 'Preparação indisponível no momento',
    revision_unavailable: 'Arquivo indisponível no momento',
};

/**
 * Título curto de cada recusa da devolução pelo portal gov.br (`docs/fase-3/gov-br.md` §4 e
 * §6). O motivo exato é o `message` do servidor.
 */
export const govbrReturnErrorTitles: Record<string, string> = {
    base_not_prefix: 'Não é o arquivo esperado',
    no_new_revision: 'Nenhuma assinatura nova',
    no_new_signature: 'Nenhuma assinatura nova',
    unexpected_revision_count: 'O arquivo foi alterado além da assinatura',
    multiple_new_signatures: 'Mais de uma assinatura nova',
    unexpected_document_timestamp: 'Selo de documento não aceito',
    signature_not_intact: 'O conteúdo assinado foi alterado',
    signature_invalid: 'Assinatura inválida',
    signature_not_covering_file: 'Conteúdo acrescentado depois da assinatura',
    unpermitted_changes: 'O arquivo foi alterado além da assinatura',
    previous_signature_broken: 'Assinatura anterior deixou de conferir',
    docmdp_violation: 'Permissões de alteração desrespeitadas',
    docmdp_locks_document: 'A assinatura bloqueia o documento',
    chain_not_trusted: 'Certificado fora da cadeia configurada',
    holder_mismatch: 'Certificado de outra pessoa',
    holder_cpf_not_found: 'CPF não encontrado no certificado',
    test_certificate_not_accepted: 'Certificado de teste não aceito',
    invalid_pdf: 'PDF ilegível',
    encrypted_pdf: 'PDF protegido por senha',
    not_pdf: 'O arquivo não é um PDF',
    file_too_large: 'Arquivo grande demais',
    upload_failed: 'O arquivo não foi recebido',
    not_ready: 'Ainda não é possível reservar',
    reservation_busy: 'Arquivo reservado por outro participante',
    reservation_expired: 'Reserva vencida',
    base_changed: 'O documento mudou depois da reserva',
    not_reserved: 'Nenhuma versão reservada',
    already_completed: 'Arquivo já recebido',
    envelope_closed: 'Documento encerrado',
    window_closed: 'Prazo encerrado',
    cannot_withdraw: 'Não é possível desistir agora',
    busy: 'O documento está ocupado',
    not_authenticated: 'Confirme sua identidade',
    not_found: 'Pedido não encontrado',
    verification_unavailable: 'Conferência indisponível no momento',
    trust_anchor_misconfigured: 'Conferência indisponível no momento',
};

/** Tom do selo do pedido gov.br (`ExternalSignatureRequestStatus`). */
export function govbrRequestTone(status: string): BadgeTone {
    switch (status) {
        case 'completed':
            return 'success';
        case 'pending':
        case 'requested':
            return 'warning';
        default:
            return 'neutral';
    }
}

export const dossierStatusTones: Record<DossierExportStatus, BadgeTone> = {
    pending: 'info',
    building: 'info',
    ready: 'success',
    failed: 'danger',
    expired: 'neutral',
};
