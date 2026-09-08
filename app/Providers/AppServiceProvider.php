<?php

namespace App\Providers;

use App\Support\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentOrganization::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTrustedProxies();
        $this->configureRateLimiting();
    }

    /**
     * Proxies confiáveis (X-Forwarded-*): "*" ou lista de IPs/CIDRs em TRUSTED_PROXIES
     * (config `assinavelox.trusted_proxies`). O IP registrado nos aceites depende disto.
     */
    protected function configureTrustedProxies(): void
    {
        $trustedProxies = trim((string) config('assinavelox.trusted_proxies', ''));

        if ($trustedProxies === '') {
            return;
        }

        TrustProxies::at($trustedProxies === '*' ? '*' : array_map('trim', explode(',', $trustedProxies)));
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO,
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Limitadores das rotas públicas (ROUTES_AND_PAGES §0.2 e §1.3/1.4).
     */
    protected function configureRateLimiting(): void
    {
        // Verificação pública, páginas legais, home: 60/min por IP.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Página do signatário: 30/min por IP+token.
        RateLimiter::for('signer', fn (Request $request) => Limit::perMinute(30)
            ->by($request->ip().'|'.(string) $request->route('token')));

        // Envio/reenvio de OTP: 3 a cada 10 min por link; verificação: 5 a cada 10 min por link.
        RateLimiter::for('otp-send', fn (Request $request) => Limit::perMinutes(10, 3)
            ->by('otp-send|'.(string) $request->route('token').'|'.$request->ip()));
        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinutes(10, 5)
            ->by('otp-verify|'.(string) $request->route('token').'|'.$request->ip()));

        // Busca global ⌘K: 120/min por usuário.
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(120)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Webhook do Mercado Pago: 300/min por IP.
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
    }
}
