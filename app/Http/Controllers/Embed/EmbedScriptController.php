<?php

namespace App\Http\Controllers\Embed;

use App\Http\Controllers\Controller;
use App\Services\Embed\EmbedFeature;
use App\Services\Embed\EmbedFrame;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /embed/v1/embed.js` (`embed.script`, docs/fase-3/widget-embutido.md §9).
 *
 * A fonte é `resources/js/embed/embed.ts`, entrada própria do Vite. O build gera um arquivo com
 * hash em `public/build/assets`; esta rota dá a ele um endereço ESTÁVEL e versionado pelo
 * caminho (`/v1/`), que é o que o site do cliente referencia. Uma mudança incompatível do
 * protocolo ganha `/v2/` e a v1 continua servida.
 *
 * Cache curto (5 min) — uma correção chega aos sites em minutos — e
 * `Cross-Origin-Resource-Policy: cross-origin` (EmbedFrame::markScript), porque o script é
 * carregado de outra origem. Com o Vite em modo de desenvolvimento, redireciona para ele.
 */
class EmbedScriptController extends Controller
{
    public const ENTRY = 'resources/js/embed/embed.ts';

    public function show(Request $request): Response
    {
        abort_unless(EmbedFeature::globallyEnabled(), 404);

        if (Vite::isRunningHot()) {
            return redirect()->away(Vite::asset(self::ENTRY));
        }

        $manifestPath = public_path('build/manifest.json');

        abort_unless(is_file($manifestPath), 404, 'O embed.js ainda não foi compilado.');

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $file = is_array($manifest) ? ($manifest[self::ENTRY]['file'] ?? null) : null;

        abort_unless(is_string($file) && $file !== '' && ! str_contains($file, '..'), 404, 'O embed.js ainda não foi compilado.');

        $path = public_path('build/'.$file);

        abort_unless(is_file($path), 404, 'O embed.js ainda não foi compilado.');

        EmbedFrame::markScript($request);

        return response()->file($path, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
