<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ID de assertion SAML já aceita, guardado até expirar (proteção contra replay — código nosso;
 * o onelogin/php-saml não faz). docs/fase-3/sso.md §7.
 *
 * @property int $id
 * @property int $sso_connection_id
 * @property string $assertion_id_hash
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 */
class SsoConsumedAssertion extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['sso_connection_id', 'assertion_id_hash', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
