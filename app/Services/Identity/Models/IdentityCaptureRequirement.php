<?php

namespace App\Services\Identity\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * O que o remetente exige de um participante antes do aceite (`identity_capture_requirements`).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $recipient_id
 * @property list<string> $kinds
 * @property int|null $updated_by_user_id
 */
class IdentityCaptureRequirement extends Model
{
    use BelongsToOrganization;

    protected $table = 'identity_capture_requirements';

    protected $fillable = [
        'organization_id',
        'envelope_id',
        'recipient_id',
        'kinds',
        'updated_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kinds' => 'array',
        ];
    }
}
