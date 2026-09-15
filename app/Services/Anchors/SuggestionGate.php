<?php

namespace App\Services\Anchors;

use App\Models\AnchorScan;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\FieldSuggestion;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Sugestão nunca vira campo sem revisão" (Fase 3 §3.2): enquanto houver sugestão PENDENTE na
 * versão exibível corrente de algum documento, ou uma busca ainda em andamento, o envelope não
 * fica pronto. Ganchos (uma linha cada):
 *
 *  - `App\Services\Documents\EnvelopeReadiness::completeness()` — quem grava o status;
 *  - `App\Services\Envelopes\EnvelopeReadiness::issues()` — a lista de pendências do passo 4.
 *
 * Com a flag global `field_anchors` desligada (o padrão) volta ANTES de qualquer consulta: o
 * preparo é exatamente o de antes. Com a flag global ligada mas o plano da organização sem o
 * recurso, também não bloqueia — sem a interface o remetente não teria como revisar, e uma
 * sugestão ignorada nunca vira campo (é o lado seguro).
 *
 * Buscas `failed` nunca bloqueiam (a falha de OCR não trava o fluxo manual) e buscas paradas há
 * mais de `field_anchors.stale_minutes` também não (worker caído).
 */
final class SuggestionGate
{
    public static function blocks(Envelope $envelope): bool
    {
        if (! self::applies($envelope)) {
            return false;
        }

        return self::pendingCount($envelope) > 0 || self::activeScanCount($envelope) > 0;
    }

    /**
     * @return list<string>
     */
    public static function issues(Envelope $envelope): array
    {
        if (! self::applies($envelope)) {
            return [];
        }

        $issues = [];
        $pending = self::pendingCount($envelope);

        if ($pending > 0) {
            $issues[] = $pending === 1
                ? 'Há 1 campo sugerido aguardando revisão. Confirme ou descarte no passo 3.'
                : "Há {$pending} campos sugeridos aguardando revisão. Confirme ou descarte no passo 3.";
        }

        if (self::activeScanCount($envelope) > 0) {
            $issues[] = 'A detecção de campos ainda está em andamento. Aguarde o resultado para revisar as sugestões.';
        }

        return $issues;
    }

    public static function pendingCount(Envelope $envelope): int
    {
        return FieldSuggestion::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('status', SuggestionStatus::Pending->value)
            ->whereIn('document_version_id', self::currentVersionIds($envelope))
            ->count();
    }

    public static function activeScanCount(Envelope $envelope): int
    {
        $versions = self::currentVersionIds($envelope);

        return AnchorScan::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereIn('status', [AnchorScanStatus::Pending->value, AnchorScanStatus::Running->value])
            ->where('created_at', '>=', now()->subMinutes(self::staleMinutes()))
            ->where(fn (Builder $query) => $query
                ->whereNull('document_version_id')
                ->orWhereIn('document_version_id', $versions))
            ->count();
    }

    public static function staleMinutes(): int
    {
        return max(1, (int) config('assinavelox.field_anchors.stale_minutes', 15));
    }

    private static function applies(Envelope $envelope): bool
    {
        if (! AnchorFeatures::globallyEnabled()) {
            return false;
        }

        return AnchorFeatures::fieldAnchors($envelope->organization);
    }

    /**
     * @return list<int>
     */
    private static function currentVersionIds(Envelope $envelope): array
    {
        return array_values(array_map('intval', Document::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereNotNull('current_version_id')
            ->pluck('current_version_id')
            ->all()));
    }
}
