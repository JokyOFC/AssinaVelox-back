<?php

namespace App\Http\Controllers\Tags;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Tag;
use App\Services\AdminLog\ToolFlags;
use App\Services\Organizations\EnvelopeVisibility;
use App\Services\Tags\TagColor;
use App\Services\Tags\TagManager;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › Etiquetas (Fase 2 — docs/fase-2/tags-relatorios-e-logs.md).
 *
 * Qualquer membro vê a lista (com a contagem dos documentos que ELE vê em cada etiqueta);
 * criar, renomear, recolorir e excluir exigem `manage_tags`. Tudo exige a flag `tags`:
 * desligada, a página mostra o estado "Fase 2" e as ações respondem 403.
 */
class TagController extends Controller
{
    public function __construct(private readonly TagManager $tags) {}

    public function index(): Response
    {
        [$organization, $membership] = $this->context();

        if (! ToolFlags::tags($organization)) {
            return Inertia::render('settings/tags', ['enabled' => false]);
        }

        $visible = EnvelopeVisibility::envelopes($membership)->select('envelopes.id');

        $counts = DB::table('envelope_tag')
            ->where('organization_id', $organization->getKey())
            ->whereIn('envelope_id', $visible)
            ->groupBy('tag_id')
            ->selectRaw('tag_id, count(*) as aggregate')
            ->pluck('aggregate', 'tag_id');

        return Inertia::render('settings/tags', [
            'enabled' => true,
            'tags' => Tag::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Tag $tag): array => [
                    ...$tag->toChip(),
                    'envelopes_count' => (int) ($counts[$tag->getKey()] ?? 0),
                    'created_at' => $tag->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'colors' => TagColor::options(),
            'limits' => ['max_tags' => TagManager::MAX_TAGS, 'max_name' => Tag::MAX_NAME],
            'can' => ['manage' => $membership->hasPermission(Permission::ManageTags)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$organization, $membership] = $this->authorizeManage();

        ['name' => $name, 'color' => $color] = $this->validated($request, $organization);

        if (Tag::query()->count() >= TagManager::MAX_TAGS) {
            throw ValidationException::withMessages(['name' => 'Esta conta já tem o máximo de '.TagManager::MAX_TAGS.' etiquetas.']);
        }

        $tag = $this->tags->create((int) $organization->getKey(), $name, $color, $request->user());

        return back()->with('success', 'Etiqueta “'.$tag->name.'” criada.');
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        [$organization] = $this->authorizeManage();

        ['name' => $name, 'color' => $color] = $this->validated($request, $organization, $tag);

        $this->tags->update($tag, $name, $color, $request->user());

        return back()->with('success', 'Etiqueta atualizada.');
    }

    public function destroy(Request $request, Tag $tag): RedirectResponse
    {
        $this->authorizeManage();

        $count = $this->tags->delete($tag, $request->user());

        return back()->with('success', $count > 0
            ? 'Etiqueta excluída e removida de '.$count.($count === 1 ? ' documento.' : ' documentos.')
            : 'Etiqueta excluída.');
    }

    /**
     * @return array{name: string, color: TagColor}
     */
    private function validated(Request $request, Organization $organization, ?Tag $ignore = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.Tag::MAX_NAME],
            'color' => ['required', Rule::enum(TagColor::class)],
        ], [], ['name' => 'nome', 'color' => 'cor']);

        $name = Tag::cleanName((string) $validated['name']);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Informe o nome da etiqueta.']);
        }

        $taken = Tag::forOrganization($organization)
            ->where('name_key', Tag::keyFor($name))
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'Já existe uma etiqueta com este nome.']);
        }

        return ['name' => $name, 'color' => TagColor::from((string) $validated['color'])];
    }

    /**
     * @return array{0: Organization, 1: Membership}
     */
    private function authorizeManage(): array
    {
        [$organization, $membership] = $this->context();

        abort_unless(ToolFlags::tags($organization), 403, 'Etiquetas não estão disponíveis no plano desta conta.');
        abort_unless($membership->hasPermission(Permission::ManageTags), 403, 'Você não tem permissão para gerenciar etiquetas.');

        return [$organization, $membership];
    }

    /**
     * @return array{0: Organization, 1: Membership}
     */
    private function context(): array
    {
        $current = CurrentOrganization::instance();
        $organization = $current->get();
        $membership = $current->membership();

        abort_if($organization === null || $membership === null, 403, 'Nenhuma organização ativa.');

        return [$organization, $membership];
    }
}
