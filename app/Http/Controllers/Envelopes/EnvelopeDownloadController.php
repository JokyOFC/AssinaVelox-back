<?php

namespace App\Http\Controllers\Envelopes;

use App\Enums\EnvelopeStatus;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download autorizado (ROUTES §1.2 envelopes.download): type ∈ original | signed | evidence.
 * // TODO(Wave B/C): stream do disco privado `documents` + auditoria envelope.downloaded.
 */
class EnvelopeDownloadController extends Controller
{
    public function show(Request $request, Envelope $envelope, string $type): Response
    {
        Gate::authorize('download', $envelope);

        abort_unless(in_array($type, ['original', 'signed', 'evidence'], true), 404);

        if ($type !== 'original' && $envelope->status !== EnvelopeStatus::Completed) {
            abort(409, 'Arquivo disponível apenas após a conclusão do documento.');
        }

        // TODO(Wave B): localizar DocumentVersion (original/final/evidence) e devolver via Storage::disk('documents')->response().
        abort(404, 'Arquivo indisponível.');
    }
}
