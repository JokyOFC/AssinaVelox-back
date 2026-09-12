<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Models\VerificationRecord;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Services\Webhooks\WebhookSignature;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Documents\Support\DocumentFixtures;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Documents/Support/helpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../Phase2/Templates/Support/TemplateHelpers.php';
require_once __DIR__.'/../Phase2/Domain/Support/DomainHelpers.php';
require_once __DIR__.'/../Phase2/RestHooks/Support/RestHookHelpers.php';
require_once __DIR__.'/../Phase2/Billing/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Ponta a ponta da Fase 2, onda D (integração I-2D)
|--------------------------------------------------------------------------
| Com as flags da onda D ligadas (interruptor global E plano), pelas rotas HTTP reais e sem
| rede (DNS falso + Http::fake + Http::preventStrayRequests):
|
|  1. o proprietário cria uma chave de API na tela (texto exibido uma vez) e um endpoint de
|     webhook apontando para um RECEPTOR DE TESTE cujo endereço interno só é aceito porque a
|     configuração de teste o libera (`webhooks.testing_allowed_cidrs`, ignorada em produção);
|  2. pela API: REST Hook para `envelope.completed` → documento gerado de um modelo → um anexo
|     enviado → participantes e campos definidos → envio com Idempotency-Key, repetido sem
|     duplicar;
|  3. os dois participantes assinam pela página pública (código por e-mail) e a finalização
|     roda com o pdftool real;
|  4. o receptor e o REST Hook recebem as entregas com HMAC válido (cada um com o SEU
|     segredo), payload mínimo e o mesmo rótulo de assinatura da verificação pública;
|  5. pela API: registro de verificação e os PDFs finais, conferidos pelo SHA-256.
|
| E, na cobrança (flag `extended_payments`), um estorno parcial e um total com o efeito da
| política conservadora no plano.
*/

const ONDA_D_RECEIVER_HOST = 'receptor-teste.example.com';
const ONDA_D_RECEIVER_URL = 'https://receptor-teste.example.com/assinavelox';
const ONDA_D_RECEIVER_IP = '10.77.0.10';
const ONDA_D_ZAP_URL = 'https://receiver.example.com/zap/catch';

/**
 * @return ArrayObject<string, string> e-mail => URL do convite
 */
function ondaDCaptureInvites(): ArrayObject
{
    /** @var ArrayObject<string, string> $invites */
    $invites = new ArrayObject;

    Event::listen(NotificationSending::class, function (NotificationSending $event) use ($invites): void {
        if ($event->notification instanceof RecipientInvitationNotification) {
            $invites[$event->notification->recipient->email] = $event->notification->signingUrl;
        }
    });

    return $invites;
}

function ondaDToken(string $url): string
{
    $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));

    return (string) end($segments);
}

/**
 * Requisições que o Http::fake registrou para um host.
 *
 * @return list<ClientRequest>
 */
function ondaDRequestsTo(string $host): array
{
    return Http::recorded()
        ->map(fn (array $pair): ClientRequest => $pair[0])
        ->filter(fn (ClientRequest $request): bool => parse_url($request->url(), PHP_URL_HOST) === $host)
        ->values()
        ->all();
}

function ondaDHeader(ClientRequest $request, string $name): string
{
    $values = $request->header($name);

    return $values === [] ? '' : (string) $values[0];
}

it('API + webhooks + REST Hook: modelo → anexo → participantes e campos → envio idempotente → assinatura pública → entregas assinadas → verificação e PDF final', function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->withoutVite();
    $work = templatesWorkspace();
    config()->set('pdftool.company_certificate.enabled', false);
    config()->set('pdftool.trust_roots', []);
    fakeDocumentsDisk($work.DIRECTORY_SEPARATOR.'disco-documents');

    try {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Onda D']);
        setPlanQuota($organization, 10);
        templatesEnable($organization);
        domainEnableFlags($organization, multiDocument: true, roles: false);
        restHooksEnable($organization);

        // Receptor de teste: nome que resolve para um endereço INTERNO, aceito só porque a
        // configuração de teste libera a faixa. Sem essa linha a mesma URL é recusada.
        fakeDns([ONDA_D_RECEIVER_HOST => [ONDA_D_RECEIVER_IP]]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('ok', 200)]);

        fakeEmailProvider();
        $this->codes = signerCaptureCodes();
        $invites = ondaDCaptureInvites();

        actingAsMember($owner, $organization);

        // -- 1a. Chave de API criada pela tela, com abilities explícitas ---------------------
        $abilities = ['envelopes:read', 'envelopes:write', 'envelopes:send', 'documents:read', 'recipients:read', 'templates:read', 'templates:use', 'webhooks:manage'];
        $keysPage = $this->post(route('integrations.keys.store'), ['name' => 'Integração ERP', 'abilities' => $abilities])->assertOk();

        expect((string) $keysPage->headers->get('Cache-Control'))->toContain('no-store');
        $revealed = $keysPage->viewData('page')['props']['revealed_token'];
        $plainToken = (string) $revealed['token'];
        $token = ApiToken::withoutOrganizationScope()->where('ulid', $revealed['id'])->sole();

        expect($token->organization_id)->toBe($organization->id)
            ->and($token->created_by_user_id)->toBe($owner->id)
            ->and($token->abilityValues())->toEqualCanonicalizing($abilities)
            // Só o hash no banco; a listagem mostra o prefixo, nunca o texto.
            ->and(DB::table('personal_access_tokens')->where('id', $token->id)->value('token'))->toBe(hash('sha256', explode('|', $plainToken, 2)[1]))
            ->and(json_encode($this->get(route('integrations.keys'))->viewData('page')['props']['tokens']))->not->toContain(explode('|', $plainToken, 2)[1]);

        // -- 1b. Endpoint de webhook pela tela: interno recusado, até a configuração liberar --
        $this->post(route('integrations.webhooks.store'), ['url' => ONDA_D_RECEIVER_URL, 'events' => ['*'], 'description' => 'Receptor de teste'])
            ->assertSessionHasErrors('url');
        expect(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(0);

        config()->set('assinavelox.webhooks.testing_allowed_cidrs', ['10.77.0.0/16']);

        $created = $this->post(route('integrations.webhooks.store'), ['url' => ONDA_D_RECEIVER_URL, 'events' => ['*'], 'description' => 'Receptor de teste'])
            ->assertRedirect();
        $receiverPage = $this->get((string) $created->headers->get('Location'))->assertOk()->viewData('page')['props'];
        $receiverSecret = (string) $receiverPage['revealed_secret']['secret'];
        expect($receiverSecret)->toStartWith('whsec_');

        // O segredo aparece uma vez: a próxima visita não o traz.
        $this->get((string) $created->headers->get('Location'))->assertOk()->assertInertia(fn ($page) => $page->where('revealed_secret', null));

        // -- 2a. REST Hook (padrão Zapier/Make/n8n) para a conclusão -------------------------
        $zap = restSubscribe($plainToken, ['target_url' => ONDA_D_ZAP_URL, 'event' => 'envelope.completed'], 'zap-assinatura-1')->assertCreated();
        $zapSecret = (string) $zap->json('data.secret');
        expect($zapSecret)->toStartWith('whsec_')->not->toBe($receiverSecret);

        // -- 2b. Documento a partir de modelo, pela API (idempotente) ------------------------
        $template = templateHtml($organization, $owner, '<p>Contrato de locação. Locatária: {{nome}}. Aluguel: {{valor}}.</p>', [
            ['key' => 'nome', 'label' => 'Nome da locatária', 'type' => 'text', 'required' => true],
            ['key' => 'valor', 'label' => 'Valor do aluguel', 'type' => 'currency', 'required' => false],
        ]);
        $roleId = templateRoleIds($template)['Locatário'];

        $fromTemplate = $this->postJson('/api/v1/templates/'.$template->ulid.'/envelopes', [
            'title' => 'Locação — Apto 302',
            'values' => ['nome' => 'Maria Alves', 'valor' => '1.500,00'],
            'participants' => [$roleId => ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com']],
        ], apiHeaders($plainToken, apiIdem('modelo-onda-d-1')))->assertCreated();

        $id = (string) $fromTemplate->json('data.id');
        $envelope = Envelope::withoutOrganizationScope()->where('ulid', $id)->sole();
        expect($envelope->organization_id)->toBe($organization->id)
            ->and($fromTemplate->json('data.recipients.0.email'))->toBe('maria@exemplo.com');

        // -- 2c. Anexo enviado pela API (segundo arquivo, multi-documento) -------------------
        $annex = PdfFixtures::onePagePdf($work.DIRECTORY_SEPARATOR.'anexo-vistoria.pdf');
        $this->post('/api/v1/envelopes/'.$id.'/documents', ['file' => DocumentFixtures::upload($annex, 'anexo-vistoria.pdf', 'application/pdf')], apiHeaders($plainToken, apiIdem()))
            ->assertCreated()
            ->assertJsonPath('data.processing_status', 'ready');

        $documents = $this->getJson('/api/v1/envelopes/'.$id, apiHeaders($plainToken))->assertOk()->json('data.documents');
        expect($documents)->toHaveCount(2);

        // -- 2d. Participantes e campos ------------------------------------------------------
        $mariaId = (string) $fromTemplate->json('data.recipients.0.id');
        $recipients = $this->putJson('/api/v1/envelopes/'.$id.'/recipients', [
            'signing_order' => 'parallel',
            'recipients' => [
                ['id' => $mariaId, 'name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
                ['name' => 'João Souza', 'email' => 'joao@exemplo.com'],
            ],
        ], apiHeaders($plainToken))->assertOk()->json('data');
        expect($recipients)->toHaveCount(2)->and($recipients[0]['id'])->toBe($mariaId);
        $joaoId = (string) collect($recipients)->firstWhere('email', 'joao@exemplo.com')['id'];

        $this->putJson('/api/v1/envelopes/'.$id.'/fields', ['fields' => [
            ['recipient_id' => $mariaId, 'document_id' => $documents[0]['id'], 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06, 'required' => true],
            ['recipient_id' => $joaoId, 'document_id' => $documents[1]['id'], 'type' => 'signature', 'page' => 1, 'x' => 0.5, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06, 'required' => true],
        ]], apiHeaders($plainToken))->assertOk();

        $this->getJson('/api/v1/envelopes/'.$id, apiHeaders($plainToken))->assertJsonPath('data.status', 'ready');

        // -- 2e. Envio com Idempotency-Key, repetido: a mesma resposta e nada duplicado -------
        $sendHeaders = apiHeaders($plainToken, apiIdem('envio-onda-d-1'));
        $sent = $this->postJson('/api/v1/envelopes/'.$id.'/send', [], $sendHeaders)->assertOk();
        $again = $this->postJson('/api/v1/envelopes/'.$id.'/send', [], $sendHeaders)->assertOk();

        expect($sent->json('data.status'))->toBe('in_progress')
            ->and($sent->json('meta.invitations_sent'))->toBe(2)
            ->and($again->json())->toBe($sent->json())
            ->and(AuditEvent::withoutOrganizationScope()->where('envelope_id', $envelope->id)->where('event_type', AuditEventType::EnvelopeSent->value)->count())->toBe(1)
            ->and(count($invites))->toBe(2)
            ->and(ApiRequestLog::withoutOrganizationScope()->where('idempotent_replay', true)->count())->toBeGreaterThanOrEqual(1);

        // O receptor já recebeu `envelope.sent`; o REST Hook (só conclusão) ainda não.
        expect(collect(ondaDRequestsTo(ONDA_D_RECEIVER_HOST))->map(fn ($r) => ondaDHeader($r, WebhookSignature::HEADER_EVENT))->all())->toContain('envelope.sent')
            ->and(ondaDRequestsTo('receiver.example.com'))->toBe([]);

        // -- 3. Assinatura pela página pública (código por e-mail, cada arquivo apresentado) --
        $this->flushSession();
        app('auth')->forgetGuards();

        foreach (['maria@exemplo.com', 'joao@exemplo.com'] as $email) {
            $signToken = ondaDToken((string) $invites[$email]);
            $props = authenticateSigner($this, $signToken);

            foreach ($documents as $document) {
                $this->get(route('sign.document', ['token' => $signToken, 'document' => $document['id']]))->assertOk();
            }

            $this->post(route('sign.complete', ['token' => $signToken]), [
                'authorization' => $props['authorization']['token'],
                'consent' => true,
                'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
                'fields' => [],
            ], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (E2E Onda D)'])->assertSessionHasNoErrors();
        }

        $envelope->refresh();
        expect($envelope->status)->toBe(EnvelopeStatus::Completed);

        $record = VerificationRecord::query()->withoutGlobalScopes()->where('envelope_id', $envelope->id)->whereNull('revoked_at')->latest('id')->firstOrFail();

        // -- 4a. Receptor de teste: tudo assinado com o SEU segredo ---------------------------
        $receiverRequests = ondaDRequestsTo(ONDA_D_RECEIVER_HOST);
        $receiverEvents = collect($receiverRequests)->map(fn ($r) => ondaDHeader($r, WebhookSignature::HEADER_EVENT))->all();

        expect($receiverEvents)->toContain('envelope.sent', 'recipient.signed', 'envelope.completed')
            ->and(collect($receiverEvents)->filter(fn ($type) => $type === 'recipient.signed')->count())->toBe(2)
            ->and(collect($receiverEvents)->filter(fn ($type) => $type === 'envelope.completed')->count())->toBe(1);

        foreach ($receiverRequests as $request) {
            expect(WebhookSignature::verify(
                $receiverSecret,
                ondaDHeader($request, WebhookSignature::HEADER_SIGNATURE),
                ondaDHeader($request, WebhookSignature::HEADER_TIMESTAMP),
                $request->body(),
                Carbon::now()->getTimestamp(),
            ))->toBeTrue()
                // O segredo do REST Hook não valida as entregas do outro endpoint.
                ->and(WebhookSignature::verify(
                    $zapSecret,
                    ondaDHeader($request, WebhookSignature::HEADER_SIGNATURE),
                    ondaDHeader($request, WebhookSignature::HEADER_TIMESTAMP),
                    $request->body(),
                    Carbon::now()->getTimestamp(),
                ))->toBeFalse();
        }

        $signedPayload = collect($receiverRequests)->first(fn ($r) => ondaDHeader($r, WebhookSignature::HEADER_EVENT) === 'recipient.signed')->data();
        expect($signedPayload['data']['recipient']['action'])->toBe('electronic_acceptance')
            ->and($signedPayload['data']['recipient']['action_label'])->toContain('não é assinatura com certificado');

        // -- 4b. REST Hook: só a conclusão, uma vez, com o próprio segredo -------------------
        $zapRequests = ondaDRequestsTo('receiver.example.com');
        expect($zapRequests)->toHaveCount(1);

        $completed = $zapRequests[0];
        expect(ondaDHeader($completed, WebhookSignature::HEADER_EVENT))->toBe('envelope.completed')
            ->and(WebhookSignature::verify(
                $zapSecret,
                ondaDHeader($completed, WebhookSignature::HEADER_SIGNATURE),
                ondaDHeader($completed, WebhookSignature::HEADER_TIMESTAMP),
                $completed->body(),
                Carbon::now()->getTimestamp(),
            ))->toBeTrue();

        $payload = $completed->data();
        expect($payload['data']['envelope']['id'])->toBe($id)
            ->and($payload['data']['envelope']['status'])->toBe('completed')
            ->and($payload['data']['envelope']['participants'])->toBe(['total' => 2, 'concluded' => 2])
            // O mesmo rótulo honesto da verificação pública.
            ->and($payload['data']['signature']['status'])->toBe($record->signature_status->value)
            ->and($payload['data']['signature']['label'])->toBe($record->signature_status->label())
            // Sem certificado da operadora não há assinatura criptográfica: o perfil é nulo e o
            // rótulo diz aceite eletrônico — nada é anunciado além do que existe (T1/T2).
            ->and($payload['data']['signature']['profile'])->toBe($record->signature_profile)
            ->and($payload['data']['signature']['final_sha256'])->toBe($record->final_sha256);

        // Payload mínimo: nada de nome, e-mail, título, código de acesso, token ou segredo.
        $codes = iterator_to_array($this->codes);
        foreach (array_merge($receiverRequests, $zapRequests) as $request) {
            foreach (['maria@exemplo.com', 'joao@exemplo.com', 'Maria Alves', 'João Souza', 'Locação — Apto 302', $receiverSecret, $zapSecret, $plainToken, ...$codes] as $secret) {
                expect($request->body())->not->toContain($secret);
            }
        }

        expect(WebhookDelivery::withoutOrganizationScope()->where('status', '!=', WebhookDelivery::STATUS_DELIVERED)->count())->toBe(0);

        // -- 5. Consulta pela API: verificação e PDFs finais ---------------------------------
        $verification = $this->getJson('/api/v1/envelopes/'.$id.'/verification', apiHeaders($plainToken))->assertOk();
        $verificationJson = (string) $verification->getContent();
        expect($verification->json('data.verification_code'))->toBe($sent->json('data.verification_code'))
            ->and($verificationJson)->not->toContain('maria@exemplo.com')
            ->and($verificationJson)->not->toContain('joao@exemplo.com');

        $show = $this->getJson('/api/v1/envelopes/'.$id, apiHeaders($plainToken))->assertOk();
        expect($show->json('data.status'))->toBe('completed')
            ->and($show->json('data.signature_status'))->toBe($record->signature_status->value)
            ->and($show->json('data.signature_status_label'))->toBe($record->signature_status->label());

        foreach ($show->json('data.documents') as $document) {
            $final = $this->get('/api/v1/envelopes/'.$id.'/files/signed?document='.$document['id'], apiHeaders($plainToken))->assertOk();
            expect(hash('sha256', (string) $final->streamedContent()))->toBe($document['sha256']['final']);
        }

        // Registro de requisições: só metadados, nunca o texto do token.
        expect(json_encode(ApiRequestLog::withoutOrganizationScope()->get()->toArray()))->not->toContain(explode('|', $plainToken, 2)[1]);

        // Detalhes do documento na interface: linha "Webhook" com a última entrega.
        $this->flushSession();
        actingAsMember($owner, $organization);
        $detail = $this->get(route('envelopes.show', ['envelope' => $id]))->assertOk()->viewData('page')['props'];
        expect($detail['webhook']['status'])->toBe(WebhookDelivery::STATUS_DELIVERED)
            ->and($detail['webhook']['href'])->not->toBeNull();

        // Revogar a chave remove o REST Hook dela; o endpoint da tela continua.
        $this->delete(route('integrations.keys.destroy', ['apiToken' => $token->ulid]))->assertRedirect();
        expect(WebhookEndpoint::withoutOrganizationScope()->where('source', WebhookEndpoint::SOURCE_REST_HOOK)->count())->toBe(0)
            ->and(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(1);
        $this->getJson('/api/v1/envelopes/'.$id, apiHeaders($plainToken))->assertUnauthorized();
    } finally {
        cleanupDocumentsWorkspace($work);
    }
});

it('REST Hook de token apenas vencido deixa de receber e a varredura remove a assinatura', function () {
    ['organization' => $organization, 'owner' => $owner] = restHooksOrg();
    Http::fake(['*' => Http::response('ok', 200)]);
    [$plain, $token] = restHookToken($organization, $owner);

    restSubscribe($plain, ['target_url' => ONDA_D_ZAP_URL, 'event' => '*'])->assertCreated();

    ['envelope' => $envelope] = webhookEnvelope($organization, $owner);
    recordAudit($envelope, AuditEventType::EnvelopeSent);
    expect(ondaDRequestsTo('receiver.example.com'))->toHaveCount(1);

    // Vence sem ninguém revogar: nenhuma entrega nova sai.
    $token->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();
    recordAudit($envelope, AuditEventType::EnvelopeCanceled);
    expect(ondaDRequestsTo('receiver.example.com'))->toHaveCount(1);

    $this->artisan('rest-hooks:prune')->assertSuccessful();
    expect(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(0);
});

it('cobrança ampliada: estorno parcial não mexe no plano; estorno total não renova e mantém a cota usada', function () {
    $admin = User::factory()->platformAdmin()->create();

    // Estorno parcial.
    ['organization' => $partialOrg, 'subscription' => $partialSubscription, 'payment' => $partialPayment, 'gateway' => $gateway, 'plan' => $plan] = extendedBillingContext();

    adminBillingPost($admin, 'admin.billing.payments.refund', ['payment' => $partialPayment->ulid], [
        'reason' => 'Desconto concedido',
        'amount_cents' => 1_000,
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHas('success');

    $partial = PaymentRefund::withoutOrganizationScope()->where('payment_id', $partialPayment->id)->sole();
    $partialSubscription->refresh();

    expect($partial->kind)->toBe(PaymentRefund::KIND_PARTIAL)
        ->and($partial->amount_cents)->toBe(1_000)
        ->and($gateway->refundRequests()[0]['amount_cents'])->toBe(1_000)
        ->and($partialSubscription->status)->toBe(SubscriptionStatus::Active)
        ->and($partialSubscription->cancel_at_period_end)->toBeFalse()
        ->and($partialSubscription->paid_cycle_refunded_at)->toBeNull()
        ->and($partialSubscription->envelopes_used)->toBe(3);

    // Estorno total (outra organização, outro pagamento, mesmo plano e mesmo dublê do
    // gateway), com a mesma chave repetida.
    ['organization' => $totalOrg] = createOrganizationWithOwner();
    $totalSubscription = subscribeOrganization($totalOrg, $plan, used: 3);
    $totalPayment = activatedPaymentFor($totalOrg, $plan, $totalSubscription, $gateway, '9000000002');
    $key = (string) Str::uuid();

    foreach ([1, 2] as $attempt) {
        adminBillingPost($admin, 'admin.billing.payments.refund', ['payment' => $totalPayment->ulid], [
            'reason' => 'Cobrança em duplicidade',
            'idempotency_key' => $key,
        ])->assertSessionHas('success');
    }

    $total = PaymentRefund::withoutOrganizationScope()->where('payment_id', $totalPayment->id)->sole();
    $totalSubscription->refresh();

    expect($total->kind)->toBe(PaymentRefund::KIND_TOTAL)
        ->and($total->idempotency_key)->toBe($key)
        ->and(collect($gateway->refundRequests())->where('payment', '9000000002')->count())->toBe(1)
        ->and($totalPayment->fresh()->status)->toBe(PaymentStatus::Refunded)
        // Política conservadora (docs/fase-2/pagamentos-e-fiscal.md §4): vale até o fim do
        // ciclo, não renova, não passa por inadimplência e a cota consumida não volta.
        ->and($totalSubscription->status)->toBe(SubscriptionStatus::Active)
        ->and($totalSubscription->cancel_at_period_end)->toBeTrue()
        ->and($totalSubscription->paid_cycle_refunded_at)->not->toBeNull()
        ->and($totalSubscription->envelopes_used)->toBe(3)
        ->and($partialOrg->id)->not->toBe($totalOrg->id);
});
