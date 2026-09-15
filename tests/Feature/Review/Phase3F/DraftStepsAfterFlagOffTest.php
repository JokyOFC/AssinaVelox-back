<?php

use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\SigningOrder;
use App\Events\EnvelopeReadyForFinalization;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Domain/Support/DomainHelpers.php';
require_once __DIR__.'/../../Phase3/Flow/Support/FlowHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial F-FLOW — rascunho com etapas quando a flag é desligada
|--------------------------------------------------------------------------
| FlowFeatures: "A flag liga a INTERFACE e a criação de coisas novas". Um RASCUNHO cujas etapas
| foram salvas com a flag ligada continua com `uses_signing_steps = true` depois que a flag é
| desligada. O remetente não consegue desfazer (o PUT das etapas responde 404 sem a flag) e o
| envio (`SigningStepsOnSend`, que não olha a flag) conduz o envelope por etapas: no paralelo, só
| a etapa 1 é convidada. Com a flag desligada, o envio deixa de ser o de antes.
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

it('com a flag desligada antes do envio, o remetente consegue voltar ao envio de antes e o paralelo convida todos', function () {
    $ctx = flowDraft([
        ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Ana Compradora', 'email' => 'ana@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ['name' => 'Bruno Jurídico', 'email' => 'bruno@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ], SigningOrder::Parallel);

    $r = $ctx['recipients'];
    $paula = $r['paula@exemplo.test']->ulid;

    flowSaveSteps($this, $ctx, [
        ['name' => 'Aprovação', 'recipients' => [$paula]],
        ['name' => 'Compradora', 'recipients' => [$r['ana@exemplo.test']->ulid], 'condition' => flowDecisionRule($paula, 'approved')],
        ['name' => 'Jurídico', 'recipients' => [$r['bruno@exemplo.test']->ulid], 'condition' => flowDecisionRule($paula, 'refused')],
    ])->assertOk();

    // O proprietário desliga a flag (global) com o rascunho ainda em preparo.
    config()->set('assinavelox.features.conditional_steps', false);

    // O remetente tenta desfazer as etapas pelo mesmo caminho da interface.
    $disable = $this->putJson(route('envelopes.steps.update', ['envelope' => $ctx['envelope']->ulid]), ['enabled' => false]);

    flowSend($this, $ctx)->assertSessionHasNoErrors();

    // Hoje: o PUT dá 404, o envelope sai com `uses_signing_steps = true` e só a Paula é convidada.
    expect(['disable_status' => $disable->status(), 'uses_steps' => $ctx['envelope']->fresh()->usesSigningSteps()])
        ->toBeArray()
        ->and(array_keys($this->invites->getArrayCopy()))->toEqualCanonicalizing([
            'paula@exemplo.test',
            'ana@exemplo.test',
            'bruno@exemplo.test',
        ]);
});
