/**
 * Fase 3, parte 1 (onda E) — espelhos dos contratos do backend para a página pública, as
 * evidências e a verificação:
 *
 * - `docs/fase-3/assinatura-externa-a3.md` §7 — assinatura por componente local
 *   (`sign.external.*`, flag `a3_signing`);
 * - `docs/fase-3/gov-br.md` §6 — PDF assinado no portal gov.br e devolvido
 *   (`sign.govbr.*`, flag `govbr_return`);
 * - `docs/fase-3/longo-prazo.md` §8 — estado técnico de longo prazo e histórico de resumos.
 *
 * Os rótulos (`label`, `kind_label`, `status_label`, `*_label`) chegam prontos do servidor e
 * são exibidos como vieram (roadmap T1): é a redação do servidor que diz "simulado — nenhum
 * token foi usado", "cadeia não verificada" e "Assinatura gov.br (avançada)" só quando
 * validada contra âncoras.
 */

// ---------------------------------------------------------------------------
// Assinatura por componente local (A3) — `sign.external.*`
// ---------------------------------------------------------------------------

export type LocalSignerComponentName = 'simulated' | 'nexu';

export type ExternalSigningStage =
    | 'choose'
    | 'awaiting_others'
    | 'ready_to_sign'
    | 'applied'
    | 'expired'
    | 'withdrawn'
    | 'closed'
    | 'other_method';

export type ExternalSignatureMode = 'raw' | 'cms';

export type ExternalSignatureKindValue =
    | 'participant_a3'
    | 'participant_external';

export type ExternalDocumentStatus =
    | 'to_sign'
    | 'reserved'
    | 'busy'
    | 'waiting_base'
    | 'signed';

/** Fatos públicos do certificado anunciado na preparação (CPF só mascarado). */
export interface ExternalCertificateFacts {
    holder_name: string | null;
    holder_cpf_masked: string | null;
    issuer_cn: string | null;
    serial: string | null;
    fingerprint_sha256: string | null;
    valid_from: string | null;
    valid_to: string | null;
    is_test: boolean;
    kind_label: string;
}

export interface ExternalChainState {
    trusted: boolean;
    label: string;
    revocation: 'not_checked';
    revocation_label: string;
}

/**
 * Reserva ativa (`pendingProps`). O `digest` só vem na resposta de `prepare` — nas demais
 * respostas é `null`. Ele nunca é guardado fora da memória do componente.
 */
export interface ExternalPending {
    id: string;
    document_id: string;
    mode: ExternalSignatureMode;
    component: string;
    simulated: boolean;
    hash_function: 'SHA256';
    digest: string | null;
    digest_kind: 'signed_attributes' | 'document';
    expires_at: string;
    certificate: ExternalCertificateFacts;
    chain: ExternalChainState;
    endpoints: { submit: string | null; simulate: string | null };
}

export interface ExternalSigningRequest {
    id: string;
    status: string;
    status_label: string;
    method: 'local_component';
    component: LocalSignerComponentName | null;
    signature_status: ExternalSignatureKindValue | null;
    kind_label: string | null;
    /** Com "simulado — nenhum token foi usado" quando for o caso. */
    label: string | null;
    documents_signed: number;
    applied_at: string | null;
    window_expires_at: string | null;
    failure: { code: string; message: string | null } | null;
}

export interface ExternalSigningDocument {
    id: string;
    name: string | null;
    position: number;
    status: ExternalDocumentStatus;
    signed_at: string | null;
    retry_after: string | null;
    pending: ExternalPending | null;
}

export interface LocalComponentStatus {
    component: string;
    label: string;
    available: boolean;
    simulated: boolean;
    production_enabled: boolean;
    version: string | null;
    reason: string | null;
}

export interface LocalComponentProtocol {
    component: 'nexu';
    production_enabled: boolean;
    http_base: string;
    https_base: string;
    minimum_version: string;
    endpoints: {
        status: { method: 'GET'; path: string };
        signing_certificate: { method: 'POST'; path: string };
        sign: {
            method: 'POST';
            path: string;
            hash_function: 'SHA256';
            mode: 'raw';
        };
    };
    browser_notes: string[];
    missing_for_production: string[];
}

/** Estado do passo (`GET sign.external.show` e respostas de intent/withdraw/submit/simulador). */
export interface ExternalSigningState {
    available: true;
    method: 'local_component';
    authenticated: boolean;
    stage: ExternalSigningStage;
    ready: boolean;
    message: string | null;
    can_request: boolean;
    can_withdraw: boolean;
    can_prepare: boolean;
    request: ExternalSigningRequest | null;
    documents: ExternalSigningDocument[];
    components: LocalComponentStatus[];
    local_component: LocalComponentProtocol;
    chain: {
        anchors_pinned: number;
        label: string;
        revocation: 'not_checked';
        revocation_label: string;
    };
    limits: {
        pending_ttl_minutes: number;
        hash_function: 'SHA256';
        modes: ExternalSignatureMode[];
        max_signature_bytes: number;
        max_certificate_kb: number;
        max_chain_certificates: number;
        max_cms_kb: number;
    };
    notices: string[];
    endpoints: {
        show: string;
        intent: string;
        withdraw: string;
        prepare: string;
        submit: string;
        simulator_certificate: string | null;
        simulator_sign: string | null;
    };
}

/** Resposta de `POST sign.external.prepare` (201). */
export interface ExternalPrepareResponse {
    pending: ExternalPending;
    state: ExternalSigningState;
}

/**
 * `GET sign.external.simulator.certificate` — só teste/local. Certificado público do
 * simulador; `key_handle` é opaco e NÃO é enviado de volta ao servidor.
 */
export interface SimulatorCertificateResponse {
    certificate: string;
    certificate_chain: string[];
    encryption_algorithm: string | null;
    key_handle: unknown;
    supported_digests: string[];
    preferred_digest: string | null;
    fingerprint_sha256: string | null;
    component: 'simulated';
    simulated: true;
    label: string;
}

export interface SignerJsonError {
    message: string;
    code?: string;
    errors?: Partial<Record<string, string[]>>;
    retry_after?: string | number | null;
    reason?: string | null;
}

// ---------------------------------------------------------------------------
// gov.br — PDF assinado no portal e devolvido (`sign.govbr.*`)
// ---------------------------------------------------------------------------

export type GovBrStage =
    | 'choose'
    | 'awaiting_others'
    | 'ready_to_reserve'
    | 'reserved'
    | 'completed'
    | 'expired'
    | 'closed';

export type GovBrRequestStatus =
    | 'requested'
    | 'pending'
    | 'completed'
    | 'expired'
    | 'withdrawn'
    | 'closed';

export type GovBrSignatureKindValue =
    | 'participant_govbr'
    | 'participant_external_unverified';

export interface GovBrReturnSignature {
    /** Um de {@link GovBrSignatureKindValue} (texto livre para valores futuros). */
    kind: string;
    /** "Assinatura gov.br (avançada)" só com âncora; senão "…cadeia não verificada". */
    label: string;
    description: string;
    holder_name: string | null;
    holder_cpf_masked: string | null;
    issuer: string | null;
    fingerprint_sha256: string | null;
    valid_from: string | null;
    valid_to: string | null;
    trusted: boolean;
    is_test: boolean;
    signed_at: string | null;
}

export interface GovBrReturnRequest {
    id: string;
    status: GovBrRequestStatus;
    status_label: string;
    expected_revision: null | {
        sha256: string;
        size_bytes: number;
        download_url: string | null;
    };
    upload_url: string | null;
    reserved_at: string | null;
    expires_at: string | null;
    window_expires_at: string | null;
    completed_at: string | null;
    attempts: number;
    failure: { code: string; message: string | null } | null;
    last_return: null | {
        outcome: 'accepted' | 'rejected';
        rejection_code: string | null;
        received_sha256: string;
        received_at: string | null;
    };
    signature: GovBrReturnSignature | null;
}

export interface GovBrReturnState {
    available: true;
    authenticated: boolean;
    stage: GovBrStage;
    ready: boolean;
    message: string | null;
    can_request: boolean;
    can_withdraw: boolean;
    can_reserve: boolean;
    can_upload: boolean;
    trust: {
        anchors_configured: boolean;
        accepted_kind: GovBrSignatureKindValue;
        accepted_label: string;
        revocation_checked: false;
    };
    documents: {
        document: { id: string; name: string; position: number };
        request: GovBrReturnRequest | null;
    }[];
    instructions: string[];
    limits: {
        max_upload_mb: number;
        accepted_extensions: string[];
        reservation_ttl_minutes: number;
        application_window_minutes: number;
    };
    notices: string[];
    portal_url: string;
    endpoints: {
        show: string;
        intent: string;
        withdraw: string;
        reserve: string;
    };
}

// ---------------------------------------------------------------------------
// Longo prazo (estado TÉCNICO, não o perfil anunciado) e histórico de resumos
// ---------------------------------------------------------------------------

export type LtvStatusValue = 'not_applicable' | 'b_t' | 'b_lt' | 'b_lta';

/**
 * `LtvState::view()` — visão INTERNA (evidências). `level` existe no contrato, mas a interface
 * não o exibe: o perfil anunciado é `announced_profile` (hoje sempre PAdES-B-B, T2).
 */
export interface LtvTechnicalState {
    status: LtvStatusValue;
    label: string;
    level: string | null;
    /** Há camada de arquivamento da operadora a renovar (independe do nível do arquivo). */
    archive_layer?: boolean;
    last_timestamp_at: string | null;
    revocation_embedded: boolean;
    archive_expires_at: string | null;
    next_refresh_at: string | null;
    checked_at: string | null;
    /** Hoje sempre `operator` (TSA da operadora). */
    tsa_kind: string;
    announced: boolean;
    announced_profile: string | null;
    notice: string;
}

/** `VerificationHashHistory::publicProps()` — chaves aditivas, ausentes com um só resumo. */
export interface HashHistoryEntry {
    position: number;
    sha256: string;
    current: boolean;
    valid_from: string | null;
    superseded_at: string | null;
    /** `finalized` | `ltv_refresh` (texto livre para valores futuros). */
    reason: string;
    reason_label: string;
}
