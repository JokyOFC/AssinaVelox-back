<?php

namespace App\Integrations\Cnpj;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cache da consulta pública de CNPJ (`cnpj_lookups`). Sem organização: o dado é público e
 * igual para todos, e a linha não registra quem consultou.
 *
 * @property int $id
 * @property string $cnpj
 * @property string $status found | not_found
 * @property array<string, mixed>|null $payload
 * @property string $source
 * @property string|null $source_updated
 * @property Carbon $fetched_at
 * @property Carbon $expires_at
 */
class CnpjLookupRecord extends Model
{
    public const STATUS_FOUND = 'found';

    public const STATUS_NOT_FOUND = 'not_found';

    protected $table = 'cnpj_lookups';

    protected $fillable = [
        'cnpj',
        'status',
        'payload',
        'source',
        'source_updated',
        'fetched_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isFresh(): bool
    {
        return $this->expires_at->isFuture();
    }
}
