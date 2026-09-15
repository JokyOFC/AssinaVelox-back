<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\Sso\SsoConnectionStatus;
use App\Services\Sso\SsoProtocol;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Conexão de login corporativo da organização (Fase 3 §3.9 — docs/fase-3/sso.md §3).
 *
 * Autentica o USUÁRIO do painel, nunca o signatário de um envelope (T1). Uma por organização.
 *
 * O client secret do OIDC é guardado cifrado (cast `encrypted`) e fica fora de `toArray()`
 * (`$hidden`): nunca vai para prop, log, evento, fila ou exceção. Os certificados do IdP são
 * públicos (PEM) e ficam em JSON.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $created_by_user_id
 * @property SsoProtocol $protocol
 * @property string $name
 * @property SsoConnectionStatus $status
 * @property string|null $oidc_issuer
 * @property string|null $oidc_client_id
 * @property string|null $oidc_client_secret
 * @property string|null $oidc_id_token_alg
 * @property string|null $saml_idp_entity_id
 * @property string|null $saml_idp_sso_url
 * @property list<string>|null $saml_idp_certificates
 * @property string|null $saml_metadata_url
 * @property bool $saml_allow_idp_initiated
 * @property bool $jit_provisioning
 * @property string $jit_role
 * @property bool $enforce
 * @property string $two_factor_policy
 * @property Carbon|null $last_tested_at
 * @property string|null $last_test_status
 * @property string|null $last_test_message
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SsoConnection extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const TWO_FACTOR_KEEP = 'keep';

    public const TWO_FACTOR_TRUST_IDP = 'trust_idp';

    public const TEST_OK = 'ok';

    public const TEST_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'protocol',
        'name',
        'status',
        'oidc_issuer',
        'oidc_client_id',
        'oidc_client_secret',
        'oidc_id_token_alg',
        'saml_idp_entity_id',
        'saml_idp_sso_url',
        'saml_idp_certificates',
        'saml_metadata_url',
        'saml_allow_idp_initiated',
        'jit_provisioning',
        'jit_role',
        'enforce',
        'two_factor_policy',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'last_login_at',
    ];

    /** @var list<string> */
    protected $hidden = ['oidc_client_secret'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'saml_allow_idp_initiated' => false,
        'jit_provisioning' => false,
        'jit_role' => 'member',
        'enforce' => false,
        'two_factor_policy' => self::TWO_FACTOR_KEEP,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'protocol' => SsoProtocol::class,
            'status' => SsoConnectionStatus::class,
            'oidc_client_secret' => 'encrypted',
            'saml_idp_certificates' => 'array',
            'saml_allow_idp_initiated' => 'boolean',
            'jit_provisioning' => 'boolean',
            'enforce' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<SsoIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(SsoIdentity::class);
    }

    public function isActive(): bool
    {
        return $this->status === SsoConnectionStatus::Active;
    }

    public function isOidc(): bool
    {
        return $this->protocol === SsoProtocol::Oidc;
    }

    public function isSaml(): bool
    {
        return $this->protocol === SsoProtocol::Saml;
    }

    /**
     * A exigência de SSO só vale com a conexão ATIVA: desativar nunca tranca a organização.
     */
    public function enforcesSso(): bool
    {
        return $this->enforce && $this->isActive();
    }

    public function trustsIdpForTwoFactor(): bool
    {
        return $this->two_factor_policy === self::TWO_FACTOR_TRUST_IDP;
    }

    /**
     * Papel do provisionamento JIT: `member` por padrão, no máximo `admin`, NUNCA `owner`.
     */
    public function jitRole(): MembershipRole
    {
        return $this->jit_role === MembershipRole::Admin->value ? MembershipRole::Admin : MembershipRole::Member;
    }

    public function passedLastTest(): bool
    {
        return $this->last_test_status === self::TEST_OK && $this->last_tested_at !== null;
    }

    /**
     * @return list<string>
     */
    public function idpCertificates(): array
    {
        return array_values(array_filter((array) ($this->saml_idp_certificates ?? []), 'is_string'));
    }
}
