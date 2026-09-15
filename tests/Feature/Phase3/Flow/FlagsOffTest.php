<?php

use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\SignatureStatus;
use App\Enums\SigningOrder;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\DocumentVersion;
use App\Models\SigningStep;
use App\Services\Envelopes\Finalization\EvidenceData;
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
| Fase 3 §3.3 (F-FLOW) — flags desligadas = comportamento de antes
|--------------------------------------------------------------------------
| As rotas novas não existem, a vez continua sendo só a do sequencial, a evidência não ganha
| chave nova. E desligar a flag depois do envio não abandona um envelope com etapas.
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

it('com as flags desligadas as rotas do fluxo respondem 404 e nada é gravado', function () {
    $ctx = flowDraft([
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ], steps: false, delegation: false);

    $envelope = $ctx['envelope'];

    $this->getJson(route('envelopes.flow.show', ['envelope' => $envelope->ulid]))->assertNotFound();
    flowSaveSteps($this, $ctx, [['recipients' => [$ctx['recipients']['maria@exemplo.test']->ulid]]])->assertNotFound();
    $this->putJson(route('envelopes.delegation.update', ['envelope' => $envelope->ulid]), ['allow' => true, 'requires_confirmation' => false, 'personal' => []])->assertNotFound();
    $this->post(route('envelopes.delegations.approve', ['envelope' => $envelope->ulid, 'delegation' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']))->assertNotFound();

    flowSend($this, $ctx)->assertSessionHasNoErrors();

    $token = $this->invites['maria@exemplo.test'];
    domainAuthenticate($this, $token);

    $this->getJson(route('sign.delegation.show', ['token' => $token]))->assertNotFound();
    flowDelegate($this, $token)->assertNotFound();

    expect($envelope->fresh()->usesSigningSteps())->toBeFalse()
        ->and(SigningStep::query()->count())->toBe(0);
});

it('sem etapas a vez é exatamente a do signing_order e a evidência não ganha chaves novas', function (SigningOrder $order) {
    $ctx = flowDraft([
        ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ], $order, steps: false, delegation: false);

    flowSend($this, $ctx)->assertSessionHasNoErrors();

    $envelope = $ctx['envelope']->fresh();

    expect($envelope->hasTurns())->toBe($order === SigningOrder::Sequential)
        ->and(array_keys($this->invites->getArrayCopy()))->toEqualCanonicalizing(
            $order === SigningOrder::Sequential ? ['paula@exemplo.test'] : ['paula@exemplo.test', 'maria@exemplo.test'],
        );

    // Recusa do aprovador encerra o envelope, como na Fase 2.
    flowRefuse($this, $this->invites['paula@exemplo.test'])->assertSessionHasNoErrors();

    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Refused);

    $version = DocumentVersion::withoutOrganizationScope()->findOrFail($envelope->sent_document_version_id);
    $data = app(EvidenceData::class)->build($envelope, $version, ['original' => 'a', 'sent' => 'b', 'consolidated' => 'c'], SignatureStatus::None);

    expect($data)->not->toHaveKey('flow')
        ->and(collect($data['participants'])->every(fn (array $row): bool => ! array_key_exists('flow_note', $row)))->toBeTrue();
})->with(['sequencial' => [SigningOrder::Sequential], 'paralelo' => [SigningOrder::Parallel]]);

it('desligar a flag depois do envio não abandona um envelope conduzido por etapas', function () {
    $ctx = flowApprovalScenario($this);

    config()->set('assinavelox.features.conditional_steps', false);

    flowSign($this, $this->invites['paula@exemplo.test'], [], withSignature: false)->assertSessionHasNoErrors();
    flowSign($this, $this->invites['ana@exemplo.test'])->assertSessionHasNoErrors();

    expect($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::Finalizing)
        ->and(isset($this->invites['bruno@exemplo.test']))->toBeFalse();
});
