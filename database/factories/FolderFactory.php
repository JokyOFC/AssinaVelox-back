<?php

namespace Database\Factories;

use App\Models\Folder;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Folder>
 */
class FolderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'parent_id' => null,
            'name' => fake()->unique()->randomElement([
                'Contratos', 'Recursos Humanos', 'Jurídico', 'Fornecedores', 'Clientes',
                'Locações', 'Procurações', 'Propostas', 'Financeiro', 'Parcerias',
            ]).' '.fake()->unique()->numberBetween(1, 9999),
            'created_by_user_id' => User::factory(),
        ];
    }

    public function childOf(Folder $parent): static
    {
        return $this->state(fn () => [
            'organization_id' => $parent->organization_id,
            'parent_id' => $parent->id,
        ]);
    }
}
