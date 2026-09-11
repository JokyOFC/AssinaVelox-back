<?php

namespace App\Services\Tags;

use App\Enums\AuditEventType;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Tag;
use App\Models\User;
use App\Services\AdminLog\OrganizationTrail;
use App\Services\Organizations\EnvelopeVisibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Aplica/remove uma etiqueta em documentos.
 *
 * Regras:
 *  - só envelopes VISÍVEIS à membership entram na consulta (EnvelopeVisibility — um ULID de
 *    documento alheio simplesmente não é encontrado);
 *  - entre os visíveis, só os que a pessoa pode EDITAR (`EnvelopePolicy::update`) recebem
 *    ou perdem a etiqueta; os demais são contados como ignorados;
 *  - etiqueta e envelope sempre da mesma organização (a etiqueta vem do binding escopado e
 *    `envelope_tag.organization_id` é gravado a partir dela).
 *
 * Etiquetar é metadado de organização interna: não altera status, prontidão, documento ou
 * a trilha do envelope (o evento vai para a trilha da organização, `envelope_id` nulo).
 */
final class TagAssignment
{
    /**
     * @param  list<string>  $envelopeUlids
     * @return array{applied: int, already: int, skipped: int}
     */
    public function apply(Tag $tag, array $envelopeUlids, Membership $membership, User $actor): array
    {
        [$editable, $skipped] = $this->editableEnvelopes($tag, $envelopeUlids, $membership, $actor);

        if ($editable === []) {
            return ['applied' => 0, 'already' => 0, 'skipped' => $skipped];
        }

        $ids = array_keys($editable);

        $existing = DB::table('envelope_tag')
            ->where('organization_id', $tag->organization_id)
            ->where('tag_id', $tag->getKey())
            ->whereIn('envelope_id', $ids)
            ->pluck('envelope_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $new = array_values(array_diff($ids, $existing));
        $now = Carbon::now();

        if ($new !== []) {
            DB::table('envelope_tag')->insertOrIgnore(array_map(fn (int $envelopeId): array => [
                'organization_id' => $tag->organization_id,
                'envelope_id' => $envelopeId,
                'tag_id' => $tag->getKey(),
                'added_by_user_id' => $actor->getKey(),
                'created_at' => $now,
            ], $new));

            OrganizationTrail::record($tag->organization_id, AuditEventType::TagsApplied, [
                'tag' => $tag->ulid,
                'name' => $tag->name,
                'envelopes' => array_map(fn (int $id): string => $editable[$id], $new),
            ], $actor);
        }

        return ['applied' => count($new), 'already' => count($existing), 'skipped' => $skipped];
    }

    /**
     * @param  list<string>  $envelopeUlids
     * @return array{removed: int, skipped: int}
     */
    public function remove(Tag $tag, array $envelopeUlids, Membership $membership, User $actor): array
    {
        [$editable, $skipped] = $this->editableEnvelopes($tag, $envelopeUlids, $membership, $actor);

        if ($editable === []) {
            return ['removed' => 0, 'skipped' => $skipped];
        }

        $ids = array_keys($editable);

        $present = DB::table('envelope_tag')
            ->where('organization_id', $tag->organization_id)
            ->where('tag_id', $tag->getKey())
            ->whereIn('envelope_id', $ids)
            ->pluck('envelope_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($present !== []) {
            DB::table('envelope_tag')
                ->where('organization_id', $tag->organization_id)
                ->where('tag_id', $tag->getKey())
                ->whereIn('envelope_id', $present)
                ->delete();

            OrganizationTrail::record($tag->organization_id, AuditEventType::TagsRemoved, [
                'tag' => $tag->ulid,
                'name' => $tag->name,
                'envelopes' => array_values(array_map(fn (int $id): string => $editable[$id], $present)),
            ], $actor);
        }

        return ['removed' => count($present), 'skipped' => $skipped];
    }

    /**
     * @param  list<string>  $envelopeUlids
     * @return array{0: array<int, string>, 1: int} [envelope_id => ulid dos editáveis, ignorados]
     */
    private function editableEnvelopes(Tag $tag, array $envelopeUlids, Membership $membership, User $actor): array
    {
        $ulids = array_values(array_unique($envelopeUlids));

        $envelopes = EnvelopeVisibility::envelopes($membership)
            ->where('envelopes.organization_id', $tag->organization_id)
            ->whereIn('ulid', $ulids)
            ->get();

        $editable = [];

        foreach ($envelopes as $envelope) {
            /** @var Envelope $envelope */
            if (Gate::forUser($actor)->allows('update', $envelope)) {
                $editable[(int) $envelope->getKey()] = $envelope->ulid;
            }
        }

        return [$editable, count($ulids) - count($editable)];
    }
}
