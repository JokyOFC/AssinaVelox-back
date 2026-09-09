<?php

namespace Tests\Feature\Documents\Support;

use App\Services\Pdf\PdfToolClient;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Feature\Pdf\Support\PdfFixtures;
use ZipArchive;

/**
 * Fixtures do pipeline documental, todas geradas em tempo de teste (nada binário
 * versionado). PDFs simples/cifrados/corrompidos e imagens vêm de
 * Tests\Feature\Pdf\Support\PdfFixtures; aqui ficam os artefatos específicos da
 * preparação: PDF já assinado, DOCX válido, DOCX "zip bomb" e PDF com página rotacionada.
 */
final class DocumentFixtures
{
    /**
     * Nome da variável de ambiente com a senha do PKCS#12 de teste (o valor nunca entra
     * em config, argv ou log).
     */
    public const CERT_PASS_ENV = 'DOCTEST_CERT_PASS';

    public const CERT_PASSPHRASE = 'senha-de-teste-Doc9!';

    /**
     * PDF de uma página **já assinado digitalmente** (PAdES B-B com certificado de TESTE,
     * autoassinado — não é ICP-Brasil). Serve para provar que o pipeline recusa preparar
     * um arquivo cujas assinaturas seriam destruídas.
     */
    public static function signedPdf(string $workDirectory, PdfToolClient $client): string
    {
        $base = PdfFixtures::onePagePdf($workDirectory.'/para-assinar.pdf', 'Contrato já assinado');
        $pfx = $workDirectory.'/doctest.pfx';
        $signed = $workDirectory.'/assinado.pdf';

        putenv(self::CERT_PASS_ENV.'='.self::CERT_PASSPHRASE);

        try {
            $client->generateTestCertificate($pfx, self::CERT_PASS_ENV, PdfToolClient::DEFAULT_TEST_SUBJECT, 2);
            $client->sign($base, $signed, $pfx, self::CERT_PASS_ENV);
        } finally {
            putenv(self::CERT_PASS_ENV);
        }

        if (! is_file($signed)) {
            throw new RuntimeException('Não foi possível gerar o PDF assinado de teste.');
        }

        return $signed;
    }

    /**
     * PDF de 2 páginas com a segunda girada 90° (`/Rotate 90`), para conferir que
     * `pages_meta` guarda a rotação e as dimensões **exibidas** (trocadas em 90/270).
     *
     * A rotação é aplicada com o pypdf do venv do pdftool — mexer nos bytes do PDF com
     * regex quebraria a tabela de referências cruzadas.
     */
    public static function rotatedPdf(string $path, PdfToolClient $client): string
    {
        $source = PdfFixtures::twoPagePdf(dirname($path).'/origem-rotacao.pdf');

        $script = <<<'PYTHON'
        import sys
        from pypdf import PdfReader, PdfWriter

        reader = PdfReader(sys.argv[1])
        writer = PdfWriter()
        for index, page in enumerate(reader.pages):
            if index == 1:
                page.rotate(90)
            writer.add_page(page)
        with open(sys.argv[2], "wb") as handle:
            writer.write(handle)
        PYTHON;

        $process = new Process(
            [$client->pythonBinary(), '-c', $script, $source, $path],
            $client->workingDirectory(),
            null,
            null,
            60,
        );
        $process->run();

        if ($process->getExitCode() !== 0 || ! is_file($path)) {
            throw new RuntimeException('Não foi possível girar a página do PDF de teste: '.$process->getErrorOutput());
        }

        @unlink($source);

        return $path;
    }

    /**
     * DOCX mínimo, mas real: `[Content_Types].xml` + `word/document.xml`.
     */
    public static function docx(string $path, string $text = 'Contrato de teste'): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Não foi possível criar o DOCX de teste em {$path}.");
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        return $path;
    }

    /**
     * ZIP bomb com cara de DOCX: poucos KB no disco, dezenas de MB descompactados. A razão
     * de compressão fica muito acima do limite configurado.
     */
    public static function docxZipBomb(string $path, int $uncompressedMegabytes = 48): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Não foi possível criar o DOCX de teste em {$path}.");
        }

        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<w:document/>');
        // Bloco altamente compressível: alguns KB no arquivo, muitos MB ao abrir.
        $zip->addFromString('word/media/bomba.bin', str_repeat("\0", $uncompressedMegabytes * 1024 * 1024));
        $zip->close();

        return $path;
    }

    /**
     * ZIP válido que NÃO é um documento do Word (sem `word/document.xml`).
     */
    public static function zipWithoutWordDocument(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Não foi possível criar o ZIP de teste em {$path}.");
        }

        $zip->addFromString('planilha/dados.xml', '<x/>');
        $zip->close();

        return $path;
    }

    /**
     * UploadedFile em modo teste (o arquivo não é movido do lugar). O MIME informado é o
     * do CLIENTE — de propósito, para provar que o inspetor decide pelo conteúdo.
     */
    public static function upload(string $path, string $clientName, ?string $clientMime = null): UploadedFile
    {
        return new UploadedFile($path, $clientName, $clientMime, null, true);
    }
}
