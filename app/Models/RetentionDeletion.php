<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Recibo de exclusão (Fase 2 §2.19; o `deletion_receipts` do roadmap) e, para envelopes já
 * publicados, o registro mínimo consultado pela verificação pública depois da exclusão.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property string $category
 * @property string $subject_type
 * @property string|null $subject_ulid
 * @property string|null $verification_code
 * @property string|null $envelope_status
 * @property Carbon|null $reference_at
 * @property list<string>|null $final_hashes
 * @property array<string, mixed>|null $manifest
 * @property list<string>|null $pending_paths
 * @property string $status
 * @property string $trigger
 * @property int|null $retention_run_id
 * @property Carbon|null $purged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RetentionDeletion extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const SUBJECT_ENVELOPE = 'envelope';

    public const SUBJECT_IDENTITY_CAPTURES = 'identity_captures';

    public const SUBJECT_DOSSIERS = 'dossiers';

    public const SUBJECT_AUDIT_TRAIL = 'audit_trail';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'category',
        'subject_type',
        'subject_ulid',
        'verification_code',
        'envelope_status',
        'reference_at',
        'final_hashes',
        'manifest',
        'pending_paths',
        'status',
        'trigger',
        'retention_run_id',
        'purged_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_at' => 'datetime',
            'purged_at' => 'datetime',
            'final_hashes' => 'array',
            'manifest' => 'array',
            'pending_paths' => 'array',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
