<?php

use App\Enums\AuditEventType;
use App\Enums\FieldType;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\SignatureAcceptance;
use App\Services\Identity\IdentityCaptures;
use App\Services\Identity\IdentityFeatures;
use App\Services\Identity\Models\IdentityCaptureRequirement;
use App\Services\Signing\SignerContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/Support/IdentityHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2, onda B (C-ID) — flags desligadas = comportamento atual
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    config()->set('inertia.ssr.enabled', false);
    Http::preventStrayRequests();
    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('as quatro flags de identidade nascem desligadas, mesmo com o plano dizendo sim', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    foreach (IdentityFeatures::FLAGS as $flag) {
        expect(config('assinavelox.features.'.$flag))->toBeFalse();
    }

    expect(IdentityFeatures::forOrganization($organization))->toBe([
        'cpf_field' => false, 'cpf_lookup' => false, 'cnpj_lookup' => false, 'identity_capture' => false,
    ]);

    // Plano liga, interruptor global desligado: continua desligado (T8).
    $plan = $organization->currentSubscription()->with('plan')->first()->plan;
    $plan->forceFill(['features' => array_merge((array) $plan->features, array_fill_keys(IdentityFeatures::FLAGS, true))])->save();

    expect(array_filter(IdentityFeatures::forOrganization($organization->fresh())))->toBe([])
        ->and(IdentityFeatures::cnpjLookupWithoutOrganization())->toBeFalse();
});

it('sem flag: rotas novas respondem 404, nenhuma chamada externa e a câmera continua negada', function () {
    $ctx = signerEnvelope([['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]]]);
    $token = $ctx['tokens']['maria@exemplo.test'];
    $recipient = $ctx['recipients']['maria@exemplo.test'];

    // Exigência gravada enquanto a flag esteve ligada (ou por dado antigo): sem flag, não vale.
    IdentityCaptureRequirement::withoutOrganizationScope()->create([
        'organization_id' => $ctx['organization']->getKey(),
        'envelope_id' => $ctx['envelope']->getKey(),
        'recipient_id' => $recipient->getKey(),
        'kinds' => ['selfie'],
    ]);

    $page = $this->get(route('sign.show', ['token' => $token]))->assertOk();
    expect((string) $page->headers->get('Permissions-Policy'))->toContain('camera=()');

    $props = authenticateSigner($this, $token);

    identityPostCapture($this, $token, 'selfie', identityPng())->assertNotFound();

    // O aceite é o de sempre: sem foto, sem chave nova no snapshot, sem evento novo.
    identityAccept($this, $token, $props)->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::withoutOrganizationScope()->firstOrFail();

    expect($acceptance->fields_snapshot)->not->toHaveKey('identity_captures')
        ->and(collect($acceptance->fields_snapshot['values'])->every(fn (array $value): bool => ! array_key_exists('cpf_check', $value) && ! array_key_exists('cpf_masked', $value)))->toBeTrue()
        ->and(AuditEvent::query()->withoutGlobalScopes()->whereIn('event_type', [
            AuditEventType::CpfLookupPerformed->value,
            AuditEventType::IdentityCaptureRecorded->value,
        ])->count())->toBe(0);

    actingAsMember($ctx['owner'], $ctx['organization']);

    $this->putJson(route('envelopes.recipients.identity_capture', ['envelope' => $ctx['envelope']->ulid, 'recipient' => $recipient->ulid]), ['kinds' => ['selfie']])->assertNotFound();
    $this->postJson(route('settings.organization.cnpj'), ['cnpj' => '33.683.111/0002-80'])->assertNotFound();
    $this->postJson(route('cnpj.lookup'), ['cnpj' => '33.683.111/0002-80'])->assertNotFound();

    Http::assertNothingSent();

    expect(app(IdentityCaptures::class)->isRequiredFor(
        new SignerContext($token, $ctx['links']['maria@exemplo.test'], $recipient, $ctx['envelope'], $ctx['organization'], 'active'),
    ))->toBeFalse();
});
