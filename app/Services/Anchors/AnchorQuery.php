<?php

namespace App\Services\Anchors;

use App\Enums\FieldType;

/**
 * O que uma busca de âncoras PROCURA (Fase 3 §3.2): marcadores `{{tipo:papel}}` e textos
 * literais com o campo que cada um sugere. Gravado em `anchor_scans.query`.
 *
 * Os textos literais vêm do remetente (busca manual) ou das regras do modelo. São procurados
 * como texto LITERAL pelo pdftool (normalização de espaços, maiúsculas e acentos) — nunca como
 * expressão regular. O spec entregue ao pdftool leva só `id` e `text`; o resto (tipo, pessoa,
 * posição) fica aqui e é aplicado depois, em PHP, sobre as caixas devolvidas.
 *
 * @phpstan-type Literal array{id: string, text: string, field_type: string, recipient: string|null, placement: string, offset_x_pt: float, offset_y_pt: float, width_pt: float|null, height_pt: float|null, required: bool, occurrence: string, rule: int|null, label: string|null}
 */
final readonly class AnchorQuery
{
    public const MIN_LITERAL_LENGTH = 2;

    public const MAX_LITERAL_LENGTH = 120;

    /**
     * @param  list<Literal>  $literals
     */
    public function __construct(
        public bool $markers,
        public array $literals,
        public int $maxMatches,
    ) {}

    public function isEmpty(): bool
    {
        return ! $this->markers && $this->literals === [];
    }

    /**
     * Spec do `pdftool find-anchors --spec` (só identificadores e textos).
     *
     * @return array{markers: bool, literals: list<array{id: string, text: string}>, max_matches: int}
     */
    public function toSpec(): array
    {
        return [
            'markers' => $this->markers,
            'literals' => array_map(
                static fn (array $literal): array => ['id' => $literal['id'], 'text' => $literal['text']],
                $this->literals,
            ),
            'max_matches' => max(1, min(2000, $this->maxMatches)),
        ];
    }

    /**
     * @return array{markers: bool, literals: list<Literal>, max_matches: int}
     */
    public function toArray(): array
    {
        return ['markers' => $this->markers, 'literals' => $this->literals, 'max_matches' => $this->maxMatches];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $literals = [];

        foreach (is_array($data['literals'] ?? null) ? $data['literals'] : [] as $raw) {
            if (! is_array($raw) || ! is_string($raw['id'] ?? null) || ! is_string($raw['text'] ?? null)) {
                continue;
            }

            $literals[] = self::literal(
                id: $raw['id'],
                text: $raw['text'],
                fieldType: is_string($raw['field_type'] ?? null) ? $raw['field_type'] : FieldType::Signature->value,
                recipient: is_string($raw['recipient'] ?? null) ? $raw['recipient'] : null,
                placement: is_string($raw['placement'] ?? null) ? $raw['placement'] : AnchorPlacement::Below->value,
                offsetX: is_numeric($raw['offset_x_pt'] ?? null) ? (float) $raw['offset_x_pt'] : 0.0,
                offsetY: is_numeric($raw['offset_y_pt'] ?? null) ? (float) $raw['offset_y_pt'] : 0.0,
                width: is_numeric($raw['width_pt'] ?? null) ? (float) $raw['width_pt'] : null,
                height: is_numeric($raw['height_pt'] ?? null) ? (float) $raw['height_pt'] : null,
                required: (bool) ($raw['required'] ?? true),
                occurrence: ($raw['occurrence'] ?? 'all') === 'first' ? 'first' : 'all',
                rule: is_numeric($raw['rule'] ?? null) ? (int) $raw['rule'] : null,
                label: is_string($raw['label'] ?? null) ? $raw['label'] : null,
            );
        }

        return new self(
            markers: (bool) ($data['markers'] ?? true),
            literals: $literals,
            maxMatches: is_numeric($data['max_matches'] ?? null) ? (int) $data['max_matches'] : 300,
        );
    }

    /**
     * @return Literal
     */
    public static function literal(
        string $id,
        string $text,
        string $fieldType,
        ?string $recipient = null,
        string $placement = 'below',
        float $offsetX = 0.0,
        float $offsetY = 0.0,
        ?float $width = null,
        ?float $height = null,
        bool $required = true,
        string $occurrence = 'all',
        ?int $rule = null,
        ?string $label = null,
    ): array {
        return [
            'id' => $id,
            'text' => self::normalizeLiteral($text),
            'field_type' => $fieldType,
            'recipient' => $recipient,
            'placement' => AnchorPlacement::tryFrom($placement)->value ?? AnchorPlacement::Below->value,
            'offset_x_pt' => max(-500.0, min(500.0, $offsetX)),
            'offset_y_pt' => max(-500.0, min(500.0, $offsetY)),
            'width_pt' => $width,
            'height_pt' => $height,
            'required' => $required,
            'occurrence' => $occurrence,
            'rule' => $rule,
            'label' => $label,
        ];
    }

    /**
     * @return Literal|null
     */
    public function literalById(string $id): ?array
    {
        foreach ($this->literals as $literal) {
            if ($literal['id'] === $id) {
                return $literal;
            }
        }

        return null;
    }

    /**
     * Texto literal como será procurado: sem caracteres de controle, espaços colapsados.
     * Maiúsculas e acentos são tratados pelo pdftool (os dois lados passam pela mesma
     * normalização lá).
     */
    public static function normalizeLiteral(string $text): string
    {
        $text = (string) preg_replace('/[\p{C}]+/u', ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * O texto é aceitável como literal? (2..120 caracteres depois de normalizado e ao menos
     * uma letra ou um dígito).
     */
    public static function acceptableLiteral(string $text): bool
    {
        $normalized = self::normalizeLiteral($text);
        $length = mb_strlen($normalized);

        return $length >= self::MIN_LITERAL_LENGTH
            && $length <= self::MAX_LITERAL_LENGTH
            && preg_match('/[\p{L}\p{N}]/u', $normalized) === 1;
    }
}
