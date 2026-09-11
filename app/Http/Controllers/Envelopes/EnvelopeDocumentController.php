<?php

namespace App\Http\Controllers\Envelopes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreEnvelopeDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\Envelope;
use App\Services\Documents\DocumentIntake;
use App\Services\Documents\DocumentStorage;
use App\Services\Documents\EnvelopeDocuments;
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
 * Fase 1 (flag `multi_document` desligada): exatamente um documento por envelope. Enviar um
 * segundo arquivo **substitui** o anterior — os campos posicionados sobre ele são apagados e
 * o envelope volta a `draft`, porque a geometria dos campos só faz sentido sobre a versão a
 * que se referem.
 *
 * Fase 2 §2.3 (flag ligada): cada upload acrescenta um arquivo. As rotas existentes aceitam
 * o parâmetro opcional `document` (ULID) para escolher o arquivo em `destroy`, `preview` e
 * `status`; sem ele, valem para o PRIMEIRO arquivo — exatamente o contrato da Fase 1. Um ULID
 * de outro envelope ou de outra organização responde 404.
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
     * Remove um arquivo (e os campos posicionados sobre ele); com um arquivo só, o envelope
     * volta a `draft`.
     */
    public function destroy(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        if (! $this->intake->acceptsUpload($envelope)) {
            return back()->with('error', 'Ação indisponível no status atual.');
        }

        $document = $this->requestedDocument($request, $envelope);

        try {
            $removed = $this->intake->remove($envelope, $request->user(), $request, $document);
        } catch (UploadRejectedException $exception) {
            // A checagem acima decidiu pelo model do route binding; o serviço decide de
            // novo sob lock. Se outra requisição concluiu o envio no meio do caminho, a
            // recusa chega aqui — e é resposta de tela, não erro de servidor.
            return back()->with('error', $exception->getMessage());
        }

        if (! $removed) {
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

        $document = $this->requestedDocument($request, $envelope) ?? $envelope->document;

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

        $payload = DocumentResource::processing($document) + [
            'envelope_status' => $envelope->status->value,
        ];

        $documents = EnvelopeDocuments::ordered($envelope);

        // Vários arquivos: o polling recebe o estado de todos numa só resposta.
        if ($documents->count() > 1) {
            $payload['documents'] = $documents
                ->map(fn (Document $item): array => ['id' => $item->ulid, 'position' => (int) $item->position] + DocumentResource::processing($item))
                ->values()
                ->all();
        }

        return response()->json($payload);
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

        $document = $this->requestedDocument($request, $envelope) ?? $envelope->document;
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

    /**
     * Arquivo pedido por `?document={ulid}`. Ausente → null (o chamador usa o primeiro).
     * Presente e de outro envelope/organização → 404: nunca se confirma a existência.
     */
    private function requestedDocument(Request $request, Envelope $envelope): ?Document
    {
        $ulid = $request->query('document', $request->input('document'));

        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        $document = EnvelopeDocuments::find($envelope, $ulid);

        abort_if($document === null, 404, 'Arquivo não encontrado.');

        return $document;
    }
}
