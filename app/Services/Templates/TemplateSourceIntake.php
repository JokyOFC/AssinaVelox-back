<?php

namespace App\Services\Templates;

use App\Enums\DocumentSourceType;
use App\Models\Template;
use App\Services\Documents\Exceptions\UploadRejectedException;
use App\Services\Documents\UploadInspector;
use App\Services\Pdf\Exceptions\PdfToolException;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Http\UploadedFile;

/**
 * Entrada do arquivo de origem de um modelo (DOCX ou PDF).
 *
 * 1. a MESMA inspeção do upload de documentos (`UploadInspector`: tipo real pelos bytes,
 *    limite de tamanho, zip bomb, entradas com caminho inválido, extensão coerente);
 * 2. o tipo real precisa ser o do modelo (um modelo PDF não aceita DOCX, e vice-versa);
 * 3. DOCX: regras de modelo ({@see DocxSafety} — macros, ActiveX, objetos, referências
 *    externas, campos DDE/INCLUDE) e leitura dos marcadores `${chave}`;
 * 4. PDF: `pdftool inspect` — protegido por senha, já assinado ou ilegível é recusado; as
 *    páginas (`pages_meta`) viram a base da geometria dos campos;
 * 5. só então os bytes são gravados, sob caminho opaco ({@see TemplateStorage}).
 *
 * Quem chama é responsável por apagar o arquivo gravado se o registro não for commitado.
 */
final class TemplateSourceIntake
{
    public function __construct(
        private readonly UploadInspector $inspector,
        private readonly DocxSafety $safety,
        private readonly PdfToolClient $pdftool,
        private readonly TemplateStorage $storage,
    ) {}

    /**
     * @throws TemplateRejectedException
     */
    public function ingest(Template $template, UploadedFile $file, string $versionUlid): IngestedSource
    {
        try {
            $inspected = $this->inspector->inspect($file);
        } catch (UploadRejectedException $exception) {
            throw TemplateRejectedException::make('upload_rejected', $exception->getMessage());
        }

        $expected = $template->source_type->documentSourceType();

        if ($expected === null || $inspected->sourceType !== $expected) {
            throw TemplateRejectedException::make(
                'source_type_mismatch',
                $expected === DocumentSourceType::Docx
                    ? 'Este modelo é do tipo Word: envie um arquivo .docx.'
                    : 'Este modelo é do tipo PDF fixo: envie um arquivo .pdf.',
            );
        }

        $path = (string) $file->getRealPath();
        $placeholders = [];
        $pageCount = null;
        $pagesMeta = null;

        if ($expected === DocumentSourceType::Docx) {
            $this->safety->assertSafe($path);
            $placeholders = $this->docxPlaceholders($path);
        } else {
            [$pageCount, $pagesMeta] = $this->inspectPdf($path);
        }

        $sha256 = hash_file('sha256', $path);

        if ($sha256 === false) {
            throw TemplateRejectedException::make('hash_failed', 'Não foi possível processar o arquivo enviado. Tente novamente.');
        }

        $storagePath = $this->storage->pathFor($template, $versionUlid, $inspected->extension);

        try {
            $this->storage->putFile($path, $storagePath);
        } catch (\Throwable) {
            throw TemplateRejectedException::make('storage_unavailable', 'Não foi possível guardar o arquivo agora. Tente de novo em instantes.');
        }

        return new IngestedSource(
            type: $template->source_type,
            storagePath: $storagePath,
            originalFilename: $inspected->displayName,
            mimeType: $inspected->mimeType,
            sizeBytes: $inspected->sizeBytes,
            sha256: $sha256,
            pageCount: $pageCount,
            pagesMeta: $pagesMeta,
            placeholders: $placeholders,
        );
    }

    /**
     * @return list<string>
     *
     * @throws TemplateRejectedException
     */
    private function docxPlaceholders(string $path): array
    {
        try {
            $processor = new RestrictedTemplateProcessor($path);
        } catch (\Throwable) {
            throw TemplateRejectedException::make('invalid_docx', 'O arquivo DOCX está corrompido ou não pôde ser lido.');
        }

        try {
            $scan = $processor->scanMarkers();
        } finally {
            $processor->discard();
        }

        if ($scan['invalid'] !== []) {
            throw TemplateRejectedException::make(
                'docx_invalid_markers',
                sprintf(
                    'Marcadores inválidos no arquivo: %s. Use ${nome_da_variavel} com letras minúsculas, números e "_", sem formatação diferente no meio do marcador.',
                    implode(', ', array_slice($scan['invalid'], 0, 5)),
                ),
            );
        }

        return $scan['keys'];
    }

    /**
     * @return array{0: int, 1: list<array<string, mixed>>}
     *
     * @throws TemplateRejectedException
     */
    private function inspectPdf(string $path): array
    {
        if (! $this->pdftool->isAvailable()) {
            throw TemplateRejectedException::make(
                'pdf_tool_unavailable',
                'A leitura de PDF não está disponível nesta instalação, então não é possível cadastrar modelos PDF agora. Avise o suporte.',
            );
        }

        try {
            $inspection = $this->pdftool->inspect($path);
        } catch (PdfToolException) {
            throw TemplateRejectedException::make('invalid_pdf', 'Não foi possível ler este PDF. Verifique se o arquivo abre normalmente e envie de novo.');
        }

        if ($inspection->encrypted) {
            throw TemplateRejectedException::make('encrypted_pdf', 'O PDF está protegido por senha. Remova a proteção e envie de novo.');
        }

        if ($inspection->hasSignatures) {
            throw TemplateRejectedException::make('signed_pdf', 'O PDF já tem assinatura digital; um modelo não pode partir de um arquivo assinado.');
        }

        if ($inspection->isBlockedForPreparation() || $inspection->pageCount < 1) {
            throw TemplateRejectedException::make('invalid_pdf', 'Este PDF não pode ser usado como modelo.');
        }

        return [$inspection->pageCount, $inspection->pagesMeta()];
    }
}
