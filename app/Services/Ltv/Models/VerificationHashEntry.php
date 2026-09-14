<?php

namespace App\Services\Ltv\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um resumo final (SHA-256) que o documento teve, com o período em que foi o vigente
 * (viabilidade §4.5 item 29). Linha só existe depois do primeiro re-carimbo.
 *
 * @property int $id
 * @property int $verification_record_id
 * @property int|null $verification_record_document_id
 * @property int $position
 * @property int|null $document_version_id
 * @property string $sha256
 * @property string $reason
 * @property string|null $ltv_status
 * @property Carbon|null $valid_from
 * @property Carbon|null $superseded_at
 * @property Carbon|null $created_at
 */
class VerificationHashEntry extends Model
{
    public const UPDATED_AT = null;

    public const REASON_FINALIZED = 'finalized';

    public const REASON_LTV_REFRESH = 'ltv_refresh';

    protected $table = 'verification_hash_history';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'valid_from' => 'datetime',
            'superseded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }
}
