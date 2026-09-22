<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Identity\IdentityCaptures;
use App\Services\Identity\IdentityFeatures;
use App\Services\Identity\IdentityVerifications;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * O remetente exige (ou deixa de exigir) a VERIFICAÇÃO FACIAL COM DOCUMENTO de um participante
 * antes do aceite — `PUT documentos/{envelope}/participantes/{recipient}/verificacao-facial`,
 * rota `envelopes.recipients.identity_verification` (Fase 4 §4.1, docs/fase-4/verificacao-facial.md).
 *
 * Corpo: `required` (bool). Ligar acrescenta as três fotos (rosto, frente e verso) à exigência
 * de fotos do participante; desligar deixa as fotos como estão. Só no rascunho e só para quem
 * registra aceite. 404 com a flag `identity_verification` desligada (ou sem `identity_capture`);
 * envelope/participante de outra organização: 404 pelo binding.
 */
class VerificationRequirementController extends Controller
{
    public function __construct(
        private readonly IdentityVerifications $verifications,
        private readonly IdentityCaptures $captures,
    ) {}

    public function update(Request $request, Envelope $envelope, Recipient $recipient): JsonResponse|RedirectResponse
    {
        abort_unless(IdentityFeatures::identityVerification(CurrentOrganization::instance()->get()), 404);

        Gate::authorize('update', $envelope);

        abort_unless($recipient->envelope_id === $envelope->getKey(), 404);

        $validated = $request->validate([
            'required' => ['required', 'boolean'],
        ]);

        $required = (bool) $validated['required'];

        $error = match (true) {
            ! $envelope->status->isDraftLike() => 'A exigência de verificação facial só pode ser alterada antes do envio.',
            ! $recipient->participates() && $required => 'Visualizadores só recebem cópia: não registram aceite nem passam pela verificação.',
            default => null,
        };

        if ($error !== null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $error, 'errors' => ['required' => [$error]]], 422);
            }

            return back()->withErrors(['required' => $error]);
        }

        $saved = $this->verifications->setRequirement($envelope, $recipient, $required, $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'recipient_id' => $recipient->ulid,
                'required' => $saved,
                // As fotos que a exigência passou a pedir (ligar acrescenta rosto, frente e verso).
                'capture_kinds' => $this->captures->requirementsForEnvelope($envelope)[$recipient->ulid] ?? [],
                'provider_label' => $this->verifications->provider()->label(),
            ]);
        }

        return back()->with('success', $saved
            ? 'Verificação facial com documento exigida antes do aceite.'
            : 'Verificação facial com documento não será exigida deste participante.');
    }
}
