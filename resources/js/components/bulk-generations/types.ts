/**
 * Tipos das telas de geração em lote (Fase 3 §3.1 — docs/fase-3/geracao-em-lote.md).
 * Espelham App\Services\BulkGeneration\BulkGenerationPresenter.
 */

export type BulkStatus =
    | 'draft'
    | 'validated'
    | 'running'
    | 'completed'
    | 'canceled';

export type BulkRowStatus =
    | 'valid'
    | 'invalid'
    | 'pending'
    | 'queued'
    | 'processing'
    | 'created'
    | 'failed'
    | 'canceled';

export type BulkRowOutcome =
    | 'ready'
    | 'draft'
    | 'sent'
    | 'scheduled'
    | 'not_sent';

export type BulkMode = 'review' | 'send' | 'schedule';

export interface BulkSummary {
    id: string;
    status: BulkStatus;
    status_label: string;
    template: { id: string; name: string } | null;
    source_filename: string;
    row_count: number;
    valid_count: number;
    invalid_count: number;
    created_count: number;
    failed_count: number;
    canceled_count: number;
    processed: number;
    created_by: string | null;
    created_at: string | null;
    confirmed_at: string | null;
    finished_at: string | null;
}

export interface BulkDetail extends BulkSummary {
    source_format: 'csv' | 'xlsx';
    dry_run_at: string | null;
    canceled_at: string | null;
    mode: BulkMode | null;
    mode_label: string | null;
    scheduled_for_label: string | null;
    reserved: number;
    /** Lote não confirmado é descartado sozinho depois deste prazo sem alteração. */
    discard_after_days?: number;
}

export interface BulkTarget {
    value: string;
    label: string;
    /** 'Documento' | 'Participantes' | 'Variáveis' */
    group: string;
    required: boolean;
    kind: 'title' | 'participant' | 'variable';
    type: string | null;
}

export interface BulkHeader {
    column: number;
    label: string;
    letter: string;
}

export interface BulkRowError {
    column: number | null;
    header: string | null;
    message: string;
}

export interface BulkRow {
    line: number;
    status: BulkRowStatus;
    status_label: string;
    errors: BulkRowError[];
    error: string | null;
    outcome: BulkRowOutcome | null;
    outcome_label: string | null;
    outcome_message: string | null;
    envelope: {
        id: string;
        title: string;
        display_code: string;
        draft: boolean;
        status_label: string;
    } | null;
}

export interface BulkCounts {
    rows: number;
    valid: number;
    invalid: number;
    pending: number;
    created: number;
    failed: number;
    canceled: number;
    sent: number;
    scheduled: number;
    ready: number;
    draft: number;
    not_sent: number;
    processed: number;
}

export interface DirectSend {
    available: boolean;
    reason: string | null;
}

export interface BulkLimits {
    max_rows: number;
    max_file_bytes: number;
    max_concurrent_batches: number;
    max_columns: number;
    max_cell_chars: number;
    max_file_label: string;
}
