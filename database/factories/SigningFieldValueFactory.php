<?php

namespace Database\Factories;

use App\Enums\FieldType;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SigningFieldValue>
 */
class SigningFieldValueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'signing_field_id' => SigningField::factory(),
            'recipient_id' => fn (array $attributes) => self::field($attributes)->recipient_id,
            'envelope_id' => fn (array $attributes) => self::field($attributes)->envelope_id,
            'organization_id' => fn (array $attributes) => self::field($attributes)->organization_id,
            'signature_acceptance_id' => fn (array $attributes) => self::acceptanceFor($attributes),
            'value_text' => fn (array $attributes) => self::field($attributes)->type === FieldType::Text
                ? fake()->words(3, true)
                : null,
            'value_bool' => fn (array $attributes) => self::field($attributes)->type === FieldType::Checkbox
                ? true
                : null,
            'image_path' => fn (array $attributes) => self::field($attributes)->type->isImageBased()
                ? sprintf('organizations/%d/signatures/%s.png', $attributes['organization_id'], fake()->uuid())
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected static function field(array $attributes): SigningField
    {
        return SigningField::withoutOrganizationScope()->whereKey($attributes['signing_field_id'])->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected static function acceptanceFor(array $attributes): int
    {
        $field = self::field($attributes);

        $acceptance = SignatureAcceptance::withoutOrganizationScope()
            ->where('recipient_id', $field->recipient_id)
            ->first();

        return ($acceptance ?? SignatureAcceptance::factory()->forRecipient($field->recipient)->create())->id;
    }

    public function forField(SigningField $field, SignatureAcceptance $acceptance): static
    {
        return $this->state(fn () => [
            'signing_field_id' => $field->id,
            'recipient_id' => $field->recipient_id,
            'envelope_id' => $field->envelope_id,
            'organization_id' => $field->organization_id,
            'signature_acceptance_id' => $acceptance->id,
        ]);
    }
}
