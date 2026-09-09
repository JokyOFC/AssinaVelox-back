<?php

namespace App\Services\Envelopes\Finalization\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use RuntimeException;

/**
 * QR code da página de evidências, gerado com `bacon/bacon-qr-code` e rasterizado com GD.
 *
 * Por que GD e não os renderizadores da própria biblioteca: o `ImagickImageBackEnd` exige a
 * extensão Imagick (não instalada) e o `SvgImageBackEnd` produz SVG, que o DOMPDF só desenha
 * por um interpretador próprio e limitado. Um PNG em `data:` URI é o formato que o DOMPDF
 * embute sem surpresa e sem acesso a arquivo ou rede.
 *
 * O conteúdo codificado é apenas a **URL pública de verificação** — nunca nome, e-mail, IP,
 * hash ou qualquer token.
 */
final class QrCode
{
    /**
     * Zona de silêncio mínima exigida pela ISO/IEC 18004, em módulos.
     */
    public const MIN_QUIET_ZONE = 4;

    /**
     * PNG do QR code em `data:` URI, pronto para `<img src="...">` no Blade.
     */
    public static function dataUri(string $content, int $modulePx = 4, int $quietZone = self::MIN_QUIET_ZONE): string
    {
        return 'data:image/png;base64,'.base64_encode(self::png($content, $modulePx, $quietZone));
    }

    /**
     * Bytes do PNG.
     */
    public static function png(string $content, int $modulePx = 4, int $quietZone = self::MIN_QUIET_ZONE): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('A extensão GD é necessária para gerar o QR code da página de evidências.');
        }

        $modulePx = max(1, min(16, $modulePx));
        // A norma é o piso, não o padrão: uma configuração menor é elevada, nunca aceita.
        $quietZone = max(self::MIN_QUIET_ZONE, min(8, $quietZone));

        $matrix = Encoder::encode($content, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();

        $modules = $matrix->getWidth();
        $side = max(1, ($modules + 2 * $quietZone) * $modulePx);

        $image = imagecreatetruecolor($side, $side);

        try {
            $white = (int) imagecolorallocate($image, 255, 255, 255);
            $black = (int) imagecolorallocate($image, 0, 0, 0);

            imagefilledrectangle($image, 0, 0, $side - 1, $side - 1, $white);

            for ($y = 0; $y < $matrix->getHeight(); $y++) {
                for ($x = 0; $x < $modules; $x++) {
                    if ($matrix->get($x, $y) !== 1) {
                        continue;
                    }

                    $left = ($x + $quietZone) * $modulePx;
                    $top = ($y + $quietZone) * $modulePx;

                    imagefilledrectangle($image, $left, $top, $left + $modulePx - 1, $top + $modulePx - 1, $black);
                }
            }

            ob_start();
            imagepng($image, null, 9);
            $bytes = (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        if ($bytes === '') {
            throw new RuntimeException('Não foi possível gerar o PNG do QR code.');
        }

        return $bytes;
    }
}
