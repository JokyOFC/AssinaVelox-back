<?php

namespace App\Services\Tags;

use App\Enums\Permission;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Tag;
use App\Services\AdminLog\ToolFlags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Etiquetas na lista de documentos (`envelopes.index`): filtro por etiqueta, chips por
 * linha e opções da ação em lote "Adicionar etiqueta".
 *
 * INTEGRAÇÃO (EnvelopeController fica fora da área B-ORG) — três pontos em `index()`:
 *
 *   $tagging = EnvelopeTagIndex::for($membership, $request->query('tag'));
 *   // 1. dentro de `$base`, depois de applyFilters:  $tagging->constrain($query)
 *   // 2. props da página:                           'tagging' => $tagging->props($envelopes->getCollection())
 *   // 3. `filters`:                                 'tag' => $tagging->tagUlid()
 *
 * Com a flag `tags` desligada, `constrain()` não altera a consulta e `props()` devolve
 * `['enabled' => false, ...]` — a página esconde tudo e a Fase 1 fica idêntica.
 *
 * O filtro só estreita o que `EnvelopeVisibility` já liberou: ele nunca amplia a consulta.
 */
final class EnvelopeTagIndex
{
    private function __construct(
        private readonly Membership $membership,
        private readonly bool $enabled,
        private readonly ?Tag $tag,
    ) {}

    public static function for(Membership $membership, mixed $tagUlid = null): self
    {
        $enabled = ToolFlags::tags($membership->organization);
        $tag = null;

        if ($enabled && is_string($tagUlid) && strlen($tagUlid) === 26) {
            $tag = Tag::forOrganization($membership->organization_id)->where('ulid', $tagUlid)->first();
        }

        return new self($membership, $enabled, $tag);
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function tagUlid(): ?string
    {
        return $this->tag?->ulid;
    }

    /**
     * Restringe a consulta de envelopes à etiqueta escolhida (nada muda sem filtro).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query): Builder
    {
        if (! $this->enabled || $this->tag === null) {
            return $query;
        }

        return self::whereTagged($query, $this->tag);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function whereTagged(Builder $query, Tag $tag): Builder
    {
        return $query->whereIn('envelopes.id', DB::table('envelope_tag')
            ->select('envelope_tag.envelope_id')
            ->where('envelope_tag.organization_id', $tag->organization_id)
            ->where('envelope_tag.tag_id', $tag->getKey()));
    }

    /**
     * @param  iterable<Envelope>  $envelopes  linhas da página atual
     * @return array{enabled: bool, can_manage: bool, available: list<array{id: string, name: string, color: string}>, by_envelope: array<string, list<array{id: string, name: string, color: string}>>}
     */
    public function props(iterable $envelopes): array
    {
        if (! $this->enabled) {
            return ['enabled' => false, 'can_manage' => false, 'available' => [], 'by_envelope' => []];
        }

        return [
            'enabled' => true,
            'can_manage' => $this->membership->hasPermission(Permission::ManageTags),
            'available' => self::available($this->membership->organization_id),
            'by_envelope' => self::chipsFor($this->membership->organization_id, $envelopes),
        ];
    }

    /**
     * @return list<array{id: string, name: string, color: string}>
     */
    public static function available(int $organizationId): array
    {
        return array_values(Tag::forOrganization($organizationId)
            ->orderBy('name')
            ->get()
            ->map(fn (Tag $tag): array => $tag->toChip())
            ->all());
    }

    /**
     * Chips por ULID de envelope (uma consulta para a página inteira).
     *
     * @param  iterable<Envelope>  $envelopes
     * @return array<string, list<array{id: string, name: string, color: string}>>
     */
    public static function chipsFor(int $organizationId, iterable $envelopes): array
    {
        $ulidById = [];

        foreach ($envelopes as $envelope) {
            if ($envelope->organization_id === $organizationId) {
                $ulidById[(int) $envelope->getKey()] = $envelope->ulid;
            }
        }

        if ($ulidById === []) {
            return [];
        }

        $rows = DB::table('envelope_tag')
            ->join('tags', 'tags.id', '=', 'envelope_tag.tag_id')
            ->where('envelope_tag.organization_id', $organizationId)
            ->where('tags.organization_id', $organizationId)
            ->whereIn('envelope_tag.envelope_id', array_keys($ulidById))
            ->orderBy('tags.name')
            ->get(['envelope_tag.envelope_id', 'tags.ulid', 'tags.name', 'tags.color']);

        $chips = [];

        foreach ($rows as $row) {
            $ulid = $ulidById[(int) $row->envelope_id] ?? null;

            if ($ulid !== null) {
                $chips[$ulid][] = ['id' => (string) $row->ulid, 'name' => (string) $row->name, 'color' => (string) $row->color];
            }
        }

        return $chips;
    }
}
