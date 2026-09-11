<?php

namespace App\Services\AdminLog;

use App\Enums\ActorType;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Consulta e apresentação do log administrativo da organização (Configurações › Registro
 * de atividades). Lê `audit_events` (somente SELECT) filtrando:
 *  - a organização (explícito, além do escopo global);
 *  - os tipos de {@see AdminEventCatalog} — nunca eventos do ciclo do envelope;
 *  - categoria, autor e período escolhidos na tela.
 *
 * A apresentação NÃO devolve o payload bruto: só as chaves de
 * {@see AdminEventCatalog::visiblePayloadKeys()}, já formatadas; IP mascarado.
 */
final class OrganizationAuditLog
{
    /**
     * @param  array{category: string|null, actor: int|null, from: Carbon|null, to: Carbon|null}  $filters
     * @return Builder<AuditEvent>
     */
    public static function query(int $organizationId, array $filters): Builder
    {
        $query = AuditEvent::forOrganization($organizationId)
            ->whereNull('envelope_id')
            ->whereIn('event_type', AdminEventCatalog::eventValues($filters['category']));

        if ($filters['actor'] !== null) {
            $query->where('actor_type', ActorType::User->value)->where('actor_id', $filters['actor']);
        }

        if ($filters['from'] !== null) {
            $query->where('occurred_at', '>=', $filters['from']);
        }

        if ($filters['to'] !== null) {
            $query->where('occurred_at', '<=', $filters['to']);
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * @param  LengthAwarePaginator<int, AuditEvent>  $page
     * @return list<array<string, mixed>>
     */
    public static function present(LengthAwarePaginator $page): array
    {
        $actorIds = collect($page->items())
            ->filter(fn (AuditEvent $event): bool => $event->actor_type === ActorType::User && $event->actor_id !== null)
            ->pluck('actor_id')
            ->unique()
            ->values()
            ->all();

        $actors = User::query()->whereIn('id', $actorIds)->get(['id', 'name', 'is_platform_admin'])->keyBy('id');
        $categories = AdminEventCatalog::categories();

        return array_values(array_map(function (AuditEvent $event) use ($actors, $categories): array {
            $category = AdminEventCatalog::categoryOf($event->event_type);
            $payload = $event->payload ?? [];
            $support = isset($payload['impersonation']);
            $actor = $event->actor_id !== null ? $actors->get($event->actor_id) : null;

            return [
                'id' => $event->ulid,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'type' => $event->event_type->value,
                'label' => $event->event_type->label(),
                'kind' => $event->event_type->kind(),
                'category' => $category,
                'category_label' => $category !== null ? $categories[$category]['label'] : null,
                'actor' => [
                    'kind' => $support ? 'support' : ($event->actor_type === ActorType::User ? 'user' : 'system'),
                    'name' => $support
                        ? 'Equipe AssinaVelox'.($actor !== null ? ' · '.$actor->name : '')
                        : ($event->actor_type === ActorType::User ? ($actor->name ?? 'Usuário removido') : 'Sistema'),
                ],
                'details' => self::details($payload),
                'ip' => self::maskIp($event->ip_address),
            ];
        }, $page->items()));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{label: string, value: string}>
     */
    public static function details(array $payload): array
    {
        $details = [];

        foreach (AdminEventCatalog::visiblePayloadKeys() as $key => $label) {
            if (! array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
                continue;
            }

            $value = $payload[$key];

            $text = match (true) {
                $key === 'amount_cents' && is_numeric($value) => 'R$ '.number_format(((int) $value) / 100, 2, ',', '.'),
                $key === 'end_reason' && is_string($value) => self::endReasonLabel($value),
                is_array($value) => (string) count($value),
                is_bool($value) => $value ? 'Sim' : 'Não',
                is_scalar($value) => (string) $value,
                default => null,
            };

            if ($text !== null && $text !== '') {
                $details[] = ['label' => $label, 'value' => mb_strimwidth($text, 0, 200, '…')];
            }
        }

        return $details;
    }

    public static function endReasonLabel(string $reason): string
    {
        return match ($reason) {
            'stopped' => 'Encerrado pelo suporte',
            'expired' => 'Expirou (30 min)',
            'logout' => 'Saída da conta',
            default => 'Encerrado automaticamente',
        };
    }

    /**
     * 187.10.23.54 → 187.10.x.x · 2804:14c:1:2::1 → 2804:14c:…
     */
    public static function maskIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return $parts[0].'.'.$parts[1].'.x.x';
        }

        $parts = explode(':', $ip);

        return implode(':', array_slice($parts, 0, 2)).':…';
    }
}
