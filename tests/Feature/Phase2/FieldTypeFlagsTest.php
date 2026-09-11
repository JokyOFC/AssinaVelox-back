<?php

use App\Enums\FieldType;
use App\Models\SigningField;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Envelopes/WizardHelpers.php';
require_once __DIR__.'/Identity/Support/IdentityHelpers.php';
require_once __DIR__.'/Branding/Support/BrandingHelpers.php';

/*
|--------------------------------------------------------------------------
| Tipos de campo da onda B atrás de flag (integração I-2B)
|--------------------------------------------------------------------------
| `cpf` (flag `cpf_field`) e `stamp` (flag `branding`). Antes da integração um POST forjado
| com esses tipos chegava ao FieldGeometry sem tamanho mínimo e respondia 500; agora é 422 no
| próprio campo com a flag desligada, e o campo é aceito (com o mínimo do tipo) com ela.
*/

function fieldFlagsDraft(): array
{
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);
    $recipient = addRecipient($envelope, 'Maria Souza', 'maria@exemplo.com');
    actingAsMember($owner, $organization);

    return [$organization, $envelope, $recipient];
}

it('recusa campo CPF e carimbo com as flags desligadas (422 no campo, não 500)', function (string $type) {
    [, $envelope, $recipient] = fieldFlagsDraft();

    $this->put(route('envelopes.fields.sync', $envelope), [
        'fields' => [
            fieldPayload($recipient),
            fieldPayload($recipient, ['client_id' => 'novo', 'type' => $type, 'y' => 0.5, 'w' => 0.36, 'h' => 0.12]),
        ],
    ])->assertSessionHasErrors('fields.1.type');

    expect(SigningField::query()->where('type', $type)->count())->toBe(0);
})->with(['cpf', 'stamp']);

it('aceita os tipos com a flag ligada e aplica o tamanho mínimo do tipo', function () {
    [$organization, $envelope, $recipient] = fieldFlagsDraft();
    identityEnableFlags($organization, ['cpf_field']);
    brandingEnable($organization);

    $this->put(route('envelopes.fields.sync', $envelope), [
        'fields' => [
            fieldPayload($recipient),
            fieldPayload($recipient, ['client_id' => 'cpf', 'type' => 'cpf', 'y' => 0.4, 'w' => 0.3, 'h' => 0.04, 'options' => ['font_size' => 11]]),
            fieldPayload($recipient, ['client_id' => 'stamp', 'type' => 'stamp', 'y' => 0.6, 'w' => 0.36, 'h' => 0.12]),
        ],
    ])->assertSessionHasNoErrors();

    expect(SigningField::query()->where('type', FieldType::Cpf->value)->sole()->options['font_size'])->toBe(11)
        ->and(SigningField::query()->where('type', FieldType::Stamp->value)->count())->toBe(1);

    // Carimbo menor que 60×20 pt: recusado pelo mínimo, não por erro interno.
    $this->put(route('envelopes.fields.sync', $envelope), [
        'fields' => [
            fieldPayload($recipient),
            fieldPayload($recipient, ['client_id' => 'mini', 'type' => 'stamp', 'y' => 0.6, 'w' => 0.02, 'h' => 0.01]),
        ],
    ])->assertSessionHasErrors();
});

it('um campo CPF já gravado continua aceito se a flag for desligada depois', function () {
    [$organization, $envelope, $recipient] = fieldFlagsDraft();
    identityEnableFlags($organization, ['cpf_field']);

    $this->put(route('envelopes.fields.sync', $envelope), [
        'fields' => [
            fieldPayload($recipient),
            fieldPayload($recipient, ['client_id' => 'cpf', 'type' => 'cpf', 'y' => 0.4, 'w' => 0.3, 'h' => 0.04]),
        ],
    ])->assertSessionHasNoErrors();

    $cpf = SigningField::query()->where('type', FieldType::Cpf->value)->sole();
    $signature = SigningField::query()->where('type', FieldType::Signature->value)->sole();
    config()->set('assinavelox.features.cpf_field', false);

    $this->put(route('envelopes.fields.sync', $envelope), [
        'fields' => [
            fieldPayload($recipient, ['id' => $signature->ulid]),
            fieldPayload($recipient, ['id' => $cpf->ulid, 'type' => 'cpf', 'y' => 0.45, 'w' => 0.3, 'h' => 0.04]),
        ],
    ])->assertSessionHasNoErrors();

    // Um CPF NOVO, não.
    $this->put(route('envelopes.fields.sync', $envelope), [
        'fields' => [
            fieldPayload($recipient, ['id' => $signature->ulid]),
            fieldPayload($recipient, ['client_id' => 'outro', 'type' => 'cpf', 'y' => 0.7, 'w' => 0.3, 'h' => 0.04]),
        ],
    ])->assertSessionHasErrors('fields.1.type');
});
