<?php

namespace App\Services\Branding;

use App\Models\Organization;
use App\Models\OrganizationBranding;

/**
 * O que cada superfície recebe da marca (docs/fase-2/branding.md §4).
 *
 * **Regra única:** a marca APARECE quando a flag `branding` está ligada para a organização
 * ({@see BrandingFeature}) **e** a organização salvou uma marca. Em qualquer outro caso
 * todos os métodos devolvem `null` e quem chama mantém exatamente o comportamento da
 * Fase 1 — é isso que deixa a integração com uma linha por ponto de uso.
 *
 * Sempre por organização explícita: nunca "a marca corrente" implícita. A página pública,
 * o e-mail na fila e a finalização não têm organização corrente, e uma marca de outra
 * organização aparecer ali seria vazamento entre clientes.
 *
 * "via AssinaVelox" não é removível: nenhum formato devolvido aqui tem opção de esconder a
 * operadora (whitelabel é backlog, roadmap §4).
 */
class BrandingPresenter
{
    public function __construct(private readonly BrandingManager $manager) {}

    /**
     * A marca que vale para a organização agora, ou null.
     */
    public function active(?Organization $organization): ?OrganizationBranding
    {
        if ($organization === null || ! BrandingFeature::enabled($organization)) {
            return null;
        }

        $branding = $this->manager->find($organization);

        if ($branding !== null) {
            $branding->setRelation('organization', $organization);
        }

        return $branding;
    }

    /**
     * URL pública do logo (mesma para todos os destinatários; só o token opaco).
     * Contrato: `current_organization.logo_url` (HandleInertiaRequests) e
     * `settings.general` → `organization.logo_url`.
     */
    public function logoUrl(?Organization $organization): ?string
    {
        $branding = $this->active($organization);

        return $branding !== null ? self::publicLogoUrl($branding) : null;
    }

    /**
     * Contrato para `SignerPageProps::sender()` (fora desta área):
     * `'logo_url' => $brand['logo_url'] ?? null, 'brand' => $brand`.
     *
     * @return array{display_name: string, logo_url: string|null, primary_color: string, accent_color: string, on_primary: string}|null
     */
    public function forSigner(?Organization $organization): ?array
    {
        $branding = $this->active($organization);

        if ($branding === null || $organization === null) {
            return null;
        }

        return [
            'display_name' => self::displayName($branding, $organization),
            'logo_url' => self::publicLogoUrl($branding),
            'primary_color' => self::primary($branding),
            'accent_color' => self::accent($branding),
            'on_primary' => ColorContrast::WHITE,
        ];
    }

    /**
     * Dados do tema do e-mail (resources/views/mail/branded.blade.php).
     *
     * @return array{display_name: string, logo_url: string|null, primary_color: string, accent_color: string, on_primary: string, operator: string}|null
     */
    public function forEmail(?Organization $organization): ?array
    {
        $signer = $this->forSigner($organization);

        if ($signer === null) {
            return null;
        }

        return $signer + ['operator' => (string) config('app.name', 'AssinaVelox')];
    }

    /**
     * Cabeçalho da página de evidências (DOMPDF sem rede: o logo vai como `data:` URI).
     * Só existe quando há logo — sem logo o cabeçalho continua o da Fase 1.
     *
     * Contrato para `EvidenceData::build()` (fora desta área):
     * `'branding' => app(BrandingPresenter::class)->forEvidence($organization)`.
     *
     * @return array{display_name: string, logo_data_uri: string, logo_width: int, logo_height: int}|null
     */
    public function forEvidence(?Organization $organization): ?array
    {
        $branding = $this->active($organization);

        if ($branding === null || $organization === null) {
            return null;
        }

        $bytes = $this->manager->logoBytes($branding);

        if ($bytes === null) {
            return null;
        }

        return [
            'display_name' => self::displayName($branding, $organization),
            'logo_data_uri' => 'data:image/png;base64,'.base64_encode($bytes),
            'logo_width' => (int) $branding->logo_width,
            'logo_height' => (int) $branding->logo_height,
        ];
    }

    /**
     * Props da tela Configurações › Marca — o que está SALVO, com ou sem flag.
     *
     * @return array<string, mixed>
     */
    public function forSettings(Organization $organization): array
    {
        $branding = $this->manager->find($organization);

        return [
            'display_name' => $branding?->display_name,
            'primary_color' => $branding?->primary_color,
            'accent_color' => $branding?->accent_color,
            'reply_to_email' => $branding?->reply_to_email,
            'sender_email' => $branding?->sender_email,
            'logo' => $branding !== null && $branding->hasLogo() ? [
                'url' => self::publicLogoUrl($branding),
                'width' => (int) $branding->logo_width,
                'height' => (int) $branding->logo_height,
                'bytes' => (int) $branding->logo_bytes,
            ] : null,
            'updated_at' => $branding?->updated_at?->toIso8601String(),
        ];
    }

    public static function publicLogoUrl(OrganizationBranding $branding): ?string
    {
        if (! $branding->hasLogo()) {
            return null;
        }

        return route('branding.logo', ['v' => $branding->logo_token]);
    }

    public static function displayName(OrganizationBranding $branding, Organization $organization): string
    {
        $name = trim((string) $branding->display_name);

        return $name !== '' ? $name : $organization->name;
    }

    public static function primary(OrganizationBranding $branding): string
    {
        return ColorContrast::normalize($branding->primary_color) ?? BrandingLimits::DEFAULT_PRIMARY;
    }

    public static function accent(OrganizationBranding $branding): string
    {
        return ColorContrast::normalize($branding->accent_color) ?? BrandingLimits::DEFAULT_ACCENT;
    }
}
