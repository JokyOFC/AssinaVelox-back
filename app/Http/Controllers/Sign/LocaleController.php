<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSignerToken;
use App\Support\Locale\SignerLocale;
use App\Support\Locale\SignerLocales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * O participante troca o idioma de EXIBIÇÃO da página pública — `POST assinar/{token}/idioma`,
 * rota `sign.locale.update` (Fase 3 §3.3 — F-I18N, docs/fase-3/multilingue.md §4).
 *
 * Vale para esta sessão do navegador e vai para a trilha (`recipient.display_locale_changed`).
 * Não altera `recipients.locale` (o que o remetente registrou para os e-mails). Não exige o
 * código: quem abre o link precisa entender a tela antes de pedir o código. 404 com a flag
 * `multilingual` desligada. O valor é conferido contra a lista fechada de idiomas.
 */
class LocaleController extends Controller
{
    public function __construct(private readonly SignerLocales $locales) {}

    public function update(Request $request, string $token): RedirectResponse
    {
        $context = ResolveSignerToken::context($request);

        abort_unless(SignerLocales::enabledFor($context->organization), 404);

        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(SignerLocale::values())],
        ], [
            'locale.required' => __('signer_ui.locale.invalid'),
            'locale.in' => __('signer_ui.locale.invalid'),
        ]);

        $locale = SignerLocale::tryFromInput($validated['locale']) ?? SignerLocale::reference();

        $this->locales->switchTo($context, $request, $locale);

        return redirect()->route('sign.show', ['token' => $token]);
    }
}
