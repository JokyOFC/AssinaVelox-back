<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes de webhooks de saída (D-HOOK)
|--------------------------------------------------------------------------
| Incluído com require_once. Não contém testes. Nenhum helper acessa a rede: o DNS é o
| FakeDnsResolver e as chamadas HTTP passam por Http::fake() (exceto o teste do pino, que usa
| um servidor local em 127.0.0.1).
*/

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Webhooks\WebhookEndpointManager;
use App\Support\Http\DnsResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Phase2\Webhooks\Support\FakeDnsResolver;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Permissions/Support/PermissionHelpers.php';

if (! defined('WEBHOOK_TEST_HOST')) {
    define('WEBHOOK_TEST_HOST', 'receiver.example.com');
    define('WEBHOOK_TEST_URL', 'https://receiver.example.com/hooks');
    define('WEBHOOK_PUBLIC_IP', '93.184.215.34');
}

if (! function_exists('webhooksOn')) {
    /**
     * Liga (ou desliga) a flag `outbound_webhooks`: interruptor global E plano vigente.
     */
    function webhooksOn(Organization $organization, bool $enabled = true, bool $global = true): void
    {
        config()->set('assinavelox.features.outbound_webhooks', $global);

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);
        $features['outbound_webhooks'] = $enabled;
        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('fakeDns')) {
    /**
     * @param  array<string, list<string>>  $records
     */
    function fakeDns(array $records = []): FakeDnsResolver
    {
        $resolver = app()->bound(DnsResolver::class) && app(DnsResolver::class) instanceof FakeDnsResolver
            ? app(DnsResolver::class)
            : new FakeDnsResolver;

        if (! isset($resolver->records[WEBHOOK_TEST_HOST])) {
            $resolver->set(WEBHOOK_TEST_HOST, [WEBHOOK_PUBLIC_IP]);
        }

        foreach ($records as $host => $addresses) {
            $resolver->set($host, $addresses);
        }

        app()->instance(DnsResolver::class, $resolver);

        return $resolver;
    }
}

if (! function_exists('webhookOrg')) {
    /**
     * Organização com a flag ligada, DNS falso e chamadas HTTP de verdade proibidas.
     *
     * @param  array<string, list<string>>  $dns
     * @return array{organization: Organization, owner: User, membership: Membership}
     */
    function webhookOrg(array $dns = [], bool $preventStray = true): array
    {
        $context = createOrganizationWithOwner();
        webhooksOn($context['organization']);
        fakeDns($dns);

        if ($preventStray) {
            Http::preventStrayRequests();
        }

        return $context;
    }
}

if (! function_exists('makeEndpoint')) {
    /**
     * @param  list<string>  $events
     * @return array{endpoint: WebhookEndpoint, secret: string}
     */
    function makeEndpoint(Organization $organization, User $creator, array $events = ['*'], string $url = WEBHOOK_TEST_URL): array
    {
        return app(WebhookEndpointManager::class)->create($organization, $creator, $url, $events);
    }
}

if (! function_exists('webhookEnvelope')) {
    /**
     * Envelope enviado com um participante (signatário) notificado.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{envelope: Envelope, recipient: Recipient}
     */
    function webhookEnvelope(Organization $organization, User $creator, array $attributes = [], array $recipientAttributes = []): array
    {
        $envelope = Envelope::factory()->forOrganization($organization, $creator)->create([
            'status' => EnvelopeStatus::InProgress,
            'sent_at' => Carbon::now()->subHour(),
            'expires_at' => Carbon::now()->addDays(10),
            ...$attributes,
        ]);

        $recipient = Recipient::factory()->forEnvelope($envelope)->create([
            'status' => RecipientStatus::Notified,
            ...$recipientAttributes,
        ]);

        return ['envelope' => $envelope, 'recipient' => $recipient];
    }
}

if (! function_exists('recordAudit')) {
    /**
     * Grava um evento na trilha pelo mesmo serviço do app — é isso que dispara os webhooks.
     *
     * @param  array<string, mixed>  $payload
     */
    function recordAudit(Envelope $envelope, AuditEventType $type, ?Recipient $recipient = null, array $payload = []): AuditEvent
    {
        return EnvelopeAudit::record($envelope, $type, $payload, $recipient);
    }
}

if (! function_exists('webhookRequests')) {
    /**
     * Requisições registradas pelo Http::fake(), em ordem.
     *
     * @return list<Request>
     */
    function webhookRequests(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0])->values()->all();
    }
}

if (! function_exists('headerOf')) {
    function headerOf(Request $request, string $name): ?string
    {
        $values = $request->header($name);

        return $values === [] ? null : (string) $values[0];
    }
}

if (! function_exists('inertiaGet')) {
    /**
     * GET como visita Inertia (resposta JSON com component/props). As páginas
     * `integrations/webhooks/*` são do front e ainda não existem no manifesto do Vite: uma
     * visita HTML completa falharia ao montar o <script> da página, não por causa do backend.
     */
    function inertiaGet(string $uri): TestResponse
    {
        $version = (string) (app(HandleInertiaRequests::class)->version(request()) ?? '');

        return test()->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->get($uri);
    }
}

if (! function_exists('inertiaPage')) {
    /**
     * @return array{component: string, props: array<string, mixed>}
     */
    function inertiaPage(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array{component: string, props: array<string, mixed>} $page */
        $page = $response->headers->has('X-Inertia') ? $response->json() : $response->viewData('page');

        return $page;
    }
}
