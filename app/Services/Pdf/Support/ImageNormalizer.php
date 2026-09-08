<?php

namespace App\Services\Pdf\Support;

use App\Services\Pdf\Exceptions\ImageRejectedException;
use GdImage;

/**
 * Normalização de imagens com GD, ANTES de qualquer processamento pelo pdftool:
 *
 * - aceita apenas PNG, JPEG e WEBP (MIME real via finfo; SVG e demais formatos
 *   são recusados — SVG pode conter script/referências externas);
 * - recusa acima de 40 megapixels (lido do cabeçalho, antes de decodificar) e
 *   quando a decodificação não caberia em memory_limit;
 * - reduz o lado maior a 4000 px;
 * - aplica a orientação EXIF de JPEGs e reencoda a imagem do zero, o que remove
 *   todos os metadados (EXIF/ICC/XMP/chunks de texto);
 * - por padrão compõe transparência sobre branco (documentos); com
 *   $preserveAlpha=true mantém o canal alfa em PNG (imagens de assinatura).
 */
final class ImageNormalizer
{
    public const MAX_SIDE_PX = 4000;

    public const MAX_PIXELS = 40_000_000;

    /** @var array<string, string> MIME real => formato */
    public const SUPPORTED = [
        'image/png' => 'png',
        'image/jpeg' => 'jpeg',
        'image/webp' => 'webp',
    ];

    /**
     * @return array{path: string, format: string, width: int, height: int, source_width: int, source_height: int, source_mime: string}
     */
    public function normalize(
        string $inputPath,
        string $outputDirectory,
        int $maxSide = self::MAX_SIDE_PX,
        int $maxPixels = self::MAX_PIXELS,
        bool $preserveAlpha = false,
    ): array {
        if (! is_file($inputPath)) {
            throw new ImageRejectedException('missing_input', 'Arquivo de imagem não encontrado.');
        }

        $mime = $this->detectMime($inputPath);
        if (! isset(self::SUPPORTED[$mime])) {
            throw new ImageRejectedException(
                'unsupported_image',
                sprintf('Formato de imagem não suportado (%s). Use PNG, JPEG ou WEBP.', $mime),
            );
        }
        $format = self::SUPPORTED[$mime];

        $size = @getimagesize($inputPath);
        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            throw new ImageRejectedException('invalid_image', 'A imagem está corrompida ou não pôde ser lida.');
        }
        [$sourceWidth, $sourceHeight] = [(int) $size[0], (int) $size[1]];

        if ($sourceWidth * $sourceHeight > $maxPixels) {
            throw new ImageRejectedException(
                'image_too_large',
                sprintf('A imagem tem %d megapixels; o limite é %d.', intdiv($sourceWidth * $sourceHeight, 1_000_000), intdiv($maxPixels, 1_000_000)),
            );
        }

        $this->assertFitsInMemory($sourceWidth, $sourceHeight);

        $image = $this->decode($inputPath, $format);

        try {
            if ($format === 'jpeg') {
                $image = JpegOrientation::apply($image, JpegOrientation::read($inputPath));
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1.0, $maxSide / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));

            $hasAlpha = $preserveAlpha && $format !== 'jpeg';
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            if ($canvas === false) {
                throw new ImageRejectedException('invalid_image', 'Não foi possível alocar a imagem normalizada.');
            }

            try {
                if ($hasAlpha) {
                    imagealphablending($canvas, false);
                    imagesavealpha($canvas, true);
                    $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
                    imagefill($canvas, 0, 0, $transparent === false ? 0 : $transparent);
                    imagealphablending($canvas, true);
                } else {
                    $white = imagecolorallocate($canvas, 255, 255, 255);
                    imagefill($canvas, 0, 0, $white === false ? 0xFFFFFF : $white);
                    imagealphablending($canvas, true);
                }

                imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

                $outputFormat = $format === 'jpeg' ? 'jpeg' : 'png';
                $outputPath = rtrim($outputDirectory, '/\\').DIRECTORY_SEPARATOR.'normalized.'.($outputFormat === 'jpeg' ? 'jpg' : 'png');

                if ($hasAlpha) {
                    imagesavealpha($canvas, true);
                }

                $written = $outputFormat === 'jpeg'
                    ? imagejpeg($canvas, $outputPath, 90)
                    : imagepng($canvas, $outputPath, 6);

                if (! $written || ! is_file($outputPath)) {
                    throw new ImageRejectedException('invalid_image', 'Não foi possível gravar a imagem normalizada.');
                }
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            imagedestroy($image);
        }

        return [
            'path' => $outputPath,
            'format' => $outputFormat,
            'width' => $targetWidth,
            'height' => $targetHeight,
            'source_width' => $sourceWidth,
            'source_height' => $sourceHeight,
            'source_mime' => $mime,
        ];
    }

    public function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo === false ? false : finfo_file($finfo, $path);
        if ($finfo !== false) {
            finfo_close($finfo);
        }

        return is_string($mime) && $mime !== '' ? strtolower($mime) : 'application/octet-stream';
    }

    private function decode(string $path, string $format): GdImage
    {
        $image = match ($format) {
            'png' => @imagecreatefrompng($path),
            'jpeg' => @imagecreatefromjpeg($path),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        };

        if ($image === false) {
            throw new ImageRejectedException('invalid_image', 'A imagem está corrompida ou o formato não é suportado pelo GD.');
        }

        // Paleta/PNG indexado: converte para truecolor para que o resampling e a
        // composição de alfa funcionem de forma previsível.
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    /**
     * GD decodifica em truecolor (~5 bytes/pixel com alfa, mais a cópia
     * reduzida). Recusa antes de estourar memory_limit.
     */
    private function assertFitsInMemory(int $width, int $height): void
    {
        $limit = $this->memoryLimitBytes();
        if ($limit === null) {
            return;
        }

        $needed = $width * $height * 5 + intdiv($width * $height * 5, 2);
        $available = $limit - memory_get_usage(true);

        if ($needed > $available) {
            throw new ImageRejectedException(
                'image_too_large',
                'A imagem é grande demais para ser processada com a memória disponível.',
            );
        }
    }

    private function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
