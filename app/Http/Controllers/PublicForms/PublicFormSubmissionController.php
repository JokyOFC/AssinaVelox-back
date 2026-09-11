<?php

namespace App\Http\Controllers\PublicForms;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicForms\Middleware\EnsurePublicFormsFeature;
use App\Models\PublicFormSubmission;
use App\Services\PublicForms\PublicFormReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;

/**
 * Fila de revisão: aprovar (envia) ou recusar (exclui o rascunho) um envio confirmado.
 * O binding de `{submission}` é escopado pela organização corrente.
 */
class PublicFormSubmissionController extends Controller implements HasMiddleware
{
    public function __construct(private readonly PublicFormReview $review) {}

    public static function middleware(): array
    {
        return [new Middleware(EnsurePublicFormsFeature::class)];
    }

    public function approve(Request $request, PublicFormSubmission $submission): RedirectResponse
    {
        Gate::authorize('approve', $submission->form);

        $this->review->approve($submission, $request->user());

        return back()->with('success', 'Documento enviado para assinatura.');
    }

    public function reject(Request $request, PublicFormSubmission $submission): RedirectResponse
    {
        Gate::authorize('review', $submission->form);

        $this->review->reject($submission, $request->user());

        return back()->with('success', 'Envio recusado. O rascunho foi excluído e ninguém foi convidado.');
    }
}
