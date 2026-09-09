<?php

namespace App\Services\Envelopes\Finalization;

use App\Enums\ActorType;
use App\Enums\DocumentVersionKind;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Services\Documents\DocumentStorage;
use App\Services\Envelopes\Finalization\Exceptions\FinalizationException;
use App\Services\Pdf\Dto\PdfInspection;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\TemporaryDirectory;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * Persistência dos artefatos da finalização como `DocumentVersion` — e, principalmente, a
 * **retomada**.
 *
 * Cada etapa pergunta primeiro `existing()`: existe uma versão daquele `kind` para este
 * documento **com bytes no disco**? Se existe, ela é reaproveitada e a etapa não roda de
 * novo. É isso que faz uma segunda execução do job não duplicar versões nem refazer
 * trabalho — a versão do banco só é criada **depois** de os bytes estarem gravados, então
 * uma linha existente é prova de que o arquivo existiu.
 *
 * A ordem "grava os bytes, depois cria a linha" é deliberada. Ela admite um estado
 * intermediário (arquivo órfão no disco sem linha no banco) e recusa o oposto (linha
 * apontando para bytes que não existem). O órfão custa espaço; a linha fantasma quebraria
 * download, verificação e hash — e não haveria como detectá-la sem ler o disco.
 */
class FinalizationArtifacts
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly PdfToolClient $client,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Versão persistida daquele tipo **cujos bytes no disco ainda são os que a linha
     * descreve**, ou null.
     *
     * Duas conferências, não uma:
     *
     * 1. os bytes existem;
     * 2. o `sha256` recalculado deles bate com o gravado na linha.
     *
     * A segunda existe porque o `final_sha256` publicado em `verification_records` vem da
     * COLUNA, enquanto `envelopes.download` entrega os BYTES. Se eles divergirem entre a
     * queda e a retentativa — corrupção de disco ou de bucket, restauração parcial de
     * backup, sincronização malfeita, adulteração —, a retomada publicaria o resumo antigo
     * sobre o arquivo novo, e a conferência "Conferir meu arquivo" responderia "Não confere"
     * para o arquivo verdadeiro: a plataforma acusando de adulterado o próprio arquivo que
     * entrega. O resumo tem que identificar os bytes servidos, byte a byte, ou o artefato
     * não serve e é refeito.
     */
    public function existing(Document $document, DocumentVersionKind $kind): ?DocumentVersion
    {
        /** @var DocumentVersion|null $version */
        $version = $document->versions()
            ->withoutGlobalScopes()
            ->where('kind', $kind->value)
            ->orderByDesc('version_number')
            ->first();

        if ($version === null) {
            return null;
        }

        if (! $this->storage->exists($version)) {
            $this->logger->warning('Finalização: versão registrada sem bytes no disco; será regerada.', [
                'document_version_ulid' => $version->ulid,
                'kind' => $kind->value,
            ]);

            return null;
        }

        $actual = $this->storage->sha256($version);

        if ($actual === null) {
            // Bytes ilegíveis: o artefato não serve, mas apagá-lo destruiria a única cópia de
            // algo que talvez ainda dê para recuperar manualmente. Fica onde está.
            $this->logger->warning('Finalização: não foi possível recalcular o sha256 do artefato; será regerado.', [
                'document_version_ulid' => $version->ulid,
                'kind' => $kind->value,
            ]);

            return null;
        }

        if (! hash_equals((string) $version->sha256, $actual)) {
            $this->discard($version, 'os bytes no disco não correspondem ao sha256 registrado');

            return null;
        }

        return $version;
    }

    /**
     * Grava os bytes no disco `documents` e cria a `DocumentVersion`.
     *
     * @throws FinalizationException
     */
    public function store(
        Envelope $envelope,
        Document $document,
        DocumentVersionKind $kind,
        string $localPath,
        ?string $correlationId = null,
    ): DocumentVersion {
        if (! is_file($localPath)) {
            throw FinalizationException::writeFailed($kind->value, ['reason' => 'missing_local_file']);
        }

        $sha256 = hash_file('sha256', $localPath);

        if ($sha256 === false) {
            throw FinalizationException::writeFailed($kind->value, ['reason' => 'hash_failed']);
        }

        $inspection = $this->inspect($localPath, $kind, $correlationId);

        $versionUlid = $this->storage->newVersionUlid();
        $storagePath = $this->storage->pathFor($envelope, $versionUlid, 'pdf');

        // Bytes primeiro. Só depois a linha do banco — nunca o contrário.
        $this->storage->putFile($localPath, $storagePath);

        return DB::transaction(function () use ($document, $kind, $versionUlid, $storagePath, $localPath, $sha256, $inspection): DocumentVersion {
            $version = new DocumentVersion;
            $version->forceFill([
                'ulid' => $versionUlid,
                'document_id' => $document->getKey(),
                'organization_id' => $document->organization_id,
                'version_number' => $document->nextVersionNumber(),
                'kind' => $kind->value,
                'storage_disk' => DocumentStorage::DISK,
                'storage_path' => $storagePath,
                'mime_type' => 'application/pdf',
                'size_bytes' => (int) filesize($localPath),
                'sha256' => $sha256,
                'page_count' => $inspection?->pageCount,
                'pages_meta' => $inspection?->pagesMeta(),
                'is_encrypted' => (bool) $inspection?->encrypted,
                'has_signatures' => (bool) $inspection?->hasSignatures,
                'created_by_type' => ActorType::System->value,
                'created_by_id' => null,
            ]);
            $version->save();

            return $version;
        });
    }

    /**
     * Copia uma versão persistida para o diretório temporário da operação.
     */
    public function copyToTemporary(DocumentVersion $version, TemporaryDirectory $workDir, string $filename): string
    {
        return $this->storage->copyToTemporary($version, $workDir, $filename);
    }

    /**
     * Descarta um artefato **nunca publicado** (arquivo + linha).
     *
     * Único uso legítimo: um `final` produzido por uma execução anterior que ficou
     * incompatível com a configuração atual de assinatura (por exemplo, gerado sem
     * certificado e agora existe um). Como o envelope não concluiu e nenhum
     * `verification_record` aponta para ele, esse artefato não é público — reconciliá-lo é
     * corrigir um passo interrompido, não apagar histórico.
     */
    public function discard(DocumentVersion $version, string $reason): void
    {
        $this->logger->warning('Finalização: descartando artefato incompleto de execução anterior.', [
            'document_version_ulid' => $version->ulid,
            'kind' => $version->kind->value,
            'reason' => $reason,
        ]);

        try {
            $this->storage->disk()->delete($version->storage_path);
        } catch (\Throwable $exception) {
            $this->logger->warning('Finalização: não foi possível remover os bytes do artefato descartado.', [
                'document_version_ulid' => $version->ulid,
                'exception' => $exception::class,
            ]);
        }

        $version->delete();
    }

    private function inspect(string $path, DocumentVersionKind $kind, ?string $correlationId): ?PdfInspection
    {
        try {
            return $this->client->inspect($path, $correlationId);
        } catch (\Throwable $exception) {
            // Metadado ausente não invalida o artefato: os bytes e o sha256 continuam
            // corretos. O que não pode acontecer é a finalização parar por causa disso.
            $this->logger->warning('Finalização: não foi possível inspecionar o artefato gerado.', [
                'kind' => $kind->value,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'correlation_id' => $correlationId,
            ]);

            return null;
        }
    }
}
