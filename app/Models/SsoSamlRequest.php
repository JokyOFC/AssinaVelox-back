<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * AuthnRequest SAML emitido e ainda não respondido (docs/fase-3/sso.md §7). Uso único.
 *
 * @property int $id
 * @property int $sso_connection_id
 * @property string $request_id_hash
 * @property string $browser_binding_hash
 * @property string $mode
 * @property int|null $initiated_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 */
class SsoSamlRequest extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'sso_connection_id',
        'request_id_hash',
        'browser_binding_hash',
        'mode',
        'initiated_by_user_id',
        'expires_at',
        'consumed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public static function hashRequestId(string $requestId): string
    {
        return hash('sha256', 'saml-request|'.$requestId);
    }
}
