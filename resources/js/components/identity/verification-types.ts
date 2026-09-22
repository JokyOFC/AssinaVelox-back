/*
 * Fase 4 §4.1 (docs/fase-4/verificacao-facial.md) — contratos da verificação facial com documento
 * por PROVEDOR EXTERNO (Verifiky). Quem compara a foto tirada na hora com a foto do documento é o
 * provedor; a plataforma envia as três fotos da captura e registra a resposta. Toda frase que a
 * tela mostra sobre o resultado vem pronta do servidor e nomeia o provedor ("Verifiky informou:
 * aprovado"): a interface repete, nunca afirma por conta própria.
 */

/** Estado de uma tentativa (`VerificationStatus`); `none` = nenhuma tentativa ainda. */
export type IdentityVerificationStatus =
    | 'none'
    | 'queued'
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'inconclusive'
    | 'expired';

/** Tipos de documento aceitos pelo provedor (`IdentityVerifications::documentTypes`). */
export type IdentityVerificationDocumentType = 'rg' | 'cnh' | 'passaporte';

/**
 * Prop `identity_verification` da página pública (`VerificationStep::props`); ausente quando não
 * se aplica. Na tela `identify` (antes do código) vem sem URLs: nada pode ser enviado ainda.
 */
export interface IdentityVerificationStep {
    required: true;
    status: IdentityVerificationStatus;
    /** "Ainda não enviada", "Em análise", "Aprovada"… já no idioma da página. */
    status_label: string;
    /** Explicação humana do estado, já localizada; `null` sem tentativa (`none`). */
    message: string | null;
    /** Explicação extra devolvida pelo adaptador — só nas inconclusivas. */
    provider_message: string | null;
    /** 'Verifiky' | 'Simulador' | 'Não configurado' — quem recebe o PRÓXIMO envio. */
    provider_label: string;
    simulated: boolean;
    document_types: {
        value: IdentityVerificationDocumentType;
        label: string;
    }[];
    /** Da última tentativa; `null` sem tentativa. */
    document_type: string | null;
    attempts_used: number;
    max_attempts: number;
    attempts_left: number;
    /** Rosto, frente e verso já existem nesta sessão. */
    captures_complete: boolean;
    /** Aprovada, mas alguma foto foi refeita depois: precisa enviar de novo. */
    captures_changed: boolean;
    /** Veredito do servidor; o botão obedece a ele. */
    can_submit: boolean;
    consent: {
        /** SHA-256 do texto de referência (PT-BR). */
        version: string;
        text: string;
        /** F-I18N: só fora do PT-BR — a tradução jurídica deste idioma já foi revisada? */
        reviewed?: boolean;
    };
    notice: string;
    /** `null` na tela de identificação (antes do código). */
    urls: { store: string | null; show: string | null };
    /** Intervalo da consulta enquanto `queued`/`pending` (3000). */
    poll_interval_ms: number;
}

/** Códigos de recusa do `POST sign.identity_verification.store`. */
export type IdentityVerificationErrorCode =
    | 'not_required'
    | 'invalid_document_type'
    | 'captures_missing'
    | 'verification_in_progress'
    | 'no_attempts_left'
    | 'consent_required';

/** Corpo do `POST`/`GET` da etapa: o bloco atualizado, com os erros quando o envio é recusado. */
export interface IdentityVerificationResponse {
    identity_verification?: IdentityVerificationStep | null;
    message?: string;
    errors?: {
        document_type?: string[];
        consent?: string[];
        verification?: string[];
    };
    /** Um de `IdentityVerificationErrorCode`; a tela usa a mensagem, o código só orienta. */
    code?: string;
}

/** Resposta do `PUT envelopes.recipients.identity_verification` (wizard). */
export interface IdentityVerificationRequirementResponse {
    recipient_id: string;
    required: boolean;
    /** Fotos que a exigência de captura passou a pedir (ligar acrescenta rosto, frente e verso). */
    capture_kinds: ('selfie' | 'document_front' | 'document_back')[];
    provider_label: string;
    message?: string;
    errors?: Record<string, string[]>;
}

/** Uma tentativa em `GET envelopes.identity_verifications.index` (`VerificationEvidence::forEnvelope`). */
export interface IdentityVerificationItem {
    id: string;
    recipient_id: string | null;
    recipient_name: string | null;
    attempt: number;
    status: Exclude<IdentityVerificationStatus, 'none'>;
    status_label: string;
    provider: string;
    provider_label: string;
    simulated: boolean;
    document_type: string | null;
    document_type_label: string | null;
    reason_code: string | null;
    /** Protocolo no provedor; `null` até o provedor responder ao envio. */
    provider_verification_id: string | null;
    /** "Verifiky informou: aprovado — …" (a mesma frase da página pública). */
    message: string | null;
    submitted_at: string;
    completed_at: string | null;
    /** "dd/mm/aaaa hh:mm" no fuso do envelope. */
    completed_at_local: string | null;
    /** Vinculada a um aceite gravado (é a que aparece no PDF de evidências). */
    attached: boolean;
    /** Só tipo e resumo SHA-256 das fotos enviadas — nunca a imagem. */
    captures: { kind: string; sha256: string }[];
}

export interface IdentityVerificationIndex {
    notice: string;
    provider_label: string;
    max_attempts: number;
    /** Por ULID do participante. */
    requirements: Record<string, true>;
    items: IdentityVerificationItem[];
}
