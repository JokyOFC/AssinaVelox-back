<?php

/*
|--------------------------------------------------------------------------
| Conferência de respostas REAIS da API contra sdks/openapi/v1.json (+ overrides.json)
|--------------------------------------------------------------------------
| Os SDKs tipam as respostas pela especificação; se a API devolver algo fora dela (campo
| novo, tipo diferente, nulo não previsto), os SDKs quebram. Este validador cobre o
| subconjunto de JSON Schema que o Scramble emite e, no modo estrito, recusa propriedades
| que a especificação não descreve. Não contém testes.
*/

use Illuminate\Testing\TestResponse;

require_once __DIR__.'/SdkHelpers.php';

if (! function_exists('sdkContractRoot')) {
    /**
     * @return array<string, mixed>
     */
    function sdkContractRoot(): array
    {
        $spec = json_decode((string) file_get_contents(base_path('sdks/openapi/v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $spec['x-sdk'] = ['schemas' => sdkOverrides()['schemas'] ?? []];

        return $spec;
    }
}

if (! function_exists('sdkPointer')) {
    /**
     * @param  array<string, mixed>  $root
     * @return array<string, mixed>
     */
    function sdkPointer(array $root, string $ref): array
    {
        $node = $root;

        foreach (explode('/', substr($ref, 2)) as $part) {
            $node = $node[str_replace(['~1', '~0'], ['/', '~'], $part)];
        }

        return $node;
    }
}

if (! function_exists('sdkResponseSchema')) {
    /**
     * @param  array<string, mixed>  $root
     * @return array<string, mixed>|null null = resposta sem corpo
     */
    function sdkResponseSchema(array $root, string $operationId, int $status): ?array
    {
        $override = sdkOverrides()['operations'][$operationId]['response'] ?? null;

        if ($override !== null) {
            expect($override['statuses'])->toContain((string) $status);

            return $override['schema'];
        }

        foreach ($root['paths'] as $item) {
            foreach ($item as $operation) {
                if (($operation['operationId'] ?? null) !== $operationId) {
                    continue;
                }

                $response = $operation['responses'][(string) $status] ?? null;
                expect($response)->not->toBeNull("{$operationId} não documenta o status {$status}");

                while (isset($response['$ref'])) {
                    $response = sdkPointer($root, $response['$ref']);
                }

                return $response['content']['application/json']['schema'] ?? null;
            }
        }

        throw new RuntimeException("Operação {$operationId} não está em sdks/openapi/v1.json");
    }
}

if (! function_exists('sdkJsonType')) {
    function sdkJsonType(mixed $value, string $type): bool
    {
        return match ($type) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            default => true,
        };
    }
}

if (! function_exists('sdkValidate')) {
    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root
     * @return list<string>
     */
    function sdkValidate(mixed $value, array $schema, array $root, string $path = '$', bool $strict = true): array
    {
        if ($schema === []) {
            return [];
        }

        if (isset($schema['$ref'])) {
            return sdkValidate($value, sdkPointer($root, $schema['$ref']), $root, $path, $strict);
        }

        $variants = $schema['anyOf'] ?? $schema['oneOf'] ?? null;

        if (is_array($variants)) {
            $best = null;

            foreach ($variants as $variant) {
                $errors = sdkValidate($value, $variant, $root, $path, $strict);

                if ($errors === []) {
                    return [];
                }

                $best = $best === null || count($errors) < count($best) ? $errors : $best;
            }

            return ["{$path}: não corresponde a nenhuma variante (".implode('; ', array_slice($best ?? [], 0, 3)).')'];
        }

        $errors = [];

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = "{$path}: esperado ".json_encode($schema['const'], JSON_UNESCAPED_UNICODE);
        }

        $types = isset($schema['type']) ? (array) $schema['type'] : [];

        if ($types !== [] && ! array_filter($types, static fn (string $type): bool => sdkJsonType($value, $type))) {
            return [...$errors, "{$path}: tipo ".get_debug_type($value).', esperado '.implode('|', $types)];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: valor ".json_encode($value, JSON_UNESCAPED_UNICODE).' fora da lista';
        }

        if (is_array($value) && array_is_list($value) && $value !== [] && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                $errors = [...$errors, ...sdkValidate($item, $schema['items'], $root, "{$path}[{$index}]", $strict)];
            }
        }

        if (is_array($value) && ($value === [] || ! array_is_list($value)) && in_array('object', $types, true) || isset($schema['properties'])) {
            if (is_array($value) && ($value === [] || ! array_is_list($value))) {
                $properties = $schema['properties'] ?? [];

                foreach ($schema['required'] ?? [] as $name) {
                    if (! array_key_exists($name, $value)) {
                        $errors[] = "{$path}.{$name}: obrigatório na especificação, ausente na resposta";
                    }
                }

                $extra = $schema['additionalProperties'] ?? null;

                foreach ($value as $name => $item) {
                    if (array_key_exists($name, $properties)) {
                        $errors = [...$errors, ...sdkValidate($item, $properties[$name], $root, "{$path}.{$name}", $strict)];
                    } elseif (is_array($extra)) {
                        $errors = [...$errors, ...sdkValidate($item, $extra, $root, "{$path}.{$name}", $strict)];
                    } elseif ($extra === false || ($strict && $extra === null && $properties !== [])) {
                        $errors[] = "{$path}.{$name}: propriedade que a especificação não descreve";
                    }
                }
            }
        }

        return $errors;
    }
}

if (! function_exists('sdkAssertContract')) {
    /**
     * A resposta real bate com o que a especificação (e portanto os SDKs) promete.
     */
    function sdkAssertContract(string $operationId, TestResponse $response): void
    {
        $root = sdkContractRoot();
        $schema = sdkResponseSchema($root, $operationId, $response->getStatusCode());

        if ($schema === null) {
            expect($response->getContent())->toBe('', "{$operationId}: a especificação não prevê corpo");

            return;
        }

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $errors = sdkValidate($body, $schema, $root);

        expect($errors)->toBe([], "{$operationId} ({$response->getStatusCode()}) fora da especificação:\n".implode("\n", $errors));
    }
}
