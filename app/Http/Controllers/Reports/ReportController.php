<?php

namespace App\Http\Controllers\Reports;

use App\Enums\AuditEventType;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Organization;
use App\Services\AdminLog\OrganizationTrail;
use App\Services\AdminLog\ToolFlags;
use App\Services\Reports\EnvelopeReport;
use App\Services\Reports\ReportFilters;
use App\Services\Tags\EnvelopeTagIndex;
use App\Support\Csv;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatórios (Fase 2, roadmap §2.14 — docs/fase-2/tags-relatorios-e-logs.md).
 *
 * Flag `reports` desligada: a página responde com o estado "Fase 2" (sem dado nenhum) e a
 * exportação também — nada muda para quem está na Fase 1.
 * Flag ligada: exige `view_reports`; a exportação exige também `export_data`. Todo número
 * vem de EnvelopeReport, que parte da regra de visibilidade do usuário.
 */
class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$organization, $membership] = $this->context();

        if (! ToolFlags::reports($organization)) {
            return $this->disabled();
        }

        abort_unless($membership->hasPermission(Permission::ViewReports), 403, 'Você não tem permissão para ver relatórios.');

        $filters = ReportFilters::fromRequest($request, $membership, $organization->timezone);
        $report = new EnvelopeReport($membership, $filters);

        return Inertia::render('reports/index', [
            'enabled' => true,
            'filters' => $filters->toArray(),
            'options' => [
                ...$report->filterOptions(),
                'statuses' => [
                    ['value' => 'in_progress', 'label' => 'Aguardando / em andamento'],
                    ['value' => 'completed', 'label' => 'Concluído'],
                    ['value' => 'refused', 'label' => 'Recusado'],
                    ['value' => 'expired', 'label' => 'Expirado'],
                    ['value' => 'canceled', 'label' => 'Cancelado'],
                ],
                'tags' => ToolFlags::tags($organization)
                    ? array_map(fn (array $tag): array => ['value' => $tag['id'], 'label' => $tag['name']], EnvelopeTagIndex::available($organization->getKey()))
                    : [],
            ],
            'report' => $report->summary(),
            'plan_usage' => $report->planUsage($organization),
            'scope' => [
                // Explica o recorte na tela: "todos os documentos" ou "os que você pode ver".
                'all_envelopes' => $membership->hasPermission(Permission::ViewAllEnvelopes),
            ],
            'can' => [
                'export' => $membership->hasPermission(Permission::ExportData),
            ],
        ]);
    }

    public function export(Request $request): Response|StreamedResponse
    {
        [$organization, $membership] = $this->context();

        if (! ToolFlags::reports($organization)) {
            return $this->disabled();
        }

        abort_unless(
            $membership->hasPermission(Permission::ViewReports) && $membership->hasPermission(Permission::ExportData),
            403,
            'Você não tem permissão para exportar relatórios.',
        );

        $filters = ReportFilters::fromRequest($request, $membership, $organization->timezone);
        $report = new EnvelopeReport($membership, $filters);
        $timezone = $organization->timezone;
        $organizationId = (int) $organization->getKey();

        $query = $report->inPeriod()
            ->with(['folder', 'creator'])
            ->orderBy('envelopes.id');

        OrganizationTrail::record($organizationId, AuditEventType::ReportExported, [
            'report' => 'envelopes',
            ...$filters->toArray(),
        ]);

        $filename = 'relatorio-documentos-'.$filters->from->format('Ymd').'-'.$filters->to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($query, $timezone, $organizationId): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, Csv::BOM);
            fputcsv($out, Csv::row([
                'Código', 'Título', 'Status', 'Pasta', 'Criado por', 'Etiquetas',
                'Enviado em', 'Concluído em', 'Recusado em', 'Expirado em', 'Cancelado em',
                'Horas até concluir',
            ]), ';');

            $format = fn ($at): string => $at?->copy()->setTimezone($timezone)->format('d/m/Y H:i') ?? '';

            $query->chunk(200, function ($envelopes) use ($out, $format, $organizationId): void {
                $chips = EnvelopeTagIndex::chipsFor($organizationId, $envelopes);

                foreach ($envelopes as $envelope) {
                    /** @var Envelope $envelope */
                    $hours = $envelope->completed_at !== null && $envelope->sent_at !== null
                        ? number_format(max(0, $envelope->completed_at->getTimestamp() - $envelope->sent_at->getTimestamp()) / 3600, 1, ',', '')
                        : '';

                    fputcsv($out, Csv::row([
                        $envelope->display_code,
                        $envelope->title,
                        $envelope->statusLabel(),
                        $envelope->folder->name ?? '',
                        $envelope->creator->name ?? '',
                        implode(', ', array_column($chips[$envelope->ulid] ?? [], 'name')),
                        $format($envelope->sent_at),
                        $format($envelope->completed_at),
                        $format($envelope->refused_at),
                        $format($envelope->expired_at),
                        $format($envelope->canceled_at),
                        $hours,
                    ]), ';');
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: Organization, 1: Membership}
     */
    private function context(): array
    {
        $current = CurrentOrganization::instance();
        $organization = $current->get();
        $membership = $current->membership();

        abort_if($organization === null || $membership === null, 403, 'Nenhuma organização ativa.');

        return [$organization, $membership];
    }

    private function disabled(): Response
    {
        return Inertia::render('reports/index', ['enabled' => false]);
    }
}
