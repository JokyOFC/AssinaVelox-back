<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes do widget de assinatura embutida (G-EMBED, docs/fase-3/widget-embutido.md)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Enums\SigningOrder;
use App\Models\Organization;
use App\Models\Recipient;
use App\Services\Embed\AllowedOrigins;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../../Phase2/Api/Support/ApiHelpers.php';
require_once __DIR__.'/../../../Sign/Support/SignerHelpers.php';

if (! defined('EMBED_ORIGIN')) {
    define('EMBED_ORIGIN', 'https://cliente.example.com.br');
}

if (! function_exists('embedEnable')) {
    /**
     * Liga `api_integrations` e `embedded_signing` na configuração global E no plano vigente (o
     * plano free é compartilhado pelas organizações dos testes).
     */
    function embedEnable(Organization $organization, bool $plan = true): void
    {
        apiEnable($organization);
        config()->set('assinavelox.features.embedded_signing', true);

        $current = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($current === null) {
            return;
        }

        $features = (array) ($current->features ?? []);
        $features['embedded_signing'] = $plan;
        $current->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('embedScenario')) {
    /**
     * Envelope em andamento (signerEnvelope), flag ligada, origem cadastrada e token da API do owner
     * com todas as abilities.
     *
     * @param  list<array<string, mixed>>  $recipients
     * @param  list<string>  $origins
     * @return array<string, mixed>
     */
    function embedScenario(array $recipients = [], SigningOrder $order = SigningOrder::Sequential, array $origins = [EMBED_ORIGIN]): array
    {
        $scenario = signerEnvelope($recipients, $order);

        embedEnable($scenario['organization']);
        AllowedOrigins::replace($scenario['organization'], $origins);

        $scenario['api_token'] = apiIssueToken($scenario['organization'], $scenario['owner']);
        $scenario['recipient'] = array_values($scenario['recipients'])[0];

        return $scenario;
    }
}

if (! function_exists('embedCreateUrl')) {
    /**
     * @param  array<string, mixed>  $scenario
     */
    function embedCreateUrl(array $scenario, ?Recipient $recipient = null): string
    {
        return route('api.v1.envelopes.recipients.embedded_sessions.store', [
            'envelope' => $scenario['envelope']->ulid,
            'recipient' => ($recipient ?? $scenario['recipient'])->ulid,
        ]);
    }
}

if (! function_exists('embedCreate')) {
    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $body
     */
    function embedCreate(object $test, array $scenario, array $body = [], ?string $key = null, ?Recipient $recipient = null): TestResponse
    {
        return $test->postJson(
            embedCreateUrl($scenario, $recipient),
            $body + ['origin' => EMBED_ORIGIN],
            apiHeaders($scenario['api_token'], apiIdem($key)),
        );
    }
}

if (! function_exists('embedTokenFrom')) {
    function embedTokenFrom(string $url): string
    {
        $fragment = (string) parse_url($url, PHP_URL_FRAGMENT);

        return str_starts_with($fragment, 't=') ? substr($fragment, 2) : '';
    }
}

if (! function_exists('embedSessionIdFrom')) {
    function embedSessionIdFrom(string $url): string
    {
        preg_match('#/embed/v1/sessoes/([0-9A-Za-z]{26})#', $url, $matches);

        return $matches[1] ?? '';
    }
}

if (! function_exists('embedHeaders')) {
    /**
     * @return array<string, string>
     */
    function embedHeaders(string $runtime): array
    {
        return ['Authorization' => 'Bearer '.$runtime, 'Accept' => 'application/json'];
    }
}

if (! function_exists('embedOpen')) {
    /**
     * Cria a sessão pela API e faz a troca pela URL de uso único.
     *
     * @param  array<string, mixed>  $scenario
     * @return array{id: string, runtime: string, url: string}
     */
    function embedOpen(object $test, array $scenario, ?Recipient $recipient = null): array
    {
        $url = (string) embedCreate($test, $scenario, [], null, $recipient)->assertCreated()->json('data.url');
        $id = embedSessionIdFrom($url);

        $runtime = (string) $test->postJson(route('embed.exchange', ['session' => $id]), [
            'token' => embedTokenFrom($url),
        ])->assertOk()->json('token');

        return ['id' => $id, 'runtime' => $runtime, 'url' => $url];
    }
}

if (! function_exists('embedAuthenticate')) {
    /**
     * Pede e confirma o código pelo widget (o teste precisa ter `$this->codes`). Devolve o estado.
     *
     * @param  array{id: string, runtime: string, url: string}  $open
     * @return array<string, mixed>
     */
    function embedAuthenticate(object $test, array $open): array
    {
        $test->postJson(route('embed.otp.send', ['session' => $open['id']]), [], embedHeaders($open['runtime']))->assertOk();

        $codes = $test->codes;

        return (array) $test->postJson(route('embed.otp.verify', ['session' => $open['id']]), [
            'code' => $codes[count($codes) - 1],
        ], embedHeaders($open['runtime']))->assertOk()->json('state');
    }
}

if (! function_exists('embedAcceptancePayload')) {
    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    function embedAcceptancePayload(array $state, bool $interaction = true): array
    {
        $payload = [
            'authorization' => $state['authorization']['token'] ?? '',
            'consent' => true,
            'fields' => [],
            'signature' => ['method' => 'draw', 'kind' => 'drawn', 'image_base64' => base64_encode(pngBytes())],
        ];

        if ($interaction) {
            $payload['interaction'] = ['confirmed' => true, 'visible' => true, 'method' => 'io_v2', 'delay_ms' => 900];
        }

        return $payload;
    }
}
