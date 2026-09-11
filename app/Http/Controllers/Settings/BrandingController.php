<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\BrandingUpdateRequest;
use App\Services\Branding\BrandingFeature;
use App\Services\Branding\BrandingLimits;
use App\Services\Branding\BrandingManager;
use App\Services\Branding\BrandingPresenter;
use App\Services\Branding\ParticipantMailSender;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › Marca (Fase 2 §2.8 — docs/fase-2/branding.md).
 *
 * Rotas sob `org` + `org.role:owner,admin` e Policy `updateSettings`. Com a flag
 * `branding` desligada, o GET mostra o estado "Fase 2" e as escritas respondem 403.
 */
class BrandingController extends Controller
{
    public function __construct(
        private readonly BrandingManager $manager,
        private readonly BrandingPresenter $presenter,
        private readonly ParticipantMailSender $sender,
    ) {}

    public function edit(Request $request): Response
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('updateSettings', $organization);

        $saved = $this->presenter->forSettings($organization);
        $status = $this->sender->senderStatus($organization, $saved['sender_email']);

        return Inertia::render('settings/branding', [
            'enabled' => BrandingFeature::enabled($organization),
            'organization_name' => $organization->name,
            'organization_initials' => $organization->initials,
            'branding' => $saved,
            'defaults' => [
                'display_name' => $organization->name,
                'primary_color' => BrandingLimits::DEFAULT_PRIMARY,
                'accent_color' => BrandingLimits::DEFAULT_ACCENT,
            ],
            'limits' => BrandingLimits::forFront(),
            'sender' => [
                'platform_from_address' => (string) config('mail.from.address'),
                'platform_from_name' => (string) config('mail.from.name'),
                'custom_sender_active' => $status['custom_sender_active'],
                'domain' => $status['domain'],
                'domain_verified' => $status['domain_verified'],
                // Texto de produto para o cliente, sem prometer prazo. A pendência de
                // integração é da operadora e fica em docs/fase-2/branding.md §5.3 e §11, não aqui.
                'pending' => [
                    'O envio com o endereço da sua organização ainda não está disponível nesta instalação.',
                    'Enquanto isso, os e-mails aos participantes saem de '.config('mail.from.address').', e as respostas deles vão para o e-mail para respostas informado acima.',
                    'Você já pode salvar o endereço desejado: ele passa a ser usado quando o recurso estiver disponível e o domínio do endereço for verificado.',
                ],
            ],
        ]);
    }

    public function update(BrandingUpdateRequest $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();

        abort_unless(BrandingFeature::enabled($organization), 403);

        $this->manager->update($organization, $request->user(), $request->validated());

        return back()->with('success', 'Marca atualizada.');
    }
}
