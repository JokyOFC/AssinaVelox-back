<?php

namespace App\Http\Controllers\Tsa;

use App\Http\Controllers\Controller;
use App\Services\Timestamp\Exceptions\TsaException;
use App\Services\Timestamp\Exceptions\TsaUnavailableException;
use App\Services\Timestamp\OperatorTsa;
use App\Services\Timestamp\TimestampFeatures;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Env;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Endpoint HTTP INTERNO da TSA da operadora (RFC 3161 §3.4):
 * `POST /tsa` com `Content-Type: application/timestamp-query` → `application/timestamp-reply`.
 *
 * Uso só pelo próprio sistema (ex.: um cliente RFC 3161 padrão ou `openssl ts` na rede
 * interna). Barreiras, nesta ordem:
 *
 * - flag `operator_tsa` desligada → 404;
 * - IP fora de `assinavelox.tsa.http.allowed_ips` (aceita CIDR) → 403;
 * - token Bearer ausente/errado → 401 (o valor esperado vive só na variável de ambiente
 *   nomeada em `tsa.http.token_env`; sem ela definida o endpoint responde 503 — fechado);
 * - tipo de conteúdo errado → 415; corpo acima do limite → 413;
 * - limite por minuto na rota (`throttle`).
 *
 * Pedido que a TSA recusa (algoritmo, política, formato) volta como resposta RFC 3161
 * `rejection` com HTTP 200 — é o que a RFC manda; erro HTTP só para falha do servidor.
 */
class TsaController extends Controller
{
    public const REQUEST_TYPE = 'application/timestamp-query';

    public const REPLY_TYPE = 'application/timestamp-reply';

    public function __invoke(Request $request, OperatorTsa $tsa): Response
    {
        abort_unless(TimestampFeatures::operatorTsa(), 404);

        $allowed = array_values(array_filter((array) config('assinavelox.tsa.http.allowed_ips', []), 'is_string'));

        if ($allowed === [] || ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            return $this->plain('Origem não autorizada.', 403);
        }

        $expected = $this->expectedToken();

        if ($expected === null) {
            return $this->plain('Endpoint da TSA não configurado.', 503);
        }

        $given = (string) $request->bearerToken();

        if ($given === '' || ! hash_equals($expected, $given)) {
            return $this->plain('Não autorizado.', 401, ['WWW-Authenticate' => 'Bearer']);
        }

        $type = strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0]));

        if ($type !== self::REQUEST_TYPE) {
            return $this->plain('Use Content-Type: '.self::REQUEST_TYPE.'.', 415);
        }

        $body = $request->getContent();
        $max = max(256, (int) config('assinavelox.tsa.http.max_request_bytes', 8192));

        if ($body === '' || strlen($body) > $max) {
            return $this->plain('Corpo vazio ou acima do limite.', 413);
        }

        try {
            $result = $tsa->respond($body, (string) $request->header('X-Correlation-Id', '') ?: null);
        } catch (TsaUnavailableException) {
            return $this->plain('TSA indisponível.', 503);
        } catch (TsaException) {
            return $this->plain('Falha na TSA.', 500);
        }

        return new Response($result['response'], 200, [
            'Content-Type' => self::REPLY_TYPE,
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-TSA-Kind' => 'operator',
        ]);
    }

    private function expectedToken(): ?string
    {
        $name = (string) config('assinavelox.tsa.http.token_env', 'ASSINAVELOX_TSA_HTTP_TOKEN');

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            return null;
        }

        $value = Env::getRepository()->get($name);

        return is_string($value) && strlen($value) >= 16 ? $value : null;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function plain(string $message, int $status, array $headers = []): Response
    {
        return new Response($message, $status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store', ...$headers]);
    }
}
