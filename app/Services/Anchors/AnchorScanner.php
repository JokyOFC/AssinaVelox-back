<?php

namespace App\Services\Anchors;

use App\Enums\DocumentProcessingStatus;
use App\Integrations\Ocr\OcrEngines;
use App\Models\AnchorScan;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\FieldSuggestion;
use App\Models\Template;
use App\Models\User;
use App\Services\Anchors\Jobs\DetectFieldAnchors;
use App\Services\Anchors\Jobs\RunAnchorOcr;
use App\Services\Documents\DocumentStorage;
use App\Services\Documents\EnvelopeDocuments;
use App\Services\Envelopes\EnvelopeReadiness;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orquestra as buscas de âncoras (Fase 3 §3.2 — docs/fase-3/ancoras-e-ocr.md §3).
 *
 *  1. Pedido (editor ou uso de modelo) → `anchor_scans` pendente → job na fila `anchors`.
 *  2. Job: copia a versão exibível para um temporário exclusivo, roda `pdftool find-anchors`
 *     e grava as ocorrências como SUGESTÕES pendentes ({@see SuggestionBuilder}).
 *  3. Páginas sem texto: com a flag `ocr` e o motor disponível, uma segunda busca (`trigger =
 *     ocr`) vai para a fila `ocr`; sem o motor, `documents.ocr_status = unavailable` — o fluxo
 *     manual continua valendo.
 *
 * Nenhuma transação fica aberta durante o processo Python (arquitetura §3.3). Toda falha
 * termina como busca `failed` com um código estável; nunca bloqueia o preparo manual.
 */
class AnchorScanner
{
    public function __construct(
        private readonly AnchorFinder $finder,
        private readonly SuggestionBuilder $builder,
        private readonly DocumentStorage $storage,
        private readonly PdfToolClient $pdftool,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Busca manual pedida no editor: uma busca por arquivo pronto. Sugestões pendentes e
     * buscas em andamento anteriores daquele arquivo saem de cena (`superseded` / `failed`).
     *
     * @return list<AnchorScan>
     *
     * @throws ValidationException
     */
    public function requestManual(Envelope $envelope, User $user, AnchorQuery $query): array
    {
        if ($query->isEmpty()) {
            throw ValidationException::withMessages(['markers' => AnchorDetectionException::messageFor('nothing_to_search')]);
        }

        $scans = DB::transaction(function () use ($envelope, $user, $query): array {
            $locked = Envelope::withoutOrganizationScope()->whereKey($envelope->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Envelope || ! $locked->status->isDraftLike()) {
                throw ValidationException::withMessages(['anchors' => 'A detecção só vale para documentos em preparo.']);
            }

            $scans = [];

            foreach (EnvelopeDocuments::ordered($locked) as $document) {
                if ($document->processing_status !== DocumentProcessingStatus::Ready || $document->current_version_id === null) {
                    continue;
                }

                $this->supersede($document);
                $scans[] = $this->newScan($locked, $document, (int) $document->current_version_id, AnchorScan::TRIGGER_MANUAL, $query, $user);
            }

            return $scans;
        });

        if ($scans === []) {
            throw ValidationException::withMessages(['anchors' => 'Nenhum arquivo está pronto para a detecção. Aguarde o processamento terminar.']);
        }

        foreach ($scans as $scan) {
            DetectFieldAnchors::dispatch($scan->getKey());
        }

        EnvelopeReadiness::refresh($envelope->fresh() ?? $envelope);

        return $scans;
    }

    /**
     * Uso de modelo com regras: uma busca no primeiro documento, que espera a conversão
     * terminar (HTML/DOCX). Despachada só depois do commit de quem criou o envelope.
     */
    public function scheduleForTemplate(Envelope $envelope, Template $template, AnchorQuery $query, ?User $user): ?AnchorScan
    {
        $document = EnvelopeDocuments::ordered($envelope)->first();

        if (! $document instanceof Document || $query->isEmpty()) {
            return null;
        }

        $scan = $this->newScan($envelope, $document, $document->current_version_id, AnchorScan::TRIGGER_TEMPLATE, $query, $user, $template->getKey());

        DetectFieldAnchors::dispatch($scan->getKey())->afterCommit();

        return $scan;
    }

    /**
     * Executa uma busca no texto do PDF. Devolve `false` quando o documento ainda está sendo
     * processado e a busca deve esperar (o job se reagenda).
     */
    public function run(AnchorScan $scan): bool
    {
        $scan->refresh();

        if (! $scan->status->isActive()) {
            return true;
        }

        [$envelope, $document, $version, $error, $wait] = $this->target($scan);

        if ($wait) {
            return false;
        }

        if ($error !== null || ! $envelope instanceof Envelope || ! $document instanceof Document || ! $version instanceof DocumentVersion) {
            $this->fail($scan, $error ?? 'document_replaced', $envelope);

            return true;
        }

        $scan->forceFill([
            'document_version_id' => $version->getKey(),
            'status' => AnchorScanStatus::Running,
            'started_at' => now(),
            'attempts' => $scan->attempts + 1,
        ])->save();

        $query = AnchorQuery::fromArray($scan->query);

        try {
            $detection = $this->detect($version, fn (string $path): AnchorDetection => $this->finder->find($path, $query));
        } catch (AnchorDetectionException $exception) {
            $this->fail($scan, $exception->errorCode, $envelope);

            return true;
        }

        $created = $this->builder->create($scan, $envelope, $document, $version, $detection->matches, $query, FieldSuggestion::VIA_TEXT);

        $scan->forceFill([
            'status' => AnchorScanStatus::Done,
            'pages_scanned' => $detection->pageCount,
            'pages_without_text' => $detection->pagesWithoutText,
            'matches_count' => min(65535, count($detection->matches)),
            'suggestions_count' => $created,
            'truncated' => $detection->truncated,
            'finished_at' => now(),
        ])->save();

        $this->afterText($scan, $envelope, $document, $detection, $query);

        EnvelopeReadiness::refresh($envelope);

        return true;
    }

    /**
     * Passagem de OCR (job RunAnchorOcr) nas páginas sem texto da busca-mãe.
     */
    public function runOcr(AnchorScan $scan): void
    {
        $scan->refresh();

        if (! $scan->status->isActive()) {
            return;
        }

        [$envelope, $document, $version, $error] = $this->target($scan);

        if ($error !== null || ! $envelope instanceof Envelope || ! $document instanceof Document || ! $version instanceof DocumentVersion) {
            $this->fail($scan, $error ?? 'document_replaced', $envelope);

            return;
        }

        $engine = OcrEngines::make();

        $scan->forceFill(['status' => AnchorScanStatus::Running, 'started_at' => now(), 'attempts' => $scan->attempts + 1, 'ocr_engine' => $engine->name()])->save();

        if (! $engine->availability()->available) {
            $this->setOcrStatus($document, OcrStatus::Unavailable);
            $this->fail($scan, 'ocr_unavailable', $envelope);

            return;
        }

        $query = AnchorQuery::fromArray($scan->query);
        $pages = array_values(array_filter(
            is_array($scan->query['ocr_pages'] ?? null) ? $scan->query['ocr_pages'] : [],
            static fn (mixed $page): bool => is_int($page) && $page >= 1,
        ));

        try {
            $detection = $this->detect($version, fn (string $path): AnchorDetection => $engine->detect($path, $query, $pages));
        } catch (AnchorDetectionException $exception) {
            $this->setOcrStatus($document, $exception->errorCode === 'ocr_unavailable' ? OcrStatus::Unavailable : OcrStatus::Failed);
            $this->fail($scan, $exception->errorCode, $envelope);

            return;
        }

        $matches = array_values(array_filter($detection->matches, static fn (AnchorMatch $match): bool => $match->viaOcr()));
        $created = $this->builder->create($scan, $envelope, $document, $version, $matches, $query, FieldSuggestion::VIA_OCR);
        $done = $detection->ocrDonePages();

        $this->setOcrStatus($document, $done !== [] ? OcrStatus::Done : OcrStatus::Failed);

        $scan->forceFill([
            'status' => $done !== [] ? AnchorScanStatus::Done : AnchorScanStatus::Failed,
            'failure_code' => $done !== [] ? null : 'ocr_failed',
            'pages_scanned' => count($done),
            'pages_without_text' => $pages,
            'matches_count' => min(65535, count($matches)),
            'suggestions_count' => $created,
            'truncated' => $detection->truncated,
            'finished_at' => now(),
        ])->save();

        EnvelopeReadiness::refresh($envelope);
    }

    /**
     * Marca como falha uma busca que o job não conseguiu concluir (tentativas esgotadas).
     */
    public function markFailed(int $scanId, string $code): void
    {
        $scan = AnchorScan::withoutOrganizationScope()->find($scanId);

        if (! $scan instanceof AnchorScan || ! $scan->status->isActive()) {
            return;
        }

        $envelope = Envelope::withoutOrganizationScope()->find($scan->envelope_id);

        if ($scan->isOcr()) {
            Document::withoutOrganizationScope()->whereKey($scan->document_id)->update(['ocr_status' => OcrStatus::Failed->value]);
        }

        $this->fail($scan, $code, $envelope);
    }

    // -- Internos -----------------------------------------------------------------------

    /**
     * @param  callable(string): AnchorDetection  $callback
     *
     * @throws AnchorDetectionException
     */
    private function detect(DocumentVersion $version, callable $callback): AnchorDetection
    {
        $directory = $this->pdftool->temporaryDirectory('anchors-doc-');

        try {
            try {
                $path = $this->storage->copyToTemporary($version, $directory, 'documento.pdf');
            } catch (Throwable $exception) {
                $this->logger->warning('anchors: arquivo da versão indisponível', ['version' => $version->ulid, 'error' => $exception::class]);

                throw AnchorDetectionException::make('file_missing');
            }

            return $callback($path);
        } finally {
            $directory->delete();
        }
    }

    /**
     * Páginas sem texto → OCR (se a flag e o motor permitirem) ou o status honesto.
     */
    private function afterText(AnchorScan $scan, Envelope $envelope, Document $document, AnchorDetection $detection, AnchorQuery $query): void
    {
        if ($detection->pagesWithoutText === []) {
            $this->setOcrStatus($document, OcrStatus::NotNeeded);

            return;
        }

        // OCR fora do plano: o status fica como está; a tela conta as páginas sem texto.
        if (! AnchorFeatures::ocr($envelope->organization)) {
            return;
        }

        $engine = OcrEngines::make();

        if (! $engine->availability()->available) {
            $this->setOcrStatus($document, OcrStatus::Unavailable);

            return;
        }

        $maxPages = max(0, (int) config('assinavelox.ocr.max_pages', 20));

        if ($maxPages === 0) {
            $this->setOcrStatus($document, OcrStatus::Unavailable);

            return;
        }

        $ocrQuery = [...$query->toArray(), 'ocr_pages' => array_slice($detection->pagesWithoutText, 0, $maxPages)];

        $ocrScan = new AnchorScan;
        $ocrScan->forceFill([
            'organization_id' => $scan->organization_id,
            'envelope_id' => $scan->envelope_id,
            'document_id' => $scan->document_id,
            'document_version_id' => $scan->document_version_id,
            'parent_id' => $scan->getKey(),
            'template_id' => $scan->template_id,
            'requested_by_user_id' => $scan->requested_by_user_id,
            'trigger' => AnchorScan::TRIGGER_OCR,
            'status' => AnchorScanStatus::Pending,
            'query' => $ocrQuery,
            'ocr_engine' => $engine->name(),
        ])->save();

        $this->setOcrStatus($document, OcrStatus::Pending);

        RunAnchorOcr::dispatch($ocrScan->getKey());
    }

    /**
     * Envelope, documento e versão da busca — ou o motivo para não seguir.
     *
     * @return array{0: Envelope|null, 1: Document|null, 2: DocumentVersion|null, 3: string|null, 4: bool}
     */
    private function target(AnchorScan $scan): array
    {
        $envelope = Envelope::withoutOrganizationScope()->find($scan->envelope_id);
        $document = Document::withoutOrganizationScope()->find($scan->document_id);

        if (! $envelope instanceof Envelope || ! $document instanceof Document) {
            return [$envelope, $document, null, 'document_replaced', false];
        }

        if (! $envelope->status->isDraftLike()) {
            return [$envelope, $document, null, 'envelope_locked', false];
        }

        if ($document->processing_status !== DocumentProcessingStatus::Ready || $document->current_version_id === null) {
            return $document->processing_status->isTerminal()
                ? [$envelope, $document, null, 'document_not_ready', false]
                : [$envelope, $document, null, null, true];
        }

        if ($scan->document_version_id !== null && (int) $scan->document_version_id !== (int) $document->current_version_id) {
            return [$envelope, $document, null, 'document_replaced', false];
        }

        $version = DocumentVersion::withoutOrganizationScope()->find($document->current_version_id);

        return $version instanceof DocumentVersion
            ? [$envelope, $document, $version, null, false]
            : [$envelope, $document, null, 'file_missing', false];
    }

    private function fail(AnchorScan $scan, string $code, ?Envelope $envelope): void
    {
        $scan->forceFill([
            'status' => AnchorScanStatus::Failed,
            'failure_code' => mb_substr($code, 0, 64),
            'finished_at' => now(),
        ])->save();

        if ($envelope instanceof Envelope) {
            EnvelopeReadiness::refresh($envelope);
        }
    }

    /**
     * Nova busca no mesmo documento: as sugestões pendentes anteriores deixam de valer e as
     * buscas em andamento são encerradas (o resultado delas seria duplicado).
     */
    private function supersede(Document $document): void
    {
        FieldSuggestion::withoutOrganizationScope()
            ->where('document_id', $document->getKey())
            ->where('status', SuggestionStatus::Pending->value)
            ->update(['status' => SuggestionStatus::Superseded->value, 'updated_at' => now()]);

        AnchorScan::withoutOrganizationScope()
            ->where('document_id', $document->getKey())
            ->whereIn('status', [AnchorScanStatus::Pending->value, AnchorScanStatus::Running->value])
            ->update(['status' => AnchorScanStatus::Failed->value, 'failure_code' => 'superseded', 'finished_at' => now(), 'updated_at' => now()]);
    }

    private function newScan(
        Envelope $envelope,
        Document $document,
        ?int $versionId,
        string $trigger,
        AnchorQuery $query,
        ?User $user,
        ?int $templateId = null,
    ): AnchorScan {
        $scan = new AnchorScan;
        $scan->forceFill([
            'organization_id' => $envelope->organization_id,
            'envelope_id' => $envelope->getKey(),
            'document_id' => $document->getKey(),
            'document_version_id' => $versionId,
            'template_id' => $templateId,
            'requested_by_user_id' => $user?->getKey(),
            'trigger' => $trigger,
            'status' => AnchorScanStatus::Pending,
            'query' => $query->toArray(),
        ])->save();

        return $scan;
    }

    private function setOcrStatus(Document $document, OcrStatus $status): void
    {
        Document::withoutOrganizationScope()->whereKey($document->getKey())->update(['ocr_status' => $status->value]);
        $document->setAttribute('ocr_status', $status->value);
    }
}
