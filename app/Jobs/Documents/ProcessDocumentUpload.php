<?php

namespace App\Jobs\Documents;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentSourceType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Integrations\Dto\ConversionRequest;
use App\Integrations\Dto\ConversionResult;
use App\Integrations\Exceptions\ConverterNotConfiguredException;
use App\Integrations\Exceptions\UnsupportedSourceTypeException;
use App\Integrations\Pdf\PdfConverterManager;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Services\Documents\DocumentAuditTrail;
use App\Services\Documents\DocumentStorage;
use App\Services\Documents\EnvelopeReadiness;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\TemporaryDirectory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Produz a versão exibível do documento (arquitetura §5, itens 2 e 3).
 *
 * Fila `conversions`. **Idempotente**: um documento já `ready` com versão exibível é
 * ignorado, e a versão convertida é reaproveitada em vez de recriada — reprocessar não
 * duplica versões nem bytes. `ShouldBeUnique` por documento evita dois workers na mesma
 * conversão.
 *
 * Por tipo de origem:
 *
 * - **PDF** — `PassthroughPdfConverter` apenas inspeciona. Cifrado, inválido ou **já
 *   assinado digitalmente** → `blocked`: preparar o arquivo reescreveria os bytes e
 *   invalidaria as assinaturas existentes. O original é preservado e o envelope volta a
 *   `draft`. Caso normal, a **própria versão original** vira a versão exibível: os bytes
 *   não são duplicados, só os metadados derivados (page_count, pages_meta, is_encrypted,
 *   has_signatures) são preenchidos uma única vez. `storage_path`, `size_bytes` e
 *   `sha256` da original nunca mudam.
 * - **DOCX** — `LibreOfficeConverter` gera uma `DocumentVersion(kind=converted)`. Sem
 *   LibreOffice configurado (o caso da máquina de desenvolvimento) o documento fica
 *   `failed` com mensagem explícita: a conversão **não** acontece e nada é simulado.
 * - **Imagem** — `ImageToPdfConverter` (GD + `pdftool image2pdf`) gera uma
 *   `DocumentVersion(kind=converted)` de uma página.
 *
 * O diretório temporário é exclusivo desta execução e removido em `finally`, inclusive
 * quando a conversão termina em exceção.
 */
class ProcessDocumentUpload implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Tentativas antes de considerar o job falho (falhas de infraestrutura). */
    public int $tries = 3;

    /** Trava de unicidade: liberada por tempo caso o worker morra sem finalizar. */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $documentId,
        public readonly int $organizationId,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('assinavelox.queues.conversions', 'conversions'));
    }

    public function uniqueId(): string
    {
        return 'document:'.$this->documentId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function handle(
        PdfConverterManager $converters,
        DocumentStorage $storage,
        PdfToolClient $client,
        DocumentAuditTrail $audit,
        EnvelopeReadiness $readiness,
        LoggerInterface $logger,
    ): void {
        $document = $this->findDocument();

        if ($document === null) {
            return;
        }

        $envelope = $document->envelope;

        if ($envelope === null) {
            return;
        }

        // Idempotência: já concluído com versão exibível registrada.
        if ($document->processing_status === DocumentProcessingStatus::Ready && $document->current_version_id !== null) {
            $logger->debug('ProcessDocumentUpload: documento já processado, nada a fazer', [
                'document_ulid' => $document->ulid,
                'correlation_id' => $this->correlationId,
            ]);

            return;
        }

        $original = $document->versions()
            ->where('kind', DocumentVersionKind::Original->value)
            ->orderBy('version_number')
            ->first();

        if ($original === null || ! $storage->exists($original)) {
            $this->markFailed(
                $document,
                $envelope,
                'missing_original',
                'O arquivo enviado não foi encontrado para processamento. Envie o documento novamente.',
                $audit,
                $readiness,
            );

            return;
        }

        $correlationId = $this->correlationId ?? (string) Str::ulid();

        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::Converting->value,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        if ($envelope->status->isDraftLike() && $envelope->status !== EnvelopeStatus::Preparing) {
            $envelope->status = EnvelopeStatus::Preparing;
            $envelope->save();
        }

        $audit->record($envelope, AuditEventType::DocumentConversionStarted, [
            'document_ulid' => $document->ulid,
            'source_type' => $document->source_type->value,
        ], null, null, $correlationId);

        $workDir = TemporaryDirectory::create($client->temporaryRoot(), 'doc-');

        try {
            $extension = pathinfo($original->storage_path, PATHINFO_EXTENSION) ?: 'bin';
            $inputPath = $storage->copyToTemporary($original, $workDir, 'original.'.$extension);

            // PDF: entrada e saída no mesmo caminho — o passthrough não copia bytes à toa.
            $outputPath = $document->source_type === DocumentSourceType::Pdf
                ? $inputPath
                : $workDir->path('converted.pdf');

            $result = $converters->convert(new ConversionRequest(
                inputPath: $inputPath,
                outputPath: $outputPath,
                sourceType: $document->source_type,
                mimeType: $original->mime_type,
                originalFilename: $document->original_filename,
                correlationId: $correlationId,
            ));

            if ($result->isBlocked()) {
                $this->markBlocked($document, $envelope, $result, $audit, $readiness, $original, $correlationId);

                return;
            }

            if (! $result->isReady() || $result->inspection === null) {
                $this->markFailed(
                    $document,
                    $envelope,
                    $result->reasonCode ?? 'conversion_failed',
                    $result->reasonMessage ?? 'Não foi possível processar o arquivo enviado.',
                    $audit,
                    $readiness,
                    $correlationId,
                );

                return;
            }

            $displayable = $document->source_type === DocumentSourceType::Pdf
                ? $this->adoptOriginalAsDisplayable($original, $result)
                : $this->storeConvertedVersion($document, $envelope, $result, $storage, $correlationId);

            $document->forceFill([
                'current_version_id' => $displayable->getKey(),
                'page_count' => $displayable->page_count,
                'processing_status' => DocumentProcessingStatus::Ready->value,
                'failure_code' => null,
                'failure_message' => null,
            ])->save();

            $audit->record($envelope, AuditEventType::DocumentConverted, [
                'document_ulid' => $document->ulid,
                'document_version_ulid' => $displayable->ulid,
                'version_kind' => $displayable->kind->value,
                'converter' => $result->converter,
                'page_count' => $displayable->page_count,
                'sha256' => $displayable->sha256,
            ], null, null, $correlationId);

            $envelope->setRelation('document', $document->fresh() ?? $document);
            $readiness->recompute($envelope);
        } catch (ConverterNotConfiguredException|UnsupportedSourceTypeException $exception) {
            // Falta de dependência externa (LibreOffice, venv do pdftool): repetir não
            // resolve. O documento fica `failed` com uma mensagem honesta — em nenhuma
            // hipótese se finge que a conversão aconteceu.
            $logger->error('ProcessDocumentUpload: conversor indisponível para o tipo de origem', [
                'document_ulid' => $document->ulid,
                'source_type' => $document->source_type->value,
                'exception' => $exception->getMessage(),
                'correlation_id' => $correlationId,
            ]);

            $this->markFailed(
                $document,
                $envelope,
                'converter_not_configured',
                $this->converterUnavailableMessage($document->source_type),
                $audit,
                $readiness,
                $correlationId,
            );
        } finally {
            // Falhas de infraestrutura (timeout, processo que não inicia) sobem: o job
            // tenta de novo e, esgotadas as tentativas, `failed()` marca o documento.
            $workDir->delete();
        }
    }

    /**
     * Última tentativa esgotada (ou exceção não capturada): o documento não pode ficar
     * eternamente em `converting`.
     */
    public function failed(Throwable $exception): void
    {
        $document = $this->findDocument();

        if ($document === null || $document->processing_status->isTerminal()) {
            return;
        }

        $envelope = $document->envelope;

        if ($envelope === null) {
            return;
        }

        app(LoggerInterface::class)->error('ProcessDocumentUpload: processamento falhou definitivamente', [
            'document_ulid' => $document->ulid,
            'source_type' => $document->source_type->value,
            'exception' => $exception->getMessage(),
            'correlation_id' => $this->correlationId,
        ]);

        $this->markFailed(
            $document,
            $envelope,
            'processing_error',
            'Não foi possível processar o arquivo. Tente enviar novamente; se o problema continuar, envie o documento em PDF.',
            app(DocumentAuditTrail::class),
            app(EnvelopeReadiness::class),
            $this->correlationId,
        );
    }

    // -- Passos ------------------------------------------------------------------------

    private function findDocument(): ?Document
    {
        /** @var Document|null $document */
        $document = Document::forOrganization($this->organizationId)
            ->whereKey($this->documentId)
            ->first();

        return $document;
    }

    /**
     * PDF válido: a versão original É a versão exibível. Os bytes não são copiados; só os
     * metadados derivados do `inspect` são preenchidos (uma única vez).
     */
    private function adoptOriginalAsDisplayable(DocumentVersion $original, ConversionResult $result): DocumentVersion
    {
        $inspection = $result->inspection;

        if ($inspection === null) {
            return $original;
        }

        $original->forceFill([
            'page_count' => $inspection->pageCount,
            'pages_meta' => $inspection->pagesMeta(),
            'is_encrypted' => $inspection->encrypted,
            'has_signatures' => $inspection->hasSignatures,
        ])->save();

        return $original;
    }

    /**
     * DOCX/imagem: nova `DocumentVersion(kind=converted)`. Se já existir uma (reprocesso),
     * ela é reaproveitada — nada é duplicado.
     */
    private function storeConvertedVersion(
        Document $document,
        Envelope $envelope,
        ConversionResult $result,
        DocumentStorage $storage,
        string $correlationId,
    ): DocumentVersion {
        $existing = $document->versions()
            ->where('kind', DocumentVersionKind::Converted->value)
            ->orderByDesc('version_number')
            ->first();

        if ($existing !== null && $storage->exists($existing)) {
            return $existing;
        }

        $outputPath = (string) $result->outputPath;
        $inspection = $result->inspection;

        if ($outputPath === '' || ! is_file($outputPath) || $inspection === null) {
            throw new \RuntimeException('Conversão concluída sem PDF de saída.');
        }

        $sha256 = hash_file('sha256', $outputPath);

        if ($sha256 === false) {
            throw new \RuntimeException('Não foi possível calcular o hash do PDF convertido.');
        }

        $versionUlid = $storage->newVersionUlid();
        $storagePath = $storage->pathFor($envelope, $versionUlid, 'pdf');
        $storage->putFile($outputPath, $storagePath);

        $version = new DocumentVersion;
        $version->forceFill([
            'ulid' => $versionUlid,
            'document_id' => $document->getKey(),
            'organization_id' => $document->organization_id,
            'version_number' => $document->nextVersionNumber(),
            'kind' => DocumentVersionKind::Converted->value,
            'storage_disk' => DocumentStorage::DISK,
            'storage_path' => $storagePath,
            'mime_type' => 'application/pdf',
            'size_bytes' => (int) filesize($outputPath),
            'sha256' => $sha256,
            'page_count' => $inspection->pageCount,
            'pages_meta' => $inspection->pagesMeta(),
            'is_encrypted' => $inspection->encrypted,
            'has_signatures' => $inspection->hasSignatures,
            'created_by_type' => ActorType::System->value,
            'created_by_id' => null,
        ]);
        $version->save();

        return $version;
    }

    private function markBlocked(
        Document $document,
        Envelope $envelope,
        ConversionResult $result,
        DocumentAuditTrail $audit,
        EnvelopeReadiness $readiness,
        DocumentVersion $original,
        string $correlationId,
    ): void {
        if ($result->inspection !== null) {
            // Registra na versão original POR QUE ela não pode ser preparada. Os bytes,
            // o caminho e o sha256 continuam intocados.
            $original->forceFill([
                'is_encrypted' => $result->inspection->encrypted,
                'has_signatures' => $result->inspection->hasSignatures,
                'page_count' => $result->inspection->pageCount > 0 ? $result->inspection->pageCount : null,
            ])->save();
        }

        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::Blocked->value,
            'failure_code' => $result->reasonCode ?? 'blocked',
            'failure_message' => $result->reasonMessage ?? 'O arquivo não pode ser preparado para assinatura.',
            'current_version_id' => null,
        ])->save();

        $audit->record($envelope, AuditEventType::DocumentBlocked, [
            'document_ulid' => $document->ulid,
            'failure_code' => $document->failure_code,
            'converter' => $result->converter,
            'has_signatures' => $result->inspection?->hasSignatures,
            'encrypted' => $result->inspection?->encrypted,
        ], null, null, $correlationId);

        $envelope->setRelation('document', $document);
        $readiness->recompute($envelope);
    }

    private function markFailed(
        Document $document,
        Envelope $envelope,
        string $code,
        string $message,
        DocumentAuditTrail $audit,
        EnvelopeReadiness $readiness,
        ?string $correlationId = null,
    ): void {
        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::Failed->value,
            'failure_code' => $code,
            'failure_message' => $message,
            'current_version_id' => null,
        ])->save();

        $audit->record($envelope, AuditEventType::DocumentProcessingFailed, [
            'document_ulid' => $document->ulid,
            'failure_code' => $code,
            'source_type' => $document->source_type->value,
        ], null, null, $correlationId);

        $envelope->setRelation('document', $document);
        $readiness->recompute($envelope);
    }

    /**
     * Mensagem honesta quando o conversor do tipo não está disponível: o arquivo NÃO foi
     * convertido e nada foi simulado.
     */
    private function converterUnavailableMessage(DocumentSourceType $sourceType): string
    {
        return match ($sourceType) {
            DocumentSourceType::Docx => 'A conversão de arquivos DOCX não está disponível nesta instalação. Converta o documento para PDF e envie novamente.',
            DocumentSourceType::Image => 'O processamento de imagens não está disponível nesta instalação. Envie o documento em PDF.',
            DocumentSourceType::Pdf => 'O processamento de PDF não está disponível nesta instalação. Avise o suporte.',
        };
    }
}
