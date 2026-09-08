<?php

namespace Database\Factories;

use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentSourceType;
use App\Models\Document;
use App\Models\Envelope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->randomElement(['contrato', 'proposta', 'termo', 'aditivo', 'procuracao']).'-'.fake()->numberBetween(100, 999);

        return [
            'envelope_id' => Envelope::factory(),
            'organization_id' => fn (array $attributes) => Envelope::withoutOrganizationScope()
                ->withTrashed()
                ->whereKey($attributes['envelope_id'])->firstOrFail()
                ->organization_id,
            'name' => ucfirst($name),
            'original_filename' => $name.'.pdf',
            'source_type' => DocumentSourceType::Pdf,
            'processing_status' => DocumentProcessingStatus::Uploaded,
            'failure_code' => null,
            'failure_message' => null,
            'current_version_id' => null,
            'page_count' => null,
        ];
    }

    public function forEnvelope(Envelope $envelope): static
    {
        return $this->state(fn () => [
            'envelope_id' => $envelope->id,
            'organization_id' => $envelope->organization_id,
        ]);
    }

    public function docx(): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => DocumentSourceType::Docx,
            'original_filename' => preg_replace('/\.pdf$/', '.docx', $attributes['original_filename']),
        ]);
    }

    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => DocumentSourceType::Image,
            'original_filename' => preg_replace('/\.pdf$/', '.jpg', $attributes['original_filename']),
        ]);
    }

    public function converting(): static
    {
        return $this->state(fn () => ['processing_status' => DocumentProcessingStatus::Converting]);
    }

    public function ready(int $pages = 3): static
    {
        return $this->state(fn () => [
            'processing_status' => DocumentProcessingStatus::Ready,
            'page_count' => $pages,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'processing_status' => DocumentProcessingStatus::Failed,
            'failure_code' => 'conversion_failed',
            'failure_message' => 'Não foi possível converter o arquivo. Tente enviar um PDF.',
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => [
            'processing_status' => DocumentProcessingStatus::Blocked,
            'failure_code' => 'pdf_encrypted',
            'failure_message' => 'O PDF está protegido por senha e não pode ser preparado.',
        ]);
    }
}
