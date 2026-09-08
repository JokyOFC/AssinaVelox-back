<?php

namespace Tests\Feature\Pdf\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fixtures geradas em tempo de teste (dompdf para PDFs, GD para imagens) e
 * utilitários de área de trabalho para os testes de tests/Feature/Pdf.
 */
final class PdfFixtures
{
    public static function pythonBinary(): string
    {
        $configured = (string) config('pdftool.python', '');
        if ($configured !== '') {
            return $configured;
        }

        return base_path(PHP_OS_FAMILY === 'Windows' ? 'tools/pdftool/.venv/Scripts/python.exe' : 'tools/pdftool/.venv/bin/python');
    }

    /**
     * venv do pdftool presente (senão os testes são pulados com mensagem clara).
     */
    public static function available(): bool
    {
        return is_file(self::pythonBinary()) && is_file(base_path('tools/pdftool/pdftool/__main__.py'));
    }

    public static function skipMessage(): string
    {
        return sprintf(
            'pdftool indisponível: %s não encontrado. Crie o venv: cd tools/pdftool && python -m venv .venv && %s -m pip install -r requirements.lock.txt',
            self::pythonBinary(),
            PHP_OS_FAMILY === 'Windows' ? '.venv/Scripts/python.exe' : '.venv/bin/python',
        );
    }

    /**
     * Diretório de trabalho exclusivo do teste (removido em afterEach).
     */
    public static function workspace(): string
    {
        $path = storage_path('app/tmp/tests/'.(string) Str::ulid());
        if (! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException("Não foi possível criar {$path}");
        }

        return $path;
    }

    public static function cleanup(?string $path): void
    {
        if ($path !== null && is_dir($path)) {
            (new Filesystem)->deleteDirectory($path);
        }
    }

    public static function onePagePdf(string $path, string $title = 'Contrato de teste'): string
    {
        return self::writePdf($path, self::html([$title]));
    }

    public static function twoPagePdf(string $path): string
    {
        return self::writePdf($path, self::html(['Página 1 — Contrato de teste', 'Página 2 — Anexo']));
    }

    /**
     * PDF cifrado (RC4 via CPDF). Senha de usuário vazia => abre sem senha, mas
     * `encrypted: true`; com senha de usuário => o pdftool recusa (encrypted_pdf).
     */
    public static function encryptedPdf(string $path, string $userPassword = '', string $ownerPassword = 'owner-secret'): string
    {
        $pdf = Pdf::loadHTML(self::html(['Documento protegido']))->setPaper('a4');
        $pdf->setEncryption($userPassword, $ownerPassword);
        file_put_contents($path, $pdf->output());

        return $path;
    }

    public static function corruptedPdf(string $path): string
    {
        file_put_contents($path, "%PDF-1.7\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nlixo lixo lixo\n");

        return $path;
    }

    /**
     * PNG transparente com um traço azul (assinatura desenhada).
     */
    public static function signaturePng(string $path, int $width = 400, int $height = 160): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, (int) $transparent);
        imagealphablending($image, true);
        $blue = imagecolorallocate($image, 0, 0, 200);
        imagesetthickness($image, 6);
        imageline($image, 10, $height - 20, intdiv($width, 3), 20, (int) $blue);
        imageline($image, intdiv($width, 3), 20, intdiv(2 * $width, 3), $height - 20, (int) $blue);
        imageline($image, intdiv(2 * $width, 3), $height - 20, $width - 10, 20, (int) $blue);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * PNG opaco grande (para testar a redução a 4000 px).
     */
    public static function largePng(string $path, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        // imagefilledrectangle em vez de imagefill: o flood fill estoura memory_limit em imagens grandes.
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) $white);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, intdiv($width, 4), intdiv($height, 4), intdiv(3 * $width, 4), intdiv(3 * $height, 4), (int) $black);
        imagepng($image, $path, 1);
        imagedestroy($image);

        return $path;
    }

    public static function jpeg(string $path, int $width = 800, int $height = 600): string
    {
        $image = imagecreatetruecolor($width, $height);
        $grey = imagecolorallocate($image, 230, 230, 230);
        imagefill($image, 0, 0, (int) $grey);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagestring($image, 5, 40, 40, 'Foto de documento', (int) $black);
        imagejpeg($image, $path, 85);
        imagedestroy($image);

        return $path;
    }

    public static function svg(string $path): string
    {
        file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><script>alert(1)</script><rect width="100" height="100" fill="red"/></svg>');

        return $path;
    }

    /**
     * PNG sintático com IHDR declarando $width x $height e sem dados de imagem:
     * suficiente para getimagesize() ler as dimensões sem decodificar pixels.
     */
    public static function pngHeaderOnly(string $path, int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height)."\x08\x02\x00\x00\x00";
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        };

        file_put_contents($path, "\x89PNG\r\n\x1a\n".$chunk('IHDR', $ihdr).$chunk('IEND', ''));

        return $path;
    }

    public static function fakeSoffice(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return base_path('tests/Fixtures/fake-soffice.bat');
        }

        $script = base_path('tests/Fixtures/fake-soffice.sh');
        @chmod($script, 0755);

        return $script;
    }

    public static function fakeConvertedPdf(): string
    {
        return base_path('tests/Fixtures/fake-converted.pdf');
    }

    /**
     * @param  list<string>  $pageTitles
     */
    private static function html(array $pageTitles): string
    {
        $pages = [];
        foreach ($pageTitles as $index => $title) {
            $break = $index < count($pageTitles) - 1 ? ' style="page-break-after: always"' : '';
            $pages[] = sprintf(
                '<div%s><h1>%s</h1><p>Texto de exemplo com acentuação: ação, coração, ñ. Gerado em teste automatizado.</p></div>',
                $break,
                htmlspecialchars($title, ENT_QUOTES),
            );
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans, sans-serif;font-size:12pt}</style></head><body>'
            .implode('', $pages)
            .'</body></html>';
    }

    private static function writePdf(string $path, string $html): string
    {
        $pdf = Pdf::loadHTML($html)->setPaper('a4');
        file_put_contents($path, $pdf->output());

        return $path;
    }
}
