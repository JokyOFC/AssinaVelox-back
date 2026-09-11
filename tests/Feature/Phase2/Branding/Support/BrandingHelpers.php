<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes de marca, carimbo visual e Reply-To (C-BRAND)
|--------------------------------------------------------------------------
| Incluído com require_once. Não contém testes.
*/

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Org/Support/OrgHelpers.php';

if (! function_exists('brandingEnable')) {
    /**
     * Liga (ou desliga) a flag `branding`: interruptor global E plano vigente.
     * Obs.: nos testes as organizações dividem o plano Grátis, então ligar para uma liga
     * para todas — o que torna os testes de isolamento mais exigentes, não menos.
     */
    function brandingEnable(Organization $organization, bool $enabled = true): void
    {
        orgEnableTools($organization, ['branding'], $enabled);
    }
}

if (! function_exists('brandingOrganization')) {
    /**
     * Organização com owner, flag ligada e sessão autenticada.
     *
     * @return array{organization: Organization, owner: User}
     */
    function brandingOrganization(string $name = 'Imobiliária Aurora', bool $enabled = true, bool $actAs = true): array
    {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => $name]);

        if ($enabled) {
            brandingEnable($organization);
        }

        if ($actAs) {
            actingAsMember($owner, $organization);
        }

        return ['organization' => $organization, 'owner' => $owner];
    }
}

if (! function_exists('brandingPng')) {
    /** PNG real (GD) com um retângulo colorido e fundo transparente. */
    function brandingPng(int $width = 400, int $height = 200): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagefilledrectangle($image, (int) ($width * 0.1), (int) ($height * 0.1), (int) ($width * 0.9), (int) ($height * 0.9), (int) imagecolorallocate($image, 18, 87, 201));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}

if (! function_exists('brandingPngWithText')) {
    /** PNG com um chunk `tEXt` (metadado) logo depois do IHDR. */
    function brandingPngWithText(string $keyword, string $text, int $width = 400, int $height = 200): string
    {
        $png = brandingPng($width, $height);
        $data = $keyword.chr(0).$text;
        $chunk = pack('N', strlen($data)).'tEXt'.$data.pack('N', crc32('tEXt'.$data));
        $offset = 8 + 25;

        return substr($png, 0, $offset).$chunk.substr($png, $offset);
    }
}

if (! function_exists('brandingJpegWithMetadata')) {
    /**
     * JPEG com um segmento APP1 "Exif" (TIFF mínimo, sem entradas) e um comentário COM
     * carregando `$marker` — os dois precisam sumir depois da normalização.
     */
    function brandingJpegWithMetadata(string $marker, int $width = 600, int $height = 300): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 200, 40, 40));

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        $tiff = 'II*'.chr(0).pack('V', 8).pack('v', 0).pack('V', 0);
        $exif = 'Exif'.chr(0).chr(0).$tiff.$marker;
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
        $com = "\xFF\xFE".pack('n', strlen($marker) + 2).$marker;

        // Logo depois do SOI (FFD8).
        return substr($jpeg, 0, 2).$app1.$com.substr($jpeg, 2);
    }
}

if (! function_exists('brandingUpload')) {
    function brandingUpload(string $bytes, string $name = 'logo.png', ?string $mime = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes)->mimeType($mime ?? 'image/png');
    }
}

if (! function_exists('brandingSvg')) {
    function brandingSvg(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="200" height="100"><script>alert(1)</script><rect width="200" height="100" fill="#1257c9"/></svg>';
    }
}
