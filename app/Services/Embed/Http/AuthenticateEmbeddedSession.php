<?php

namespace App\Services\Embed\Http;

use App\Http\Middleware\ResolveSignerToken;
use App\Models\EmbeddedSigningSession;
use App\Models\Organization;
use App\Models\Recipient;
use App\Services\Embed\AllowedOrigins;
use App\Services\Embed\EmbeddedContextResolver;
use App\Services\Embed\EmbedFeature;
use App\Services\Embed\EmbedResponses;
use App\Services\Embed\EmbedSessionStore;
use App\Services\Signing\SignerTokens;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica as rotas do widget pelo token de execução (docs/fase-3/widget-embutido.md §3.3).
 *
 * `Authorization: Bearer {token}` — o token vive só na memória do widget; nenhum cookie é lido
 * ou escrito (as rotas `/embed/*` ficam FORA do grupo `web`: sem sessão do app, sem CSRF — e
 * CSRF não se aplica, porque a credencial não é enviada sozinha pelo navegador).
 *
 * Em ordem: flag global (404) → sessão pelo ULID da rota (404) → flag do plano (404) → token
 * (401) → revogada (410) → token de execução vencido (401) → origem ainda cadastrada (410).
 * Passando, a requisição recebe o SignerContext do participante (`ResolveSignerToken::ATTRIBUTE`,
 * o mesmo atributo que os serviços do fluxo público leem) — ou nenhum, quando o convite não abre
 * mais — e a sessão Laravel isolada ({@see EmbedSessionStore}).
 */
final class AuthenticateEmbeddedSession
{
    public const ATTRIBUTE = 'assinavelox.embed.session';

    public function __construct(
        private readonly EmbeddedContextResolver $contexts,
        private readonly EmbedSessionStore $store,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! EmbedFeature::globallyEnabled()) {
            return EmbedResponses::notFound();
        }

        $ulid = (string) $request->route('session');

        /** @var EmbeddedSigningSession|null $embedded */
        $embedded = EmbeddedSigningSession::withoutGlobalScopes()->where('ulid', $ulid)->first();

        if ($embedded === null) {
            return EmbedResponses::notFound();
        }

        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($embedded->organization_id)->first();

        if ($organization === null || ! EmbedFeature::enabled($organization)) {
            return EmbedResponses::notFound();
        }

        $raw = $request->bearerToken();

        if (! is_string($raw) || $raw === '' || ! SignerTokens::matches($embedded->runtime_token_digest, $raw)) {
            return EmbedResponses::error('unauthenticated', 'Sessão inválida. Feche e abra o documento de novo pelo site em que você está.', 401);
        }

        if ($embedded->isRevoked()) {
            return EmbedResponses::error('session_revoked', 'Este acesso foi encerrado. Peça um novo acesso no site em que você está.', 410);
        }

        if (! $embedded->hasLiveRuntime()) {
            return EmbedResponses::error('session_expired', 'Sua sessão terminou. Peça um novo acesso no site em que você está.', 401);
        }

        if (! AllowedOrigins::allows($organization, $embedded->allowed_origin)) {
            return EmbedResponses::error('origin_removed', 'Este site não está mais autorizado a exibir o documento.', 410);
        }

        $context = $this->contexts->forSession($embedded);
        $recipient = $context->recipient
            ?? Recipient::withoutOrganizationScope()->whereKey($embedded->recipient_id)->first();

        if ($recipient === null) {
            return EmbedResponses::notFound();
        }

        $request->attributes->set(self::ATTRIBUTE, $embedded);

        if ($context !== null) {
            $request->attributes->set(ResolveSignerToken::ATTRIBUTE, $context);
        }

        $store = $this->store->attach($request, $embedded, $recipient);

        $response = $next($request);

        $this->store->persist($store, $embedded, $recipient);

        if ($embedded->last_seen_at === null || $embedded->last_seen_at->lt(Carbon::now()->subMinute())) {
            $embedded->forceFill(['last_seen_at' => Carbon::now()])->save();
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public static function session(Request $request): EmbeddedSigningSession
    {
        $session = $request->attributes->get(self::ATTRIBUTE);

        abort_unless($session instanceof EmbeddedSigningSession, 404);

        return $session;
    }
}
