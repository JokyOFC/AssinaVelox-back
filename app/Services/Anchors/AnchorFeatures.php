<?php

namespace App\Services\Anchors;

use App\Models\Organization;
use App\Services\Envelopes\DomainFeatures;

/**
 * Flags `field_anchors` e `ocr` (Fase 3 §3.2, roadmap T8) — nascem DESLIGADAS.
 *
 * Mesma regra das flags da organização ({@see DomainFeatures::enabled()}): interruptor global
 * `assinavelox.features.*` (padrão false) E `plans.features.*` do plano vigente. `ocr` exige
 * também `field_anchors`: o OCR só existe para alimentar a busca de âncoras.
 *
 * A flag liga a INTERFACE, as rotas e o bloqueio de prontidão por sugestão pendente. Desligada,
 * nenhuma consulta é feita no preparo ({@see SuggestionGate::blocks()} volta cedo) e as rotas
 * respondem 404.
 *
 * Contrato para `HandleInertiaRequests::features()`: as chaves `field_anchors` e `ocr` vêm de
 * {@see self::fieldAnchors()} e {@see self::ocr()} para a organização corrente.
 */
final class AnchorFeatures
{
    public const FIELD_ANCHORS = 'field_anchors';

    public const OCR = 'ocr';

    public static function fieldAnchors(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::FIELD_ANCHORS, $organization);
    }

    public static function ocr(?Organization $organization): bool
    {
        return self::fieldAnchors($organization) && DomainFeatures::enabled(self::OCR, $organization);
    }

    /**
     * Só o interruptor global, SEM consulta ao banco — usado no caminho quente da prontidão.
     */
    public static function globallyEnabled(): bool
    {
        return (bool) config('assinavelox.features.'.self::FIELD_ANCHORS, false) === true;
    }

    /**
     * @return array{field_anchors: bool, ocr: bool}
     */
    public static function forOrganization(?Organization $organization): array
    {
        return [
            self::FIELD_ANCHORS => self::fieldAnchors($organization),
            self::OCR => self::ocr($organization),
        ];
    }

    public static function ensure(?Organization $organization): void
    {
        abort_unless(self::fieldAnchors($organization), 404);
    }
}
