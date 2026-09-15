<?php

namespace App\Services\Sso;

use App\Integrations\Sso\Saml\SamlCertificates;
use App\Integrations\Sso\Saml\SamlSettingsFactory;
use App\Models\Organization;
use App\Models\SsoConnection;
use App\Models\SsoDomain;
use App\Services\Sso\Domains\SsoDomainManager;
use Throwable;

/**
 * Props da tela Configurações › Login único (SSO) — contrato em docs/fase-3/sso.md §10. Nunca
 * inclui o client secret (só `has_client_secret`) nem o token de verificação fora da instrução
 * do registro TXT, que é exibida a quem administra a organização.
 */
final class SsoPresenter
{
    public function __construct(
        private readonly SamlSettingsFactory $saml,
        private readonly OidcLoginFlow $oidc,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forSettings(Organization $organization, bool $isOwner, bool $actorHasTwoFactor = false): array
    {
        $connection = SsoConnection::withoutOrganizationScope()->where('organization_id', $organization->getKey())->first();

        $domains = SsoDomain::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->orderBy('domain')
            ->get()
            ->map(fn (SsoDomain $domain): array => [
                'ulid' => $domain->ulid,
                'domain' => $domain->domain,
                'verified' => $domain->isVerified(),
                'verified_at' => $domain->verified_at?->toIso8601String(),
                'last_checked_at' => $domain->last_checked_at?->toIso8601String(),
                'last_check_status' => $domain->last_check_status,
                'record_name' => SsoDomainManager::recordName($domain),
                'record_value' => SsoDomainManager::recordValue($domain),
            ])
            ->values()
            ->all();

        return [
            'enabled' => [
                'oidc' => SsoFeature::oidc($organization),
                'saml' => SsoFeature::saml($organization),
            ],
            'homologated' => (bool) config('assinavelox.sso.homologated', false),
            // Só o owner muda o provedor, a política de 2FA, o JIT e o IdP-initiated, ativa e liga
            // a obrigatoriedade (revisão G). O 2FA de quem vê: sem ele, a obrigatoriedade não liga.
            'can' => [
                'manage_enforce' => $isOwner,
                'manage_connection' => $isOwner,
                'two_factor_enabled' => $actorHasTwoFactor,
            ],
            'connection' => $connection === null ? null : $this->connection($connection),
            'domains' => $domains,
            'limits' => [
                'max_domains' => (int) config('assinavelox.sso.domains.max_per_organization', 10),
                'max_certificates' => SamlCertificates::MAX_CERTIFICATES,
            ],
            'algorithms' => array_values((array) config('assinavelox.sso.oidc_algorithms', [])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(SsoConnection $connection): array
    {
        $certificates = [];

        foreach ($connection->idpCertificates() as $pem) {
            try {
                $certificates[] = SamlCertificates::describe($pem);
            } catch (Throwable) {
                $certificates[] = ['fingerprint_sha256' => '', 'subject' => 'Certificado ilegível', 'expires_at' => null, 'expired' => true];
            }
        }

        return [
            'ulid' => $connection->ulid,
            'protocol' => $connection->protocol->value,
            'protocol_label' => $connection->protocol->label(),
            'name' => $connection->name,
            'status' => $connection->status->value,
            'status_label' => $connection->status->label(),
            'feature_enabled' => SsoFeature::enabledFor($connection->protocol, $connection->organization),
            'oidc' => $connection->isOidc() ? [
                'issuer' => $connection->oidc_issuer,
                'client_id' => $connection->oidc_client_id,
                'has_client_secret' => $connection->oidc_client_secret !== null && $connection->oidc_client_secret !== '',
                'id_token_alg' => $connection->oidc_id_token_alg ?: 'RS256',
                'redirect_uri' => $this->oidc->redirectUri($connection),
            ] : null,
            'saml' => $connection->isSaml() ? [
                'idp_entity_id' => $connection->saml_idp_entity_id,
                'idp_sso_url' => $connection->saml_idp_sso_url,
                'metadata_url' => $connection->saml_metadata_url,
                'allow_idp_initiated' => $connection->saml_allow_idp_initiated,
                'certificates' => $certificates,
                'sp_entity_id' => $this->saml->spEntityId($connection),
                'sp_acs_url' => $this->saml->acsUrl($connection),
                'sp_metadata_url' => $this->saml->spEntityId($connection),
            ] : null,
            'jit_provisioning' => $connection->jit_provisioning,
            'jit_role' => $connection->jit_role,
            'enforce' => $connection->enforce,
            'two_factor_policy' => $connection->two_factor_policy,
            'last_test' => [
                'status' => $connection->last_test_status,
                'message' => $connection->last_test_message,
                'tested_at' => $connection->last_tested_at?->toIso8601String(),
            ],
            'last_login_at' => $connection->last_login_at?->toIso8601String(),
        ];
    }
}
