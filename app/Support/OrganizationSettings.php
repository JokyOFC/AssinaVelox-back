<?php

namespace App\Support;

use App\Enums\SigningOrder;
use App\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * Leitura tipada de `organizations.settings` (JSON). Centraliza as chaves usadas pelas
 * telas de Configurações para que o restante do código não conheça o formato do JSON.
 *
 * Chaves (além das de Organization::DEFAULT_SETTINGS):
 *  - contact_email, require_two_factor, session_idle_hours, deletion_requested_at
 *  - default_signing_order, initials_on_all_pages, allow_typed_signature, allow_uploaded_signature
 *
 * `deletion_requested_at` vive aqui porque o schema de B1 não tem coluna própria
 * (documentado em docs/autorizacao-e-isolamento.md).
 */
final class OrganizationSettings
{
    public function __construct(private readonly Organization $organization) {}

    public static function of(Organization $organization): self
    {
        return new self($organization);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->organization->setting($key, $default);
    }

    public function contactEmail(): ?string
    {
        $value = $this->get('contact_email');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function requireTwoFactor(): bool
    {
        return (bool) $this->get('require_two_factor', false);
    }

    public function sessionIdleHours(): ?int
    {
        $value = $this->get('session_idle_hours');

        return $value === null ? null : (int) $value;
    }

    public function deletionRequestedAt(): ?Carbon
    {
        $value = $this->get('deletion_requested_at');

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    public function deletionScheduledFor(): ?Carbon
    {
        return $this->deletionRequestedAt()?->addDays((int) config('assinavelox.organization_deletion_grace_days', 30));
    }

    public function defaultExpirationDays(): int
    {
        return (int) $this->get('default_expiration_days', (int) config('assinavelox.default_expiration_days', 30));
    }

    public function defaultSigningOrder(): SigningOrder
    {
        return SigningOrder::tryFrom((string) $this->get('default_signing_order', SigningOrder::Sequential->value))
            ?? SigningOrder::Sequential;
    }

    public function initialsOnAllPages(): bool
    {
        return (bool) $this->get('initials_on_all_pages', false);
    }

    public function allowTypedSignature(): bool
    {
        return (bool) $this->get('allow_typed_signature', true);
    }

    public function allowUploadedSignature(): bool
    {
        return (bool) $this->get('allow_uploaded_signature', true);
    }

    /**
     * Persiste um conjunto de chaves preservando as demais.
     *
     * @param  array<string, mixed>  $values
     */
    public function put(array $values): void
    {
        $settings = $this->organization->settings ?? [];

        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($settings[$key]);

                continue;
            }

            $settings[$key] = $value instanceof Carbon ? $value->toIso8601String() : $value;
        }

        $this->organization->forceFill(['settings' => $settings])->save();
    }
}
