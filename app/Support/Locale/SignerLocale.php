<?php

namespace App\Support\Locale;

/**
 * Idiomas da página pública e dos e-mails ao participante (Fase 3 §3.3 — F-I18N,
 * docs/fase-3/multilingue.md). A lista é FECHADA: um valor que não está aqui nunca vira
 * caminho de arquivo, chave de sessão ou nome de dicionário — é descartado.
 *
 * `pt_BR` é o idioma de REFERÊNCIA: o texto jurídico gravado como evidência é sempre o dele. Em
 * `en` e `es` o texto jurídico exibido é tradução de cortesia até a revisão profissional.
 */
enum SignerLocale: string
{
    case PtBr = 'pt_BR';
    case En = 'en';
    case Es = 'es';

    /** Nome do idioma no próprio idioma (endônimo), como aparece no seletor. */
    public function label(): string
    {
        return match ($this) {
            self::PtBr => 'Português (Brasil)',
            self::En => 'English',
            self::Es => 'Español',
        };
    }

    /** Etiqueta BCP 47 para `Intl`/`lang=`. */
    public function bcp47(): string
    {
        return match ($this) {
            self::PtBr => 'pt-BR',
            self::En => 'en',
            self::Es => 'es',
        };
    }

    public function isReference(): bool
    {
        return $this === self::reference();
    }

    public static function reference(): self
    {
        return self::PtBr;
    }

    /**
     * Converte um valor vindo de fora (formulário, sessão, banco) SEM confiar nele: só uma das
     * strings exatas da lista vira idioma; qualquer outra coisa é `null`.
     */
    public static function tryFromInput(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Linha do PDF de evidências (que é sempre em PT-BR) sobre o idioma em que a página foi
     * exibida ao participante. `null` para um valor fora da lista.
     */
    public static function evidenceLabel(?string $code): ?string
    {
        $locale = self::tryFromInput($code);

        if ($locale === null) {
            return null;
        }

        return $locale->isReference()
            ? sprintf('Idioma da página: %s.', $locale->label())
            : sprintf(
                'Idioma da página: %s — textos jurídicos exibidos em tradução de cortesia; o texto aceito e registrado é o de referência, em português (Brasil).',
                $locale->label(),
            );
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $locale): array => ['code' => $locale->value, 'label' => $locale->label()],
            self::cases(),
        );
    }
}
