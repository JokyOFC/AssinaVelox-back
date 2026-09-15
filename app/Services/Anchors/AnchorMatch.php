<?php

namespace App\Services\Anchors;

/**
 * Uma ocorrência devolvida pelo `pdftool find-anchors`. Tudo é conferido de novo aqui (defesa
 * em profundidade, roadmap T6): uma entrada fora do contrato é descartada, nunca "corrigida".
 * Não há texto do documento: só o tipo do marcador, um identificador `[a-z0-9_-]` ou o id do
 * literal procurado.
 */
final readonly class AnchorMatch
{
    public const MARKER_FIELD_TYPES = ['signature', 'initials', 'date', 'text'];

    /**
     * @param  array{x: float, y: float, width: float, height: float}  $box
     * @param  array{x: float, y: float, width: float, height: float}  $lineBox
     */
    public function __construct(
        public int $page,
        public string $source,
        public string $kind,
        public ?string $fieldType,
        public ?string $key,
        public ?string $literalId,
        public array $box,
        public array $lineBox,
        public ?float $confidence,
        public int $lines,
    ) {}

    public function isMarker(): bool
    {
        return $this->kind === 'marker';
    }

    public function viaOcr(): bool
    {
        return $this->source === 'ocr';
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function tryFromArray(array $raw, int $pageCount): ?self
    {
        $page = $raw['page'] ?? null;
        $source = $raw['source'] ?? null;
        $kind = $raw['kind'] ?? null;

        if (! is_int($page) || $page < 1 || ($pageCount > 0 && $page > $pageCount)) {
            return null;
        }

        if (! in_array($source, ['text', 'ocr'], true) || ! in_array($kind, ['marker', 'literal'], true)) {
            return null;
        }

        $box = self::box($raw['box'] ?? null);
        $lineBox = self::box($raw['line_box'] ?? null) ?? $box;

        if ($box === null || $lineBox === null) {
            return null;
        }

        $fieldType = null;
        $key = null;
        $literalId = null;

        if ($kind === 'marker') {
            $fieldType = $raw['field_type'] ?? null;
            $key = $raw['key'] ?? null;

            if (! in_array($fieldType, self::MARKER_FIELD_TYPES, true)
                || ! is_string($key) || preg_match('/^[a-z0-9_-]{1,40}$/', $key) !== 1) {
                return null;
            }
        } else {
            $literalId = $raw['literal_id'] ?? null;

            if (! is_string($literalId) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $literalId) !== 1) {
                return null;
            }
        }

        $confidence = $raw['confidence'] ?? null;

        return new self(
            page: $page,
            source: (string) $source,
            kind: (string) $kind,
            fieldType: $fieldType,
            key: $key,
            literalId: $literalId,
            box: $box,
            lineBox: $lineBox,
            confidence: is_int($confidence) || is_float($confidence) ? max(0.0, min(100.0, (float) $confidence)) : null,
            lines: is_int($raw['lines'] ?? null) ? max(1, (int) $raw['lines']) : 1,
        );
    }

    /**
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private static function box(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $values = [];

        foreach (['x', 'y', 'width', 'height'] as $key) {
            $value = $raw[$key] ?? null;

            if (! is_int($value) && ! is_float($value)) {
                return null;
            }

            $values[$key] = (float) $value;
        }

        if ($values['x'] < -1e-6 || $values['y'] < -1e-6 || $values['width'] <= 0.0 || $values['height'] <= 0.0
            || 1.0 + 1e-6 < $values['x'] + $values['width'] || 1.0 + 1e-6 < $values['y'] + $values['height']) {
            return null;
        }

        return $values;
    }
}
