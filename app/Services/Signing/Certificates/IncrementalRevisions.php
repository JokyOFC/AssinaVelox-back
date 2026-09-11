<?php

namespace App\Services\Signing\Certificates;

use App\Enums\ActorType;
use App\Enums\DocumentVersionKind;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\ParticipantSignature;
use App\Services\Documents\DocumentStorage;
use App\Services\Pdf\PdfToolClient;
use App\Services\Signing\Certificates\Exceptions\StaleRevisionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Revisões incrementais de um documento em `crypto_mode` de participantes (Fase 2 §2.12).
 *
 * ```
 *  pre_signature (consolidado + evidências)      ← base congelada, sem assinatura
 *        │ + assinatura do participante 1        (revisão incremental, bytes só acrescentados)
 *  signed_incremental #1
 *        │ + assinatura do participante 2
 *  signed_incremental #2
 *        │ + assinatura da operadora (se configurada)
 *  final
 * ```
 *
 * A revisão N+1 só existe como acréscimo à revisão N. {@see self::storeSigned()} grava uma
 * revisão assinada SÓ SE a base usada ainda for a mais recente — compare-and-set sob
 * `lockForUpdate` no documento — e o banco recusa duas assinaturas sobre a mesma base
 * (`participant_signatures.base_document_version_id` único). Revisão irmã não entra.
 *
 * Bytes primeiro, linha depois (mesma regra de `FinalizationArtifacts`): se a linha não
 * entra, os bytes gravados são apagados.
 */
final class IncrementalRevisions
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly PdfToolClient $client,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Revisão mais recente sobre a qual se assina: a última `signed_incremental`, ou a
     * `pre_signature`. `null` enquanto a base não existe. Exige bytes no disco com o sha256
     * registrado.
     */
    public function latest(Document $document): ?DocumentVersion
    {
        $version = $this->latestRow($document);

        if ($version === null || ! $this->storage->exists($version)) {
            return null;
        }

        $actual = $this->storage->sha256($version);

        return $actual !== null && hash_equals((string) $version->sha256, $actual) ? $version : null;
    }

    /**
     * @return Collection<int, DocumentVersion>
     */
    public function signedRevisions(Document $document): Collection
    {
        return DocumentVersion::withoutOrganizationScope()
            ->where('document_id', $document->getKey())
            ->where('kind', DocumentVersionKind::SignedIncremental->value)
            ->orderBy('version_number')
            ->get();
    }

    /**
     * Grava a revisão assinada calculada sobre `$base`, se `$base` ainda for a mais recente.
     *
     * @param  callable(DocumentVersion): ParticipantSignature  $record  cria a linha de `participant_signatures`
     * @return array{0: DocumentVersion, 1: ParticipantSignature}
     *
     * @throws StaleRevisionException
     */
    public function storeSigned(
        Envelope $envelope,
        Document $document,
        DocumentVersion $base,
        string $localPath,
        callable $record,
        ?string $correlationId = null,
    ): array {
        $sha256 = hash_file('sha256', $localPath);

        if ($sha256 === false) {
            throw new \RuntimeException('Não foi possível calcular o resumo da revisão assinada.');
        }

        $inspection = null;

        try {
            $inspection = $this->client->inspect($localPath, $correlationId);
        } catch (Throwable $exception) {
            $this->logger->warning('A1 do participante: não foi possível inspecionar a revisão assinada.', [
                'exception' => $exception::class,
                'correlation_id' => $correlationId,
            ]);
        }

        $versionUlid = $this->storage->newVersionUlid();
        $storagePath = $this->storage->pathFor($envelope, $versionUlid, 'pdf');

        // Bytes primeiro. Só depois a linha — e só se a base ainda for a mais recente.
        $this->storage->putFile($localPath, $storagePath);

        try {
            return DB::transaction(function () use ($document, $base, $versionUlid, $storagePath, $localPath, $sha256, $inspection, $record): array {
                Document::withoutOrganizationScope()->whereKey($document->getKey())->lockForUpdate()->first();

                $latest = $this->latestRow($document);

                if ($latest === null || (int) $latest->getKey() !== (int) $base->getKey()) {
                    throw new StaleRevisionException('A revisão usada como base não é mais a mais recente do documento.');
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
                    'page_count' => $inspection->pageCount ?? $base->page_count,
                    'pages_meta' => $inspection?->pagesMeta() ?? $base->pages_meta,
                    'is_encrypted' => false,
                    'has_signatures' => true,
                    'created_by_type' => ActorType::System->value,
                    'created_by_id' => null,
                ]);
                $version->save();

                return [$version, $record($version)];
            });
        } catch (Throwable $exception) {
            try {
                $this->storage->disk()->delete($storagePath);
            } catch (Throwable) {
                // O órfão custa espaço; a linha fantasma não existe.
            }

            throw $exception;
        }
    }

    /**
     * Descarta a base e as revisões assinadas de um documento (artefatos NUNCA publicados:
     * o envelope não concluiu). Usado quando a consolidação ou as evidências foram refeitas —
     * as assinaturas calculadas sobre a base antiga deixaram de descrever o arquivo.
     */
    public function discardAll(Document $document, string $reason): int
    {
        $versions = DocumentVersion::withoutOrganizationScope()
            ->where('document_id', $document->getKey())
            ->whereIn('kind', [DocumentVersionKind::PreSignature->value, DocumentVersionKind::SignedIncremental->value])
            ->orderByDesc('version_number')
            ->get();

        foreach ($versions as $version) {
            $this->logger->warning('A1 do participante: descartando revisão nunca publicada.', [
                'document_version_ulid' => $version->ulid,
                'kind' => $version->kind->value,
                'reason' => $reason,
            ]);

            ParticipantSignature::withoutOrganizationScope()
                ->where('signed_document_version_id', $version->getKey())
                ->orWhere('base_document_version_id', $version->getKey())
                ->delete();

            try {
                $this->storage->disk()->delete($version->storage_path);
            } catch (Throwable) {
                // Idem: órfão no disco é tolerado.
            }

            $version->delete();
        }

        return $versions->count();
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
