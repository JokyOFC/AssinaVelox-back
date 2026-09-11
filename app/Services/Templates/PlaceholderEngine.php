<?php

namespace App\Services\Templates;

/**
 * Motor de substituição RESTRITO dos modelos (roadmap §2.1, T6).
 *
 * Não é Blade, não é PHP, não é uma linguagem de template: a única coisa que ele sabe
 * fazer é trocar um marcador por um valor, numa passada só.
 *
 *  - HTML: `{{ chave }}`; DOCX: `${chave}`. `chave` = `[a-z][a-z0-9_]{0,63}`.
 *  - Nenhuma expressão, filtro, condição, laço, inclusão ou chamada — `{{ 1+1 }}` ou
 *    `{{ $x }}` não são marcadores e ficam no texto como estão.
 *  - A troca é feita em UMA passada (`preg_replace_callback`): um valor que contenha
 *    `{{outra}}` ou `${outra}` é inserido literalmente e nunca é reprocessado.
 *  - O valor é escapado para o formato de destino (HTML aqui; XML em
 *    {@see RestrictedTemplateProcessor}). Chave desconhecida vira texto vazio.
 */
final class PlaceholderEngine
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public const HTML_MARKER = '/\{\{\s*([a-z][a-z0-9_]{0,63})\s*\}\}/';

    /** Qualquer coisa entre chaves duplas — para acusar marcadores malformados. */
    public const HTML_ANY = '/\{\{(.{0,200}?)\}\}/s';

    public const DOCX_MARKER = '/\$\{([a-z][a-z0-9_]{0,63})\}/';

    public const DOCX_ANY = '/\$\{([^}]{0,300})\}/';

    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * Marcadores válidos (em ordem de aparição, sem repetição) e malformados.
     *
     * @return array{keys: list<string>, invalid: list<string>}
     */
    public static function scanHtml(string $html): array
    {
        return self::scan($html, self::HTML_MARKER, self::HTML_ANY, '{{', '}}');
    }

    /**
     * @return array{keys: list<string>, invalid: list<string>}
     */
    public static function scanDocx(string $xml): array
    {
        return self::scan($xml, self::DOCX_MARKER, self::DOCX_ANY, '${', '}');
    }

    /**
     * Substitui os marcadores do HTML (JÁ SANITIZADO) pelos valores formatados, escapados
     * para HTML. Quebras de linha do texto longo viram `<br>`.
     *
     * @param  array<string, string>  $formatted  texto puro por chave
     */
    public static function renderHtml(string $html, array $formatted): string
    {
        return preg_replace_callback(self::HTML_MARKER, static function (array $match) use ($formatted): string {
            $value = $formatted[$match[1]] ?? '';
            $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

            return str_replace("\n", "<br>\n", $escaped);
        }, $html) ?? '';
    }

    /**
     * @return array{keys: list<string>, invalid: list<string>}
     */
    private static function scan(string $content, string $valid, string $any, string $open, string $close): array
    {
        $keys = [];
        $invalid = [];

        if (preg_match_all($any, $content, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $marker = $match[0];

                if (preg_match($valid, $marker, $inner) === 1 && $inner[0] === $marker) {
                    $keys[$inner[1]] = true;

                    continue;
                }

                // Marcador malformado: mostra só o texto (tags XML de formatação removidas),
                // encurtado — nunca o documento inteiro.
                $text = trim(strip_tags($match[1]));
                $invalid[$open.mb_substr($text, 0, 60).$close] = true;
            }
        }

        return ['keys' => array_keys($keys), 'invalid' => array_keys($invalid)];
    }
}
