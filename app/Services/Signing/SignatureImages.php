<?php

namespace App\Services\Signing;

use App\Services\Signing\Exceptions\SigningRejectedException;
use GdImage;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Normalização da **representação visual** da assinatura (arquitetura §2 e §4.5).
 *
 * Uma imagem que chega de um navegador anônimo é a entrada mais hostil de todo o produto:
 * ela é escolhida pelo remetente do ataque, entra sem autenticação de conta e sai gravada em
 * um arquivo que depois é embutido em um PDF. Por isso ela **nunca** é armazenada como veio.
 *
 * O que este serviço faz, na ordem:
 *
 * 1. Aceita apenas base64 (com ou sem prefixo `data:`), rejeitando qualquer coisa que não
 *    decodifique — sem `strict` o PHP silenciosamente ignora lixo no meio do base64.
 * 2. Confere o tamanho **decodificado** antes de olhar o conteúdo.
 * 3. Descobre o tipo real com `finfo` sobre os bytes. Só PNG, JPEG e WEBP passam.
 *    **SVG é recusado**: é XML, pode conter `<script>`, `xlink:href` externo e entidades —
 *    não existe "SVG seguro" que valha o risco em um documento assinado.
 * 4. Lê largura e altura do **cabeçalho** (`getimagesizefromstring`) e recusa acima do limite
 *    antes de decodificar um único pixel — é assim que uma "PNG gigante" de poucos KB
 *    (bomba de descompressão) morre sem consumir memória.
 * 5. Decodifica com GD, redimensiona para caber em 1200×400 e **reencoda do zero** como PNG.
 *    Reencodar é o que apaga EXIF, ICC, XMP, chunks `tEXt`/`zTXt` e qualquer payload
 *    escondido: o arquivo final tem apenas os pixels.
 * 6. Grava no disco privado `documents`, em caminho feito só de identificadores opacos.
 *
 * O que ele **não** faz: não corrige perspectiva, não remove fundo, não faz OCR. A imagem é
 * uma representação visual e não prova nada sozinha — quem prova é o aceite.
 */
final class SignatureImages
{
    /** MIME real => decodificador GD. */
    private const SUPPORTED = [
        'image/png' => 'png',
        'image/jpeg' => 'jpeg',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly Repository $config) {}

    /**
     * Normaliza e grava. Devolve o caminho no disco `documents`.
     *
     * @throws SigningRejectedException
     */
    public function store(string $base64, SignerContext $context, string $kind = 'signature'): string
    {
        $png = $this->normalize($base64);

        $path = sprintf(
            '%s/%s/envelopes/%s/signatures/%s-%s.png',
            trim((string) $this->config->get('assinavelox.upload.path_prefix', 'orgs'), '/'),
            $context->organization->ulid,
            $context->envelope->ulid,
            preg_replace('/[^a-z]/', '', $kind) ?: 'signature',
            (string) Str::ulid(),
        );

        Storage::disk('documents')->put($path, $png);

        return $path;
    }

    /**
     * Apaga uma imagem recém-gravada que acabou não pertencendo a nenhum aceite.
     *
     * Chamado quando a revalidação sob lock recusa o aceite (envelope cancelado, prazo
     * vencido, encerrado pela recusa de outro participante entre a renderização da tela e
     * o clique). Sem isso o PNG ficava no disco para sempre: nenhuma linha em
     * `signature_acceptances` ou `signing_field_values` apontava para ele, nada o
     * removia, e sobrava a imagem manuscrita da assinatura de uma pessoa referente a um
     * aceite que juridicamente não aconteceu.
     */
    public function discard(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk('documents')->delete($path);
    }

    /**
     * Decodifica, valida e devolve os **bytes** de um PNG normalizado.
     *
     * @throws SigningRejectedException
     */
    public function normalize(string $base64): string
    {
        $raw = $this->decode($base64);

        $mime = $this->detectMime($raw);

        if (! isset(self::SUPPORTED[$mime])) {
            throw new SigningRejectedException(
                'unsupported_signature_image',
                $mime === 'image/svg+xml' || str_contains($mime, 'xml')
                    ? 'Imagens SVG não são aceitas como assinatura. Envie um PNG ou JPEG.'
                    : sprintf('Formato de imagem não aceito (%s). Envie um PNG ou JPEG.', $mime),
            );
        }

        $size = @getimagesizefromstring($raw);

        if ($size === false || (int) $size[0] <= 0 || (int) $size[1] <= 0) {
            throw new SigningRejectedException('invalid_signature_image', 'A imagem da assinatura está corrompida ou não pôde ser lida.');
        }

        [$width, $height] = [(int) $size[0], (int) $size[1]];
        $maxPixels = $this->maxSourcePixels();

        if ($width * $height > $maxPixels) {
            throw new SigningRejectedException(
                'signature_image_too_large',
                sprintf(
                    'A imagem da assinatura tem %d×%d pixels; o limite é %d megapixels.',
                    $width,
                    $height,
                    max(1, intdiv($maxPixels, 1_000_000)),
                ),
            );
        }

        if (! extension_loaded('gd')) {
            throw new SigningRejectedException(
                'gd_unavailable',
                'Não foi possível processar a imagem da assinatura nesta instalação. Use a assinatura desenhada ou digitada.',
            );
        }

        $source = @imagecreatefromstring($raw);

        if ($source === false) {
            throw new SigningRejectedException('invalid_signature_image', 'A imagem da assinatura está corrompida ou não pôde ser lida.');
        }

        try {
            return $this->encode($this->fit($source));
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * Redimensiona para caber na caixa de saída preservando proporção e canal alfa.
     * Imagens menores que a caixa não são ampliadas: esticar assinatura só perde nitidez.
     */
    private function fit(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);

        $maxWidth = max(1, (int) $this->config->get('assinavelox.signing_session.signature_image.output_max_width', 1200));
        $maxHeight = max(1, (int) $this->config->get('assinavelox.signing_session.signature_image.output_max_height', 400));

        $scale = min(1.0, $maxWidth / $width, $maxHeight / $height);

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        imagealphablending($target, false);
        imagesavealpha($target, true);

        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);

        if ($transparent !== false) {
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $target;
    }

    private function encode(GdImage $image): string
    {
        $bytes = '';

        try {
            imagesavealpha($image, true);

            ob_start();
            imagepng($image, null, 6);
            $bytes = (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        if ($bytes === '') {
            throw new SigningRejectedException('signature_image_encode_failed', 'Não foi possível processar a imagem da assinatura.');
        }

        return $bytes;
    }

    /**
     * @throws SigningRejectedException
     */
    private function decode(string $base64): string
    {
        $payload = trim($base64);

        if ($payload === '') {
            throw new SigningRejectedException('empty_signature_image', 'A assinatura não foi capturada. Desenhe, digite ou envie uma imagem.');
        }

        // "data:image/png;base64,AAAA" — o tipo declarado aqui é ignorado de propósito:
        // quem decide o formato é o finfo sobre os bytes.
        if (str_starts_with($payload, 'data:')) {
            $comma = strpos($payload, ',');

            if ($comma === false) {
                throw new SigningRejectedException('invalid_signature_image', 'A imagem da assinatura não pôde ser lida.');
            }

            $payload = substr($payload, $comma + 1);
        }

        $payload = preg_replace('/\s+/', '', $payload) ?? '';

        $maxDecoded = $this->maxDecodedBytes();

        // Base64 cresce 4/3: com o tamanho da string já dá para recusar antes de decodificar.
        if (strlen($payload) > (int) ceil($maxDecoded * 4 / 3) + 16) {
            throw new SigningRejectedException(
                'signature_image_too_heavy',
                sprintf('A imagem da assinatura é maior que %d KB.', intdiv($maxDecoded, 1024)),
            );
        }

        $raw = base64_decode($payload, true);

        if ($raw === false || $raw === '') {
            throw new SigningRejectedException('invalid_signature_image', 'A imagem da assinatura não pôde ser lida.');
        }

        if (strlen($raw) > $maxDecoded) {
            throw new SigningRejectedException(
                'signature_image_too_heavy',
                sprintf('A imagem da assinatura é maior que %d KB.', intdiv($maxDecoded, 1024)),
            );
        }

        return $raw;
    }

    private function detectMime(string $raw): string
    {
        if (! extension_loaded('fileinfo')) {
            // Sem finfo não há como afirmar o tipo real; afirmar por cabeçalho seria adivinhar.
            throw new SigningRejectedException(
                'fileinfo_unavailable',
                'Não foi possível verificar o tipo da imagem nesta instalação.',
            );
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($raw);

        return is_string($mime) && $mime !== '' ? strtolower($mime) : 'application/octet-stream';
    }

    private function maxDecodedBytes(): int
    {
        return max(1024, (int) $this->config->get('assinavelox.signing_session.signature_image.max_decoded_kb', 3072) * 1024);
    }

    private function maxSourcePixels(): int
    {
        return max(10_000, (int) $this->config->get('assinavelox.signing_session.signature_image.max_source_pixels', 8_000_000));
    }
}
