<?php

namespace App\Services\Pdf\Dto;

/**
 * Página conforme `pdftool inspect`. widthPt/heightPt já são as dimensões
 * EXIBIDAS (trocadas quando /Rotate é 90/270), prontas para o front calcular
 * as frações normalizadas dos campos.
 */
final readonly class PdfPage
{
    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $mediabox
     * @param  array{0: float, 1: float, 2: float, 3: float}  $cropbox
     */
    public function __construct(
        public int $index,
        public int $rotation,
        public array $mediabox,
        public array $cropbox,
        public float $widthPt,
        public float $heightPt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            index: (int) ($data['index'] ?? 0),
            rotation: (int) ($data['rotation'] ?? 0),
            mediabox: self::box($data['mediabox'] ?? []),
            cropbox: self::box($data['cropbox'] ?? []),
            widthPt: (float) ($data['width_pt'] ?? 0),
            heightPt: (float) ($data['height_pt'] ?? 0),
        );
    }

    public function isLandscape(): bool
    {
        return $this->widthPt > $this->heightPt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'rotation' => $this->rotation,
            'mediabox' => $this->mediabox,
            'cropbox' => $this->cropbox,
            'width_pt' => $this->widthPt,
            'height_pt' => $this->heightPt,
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function box(array $values): array
    {
        $values = array_values($values);

        return [
            (float) ($values[0] ?? 0),
            (float) ($values[1] ?? 0),
            (float) ($values[2] ?? 0),
            (float) ($values[3] ?? 0),
        ];
    }
}
