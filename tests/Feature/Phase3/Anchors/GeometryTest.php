<?php

use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Models\Recipient;
use App\Services\Anchors\AnchorMatch;
use App\Services\Anchors\AnchorPlacement;
use App\Services\Anchors\AnchorQuery;
use App\Services\Anchors\SuggestionBuilder;
use App\Services\Anchors\SuggestionGeometry;
use App\Services\Envelopes\FieldGeometry;
use App\Services\Envelopes\PageBox;

/*
| Funções puras da área: posicionamento da sugestão, correspondência papel → participante
| (espelho do `marker_key_slug` do pdftool) e a conferência defensiva da saída do pdftool (T6).
*/

$a4 = fn (int $rotation = 0): PageBox => PageBox::fromPageMeta([
    'width_pt' => 595.276, 'height_pt' => 841.89, 'rotation' => $rotation,
    'mediabox' => [0, 0, 595.276, 841.89], 'cropbox' => [0, 0, 595.276, 841.89],
]);

test('marcador: o campo cobre o marcador e fica centrado na linha', function () use ($a4) {
    $anchor = ['x' => 0.2, 'y' => 0.5, 'width' => 0.3, 'height' => 0.015];

    $g = SuggestionGeometry::place($a4(), $anchor, FieldType::Signature, AnchorPlacement::Over, coverAnchorWidth: true);

    expect($g['x'])->toEqualWithDelta(0.2, 1e-6)
        ->and($g['width'])->toBeGreaterThanOrEqual(0.3)
        ->and($g['y'] + $g['height'] / 2)->toEqualWithDelta(0.5075, 0.002)
        ->and(FieldGeometry::validate(FieldType::Signature, $g['x'], $g['y'], $g['width'], $g['height'], $a4()))->toBe([]);
});

test('regra: abaixo, à direita, acima, com deslocamento e tamanho em pontos', function () use ($a4) {
    $page = $a4();
    $anchor = ['x' => 0.1, 'y' => 0.4, 'width' => 0.2, 'height' => 0.02];

    $below = SuggestionGeometry::place($page, $anchor, FieldType::Signature, AnchorPlacement::Below, 10, 5, 200, 50);
    expect($below['x'] * 595.276)->toEqualWithDelta(0.1 * 595.276 + 10, 0.01)
        ->and($below['y'] * 841.89)->toEqualWithDelta((0.42 * 841.89) + 2 + 5, 0.01)
        ->and($below['width'] * 595.276)->toEqualWithDelta(200, 0.01)
        ->and($below['height'] * 841.89)->toEqualWithDelta(50, 0.01);

    $right = SuggestionGeometry::place($page, $anchor, FieldType::Date, AnchorPlacement::Right);
    expect($right['x'])->toBeGreaterThan(0.3);

    $above = SuggestionGeometry::place($page, $anchor, FieldType::Initials, AnchorPlacement::Above);
    expect($above['y'] + $above['height'])->toBeLessThan(0.4);
});

test('o campo é deslocado para dentro da página em vez de sair dela', function () use ($a4) {
    $g = SuggestionGeometry::place($a4(), ['x' => 0.9, 'y' => 0.98, 'width' => 0.09, 'height' => 0.01], FieldType::Signature, AnchorPlacement::Below);

    expect($g['x'] + $g['width'])->toBeLessThanOrEqual(1.0 + 1e-9)
        ->and($g['y'] + $g['height'])->toBeLessThanOrEqual(1.0 + 1e-9);
});

test('página menor que o campo: a sugestão é limitada à página (o mínimo de FieldGeometry também)', function () {
    $tiny = PageBox::fromPageMeta(['width_pt' => 30, 'height_pt' => 10, 'rotation' => 0]);

    // FieldGeometry limita o mínimo do tipo ao tamanho da página; a sugestão nunca sai dela.
    expect(SuggestionGeometry::place($tiny, ['x' => 0.5, 'y' => 0.5, 'width' => 0.1, 'height' => 0.1], FieldType::Signature, AnchorPlacement::Over))
        ->toBe(['x' => 0.0, 'y' => 0.0, 'width' => 1.0, 'height' => 1.0]);

    // Página degenerada (sem dimensão): nada.
    expect(SuggestionGeometry::place(PageBox::fromPageMeta(['width_pt' => 0, 'height_pt' => 0]), ['x' => 0, 'y' => 0, 'width' => 0.1, 'height' => 0.1], FieldType::Date, AnchorPlacement::Over))->toBeNull();
});

test('página rotacionada usa as dimensões EXIBIDAS', function () use ($a4) {
    $g = SuggestionGeometry::place($a4(90), ['x' => 0.1, 'y' => 0.1, 'width' => 0.1, 'height' => 0.02], FieldType::Signature, AnchorPlacement::Below, widthPt: 150, heightPt: 40);

    // Exibida 841.89 × 595.276: 150 pt de largura = 150/841.89 da largura.
    expect($g['width'])->toEqualWithDelta(150 / 841.89, 1e-5)
        ->and($g['height'])->toEqualWithDelta(40 / 595.276, 1e-5);
});

test('sobreposição (IoU)', function () {
    $box = ['x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.2];

    expect(SuggestionGeometry::overlap($box, $box))->toEqualWithDelta(1.0, 1e-9)
        ->and(SuggestionGeometry::overlap($box, ['x' => 0.5, 'y' => 0.5, 'width' => 0.1, 'height' => 0.1]))->toBe(0.0)
        ->and(SuggestionGeometry::overlap($box, ['x' => 0.2, 'y' => 0.1, 'width' => 0.2, 'height' => 0.2]))->toEqualWithDelta(1 / 3, 1e-9);
});

test('slug do papel é o mesmo do pdftool', function () {
    expect(SuggestionBuilder::slug('Locatário'))->toBe('locatario')
        ->and(SuggestionBuilder::slug('Testemunha 1'))->toBe('testemunha_1')
        ->and(SuggestionBuilder::slug('  Sr. João  da Silva '))->toBe('sr_joao_da_silva')
        ->and(SuggestionBuilder::slug('<b>x</b>'))->toBe('bxb');
});

test('papel → participante: rótulo, nome, posição e compatibilidade', function () {
    $signer = new Recipient(['name' => 'Ana Souza', 'role_label' => 'Locatário']);
    $signer->role = RecipientRole::Signer;
    $signer->ulid = '01HAAAAAAAAAAAAAAAAAAAAAAA';
    $approver = new Recipient(['name' => 'Bruno Lima', 'role_label' => 'Gerente']);
    $approver->role = RecipientRole::Approver;
    $approver->ulid = '01HBBBBBBBBBBBBBBBBBBBBBBB';
    $eligible = collect([$signer, $approver]);

    expect(SuggestionBuilder::resolveRecipient('locatario', $eligible, FieldType::Signature))->toBe($signer)
        ->and(SuggestionBuilder::resolveRecipient('ana', $eligible, FieldType::Signature))->toBe($signer)
        ->and(SuggestionBuilder::resolveRecipient('1', $eligible, FieldType::Signature))->toBe($signer)
        ->and(SuggestionBuilder::resolveRecipient('signatario_1', $eligible, FieldType::Initials))->toBe($signer)
        // Aprovador não recebe assinatura: sem correspondência, o remetente escolhe.
        ->and(SuggestionBuilder::resolveRecipient('gerente', $eligible, FieldType::Signature))->toBeNull()
        ->and(SuggestionBuilder::resolveRecipient('gerente', $eligible, FieldType::Date))->toBe($approver)
        ->and(SuggestionBuilder::resolveRecipient('ninguem', $eligible, FieldType::Signature))->toBeNull()
        ->and(SuggestionBuilder::resolveRecipient('9', $eligible, FieldType::Date))->toBeNull();
});

test('a saída do pdftool é conferida e entradas fora do contrato são descartadas (T6)', function () {
    $ok = ['page' => 1, 'source' => 'text', 'kind' => 'marker', 'field_type' => 'signature', 'key' => 'comprador',
        'box' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.02], 'line_box' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.02]];

    expect(AnchorMatch::tryFromArray($ok, 1))->not->toBeNull()
        ->and(AnchorMatch::tryFromArray([...$ok, 'key' => '<script>'], 1))->toBeNull()
        ->and(AnchorMatch::tryFromArray([...$ok, 'field_type' => 'stamp'], 1))->toBeNull()
        ->and(AnchorMatch::tryFromArray([...$ok, 'page' => 2], 1))->toBeNull()
        ->and(AnchorMatch::tryFromArray([...$ok, 'box' => ['x' => 0.9, 'y' => 0.1, 'width' => 0.2, 'height' => 0.02]], 1))->toBeNull()
        ->and(AnchorMatch::tryFromArray([...$ok, 'kind' => 'literal', 'literal_id' => 'r 1'], 1))->toBeNull()
        ->and(AnchorMatch::tryFromArray([...$ok, 'source' => 'shell'], 1))->toBeNull();
});

test('literal: normalização e limites', function () {
    expect(AnchorQuery::normalizeLiteral("  Assinatura\t do\n locatário "))->toBe('Assinatura do locatário')
        ->and(AnchorQuery::acceptableLiteral('ab'))->toBeTrue()
        ->and(AnchorQuery::acceptableLiteral('a'))->toBeFalse()
        ->and(AnchorQuery::acceptableLiteral('...'))->toBeFalse()
        ->and(AnchorQuery::acceptableLiteral(str_repeat('x', 121)))->toBeFalse()
        ->and(AnchorQuery::acceptableLiteral('.*(a|b)+'))->toBeTrue();

    $query = new AnchorQuery(true, [AnchorQuery::literal('m1', '.*', 'signature')], 50);
    // Só id e texto vão para o pdftool — nunca participante, nunca expressão.
    expect($query->toSpec())->toBe(['markers' => true, 'literals' => [['id' => 'm1', 'text' => '.*']], 'max_matches' => 50]);
});
