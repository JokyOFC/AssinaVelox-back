/**
 * Fase 2, onda C — assinatura com o certificado do próprio participante (§2.12), carimbo
 * do tempo da operadora e dossiê (§2.13).
 *
 * Espelhos fiéis dos contratos do backend:
 * - `docs/fase-2/a1-do-participante.md` §7 (`ParticipantCertificateService`,
 *   `ParticipantSignatureViews`);
 * - `docs/fase-2/carimbo-e-dossie.md` §7 (`DossierExports::statusProps`,
 *   `TimestampEvidence`).
 *
 * Os rótulos (`label`, `kind_label`, `status_label`, `*_label`) chegam prontos do servidor
 * e são exibidos como vieram: é a redação que distingue a assinatura do participante da
 * assinatura da operadora e do aceite eletrônico (roadmap T1).
 */

// ---------------------------------------------------------------------------
// Página pública — assinar com o próprio certificado (`sign.certificate.*`)
// ---------------------------------------------------------------------------

/** `ParticipantSignatureRequestStatus` + os estágios derivados pelo servidor. */
export type ParticipantCertificateStage =
    | 'choose'
    | 'awaiting_others'
    | 'ready_to_upload'
    | 'queued'
    | 'applying'
    | 'applied'
    | 'failed'
    | 'expired'
    | 'withdrawn'
    | 'closed';

/** Fatos públicos do certificado já conferido (sem CPF completo). */
export interface ParticipantCertificateSummary {
    holder_name: string | null;
    holder_cpf_masked: string | null;
    issuer_cn: string | null;
    serial: string | null;
    fingerprint_sha256: string | null;
    valid_from: string | null;
    valid_to: string | null;
    is_test: boolean;
    /** Ex.: "Certificado de TESTE — não é ICP-Brasil e não tem validade jurídica". */
    kind_label: string;
    /** "Assinado com certificado A1 de {nome} (emitido por {AC})". */
    label: string;
}

export interface ParticipantCertificateRequest {
    id: string;
    status: string;
    status_label: string;
    certificate: ParticipantCertificateSummary | null;
    consent_version: string | null;
    consented_at: string | null;
    queued_at: string | null;
    applied_at: string | null;
    window_expires_at: string | null;
    documents_signed: number;
    failure: { code: string; message: string } | null;
}

/** Estado do passo (`GET sign.certificate.show`, e respostas de intent/withdraw/store). */
export interface ParticipantCertificateState {
    available: true;
    authenticated: boolean;
    stage: ParticipantCertificateStage;
    /** Conteúdo congelado: o PFX pode ser enviado agora. */
    ready: boolean;
    /** Por que ainda não (PT-BR, do servidor). */
    message: string | null;
    can_request: boolean;
    can_withdraw: boolean;
    can_upload: boolean;
    request: ParticipantCertificateRequest | null;
    consent: {
        version: string;
        checkbox_label: string;
        summary: string;
        legal_review_required: boolean;
    };
    limits: {
        max_upload_kb: number;
        accepted_extensions: string[];
        sealed_ttl_minutes: number;
        application_window_minutes: number;
    };
    notices: string[];
    endpoints: {
        show: string;
        intent: string;
        withdraw: string;
        inspect: string;
        store: string;
    };
}

/** Prévia do certificado lido pelo servidor (`POST sign.certificate.inspect`). Nada é guardado. */
export interface ParticipantCertificatePreview {
    certificate: {
        holder_name: string | null;
        holder_cpf_masked: string | null;
        cpf_source: string | null;
        /** Sempre `false`: não há consulta à Receita. */
        cpf_confirmed: false;
        subject: string | null;
        subject_cn: string | null;
        issuer: string | null;
        issuer_cn: string | null;
        serial: string | null;
        fingerprint_sha256: string;
        valid_from: string | null;
        valid_to: string | null;
        key_usage: string[];
        extended_key_usage: string[];
        self_signed: boolean;
        chain_length: number;
        declares_icp_brasil_policy: boolean;
        /** Sempre `false`: a plataforma não valida a cadeia até uma raiz da ICP-Brasil. */
        icp_brasil_validated: false;
        is_test: boolean;
        kind_label: string;
        label: string;
        warnings: string[];
    };
    holder_match: {
        cpf: 'match' | 'mismatch' | 'unknown';
        name: 'match' | 'different' | 'unknown';
        rule: string;
    };
    consent: {
        version: string;
        checkbox_label: string;
        /** Texto integral a exibir (gravado no pedido quando o envio é aceito). */
        statement: string;
        legal_review_required: boolean;
    };
    ready: boolean;
    message: string | null;
}

/** Códigos de recusa/erro do contrato (§7). Texto livre para códigos futuros. */
export type ParticipantCertificateErrorCode =
    | 'wrong_passphrase'
    | 'invalid_pkcs12'
    | 'pkcs12_too_large'
    | 'pkcs12_without_key'
    | 'pkcs12_without_certificate'
    | 'key_certificate_mismatch'
    | 'certificate_is_ca'
    | 'certificate_expired'
    | 'certificate_not_yet_valid'
    | 'certificate_not_for_signing'
    | 'test_certificate_not_accepted'
    | 'holder_mismatch'
    | 'certificate_changed'
    | 'not_ready'
    | 'already_queued'
    | 'already_applied'
    | 'window_closed'
    | 'envelope_closed'
    | 'cannot_withdraw'
    | 'not_requested'
    | 'not_authenticated'
    | 'certificate_check_unavailable';

export interface ParticipantCertificateError {
    message: string;
    /**
     * Um de {@link ParticipantCertificateErrorCode} (texto livre para códigos futuros e
     * para a validação do Laravel, que não manda `code`).
     */
    code?: string;
    /** Chaves do contrato: `certificate`, `password`, `consent`. */
    errors?: Partial<Record<string, string[]>>;
}

// ---------------------------------------------------------------------------
// Assinaturas criptográficas — evidências (autenticada) e verificação pública
// ---------------------------------------------------------------------------

export type CryptoIntegrity = 'intact' | 'broken' | 'unknown';

/** Um documento assinado por um participante (`ParticipantSignatureViews::forEvidence`). */
export interface EvidenceParticipantSignatureDocument {
    document_id: string | null;
    name: string | null;
    position: number;
    field_name: string;
    revision_index: number;
    signed_at: string;
    signed_sha256: string | null;
    profile: string | null;
    integrity: CryptoIntegrity;
    trusted: boolean;
    coverage: string | null;
    modification_level: string | null;
    /** Fase 3 §3.4: `raw` (assinatura bruta) ou `cms` (pacote pronto), por componente. */
    mode?: 'raw' | 'cms' | null;
}

/**
 * `kind` da lista de assinaturas de participantes: A1 por arquivo (Fase 2 §2.12) ou feita FORA
 * da plataforma por componente local (Fase 3 §3.4, `ExternalSignatureViews`).
 */
export type ParticipantSignatureListKind =
    | 'participant_a1'
    | 'participant_a3'
    | 'participant_external'
    /** Fase 3 §3.5 (`GovBrSignatureViews`): devolução do portal, cadeia gov.br validada. */
    | 'participant_govbr'
    /** Fase 3 §3.5: devolução do portal, cadeia NÃO verificada — nunca dita gov.br. */
    | 'participant_external_unverified';

/** Prop `participant_signatures` da página de evidências (só quando há pedidos). */
export interface EvidenceParticipantSignature {
    id: string;
    kind: ParticipantSignatureListKind;
    kind_label: string;
    /** Fase 3 §3.4: `local_component` nas assinaturas feitas por componente. */
    method?: string | null;
    /** Fase 3 §3.4: `simulated` | `nexu`. */
    component?: string | null;
    /** Fase 3 §3.4: produzida pelo simulador — "simulado — nenhum token foi usado". */
    simulated?: boolean;
    recipient: { id: string | null; name: string | null };
    status: string;
    status_label: string;
    label: string;
    certificate: {
        holder_name: string | null;
        holder_cpf_masked: string | null;
        subject: string | null;
        issuer: string | null;
        issuer_cn: string | null;
        serial: string | null;
        fingerprint_sha256: string | null;
        valid_from: string | null;
        valid_to: string | null;
        is_test: boolean;
        kind_label: string;
        declares_icp_brasil_policy: boolean;
        /** Fase 3 §3.4: tipo DECLARADO pela política do certificado (ex.: `A3`). */
        declared_certificate_type?: string | null;
        icp_brasil_validated: false;
    } | null;
    consent: {
        version: string;
        consented_at: string | null;
        legal_review_required: boolean;
    } | null;
    documents: EvidenceParticipantSignatureDocument[];
    signed_at: string | null;
    window_expires_at: string | null;
    failure: { code: string; message: string | null } | null;
}

/**
 * `result.participant_signatures` da verificação pública — só assinaturas APLICADAS, com o
 * nome MASCARADO e sem CPF, série, impressão digital ou e-mail.
 */
export interface PublicParticipantSignature {
    kind: ParticipantSignatureListKind;
    kind_label: string;
    /** Fase 3 §3.4: produzida pelo simulador (nunca A3, sem valor para uso real). */
    simulated?: boolean;
    label: string;
    holder_name_masked: string;
    issuer_cn: string | null;
    valid_from: string | null;
    valid_to: string | null;
    is_test: boolean;
    certificate_label: string;
    signed_at: string | null;
    documents_count: number;
    integrity: CryptoIntegrity;
    integrity_label: string;
    chain_trust: 'trusted' | 'not_verified';
    chain_trust_label: string;
}

// ---------------------------------------------------------------------------
// Carimbo do tempo (`TimestampEvidence`)
// ---------------------------------------------------------------------------

/** `operator` = TSA da própria operadora; `simulated` = simulador (nunca de produção). */
export type TsaKind = 'operator' | 'simulated' | 'icp_brasil';

export interface EvidenceTimestampItem {
    id: string;
    purpose: string;
    purpose_label: string;
    tsa_kind: TsaKind;
    /** Rótulo único do servidor — exibido como veio. */
    label: string;
    statement: string | null;
    gen_time: string;
    serial: string | number | null;
    policy_oid: string | null;
    tsa_subject: string | null;
    tsa_cert_fingerprint: string | null;
    hash_algorithm: string | null;
    imprint: string | null;
    accuracy_ms: number | null;
    environment: string | null;
    is_test: boolean;
    test_notice: string | null;
    verification: Record<string, unknown> | null;
}

/** Prop `timestamps` da página de evidências (`TimestampEvidence::forEnvelope`). */
export interface EvidenceTimestamps {
    items: EvidenceTimestampItem[];
    notice: string;
}

/** `result.timestamps` da verificação pública (`TimestampEvidence::forPublic`). */
export interface PublicTimestamp {
    tsa_kind: TsaKind;
    label: string;
    purpose_label: string;
    gen_time: string;
    is_test: boolean;
}

// ---------------------------------------------------------------------------
// Dossiê ZIP (`DossierExports::statusProps`)
// ---------------------------------------------------------------------------

export type DossierExportStatus =
    | 'pending'
    | 'building'
    | 'ready'
    | 'failed'
    | 'expired';

export interface DossierExport {
    id: string;
    kind: 'single' | 'bulk';
    status: DossierExportStatus;
    status_label: string;
    envelope_count: number;
    size_bytes: number | null;
    sha256: string | null;
    timestamp_status:
        | 'granted'
        | 'disabled'
        | 'unavailable'
        | 'not_applicable'
        | null;
    timestamp_label: string | null;
    error: string | null;
    expires_at: string | null;
    /** URL assinada com expiração — usada exatamente como veio. */
    download_url: string | null;
    status_url: string;
}

// ---------------------------------------------------------------------------
// Verificação pública depois da exclusão por retenção (`RetentionTombstones::result`)
// ---------------------------------------------------------------------------

export interface PublicRetentionNotice {
    purged: true;
    purged_at: string | null;
    mode: string;
    message: string;
    final_hashes_count: number;
}
