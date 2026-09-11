<?php

namespace App\Services\Templates;

use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Models\Organization;
use App\Services\Envelopes\DomainFeatures;
use App\Services\Envelopes\FieldGeometry;
use App\Services\Envelopes\FieldSync;
use App\Services\Envelopes\PageBox;
use App\Support\OrganizationSettings;
use Illuminate\Validation\ValidationException;

/**
 * Valida e normaliza o que o editor de modelos envia (variáveis, papéis, campos, HTML) e
 * devolve uma {@see TemplateDefinition}. Nada do navegador é aceito sem conferência:
 *
 *  - variáveis: chave `[a-z][a-z0-9_]*` única, tipo conhecido, opções coerentes com o
 *    tipo, valor padrão válido para o tipo;
 *  - HTML: sanitizado ({@see HtmlSanitizer}); todo marcador usado precisa estar declarado
 *    e marcadores malformados são acusados;
 *  - DOCX: todo `${chave}` do arquivo precisa estar declarado;
 *  - papéis: 1 a 20, nomes únicos; testemunha/aprovador/visualizador só com a flag
 *    `participant_roles` (a mesma regra do wizard);
 *  - campos (só PDF fixo): mesmas regras de {@see FieldSync} — página existente, geometria
 *    validada contra `pages_meta` com {@see FieldGeometry}, visualizador sem campos,
 *    aprovador sem assinatura/rubrica, assinatura e rubrica sempre obrigatórias.
 */
final class TemplateDefinitionBuilder
{
    public const MAX_VARIABLES = 50;

    public const MAX_ROLES = 20;

    public const MAX_CHOICES = 50;

    public const ROLE_NAME_MAX = 40;

    private const A4 = ['width_pt' => 595.276, 'height_pt' => 841.89, 'rotation' => 0];

    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly VariableValues $values,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array{type: TemplateSourceType, page_count: int|null, pages_meta: array<int, array<string, mixed>>|null, placeholders: list<string>}  $source
     *
     * @throws ValidationException
     */
    public function build(Organization $organization, array $input, array $source): TemplateDefinition
    {
        $errors = [];
        $type = $source['type'];

        $signingOrder = in_array($input['signing_order'] ?? null, ['sequential', 'parallel'], true)
            ? (string) $input['signing_order']
            : OrganizationSettings::of($organization)->defaultSigningOrder()->value;

        $variables = $this->variables($type, self::list($input['variables'] ?? []), $errors);
        $keys = array_column($variables, 'key');

        $html = null;

        if ($type === TemplateSourceType::Html) {
            $html = $this->html((string) ($input['html_body'] ?? ''), $keys, $errors);
        }

        if ($type === TemplateSourceType::Docx) {
            $undeclared = array_values(array_diff($source['placeholders'], $keys));

            if ($undeclared !== []) {
                $errors['variables'] = sprintf(
                    'O arquivo usa variáveis que não foram declaradas: %s.',
                    implode(', ', array_slice($undeclared, 0, 10)),
                );
            }
        }

        $roles = $this->roles($organization, self::list($input['roles'] ?? []), $errors);

        $rawFields = self::list($input['fields'] ?? []);
        $fields = [];

        if ($type->supportsFields()) {
            $fields = $this->fields($rawFields, $roles, $source, $errors);
        } elseif ($rawFields !== []) {
            $errors['fields'] = 'Campos pré-posicionados só existem em modelos PDF fixo. Em modelos Word e HTML os campos são posicionados no passo 3 do documento gerado.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new TemplateDefinition($html, $signingOrder, $variables, $roles, $fields);
    }

    /**
     * O campo cabe na página `page` do PDF (existe e passa na geometria)? Usado ao trocar o
     * arquivo de um modelo PDF para descartar campos que não servem mais.
     *
     * @param  array<string, mixed>  $field
     * @param  array<int, array<string, mixed>>|null  $pagesMeta
     */
    public static function fieldFits(array $field, ?array $pagesMeta, int $pageCount): bool
    {
        $type = FieldType::tryFrom((string) ($field['type'] ?? ''));
        $page = (int) ($field['page'] ?? 0);

        if ($type === null || $page < 1 || $page > $pageCount) {
            return false;
        }

        return FieldGeometry::validate(
            $type,
            (float) ($field['x'] ?? 0),
            (float) ($field['y'] ?? 0),
            (float) ($field['w'] ?? $field['width'] ?? 0),
            (float) ($field['h'] ?? $field['height'] ?? 0),
            self::pageBox($pagesMeta, $page),
        ) === [];
    }

    // -- Variáveis ------------------------------------------------------------------------

    /**
     * @param  list<mixed>  $rows
     * @param  array<string, string>  $errors
     * @return list<array{key: string, label: string, type: string, required: bool, help_text: string|null, default_value: string|null, options: array<string, mixed>|null}>
     */
    private function variables(TemplateSourceType $type, array $rows, array &$errors): array
    {
        if ($rows !== [] && ! $type->supportsVariables()) {
            $errors['variables'] = 'Modelos PDF fixo não têm variáveis no corpo do documento.';

            return [];
        }

        if (count($rows) > self::MAX_VARIABLES) {
            $errors['variables'] = sprintf('Um modelo aceita no máximo %d variáveis.', self::MAX_VARIABLES);

            return [];
        }

        $variables = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors["variables.{$index}.key"] = 'Variável inválida.';

                continue;
            }

            $key = trim((string) ($row['key'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));
            $variableType = VariableType::tryFrom((string) ($row['type'] ?? ''));

            if (! PlaceholderEngine::isValidKey($key)) {
                $errors["variables.{$index}.key"] = 'Use só letras minúsculas, números e "_", começando por uma letra (ex.: nome_locatario).';

                continue;
            }

            if (isset($seen[$key])) {
                $errors["variables.{$index}.key"] = 'Já existe outra variável com esta chave.';

                continue;
            }

            $seen[$key] = true;

            if ($label === '' || mb_strlen($label) > 120) {
                $errors["variables.{$index}.label"] = 'Informe um rótulo de até 120 caracteres.';

                continue;
            }

            if ($variableType === null) {
                $errors["variables.{$index}.type"] = 'Tipo de variável inválido.';

                continue;
            }

            $options = $this->variableOptions($variableType, is_array($row['options'] ?? null) ? $row['options'] : [], $index, $errors);

            if ($options === false) {
                continue;
            }

            $help = trim((string) ($row['help_text'] ?? ''));
            $default = $row['default_value'] ?? null;
            $default = is_scalar($default) ? trim((string) $default) : '';

            if ($variableType === VariableType::Boolean && $default !== '') {
                $default = filter_var($default, FILTER_VALIDATE_BOOL) ? '1' : '0';
            }

            if ($default !== '') {
                [, $error] = $this->values->normalize($variableType, $options ?? [], $default, $label, false);

                if ($error !== null) {
                    $errors["variables.{$index}.default_value"] = 'Valor padrão inválido: '.$error;

                    continue;
                }
            }

            $variables[] = [
                'key' => $key,
                'label' => $label,
                'type' => $variableType->value,
                'required' => filter_var($row['required'] ?? false, FILTER_VALIDATE_BOOL),
                'help_text' => $help === '' ? null : mb_substr($help, 0, 255),
                'default_value' => $default === '' ? null : mb_substr($default, 0, VariableValues::LONG_TEXT_MAX),
                'options' => $options,
            ];
        }

        return $variables;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $errors
     * @return array<string, mixed>|null|false false = erro já registrado
     */
    private function variableOptions(VariableType $type, array $raw, int $index, array &$errors): array|null|false
    {
        $prefix = "variables.{$index}.options";

        switch ($type) {
            case VariableType::Text:
            case VariableType::LongText:
                $cap = $type === VariableType::Text ? VariableValues::TEXT_MAX : VariableValues::LONG_TEXT_MAX;

                if (isset($raw['max_length']) && $raw['max_length'] !== '') {
                    $max = filter_var($raw['max_length'], FILTER_VALIDATE_INT);

                    if ($max === false || $max < 1 || $max > $cap) {
                        $errors["{$prefix}.max_length"] = sprintf('O limite de caracteres deve estar entre 1 e %d.', $cap);

                        return false;
                    }

                    return ['max_length' => $max];
                }

                return null;

            case VariableType::Number:
                $options = [];

                foreach (['min', 'max'] as $bound) {
                    if (isset($raw[$bound]) && $raw[$bound] !== '') {
                        $parsed = VariableValues::decimalString((string) $raw[$bound]);

                        if ($parsed === null) {
                            $errors["{$prefix}.{$bound}"] = 'Informe um número válido.';

                            return false;
                        }

                        $options[$bound] = (float) $parsed;
                    }
                }

                if (isset($options['min'], $options['max']) && $options['min'] > $options['max']) {
                    $errors["{$prefix}.max"] = 'O máximo não pode ser menor que o mínimo.';

                    return false;
                }

                $decimals = filter_var($raw['decimals'] ?? 2, FILTER_VALIDATE_INT);

                if ($decimals === false || $decimals < 0 || $decimals > VariableValues::NUMBER_MAX_DECIMALS) {
                    $errors["{$prefix}.decimals"] = sprintf('Casas decimais: de 0 a %d.', VariableValues::NUMBER_MAX_DECIMALS);

                    return false;
                }

                return $options + ['decimals' => $decimals];

            case VariableType::Currency:
                $options = [];

                foreach (['min' => 'min_cents', 'max' => 'max_cents'] as $bound => $key) {
                    $value = $raw[$key] ?? null;

                    if (($value === null || $value === '') && isset($raw[$bound]) && $raw[$bound] !== '') {
                        $parsed = VariableValues::decimalString(preg_replace('/^R\$\s*/iu', '', (string) $raw[$bound]) ?? '');
                        $value = $parsed === null ? false : (int) round(((float) $parsed) * 100);
                    }

                    if ($value === null || $value === '') {
                        continue;
                    }

                    $cents = filter_var($value, FILTER_VALIDATE_INT);

                    if ($cents === false || $cents < 0 || $cents > VariableValues::CURRENCY_MAX_CENTS) {
                        $errors["{$prefix}.{$bound}"] = 'Informe um valor em reais válido.';

                        return false;
                    }

                    $options[$key] = $cents;
                }

                if (isset($options['min_cents'], $options['max_cents']) && $options['min_cents'] > $options['max_cents']) {
                    $errors["{$prefix}.max"] = 'O máximo não pode ser menor que o mínimo.';

                    return false;
                }

                return $options === [] ? null : $options;

            case VariableType::Date:
                $options = [];

                foreach (['min', 'max'] as $bound) {
                    if (isset($raw[$bound]) && is_string($raw[$bound]) && $raw[$bound] !== '') {
                        $iso = VariableValues::isoDate($raw[$bound]);

                        if ($iso === null) {
                            $errors["{$prefix}.{$bound}"] = 'Informe uma data válida.';

                            return false;
                        }

                        $options[$bound] = $iso;
                    }
                }

                if (isset($options['min'], $options['max']) && $options['min'] > $options['max']) {
                    $errors["{$prefix}.max"] = 'A data máxima não pode ser anterior à mínima.';

                    return false;
                }

                return $options === [] ? null : $options;

            case VariableType::Select:
                $choices = [];

                foreach (is_array($raw['choices'] ?? null) ? $raw['choices'] : [] as $choice) {
                    $choice = is_scalar($choice) ? trim((string) $choice) : '';

                    if ($choice !== '' && ! in_array($choice, $choices, true)) {
                        $choices[] = mb_substr($choice, 0, 120);
                    }
                }

                if ($choices === [] || count($choices) > self::MAX_CHOICES) {
                    $errors["{$prefix}.choices"] = sprintf('Informe de 1 a %d opções para a lista.', self::MAX_CHOICES);

                    return false;
                }

                return ['choices' => $choices];

            default:
                return null;
        }
    }

    // -- HTML ---------------------------------------------------------------------------

    /**
     * @param  list<string>  $keys
     * @param  array<string, string>  $errors
     */
    private function html(string $raw, array $keys, array &$errors): ?string
    {
        if (mb_strlen($raw) > HtmlSanitizer::MAX_LENGTH) {
            $errors['html_body'] = sprintf('O texto do modelo aceita no máximo %d caracteres.', HtmlSanitizer::MAX_LENGTH);

            return null;
        }

        $html = $this->sanitizer->sanitize($raw);

        if (trim(strip_tags($html)) === '') {
            $errors['html_body'] = 'Escreva o texto do modelo.';

            return null;
        }

        $scan = PlaceholderEngine::scanHtml($html);

        if ($scan['invalid'] !== []) {
            $errors['html_body'] = sprintf(
                'Marcadores inválidos: %s. Use {{nome_da_variavel}} com letras minúsculas, números e "_". Nenhuma expressão é aceita.',
                implode(', ', array_slice($scan['invalid'], 0, 5)),
            );

            return null;
        }

        $undeclared = array_values(array_diff($scan['keys'], $keys));

        if ($undeclared !== []) {
            $errors['html_body'] = sprintf(
                'Declare as variáveis usadas no texto: %s.',
                implode(', ', array_slice($undeclared, 0, 10)),
            );

            return null;
        }

        return $html;
    }

    // -- Papéis -------------------------------------------------------------------------

    /**
     * @param  list<mixed>  $rows
     * @param  array<string, string>  $errors
     * @return list<array{ref: string, name: string, participant_role: string}>
     */
    private function roles(Organization $organization, array $rows, array &$errors): array
    {
        if ($rows === []) {
            $errors['roles'] = 'Adicione pelo menos um participante (papel) ao modelo.';

            return [];
        }

        if (count($rows) > self::MAX_ROLES) {
            $errors['roles'] = sprintf('Um modelo aceita no máximo %d participantes.', self::MAX_ROLES);

            return [];
        }

        $allowNonSigner = DomainFeatures::participantRoles($organization);
        $roles = [];
        $refs = [];
        $names = [];
        $participates = false;

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors["roles.{$index}.name"] = 'Participante inválido.';

                continue;
            }

            $ref = trim((string) ($row['ref'] ?? ''));
            $name = trim(preg_replace('/\s+/u', ' ', (string) ($row['name'] ?? '')) ?? '');
            $role = RecipientRole::tryFrom((string) ($row['participant_role'] ?? RecipientRole::Signer->value));

            if ($ref === '' || strlen($ref) > 64 || isset($refs[$ref])) {
                $ref = 'role-'.$index;
            }

            if ($name === '' || mb_strlen($name) > self::ROLE_NAME_MAX) {
                $errors["roles.{$index}.name"] = sprintf('Informe o nome do participante (até %d caracteres), como "Locatário".', self::ROLE_NAME_MAX);

                continue;
            }

            $normalized = mb_strtolower($name);

            if (isset($names[$normalized])) {
                $errors["roles.{$index}.name"] = 'Já existe outro participante com este nome.';

                continue;
            }

            if ($role === null) {
                $errors["roles.{$index}.participant_role"] = 'Tipo de participante inválido.';

                continue;
            }

            if ($role !== RecipientRole::Signer && ! $allowNonSigner) {
                $errors["roles.{$index}.participant_role"] = 'Testemunha, aprovador e visualizador ainda não estão disponíveis para esta organização.';

                continue;
            }

            $refs[$ref] = true;
            $names[$normalized] = true;
            $participates = $participates || $role->participates();

            $roles[] = ['ref' => $ref, 'name' => $name, 'participant_role' => $role->value];
        }

        if ($roles !== [] && ! $participates && ! isset($errors['roles'])) {
            $errors['roles'] = 'Pelo menos um participante precisa assinar ou aprovar o documento.';
        }

        return $roles;
    }

    // -- Campos -------------------------------------------------------------------------

    /**
     * @param  list<mixed>  $rows
     * @param  list<array{ref: string, name: string, participant_role: string}>  $roles
     * @param  array{type: TemplateSourceType, page_count: int|null, pages_meta: array<int, array<string, mixed>>|null, placeholders: list<string>}  $source
     * @param  array<string, string>  $errors
     * @return list<array{role_ref: string, type: string, page: int, x: float, y: float, width: float, height: float, box_type: string, page_width_pt: float, page_height_pt: float, page_rotation: int, required: bool, label: string|null, options: array<string, mixed>|null}>
     */
    private function fields(array $rows, array $roles, array $source, array &$errors): array
    {
        if (count($rows) > FieldSync::MAX_FIELDS) {
            $errors['fields'] = sprintf('Um modelo aceita no máximo %d campos.', FieldSync::MAX_FIELDS);

            return [];
        }

        $pageCount = (int) ($source['page_count'] ?? 0);
        $rolesByRef = [];

        foreach ($roles as $role) {
            $rolesByRef[$role['ref']] = RecipientRole::from($role['participant_role']);
        }

        $fields = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors["fields.{$index}.type"] = 'Campo inválido.';

                continue;
            }

            $ref = (string) ($row['role_ref'] ?? '');
            $participant = $rolesByRef[$ref] ?? null;
            $type = FieldType::tryFrom((string) ($row['type'] ?? ''));

            if ($participant === null) {
                $errors["fields.{$index}.role_ref"] = 'Escolha um participante válido para o campo.';

                continue;
            }

            if ($type === null) {
                $errors["fields.{$index}.type"] = 'Tipo de campo inválido.';

                continue;
            }

            if (! $participant->allowsFields()) {
                $errors["fields.{$index}.role_ref"] = 'Visualizadores só recebem cópia do documento e não podem ter campos.';

                continue;
            }

            if ($type->isImageBased() && ! $participant->allowsVisualSignature()) {
                $errors["fields.{$index}.type"] = 'Aprovadores aprovam o conteúdo sem assinar: não podem ter campo de assinatura ou rubrica.';

                continue;
            }

            $page = filter_var($row['page'] ?? null, FILTER_VALIDATE_INT);

            if ($page === false || $page < 1 || $page > $pageCount) {
                $errors["fields.{$index}.page"] = sprintf('O modelo tem %d página(s); escolha uma página existente.', $pageCount);

                continue;
            }

            $x = (float) ($row['x'] ?? 0);
            $y = (float) ($row['y'] ?? 0);
            $width = (float) ($row['w'] ?? $row['width'] ?? 0);
            $height = (float) ($row['h'] ?? $row['height'] ?? 0);
            $box = self::pageBox($source['pages_meta'], $page);

            $geometryErrors = FieldGeometry::validate($type, $x, $y, $width, $height, $box);

            if ($geometryErrors !== []) {
                foreach ($geometryErrors as $attribute => $message) {
                    $errors["fields.{$index}.{$attribute}"] = $message;
                }

                continue;
            }

            $geometry = FieldGeometry::normalize($x, $y, $width, $height);
            $options = $this->fieldOptions($type, $row, $index, $errors);
            $label = is_string($row['label'] ?? null) ? trim($row['label']) : '';

            $fields[] = [
                'role_ref' => $ref,
                'type' => $type->value,
                'page' => $page,
                'x' => $geometry['x'],
                'y' => $geometry['y'],
                'width' => $geometry['width'],
                'height' => $geometry['height'],
                'box_type' => $box->boxType->value,
                'page_width_pt' => $box->displayedWidth(),
                'page_height_pt' => $box->displayedHeight(),
                'page_rotation' => $box->rotation,
                // Mesma regra de FieldSync: assinatura e rubrica são sempre obrigatórias.
                'required' => match ($type) {
                    FieldType::Signature, FieldType::Initials => true,
                    FieldType::Checkbox => filter_var($row['required'] ?? false, FILTER_VALIDATE_BOOL),
                    default => filter_var($row['required'] ?? true, FILTER_VALIDATE_BOOL),
                },
                'label' => $label === '' ? null : mb_substr($label, 0, 120),
                'options' => $options,
            ];
        }

        return $fields;
    }

    /**
     * Mesmas opções aceitas por FieldSync (tamanho de fonte, formato de data, valor inicial
     * da caixa de seleção, texto de ajuda).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $errors
     * @return array<string, mixed>|null
     */
    private function fieldOptions(FieldType $type, array $row, int $index, array &$errors): ?array
    {
        $incoming = is_array($row['options'] ?? null) ? $row['options'] : [];
        $options = [];

        $placeholder = $row['placeholder'] ?? $incoming['placeholder'] ?? null;

        if (is_string($placeholder) && trim($placeholder) !== '') {
            $options['placeholder'] = mb_substr(trim($placeholder), 0, 60);
        }

        if (in_array($type, [FieldType::Name, FieldType::Date, FieldType::Text], true) && isset($incoming['font_size'])) {
            $size = $incoming['font_size'];

            if (! is_numeric($size) || (float) $size < FieldSync::MIN_FONT_SIZE || (float) $size > FieldSync::MAX_FONT_SIZE) {
                $errors["fields.{$index}.options.font_size"] = sprintf('O tamanho da fonte deve estar entre %d e %d pontos.', FieldSync::MIN_FONT_SIZE, FieldSync::MAX_FONT_SIZE);
            } else {
                $options['font_size'] = (float) $size;
            }
        }

        if ($type === FieldType::Date) {
            $format = $incoming['date_format'] ?? FieldSync::DATE_FORMATS[0];
            $options['date_format'] = in_array($format, FieldSync::DATE_FORMATS, true) ? $format : FieldSync::DATE_FORMATS[0];
        }

        if ($type === FieldType::Checkbox) {
            $options['default'] = filter_var($incoming['default'] ?? false, FILTER_VALIDATE_BOOL);
        }

        return $options === [] ? null : $options;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $pagesMeta
     */
    private static function pageBox(?array $pagesMeta, int $page): PageBox
    {
        $meta = $pagesMeta[$page - 1] ?? null;
        $box = is_array($meta) ? PageBox::fromPageMeta($meta) : PageBox::fromPageMeta(self::A4);

        return $box->isDegenerate() ? PageBox::fromPageMeta(self::A4) : $box;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
