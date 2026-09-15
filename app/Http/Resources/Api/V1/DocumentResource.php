<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\DocumentVersionKind;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Services\Api\ApiFormat;
use App\Services\Documents\EnvelopeDocuments;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Arquivo do envelope na API v1: estado do processamento e resumos SHA-256 conhecidos
 * (original, enviado, final). Nunca o caminho no disco, a URL de armazenamento ou o conteúdo;
 * os bytes saem só pela rota autorizada `files/{type}`.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /** @var Collection<int, DocumentVersion>|null */
    protected ?Collection $versions = null;

    /**
     * @param  Collection<int, DocumentVersion>  $versions
     */
    public function withVersions(Collection $versions): static
    {
        $this->versions = $versions;

        return $this;
    }

    /**
     * Todos os arquivos do envelope, na ordem de apresentação, com uma consulta de versões.
     *
     * @return list<array<string, mixed>>
     */
    public static function listFor(Envelope $envelope, Request $request): array
    {
        $documents = EnvelopeDocuments::ordered($envelope);

        if ($documents->isEmpty()) {
            return [];
        }

        $versions = DocumentVersion::withoutOrganizationScope()
            ->whereIn('document_id', $documents->pluck('id')->all())
            ->get(['id', 'document_id', 'kind', 'sha256', 'size_bytes', 'page_count', 'version_number'])
            ->groupBy('document_id');

        $items = [];

        foreach ($documents as $document) {
            $items[] = (new self($document))
                ->withVersions($versions->get($document->getKey(), collect()))
                ->resolve($request);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Document $document */
        $document = $this->resource;

        $versions = $this->versions ?? DocumentVersion::withoutOrganizationScope()
            ->where('document_id', $document->getKey())
            ->get(['id', 'document_id', 'kind', 'sha256', 'size_bytes', 'page_count', 'version_number']);

        $original = $versions->where('kind', DocumentVersionKind::Original)->sortBy('version_number')->first();
        $current = $document->current_version_id === null ? null : $versions->firstWhere('id', $document->current_version_id);
        $sent = $document->sent_version_id === null ? null : $versions->firstWhere('id', $document->sent_version_id);
        $final = $document->final_version_id === null ? null : $versions->firstWhere('id', $document->final_version_id);

        return [
            'id' => $document->ulid,
            'object' => 'document',
            'position' => (int) $document->position,
            'name' => $document->name,
            'original_filename' => $document->original_filename,
            'source_type' => $document->source_type->value,
            'processing_status' => $document->processing_status->value,
            'processing_label' => $document->processing_status->label(),
            /** @var bool */
            'ready' => $document->isReady() && $document->current_version_id !== null,
            'pages' => $document->page_count ?? $current->page_count ?? null,
            /** @var int|null */
            'size_bytes' => $original->size_bytes ?? $current->size_bytes ?? null,
            'sha256' => [
                /** @var string|null */
                'original' => $original?->sha256,
                /** @var string|null */
                'sent' => $sent?->sha256,
                /** @var string|null */
                'final' => $final?->sha256,
            ],
            'failure' => $document->failure_code === null ? null : [
                'code' => $document->failure_code,
                'message' => $document->failure_message,
            ],
            'created_at' => ApiFormat::date($document->created_at),
        ];
    }
}
