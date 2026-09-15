<?php

use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\BulkGeneration;
use App\Models\Delegation;
use App\Services\BulkGeneration\BulkGenerationLimits;
use App\Services\BulkGeneration\Spreadsheet\SpreadsheetReader;
use App\Services\Envelopes\Delegation\DelegationPolicy;
use App\Services\Envelopes\Delegation\DelegationVoider;
use App\Services\Envelopes\Sending\CancelEnvelope;
use App\Services\Envelopes\Steps\StepProgression;
use App\Services\Signing\Channels\ChannelMessages;
use App\Services\Signing\RecordAcceptance;
use App\Support\Locale\SignerLocale;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Domain/Support/DomainHelpers.php';
require_once __DIR__.'/../../Phase3/Flow/Support/FlowHelpers.php';
require_once __DIR__.'/../../Phase3/Bulk/Support/BulkHelpers.php';
require_once __DIR__.'/../../Phase2/Identity/Support/IdentityHelpers.php';
require_once __DIR__.'/../../Phase3/Video/Support/VideoHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda F — testes de regressão das correções
|--------------------------------------------------------------------------
| Cobre o que os testes dos achados não cobrem: o outro lado de cada regra (a recusa em suspenso
| que NÃO encerra; a planilha comum com poucas células à direita), os achados sem teste próprio
| (pedido de delegação sem efeito, limite de tentativas, vídeo sem duração declarada, retenção do
| lote, documento sem assinatura) e o PUT de etapas com a flag desligada.
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

it('recusa em suspenso não encerra quando uma etapa posterior lida pela recusa se aplica', function () {
    $ctx = flowDraft([
        ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Sofia Locadora', 'email' => 'sofia@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ['name' => 'Bruno Jurídico', 'email' => 'bruno@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ], SigningOrder::Parallel);

    $r = $ctx['recipients'];
    $paula = $r['paula@exemplo.test']->ulid;

    flowSaveSteps($this, $ctx, [
        ['name' => 'Aprovação e locadora', 'recipients' => [$paula, $r['sofia@exemplo.test']->ulid]],
        ['name' => 'Jurídico', 'recipients' => [$r['bruno@exemplo.test']->ulid], 'condition' => flowDecisionRule($paula, 'refused')],
    ])->assertOk();

    flowSend($this, $ctx)->assertSessionHasNoErrors();

    flowRefuse($this, $this->invites['paula@exemplo.test'])->assertSessionHasNoErrors();

    expect($ctx['envelope']->fresh()->setting(StepProgression::SETTING_DEFERRED_REFUSAL))->toBe($paula);

    flowSign($this, $this->invites['sofia@exemplo.test'])->assertSessionHasNoErrors();

    $envelope = $ctx['envelope']->fresh();

    expect($envelope->status)->toBe(EnvelopeStatus::InProgress)
        ->and($envelope->setting(StepProgression::SETTING_DEFERRED_REFUSAL))->toBeNull()
        ->and(array_keys($this->invites->getArrayCopy()))->toContain('bruno@exemplo.test');

    flowSign($this, $this->invites['bruno@exemplo.test'])->assertSessionHasNoErrors();

    expect($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::Finalizing);
});

it('com etapas, um documento em que nenhuma etapa com signatário se aplicou é encerrado sem assinatura, nunca concluído', function () {
    $ctx = flowDraft([
        ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Ana Compradora', 'email' => 'ana@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ]);

    $r = $ctx['recipients'];
    $paula = $r['paula@exemplo.test']->ulid;

    flowSaveSteps($this, $ctx, [
        ['name' => 'Aprovação', 'recipients' => [$paula]],
        ['name' => 'Só se recusar', 'recipients' => [$r['ana@exemplo.test']->ulid], 'condition' => flowDecisionRule($paula, 'refused')],
    ])->assertOk();

    flowSend($this, $ctx)->assertSessionHasNoErrors();
    flowSign($this, $this->invites['paula@exemplo.test'], [], withSignature: false)->assertSessionHasNoErrors();

    $envelope = $ctx['envelope']->fresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Canceled)
        ->and($envelope->setting('cancel_reason'))->toBe(RecordAcceptance::NO_SIGNATURE_REASON)
        ->and($r['ana@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Canceled);

    Event::assertNotDispatched(EnvelopeReadyForFinalization::class);
});

it('com a flag desligada, o PUT só deixa DESFAZER etapas gravadas antes', function () {
    $ctx = flowApprovalDraft($this);

    config()->set('assinavelox.features.conditional_steps', false);

    $url = route('envelopes.steps.update', ['envelope' => $ctx['envelope']->ulid]);

    $this->putJson($url, ['enabled' => true, 'steps' => []])->assertNotFound();
    $this->putJson($url, ['enabled' => false])->assertOk()->assertJsonPath('steps.enabled', false);

    expect($ctx['envelope']->fresh()->usesSigningSteps())->toBeFalse();

    // Sem etapas gravadas, nem desfazer passa: a rota volta a ser 404 como antes.
    $this->putJson($url, ['enabled' => false])->assertNotFound();
});

it('pedido de delegação pendente fica sem efeito quando quem pediu assina e quando a coleta é cancelada', function () {
    $ctx = flowDelegationEnvelope($this, [], SigningOrder::Parallel);
    $maria = $this->invites['maria@exemplo.test'];
    $joao = $this->invites['joao@exemplo.test'];

    domainAuthenticate($this, $maria);
    flowDelegate($this, $maria)->assertCreated();

    flowSign($this, $maria)->assertSessionHasNoErrors();

    $first = Delegation::query()->sole();

    expect($first->status)->toBe(Delegation::STATUS_VOID)
        ->and($first->decision_note)->toBe(DelegationVoider::NOTE_ACTED)
        ->and(AuditEvent::query()->where('event_type', 'delegation.voided')->count())->toBe(1);

    domainAuthenticate($this, $joao);
    flowDelegate($this, $joao, ['email' => 'daniel@exemplo.test', 'name' => 'Daniel Rocha'])->assertCreated();

    app(CancelEnvelope::class)->handle($ctx['envelope']->fresh(), 'Documento substituído.');

    $second = Delegation::query()->where('to_email', 'daniel@exemplo.test')->sole();

    expect($second->status)->toBe(Delegation::STATUS_VOID)
        ->and($second->decision_note)->toBe(DelegationVoider::NOTE_CLOSED);
});

it('delegar para um +alias de quem já participa é recusado, e tentativas recusadas têm limite próprio', function () {
    config()->set('assinavelox.delegation.max_refused_attempts_per_recipient', 2);

    $ctx = flowDelegationEnvelope($this);
    $token = $this->invites['maria@exemplo.test'];

    domainAuthenticate($this, $token);

    flowDelegate($this, $token, ['email' => 'joao+outro@exemplo.test'])->assertStatus(422)->assertJsonPath('code', 'participant');
    flowDelegate($this, $token, ['email' => 'joao@exemplo.test'])->assertStatus(422)->assertJsonPath('code', 'participant');

    // Limite de tentativas recusadas esgotado: nem um e-mail livre passa, e nada revela quem participa.
    flowDelegate($this, $token, ['email' => 'carla@exemplo.test'])->assertStatus(429)->assertJsonPath('code', 'recipient_limit');

    expect(Delegation::query()->count())->toBe(0)
        ->and(DelegationPolicy::mailboxKey('Ana.Lima+x@GoogleMail.com'))->toBe('analima@gmail.com')
        ->and(DelegationPolicy::mailboxKey('ana.lima+x@exemplo.test'))->toBe('ana.lima@exemplo.test');
});

it('SMS de convite e lembrete saem no idioma do participante; em PT-BR, o texto de sempre', function () {
    $reference = ChannelMessages::invitationText('Horizonte', 'Contrato', 'https://x.test/a', false);
    $english = ChannelMessages::invitationText('Horizonte', 'Contrato :code', 'https://x.test/a', true, SignerLocale::En);

    expect($reference)->toBe(ChannelMessages::invitationText('Horizonte', 'Contrato', 'https://x.test/a', false, SignerLocale::PtBr))
        ->and($reference)->toContain('enviou o documento "Contrato"')
        ->and($english)->toStartWith('Reminder: Horizonte')
        ->and($english)->toContain('"Contrato :code"');
});

it('vídeo WebM sem Duration e sem duração informada: a duração estimada pelos blocos impõe o limite', function () {
    $ctx = videoEnvelope();
    authenticateSigner($this, $ctx['token']);

    $block = videoEbmlElement(0xA3, "\x81\x00\x00\x80".str_repeat("\x00", 16));
    $cluster = fn (int $ms): string => "\x1F\x43\xB6\x75\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".videoEbmlElement(0xE7, pack('N', $ms)).$block;

    $long = videoWebm(null, clusterBytes: 0).videoEbmlElement(0xE7, pack('N', 0)).$block.$cluster(60_000);
    $short = videoWebm(null, clusterBytes: 0).videoEbmlElement(0xE7, pack('N', 0)).$block.$cluster(4_000);

    videoPost($this, $ctx['token'], $long)->assertStatus(422)->assertJsonPath('code', 'video_too_long');
    videoPost($this, $ctx['token'], $short)->assertCreated();
});

it('planilha comum com poucas células muito à direita continua sendo lida', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'review-fixes-'.bin2hex(random_bytes(4));
    mkdir($dir);

    try {
        $rows = [['Nome', 'E-mail']];

        for ($i = 1; $i <= 5; $i++) {
            $row = ["Pessoa {$i}", "p{$i}@example.com"];

            if ($i <= 3) {
                $row[16383] = 'nota perdida';
            }

            $rows[] = $row;
        }

        $sheet = (new SpreadsheetReader)->read(
            bulkXlsx($dir.'/comum.xlsx', [['rows' => $rows]]),
            'xlsx',
            new BulkGenerationLimits(1000, 5 * 1024 * 1024, 2, 3, 100, 5000, 50 * 1024 * 1024),
        );

        expect($sheet->rowCount())->toBe(5)
            ->and($sheet->headers)->toBe(['Nome', 'E-mail']);
    } finally {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }
});

it('lote nunca confirmado é descartado depois do prazo de retenção; o recente fica', function () {
    templatesRequirePdftool();
    $work = templatesWorkspace();

    try {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
        bulkEnable($organization);
        actingAsMember($owner, $organization);
        $template = bulkPdfTemplate($organization, $owner, $work);

        $old = bulkUpload($template, bulkFile(bulkCsv($work.'/antigo.csv', bulkPdfRows(1))));
        $file = (string) $old->source_file;

        $this->travel(8)->days();

        $recent = bulkUpload($template, bulkFile(bulkCsv($work.'/recente.csv', bulkPdfRows(1))));

        $this->artisan('bulk-generations:prune-unconfirmed')->assertSuccessful();

        expect(BulkGeneration::withoutOrganizationScope()->whereKey($old->getKey())->exists())->toBeFalse()
            ->and(Storage::disk('documents')->exists($file))->toBeFalse()
            ->and(BulkGeneration::withoutOrganizationScope()->whereKey($recent->getKey())->exists())->toBeTrue();
    } finally {
        PdfFixtures::cleanup($work);
    }
});

if (! function_exists('flowApprovalDraft')) {
    /**
     * Rascunho com etapas gravadas (flag ligada), ainda NÃO enviado.
     *
     * @return array<string, mixed>
     */
    function flowApprovalDraft(object $test): array
    {
        $ctx = flowDraft([
            ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
            ['name' => 'Ana Compradora', 'email' => 'ana@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ]);

        $paula = $ctx['recipients']['paula@exemplo.test']->ulid;

        flowSaveSteps($test, $ctx, [
            ['name' => 'Aprovação', 'recipients' => [$paula]],
            ['name' => 'Compradora', 'recipients' => [$ctx['recipients']['ana@exemplo.test']->ulid], 'condition' => flowDecisionRule($paula, 'approved')],
        ])->assertOk();

        return $ctx;
    }
}
