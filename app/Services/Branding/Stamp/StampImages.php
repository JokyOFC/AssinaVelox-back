<?php

namespace App\Services\Branding\Stamp;

use App\Models\Envelope;
use App\Models\Organization;
use App\Services\Branding\BrandingManager;
use App\Services\Branding\BrandingPresenter;

/**
 * Imagem do carimbo visual por organização e o **retrato congelado** usado no PDF.
 *
 * O carimbo que entra no documento consolidado não é redesenhado na finalização a partir
 * da marca "de agora": ele é congelado quando o participante dono do campo aceita
 * ({@see self::snapshot()}), e o caminho vai para `signing_field_values.image_path` — o
 * mesmo lugar da imagem de assinatura. Assim uma troca de logo no meio do fluxo não muda
 * o que já foi aceito.
 *
 * Contrato para `RecordAcceptance` (fora desta área), no `image_path` do valor do campo:
 * `FieldType::Stamp => $stampPath ??= app(StampImages::class)->snapshot($envelope)`.
 */
class StampImages
{
    public function __construct(
        private readonly BrandingPresenter $presenter,
        private readonly BrandingManager $manager,
        private readonly StampRenderer $renderer,
    ) {}

    /**
     * PNG do carimbo da organização, ou null quando a marca não está ativa.
     */
    public function render(?Organization $organization): ?string
    {
        $branding = $this->presenter->active($organization);

        if ($branding === null || $organization === null) {
            return null;
        }

        return $this->renderer->render(
            BrandingPresenter::displayName($branding, $organization),
            $this->manager->logoBytes($branding),
            BrandingPresenter::primary($branding),
            BrandingPresenter::accent($branding),
        );
    }

    /**
     * Grava (uma vez por conteúdo) o carimbo no disco privado, junto dos arquivos do
     * envelope, e devolve o caminho. Null quando a marca não está ativa: o campo fica
     * vazio no PDF — nunca se inventa um carimbo.
     */
    public function snapshot(Envelope $envelope): ?string
    {
        $organization = $envelope->organization;
        $bytes = $this->render($organization);

        if ($bytes === null) {
            return null;
        }

        $prefix = trim((string) config('assinavelox.upload.path_prefix', 'orgs'), '/');
        $path = sprintf(
            '%s/%s/envelopes/%s/stamp-%s.png',
            $prefix,
            $organization->ulid,
            $envelope->ulid,
            substr(hash('sha256', $bytes), 0, 32),
        );

        $disk = $this->manager->disk();

        if (! $disk->exists($path)) {
            $disk->put($path, $bytes);
        }

        return $path;
    }
}
