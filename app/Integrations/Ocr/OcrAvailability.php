<?php

namespace App\Integrations\Ocr;

/**
 * Resultado da verificação de disponibilidade do OCR. `reason` é um código para log e para a
 * documentação de operação; a interface mostra só {@see self::message()}.
 */
final readonly class OcrAvailability
{
    public const NOT_CONFIGURED = 'not_configured';

    public const BINARY_MISSING = 'binary_missing';

    public const LANGUAGE_MISSING = 'language_missing';

    public const PROBE_FAILED = 'probe_failed';

    private function __construct(
        public bool $available,
        public ?string $reason,
    ) {}

    public static function available(): self
    {
        return new self(true, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, $reason);
    }

    public function message(): string
    {
        return $this->available ? 'OCR disponível neste servidor' : 'OCR indisponível neste servidor';
    }
}
