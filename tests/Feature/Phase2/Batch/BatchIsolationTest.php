<?php

use App\Enums\AuthMethod;
use App\Enums\EnvelopeStatus;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\SignatureAcceptance;
use App\Services\Batch\Models\BatchSigningItem;
use App\Services\Batch\Models\BatchSigningSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../InPerson/Support/PresenceHelpers.php';

/*
|--------------------------------------------------------------------------
| Assinatura em lote — organizações, itens encerrados e corrida
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/batch-iso-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    $this->batchCodes = presenceCaptureBatchCodes();
    $this->batchLinks = presenceCaptureBatchLinks();
    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('item de outra organização remetente nunca entra no lote, nem por ULID', function () {
    $s = batchScenario();

    // A outra organização também tem um lote para a Maria (dois documentos lá).
    signerEnvelope([['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test']], envelopeAttributes: ['title' => 'Segundo da outra conta'], organization: $s['other'], owner: $s['other_owner']);
    presenceEnable($s['other']);
    batchIssue($this, $s['other'], $s['other_owner'], $s['foreign']['envelope'], $s['maria_foreign'])->assertSessionHas('success');
    $foreignItem = BatchSigningItem::withoutOrganizationScope()->where('organization_id', $s['other']->id)->orderBy('position')->first();

    $this->flushSession();
    batchIssue($this, $s['organization'], $s['owner'], $s['one']['envelope'], $s['maria_one'])->assertSessionHas('success');

    $ours = BatchSigningSession::withoutOrganizationScope()->where('organization_id', $s['organization']->id)->sole();
    expect($ours->items()->pluck('envelope_id')->all())->not->toContain($s['foreign']['envelope']->id);

    batchAsParticipant($this);
    $props = batchAuthenticate($this, $this->batchLinks[1]);

    expect(collect($props['items'])->pluck('title')->all())->not->toContain('Contrato de outra conta');

    // O item da outra organização, pelo ULID, no lote da Horizonte: 404.
    $this->post(route('sign.batch.items.open', ['item' => $foreignItem->ulid]))->assertNotFound();
    $this->get(route('sign.batch.document', ['item' => $foreignItem->ulid]))->assertNotFound();
    $this->post(route('sign.batch.items.authorize', ['item' => $foreignItem->ulid]), [
        'authorization' => str_repeat('x', 43),
        'consent' => true,
    ])->assertNotFound();

    expect(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(0);
});

it('itens expirados, recusados ou cancelados aparecem e não podem ser autorizados', function () {
    $s = batchScenario();
    batchIssue($this, $s['organization'], $s['owner'], $s['one']['envelope'], $s['maria_one']);
    batchAsParticipant($this);
    $props = batchAuthenticate($this, $this->batchLinks[0]);
    [$first, $second, $third] = collect($props['items'])->pluck('id')->all();

    // Abertos antes do encerramento: a sessão existe, mas o item deixa de ser autorizável.
    $currentFirst = batchOpen($this, $first);

    $s['one']['envelope']->forceFill(['status' => EnvelopeStatus::Canceled, 'canceled_at' => now()])->save();
    $s['two']['envelope']->forceFill(['expires_at' => now()->subMinute()])->save();
    $s['maria_three']->forceFill(['status' => 'refused', 'refused_at' => now(), 'refusal_reason' => 'Não concordo com o valor'])->save();

    $list = $this->get(route('sign.batch.show'))->viewData('page')['props']['items'];

    expect(collect($list)->pluck('state')->all())->toBe(['canceled', 'expired', 'refused'])
        ->and(collect($list)->pluck('authorizable')->unique()->all())->toBe([false]);

    batchAuthorize($this, $first, $currentFirst)->assertSessionHasErrors('item');
    $this->post(route('sign.batch.items.open', ['item' => $second]))->assertSessionHasErrors('item');
    $this->post(route('sign.batch.items.open', ['item' => $third]))->assertSessionHasErrors('item');

    expect(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(0)
        ->and(BatchSigningItem::withoutOrganizationScope()->where('ulid', $first)->value('last_error_code'))->toBe('item_not_authorizable');
});

it('duas autorizações simultâneas do mesmo item gravam um único aceite', function () {
    $s = batchScenario();
    batchIssue($this, $s['organization'], $s['owner'], $s['one']['envelope'], $s['maria_one']);
    batchAsParticipant($this);
    $props = batchAuthenticate($this, $this->batchLinks[0]);
    $first = $props['items'][0]['id'];

    // Navegador 1: o lote, com o item aberto e pronto para autorizar.
    $current = batchOpen($this, $first);
    $browserOne = session()->all();

    // Navegador 2: o link individual do MESMO documento, também pronto para aceitar.
    $this->flushSession();
    $individual = authenticateSigner($this, $s['one']['tokens']['maria@exemplo.test']);
    $browserTwo = session()->all();

    // Os dois passaram por todas as checagens da tela; agora os dois clicam.
    $this->flushSession();
    $this->withSession($browserOne);
    batchAuthorize($this, $first, $current)->assertSessionHasNoErrors();

    $this->flushSession();
    $this->withSession($browserTwo);
    $this->post(route('sign.complete', ['token' => $s['one']['tokens']['maria@exemplo.test']]), [
        'authorization' => $individual['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertSessionHasErrors('signature');

    // E o mesmo item autorizado de novo pelo lote.
    $this->flushSession();
    $this->withSession($browserOne);
    batchAuthorize($this, $first, $current)->assertSessionHasErrors();

    expect(SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $s['maria_one']->id)->count())->toBe(1);
});

it('itens com SMS, WhatsApp ou PIN pedem o link individual e não são autorizados no lote', function () {
    $s = batchScenario();
    $s['maria_three']->forceFill(['auth_method' => AuthMethod::SmsOtp, 'phone' => '+5511912345678'])->save();

    batchIssue($this, $s['organization'], $s['owner'], $s['one']['envelope'], $s['maria_one']);
    batchAsParticipant($this);
    $props = batchAuthenticate($this, $this->batchLinks[0]);

    $third = collect($props['items'])->firstWhere('title', 'Termo de entrega de chaves');

    expect($third['state'])->toBe('individual')
        ->and($third['authorizable'])->toBeFalse()
        ->and($third['reason'])->toContain('link individual');

    $this->post(route('sign.batch.items.open', ['item' => $third['id']]))->assertSessionHasErrors('item');

    expect(BatchSigningItem::withoutOrganizationScope()->where('ulid', $third['id'])->value('signing_session_id'))->toBeNull();
});

it('um só documento pendente não gera lote; um novo link revoga o anterior', function () {
    $s = batchScenario();

    // Carla só tem um documento pendente nesta conta.
    $single = signerEnvelope([['name' => 'Carla Dias', 'email' => 'carla@exemplo.test']], envelopeAttributes: ['title' => 'Único'], organization: $s['organization'], owner: $s['owner']);
    batchIssue($this, $s['organization'], $s['owner'], $single['envelope'], $single['recipients']['carla@exemplo.test'])
        ->assertSessionHas('error');

    expect(BatchSigningSession::withoutOrganizationScope()->count())->toBe(0);

    batchIssue($this, $s['organization'], $s['owner'], $s['one']['envelope'], $s['maria_one'])->assertSessionHas('success');
    batchIssue($this, $s['organization'], $s['owner'], $s['two']['envelope'], $s['maria_two'])->assertSessionHas('success');

    expect(BatchSigningSession::withoutOrganizationScope()->whereNotNull('revoked_at')->count())->toBe(1);

    batchAsParticipant($this);

    // O primeiro link não abre mais (404 genérico); o segundo abre.
    $this->get($this->batchLinks[0])->assertNotFound();
    $this->get($this->batchLinks[1])->assertRedirect(route('sign.batch.show'));
});

it('o remetente de outra organização não emite link para participante alheio', function () {
    $s = batchScenario();
    presenceEnable($s['other']);

    batchIssue($this, $s['other'], $s['other_owner'], $s['one']['envelope'], $s['maria_one'])->assertNotFound();

    expect(BatchSigningSession::withoutOrganizationScope()->count())->toBe(0);
});
