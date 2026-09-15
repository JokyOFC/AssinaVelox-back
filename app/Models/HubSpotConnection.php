<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Conexão OAuth de uma organização com um portal do HubSpot (Fase 3 §3.9, G-CONN,
 * docs/fase-3/conectores.md §5).
 *
 * Os tokens são CIFRADOS em repouso (cast `encrypted`) e ficam fora de `toArray()`/JSON
 * (`$hidden`). Nunca vão para log, evento, fila, exceção ou resposta: quem precisa do access
 * token pede a App\Services\HubSpot\HubSpotConnections::accessToken(), que renova quando vence.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $portal_id
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property list<string>|null $scopes
 * @property string $status
 * @property string|null $last_error_code
 * @property int|null $connected_by_user_id
 * @property Carbon|null $connected_at
 * @property Carbon|null $last_refreshed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class HubSpotConnection extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ERROR = 'error';

    protected $table = 'hubspot_connections';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'portal_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'status',
        'last_error_code',
        'connected_by_user_id',
        'connected_at',
        'last_refreshed_at',
    ];

    /** @var list<string> */
    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'portal_id' => 'integer',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'connected_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->refresh_token !== null && $this->refresh_token !== '';
    }

    /**
     * Nunca expõe os tokens, nem em dump de depuração.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'organization_id' => $this->organization_id, 'portal_id' => $this->portal_id, 'status' => $this->status];
    }
}
