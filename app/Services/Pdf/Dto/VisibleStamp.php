<?php

namespace App\Services\Pdf\Dto;

use InvalidArgumentException;

/**
 * Carimbo visível da assinatura (`sign --visible "<pagina>,<x>,<y>,<w>,<h>"`),
 * coordenadas normalizadas com origem no canto superior esquerdo.
 */
final readonly class VisibleStamp
{
    public function __construct(
        public int $page,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('A página do carimbo deve ser >= 1.');
        }
        if ($x < 0 || $x > 1 || $y < 0 || $y > 1 || $width <= 0 || $height <= 0 || $x + $width > 1.001 || $y + $height > 1.001) {
            throw new InvalidArgumentException('O carimbo deve estar dentro da página (coordenadas normalizadas 0..1).');
        }
    }

    public function toArgument(): string
    {
        return sprintf('%d,%s,%s,%s,%s', $this->page, $this->x, $this->y, $this->width, $this->height);
    }
}
