<?php

namespace App\Services\Anchors;

use App\Integrations\Ocr\OcrEngines;
use App\Models\AnchorScan;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\FieldSuggestion;
use App\Services\Documents\EnvelopeDocuments;
use Illuminate\Database\Eloquent\Collection;

/**
 * JSON do painel "Detectar campos" do editor (Fase 3 §3.2). Contrato em
 * docs/fase-3/ancoras-e-ocr.md §8 e em resources/js/components/anchors/types.ts.
 *
 * Nada aqui vem do texto do documento: só geometria, tipo, o identificador do marcador
 * (`[a-z0-9_-]`) e os estados. A interface exibe tudo como texto (nunca HTML).
 */
final class AnchorPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function envelope(Envelope $envelope): array
    {
        $organization = $envelope->organization;
        $ocrEnabled = AnchorFeatures::ocr($organization);
        $engine = $ocrEnabled ? OcrEngines::make() : null;
        $availability = $engine?->availability();

        $documents = EnvelopeDocuments::ordered($envelope);
        $versionIds = $documents->pluck('current_version_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();

        $suggestions = FieldSuggestion::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('status', SuggestionStatus::Pending->value)
            ->whereIn('document_version_id', $versionIds)
            ->with(['document:id,ulid', 'recipient:id,ulid'])
            ->orderBy('page')
            ->orderBy('y')
            ->orderBy('x')
            ->get();

        $scans = AnchorScan::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $stale = now()->subMinutes(SuggestionGate::staleMinutes());

        $busy = $scans->contains(fn (AnchorScan $scan): bool => $scan->status->isActive()
            && $scan->created_at !== null && $scan->created_at->greaterThanOrEqualTo($stale)
            && ($scan->document_version_id === null || in_array((int) $scan->document_version_id, $versionIds, true)));

        return [
            'enabled' => true,
            'busy' => $busy,
            'pending_count' => $suggestions->count(),
            'ocr' => [
                'enabled' => $ocrEnabled,
                'available' => $availability->available ?? false,
                'engine' => $engine?->name(),
                'simulated' => $engine?->isFake() ?? false,
                'message' => ! $ocrEnabled
                    ? 'A leitura de páginas escaneadas (OCR) não está ativa para a sua organização.'
                    : ($engine?->isFake() ? 'OCR simulado (ambiente de teste)' : $availability?->message()),
            ],
            'documents' => $documents->map(fn (Document $document): array => [
                'id' => $document->ulid,
                'ocr_status' => OcrStatus::fromStored($document->getAttribute('ocr_status'))?->value,
                'ocr_status_label' => OcrStatus::fromStored($document->getAttribute('ocr_status'))?->label(),
                'last_scan' => self::latest($scans, $document, false),
                'last_ocr_scan' => self::latest($scans, $document, true),
            ])->values()->all(),
            'suggestions' => $suggestions->map(fn (FieldSuggestion $suggestion): array => self::suggestion($suggestion))->values()->all(),
            'limits' => [
                'max_literals' => max(0, (int) config('assinavelox.field_anchors.max_literals', 10)),
            ],
            'placements' => AnchorPlacement::options(),
            'field_types' => TemplateAnchorRules::fieldTypeOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function suggestion(FieldSuggestion $suggestion): array
    {
        return [
            'id' => $suggestion->ulid,
            'document_id' => $suggestion->document?->ulid,
            'page' => $suggestion->page,
            'type' => $suggestion->type->value,
            'type_label' => $suggestion->type->label(),
            'x' => (float) $suggestion->x,
            'y' => (float) $suggestion->y,
            'w' => (float) $suggestion->width,
            'h' => (float) $suggestion->height,
            'required' => $suggestion->required,
            'label' => $suggestion->label,
            'recipient_id' => $suggestion->recipient?->ulid,
            'role_hint' => $suggestion->role_hint,
            'source' => $suggestion->source,
            'source_label' => match ($suggestion->source) {
                FieldSuggestion::SOURCE_MARKER => 'Marcador no documento',
                FieldSuggestion::SOURCE_RULE => 'Regra do modelo',
                default => 'Texto procurado',
            },
            'via' => $suggestion->via,
            'confidence' => $suggestion->confidence !== null ? (float) $suggestion->confidence : null,
            // OCR: revisão explícita, uma a uma (fora do "Confirmar todas").
            'requires_explicit_review' => $suggestion->viaOcr(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function scan(AnchorScan $scan): array
    {
        return [
            'id' => $scan->ulid,
            'trigger' => $scan->trigger,
            'status' => $scan->status->value,
            'status_label' => $scan->status->label(),
            'pages_scanned' => $scan->pages_scanned,
            'pages_without_text' => $scan->pages_without_text ?? [],
            'suggestions_count' => $scan->suggestions_count,
            'truncated' => $scan->truncated,
            'engine' => $scan->ocr_engine,
            'simulated' => $scan->ocr_engine === 'fake',
            'failure_message' => $scan->failure_code !== null ? AnchorDetectionException::messageFor($scan->failure_code) : null,
            'finished_at' => $scan->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, AnchorScan>  $scans
     * @return array<string, mixed>|null
     */
    private static function latest($scans, Document $document, bool $ocr): ?array
    {
        $scan = $scans->first(fn (AnchorScan $scan): bool => (int) $scan->document_id === (int) $document->getKey() && $scan->isOcr() === $ocr);

        return $scan instanceof AnchorScan ? self::scan($scan) : null;
    }
}
