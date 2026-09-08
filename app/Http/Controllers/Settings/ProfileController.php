<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Services\Accounts\AccountDeletion;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        $emailChanged = $request->user()->isDirty('email');

        if ($emailChanged) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        // Trocar o e-mail invalida a verificação: reenvia o link para o novo endereço.
        if ($emailChanged) {
            $request->user()->sendEmailVerificationNotification();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Exclui a conta do usuário.
     *
     * As invariantes (último proprietário / autoria de documentos) são checadas ANTES do
     * logout: nada de deslogar e só então falhar por violação de chave estrangeira.
     * Ver App\Services\Accounts\AccountDeletion para a justificativa de cada bloqueio.
     */
    public function destroy(ProfileDeleteRequest $request, AccountDeletion $accountDeletion): RedirectResponse
    {
        $user = $request->user();

        $blockers = $accountDeletion->blockers($user);

        if ($blockers !== []) {
            throw ValidationException::withMessages(['account' => $blockers]);
        }

        Auth::logout();

        $accountDeletion->delete($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
