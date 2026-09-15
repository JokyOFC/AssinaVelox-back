<?php

namespace App\Services\Embed;

use App\Services\Signing\Exceptions\SigningRejectedException;
use Illuminate\Http\JsonResponse;

/**
 * Respostas JSON das rotas `/embed/*` (docs/fase-3/widget-embutido.md §6): `{code, message}`
 * com mensagem PT-BR escrita pela aplicação. Nunca token, e-mail, IP ou detalhe interno.
 * `Cache-Control: no-store` em todas — nada do widget fica em cache.
 */
final class EmbedResponses
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public static function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message] + $extra, $status, [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public static function rejected(EmbedRejected $exception): JsonResponse
    {
        return self::error($exception->slug, $exception->getMessage(), $exception->status);
    }

    public static function signing(SigningRejectedException $exception): JsonResponse
    {
        $extra = [];
        $left = $exception->context['attempts_left'] ?? null;

        if (is_int($left)) {
            $extra['attempts_left'] = $left;
        }

        if ($exception->retryAfterSeconds !== null) {
            $extra['retry_after'] = $exception->retryAfterSeconds;
        }

        $response = self::error($exception->errorCode, $exception->getMessage(), $exception->status, $extra);

        if ($exception->retryAfterSeconds !== null) {
            $response->headers->set('Retry-After', (string) $exception->retryAfterSeconds);
        }

        return $response;
    }

    public static function notFound(): JsonResponse
    {
        return self::error('not_found', 'Este acesso não existe ou não está disponível.', 404);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function state(array $state, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $message,
            'state' => $state,
        ], static fn ($value): bool => $value !== null), $status, [
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
