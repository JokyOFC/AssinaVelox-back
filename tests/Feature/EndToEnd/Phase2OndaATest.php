<?php

use App\Enums\AcceptanceAction;
use App\Enums\DeliveryPurpose;
use App\Enums\DocumentProcessingStatus;
use App\Enums\EnvelopeStatus;
use App\Enums\FolderAccessLevel;
use App\Enums\Permission;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Models\AcceptanceDocument;
use App\Models\DeliveryAttempt;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\Tag;
use App\Models\VerificationRecord;
use App\Models\VerificationRecordDocument;
use App\Notifications\Envelopes\EnvelopeCompletedNotification;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Notifications\Envelopes\RecipientReminderNotification;
use App\Notifications\Signing\SignerOtpNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Documents/Support/helpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../Phase2/Domain/Support/DomainHelpers.php';
require_once __DIR__.'/../Phase2/Templates/Support/TemplateHelpers.php';
require_once __DIR__.'/../Phase2/Reminders/Support/ReminderHelpers.php';
require_once __DIR__.'/../Phase2/Permissions/Support/PermissionHelpers.php';
require_once __DIR__.'/../Phase2/Org/Support/OrgHelpers.php';

/*
|--------------------------------------------------------------------------
| Ponta a ponta da Fase 2, onda A (integração I-2A)
|--------------------------------------------------------------------------
| Com TODAS as flags da onda A ligadas para a organização de teste (interruptor global E
| plano), o caminho do usuário atravessa as cinco áreas, pelas rotas HTTP reais:
|
|  modelo com variáveis e papéis → envelope gerado pelo modelo (documento 1, DOMPDF +
|  pipeline) + um PDF anexado (documento 2) → participantes signer, witness, approver e
|  viewer → campos por arquivo → lembretes e envio agendado → disparo agendado → lembrete
|  automático → aprovador aprova, signatário e testemunha assinam → finalização dos dois
|  documentos → visualizador recebe a cópia final → verificação pública lista os dois
|  arquivos com hashes → função personalizada com acesso por pasta vê o envelope e outra
|  sem acesso não vê → relatório agrega → etiqueta aplicada filtra a lista.
|
| O que é montado direto no banco é só o que o usuário não faz por aqui (plano com as
| flags, pasta do envelope). A finalização roda de verdade (fila `sync` + pdftool).
*/

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->withoutVite();
    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('pdftool.company_certificate.enabled', false);
    config()->set('pdftool.trust_roots', []);
    fakeDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    // Segunda-feira, 09:00 em São Paulo (12:00 UTC): dentro da janela de lembretes.
    Carbon::setTestNow(Carbon::parse('2026-09-14 12:00:00', 'UTC'));

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Onda A']);
    $this->organization = $organization;
    $this->owner = $owner;
    setPlanQuota($organization, 10);

    // Todas as flags da onda A, pelos helpers de cada área (global E plano).
    templatesEnable($organization, participantRoles: true);
    domainEnableFlags($organization);
    remEnableFeature($organization);
    enableCustomRoles();
    orgEnableTools($organization);

    $this->invites = new ArrayObject;
    $this->codes = new ArrayObject;
    $this->finalCopies = new ArrayObject;

    Event::listen(NotificationSending::class, function (NotificationSending $event): void {
        $notification = $event->notification;

        if ($notification instanceof RecipientInvitationNotification || $notification instanceof RecipientReminderNotification) {
            // O lembrete emite link novo e revoga o anterior: o último vale.
            $this->invites[$notification->recipient->email] = $notification->signingUrl;
        }

        if ($notification instanceof SignerOtpNotification) {
            $this->codes[] = $notification->code;
        }

        if ($notification instanceof EnvelopeCompletedNotification && $event->notifiable instanceof AnonymousNotifiable) {
            $this->finalCopies[] = (string) ($event->notifiable->routes['mail'] ?? '');
        }
    });

    actingAsMember($owner, $organization);
});

afterEach(function () {
    Carbon::setTestNow();
    cleanupDocumentsWorkspace($this->work ?? null);
});

function ondaAToken(object $test, string $email): string
{
    expect(isset($test->invites[$email]))->toBeTrue("Nenhum link foi enviado para {$email}.");

    $segments = array_values(array_filter(explode('/', (string) parse_url((string) $test->invites[$email], PHP_URL_PATH))));

    return (string) end($segments);
}

it('atravessa modelo, vários documentos, papéis, lembretes, agendamento, finalização, verificação, pastas, relatório e etiquetas', function () {
    // -- 1. Modelo HTML com variáveis tipadas e quatro papéis --------------------------
    $template = templateHtml($this->organization, $this->owner,
        '<h1>Contrato de locação</h1><p>Locatário: {{locatario}}. Aluguel mensal de {{valor}}.</p>',
        [
            ['key' => 'locatario', 'label' => 'Locatário', 'type' => 'text', 'required' => true],
            ['key' => 'valor', 'label' => 'Valor do aluguel', 'type' => 'currency', 'required' => true],
        ],
        [
            ['ref' => 'aprovador', 'name' => 'Aprovação jurídica', 'participant_role' => 'approver'],
            ['ref' => 'locatario', 'name' => 'Locatário', 'participant_role' => 'signer'],
            ['ref' => 'testemunha', 'name' => 'Testemunha', 'participant_role' => 'witness'],
            ['ref' => 'corretor', 'name' => 'Corretor', 'participant_role' => 'viewer'],
        ],
        name: 'Locação residencial',
    );

    $roleIds = templateRoleIds($template);

    // -- 2. Envelope gerado pelo modelo -----------------------------------------------
    $this->post(route('templates.use', $template), [
        'title' => 'Locação — Rua das Acácias, 120',
        'values' => ['locatario' => 'Ana Beatriz Rocha', 'valor' => '2.450,00'],
        'participants' => [
            $roleIds['Aprovação jurídica'] => ['name' => 'Paulo Aprovador', 'email' => 'paulo@exemplo.test'],
            $roleIds['Locatário'] => ['name' => 'Ana Beatriz Rocha', 'email' => 'ana@exemplo.test'],
            $roleIds['Testemunha'] => ['name' => 'Tereza Testemunha', 'email' => 'tereza@exemplo.test'],
            $roleIds['Corretor'] => ['name' => 'Carlos Corretor', 'email' => 'carlos@exemplo.test'],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect();

    /** @var Envelope $envelope */
    $envelope = Envelope::query()->latest('id')->firstOrFail();
    $recipients = $envelope->recipients()->orderBy('id')->get()->keyBy('email');

    expect($envelope->title)->toBe('Locação — Rua das Acácias, 120')
        ->and($recipients)->toHaveCount(4)
        ->and($recipients['paulo@exemplo.test']->role)->toBe(RecipientRole::Approver)
        ->and($recipients['ana@exemplo.test']->role)->toBe(RecipientRole::Signer)
        ->and($recipients['tereza@exemplo.test']->role)->toBe(RecipientRole::Witness)
        ->and($recipients['carlos@exemplo.test']->role)->toBe(RecipientRole::Viewer);

    // -- 3. Segundo documento: PDF anexado (flag multi_document) ----------------------
    uploadDocument($envelope->fresh(), PdfFixtures::onePagePdf($this->work.DIRECTORY_SEPARATOR.'anexo.pdf', 'Anexo I'), 'anexo-vistoria.pdf')
        ->assertSessionHasNoErrors();

    $documents = Document::query()->where('envelope_id', $envelope->id)->orderBy('position')->get();
    expect($documents)->toHaveCount(2)
        ->and($documents->pluck('position')->all())->toBe([1, 2])
        ->and($documents->every(fn (Document $d) => $d->processing_status === DocumentProcessingStatus::Ready))->toBeTrue();

    [$contrato, $anexo] = [$documents[0], $documents[1]];

    // -- 4. Campos por arquivo: signatário nos dois, testemunha no anexo, aprovador sem
    //       campo de assinatura, visualizador sem campo nenhum ---------------------------
    $this->put(route('envelopes.fields.sync', ['envelope' => $envelope->ulid]), [
        'initials_on_all_pages' => false,
        'fields' => [
            ['client_id' => 'f1', 'recipient_id' => $recipients['ana@exemplo.test']->ulid, 'document_id' => $contrato->ulid, 'type' => 'signature', 'page' => 1, 'x' => 0.10, 'y' => 0.60, 'w' => 0.34, 'h' => 0.08, 'required' => true],
            ['client_id' => 'f2', 'recipient_id' => $recipients['ana@exemplo.test']->ulid, 'document_id' => $anexo->ulid, 'type' => 'text', 'page' => 1, 'x' => 0.10, 'y' => 0.40, 'w' => 0.36, 'h' => 0.05, 'required' => true, 'label' => 'Matrícula'],
            ['client_id' => 'f3', 'recipient_id' => $recipients['tereza@exemplo.test']->ulid, 'document_id' => $anexo->ulid, 'type' => 'signature', 'page' => 1, 'x' => 0.10, 'y' => 0.70, 'w' => 0.34, 'h' => 0.08, 'required' => true],
        ],
    ])->assertSessionHasNoErrors();

    $envelope->refresh();
    expect($envelope->status)->toBe(EnvelopeStatus::Ready);

    // -- 5. Lembretes (a cada 2 dias, até 3) e envio agendado para as 10:00 locais --------
    $this->put(route('envelopes.reminders.update', ['envelope' => $envelope->ulid]), [
        'enabled' => true,
        'first_after_days' => 2,
        'interval_days' => 2,
        'max_count' => 3,
    ])->assertSessionHasNoErrors();

    $this->post(route('envelopes.schedule', ['envelope' => $envelope->ulid]), [
        'scheduled_for' => '2026-09-14T10:00',
    ])->assertSessionHasNoErrors();

    $envelope->refresh();
    expect($envelope->status)->toBe(EnvelopeStatus::Ready)
        ->and($envelope->scheduled_send_at?->utc()->format('Y-m-d H:i'))->toBe('2026-09-14 13:00')
        ->and($this->invites)->toHaveCount(0);

    // A tela de detalhe expõe o agendamento (contrato B-REM, prop `reminders`).
    $this->get(route('envelopes.edit', ['envelope' => $envelope->ulid, 'step' => 4]))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('reminders.available', true)
            ->where('reminders.scheduled_send.input_value', '2026-09-14T10:00')
            ->etc());

    // -- 6. Disparo agendado: antes da hora nada sai; na hora, o envio acontece -------------
    remRunScheduled();
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);

    Carbon::setTestNow(Carbon::parse('2026-09-14 13:01:00', 'UTC'));
    remRunScheduled();

    $envelope->refresh();
    expect($envelope->status)->toBe(EnvelopeStatus::InProgress)
        ->and($envelope->scheduled_send_at)->toBeNull()
        ->and($envelope->verification_code)->not->toBeNull();

    // Versões congeladas POR arquivo no envio.
    $documents = Document::query()->where('envelope_id', $envelope->id)->orderBy('position')->get();
    expect($documents->every(fn (Document $d) => $d->sent_version_id !== null))->toBeTrue();

    // Sequencial: o aprovador (1ª etapa) e o visualizador (independe da vez) são avisados.
    expect(isset($this->invites['paulo@exemplo.test']))->toBeTrue()
        ->and(isset($this->invites['carlos@exemplo.test']))->toBeTrue()
        ->and(isset($this->invites['ana@exemplo.test']))->toBeFalse()
        ->and(isset($this->invites['tereza@exemplo.test']))->toBeFalse();

    // -- 7. Lembrete automático dois dias depois, só para quem está na vez ---------------
    $firstApproverLink = $this->invites['paulo@exemplo.test'];

    Carbon::setTestNow(Carbon::parse('2026-09-16 13:30:00', 'UTC'));
    remRunReminders();

    $reminders = DeliveryAttempt::withoutOrganizationScope()->where('purpose', DeliveryPurpose::Reminder->value)->get();
    expect($reminders)->toHaveCount(1)
        ->and($reminders->first()->recipient_id)->toBe($recipients['paulo@exemplo.test']->id)
        ->and($this->invites['paulo@exemplo.test'])->not->toBe($firstApproverLink);

    // -- 8. Aprovador aprova (sem imagem de assinatura) -----------------------------------
    $approverToken = ondaAToken($this, 'paulo@exemplo.test');
    $props = domainAuthenticate($this, $approverToken);
    expect($props['screen'])->toBe('sign')
        ->and($props['documents'])->toHaveCount(2);
    domainPresent($this, $props);
    domainAccept($this, $approverToken, $props, withSignature: false)->assertSessionHasNoErrors();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::InProgress)
        ->and(SignatureAcceptance::query()->where('recipient_id', $recipients['paulo@exemplo.test']->id)->firstOrFail()->action)->toBe(AcceptanceAction::Approve);

    // -- 9. Signatário assina (campo de texto no anexo) ------------------------------------
    $signerToken = ondaAToken($this, 'ana@exemplo.test');
    $props = domainAuthenticate($this, $signerToken);
    domainPresent($this, $props);
    $textFieldId = collect($props['my_fields'])->firstWhere('type', 'text')['id'];
    domainAccept($this, $signerToken, $props, [$textFieldId => 'M-2291'])->assertSessionHasNoErrors();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::InProgress);

    // -- 10. Testemunha assina: o último aceite entrega o envelope à finalização ----------
    $witnessToken = ondaAToken($this, 'tereza@exemplo.test');
    $props = domainAuthenticate($this, $witnessToken);
    domainPresent($this, $props);
    domainAccept($this, $witnessToken, $props)->assertSessionHasNoErrors();

    $envelope->refresh();
    expect($envelope->status)->toBe(EnvelopeStatus::Completed);

    $acceptances = SignatureAcceptance::query()->where('envelope_id', $envelope->id)->get()->keyBy('recipient_id');
    expect($acceptances)->toHaveCount(3)
        ->and($acceptances[$recipients['ana@exemplo.test']->id]->action)->toBe(AcceptanceAction::Sign)
        ->and($acceptances[$recipients['tereza@exemplo.test']->id]->action)->toBe(AcceptanceAction::Witness)
        // Cada aceite cobre os dois arquivos.
        ->and(AcceptanceDocument::query()->whereIn('signature_acceptance_id', $acceptances->pluck('id'))->count())->toBe(6);

    // O visualizador não aceitou nada e não travou a conclusão.
    expect($recipients['carlos@exemplo.test']->fresh()->status)->not->toBe(RecipientStatus::Signed)
        ->and(SignatureAcceptance::query()->where('recipient_id', $recipients['carlos@exemplo.test']->id)->exists())->toBeFalse();

    // -- 11. Finalização dos dois arquivos + cópia final para o visualizador -------------
    $documents = Document::query()->where('envelope_id', $envelope->id)->orderBy('position')->get();
    expect($documents->every(fn (Document $d) => $d->final_version_id !== null))->toBeTrue();

    /** @var VerificationRecord $record */
    $record = VerificationRecord::query()->where('envelope_id', $envelope->id)->firstOrFail();
    $perFile = VerificationRecordDocument::query()->where('verification_record_id', $record->id)->orderBy('position')->get();
    expect($perFile)->toHaveCount(2)
        ->and($perFile->pluck('final_sha256')->filter()->unique())->toHaveCount(2);

    expect(iterator_to_array($this->finalCopies))->toContain('carlos@exemplo.test');

    // -- 12. Verificação pública lista os dois arquivos com os hashes --------------------
    $code = (string) $envelope->verification_code;
    $this->post(route('logout'));

    $public = $this->get(route('verify.show', ['code' => $code]))->assertOk();
    $publicJson = json_encode($public->viewData('page')['props']);

    foreach ($perFile as $row) {
        expect($publicJson)->toContain($row->final_sha256);
    }

    expect($public->viewData('page')['props']['result']['documents_count'] ?? null)->toBe(2);

    // -- 13. Função personalizada com acesso por pasta vê; outra sem acesso não vê --------
    $folder = folderIn($this->organization, 'Locações');
    $envelope->forceFill(['folder_id' => $folder->id])->save();

    $withAccess = createCustomRole($this->organization, 'Revisor de locações', [Permission::CreateEnvelopes, Permission::ViewReports]);
    $withoutAccess = createCustomRole($this->organization, 'Revisor de vendas', [Permission::CreateEnvelopes, Permission::ViewReports]);
    grantFolder($folder, 'role', $withAccess->id, FolderAccessLevel::View);

    $reviewer = attachWithCustomRole($this->organization, $withAccess);
    $outsider = attachWithCustomRole($this->organization, $withoutAccess);

    actingAsMember($reviewer, $this->organization);
    $this->get(route('envelopes.show', ['envelope' => $envelope->ulid]))->assertOk();
    $listed = $this->get(route('envelopes.index'))->viewData('page')['props']['envelopes']['data'];
    expect(collect($listed)->pluck('id')->all())->toContain($envelope->ulid);

    actingAsMember($outsider, $this->organization);
    expect($this->get(route('envelopes.show', ['envelope' => $envelope->ulid]))->getStatusCode())->toBeIn([403, 404]);
    $listed = $this->get(route('envelopes.index'))->viewData('page')['props']['envelopes']['data'];
    expect(collect($listed)->pluck('id')->all())->not->toContain($envelope->ulid);

    // -- 14. Relatório agrega o envelope (owner vê tudo; quem não vê o envelope, não conta) --
    actingAsMember($this->owner, $this->organization);
    $report = $this->get(route('reports.index'))->assertOk()->viewData('page')['props']['report'];
    expect($report['totals']['sent'])->toBe(1)
        ->and($report['totals']['completed'])->toBe(1);

    actingAsMember($outsider, $this->organization);
    $report = $this->get(route('reports.index'))->assertOk()->viewData('page')['props']['report'];
    expect($report['totals']['sent'])->toBe(0);

    // -- 15. Etiqueta aplicada filtra a lista ---------------------------------------------
    actingAsMember($this->owner, $this->organization);
    $other = Envelope::factory()->forOrganization($this->organization, $this->owner)->draft()->create(['title' => 'Outro rascunho']);

    $this->post(route('tags.store'), ['name' => 'Locação residencial', 'color' => 'blue'])->assertRedirect();
    $tag = Tag::query()->where('organization_id', $this->organization->id)->firstOrFail();
    $this->post(route('envelopes.tags.apply'), ['tag' => $tag->ulid, 'ids' => [$envelope->ulid]])->assertSessionHasNoErrors();

    $all = collect($this->get(route('envelopes.index'))->viewData('page')['props']['envelopes']['data'])->pluck('id');
    $tagged = $this->get(route('envelopes.index', ['tag' => $tag->ulid]))->viewData('page')['props'];

    expect($all->all())->toContain($envelope->ulid, $other->ulid)
        ->and(collect($tagged['envelopes']['data'])->pluck('id')->all())->toBe([$envelope->ulid])
        ->and($tagged['filters']['tag'])->toBe($tag->ulid)
        ->and($tagged['tagging']['enabled'])->toBeTrue();

    // As props compartilhadas refletem as flags ligadas para esta organização.
    $features = $this->get(route('dashboard'))->viewData('page')['props']['features'];
    expect($features)->toMatchArray([
        'templates' => true,
        'multi_document' => true,
        'participant_roles' => true,
        'reminders' => true,
        'custom_roles' => true,
        'tags' => true,
        'reports' => true,
        'audit_log' => true,
    ]);

    expect(Recipient::query()->where('envelope_id', $envelope->id)->where('role', RecipientRole::Viewer->value)->count())->toBe(1);
});
