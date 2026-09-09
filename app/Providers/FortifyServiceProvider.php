<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Services\Organizations\Invitations;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(function (Request $request) {
            $invitation = Invitations::findByToken($request->query('invitation'));
            $pending = $invitation !== null && $invitation->isPending();

            if ($pending) {
                $request->session()->put(Invitations::PENDING_SESSION_KEY, (string) $request->query('invitation'));
            }

            return Inertia::render('auth/register', [
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
                'termsVersion' => (string) config('assinavelox.terms_version'),
                'invitation' => $pending ? [
                    'token' => (string) $request->query('invitation'),
                    'email' => $invitation->email,
                    'organization_name' => $invitation->organization->name,
                ] : null,
            ]);
        });

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        /*
         * Chave composta: e-mail informado + IP de origem. É o que impede que um atacante
         * tranque a conta de outra pessoa só repetindo o formulário com o e-mail dela —
         * ele gasta o próprio balde, e a vítima, vindo de outro IP, continua entrando.
         *
         * O `is_string` não é zelo excessivo: o limitador roda ANTES da validação do
         * Fortify, com o input cru. `email[]=a@b.c` faria `Str::lower()` receber um array
         * e a requisição terminaria em 500 — um erro de servidor ao alcance de qualquer
         * visitante. Entrada que não é string vira balde vazio e segue para a validação,
         * que a recusa como deve.
         */
        RateLimiter::for('login', function (Request $request) {
            $username = $request->input(Fortify::username());
            $username = is_string($username) ? $username : '';

            return Limit::perMinute(5)->by(
                Str::transliterate(Str::lower($username).'|'.$request->ip()),
            );
        });
    }
}
