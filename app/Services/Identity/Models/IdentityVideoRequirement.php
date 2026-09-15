<?php

namespace App\Services\Identity\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Vídeo curto exigido de um participante antes do aceite (`identity_video_requirements`,
 * Fase 3 §3.3, docs/fase-3/captura-de-video.md). Uma linha por destinatário.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $recipient_id
 * @property int|null $max_seconds
 * @property int|null $updated_by_user_id
 */
class IdentityVideoRequirement extends Model
{
    use BelongsToOrganization;

    protected $table = 'identity_video_requirements';

    protected $fillable = [
        'organization_id',
        'envelope_id',
        'recipient_id',
        'max_seconds',
        'updated_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_seconds' => 'integer',
        ];
    }
}
