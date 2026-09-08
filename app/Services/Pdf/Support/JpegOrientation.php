<?php

namespace App\Services\Pdf\Support;

use GdImage;

/**
 * Lê a orientação EXIF (tag 0x0112) de um JPEG e a aplica com GD.
 *
 * A extensão `exif` pode não estar habilitada (não está no ambiente local);
 * este leitor mínimo percorre os segmentos APP1 do JPEG sem depender dela. Só
 * o campo de orientação é lido; nenhum outro metadado é preservado.
 */
final class JpegOrientation
{
    /**
     * Orientação EXIF (1..8); 1 quando ausente/ilegível.
     */
    public static function read(string $path): int
    {
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($path, 'IFD0', true);
            if (is_array($exif) && isset($exif['IFD0']['Orientation'])) {
                $orientation = (int) $exif['IFD0']['Orientation'];

                return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
            }
        }

        return self::parse($path);
    }

    /**
     * Devolve uma nova imagem com a orientação aplicada (ou a própria, se 1).
     */
    public static function apply(GdImage $image, int $orientation): GdImage
    {
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 3:
                return self::rotate($image, 180);
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);

                return $image;
            case 5:
                $rotated = self::rotate($image, 90);
                imageflip($rotated, IMG_FLIP_VERTICAL);

                return $rotated;
            case 6:
                return self::rotate($image, -90);
            case 7:
                $rotated = self::rotate($image, -90);
                imageflip($rotated, IMG_FLIP_VERTICAL);

                return $rotated;
            case 8:
                return self::rotate($image, 90);
            default:
                return $image;
        }
    }

    private static function rotate(GdImage $image, float $degrees): GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);
        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private static function parse(string $path): int
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return 1;
        }

        try {
            $header = fread($handle, 2);
            if ($header !== "\xFF\xD8") {
                return 1;
            }

            // Percorre os segmentos até SOS (FFDA); APP1 com "Exif\0\0" traz o TIFF.
            for ($segment = 0; $segment < 64; $segment++) {
                $marker = fread($handle, 2);
                if ($marker === false || strlen($marker) < 2 || $marker[0] !== "\xFF") {
                    return 1;
                }
                $type = ord($marker[1]);
                if ($type === 0xDA || $type === 0xD9) {
                    return 1;
                }
                $lengthBytes = fread($handle, 2);
                if ($lengthBytes === false || strlen($lengthBytes) < 2) {
                    return 1;
                }
                $length = (ord($lengthBytes[0]) << 8 | ord($lengthBytes[1])) - 2;
                if ($length < 0) {
                    return 1;
                }
                if ($type !== 0xE1) {
                    fseek($handle, $length, SEEK_CUR);

                    continue;
                }
                $data = $length > 0 ? fread($handle, min($length, 65535)) : '';
                if (! is_string($data) || ! str_starts_with($data, "Exif\0\0")) {
                    continue;
                }

                return self::orientationFromTiff(substr($data, 6));
            }
        } finally {
            fclose($handle);
        }

        return 1;
    }

    private static function orientationFromTiff(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }

        $order = substr($tiff, 0, 2);
        if ($order === 'II') {
            $format16 = 'v';
            $format32 = 'V';
        } elseif ($order === 'MM') {
            $format16 = 'n';
            $format32 = 'N';
        } else {
            return 1;
        }

        $read16 = static function (string $data, int $offset) use ($format16): ?int {
            if ($offset + 2 > strlen($data)) {
                return null;
            }
            $value = unpack($format16, substr($data, $offset, 2));

            return $value === false ? null : (int) $value[1];
        };
        $read32 = static function (string $data, int $offset) use ($format32): ?int {
            if ($offset + 4 > strlen($data)) {
                return null;
            }
            $value = unpack($format32, substr($data, $offset, 4));

            return $value === false ? null : (int) $value[1];
        };

        if ($read16($tiff, 2) !== 42) {
            return 1;
        }

        $ifdOffset = $read32($tiff, 4);
        if ($ifdOffset === null || $ifdOffset < 8) {
            return 1;
        }

        $entries = $read16($tiff, $ifdOffset);
        if ($entries === null) {
            return 1;
        }

        for ($i = 0; $i < min($entries, 256); $i++) {
            $entry = $ifdOffset + 2 + $i * 12;
            $tag = $read16($tiff, $entry);
            if ($tag === null) {
                return 1;
            }
            if ($tag === 0x0112) {
                $orientation = $read16($tiff, $entry + 8);

                return $orientation !== null && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
            }
        }

        return 1;
    }
}
