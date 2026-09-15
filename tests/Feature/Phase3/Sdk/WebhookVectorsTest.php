<?php

use App\Services\Webhooks\WebhookSignature;

require_once __DIR__.'/../../../../tools/sdkgen/webhook_vectors.php';

/*
|--------------------------------------------------------------------------
| G-SDK: vetores da assinatura dos webhooks (docs/fase-3/sdks.md §6)
|--------------------------------------------------------------------------
| sdks/testdata/webhook-signature-vectors.json vem de App\Services\Webhooks\WebhookSignature e
| é conferido pelos três SDKs. Se o algoritmo do servidor mudar, este teste falha: regenere
| (php tools/sdkgen/webhook_vectors.php --write) e os testes dos SDKs mostram o que ajustar.
*/

test('o arquivo versionado é o que a implementação real produz', function () {
    $committed = json_decode((string) file_get_contents(base_path('sdks/testdata/webhook-signature-vectors.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($committed)->toEqual(sdkgen_webhook_vectors(), 'Vetores desatualizados: rode php tools/sdkgen/webhook_vectors.php --write');
});

test('os vetores cobrem aceite e recusa, e cada um confere de novo com o verify() real', function () {
    $vectors = sdkgen_webhook_vectors();
    $outcomes = array_count_values(array_map(static fn (array $case): string => $case['expected'] ? 'aceita' : 'recusa', $vectors['verify']));

    expect($outcomes['aceita'] ?? 0)->toBeGreaterThanOrEqual(8)
        ->and($outcomes['recusa'] ?? 0)->toBeGreaterThanOrEqual(20)
        ->and($vectors['default_tolerance'])->toBe(WebhookSignature::DEFAULT_TOLERANCE_SECONDS);

    foreach ($vectors['verify'] as $case) {
        $body = base64_decode($case['body_base64'], true);

        expect(WebhookSignature::verify($case['secret'], $case['signature_header'], $case['timestamp_header'], (string) $body, $case['now'], $case['tolerance']))
            ->toBe($case['expected'], $case['name']);
    }
});
