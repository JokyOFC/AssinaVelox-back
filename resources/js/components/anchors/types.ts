/**
 * Contratos JSON da detecção de campos por âncoras e OCR (Fase 3 §3.2).
 * Espelho de `App\Services\Anchors\AnchorPresenter` e `TemplateAnchorRules`
 * (docs/fase-3/ancoras-e-ocr.md §8). Nada aqui é texto do documento: só
 * geometria, tipo, o identificador do marcador (`[a-z0-9_-]`) e estados — e a
 * interface exibe tudo como texto, nunca como HTML.
 */
import type { SigningFieldType } from '@/types/enums';

export type AnchorFieldType =
    | 'signature'
    | 'initials'
    | 'name'
    | 'date'
    | 'text'
    | 'checkbox';

export type AnchorPlacement = 'below' | 'right' | 'above' | 'over';

export type OcrStatus =
    | 'not_needed'
    | 'pending'
    | 'done'
    | 'failed'
    | 'unavailable';

export interface AnchorOption {
    value: string;
    label: string;
}

export interface FieldSuggestion {
    id: string;
    document_id: string | null;
    page: number;
    type: SigningFieldType;
    type_label: string;
    /** Frações [0,1] do CropBox exibido, origem no canto superior esquerdo. */
    x: number;
    y: number;
    w: number;
    h: number;
    required: boolean;
    label: string | null;
    recipient_id: string | null;
    role_hint: string | null;
    source: 'marker' | 'rule' | 'literal';
    source_label: string;
    via: 'text' | 'ocr';
    confidence: number | null;
    /** OCR: revisão uma a uma (fora do "Confirmar todas"). */
    requires_explicit_review: boolean;
}

export interface AnchorScanSummary {
    id: string;
    trigger: 'manual' | 'template' | 'ocr';
    status: 'pending' | 'running' | 'done' | 'failed';
    status_label: string;
    pages_scanned: number;
    pages_without_text: number[];
    suggestions_count: number;
    truncated: boolean;
    engine: string | null;
    simulated: boolean;
    failure_message: string | null;
    finished_at: string | null;
}

export interface AnchorDocumentState {
    id: string;
    ocr_status: OcrStatus | null;
    ocr_status_label: string | null;
    last_scan: AnchorScanSummary | null;
    last_ocr_scan: AnchorScanSummary | null;
}

export interface AnchorState {
    enabled: boolean;
    busy: boolean;
    pending_count: number;
    ocr: {
        enabled: boolean;
        available: boolean;
        engine: string | null;
        simulated: boolean;
        message: string | null;
    };
    documents: AnchorDocumentState[];
    suggestions: FieldSuggestion[];
    limits: { max_literals: number };
    placements: AnchorOption[];
    field_types: AnchorOption[];
}

/** Campo confirmado, no formato do editor (sem os ids do cliente). */
export interface AcceptedField {
    suggestion_id: string;
    document_id: string;
    recipient_id: string;
    type: SigningFieldType;
    page: number;
    x: number;
    y: number;
    w: number;
    h: number;
    required: boolean;
    label: string | null;
    via: 'text' | 'ocr';
}

export interface DetectLiteral {
    text: string;
    field_type: AnchorFieldType;
    recipient_id: string | null;
    placement: AnchorPlacement;
}

export interface AnchorRule {
    id?: string | null;
    pattern: string;
    field_type: AnchorFieldType;
    role_position: number | null;
    role_name?: string | null;
    placement: AnchorPlacement;
    offset_x_pt: number;
    offset_y_pt: number;
    width_pt: number | null;
    height_pt: number | null;
    required: boolean;
    occurrence: 'all' | 'first';
}

export interface AnchorRulesPayload {
    rules: AnchorRule[];
    roles: { position: number; name: string; participant_role: string }[];
    field_types: AnchorOption[];
    placements: AnchorOption[];
    limits: { max_rules: number; pattern_max: number };
    can_test: boolean;
}

export interface AnchorRulesTestResult {
    pages: number;
    pages_without_text: number[];
    markers: number;
    rules: Record<string, number>;
    truncated: boolean;
}
