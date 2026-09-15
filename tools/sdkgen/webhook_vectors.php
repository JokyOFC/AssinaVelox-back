<?php

/*
|--------------------------------------------------------------------------
| Vetores de teste da assinatura dos webhooks de saída (docs/fase-3/sdks.md §6)
|--------------------------------------------------------------------------
|
| Gerados pela implementação REAL (App\Services\Webhooks\WebhookSignature) e conferidos pelos
| três SDKs. O valor `expected` de cada caso vem de WebhookSignature::verify(); nada é escrito
| à mão. tests/Feature/Phase3/Sdk/WebhookVectorsTest.php falha se o arquivo versionado
| (sdks/testdata/webhook-signature-vectors.json) divergir do que este script produz.
|
|   php tools/sdkgen/webhook_vectors.php           # imprime
|   php tools/sdkgen/webhook_vectors.php --write   # grava o arquivo versionado
|
*/

use App\Services\Webhooks\WebhookSignature;

if (! function_exists('sdkgen_webhook_vectors')) {
    /**
     * @return array<string, mixed>
     */
    function sdkgen_webhook_vectors(): array
    {
        $secretA = 'whsec_dGVzdGUtc2VncmVkby1hc3NpbmF2ZWxveC0wMDAx';
        $secretB = 'whsec_c2VncmVkby1hbnRlcmlvci1hc3NpbmF2ZWxveC0y';
        $secretC = 'whsec_b3V0cm8tc2VncmVkby1xdWUtbmFvLWNvbmZlcmU';
        $timestamp = 1789142400;
        $now = $timestamp + 42;

        $event = '{"id":"01K7Q3N6T2S8R4P1M0L9K7J5H3","type":"recipient.signed","version":1,"occurred_at":"2026-09-11T15:00:00Z","organization":{"id":"01K5ORGANIZACAO000000000000"},"data":{"envelope":{"id":"01K6ENVELOPE00000000000000","code":"AV-000123","status":"in_progress","status_label":"Em andamento"},"recipient":{"id":"01K6PARTICIPANTE0000000000","action":"electronic_acceptance","action_label":"Aceite eletrônico registrado (não é assinatura com certificado)"}}}';
        $binary = "\x00\xff\xfe\r\n{\"a\":1}\x80";

        $sig = static fn (string $secret, string $body, int $ts = 1789142400): string => WebhookSignature::compute($secret, $ts, $body);
        $valid = 'v1='.$sig($secretA, $event);

        $compute = [
            ['name' => 'evento JSON com acentos', 'secret' => $secretA, 'timestamp' => $timestamp, 'body' => $event],
            ['name' => 'mesmo corpo, outro segredo', 'secret' => $secretB, 'timestamp' => $timestamp, 'body' => $event],
            ['name' => 'corpo vazio', 'secret' => $secretA, 'timestamp' => $timestamp, 'body' => ''],
            ['name' => 'corpo binário (bytes fora de UTF-8)', 'secret' => $secretA, 'timestamp' => 1, 'body' => $binary],
        ];

        $headers = [
            ['name' => 'um segredo', 'secrets' => [$secretA], 'timestamp' => $timestamp, 'body' => $event],
            ['name' => 'rotação: vigente e anterior', 'secrets' => [$secretA, $secretB], 'timestamp' => $timestamp, 'body' => $event],
        ];

        // [nome, segredo do receptor, cabeçalho de assinatura, cabeçalho de timestamp, corpo, agora, tolerância]
        $verify = [
            ['assinatura correta', $secretA, $valid, (string) $timestamp, $event, $now, 300],
            ['rotação: receptor com o segredo anterior', $secretB, WebhookSignature::header([$secretA, $secretB], $timestamp, $event), (string) $timestamp, $event, $now, 300],
            ['rotação: receptor com o segredo vigente', $secretA, WebhookSignature::header([$secretA, $secretB], $timestamp, $event), (string) $timestamp, $event, $now, 300],
            ['segredo diferente', $secretC, $valid, (string) $timestamp, $event, $now, 300],
            ['corpo adulterado (um espaço a mais)', $secretA, $valid, (string) $timestamp, $event.' ', $now, 300],
            ['timestamp adulterado', $secretA, $valid, (string) ($timestamp + 1), $event, $now, 300],
            ['301 s depois: fora da janela', $secretA, $valid, (string) $timestamp, $event, $timestamp + 301, 300],
            ['exatamente 300 s depois: dentro da janela', $secretA, $valid, (string) $timestamp, $event, $timestamp + 300, 300],
            ['301 s antes (relógio adiantado): fora', $secretA, $valid, (string) $timestamp, $event, $timestamp - 301, 300],
            ['exatamente 300 s antes: dentro', $secretA, $valid, (string) $timestamp, $event, $timestamp - 300, 300],
            ['tolerância de 10 s, 11 s depois', $secretA, $valid, (string) $timestamp, $event, $timestamp + 11, 10],
            ['timestamp com letra', $secretA, $valid, $timestamp.'a', $event, $now, 300],
            ['timestamp vazio', $secretA, $valid, '', $event, $now, 300],
            ['timestamp negativo', $secretA, $valid, '-'.$timestamp, $event, $now, 300],
            ['timestamp com sinal +', $secretA, $valid, '+'.$timestamp, $event, $now, 300],
            ['timestamp com espaço antes', $secretA, $valid, ' '.$timestamp, $event, $now, 300],
            ['timestamp com uma quebra de linha no fim (aceita, como no PHP)', $secretA, $valid, $timestamp."\n", $event, $now, 300],
            ['timestamp com duas quebras de linha no fim', $secretA, $valid, $timestamp."\n\n", $event, $now, 300],
            ['timestamp com 13 dígitos (zeros à esquerda)', $secretA, $valid, '000'.$timestamp, $event, $now, 300],
            ['timestamp com um zero à esquerda (a assinatura usa o inteiro)', $secretA, $valid, '0'.$timestamp, $event, $now, 300],
            ['timestamp com dígitos não ASCII', $secretA, $valid, "\u{0661}\u{0667}\u{0668}\u{0669}\u{0661}\u{0664}\u{0662}\u{0664}\u{0660}\u{0660}", $event, $now, 300],
            ['assinatura em maiúsculas', $secretA, strtoupper($valid), (string) $timestamp, $event, $now, 300],
            ['espaços, tabulação e quebra em volta', $secretA, " \t".$valid." \n", (string) $timestamp, $event, $now, 300],
            ['espaço não separável antes (não é aparado)', $secretA, "\u{00A0}".$valid, (string) $timestamp, $event, $now, 300],
            ['versão v0', $secretA, 'v0='.$sig($secretA, $event), (string) $timestamp, $event, $now, 300],
            ['versão V1 maiúscula', $secretA, 'V1='.$sig($secretA, $event), (string) $timestamp, $event, $now, 300],
            ['espaço em volta do sinal de igual', $secretA, 'v1 = '.$sig($secretA, $event), (string) $timestamp, $event, $now, 300],
            ['cabeçalho vazio', $secretA, '', (string) $timestamp, $event, $now, 300],
            ['v1 sem valor', $secretA, 'v1=', (string) $timestamp, $event, $now, 300],
            ['v1 sem sinal de igual', $secretA, 'v1', (string) $timestamp, $event, $now, 300],
            ['primeira inválida, segunda válida', $secretA, 'v1=deadbeef,'.$valid, (string) $timestamp, $event, $now, 300],
            ['sinal de igual sobrando no fim', $secretA, $valid.'=', (string) $timestamp, $event, $now, 300],
            ['separador ponto e vírgula (não é separador)', $secretA, 'v1=deadbeef;'.$valid, (string) $timestamp, $event, $now, 300],
            ['assinatura truncada', $secretA, substr($valid, 0, -2), (string) $timestamp, $event, $now, 300],
            ['corpo vazio', $secretA, 'v1='.$sig($secretA, ''), (string) $timestamp, '', $now, 300],
            ['corpo binário', $secretA, 'v1='.$sig($secretA, $binary), (string) $timestamp, $binary, $now, 300],
        ];

        return [
            '_doc' => 'Gerado por tools/sdkgen/webhook_vectors.php com App\\Services\\Webhooks\\WebhookSignature. Não edite: rode php tools/sdkgen/webhook_vectors.php --write. Corpos em base64 (bytes brutos).',
            'algorithm' => 'X-AssinaVelox-Signature: v1=hex(HMAC-SHA256(segredo inteiro, "{timestamp inteiro}.{corpo bruto}")); várias assinaturas separadas por vírgula; tolerância |agora - timestamp| <= 300 s.',
            'default_tolerance' => WebhookSignature::DEFAULT_TOLERANCE_SECONDS,
            'compute' => array_map(static fn (array $case): array => [
                'name' => $case['name'],
                'secret' => $case['secret'],
                'timestamp' => $case['timestamp'],
                'body_base64' => base64_encode($case['body']),
                'signature' => WebhookSignature::compute($case['secret'], $case['timestamp'], $case['body']),
            ], $compute),
            'header' => array_map(static fn (array $case): array => [
                'name' => $case['name'],
                'secrets' => $case['secrets'],
                'timestamp' => $case['timestamp'],
                'body_base64' => base64_encode($case['body']),
                'header' => WebhookSignature::header($case['secrets'], $case['timestamp'], $case['body']),
            ], $headers),
            'verify' => array_map(static fn (array $case): array => [
                'name' => $case[0],
                'secret' => $case[1],
                'signature_header' => $case[2],
                'timestamp_header' => $case[3],
                'body_base64' => base64_encode($case[4]),
                'now' => $case[5],
                'tolerance' => $case[6],
                'expected' => WebhookSignature::verify($case[1], $case[2], $case[3], $case[4], $case[5], $case[6]),
            ], $verify),
        ];
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    require_once __DIR__.'/../../vendor/autoload.php';

    $json = json_encode(sdkgen_webhook_vectors(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

    if (in_array('--write', $argv, true)) {
        $target = __DIR__.'/../../sdks/testdata/webhook-signature-vectors.json';

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }

        file_put_contents($target, $json);
        fwrite(STDOUT, "Vetores gravados em sdks/testdata/webhook-signature-vectors.json\n");
    } else {
        fwrite(STDOUT, $json);
    }
}
