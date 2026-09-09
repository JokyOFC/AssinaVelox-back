<?php

namespace App\Providers;

use App\Services\Envelopes\Contracts\RotatesInvitations;
use App\Services\Envelopes\Sending\EnvelopeNotifications;
use App\Services\Envelopes\Sending\ExpireEnvelopes;
use App\Services\Envelopes\Sending\InvitationDispatcher;
use App\Services\Signing\Contracts\RevalidatesEnvelopeExpiration;
use App\Services\Signing\Contracts\SignerNotifications;
use App\Support\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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

        // Ponto de extensão entre o preparo e o envio: quando o e-mail de um destinatário
        // pendente muda, `RecipientSync` revoga o link antigo e pede a rotação. Sem este
        // binding a interface só avisa o remetente para reenviar à mão.
        $this->app->bind(RotatesInvitations::class, InvitationDispatcher::class);

        // Pontos de extensão entre o fluxo público do signatário e o envio. O fluxo público
        // muda o estado (avança a vez, encerra o envelope) e delega a MENSAGEM à camada de
        // envio, que é quem sabe emitir link e escrever e-mail. Sem estes bindings o
        // signatário continua funcionando: os avisos só ficam registrados no log.
        $this->app->bind(SignerNotifications::class, EnvelopeNotifications::class);
        $this->app->bind(RevalidatesEnvelopeExpiration::class, ExpireEnvelopes::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTrustedProxies();
        $this->configureAbsoluteUrls();
        $this->configureRateLimiting();
    }

    /**
     * Proxies confiáveis (X-Forwarded-*): lista de IPs/CIDRs do balanceador em TRUSTED_PROXIES
     * (config `assinavelox.trusted_proxies`). O IP registrado nos aceites depende disto.
     *
     * Duas travas de segurança:
     *
     *  1. `X-Forwarded-Host` NUNCA é confiado. Ele serviria para montar URLs absolutas a
     *     partir de um cabeçalho que o cliente escolhe (injeção de Host nos links de
     *     redefinição de senha e verificação de e-mail). A raiz das URLs vem de APP_URL
     *     (configureAbsoluteUrls) e esquema/porta continuam vindo de PROTO/PORT.
     *  2. `TRUSTED_PROXIES=*` significa "confie em qualquer origem" e, com isso, o
     *     `X-Forwarded-For` passa a ser escrito pelo próprio cliente — o IP mais à esquerda
     *     da cadeia é o que o Symfony devolve em `request()->ip()`. Isso zeraria todos os
     *     limitadores por IP e tornaria forjável o IP gravado em `signature_acceptances`
     *     e `audit_events`. Por isso, com `*`, o X-Forwarded-For é ignorado e o IP do
     *     cliente volta a ser o do socket. Para registrar o IP real do cliente, configure a
     *     lista de IPs/CIDRs do balanceador (docs/configuracao.md §2.1).
     */
    protected function configureTrustedProxies(): void
    {
        $trustedProxies = trim((string) config('assinavelox.trusted_proxies', ''));

        if ($trustedProxies === '') {
            return;
        }

        $trustAnyOrigin = $trustedProxies === '*' || $trustedProxies === '**';

        TrustProxies::at($trustAnyOrigin ? '*' : array_map('trim', explode(',', $trustedProxies)));
        TrustProxies::withHeaders(
            $trustAnyOrigin
                ? Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO
                : Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
        );
    }

    /**
     * Raiz das URLs absolutas fixada em APP_URL: `route()`/`url()` (e portanto os links de
     * redefinição de senha e de verificação de e-mail) deixam de ser montados a partir do
     * cabeçalho Host da requisição.
     *
     * Em `local` a raiz continua livre — o desenvolvedor navega por host/porta arbitrários
     * (`artisan serve --port=...`, 127.0.0.1) e fixar a raiz quebraria os links da sessão.
     * É a mesma exceção que o TrustHosts do Laravel já faz.
     */
    protected function configureAbsoluteUrls(): void
    {
        $appUrl = trim((string) config('app.url'));

        if ($appUrl === '' || $this->app->environment('local')) {
            return;
        }

        URL::forceRootUrl($appUrl);
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

        /*
        | Página do signatário: dois limites simultâneos.
        |
        | O limite por IP+token freia quem insiste no MESMO convite. Sozinho ele não
        | freia nada: como o token entra na chave, cada palpite cai em um balde novo e
        | uma varredura anônima com tokens diferentes passa sem teto — e cada requisição
        | custa um SHA-256, uma consulta indexada e uma página Inertia de 404. O segundo
        | limite é por IP puro e cobre o VOLUME da origem, como `throttle:public` faz nas
        | demais rotas públicas.
        */
        RateLimiter::for('signer', fn (Request $request) => [
            Limit::perMinute(30)->by('signer|'.$request->ip().'|'.(string) $request->route('token')),
            Limit::perMinute(120)->by('signer-ip|'.$request->ip()),
        ]);

        /*
        | Código por e-mail: também dois limites, por ORIGEM e por LINK.
        |
        | A chave anterior era só o token do convite. O efeito colateral não estava
        | previsto: com a chave no token e não na origem, os limites viravam um recurso
        | COMPARTILHADO entre o signatário legítimo e qualquer pessoa com a URL do
        | convite. Quem tinha só o link gastava as tentativas de verificação com palpites
        | errados e os envios, e a partir daí o signatário — em outro navegador, em outro
        | IP, com o código correto na mão — recebia 429 tanto para verificar quanto para
        | pedir outro código, indefinidamente.
        |
        | Separar por origem não reabre a adivinhação: o teto absoluto por código
        | continua sendo `auth_challenges.max_attempts` (5) mais o
        | `invalidateLiveChallenges()` (um código vivo por vez), que valem mesmo se o
        | atacante trocar de IP. O balde por link continua existindo, com teto alto, para
        | conter uma varredura distribuída sobre um mesmo convite.
        */
        RateLimiter::for('otp-send', fn (Request $request) => [
            Limit::perMinutes(10, 3)->by('otp-send|'.(string) $request->route('token').'|'.$request->ip()),
            Limit::perMinutes(10, 15)->by('otp-send|'.(string) $request->route('token')),
        ]);
        RateLimiter::for('otp-verify', fn (Request $request) => [
            Limit::perMinutes(10, 5)->by('otp-verify|'.(string) $request->route('token').'|'.$request->ip()),
            Limit::perMinutes(10, 25)->by('otp-verify|'.(string) $request->route('token')),
        ]);

        // Busca global ⌘K: 120/min por usuário.
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(120)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Webhook do Mercado Pago: 300/min por IP.
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
    }
}
