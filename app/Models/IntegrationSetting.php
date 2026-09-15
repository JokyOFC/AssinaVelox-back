<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Configurações de integração da organização (Fase 3 §3.9 — docs/fase-3/widget-embutido.md §4).
 *
 * Uma linha por organização. Hoje só `allowed_origins` (origens exatas que podem hospedar o
 * widget de assinatura embutida); a normalização e as regras ficam em
 * App\Services\Embed\AllowedOrigins — este modelo não valida nada sozinho.
 *
 * @property int $id
 * @property int $organization_id
 * @property list<string>|null $allowed_origins
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class IntegrationSetting extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'allowed_origins',
        'updated_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
        ];
    }
}
