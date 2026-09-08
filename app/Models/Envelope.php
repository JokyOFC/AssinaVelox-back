<?php

namespace App\Models;

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Exceptions\InvalidEnvelopeTransition;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\EnvelopeFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $folder_id
 * @property int $created_by_user_id
 * @property int $number
 * @property string $title
 * @property string|null $message
 * @property EnvelopeStatus $status
 * @property SigningOrder $signing_order
 * @property int $current_order
 * @property Carbon|null $expires_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $refused_at
 * @property Carbon|null $expired_at
 * @property Carbon|null $canceled_at
 * @property int|null $sent_document_version_id
 * @property int|null $final_document_version_id
 * @property string|null $verification_code
 * @property string|null $finalization_key
 * @property string|null $terms_version
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $display_code
 * @property-read string|null $formatted_verification_code
 * @property-read Document|null $document
 */
class Envelope extends Model
{
    /** @use HasFactory<EnvelopeFactory> */
    use BelongsToOrganization, HasFactory, HasPublicUlid, SoftDeletes;

    public const DISPLAY_CODE_PREFIX = 'AV-';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'folder_id',
        'created_by_user_id',
        'number',
        'title',
        'message',
        'status',
        'signing_order',
        'current_order',
        'expires_at',
        'sent_at',
        'completed_at',
        'refused_at',
        'expired_at',
        'canceled_at',
        'sent_document_version_id',
        'final_document_version_id',
        'verification_code',
        'finalization_key',
        'terms_version',
        'settings',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => EnvelopeStatus::Draft->value,
        'signing_order' => SigningOrder::Sequential->value,
        'current_order' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => EnvelopeStatus::class,
            'signing_order' => SigningOrder::class,
            'current_order' => 'integer',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'refused_at' => 'datetime',
            'expired_at' => 'datetime',
            'canceled_at' => 'datetime',
            'settings' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Envelope $envelope): void {
            if (empty($envelope->number) && $envelope->organization_id) {
                $envelope->number = static::nextNumberFor($envelope->organization_id);
            }
        });
    }

    // -- Numeração --------------------------------------------------------------------

    /**
     * Próximo número sequencial da organização.
     *
     * Bloqueia a linha da organização (MySQL: SELECT ... FOR UPDATE) para serializar a
     * numeração entre requisições concorrentes. O SQLite ignora o lock — nos testes só a
     * sequência é verificada. Deve ser chamado dentro da transação que persiste o envelope;
     * fora dela, o UNIQUE(organization_id, number) é a rede de segurança.
     */
    public static function nextNumberFor(Organization|int $organization): int
    {
        $organizationId = $organization instanceof Organization ? $organization->getKey() : $organization;

        return DB::transaction(function () use ($organizationId): int {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->first();

            $max = static::query()
                ->withoutGlobalScope(OrganizationScope::class)
                ->withTrashed()
                ->where('organization_id', $organizationId)
                ->max('number');

            return ((int) $max) + 1;
        });
    }

    // -- Transições -------------------------------------------------------------------

    /**
     * Valida e define o novo status SEM persistir — quem grava é o serviço (dentro da
     * transação apropriada). Também carimba o timestamp correspondente quando aplicável.
     *
     * @throws InvalidEnvelopeTransition
     */
    public function transitionTo(EnvelopeStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw new InvalidEnvelopeTransition($this->status, $to, $this->ulid);
        }

        $this->status = $to;

        $now = Carbon::now();

        match ($to) {
            EnvelopeStatus::InProgress => $this->sent_at ??= $now,
            EnvelopeStatus::Completed => $this->completed_at ??= $now,
            EnvelopeStatus::Refused => $this->refused_at ??= $now,
            EnvelopeStatus::Expired => $this->expired_at ??= $now,
            EnvelopeStatus::Canceled => $this->canceled_at ??= $now,
            default => null,
        };
    }

    public function canTransitionTo(EnvelopeStatus $to): bool
    {
        return $this->status->canTransitionTo($to);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isSequential(): bool
    {
        return $this->signing_order === SigningOrder::Sequential;
    }

    // -- Relações ---------------------------------------------------------------------

    /** @return BelongsTo<Folder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Fase 1: exatamente um documento por envelope.
     *
     * @return HasOne<Document, $this>
     */
    public function document(): HasOne
    {
        return $this->hasOne(Document::class)->oldestOfMany();
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function sentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'sent_document_version_id');
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function finalVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'final_document_version_id');
    }

    /** @return HasMany<Recipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class)->orderBy('order_index')->orderBy('id');
    }

    /** @return HasMany<SigningField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(SigningField::class)->orderBy('page')->orderBy('sort_order');
    }

    /** @return HasMany<SigningFieldValue, $this> */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(SigningFieldValue::class);
    }

    /** @return HasMany<SignatureAcceptance, $this> */
    public function acceptances(): HasMany
    {
        return $this->hasMany(SignatureAcceptance::class);
    }

    /** @return HasMany<RecipientAccessLink, $this> */
    public function accessLinks(): HasMany
    {
        return $this->hasMany(RecipientAccessLink::class);
    }

    /** @return HasMany<SigningSession, $this> */
    public function signingSessions(): HasMany
    {
        return $this->hasMany(SigningSession::class);
    }

    /** @return HasMany<AuditEvent, $this> */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    /** @return HasMany<DeliveryAttempt, $this> */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    /** @return HasOne<VerificationRecord, $this> */
    public function verificationRecord(): HasOne
    {
        return $this->hasOne(VerificationRecord::class);
    }

    /** @return HasMany<PlanConsumption, $this> */
    public function planConsumptions(): HasMany
    {
        return $this->hasMany(PlanConsumption::class);
    }

    // -- Acessores e helpers ----------------------------------------------------------

    /** @return Attribute<string, never> */
    protected function displayCode(): Attribute
    {
        return Attribute::get(fn (): string => static::formatDisplayCode((int) $this->number));
    }

    public static function formatDisplayCode(int $number): string
    {
        return self::DISPLAY_CODE_PREFIX.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Alfabeto base32 sem 0/1/O/I (evita ambiguidade na leitura).
     */
    public const VERIFICATION_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const VERIFICATION_CODE_LENGTH = 12;

    /**
     * Gera um código de verificação de 12 caracteres (não garante unicidade; o UNIQUE da
     * coluna e a retentativa no serviço cuidam disso).
     */
    public static function generateVerificationCode(): string
    {
        $alphabet = self::VERIFICATION_CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::VERIFICATION_CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Código de verificação exibido como XXXX-XXXX-XXXX (acessor `formatted_verification_code`).
     */
    public function getFormattedVerificationCodeAttribute(): ?string
    {
        if ($this->verification_code === null) {
            return null;
        }

        $code = strtoupper(str_replace('-', '', $this->verification_code));

        return implode('-', str_split($code, 4));
    }

    public function signedCount(): int
    {
        if ($this->relationLoaded('recipients')) {
            return $this->recipients->where('status', RecipientStatus::Signed)->count();
        }

        return $this->recipients()->where('status', RecipientStatus::Signed->value)->count();
    }

    public function statusLabel(): string
    {
        return $this->status->labelWithProgress($this->signedCount());
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }
}
