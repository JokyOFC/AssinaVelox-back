<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da Fase 2 — identidade (C-ID)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Models\AuditEvent;
use App\Models\Organization;
use Illuminate\Http\UploadedFile;

if (! function_exists('identityEnableFlags')) {
    /**
     * Liga as flags de identidade: interruptor global E plano vigente da organização.
     *
     * @param  list<string>  $flags
     */
    function identityEnableFlags(?Organization $organization, array $flags): void
    {
        foreach ($flags as $flag) {
            config()->set('assinavelox.features.'.$flag, true);
        }

        if ($organization === null) {
            return;
        }

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);

        foreach ($flags as $flag) {
            $features[$flag] = true;
        }

        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('identityJpegWithGps')) {
    /**
     * JPEG real (GD) com um segmento APP1 "Exif" contendo marcadores de GPS e de câmera logo
     * depois do SOI — o que um celular grava numa foto.
     */
    function identityJpegWithGps(int $width = 640, int $height = 480): string
    {
        $image = imagecreatetruecolor($width, $height);
        $sky = imagecolorallocate($image, 90, 140, 200);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) $sky);
        $face = imagecolorallocate($image, 230, 190, 160);
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width / 3), (int) ($height / 2), (int) $face);

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        // TIFF big-endian, IFD0 vazio; o texto depois dele é o "vazamento" que o reencode
        // precisa apagar.
        $payload = "Exif\0\0"."MM\0\x2A\0\0\0\x08"."\0\0"."\0\0\0\0"
            .'GPSLatitude=-23.5505;GPSLongitude=-46.6333;Make=CameraDeTeste;Model=Modelo-X';
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }
}

if (! function_exists('identityPng')) {
    function identityPng(int $width = 400, int $height = 300): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        $ink = imagecolorallocate($image, 20, 40, 60);
        imagefilledrectangle($image, 10, 10, $width - 10, $height - 10, (int) $ink);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}

if (! function_exists('identityGiantPng')) {
    /**
     * PNG de poucos bytes cujo cabeçalho declara 40000×40000 pixels (bomba de descompressão):
     * precisa morrer na leitura do cabeçalho, antes de qualquer decodificação.
     */
    function identityGiantPng(int $side = 40000): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1A\n"
            .$chunk('IHDR', pack('NNCCCCC', $side, $side, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress(str_repeat("\0", 64)))
            .$chunk('IEND', '');
    }
}

if (! function_exists('identitySvg')) {
    function identitySvg(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            .'<script>alert(1)</script><rect width="10" height="10"/></svg>';
    }
}

if (! function_exists('identityUpload')) {
    function identityUpload(string $bytes, string $name = 'foto.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }
}

if (! function_exists('identityAccept')) {
    /**
     * POST do aceite com assinatura desenhada.
     *
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $fields
     */
    function identityAccept(object $test, string $token, array $props, array $fields = []): mixed
    {
        return $test->post(route('sign.complete', ['token' => $token]), [
            'authorization' => $props['authorization']['token'],
            'consent' => true,
            'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
            'fields' => $fields,
        ]);
    }
}

if (! function_exists('identityPostCapture')) {
    function identityPostCapture(object $test, string $token, string $kind, string $bytes, string $name = 'foto.jpg'): mixed
    {
        return $test->post(
            route('sign.capture.store', ['token' => $token, 'kind' => $kind]),
            ['image' => identityUpload($bytes, $name), 'source' => 'camera'],
            ['Accept' => 'application/json'],
        );
    }
}

if (! function_exists('identityAuditPayloads')) {
    /**
     * Todos os payloads da trilha, serializados, para procurar vazamento de dado.
     */
    function identityAuditPayloads(): string
    {
        return (string) json_encode(AuditEvent::query()->withoutGlobalScopes()->get()->map(fn (AuditEvent $event): array => [
            'type' => $event->event_type->value,
            'payload' => $event->payload,
        ])->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
