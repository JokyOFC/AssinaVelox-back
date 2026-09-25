<?php

namespace App\Services\Plans;

use App\Enums\PlanBillingPeriod;
use App\Models\Plan;
use App\Models\User;
use App\Services\AdminLog\PlatformAction;
use App\Services\AdminLog\PlatformTrail;
use Illuminate\Support\Facades\DB;

/**
 * Edição do catálogo de planos pelo painel interno (docs/cobranca.md §18).
 *
 * A tabela `plans` é a fonte de verdade da oferta comercial: preço, período, cotas,
 * armazenamento, recursos e visibilidade. O `PlanSeeder` só cria o catálogo inicial. Tudo o
 * que muda aqui vai para `platform_audit_events` com a lista de alterações — preço e
 * recurso são decisões comerciais, e a trilha diz quem tomou cada uma.
 *
 * Chaves de `features` fora do catálogo ({@see PlanFeatureCatalog}) — `bulk_generation_limits`,
 * por exemplo — são preservadas exatamente como estavam.
 */
final class PlanCatalogEditor
{
    public const STORAGE_KEY = 'storage_bytes';

    /**
     * @param  array<string, mixed>  $attributes  saída validada de SavePlanRequest
     */
    public function create(array $attributes, User $actor): Plan
    {
        return DB::transaction(function () use ($attributes, $actor): Plan {
            $plan = Plan::query()->create([
                'code' => $attributes['code'],
                ...$this->columns($attributes),
                'features' => $this->mergeFeatures([], $attributes),
            ]);

            PlatformTrail::record(PlatformAction::PlanCreated, $actor, 'plan', (int) $plan->getKey(), null, [
                'plan' => $plan->code,
                'name' => $plan->name,
                'changes' => $this->describe([], $this->snapshot($plan)),
            ]);

            return $plan;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  saída validada de SavePlanRequest
     */
    public function update(Plan $plan, array $attributes, User $actor): Plan
    {
        return DB::transaction(function () use ($plan, $attributes, $actor): Plan {
            $before = $this->snapshot($plan);

            $plan->fill($this->columns($attributes));
            $plan->features = $this->mergeFeatures(is_array($plan->features) ? $plan->features : [], $attributes);
            $plan->save();

            $changes = $this->describe($before, $this->snapshot($plan->refresh()));

            if ($changes !== []) {
                PlatformTrail::record(PlatformAction::PlanUpdated, $actor, 'plan', (int) $plan->getKey(), null, [
                    'plan' => $plan->code,
                    'name' => $plan->name,
                    'changes' => $changes,
                ]);
            }

            return $plan;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function columns(array $attributes): array
    {
        return [
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'price_cents' => (int) $attributes['price_cents'],
            'currency' => 'BRL',
            'billing_period' => PlanBillingPeriod::from($attributes['billing_period']),
            'envelope_quota' => $attributes['envelope_quota'] ?? null,
            'user_quota' => $attributes['user_quota'] ?? null,
            'is_active' => (bool) $attributes['is_active'],
            'is_public' => (bool) $attributes['is_public'],
            'is_sandbox' => (bool) $attributes['is_sandbox'],
            'sort_order' => (int) $attributes['sort_order'],
        ];
    }

    /**
     * Catálogo por cima do JSON existente: chaves desconhecidas ficam como estão.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function mergeFeatures(array $current, array $attributes): array
    {
        $submitted = (array) ($attributes['features'] ?? []);

        foreach (PlanFeatureCatalog::keys() as $key) {
            $current[$key] = ($submitted[$key] ?? false) === true;
        }

        $storageGb = $attributes['storage_gb'] ?? null;

        if ($storageGb === null || $storageGb === '') {
            unset($current[self::STORAGE_KEY]);
        } else {
            $current[self::STORAGE_KEY] = (int) round(((float) $storageGb) * 1024 * 1024 * 1024);
        }

        return $current;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function snapshot(Plan $plan): array
    {
        $features = is_array($plan->features) ? $plan->features : [];

        $row = [
            'name' => $plan->name,
            'description' => $plan->description,
            'price_cents' => (int) $plan->price_cents,
            'billing_period' => $plan->billing_period->value,
            'envelope_quota' => $plan->envelope_quota,
            'user_quota' => $plan->user_quota,
            'storage_bytes' => isset($features[self::STORAGE_KEY]) ? (int) $features[self::STORAGE_KEY] : null,
            'is_active' => (bool) $plan->is_active,
            'is_public' => (bool) $plan->is_public,
            'is_sandbox' => (bool) $plan->is_sandbox,
            'sort_order' => (int) $plan->sort_order,
        ];

        foreach (PlanFeatureCatalog::keys() as $key) {
            $row['features.'.$key] = ($features[$key] ?? false) === true;
        }

        return $row;
    }

    /**
     * "campo: antes → depois", só para o que mudou (na criação, "campo: valor"). Rótulos
     * legíveis para a trilha.
     *
     * @param  array<string, scalar|null>  $before
     * @param  array<string, scalar|null>  $after
     * @return list<string>
     */
    private function describe(array $before, array $after): array
    {
        $labels = array_column(PlanFeatureCatalog::entries(), 'label', 'key');
        $changes = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;

            if ($previous === $value) {
                continue;
            }

            $label = str_starts_with($key, 'features.')
                ? ($labels[substr($key, 9)] ?? $key)
                : match ($key) {
                    'name' => 'Nome',
                    'description' => 'Descrição',
                    'price_cents' => 'Preço',
                    'billing_period' => 'Período',
                    'envelope_quota' => 'Documentos/mês',
                    'user_quota' => 'Usuários',
                    'storage_bytes' => 'Armazenamento',
                    'is_active' => 'Ativo',
                    'is_public' => 'Público',
                    'is_sandbox' => 'Sandbox',
                    'sort_order' => 'Ordem',
                    default => $key,
                };

            // Na criação não há "antes": a trilha lista o valor com que o plano nasceu.
            $changes[] = $before === []
                ? $label.': '.$this->format($key, $value)
                : $label.': '.$this->format($key, $previous).' → '.$this->format($key, $value);
        }

        return $changes;
    }

    private function format(string $key, mixed $value): string
    {
        return match (true) {
            $value === null => 'ilimitado',
            is_bool($value) => $value ? 'sim' : 'não',
            $key === 'price_cents' => 'R$ '.number_format(((int) $value) / 100, 2, ',', '.'),
            $key === 'storage_bytes' => number_format(((int) $value) / (1024 * 1024 * 1024), 1, ',', '.').' GB',
            default => (string) $value,
        };
    }
}
