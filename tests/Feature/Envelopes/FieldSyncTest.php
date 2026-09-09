<?php

use App\Enums\AuditEventType;
use App\Enums\FieldBoxType;
use App\Enums\FieldType;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\SigningField;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/WizardHelpers.php';

beforeEach(fn () => $this->withoutVite());

/** Página 2 em paisagem rotacionada, com CropBox deslocado, para testar a origem dos dados. */
function mixedPagesMeta(): array
{
    return [
        ['width_pt' => 595.276, 'height_pt' => 841.89, 'rotation' => 0, 'mediabox' => [0, 0, 595.276, 841.89], 'cropbox' => [0, 0, 595.276, 841.89]],
        ['width_pt' => 780.0, 'height_pt' => 555.0, 'rotation' => 90, 'mediabox' => [0, 0, 575.0, 800.0], 'cropbox' => [10, 20, 565.0, 800.0]],
    ];
}

test('sync grava os campos e a geometria normalizada', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner, 2);
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    $this->put(route('envelopes.fields.sync', $envelope), [
        'initials_on_all_pages' => false,
        'fields' => [
            fieldPayload($maria, ['x' => 0.1234567891, 'y' => 0.2, 'w' => 0.3, 'h' => 0.06]),
            fieldPayload($maria, ['type' => 'date', 'page' => 2, 'x' => 0.6, 'y' => 0.8, 'w' => 0.2, 'h' => 0.03]),
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $fields = $envelope->fresh()->fields()->get();

    expect($fields)->toHaveCount(2);
    expect((float) $fields[0]->x)->toBe(0.123457);
    expect($fields[0]->type)->toBe(FieldType::Signature);
    expect($fields[1]->type)->toBe(FieldType::Date);
    expect($fields[1]->options['date_format'])->toBe('d/m/Y');

    expect(AuditEvent::query()
        ->where('envelope_id', $envelope->id)
        ->where('event_type', AuditEventType::FieldsUpdated)
        ->exists())->toBeTrue();
});

test('campo fora dos limites da página é rejeitado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    $this->from(route('envelopes.edit', $envelope))
        ->put(route('envelopes.fields.sync', $envelope), [
            'initials_on_all_pages' => false,
            'fields' => [fieldPayload($maria, ['x' => 0.85, 'w' => 0.3])],
        ])
        ->assertSessionHasErrors('fields.0.width');

    $this->from(route('envelopes.edit', $envelope))
        ->put(route('envelopes.fields.sync', $envelope), [
            'initials_on_all_pages' => false,
            'fields' => [fieldPayload($maria, ['y' => 0.99, 'h' => 0.06])],
        ])
        ->assertSessionHasErrors('fields.0.height');

    $this->from(route('envelopes.edit', $envelope))
        ->put(route('envelopes.fields.sync', $envelope), [
            'initials_on_all_pages' => false,
            'fields' => [fieldPayload($maria, ['x' => -0.2])],
        ])
        ->assertSessionHasErrors('fields.0.x');

    expect($envelope->fresh()->fields()->count())->toBe(0);
});

test('campo menor que o mínimo do tipo é rejeitado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    $this->from(route('envelopes.edit', $envelope))
        ->put(route('envelopes.fields.sync', $envelope), [
            'initials_on_all_pages' => false,
            'fields' => [fieldPayload($maria, ['w' => 0.01, 'h' => 0.002])],
        ])
        ->assertSessionHasErrors(['fields.0.width', 'fields.0.height']);
});

test('página inexistente no documento é rejeitada', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner, 3);
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    $this->from(route('envelopes.edit', $envelope))
        ->put(route('envelopes.fields.sync', $envelope), [
            'initials_on_all_pages' => false,
            'fields' => [fieldPayload($maria, ['page' => 9])],
        ])
        ->assertSessionHasErrors('fields.0.page');
});

test('signatário de outro envelope é rejeitado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);
    addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    $other = draftWithDocument($organization, $owner);
    $alien = addRecipient($other, 'Alheio', 'alheio@exemplo.com');

    actingAsMember($owner, $organization);

    $this->from(route('envelopes.edit', $envelope))
        ->put(route('envelopes.fields.sync', $envelope), [
            'initials_on_all_pages' => false,
            'fields' => [fieldPayload($alien)],
        ])
        ->assertSessionHasErrors('fields.0.recipient_id');

    expect($envelope->fresh()->fields()->count())->toBe(0);
});

test('valores de página vindos do cliente são ignorados em favor do pages_meta', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner, 2, mixedPagesMeta());
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    $this->put(route('envelopes.fields.sync', $envelope), [
        'initials_on_all_pages' => false,
        'fields' => [
            fieldPayload($maria, [
                'page' => 2,
                // Mentiras do cliente: página quadrada, sem rotação, MediaBox.
                'page_width_pt' => 100,
                'page_height_pt' => 100,
                'page_rotation' => 0,
                'box_type' => 'mediabox',
            ]),
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $field = $envelope->fresh()->fields()->first();

    // CropBox [10,20,565,800] com /Rotate 90 → exibido 780 × 555.
    expect((float) $field->page_width_pt)->toBe(780.0);
    expect((float) $field->page_height_pt)->toBe(555.0);
    expect($field->page_rotation)->toBe(90);
    expect($field->box_type)->toBe(FieldBoxType::CropBox);
});

test('a rubrica em todas as páginas gera um campo por página e por signatário', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner, 3);
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com', 1);
    $carlos = addRecipient($envelope, 'Carlos', 'carlos@exemplo.com', 2);

    actingAsMember($owner, $organization);

    $this->put(route('envelopes.fields.sync', $envelope), [
        'initials_on_all_pages' => true,
        'fields' => [fieldPayload($maria), fieldPayload($carlos)],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $initials = $envelope->fresh()->fields()->where('type', FieldType::Initials)->get();

    expect($initials)->toHaveCount(6); // 3 páginas × 2 signatários
    expect($initials->pluck('page')->unique()->sort()->values()->all())->toBe([1, 2, 3]);
    expect((float) $initials->first()->x)->toBe(0.86);
    expect((float) $initials->first()->y)->toBe(0.94);
    expect((float) $initials->first()->width)->toBe(0.1);
    expect((float) $initials->first()->height)->toBe(0.04);
    expect($initials->first()->options['auto'])->toBeTrue();

    expect((bool) $envelope->fresh()->setting('initials_on_all_pages'))->toBeTrue();
});

test('a rubrica automática não duplica onde já existe uma rubrica manual do mesmo signatário', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner, 3);
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    $this->put(route('envelopes.fields.sync', $envelope), [
        'initials_on_all_pages' => true,
        'fields' => [
            fieldPayload($maria),
            fieldPayload($maria, ['type' => 'initials', 'page' => 2, 'x' => 0.2, 'y' => 0.5, 'w' => 0.1, 'h' => 0.04]),
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $initials = $envelope->fresh()->fields()->where('type', FieldType::Initials)->orderBy('page')->get();

    expect($initials)->toHaveCount(3);          // uma por página, sem duplicar a manual
    expect($initials->where('page', 2))->toHaveCount(1);

    $manual = $initials->firstWhere('page', 2);
    expect((float) $manual->x)->toBe(0.2);
    expect($manual->options['auto'] ?? false)->toBeFalse();
});

test('reenviar a lista com as rubricas automáticas não as multiplica', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner, 3);
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    $payload = ['initials_on_all_pages' => true, 'fields' => [fieldPayload($maria)]];

    $this->put(route('envelopes.fields.sync', $envelope), $payload)->assertRedirect();

    // Segundo salvamento: o front devolve tudo o que recebeu, inclusive as automáticas.
    $roundTrip = $envelope->fresh()->fields()->get()->map(fn (SigningField $field): array => [
        'id' => $field->ulid,
        'client_id' => $field->ulid,
        'auto' => (bool) ($field->options['auto'] ?? false),
        'recipient_id' => $maria->ulid,
        'type' => $field->type->value,
        'page' => (int) $field->page,
        'x' => (float) $field->x,
        'y' => (float) $field->y,
        'w' => (float) $field->width,
        'h' => (float) $field->height,
        'required' => (bool) $field->required,
    ])->all();

    $this->put(route('envelopes.fields.sync', $envelope), [
        'initials_on_all_pages' => true,
        'fields' => $roundTrip,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($envelope->fresh()->fields()->where('type', FieldType::Initials)->count())->toBe(3);
    expect($envelope->fresh()->fields()->count())->toBe(4);
});

test('desmarcar a rubrica em todas as páginas remove os campos automáticos', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner, 2);
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    $this->put(route('envelopes.fields.sync', $envelope), [
        'initials_on_all_pages' => true,
        'fields' => [fieldPayload($maria)],
    ])->assertRedirect();

    expect($envelope->fresh()->fields()->count())->toBe(3);

    $this->put(route('envelopes.fields.sync', $envelope), [
        'initials_on_all_pages' => false,
        'fields' => [fieldPayload($maria)],
    ])->assertRedirect();

    expect($envelope->fresh()->fields()->count())->toBe(1);
    expect((bool) $envelope->fresh()->setting('initials_on_all_pages'))->toBeFalse();
});

test('page "all" só vale para rubrica e vira um campo por página', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner, 3);
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    $this->from(route('envelopes.edit', $envelope))
        ->put(route('envelopes.fields.sync', $envelope), [
            'initials_on_all_pages' => false,
            'fields' => [fieldPayload($maria, ['page' => 'all'])],
        ])
        ->assertSessionHasErrors('fields.0.page');

    $this->put(route('envelopes.fields.sync', $envelope), [
        'initials_on_all_pages' => false,
        'fields' => [
            fieldPayload($maria),
            fieldPayload($maria, ['type' => 'initials', 'page' => 'all', 'x' => 0.5, 'y' => 0.5, 'w' => 0.1, 'h' => 0.04]),
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($envelope->fresh()->fields()->where('type', FieldType::Initials)->count())->toBe(3);
});

test('sync de campos exige documento processado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create();
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    $this->from(route('envelopes.edit', $envelope))
        ->put(route('envelopes.fields.sync', $envelope), [
            'initials_on_all_pages' => false,
            'fields' => [fieldPayload($maria)],
        ])
        ->assertSessionHasErrors('fields');
});

test('envelope enviado recusa alteração de campos', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    $this->from(route('envelopes.show', $envelope))
        ->put(route('envelopes.fields.sync', $envelope), [
            'initials_on_all_pages' => false,
            'fields' => [fieldPayload($maria)],
        ])
        ->assertSessionHas('error', 'Ação indisponível no status atual.');
});

test('outra organização não altera os campos', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    ['organization' => $other, 'owner' => $intruder] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);
    $maria = addRecipient($envelope, 'Maria', 'maria@exemplo.com');

    actingAsMember($intruder, $other);

    $this->put(route('envelopes.fields.sync', $envelope), [
        'initials_on_all_pages' => false,
        'fields' => [fieldPayload($maria)],
    ])->assertNotFound();
});
