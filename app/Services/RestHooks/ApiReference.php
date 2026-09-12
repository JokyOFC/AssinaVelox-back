<?php

namespace App\Services\RestHooks;

use App\Enums\ApiAbility;
use App\Services\Api\ApiTokenManager;
use App\Services\Webhooks\WebhookSignature;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Referência rápida da API v1 para a aba "Documentação" (Integrações). A lista de rotas é
 * LIDA DO ROTEADOR (grupo `api.v1.*`): método, caminho, ability e exigência de
 * `Idempotency-Key` vêm do próprio middleware da rota — nada é digitado à mão e uma rota nova
 * aparece sozinha. Só as descrições são texto (PT-BR). A especificação completa continua sendo
 * a OpenAPI do Scramble (`/docs/api`).
 */
final class ApiReference
{
    /** @var array<string, array{0: string, 1: string}> nome da rota → [grupo, descrição] */
    private const DESCRIPTIONS = [
        'api.v1.envelopes.index' => ['Documentos', 'Lista paginada por cursor. Filtros: status, folder, q, created_after, created_before, updated_after.'],
        'api.v1.envelopes.store' => ['Documentos', 'Cria um documento em rascunho (título, mensagem, ordem, prazo, pasta).'],
        'api.v1.envelopes.show' => ['Documentos', 'Detalhe com arquivos, participantes e links.'],
        'api.v1.envelopes.documents.store' => ['Documentos', 'Envia um arquivo (só multipart; não aceita URL).'],
        'api.v1.envelopes.recipients.index' => ['Participantes e campos', 'Situação de cada participante.'],
        'api.v1.envelopes.recipients.sync' => ['Participantes e campos', 'Define a lista de participantes do rascunho.'],
        'api.v1.envelopes.fields.index' => ['Participantes e campos', 'Campos posicionados no documento (sem o valor preenchido).'],
        'api.v1.envelopes.fields.sync' => ['Participantes e campos', 'Define os campos do rascunho (coordenadas de 0 a 1).'],
        'api.v1.envelopes.send' => ['Envio e acompanhamento', 'Envia para assinatura e dispara os convites.'],
        'api.v1.envelopes.cancel' => ['Envio e acompanhamento', 'Cancela um documento em andamento.'],
        'api.v1.envelopes.files.show' => ['Envio e acompanhamento', 'Baixa o original, o arquivo final ou a página de evidências.'],
        'api.v1.envelopes.events.index' => ['Envio e acompanhamento', 'Eventos da trilha em ordem cronológica.'],
        'api.v1.envelopes.verification.show' => ['Envio e acompanhamento', 'O mesmo registro da verificação pública, com os mesmos rótulos.'],
        'api.v1.templates.index' => ['Modelos', 'Lista os modelos da organização.'],
        'api.v1.templates.show' => ['Modelos', 'Variáveis e papéis de um modelo.'],
        'api.v1.templates.envelopes.store' => ['Modelos', 'Gera um documento em rascunho a partir de um modelo.'],
        'api.v1.webhook_events.index' => ['REST Hooks', 'Eventos que podem ser assinados.'],
        'api.v1.webhook_events.sample' => ['REST Hooks', 'Payload de exemplo do evento, sem dados reais (para mapear campos).'],
        'api.v1.webhook_subscriptions.index' => ['REST Hooks', 'Assinaturas ativas deste token.'],
        'api.v1.webhook_subscriptions.store' => ['REST Hooks', 'Assina um evento numa URL HTTPS (devolve o segredo uma única vez).'],
        'api.v1.webhook_subscriptions.destroy' => ['REST Hooks', 'Remove a assinatura.'],
    ];

    /**
     * @return list<array{method: string, path: string, name: string, group: string, description: string, abilities: list<string>, idempotency: string|null}>
     */
    public static function endpoints(bool $includeRestHooks): array
    {
        $rows = [];

        /** @var RoutingRoute $route */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if (! is_string($name) || ! str_starts_with($name, 'api.v1.')) {
                continue;
            }

            [$group, $description] = self::DESCRIPTIONS[$name] ?? ['Outros', ''];

            if ($group === 'REST Hooks' && ! $includeRestHooks) {
                continue;
            }

            $abilities = [];
            $idempotency = null;

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }

                if (str_starts_with($middleware, 'api.ability:')) {
                    array_push($abilities, ...explode(',', substr($middleware, strlen('api.ability:'))));
                } elseif ($middleware === 'api.idempotent') {
                    $idempotency = 'required';
                } elseif ($middleware === 'api.idempotent:optional') {
                    $idempotency = 'optional';
                }
            }

            $path = '/'.ltrim((string) preg_replace('#^api/v1#', '', $route->uri()), '/');

            foreach (array_values(array_diff($route->methods(), ['HEAD'])) as $method) {
                $rows[] = [
                    'method' => $method,
                    'path' => $path,
                    'name' => $name,
                    'group' => $group,
                    'description' => $description,
                    'abilities' => $abilities,
                    'idempotency' => $idempotency,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function abilities(): array
    {
        return array_map(static fn (ApiAbility $ability): array => [
            'value' => $ability->value,
            'label' => $ability->label(),
            'description' => $ability->description(),
        ], ApiAbility::cases());
    }

    /**
     * Erros mais comuns (RFC 9457) — o catálogo completo está em docs/fase-2/api-v1.md §6.
     *
     * @return list<array{status: int, type: string, description: string}>
     */
    public static function problems(): array
    {
        return [
            ['status' => 400, 'type' => 'idempotency-key-missing', 'description' => 'Faltou o cabeçalho Idempotency-Key numa criação ou envio.'],
            ['status' => 401, 'type' => 'unauthenticated', 'description' => 'Token ausente, incorreto, expirado ou revogado.'],
            ['status' => 403, 'type' => 'missing-ability', 'description' => 'O token não tem a permissão exigida pela rota (campo required_ability).'],
            ['status' => 403, 'type' => 'creator-lacks-permission', 'description' => 'Quem criou o token perdeu a permissão correspondente na organização.'],
            ['status' => 404, 'type' => 'not-found', 'description' => 'Recurso inexistente, de outra organização ou fora da visibilidade de quem criou o token.'],
            ['status' => 409, 'type' => 'invalid-status', 'description' => 'Ação incompatível com o status atual do documento.'],
            ['status' => 409, 'type' => 'idempotency-key-reused', 'description' => 'A mesma Idempotency-Key foi usada com outro pedido.'],
            ['status' => 422, 'type' => 'validation-failed', 'description' => 'Campos inválidos; detalhes em errors.'],
            ['status' => 429, 'type' => 'rate-limited', 'description' => 'Limite de requisições atingido; aguarde Retry-After.'],
            ['status' => 500, 'type' => 'internal-error', 'description' => 'Erro inesperado; informe o correlation_id ao suporte.'],
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function limits(): array
    {
        return [
            'per_token_per_minute' => (int) config('assinavelox.api.rate_limit.per_token_per_minute', 120),
            'per_organization_per_minute' => (int) config('assinavelox.api.rate_limit.per_organization_per_minute', 600),
            'idempotency_ttl_hours' => (int) config('assinavelox.api.idempotency.ttl_hours', 24),
            'page_size_default' => (int) config('assinavelox.api.page_size.default', 25),
            'page_size_max' => (int) config('assinavelox.api.page_size.max', 100),
            'max_upload_mb' => (int) config('assinavelox.upload.max_mb', 25),
            'token_max_expiration_days' => ApiTokenManager::maxExpirationDays(),
            'request_log_retention_days' => (int) config('assinavelox.api.request_logs.retention_days', 30),
            'rest_hooks_per_token' => RestHookSubscriptions::maxPerToken(),
        ];
    }

    /**
     * @return array{delivery_id: string, timestamp: string, signature: string, tolerance_seconds: int}
     */
    public static function signature(): array
    {
        return [
            'delivery_id' => WebhookSignature::HEADER_DELIVERY,
            'timestamp' => WebhookSignature::HEADER_TIMESTAMP,
            'signature' => WebhookSignature::HEADER_SIGNATURE,
            'tolerance_seconds' => (int) config('assinavelox.webhooks.signature_tolerance_seconds', 300),
        ];
    }
}
