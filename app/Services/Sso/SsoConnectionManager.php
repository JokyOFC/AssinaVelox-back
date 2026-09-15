<?php

namespace App\Services\Sso;

use App\Enums\AuditEventType;
use App\Enums\MembershipRole;
use App\Integrations\Sso\Oidc\KnownOidcProviders;
use App\Integrations\Sso\Saml\SamlCertificates;
use App\Integrations\Sso\SsoHttpClient;
use App\Models\Organization;
use App\Models\SsoConnection;
use App\Models\SsoIdentity;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Cadastro da conexão de login corporativo (docs/fase-3/sso.md §3).
 *
 * Regras:
 *  - uma conexão por organização; o protocolo precisa estar ligado (flag global E plano);
 *  - URL do provedor passa pela proteção contra SSRF já no cadastro (e de novo a cada uso);
 *  - trocar qualquer dado do provedor (emissor, cliente, segredo, algoritmo, entityID, URL,
 *    certificados) volta a conexão para "em configuração", desliga a obrigatoriedade e zera o
 *    teste — nada autentica com configuração não testada;
 *  - SÓ O OWNER muda os dados do provedor, a política de 2FA, o papel do JIT e o login iniciado
 *    pelo provedor, e só ele ativa a conexão e liga/desliga o SSO obrigatório (quem controla
 *    esses campos controla quem entra, inclusive na conta do owner — revisão adversarial G);
 *  - emissor ou entityID novo INVALIDA os vínculos pelo sujeito do IdP antigo (a linha fica,
 *    mas não casa mais; o próximo login refaz o vínculo pelo e-mail) e registra quantos;
 *  - ativar exige o último teste bem-sucedido; SSO obrigatório exige a conexão ativa e o 2FA do
 *    owner que liga; o JIT cria `member` ou `admin`, NUNCA `owner`;
 *  - desativar ou remover nunca tranca a organização (a exigência só vale com a conexão ativa,
 *    e owners mantêm o acesso de emergência) — por isso o admin também pode fazê-lo.
 */
final class SsoConnectionManager
{
    /** Campos do provedor: mudar qualquer um exige novo teste. */
    private const PROVIDER_FIELDS = [
        'oidc_issuer', 'oidc_client_id', 'oidc_client_secret', 'oidc_id_token_alg',
        'saml_idp_entity_id', 'saml_idp_sso_url', 'saml_idp_certificates',
    ];

    /** Decisões do owner: o provedor inteiro e o que muda quem entra e como. */
    private const OWNER_ONLY_FIELDS = [
        'oidc_issuer', 'oidc_client_id', 'oidc_client_secret', 'oidc_id_token_alg',
        'saml_idp_entity_id', 'saml_idp_sso_url', 'saml_idp_certificates',
        'two_factor_policy', 'jit_role', 'saml_allow_idp_initiated',
    ];

    public const OWNER_ONLY_MESSAGE = 'Só o proprietário da conta muda o provedor de identidade, a autenticação em duas etapas de quem entra pelo SSO, o papel dado no primeiro acesso e o login iniciado pelo provedor.';

    public function __construct(private readonly SsoHttpClient $http) {}

    /**
     * @param  array<string, mixed>  $data  já validado no formato pelo controller
     *
     * @throws SsoFailure
     */
    public function create(Organization $organization, array $data, User $actor): SsoConnection
    {
        $protocol = SsoProtocol::from((string) $data['protocol']);

        if (! SsoFeature::enabledFor($protocol, $organization)) {
            throw new SsoFailure('protocol_disabled', 'Este protocolo não está disponível no plano desta organização.', 'protocol');
        }

        if (SsoConnection::withoutOrganizationScope()->where('organization_id', $organization->getKey())->exists()) {
            throw new SsoFailure('connection_exists', 'Esta organização já tem uma conexão de login corporativo.', 'protocol');
        }

        $attributes = [
            'organization_id' => $organization->getKey(),
            'created_by_user_id' => $actor->getKey(),
            'protocol' => $protocol,
            'status' => SsoConnectionStatus::Draft,
            ...$this->commonAttributes($data),
            ...$this->providerAttributes($protocol, $data, null),
        ];

        $connection = SsoConnection::withoutOrganizationScope()->create($attributes);

        SsoTrail::record((int) $organization->getKey(), AuditEventType::SsoConnectionCreated, SsoTrail::connectionPayload($connection, [
            'jit_provisioning' => $connection->jit_provisioning,
            'jit_role' => $connection->jit_role,
            'two_factor_policy' => $connection->two_factor_policy,
        ]), $actor);

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws SsoFailure
     */
    public function update(SsoConnection $connection, array $data, User $actor, bool $actorIsOwner): SsoConnection
    {
        $attributes = [
            ...$this->commonAttributes($data),
            ...$this->providerAttributes($connection->protocol, $data, $connection),
        ];

        $connection->fill($attributes);
        $dirty = array_keys($connection->getDirty());
        $providerChanged = array_values(array_intersect(self::PROVIDER_FIELDS, $dirty));
        $ownerOnly = array_values(array_intersect(self::OWNER_ONLY_FIELDS, $dirty));

        if ($ownerOnly !== [] && ! $actorIsOwner) {
            // Quem controla o provedor, a política de 2FA, o papel do JIT ou o IdP-initiated
            // controla quem entra — inclusive na conta do owner. Decisão só do owner (revisão G).
            throw new SsoFailure('owner_only', self::OWNER_ONLY_MESSAGE, $ownerOnly[0]);
        }

        // Emissor (OIDC) ou entityID (SAML) novo = outro IdP: os vínculos pelo sujeito do IdP
        // antigo não valem mais (um sujeito igual no IdP novo seria outra pessoa).
        $identityScopeChanged = in_array('oidc_issuer', $dirty, true) || in_array('saml_idp_entity_id', $dirty, true);

        if ($providerChanged !== []) {
            $connection->forceFill([
                'status' => SsoConnectionStatus::Draft,
                'enforce' => false,
                'last_test_status' => null,
                'last_test_message' => null,
                'last_tested_at' => null,
            ]);
        }

        if (array_key_exists('enforce', $data)) {
            $enforce = (bool) $data['enforce'];

            if ($enforce !== $connection->getOriginal('enforce') && $providerChanged === []) {
                if (! $actorIsOwner) {
                    throw new SsoFailure('enforce_owner_only', 'Só o proprietário da conta liga ou desliga o login corporativo obrigatório.', 'enforce');
                }

                if ($enforce && ! $connection->isActive()) {
                    throw new SsoFailure('enforce_requires_active', 'Ative a conexão (depois de um teste bem-sucedido) antes de tornar o login corporativo obrigatório.', 'enforce');
                }

                if ($enforce && ! $actor->hasTwoFactorEnabled()) {
                    // O acesso de emergência do owner é senha + 2FA: sem o 2FA, ligar a chave
                    // tiraria dele o acesso por senha na tela seguinte, sem aviso.
                    throw new SsoFailure('enforce_requires_two_factor', 'Ative a autenticação em duas etapas na sua conta antes: com o login corporativo obrigatório, ela é o seu acesso de emergência por senha.', 'enforce');
                }

                $connection->enforce = $enforce;
            }
        }

        $changed = array_keys($connection->getDirty());
        $connection->save();

        $invalidatedIdentities = 0;

        if ($identityScopeChanged) {
            // Invalidados (não apagados): a linha fica, mas o sujeito do IdP antigo não casa mais
            // com ninguém — um sujeito igual no IdP novo é outra pessoa (revisão adversarial G).
            SsoIdentity::query()
                ->where('sso_connection_id', $connection->getKey())
                ->where('subject_hash', 'not like', SsoIdentity::INVALIDATED_PREFIX.'%')
                ->get()
                ->each(function (SsoIdentity $identity) use (&$invalidatedIdentities): void {
                    $identity->forceFill(['subject_hash' => SsoIdentity::invalidatedHash((int) $identity->getKey())])->save();
                    $invalidatedIdentities++;
                });
        }

        if ($changed !== []) {
            SsoTrail::record((int) $connection->organization_id, AuditEventType::SsoConnectionUpdated, SsoTrail::connectionPayload($connection, [
                // Só os NOMES dos campos alterados; o segredo aparece como "oidc_client_secret".
                'fields' => array_values(array_diff($changed, ['updated_at'])),
                'status' => $connection->status->value,
                'enforce' => $connection->enforce,
                'retest_required' => $providerChanged !== [],
                ...($identityScopeChanged ? ['identities_invalidated' => $invalidatedIdentities] : []),
            ]), $actor);
        }

        return $connection;
    }

    /**
     * @throws SsoFailure
     */
    public function setStatus(SsoConnection $connection, SsoConnectionStatus $status, User $actor, bool $actorIsOwner): SsoConnection
    {
        if ($status === SsoConnectionStatus::Active) {
            if (! $actorIsOwner) {
                // Ativar = o provedor passa a autenticar a equipe (e pode vincular a conta do
                // owner): decisão do owner. Desativar continua liberado ao admin (nunca tranca).
                throw new SsoFailure('activation_owner_only', 'Só o proprietário da conta ativa o login corporativo.');
            }

            if (! SsoFeature::enabledFor($connection->protocol, $connection->organization)) {
                throw new SsoFailure('protocol_disabled', 'Este protocolo não está disponível no plano desta organização.');
            }

            if (! $connection->passedLastTest()) {
                throw new SsoFailure('activation_requires_test', 'Teste a conexão com sucesso antes de ativá-la.');
            }
        }

        if ($status !== SsoConnectionStatus::Active && $connection->enforce && ! $actorIsOwner) {
            // Desligar nunca tranca: mesmo o admin pode desativar; a obrigatoriedade cai junto.
            $connection->enforce = false;
        }

        $connection->forceFill([
            'status' => $status,
            'enforce' => $status === SsoConnectionStatus::Active ? $connection->enforce : false,
        ])->save();

        SsoTrail::record((int) $connection->organization_id, AuditEventType::SsoConnectionUpdated, SsoTrail::connectionPayload($connection, [
            'fields' => ['status'],
            'status' => $status->value,
            'enforce' => $connection->enforce,
        ]), $actor);

        return $connection;
    }

    public function delete(SsoConnection $connection, User $actor): void
    {
        $organizationId = (int) $connection->organization_id;
        $payload = SsoTrail::connectionPayload($connection);
        $connection->delete();

        SsoTrail::record($organizationId, AuditEventType::SsoConnectionDeleted, $payload, $actor);
    }

    public function recordTest(SsoConnection $connection, bool $ok, string $message, ?User $actor, ?string $reason = null): void
    {
        $connection->forceFill([
            'last_tested_at' => Carbon::now(),
            'last_test_status' => $ok ? SsoConnection::TEST_OK : SsoConnection::TEST_FAILED,
            'last_test_message' => mb_substr($message, 0, 255),
        ])->save();

        SsoTrail::record((int) $connection->organization_id, AuditEventType::SsoConnectionTested, SsoTrail::connectionPayload($connection, [
            'result' => $ok ? 'ok' : 'failed',
            'reason' => $reason,
        ]), $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function commonAttributes(array $data): array
    {
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = mb_substr(trim((string) $data['name']), 0, 120);
        }

        if (array_key_exists('jit_provisioning', $data)) {
            $attributes['jit_provisioning'] = (bool) $data['jit_provisioning'];
        }

        if (array_key_exists('jit_role', $data)) {
            // NUNCA owner: qualquer valor fora de admin vira member.
            $attributes['jit_role'] = $data['jit_role'] === MembershipRole::Admin->value ? MembershipRole::Admin->value : MembershipRole::Member->value;
        }

        if (array_key_exists('two_factor_policy', $data)) {
            $attributes['two_factor_policy'] = $data['two_factor_policy'] === SsoConnection::TWO_FACTOR_TRUST_IDP
                ? SsoConnection::TWO_FACTOR_TRUST_IDP
                : SsoConnection::TWO_FACTOR_KEEP;
        }

        if (array_key_exists('saml_allow_idp_initiated', $data)) {
            $attributes['saml_allow_idp_initiated'] = (bool) $data['saml_allow_idp_initiated'];
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws SsoFailure
     */
    private function providerAttributes(SsoProtocol $protocol, array $data, ?SsoConnection $existing): array
    {
        if ($protocol === SsoProtocol::Oidc) {
            $attributes = [];

            if (array_key_exists('oidc_issuer', $data)) {
                $issuer = trim((string) $data['oidc_issuer']);
                $this->assertIssuer($issuer);
                $attributes['oidc_issuer'] = $issuer;
            }

            if (array_key_exists('oidc_client_id', $data)) {
                $attributes['oidc_client_id'] = trim((string) $data['oidc_client_id']);
            }

            $secret = $data['oidc_client_secret'] ?? null;

            if (is_string($secret) && $secret !== '') {
                $attributes['oidc_client_secret'] = $secret;
            } elseif ($existing === null) {
                throw new SsoFailure('client_secret_required', 'Informe o client secret.', 'oidc_client_secret');
            }

            if (array_key_exists('oidc_id_token_alg', $data)) {
                $alg = (string) $data['oidc_id_token_alg'];

                if (! in_array($alg, (array) config('assinavelox.sso.oidc_algorithms', []), true)) {
                    throw new SsoFailure('alg_not_allowed', 'Algoritmo não permitido.', 'oidc_id_token_alg');
                }

                $attributes['oidc_id_token_alg'] = $alg;
            }

            return $attributes;
        }

        $attributes = [];

        if (array_key_exists('saml_idp_entity_id', $data)) {
            $attributes['saml_idp_entity_id'] = trim((string) $data['saml_idp_entity_id']);
        }

        if (array_key_exists('saml_idp_sso_url', $data)) {
            $url = trim((string) $data['saml_idp_sso_url']);
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $httpAllowed = ! app()->environment('production') && (bool) config('assinavelox.webhooks.allow_http', false);

            if (parse_url($url, PHP_URL_HOST) === null || ($scheme !== 'https' && ! ($scheme === 'http' && $httpAllowed))) {
                throw new SsoFailure('saml_sso_url_invalid', 'Use o endereço https:// de login (SSO) do provedor.', 'saml_idp_sso_url');
            }

            $attributes['saml_idp_sso_url'] = $url;
        }

        if (array_key_exists('saml_idp_certificates', $data) && is_string($data['saml_idp_certificates']) && trim($data['saml_idp_certificates']) !== '') {
            try {
                $attributes['saml_idp_certificates'] = SamlCertificates::normalize($data['saml_idp_certificates']);
            } catch (SsoFailure $failure) {
                throw $failure->forField('saml_idp_certificates');
            }
        } elseif ($existing === null || $existing->idpCertificates() === []) {
            throw new SsoFailure('saml_certificate_required', 'Informe o certificado de assinatura do provedor.', 'saml_idp_certificates');
        }

        if (array_key_exists('saml_metadata_url', $data)) {
            $metadataUrl = trim((string) ($data['saml_metadata_url'] ?? ''));
            $attributes['saml_metadata_url'] = $metadataUrl === '' ? null : $metadataUrl;
        }

        return $attributes;
    }

    /**
     * O emissor é conferido pela proteção contra SSRF já no cadastro: host público, https,
     * sem credenciais na URL (a busca do discovery é refeita — e reconferida — a cada uso).
     *
     * @throws SsoFailure
     */
    private function assertIssuer(string $issuer): void
    {
        if ($issuer === '' || strlen($issuer) > 500 || parse_url($issuer, PHP_URL_QUERY) !== null || parse_url($issuer, PHP_URL_FRAGMENT) !== null) {
            throw new SsoFailure('issuer_invalid', 'Informe o emissor (issuer) do provedor, como https://login.suaempresa.com.br.', 'oidc_issuer');
        }

        try {
            $this->http->assertAllowed(rtrim($issuer, '/').'/.well-known/openid-configuration', KnownOidcProviders::allowedHostsFor($issuer));
        } catch (SsoFailure $failure) {
            throw $failure->forField('oidc_issuer');
        }
    }
}
