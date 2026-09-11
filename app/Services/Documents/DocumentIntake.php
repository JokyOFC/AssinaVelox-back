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
use App\Services\Envelopes\DomainFeatures;
use App\Services\Envelopes\PreparationGuard;
use App\Services\Retention\LegalHolds;
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
 * ## Um documento ou vários (Fase 2 §2.3)
 *
 * - **Flag `multi_document` desligada** (padrão, comportamento da Fase 1): o envelope tem
 *   exatamente um documento. Enviar outro arquivo **substitui** o anterior — que sai, com
 *   seus arquivos e os campos posicionados sobre ele, no mesmo commit do substituto.
 * - **Flag ligada** ({@see DomainFeatures::multiDocument()}): cada arquivo enviado é
 *   ACRESCENTADO ao fim da lista (`documents.position`), até
 *   `assinavelox.multi_document.max_documents`. Remover um arquivo apaga só os campos
 *   posicionados sobre ele. A ordem pode ser alterada por {@see self::reorder()}.
 *
 * Garantias (valem nos dois modos):
 * - a versão original **nunca** é sobrescrita: cada upload grava um arquivo novo, com
 *   caminho derivado do ULID da versão;
 * - na substituição, o documento anterior só sai DEPOIS de o substituto estar gravado, e no
 *   mesmo commit: uma falha de disco ou de banco deixa o anterior exatamente onde estava;
 * - o arquivo só é gravado depois de aprovado; o commit no banco acontece depois da
 *   gravação, e uma falha no banco apaga o arquivo recém-escrito;
 * - toda decisão que depende do estado do envelope é refeita sob lock (TOCTOU).
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

        $multi = DomainFeatures::multiDocument($envelope->organization);
        $max = DomainFeatures::maxDocuments($envelope->organization);

        $this->assertRoomForAnother($envelope, $multi, $max, EnvelopeDocuments::count($envelope));

        $inspected = $this->inspector->inspect($file);

        $correlationId = (string) Str::ulid();

        // Substituição (só com a flag desligada): o documento anterior só sai DEPOIS que o
        // novo estiver gravado. Removê-lo primeiro (registro, bytes e campos posicionados,
        // tudo commitado) fazia com que uma falha transitória de disco — cheio, S3 fora,
        // permissão — deixasse o envelope sem documento algum e sem os campos que o
        // remetente já tinha posto.
        $previous = $multi ? null : $envelope->document()->first();

        // Substituir apaga o documento anterior de vez: mesma guarda da remoção.
        if ($previous !== null) {
            app(LegalHolds::class)->guardEnvelope($envelope, 'document_replace', $actor);
        }

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
            $document = DB::transaction(function () use ($envelope, $previous, $inspected, $versionUlid, $storagePath, $sha256, $actor, $multi, $max): Document {
                // TOCTOU: `acceptsUpload()` acima decidiu pelo model que a requisição
                // trouxe. Entre aquela leitura e este commit outra requisição pode ter
                // concluído o envio — e a substituição apaga a versão congelada, os campos
                // e, em cascata, os aceites. Decide de novo pelo que está no banco, sob
                // lock, como `PreparationGuard` faz nos demais serviços de preparo.
                $locked = $this->lockForPreparation($envelope);

                if ($multi) {
                    // O limite também é reconferido sob lock: dois uploads simultâneos não
                    // podem, juntos, passar do teto.
                    $this->assertRoomForAnother($locked, true, $max, EnvelopeDocuments::count($locked));
                    $position = EnvelopeDocuments::nextPosition($locked);
                } else {
                    // Dentro da MESMA transação do novo documento: ou os dois passos valem,
                    // ou nenhum vale. Com a flag desligada o envelope admite um documento, e
                    // é este commit que restabelece a regra.
                    if ($previous !== null) {
                        $this->purgeDocumentRecords($locked, $previous);
                    }

                    $position = 1;
                }

                /** @var Document $document */
                $document = Document::query()->create([
                    'envelope_id' => $envelope->getKey(),
                    'organization_id' => $envelope->organization_id,
                    'position' => $position,
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

                if ($locked->status !== EnvelopeStatus::Preparing) {
                    $locked->status = EnvelopeStatus::Preparing;
                    $locked->save();
                }

                $envelope->setRawAttributes($locked->getAttributes(), true);

                return $document;
            });
        } catch (\Throwable $exception) {
            // O registro não entrou: não deixa o arquivo órfão no disco.
            $this->storage->disk()->delete($storagePath);

            throw $exception;
        }

        if ($multi) {
            // Com vários documentos, `document` é o PRIMEIRO — não necessariamente este.
            $envelope->unsetRelation('document');
        } else {
            $envelope->setRelation('document', $document);
        }

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

        $payload = [
            'document_ulid' => $document->ulid,
            'document_version_ulid' => $versionUlid,
            'original_filename' => $inspected->displayName,
            'source_type' => $inspected->sourceType->value,
            'mime_type' => $inspected->mimeType,
            'size_bytes' => $inspected->sizeBytes,
            'sha256' => $sha256,
        ];

        if ($multi) {
            $payload['position'] = (int) $document->position;
        }

        $this->audit->record($envelope, AuditEventType::DocumentUploaded, $payload, $actor, $request, $correlationId);

        ProcessDocumentUpload::dispatch(
            (int) $document->getKey(),
            (int) $envelope->organization_id,
            $correlationId,
        );

        return $document;
    }

    /**
     * Remove um documento do envelope (ação "remover" do wizard). Sem `$document`, remove o
     * primeiro — o único, na Fase 1.
     *
     * @throws UploadRejectedException quando o envelope já saiu da preparação
     */
    public function remove(Envelope $envelope, ?User $actor = null, ?Request $request = null, ?Document $document = null): bool
    {
        // A remoção destrói mais do que a substituição: campos posicionados, o registro do
        // documento e — por cascata do esquema — as versões, os `signing_field_values` e os
        // `signature_acceptances` gravados sobre elas, além dos bytes no disco. Fora de
        // `draft|preparing|ready` isso apagaria a versão congelada apresentada aos
        // signatários, o PDF final e o aceite eletrônico já registrado. Recusa aqui, antes
        // de qualquer trabalho, e recusa de novo sob lock dentro da transação.
        if (! $this->acceptsUpload($envelope)) {
            throw UploadRejectedException::make(
                'envelope_not_editable',
                'Este documento não está mais em rascunho e não pode ser removido.',
            );
        }

        $document ??= $envelope->document()->first();

        if ($document === null || (int) $document->envelope_id !== (int) $envelope->getKey()) {
            return false;
        }

        // A remoção apaga DE VEZ o documento, as versões e os bytes: a preservação legal vence
        // (retencao-e-preservacao.md §5.2 — regra única). Registra a tentativa com o autor e
        // lança LegalHoldActiveException, que se renderiza como resposta de tela.
        app(LegalHolds::class)->guardEnvelope($envelope, 'document_remove', $actor);

        $correlationId = (string) Str::ulid();

        $this->removeDocument($envelope, $document, $actor, $request, $correlationId, replaced: false);

        EnvelopeDocuments::compactPositions($envelope);

        $envelope->unsetRelation('document');
        $this->readiness->recompute($envelope);

        return true;
    }

    /**
     * Nova ordem de apresentação dos documentos (Fase 2 §2.3). `$ulids` precisa listar cada
     * documento do envelope exatamente uma vez.
     *
     * @param  list<string>  $ulids
     *
     * @throws UploadRejectedException
     */
    public function reorder(Envelope $envelope, array $ulids, ?User $actor = null, ?Request $request = null): void
    {
        if (! $this->acceptsUpload($envelope)) {
            throw UploadRejectedException::make(
                'envelope_not_editable',
                'Este documento não está mais em rascunho e não pode ser alterado.',
            );
        }

        $ulids = array_map('strval', $ulids);

        $changed = DB::transaction(function () use ($envelope, $ulids): bool {
            $locked = $this->lockForPreparation($envelope);
            $documents = EnvelopeDocuments::ordered($locked)->keyBy('ulid');

            $expected = $documents->keys()->sort()->values()->all();
            $given = collect($ulids)->sort()->values()->all();

            if (count($ulids) !== count(array_unique($ulids)) || $expected !== $given) {
                throw UploadRejectedException::make(
                    'invalid_document_order',
                    'A nova ordem precisa listar cada arquivo do documento exatamente uma vez.',
                );
            }

            $changed = false;

            foreach ($ulids as $index => $ulid) {
                /** @var Document $document */
                $document = $documents->get($ulid);

                if ((int) $document->position !== $index + 1) {
                    $document->forceFill(['position' => $index + 1])->save();
                    $changed = true;
                }
            }

            return $changed;
        });

        if (! $changed) {
            return;
        }

        $envelope->unsetRelation('document');

        $this->audit->record($envelope, AuditEventType::DocumentsReordered, [
            'order' => $ulids,
        ], $actor, $request);
    }

    /**
     * @throws UploadRejectedException
     */
    private function assertRoomForAnother(Envelope $envelope, bool $multi, int $max, int $existing): void
    {
        if (! $multi && $existing > 1) {
            // A flag foi desligada com um rascunho de vários arquivos em andamento: substituir
            // "o primeiro" deixaria os outros para trás sem que ninguém percebesse.
            throw UploadRejectedException::make(
                'multi_document_disabled',
                'Este documento tem mais de um arquivo, mas o envio de vários arquivos não está disponível para esta organização. Remova os arquivos extras antes de enviar outro.',
            );
        }

        if ($multi && $existing >= $max) {
            throw UploadRejectedException::make(
                'too_many_documents',
                sprintf('Este documento aceita no máximo %d arquivos.', $max),
            );
        }
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

        DB::transaction(function () use ($envelope, $document): void {
            $locked = $this->lockForPreparation($envelope);

            $this->purgeDocumentRecords($locked, $document);

            $envelope->setRawAttributes($locked->getAttributes(), true);
        });

        $this->purgeVersionFiles($versions, $correlationId);

        $this->audit->record($envelope, AuditEventType::DocumentRemoved, $payload, $actor, $request, $correlationId);
    }

    /**
     * Recarrega o envelope sob `lockForUpdate()` e recusa se ele já saiu da preparação.
     *
     * Mesma invariante de {@see PreparationGuard}, com a exceção
     * que este serviço precisa: a recusa sai como {@see UploadRejectedException}, que é o
     * vocabulário de erro do passo 1 do wizard.
     *
     * @throws UploadRejectedException
     */
    private function lockForPreparation(Envelope $envelope): Envelope
    {
        /** @var Envelope|null $locked */
        $locked = Envelope::withoutOrganizationScope()
            ->whereKey($envelope->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null || ! $locked->status->isDraftLike()) {
            throw UploadRejectedException::make(
                'envelope_not_editable',
                'Este documento já saiu da preparação e não pode mais ser alterado.',
            );
        }

        return $locked;
    }

    /**
     * Parte de BANCO da remoção — sem transação própria, para poder entrar na transação
     * de quem chama (é o que torna a substituição atômica).
     */
    private function purgeDocumentRecords(Envelope $envelope, Document $document): void
    {
        $hasOthers = Document::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereKeyNot($document->getKey())
            ->exists();

        // Campos posicionados sobre a versão que está saindo perdem a referência.
        // Consulta direta: a relação `fields()` carrega ORDER BY, que o SQLite recusa
        // em DELETE.
        if ($hasOthers) {
            // Vários documentos: só os campos DESTE documento saem; os posicionados sobre os
            // demais continuam válidos.
            $versionIds = DocumentVersion::withoutOrganizationScope()
                ->where('document_id', $document->getKey())
                ->pluck('id')
                ->all();

            SigningField::query()
                ->where('envelope_id', $envelope->getKey())
                ->whereIn('document_version_id', $versionIds === [] ? [0] : $versionIds)
                ->delete();
        } else {
            SigningField::query()->where('envelope_id', $envelope->getKey())->delete();
        }

        $envelope->forceFill([
            'sent_document_version_id' => null,
            'final_document_version_id' => null,
        ]);

        if ($envelope->status !== EnvelopeStatus::Draft && $envelope->status->isDraftLike()) {
            $envelope->status = EnvelopeStatus::Draft;
        }

        $envelope->save();

        // current_version_id aponta para uma linha de document_versions: solta antes.
        $document->forceFill(['current_version_id' => null, 'sent_version_id' => null, 'final_version_id' => null])->save();
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
