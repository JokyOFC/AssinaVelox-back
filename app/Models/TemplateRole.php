<?php

namespace App\Models;

use App\Enums\RecipientRole;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Participante nomeado do modelo ("Locatário", "Locador", "Testemunha 1"). Ao usar o modelo
 * cada papel vira um destinatário: `name` → `recipients.role_label` e `participant_role`
 * → `recipients.role` (efeito na coleta, Fase 2 §2.4). Imutável junto com a versão.
 *
 * @property int $id
 * @property string $ulid
 * @property int $template_version_id
 * @property int $organization_id
 * @property string $name
 * @property RecipientRole $participant_role
 * @property int $position
 */
class TemplateRole extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'template_version_id',
        'organization_id',
        'name',
        'participant_role',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'participant_role' => RecipientRole::class,
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Papéis de modelo pertencem a uma versão imutável.');
        });
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }

    /** @return HasMany<TemplateField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(TemplateField::class);
    }
}
