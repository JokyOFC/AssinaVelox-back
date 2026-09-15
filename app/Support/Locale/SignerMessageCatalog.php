<?php

namespace App\Support\Locale;

use Illuminate\Contracts\Translation\Loader;

/**
 * Tradução, na FRONTEIRA, das mensagens que os serviços do fluxo público escrevem em PT-BR
 * (Fase 3 §3.3 — F-I18N, docs/fase-3/multilingue.md §5).
 *
 * As mensagens de erro e de aviso do participante nascem em vários serviços (código, PIN,
 * aceite, recusa, captura, delegação). Em vez de tocar cada um, o texto PT-BR é o identificador:
 * `lang/{en,es}/signer_messages.php` mapeia o texto exato — ou um MODELO com `{nome}` para as
 * partes variáveis — para a tradução. Em PT-BR nada é consultado e a mensagem sai idêntica.
 *
 * Uma mensagem sem entrada no catálogo sai em PT-BR: nunca some, nunca vira chave. O teste de
 * navegador em inglês procura palavras-sentinela em português justamente para pegar esse caso.
 *
 * Dado não confiável (T6): o valor capturado de um `{nome}` (título, rótulo de campo, e-mail
 * mascarado) é devolvido como veio, como TEXTO — nada é avaliado. Só `{list}` passa de novo pelo
 * catálogo, item a item, porque carrega rótulos nossos ("Foto do rosto, Vídeo curto").
 */
final class SignerMessageCatalog
{
    /** @var array<string, array{exact: array<string, string>, patterns: list<array{regex: string, target: string}>}> */
    private static array $compiled = [];

    public static function translate(string $message, SignerLocale $locale): string
    {
        if ($locale->isReference() || $message === '') {
            return $message;
        }

        $catalog = self::compiled($locale);

        if (isset($catalog['exact'][$message])) {
            return $catalog['exact'][$message];
        }

        foreach ($catalog['patterns'] as $pattern) {
            if (preg_match($pattern['regex'], $message, $matches) !== 1) {
                continue;
            }

            $replacements = [];

            foreach ($matches as $name => $value) {
                if (! is_string($name)) {
                    continue;
                }

                $replacements['{'.$name.'}'] = $name === 'list'
                    ? self::translateList($value, $catalog['exact'])
                    : $value;
            }

            return strtr($pattern['target'], $replacements);
        }

        return $message;
    }

    /**
     * Traduz, se houver entrada, e devolve `null` para `null`.
     */
    public static function translateNullable(?string $message, SignerLocale $locale): ?string
    {
        return $message === null ? null : self::translate($message, $locale);
    }

    /**
     * Entradas cruas do catálogo (texto PT-BR => tradução).
     *
     * @return array<string, string>
     */
    public static function entries(SignerLocale $locale): array
    {
        /** @var Loader $loader */
        $loader = app('translator')->getLoader();
        $entries = $loader->load($locale->value, 'signer_messages');

        return array_filter($entries, static fn ($value, $key): bool => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH);
    }

    public static function flush(): void
    {
        self::$compiled = [];
    }

    /**
     * @return array{exact: array<string, string>, patterns: list<array{regex: string, target: string}>}
     */
    private static function compiled(SignerLocale $locale): array
    {
        if (isset(self::$compiled[$locale->value])) {
            return self::$compiled[$locale->value];
        }

        $exact = [];
        $patterns = [];

        foreach (self::entries($locale) as $source => $target) {
            if (preg_match('/\{[a-z_]+\}/', $source) !== 1) {
                $exact[$source] = $target;

                continue;
            }

            $patterns[] = ['regex' => self::regex($source), 'target' => $target];
        }

        // Modelos mais longos primeiro: o mais específico ganha.
        usort($patterns, static fn (array $a, array $b): int => strlen($b['regex']) <=> strlen($a['regex']));

        return self::$compiled[$locale->value] = ['exact' => $exact, 'patterns' => $patterns];
    }

    /**
     * "Aguarde {seconds}s para reenviar o código." → /^Aguarde (?P<seconds>.+?)s para …$/su
     */
    private static function regex(string $template): string
    {
        $parts = preg_split('/(\{[a-z_]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $regex = '';
        $seen = [];

        foreach ($parts as $part) {
            if (preg_match('/^\{([a-z_]+)\}$/', $part, $name) === 1 && ! isset($seen[$name[1]])) {
                $seen[$name[1]] = true;
                $regex .= '(?P<'.$name[1].'>.+?)';

                continue;
            }

            $regex .= preg_quote($part, '/');
        }

        return '/^'.$regex.'$/su';
    }

    /**
     * @param  array<string, string>  $exact
     */
    private static function translateList(string $value, array $exact): string
    {
        return implode(', ', array_map(
            static fn (string $item): string => $exact[$item] ?? $exact[mb_strtoupper(mb_substr($item, 0, 1)).mb_substr($item, 1)] ?? $item,
            explode(', ', $value),
        ));
    }
}
