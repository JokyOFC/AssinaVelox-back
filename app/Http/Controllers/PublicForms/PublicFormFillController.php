<?php

namespace App\Http\Controllers\PublicForms;

use App\Http\Controllers\Controller;
use App\Services\PublicForms\FillTimer;
use App\Services\PublicForms\PublicFormAvailability;
use App\Services\PublicForms\PublicFormIntake;
use App\Services\PublicForms\PublicFormPresenter;
use App\Services\PublicForms\PublicFormsConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Página pública do formulário (sem login) — docs/fase-2/formulario-publico.md §5 e §6.
 *
 * Leve e sem rastreadores: só o bundle da própria aplicação. `noindex` no cabeçalho e na
 * página. Token desconhecido, rascunho, revogado e flag desligada: a mesma resposta 404.
 */
class PublicFormFillController extends Controller
{
    public function __construct(
        private readonly PublicFormAvailability $availability,
        private readonly PublicFormPresenter $presenter,
    ) {}

    public function show(Request $request, string $token, FillTimer $timer): Response
    {
        $form = $this->availability->resolve($token);
        $state = $this->availability->state($form);

        if ($form === null || $state === PublicFormAvailability::NOT_FOUND) {
            return $this->page($request, ['screen' => 'not_found', 'message' => PublicFormAvailability::message(PublicFormAvailability::NOT_FOUND)], 404);
        }

        $base = [
            'form' => ['title' => $form->title, 'organization_name' => $form->organization->name],
            'privacy' => $this->presenter->privacy($form->organization->name),
        ];

        if ($state !== null) {
            return $this->page($request, $base + ['screen' => $state, 'message' => PublicFormAvailability::message($state)]);
        }

        $submitted = $request->session()->get('public_form_submitted');

        if (is_array($submitted) && ($submitted['form'] ?? null) === $form->ulid) {
            return $this->page($request, $base + [
                'screen' => 'submitted',
                'submitted' => ['email_hint' => $submitted['email_hint'] ?? '', 'ttl_minutes' => $submitted['ttl_minutes'] ?? 0],
            ]);
        }

        return $this->page($request, ['screen' => 'form'] + $this->presenter->fill($form, $timer->issue($form)));
    }

    public function store(Request $request, string $token, PublicFormIntake $intake): RedirectResponse
    {
        $form = $this->availability->resolve($token);
        $state = $this->availability->state($form);

        abort_if($form === null || $state === PublicFormAvailability::NOT_FOUND, 404);

        if ($state !== null) {
            return redirect()->route('form_fill.show', ['token' => $token])
                ->withErrors(['form' => PublicFormAvailability::message($state)]);
        }

        $result = $intake->submit($form, $request);

        return redirect()->route('form_fill.show', ['token' => $token])->with('public_form_submitted', [
            'form' => $form->ulid,
            'email_hint' => $result['email_hint'],
            'ttl_minutes' => PublicFormsConfig::confirmationTtlMinutes(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function page(Request $request, array $props, int $status = 200): Response
    {
        $response = Inertia::render('public-forms/fill', $props)->toResponse($request);
        $response->setStatusCode($status);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
