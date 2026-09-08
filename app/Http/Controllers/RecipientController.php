<?php

namespace App\Http\Controllers;

use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Assinaturas (recipients cross-envelope) — ROUTES §2.9. Esqueleto com o contrato de props
 * (lista paginada vazia no shape Paginated) — // TODO(Wave B): consulta real via EnvelopeVisibility::recipients().
 */
class RecipientController extends Controller
{
    private const TABS = ['all', 'pending', 'signed', 'refused', 'expired'];

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(self::TABS)],
            'q' => ['nullable', 'string', 'max:120'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date', 'after_or_equal:period_from'],
            'sort' => ['nullable', Rule::in(['recent', 'oldest'])],
        ]);

        $membership = CurrentOrganization::instance()->membership();

        return Inertia::render('recipients/index', [
            'filters' => [
                'status' => $validated['status'] ?? 'all',
                'q' => trim((string) ($validated['q'] ?? '')),
                'period_from' => $validated['period_from'] ?? null,
                'period_to' => $validated['period_to'] ?? null,
                'sort' => $validated['sort'] ?? 'recent',
            ],
            'kpis' => [
                'signed_today' => ['value' => 0, 'delta_vs_yesterday' => 0],
                'pending' => ['value' => 0, 'viewed' => 0],
                'refused_30d' => ['value' => 0, 'pct_of_total' => null],
                'avg_minutes_to_sign' => ['value' => null],
            ],
            'tabs' => array_fill_keys(self::TABS, 0),
            'recipients' => self::emptyPaginated($request->url()),
            'can' => ['resend_pending' => $membership->role->canManageMembers()],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        // TODO(Wave B): CSV com os mesmos filtros da listagem.
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Nome', 'E-mail', 'Documento', 'Status', 'Quando'], ';');
            fclose($out);
        }, 'assinaturas-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function resendPending(Request $request): RedirectResponse
    {
        // TODO(Wave B): job em fila; throttle 1×/hora por organização.
        return back()->with('info', 'Reenvio em lote estará disponível em breve (Wave B).');
    }

    /**
     * Shape Paginated<T> vazio (ROUTES §0.4) para esqueletos.
     *
     * @return array<string, mixed>
     */
    public static function emptyPaginated(string $path, int $perPage = 10): array
    {
        return [
            'data' => [],
            'links' => ['first' => $path.'?page=1', 'last' => $path.'?page=1', 'prev' => null, 'next' => null],
            'meta' => [
                'current_page' => 1,
                'from' => null,
                'to' => null,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
                'path' => $path,
                'links' => [
                    ['url' => null, 'label' => '&laquo; Anterior', 'active' => false],
                    ['url' => $path.'?page=1', 'label' => '1', 'active' => true],
                    ['url' => null, 'label' => 'Próxima &raquo;', 'active' => false],
                ],
            ],
        ];
    }
}
