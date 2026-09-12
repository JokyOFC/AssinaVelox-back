<?php

namespace App\Models;

use App\Enums\ApiAbility;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token da API v1 (Fase 2 §2.15 — docs/fase-2/api-v1.md §2).
 *
 * É o `PersonalAccessToken` do Sanctum — o texto do token existe só no momento da criação;
 * o banco guarda o SHA-256 (`token`) e a busca é a do Sanctum (`findToken`, `hash_equals`) —
 * com o que o roadmap reservou: organização dona, quem criou, prefixo de exibição, último uso
 * e revogação.
 *
 * O `tokenable` é sempre o usuário criador: o token age como uma "pessoa de integração" com
 * as permissões ATUAIS dessa pessoa na organização do token, limitadas pelas abilities.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $created_by_user_id
 * @property string $tokenable_type
 * @property int $tokenable_id
 * @property string $name
 * @property string $token
 * @property array<int, mixed>|null $abilities JSON do banco: conferido valor a valor em abilityValues()
 * @property string|null $token_prefix
 * @property string|null $last_used_ip
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read Organization|null $organization
 */
class ApiToken extends PersonalAccessToken
{
    use BelongsToOrganization, HasPublicUlid;

    protected $table = 'personal_access_tokens';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'organization_id',
        'created_by_user_id',
        'token_prefix',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /**
     * Tem a ability? Conjunto fechado: `*` nunca concede nada (nenhum token é emitido com ele).
     *
     * @param  string|ApiAbility  $ability
     */
    public function can($ability): bool
    {
        $value = $ability instanceof ApiAbility ? $ability->value : (string) $ability;

        return in_array($value, $this->abilityValues(), true);
    }

    /**
     * @param  string|ApiAbility  $ability
     */
    public function cant($ability): bool
    {
        return ! $this->can($ability);
    }

    /**
     * @return list<string>
     */
    public function abilityValues(): array
    {
        $abilities = $this->abilities;

        if (! is_array($abilities)) {
            return [];
        }

        return array_values(array_filter(
            $abilities,
            static fn ($value): bool => is_string($value) && ApiAbility::tryFrom($value) !== null,
        ));
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /** active | expired | revoked */
    public function state(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isExpired() => 'expired',
            default => 'active',
        };
    }

    /**
     * Tokens que ainda podem autenticar.
     *
     * @param  Builder<ApiToken>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()));
    }
}
