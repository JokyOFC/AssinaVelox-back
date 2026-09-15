<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Identity\IdentityFeatures;
use App\Services\Identity\IdentityVideos;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * O remetente exige (ou deixa de exigir) um VÍDEO CURTO de um participante antes do aceite —
 * `PUT documentos/{envelope}/participantes/{recipient}/video`, rota
 * `envelopes.recipients.identity_video` (Fase 3 §3.3, docs/fase-3/captura-de-video.md §2).
 *
 * Corpo: `required` (bool) e `max_seconds` opcional (3 até `capture_video.max_seconds_ceiling`;
 * nulo = padrão). Só no rascunho e só para quem registra aceite. 404 com a flag
 * `identity_video` desligada; envelope/participante de outra organização: 404 pelo binding.
 */
class VideoRequirementController extends Controller
{
    public function __construct(private readonly IdentityVideos $videos) {}

    public function update(Request $request, Envelope $envelope, Recipient $recipient): JsonResponse|RedirectResponse
    {
        abort_unless(IdentityFeatures::identityVideo(CurrentOrganization::instance()->get()), 404);

        Gate::authorize('update', $envelope);

        abort_unless($recipient->envelope_id === $envelope->getKey(), 404);

        $validated = $request->validate([
            'required' => ['required', 'boolean'],
            'max_seconds' => ['nullable', 'integer', 'min:3', 'max:'.IdentityVideos::ceilingSeconds()],
        ], [
            'max_seconds.min' => 'A duração mínima é de 3 segundos.',
            'max_seconds.max' => sprintf('A duração máxima é de %d segundos.', IdentityVideos::ceilingSeconds()),
        ]);

        $required = (bool) $validated['required'];

        $error = match (true) {
            ! $envelope->status->isDraftLike() => 'A exigência de vídeo só pode ser alterada antes do envio.',
            ! $recipient->participates() && $required => 'Visualizadores só recebem cópia: não registram aceite nem enviam vídeo.',
            default => null,
        };

        if ($error !== null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $error, 'errors' => ['required' => [$error]]], 422);
            }

            return back()->withErrors(['required' => $error]);
        }

        $maxSeconds = isset($validated['max_seconds']) ? (int) $validated['max_seconds'] : null;
        $saved = $this->videos->setRequirement($envelope, $recipient, $required, $maxSeconds, $request->user());

        if ($request->expectsJson()) {
            return response()->json(['recipient_id' => $recipient->ulid] + $saved);
        }

        return back()->with('success', $saved['required']
            ? 'Vídeo curto exigido antes do aceite.'
            : 'Vídeo curto não será exigido deste participante.');
    }
}
