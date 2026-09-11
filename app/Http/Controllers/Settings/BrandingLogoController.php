<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\BrandingLogoRequest;
use App\Services\Branding\BrandingFeature;
use App\Services\Branding\BrandingManager;
use App\Services\Branding\Exceptions\LogoRejectedException;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logo da organização: envio e remoção (Configurações › Marca) e a entrega pública.
 *
 * ## Entrega pública (`GET /marca/logo.png?v={token}`)
 *
 * O logo aparece em e-mails e na página pública do signatário, então precisa de uma URL
 * sem login. Ela carrega **só** o token opaco da versão do logo (40 hex, trocado a cada
 * envio): a mesma URL para todos os destinatários, sem nada que identifique pessoa,
 * mensagem ou envelope. Não é pixel de rastreamento — não há como saber QUEM abriu.
 *
 * Token desconhecido, logo removido, marca desligada: a resposta é um PNG transparente de
 * 1×1 com status 200, igual para todos os casos. Assim a rota não vira oráculo de
 * existência de tokens, e e-mails antigos (de um logo já trocado) não mostram imagem
 * quebrada.
 */
class BrandingLogoController extends Controller
{
    public function __construct(private readonly BrandingManager $manager) {}

    public function store(BrandingLogoRequest $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();

        abort_unless(BrandingFeature::enabled($organization), 403);

        $file = $request->file('logo');
        $path = $file instanceof UploadedFile ? (string) $file->getRealPath() : '';

        try {
            $this->manager->replaceLogo($organization, $request->user(), $path);
        } catch (LogoRejectedException $exception) {
            return back()->withErrors(['logo' => $exception->getMessage()]);
        }

        return back()->with('success', 'Logo atualizado.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('updateSettings', $organization);

        abort_unless(BrandingFeature::enabled($organization), 403);

        $this->manager->removeLogo($organization, $request->user());

        return back()->with('success', 'Logo removido.');
    }

    public function show(Request $request): Response
    {
        $token = $request->query('v');
        $branding = is_string($token) ? $this->manager->findByLogoToken($token) : null;

        $bytes = $branding !== null && BrandingFeature::enabled($branding->organization)
            ? $this->manager->logoBytes($branding)
            : null;

        if ($bytes === null) {
            return $this->image(self::transparentPixel(), 'no-store');
        }

        // O token muda a cada logo novo: o conteúdo de uma URL nunca muda.
        return $this->image($bytes, 'public, max-age=604800, immutable');
    }

    private function image(string $bytes, string $cacheControl): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public static function transparentPixel(): string
    {
        static $pixel = null;

        if (is_string($pixel)) {
            return $pixel;
        }

        $image = imagecreatetruecolor(1, 1);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagesetpixel($image, 0, 0, (int) imagecolorallocatealpha($image, 255, 255, 255, 127));

        ob_start();
        imagepng($image);
        $pixel = (string) ob_get_clean();
        imagedestroy($image);

        return $pixel;
    }
}
