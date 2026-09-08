<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Termos de uso e Política de Privacidade (ROUTES §1.3 legal.*): Markdown de docs/juridico
 * convertido em HTML (sem HTML bruto do arquivo) e cacheado.
 */
class LegalController extends Controller
{
    public function terms(): Response
    {
        return $this->render('legal/terms', 'termos-de-uso.md');
    }

    public function privacy(): Response
    {
        return $this->render('legal/privacy', 'politica-de-privacidade.md');
    }

    protected function render(string $component, string $file): Response
    {
        $path = base_path('docs/juridico/'.$file);

        $document = Cache::remember('legal:'.$file.':'.(File::exists($path) ? File::lastModified($path) : 0), 3600, function () use ($path): array {
            if (! File::exists($path)) {
                return ['content_html' => null, 'updated_at' => null];
            }

            $markdown = File::get($path);

            return [
                'content_html' => (string) Str::markdown($markdown, [
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                    'max_nesting_level' => 20,
                ]),
                'updated_at' => date(DATE_ATOM, File::lastModified($path)),
            ];
        });

        return Inertia::render($component, [
            'content_html' => $document['content_html'],
            'version' => (string) config('assinavelox.terms_version'),
            'updated_at' => $document['updated_at'],
        ]);
    }
}
