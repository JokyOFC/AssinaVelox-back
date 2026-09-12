<?php

namespace App\Http\Middleware;

use App\Models\Envelope;
use App\Models\Template;
use App\Services\Api\ApiContext;
use App\Services\Api\ApiEnvelopeAccess;
use App\Services\Api\Exceptions\ApiProblemException;
use App\Services\Api\IdempotencyStore;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * `api.idempotent` (obrigatório) ou `api.idempotent:optional` — cabeçalho `Idempotency-Key`
 * nas criações e no envio (docs/fase-2/api-v1.md §7; App\Services\Api\IdempotencyStore).
 *
 *  - ausente onde é obrigatório → 400 `idempotency-key-missing`;
 *  - formato inválido (1–255 caracteres ASCII visíveis) → 400 `idempotency-key-invalid`;
 *  - repetição com o mesmo pedido → a mesma resposta, com `Idempotent-Replayed: true`;
 *  - mesma chave com outro pedido → 409 `idempotency-key-reused`;
 *  - mesma chave ainda em processamento → 409 `idempotency-request-in-progress` + `Retry-After`.
 */
class ApiIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public function __construct(private readonly IdempotencyStore $store) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $key = $request->headers->get(self::HEADER);

        if ($key === null || $key === '') {
            if ($mode === 'optional') {
                return $next($request);
            }

            throw new ApiProblemException(
                400,
                'idempotency-key-missing',
                'Cabeçalho Idempotency-Key obrigatório',
                'Envie um cabeçalho "Idempotency-Key" com um valor único (ex.: um UUID) em cada criação ou envio. Repetir a mesma chave com o mesmo corpo devolve a mesma resposta.',
            );
        }

        if (preg_match('/^[\x21-\x7E]{1,255}$/', $key) !== 1) {
            throw new ApiProblemException(
                400,
                'idempotency-key-invalid',
                'Idempotency-Key inválida',
                'A chave deve ter de 1 a 255 caracteres ASCII visíveis, sem espaços.',
            );
        }

        $token = ApiContext::token($request) ?? throw new AuthenticationException('Unauthenticated.');
        $route = (string) ($request->route()?->getName() ?? $request->path());

        $reservation = $this->store->reserve(
            $token,
            $key,
            IdempotencyStore::fingerprint($request),
            $request->getMethod(),
            $route,
        );

        if ($reservation['state'] === IdempotencyStore::REPLAY && $reservation['id'] !== null) {
            $replay = $this->store->replay($reservation['id']);

            if ($replay !== null) {
                // A resposta guardada NÃO passa por cima da autorização atual: o criador pode ter
                // perdido a visibilidade do documento (rebaixamento, pasta) desde a primeira vez.
                $this->reauthorize($request, $replay);

                return $replay;
            }
        }

        if ($reservation['state'] === IdempotencyStore::MISMATCH) {
            throw ApiProblemException::conflict(
                'idempotency-key-reused',
                'Esta Idempotency-Key já foi usada com outro pedido (corpo, arquivo ou recurso diferentes). Use uma chave nova para um pedido novo.',
                [],
                'Idempotency-Key reutilizada',
            );
        }

        if ($reservation['state'] !== IdempotencyStore::RESERVED || $reservation['id'] === null) {
            throw new ApiProblemException(
                409,
                'idempotency-request-in-progress',
                'Pedido com esta chave ainda em processamento',
                'Outra requisição com a mesma Idempotency-Key ainda está sendo processada. Tente de novo em instantes com a mesma chave.',
                [],
                ['Retry-After' => 1],
            );
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->store->release($reservation['id']);

            throw $exception;
        }

        $this->store->complete($reservation['id'], $response);

        return $response;
    }

    /**
     * Antes de repetir uma resposta guardada, confere de novo o que o controller conferiria:
     * o documento da rota (`{envelope}`) e o documento que a resposta descreve precisam continuar
     * visíveis para o criador do token (404 como qualquer recurso invisível — §6), e o modelo da
     * rota (`{template}`) continua sujeito à TemplatePolicy.
     */
    private function reauthorize(Request $request, Response $replay): void
    {
        $route = $request->route();
        $bound = $route?->parameter('envelope');

        if ($bound !== null) {
            ApiEnvelopeAccess::ensureVisible($this->envelope($bound));
        }

        $template = $route?->parameter('template');

        if ($template !== null) {
            $model = match (true) {
                $template instanceof Template => $template,
                is_string($template) => Template::query()->where('ulid', $template)->first(),
                default => null,
            };

            if ($model === null || ! Gate::allows('view', $model)) {
                throw new NotFoundHttpException;
            }
        }

        $body = json_decode((string) $replay->getContent(), true);
        $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : null;

        if ($data !== null && ($data['object'] ?? null) === 'envelope' && is_string($data['id'] ?? null)) {
            ApiEnvelopeAccess::ensureVisible($this->envelope($data['id']));
        }
    }

    private function envelope(mixed $value): Envelope
    {
        // Escopo global da organização do token (CurrentOrganization): outra organização → 404.
        $envelope = match (true) {
            $value instanceof Envelope => $value,
            is_string($value) => Envelope::query()->where('ulid', $value)->first(),
            default => null,
        };

        return $envelope ?? throw new NotFoundHttpException;
    }
}
