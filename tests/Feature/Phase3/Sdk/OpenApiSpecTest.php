<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/Support/SdkHelpers.php';

/*
|--------------------------------------------------------------------------
| G-SDK (roadmap §3.9): especificação OpenAPI versionada (docs/fase-3/sdks.md §3)
|--------------------------------------------------------------------------
| sdks/openapi/v1.json é a exportação ATUAL do Scramble. Se estes testes falharem porque a API
| mudou (rota nova, campo novo, regra nova), regenere a especificação e os SDKs:
|
|   tools/pdftool/.venv/Scripts/python.exe tools/sdkgen/sdkgen.py all
|
| Rota nova em /api/v1 também precisa de um nome de método em tools/sdkgen/overrides.json.
*/

test('sdks/openapi/v1.json é igual à exportação atual do Scramble', function () {
    $committed = json_decode((string) file_get_contents(base_path('sdks/openapi/v1.json')), false, 512, JSON_THROW_ON_ERROR);
    $exported = sdkExportSpec();

    $differences = [];
    sdkDifferences(sdkCanonical($committed), sdkCanonical($exported), '$', $differences);

    expect($differences)->toBe([], "A especificação exportada divergiu do arquivo versionado.\n".implode("\n", $differences)."\n".SDK_REGENERATE_HINT);
});

test('toda rota de /api/v1 está na especificação e em overrides.json, com a idempotência do middleware', function () {
    $spec = json_decode((string) file_get_contents(base_path('sdks/openapi/v1.json')), true, 512, JSON_THROW_ON_ERROR);
    $operations = sdkOverrides()['operations'];
    $seen = [];

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $path = '/'.substr($route->uri(), strlen('api/v1/'));

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $operation = $spec['paths'][$path][strtolower($method)] ?? null;

            expect($operation)->not->toBeNull("{$method} {$path} não está em sdks/openapi/v1.json. ".SDK_REGENERATE_HINT);

            $id = $operation['operationId'];
            $seen[] = $id;

            expect(array_key_exists($id, $operations))->toBeTrue("{$id} sem nome de método em tools/sdkgen/overrides.json (operations). ".SDK_REGENERATE_HINT);

            $middleware = $route->gatherMiddleware();
            $expected = match (true) {
                in_array('api.idempotent', $middleware, true) => 'required',
                in_array('api.idempotent:optional', $middleware, true) => 'optional',
                default => null,
            };

            expect($operations[$id]['idempotency'] ?? null)->toBe($expected, "Idempotency-Key de {$id} em overrides.json não bate com o middleware da rota.");
        }
    }

    expect($seen)->not->toBeEmpty()
        ->and(array_values(array_diff(array_keys($operations), $seen)))->toBe([], 'overrides.json cita operações que não existem mais.');
});

test('a especificação versionada descreve os buracos corrigidos por anotação (docs/fase-3/sdks.md §4)', function () {
    $schemas = json_decode((string) file_get_contents(base_path('sdks/openapi/v1.json')), true, 512, JSON_THROW_ON_ERROR)['components']['schemas'];

    expect($schemas['DocumentResource']['properties']['ready']['type'])->toBe('boolean')
        ->and($schemas['DocumentResource']['properties']['size_bytes']['type'])->toBe(['integer', 'null'])
        ->and($schemas['RecipientResource']['properties']['signed_at']['type'])->toBe(['string', 'null'])
        ->and($schemas['EventResource']['properties']['label'])->not->toHaveKey('enum')
        ->and($schemas['GenerateEnvelopeFromTemplateRequest']['properties']['participants']['type'])->toBe(['object', 'null'])
        ->and($schemas['SyncEnvelopeFieldsRequest']['properties']['fields']['items']['properties']['page']['type'])->toBe('integer');

    $detail = collect($schemas['EnvelopeResource']['anyOf'])->firstWhere(fn (array $variant): bool => isset($variant['properties']['documents']));

    expect($detail)->not->toBeNull()
        ->and($detail['properties']['documents']['items']['$ref'])->toBe('#/components/schemas/DocumentResource')
        ->and($detail['properties']['sent_at']['type'])->toBe(['string', 'null']);
});
