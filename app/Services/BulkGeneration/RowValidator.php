<?php

namespace App\Services\BulkGeneration;

use App\Models\TemplateRole;
use App\Models\TemplateVariable;
use App\Models\TemplateVersion;
use App\Rules\PhoneE164;
use App\Services\BulkGeneration\Spreadsheet\RejectedCell;
use App\Services\Templates\VariableType;
use App\Services\Templates\VariableValues;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;

/**
 * Pré-validação de UMA linha (dry run, sem criar nada), com as mesmas regras de "Usar modelo":
 *
 *  - variáveis pelo {@see VariableValues::normalize()} (a mesma validação por tipo do servidor);
 *  - participante: nome 2–120, e-mail RFC (o validador de `email:rfc`, até 255), sem e-mail
 *    repetido entre papéis; celular em E.164 só quando o canal existe;
 *  - célula recusada na leitura (fórmula, sinal de fórmula, erro de planilha) vira erro da
 *    coluna — o valor NUNCA é usado.
 *
 * Os erros guardam coluna, cabeçalho e mensagem — nunca o valor digitado. O `payload` só leva
 * as colunas mapeadas, como texto; a geração revalida tudo de novo.
 */
final class RowValidator
{
    /** @var array<string, TemplateVariable> */
    private array $variables = [];

    /** @var array<string, TemplateRole> */
    private array $roles = [];

    private EmailValidator $emails;

    /**
     * @param  list<array{column: int, target: string}>  $mapping
     * @param  list<string>  $headers
     */
    public function __construct(
        private readonly VariableValues $values,
        TemplateVersion $version,
        private readonly array $mapping,
        private readonly array $headers,
    ) {
        $version->loadMissing(['variables', 'roles']);

        foreach ($version->variables as $variable) {
            $this->variables[$variable->key] = $variable;
        }

        foreach ($version->roles as $role) {
            $this->roles[$role->ulid] = $role;
        }

        $this->emails = new EmailValidator;
    }

    /**
     * @param  array<int, string|RejectedCell>  $cells
     * @return array{payload: array{title?: string, values: array<string, string>, participants: array<string, array<string, string>>}|null, errors: list<array{column: int|null, header: string|null, message: string}>}
     */
    public function validate(array $cells): array
    {
        $errors = [];
        $values = [];
        $participants = [];
        $title = null;

        foreach ($this->mapping as ['column' => $column, 'target' => $target]) {
            $cell = $cells[$column] ?? '';

            if ($cell instanceof RejectedCell) {
                $errors[] = $this->error($column, self::sentence($cell->message));

                // Participante com a célula recusada: já tem o erro da coluna; não acusa de novo
                // como "preencha".
                if (preg_match('/^role:([0-9A-Za-z]{26}):(name|email|phone)$/', $target, $match)) {
                    $participants[$match[1]][$match[2]] = ['column' => $column, 'value' => null];
                }

                continue;
            }

            if ($target === ColumnMapping::TITLE) {
                $title = $this->title($cell, $column, $errors);

                continue;
            }

            if (str_starts_with($target, 'var:')) {
                $variable = $this->variables[substr($target, 4)] ?? null;

                if ($variable !== null) {
                    $value = $this->variable($variable, $cell, $column, $errors);

                    if ($value !== null) {
                        $values[$variable->key] = $value;
                    }
                }

                continue;
            }

            if (preg_match('/^role:([0-9A-Za-z]{26}):(name|email|phone)$/', $target, $match)) {
                $participants[$match[1]][$match[2]] = ['column' => $column, 'value' => $cell];
            }
        }

        $normalizedParticipants = $this->participants($participants, $errors);

        if ($errors !== []) {
            return ['payload' => null, 'errors' => $errors];
        }

        $payload = ['values' => $values, 'participants' => $normalizedParticipants];

        if ($title !== null) {
            $payload['title'] = $title;
        }

        return ['payload' => $payload, 'errors' => []];
    }

    /**
     * @param  list<array{column: int|null, header: string|null, message: string}>  $errors
     */
    private function title(string $cell, int $column, array &$errors): ?string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $cell));

        if ($title === '') {
            return null;
        }

        if (mb_strlen($title) > 160) {
            $errors[] = $this->error($column, 'O título aceita no máximo 160 caracteres.');

            return null;
        }

        return $title;
    }

    /**
     * Valor da variável como TEXTO (a geração revalida). Célula vazia deixa o valor padrão do
     * modelo valer — ou acusa a obrigatória sem padrão.
     *
     * @param  list<array{column: int|null, header: string|null, message: string}>  $errors
     */
    private function variable(TemplateVariable $variable, string $cell, int $column, array &$errors): ?string
    {
        if ($cell === '') {
            $hasDefault = $variable->default_value !== null && $variable->default_value !== '';

            if ($variable->required && ! $hasDefault) {
                $errors[] = $this->error($column, sprintf('Preencha "%s".', $variable->label));
            }

            return null;
        }

        $raw = match ($variable->type) {
            VariableType::Boolean => self::boolean($cell),
            VariableType::Cpf => self::padDigits($cell, 11),
            VariableType::Cnpj => self::padDigits($cell, 14),
            default => $cell,
        };

        if ($raw === null) {
            $errors[] = $this->error($column, sprintf('Escolha sim ou não em "%s".', $variable->label));

            return null;
        }

        [, $error] = $this->values->normalize($variable->type, $variable->options ?? [], $raw, $variable->label, $variable->required);

        if ($error !== null) {
            $errors[] = $this->error($column, $error);

            return null;
        }

        return $raw;
    }

    /**
     * @param  array<string, array<string, array{column: int, value: string|null}>>  $input  `value` nulo = célula recusada (erro já registrado)
     * @param  list<array{column: int|null, header: string|null, message: string}>  $errors
     * @return array<string, array<string, string>>
     */
    private function participants(array $input, array &$errors): array
    {
        $result = [];
        $seen = [];

        foreach ($this->roles as $ulid => $role) {
            $name = $input[$ulid]['name'] ?? null;
            $email = $input[$ulid]['email'] ?? null;
            $phone = $input[$ulid]['phone'] ?? null;
            $row = [];
            $valid = true;

            $rejected = fn (?array $cell): bool => $cell !== null && $cell['value'] === null;

            if ($rejected($name) || $rejected($email) || $rejected($phone)) {
                continue;
            }

            $nameValue = trim((string) preg_replace('/\s+/u', ' ', (string) ($name['value'] ?? '')));

            if ($nameValue === '') {
                $errors[] = $this->error($name['column'] ?? null, sprintf('Preencha o nome de "%s".', $role->name));
                $valid = false;
            } elseif (mb_strlen($nameValue) < 2 || mb_strlen($nameValue) > 120) {
                $errors[] = $this->error($name['column'] ?? null, sprintf('O nome de "%s" deve ter de 2 a 120 caracteres.', $role->name));
                $valid = false;
            }

            $emailValue = mb_strtolower(trim((string) ($email['value'] ?? '')));

            if ($emailValue === '') {
                $errors[] = $this->error($email['column'] ?? null, sprintf('Preencha o e-mail de "%s".', $role->name));
                $valid = false;
            } elseif (mb_strlen($emailValue) > 255 || ! $this->emails->isValid($emailValue, new RFCValidation)) {
                $errors[] = $this->error($email['column'] ?? null, sprintf('Informe um e-mail válido para "%s".', $role->name));
                $valid = false;
            } elseif (isset($seen[$emailValue])) {
                $errors[] = $this->error($email['column'] ?? null, sprintf('O e-mail de "%s" já está em outro participante desta linha.', $role->name));
                $valid = false;
            } else {
                $seen[$emailValue] = true;
            }

            if ($phone !== null && trim($phone['value']) !== '') {
                if (PhoneE164::normalize(trim($phone['value'])) === null) {
                    $errors[] = $this->error($phone['column'], sprintf('Informe um celular válido com DDD para "%s", por exemplo +55 11 91234-5678.', $role->name));
                    $valid = false;
                } else {
                    $row['phone'] = trim($phone['value']);
                }
            }

            if ($valid) {
                $result[$ulid] = ['name' => $nameValue, 'email' => $emailValue] + $row;
            }
        }

        return $result;
    }

    /**
     * @return array{column: int|null, header: string|null, message: string}
     */
    private function error(?int $column, string $message): array
    {
        return [
            'column' => $column,
            'header' => $column !== null ? ($this->headers[$column] ?? null) : null,
            'message' => $message,
        ];
    }

    private static function sentence(string $message): string
    {
        return mb_strtoupper(mb_substr($message, 0, 1)).mb_substr($message, 1);
    }

    /** "sim"/"não" (e variações) → "1"/"0"; qualquer outra coisa → null. */
    private static function boolean(string $value): ?string
    {
        $normalized = ColumnMapping::normalize($value);

        return match (true) {
            in_array($normalized, ['sim', 's', 'yes', 'y', 'true', '1', 'verdadeiro', 'x'], true) => '1',
            in_array($normalized, ['nao', 'n', 'no', 'false', '0', 'falso'], true) => '0',
            default => null,
        };
    }

    /**
     * CPF/CNPJ vindos de célula numérica perdem os zeros à esquerda ("01234567890" vira
     * 1234567890). Só dígitos e 1–2 zeros faltando: completa. Os dígitos verificadores decidem.
     */
    private static function padDigits(string $value, int $length): string
    {
        if (ctype_digit($value) && strlen($value) < $length && strlen($value) >= $length - 2) {
            return str_pad($value, $length, '0', STR_PAD_LEFT);
        }

        return $value;
    }
}
