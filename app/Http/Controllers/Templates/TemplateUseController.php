<?php

namespace App\Http\Controllers\Templates;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Templates\Concerns\RequiresTemplatesFeature;
use App\Http\Requests\Templates\UseTemplateRequest;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Services\Templates\CreateEnvelopeFromTemplate;
use App\Services\Templates\TemplatePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Usar modelo": `GET envelopes.create?template={ulid}` mostra o formulário (página
 * templates/use) e `POST templates.use` gera o envelope em rascunho.
 */
class TemplateUseController extends Controller implements HasMiddleware
{
    use RequiresTemplatesFeature;

    public static function middleware(): array
    {
        return [self::templatesFeatureMiddleware()];
    }

    /**
     * Chamado por EnvelopeController::create (a flag já foi conferida lá). O ULID é
     * resolvido DENTRO da organização corrente: modelo de outra organização é 404.
     */
    public function form(Request $request, string $ulid): Response|RedirectResponse
    {
        $template = Template::query()->where('ulid', $ulid)->first();

        abort_unless($template instanceof Template, 404);

        if (! $template->isUsable()) {
            return redirect()->route('templates.index')->with('error', 'Este modelo está arquivado e não pode ser usado.');
        }

        Gate::authorize('use', $template);

        $version = $template->currentVersion()->with(['variables', 'roles', 'fields'])->first();

        abort_unless($version instanceof TemplateVersion, 404);

        return Inertia::render('templates/use', TemplatePresenter::useForm($template, $version));
    }

    public function store(UseTemplateRequest $request, Template $template, CreateEnvelopeFromTemplate $creator): RedirectResponse
    {
        /** @var array{title?: string|null, values?: array<string, mixed>|null, participants?: array<string, mixed>|null} $input */
        $input = $request->validated();

        $envelope = $creator->handle($template, $request->user(), $input, $request);

        // O wizard rebaixa o passo pedido para o primeiro com pendência (EnvelopeController::edit).
        return redirect()
            ->route('envelopes.edit', ['envelope' => $envelope->ulid, 'step' => 4])
            ->with('success', 'Documento gerado a partir do modelo. Revise e envie.');
    }
}
