<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSigningSettingsRequest;
use App\Services\Envelopes\Reminders\ReminderSettings;
use App\Services\Envelopes\Reminders\RemindersFeature;
use App\Services\Envelopes\Reminders\ReminderWindow;
use App\Support\CurrentOrganization;
use App\Support\OrganizationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › Padrões de assinatura (ROUTES §2.13). Persistido em organizations.settings.
 *
 * Fase 2 §2.5: o controle "Lembretes automáticos" só é real quando a flag
 * `features.reminders` está ligada para a organização (`phase2.reminders = true`). Desligada,
 * a tela continua como na Fase 1 — controle desabilitado com o selo "Fase 2" — e o que
 * vier em `reminders` no PATCH é ignorado.
 */
class SigningController extends Controller
{
    public function edit(Request $request, RemindersFeature $feature): Response
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('updateSettings', $organization);

        $settings = OrganizationSettings::of($organization);

        return Inertia::render('settings/signing', [
            'defaults' => [
                'expires_in_days' => $settings->defaultExpirationDays(),
                'signing_order' => $settings->defaultSigningOrder()->value,
                'initials_on_all_pages' => $settings->initialsOnAllPages(),
                'allow_drawn_signature' => true,
                'allow_typed_signature' => $settings->allowTypedSignature(),
                'allow_uploaded_signature' => $settings->allowUploadedSignature(),
            ],
            // Padrão de lembretes da organização (cadência + janela de horário, no fuso da
            // organização). Sempre presente; só editável com `phase2.reminders = true`.
            'reminders' => [
                ...ReminderSettings::forOrganization($organization)->toArray(),
                ...ReminderWindow::forOrganization($organization)->toArray(),
                'timezone' => $organization->timezone,
            ],
            'limits' => [
                'expires_in_days' => [
                    'min' => (int) config('assinavelox.expiration_days.min', 1),
                    'max' => (int) config('assinavelox.expiration_days.max', 90),
                ],
                'reminders' => ReminderSettings::LIMITS,
            ],
            'phase2' => [
                'reminders' => $feature->enabledFor($organization),
                'auth_methods' => ['email_otp'],
                'channels' => ['email'],
            ],
        ]);
    }

    public function update(UpdateSigningSettingsRequest $request, RemindersFeature $feature): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();

        $values = [
            'default_expiration_days' => (int) $request->validated('expires_in_days'),
            'default_signing_order' => $request->validated('signing_order'),
            'initials_on_all_pages' => (bool) $request->validated('initials_on_all_pages'),
            'allow_typed_signature' => (bool) $request->validated('allow_typed_signature'),
            'allow_uploaded_signature' => (bool) $request->validated('allow_uploaded_signature'),
        ];

        $reminders = $request->validated('reminders');

        if ($feature->enabledFor($organization) && is_array($reminders)) {
            $values['reminders'] = [
                ...ReminderSettings::fromArray($reminders)->toArray(),
                'window_start_hour' => (int) $reminders['window_start_hour'],
                'window_end_hour' => (int) $reminders['window_end_hour'],
            ];
        }

        OrganizationSettings::of($organization)->put($values);

        return back()->with('success', 'Padrões de assinatura salvos.');
    }
}
