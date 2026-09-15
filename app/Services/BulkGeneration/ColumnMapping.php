<?php

namespace App\Services\BulkGeneration;

use App\Models\TemplateRole;
use App\Models\TemplateVariable;
use App\Models\TemplateVersion;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mapeamento "coluna da planilha → destino no modelo" (docs/fase-3/geracao-em-lote.md §4).
 *
 * Destinos (`target`):
 *  - `title` — título do documento (opcional; sem ele vale "Modelo — Participante");
 *  - `role:{ulid do papel}:name|email|phone` — participante de cada papel do modelo
 *    (`phone` só existe com o canal SMS/WhatsApp ligado para a organização);
 *  - `var:{chave}` — variável tipada do modelo.
 *
 * A sugestão compara o nome da coluna, sem acento/caixa/pontuação, com o rótulo e a chave de
 * cada destino. É só sugestão: o remetente confirma o mapeamento antes da pré-validação.
 */
final class ColumnMapping
{
    public const TITLE = 'title';

    /**
     * @return list<array{value: string, label: string, group: string, required: bool, kind: string, type: string|null}>
     */
    public static function targets(TemplateVersion $version, bool $phoneEnabled): array
    {
        $version->loadMissing(['variables', 'roles']);

        $targets = [[
            'value' => self::TITLE,
            'label' => 'Título do documento',
            'group' => 'Documento',
            'required' => false,
            'kind' => 'title',
            'type' => null,
        ]];

        foreach ($version->roles as $role) {
            /** @var TemplateRole $role */
            $targets[] = self::roleTarget($role, 'name', 'nome', true);
            $targets[] = self::roleTarget($role, 'email', 'e-mail', true);

            if ($phoneEnabled) {
                $targets[] = self::roleTarget($role, 'phone', 'celular', false);
            }
        }

        foreach ($version->variables as $variable) {
            /** @var TemplateVariable $variable */
            $targets[] = [
                'value' => 'var:'.$variable->key,
                'label' => $variable->label,
                'group' => 'Variáveis',
                'required' => $variable->required && ($variable->default_value === null || $variable->default_value === ''),
                'kind' => 'variable',
                'type' => $variable->type->value,
            ];
        }

        return $targets;
    }

    /**
     * Nomes de coluna da planilha-modelo (download "Baixar planilha modelo"). A sugestão
     * reconhece exatamente estes nomes.
     *
     * @return list<string>
     */
    public static function sampleHeaders(TemplateVersion $version, bool $phoneEnabled): array
    {
        $headers = [];

        foreach (self::targets($version, $phoneEnabled) as $target) {
            if ($target['kind'] !== 'title') {
                $headers[] = $target['label'];
            }
        }

        $headers[] = 'Título do documento';

        return $headers;
    }

    /**
     * @param  list<string>  $headers
     * @return list<array{column: int, target: string}>
     */
    public static function suggest(array $headers, TemplateVersion $version, bool $phoneEnabled): array
    {
        $candidates = self::candidates($version, $phoneEnabled);
        $used = [];
        $mapping = [];

        foreach ($headers as $column => $header) {
            $normalized = self::normalize($header);

            if ($normalized === '') {
                continue;
            }

            foreach ($candidates as $target => $names) {
                if (isset($used[$target]) || ! in_array($normalized, $names, true)) {
                    continue;
                }

                $used[$target] = true;
                $mapping[] = ['column' => (int) $column, 'target' => $target];

                break;
            }
        }

        return $mapping;
    }

    /**
     * Confere o mapeamento enviado pelo navegador (`coluna => destino`, destino vazio =
     * ignorar a coluna) contra o cabeçalho e a versão FIXADA do modelo.
     *
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $headers
     * @return list<array{column: int, target: string}>
     *
     * @throws ValidationException
     */
    public static function validate(array $input, array $headers, TemplateVersion $version, bool $phoneEnabled): array
    {
        $targets = [];

        foreach (self::targets($version, $phoneEnabled) as $target) {
            $targets[$target['value']] = $target;
        }

        $mapping = [];
        $used = [];
        $errors = [];

        foreach ($input as $column => $target) {
            if (! is_string($target) || $target === '') {
                continue;
            }

            if (! is_int($column) && ! ctype_digit((string) $column)) {
                $errors[] = 'O mapeamento enviado é inválido. Recarregue a página e tente de novo.';

                continue;
            }

            $column = (int) $column;

            if (! array_key_exists($column, $headers)) {
                $errors[] = 'O mapeamento cita uma coluna que não existe na planilha.';

                continue;
            }

            if (! isset($targets[$target])) {
                $errors[] = sprintf('A coluna "%s" foi ligada a um campo que não existe neste modelo.', $headers[$column]);

                continue;
            }

            if (isset($used[$target])) {
                $errors[] = sprintf('"%s" está ligado a mais de uma coluna. Escolha só uma.', $targets[$target]['label']);

                continue;
            }

            $used[$target] = true;
            $mapping[] = ['column' => $column, 'target' => $target];
        }

        foreach ($targets as $value => $target) {
            if ($target['required'] && ! isset($used[$value])) {
                $errors[] = sprintf('Escolha a coluna de "%s".', $target['label']);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['mapping' => array_values(array_unique($errors))]);
        }

        usort($mapping, fn (array $a, array $b): int => $a['column'] <=> $b['column']);

        return $mapping;
    }

    /**
     * "Locatário — E-mail" → "locatario e mail".
     */
    public static function normalize(string $value): string
    {
        $ascii = Str::ascii(mb_strtolower($value));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    /**
     * @return array{value: string, label: string, group: string, required: bool, kind: string, type: string|null}
     */
    private static function roleTarget(TemplateRole $role, string $field, string $label, bool $required): array
    {
        return [
            'value' => "role:{$role->ulid}:{$field}",
            'label' => "{$role->name} — {$label}",
            'group' => 'Participantes',
            'required' => $required,
            'kind' => 'participant',
            'type' => $field,
        ];
    }

    /**
     * @return array<string, list<string>> destino => nomes de coluna reconhecidos (normalizados)
     */
    private static function candidates(TemplateVersion $version, bool $phoneEnabled): array
    {
        $version->loadMissing(['variables', 'roles']);

        $single = $version->roles->count() === 1;
        $candidates = [self::TITLE => ['titulo', 'titulo do documento', 'title']];

        $synonyms = [
            'name' => ['nome', 'nome completo'],
            'email' => ['e mail', 'email'],
            'phone' => ['celular', 'telefone', 'whatsapp'],
        ];

        foreach ($version->roles as $role) {
            /** @var TemplateRole $role */
            $roleName = self::normalize($role->name);

            foreach ($synonyms as $field => $words) {
                if ($field === 'phone' && ! $phoneEnabled) {
                    continue;
                }

                $names = [];

                foreach ($words as $word) {
                    foreach (["{$roleName} {$word}", "{$word} {$roleName}", "{$word} do {$roleName}", "{$word} da {$roleName}"] as $name) {
                        $names[] = self::normalize($name);
                    }

                    if ($single) {
                        $names[] = $word;
                    }
                }

                $candidates["role:{$role->ulid}:{$field}"] = array_values(array_unique($names));
            }
        }

        foreach ($version->variables as $variable) {
            /** @var TemplateVariable $variable */
            $candidates['var:'.$variable->key] = array_values(array_unique(array_filter([
                self::normalize($variable->label),
                self::normalize($variable->key),
            ])));
        }

        return $candidates;
    }
}
