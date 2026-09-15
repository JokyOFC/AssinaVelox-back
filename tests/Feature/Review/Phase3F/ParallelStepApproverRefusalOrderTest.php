<?php

use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
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
| Revisão adversarial F-FLOW — recusa de aprovador no paralelo com etapas
|--------------------------------------------------------------------------
| docs/fase-3/etapas-e-delegacao.md §2.4: a recusa de um APROVADOR cuja decisão uma etapa
| posterior lê recalcula o fluxo; "não resta etapa aplicável → a recusa encerra o envelope,
| como sempre". O recálculo só acontece NO INSTANTE da recusa. Se outro participante da mesma
| etapa ainda está pendente, a recusa "continua" o envelope; quando esse participante termina,
| a etapa seguinte é pulada e o envelope vai para `finalizing` — concluído, com a recusa do
| aprovador dentro. As MESMAS decisões, em ordem inversa, dão `refused`.
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

it('a recusa do aprovador lida por uma etapa posterior dá o mesmo resultado qualquer que seja a ordem das decisões da etapa', function (string $order) {
    $ctx = flowDraft([
        ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Sofia Locadora', 'email' => 'sofia@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ['name' => 'Ana Compradora', 'email' => 'ana@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ], SigningOrder::Parallel);

    $r = $ctx['recipients'];
    $paula = $r['paula@exemplo.test']->ulid;

    flowSaveSteps($this, $ctx, [
        ['name' => 'Aprovação e locadora', 'recipients' => [$paula, $r['sofia@exemplo.test']->ulid]],
        ['name' => 'Compradora', 'recipients' => [$r['ana@exemplo.test']->ulid], 'condition' => flowDecisionRule($paula, 'approved')],
    ])->assertOk();

    flowSend($this, $ctx)->assertSessionHasNoErrors();

    if ($order === 'refuse_first') {
        flowRefuse($this, $this->invites['paula@exemplo.test'])->assertSessionHasNoErrors();
        flowSign($this, $this->invites['sofia@exemplo.test'])->assertSessionHasNoErrors();
    } else {
        flowSign($this, $this->invites['sofia@exemplo.test'])->assertSessionHasNoErrors();
        flowRefuse($this, $this->invites['paula@exemplo.test'])->assertSessionHasNoErrors();
    }

    $envelope = $ctx['envelope']->fresh();

    expect($r['paula@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Refused)
        ->and($r['ana@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Canceled)
        // Nenhuma etapa aplicável depois da recusa: a regra do §2.4 manda encerrar como recusado.
        // Hoje, com a recusa ANTES da locadora, o envelope vai para `finalizing` (será concluído).
        ->and($envelope->status)->toBe(EnvelopeStatus::Refused);
})->with([
    'recusa antes da locadora assinar' => ['refuse_first'],
    'recusa depois da locadora assinar' => ['sign_first'],
]);
