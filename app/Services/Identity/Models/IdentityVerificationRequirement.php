<?php

namespace App\Services\Identity\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Verificação facial com documento exigida de um participante antes do aceite
 * (`identity_verification_requirements`, Fase 4 §4.1, docs/fase-4/verificacao-facial.md).
 * Uma linha por destinatário; a existência da linha É a exigência.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $recipient_id
 * @property int|null $created_by_user_id
 */
class IdentityVerificationRequirement extends Model
{
    use BelongsToOrganization;

    protected $table = 'identity_verification_requirements';

    protected $fillable = [
        'organization_id',
        'envelope_id',
        'recipient_id',
        'created_by_user_id',
    ];
}
