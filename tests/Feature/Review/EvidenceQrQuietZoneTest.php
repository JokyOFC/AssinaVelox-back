<?php

use App\Services\Envelopes\Finalization\Support\QrCode;

/*
|--------------------------------------------------------------------------
| Revisão de design — a margem do QR fica abaixo do mínimo da norma
|--------------------------------------------------------------------------
| O QR da página de evidências é o único caminho "aponte a câmera e confira"
| que o produto oferece a quem recebe o PDF em papel ou em outra tela
| (ROUTES §4.1; blade `evidence/page.blade.php`, célula `.qr`).
|
| A ISO/IEC 18004 exige uma zona de silêncio (margem branca) de **4 módulos** em
| volta do símbolo. `config/assinavelox.php` configura `quiet_zone => 2` e
| `QrCode::png()` usa o mesmo 2 como padrão — metade do mínimo. Medido no PDF de
| evidências gerado de verdade nesta revisão (envelope AV-00006): PNG de 148×148
| para uma matriz de 33 módulos a 4 px, ou seja 8 px de margem = 2 módulos.
|
| No PDF o símbolo é desenhado a 28 mm dentro de uma célula de 30 mm, então a
| margem impressa fica em ~1,5 mm — é a zona de silêncio embutida no PNG que
| precisa carregar a norma, e ela não carrega. Leitores tolerantes decodificam;
| câmeras em ângulo, impressões em baixo contraste e o texto do cabeçalho
| encostado à esquerda do símbolo são exatamente os casos em que 2 módulos
| falham.
|
| O teste mede a margem no PNG que a plataforma realmente gera, com a
| configuração que ela realmente publica.
*/

it('gera o QR com a zona de silêncio de 4 módulos exigida pela ISO/IEC 18004', function () {
    if (! function_exists('imagecreatefromstring')) {
        $this->markTestSkipped('GD indisponível.');
    }

    $modulePx = (int) config('assinavelox.evidence.qr.module_px', 4);
    $quietZone = (int) config('assinavelox.evidence.qr.quiet_zone', 2);

    $png = QrCode::png('https://assinavelox.test/verificar/ABCD-EFGH-JKLM', $modulePx, $quietZone);
    $image = imagecreatefromstring($png);
    expect($image)->not->toBeFalse();

    $side = imagesx($image);

    // Primeira coluna com pixel escuro, a partir da borda esquerda.
    $marginPx = $side;
    for ($x = 0; $x < $side; $x++) {
        for ($y = 0; $y < $side; $y++) {
            $rgb = imagecolorat($image, $x, $y);
            if ((($rgb >> 16) & 0xFF) < 128) {
                $marginPx = $x;
                break 2;
            }
        }
    }

    imagedestroy($image);

    $marginModules = $modulePx > 0 ? $marginPx / $modulePx : 0;

    expect($marginModules)->toBeGreaterThanOrEqual(
        4,
        sprintf('Zona de silêncio de %.1f módulos (%d px a %d px/módulo); a norma exige 4.', $marginModules, $marginPx, $modulePx),
    );
});
