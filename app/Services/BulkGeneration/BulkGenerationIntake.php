<?php

namespace App\Services\BulkGeneration;

use App\Models\BulkGeneration;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\BulkGeneration\Spreadsheet\SpreadsheetReader;
use App\Services\BulkGeneration\Spreadsheet\SpreadsheetRejectedException;
use App\Services\Documents\UploadInspector;
use App\Services\Envelopes\DomainFeatures;
use App\Services\Signing\Channels\ChannelFeatures;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Passo 1 do lote: recebe a planilha, lê o cabeçalho, fixa a versão ATUAL do modelo e guarda
 * o arquivo no disco privado com sha256. Nada é validado linha a linha ainda e nenhuma cota é
 * tocada. A sugestão de mapeamento já sai gravada para a tela de colunas.
 */
final class BulkGenerationIntake
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly BulkGenerationStorage $storage,
        private readonly UploadInspector $inspector,
    ) {}

    /**
     * @throws ValidationException
     */
    public function store(Template $template, User $user, UploadedFile $file): BulkGeneration
    {
        $organization = $template->organization;

        /** @var TemplateVersion|null $version */
        $version = $template->currentVersion()->with(['variables', 'roles'])->first();

        self::assertTemplateUsable($template, $version);

        $limits = BulkGenerationLimits::for($organization);
        $path = (string) $file->getRealPath();

        try {
            $sheet = $this->reader->read($path, strtolower($file->getClientOriginalExtension()), $limits);
        } catch (SpreadsheetRejectedException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $ulid = (string) Str::ulid();
        $storagePath = $this->storage->pathFor($organization, $ulid, $sheet->format);

        try {
            $this->storage->put($path, $storagePath);
        } catch (SpreadsheetRejectedException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        /** @var TemplateVersion $version */
        $phoneEnabled = ChannelFeatures::smsWhatsapp($organization);

        try {
            $batch = new BulkGeneration;
            $batch->forceFill([
                'ulid' => $ulid,
                'organization_id' => $organization->getKey(),
                'template_id' => $template->getKey(),
                'template_version_id' => $version->getKey(),
                'template_version_number' => $version->version_number,
                'status' => BulkGenerationStatus::Draft,
                'source_file' => $storagePath,
                'source_filename' => $this->inspector->sanitizeFilename($file->getClientOriginalName(), $sheet->format),
                'source_format' => $sheet->format,
                'source_sha256' => (string) hash_file('sha256', $path),
                'source_size_bytes' => (int) $file->getSize(),
                'headers' => $sheet->headers,
                'mapping' => ColumnMapping::suggest($sheet->headers, $version, $phoneEnabled),
                'row_count' => $sheet->rowCount(),
                'created_by_user_id' => $user->getKey(),
            ])->save();
        } catch (Throwable $exception) {
            $this->storage->disk()->deleteDirectory(dirname($storagePath));

            throw $exception;
        }

        return $batch;
    }

    /**
     * @throws ValidationException
     */
    public static function assertTemplateUsable(Template $template, ?TemplateVersion $version): void
    {
        if (! $template->isUsable() || $version === null) {
            throw ValidationException::withMessages(['template' => 'Este modelo não está disponível para uso.']);
        }

        if ($version->roles->isEmpty()) {
            throw ValidationException::withMessages(['template' => 'Cadastre ao menos um participante no modelo para gerar documentos em lote.']);
        }

        if ($version->usesNonSignerRoles() && ! DomainFeatures::participantRoles($template->organization)) {
            throw ValidationException::withMessages([
                'template' => 'Este modelo tem testemunha, aprovador ou visualizador, e esses papéis não estão disponíveis para esta organização.',
            ]);
        }
    }
}
