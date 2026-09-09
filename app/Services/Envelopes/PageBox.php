<?php

namespace App\Services\Envelopes;

use App\Enums\FieldBoxType;

/**
 * Caixa de referência de uma página (CropBox por padrão) em pontos PDF, mais a rotação
 * declarada em `/Rotate`. Espelha `PageMeta` de tools/pdftool/pdftool/geometry.py.
 *
 * Todos os valores vêm de `document_versions.pages_meta` (produzido por `pdftool inspect`);
 * nada aqui é lido do navegador.
 */
final readonly class PageBox
{
    public function __construct(
        public float $x0,
        public float $y0,
        public float $x1,
        public float $y1,
        public int $rotation = 0,
        public FieldBoxType $boxType = FieldBoxType::CropBox,
    ) {}

    /**
     * Constrói a caixa a partir de uma entrada de `pages_meta`.
     *
     * Aceita `cropbox`/`mediabox` (4 números) e cai para `width_pt`/`height_pt` quando os
     * boxes não vieram. Atenção: `width_pt`/`height_pt` de `pdftool inspect` já são as
     * dimensões EXIBIDAS (trocadas em 90/270), então o retângulo reconstruído a partir
     * delas desfaz a troca.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function fromPageMeta(array $meta, FieldBoxType $boxType = FieldBoxType::CropBox): self
    {
        $rotation = self::normalizeRotation($meta['rotation'] ?? 0);

        $media = self::rect($meta['mediabox'] ?? null);
        $crop = self::rect($meta['cropbox'] ?? null);

        if ($media !== null && $crop !== null) {
            $crop = self::intersect($crop, $media) ?? $media;
        }

        $chosen = $boxType === FieldBoxType::MediaBox
            ? ($media ?? $crop)
            : ($crop ?? $media);

        if ($chosen === null) {
            // Sem boxes: reconstrói a partir das dimensões exibidas.
            $displayedWidth = (float) ($meta['width_pt'] ?? 0);
            $displayedHeight = (float) ($meta['height_pt'] ?? 0);

            [$width, $height] = in_array($rotation, [90, 270], true)
                ? [$displayedHeight, $displayedWidth]
                : [$displayedWidth, $displayedHeight];

            $chosen = [0.0, 0.0, $width, $height];
        }

        return new self($chosen[0], $chosen[1], $chosen[2], $chosen[3], $rotation, $boxType);
    }

    public function width(): float
    {
        return $this->x1 - $this->x0;
    }

    public function height(): float
    {
        return $this->y1 - $this->y0;
    }

    /**
     * Largura da página COMO EXIBIDA (trocada quando `/Rotate` é 90 ou 270).
     */
    public function displayedWidth(): float
    {
        return in_array($this->rotation, [90, 270], true) ? $this->height() : $this->width();
    }

    public function displayedHeight(): float
    {
        return in_array($this->rotation, [90, 270], true) ? $this->width() : $this->height();
    }

    public function isDegenerate(): bool
    {
        return $this->width() <= 0.0 || $this->height() <= 0.0;
    }

    /**
     * Ponto exibido (origem no canto superior esquerdo, y para baixo, em pontos) → espaço
     * do usuário do PDF (origem inferior esquerda da página NÃO rotacionada).
     *
     * Idêntico a `displayed_to_user` de tools/pdftool/pdftool/geometry.py.
     *
     * @return array{0: float, 1: float}
     */
    public function displayedToUser(float $dx, float $dy): array
    {
        return match ($this->rotation) {
            90 => [$this->x0 + $dy, $this->y0 + $dx],
            180 => [$this->x1 - $dx, $this->y0 + $dy],
            270 => [$this->x1 - $dy, $this->y1 - $dx],
            default => [$this->x0 + $dx, $this->y1 - $dy],
        };
    }

    /**
     * @return array{width_pt: float, height_pt: float, rotation: int, box: string}
     */
    public function toArray(): array
    {
        return [
            'width_pt' => $this->displayedWidth(),
            'height_pt' => $this->displayedHeight(),
            'rotation' => $this->rotation,
            'box' => $this->boxType->value,
        ];
    }

    /**
     * Qualquer valor de `/Rotate` (negativo, > 360, não múltiplo) → 0/90/180/270.
     */
    public static function normalizeRotation(mixed $rotation): int
    {
        $value = is_numeric($rotation) ? (int) round((float) $rotation) : 0;
        $value %= 360;

        if ($value < 0) {
            $value += 360;
        }

        return ((int) round($value / 90) * 90) % 360;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private static function rect(mixed $values): ?array
    {
        if (! is_array($values) || count($values) !== 4) {
            return null;
        }

        $numbers = [];

        foreach (array_values($values) as $value) {
            if (! is_numeric($value)) {
                return null;
            }

            $numbers[] = (float) $value;
        }

        $rect = [
            min($numbers[0], $numbers[2]),
            min($numbers[1], $numbers[3]),
            max($numbers[0], $numbers[2]),
            max($numbers[1], $numbers[3]),
        ];

        return ($rect[2] - $rect[0]) <= 0.0 || ($rect[3] - $rect[1]) <= 0.0 ? null : $rect;
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $a
     * @param  array{0: float, 1: float, 2: float, 3: float}  $b
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private static function intersect(array $a, array $b): ?array
    {
        $rect = [max($a[0], $b[0]), max($a[1], $b[1]), min($a[2], $b[2]), min($a[3], $b[3])];

        return ($rect[2] - $rect[0]) <= 0.0 || ($rect[3] - $rect[1]) <= 0.0 ? null : $rect;
    }
}
