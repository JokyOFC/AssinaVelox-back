<?php

namespace Database\Factories;

use App\Enums\AuthMethod;
use App\Enums\SignatureKind;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SignatureAcceptance>
 */
class SignatureAcceptanceFactory extends Factory
{
    public const CONSENT_STATEMENT = 'Declaro que li o documento apresentado e manifesto minha concordância com seu conteúdo, '
        .'ciente de que este aceite eletrônico é registrado com data, IP e método de autenticação.';

    public function definition(): array
    {
        return [
            'recipient_id' => Recipient::factory()->signed(),
            'envelope_id' => fn (array $attributes) => self::recipient($attributes)->envelope_id,
            'organization_id' => fn (array $attributes) => self::recipient($attributes)->organization_id,
            'document_version_id' => fn (array $attributes) => self::versionFor($attributes),
            'signing_session_id' => null,
            'auth_challenge_id' => null,
            'accepted_at' => fn (array $attributes) => self::recipient($attributes)->signed_at ?? now(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'auth_method' => AuthMethod::EmailOtp,
            'terms_version' => '2026-09',
            'consent_statement' => self::CONSENT_STATEMENT,
            'document_sha256' => fn (array $attributes) => DocumentVersion::withoutOrganizationScope()
                ->whereKey($attributes['document_version_id'])->firstOrFail()
                ->sha256,
            'fields_snapshot' => [],
            'signature_kind' => SignatureKind::Drawn,
            'signature_image_path' => fn (array $attributes) => sprintf(
                'organizations/%d/signatures/%s.png',
                $attributes['organization_id'],
                Str::ulid(),
            ),
            'typed_name' => null,
            'typed_font' => null,
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
            'accepted_at' => $recipient->signed_at,
        ]));
    }

    public function typed(?string $name = null): static
    {
        return $this->state(fn (array $attributes) => [
            'signature_kind' => SignatureKind::Typed,
            'signature_image_path' => null,
            'typed_name' => $name ?? self::recipient($attributes)->name,
            'typed_font' => 'Caveat',
        ]);
    }

    public function uploaded(): static
    {
        return $this->state(fn () => ['signature_kind' => SignatureKind::Uploaded]);
    }
}
