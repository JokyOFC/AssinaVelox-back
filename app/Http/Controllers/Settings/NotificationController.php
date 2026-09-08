<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNotificationPreferencesRequest;
use App\Services\Organizations\NotificationPreferences;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › Notificações (ROUTES §2.14): preferências POR USUÁRIO na organização
 * (memberships.notification_preferences). Qualquer membro ativo acessa.
 */
class NotificationController extends Controller
{
    public function __construct(protected NotificationPreferences $preferences) {}

    public function edit(Request $request): Response
    {
        $current = CurrentOrganization::instance();
        $membership = $current->membership();
        $organization = $current->get();

        return Inertia::render('settings/notifications', [
            'events' => $this->preferences->rows($membership),
            'digest_time_label' => '08:00 ('.$organization->timezone.')',
        ]);
    }

    public function update(UpdateNotificationPreferencesRequest $request): RedirectResponse
    {
        $membership = CurrentOrganization::instance()->membership();

        $this->preferences->save($membership, $request->preferences());

        return back()->with('success', 'Preferências salvas.');
    }
}
