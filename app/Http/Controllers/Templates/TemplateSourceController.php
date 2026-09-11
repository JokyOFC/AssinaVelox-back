<?php

namespace App\Http\Controllers\Templates;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Templates\Concerns\RequiresTemplatesFeature;
use App\Http\Requests\Templates\ReplaceTemplateSourceRequest;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Services\Documents\DocumentStorage;
use App\Services\Templates\TemplateDocumentRenderer;
use App\Services\Templates\TemplateManager;
use App\Services\Templates\TemplateSourceType;
use App\Services\Templates\TemplateStorage;
use App\Services\Templates\VariableValues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Arquivo de origem e pré-visualização de um modelo. Todo byte passa por Policy; o disco é
 * privado e não há URL pública (mesma regra dos documentos).
 */
class TemplateSourceController extends Controller implements HasMiddleware
{
    use RequiresTemplatesFeature;

    public static function middleware(): array
    {
        return [self::templatesFeatureMiddleware()];
    }

    /**
     * Download do arquivo enviado (DOCX/PDF) da versão atual ou de `?version={ulid}`.
     */
    public function show(Request $request, Template $template, TemplateStorage $storage, DocumentStorage $documents): Response
    {
        Gate::authorize('update', $template);

        $version = $this->version($request, $template);

        abort_unless($version->hasFile() && $storage->exists($version), 404, 'Este modelo não tem arquivo de origem.');

        $extension = $template->source_type === TemplateSourceType::Docx ? 'docx' : 'pdf';

        return $storage->stream(
            $version,
            $documents->downloadFilename((string) pathinfo((string) $version->original_filename, PATHINFO_FILENAME), 'modelo', $extension),
            'attachment',
            (string) $version->mime_type,
        );
    }

    /**
     * PDF para o editor e para a pré-visualização: o próprio PDF (fonte pdf) ou o HTML
     * renderizado com valores de EXEMPLO (fonte html). DOCX depende da conversão e não tem
     * pré-visualização no servidor.
     */
    public function preview(Request $request, Template $template, TemplateStorage $storage, TemplateDocumentRenderer $renderer, VariableValues $values): Response
    {
        Gate::authorize('view', $template);

        $version = $this->version($request, $template);

        if ($template->source_type === TemplateSourceType::Pdf) {
            abort_unless($storage->exists($version), 404, 'O arquivo deste modelo não foi encontrado.');

            return $storage->stream($version, 'modelo.pdf', 'inline', 'application/pdf');
        }

        abort_if(
            $template->source_type === TemplateSourceType::Docx,
            404,
            'A pré-visualização de modelos Word depende da conversão para PDF; baixe o arquivo para conferir.',
        );

        $bytes = $renderer->htmlPdf($version, $renderer->sample($version, $values), $template->name);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="pre-visualizacao.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    public function update(ReplaceTemplateSourceRequest $request, Template $template, TemplateManager $manager): RedirectResponse
    {
        $result = $manager->replaceSource($template, $request->user(), $request->file('file'));

        $notes = [];

        if ($result['added_variables'] > 0) {
            $notes[] = $result['added_variables'].' variável(is) nova(s) encontrada(s) no arquivo — revise o tipo de cada uma';
        }

        if ($result['dropped_fields'] > 0) {
            $notes[] = $result['dropped_fields'].' campo(s) removido(s) por não caberem nas páginas do novo arquivo';
        }

        return back()->with('success', sprintf(
            'Arquivo substituído: versão %d criada.%s',
            $result['version']->version_number,
            $notes === [] ? '' : ' '.implode('; ', $notes).'.',
        ));
    }

    private function version(Request $request, Template $template): TemplateVersion
    {
        $ulid = $request->query('version');

        $version = is_string($ulid) && $ulid !== ''
            ? TemplateVersion::query()->with('variables')->where('template_id', $template->getKey())->where('ulid', $ulid)->first()
            : $template->currentVersion()->with('variables')->first();

        abort_unless($version instanceof TemplateVersion, 404);

        return $version;
    }
}
