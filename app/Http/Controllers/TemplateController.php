<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Templates\Concerns\RequiresTemplatesFeature;
use App\Http\Requests\Templates\StoreTemplateRequest;
use App\Http\Requests\Templates\UpdateTemplateRequest;
use App\Models\Envelope;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Services\Templates\TemplateManager;
use App\Services\Templates\TemplatePresenter;
use App\Services\Templates\TemplatesFeature;
use App\Services\Templates\TemplateSourceType;
use App\Services\Templates\TemplateStatus;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Modelos de documentos (Fase 2 §2.1 — docs/fase-2/modelos.md).
 *
 * Com a flag `templates` desligada, `index` continua sendo o placeholder da Fase 1 (mesmas
 * props) e todas as outras ações respondem 404.
 */
class TemplateController extends Controller implements HasMiddleware
{
    use RequiresTemplatesFeature;

    public static function middleware(): array
    {
        return [self::templatesFeatureMiddleware(except: ['index'])];
    }

    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::instance()->get();

        $placeholder = [
            'feature' => 'templates',
            'title' => 'Modelos de documentos',
            'subtitle' => 'Reaproveite contratos e formulários recorrentes com campos já posicionados.',
        ];

        if (! TemplatesFeature::enabled($organization)) {
            return Inertia::render('templates/index', $placeholder);
        }

        Gate::authorize('viewAny', Template::class);

        $status = $request->query('status') === TemplateStatus::Archived->value ? TemplateStatus::Archived : TemplateStatus::Active;

        $templates = Template::query()
            ->with(['currentVersion' => fn ($query) => $query->withCount(['roles', 'variables', 'fields'])])
            ->where('status', $status->value)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $counts = Template::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $categories = Template::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->values()
            ->all();

        return Inertia::render('templates/index', $placeholder + [
            'enabled' => true,
            'templates' => TemplatePresenter::rows($templates, TemplatePresenter::usage((int) $organization?->getKey())),
            'categories' => $categories,
            'filters' => ['status' => $status->value],
            'counts' => [
                'active' => (int) ($counts[TemplateStatus::Active->value] ?? 0),
                'archived' => (int) ($counts[TemplateStatus::Archived->value] ?? 0),
            ],
            'source_types' => array_map(static fn (TemplateSourceType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'requires_file' => $type->requiresFile(),
            ], TemplateSourceType::cases()),
            'limits' => [
                'max_upload_bytes' => (int) config('assinavelox.upload.max_mb', 25) * 1024 * 1024,
            ],
            'conversion' => TemplatePresenter::conversion(TemplateSourceType::Docx),
            'can' => [
                'create' => $request->user()->can('create', Template::class),
                'use' => $request->user()->can('create', Envelope::class),
            ],
        ]);
    }

    public function store(StoreTemplateRequest $request, TemplateManager $manager): RedirectResponse
    {
        /** @var array{name: string, description?: string|null, category?: string|null, source_type: string, html_body?: string|null} $data */
        $data = $request->safe()->except('file');

        $template = $manager->create(
            CurrentOrganization::instance()->get(),
            $request->user(),
            $data,
            $request->file('file'),
        );

        return redirect()->route('templates.edit', $template)->with('success', 'Modelo criado. Revise as variáveis, os participantes e os campos.');
    }

    public function edit(Request $request, Template $template): Response
    {
        Gate::authorize('update', $template);

        $version = $template->currentVersion()->with(['variables', 'roles', 'fields'])->first();

        abort_unless($version instanceof TemplateVersion, 404);

        return Inertia::render('templates/edit', TemplatePresenter::editor($template, $version, $request->user()));
    }

    public function update(UpdateTemplateRequest $request, Template $template, TemplateManager $manager): RedirectResponse
    {
        $result = $manager->update($template, $request->user(), $request->all());

        return back()->with('success', $result['version_created']
            ? sprintf('Modelo salvo como versão %d. Documentos já gerados não mudam.', $result['version']->version_number)
            : 'Modelo salvo. O conteúdo não mudou, então nenhuma versão nova foi criada.');
    }

    public function duplicate(Request $request, Template $template, TemplateManager $manager): RedirectResponse
    {
        Gate::authorize('duplicate', $template);

        try {
            $copy = $manager->duplicate($template, $request->user());
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->first() ?? 'Não foi possível duplicar o modelo.');
        }

        return redirect()->route('templates.edit', $copy)->with('success', 'Modelo duplicado.');
    }

    public function archive(Request $request, Template $template, TemplateManager $manager): RedirectResponse
    {
        Gate::authorize('archive', $template);

        $manager->archive($template, $request->user());

        return back()->with('success', 'Modelo arquivado. Ele não aparece mais para uso, e os documentos já gerados continuam intactos.');
    }

    public function restore(Request $request, Template $template, TemplateManager $manager): RedirectResponse
    {
        Gate::authorize('restore', $template);

        $manager->restore($template, $request->user());

        return back()->with('success', 'Modelo restaurado.');
    }
}
