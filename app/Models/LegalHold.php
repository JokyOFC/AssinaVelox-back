<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\Retention\LegalHoldScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bloqueio de exclusão por preservação (Fase 2 §2.19).
 *
 * Ativo = não liberado, já iniciado e sem `ends_at` vencido. Nada coberto por um bloqueio
 * ativo é apagado — nem pela retenção, nem pela exclusão manual, nem pela exclusão da
 * organização (App\Services\Retention\LegalHolds).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property LegalHoldScope $scope
 * @property int|null $envelope_id
 * @property int|null $folder_id
 * @property string|null $subject_ulid
 * @property string $reason
 * @property int|null $created_by_user_id
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $released_at
 * @property int|null $released_by_user_id
 * @property string|null $release_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Envelope|null $envelope
 * @property-read Folder|null $folder
 * @property-read User|null $creator
 * @property-read User|null $releaser
 */
class LegalHold extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'scope',
        'envelope_id',
        'folder_id',
        'subject_ulid',
        'reason',
        'created_by_user_id',
        'starts_at',
        'ends_at',
        'released_at',
        'released_by_user_id',
        'release_reason',
    ];

    protected function casts(): array
    {
        return [
            'scope' => LegalHoldScope::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<LegalHold>  $query
     * @return Builder<LegalHold>
     */
    public function scopeActive(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= Carbon::now();

        return $query
            ->whereNull('released_at')
            ->where('starts_at', '<=', $now)
            ->where(fn (Builder $inner) => $inner->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    public function isActive(?CarbonInterface $now = null): bool
    {
        $now ??= Carbon::now();

        return $this->released_at === null
            && $this->starts_at->lessThanOrEqualTo($now)
            && ($this->ends_at === null || $this->ends_at->greaterThan($now));
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Folder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }
}
