<?php

namespace App\Http\Controllers\Envelopes;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documento do envelope (upload, remoção, status, miniaturas) — ROUTES §1.2.
 * // TODO(Wave B): upload real (disco `documents`), job ProcessDocumentUpload, conversão, miniaturas.
 */
class EnvelopeDocumentController extends Controller
{
    public function store(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:pdf,docx,png,jpg,jpeg',
                'max:'.((int) config('assinavelox.upload.max_mb', 25) * 1024),
            ],
        ], [], ['file' => 'arquivo']);

        // TODO(Wave B): gravar DocumentVersion(kind=original) + sha256, despachar processamento.
        return back()->with('info', 'Upload de documentos estará disponível em breve.');
    }

    public function destroy(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        // TODO(Wave B): remover documento/versões/campos e voltar o envelope para draft.
        return back();
    }

    public function status(Request $request, Envelope $envelope): JsonResponse
    {
        Gate::authorize('view', $envelope);

        $document = $envelope->document;

        return response()->json([
            'status' => $document?->processing_status->value ?? 'uploaded',
            'pages' => $document?->page_count,
            'error' => $document?->failure_message,
            'progress_pct' => null,
        ]);
    }

    public function page(Request $request, Envelope $envelope, int $page): Response
    {
        Gate::authorize('view', $envelope);

        // TODO(Wave B): renderizar miniatura PNG da página via pdftool.
        abort(404, 'Miniatura indisponível.');
    }
}
