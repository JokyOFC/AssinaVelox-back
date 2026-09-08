<?php

namespace App\Http\Controllers\Members;

use App\Enums\InvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCurrentOrganization;
use App\Models\MembershipInvitation;
use App\Services\Organizations\Invitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aceite de convite (ROUTES §2.11): GET mostra estados valid|expired|revoked|accepted ×
 * guest|same_user|other_user; POST cria a membership (usuário autenticado com o mesmo e-mail).
 */
class InvitationAcceptController extends Controller
{
    public function __construct(protected Invitations $invitations) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = Invitations::findByToken($token);
        $user = $request->user();

        if ($invitation !== null && $invitation->isPending() && $user === null) {
            $request->session()->put(Invitations::PENDING_SESSION_KEY, $token);
        }

        return Inertia::render('invitations/accept', [
            'token' => $token,
            'invitation' => $invitation ? [
                'organization_name' => $invitation->organization->name,
                'organization_initials' => $invitation->organization->initials,
                'role_label' => $invitation->role->label(),
                'email' => $invitation->email,
                'invited_by' => $invitation->inviter->name ?? 'AssinaVelox',
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ] : null,
            'state' => $this->state($invitation),
            'auth_state' => $this->authState($request, $invitation),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = Invitations::findByToken($token);
        $state = $this->state($invitation);

        if ($state !== 'valid' || $invitation === null) {
            return redirect()->route('invitations.accept', ['token' => $token]);
        }

        $user = $request->user();

        if ($user === null) {
            $request->session()->put(Invitations::PENDING_SESSION_KEY, $token);

            return redirect()->route('register', ['invitation' => $token]);
        }

        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            return back()->with('error', 'Este convite foi enviado para '.$invitation->email.'. Saia e entre com esse e-mail para aceitá-lo.');
        }

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice')
                ->with('info', 'Confirme seu e-mail para aceitar o convite.');
        }

        $membership = $this->invitations->accept($invitation, $user);

        $request->session()->forget(Invitations::PENDING_SESSION_KEY);
        $request->session()->put(EnsureCurrentOrganization::SESSION_KEY, $membership->organization_id);

        return redirect()->route('dashboard')
            ->with('success', 'Você entrou em '.$invitation->organization->name.'.');
    }

    /**
     * @return 'valid'|'expired'|'revoked'|'accepted'
     */
    protected function state(?MembershipInvitation $invitation): string
    {
        if ($invitation === null) {
            // Token desconhecido: tratado como revogado (não revela existência).
            return 'revoked';
        }

        return match ($invitation->resolveStatus()) {
            InvitationStatus::Pending => 'valid',
            InvitationStatus::Accepted => 'accepted',
            InvitationStatus::Expired => 'expired',
            InvitationStatus::Revoked => 'revoked',
        };
    }

    /**
     * @return 'guest'|'same_user'|'other_user'
     */
    protected function authState(Request $request, ?MembershipInvitation $invitation): string
    {
        $user = $request->user();

        if ($user === null) {
            return 'guest';
        }

        if ($invitation !== null && Str::lower($user->email) === Str::lower($invitation->email)) {
            return 'same_user';
        }

        return 'other_user';
    }
}
