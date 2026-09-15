/*
 * Fase 3 §3.3 (F-VIDEO, docs/fase-3/captura-de-video.md) — contratos do vídeo curto do aceite.
 * O vídeo é captura enviada pelo participante: não verifica identidade.
 */

/** Prop `identity_video` da página pública (`VideoStep::props`); ausente quando não se aplica. */
export interface IdentityVideoStep {
    required: true;
    complete: boolean;
    title: string;
    label: string;
    instructions: string;
    /** Para que serve. */
    purpose: string;
    /** Quem vê e onde não aparece. */
    audience: string;
    notice: string;
    /** Texto do consentimento, marcado antes de ligar a câmera. */
    consent_label: string;
    consent_version: string;
    /** Alternativa quando o navegador não grava ou a câmera é negada. */
    fallback: string;
    facing_mode: 'user' | 'environment';
    /** Sempre `false`: gravado sem som, a página nunca pede o microfone. */
    audio: false;
    max_seconds: number;
    max_upload_kb: number;
    video_bits_per_second: number;
    accept: string[];
    retention_days: number;
    captured: boolean;
    captured_at: string | null;
    duration_ms: number | null;
    size_bytes: number | null;
    /** POST multipart `video` + `consent` + `source` + `duration_ms`. `null` antes do código. */
    upload_url: string | null;
}

/** Item de `GET envelopes.identity_videos.index` (`VideoEvidence::forEnvelope`). */
export interface IdentityVideoItem {
    id: string;
    recipient_id: string | null;
    recipient_name: string | null;
    kind: 'video';
    kind_label: string;
    /** "Vídeo enviado pelo participante — origem informada pelo navegador: …" */
    label: string;
    /** Origem DECLARADA pelo navegador; não verificada. */
    source: 'camera' | 'upload' | null;
    source_label: string;
    container: 'webm' | 'matroska' | 'mp4' | null;
    container_label: string;
    mime_type: string;
    duration_ms: number | null;
    declared_duration_ms: number | null;
    size_bytes: number;
    width: number | null;
    height: number | null;
    sha256: string;
    captured_at: string;
    consented_at: string | null;
    available: boolean;
    purged_at: string | null;
    /** URL assinada e curta (reprodução); `null` depois da retenção. */
    play_url: string | null;
    download_url: string | null;
    expires_at: string | null;
}

export interface IdentityVideoIndex {
    notice: string;
    items: IdentityVideoItem[];
    /** Por ULID do participante. */
    requirements: Record<string, { max_seconds: number }>;
    limits: {
        default_seconds: number;
        ceiling_seconds: number;
        max_upload_kb: number;
    };
}
