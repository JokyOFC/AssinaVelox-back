<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Entrega de UM documento a uma sessão de assinatura (Fase 2 §2.3).
 *
 * Prova que os bytes da versão congelada daquele documento saíram do servidor para a sessão
 * — não que foram lidos. O aceite de um envelope com N documentos exige uma linha por
 * documento (RecordAcceptance).
 *
 * @property int $id
 * @property int $signing_session_id
 * @property int $document_id
 * @property int $document_version_id
 * @property int $organization_id
 * @property Carbon $presented_at
 * @property Carbon|null $created_at
 */
class SigningSessionDocument extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'signing_session_id',
        'document_id',
        'document_version_id',
        'organization_id',
        'presented_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'presented_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SigningSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SigningSession::class, 'signing_session_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
