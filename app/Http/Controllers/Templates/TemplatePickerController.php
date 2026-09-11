<?php

namespace App\Http\Controllers\Templates;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Templates\Concerns\RequiresTemplatesFeature;
use App\Models\Envelope;
use App\Models\Template;
use App\Services\Templates\TemplatePresenter;
use App\Services\Templates\TemplateStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;

/**
 * JSON do `TemplatePicker` (resources/js/components/templates/template-picker.tsx): lista
 * curta de modelos ATIVOS da organização corrente, com busca por nome/categoria.
 */
class TemplatePickerController extends Controller implements HasMiddleware
{
    use RequiresTemplatesFeature;

    public const LIMIT = 8;

    public static function middleware(): array
    {
        return [self::templatesFeatureMiddleware()];
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Template::class);

        $validated = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $search = trim((string) ($validated['q'] ?? ''));

        $templates = Template::query()
            ->with(['currentVersion' => fn ($query) => $query->withCount(['roles', 'variables'])])
            ->where('status', TemplateStatus::Active->value)
            ->whereNotNull('current_version_id')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(fn (Builder $inner) => $inner->where('name', 'like', $like)->orWhere('category', 'like', $like));
            })
            ->orderByDesc('updated_at')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'templates' => TemplatePresenter::picker($templates),
            'can_use' => $request->user()->can('create', Envelope::class),
        ]);
    }
}
