<?php

use App\Enums\RecipientStatus;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\Delegation;
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
| Revisão adversarial F-FLOW — delegar para o próprio endereço com +alias
|--------------------------------------------------------------------------
| DelegationService::request só compara o e-mail inteiro, em minúsculas. "maria+x@..." é a
| mesma caixa de correio da Maria: ela "delega" para si mesma, com outro nome, e as evidências
| passam a dizer que uma terceira pessoa participou "com aceite próprio" (T1).
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

it('recusa delegar para o próprio endereço escrito com +alias', function () {
    $ctx = flowDelegationEnvelope($this, ['requires_confirmation' => false]);
    $token = $this->invites['maria@exemplo.test'];

    domainAuthenticate($this, $token);

    $response = flowDelegate($this, $token, ['email' => 'Maria+Procuradora@Exemplo.test', 'name' => 'Carla Souza']);

    expect($response->status())->toBe(422)
        ->and($response->json('code'))->toBe('self')
        ->and(Delegation::query()->count())->toBe(0)
        ->and($ctx['recipients']['maria@exemplo.test']->fresh()->status)->not->toBe(RecipientStatus::Delegated);
});
