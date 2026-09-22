<?php

namespace App\Http\Middleware;

use App\Services\Signing\SignerContext;
use App\Support\Locale\SignerLocale;
use App\Support\Locale\SignerLocales;
use App\Support\Locale\SignerMessageCatalog;
use App\Support\Locale\SignerPropsLocalizer;
use Closure;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idioma da página pública (Fase 3 §3.3 — F-I18N, docs/fase-3/multilingue.md §4 e §5). Roda
 * DEPOIS de `signer` (precisa do contexto resolvido) em todo o grupo `sign.*`.
 *
 * Flag `multilingual` desligada para a organização: não faz nada — nenhuma prop nova, nenhuma
 * troca de idioma, nenhuma mensagem tocada.
 *
 * Ligada:
 *
 * 1. resolve o idioma ({@see SignerLocales::forContext()}) e o guarda na requisição — é daí que
 *    SignerPageController monta a prop `i18n` e o aceite grava `display_locale`;
 * 2. fora do PT-BR, traduz pelo catálogo, na FRONTEIRA, as mensagens que os serviços escreveram
 *    em PT-BR: os avisos da sessão (`success`, `info`…), a sacola de erros (e o `errors` do
 *    Inertia) e as respostas JSON (`message`, `errors`, blocos `identity_capture`/`identity_video`/
 *    `identity_verification`).
 *
 * O idioma da aplicação NÃO é trocado (ver o comentário em `handle`).
 */
class ApplySignerLocale
{
    /** Chaves de aviso da sessão lidas pela prop compartilhada `flash`. */
    private const FLASH_KEYS = ['success', 'error', 'warning', 'info', 'status'];

    public function __construct(private readonly SignerLocales $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->attributes->get(ResolveSignerToken::ATTRIBUTE);

        if (! $context instanceof SignerContext || ! SignerLocales::enabledFor($context->organization)) {
            return $next($request);
        }

        $locale = $this->locales->forContext($context, $request);
        // A prop `i18n` da página é montada por SignerPageController a partir deste atributo
        // (nada de `Inertia::share`: num processo longo ela vazaria para a requisição seguinte).
        $request->attributes->set(SignerLocales::ATTRIBUTE, $locale);

        if ($locale->isReference()) {
            return $next($request);
        }

        /*
         * O idioma da APLICAÇÃO não é trocado de propósito: na mesma requisição podem nascer
         * coisas que precisam continuar em PT-BR — a finalização (PDF de evidências) e os avisos
         * a quem enviou, quando a fila é síncrona. O que o participante lê é traduzido na fronteira:
         * as props por SignerPropsLocalizer e as mensagens por SignerMessageCatalog.
         */
        if ($request->hasSession()) {
            $this->translateSession($request, $locale);

            // O `errors` compartilhado pelo Inertia é resolvido ANTES das rotas (HandleInertiaRequests,
            // no grupo `web`). Depois de traduzir a sacola da sessão, ele é resolvido de novo, pela
            // mesma rotina do Inertia. A requisição seguinte o sobrescreve: nada vaza entre elas.
            if ($request->session()->has('errors')) {
                Inertia::share('errors', Inertia::always(
                    app(HandleInertiaRequests::class)->resolveValidationErrors($request),
                ));
            }
        }

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $this->translateJson($response, $locale);
        }

        return $response;
    }

    /**
     * Prop `i18n` da página pública.
     *
     * @return array<string, mixed>
     */
    public static function props(SignerContext $context, SignerLocale $locale): array
    {
        $timezone = SignerLocales::timezone($context->recipient, $context->organization);

        return [
            'locale' => $locale->value,
            'bcp47' => $locale->bcp47(),
            'reference_locale' => SignerLocale::reference()->value,
            'locales' => SignerLocale::options(),
            // A troca vale só para esta sessão (POST sign.locale.update).
            'switch_url' => route('sign.locale.update', ['token' => $context->token]),
            'time_zone' => in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : null,
            'legal_reviewed' => SignerPropsLocalizer::reviewed($locale),
        ];
    }

    private function translateSession(Request $request, SignerLocale $locale): void
    {
        $session = $request->session();

        foreach (self::FLASH_KEYS as $key) {
            $value = $session->get($key);

            if (is_string($value) && $value !== '') {
                // `put` numa chave de aviso não muda a lista `_flash.old`: ela continua sendo
                // apagada no fim desta requisição, como qualquer aviso.
                $session->put($key, SignerMessageCatalog::translate($value, $locale));
            }
        }

        $errors = $session->get('errors');

        if (! $errors instanceof ViewErrorBag) {
            return;
        }

        $translated = new ViewErrorBag;

        foreach ($errors->getBags() as $name => $bag) {
            $messages = [];

            foreach ($bag->getMessages() as $field => $list) {
                $messages[$field] = array_map(
                    static fn (mixed $message): mixed => is_string($message) ? SignerMessageCatalog::translate($message, $locale) : $message,
                    (array) $list,
                );
            }

            $translated->put($name, new MessageBag($messages));
        }

        $session->put('errors', $translated);
    }

    private function translateJson(JsonResponse $response, SignerLocale $locale): void
    {
        $data = $response->getData(true);

        if (! is_array($data)) {
            return;
        }

        if (is_string($data['message'] ?? null)) {
            $data['message'] = SignerMessageCatalog::translate($data['message'], $locale);
        }

        if (is_array($data['errors'] ?? null)) {
            foreach ($data['errors'] as $field => $list) {
                $data['errors'][$field] = is_array($list)
                    ? array_map(static fn (mixed $message): mixed => is_string($message) ? SignerMessageCatalog::translate($message, $locale) : $message, $list)
                    : (is_string($list) ? SignerMessageCatalog::translate($list, $locale) : $list);
            }
        }

        if (array_key_exists('identity_capture', $data)) {
            $data['identity_capture'] = SignerPropsLocalizer::captureBlock($data['identity_capture'], $locale);
        }

        if (array_key_exists('identity_video', $data)) {
            $data['identity_video'] = SignerPropsLocalizer::videoBlock($data['identity_video'], $locale);
        }

        if (array_key_exists('identity_verification', $data)) {
            $data['identity_verification'] = SignerPropsLocalizer::verificationBlock($data['identity_verification'], $locale);
        }

        $response->setData($data);
    }
}
