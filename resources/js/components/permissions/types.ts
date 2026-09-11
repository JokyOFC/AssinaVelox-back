/**
 * Tipos da Fase 2 — funções personalizadas, times e acesso por pasta
 * (docs/fase-2/permissoes-e-times.md). Espelham as props de `members/index`.
 */
import type { Membership } from '@/types';

export type AccessLevel = 'view' | 'manage';

export const accessLevelLabels: Record<AccessLevel, string> = {
    view: 'Visualizar',
    manage: 'Gerenciar',
};

export const accessLevelDescriptions: Record<AccessLevel, string> = {
    view: 'Vê, baixa e duplica os documentos da pasta',
    manage: 'Também edita, envia, move e cancela os documentos da pasta',
};

export interface FolderOption {
    id: string;
    name: string;
}

/** Acesso concedido a uma pasta (chave pública = ulid da pasta). */
export interface FolderGrant {
    folder: string;
    name?: string;
    level: AccessLevel;
}

export interface PermissionCatalogItem {
    key: string;
    label: string;
    description: string;
    grantable: boolean;
}

export interface PermissionCatalogGroup {
    key: string;
    label: string;
    permissions: PermissionCatalogItem[];
}

export type SystemRoleKey = 'owner' | 'admin' | 'member';

export interface RoleEntry {
    /** ulid da linha em `roles` */
    id: string;
    key: SystemRoleKey | null;
    name: string;
    description: string | null;
    is_system: boolean;
    permissions: string[];
    members_count: number;
    folders: FolderGrant[];
    can: {
        update: boolean;
        delete: boolean;
        manage_folders: boolean;
        assign: boolean;
    };
}

export interface TeamEntry {
    id: string;
    name: string;
    description: string | null;
    member_ids: string[];
    folders: FolderGrant[];
    can: {
        update: boolean;
        delete: boolean;
        manage_folders: boolean;
    };
}

/** Linha de membro com os campos da Fase 2. */
export type MemberRow = Omit<Membership, 'can'> & {
    role_id?: string | null;
    teams?: { id: string; name: string }[];
    folders?: FolderGrant[];
    can: Membership['can'] & { manage_folders?: boolean };
};

/** Valor do seletor de função: key de sistema ou ulid da função personalizada. */
export function roleValue(role: RoleEntry): string {
    return role.is_system && role.key ? role.key : role.id;
}

/** Payload do PATCH/POST para o valor escolhido no seletor. */
export function rolePayload(
    value: string,
): { role: 'admin' | 'member'; role_id: null } | { role_id: string } {
    return value === 'admin' || value === 'member'
        ? { role: value, role_id: null }
        : { role_id: value };
}
