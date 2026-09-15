<?php

use App\Enums\ActorType;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Enums\SignatureStatus;
use App\Enums\SigningOrder;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\Delegation;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Services\Envelopes\Finalization\EvidenceData;
use App\Services\Identity\Models\IdentityCaptureRequirement;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Domain/Support/DomainHelpers.php';
require_once __DIR__.'/Support/FlowHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 3 §3.3 (F-FLOW) — delegação auditada
|--------------------------------------------------------------------------
| O delegado é um participante NOVO, com convite, código e aceite próprios; o original fica
| `delegated` (nunca "assinado") e o link dele deixa de abrir. Com e sem confirmação do
| remetente, recusa do remetente, cada proibição e os limites, e as evidências.
*/

beforeEach(function () {
    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    $this->invites = new ArrayObject;
    $this->inviteCounts = new ArrayObject;
    flowCaptureInvites($this->invites, $this->inviteCounts);

    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('com confirmação: o pedido espera quem enviou; confirmado, o delegado assina com aceite próprio e a evidência mostra quem, quando e por quê', function () {
    $ctx = flowDelegationEnvelope($this);
    $maria = $ctx['recipients']['maria@exemplo.test'];
    $token = $this->invites['maria@exemplo.test'];

    domainAuthenticate($this, $token);

    $this->getJson(route('sign.delegation.show', ['token' => $token]))
        ->assertOk()
        ->assertJsonPath('can_delegate', true)
        ->assertJsonPath('requires_confirmation', true)
        ->assertJsonPath('pending', null);

    flowDelegate($this, $token)->assertCreated()->assertJsonPath('status', 'pending');

    $delegation = Delegation::query()->sole();

    expect($delegation->status)->toBe(Delegation::STATUS_PENDING)
        ->and($delegation->to_email)->toBe('carla@exemplo.test')
        ->and($delegation->ip_address)->toEndWith('/24')
        ->and($maria->fresh()->status)->not->toBe(RecipientStatus::Delegated)
        ->and(AuditEvent::query()->where('event_type', 'delegation.requested')->count())->toBe(1)
        ->and($ctx['owner']->notifications()->where('data->event', 'delegation_requested')->count())->toBe(1)
        ->and(isset($this->invites['carla@exemplo.test']))->toBeFalse();

    // Enquanto quem enviou não decide, a participação continua sendo da Maria.
    $this->get(route('sign.show', ['token' => $token]))->assertOk();
    $this->getJson(route('sign.delegation.show', ['token' => $token]))->assertJsonPath('pending.to_email_masked', 'c••••@exemplo.test');

    $this->getJson(route('envelopes.flow.show', ['envelope' => $ctx['envelope']->ulid]))
        ->assertOk()
        ->assertJsonPath('delegation.requests.0.can_decide', true);

    $this->post(route('envelopes.delegations.approve', ['envelope' => $ctx['envelope']->ulid, 'delegation' => $delegation->ulid]))
        ->assertSessionHas('success');

    $maria->refresh();
    $carla = Recipient::query()->where('email', 'carla@exemplo.test')->sole();

    expect($maria->status)->toBe(RecipientStatus::Delegated)
        ->and($maria->getAttribute('status_reason'))->toBe('delegated')
        ->and($carla->getAttribute('delegated_from_recipient_id'))->toBe($maria->id)
        ->and($carla->order_index)->toBe($maria->order_index)
        ->and($carla->role)->toBe(RecipientRole::Signer)
        ->and($carla->status)->toBe(RecipientStatus::Notified)
        ->and(SigningField::query()->where('recipient_id', $carla->id)->count())->toBe(1)
        ->and(SigningField::query()->where('recipient_id', $maria->id)->count())->toBe(0)
        ->and($delegation->fresh()->status)->toBe(Delegation::STATUS_EFFECTIVE)
        ->and($delegation->fresh()->approved_by_sender_at)->not->toBeNull()
        ->and($this->inviteCounts['carla@exemplo.test'] ?? 0)->toBe(1);

    $event = AuditEvent::query()->where('event_type', 'recipient.delegated')->sole();

    expect($event->actor_type)->toBe(ActorType::User)
        ->and($event->recipient_id)->toBe($maria->id)
        ->and($event->payload['to'])->toBe($carla->ulid)
        ->and($event->payload['confirmed_by_sender'])->toBeTrue()
        ->and($event->payload)->not->toHaveKey('reason');

    // O link da Maria deixou de valer.
    $this->get(route('sign.show', ['token' => $token]))->assertNotFound();

    // A delegada assina com aceite PRÓPRIO; a vez segue para o João.
    flowSign($this, $this->invites['carla@exemplo.test'])->assertSessionHasNoErrors();

    expect(SignatureAcceptance::query()->where('recipient_id', $carla->id)->count())->toBe(1)
        ->and(SignatureAcceptance::query()->where('recipient_id', $maria->id)->exists())->toBeFalse()
        ->and($ctx['envelope']->fresh()->current_order)->toBe(2)
        ->and(isset($this->invites['joao@exemplo.test']))->toBeTrue();

    flowSign($this, $this->invites['joao@exemplo.test'])->assertSessionHasNoErrors();

    $envelope = $ctx['envelope']->fresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Finalizing);

    // Evidências: quem delegou a quem, quando, com que confirmação e por quê.
    $version = DocumentVersion::withoutOrganizationScope()->findOrFail($envelope->sent_document_version_id);
    $data = app(EvidenceData::class)->build($envelope, $version, ['original' => 'a', 'sent' => 'b', 'consolidated' => 'c'], SignatureStatus::None);
    $participants = collect($data['participants'])->keyBy('email');

    expect($participants['maria@exemplo.test']['status'])->toBe('delegated')
        ->and($participants['maria@exemplo.test']['flow_note'])->toContain('Delegou a Carla Souza')
        ->and($participants['maria@exemplo.test']['flow_note'])->toContain('procuração')
        ->and($participants['carla@exemplo.test']['flow_note'])->toContain('delegação de Maria Alves')
        ->and($participants['joao@exemplo.test'])->not->toHaveKey('flow_note')
        ->and($data['flow']['delegations'][0]['confirmed_at'])->not->toBeNull()
        ->and($data['flow']['delegations'][0]['reason'])->toContain('procuração')
        ->and(collect($data['timeline'])->pluck('type')->all())->toContain('recipient.delegated');

    $html = view('evidence.page', ['evidence' => $data])->render();

    expect($html)->toContain('Delegações')
        ->and($html)->toContain('Carla Souza')
        ->and($html)->toContain('aceite próprio');
});

it('sem confirmação: vale na hora, o original fica delegado e o delegado é convidado', function () {
    $ctx = flowDelegationEnvelope($this, ['requires_confirmation' => false]);
    $token = $this->invites['maria@exemplo.test'];

    domainAuthenticate($this, $token);

    flowDelegate($this, $token)->assertCreated()
        ->assertJsonPath('status', 'effective')
        ->assertJsonPath('to_email_masked', 'c••••@exemplo.test');

    $maria = $ctx['recipients']['maria@exemplo.test']->fresh();
    $event = AuditEvent::query()->where('event_type', 'recipient.delegated')->sole();

    expect($maria->status)->toBe(RecipientStatus::Delegated)
        ->and(Delegation::query()->sole()->approved_by_sender_at)->toBeNull()
        ->and($event->actor_type)->toBe(ActorType::Recipient)
        ->and($event->payload['confirmed_by_sender'])->toBeFalse()
        ->and(AuditEvent::query()->where('event_type', 'delegation.requested')->exists())->toBeFalse()
        ->and($this->inviteCounts['carla@exemplo.test'] ?? 0)->toBe(1);

    $this->get(route('sign.show', ['token' => $token]))->assertNotFound();
});

it('participante com exigência reforçada (fotos) precisa da confirmação mesmo com ela desligada', function () {
    $ctx = flowDelegationEnvelope($this, ['requires_confirmation' => false]);
    $maria = $ctx['recipients']['maria@exemplo.test'];

    $requirement = new IdentityCaptureRequirement;
    $requirement->forceFill([
        'organization_id' => $ctx['organization']->id,
        'envelope_id' => $ctx['envelope']->id,
        'recipient_id' => $maria->id,
        'kinds' => ['selfie'],
    ])->save();

    $token = $this->invites['maria@exemplo.test'];
    domainAuthenticate($this, $token);

    flowDelegate($this, $token)->assertCreated()->assertJsonPath('status', 'pending');

    $this->post(route('envelopes.delegations.approve', ['envelope' => $ctx['envelope']->ulid, 'delegation' => Delegation::query()->sole()->ulid]))
        ->assertSessionHas('success');

    $carla = Recipient::query()->where('email', 'carla@exemplo.test')->sole();

    // A exigência não se perde na troca: o delegado herda a mesma exigência de fotos.
    expect(IdentityCaptureRequirement::query()->withoutGlobalScopes()->where('recipient_id', $carla->id)->value('kinds'))->toBe(['selfie']);
});

it('recusa de quem enviou mantém o original, que continua podendo assinar', function () {
    $ctx = flowDelegationEnvelope($this);
    $token = $this->invites['maria@exemplo.test'];

    domainAuthenticate($this, $token);
    flowDelegate($this, $token)->assertCreated();

    $delegation = Delegation::query()->sole();

    $this->post(route('envelopes.delegations.reject', ['envelope' => $ctx['envelope']->ulid, 'delegation' => $delegation->ulid]), [
        'note' => 'Preciso que a própria Maria assine.',
    ])->assertSessionHas('success');

    expect($delegation->fresh()->status)->toBe(Delegation::STATUS_REJECTED)
        ->and(AuditEvent::query()->where('event_type', 'delegation.rejected')->count())->toBe(1)
        ->and(Recipient::query()->where('email', 'carla@exemplo.test')->exists())->toBeFalse();

    $this->getJson(route('sign.delegation.show', ['token' => $token]))
        ->assertOk()
        ->assertJsonPath('rejected.note', 'Preciso que a própria Maria assine.');

    // Confirmar depois de recusado não faz nada.
    $this->post(route('envelopes.delegations.approve', ['envelope' => $ctx['envelope']->ulid, 'delegation' => $delegation->ulid]))
        ->assertSessionHas('error');

    flowSign($this, $token)->assertSessionHasNoErrors();

    expect($ctx['recipients']['maria@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Signed);
});

it('recusa delegar para si mesmo e para quem já participa', function (string $email, string $code) {
    $ctx = flowDelegationEnvelope($this, ['requires_confirmation' => false]);
    $token = $this->invites['maria@exemplo.test'];

    domainAuthenticate($this, $token);

    flowDelegate($this, $token, ['email' => $email])->assertStatus(422)->assertJsonPath('code', $code);

    expect(Delegation::query()->count())->toBe(0)
        ->and($ctx['recipients']['maria@exemplo.test']->fresh()->status)->not->toBe(RecipientStatus::Delegated);
})->with([
    'para si mesmo' => ['MARIA@Exemplo.test', 'self'],
    'para outro participante' => ['joao@exemplo.test', 'participant'],
]);

it('recusa delegação em cadeia além do limite configurado', function () {
    $ctx = flowDelegationEnvelope($this, ['requires_confirmation' => false]);
    $token = $this->invites['maria@exemplo.test'];

    domainAuthenticate($this, $token);
    flowDelegate($this, $token)->assertCreated();

    $carlaToken = $this->invites['carla@exemplo.test'];
    domainAuthenticate($this, $carlaToken);

    $this->getJson(route('sign.delegation.show', ['token' => $carlaToken]))
        ->assertOk()
        ->assertJsonPath('can_delegate', false)
        ->assertJsonPath('received_from.name', 'Maria Alves');

    flowDelegate($this, $carlaToken, ['email' => 'daniel@exemplo.test'])->assertStatus(422)->assertJsonPath('code', 'chain_limit');

    // Com o limite em 2, a delegada pode repassar uma vez.
    config()->set('assinavelox.delegation.max_chain_depth', 2);

    flowDelegate($this, $carlaToken, ['email' => 'daniel@exemplo.test', 'name' => 'Daniel Rocha'])->assertCreated();

    expect(Delegation::query()->where('status', Delegation::STATUS_EFFECTIVE)->latest('id')->first()?->chain_depth)->toBe(2);
});

it('recusa delegar depois de aceitar e anula o pedido pendente de quem já respondeu', function () {
    $ctx = flowDelegationEnvelope($this);
    $token = $this->invites['maria@exemplo.test'];

    domainAuthenticate($this, $token);
    flowDelegate($this, $token)->assertCreated();

    // A Maria assinou antes de quem enviou decidir.
    flowSign($this, $token)->assertSessionHasNoErrors();

    $delegation = Delegation::query()->sole();

    $this->post(route('envelopes.delegations.approve', ['envelope' => $ctx['envelope']->ulid, 'delegation' => $delegation->ulid]))
        ->assertSessionHas('error');

    expect($delegation->fresh()->status)->toBe(Delegation::STATUS_VOID)
        ->and($ctx['recipients']['maria@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Signed)
        ->and(Recipient::query()->where('email', 'carla@exemplo.test')->exists())->toBeFalse();

    // E um novo pedido depois do aceite não é aceito.
    $response = flowDelegate($this, $token, ['email' => 'outra@exemplo.test']);

    expect($response->status())->toBeIn([403, 404, 409])
        ->and(Delegation::query()->count())->toBe(1);
});

it('recusa participação marcada como pessoal', function () {
    ['recipients' => $recipients] = $ctx = flowDraft([
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ]);

    $this->putJson(route('envelopes.delegation.update', ['envelope' => $ctx['envelope']->ulid]), [
        'allow' => true,
        'requires_confirmation' => false,
        'personal' => [$recipients['maria@exemplo.test']->ulid],
    ])->assertOk()->assertJsonPath('delegation.policy.personal.0', $recipients['maria@exemplo.test']->ulid);

    flowSend($this, $ctx)->assertSessionHasNoErrors();

    $token = $this->invites['maria@exemplo.test'];
    domainAuthenticate($this, $token);

    $this->getJson(route('sign.delegation.show', ['token' => $token]))->assertNotFound();
    flowDelegate($this, $token)->assertStatus(422)->assertJsonPath('code', 'personal');
});

it('sem a política do remetente, sem a flag ou sem a sessão do código, o recurso não existe', function () {
    $ctx = flowDelegationEnvelope($this, ['allow' => false]);
    $token = $this->invites['maria@exemplo.test'];

    // Sem sessão autenticada.
    $this->getJson(route('sign.delegation.show', ['token' => $token]))->assertNotFound();

    domainAuthenticate($this, $token);

    $this->getJson(route('sign.delegation.show', ['token' => $token]))->assertNotFound();
    flowDelegate($this, $token)->assertNotFound();

    // Política ligada, mas sem sessão: 403 (nunca um pedido anônimo).
    $other = flowDelegationEnvelope($this);
    flowDelegate($this, $this->invites['maria@exemplo.test'])->assertStatus(403)->assertJsonPath('code', 'session_required');

    // Flag desligada depois do envio: some.
    config()->set('assinavelox.features.delegation', false);
    domainAuthenticate($this, $this->invites['maria@exemplo.test']);

    $this->getJson(route('sign.delegation.show', ['token' => $this->invites['maria@exemplo.test']]))->assertNotFound();
    flowDelegate($this, $this->invites['maria@exemplo.test'])->assertNotFound();

    expect(Delegation::query()->count())->toBe(0)
        ->and($other['envelope']->fresh()->status)->toBe(EnvelopeStatus::InProgress);
});

it('limita pedidos por participante, por organização e um pendente por vez', function () {
    config()->set('assinavelox.delegation.max_requests_per_recipient', 2);
    config()->set('assinavelox.delegation.max_per_organization_per_day', 3);

    $ctx = flowDelegationEnvelope($this, [], SigningOrder::Parallel);
    $maria = $this->invites['maria@exemplo.test'];
    $joao = $this->invites['joao@exemplo.test'];

    domainAuthenticate($this, $maria);

    flowDelegate($this, $maria)->assertCreated();
    flowDelegate($this, $maria, ['email' => 'outra@exemplo.test'])->assertStatus(409)->assertJsonPath('code', 'pending_exists');

    $this->post(route('envelopes.delegations.reject', ['envelope' => $ctx['envelope']->ulid, 'delegation' => Delegation::query()->sole()->ulid]))
        ->assertSessionHas('success');

    flowDelegate($this, $maria, ['email' => 'outra@exemplo.test'])->assertCreated();

    $this->post(route('envelopes.delegations.reject', ['envelope' => $ctx['envelope']->ulid, 'delegation' => Delegation::query()->latest('id')->first()->ulid]))
        ->assertSessionHas('success');

    flowDelegate($this, $maria, ['email' => 'terceira@exemplo.test'])->assertStatus(429)->assertJsonPath('code', 'recipient_limit');

    domainAuthenticate($this, $joao);
    flowDelegate($this, $joao, ['email' => 'quarta@exemplo.test'])->assertCreated();

    domainAuthenticate($this, $maria);
    config()->set('assinavelox.delegation.max_requests_per_recipient', 10);
    flowDelegate($this, $maria, ['email' => 'quinta@exemplo.test'])->assertStatus(429)->assertJsonPath('code', 'organization_limit');

    expect(Delegation::query()->count())->toBe(3);
});

it('duplicar recomeça com o participante original, que recebe os campos de volta', function () {
    $ctx = flowDelegationEnvelope($this, ['requires_confirmation' => false]);
    $token = $this->invites['maria@exemplo.test'];

    domainAuthenticate($this, $token);
    flowDelegate($this, $token)->assertCreated();

    $this->post(route('envelopes.duplicate', ['envelope' => $ctx['envelope']->ulid]))->assertRedirect();

    $copy = Envelope::query()->where('id', '!=', $ctx['envelope']->id)->latest('id')->firstOrFail();
    $recipients = Recipient::query()->where('envelope_id', $copy->id)->get()->keyBy('email');

    expect($recipients->keys()->sort()->values()->all())->toBe(['joao@exemplo.test', 'maria@exemplo.test'])
        ->and($recipients['maria@exemplo.test']->status)->toBe(RecipientStatus::Pending)
        ->and(SigningField::query()->where('recipient_id', $recipients['maria@exemplo.test']->id)->count())->toBe(1)
        ->and($copy->setting('allow_delegation'))->toBeTrue();
});
