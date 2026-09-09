<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial — invariantes de domínio / concorrência
|--------------------------------------------------------------------------
|
| DEFEITO: `App\Services\Envelopes\FieldSync` e `App\Services\Envelopes\RecipientSync`
| documentam, no próprio docblock, que só rodam com o envelope em
| `draft | preparing | ready` — mas nenhum dos dois VERIFICA o status. A única barreira
| é um `if (! $envelope->status->isDraftLike())` nos controllers, feito sobre o model
| resolvido pelo route binding, fora de qualquer transação e sem `lockForUpdate`.
|
| Consequências, provadas abaixo:
|
|  - `FieldSync::persist()` apaga os `signing_fields` que não voltaram na lista;
|    `signing_field_values.signing_field_id` é ON DELETE CASCADE, então os VALORES já
|    gravados de um aceite existente somem junto — o `signature_acceptance` sobrevive
|    apontando para nada.
|  - `RecipientSync::handle()` apaga os `recipients` fora da lista;
|    `signature_acceptances.recipient_id` é ON DELETE CASCADE, então o ACEITE de quem já
|    assinou é destruído, e `audit_events.recipient_id` (nullOnDelete) perde o vínculo.
|    Ainda reescreve `current_order = 1` de um envelope em andamento.
|
| Além do uso direto do serviço, a guarda do controller é um TOCTOU real: o wizard salva
| campos automaticamente, e um PUT `envelopes.fields.sync` que carregou o envelope como
| `ready` continua valendo depois de outra requisição ter feito o envio — nada é
| revalidado sob lock dentro da transação do sync (compare com
| `SendEnvelope::commitSend`, que faz exatamente isso).
*/

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Services\Envelopes\FieldSync;
use App\Services\Envelopes\RecipientSync;
use App\Services\Envelopes\Sending\SendEnvelope;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';

beforeEach(fn () => $this->withoutVite());

it('FieldSync recusa alterar os campos de um envelope já enviado, em vez de apagar os valores do aceite', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    actingAsMember($owner, $organization);
    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    $recipient = $envelope->recipients()->firstOrFail();
    $field = $envelope->fields()->firstOrFail();

    $acceptance = SignatureAcceptance::query()->create([
        'recipient_id' => $recipient->id,
        'envelope_id' => $envelope->id,
        'document_version_id' => $envelope->sent_document_version_id,
        'organization_id' => $organization->id,
        'accepted_at' => now(),
        'auth_method' => 'email_otp',
        'consent_statement' => 'Declaração de aceite.',
        'document_sha256' => str_repeat('a', 64),
    ]);

    SigningFieldValue::query()->create([
        'signing_field_id' => $field->id,
        'recipient_id' => $recipient->id,
        'signature_acceptance_id' => $acceptance->id,
        'envelope_id' => $envelope->id,
        'organization_id' => $organization->id,
        'image_path' => 'orgs/x/envelopes/y/signatures/signature-1.png',
    ]);

    expect($envelope->status)->toBe(EnvelopeStatus::InProgress);

    // O serviço é o contrato público do passo 3 do wizard; ele precisa recusar aqui.
    $threw = false;

    try {
        app(FieldSync::class)->handle($envelope, ['initials_on_all_pages' => false, 'fields' => []]);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue('FieldSync aceitou reescrever os campos de um envelope in_progress');

    // E, sobretudo: a evidência do aceite não pode ter sido destruída.
    expect(SigningField::query()->where('envelope_id', $envelope->id)->count())->toBe(1)
        ->and(SigningFieldValue::query()->where('signature_acceptance_id', $acceptance->id)->count())->toBe(1);
});

it('RecipientSync recusa alterar a lista de um envelope já enviado, em vez de apagar o aceite de quem assinou', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner, [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com'],
    ]);

    actingAsMember($owner, $organization);
    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    $maria = $envelope->recipients()->where('email', 'maria@exemplo.com')->firstOrFail();
    $maria->forceFill(['status' => RecipientStatus::Signed, 'signed_at' => now()])->save();

    SignatureAcceptance::query()->create([
        'recipient_id' => $maria->id,
        'envelope_id' => $envelope->id,
        'document_version_id' => $envelope->sent_document_version_id,
        'organization_id' => $organization->id,
        'accepted_at' => now(),
        'auth_method' => 'email_otp',
        'consent_statement' => 'Declaração de aceite.',
        'document_sha256' => str_repeat('b', 64),
    ]);

    $carlos = $envelope->recipients()->where('email', 'carlos@exemplo.com')->firstOrFail();

    $threw = false;

    try {
        app(RecipientSync::class)->handle($envelope, [
            'signing_order' => 'sequential',
            'recipients' => [['id' => $carlos->ulid, 'name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com']],
        ]);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue('RecipientSync aceitou reescrever a lista de um envelope in_progress');

    expect(Recipient::query()->where('envelope_id', $envelope->id)->count())->toBe(2)
        ->and(SignatureAcceptance::query()->where('recipient_id', $maria->id)->exists())->toBeTrue();
});
