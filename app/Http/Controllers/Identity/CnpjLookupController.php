<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Services\Identity\CnpjLookupService;
use App\Services\Identity\IdentityFeatures;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Autopreenchimento por CNPJ (Fase 2 §2.11, docs/fase-2/identidade.md §3). Dois pontos de
 * entrada, mesma resposta JSON ({@see CnpjLookupService::lookup()}):
 *
 * - `POST configuracoes/organizacao/cnpj` (`settings.organization.cnpj`): Configurações ›
 *   Geral. Exige `updateSettings` na organização corrente e a flag `cnpj_lookup` dela.
 * - `POST cnpj/consulta` (`cnpj.lookup`): cadastro e "Nova organização" — ainda sem
 *   organização, então vale só o interruptor global. Limite por usuário (autenticado) ou IP.
 *
 * Status HTTP: 200 para `found`, `not_found` e `unavailable` (o formulário segue manual),
 * 422 para CNPJ inválido, 429 para o limite. Nenhum desfecho bloqueia o formulário.
 */
class CnpjLookupController extends Controller
{
    public function __construct(private readonly CnpjLookupService $lookups) {}

    public function organization(Request $request): JsonResponse
    {
        $organization = CurrentOrganization::instance()->get();

        abort_unless(IdentityFeatures::cnpjLookup($organization), 404);

        Gate::authorize('updateSettings', $organization);

        return $this->respond($request, 'user:'.$request->user()?->getAuthIdentifier(), (int) config('assinavelox.cnpj.rate_limit.per_minute_user', 10));
    }

    public function registration(Request $request): JsonResponse
    {
        abort_unless(IdentityFeatures::cnpjLookupWithoutOrganization(), 404);

        $user = $request->user();

        return $user !== null
            ? $this->respond($request, 'user:'.$user->getAuthIdentifier(), (int) config('assinavelox.cnpj.rate_limit.per_minute_user', 10))
            : $this->respond($request, 'ip:'.$request->ip(), (int) config('assinavelox.cnpj.rate_limit.per_minute_guest', 5));
    }

    private function respond(Request $request, string $rateKey, int $perMinute): JsonResponse
    {
        $validated = $request->validate([
            'cnpj' => ['required', 'string', 'max:20'],
        ], [], ['cnpj' => 'CNPJ']);

        $result = $this->lookups->lookup($validated['cnpj'], $rateKey, $perMinute);

        return match ($result['status']) {
            'invalid' => response()->json($result + ['errors' => ['cnpj' => [$result['message']]]], 422),
            'rate_limited' => response()->json($result, 429, ['Retry-After' => (string) max(1, (int) $result['retry_after'])]),
            default => response()->json($result),
        };
    }
}
