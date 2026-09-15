<?php

namespace App\Http\Controllers\Embed;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organization;
use App\Services\Api\ApiFeature;
use App\Services\Embed\AllowedOrigins;
use App\Services\Embed\EmbedFeature;
use App\Services\Embed\EmbedFrame;
use App\Services\RestHooks\IntegrationsNavigation;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * API e integrações → Widget de assinatura (docs/fase-3/widget-embutido.md §4): as origens
 * exatas que podem hospedar o widget (`integration_settings.allowed_origins`).
 *
 * Flag `embedded_signing` desligada: 404. Só quem tem `manage_integrations` (a mesma regra das
 * chaves e dos webhooks). A trilha da organização registra quem mudou a lista e o resultado.
 */
class EmbedOriginsController extends Controller
{
    public function edit(): Response
    {
        [$organization] = $this->context();

        return Inertia::render('embed/origins', [
            'origins' => AllowedOrigins::forOrganization($organization),
            'max_origins' => AllowedOrigins::maxPerOrganization(),
            'allows_loopback' => ! app()->environment('production'),
            'api_enabled' => ApiFeature::enabled($organization),
            'script_url' => route('embed.script'),
            'protocol_version' => EmbedFrame::PROTOCOL_VERSION,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        [$organization] = $this->context();

        $validated = $request->validate([
            'origins' => ['present', 'array', 'max:'.(AllowedOrigins::maxPerOrganization() * 2)],
            'origins.*' => ['nullable', 'string', 'max:255'],
        ], [
            'origins.max' => sprintf('Cadastre no máximo %d origens.', AllowedOrigins::maxPerOrganization()),
            'origins.*.max' => 'Cada origem pode ter no máximo 255 caracteres.',
        ]);

        $before = AllowedOrigins::forOrganization($organization);
        $origins = AllowedOrigins::replace($organization, array_values((array) $validated['origins']), $request->user());

        if ($before !== $origins) {
            AuditEvent::query()->create([
                'organization_id' => $organization->getKey(),
                'envelope_id' => null,
                'recipient_id' => null,
                'actor_type' => ActorType::User,
                'actor_id' => $request->user()?->getKey(),
                'event_type' => AuditEventType::EmbedOriginsUpdated,
                'payload' => [
                    'added' => array_values(array_diff($origins, $before)),
                    'removed' => array_values(array_diff($before, $origins)),
                    'count' => count($origins),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'correlation_id' => (string) Str::ulid(),
                'occurred_at' => Carbon::now(),
            ]);
        }

        return back()->with('success', $origins === []
            ? 'Lista de origens esvaziada. Nenhum site pode exibir o widget.'
            : 'Origens permitidas atualizadas.');
    }

    /**
     * @return array{0: Organization, 1: Membership}
     */
    private function context(): array
    {
        $organization = CurrentOrganization::instance()->get();
        $membership = CurrentOrganization::instance()->membership();

        abort_unless($organization !== null && EmbedFeature::enabled($organization), 404);
        abort_unless($membership !== null && IntegrationsNavigation::canManage($membership), 403, 'Só quem pode gerenciar a API e as integrações altera as origens do widget.');

        return [$organization, $membership];
    }
}
