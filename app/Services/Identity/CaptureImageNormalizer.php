<?php

namespace App\Services\Identity;

use App\Services\Identity\Exceptions\CaptureRejectedException;
use App\Services\Pdf\Support\JpegOrientation;
use GdImage;

/**
 * Normalização da foto da captura simples com GD (docs/fase-2/identidade.md §5.3).
 *
 * A imagem vem de um navegador anônimo: nunca é guardada como chegou. Na ordem:
 *
 * 1. tamanho em bytes contra `assinavelox.capture.max_upload_kb`;
 * 2. tipo REAL por `finfo` sobre os bytes — só JPEG e PNG. SVG (XML, pode ter script e
 *    referência externa) e qualquer outra coisa são recusados;
 * 3. largura × altura lidas do CABEÇALHO e comparadas com `max_source_pixels` antes de
 *    decodificar um pixel (bomba de descompressão), e estimativa de memória;
 * 4. decodificação, orientação EXIF aplicada aos pixels (JPEG de celular), redução para
 *    `output_max_side` e **reencode do zero** como JPEG sobre fundo branco. É o reencode que
 *    apaga EXIF (inclusive GPS), ICC, XMP e chunks de texto: o arquivo final só tem pixels.
 *
 * É captura simples. Não faz (e não deve fazer) detecção de rosto nem comparação facial.
 * Não faz prova de vida nem leitura (OCR) do documento.
 */
final class CaptureImageNormalizer
{
    /** @var array<string, string> MIME real => formato */
    private const SUPPORTED = [
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
    ];

    /**
     * @return array{bytes: string, width: int, height: int, sha256: string, mime: string}
     *
     * @throws CaptureRejectedException
     */
    public function normalize(string $raw): array
    {
        $maxBytes = max(64, (int) config('assinavelox.capture.max_upload_kb', 8192)) * 1024;

        if ($raw === '') {
            throw new CaptureRejectedException('empty_image', 'Nenhuma imagem foi recebida. Tire a foto novamente.');
        }

        if (strlen($raw) > $maxBytes) {
            throw new CaptureRejectedException('image_too_heavy', sprintf('A foto é maior que %d MB. Tire a foto novamente ou envie uma imagem menor.', max(1, intdiv($maxBytes, 1024 * 1024))));
        }

        $mime = $this->detectMime($raw);

        if (! isset(self::SUPPORTED[$mime])) {
            throw new CaptureRejectedException(
                'unsupported_image',
                str_contains($mime, 'svg') || str_contains($mime, 'xml')
                    ? 'Imagens SVG não são aceitas. Envie uma foto em JPEG ou PNG.'
                    : 'O arquivo enviado não é uma foto em JPEG ou PNG.',
            );
        }

        $size = @getimagesizefromstring($raw);

        if ($size === false || (int) $size[0] <= 0 || (int) $size[1] <= 0) {
            throw new CaptureRejectedException('invalid_image', 'A foto está corrompida ou não pôde ser lida.');
        }

        [$width, $height] = [(int) $size[0], (int) $size[1]];
        $maxPixels = max(10_000, (int) config('assinavelox.capture.max_source_pixels', 30_000_000));

        if ($width * $height > $maxPixels) {
            throw new CaptureRejectedException(
                'image_too_large',
                sprintf('A foto tem %d×%d pixels; o limite é %d megapixels.', $width, $height, max(1, intdiv($maxPixels, 1_000_000))),
            );
        }

        $this->assertFitsInMemory($width, $height);

        if (! extension_loaded('gd')) {
            throw new CaptureRejectedException('gd_unavailable', 'Não foi possível processar a foto nesta instalação.', 503);
        }

        $image = @imagecreatefromstring($raw);

        if ($image === false) {
            throw new CaptureRejectedException('invalid_image', 'A foto está corrompida ou não pôde ser lida.');
        }

        try {
            if (self::SUPPORTED[$mime] === 'jpeg') {
                $image = JpegOrientation::apply($image, $this->orientation($raw));
            }

            $canvas = $this->fit($image);
        } finally {
            imagedestroy($image);
        }

        $bytes = $this->encode($canvas);

        return [
            'bytes' => $bytes['bytes'],
            'width' => $bytes['width'],
            'height' => $bytes['height'],
            'sha256' => hash('sha256', $bytes['bytes']),
            'mime' => 'image/jpeg',
        ];
    }

    /**
     * Miniatura JPEG para a página de evidências do remetente (nunca para a página pública).
     */
    public function thumbnail(string $jpeg, int $maxSide): ?string
    {
        $image = @imagecreatefromstring($jpeg);

        if ($image === false) {
            return null;
        }

        try {
            $canvas = $this->fit($image, max(32, $maxSide));
        } finally {
            imagedestroy($image);
        }

        return $this->encode($canvas, 70)['bytes'];
    }

    private function fit(GdImage $source, ?int $maxSide = null): GdImage
    {
        $maxSide ??= max(200, (int) config('assinavelox.capture.output_max_side', 1600));
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, $maxSide / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($canvas === false) {
            throw new CaptureRejectedException('invalid_image', 'Não foi possível processar a foto.');
        }

        // Transparência (PNG) composta sobre branco: o JPEG final não tem canal alfa.
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth - 1, $targetHeight - 1, $white === false ? 0xFFFFFF : $white);
        imagealphablending($canvas, true);

        if (! imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
            imagedestroy($canvas);

            throw new CaptureRejectedException('invalid_image', 'Não foi possível redimensionar a foto.');
        }

        return $canvas;
    }

    /**
     * @return array{bytes: string, width: int, height: int}
     */
    private function encode(GdImage $image, ?int $quality = null): array
    {
        $quality ??= min(95, max(50, (int) config('assinavelox.capture.jpeg_quality', 85)));
        $width = imagesx($image);
        $height = imagesy($image);

        try {
            ob_start();
            imagejpeg($image, null, $quality);
            $bytes = (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        if ($bytes === '') {
            throw new CaptureRejectedException('encode_failed', 'Não foi possível processar a foto.');
        }

        return ['bytes' => $bytes, 'width' => $width, 'height' => $height];
    }

    /**
     * Orientação EXIF (1–8). EXIF malformado ou ausente vale 1: a orientação é conforto de
     * exibição, não motivo para recusar a foto.
     */
    private function orientation(string $raw): int
    {
        $path = tempnam(sys_get_temp_dir(), 'avcap');

        if ($path === false) {
            return 1;
        }

        try {
            file_put_contents($path, $raw);

            return JpegOrientation::read($path);
        } catch (\Throwable) {
            return 1;
        } finally {
            @unlink($path);
        }
    }

    private function detectMime(string $raw): string
    {
        if (! extension_loaded('fileinfo')) {
            throw new CaptureRejectedException('fileinfo_unavailable', 'Não foi possível verificar o tipo da foto nesta instalação.', 503);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);

        return is_string($mime) && $mime !== '' ? strtolower($mime) : 'application/octet-stream';
    }

    /**
     * Decodificar precisa de ~5 bytes por pixel na origem mais a tela de saída. Recusa com
     * mensagem clara em vez de estourar `memory_limit` (erro fatal, sem resposta).
     */
    private function assertFitsInMemory(int $width, int $height): void
    {
        $limit = self::memoryLimitBytes();

        if ($limit <= 0) {
            return;
        }

        $maxSide = max(200, (int) config('assinavelox.capture.output_max_side', 1600));
        $needed = ($width * $height * 5) + ($maxSide * $maxSide * 5) + (16 * 1024 * 1024);

        if (memory_get_usage(true) + $needed > $limit) {
            throw new CaptureRejectedException('image_too_large', 'A foto é grande demais para ser processada. Envie uma imagem com resolução menor.');
        }
    }

    private static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
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
