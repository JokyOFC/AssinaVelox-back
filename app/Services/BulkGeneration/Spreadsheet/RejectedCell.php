<?php

namespace App\Services\BulkGeneration\Spreadsheet;

/**
 * Célula recusada na leitura (fórmula, erro de planilha, valor com cara de fórmula, texto
 * longo demais). O valor original NÃO é guardado: só o motivo, que vira erro da linha se a
 * coluna estiver mapeada. Coluna não mapeada com célula recusada é simplesmente ignorada.
 */
final class RejectedCell
{
    public const FORMULA = 'formula';

    public const FORMULA_LIKE = 'formula_like';

    public const SPREADSHEET_ERROR = 'spreadsheet_error';

    public const TOO_LONG = 'too_long';

    public const UNSUPPORTED = 'unsupported';

    public function __construct(
        public readonly string $reason,
        public readonly string $message,
    ) {}

    public static function formula(): self
    {
        return new self(self::FORMULA, 'a célula contém uma fórmula. Fórmulas nunca são calculadas aqui: digite o valor final.');
    }

    public static function formulaLike(): self
    {
        return new self(self::FORMULA_LIKE, 'o valor começa com "=", "+", "-" ou "@" e foi recusado por segurança. Remova o sinal do início.');
    }

    public static function spreadsheetError(): self
    {
        return new self(self::SPREADSHEET_ERROR, 'a célula contém um erro da planilha (como #N/D ou #DIV/0!).');
    }

    public static function tooLong(int $max): self
    {
        return new self(self::TOO_LONG, sprintf('o valor passa de %s caracteres.', number_format($max, 0, ',', '.')));
    }

    public static function unsupported(): self
    {
        return new self(self::UNSUPPORTED, 'o tipo desta célula não é aceito. Use texto, número ou data.');
    }
}
