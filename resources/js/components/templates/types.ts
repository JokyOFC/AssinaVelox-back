import type { FieldType, ParticipantRole } from '@/types/enums';

/**
 * Tipos das telas de modelos (Fase 2 §2.1). Contrato de props em
 * docs/fase-2/modelos.md §7 — espelha `App\Services\Templates\TemplatePresenter`.
 */

export type TemplateSourceType = 'docx' | 'html' | 'pdf';

export type TemplateStatus = 'active' | 'archived';

export type VariableType =
    | 'text'
    | 'long_text'
    | 'number'
    | 'currency'
    | 'date'
    | 'cpf'
    | 'cnpj'
    | 'email'
    | 'phone'
    | 'select'
    | 'boolean';

export interface OptionItem {
    value: string;
    label: string;
}

export interface ParticipantRoleOption extends OptionItem {
    value: ParticipantRole;
    enabled: boolean;
}

export interface TemplateRow {
    id: string;
    name: string;
    description: string | null;
    category: string | null;
    source_type: TemplateSourceType;
    source_label: string;
    status: TemplateStatus;
    version: number | null;
    roles_count: number;
    variables_count: number;
    fields_count: number;
    page_count: number | null;
    uses: number;
    last_used_at: string | null;
    updated_at: string | null;
    usable: boolean;
}

export interface TemplateSummary {
    id: string;
    name: string;
    description: string | null;
    category: string | null;
    source_type: TemplateSourceType;
    source_label: string;
    status: TemplateStatus;
    supports_variables: boolean;
    supports_fields: boolean;
    requires_file: boolean;
}

export type VariableOptions = {
    max_length?: number | string | null;
    min?: number | string | null;
    max?: number | string | null;
    decimals?: number | string | null;
    min_cents?: number | null;
    max_cents?: number | null;
    choices?: string[];
};

export type TemplateVariableDraft = {
    key: string;
    label: string;
    type: VariableType;
    required: boolean;
    help_text: string | null;
    default_value: string | null;
    options: VariableOptions;
};

export type TemplateRoleDraft = {
    /** ULID do papel na versão atual, ou id temporário do editor. */
    ref: string;
    name: string;
    participant_role: ParticipantRole;
};

export type TemplateFieldOptions = {
    font_size?: number | null;
    date_format?: string | null;
    placeholder?: string | null;
    default?: boolean;
};

export type TemplateFieldDraft = {
    id?: string | null;
    /** Chave estável no cliente (o `id` do servidor muda a cada versão). */
    client_id: string;
    role_ref: string;
    type: FieldType;
    page: number;
    x: number;
    y: number;
    w: number;
    h: number;
    required: boolean;
    label: string | null;
    options: TemplateFieldOptions;
};

export interface TemplateDefinitionProps {
    html_body: string | null;
    signing_order: 'sequential' | 'parallel' | null;
    variables: TemplateVariableDraft[];
    roles: TemplateRoleDraft[];
    fields: Omit<TemplateFieldDraft, 'client_id'>[];
}

export interface TemplatePageInfo {
    page: number;
    width_pt: number;
    height_pt: number;
    rotation: number;
    box: string;
}

export interface ConversionInfo {
    required: boolean;
    available: boolean;
    message: string | null;
}

export interface TemplateVersionRow {
    id: string;
    number: number;
    created_at: string | null;
    created_by: string | null;
    uses: number;
    is_current: boolean;
    original_filename: string | null;
}

export interface TemplatePickerItem {
    id: string;
    name: string;
    category: string | null;
    source_type: TemplateSourceType;
    source_label: string;
    roles_count: number;
    variables_count: number;
}

/** Explicação curta de cada tipo, exibida no editor de variáveis. */
export const VARIABLE_TYPE_HINTS: Record<VariableType, string> = {
    text: 'Uma linha de texto (até 500 caracteres).',
    long_text: 'Vários parágrafos; as quebras de linha são mantidas.',
    number: 'Número com limites e casas decimais opcionais.',
    currency: 'Valor em reais, inserido como "R$ 1.234,56".',
    date: 'Data válida, inserida como dd/mm/aaaa.',
    cpf: 'CPF conferido pelos dígitos verificadores.',
    cnpj: 'CNPJ conferido pelos dígitos verificadores.',
    email: 'Endereço de e-mail válido.',
    phone: 'Telefone brasileiro com DDD.',
    select: 'Uma das opções que você definir.',
    boolean: 'Sim ou não.',
};

/** Chave de variável: minúsculas, números e "_", começando por letra. */
export function slugifyKey(value: string): string {
    const slug = value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 64);

    return /^[a-z]/.test(slug) ? slug : slug ? `v_${slug}`.slice(0, 64) : '';
}
