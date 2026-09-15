<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes dos conectores (G-CONN, docs/fase-3/conectores.md)
|--------------------------------------------------------------------------
| Incluído com require_once. Não contém testes. NENHUM teste depende de rede: Google, Dropbox e
| HubSpot são simulados com Http::fake + Http::preventStrayRequests, e o DNS é o resolvedor
| falso dos webhooks (FakeDnsResolver) — um host fora da lista simplesmente não resolve.
*/

use App\Enums\EnvelopeStatus;
use App\Integrations\GoogleDrive\GoogleDriveSource;
use App\Integrations\GoogleDrive\GoogleOAuthClient;
use App\Integrations\HubSpot\HubSpotSignature;
use App\Models\Envelope;
use App\Models\HubSpotConnection;
use App\Models\Organization;
use App\Models\User;
use App\Support\Http\DnsResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Phase2\Webhooks\Support\FakeDnsResolver;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../../Phase2/Templates/Support/TemplateHelpers.php';

if (! defined('CONNECTORS_PUBLIC_IP')) {
    // Endereço público qualquer (não é faixa reservada): o pino de IP aponta para ele, mas o
    // Http::fake responde antes de qualquer conexão.
    define('CONNECTORS_PUBLIC_IP', '142.250.79.10');
}

if (! function_exists('connectorsEnable')) {
    /**
     * Liga as flags: interruptor global E plano da organização. O plano "free" é compartilhado
     * entre as organizações de teste (o isolamento é testado com a flag ligada nas duas).
     */
    function connectorsEnable(Organization $organization, bool $cloud = true, bool $hubspot = true): void
    {
        config()->set('assinavelox.features.cloud_import', $cloud);
        config()->set('assinavelox.features.hubspot', $hubspot);

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);
        $features['cloud_import'] = $cloud;
        $features['hubspot'] = $hubspot;
        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('connectorsDns')) {
    /**
     * @param  array<string, list<string>>  $extra
     */
    function connectorsDns(array $extra = []): FakeDnsResolver
    {
        $resolver = new FakeDnsResolver;

        foreach (['oauth2.googleapis.com', 'www.googleapis.com', 'dl.dropboxusercontent.com', 'uc.dropboxusercontent.com', 'api.hubapi.com'] as $host) {
            $resolver->set($host, [CONNECTORS_PUBLIC_IP]);
        }

        foreach ($extra as $host => $addresses) {
            $resolver->set($host, $addresses);
        }

        app()->instance(DnsResolver::class, $resolver);

        return $resolver;
    }
}

if (! function_exists('connectorsConfigure')) {
    /**
     * Credenciais FALSAS dos apps (o que o proprietário registraria). Valores sentinela fáceis
     * de procurar em log e banco.
     */
    function connectorsConfigure(bool $google = true, bool $dropbox = true, bool $hubspot = true): void
    {
        config()->set('services.google_drive', $google ? [
            'client_id' => 'google-client-id.apps.test',
            'client_secret' => 'GOOGLE-CLIENT-SECRET-SENTINELA',
            'api_key' => 'google-picker-api-key',
            'app_id' => '123456789012',
        ] : []);
        config()->set('services.dropbox', $dropbox ? ['app_key' => 'dropbox-app-key'] : []);
        config()->set('services.hubspot', $hubspot ? [
            'client_id' => 'hubspot-client-id',
            'client_secret' => 'HUBSPOT-CLIENT-SECRET-SENTINELA',
            'app_id' => '999',
        ] : []);
    }
}

if (! function_exists('connectorsPdfBytes')) {
    function connectorsPdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }
}

if (! function_exists('connectorsPngBytes')) {
    function connectorsPngBytes(): string
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}

if (! function_exists('connectorsDraft')) {
    function connectorsDraft(Organization $organization, User $owner): Envelope
    {
        return Envelope::factory()->forOrganization($organization, $owner)->create(['status' => EnvelopeStatus::Draft]);
    }
}

if (! function_exists('connectorsCaptureLogs')) {
    /**
     * Tudo que for logado (mensagem + contexto serializado) durante o teste.
     */
    function connectorsCaptureLogs(): ArrayObject
    {
        $logs = new ArrayObject;

        Event::listen(MessageLogged::class, static function (MessageLogged $event) use ($logs): void {
            $logs->append($event->message.' '.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR));
        });

        return $logs;
    }
}

if (! function_exists('connectorsQueryFrom')) {
    /**
     * `state` e demais parâmetros da URL de autorização para onde o app redirecionou.
     *
     * @return array<string, string>
     */
    function connectorsQueryFrom(TestResponse $response): array
    {
        $location = (string) $response->headers->get('Location', $response->headers->get('X-Inertia-Location', ''));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return array_map('strval', $query);
    }
}

if (! function_exists('connectorsGoogleAuthorize')) {
    /**
     * Completa o OAuth do Google (início + retorno com o token simulado) para o envelope.
     * O Http::fake do teste precisa responder o endpoint de token.
     */
    function connectorsGoogleAuthorize(object $test, Envelope $envelope): void
    {
        $start = $test->get(route('cloud_import.google.start', $envelope));
        $start->assertRedirect();
        $query = connectorsQueryFrom($start);

        $test->get(route('cloud_import.google.callback', ['state' => $query['state'], 'code' => 'codigo-google']))
            ->assertRedirect(route('cloud_import.show', $envelope));
    }
}

if (! function_exists('connectorsHubSpotConnection')) {
    function connectorsHubSpotConnection(Organization $organization, User $user, int $portalId = 555001, ?Carbon $expiresAt = null): HubSpotConnection
    {
        /** @var HubSpotConnection $connection */
        $connection = HubSpotConnection::withoutOrganizationScope()->create([
            'organization_id' => $organization->getKey(),
            'portal_id' => $portalId,
            'access_token' => 'HUBSPOT-ACCESS-SENTINELA-'.$portalId,
            'refresh_token' => 'HUBSPOT-REFRESH-SENTINELA-'.$portalId,
            'token_expires_at' => $expiresAt ?? Carbon::now()->addMinutes(30),
            'scopes' => ['oauth', 'crm.objects.deals.write'],
            'status' => HubSpotConnection::STATUS_ACTIVE,
            'connected_by_user_id' => $user->getKey(),
            'connected_at' => Carbon::now(),
        ]);

        return $connection;
    }
}

if (! function_exists('connectorsHubSpotPost')) {
    /**
     * POST assinado (v3) na ação de workflow, exatamente como o HubSpot faria.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $override  cabeçalhos a sobrescrever
     */
    function connectorsHubSpotPost(object $test, array $payload, ?int $timestampMs = null, ?string $secret = null, array $override = []): TestResponse
    {
        $uri = route('webhooks.hubspot.action');
        $body = (string) json_encode($payload);
        $timestamp = (string) ($timestampMs ?? (int) floor(microtime(true) * 1000));
        $signature = HubSpotSignature::sign('POST', $uri, $body, $timestamp, $secret ?? 'HUBSPOT-CLIENT-SECRET-SENTINELA');

        $headers = $override + [
            'X-HubSpot-Signature-v3' => $signature,
            'X-HubSpot-Request-Timestamp' => $timestamp,
        ];

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $test->call('POST', $uri, [], [], [], $server, $body);
    }
}

if (! function_exists('connectorsActionPayload')) {
    /**
     * @param  array<string, mixed>  $inputFields
     * @return array<string, mixed>
     */
    function connectorsActionPayload(int $portalId, string $callbackId, array $inputFields, string $objectType = 'DEAL', int $objectId = 9001): array
    {
        return [
            'callbackId' => $callbackId,
            'origin' => ['portalId' => $portalId, 'actionDefinitionId' => 1, 'actionDefinitionVersion' => 1],
            'context' => ['source' => 'WORKFLOWS', 'workflowId' => 77],
            'object' => ['objectId' => $objectId, 'objectType' => $objectType],
            'inputFields' => $inputFields,
        ];
    }
}

if (! function_exists('connectorsFakeGoogle')) {
    /**
     * Google simulado: endpoint de token, revogação e Drive v3 (metadados, `alt=media`,
     * `export`). `$files`: id => [name, mimeType, bytes, size?].
     *
     * @param  array<string, array{name: string, mimeType: string, bytes: string, size?: int}>  $files
     * @param  array<string, mixed>|null  $tokenResponse
     */
    function connectorsFakeGoogle(array $files = [], ?array $tokenResponse = null): void
    {
        Http::fake(function (Request $request) use ($files, $tokenResponse) {
            $url = $request->url();

            if ($url === GoogleOAuthClient::TOKEN_URL) {
                return Http::response($tokenResponse ?? [
                    'access_token' => 'ya29.SENTINELA-GOOGLE-ACCESS',
                    'expires_in' => 3599,
                    'scope' => GoogleOAuthClient::SCOPE,
                    'token_type' => 'Bearer',
                    'refresh_token' => 'GOOGLE-REFRESH-SENTINELA',
                ]);
            }

            if ($url === GoogleOAuthClient::REVOKE_URL) {
                return Http::response('', 200);
            }

            if (str_starts_with($url, GoogleDriveSource::FILES_URL)) {
                $path = substr((string) parse_url($url, PHP_URL_PATH), strlen('/drive/v3/files/'));
                [$id, $suffix] = array_pad(explode('/', $path, 2), 2, null);
                $file = $files[rawurldecode((string) $id)] ?? null;

                if ($file === null) {
                    return Http::response(['error' => ['code' => 404]], 404);
                }

                if ($suffix === 'export') {
                    return Http::response($file['bytes'], 200, ['Content-Type' => 'application/pdf']);
                }

                if (str_contains($url, 'alt=media')) {
                    return Http::response($file['bytes'], 200, ['Content-Type' => $file['mimeType']]);
                }

                return Http::response([
                    'id' => $id,
                    'name' => $file['name'],
                    'mimeType' => $file['mimeType'],
                    'size' => (string) ($file['size'] ?? strlen($file['bytes'])),
                ]);
            }

            return Http::response('inesperado', 500);
        });
    }
}

if (! function_exists('connectorsUpload')) {
    function connectorsUpload(string $bytes, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'av-conn-');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }
}
