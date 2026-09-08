<?php

namespace App\Services\Pdf\Dto;

/**
 * Um campo do plano de `compose`, já validado. Coordenadas normalizadas em
 * [0, 1] relativas à caixa exibida (CropBox após /Rotate), origem no canto
 * superior esquerdo, y crescendo para baixo (README do pdftool).
 */
final readonly class ComposeField
{
    public const IMAGE_TYPES = ['signature', 'initials'];

    public const TEXT_TYPES = ['name', 'date', 'text'];

    public function __construct(
        public string $id,
        public int $page,
        public string $type,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public mixed $value = null,
        public ?string $image = null,
        public ?float $fontSize = null,
        public ?string $align = null,
        public ?string $box = null,
    ) {}

    public function isImage(): bool
    {
        return in_array($this->type, self::IMAGE_TYPES, true);
    }

    /**
     * Serialização exatamente no formato aceito pelo pdftool.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'page' => $this->page,
            'type' => $this->type,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
        ];

        if ($this->isImage()) {
            $data['image'] = $this->image;
        } else {
            $data['value'] = $this->value;
        }

        if ($this->fontSize !== null) {
            $data['font_size'] = $this->fontSize;
        }
        if ($this->align !== null) {
            $data['align'] = $this->align;
        }
        if ($this->box !== null) {
            $data['box'] = $this->box;
        }

        return $data;
    }
}
