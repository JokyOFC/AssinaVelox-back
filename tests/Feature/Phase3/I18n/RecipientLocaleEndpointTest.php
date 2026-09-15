<?php

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\User;

require_once __DIR__.'/Support/I18nHelpers.php';

/*
|--------------------------------------------------------------------------
| F-I18N — idioma do participante no wizard (docs/fase-3/multilingue.md §3)
|--------------------------------------------------------------------------
*/

beforeEach(fn () => $this->withoutVite());

/**
 * @return array{organization: Organization, owner: User, envelope: Envelope, recipient: Recipient}
 */
function localeDraft(bool $flag = true): array
{
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    i18nEnableFlag($organization, $flag);
    actingAsMember($owner, $organization);

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create();
    $recipient = Recipient::factory()->forEnvelope($envelope)->create(['name' => 'Maria Alves Souza']);

    return ['organization' => $organization, 'owner' => $owner, 'envelope' => $envelope, 'recipient' => $recipient];
}

it('responde 404 com a flag desligada', function () {
    ['envelope' => $envelope, 'recipient' => $recipient] = localeDraft(flag: false);

    $this->getJson(route('envelopes.recipients.locales', $envelope->ulid))->assertNotFound();
    $this->putJson(route('envelopes.recipients.locale', [$envelope->ulid, $recipient->ulid]), ['locale' => 'en'])->assertNotFound();

    expect($recipient->fresh()->locale)->toBe('pt_BR');
});

it('lista os idiomas e grava o do participante, com trilha', function () {
    ['envelope' => $envelope, 'recipient' => $recipient] = localeDraft();

    $this->getJson(route('envelopes.recipients.locales', $envelope->ulid))
        ->assertOk()
        ->assertJsonPath('reference_locale', 'pt_BR')
        ->assertJsonPath('editable', true)
        ->assertJsonPath('locales.1.code', 'en')
        ->assertJsonPath("recipients.{$recipient->ulid}.locale", 'pt_BR');

    $this->putJson(route('envelopes.recipients.locale', [$envelope->ulid, $recipient->ulid]), [
        'locale' => 'es',
        'timezone' => 'Europe/Madrid',
    ])->assertOk()->assertJsonPath('locale', 'es')->assertJsonPath('timezone', 'Europe/Madrid');

    expect($recipient->fresh())->locale->toBe('es')->timezone->toBe('Europe/Madrid');

    $event = AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::RecipientLocaleUpdated)->sole();

    expect($event->payload)->toMatchArray(['recipient_ulid' => $recipient->ulid, 'from' => 'pt_BR', 'to' => 'es']);
});

it('recusa idioma fora da lista fechada e fuso inválido', function (array $payload) {
    ['envelope' => $envelope, 'recipient' => $recipient] = localeDraft();

    $this->putJson(route('envelopes.recipients.locale', [$envelope->ulid, $recipient->ulid]), $payload)->assertUnprocessable();

    expect($recipient->fresh()->locale)->toBe('pt_BR');
})->with([
    'idioma desconhecido' => [['locale' => 'fr']],
    'caminho' => [['locale' => '../../lang/en']],
    'lista' => [['locale' => ['en']]],
    'fuso' => [['locale' => 'en', 'timezone' => 'Marte/Olympus']],
]);

it('só altera antes do envio', function () {
    ['envelope' => $envelope, 'recipient' => $recipient] = localeDraft();
    $envelope->forceFill(['status' => 'in_progress'])->save();

    $this->putJson(route('envelopes.recipients.locale', [$envelope->ulid, $recipient->ulid]), ['locale' => 'en'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.locale.0', 'O idioma do participante só pode ser alterado antes do envio.');
});

it('não enxerga envelope nem participante de outra organização', function () {
    ['envelope' => $envelope, 'recipient' => $recipient] = localeDraft();
    ['envelope' => $other, 'recipient' => $stranger] = localeDraft();

    // Agora autenticado na segunda organização: o envelope da primeira não existe para ela.
    $this->getJson(route('envelopes.recipients.locales', $envelope->ulid))->assertNotFound();
    $this->putJson(route('envelopes.recipients.locale', [$other->ulid, $recipient->ulid]), ['locale' => 'en'])->assertNotFound();

    expect($recipient->fresh()->locale)->toBe('pt_BR')
        ->and($stranger->fresh()->locale)->toBe('pt_BR');
});
