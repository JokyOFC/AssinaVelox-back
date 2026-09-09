<?php

namespace App\Http\Controllers\Envelopes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreEnvelopeDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Envelope;
use App\Services\Documents\DocumentIntake;
use App\Services\Documents\DocumentStorage;
use App\Services\Documents\Exceptions\UploadRejectedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documento do envelope: upload, remoção, status do processamento e visualização.
 * ROUTES §1.2 · pipeline em docs/preparacao-documental.md.
 *
 * Fase 1: exatamente um documento por envelope. Enviar um segundo arquivo **substitui** o
 * anterior — os campos posicionados sobre ele são apagados e o envelope volta a `draft`,
 * porque a geometria dos campos só faz sentido sobre a versão a que se referem.
 */
class EnvelopeDocumentController extends Controller
{
    public function __construct(
        private readonly DocumentIntake $intake,
        private readonly DocumentStorage $storage,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Upload multipart. Só em envelope `draft`, `preparing` ou `ready`.
     */
    public function store(StoreEnvelopeDocumentRequest $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        if (! $this->intake->acceptsUpload($envelope)) {
            return back()->with('error', 'Ação indisponível no status atual.');
        }

        try {
            $document = $this->intake->store($envelope, $request->document(), $request->user(), $request);
        } catch (UploadRejectedException $exception) {
            $this->logger->info('Upload de documento recusado', [
                'envelope_ulid' => $envelope->ulid,
                'error_code' => $exception->errorCode,
                'context' => $exception->context,
            ]);

            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        return back()->with('success', sprintf('"%s" enviado. Estamos preparando o documento.', $document->original_filename));
    }

    /**
     * Remove o documento (e os campos posicionados sobre ele); o envelope volta a `draft`.
     */
    public function destroy(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        if (! $this->intake->acceptsUpload($envelope)) {
            return back()->with('error', 'Ação indisponível no status atual.');
        }

        if (! $this->intake->remove($envelope, $request->user(), $request)) {
            return back()->with('info', 'Nenhum arquivo para remover.');
        }

        return back()->with('success', 'Arquivo removido.');
    }

    /**
     * Estado do processamento para o polling do wizard (ROUTES §1.2).
     */
    public function status(Request $request, Envelope $envelope): JsonResponse
    {
        Gate::authorize('view', $envelope);

        $document = $envelope->document;

        if ($document === null) {
            return response()->json([
                'status' => null,
                'label' => null,
                'pages' => null,
                'error' => null,
                'failure_code' => null,
                'ready' => false,
                'terminal' => false,
                'progress_pct' => null,
                'envelope_status' => $envelope->status->value,
            ]);
        }

        return response()->json(DocumentResource::processing($document) + [
            'envelope_status' => $envelope->status->value,
        ]);
    }

    /**
     * Transmite o PDF da **versão exibível** (`Content-Type: application/pdf`, `inline`,
     * sem cache). É a fonte do visualizador PDF.js do editor de campos e da lista de
     * miniaturas. Sempre pelo controller autorizado: o disco é privado e nunca há URL
     * pública ou assinada.
     */
    public function preview(Request $request, Envelope $envelope): Response
    {
        Gate::authorize('view', $envelope);

        $document = $envelope->document;
        $version = $document?->currentVersion;

        if ($document === null || $version === null || ! $this->storage->exists($version)) {
            abort(404, 'Documento ainda não disponível para visualização.');
        }

        return $this->storage->stream(
            $version,
            $this->storage->downloadFilename($document->name, 'documento', 'pdf'),
            'inline',
            'application/pdf',
        );
    }

    /**
     * Miniatura PNG por página — **não implementada por decisão de arquitetura**.
     *
     * O rail de miniaturas do wizard é renderizado no navegador pelo próprio PDF.js, a
     * partir do mesmo PDF que o canvas já carregou (`envelopes.document.preview`). Gerar
     * PNG no servidor exigiria um rasterizador (Ghostscript/poppler/pdfium) que o projeto
     * decidiu não ter — o `pdftool` não rasteriza —, e custaria uma chamada de processo e
     * um arquivo por página a cada visita.
     *
     * A rota continua registrada (não é área deste serviço removê-la) e responde 404 com
     * mensagem clara. Ver docs/preparacao-documental.md §"Miniaturas".
     */
    public function page(Request $request, Envelope $envelope, int $page): Response
    {
        Gate::authorize('view', $envelope);

        abort(404, 'Miniaturas são geradas no navegador a partir do PDF; não há imagem no servidor.');
    }
}
