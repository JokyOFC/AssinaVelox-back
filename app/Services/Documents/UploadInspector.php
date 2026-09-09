<?php

namespace App\Services\Documents;

use App\Enums\DocumentSourceType;
use App\Services\Documents\Dto\InspectedUpload;
use App\Services\Documents\Exceptions\UploadRejectedException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Validação de entrada do arquivo enviado — sobre o CONTEÚDO, nunca sobre a extensão
 * informada pelo navegador (docs/preparacao-documental.md).
 *
 * Ordem das verificações (a mais barata primeiro; nada é decodificado antes de passar
 * pelos limites):
 *
 *  1. upload concluído sem erro e arquivo não vazio;
 *  2. tamanho ≤ `assinavelox.upload.max_mb`;
 *  3. assinatura de bytes (magic number) → tipo real: PDF, ZIP(=DOCX), PNG, JPEG, WEBP.
 *     Qualquer outra coisa — SVG incluído, que pode carregar script e referências
 *     externas — é recusada aqui;
 *  4. `finfo` sobre o conteúdo precisa concordar com a assinatura;
 *  5. a extensão informada precisa combinar com o tipo real (um .pdf com bytes de PNG
 *     é recusado);
 *  6. por tipo: DOCX → inspeção do ZIP (entradas, total descompactado, razão de
 *     compressão, `word/document.xml`); imagem → dimensões e megapixels lidos do
 *     cabeçalho, sem decodificar os pixels.
 *
 * O nome exibido é sanitizado e devolvido em `displayName`; ele nunca vira caminho no
 * disco (o caminho é montado com ULIDs em DocumentIntake).
 */
class UploadInspector
{
    public function __construct(private readonly Repository $config) {}

    public function maxBytes(): int
    {
        return max(1, (int) $this->config->get('assinavelox.upload.max_mb', 25)) * 1024 * 1024;
    }

    public function maxMegabytes(): int
    {
        return max(1, (int) $this->config->get('assinavelox.upload.max_mb', 25));
    }

    /**
     * @throws UploadRejectedException
     */
    public function inspect(UploadedFile $file): InspectedUpload
    {
        if (! $file->isValid()) {
            throw UploadRejectedException::make(
                'upload_failed',
                'O envio do arquivo não foi concluído. Tente novamente.',
            );
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            throw UploadRejectedException::make(
                'upload_failed',
                'O envio do arquivo não foi concluído. Tente novamente.',
            );
        }

        $size = (int) filesize($path);

        if ($size <= 0) {
            throw UploadRejectedException::make('empty_file', 'O arquivo enviado está vazio.');
        }

        if ($size > $this->maxBytes()) {
            throw UploadRejectedException::make(
                'file_too_large',
                sprintf('O arquivo excede o limite de %d MB.', $this->maxMegabytes()),
                ['size_bytes' => $size, 'max_bytes' => $this->maxBytes()],
            );
        }

        $format = $this->detectFormat($path);
        $this->assertFinfoAgrees($path, $format);

        $extension = $this->normalizedExtension($file);
        $sourceType = self::SOURCE_TYPE_BY_FORMAT[$format];
        $this->assertExtensionMatches($extension, $sourceType);

        $details = match ($sourceType) {
            DocumentSourceType::Docx => $this->inspectDocx($path, $size),
            DocumentSourceType::Image => $this->inspectImage($path, $format),
            DocumentSourceType::Pdf => [],
        };

        $displayName = $this->sanitizeFilename((string) $file->getClientOriginalName(), $extension);

        return new InspectedUpload(
            sourceType: $sourceType,
            mimeType: self::MIME_BY_FORMAT[$format],
            extension: $extension === '' ? self::DEFAULT_EXTENSION_BY_FORMAT[$format] : $extension,
            sizeBytes: $size,
            displayName: $displayName,
            baseName: $this->baseName($displayName),
            details: $details + ['format' => $format],
        );
    }

    // -- Detecção de formato ----------------------------------------------------------

    private const SOURCE_TYPE_BY_FORMAT = [
        'pdf' => DocumentSourceType::Pdf,
        'docx' => DocumentSourceType::Docx,
        'png' => DocumentSourceType::Image,
        'jpeg' => DocumentSourceType::Image,
        'webp' => DocumentSourceType::Image,
    ];

    private const MIME_BY_FORMAT = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'png' => 'image/png',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    private const DEFAULT_EXTENSION_BY_FORMAT = [
        'pdf' => 'pdf',
        'docx' => 'docx',
        'png' => 'png',
        'jpeg' => 'jpg',
        'webp' => 'webp',
    ];

    /**
     * MIME aceitos por `finfo` para cada formato. A lista é tolerante com bases libmagic
     * antigas (que devolvem `application/zip` para OOXML), mas nunca aceita um MIME de
     * outro formato.
     *
     * @var array<string, list<string>>
     */
    private const FINFO_ALLOWED = [
        'pdf' => ['application/pdf'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ],
        'png' => ['image/png'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp', 'image/x-webp'],
    ];

    /**
     * @return 'pdf'|'docx'|'png'|'jpeg'|'webp'
     *
     * @throws UploadRejectedException
     */
    private function detectFormat(string $path): string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 1024);

        if (str_starts_with($head, '%PDF-')) {
            return 'pdf';
        }

        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }

        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpeg';
        }

        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }

        // ZIP local file header. "PK\x05\x06"/"PK\x07\x08" são pacotes vazios/spanned: não servem.
        if (str_starts_with($head, "PK\x03\x04")) {
            return 'docx';
        }

        throw UploadRejectedException::make(
            'unsupported_type',
            'Formato não aceito. Envie um arquivo PDF, DOCX, PNG, JPEG ou WEBP.',
        );
    }

    /**
     * @throws UploadRejectedException
     */
    private function assertFinfoAgrees(string $path, string $format): void
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            // Sem libmagic disponível a assinatura de bytes já decidiu; não afrouxa nada.
            return;
        }

        $detected = finfo_file($finfo, $path);
        finfo_close($finfo);

        if ($detected === false) {
            throw UploadRejectedException::make(
                'unsupported_type',
                'Não foi possível identificar o tipo do arquivo. Envie um PDF, DOCX, PNG, JPEG ou WEBP.',
            );
        }

        $detected = strtolower(trim(explode(';', $detected)[0]));

        if (! in_array($detected, self::FINFO_ALLOWED[$format], true)) {
            throw UploadRejectedException::make(
                'content_type_mismatch',
                'O conteúdo do arquivo não corresponde a um PDF, DOCX, PNG, JPEG ou WEBP válido.',
                ['detected_mime' => $detected, 'format' => $format],
            );
        }
    }

    /**
     * @throws UploadRejectedException
     */
    private function assertExtensionMatches(string $extension, DocumentSourceType $sourceType): void
    {
        /** @var array<string, list<string>> $map */
        $map = (array) $this->config->get('assinavelox.upload.extensions', []);
        $allowed = $map[$sourceType->value] ?? [];

        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            throw UploadRejectedException::make(
                'extension_mismatch',
                sprintf(
                    'O conteúdo do arquivo (%s) não corresponde à extensão informada. Renomeie o arquivo com a extensão correta e envie novamente.',
                    $sourceType->label(),
                ),
                ['extension' => $extension, 'source_type' => $sourceType->value],
            );
        }
    }

    // -- DOCX -------------------------------------------------------------------------

    /**
     * Inspeção do pacote OOXML antes de aceitar: um ZIP de poucos KB pode declarar
     * gigabytes descompactados ("zip bomb") e derrubar o conversor.
     *
     * @return array<string, mixed>
     *
     * @throws UploadRejectedException
     */
    private function inspectDocx(string $path, int $size): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw UploadRejectedException::make(
                'zip_unavailable',
                'Não foi possível validar o arquivo DOCX nesta instalação. Envie o documento em PDF.',
            );
        }

        $limits = (array) $this->config->get('assinavelox.upload.docx', []);
        $maxEntries = max(1, (int) ($limits['max_entries'] ?? 2000));
        $maxUncompressed = max(1, (int) ($limits['max_uncompressed_mb'] ?? 300)) * 1024 * 1024;
        $maxRatio = max(1, (int) ($limits['max_compression_ratio'] ?? 150));
        $requiredEntry = (string) ($limits['required_entry'] ?? 'word/document.xml');

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw UploadRejectedException::make(
                'invalid_docx',
                'O arquivo DOCX está corrompido ou não pôde ser aberto.',
            );
        }

        try {
            $entries = $zip->numFiles;

            if ($entries <= 0) {
                throw UploadRejectedException::make('invalid_docx', 'O arquivo DOCX está vazio ou corrompido.');
            }

            if ($entries > $maxEntries) {
                throw UploadRejectedException::make(
                    'docx_too_many_entries',
                    'O arquivo DOCX tem itens internos demais e não pôde ser aceito.',
                    ['entries' => $entries, 'max_entries' => $maxEntries],
                );
            }

            $uncompressed = 0;

            for ($index = 0; $index < $entries; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    throw UploadRejectedException::make('invalid_docx', 'O arquivo DOCX está corrompido.');
                }

                $name = (string) $stat['name'];

                if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
                    throw UploadRejectedException::make(
                        'docx_unsafe_entry',
                        'O arquivo DOCX contém itens internos com caminhos inválidos.',
                        ['entry_index' => $index],
                    );
                }

                $uncompressed += max(0, (int) $stat['size']);

                if ($uncompressed > $maxUncompressed) {
                    throw UploadRejectedException::make(
                        'docx_uncompressed_too_large',
                        sprintf(
                            'O conteúdo descompactado do DOCX ultrapassa %d MB e não pôde ser aceito.',
                            intdiv($maxUncompressed, 1024 * 1024),
                        ),
                        ['uncompressed_bytes' => $uncompressed, 'max_bytes' => $maxUncompressed],
                    );
                }
            }

            if ($uncompressed > $size * $maxRatio) {
                throw UploadRejectedException::make(
                    'docx_compression_ratio',
                    'O arquivo DOCX tem uma taxa de compressão suspeita e não pôde ser aceito.',
                    ['uncompressed_bytes' => $uncompressed, 'size_bytes' => $size, 'max_ratio' => $maxRatio],
                );
            }

            // Até aqui tudo veio do que o PACOTE DECLARA (`statIndex()['size']`, lido do
            // diretório central do ZIP) — um número escolhido por quem montou o arquivo.
            // Nada obriga o dado deflate a caber nele: um DOCX de 60 KB pode declarar 500
            // bytes e expandir 60 MB. As checagens acima ficam como corte barato (rejeitam
            // cedo quem declara demais); a expansão REAL é medida agora, em streaming e
            // com corte no limite, para que uma bomba nunca chegue ao conversor.
            $this->assertDocxExpansionWithinLimits($zip, $entries, $maxUncompressed, $size, $maxRatio);

            if ($zip->locateName($requiredEntry, ZipArchive::FL_NOCASE) === false) {
                throw UploadRejectedException::make(
                    'not_a_word_document',
                    'O arquivo não é um documento do Word (.docx) válido.',
                    ['required_entry' => $requiredEntry],
                );
            }

            return [
                'entries' => $entries,
                'uncompressed_bytes' => $uncompressed,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Lê cada entrada do DOCX em blocos e aborta assim que o acumulado passa do menor
     * teto entre `max_uncompressed_mb` e `size * max_compression_ratio`.
     *
     * Nada é mantido em memória além do bloco corrente, e a leitura para no primeiro byte
     * excedente — um pacote hostil custa, no máximo, o teto configurado.
     *
     * @throws UploadRejectedException
     */
    private function assertDocxExpansionWithinLimits(
        ZipArchive $zip,
        int $entries,
        int $maxUncompressed,
        int $size,
        int $maxRatio,
    ): void {
        $ratioCeiling = (int) floor($size * $maxRatio);
        $ceiling = max(1, min($maxUncompressed, $ratioCeiling));
        $chunk = 256 * 1024;
        $total = 0;

        for ($index = 0; $index < $entries; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                throw UploadRejectedException::make('invalid_docx', 'O arquivo DOCX está corrompido.');
            }

            $name = (string) $stat['name'];

            if (str_ends_with($name, '/')) {
                continue;
            }

            $stream = $zip->getStream($name);

            if (! is_resource($stream)) {
                // Entrada anunciada mas ilegível: criptografada, método desconhecido ou
                // diretório central mentindo. Não vai para o conversor.
                throw UploadRejectedException::make(
                    'invalid_docx',
                    'O arquivo DOCX contém itens internos que não puderam ser lidos.',
                    ['entry_index' => $index],
                );
            }

            try {
                while (! feof($stream)) {
                    $buffer = fread($stream, $chunk);

                    if ($buffer === false) {
                        throw UploadRejectedException::make(
                            'invalid_docx',
                            'O arquivo DOCX contém itens internos que não puderam ser lidos.',
                            ['entry_index' => $index],
                        );
                    }

                    $total += strlen($buffer);

                    if ($total > $ceiling) {
                        throw $total > $ratioCeiling && $ratioCeiling <= $maxUncompressed
                            ? UploadRejectedException::make(
                                'docx_compression_ratio',
                                'O arquivo DOCX tem uma taxa de compressão suspeita e não pôde ser aceito.',
                                ['uncompressed_bytes' => $total, 'size_bytes' => $size, 'max_ratio' => $maxRatio],
                            )
                            : UploadRejectedException::make(
                                'docx_uncompressed_too_large',
                                sprintf(
                                    'O conteúdo descompactado do DOCX ultrapassa %d MB e não pôde ser aceito.',
                                    intdiv($maxUncompressed, 1024 * 1024),
                                ),
                                ['uncompressed_bytes' => $total, 'max_bytes' => $maxUncompressed],
                            );
                    }
                }
            } finally {
                fclose($stream);
            }
        }
    }

    // -- Imagem -----------------------------------------------------------------------

    /**
     * Dimensões lidas do cabeçalho (getimagesize não decodifica os pixels), comparadas
     * com os mesmos limites do ImageNormalizer/pdftool.
     *
     * @return array<string, mixed>
     *
     * @throws UploadRejectedException
     */
    private function inspectImage(string $path, string $format): array
    {
        $info = @getimagesize($path);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw UploadRejectedException::make(
                'invalid_image',
                'A imagem está corrompida ou não pôde ser lida.',
            );
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        $limits = (array) $this->config->get('assinavelox.upload.image', []);
        $maxMegapixels = max(1, (int) ($limits['max_megapixels'] ?? 40));
        $maxSide = max(1, (int) ($limits['max_side_px'] ?? 20000));

        if ($width > $maxSide || $height > $maxSide) {
            throw UploadRejectedException::make(
                'image_too_large',
                sprintf('A imagem excede %d pixels de lado. Reduza a resolução e envie novamente.', $maxSide),
                ['width' => $width, 'height' => $height, 'max_side_px' => $maxSide],
            );
        }

        if ($width * $height > $maxMegapixels * 1_000_000) {
            throw UploadRejectedException::make(
                'image_too_large',
                sprintf('A imagem excede o limite de %d megapixels. Reduza a resolução e envie novamente.', $maxMegapixels),
                ['width' => $width, 'height' => $height, 'max_megapixels' => $maxMegapixels],
            );
        }

        return [
            'width_px' => $width,
            'height_px' => $height,
            'image_format' => $format,
        ];
    }

    // -- Nome de exibição ---------------------------------------------------------------

    private function normalizedExtension(UploadedFile $file): string
    {
        $name = (string) $file->getClientOriginalName();
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return preg_replace('/[^a-z0-9]/', '', $extension) ?? '';
    }

    /**
     * Nome exibido: só o basename, sem separadores, sem caracteres de controle, sem
     * espaços nas pontas, limitado a 255 bytes. NUNCA usado como caminho no disco.
     */
    public function sanitizeFilename(string $original, string $extension = ''): string
    {
        // Barra invertida vira barra para que `basename` também descarte caminhos do
        // Windows quando o PHP roda em Linux.
        $name = basename(str_replace('\\', '/', $original));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = trim($name, " .\t\n\r\0\x0B");

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'documento'.($extension !== '' ? '.'.$extension : '');
        }

        if (mb_strlen($name) > 200) {
            $suffix = $extension !== '' ? '.'.$extension : '';
            $name = mb_substr($name, 0, 200 - mb_strlen($suffix)).$suffix;
        }

        return $name;
    }

    /**
     * Nome sem extensão, para `documents.name` (máx. 200 conforme a migration).
     */
    private function baseName(string $displayName): string
    {
        $base = (string) pathinfo($displayName, PATHINFO_FILENAME);
        $base = trim($base);

        if ($base === '') {
            $base = 'Documento';
        }

        return mb_substr($base, 0, 200);
    }
}
