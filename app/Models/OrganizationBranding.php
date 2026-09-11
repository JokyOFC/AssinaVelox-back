<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Marca da organização (Fase 2, roadmap §2.8 — docs/fase-2/branding.md).
 *
 * Uma linha por organização. Quem decide se a marca APARECE é
 * App\Services\Branding\BrandingPresenter (flag `branding` ligada E linha existente);
 * este model só guarda o que o cliente configurou.
 *
 * @property int $id
 * @property int $organization_id
 * @property string|null $display_name
 * @property string|null $primary_color
 * @property string|null $accent_color
 * @property string|null $logo_path
 * @property string|null $logo_token
 * @property int|null $logo_width
 * @property int|null $logo_height
 * @property int|null $logo_bytes
 * @property string|null $logo_sha256
 * @property string|null $reply_to_email
 * @property string|null $sender_email
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 */
class OrganizationBranding extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'display_name',
        'primary_color',
        'accent_color',
        'logo_path',
        'logo_token',
        'logo_width',
        'logo_height',
        'logo_bytes',
        'logo_sha256',
        'reply_to_email',
        'sender_email',
        'updated_by_user_id',
    ];

    /** O caminho interno e o hash não saem em serialização acidental. */
    protected $hidden = ['logo_path', 'logo_sha256'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'logo_width' => 'integer',
            'logo_height' => 'integer',
            'logo_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function hasLogo(): bool
    {
        return is_string($this->logo_path) && $this->logo_path !== ''
            && is_string($this->logo_token) && $this->logo_token !== '';
    }
}
