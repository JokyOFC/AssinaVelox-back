<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use App\Services\Risk\RiskRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Sinal do antifraude (Fase 3 §3.7) — APPEND-ONLY. Sem updated_at; o model recusa update e
 * delete. Grave sempre por App\Services\Risk\RiskSignals::record(), que minimiza a evidência.
 *
 * Sem o escopo global de organização de propósito: é lido pelo painel interno (entre
 * organizações) e, do lado da organização, só por consultas que filtram `organization_id`.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $envelope_id
 * @property string $rule_code
 * @property int $score
 * @property string|null $subject_key
 * @property string $fingerprint
 * @property array<string, int|float|bool|string>|null $evidence
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property-read Organization $organization
 * @property-read Envelope|null $envelope
 */
class RiskSignal extends Model
{
    use HasPublicUlid;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'envelope_id',
        'rule_code',
        'score',
        'subject_key',
        'fingerprint',
        'evidence',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'evidence' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RiskSignal $signal): void {
            $signal->occurred_at ??= Carbon::now();
        });

        static::updating(function (): never {
            throw new LogicException('risk_signals é append-only: sinais não podem ser alterados.');
        });

        static::deleting(function (): never {
            throw new LogicException('risk_signals é append-only: sinais não podem ser removidos.');
        });
    }

    public function rule(): ?RiskRule
    {
        return RiskRule::tryFrom($this->rule_code);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }
}
