<?php

namespace Database\Factories;

use App\Enums\FieldBoxType;
use App\Enums\FieldType;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Recipient;
use App\Models\SigningField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SigningField>
 */
class SigningFieldFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recipient_id' => Recipient::factory(),
            'envelope_id' => fn (array $attributes) => self::recipient($attributes)->envelope_id,
            'organization_id' => fn (array $attributes) => self::recipient($attributes)->organization_id,
            'document_version_id' => fn (array $attributes) => self::versionFor($attributes),
            'type' => FieldType::Signature,
            'page' => 1,
            'x' => fake()->randomFloat(6, 0.05, 0.55),
            'y' => fake()->randomFloat(6, 0.05, 0.85),
            'width' => 0.30,
            'height' => 0.06,
            'box_type' => FieldBoxType::CropBox,
            'page_width_pt' => 595.276,
            'page_height_pt' => 841.89,
            'page_rotation' => 0,
            'required' => true,
            'label' => null,
            'options' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected static function recipient(array $attributes): Recipient
    {
        return Recipient::withoutOrganizationScope()->whereKey($attributes['recipient_id'])->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected static function versionFor(array $attributes): int
    {
        $recipient = self::recipient($attributes);
        $envelope = $recipient->envelope()->withoutGlobalScopes()->withTrashed()->firstOrFail();

        if ($envelope->sent_document_version_id) {
            return $envelope->sent_document_version_id;
        }

        $document = $envelope->documents()->withoutGlobalScopes()->first()
            ?? Document::factory()->forEnvelope($envelope)->ready()->create();

        $version = $document->versions()->withoutGlobalScopes()->latest('version_number')->first()
            ?? DocumentVersion::factory()->forDocument($document)->create();

        return $version->id;
    }

    public function forRecipient(Recipient $recipient, ?DocumentVersion $version = null): static
    {
        return $this->state(fn () => array_filter([
            'recipient_id' => $recipient->id,
            'envelope_id' => $recipient->envelope_id,
            'organization_id' => $recipient->organization_id,
            'document_version_id' => $version?->id,
        ]));
    }

    public function onPage(int $page): static
    {
        return $this->state(fn () => ['page' => $page]);
    }

    public function signature(): static
    {
        return $this->state(fn () => ['type' => FieldType::Signature, 'width' => 0.30, 'height' => 0.06]);
    }

    /**
     * Rubrica na posição padrão (RECONCILIACAO Q10).
     */
    public function initials(): static
    {
        return $this->state(fn () => [
            'type' => FieldType::Initials,
            'x' => 0.86,
            'y' => 0.94,
            'width' => 0.10,
            'height' => 0.04,
        ]);
    }

    public function name(): static
    {
        return $this->state(fn () => ['type' => FieldType::Name, 'width' => 0.30, 'height' => 0.03]);
    }

    public function date(): static
    {
        return $this->state(fn () => [
            'type' => FieldType::Date,
            'width' => 0.15,
            'height' => 0.03,
            'options' => ['date_format' => 'd/m/Y'],
        ]);
    }

    public function text(?string $label = null): static
    {
        return $this->state(fn () => [
            'type' => FieldType::Text,
            'width' => 0.30,
            'height' => 0.03,
            'label' => $label ?? 'Texto livre',
            'options' => ['font_size' => 10],
        ]);
    }

    public function checkbox(?string $label = null): static
    {
        return $this->state(fn () => [
            'type' => FieldType::Checkbox,
            'width' => 0.02,
            'height' => 0.015,
            'label' => $label ?? 'Li e concordo',
        ]);
    }

    public function optional(): static
    {
        return $this->state(fn () => ['required' => false]);
    }
}
