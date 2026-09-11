<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\Tags\TagColor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Etiqueta de envelopes da organização (Fase 2 — docs/fase-2/tags-relatorios-e-logs.md).
 *
 * Nome único por organização sem diferenciar maiúsculas (`name_key`). A cor é uma das
 * cores fechadas da paleta do design ({@see TagColor}); nunca um valor livre do cliente.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property string $name
 * @property string $name_key
 * @property TagColor $color
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Tag extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const MAX_NAME = 40;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'name', 'color', 'created_by_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'color' => TagColor::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Tag $tag): void {
            $tag->name = self::cleanName($tag->name);
            $tag->name_key = self::keyFor($tag->name);
        });
    }

    /** Colapsa espaços e remove caracteres de controle. */
    public static function cleanName(string $name): string
    {
        $clean = preg_replace('/[\p{C}]+/u', '', $name) ?? '';

        return trim((string) preg_replace('/\s+/u', ' ', $clean));
    }

    public static function keyFor(string $name): string
    {
        return Str::limit(mb_strtolower(self::cleanName($name)), self::MAX_NAME, '');
    }

    /** @return BelongsToMany<Envelope, $this> */
    public function envelopes(): BelongsToMany
    {
        return $this->belongsToMany(Envelope::class, 'envelope_tag')
            ->withPivot(['organization_id', 'added_by_user_id', 'created_at']);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return array{id: string, name: string, color: string}
     */
    public function toChip(): array
    {
        return ['id' => $this->ulid, 'name' => $this->name, 'color' => $this->color->value];
    }
}
