<?php

namespace App\Http\Controllers\Tags;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Tag;
use App\Services\AdminLog\ToolFlags;
use App\Services\Tags\TagAssignment;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Aplicar/remover etiqueta em documentos (ação em lote "Adicionar etiqueta" e o "x" do chip).
 *
 * Não exige `manage_tags` — aplicar etiqueta é editar o documento: cada envelope passa pela
 * `EnvelopePolicy::update` (TagAssignment). Exige a flag `tags` (403 sem ela).
 */
class EnvelopeTagController extends Controller
{
    public function __construct(private readonly TagAssignment $assignment) {}

    public function apply(Request $request): RedirectResponse
    {
        $membership = $this->membership();

        $validated = $request->validate([
            'tag' => ['required', 'string', 'size:26', Rule::exists('tags', 'ulid')->where('organization_id', $membership->organization_id)],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string', 'size:26'],
        ], [], ['tag' => 'etiqueta', 'ids' => 'documentos']);

        $tag = Tag::query()->where('ulid', $validated['tag'])->firstOrFail();

        $result = $this->assignment->apply($tag, array_values($validated['ids']), $membership, $request->user());

        $message = $result['applied'] > 0
            ? 'Etiqueta “'.$tag->name.'” aplicada a '.$result['applied'].($result['applied'] === 1 ? ' documento.' : ' documentos.')
            : 'Nenhum documento novo recebeu a etiqueta “'.$tag->name.'”.';

        if ($result['already'] > 0) {
            $message .= ' '.$result['already'].($result['already'] === 1 ? ' já tinha a etiqueta.' : ' já tinham a etiqueta.');
        }

        if ($result['skipped'] > 0) {
            $message .= ' '.$result['skipped'].($result['skipped'] === 1 ? ' ignorado' : ' ignorados').' — sem permissão para editar.';
        }

        return back()->with($result['applied'] > 0 || $result['already'] > 0 ? 'success' : 'warning', $message);
    }

    public function detach(Request $request, Envelope $envelope, Tag $tag): RedirectResponse
    {
        $membership = $this->membership();

        $result = $this->assignment->remove($tag, [$envelope->ulid], $membership, $request->user());

        abort_if($result['skipped'] > 0, 403, 'Você não tem permissão para editar este documento.');

        return back()->with('success', 'Etiqueta “'.$tag->name.'” removida.');
    }

    private function membership(): Membership
    {
        $current = CurrentOrganization::instance();
        $membership = $current->membership();

        abort_if($membership === null, 403, 'Nenhuma organização ativa.');
        abort_unless(ToolFlags::tags($current->get()), 403, 'Etiquetas não estão disponíveis no plano desta conta.');

        return $membership;
    }
}
