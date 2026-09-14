<?php

namespace App\Services\Signing\GovBr;

use App\Enums\ActorType;
use App\Enums\DocumentVersionKind;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Services\Documents\DocumentStorage;
use App\Services\Pdf\PdfToolClient;
use App\Services\Signing\Certificates\Exceptions\StaleRevisionException;
use App\Services\Signing\Certificates\IncrementalRevisions;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Grava a revisão DEVOLVIDA como `signed_incremental`, na mesma cadeia das assinaturas dos
 * participantes (Fase 2 §2.12): `pre_signature → signed_incremental… → final`.
 *
 * Compare-and-set, como {@see IncrementalRevisions::storeSigned()}: a linha só entra se a
 * revisão reservada ainda for a MAIS RECENTE do documento (`lockForUpdate` no documento).
 * Se outro gravador chegou antes, {@see StaleRevisionException} — a devolução foi calculada
 * sobre uma revisão que não é mais a atual e viraria revisão IRMÃ; o participante precisa
 * reservar e assinar de novo (a plataforma não pode "refazer" uma assinatura de terceiro).
 *
 * Bytes primeiro, linha depois; se a linha não entra, os bytes são apagados.
 */
final class GovBrRevisionStore
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly IncrementalRevisions $revisions,
        private readonly PdfToolClient $client,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Revisão mais recente sobre a qual se assina (bytes conferidos), ou null sem base.
     */
    public function latest(Document $document): ?DocumentVersion
    {
        return $this->revisions->latest($document);
    }

    /**
     * @param  callable(DocumentVersion): void  $record  grava o resultado (dentro da transação)
     *
     * @throws StaleRevisionException
     */
    public function store(
        Envelope $envelope,
        Document $document,
        DocumentVersion $expected,
        string $localPath,
        callable $record,
        ?string $correlationId = null,
    ): DocumentVersion {
        $sha256 = hash_file('sha256', $localPath);

        if ($sha256 === false) {
            throw new \RuntimeException('Não foi possível calcular o resumo da revisão devolvida.');
        }

        $inspection = null;

        try {
            $inspection = $this->client->inspect($localPath, $correlationId);
        } catch (Throwable $exception) {
            $this->logger->warning('gov.br (devolução): não foi possível inspecionar a revisão devolvida.', [
                'exception' => $exception::class,
                'correlation_id' => $correlationId,
            ]);
        }

        $versionUlid = $this->storage->newVersionUlid();
        $storagePath = $this->storage->pathFor($envelope, $versionUlid, 'pdf');

        $this->storage->putFile($localPath, $storagePath);

        try {
            return DB::transaction(function () use ($document, $expected, $versionUlid, $storagePath, $localPath, $sha256, $inspection, $record): DocumentVersion {
                Document::withoutOrganizationScope()->whereKey($document->getKey())->lockForUpdate()->first();

                $latest = $this->latestRow($document);

                if ($latest === null || (int) $latest->getKey() !== (int) $expected->getKey()) {
                    throw new StaleRevisionException('A revisão reservada não é mais a mais recente do documento.');
                }

                $version = new DocumentVersion;
                $version->forceFill([
                    'ulid' => $versionUlid,
                    'document_id' => $document->getKey(),
                    'organization_id' => $document->organization_id,
                    'version_number' => ((int) DocumentVersion::withoutOrganizationScope()->where('document_id', $document->getKey())->max('version_number')) + 1,
                    'kind' => DocumentVersionKind::SignedIncremental->value,
                    'storage_disk' => DocumentStorage::DISK,
                    'storage_path' => $storagePath,
                    'mime_type' => 'application/pdf',
                    'size_bytes' => (int) filesize($localPath),
                    'sha256' => $sha256,
                    'page_count' => $inspection->pageCount ?? $expected->page_count,
                    'pages_meta' => $inspection?->pagesMeta() ?? $expected->pages_meta,
                    'is_encrypted' => false,
                    'has_signatures' => true,
                    // Quem produziu os bytes foi o participante (no portal); a plataforma só conferiu.
                    'created_by_type' => ActorType::Recipient->value,
                    'created_by_id' => null,
                ]);
                $version->save();

                $record($version);

                return $version;
            });
        } catch (Throwable $exception) {
            try {
                $this->storage->disk()->delete($storagePath);
            } catch (Throwable) {
                // Órfão no disco é tolerado; linha fantasma não existe.
            }

            throw $exception;
        }
    }

    private function latestRow(Document $document): ?DocumentVersion
    {
        /** @var DocumentVersion|null $version */
        $version = DocumentVersion::withoutOrganizationScope()
            ->where('document_id', $document->getKey())
            ->whereIn('kind', [DocumentVersionKind::PreSignature->value, DocumentVersionKind::SignedIncremental->value])
            ->orderByDesc('version_number')
            ->first();

        return $version;
    }
}
