<?php

namespace App\Http\Controllers\PublicForms;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicForms\Middleware\EnsurePublicFormsFeature;
use App\Http\Requests\PublicForms\StorePublicFormRequest;
use App\Http\Requests\PublicForms\UpdatePublicFormRequest;
use App\Models\PublicForm;
use App\Models\Template;
use App\Services\PublicForms\PublicFormManager;
use App\Services\PublicForms\PublicFormPresenter;
use App\Services\PublicForms\SubmissionPurge;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Formulários públicos — telas internas (docs/fase-2/formulario-publico.md §3, §4 e §8).
 * Flag `public_forms` desligada: 404 em todas as rotas (middleware do próprio controller).
 */
class PublicFormController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly PublicFormManager $manager,
        private readonly PublicFormPresenter $presenter,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware(EnsurePublicFormsFeature::class)];
    }

    public function index(Request $request, SubmissionPurge $purge): Response
    {
        Gate::authorize('viewAny', PublicForm::class);

        $organization = CurrentOrganization::instance()->get();
        abort_if($organization === null, 404);

        $purge->run(organizationId: (int) $organization->getKey());

        return Inertia::render('public-forms/index', $this->presenter->index($organization, $request->user()));
    }

    public function store(StorePublicFormRequest $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        abort_if($organization === null, 404);

        // Resolvido DENTRO da organização corrente (escopo global): modelo de outra
        // organização não existe aqui.
        $template = Template::query()->where('ulid', $request->validated('template'))->first();

        abort_unless($template instanceof Template, 404);

        $form = $this->manager->create($organization, $request->user(), $template, $request->validated('title'));

        return redirect()
            ->route('public_forms.edit', ['publicForm' => $form->ulid])
            ->with('success', 'Formulário criado em rascunho. Revise a configuração e publique.');
    }

    public function edit(Request $request, PublicForm $publicForm): Response
    {
        Gate::authorize('view', $publicForm);

        return Inertia::render('public-forms/edit', $this->presenter->edit($publicForm, $request->user()));
    }

    public function update(UpdatePublicFormRequest $request, PublicForm $publicForm): RedirectResponse
    {
        $this->manager->update($publicForm, $request->user(), $request->validated());

        return back()->with('success', 'Formulário salvo.');
    }

    public function activate(PublicForm $publicForm): RedirectResponse
    {
        Gate::authorize('update', $publicForm);

        $wasPaused = $publicForm->status->value === 'paused';
        $this->manager->activate($publicForm);

        return back()->with('success', $wasPaused ? 'Formulário retomado: o link voltou a aceitar respostas.' : 'Formulário publicado. Compartilhe o link.');
    }

    public function pause(PublicForm $publicForm): RedirectResponse
    {
        Gate::authorize('update', $publicForm);

        $this->manager->pause($publicForm);

        return back()->with('success', 'Formulário pausado: o link continua existindo, mas não aceita respostas.');
    }

    public function revoke(PublicForm $publicForm): RedirectResponse
    {
        Gate::authorize('update', $publicForm);

        $this->manager->revoke($publicForm);

        return back()->with('success', 'Formulário revogado. O link deixou de funcionar.');
    }
}
