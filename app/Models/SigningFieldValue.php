<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SigningFieldValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $signing_field_id
 * @property int $recipient_id
 * @property int $signature_acceptance_id
 * @property int $envelope_id
 * @property int $organization_id
 * @property string|null $value_text
 * @property bool|null $value_bool
 * @property string|null $image_path
 * @property Carbon|null $created_at
 */
class SigningFieldValue extends Model
{
    /** @use HasFactory<SigningFieldValueFactory> */
    use BelongsToOrganization, HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'signing_field_id',
        'recipient_id',
        'signature_acceptance_id',
        'envelope_id',
        'organization_id',
        'value_text',
        'value_bool',
        'image_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_bool' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SigningField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(SigningField::class, 'signing_field_id');
    }

    /** @return BelongsTo<Recipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /** @return BelongsTo<SignatureAcceptance, $this> */
    public function acceptance(): BelongsTo
    {
        return $this->belongsTo(SignatureAcceptance::class, 'signature_acceptance_id');
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }
}
