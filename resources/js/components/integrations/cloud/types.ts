/*
 * Fase 3 §3.9 (G-CONN) — tipos das telas de importação da nuvem e do app HubSpot
 * (App\Http\Controllers\Integrations\CloudImportController / HubSpotController).
 * Nenhuma prop carrega token: o do Google chega ao Picker por um POST JSON sem cache.
 */

export interface CloudProviderState {
    /** App registrado pelo proprietário (credenciais presentes). */
    available: boolean;
    /** Simulador identificado (testes/local) no lugar do provedor real. */
    simulated: boolean;
    /** Nomes das variáveis de ambiente que faltam (nunca valores). */
    missing: string[];
}

export interface GoogleProviderState extends CloudProviderState {
    authorized: boolean;
    picker: { client_id: string; api_key: string; app_id: string } | null;
}

export interface DropboxProviderState extends CloudProviderState {
    app_key: string | null;
}

export interface CloudImportCapacity {
    remaining: number;
    max_documents: number;
    replaces: boolean;
    max_bytes: number;
    max_files: number;
    extensions: string[];
}

export interface CloudImportRow {
    id: string;
    provider: 'google_drive' | 'dropbox';
    provider_label: string;
    name: string | null;
    status: 'completed' | 'rejected';
    code: string | null;
    /** Motivo da recusa em PT-BR (CloudImportRejected::describe); null se importado. */
    reason: string | null;
    simulated: boolean;
    created_at: string | null;
}

export interface CloudImportPageProps {
    envelope: { id: string; title: string; edit_url: string };
    editable: boolean;
    capacity: CloudImportCapacity;
    providers: {
        google_drive: GoogleProviderState;
        dropbox: DropboxProviderState;
    };
    recent: CloudImportRow[];
}

/** Arquivo escolhido no Dropbox Chooser, como vai para `cloud_import.dropbox.store`. */
export type PickedDropboxFile = {
    link: string;
    name: string;
    id: string | null;
    bytes: number | null;
};

export type HubSpotStatus =
    | 'awaiting_app'
    | 'disconnected'
    | 'connected'
    | 'error';

export interface HubSpotExecutionRow {
    id: string;
    created_at: string | null;
    status: string;
    status_label: string;
    error_code: string | null;
    object_type: string | null;
    object_id: string | null;
    sync_status: string;
    synced_value: string | null;
    envelope: { id: string; title: string; url: string } | null;
}

export interface HubSpotPageProps {
    status: HubSpotStatus;
    missing: string[];
    connection: {
        id: string;
        portal_id: number;
        connected_at: string | null;
        connected_by: string | null;
        scopes: string[];
        last_error: string | null;
    } | null;
    action: {
        url: string;
        status_property: string;
        max_participants: number;
    };
    templates: { id: string; name: string }[];
    executions: HubSpotExecutionRow[];
}
