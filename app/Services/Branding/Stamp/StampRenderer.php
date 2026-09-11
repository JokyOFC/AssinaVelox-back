<?php

namespace App\Services\Branding\Stamp;

use App\Services\Branding\ColorContrast;
use GdImage;
use RuntimeException;

/**
 * Desenha o carimbo visual da organização (logo + nome) como PNG com GD.
 *
 * É uma **representação visual**: não carrega hash, código, data nem qualquer elemento
 * que pareça selo de autenticidade. A imagem tem fundo transparente e uma moldura fina na
 * cor de destaque; o nome sai na cor primária (contraste já validado contra branco).
 *
 * Fonte: DejaVu Sans Bold, a mesma que acompanha o dompdf (dependência de execução do
 * projeto). Sem FreeType, cai na fonte bitmap do GD — feio, mas legível e sem rede.
 */
final class StampRenderer
{
    public const WIDTH = 900;

    public const HEIGHT = 300;

    private const PADDING = 24;

    /**
     * @param  string|null  $logoPng  PNG já normalizado pelo LogoProcessor
     */
    public function render(string $displayName, ?string $logoPng, string $primaryHex, string $accentHex): string
    {
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        if ($canvas === false) {
            throw new RuntimeException('Não foi possível alocar a imagem do carimbo.');
        }

        try {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = (int) imagecolorallocatealpha($canvas, 255, 255, 255, 127);
            imagefilledrectangle($canvas, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $transparent);
            imagealphablending($canvas, true);

            $accent = $this->color($canvas, $accentHex);
            $primary = $this->color($canvas, $primaryHex);

            imagesetthickness($canvas, 6);
            imagerectangle($canvas, 3, 3, self::WIDTH - 4, self::HEIGHT - 4, $accent);
            imagesetthickness($canvas, 1);

            $textLeft = self::PADDING * 2;

            if ($logoPng !== null && $logoPng !== '') {
                $textLeft = $this->drawLogo($canvas, $logoPng) + self::PADDING * 2;
            }

            $this->drawName($canvas, $displayName, $textLeft, $primary);

            ob_start();
            imagepng($canvas, null, 9);
            $bytes = (string) ob_get_clean();
        } finally {
            imagedestroy($canvas);
        }

        if ($bytes === '') {
            throw new RuntimeException('Não foi possível gravar a imagem do carimbo.');
        }

        return $bytes;
    }

    /**
     * Desenha o logo à esquerda, dentro de um quadrado de (altura − 2·padding). Devolve a
     * coordenada x da borda direita do logo.
     */
    private function drawLogo(GdImage $canvas, string $logoPng): int
    {
        $logo = @imagecreatefromstring($logoPng);

        if ($logo === false) {
            return self::PADDING;
        }

        try {
            $box = self::HEIGHT - self::PADDING * 2;
            $maxWidth = (int) (self::WIDTH * 0.42);
            $width = imagesx($logo);
            $height = imagesy($logo);
            $scale = min($maxWidth / $width, $box / $height);
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $x = self::PADDING * 2;
            $y = (int) round((self::HEIGHT - $targetHeight) / 2);

            imagecopyresampled($canvas, $logo, $x, $y, 0, 0, $targetWidth, $targetHeight, $width, $height);

            return $x + $targetWidth;
        } finally {
            imagedestroy($logo);
        }
    }

    private function drawName(GdImage $canvas, string $name, int $left, int $color): void
    {
        $name = trim($name) !== '' ? trim($name) : '—';
        $available = self::WIDTH - $left - self::PADDING * 2;
        $font = self::fontPath();

        if ($font === null || ! function_exists('imagettftext')) {
            $line = mb_substr($name, 0, max(4, intdiv($available, imagefontwidth(5))));
            imagestring($canvas, 5, $left, (int) (self::HEIGHT / 2 - imagefontheight(5) / 2), $line, $color);

            return;
        }

        // Maior tamanho (até 64 pt) em que o nome cabe em até duas linhas.
        for ($size = 64; $size >= 18; $size -= 2) {
            $lines = $this->wrap($name, $font, $size, $available);

            if (count($lines) <= 2) {
                break;
            }
        }

        $lines = array_slice($lines, 0, 2);
        $lineHeight = (int) round($size * 1.35);
        $blockHeight = $lineHeight * count($lines);
        $baseline = (int) round((self::HEIGHT - $blockHeight) / 2 + $size * 1.05);

        foreach ($lines as $index => $line) {
            imagettftext($canvas, $size, 0, $left, $baseline + $index * $lineHeight, $color, $font, $line);
        }
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, string $font, int $size, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($this->textWidth($candidate, $font, $size) <= $maxWidth) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            // Palavra sozinha maior que a linha: corta por caractere.
            while ($this->textWidth($word, $font, $size) > $maxWidth && mb_strlen($word) > 1) {
                $cut = mb_strlen($word);

                while ($cut > 1 && $this->textWidth(mb_substr($word, 0, $cut), $font, $size) > $maxWidth) {
                    $cut--;
                }

                $lines[] = mb_substr($word, 0, $cut);
                $word = mb_substr($word, $cut);
            }

            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function textWidth(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box === false ? PHP_INT_MAX : abs($box[2] - $box[0]);
    }

    private function color(GdImage $canvas, string $hex): int
    {
        [$r, $g, $b] = ColorContrast::rgb($hex);

        // Canais de 0 a 255 por construção (dois dígitos hexadecimais); o limite deixa isso
        // explícito para o GD.
        return (int) imagecolorallocate(
            $canvas,
            max(0, min(255, $r)),
            max(0, min(255, $g)),
            max(0, min(255, $b)),
        );
    }

    public static function fontPath(): ?string
    {
        $candidates = [
            (string) config('assinavelox.branding.stamp_font', ''),
            base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf'),
        ];

        foreach ($candidates as $path) {
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
