<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Domínio de envio da organização (Fase 2 §2.8, docs/fase-2/canais-e-pin.md §6).
 *
 * `is_simulated` = a verificação veio do simulador. Um domínio assim NUNCA é usado como
 * remetente (App\Integrations\Email\SenderIdentity).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property string $domain
 * @property string $status
 * @property string $verification_token
 * @property list<array{type: string, host: string, value: string, purpose: string}>|null $expected_records
 * @property string $provider
 * @property string|null $provider_domain_id
 * @property bool $is_simulated
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SenderDomain extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'domain',
        'status',
        'verification_token',
        'expected_records',
        'provider',
        'provider_domain_id',
        'is_simulated',
        'last_checked_at',
        'verified_at',
        'failed_at',
        'failure_reason',
        'created_by_user_id',
    ];

    /** @var list<string> */
    protected $hidden = ['verification_token'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'is_simulated' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_records' => 'array',
            'is_simulated' => 'boolean',
            'last_checked_at' => 'datetime',
            'verified_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_VERIFIED => 'Verificado',
            self::STATUS_FAILED => 'Falhou',
            default => 'Aguardando verificação',
        };
    }
}
