<?php

namespace App\Services\Branding;

use App\Services\Branding\Exceptions\LogoRejectedException;
use App\Services\Pdf\Support\JpegOrientation;
use GdImage;

/**
 * Normaliza o logo da organização com GD.
 *
 * Regras (na ordem em que são conferidas, todas ANTES de decodificar a imagem):
 *
 * 1. tamanho do arquivo ≤ {@see BrandingLimits::logoMaxKb()};
 * 2. MIME real lido do conteúdo (finfo), nunca o nome nem o MIME do navegador;
 *    **SVG é recusado** (pode carregar script e referências externas) — também quando
 *    chega disfarçado com extensão `.png`; qualquer outro formato que não PNG/JPEG é
 *    recusado;
 * 3. dimensões lidas do cabeçalho: lado ≤ {@see BrandingLimits::logoMaxSourceSide()},
 *    pixels ≤ {@see BrandingLimits::logoMaxSourcePixels()} (bomba de descompressão),
 *    lado ≥ {@see BrandingLimits::logoMinSide()}.
 *
 * Depois: decodifica, aplica a orientação EXIF do JPEG, reduz para caber em
 * {@see BrandingLimits::logoOutputWidth()} × {@see BrandingLimits::logoOutputHeight()}
 * e **reencoda do zero como PNG** com canal alfa. A reencodificação descarta todos os
 * metadados (EXIF, XMP, ICC, chunks de texto). O arquivo guardado é sempre esse PNG.
 */
final class LogoProcessor
{
    /**
     * @return array{bytes: string, width: int, height: int, sha256: string}
     *
     * @throws LogoRejectedException
     */
    public function process(string $path): array
    {
        if (! is_file($path)) {
            throw new LogoRejectedException('missing_file', 'Envie um arquivo de imagem.');
        }

        $size = (int) filesize($path);
        $maxKb = BrandingLimits::logoMaxKb();

        if ($size <= 0) {
            throw new LogoRejectedException('empty_file', 'O arquivo enviado está vazio.');
        }

        if ($size > $maxKb * 1024) {
            throw new LogoRejectedException('file_too_large', sprintf('O logo pode ter no máximo %s KB.', number_format($maxKb, 0, ',', '.')));
        }

        $mime = $this->detectMime($path);

        if ($this->looksLikeSvg($path, $mime)) {
            throw new LogoRejectedException('svg_not_allowed', 'SVG não é aceito. Envie o logo em PNG ou JPEG.');
        }

        if (! str_starts_with($mime, 'image/')) {
            throw new LogoRejectedException('not_an_image', 'O arquivo enviado não é uma imagem. Envie o logo em PNG ou JPEG.');
        }

        if (! in_array($mime, BrandingLimits::LOGO_MIMES, true)) {
            throw new LogoRejectedException('unsupported_format', 'Formato não aceito. Envie o logo em PNG ou JPEG.');
        }

        $info = @getimagesize($path);

        if ($info === false || $info[0] <= 0 || $info[1] <= 0) {
            throw new LogoRejectedException('invalid_image', 'A imagem está corrompida ou não pôde ser lida.');
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];
        $maxSide = BrandingLimits::logoMaxSourceSide();

        if ($width > $maxSide || $height > $maxSide || $width * $height > BrandingLimits::logoMaxSourcePixels()) {
            throw new LogoRejectedException(
                'image_too_large',
                sprintf('A imagem tem %d×%d px; o limite é %d px no maior lado.', $width, $height, $maxSide),
            );
        }

        $minSide = BrandingLimits::logoMinSide();

        if ($width < $minSide || $height < $minSide) {
            throw new LogoRejectedException('image_too_small', sprintf('A imagem precisa ter pelo menos %d px de cada lado.', $minSide));
        }

        $image = $this->decode($path, $mime);

        try {
            if ($mime === 'image/jpeg') {
                $image = JpegOrientation::apply($image, JpegOrientation::read($path));
            }

            $image = $this->fit($image);
            $bytes = $this->encode($image);
            $outWidth = imagesx($image);
            $outHeight = imagesy($image);
        } finally {
            imagedestroy($image);
        }

        return [
            'bytes' => $bytes,
            'width' => $outWidth,
            'height' => $outHeight,
            'sha256' => hash('sha256', $bytes),
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

    /**
     * SVG é XML: o finfo pode devolver `image/svg+xml`, `text/xml`, `text/html` ou até
     * `text/plain`. Por isso também se olha o começo do arquivo.
     */
    private function looksLikeSvg(string $path, string $mime): bool
    {
        if (str_contains($mime, 'svg')) {
            return true;
        }

        if (! str_starts_with($mime, 'text/') && $mime !== 'application/xml') {
            return false;
        }

        $head = (string) file_get_contents($path, false, null, 0, 2048);

        return preg_match('/<svg[\s>]/i', $head) === 1;
    }

    private function decode(string $path, string $mime): GdImage
    {
        $image = match ($mime) {
            'image/png' => @imagecreatefrompng($path),
            'image/jpeg' => @imagecreatefromjpeg($path),
            default => false,
        };

        if ($image === false) {
            throw new LogoRejectedException('invalid_image', 'A imagem está corrompida ou não pôde ser lida.');
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    /**
     * Reduz (nunca amplia) para caber na caixa de saída, sobre fundo transparente.
     * Sempre desenha numa tela nova: é isso que garante um PNG "limpo".
     */
    private function fit(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, BrandingLimits::logoOutputWidth() / $width, BrandingLimits::logoOutputHeight() / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($canvas === false) {
            throw new LogoRejectedException('invalid_image', 'Não foi possível processar a imagem.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefilledrectangle($canvas, 0, 0, $targetWidth - 1, $targetHeight - 1, $transparent === false ? 0x7FFFFFFF : $transparent);
        imagealphablending($canvas, true);

        if (! imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
            imagedestroy($canvas);

            throw new LogoRejectedException('invalid_image', 'Não foi possível processar a imagem.');
        }

        imagedestroy($image);
        imagesavealpha($canvas, true);

        return $canvas;
    }

    private function encode(GdImage $image): string
    {
        ob_start();
        $ok = imagepng($image, null, 9);
        $bytes = (string) ob_get_clean();

        if (! $ok || $bytes === '') {
            throw new LogoRejectedException('invalid_image', 'Não foi possível gravar a imagem normalizada.');
        }

        return $bytes;
    }
}
