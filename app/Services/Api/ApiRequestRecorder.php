<?php

namespace App\Services\Api;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Support\Correlation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Lottery;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Grava o registro mínimo de uma requisição autenticada da API v1 (`api_request_logs`).
 *
 * Só metadados: token, método, NOME e PADRÃO da rota (nunca a URL com ULIDs ou query), status,
 * duração, correlação e se foi repetição idempotente. Sem corpo, cabeçalho, IP ou dado pessoal.
 * Falhar aqui nunca derruba a resposta.
 *
 * Retenção curta: além do `model:prune` (App\Models\ApiRequestLog é MassPrunable), 1 em cada
 * 100 gravações apaga um lote de registros vencidos — a retenção vale mesmo sem o agendador.
 */
final class ApiRequestRecorder
{
    public function record(Request $request, Response $response, ApiToken $token, int $startedAtNs): void
    {
        try {
            $route = $request->route();
            $durationMs = (int) max(0, round((hrtime(true) - $startedAtNs) / 1_000_000));

            ApiRequestLog::query()->create([
                'organization_id' => $token->organization_id,
                'personal_access_token_id' => $token->getKey(),
                'method' => mb_substr($request->getMethod(), 0, 10),
                'route' => is_object($route) ? $route->getName() : null,
                'path_pattern' => is_object($route) ? mb_substr($route->uri(), 0, 191) : null,
                'status' => $response->getStatusCode(),
                'duration_ms' => min($durationMs, 4_294_967_295),
                'correlation_id' => Correlation::current(),
                'idempotent_replay' => $response->headers->get('Idempotent-Replayed') === 'true',
                'occurred_at' => Carbon::now(),
            ]);

            if (Lottery::odds(1, 100)->choose()) {
                $this->pruneExpired();
            }
        } catch (Throwable $exception) {
            Log::warning('API v1: não foi possível gravar o registro da requisição.', [
                'exception' => $exception::class,
            ]);
        }
    }

    public function pruneExpired(int $batch = 1000): int
    {
        return ApiRequestLog::withoutOrganizationScope()
            ->where('occurred_at', '<', Carbon::now()->subDays(ApiRequestLog::retentionDays()))
            ->limit($batch)
            ->delete();
    }
}
