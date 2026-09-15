<?php

namespace App\Http\Controllers\BulkGenerations;

use App\Http\Controllers\BulkGenerations\Middleware\EnsureBulkGenerationFeature;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkGenerations\ConfirmBulkGenerationRequest;
use App\Http\Requests\BulkGenerations\StoreBulkGenerationRequest;
use App\Http\Requests\BulkGenerations\UpdateMappingRequest;
use App\Models\BulkGeneration;
use App\Models\BulkGenerationRow;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\BulkGeneration\BulkGenerationAccess;
use App\Services\BulkGeneration\BulkGenerationDryRun;
use App\Services\BulkGeneration\BulkGenerationIntake;
use App\Services\BulkGeneration\BulkGenerationManager;
use App\Services\BulkGeneration\BulkGenerationPresenter;
use App\Services\BulkGeneration\BulkRowOutcome;
use App\Services\BulkGeneration\BulkRowStatus;
use App\Services\BulkGeneration\ColumnMapping;
use App\Services\Signing\Channels\ChannelFeatures;
use App\Support\Csv;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Geração documental em lote (Fase 3 §3.1 — docs/fase-3/geracao-em-lote.md).
 *
 * Fluxo: modelo → "Gerar em lote" (create) → envio da planilha (store) → mapeamento de colunas
 * e pré-validação (mapping) → confirmação com a cota (confirm) → acompanhamento (show, com
 * polling) → relatório CSV (report). Flag `bulk_generation` desligada: 404 em todas.
 */
class BulkGenerationController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(EnsureBulkGenerationFeature::class)];
    }

    public function index(Request $request): Response
    {
        abort_unless(BulkGenerationAccess::canList(), 403, 'Sua função não permite gerar documentos em lote.');

        $query = BulkGeneration::query()->with(['template', 'creator'])->latest('id');

        if (! BulkGenerationAccess::seesAll()) {
            $query->where('created_by_user_id', $this->user($request)->getKey());
        }

        return Inertia::render('bulk-generations/index', BulkGenerationPresenter::index($query->paginate(20)->withQueryString()));
    }

    public function create(Request $request, Template $template): Response
    {
        abort_unless(BulkGenerationAccess::canCreateFrom($this->user($request), $template), 403, 'Sua função não permite gerar documentos a partir deste modelo.');

        $version = $this->currentVersion($template);

        return Inertia::render('bulk-generations/create', BulkGenerationPresenter::create($template, $version, $template->organization));
    }

    /**
     * Planilha-modelo (CSV com o cabeçalho reconhecido pela sugestão de mapeamento).
     */
    public function sample(Request $request, Template $template): StreamedResponse
    {
        abort_unless(BulkGenerationAccess::canCreateFrom($this->user($request), $template), 403, 'Sua função não permite gerar documentos a partir deste modelo.');

        $version = $this->currentVersion($template);
        $headers = ColumnMapping::sampleHeaders($version, ChannelFeatures::smsWhatsapp($template->organization));

        return response()->streamDownload(function () use ($headers): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, Csv::BOM);
            fputcsv($out, Csv::row($headers), ';', '"', '');
            fclose($out);
        }, 'planilha-modelo.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(StoreBulkGenerationRequest $request, Template $template, BulkGenerationIntake $intake): RedirectResponse
    {
        $user = $this->user($request);

        abort_unless(BulkGenerationAccess::canCreateFrom($user, $template), 403, 'Sua função não permite gerar documentos a partir deste modelo.');

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Escolha a planilha (CSV ou XLSX).']);
        }

        $batch = $intake->store($template, $user, $file);

        return redirect()
            ->route('bulk_generations.show', $batch)
            ->with('success', 'Planilha recebida. Confira as colunas e valide.');
    }

    public function show(Request $request, BulkGeneration $bulkGeneration): Response
    {
        abort_unless(BulkGenerationAccess::canView($this->user($request), $bulkGeneration), 403, 'Você não tem acesso a este lote.');

        $default = $bulkGeneration->status->isEditable() ? 'problems' : 'all';
        $filter = in_array($request->query('linhas'), ['problems', 'all'], true) ? (string) $request->query('linhas') : $default;

        return Inertia::render('bulk-generations/show', BulkGenerationPresenter::show(
            $bulkGeneration,
            $filter,
            max(1, (int) $request->query('pagina', '1')),
        ));
    }

    public function mapping(UpdateMappingRequest $request, BulkGeneration $bulkGeneration, BulkGenerationDryRun $dryRun): RedirectResponse
    {
        abort_unless(BulkGenerationAccess::canManage($this->user($request), $bulkGeneration), 403, 'Você não pode alterar este lote.');

        /** @var array<array-key, mixed> $mapping */
        $mapping = (array) $request->validated('mapping', []);
        $batch = $dryRun->run($bulkGeneration, $mapping);

        $message = $batch->invalid_count === 0
            ? sprintf('Pré-validação concluída: %d %s pronta(s) para gerar.', $batch->valid_count, $batch->valid_count === 1 ? 'linha' : 'linhas')
            : sprintf('Pré-validação concluída: %d válida(s) e %d com erro.', $batch->valid_count, $batch->invalid_count);

        return redirect()->route('bulk_generations.show', $batch)->with('success', $message);
    }

    public function confirm(ConfirmBulkGenerationRequest $request, BulkGeneration $bulkGeneration, BulkGenerationManager $manager): RedirectResponse
    {
        $user = $this->user($request);

        abort_unless(BulkGenerationAccess::canManage($user, $bulkGeneration), 403, 'Você não pode confirmar este lote.');

        /** @var array{mode?: string|null, scheduled_for?: string|null} $options */
        $options = $request->validated();
        $manager->confirm($bulkGeneration, $user, $options);

        return redirect()
            ->route('bulk_generations.show', $bulkGeneration)
            ->with('success', 'Lote confirmado. Os documentos estão sendo gerados.');
    }

    public function cancel(Request $request, BulkGeneration $bulkGeneration, BulkGenerationManager $manager): RedirectResponse
    {
        $user = $this->user($request);

        abort_unless(BulkGenerationAccess::canCancel($user, $bulkGeneration), 403, 'Você não pode cancelar este lote.');

        $batch = $manager->cancel($bulkGeneration, $user);

        return redirect()
            ->route('bulk_generations.show', $batch)
            ->with('success', 'Lote cancelado. Os documentos já gerados continuam em Documentos.');
    }

    public function destroy(Request $request, BulkGeneration $bulkGeneration, BulkGenerationManager $manager): RedirectResponse
    {
        abort_unless(BulkGenerationAccess::canManage($this->user($request), $bulkGeneration), 403, 'Você não pode descartar este lote.');

        $manager->discard($bulkGeneration);

        return redirect()->route('bulk_generations.index')->with('success', 'Lote descartado.');
    }

    /**
     * Relatório CSV das linhas com problema (erros de validação, falhas e envios que não
     * aconteceram). Só mensagens e o código do documento — nenhum valor da planilha. Células
     * neutralizadas contra injeção de fórmula (App\Support\Csv).
     */
    public function report(Request $request, BulkGeneration $bulkGeneration): StreamedResponse
    {
        abort_unless(BulkGenerationAccess::canView($this->user($request), $bulkGeneration), 403, 'Você não tem acesso a este lote.');

        $batchId = (int) $bulkGeneration->getKey();
        $organizationId = (int) $bulkGeneration->organization_id;

        return response()->streamDownload(function () use ($batchId, $organizationId): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, Csv::BOM);
            fputcsv($out, ['Linha', 'Situação', 'Coluna', 'Mensagem', 'Documento'], ';', '"', '');

            BulkGenerationRow::withoutOrganizationScope()
                ->where('bulk_generation_id', $batchId)
                ->where('organization_id', $organizationId)
                ->where(function ($where): void {
                    $where->whereIn('status', [BulkRowStatus::Invalid->value, BulkRowStatus::Failed->value, BulkRowStatus::Canceled->value])
                        ->orWhere('outcome', BulkRowOutcome::NotSent->value);
                })
                ->with(['envelope' => fn ($relation) => $relation->withoutGlobalScopes()])
                ->orderBy('row_index')
                ->chunk(200, function (Collection $rows) use ($out): void {
                    foreach ($rows as $row) {
                        /** @var BulkGenerationRow $row */
                        $code = $row->envelope->display_code ?? '';

                        if ($row->status === BulkRowStatus::Invalid) {
                            foreach ($row->errors ?? [] as $error) {
                                fputcsv($out, Csv::row([$row->row_index, $row->status->label(), $error['header'] ?? '', $error['message'], '']), ';', '"', '');
                            }

                            continue;
                        }

                        $message = match (true) {
                            $row->status === BulkRowStatus::Failed => (string) $row->error,
                            $row->status === BulkRowStatus::Canceled => 'Linha cancelada antes da geração.',
                            default => (string) $row->outcome_message,
                        };

                        $label = $row->outcome !== null ? $row->outcome->label() : $row->status->label();

                        fputcsv($out, Csv::row([$row->row_index, $label, '', $message, $code]), ';', '"', '');
                    }
                });

            fclose($out);
        }, 'lote-'.strtolower($bulkGeneration->ulid).'-relatorio.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && CurrentOrganization::instance()->membership() !== null, 403);

        return $user;
    }

    private function currentVersion(Template $template): TemplateVersion
    {
        /** @var TemplateVersion|null $version */
        $version = $template->currentVersion()->with(['variables', 'roles', 'fields'])->first();

        BulkGenerationIntake::assertTemplateUsable($template, $version);

        /** @var TemplateVersion $version */
        return $version;
    }
}
