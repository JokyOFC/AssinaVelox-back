<?php

use App\Enums\AuditEventType;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Models\SigningField;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/WizardHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('sync grava a lista, define order_index pela ordem recebida e registra a trilha', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);

    actingAsMember($owner, $organization);

    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [
            ['id' => null, 'name' => 'Maria A. Souza', 'email' => 'maria@exemplo.com', 'role' => 'Locatária', 'order' => 1],
            ['id' => null, 'name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com', 'role' => 'Fiador', 'order' => 2],
        ],
    ])->assertRedirect();

    $recipients = $envelope->fresh()->recipients()->orderBy('order_index')->get();

    expect($recipients)->toHaveCount(2);
    expect($recipients[0]->name)->toBe('Maria A. Souza');
    expect($recipients[0]->order_index)->toBe(1);
    expect($recipients[0]->role_label)->toBe('Locatária');
    expect($recipients[1]->email)->toBe('carlos@exemplo.com');
    expect($recipients[1]->order_index)->toBe(2);
    expect($recipients[1]->status)->toBe(RecipientStatus::Pending);

    expect(AuditEvent::query()
        ->where('envelope_id', $envelope->id)
        ->where('event_type', AuditEventType::RecipientsUpdated)
        ->exists())->toBeTrue();
});

test('sync preserva os id enviados e remove quem saiu da lista (com os campos dele)', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);

    $keep = addRecipient($envelope, 'Maria', 'maria@exemplo.com', 1);
    $drop = addRecipient($envelope, 'Carlos', 'carlos@exemplo.com', 2);
    SigningField::factory()->forRecipient($drop)->signature()->create();

    actingAsMember($owner, $organization);

    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [
            ['id' => $keep->ulid, 'name' => 'Maria A. Souza', 'email' => 'maria@exemplo.com', 'order' => 1],
        ],
    ])->assertRedirect();

    expect(Recipient::query()->whereKey($keep->id)->exists())->toBeTrue();
    expect($keep->fresh()->name)->toBe('Maria A. Souza');
    expect(Recipient::query()->whereKey($drop->id)->exists())->toBeFalse();
    expect(SigningField::query()->where('recipient_id', $drop->id)->exists())->toBeFalse();
});

test('sync rejeita e-mail duplicado no mesmo envelope', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);

    actingAsMember($owner, $organization);

    $this->from(route('envelopes.edit', $envelope))
        ->put(route('envelopes.recipients.sync', $envelope), [
            'signing_order' => 'parallel',
            'recipients' => [
                ['id' => null, 'name' => 'Maria', 'email' => 'igual@exemplo.com', 'order' => 1],
                ['id' => null, 'name' => 'Carlos', 'email' => 'IGUAL@exemplo.com', 'order' => 2],
            ],
        ])
        ->assertSessionHasErrors('recipients.1.email');

    expect($envelope->fresh()->recipients()->count())->toBe(0);
});

test('sync exige pelo menos um signatário', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);

    actingAsMember($owner, $organization);

    $this->from(route('envelopes.edit', $envelope))
        ->put(route('envelopes.recipients.sync', $envelope), ['signing_order' => 'sequential', 'recipients' => []])
        ->assertSessionHasErrors('recipients');
});

test('sync troca e-mails entre dois signatários sem violar a unicidade', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);

    $a = addRecipient($envelope, 'Maria', 'a@exemplo.com', 1);
    $b = addRecipient($envelope, 'Carlos', 'b@exemplo.com', 2);

    actingAsMember($owner, $organization);

    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [
            ['id' => $a->ulid, 'name' => 'Maria', 'email' => 'b@exemplo.com', 'order' => 1],
            ['id' => $b->ulid, 'name' => 'Carlos', 'email' => 'a@exemplo.com', 'order' => 2],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($a->fresh()->email)->toBe('b@exemplo.com');
    expect($b->fresh()->email)->toBe('a@exemplo.com');
});

test('em paralelo todos ficam na mesma vez (order_index = 1)', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);

    actingAsMember($owner, $organization);

    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'parallel',
        'recipients' => [
            ['id' => null, 'name' => 'Maria', 'email' => 'maria@exemplo.com', 'order' => 1],
            ['id' => null, 'name' => 'Carlos', 'email' => 'carlos@exemplo.com', 'order' => 2],
        ],
    ])->assertRedirect();

    $envelope = $envelope->fresh();

    expect($envelope->signing_order)->toBe(SigningOrder::Parallel);
    expect($envelope->recipients()->pluck('order_index')->all())->toBe([1, 1]);
});

test('envelope já enviado recusa a alteração da lista', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    $this->from(route('envelopes.show', $envelope))
        ->put(route('envelopes.recipients.sync', $envelope), [
            'signing_order' => 'sequential',
            'recipients' => [['id' => null, 'name' => 'Outro', 'email' => 'outro@exemplo.com', 'order' => 1]],
        ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Ação indisponível no status atual.');

    expect($envelope->fresh()->recipients()->pluck('email')->all())->toBe(['maria@exemplo.com']);
});

test('sync rejeita id de signatário de outro envelope', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);
    $other = draftWithDocument($organization, $owner);
    $alien = addRecipient($other, 'Alheio', 'alheio@exemplo.com');

    actingAsMember($owner, $organization);

    $this->from(route('envelopes.edit', $envelope))
        ->put(route('envelopes.recipients.sync', $envelope), [
            'signing_order' => 'sequential',
            'recipients' => [['id' => $alien->ulid, 'name' => 'Alheio', 'email' => 'alheio@exemplo.com', 'order' => 1]],
        ])
        ->assertSessionHasErrors('recipients.0.id');
});

test('editar signatário pendente após o envio revoga os links e avisa que falta reenviar', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    $recipient = Recipient::factory()->forEnvelope($envelope)->notified()->create(['email' => 'antigo@exemplo.com']);
    $link = RecipientAccessLink::factory()->create([
        'recipient_id' => $recipient->id,
        'envelope_id' => $envelope->id,
        'organization_id' => $organization->id,
    ]);

    actingAsMember($owner, $organization);

    $this->patch(route('envelopes.recipients.update', [$envelope, $recipient]), [
        'name' => 'Maria Corrigida',
        'email' => 'novo@exemplo.com',
    ])->assertRedirect()->assertSessionHas('warning');

    expect($recipient->fresh()->email)->toBe('novo@exemplo.com');
    expect($link->fresh()->revoked_at)->not->toBeNull();
});

test('signatário que já assinou não pode ser editado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    $recipient = Recipient::factory()->forEnvelope($envelope)->signed()->create();

    actingAsMember($owner, $organization);

    $this->from(route('envelopes.show', $envelope))
        ->patch(route('envelopes.recipients.update', [$envelope, $recipient]), [
            'name' => 'Outro nome',
            'email' => 'outro@exemplo.com',
        ])
        ->assertSessionHasErrors('name');
});

test('outra organização não enxerga nem altera os signatários', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    ['organization' => $other, 'owner' => $intruder] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);

    actingAsMember($intruder, $other);

    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [['id' => null, 'name' => 'Invasor', 'email' => 'invasor@exemplo.com', 'order' => 1]],
    ])->assertNotFound();

    expect($envelope->fresh()->recipients()->count())->toBe(0);
});
