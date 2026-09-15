<?php

namespace App\Services\Sso;

use App\Integrations\Sso\Saml\SamlAdapter;
use App\Models\SsoConnection;
use App\Models\SsoConsumedAssertion;
use App\Models\SsoSamlRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Fluxo SAML do login corporativo (docs/fase-3/sso.md §7).
 *
 * SP-initiated por padrão: o AuthnRequest emitido fica em `sso_saml_requests` (ID só como
 * hash, validade curta, uso único) e é ligado ao navegador por um cookie próprio — a resposta
 * do IdP chega por POST ENTRE SITES, e o cookie de sessão (SameSite=Lax) não vem junto.
 * InResponseTo desconhecido, vencido, já usado ou vindo de outro navegador é recusado.
 *
 * IdP-initiated (sem InResponseTo) só com `saml_allow_idp_initiated` — desligado por padrão
 * (sem pedido nosso, não há como provar que o navegador pediu o login).
 *
 * Replay: o ID de cada assertion aceita é guardado até NotOnOrAfter + margem
 * (`sso_consumed_assertions`, UNIQUE): a mesma assertion nunca entra duas vezes.
 */
final class SamlLoginFlow
{
    public function __construct(private readonly SamlAdapter $adapter) {}

    /**
     * @return array{url: string, cookie: Cookie}
     *
     * @throws SsoFailure
     */
    public function start(SsoConnection $connection, Request $request, string $mode, ?User $initiator = null): array
    {
        $issued = $this->adapter->loginRequest($connection);
        $binding = Str::random(48);
        $ttl = max(1, (int) config('assinavelox.sso.flow_ttl_minutes', 10));

        SsoSamlRequest::query()->create([
            'sso_connection_id' => $connection->getKey(),
            'request_id_hash' => SsoSamlRequest::hashRequestId($issued['request_id']),
            'browser_binding_hash' => hash('sha256', $binding),
            'mode' => $mode === OidcLoginFlow::MODE_TEST ? OidcLoginFlow::MODE_TEST : OidcLoginFlow::MODE_LOGIN,
            'initiated_by_user_id' => $initiator?->getKey(),
            'expires_at' => Carbon::now()->addMinutes($ttl),
        ]);

        $this->pruneLottery();

        // SameSite=None (com Secure) para o POST do IdP trazer o cookie; em http local (sem TLS)
        // o navegador recusaria None — ali o IdP de teste costuma ser do mesmo site (Lax basta).
        $secure = $request->isSecure() || (bool) config('session.secure', false);
        $cookie = Cookie::create(
            name: (string) config('assinavelox.sso.saml.binding_cookie', 'av_sso_saml'),
            value: $binding,
            expire: Carbon::now()->addMinutes($ttl),
            path: '/sso/saml/',
            domain: null,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: $secure ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_LAX,
        );

        return ['url' => $issued['url'], 'cookie' => $cookie];
    }

    /**
     * Leitura prévia + pedido pendente. Com InResponseTo, o pedido precisa existir para esta
     * conexão, não ter vencido nem sido usado e ter saído deste navegador. Sem InResponseTo, só
     * com IdP-initiated permitido (retorna null).
     *
     * @throws SsoFailure
     */
    public function claim(SsoConnection $connection, Request $request, string $encoded): ?SsoSamlRequest
    {
        $info = $this->adapter->inspect($connection, $encoded);
        $inResponseTo = $info['in_response_to'];

        if ($inResponseTo === null) {
            if (! $connection->saml_allow_idp_initiated) {
                throw new SsoFailure('saml_idp_initiated_disabled');
            }

            return null;
        }

        $pending = SsoSamlRequest::query()
            ->where('sso_connection_id', $connection->getKey())
            ->where('request_id_hash', SsoSamlRequest::hashRequestId($inResponseTo))
            ->first();

        if ($pending === null || $pending->consumed_at !== null) {
            throw new SsoFailure('saml_request_unknown');
        }

        if ($pending->expires_at->isPast()) {
            throw new SsoFailure('flow_expired');
        }

        $cookie = $request->cookie((string) config('assinavelox.sso.saml.binding_cookie', 'av_sso_saml'));

        if (! is_string($cookie) || $cookie === '' || ! hash_equals($pending->browser_binding_hash, hash('sha256', $cookie))) {
            throw new SsoFailure('saml_browser_mismatch');
        }

        // O pedido só é GASTO em consume(), depois de a assinatura e as condições conferirem:
        // uma resposta inválida com o InResponseTo certo não queima o login legítimo seguinte.
        return $pending->setAttribute('request_id', $inResponseTo);
    }

    /**
     * @throws SsoFailure
     */
    public function consume(SsoConnection $connection, string $encoded, ?SsoSamlRequest $pending): VerifiedIdentity
    {
        $requestId = $pending?->getAttribute('request_id');
        $result = $this->adapter->consume($connection, $encoded, is_string($requestId) ? $requestId : null);

        if ($result['not_on_or_after'] === null) {
            // Nem Conditions nem SubjectConfirmationData com NotOnOrAfter: a assertion não vence,
            // e não há prazo pelo qual guardar o ID contra replay (perfil Web SSO §4.1.4.2).
            throw new SsoFailure('saml_invalid');
        }

        if ($pending !== null) {
            // Uso único e atômico: só quem virar a linha de "não usada" para "usada" continua.
            $claimed = DB::table('sso_saml_requests')
                ->where('id', $pending->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => Carbon::now()]);

            if ($claimed !== 1) {
                throw new SsoFailure('saml_request_unknown');
            }
        }

        // Guardado até o MAIOR dos NotOnOrAfter (Conditions e SubjectConfirmationData — o toolkit
        // aceita a assertion enquanto as Conditions valem) + margem.
        $margin = max(1, (int) config('assinavelox.sso.saml.replay_margin_minutes', 10));
        $expires = Carbon::createFromTimestamp($result['not_on_or_after'])->addMinutes($margin);

        if ($expires->lessThan(Carbon::now()->addMinutes($margin))) {
            $expires = Carbon::now()->addMinutes($margin);
        }

        try {
            SsoConsumedAssertion::query()->create([
                'sso_connection_id' => $connection->getKey(),
                'assertion_id_hash' => hash('sha256', 'saml-assertion|'.$result['assertion_id']),
                'expires_at' => $expires,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new SsoFailure('saml_replay');
        }

        return $result['identity'];
    }

    /**
     * Limpeza oportunista (1 em 50) além do `sso:prune` agendado.
     */
    private function pruneLottery(): void
    {
        if (random_int(1, 50) === 1) {
            self::prune();
        }
    }

    /**
     * @return array{requests: int, assertions: int}
     */
    public static function prune(): array
    {
        $now = Carbon::now();

        return [
            'requests' => SsoSamlRequest::query()->where('expires_at', '<', $now->copy()->subDay())->delete(),
            'assertions' => SsoConsumedAssertion::query()->where('expires_at', '<', $now)->delete(),
        ];
    }
}
