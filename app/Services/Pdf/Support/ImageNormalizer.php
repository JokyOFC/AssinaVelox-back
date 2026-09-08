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
 *   quando a decodificação não caberia em memory_limit (estimativa de
 *   ~8 bytes/pixel; com 512M processa qualquer imagem dentro dos limites);
 * - reduz o lado maior a 4000 px;
 * - aplica a orientação EXIF de JPEGs e reencoda a imagem do zero, o que remove
 *   todos os metadados (EXIF/ICC/XMP/chunks de texto);
 * - mantém no máximo duas imagens GD vivas ao mesmo tempo (origem é liberada
 *   antes de codificar; sem redução, a origem é reencodada diretamente);
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

        $this->assertFitsInMemory($sourceWidth, $sourceHeight, $maxSide);

        $outputFormat = $format === 'jpeg' ? 'jpeg' : 'png';
        $outputPath = rtrim($outputDirectory, '/\\').DIRECTORY_SEPARATOR.'normalized.'.($outputFormat === 'jpeg' ? 'jpg' : 'png');
        $hasAlpha = $preserveAlpha && $format !== 'jpeg';

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

            // Sem redução e sem composição sobre branco (JPEG, ou PNG mantendo o
            // alfa), a própria imagem decodificada é reencodada: evita uma segunda
            // cópia em memória. A reencodificação do zero já descarta os metadados.
            $needsCanvas = $scale < 1.0 || ! ($format === 'jpeg' || $hasAlpha);

            if ($needsCanvas) {
                $canvas = $this->resample($image, $targetWidth, $targetHeight, $hasAlpha);
                // Libera a origem antes de codificar: o pico de memória passa a ser
                // origem + destino OU destino + buffer de codificação, nunca os três.
                imagedestroy($image);
                $image = $canvas;
            } elseif ($hasAlpha) {
                imagesavealpha($image, true);
            }

            $this->encode($image, $outputFormat, $outputPath);
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

    /**
     * Cópia redimensionada (bicúbica) sobre fundo branco ou transparente.
     *
     * @param  positive-int  $targetWidth
     * @param  positive-int  $targetHeight
     */
    private function resample(GdImage $image, int $targetWidth, int $targetHeight, bool $hasAlpha): GdImage
    {
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            throw new ImageRejectedException('invalid_image', 'Não foi possível alocar a imagem normalizada.');
        }

        try {
            if ($hasAlpha) {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
                imagefilledrectangle($canvas, 0, 0, $targetWidth - 1, $targetHeight - 1, $transparent === false ? 0x7FFFFFFF : $transparent);
                imagealphablending($canvas, true);
            } else {
                $white = imagecolorallocate($canvas, 255, 255, 255);
                // imagefilledrectangle, não imagefill: o flood fill aloca uma pilha
                // proporcional à área e estoura memory_limit em imagens grandes.
                imagefilledrectangle($canvas, 0, 0, $targetWidth - 1, $targetHeight - 1, $white === false ? 0xFFFFFF : $white);
                imagealphablending($canvas, true);
            }

            if (! imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($image), imagesy($image))) {
                throw new ImageRejectedException('invalid_image', 'Não foi possível redimensionar a imagem.');
            }

            if ($hasAlpha) {
                imagesavealpha($canvas, true);
            }
        } catch (ImageRejectedException $exception) {
            imagedestroy($canvas);

            throw $exception;
        }

        return $canvas;
    }

    private function encode(GdImage $image, string $outputFormat, string $outputPath): void
    {
        $written = $outputFormat === 'jpeg'
            ? imagejpeg($image, $outputPath, 90)
            : imagepng($image, $outputPath, 6);

        if (! $written || ! is_file($outputPath)) {
            throw new ImageRejectedException('invalid_image', 'Não foi possível gravar a imagem normalizada.');
        }
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
            default => false,
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
     * Estimativa do pico de memória do GD (que conta para memory_limit):
     * decodificação ≈ 8 bytes/pixel da origem (buffer da libpng/libjpeg + imagem
     * truecolor) e, depois de liberar a origem, ≈ 8 bytes/pixel do destino
     * (imagem reduzida + linhas do codificador). Recusa antes de estourar.
     */
    private function assertFitsInMemory(int $width, int $height, int $maxSide): void
    {
        $limit = $this->memoryLimitBytes();
        if ($limit === null) {
            return;
        }

        $sourcePixels = $width * $height;
        $targetPixels = min($sourcePixels, $maxSide * $maxSide);
        $needed = max($sourcePixels * 8, $sourcePixels * 4 + $targetPixels * 8) + 4 * 1024 * 1024;
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
