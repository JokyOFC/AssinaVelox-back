<?php

namespace App\Services\RestHooks;

use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\WebhookEndpoint;
use App\Services\Api\ApiFormat;
use App\Services\Api\Exceptions\ApiProblemException;
use App\Services\Webhooks\WebhookActionRefused;
use App\Services\Webhooks\WebhookEndpointManager;
use App\Services\Webhooks\WebhookEventType;
use App\Support\Http\BlockedOutboundUrl;
use App\Support\Http\OutboundUrlGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Assinaturas de webhook criadas pela API — padrão REST Hooks usado por Zapier, Make e n8n
 * (roadmap §2.17; docs/fase-2/integracoes-no-code.md).
 *
 * Uma assinatura É um endpoint do motor de webhooks (D-HOOK), com `source = rest_hook` e
 * `api_token_id` = o token que a criou. Rede, SSRF, segredo, assinatura HMAC, fila,
 * retentativas, pausa e histórico são os do motor — nada é reimplementado aqui.
 *
 * Regras próprias:
 *  - a assinatura pertence ao TOKEN: listar e remover só enxergam as do próprio token
 *    (outro token da mesma organização recebe 404, como recurso inexistente);
 *  - teto de assinaturas ativas por token (`assinavelox.rest_hooks.max_subscriptions_per_token`),
 *    além do teto de endpoints da organização do motor;
 *  - repetir a mesma assinatura (mesmo token, mesma URL, mesmos eventos) devolve a existente,
 *    SEM o segredo — ele só aparece na criação;
 *  - revogar o token remove as assinaturas dele ({@see self::removeForToken()}).
 *
 * Quem chama já conferiu a ability `webhooks:manage` (middleware) e a policy do endpoint.
 */
final class RestHookSubscriptions
{
    public function __construct(
        private readonly WebhookEndpointManager $manager,
        private readonly OutboundUrlGuard $guard,
    ) {}

    /**
     * @param  list<string>  $events
     * @return array{endpoint: WebhookEndpoint, secret: string|null, created: bool}
     *
     * @throws ApiProblemException
     */
    public function subscribe(Organization $organization, Membership $creator, ApiToken $token, string $targetUrl, array $events): array
    {
        try {
            $events = WebhookEndpointManager::normalizeEvents($events);
        } catch (WebhookActionRefused $refused) {
            throw new ApiProblemException(422, 'validation-failed', 'Dados inválidos', $refused->getMessage(), [
                'errors' => ['event' => [$refused->getMessage()]],
            ]);
        }

        // A mesma forma normalizada que o motor grava (para achar a assinatura repetida). O motor
        // valida de novo ao criar — a proteção nunca depende desta checagem antecipada.
        try {
            $targetUrl = $this->guard->inspect($targetUrl)->url;
        } catch (BlockedOutboundUrl $blocked) {
            throw self::blocked($blocked);
        }

        return DB::transaction(function () use ($organization, $creator, $token, $targetUrl, $events): array {
            // Serializa as assinaturas do mesmo token (a contagem e a busca da existente não
            // podem correr em paralelo com outra criação).
            ApiToken::withoutOrganizationScope()->whereKey($token->getKey())->lockForUpdate()->first();

            $existing = $this->findSame($token, $targetUrl, $events);

            if ($existing !== null) {
                return ['endpoint' => $existing, 'secret' => null, 'created' => false];
            }

            $max = self::maxPerToken();

            if (self::forToken($token)->count() >= $max) {
                throw ApiProblemException::conflict(
                    'subscription-limit-reached',
                    "Este token já tem {$max} assinaturas de webhook ativas. Remova uma antes de criar outra.",
                    ['limit' => $max],
                    'Limite de assinaturas atingido',
                );
            }

            try {
                $result = $this->manager->create(
                    $organization,
                    $creator->user,
                    $targetUrl,
                    $events,
                    'REST Hook · '.$token->name,
                    WebhookEndpoint::SOURCE_REST_HOOK,
                    (int) $token->getKey(),
                );
            } catch (BlockedOutboundUrl $blocked) {
                throw self::blocked($blocked);
            } catch (WebhookActionRefused $refused) {
                throw ApiProblemException::conflict('subscription-refused', $refused->getMessage(), [], 'Assinatura recusada');
            }

            return ['endpoint' => $result['endpoint'], 'secret' => $result['secret'], 'created' => true];
        });
    }

    /**
     * Remove a assinatura (soft delete do motor: cancela as entregas abertas e descarta o
     * segredo). Só a do próprio token.
     */
    public function unsubscribe(WebhookEndpoint $endpoint): void
    {
        $this->manager->delete($endpoint);
    }

    /**
     * Assinatura do token pelo ULID — `null` para inexistente, de outro token, de outra
     * organização ou criada pela tela.
     */
    public function findForToken(ApiToken $token, string $ulid): ?WebhookEndpoint
    {
        /** @var WebhookEndpoint|null $endpoint */
        $endpoint = self::forToken($token)->where('ulid', $ulid)->first();

        return $endpoint;
    }

    /**
     * Revogar um token remove as assinaturas dele: um token revogado não deve continuar
     * recebendo eventos por um endpoint que só ele sabia remover.
     *
     * @return int quantas foram removidas
     */
    public function removeForToken(ApiToken $token): int
    {
        $removed = 0;

        foreach (self::forToken($token)->get() as $endpoint) {
            $this->manager->delete($endpoint);
            $removed++;
        }

        return $removed;
    }

    /**
     * Varredura agendada (`rest-hooks:prune`, integração I-2D): remove as assinaturas cujo token
     * VENCEU, foi revogado por outro caminho que não a tela ou não existe mais. Enquanto a
     * varredura não roda, o motor já deixa de entregar a elas (WebhookAccess::restHookTokenUsable).
     *
     * @return int quantas foram removidas
     */
    public function pruneInactive(): int
    {
        $removed = 0;

        WebhookEndpoint::withoutOrganizationScope()
            ->where('source', WebhookEndpoint::SOURCE_REST_HOOK)
            ->orderBy('id')
            ->chunkById(200, function ($endpoints) use (&$removed): void {
                foreach ($endpoints as $endpoint) {
                    /** @var WebhookEndpoint $endpoint */
                    $token = $endpoint->api_token_id === null
                        ? null
                        : ApiToken::withoutOrganizationScope()->find($endpoint->api_token_id);

                    if ($token !== null && $token->organization_id === $endpoint->organization_id && $token->isUsable()) {
                        continue;
                    }

                    $this->manager->delete($endpoint);
                    $removed++;
                }
            });

        return $removed;
    }

    /**
     * Assinaturas ativas (não removidas) de um token, mais recentes primeiro.
     *
     * @return Builder<WebhookEndpoint>
     */
    public static function forToken(ApiToken $token): Builder
    {
        return WebhookEndpoint::withoutOrganizationScope()
            ->where('organization_id', $token->organization_id)
            ->where('source', WebhookEndpoint::SOURCE_REST_HOOK)
            ->where('api_token_id', $token->getKey())
            ->orderByDesc('id');
    }

    /**
     * Quantas assinaturas cada token tem (para a tela de chaves).
     *
     * @param  array<int, int>  $tokenIds  ids internos dos tokens
     * @return array<int, int>
     */
    public static function countsByToken(Organization $organization, array $tokenIds): array
    {
        if ($tokenIds === []) {
            return [];
        }

        /** @var array<int, int> $counts */
        $counts = WebhookEndpoint::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->where('source', WebhookEndpoint::SOURCE_REST_HOOK)
            ->whereIn('api_token_id', $tokenIds)
            ->selectRaw('api_token_id, COUNT(*) as aggregate')
            ->groupBy('api_token_id')
            ->pluck('aggregate', 'api_token_id')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        return $counts;
    }

    public static function maxPerToken(): int
    {
        return max(1, (int) config('assinavelox.rest_hooks.max_subscriptions_per_token', 10));
    }

    /**
     * Representação na API. O segredo só entra quando acabou de ser criado.
     *
     * @return array<string, mixed>
     */
    public static function present(WebhookEndpoint $endpoint, ?string $secret = null): array
    {
        $data = [
            'id' => $endpoint->ulid,
            'object' => 'webhook_subscription',
            'target_url' => $endpoint->url,
            'events' => $endpoint->events ?? [],
            'status' => $endpoint->is_active ? 'active' : 'paused',
            'status_label' => $endpoint->is_active ? 'Ativa' : 'Pausada',
            'paused_reason' => $endpoint->is_active ? null : $endpoint->paused_reason,
            'secret_hint' => $endpoint->secret_hint,
            'signature_header' => 'X-AssinaVelox-Signature',
            'created_at' => ApiFormat::date($endpoint->created_at),
        ];

        if ($secret !== null) {
            $data['secret'] = $secret;
        }

        return $data;
    }

    /**
     * Valores aceitos em `event`/`events[]`.
     *
     * @return list<string>
     */
    public static function acceptedEvents(): array
    {
        return [WebhookEventType::ALL, ...WebhookEventType::subscribableValues()];
    }

    /**
     * @param  list<string>  $events  já normalizados
     */
    private function findSame(ApiToken $token, string $targetUrl, array $events): ?WebhookEndpoint
    {
        $wanted = $events;
        sort($wanted);

        foreach (self::forToken($token)->where('url', $targetUrl)->get() as $endpoint) {
            $current = $endpoint->events ?? [];
            sort($current);

            if ($current === $wanted) {
                return $endpoint;
            }
        }

        return null;
    }

    /**
     * Nunca o `detail` interno do bloqueio: só a mensagem para o usuário, que é a mesma para
     * "não resolve" e "resolve para endereço interno".
     */
    private static function blocked(BlockedOutboundUrl $blocked): ApiProblemException
    {
        return new ApiProblemException(422, 'target-url-blocked', 'URL de destino recusada', $blocked->userMessage(), [
            'errors' => ['target_url' => [$blocked->userMessage()]],
        ]);
    }
}
