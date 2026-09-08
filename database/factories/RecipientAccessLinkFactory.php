<?php

namespace Database\Factories;

use App\Enums\AccessLinkPurpose;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecipientAccessLink>
 */
class RecipientAccessLinkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recipient_id' => Recipient::factory(),
            'envelope_id' => fn (array $attributes) => self::recipient($attributes)->envelope_id,
            'organization_id' => fn (array $attributes) => self::recipient($attributes)->organization_id,
            'document_version_id' => fn (array $attributes) => self::versionFor($attributes),
            'token_digest' => RecipientAccessLink::digestFor(Str::random(43)),
            'purpose' => AccessLinkPurpose::Signing,
            'expires_at' => now()->addDays(30),
            'revoked_at' => null,
            'last_used_at' => null,
            'use_count' => 0,
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
     * Reaproveita a versão enviada do envelope ou cria documento + versão.
     *
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

    public function forRecipient(Recipient $recipient): static
    {
        return $this->state(fn () => [
            'recipient_id' => $recipient->id,
            'envelope_id' => $recipient->envelope_id,
            'organization_id' => $recipient->organization_id,
        ]);
    }

    public function download(): static
    {
        return $this->state(fn () => ['purpose' => AccessLinkPurpose::Download]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()->subHour()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function used(int $count = 1): static
    {
        return $this->state(fn () => [
            'use_count' => $count,
            'last_used_at' => now()->subMinutes(30),
        ]);
    }
}
