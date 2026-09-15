<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEmbeddedSigningSessionRequest;
use App\Http\Resources\Api\V1\EmbeddedSigningSessionResource;
use App\Models\EmbeddedSigningSession;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Api\ApiContext;
use App\Services\Api\ApiEnvelopeAccess;
use App\Services\Api\Exceptions\ApiProblemException;
use App\Services\Api\IdempotencyStore;
use App\Services\Embed\EmbeddedSessionIssuer;
use App\Services\Embed\EmbeddedSessionRevoker;
use App\Services\Embed\EmbedFeature;
use App\Services\Embed\EmbedRejected;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sessões de assinatura embutida — API v1 (Fase 3 §3.9, docs/fase-3/widget-embutido.md §2).
 *
 * Uma sessão dá a UM participante, dentro do site de UMA origem cadastrada, acesso ao próprio
 * documento pelo widget (`embed.js`). As regras de autenticação do participante continuam as
 * mesmas (código e, se houver, PIN): a API não assina por ninguém.
 *
 * Ability: `embedded_signing:manage` (o criador precisa de `send_envelopes`). Flag
 * `embedded_signing` desligada: 404.
 */
class EmbeddedSigningSessionController extends Controller
{
    public function __construct(
        private readonly EmbeddedSessionIssuer $issuer,
        private readonly EmbeddedSessionRevoker $revoker,
    ) {}

    /**
     * Criar sessão de assinatura embutida
     *
     * Devolve `url` — endereço de uso único para o iframe (`AssinaVelox.mount({ url })`), com o
     * token no fragmento `#t=`. Ela aparece SÓ nesta resposta e vale `expires_in` segundos
     * (padrão 300, máximo 900) até ser aberta. `origin` é a origem exata do site que vai
     * hospedar o widget e precisa estar cadastrada em API e integrações → Widget de assinatura.
     *
     * Exige `Idempotency-Key`. Repetir o pedido com a mesma chave devolve a MESMA sessão com uma
     * `url` nova (a anterior deixa de valer) enquanto ela não foi aberta; depois de aberta,
     * `409 embedded-session-closed`.
     *
     * Erros próprios: `409 invalid-status` (documento fora de andamento), `recipient-not-signable`
     * (visualizador), `recipient-not-invited`, `recipient-not-active` (fora da vez ou já
     * respondeu), `embedded-unsupported` (foto ou vídeo exigidos), `too-many-sessions`;
     * `422 validation-failed` com `errors.origin` (origem inválida ou não cadastrada).
     */
    public function store(StoreEmbeddedSigningSessionRequest $request, Envelope $envelope, string $recipient): Response
    {
        $this->ensureEnabled();
        ApiEnvelopeAccess::ensureVisible($envelope);
        $recipient = $this->recipientOf($envelope, $recipient);

        try {
            $result = $this->issuer->issue(
                $envelope,
                $recipient,
                $request->origin(),
                $request->expiresIn(),
                ApiContext::token($request),
                $request->user(),
                $request->header('Idempotency-Key'),
                IdempotencyStore::fingerprint($request),
            );
        } catch (EmbedRejected $exception) {
            throw self::problem($exception);
        }

        $session = $result['session'];
        $body = ['data' => (new EmbeddedSigningSessionResource($session))->withUrl($result['url'])->resolve($request)];

        // Resposta HTTP simples, não `JsonResponse`: o armazenamento de `Idempotency-Key` guarda o
        // corpo de respostas JSON por 24 h e a URL (com o token) não pode ficar gravada em claro.
        // A repetição com a mesma chave é resolvida pelo serviço (mesma sessão, URL nova).
        $headers = [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
            'Location' => route('api.v1.envelopes.recipients.embedded_sessions.show', [
                'envelope' => $envelope->ulid,
                'recipient' => $recipient->ulid,
                'embeddedSession' => $session->ulid,
            ]),
        ];

        if ($result['replayed']) {
            $headers['Idempotent-Replayed'] = 'true';
        }

        return new Response(
            (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            201,
            $headers,
        );
    }

    /**
     * Situação da sessão embutida
     *
     * `status`: `pending` (URL ainda não aberta), `active`, `completed`, `refused`, `expired`,
     * `revoked` ou `closed` (a pessoa já respondeu por outro caminho, como o link do e-mail, ou foi
     * encerrada). O desfecho registrado pelo widget nunca é trocado por `revoked`. A `url` nunca
     * volta.
     */
    public function show(Request $request, Envelope $envelope, string $recipient, string $embeddedSession): EmbeddedSigningSessionResource
    {
        $this->ensureEnabled();
        ApiEnvelopeAccess::ensureVisible($envelope);
        $session = $this->sessionOf($this->recipientOf($envelope, $recipient), $embeddedSession);

        return new EmbeddedSigningSessionResource($session);
    }

    /**
     * Revogar sessão embutida
     *
     * Encerra a sessão (a URL e o widget aberto deixam de valer, inclusive a sessão de
     * assinatura já confirmada por código). Idempotente. Um aceite já registrado nunca é
     * desfeito.
     */
    public function destroy(Request $request, Envelope $envelope, string $recipient, string $embeddedSession): EmbeddedSigningSessionResource
    {
        $this->ensureEnabled();
        ApiEnvelopeAccess::ensureVisible($envelope);
        $session = $this->sessionOf($this->recipientOf($envelope, $recipient), $embeddedSession);

        $this->revoker->revoke($session, 'api');

        return new EmbeddedSigningSessionResource($session->refresh());
    }

    private function ensureEnabled(): void
    {
        if (! EmbedFeature::enabled(ApiContext::organization())) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * Participante DESTE envelope, pelo ULID (escopo da organização do token). Resolvido aqui, e
     * não por binding implícito, para a ability da rota ser conferida antes de qualquer consulta.
     */
    private function recipientOf(Envelope $envelope, string $ulid): Recipient
    {
        /** @var Recipient|null $recipient */
        $recipient = Recipient::query()
            ->where('envelope_id', $envelope->getKey())
            ->where('ulid', $ulid)
            ->first();

        return $recipient ?? throw new NotFoundHttpException;
    }

    private function sessionOf(Recipient $recipient, string $ulid): EmbeddedSigningSession
    {
        /** @var EmbeddedSigningSession|null $session */
        $session = EmbeddedSigningSession::query()
            ->where('recipient_id', $recipient->getKey())
            ->where('ulid', $ulid)
            ->first();

        return $session ?? throw new NotFoundHttpException;
    }

    private static function problem(EmbedRejected $exception): ApiProblemException
    {
        $title = match ($exception->status) {
            404 => 'Recurso não encontrado',
            410 => 'Recurso encerrado',
            default => 'Conflito com o estado atual',
        };

        return new ApiProblemException($exception->status, $exception->slug, $title, $exception->getMessage(), $exception->extensions);
    }
}
