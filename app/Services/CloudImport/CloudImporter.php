<?php

namespace App\Services\CloudImport;

use App\Enums\AuditEventType;
use App\Integrations\Contracts\CloudFileSource;
use App\Models\CloudImport;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\User;
use App\Services\CloudImport\Dto\CloudFileSelection;
use App\Services\CloudImport\Dto\FetchedCloudFile;
use App\Services\CloudImport\Http\ConnectorHttp;
use App\Services\Documents\DocumentIntake;
use App\Services\Documents\Exceptions\UploadRejectedException;
use App\Services\Documents\UploadInspector;
use App\Services\Envelopes\EnvelopeAudit;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Importação de um arquivo da nuvem para o envelope (Fase 3 §3.9, G-CONN,
 * docs/fase-3/conectores.md §3).
 *
 * O arquivo baixado entra pelo MESMO caminho de um upload: DocumentIntake → UploadInspector
 * (tipo pelo conteúdo, tamanho, extensão coerente, DOCX seguro) → disco privado →
 * ProcessDocumentUpload (PDF criptografado ou já assinado é bloqueado lá, como no upload).
 * Nada aqui decide se um arquivo é aceitável: um executável renomeado para `.pdf` no Drive é
 * recusado exatamente como seria no navegador.
 *
 * Trilha: além do `document.uploaded` do DocumentIntake, `cloud_import.completed` registra a
 * ORIGEM (provedor, id externo, hash) e `cloud_import.rejected` a recusa (código). A linha em
 * `cloud_imports` guarda quem importou. Nenhum token, link ou conteúdo em lugar nenhum.
 */
final class CloudImporter
{
    public function __construct(
        private readonly DocumentIntake $intake,
        private readonly UploadInspector $inspector,
    ) {}

    public function maxBytes(): int
    {
        return $this->inspector->maxBytes();
    }

    /**
     * @throws CloudImportRejected
     */
    public function import(Envelope $envelope, User $actor, CloudFileSource $source, CloudFileSelection $selection, ?Request $request = null): CloudImport
    {
        if (! $this->intake->acceptsUpload($envelope)) {
            throw CloudImportRejected::make('envelope_not_editable', 'Este documento não está mais em rascunho e não aceita novos arquivos.');
        }

        if (! $source->isConfigured()) {
            throw CloudImportRejected::make('provider_not_configured', 'Importação do '.$source->provider()->label().' ainda não disponível: aguardando app registrado pelo proprietário.');
        }

        $fetched = null;

        try {
            $fetched = $source->fetch($selection, $this->inspector->maxBytes());

            $document = $this->intake->store(
                $envelope,
                new UploadedFile($fetched->path, self::clientName($fetched->name), null, null, true),
                $actor,
                $request,
            );
        } catch (UploadRejectedException $exception) {
            $rejected = CloudImportRejected::fromUpload($exception);
            $this->recordRejection($envelope, $actor, $source, $selection, $rejected, $fetched);

            throw $rejected;
        } catch (CloudImportRejected $rejected) {
            $this->recordRejection($envelope, $actor, $source, $selection, $rejected, $fetched);

            throw $rejected;
        } finally {
            ConnectorHttp::discard($fetched?->path);
        }

        return $this->recordCompletion($envelope, $actor, $source, $fetched, $document);
    }

    /**
     * Nome informado ao UploadInspector como "nome do cliente": sem caminho, sem controle.
     * O inspetor ainda sanitiza e confere a extensão contra o conteúdo.
     */
    private static function clientName(string $name): string
    {
        $name = str_replace(['/', '\\'], '_', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'documento' : mb_substr($name, 0, 255);
    }

    private function recordCompletion(Envelope $envelope, User $actor, CloudFileSource $source, FetchedCloudFile $fetched, Document $document): CloudImport
    {
        $version = $document->relationLoaded('originalVersion') ? $document->getRelation('originalVersion') : null;

        if (! $version instanceof DocumentVersion) {
            $version = DocumentVersion::withoutOrganizationScope()->where('document_id', $document->getKey())->orderBy('version_number')->first();
        }

        /** @var CloudImport $import */
        $import = CloudImport::query()->create([
            'organization_id' => $envelope->organization_id,
            'envelope_id' => $envelope->getKey(),
            'document_id' => $document->getKey(),
            'provider' => $source->provider(),
            'external_id' => $fetched->externalId === null ? null : mb_substr($fetched->externalId, 0, 255),
            'original_filename' => $document->original_filename,
            'sha256' => $version?->sha256,
            'size_bytes' => $version?->size_bytes,
            'status' => CloudImport::STATUS_COMPLETED,
            'simulated' => $source->isSimulated() || $fetched->simulated,
            'imported_by_user_id' => $actor->getKey(),
        ]);

        EnvelopeAudit::record($envelope, AuditEventType::CloudImportCompleted, [
            'import' => $import->ulid,
            'provider' => $source->provider()->value,
            'external_id' => $import->external_id,
            'document_ulid' => $document->ulid,
            'sha256' => $import->sha256,
            'size_bytes' => $import->size_bytes,
            'simulated' => $import->simulated,
        ]);

        return $import;
    }

    private function recordRejection(Envelope $envelope, User $actor, CloudFileSource $source, CloudFileSelection $selection, CloudImportRejected $rejected, ?FetchedCloudFile $fetched): void
    {
        $externalId = $fetched->externalId ?? $selection->externalId;
        $externalId = is_string($externalId) && preg_match('/^[A-Za-z0-9_:\-]{1,255}$/', $externalId) === 1 ? $externalId : null;

        /** @var CloudImport $import */
        $import = CloudImport::query()->create([
            'organization_id' => $envelope->organization_id,
            'envelope_id' => $envelope->getKey(),
            'document_id' => null,
            'provider' => $source->provider(),
            'external_id' => $externalId,
            // Nome informado na seleção (só para a lista do documento): quem importou vários
            // arquivos precisa saber qual falhou. Sem caracteres de controle, até 255.
            'original_filename' => self::displayName($selection->name ?? null),
            'sha256' => null,
            'size_bytes' => $fetched?->sizeBytes,
            'status' => CloudImport::STATUS_REJECTED,
            'rejection_code' => mb_substr($rejected->errorCode, 0, 64),
            'simulated' => $source->isSimulated(),
            'imported_by_user_id' => $actor->getKey(),
        ]);

        EnvelopeAudit::record($envelope, AuditEventType::CloudImportRejected, [
            'import' => $import->ulid,
            'provider' => $source->provider()->value,
            'external_id' => $externalId,
            'code' => $import->rejection_code,
            'simulated' => $import->simulated,
        ]);
    }

    /** Nome de exibição vindo do provedor: sem caracteres de controle, até 255; null se vazio. */
    private static function displayName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));

        return $clean === '' ? null : mb_substr($clean, 0, 255);
    }
}
