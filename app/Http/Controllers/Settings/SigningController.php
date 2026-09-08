<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSigningSettingsRequest;
use App\Support\CurrentOrganization;
use App\Support\OrganizationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › Padrões de assinatura (ROUTES §2.13). Persistido em organizations.settings.
 */
class SigningController extends Controller
{
    public function edit(Request $request): Response
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
            'limits' => [
                'expires_in_days' => [
                    'min' => (int) config('assinavelox.expiration_days.min', 1),
                    'max' => (int) config('assinavelox.expiration_days.max', 90),
                ],
            ],
            'phase2' => [
                'reminders' => false,
                'auth_methods' => ['email_otp'],
                'channels' => ['email'],
            ],
        ]);
    }

    public function update(UpdateSigningSettingsRequest $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();

        OrganizationSettings::of($organization)->put([
            'default_expiration_days' => (int) $request->validated('expires_in_days'),
            'default_signing_order' => $request->validated('signing_order'),
            'initials_on_all_pages' => (bool) $request->validated('initials_on_all_pages'),
            'allow_typed_signature' => (bool) $request->validated('allow_typed_signature'),
            'allow_uploaded_signature' => (bool) $request->validated('allow_uploaded_signature'),
        ]);

        return back()->with('success', 'Padrões de assinatura salvos.');
    }
}
