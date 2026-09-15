/**
 * Props da tela Configurações › Login único (SSO) — contrato em docs/fase-3/sso.md §10
 * (App\Services\Sso\SsoPresenter). O client secret nunca vem: só `has_client_secret`.
 */
export type SsoProtocol = 'oidc' | 'saml';
export type SsoConnectionStatus = 'draft' | 'active' | 'disabled';
export type SsoTwoFactorPolicy = 'keep' | 'trust_idp';

export interface SsoCertificate {
    fingerprint_sha256: string;
    subject: string;
    expires_at: string | null;
    expired: boolean;
}

export interface SsoConnectionProps {
    ulid: string;
    protocol: SsoProtocol;
    protocol_label: string;
    name: string;
    status: SsoConnectionStatus;
    status_label: string;
    feature_enabled: boolean;
    oidc: {
        issuer: string | null;
        client_id: string | null;
        has_client_secret: boolean;
        id_token_alg: string;
        redirect_uri: string;
    } | null;
    saml: {
        idp_entity_id: string | null;
        idp_sso_url: string | null;
        metadata_url: string | null;
        allow_idp_initiated: boolean;
        certificates: SsoCertificate[];
        sp_entity_id: string;
        sp_acs_url: string;
        sp_metadata_url: string;
    } | null;
    jit_provisioning: boolean;
    jit_role: 'member' | 'admin';
    enforce: boolean;
    two_factor_policy: SsoTwoFactorPolicy;
    last_test: {
        status: 'ok' | 'failed' | null;
        message: string | null;
        tested_at: string | null;
    };
    last_login_at: string | null;
}

export interface SsoDomainProps {
    ulid: string;
    domain: string;
    verified: boolean;
    verified_at: string | null;
    last_checked_at: string | null;
    last_check_status: string | null;
    record_name: string;
    record_value: string;
}

export interface SettingsSsoProps {
    enabled: { oidc: boolean; saml: boolean };
    /** false até o proprietário homologar com um provedor de identidade real (classe B). */
    homologated: boolean;
    /**
     * `manage_enforce` e `manage_connection`: só o owner (provedor, 2FA, JIT, IdP-initiated,
     * ativar, obrigatoriedade). `two_factor_enabled`: 2FA da conta de quem vê a tela — o owner
     * sem ele não liga a obrigatoriedade (é o acesso de emergência).
     */
    can: {
        manage_enforce: boolean;
        manage_connection: boolean;
        two_factor_enabled: boolean;
    };
    connection: SsoConnectionProps | null;
    domains: SsoDomainProps[];
    limits: { max_domains: number; max_certificates: number };
    algorithms: string[];
}
