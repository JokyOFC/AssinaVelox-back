<?php

namespace App\Services\Pdf\Dto;

use App\Enums\FieldType;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Builder fluente do plano de `pdftool compose`.
 *
 * Serializa exatamente o formato documentado em tools/pdftool/README.md:
 * {"source": "...", "font": {"path": "...", "name": "..."}, "fields": [...]}.
 * Coordenadas normalizadas [0, 1] relativas ao CropBox exibido (após /Rotate),
 * origem no canto superior esquerdo, y para baixo — o mesmo referencial de
 * signing_fields no banco e do canvas sobre o PDF.js.
 */
final class ComposePlan implements JsonSerializable
{
    public const TYPES = ['signature', 'initials', 'name', 'date', 'text', 'checkbox'];

    public const ALIGNMENTS = ['left', 'center', 'right'];

    public const BOXES = ['cropbox', 'mediabox'];

    /** @var array{path: string, name: string}|null */
    private ?array $font = null;

    /** @var list<ComposeField> */
    private array $fields = [];

    private function __construct(private readonly string $source)
    {
        if (trim($source) === '') {
            throw new InvalidArgumentException('O caminho do PDF de origem do plano não pode ser vazio.');
        }
    }

    /**
     * PDF de origem (caminho absoluto).
     */
    public static function source(string $sourcePath): self
    {
        return new self($sourcePath);
    }

    /**
     * Fonte TrueType opcional (Unicode completo). Sem fonte: Helvetica (WinAnsi).
     */
    public function font(string $path, ?string $name = null): self
    {
        if (trim($path) === '') {
            throw new InvalidArgumentException('O caminho da fonte não pode ser vazio.');
        }

        $this->font = ['path' => $path, 'name' => $name ?? pathinfo($path, PATHINFO_FILENAME)];

        return $this;
    }

    /**
     * Adiciona um campo. Para signature/initials, $value é o caminho da imagem
     * (PNG/JPEG/WEBP); para name/date/text é a string; para checkbox, bool.
     *
     * @param  array{font_size?: float|int|null, align?: string|null, box?: string|null}  $options
     */
    public function addField(
        string $id,
        int $page,
        FieldType|string $type,
        float $x,
        float $y,
        float $width,
        float $height,
        mixed $value = null,
        array $options = [],
    ): self {
        $type = $type instanceof FieldType ? $type->value : strtolower(trim($type));

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Campo {$id}: tipo desconhecido '{$type}'.");
        }
        if (trim($id) === '') {
            throw new InvalidArgumentException('O id do campo não pode ser vazio.');
        }
        if ($page < 1) {
            throw new InvalidArgumentException("Campo {$id}: a página deve ser >= 1.");
        }

        $eps = 1e-6;
        if ($x < -$eps || $x > 1 + $eps || $y < -$eps || $y > 1 + $eps) {
            throw new InvalidArgumentException("Campo {$id}: x e y devem estar em [0, 1].");
        }
        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException("Campo {$id}: width e height devem ser > 0.");
        }
        if ($x + $width > 1 + 1e-3 || $y + $height > 1 + 1e-3) {
            throw new InvalidArgumentException("Campo {$id}: o campo ultrapassa os limites da página.");
        }

        $image = null;
        if (in_array($type, ComposeField::IMAGE_TYPES, true)) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("Campo {$id}: campos de imagem exigem o caminho da imagem.");
            }
            $image = $value;
            $value = null;
        } elseif ($type === 'checkbox') {
            $value = (bool) $value;
        } else {
            $value = $value === null ? '' : (string) $value;
        }

        $fontSize = $options['font_size'] ?? null;
        if ($fontSize !== null) {
            $fontSize = (float) $fontSize;
            if ($fontSize <= 0) {
                throw new InvalidArgumentException("Campo {$id}: font_size deve ser > 0.");
            }
        }

        $align = $options['align'] ?? null;
        if ($align !== null) {
            $align = strtolower($align);
            if (! in_array($align, self::ALIGNMENTS, true)) {
                throw new InvalidArgumentException("Campo {$id}: align deve ser left, center ou right.");
            }
        }

        $box = $options['box'] ?? null;
        if ($box !== null) {
            $box = strtolower($box);
            if (! in_array($box, self::BOXES, true)) {
                throw new InvalidArgumentException("Campo {$id}: box deve ser cropbox ou mediabox.");
            }
        }

        $this->fields[] = new ComposeField(
            id: $id,
            page: $page,
            type: $type,
            x: $x,
            y: $y,
            width: $width,
            height: $height,
            value: $value,
            image: $image,
            fontSize: $fontSize,
            align: $align,
            box: $box,
        );

        return $this;
    }

    /**
     * @param  array{font_size?: float|int|null, align?: string|null, box?: string|null}  $options
     */
    public function addText(string $id, int $page, float $x, float $y, float $width, float $height, string $value, array $options = []): self
    {
        return $this->addField($id, $page, FieldType::Text, $x, $y, $width, $height, $value, $options);
    }

    /**
     * @param  array{box?: string|null}  $options
     */
    public function addImage(string $id, int $page, FieldType|string $type, float $x, float $y, float $width, float $height, string $imagePath, array $options = []): self
    {
        return $this->addField($id, $page, $type, $x, $y, $width, $height, $imagePath, $options);
    }

    /**
     * @param  array{box?: string|null}  $options
     */
    public function addCheckbox(string $id, int $page, float $x, float $y, float $width, float $height, bool $checked, array $options = []): self
    {
        return $this->addField($id, $page, FieldType::Checkbox, $x, $y, $width, $height, $checked, $options);
    }

    public function sourcePath(): string
    {
        return $this->source;
    }

    /**
     * @return list<ComposeField>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function count(): int
    {
        return count($this->fields);
    }

    /**
     * Caminhos de imagem referenciados (para validação prévia de existência).
     *
     * @return list<string>
     */
    public function imagePaths(): array
    {
        $paths = [];
        foreach ($this->fields as $field) {
            if ($field->image !== null) {
                $paths[] = $field->image;
            }
        }

        return $paths;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $plan = ['source' => $this->source];
        if ($this->font !== null) {
            $plan['font'] = $this->font;
        }
        $plan['fields'] = array_map(fn (ComposeField $field): array => $field->toArray(), $this->fields);

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
