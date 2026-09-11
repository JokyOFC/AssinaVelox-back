/**
 * Tipos da retenção e da preservação (Fase 2 §2.19 — docs/fase-2/retencao-e-preservacao.md).
 * Espelham `App\Services\Retention\RetentionPresenter`.
 */

export type RetentionCategoryKey =
    | 'completed'
    | 'terminal_other'
    | 'draft'
    | 'identity_capture'
    | 'dossier'
    | 'audit_trail';

export type LegalHoldScope = 'envelope' | 'folder' | 'organization';

export interface RetentionCategory {
    key: RetentionCategoryKey;
    label: string;
    reference: string;
    deletes: string[];
    preserves: string[];
    minimum_days: number;
    available: boolean;
    unavailable_reason: string | null;
}

export interface LegalHoldRow {
    id: string;
    scope: LegalHoldScope;
    scope_label: string;
    subject: string;
    reason: string;
    active: boolean;
    starts_at: string;
    ends_at: string | null;
    released_at: string | null;
    created_by: string | null;
    released_by: string | null;
    release_reason: string | null;
    can_release: boolean;
    release_url: string;
}

export interface RetentionDeletionRow {
    id: string;
    /** Uma de {@link RetentionCategoryKey} (texto livre para recibos antigos). */
    category: string;
    category_label: string;
    /** `envelope` | `identity_captures` | `dossiers` | `audit_trail`. */
    subject_type: string;
    /** `pending` (arquivos ainda saindo) | `completed`. */
    status: string;
    /** `retention` | `manual`. */
    trigger: string;
    purged_at: string | null;
    counts: Record<string, number>;
}

export type RetentionSettingsProps =
    | { enabled: false }
    | {
          enabled: true;
          can: { configure: boolean; manage_holds: boolean };
          policy: {
              is_active: boolean;
              periods: Record<RetentionCategoryKey, number | null>;
              updated_at: string | null;
              updated_by: string | null;
          };
          categories: RetentionCategory[];
          limits: { max_days: number };
          confirmation_phrase: string;
          verification: { mode: string; label: string; description: string };
          backups: { window_days: number; text: string };
          holds: LegalHoldRow[];
          folders: { id: string; name: string }[];
          recent_deletions: RetentionDeletionRow[];
          /** Prévia com os prazos salvos (`RetentionPresenter::deletionPreview`). */
          deletion_preview: RetentionDeletionPreview;
      };

/**
 * Quantos itens a próxima execução apagaria (nada é apagado ao consultar) e quando
 * ela roda. `null` numa categoria = sem prazo definido.
 */
export interface RetentionDeletionPreview {
    counts: Record<RetentionCategoryKey, number | null>;
    held: number;
    total: number;
    organization_held: boolean;
    next_run_at: string;
    /** GET com `periods[categoria]=dias` refaz a conta com os prazos do formulário. */
    endpoint: string;
}

/**
 * Contrato do detalhe do documento (`GET envelopes.legal_hold.show`, JSON) —
 * `App\Services\Retention\RetentionPresenter::forEnvelope()`.
 */
export interface EnvelopeLegalHold {
    feature_enabled: boolean;
    preserved: boolean;
    holds: LegalHoldRow[];
    can: { place: boolean; release: boolean };
    retention: {
        category: RetentionCategoryKey | null;
        category_label: string | null;
        policy_active: boolean;
        eligible_at: string | null;
    };
    endpoints: { show: string; store: string };
}

/** "1825 dias" → "5 anos"; "400 dias" → "≈ 1,1 ano". */
export function describeDays(days: number): string {
    if (days >= 365) {
        const years = days / 365;

        if (Number.isInteger(years)) {
            return `${years} ${years === 1 ? 'ano' : 'anos'}`;
        }

        return `≈ ${years.toLocaleString('pt-BR', { maximumFractionDigits: 1 })} ${years < 2 ? 'ano' : 'anos'}`;
    }

    return `${days} ${days === 1 ? 'dia' : 'dias'}`;
}
