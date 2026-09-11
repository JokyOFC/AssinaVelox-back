<?php

namespace App\Http\Controllers\PublicForms;

use App\Http\Controllers\Controller;
use App\Services\PublicForms\PublicFormAvailability;
use App\Services\PublicForms\PublicFormConfirmation;
use App\Services\PublicForms\PublicFormIntake;
use App\Services\PublicForms\PublicFormRefusal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Link de confirmação do e-mail (docs/fase-2/formulario-publico.md §5).
 *
 * GET só mostra a tela e um botão — clientes de e-mail e antivírus que pré-carregam links
 * não confirmam nada. A confirmação (e a geração do envelope) é o POST.
 */
class PublicFormConfirmationController extends Controller
{
    public function __construct(
        private readonly PublicFormAvailability $availability,
        private readonly PublicFormConfirmation $confirmation,
    ) {}

    public function show(Request $request, string $token, string $confirmation): Response
    {
        $form = $this->availability->resolve($token);
        $formState = $this->availability->state($form);

        if ($form === null || $formState === PublicFormAvailability::NOT_FOUND) {
            return $this->page($request, ['screen' => 'not_found', 'message' => PublicFormAvailability::message(PublicFormAvailability::NOT_FOUND)], 404);
        }

        $base = ['form' => ['title' => $form->title, 'organization_name' => $form->organization->name]];
        $submission = $this->confirmation->find($form, $confirmation);

        $done = $request->session()->get('public_form_outcome');

        if ($submission !== null && is_array($done) && ($done['submission'] ?? null) === $submission->ulid) {
            return $this->page($request, $base + ['screen' => 'done', 'outcome' => $done['outcome'] ?? PublicFormConfirmation::OUTCOME_REVIEW]);
        }

        $state = $this->confirmation->state($submission);

        if ($state !== 'confirm') {
            return $this->page($request, $base + ['screen' => $state, 'message' => match ($state) {
                PublicFormRefusal::USED => PublicFormRefusal::used()->getMessage(),
                PublicFormRefusal::EXPIRED => PublicFormRefusal::expired()->getMessage(),
                default => PublicFormRefusal::invalid()->getMessage(),
            }], $state === PublicFormRefusal::INVALID ? 404 : 200);
        }

        if ($formState !== null) {
            return $this->page($request, $base + ['screen' => 'unavailable', 'message' => PublicFormAvailability::message($formState)]);
        }

        $email = (string) ($submission?->payload['email'] ?? '');

        return $this->page($request, $base + [
            'screen' => 'confirm',
            'email_hint' => $email !== '' ? PublicFormIntake::maskEmail($email) : null,
        ]);
    }

    public function store(Request $request, string $token, string $confirmation): RedirectResponse
    {
        $form = $this->availability->resolve($token);

        abort_if($form === null || $this->availability->state($form) === PublicFormAvailability::NOT_FOUND, 404);

        $back = redirect()->route('form_fill.confirm.show', ['token' => $token, 'confirmation' => $confirmation]);

        try {
            $result = $this->confirmation->confirm($form, $confirmation);
        } catch (PublicFormRefusal $refusal) {
            return $back->withErrors(['confirmation' => $refusal->getMessage()]);
        }

        return $back->with('public_form_outcome', [
            'submission' => $result['submission']->ulid,
            'outcome' => $result['outcome'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function page(Request $request, array $props, int $status = 200): Response
    {
        $response = Inertia::render('public-forms/confirm', $props)->toResponse($request);
        $response->setStatusCode($status);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
