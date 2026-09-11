<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\Retention\RetentionCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Política de retenção da organização (Fase 2 §2.19 — docs/fase-2/retencao-e-preservacao.md).
 *
 * Um prazo em dias por categoria ({@see RetentionCategory}); null = não apagar
 * automaticamente. Só é aplicada com `is_active` E a flag `retention_policies`.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property bool $is_active
 * @property int|null $completed_days
 * @property int|null $terminal_days
 * @property int|null $draft_days
 * @property int|null $identity_capture_days
 * @property int|null $dossier_days
 * @property int|null $audit_trail_days
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RetentionPolicy extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'is_active',
        'completed_days',
        'terminal_days',
        'draft_days',
        'identity_capture_days',
        'dossier_days',
        'audit_trail_days',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'completed_days' => 'integer',
            'terminal_days' => 'integer',
            'draft_days' => 'integer',
            'identity_capture_days' => 'integer',
            'dossier_days' => 'integer',
            'audit_trail_days' => 'integer',
        ];
    }

    public function daysFor(RetentionCategory $category): ?int
    {
        $value = $this->getAttribute($category->column());

        return $value === null ? null : (int) $value;
    }

    /**
     * @return array<string, int|null>
     */
    public function periods(): array
    {
        $periods = [];

        foreach (RetentionCategory::cases() as $category) {
            $periods[$category->value] = $this->daysFor($category);
        }

        return $periods;
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
