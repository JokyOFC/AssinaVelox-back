<?php

use App\Enums\FieldBoxType;
use App\Enums\FieldType;
use App\Services\Envelopes\FieldGeometry;
use App\Services\Envelopes\PageBox;

/*
|--------------------------------------------------------------------------
| Geometria pura (App\Services\Envelopes\FieldGeometry / PageBox)
|--------------------------------------------------------------------------
| Convenção: frações [0,1] do CropBox EXIBIDO (depois de /Rotate), origem no canto
| superior esquerdo, y para baixo — a mesma de tools/pdftool/pdftool/geometry.py.
| Os valores esperados abaixo foram calculados à mão a partir daquele algoritmo.
*/

/** CropBox deslocado: [10, 20, 210, 320] → 200 × 300 pt. */
function offsetPage(int $rotation): PageBox
{
    return PageBox::fromPageMeta([
        'mediabox' => [0, 0, 220, 340],
        'cropbox' => [10, 20, 210, 320],
        'rotation' => $rotation,
        'width_pt' => in_array($rotation, [90, 270], true) ? 300 : 200,
        'height_pt' => in_array($rotation, [90, 270], true) ? 200 : 300,
    ]);
}

test('o tamanho exibido troca largura e altura em 90 e 270', function () {
    expect([offsetPage(0)->displayedWidth(), offsetPage(0)->displayedHeight()])->toBe([200.0, 300.0]);
    expect([offsetPage(90)->displayedWidth(), offsetPage(90)->displayedHeight()])->toBe([300.0, 200.0]);
    expect([offsetPage(180)->displayedWidth(), offsetPage(180)->displayedHeight()])->toBe([200.0, 300.0]);
    expect([offsetPage(270)->displayedWidth(), offsetPage(270)->displayedHeight()])->toBe([300.0, 200.0]);
});

test('normalizado → espaço do PDF com CropBox deslocado, nas quatro rotações', function (int $rotation, array $expected) {
    $rect = FieldGeometry::toPdfRect(offsetPage($rotation), 0.1, 0.2, 0.3, 0.1);

    expect(array_map(fn (float $v): float => round($v, 4), $rect))->toBe($expected);
})->with([
    // rot 0: origem exibida = canto superior esquerdo do CropBox (10, 320).
    [0, [30.0, 230.0, 90.0, 260.0]],
    // rot 90: a página é girada no sentido horário; tela→direita = +y do usuário.
    [90, [50.0, 50.0, 70.0, 140.0]],
    [180, [130.0, 80.0, 190.0, 110.0]],
    [270, [150.0, 200.0, 170.0, 290.0]],
]);

test('o retângulo devolvido é sempre normalizado (llx ≤ urx, lly ≤ ury)', function () {
    foreach ([0, 90, 180, 270] as $rotation) {
        [$llx, $lly, $urx, $ury] = FieldGeometry::toPdfRect(offsetPage($rotation), 0.05, 0.7, 0.4, 0.2);

        expect($llx)->toBeLessThanOrEqual($urx);
        expect($lly)->toBeLessThanOrEqual($ury);
    }
});

test('o campo inteiro cabe dentro do CropBox em qualquer rotação', function () {
    foreach ([0, 90, 180, 270] as $rotation) {
        $page = offsetPage($rotation);
        [$llx, $lly, $urx, $ury] = FieldGeometry::toPdfRect($page, 0.0, 0.0, 1.0, 1.0);

        expect(round($llx, 4))->toBe(10.0);
        expect(round($lly, 4))->toBe(20.0);
        expect(round($urx, 4))->toBe(210.0);
        expect(round($ury, 4))->toBe(320.0);
    }
});

test('rotações fora do padrão são normalizadas para 0/90/180/270', function () {
    expect(PageBox::normalizeRotation(-90))->toBe(270);
    expect(PageBox::normalizeRotation(450))->toBe(90);
    expect(PageBox::normalizeRotation(89))->toBe(90);
    expect(PageBox::normalizeRotation('abc'))->toBe(0);
    expect(PageBox::normalizeRotation(null))->toBe(0);
});

test('o CropBox é recortado pelo MediaBox e o retângulo invertido é reordenado', function () {
    $page = PageBox::fromPageMeta([
        'mediabox' => [0, 0, 100, 100],
        'cropbox' => [200, 200, 50, 50], // invertido e maior que o MediaBox
        'rotation' => 0,
    ]);

    expect($page->x0)->toBe(50.0);
    expect($page->y0)->toBe(50.0);
    expect($page->x1)->toBe(100.0);
    expect($page->y1)->toBe(100.0);
});

test('sem boxes, a caixa é reconstruída a partir das dimensões exibidas desfazendo a rotação', function () {
    $page = PageBox::fromPageMeta(['width_pt' => 842.0, 'height_pt' => 595.0, 'rotation' => 90]);

    // width_pt/height_pt já vêm exibidos: a caixa não rotacionada é 595 × 842.
    expect($page->width())->toBe(595.0);
    expect($page->height())->toBe(842.0);
    expect($page->displayedWidth())->toBe(842.0);
});

test('mediabox como caixa de referência ignora o CropBox', function () {
    $page = PageBox::fromPageMeta([
        'mediabox' => [0, 0, 220, 340],
        'cropbox' => [10, 20, 210, 320],
        'rotation' => 0,
    ], FieldBoxType::MediaBox);

    expect($page->width())->toBe(220.0);
    expect($page->boxType)->toBe(FieldBoxType::MediaBox);
});

test('normalize arredonda para 6 casas sem estourar x+w ≤ 1', function () {
    $geometry = FieldGeometry::normalize(0.9999999, 0.5, 0.5, 0.4);

    expect($geometry['x'] + $geometry['width'])->toBeLessThanOrEqual(1.0);
    expect($geometry['y'] + $geometry['height'])->toBeLessThanOrEqual(1.0);

    $rounded = FieldGeometry::normalize(0.1234567891, 0.2, 0.3, 0.1);
    expect($rounded['x'])->toBe(0.123457);
});

test('valores negativos e fora da página são recortados por normalize', function () {
    $geometry = FieldGeometry::normalize(-0.5, -0.2, 2.0, 3.0);

    expect($geometry)->toBe(['x' => 0.0, 'y' => 0.0, 'width' => 1.0, 'height' => 1.0]);
});

test('validate rejeita campo fora dos limites e aceita campo dentro', function () {
    $page = offsetPage(0);

    expect(FieldGeometry::validate(FieldType::Signature, 0.8, 0.1, 0.3, 0.1, $page))
        ->toHaveKey('width');

    expect(FieldGeometry::validate(FieldType::Signature, 0.1, 0.95, 0.3, 0.1, $page))
        ->toHaveKey('height');

    expect(FieldGeometry::validate(FieldType::Signature, -0.1, 0.1, 0.3, 0.1, $page))
        ->toHaveKey('x');

    expect(FieldGeometry::validate(FieldType::Signature, 0.1, 0.1, 0.3, 0.1, $page))->toBe([]);
});

test('validate exige o tamanho mínimo em pontos por tipo', function () {
    $page = offsetPage(0); // 200 × 300 pt

    // Assinatura: mínimo 56 × 20 pt = 0,28 × 0,0667 nesta página.
    expect(FieldGeometry::validate(FieldType::Signature, 0.1, 0.1, 0.2, 0.1, $page))->toHaveKey('width');
    expect(FieldGeometry::validate(FieldType::Signature, 0.1, 0.1, 0.3, 0.02, $page))->toHaveKey('height');
    expect(FieldGeometry::validate(FieldType::Signature, 0.1, 0.1, 0.3, 0.07, $page))->toBe([]);

    // Caixa de seleção: 8 × 8 pt cabe em uma fração bem menor.
    expect(FieldGeometry::validate(FieldType::Checkbox, 0.1, 0.1, 0.05, 0.03, $page))->toBe([]);
});

test('a rubrica automática cabe na página com folga', function () {
    $auto = FieldGeometry::AUTO_INITIALS;

    expect($auto['x'] + $auto['width'])->toBeLessThanOrEqual(1.0);
    expect($auto['y'] + $auto['height'])->toBeLessThanOrEqual(1.0);
    expect(FieldGeometry::validate(
        FieldType::Initials,
        $auto['x'],
        $auto['y'],
        $auto['width'],
        $auto['height'],
        PageBox::fromPageMeta(['width_pt' => 595.276, 'height_pt' => 841.89, 'rotation' => 0]),
    ))->toBe([]);
});

test('canvas ↔ normalizado é ida e volta', function () {
    $normalized = FieldGeometry::fromCanvas(120.0, 300.0, 240.0, 60.0, 800.0, 1000.0);

    expect($normalized)->toBe(['x' => 0.15, 'y' => 0.3, 'width' => 0.3, 'height' => 0.06]);

    $canvas = FieldGeometry::toCanvas(0.15, 0.3, 0.3, 0.06, 800.0, 1000.0);

    expect($canvas)->toBe(['left' => 120.0, 'top' => 300.0, 'width' => 240.0, 'height' => 60.0]);
});
