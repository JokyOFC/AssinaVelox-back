<?php

namespace App\Services\Documents;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Jobs\Documents\ProcessDocumentUpload;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\SigningField;
use App\Models\User;
use App\Services\Documents\Exceptions\UploadRejectedException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Entrada do pipeline documental (arquitetura §5, item 1).
 *
 * Recebe o arquivo enviado, valida o CONTEÚDO (UploadInspector), grava os bytes no disco
 * privado `documents` e persiste `Document` + `DocumentVersion(kind=original)` com o
 * sha256 desses bytes. Emite `document.uploaded` e despacha `ProcessDocumentUpload`.
 *
 * Garantias:
 * - a versão original **nunca** é sobrescrita: cada upload grava um arquivo novo, com
 *   caminho derivado do ULID da versão;
 * - substituir o documento de um envelope (Fase 1: um documento por envelope) remove o
 *   documento anterior, seus arquivos e os campos posicionados sobre ele — mas só DEPOIS
 *   de o substituto estar gravado, e no mesmo commit: uma falha de disco ou de banco
 *   deixa o documento anterior exatamente onde estava;
 * - o arquivo só é gravado depois de aprovado; o commit no banco acontece depois da
 *   gravação, e uma falha no banco apaga o arquivo recém-escrito.
 */
class DocumentIntake
{
    public function __construct(
        private readonly UploadInspector $inspector,
        private readonly DocumentStorage $storage,
        private readonly DocumentAuditTrail $audit,
        private readonly EnvelopeReadiness $readiness,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Status do envelope que aceitam upload/substituição de documento.
     */
    public function acceptsUpload(Envelope $envelope): bool
    {
        return $envelope->status->isDraftLike();
    }

    /**
     * @throws UploadRejectedException
     */
    public function store(Envelope $envelope, UploadedFile $file, ?User $actor = null, ?Request $request = null): Document
    {
        if (! $this->acceptsUpload($envelope)) {
            throw UploadRejectedException::make(
                'envelope_not_editable',
                'Este documento não está mais em rascunho e não aceita novos arquivos.',
            );
        }

        $inspected = $this->inspector->inspect($file);

        $correlationId = (string) Str::ulid();

        // Substituição: o documento anterior só sai DEPOIS que o novo estiver gravado.
        // Removê-lo primeiro (registro, bytes e campos posicionados, tudo commitado) fazia
        // com que uma falha transitória de disco — cheio, S3 fora, permissão — deixasse o
        // envelope sem documento algum e sem os campos que o remetente já tinha posto.
        $previous = $envelope->document()->first();
        $previousVersions = $previous === null ? collect() : $previous->versions()->get();
        $previousPayload = $previous === null ? null : [
            'document_ulid' => $previous->ulid,
            'original_filename' => $previous->original_filename,
            'source_type' => $previous->source_type->value,
            'replaced' => true,
        ];

        $versionUlid = $this->storage->newVersionUlid();
        $storagePath = $this->storage->pathFor($envelope, $versionUlid, $inspected->extension);

        $localPath = (string) $file->getRealPath();
        $sha256 = hash_file('sha256', $localPath);

        if ($sha256 === false) {
            throw UploadRejectedException::make(
                'hash_failed',
                'Não foi possível processar o arquivo enviado. Tente novamente.',
            );
        }

        try {
            $this->storage->putFile($localPath, $storagePath);
        } catch (\Throwable $exception) {
            $this->logger->error('DocumentIntake: falha ao gravar o arquivo no disco de documentos', [
                'envelope_ulid' => $envelope->ulid,
                'exception' => $exception->getMessage(),
                'correlation_id' => $correlationId,
            ]);

            throw UploadRejectedException::make(
                'storage_unavailable',
                'Não foi possível guardar o arquivo agora. Tente de novo em instantes; o documento atual do envelope continua no lugar.',
            );
        }

        try {
            $document = DB::transaction(function () use ($envelope, $previous, $inspected, $versionUlid, $storagePath, $sha256, $actor): Document {
                // Dentro da MESMA transação do novo documento: ou os dois passos valem,
                // ou nenhum vale. A Fase 1 admite um documento por envelope, e é este
                // commit que restabelece a regra.
                if ($previous !== null) {
                    $this->purgeDocumentRecords($envelope, $previous);
                }

                /** @var Document $document */
                $document = Document::query()->create([
                    'envelope_id' => $envelope->getKey(),
                    'organization_id' => $envelope->organization_id,
                    'name' => $inspected->baseName,
                    'original_filename' => $inspected->displayName,
                    'source_type' => $inspected->sourceType,
                    'processing_status' => DocumentProcessingStatus::Uploaded,
                    'failure_code' => null,
                    'failure_message' => null,
                    'current_version_id' => null,
                    'page_count' => null,
                ]);

                // `ulid` não é fillable (o trait gera um); aqui ele precisa ser exatamente o
                // ULID que já compôs o caminho no disco, por isso forceFill.
                $version = new DocumentVersion;
                $version->forceFill([
                    'ulid' => $versionUlid,
                    'document_id' => $document->getKey(),
                    'organization_id' => $envelope->organization_id,
                    'version_number' => 1,
                    'kind' => DocumentVersionKind::Original,
                    'storage_disk' => DocumentStorage::DISK,
                    'storage_path' => $storagePath,
                    'mime_type' => $inspected->mimeType,
                    'size_bytes' => $inspected->sizeBytes,
                    'sha256' => $sha256,
                    'page_count' => null,
                    'pages_meta' => null,
                    'is_encrypted' => false,
                    'has_signatures' => false,
                    'created_by_type' => ($actor !== null ? ActorType::User : ActorType::System)->value,
                    'created_by_id' => $actor?->getKey(),
                ]);
                $version->save();

                $document->setRelation('originalVersion', $version);

                if ($envelope->status !== EnvelopeStatus::Preparing) {
                    $envelope->status = EnvelopeStatus::Preparing;
                    $envelope->save();
                }

                return $document;
            });
        } catch (\Throwable $exception) {
            // O registro não entrou: não deixa o arquivo órfão no disco.
            $this->storage->disk()->delete($storagePath);

            throw $exception;
        }

        $envelope->setRelation('document', $document);

        if ($previousPayload !== null) {
            // Bytes e trilha do documento substituído, já fora da transação: se apagar o
            // arquivo falhar sobra um órfão registrado em log, nunca um documento
            // fantasma no banco.
            $this->purgeVersionFiles($previousVersions, $correlationId);

            $this->audit->record(
                $envelope,
                AuditEventType::DocumentRemoved,
                $previousPayload,
                $actor,
                $request,
                $correlationId,
            );
        }

        $this->audit->record(
            $envelope,
            AuditEventType::DocumentUploaded,
            [
                'document_ulid' => $document->ulid,
                'document_version_ulid' => $versionUlid,
                'original_filename' => $inspected->displayName,
                'source_type' => $inspected->sourceType->value,
                'mime_type' => $inspected->mimeType,
                'size_bytes' => $inspected->sizeBytes,
                'sha256' => $sha256,
            ],
            $actor,
            $request,
            $correlationId,
        );

        ProcessDocumentUpload::dispatch(
            (int) $document->getKey(),
            (int) $envelope->organization_id,
            $correlationId,
        );

        return $document;
    }

    /**
     * Remove o documento do envelope (ação "remover" do wizard).
     */
    public function remove(Envelope $envelope, ?User $actor = null, ?Request $request = null): bool
    {
        $document = $envelope->document()->first();

        if ($document === null) {
            return false;
        }

        $correlationId = (string) Str::ulid();

        $this->removeDocument($envelope, $document, $actor, $request, $correlationId, replaced: false);

        $envelope->unsetRelation('document');
        $this->readiness->recompute($envelope);

        return true;
    }

    /**
     * Remoção efetiva: campos posicionados sobre o documento, registros e arquivos.
     *
     * Os arquivos saem DEPOIS do commit: se a transação falhar, nada foi apagado do disco;
     * se a remoção do arquivo falhar, sobra um órfão (registrado em log), nunca um
     * documento fantasma no banco.
     */
    private function removeDocument(
        Envelope $envelope,
        Document $document,
        ?User $actor,
        ?Request $request,
        string $correlationId,
        bool $replaced,
    ): void {
        $payload = [
            'document_ulid' => $document->ulid,
            'original_filename' => $document->original_filename,
            'source_type' => $document->source_type->value,
            'replaced' => $replaced,
        ];

        $versions = $document->versions()->get();

        DB::transaction(fn () => $this->purgeDocumentRecords($envelope, $document));

        $this->purgeVersionFiles($versions, $correlationId);

        $this->audit->record($envelope, AuditEventType::DocumentRemoved, $payload, $actor, $request, $correlationId);
    }

    /**
     * Parte de BANCO da remoção — sem transação própria, para poder entrar na transação
     * de quem chama (é o que torna a substituição atômica).
     */
    private function purgeDocumentRecords(Envelope $envelope, Document $document): void
    {
        // Campos posicionados sobre a versão que está saindo perdem a referência.
        // Consulta direta: a relação `fields()` carrega ORDER BY, que o SQLite recusa
        // em DELETE.
        SigningField::query()->where('envelope_id', $envelope->getKey())->delete();

        $envelope->forceFill([
            'sent_document_version_id' => null,
            'final_document_version_id' => null,
        ]);

        if ($envelope->status !== EnvelopeStatus::Draft && $envelope->status->isDraftLike()) {
            $envelope->status = EnvelopeStatus::Draft;
        }

        $envelope->save();

        // current_version_id aponta para uma linha de document_versions: solta antes.
        $document->forceFill(['current_version_id' => null])->save();
        $document->delete();
    }

    /**
     * Parte de DISCO da remoção, sempre depois do commit.
     *
     * @param  Collection<int, DocumentVersion>  $versions
     */
    private function purgeVersionFiles($versions, string $correlationId): void
    {
        foreach ($versions as $version) {
            if ($version->storage_disk !== DocumentStorage::DISK) {
                continue;
            }

            try {
                $this->storage->disk()->delete($version->storage_path);
            } catch (\Throwable $exception) {
                $this->logger->warning('DocumentIntake: arquivo de versão não removido do disco', [
                    'document_version_ulid' => $version->ulid,
                    'exception' => $exception->getMessage(),
                    'correlation_id' => $correlationId,
                ]);
            }
        }
    }
}
