<?php

namespace App\Http\Resources;

use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FolderRef (+ count quando `envelopes_count` foi carregado via withCount).
 *
 * @mixin Folder
 */
class FolderResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'count' => $this->when(isset($this->envelopes_count), fn () => (int) $this->envelopes_count),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
