<?php

declare(strict_types=1);

use AssinaVelox\Sdk\Exception\WebhookSignatureException;
use AssinaVelox\Sdk\Webhook\WebhookSignature;

/*
 * Assinatura dos webhooks: vetores gerados pelo PHP real (sdks/testdata).
 */

function sdk_webhook_vectors(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../testdata/webhook-signature-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
}

SdkTest::add('webhook: vetores de compute', static function (TestContext $t): void {
    foreach (sdk_webhook_vectors()['compute'] as $case) {
        $t->same($case['signature'], WebhookSignature::compute($case['secret'], $case['timestamp'], base64_decode($case['body_base64'], true)), $case['name']);
    }
});

SdkTest::add('webhook: vetores de header', static function (TestContext $t): void {
    foreach (sdk_webhook_vectors()['header'] as $case) {
        $t->same($case['header'], WebhookSignature::header($case['secrets'], $case['timestamp'], base64_decode($case['body_base64'], true)), $case['name']);
    }
});

SdkTest::add('webhook: vetores de verify', static function (TestContext $t): void {
    $vectors = sdk_webhook_vectors();
    $t->same(WebhookSignature::DEFAULT_TOLERANCE_SECONDS, $vectors['default_tolerance']);

    foreach ($vectors['verify'] as $case) {
        $result = WebhookSignature::verify(
            $case['secret'],
            $case['signature_header'],
            $case['timestamp_header'],
            base64_decode($case['body_base64'], true),
            $case['now'],
            $case['tolerance'],
        );
        $t->same($case['expected'], $result, $case['name']);
    }
});

SdkTest::add('webhook: constructEvent', static function (TestContext $t): void {
    $case = sdk_webhook_vectors()['verify'][0];
    $body = base64_decode($case['body_base64'], true);
    $headers = ['x-assinavelox-signature' => [$case['signature_header']], 'X-ASSINAVELOX-TIMESTAMP' => $case['timestamp_header']];

    $event = WebhookSignature::constructEvent($body, $headers, $case['secret'], 300, $case['now']);
    $t->same(json_decode($body, true), $event);

    $t->throws(WebhookSignatureException::class, static fn () => WebhookSignature::constructEvent($body.' ', $headers, $case['secret'], 300, $case['now']));
    $t->throws(WebhookSignatureException::class, static fn () => WebhookSignature::constructEvent($body, [], $case['secret'], 300, $case['now']));
});
