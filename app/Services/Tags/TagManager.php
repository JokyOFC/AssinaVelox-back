<?php

namespace App\Services\Tags;

use App\Enums\AuditEventType;
use App\Models\Tag;
use App\Models\User;
use App\Services\AdminLog\OrganizationTrail;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro de etiquetas da organização corrente (criar, renomear/recolorir, excluir), com
 * trilha. A autorização (flag + `manage_tags`) é feita pelo controller antes de chamar.
 */
final class TagManager
{
    /** Teto por organização — a lista aparece inteira em filtros e menus. */
    public const MAX_TAGS = 100;

    public function create(int $organizationId, string $name, TagColor $color, User $actor): Tag
    {
        $tag = new Tag;
        $tag->forceFill([
            'organization_id' => $organizationId,
            'name' => $name,
            'color' => $color,
            'created_by_user_id' => $actor->getKey(),
        ])->save();

        OrganizationTrail::record($organizationId, AuditEventType::TagCreated, [
            'tag' => $tag->ulid,
            'name' => $tag->name,
            'color' => $tag->color->value,
        ], $actor);

        return $tag;
    }

    public function update(Tag $tag, string $name, TagColor $color, User $actor): Tag
    {
        $before = $tag->name;

        $tag->forceFill(['name' => $name, 'color' => $color])->save();

        if ($tag->wasChanged(['name', 'color'])) {
            OrganizationTrail::record($tag->organization_id, AuditEventType::TagUpdated, [
                'tag' => $tag->ulid,
                'name' => $tag->name,
                'previous_name' => $before !== $tag->name ? $before : null,
                'color' => $tag->color->value,
            ], $actor);
        }

        return $tag;
    }

    /**
     * Exclui a etiqueta e as atribuições (os envelopes não mudam). Devolve quantos
     * documentos a usavam.
     */
    public function delete(Tag $tag, User $actor): int
    {
        return DB::transaction(function () use ($tag, $actor): int {
            $count = DB::table('envelope_tag')
                ->where('organization_id', $tag->organization_id)
                ->where('tag_id', $tag->getKey())
                ->count();

            DB::table('envelope_tag')
                ->where('organization_id', $tag->organization_id)
                ->where('tag_id', $tag->getKey())
                ->delete();

            $payload = ['tag' => $tag->ulid, 'name' => $tag->name, 'envelopes' => $count];
            $organizationId = $tag->organization_id;

            $tag->delete();

            OrganizationTrail::record($organizationId, AuditEventType::TagDeleted, $payload, $actor);

            return $count;
        });
    }
}
