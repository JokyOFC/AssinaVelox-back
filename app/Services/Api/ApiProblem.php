<?php

namespace App\Services\Api;

use App\Services\Api\Exceptions\ApiProblemException;
use App\Support\Correlation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Contrato de erros da API v1: RFC 9457 (`application/problem+json`) — docs/fase-2/api-v1.md §6.
 *
 * Toda resposta de erro tem a MESMA forma, qualquer que seja o status:
 *
 *  - `type`: URN estável e não resolvível (`urn:assinavelox:problem:{slug}`) — trate como string;
 *  - `title`: resumo curto em PT-BR, fixo por tipo;
 *  - `status`: o status HTTP;
 *  - `detail`: explicação desta ocorrência, só quando escrita pela aplicação (nunca a mensagem
 *    do framework, que pode revelar nome de classe ou de tabela);
 *  - `instance`: o caminho pedido, sem query string;
 *  - `errors`: só no 422, `{campo: [mensagens]}`;
 *  - `correlation_id`: o mesmo do cabeçalho `X-Correlation-Id`, para o suporte.
 *
 * Um 500 nunca carrega stack, mensagem da exceção nem dado nenhum além do correlation_id.
 */
final class ApiProblem
{
    public const CONTENT_TYPE = 'application/problem+json';

    public const TYPE_PREFIX = 'urn:assinavelox:problem:';

    /**
     * Mensagens do framework (inglês, às vezes com nome de model/rota) nunca viram `detail`.
     */
    private const FRAMEWORK_PREFIXES = [
        'The route ',
        'No query results for model',
        'The GET method is not supported',
        'The POST method is not supported',
        'The PUT method is not supported',
        'The PATCH method is not supported',
        'The DELETE method is not supported',
        'This action is unauthorized',
        'Unauthenticated',
        'Too Many Attempts',
        'Server Error',
        'Not Found',
        'Forbidden',
        'Service Unavailable',
        'The POST data is too large',
        'Unable to ',
    ];

    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string|int|list<string>>  $headers
     */
    public static function make(
        int $status,
        string $slug,
        string $title,
        ?string $detail = null,
        array $extensions = [],
        array $headers = [],
    ): JsonResponse {
        $body = [
            'type' => self::TYPE_PREFIX.$slug,
            'title' => $title,
            'status' => $status,
        ];

        if ($detail !== null && trim($detail) !== '') {
            $body['detail'] = $detail;
        }

        $body['instance'] = '/'.ltrim(request()->path(), '/');

        foreach ($extensions as $key => $value) {
            if (! array_key_exists($key, $body)) {
                $body[$key] = $value;
            }
        }

        $body['correlation_id'] = Correlation::id();

        $response = new JsonResponse($body, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($headers as $name => $value) {
            $response->headers->set($name, is_array($value) ? $value : (string) $value);
        }

        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public static function fromThrowable(Throwable $exception): Response
    {
        return match (true) {
            $exception instanceof ApiProblemException => self::make(
                $exception->getStatusCode(),
                $exception->slug,
                $exception->title,
                $exception->getMessage(),
                $exception->extensions,
                self::headersOf($exception),
            ),
            $exception instanceof HttpResponseException => $exception->getResponse(),
            $exception instanceof ValidationException => self::make(
                422,
                'validation-failed',
                'Dados inválidos',
                'Um ou mais campos não passaram na validação.',
                ['errors' => $exception->errors()],
            ),
            $exception instanceof AuthenticationException => self::make(
                401,
                'unauthenticated',
                'Autenticação necessária',
                'Envie um token de API válido no cabeçalho "Authorization: Bearer". O token pode estar ausente, incorreto, expirado ou revogado.',
                [],
                ['WWW-Authenticate' => 'Bearer'],
            ),
            $exception instanceof AuthorizationException => self::make(
                403,
                'forbidden',
                'Acesso negado',
                self::appMessage($exception->getMessage()) ?? 'O token não pode realizar esta ação neste recurso.',
            ),
            $exception instanceof ModelNotFoundException, $exception instanceof RecordsNotFoundException => self::make(
                404,
                'not-found',
                'Recurso não encontrado',
            ),
            $exception instanceof ThrottleRequestsException => self::make(
                429,
                'rate-limited',
                'Muitas requisições',
                'O limite de requisições foi atingido. Aguarde o tempo indicado em "Retry-After".',
                [],
                self::headersOf($exception),
            ),
            $exception instanceof HttpExceptionInterface => self::http($exception),
            default => self::make(
                500,
                'internal-error',
                'Erro interno',
                'Ocorreu um erro inesperado. Se persistir, informe o correlation_id ao suporte.',
            ),
        };
    }

    private static function http(HttpExceptionInterface $exception): JsonResponse
    {
        $status = $exception->getStatusCode();
        $message = self::appMessage($exception->getMessage());

        [$slug, $title, $fallback] = match (true) {
            $status === 400 => ['bad-request', 'Requisição inválida', null],
            $status === 401 => ['unauthenticated', 'Autenticação necessária', null],
            $status === 403 => ['forbidden', 'Acesso negado', 'O token não pode realizar esta ação neste recurso.'],
            $status === 404 => ['not-found', 'Recurso não encontrado', null],
            $status === 405 => ['method-not-allowed', 'Método não permitido', null],
            $status === 409 => ['conflict', 'Conflito com o estado atual', null],
            $status === 413 => ['payload-too-large', 'Conteúdo grande demais', 'O corpo da requisição ou o arquivo excede o limite aceito.'],
            $status === 415 => ['unsupported-media-type', 'Tipo de conteúdo não suportado', null],
            $status === 422 => ['unprocessable', 'Não foi possível processar', null],
            $status === 429 => ['rate-limited', 'Muitas requisições', null],
            $status === 503 => ['service-unavailable', 'Serviço indisponível', 'Tente novamente em instantes.'],
            $status >= 500 => ['internal-error', 'Erro interno', 'Ocorreu um erro inesperado. Se persistir, informe o correlation_id ao suporte.'],
            default => ['http-error', 'Erro na requisição', null],
        };

        // Em 5xx a mensagem da exceção nunca sai.
        $detail = $status >= 500 ? $fallback : ($message ?? $fallback);

        return self::make($status, $slug, $title, $detail, [], self::headersOf($exception));
    }

    /**
     * @return array<string, string|int|list<string>>
     */
    private static function headersOf(Throwable $exception): array
    {
        if (! $exception instanceof HttpExceptionInterface) {
            return [];
        }

        $headers = [];

        foreach ($exception->getHeaders() as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value))) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * Só mensagens escritas pela aplicação (PT-BR, `abort(404, '…')`) viram `detail`.
     */
    private static function appMessage(string $message): ?string
    {
        $message = trim($message);

        if ($message === '') {
            return null;
        }

        foreach (self::FRAMEWORK_PREFIXES as $prefix) {
            if (str_starts_with($message, $prefix)) {
                return null;
            }
        }

        return $message;
    }
}
