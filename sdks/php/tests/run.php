<?php

declare(strict_types=1);

/*
 * Testes do SDK PHP contra o servidor falso (tools/sdkgen/fake_server.py).
 *
 *     python tools/sdkgen/sdkgen.py test            # sobe o servidor e roda os três SDKs
 *     FAKE_API_URL=http://127.0.0.1:8765/api/v1 php sdks/php/tests/run.php
 */

require __DIR__.'/../autoload.php';
require __DIR__.'/support.php';
require __DIR__.'/client_test.php';
require __DIR__.'/webhook_test.php';
require __DIR__.'/generated_operations.php';

$url = getenv('FAKE_API_URL');

if (! is_string($url) || $url === '') {
    fwrite(STDERR, "FAKE_API_URL não definido. Rode: python tools/sdkgen/sdkgen.py test\n");
    exit(2);
}

$transports = extension_loaded('curl') ? ['curl', 'stream'] : ['stream'];
$failures = 0;
$total = 0;

foreach ($transports as $transport) {
    $context = new TestContext(rtrim($url, '/'), $transport);

    foreach (SdkTest::$tests as $name => $test) {
        $total++;

        try {
            $test($context);
            fwrite(STDOUT, "ok - [{$transport}] {$name}\n");
        } catch (Throwable $exception) {
            $failures++;
            fwrite(STDOUT, "not ok - [{$transport}] {$name}: ".get_class($exception).': '.$exception->getMessage()."\n");
        }
    }
}

fwrite(STDOUT, sprintf("\n%d testes, %d falhas (transportes: %s)\n", $total, $failures, implode(', ', $transports)));

exit($failures === 0 ? 0 : 1);
