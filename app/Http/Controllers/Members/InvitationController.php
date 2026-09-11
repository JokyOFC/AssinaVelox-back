<?php

namespace App\Http\Controllers\Members;

use App\Http\Controllers\Controller;
use App\Http\Requests\Members\StoreInvitationRequest;
use App\Models\MembershipInvitation;
use App\Services\Organizations\Invitations;
use App\Services\Organizations\SeatUsage;
use App\Support\CurrentOrganization;
use App\Support\PermissionsInvitationGrants;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Convites de membros (ROUTES §2.10): emitir (token 32 bytes → digest, expira em
 * `assinavelox.invitations.expires_in_days`), reenviar (novo token) e revogar.
 */
class InvitationController extends Controller
{
    public function __construct(protected Invitations $invitations) {}

    public function store(StoreInvitationRequest $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        $emails = $request->emails();

        if (! SeatUsage::hasAvailable($organization, count($emails))) {
            throw ValidationException::withMessages([
                'seats' => SeatUsage::unavailableMessage($organization),
            ]);
        }

        $customRole = $request->customRole();
        $folders = $request->folderGrants();

        foreach ($emails as $email) {
            $invitation = $this->invitations->invite($organization, $request->user(), $email, $request->role());

            // Fase 2: função personalizada e "Pastas com acesso" ficam no convite e são
            // aplicadas no aceite (PermissionsInvitationGrants::apply).
            if ($customRole !== null || $folders !== []) {
                PermissionsInvitationGrants::store($invitation, $customRole, $folders);
            }
        }

        $count = count($emails);

        return back()->with('success', $count === 1
            ? 'Convite enviado para '.$emails[0].'.'
            : "Convite enviado para {$count} e-mails.");
    }

    public function resend(Request $request, MembershipInvitation $invitation): RedirectResponse
    {
        Gate::authorize('resend', $invitation);

        if ($invitation->accepted_at !== null) {
            return back()->with('error', 'Este convite já foi aceito.');
        }

        // Convite revogado não ressuscita: emita um novo convite para o mesmo e-mail.
        if ($invitation->revoked_at !== null) {
            return back()->with('error', 'Este convite foi cancelado e não pode ser reenviado. Envie um novo convite para '.$invitation->email.'.');
        }

        $organization = CurrentOrganization::instance()->get();

        // Reenviar um convite expirado volta a ocupar um assento — precisa haver assento livre.
        if (! SeatUsage::hasAvailableForResend($organization, $invitation)) {
            return back()->with('error', SeatUsage::unavailableMessage($organization));
        }

        $this->invitations->resend($invitation);

        return back()->with('success', 'Convite reenviado para '.$invitation->email.'.');
    }

    public function destroy(Request $request, MembershipInvitation $invitation): RedirectResponse
    {
        Gate::authorize('delete', $invitation);

        if ($invitation->accepted_at !== null) {
            return back()->with('error', 'Este convite já foi aceito; remova o membro na lista de usuários.');
        }

        $this->invitations->revoke($invitation);

        return back()->with('success', 'Convite para '.$invitation->email.' revogado.');
    }
}
