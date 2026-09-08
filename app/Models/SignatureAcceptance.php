<?php

namespace App\Models;

use App\Enums\AuthMethod;
use App\Enums\SignatureKind;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\SignatureAcceptanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Aceite eletrônico: manifestação de vontade vinculada a uma versão exata do documento.
 * Um por destinatário (UNIQUE recipient_id). Imutável após criação.
 *
 * @property int $id
 * @property string $ulid
 * @property int $recipient_id
 * @property int $envelope_id
 * @property int $document_version_id
 * @property int|null $signing_session_id
 * @property int|null $auth_challenge_id
 * @property int $organization_id
 * @property Carbon $accepted_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property AuthMethod $auth_method
 * @property string|null $terms_version
 * @property string $consent_statement
 * @property string $document_sha256
 * @property array<string, mixed>|null $fields_snapshot
 * @property SignatureKind|null $signature_kind
 * @property string|null $signature_image_path
 * @property string|null $typed_name
 * @property string|null $typed_font
 * @property Carbon|null $created_at
 */
class SignatureAcceptance extends Model
{
    /** @use HasFactory<SignatureAcceptanceFactory> */
    use BelongsToOrganization, HasFactory, HasPublicUlid;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'recipient_id',
        'envelope_id',
        'document_version_id',
        'signing_session_id',
        'auth_challenge_id',
        'organization_id',
        'accepted_at',
        'ip_address',
        'user_agent',
        'auth_method',
        'terms_version',
        'consent_statement',
        'document_sha256',
        'fields_snapshot',
        'signature_kind',
        'signature_image_path',
        'typed_name',
        'typed_font',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'auth_method' => AuthMethod::class,
            'fields_snapshot' => 'array',
            'signature_kind' => SignatureKind::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Recipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    /** @return BelongsTo<SigningSession, $this> */
    public function signingSession(): BelongsTo
    {
        return $this->belongsTo(SigningSession::class);
    }

    /** @return BelongsTo<AuthChallenge, $this> */
    public function authChallenge(): BelongsTo
    {
        return $this->belongsTo(AuthChallenge::class);
    }

    /** @return HasMany<SigningFieldValue, $this> */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(SigningFieldValue::class, 'signature_acceptance_id');
    }
}
