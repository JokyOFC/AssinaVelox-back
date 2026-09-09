<?php

namespace App\Services\Envelopes;

use App\Enums\FieldType;

/**
 * Geometria dos campos de assinatura — funções puras, sem banco e sem request.
 *
 * Convenção (docs/campos-e-geometria.md, docs/arquitetura.md §3.1 e a seção
 * "Convencao de coordenadas" de tools/pdftool/README.md):
 *
 *  - `x`, `y`, `width`, `height` são frações em [0,1] da página COMO EXIBIDA, isto é, do
 *    CropBox depois de aplicar `/Rotate` — exatamente o que um canvas HTML sobreposto ao
 *    PDF.js enxerga.
 *  - A origem é o canto SUPERIOR ESQUERDO da página exibida; `y` cresce para baixo.
 *  - A conversão para o espaço do usuário do PDF (origem inferior esquerda, página não
 *    rotacionada, CropBox possivelmente deslocado) é feita por `toPdfRect()`, espelho de
 *    `normalized_to_pdf_rect` em tools/pdftool/pdftool/geometry.py.
 *
 * Tolerância: comparações usam EPSILON para absorver o arredondamento de DECIMAL(9,6).
 */
final class FieldGeometry
{
    /** Casas decimais de signing_fields.x/y/width/height (DECIMAL(9,6)). */
    public const SCALE = 6;

    /** Folga para comparações de ponto flutuante (uma unidade da última casa). */
    public const EPSILON = 1e-6;

    /**
     * Tamanho mínimo por tipo, em PONTOS da página exibida. Em pontos (e não em frações)
     * porque o que importa é o campo ser legível/clicável no papel, independentemente do
     * tamanho da página.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    public const MINIMUM_POINTS = [
        'signature' => [56.0, 20.0],
        'initials' => [22.0, 14.0],
        'name' => [40.0, 9.0],
        'date' => [40.0, 9.0],
        'text' => [18.0, 9.0],
        'checkbox' => [8.0, 8.0],
    ];

    /** Piso absoluto em frações: nada menor que isso sobrevive ao DECIMAL(9,6). */
    public const MINIMUM_FRACTION = 0.001;

    /** Posição padrão da rubrica automática em todas as páginas (RECONCILIACAO §4 Q10). */
    public const AUTO_INITIALS = ['x' => 0.86, 'y' => 0.94, 'width' => 0.10, 'height' => 0.04];

    /** Folga entre duas rubricas automáticas vizinhas, em fração da página. */
    public const AUTO_INITIALS_GAP = 0.01;

    /**
     * Posição da rubrica automática do N-ésimo destinatário (0-based).
     *
     * A constante de Q10 fixa UMA posição; aplicá-la igual para todo mundo empilhava as
     * rubricas de todos os signatários no mesmo retângulo — 100 % de sobreposição em
     * cada página, uma escondendo a outra na tela e as duas imagens carimbadas no mesmo
     * lugar na composição do arquivo final. A posição de Q10 continua sendo a do
     * PRIMEIRO destinatário; os demais são deslocados para a esquerda e, quando a linha
     * enche, para a linha de cima.
     *
     * @return array{x: float, y: float, width: float, height: float}
     */
    public static function autoInitialsSlot(int $index): array
    {
        $width = (float) self::AUTO_INITIALS['width'];
        $height = (float) self::AUTO_INITIALS['height'];
        $x0 = (float) self::AUTO_INITIALS['x'];
        $y0 = (float) self::AUTO_INITIALS['y'];
        $gap = self::AUTO_INITIALS_GAP;

        $columns = max(1, (int) floor($x0 / ($width + $gap)) + 1);
        $rows = max(1, (int) floor($y0 / ($height + $gap)) + 1);

        // Além de `columns * rows` slots a página não comporta mais rubricas sem
        // sobrepor; o resto volta ao começo. Na prática são 8 × 19 = 152 posições, muito
        // acima de qualquer lista de signatários da Fase 1.
        $slot = $index % ($columns * $rows);

        return self::normalize(
            $x0 - ($slot % $columns) * ($width + $gap),
            $y0 - intdiv($slot, $columns) * ($height + $gap),
            $width,
            $height,
        );
    }

    /**
     * Arredonda para a escala da coluna.
     */
    public static function round(float $value): float
    {
        return round($value, self::SCALE);
    }

    /**
     * Normaliza uma geometria para o que será gravado: arredonda para 6 casas e recorta
     * dentro de [0,1] preservando `x + width <= 1` e `y + height <= 1` (o arredondamento
     * sozinho poderia estourar o limite por 1e-7).
     *
     * @return array{x: float, y: float, width: float, height: float}
     */
    public static function normalize(float $x, float $y, float $width, float $height): array
    {
        $x = self::round(max(0.0, min(1.0, $x)));
        $y = self::round(max(0.0, min(1.0, $y)));
        $width = self::round(max(0.0, min(1.0 - $x, $width)));
        $height = self::round(max(0.0, min(1.0 - $y, $height)));

        return ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
    }

    /**
     * Está dentro dos limites da página? (0 ≤ x, 0 ≤ y, x+w ≤ 1, y+h ≤ 1, w > 0, h > 0)
     */
    public static function withinBounds(float $x, float $y, float $width, float $height): bool
    {
        return $x >= -self::EPSILON
            && $y >= -self::EPSILON
            && $width > 0.0
            && $height > 0.0
            && ($x + $width) <= 1.0 + self::EPSILON
            && ($y + $height) <= 1.0 + self::EPSILON;
    }

    /**
     * Tamanho mínimo do tipo convertido em frações da página exibida.
     *
     * @return array{0: float, 1: float}
     */
    public static function minimumFractionFor(FieldType $type, float $displayedWidth, float $displayedHeight): array
    {
        [$minWidthPt, $minHeightPt] = self::MINIMUM_POINTS[$type->value];

        $width = $displayedWidth > 0.0 ? $minWidthPt / $displayedWidth : self::MINIMUM_FRACTION;
        $height = $displayedHeight > 0.0 ? $minHeightPt / $displayedHeight : self::MINIMUM_FRACTION;

        return [
            max(self::MINIMUM_FRACTION, min(1.0, $width)),
            max(self::MINIMUM_FRACTION, min(1.0, $height)),
        ];
    }

    /**
     * Tabela de mínimos em pontos, por tipo, para o editor aplicar as MESMAS regras do
     * servidor ao arrastar/redimensionar (prop `field_minimums` do wizard). O editor
     * converte em fração dividindo pela dimensão exibida da página em questão.
     *
     * @return array<string, array{width_pt: float, height_pt: float}>
     */
    public static function minimumsForProps(): array
    {
        $minimums = [];

        foreach (self::MINIMUM_POINTS as $type => [$width, $height]) {
            $minimums[$type] = ['width_pt' => $width, 'height_pt' => $height];
        }

        return $minimums;
    }

    public static function meetsMinimumSize(FieldType $type, float $width, float $height, PageBox $page): bool
    {
        [$minWidth, $minHeight] = self::minimumFractionFor($type, $page->displayedWidth(), $page->displayedHeight());

        return $width >= $minWidth - self::EPSILON && $height >= $minHeight - self::EPSILON;
    }

    /**
     * Valida a geometria de um campo contra a página real e devolve as mensagens de erro
     * em PT-BR (lista vazia = válido). Chave do array = sufixo do atributo para o erro
     * 422 (`x`, `y`, `width`, `height`), para que o wizard destaque o campo certo.
     *
     * @return array<string, string>
     */
    public static function validate(FieldType $type, float $x, float $y, float $width, float $height, PageBox $page): array
    {
        $errors = [];

        if ($x < -self::EPSILON || $x > 1.0 + self::EPSILON) {
            $errors['x'] = 'A posição horizontal do campo deve estar entre 0 e 1.';
        }

        if ($y < -self::EPSILON || $y > 1.0 + self::EPSILON) {
            $errors['y'] = 'A posição vertical do campo deve estar entre 0 e 1.';
        }

        if ($width <= 0.0) {
            $errors['width'] = 'A largura do campo deve ser maior que zero.';
        }

        if ($height <= 0.0) {
            $errors['height'] = 'A altura do campo deve ser maior que zero.';
        }

        if ($errors !== []) {
            return $errors;
        }

        if (($x + $width) > 1.0 + self::EPSILON) {
            $errors['width'] = 'O campo ultrapassa a borda direita da página.';
        }

        if (($y + $height) > 1.0 + self::EPSILON) {
            $errors['height'] = 'O campo ultrapassa a borda inferior da página.';
        }

        if ($errors !== []) {
            return $errors;
        }

        [$minWidth, $minHeight] = self::minimumFractionFor($type, $page->displayedWidth(), $page->displayedHeight());

        if ($width < $minWidth - self::EPSILON) {
            $errors['width'] = sprintf('O campo "%s" está estreito demais para ser preenchido.', $type->label());
        }

        if ($height < $minHeight - self::EPSILON) {
            $errors['height'] = sprintf('O campo "%s" está baixo demais para ser preenchido.', $type->label());
        }

        return $errors;
    }

    /**
     * Retângulo normalizado (origem superior esquerda) → retângulo no espaço do usuário do
     * PDF, em pontos: `[llx, lly, urx, ury]` com `llx <= urx` e `lly <= ury` em qualquer
     * rotação. Espelho de `normalized_to_pdf_rect` (tools/pdftool/pdftool/geometry.py).
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function toPdfRect(PageBox $page, float $x, float $y, float $width, float $height): array
    {
        $displayedWidth = $page->displayedWidth();
        $displayedHeight = $page->displayedHeight();

        $dx0 = $x * $displayedWidth;
        $dy0 = $y * $displayedHeight;
        $dx1 = ($x + $width) * $displayedWidth;
        $dy1 = ($y + $height) * $displayedHeight;

        $corners = [
            $page->displayedToUser($dx0, $dy0),
            $page->displayedToUser($dx1, $dy0),
            $page->displayedToUser($dx0, $dy1),
            $page->displayedToUser($dx1, $dy1),
        ];

        $xs = array_column($corners, 0);
        $ys = array_column($corners, 1);

        return [min($xs), min($ys), max($xs), max($ys)];
    }

    /**
     * Canvas → normalizado. `canvasWidth`/`canvasHeight` são as dimensões em pixels CSS do
     * elemento que exibe a página (PDF.js já aplica a rotação), e `left`/`top` são as
     * coordenadas do canto superior esquerdo do campo dentro desse elemento.
     *
     * @return array{x: float, y: float, width: float, height: float}
     */
    public static function fromCanvas(
        float $left,
        float $top,
        float $boxWidth,
        float $boxHeight,
        float $canvasWidth,
        float $canvasHeight,
    ): array {
        if ($canvasWidth <= 0.0 || $canvasHeight <= 0.0) {
            return ['x' => 0.0, 'y' => 0.0, 'width' => 0.0, 'height' => 0.0];
        }

        return self::normalize(
            $left / $canvasWidth,
            $top / $canvasHeight,
            $boxWidth / $canvasWidth,
            $boxHeight / $canvasHeight,
        );
    }

    /**
     * Normalizado → canvas (pixels CSS), o inverso de `fromCanvas()`.
     *
     * @return array{left: float, top: float, width: float, height: float}
     */
    public static function toCanvas(
        float $x,
        float $y,
        float $width,
        float $height,
        float $canvasWidth,
        float $canvasHeight,
    ): array {
        return [
            'left' => $x * $canvasWidth,
            'top' => $y * $canvasHeight,
            'width' => $width * $canvasWidth,
            'height' => $height * $canvasHeight,
        ];
    }
}
