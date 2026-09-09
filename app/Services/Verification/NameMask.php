<?php

namespace App\Services\Verification;

use Illuminate\Support\Str;

/**
 * Mascaramento de nomes para a página pública de verificação
 * (ROUTES §4.2 e RECONCILIACAO §4 Q13: "Maria A. S.").
 *
 * A página é acessível a **qualquer pessoa** que tenha o código impresso no rodapé do PDF —
 * inclusive a quem recebeu o arquivo de terceiros. O nome completo de um signatário é dado
 * pessoal e não é necessário para o propósito da página (conferir integridade e estado). O
 * primeiro nome fica legível para que quem participou do ato se reconheça na lista; o
 * restante vira inicial.
 *
 * Partículas ("da", "de", "dos", "e", "van"…) são **omitidas** em vez de viram inicial:
 * "Maria da Silva" → "Maria S.", e não "Maria d. S.", que não informa nada e ainda sugere
 * um sobrenome inexistente.
 */
final class NameMask
{
    /**
     * Partículas de ligação que não geram inicial.
     *
     * @var list<string>
     */
    private const PARTICLES = [
        'da', 'das', 'de', 'del', 'della', 'di', 'do', 'dos', 'du',
        'e', 'la', 'le', 'van', 'von', 'y',
    ];

    /**
     * "Maria Aparecida da Silva" → "Maria A. S.".
     *
     * Um nome de uma palavra só permanece inteiro (não há o que abreviar); um nome vazio
     * devolve o travessão que a interface usa para ausência.
     */
    public static function mask(?string $name): string
    {
        $tokens = preg_split('/\s+/u', trim((string) $name)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));

        if ($tokens === []) {
            return '—';
        }

        // O primeiro nome sai como o remetente digitou: corrigir capitalização aqui mudaria
        // um dado de evidência por estética.
        $parts = [array_shift($tokens)];

        foreach ($tokens as $token) {
            if (in_array(Str::lower($token), self::PARTICLES, true)) {
                continue;
            }

            $initial = Str::upper(Str::substr($token, 0, 1));

            if ($initial !== '') {
                $parts[] = $initial.'.';
            }
        }

        return implode(' ', $parts);
    }
}
