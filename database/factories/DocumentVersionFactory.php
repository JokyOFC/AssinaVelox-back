<?php

namespace Database\Factories;

use App\Enums\ActorType;
use App\Enums\DocumentVersionKind;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    public function definition(): array
    {
        $pages = fake()->numberBetween(1, 6);

        return [
            'document_id' => Document::factory(),
            'organization_id' => fn (array $attributes) => Document::withoutOrganizationScope()
                ->whereKey($attributes['document_id'])->firstOrFail()
                ->organization_id,
            'version_number' => fn (array $attributes) => Document::withoutOrganizationScope()
                ->whereKey($attributes['document_id'])->firstOrFail()
                ->nextVersionNumber(),
            'kind' => DocumentVersionKind::Original,
            'storage_disk' => 'documents',
            'storage_path' => fn (array $attributes) => sprintf(
                'organizations/%d/documents/%d/%s.pdf',
                $attributes['organization_id'],
                $attributes['document_id'],
                Str::ulid(),
            ),
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(20_000, 2_000_000),
            'sha256' => hash('sha256', Str::random(64)),
            'page_count' => $pages,
            'pages_meta' => self::pagesMeta($pages),
            'is_encrypted' => false,
            'has_signatures' => false,
            'created_by_type' => ActorType::System,
            'created_by_id' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function pagesMeta(int $pages, float $width = 595.276, float $height = 841.89): array
    {
        return array_map(fn () => [
            'width_pt' => $width,
            'height_pt' => $height,
            'rotation' => 0,
            'mediabox' => [0, 0, $width, $height],
            'cropbox' => [0, 0, $width, $height],
        ], range(1, $pages));
    }

    public function forDocument(Document $document): static
    {
        return $this->state(fn () => [
            'document_id' => $document->id,
            'organization_id' => $document->organization_id,
        ]);
    }

    public function original(): static
    {
        return $this->state(fn () => ['kind' => DocumentVersionKind::Original]);
    }

    public function converted(): static
    {
        return $this->state(fn () => ['kind' => DocumentVersionKind::Converted]);
    }

    public function consolidated(): static
    {
        return $this->state(fn () => ['kind' => DocumentVersionKind::Consolidated]);
    }

    public function evidence(): static
    {
        return $this->state(fn () => [
            'kind' => DocumentVersionKind::Evidence,
            'page_count' => 1,
            'pages_meta' => self::pagesMeta(1),
        ]);
    }

    public function final(bool $signed = false): static
    {
        return $this->state(fn () => [
            'kind' => DocumentVersionKind::Final,
            'has_signatures' => $signed,
        ]);
    }

    public function docxOriginal(): static
    {
        return $this->state(fn () => [
            'kind' => DocumentVersionKind::Original,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'storage_path' => fn (array $attributes) => sprintf(
                'organizations/%d/documents/%d/%s.docx',
                $attributes['organization_id'],
                $attributes['document_id'],
                Str::ulid(),
            ),
            'page_count' => null,
            'pages_meta' => null,
        ]);
    }
}
