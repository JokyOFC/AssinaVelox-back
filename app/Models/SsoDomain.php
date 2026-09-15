<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Domínio de e-mail da organização para o login corporativo (docs/fase-3/sso.md §4). Só vale
 * depois de verificado por registro TXT; verificado, pertence a uma única organização
 * (`verified_domain` UNIQUE).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $created_by_user_id
 * @property string $domain
 * @property string|null $verified_domain
 * @property string $verification_token
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_checked_at
 * @property string|null $last_check_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SsoDomain extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'domain',
        'verified_domain',
        'verification_token',
        'verified_at',
        'last_checked_at',
        'last_check_status',
    ];

    /** @var list<string> */
    protected $hidden = ['verification_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null && $this->verified_domain !== null;
    }
}
