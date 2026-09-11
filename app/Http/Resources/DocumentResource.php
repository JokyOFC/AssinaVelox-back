<?php

namespace App\Http\Resources;

use App\Enums\DocumentVersionKind;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Prop `document` do wizard (ROUTES §2.6) e do editor de campos.
 *
 * `pdf_url` aponta para `envelopes.document.preview`, que transmite a **versão exibível**
 * (o PDF que o PDF.js abre). `envelopes.download` com type `original` continua servindo
 * o arquivo como o usuário enviou — que para DOCX e imagem não é um PDF.
 *
 * As miniaturas do rail são renderizadas no navegador pelo próprio PDF.js a partir desse
 * mesmo PDF; por isso `page_thumb_url_template` é `null` (ver docs/preparacao-documental.md).
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Document $document */
        $document = $this->resource;

        $version = $document->currentVersion;
        $original = self::originalVersion($document);

        return [
            'id' => $document->ulid,
            'original_name' => $document->original_filename,
            'size_bytes' => $original === null ? 0 : (int) $original->size_bytes,
            'mime' => $original === null ? '' : $original->mime_type,
            'source_type' => $document->source_type->value,
            'processing' => self::processing($document),
            'pdf_url' => $version === null
                ? null
                : route('envelopes.document.preview', self::routeParameters($document)),
            'page_thumb_url_template' => null,
            'page_sizes' => self::pageSizes($version),
            'sha256' => $original?->sha256,
            // Fase 2 §2.3 (aditivos): posição na lista e nome de exibição.
            'position' => (int) $document->position,
            'name' => $document->name,
        ];
    }

    /**
     * Parâmetros das rotas por documento. O primeiro arquivo (position 1) dispensa
     * `document` — é o padrão das rotas, e a URL da Fase 1 continua exatamente igual.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public static function routeParameters(Document $document, array $extra = []): array
    {
        $parameters = ['envelope' => $document->envelope->ulid] + $extra;

        if ((int) $document->position > 1) {
            $parameters['document'] = $document->ulid;
        }

        return $parameters;
    }

    /**
     * Versão original (kind=original) do documento, ou null quando o registro sumiu.
     */
    public static function originalVersion(Document $document): ?DocumentVersion
    {
        return $document->versions()
            ->where('kind', DocumentVersionKind::Original->value)
            ->orderBy('version_number')
            ->first();
    }

    /**
     * Bloco `processing` — mesmo shape devolvido por `envelopes.document.status`.
     *
     * @return array<string, mixed>
     */
    public static function processing(Document $document): array
    {
        return [
            'status' => $document->processing_status->value,
            'label' => $document->processing_status->label(),
            'pages' => $document->page_count,
            'error' => $document->failure_message,
            'failure_code' => $document->failure_code,
            'ready' => $document->isReady() && $document->current_version_id !== null,
            'terminal' => $document->processing_status->isTerminal(),
            // O pipeline não expõe percentual real; a UI mostra progresso indeterminado.
            'progress_pct' => null,
        ];
    }

    /**
     * Tamanho exibido de cada página (já com a rotação aplicada), para o front converter
     * as coordenadas dos campos.
     *
     * @return list<array<string, mixed>>
     */
    public static function pageSizes(?DocumentVersion $version): array
    {
        $sizes = [];

        foreach ($version === null ? [] : ($version->pages_meta ?? []) as $index => $page) {
            $sizes[] = [
                'page' => (int) ($page['index'] ?? $index + 1),
                'width_pt' => (float) ($page['width_pt'] ?? 0),
                'height_pt' => (float) ($page['height_pt'] ?? 0),
                'rotation' => (int) ($page['rotation'] ?? 0),
            ];
        }

        return $sizes;
    }
}
