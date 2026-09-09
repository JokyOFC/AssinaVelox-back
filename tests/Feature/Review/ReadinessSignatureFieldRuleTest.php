<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial — invariantes de domínio
|--------------------------------------------------------------------------
|
| DEFEITO: a MESMA regra ("todo signatário precisa de pelo menos um campo de assinatura",
| arquitetura §3.2 / ROUTES §2.6 passo 3) está implementada duas vezes, com definições
| diferentes:
|
|  - `App\Services\Documents\EnvelopeReadiness::everyRecipientHasSignatureField()` —
|    aceita QUALQUER campo `signature`, obrigatório ou não. É quem DECIDE e GRAVA o
|    status do envelope;
|  - `App\Services\Envelopes\EnvelopeReadiness::fieldIssues()` — exige
|    `type === signature && required`. É quem monta a lista de pendências em PT-BR
|    exibida no passo 4 do wizard.
|
| Como `SyncFieldsRequest`/`FieldSync` aceitam `required: false` em qualquer campo,
| um único campo de assinatura opcional produz um envelope simultaneamente:
|
|   status = `ready`  (habilita o botão "Enviar")
|   issues = ["Todo signatário precisa de pelo menos um campo de assinatura."]
|
| e `SendEnvelope::commitSend()`, que só olha o STATUS, envia mesmo assim — enquanto a
| própria tela dizia que faltava um campo. O comentário do serviço de prontidão afirma
| que existe "um único escritor do status e uma única definição de completude"; não existe.
*/

use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Models\SigningField;
use App\Services\Envelopes\EnvelopeReadiness;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Envelopes/WizardHelpers.php';

beforeEach(fn () => $this->withoutVite());

it('não deixa o envelope ficar `ready` enquanto a própria lista de pendências acusa falta de campo de assinatura', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner, 1);
    $maria = addRecipient($envelope, 'Maria Alves', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    // Único campo de assinatura da Maria, marcado como NÃO obrigatório.
    $this->put(route('envelopes.fields.sync', $envelope), [
        'initials_on_all_pages' => false,
        'fields' => [fieldPayload($maria, ['required' => false])],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $envelope = $envelope->fresh();

    $issues = EnvelopeReadiness::issues($envelope);

    // As duas leituras da mesma regra têm de concordar: `ready` e "há pendências" não
    // podem ser verdade ao mesmo tempo.
    if ($envelope->status === EnvelopeStatus::Ready) {
        expect($issues)->toBe([], 'envelope `ready` com pendências listadas: '.implode(' | ', $issues));
    } else {
        expect($issues)->not->toBe([]);
    }
});

it('não envia um documento cuja lista de pendências ainda acusa falta de campo de assinatura', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner, 1);
    $maria = addRecipient($envelope, 'Maria Alves', 'maria@exemplo.com');

    actingAsMember($owner, $organization);

    $this->put(route('envelopes.fields.sync', $envelope), [
        'initials_on_all_pages' => false,
        'fields' => [fieldPayload($maria, ['required' => false])],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $envelope = $envelope->fresh();

    // AJUSTE DESTA REVISÃO — a correção fecha a porta pela qual este teste entrava.
    //
    // `FieldSync` passou a forçar `required = true` em campos `signature`/`initials` (um
    // campo de assinatura opcional não existe no domínio: o aceite é a manifestação de
    // vontade), então o estado divergente já não é alcançável pela rota. O que continua
    // valendo — e é o que o achado cobrava — é que o ENVIO não pode confiar apenas no
    // status gravado. Aqui a divergência é reproduzida direto no banco (dado legado, ou
    // escrita fora do serviço) para exercitar essa defesa.
    SigningField::query()
        ->where('envelope_id', $envelope->getKey())
        ->where('type', FieldType::Signature->value)
        ->update(['required' => false]);

    $envelope->forceFill(['status' => EnvelopeStatus::Ready])->save();
    $envelope = $envelope->fresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Ready)
        ->and(EnvelopeReadiness::issues($envelope))->not->toBe([]);

    $this->post(route('envelopes.send', $envelope));

    expect($envelope->fresh()->status)->not->toBe(EnvelopeStatus::InProgress);
});
