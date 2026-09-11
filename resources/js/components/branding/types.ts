/**
 * Tipos da marca da organização (Fase 2 §2.8 — docs/fase-2/branding.md).
 */

/**
 * Marca aplicada à página pública do signatário. Vem do backend em
 * `sender.brand` (BrandingPresenter::forSigner) e só existe com a flag
 * `branding` ligada E uma marca salva; ausente/nulo = visual da Fase 1.
 */
export interface SignerBrand {
    display_name: string;
    logo_url: string | null;
    /** `#RRGGBB`, contraste com texto branco ≥ 4,5:1 (validado no servidor). */
    primary_color: string;
    /** `#RRGGBB`, contraste com fundo branco ≥ 3:1 — só elementos gráficos. */
    accent_color: string;
    /** Cor do texto sobre a primária (sempre branco). */
    on_primary: string;
}

export interface BrandingLogo {
    url: string | null;
    width: number;
    height: number;
    bytes: number;
}

export interface BrandingLimits {
    logo_max_kb: number;
    logo_max_source_side: number;
    logo_min_side: number;
    logo_output: { width: number; height: number };
    logo_formats: string[];
    display_name_max: number;
    contrast: { primary_min: number; accent_min: number };
}
