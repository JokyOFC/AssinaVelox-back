<?php

namespace App\Services\Sso;

use App\Models\User;
use App\Services\Sso\Console\PruneSsoCommand;
use App\Services\Sso\Domains\SystemTxtRecordResolver;
use App\Services\Sso\Domains\TxtRecordResolver;
use App\Support\Http\DnsResolver;
use App\Support\Http\SystemDnsResolver;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Login corporativo OIDC/SAML (Fase 3 §3.9 — G-SSO, docs/fase-3/sso.md). Com as flags
 * `sso_oidc`/`sso_saml` desligadas (o padrão) nada daqui muda o comportamento: os limitadores
 * só valem nas rotas novas (que dão 404), o listener de `Login` só age com um login por SSO
 * pendente na sessão e o `sso:prune` não encontra nada.
 */
class SsoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(TxtRecordResolver::class, SystemTxtRecordResolver::class);
        $this->app->bindIf(DnsResolver::class, SystemDnsResolver::class);
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->listenToAuthentication();

        if ($this->app->runningInConsole()) {
            $this->commands([PruneSsoCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('sso:prune')->daily()->withoutOverlapping();
        });
    }

    /**
     * Limitadores NOMEADOS (throttle sem nome divide o contador entre rotas).
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('sso-login', function (Request $request): array {
            $email = $request->input('email');
            $email = is_string($email) ? mb_strtolower(trim($email)) : '';
            // Sem e-mail no corpo (SSO obrigatório, já autenticado): o balde é da pessoa, nunca
            // um balde "vazio" dividido por todo mundo.
            $subject = $email !== '' ? 'email:'.$email : 'user:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

            return [
                Limit::perMinute(10)->by('sso-login|'.$request->ip()),
                Limit::perMinute(5)->by('sso-login-subject|'.hash('sha256', $subject)),
            ];
        });

        RateLimiter::for('sso-callback', fn (Request $request) => Limit::perMinute(30)->by('sso-callback|'.$request->ip()));

        RateLimiter::for('sso-settings', fn (Request $request) => Limit::perMinute(30)
            ->by('sso-settings|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * Política `keep` do 2FA: o login por SSO de quem tem 2FA termina no desafio do Fortify. Só
     * a POST do desafio (`two-factor.login.store`), para o MESMO usuário e dentro da validade,
     * promove a sessão a "entrou pelo SSO". Uma tentativa de login por senha descarta o pendente.
     */
    private function listenToAuthentication(): void
    {
        Event::listen(Attempting::class, function (): void {
            try {
                $request = request();

                if ($request->hasSession()) {
                    $request->session()->forget(SsoSession::PENDING_TWO_FACTOR);
                }
            } catch (Throwable) {
                // Sem requisição HTTP (console): nada a limpar.
            }
        });

        Event::listen(Login::class, function (Login $event): void {
            $request = request();

            if (! $request->hasSession()) {
                return;
            }

            // Todo login novo começa sem a dispensa do 2FA pelo IdP; SsoLoginCompleter a marca de
            // novo, depois do Auth::login, só no login por SSO com `trust_idp`.
            SsoSession::clearTwoFactorTrust($request);

            $pending = $request->session()->pull(SsoSession::PENDING_TWO_FACTOR);

            if (! is_array($pending) || $event->guard !== 'web' || ! $event->user instanceof User) {
                return;
            }

            if ((int) ($pending['user_id'] ?? 0) !== (int) $event->user->getKey()
                || (int) ($pending['expires_at'] ?? 0) < Carbon::now()->getTimestamp()
                || ! $request->routeIs('two-factor.login.store')) {
                return;
            }

            app(SsoLoginCompleter::class)->afterTwoFactor($event->user, $pending, $request);
        });
    }
}
