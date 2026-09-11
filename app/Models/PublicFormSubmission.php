<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\PublicForms\SubmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Um envio de formulário público (Fase 2 §2.2 — docs/fase-2/formulario-publico.md §5).
 *
 * O `payload` (nome, e-mail e valores digitados) é cifrado em repouso e só existe até o
 * envelope nascer; depois disso vale o que está no documento e nos destinatários.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $public_form_id
 * @property SubmissionStatus $status
 * @property array{name?: string, email?: string, values?: array<string, string>}|null $payload
 * @property string $email_digest
 * @property string|null $confirmation_digest
 * @property Carbon|null $confirmation_expires_at
 * @property Carbon|null $confirmed_at
 * @property int|null $envelope_id
 * @property string|null $failure_reason
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property string|null $privacy_notice_version
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PublicForm $form
 * @property-read Envelope|null $envelope
 */
class PublicFormSubmission extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'public_form_id',
        'status',
        'payload',
        'email_digest',
        'confirmation_digest',
        'confirmation_expires_at',
        'confirmed_at',
        'envelope_id',
        'failure_reason',
        'reviewed_by_user_id',
        'reviewed_at',
        'privacy_notice_version',
        'ip_address',
        'user_agent',
    ];

    /** @var list<string> */
    protected $hidden = ['payload', 'email_digest', 'confirmation_digest'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'payload' => 'encrypted:array',
            'confirmation_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PublicForm, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(PublicForm::class, 'public_form_id');
    }

    /**
     * Envelope gerado (inclusive rascunho excluído, para a fila saber o que aconteceu).
     *
     * @return BelongsTo<Envelope, $this>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function confirmationExpired(): bool
    {
        return $this->confirmation_expires_at === null || $this->confirmation_expires_at->isPast();
    }
}
