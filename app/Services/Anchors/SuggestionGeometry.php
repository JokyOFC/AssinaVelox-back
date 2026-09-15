<?php

namespace App\Services\Anchors;

use App\Enums\FieldType;
use App\Services\Envelopes\FieldGeometry;
use App\Services\Envelopes\PageBox;

/**
 * Da caixa de uma âncora ao campo sugerido — funções puras (Fase 3 §3.2).
 *
 * Tudo no sistema de `FieldGeometry` (docs/arquitetura.md §3.1): frações da página EXIBIDA
 * (CropBox depois de `/Rotate`), origem no canto superior esquerdo, `y` para baixo. Os
 * deslocamentos e tamanhos das regras estão em PONTOS da página exibida (positivo = para a
 * direita / para baixo), convertidos com as dimensões de `pages_meta` — nunca do navegador.
 *
 * O resultado passa por {@see FieldGeometry::validate()} com a página real: um campo que não
 * cabe (página menor que o mínimo do tipo) não vira sugestão.
 */
final class SuggestionGeometry
{
    /** Tamanho padrão da sugestão por tipo, em pontos (sempre ≥ o mínimo de FieldGeometry). */
    public const DEFAULT_POINTS = [
        'signature' => [150.0, 40.0],
        'initials' => [56.0, 26.0],
        'name' => [150.0, 18.0],
        'date' => [90.0, 18.0],
        'text' => [150.0, 18.0],
        'checkbox' => [14.0, 14.0],
        'cpf' => [110.0, 18.0],
        'stamp' => [120.0, 40.0],
    ];

    /** Folga entre o texto e o campo, em pontos. */
    public const GAP_PT = 2.0;

    /**
     * @param  array{x: float, y: float, width: float, height: float}  $anchor  caixa normalizada
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    public static function place(
        PageBox $page,
        array $anchor,
        FieldType $type,
        AnchorPlacement $placement,
        float $offsetX = 0.0,
        float $offsetY = 0.0,
        ?float $widthPt = null,
        ?float $heightPt = null,
        bool $coverAnchorWidth = false,
    ): ?array {
        $pageWidth = $page->displayedWidth();
        $pageHeight = $page->displayedHeight();

        if ($pageWidth <= 0.0 || $pageHeight <= 0.0) {
            return null;
        }

        [$defaultWidth, $defaultHeight] = self::DEFAULT_POINTS[$type->value];
        [$minWidth, $minHeight] = FieldGeometry::MINIMUM_POINTS[$type->value];

        $width = max($minWidth, $widthPt ?? $defaultWidth);
        $height = max($minHeight, $heightPt ?? $defaultHeight);

        $anchorX = $anchor['x'] * $pageWidth;
        $anchorY = $anchor['y'] * $pageHeight;
        $anchorWidth = $anchor['width'] * $pageWidth;
        $anchorHeight = $anchor['height'] * $pageHeight;

        // Marcador: o campo cobre o marcador inteiro (ele some embaixo do campo na composição).
        if ($coverAnchorWidth && $widthPt === null) {
            $width = max($width, $anchorWidth);
        }

        [$x, $y] = match ($placement) {
            AnchorPlacement::Below => [$anchorX, $anchorY + $anchorHeight + self::GAP_PT],
            AnchorPlacement::Above => [$anchorX, $anchorY - $height - self::GAP_PT],
            AnchorPlacement::Right => [$anchorX + $anchorWidth + 2 * self::GAP_PT, $anchorY + $anchorHeight / 2 - $height / 2],
            AnchorPlacement::Over => [$anchorX, $anchorY + $anchorHeight / 2 - $height / 2],
        };

        $x += $offsetX;
        $y += $offsetY;

        $width = min($width, $pageWidth);
        $height = min($height, $pageHeight);
        // Desloca para dentro da página em vez de encolher: o tamanho continua o pedido.
        $x = max(0.0, min($x, $pageWidth - $width));
        $y = max(0.0, min($y, $pageHeight - $height));

        $geometry = FieldGeometry::normalize($x / $pageWidth, $y / $pageHeight, $width / $pageWidth, $height / $pageHeight);

        if (FieldGeometry::validate($type, $geometry['x'], $geometry['y'], $geometry['width'], $geometry['height'], $page) !== []) {
            return null;
        }

        return $geometry;
    }

    /**
     * Interseção sobre união de duas caixas normalizadas (0 = disjuntas, 1 = iguais).
     *
     * @param  array{x: float, y: float, width: float, height: float}  $a
     * @param  array{x: float, y: float, width: float, height: float}  $b
     */
    public static function overlap(array $a, array $b): float
    {
        $left = max($a['x'], $b['x']);
        $top = max($a['y'], $b['y']);
        $right = min($a['x'] + $a['width'], $b['x'] + $b['width']);
        $bottom = min($a['y'] + $a['height'], $b['y'] + $b['height']);

        if ($right <= $left || $bottom <= $top) {
            return 0.0;
        }

        $intersection = ($right - $left) * ($bottom - $top);
        $union = $a['width'] * $a['height'] + $b['width'] * $b['height'] - $intersection;

        return $union > 0.0 ? $intersection / $union : 0.0;
    }
}
