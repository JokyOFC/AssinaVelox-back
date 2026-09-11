<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Requests\Settings\UpdateOrganizationSettingsRequest;
use App\Http\Requests\Settings\UpdateSecuritySettingsRequest;
use App\Services\Branding\BrandingPresenter;
use App\Services\Retention\LegalHolds;
use App\Support\CurrentOrganization;
use App\Support\OrganizationSettings;
use App\Support\TaxId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › Geral e segurança (ROUTES §2.12). Rotas sob `org` + `org.role:owner,admin`;
 * exclusão sob `org.role:owner` + `password.confirm`.
 */
class GeneralController extends Controller
{
    public function edit(Request $request): Response
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('updateSettings', $organization);

        $settings = OrganizationSettings::of($organization);

        // A prop `organization` desta página tem o mesmo nome da prop compartilhada (ROUTES §0.3):
        // mesclamos os campos do shell (plan/role/permissions) para não quebrar sidebar e switcher.
        return Inertia::render('settings/general', [
            'organization' => [
                ...(HandleInertiaRequests::currentOrganizationProps() ?? []),
                'legal_name' => $organization->legal_name,
                'name' => $organization->name,
                'tax_id' => TaxId::format($organization->tax_id),
                'contact_email' => $settings->contactEmail(),
                'initials' => $organization->initials,
                // Fase 2 §2.8 (C-BRAND): logo salvo, só com a flag `branding`; senão null.
                'logo_url' => app(BrandingPresenter::class)->logoUrl($organization),
                'timezone' => $organization->timezone,
            ],
            'security' => [
                'require_two_factor' => $settings->requireTwoFactor(),
                'session_idle_hours' => $settings->sessionIdleHours(),
                'sso_enabled' => false,
                'ip_allowlist_enabled' => false,
            ],
            'deletion' => [
                'requested_at' => $settings->deletionRequestedAt()?->toIso8601String(),
                'scheduled_for' => $settings->deletionScheduledFor()?->toIso8601String(),
            ],
            'can' => [
                'delete_organization' => $request->user()->can('delete', $organization),
            ],
        ]);
    }

    public function updateOrganization(UpdateOrganizationSettingsRequest $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();

        $organization->forceFill([
            'name' => trim($request->validated('name')),
            'legal_name' => filled($request->validated('legal_name')) ? trim($request->validated('legal_name')) : null,
            'tax_id' => filled($request->validated('tax_id')) ? TaxId::digits($request->validated('tax_id')) : null,
            'timezone' => $request->validated('timezone') ?? $organization->timezone,
        ])->save();

        OrganizationSettings::of($organization)->put([
            'contact_email' => filled($request->validated('contact_email')) ? mb_strtolower(trim($request->validated('contact_email'))) : null,
        ]);

        return back()->with('success', 'Alterações salvas.');
    }

    public function updateSecurity(UpdateSecuritySettingsRequest $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();

        OrganizationSettings::of($organization)->put([
            'require_two_factor' => (bool) $request->validated('require_two_factor'),
            'session_idle_hours' => $request->validated('session_idle_hours'),
        ]);

        return back()->with('success', 'Políticas de segurança atualizadas.');
    }

    /**
     * POST = solicitar exclusão; DELETE = cancelar a solicitação (mesma URL, ROUTES §2.12).
     */
    public function deletion(Request $request): RedirectResponse
    {
        return $request->isMethod('DELETE')
            ? $this->cancelDeletion($request)
            : $this->requestDeletion($request);
    }

    public function requestDeletion(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('delete', $organization);

        $settings = OrganizationSettings::of($organization);

        if ($settings->deletionRequestedAt() !== null) {
            return back()->with('info', 'A exclusão desta organização já está agendada.');
        }

        $settings->put(['deletion_requested_at' => now()]);

        // A exclusão efetiva (job após o período de carência) e o cancelamento da assinatura
        // pertencem ao incremento de cobrança; aqui só registramos a solicitação.
        $scheduled = $settings->deletionScheduledFor();
        $message = 'Exclusão agendada para '.$scheduled?->setTimezone($organization->timezone)->format('d/m/Y').'. Você pode cancelar até essa data.';

        // Fase 2 §2.19 (integração I-2C, retencao-e-preservacao.md §5.4): com preservação ativa
        // a exclusão definitiva fica bloqueada até a última ser liberada. Sem bloqueio, o texto
        // é o de sempre.
        if (app(LegalHolds::class)->anyActive((int) $organization->getKey()) !== null) {
            $message .= ' Atenção: há documentos sob preservação legal. A exclusão definitiva só acontece depois que todas as preservações forem liberadas.';
        }

        return back()->with('warning', $message);
    }

    public function cancelDeletion(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('delete', $organization);

        OrganizationSettings::of($organization)->put(['deletion_requested_at' => null]);

        return back()->with('success', 'Exclusão cancelada. A organização continua ativa.');
    }
}
