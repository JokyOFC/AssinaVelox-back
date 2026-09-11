<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Identity\CaptureKind;
use App\Services\Identity\IdentityCaptures;
use App\Services\Identity\IdentityFeatures;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * O remetente exige (ou deixa de exigir) fotos de um participante antes do aceite —
 * `PUT documentos/{envelope}/participantes/{recipient}/captura`, rota
 * `envelopes.recipients.identity_capture`.
 *
 * Corpo: `kinds` ⊂ {selfie, document_front, document_back}; lista vazia remove a exigência.
 * `document_back` só junto com `document_front`. Só no rascunho (a lista de participantes
 * congela no envio) e só para quem registra aceite (visualizador não). 404 com a flag
 * `identity_capture` desligada. Envelope e participante de outra organização: 404 pelo
 * binding escopado.
 */
class CaptureRequirementController extends Controller
{
    public function __construct(private readonly IdentityCaptures $captures) {}

    public function update(Request $request, Envelope $envelope, Recipient $recipient): JsonResponse|RedirectResponse
    {
        abort_unless(IdentityFeatures::identityCapture(CurrentOrganization::instance()->get()), 404);

        Gate::authorize('update', $envelope);

        abort_unless($recipient->envelope_id === $envelope->getKey(), 404);

        $validated = $request->validate([
            'kinds' => ['present', 'array', 'max:3'],
            'kinds.*' => ['string', 'distinct', Rule::in(CaptureKind::values())],
        ], [
            'kinds.*.in' => 'Tipo de foto inválido.',
        ]);

        /** @var list<string> $kinds */
        $kinds = array_values($validated['kinds']);

        $error = match (true) {
            ! $envelope->status->isDraftLike() => 'A exigência de fotos só pode ser alterada antes do envio.',
            ! $recipient->participates() && $kinds !== [] => 'Visualizadores só recebem cópia: não registram aceite nem enviam fotos.',
            in_array(CaptureKind::DocumentBack->value, $kinds, true) && ! in_array(CaptureKind::DocumentFront->value, $kinds, true) => 'O verso do documento só pode ser exigido junto com a frente.',
            default => null,
        };

        if ($error !== null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $error, 'errors' => ['kinds' => [$error]]], 422);
            }

            return back()->withErrors(['kinds' => $error]);
        }

        $saved = $this->captures->setRequirement($envelope, $recipient, $kinds, $request->user());

        if ($request->expectsJson()) {
            return response()->json(['recipient_id' => $recipient->ulid, 'kinds' => $saved]);
        }

        return back()->with('success', $saved === []
            ? 'Fotos não serão exigidas deste participante.'
            : 'Fotos exigidas antes do aceite atualizadas.');
    }
}
