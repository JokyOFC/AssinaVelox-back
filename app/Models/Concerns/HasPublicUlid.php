<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Identificador público opaco (CHAR(26)). Nunca substitui autorização.
 *
 * @mixin Model
 *
 * @property string $ulid
 */
trait HasPublicUlid
{
    public static function bootHasPublicUlid(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('ulid'))) {
                $model->setAttribute('ulid', (string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
