<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Notifications\Envelopes\EnvelopeCompletedNotification;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Services\Documents\EnvelopeReadiness as DocumentReadiness;
use App\Services\Envelopes\EnvelopeReadiness;
use App\Services\Envelopes\Sending\CompletionNotifier;
use App\Services\Signing\ConsentText;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/Support/DomainHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.4 — papéis: testemunha, aprovador, visualizador
|--------------------------------------------------------------------------
| Testemunha assina com declaração própria e conta para a conclusão; aprovador aprova sem
| representação visual; visualizador só vê (depois do código) e recebe cópia, nunca
| bloqueia a conclusão. Ordem sequencial com papéis mistos; prontidão por papel.
*/

beforeEach(function () {
    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();

    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('aprovador aprova sem campo e sem representação visual, com declaração própria', function () {
    $ctx = domainEnvelope(['Contrato'], [
        ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ]);

    $token = $ctx['tokens']['paula@exemplo.test'];
    $props = domainAuthenticate($this, $token);

    expect($props['screen'])->toBe('sign')
        ->and($props['action']['type'])->toBe('approve')
        ->and($props['action']['button_label'])->toBe('Aprovar documento')
        ->and($props['action']['requires_signature'])->toBeFalse()
        ->and($props['my_fields'])->toBe([])
        ->and($props['consent']['version'])->toBe(ConsentText::APPROVAL_TERMS_VERSION)
        ->and($props['consent']['statement'])->toContain('na qualidade de aprovador(a)')
        ->and($props['consent']['statement'])->toContain('não contém representação visual de assinatura');

    domainPresent($this, $props);

    domainAccept($this, $token, $props, [], withSignature: false)->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::query()->sole();

    expect($acceptance->action->value)->toBe('approve')
        ->and($acceptance->signature_kind)->toBeNull()
        ->and($acceptance->signature_image_path)->toBeNull()
        ->and($acceptance->terms_version)->toBe(ConsentText::APPROVAL_TERMS_VERSION)
        ->and($ctx['recipients']['paula@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Signed)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ApprovalRecorded->value)->count())->toBe(1)
        ->and($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::InProgress);
});

it('testemunha assina com rótulo e declaração próprios e conta para a conclusão', function () {
    $ctx = domainEnvelope(['Contrato'], [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ['name' => 'Tadeu Testemunha', 'email' => 'tadeu@exemplo.test', 'role' => RecipientRole::Witness, 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ]);

    $maria = $ctx['tokens']['maria@exemplo.test'];
    $props = domainAuthenticate($this, $maria);
    domainPresent($this, $props);
    domainAccept($this, $maria, $props)->assertSessionHasNoErrors();

    // Falta a testemunha: o envelope não finaliza.
    expect($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::InProgress);
    Event::assertNotDispatched(EnvelopeReadyForFinalization::class);

    $tadeu = $ctx['tokens']['tadeu@exemplo.test'];
    $props = domainAuthenticate($this, $tadeu);

    expect($props['action']['button_label'])->toBe('Assinar como testemunha')
        ->and($props['my_fields'][0]['label'])->toBe('Testemunha')
        ->and($props['consent']['version'])->toBe(ConsentText::WITNESS_TERMS_VERSION)
        ->and($props['consent']['statement'])->toContain('na qualidade de testemunha')
        ->and($props['consent']['statement'])->toContain('não me torna parte do documento');

    domainPresent($this, $props);
    domainAccept($this, $tadeu, $props)->assertSessionHasNoErrors();

    $witness = SignatureAcceptance::query()->where('recipient_id', $ctx['recipients']['tadeu@exemplo.test']->id)->sole();

    expect($witness->action->value)->toBe('witness')
        ->and($witness->signature_kind)->not->toBeNull()
        ->and($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::Finalizing);

    Event::assertDispatched(EnvelopeReadyForFinalization::class);
});

it('visualizador vê o documento depois do código, não aceita nem recusa e não bloqueia a conclusão', function () {
    $ctx = domainEnvelope(['Contrato', 'Anexo'], [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ['name' => 'Victor Visualizador', 'email' => 'victor@exemplo.test', 'role' => RecipientRole::Viewer],
    ]);

    $viewer = $ctx['tokens']['victor@exemplo.test'];

    $identify = $this->get(route('sign.show', ['token' => $viewer]))->viewData('page')['props'];

    expect($identify['screen'])->toBe('identify')
        ->and($identify['document'])->toBeNull()
        ->and($identify['action']['type'])->toBe('view');

    $props = domainAuthenticate($this, $viewer);

    expect($props['screen'])->toBe('view')
        ->and($props['authorization'])->toBeNull()
        ->and($props['consent'])->toBeNull()
        ->and($props['my_fields'])->toBe([])
        ->and($props['documents'])->toHaveCount(2)
        ->and($props['copy']['final_available'])->toBeFalse();

    domainPresent($this, $props);

    $this->post(route('sign.complete', ['token' => $viewer]), [
        'authorization' => str_repeat('x', 43),
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertSessionHasErrors('signature');

    $this->post(route('sign.refuse', ['token' => $viewer]), ['reason' => 'Não quero acompanhar este documento.'])
        ->assertSessionHasErrors('reason');

    expect(SignatureAcceptance::query()->count())->toBe(0)
        ->and($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::InProgress);

    // Os demais participantes não veem o visualizador listado.
    $maria = $ctx['tokens']['maria@exemplo.test'];
    $signerProps = domainAuthenticate($this, $maria);

    expect($signerProps['others'])->toBe([]);

    domainPresent($this, $signerProps);
    domainAccept($this, $maria, $signerProps)->assertSessionHasNoErrors();

    // Único participante aceitou: o visualizador pendente não impede a finalização.
    expect($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::Finalizing)
        ->and($ctx['recipients']['victor@exemplo.test']->fresh()->status->isPendingSignature())->toBeTrue();

    Event::assertDispatched(EnvelopeReadyForFinalization::class);
});

it('visualizador recebe a cópia na conclusão', function () {
    Notification::fake();

    $ctx = domainEnvelope(['Contrato'], [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'status' => RecipientStatus::Signed, 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ['name' => 'Victor Visualizador', 'email' => 'victor@exemplo.test', 'role' => RecipientRole::Viewer],
    ]);

    $envelope = $ctx['envelope'];
    $envelope->forceFill(['status' => EnvelopeStatus::Completed, 'completed_at' => now()])->save();

    $result = app(CompletionNotifier::class)->notify($envelope->fresh());

    expect($result['notified'])->toBe(2);

    Notification::assertSentOnDemand(
        EnvelopeCompletedNotification::class,
        fn ($notification, $channels, $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'victor@exemplo.test',
    );
});

it('conduz a ordem sequencial com papéis mistos: aprovador, signatário, testemunha; o visualizador recebe no envio', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    domainEnableFlags($organization);
    setPlanQuota($organization, 5);
    actingAsMember($owner, $organization);

    $invites = new ArrayObject;
    Event::listen(NotificationSending::class, function (NotificationSending $event) use ($invites): void {
        if ($event->notification instanceof RecipientInvitationNotification) {
            $segments = array_values(array_filter(explode('/', (string) parse_url($event->notification->signingUrl, PHP_URL_PATH))));
            $invites[$event->notification->recipient->email] = end($segments);
        }
    });

    $ctx = domainEnvelope(['Contrato'], [], SigningOrder::Sequential, $organization, $owner, sent: false);
    $envelope = $ctx['envelope'];

    $this->put(route('envelopes.recipients.sync', ['envelope' => $envelope->ulid]), [
        'signing_order' => 'sequential',
        'recipients' => [
            ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'participant_role' => 'approver'],
            ['name' => 'Victor Visualizador', 'email' => 'victor@exemplo.test', 'participant_role' => 'viewer'],
            ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'participant_role' => 'signer'],
            ['name' => 'Tadeu Testemunha', 'email' => 'tadeu@exemplo.test', 'participant_role' => 'witness'],
        ],
    ])->assertSessionHasNoErrors();

    $recipients = Recipient::query()->where('envelope_id', $envelope->id)->get()->keyBy('email');

    // Só quem participa tem vez; o visualizador fica em 0.
    $turns = $recipients->map(fn (Recipient $r) => [$r->role->value, $r->order_index]);

    expect($turns['paula@exemplo.test'])->toBe(['approver', 1])
        ->and($turns['victor@exemplo.test'])->toBe(['viewer', 0])
        ->and($turns['maria@exemplo.test'])->toBe(['signer', 2])
        ->and($turns['tadeu@exemplo.test'])->toBe(['witness', 3]);

    $this->put(route('envelopes.fields.sync', ['envelope' => $envelope->ulid]), [
        'fields' => [
            ['recipient_id' => $recipients['maria@exemplo.test']->ulid, 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.08],
            ['recipient_id' => $recipients['tadeu@exemplo.test']->ulid, 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.4, 'w' => 0.3, 'h' => 0.08],
        ],
    ])->assertSessionHasNoErrors();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);

    $this->post(route('envelopes.send', ['envelope' => $envelope->ulid]))->assertSessionHasNoErrors();

    expect(array_keys($invites->getArrayCopy()))->toEqualCanonicalizing(['paula@exemplo.test', 'victor@exemplo.test']);

    // Aprovador → libera o signatário.
    $props = domainAuthenticate($this, $invites['paula@exemplo.test']);
    domainPresent($this, $props);
    domainAccept($this, $invites['paula@exemplo.test'], $props, [], withSignature: false)->assertSessionHasNoErrors();

    expect($envelope->fresh()->current_order)->toBe(2)
        ->and(isset($invites['maria@exemplo.test']))->toBeTrue()
        ->and(isset($invites['tadeu@exemplo.test']))->toBeFalse();

    // Signatário → libera a testemunha.
    $props = domainAuthenticate($this, $invites['maria@exemplo.test']);
    domainPresent($this, $props);
    domainAccept($this, $invites['maria@exemplo.test'], $props)->assertSessionHasNoErrors();

    expect($envelope->fresh()->current_order)->toBe(3)
        ->and(isset($invites['tadeu@exemplo.test']))->toBeTrue();

    // Testemunha → finaliza; o visualizador nunca foi pendência.
    $props = domainAuthenticate($this, $invites['tadeu@exemplo.test']);
    domainPresent($this, $props);
    domainAccept($this, $invites['tadeu@exemplo.test'], $props)->assertSessionHasNoErrors();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Finalizing)
        ->and(SignatureAcceptance::query()->pluck('action')->map->value->sort()->values()->all())->toBe(['approve', 'sign', 'witness']);
});

it('aplica as regras de prontidão por papel', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    domainEnableFlags($organization);
    actingAsMember($owner, $organization);

    // Testemunha sem campo de assinatura: não fica pronto.
    $ctx = domainEnvelope(['Contrato'], [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ['name' => 'Tadeu Testemunha', 'email' => 'tadeu@exemplo.test', 'role' => RecipientRole::Witness],
        ['name' => 'Victor Visualizador', 'email' => 'victor@exemplo.test', 'role' => RecipientRole::Viewer],
    ], organization: $organization, owner: $owner, sent: false);

    $envelope = $ctx['envelope'];

    expect(app(DocumentReadiness::class)->recompute($envelope))->toBe(EnvelopeStatus::Draft)
        ->and(EnvelopeReadiness::issues($envelope->fresh()))->toBe(['Sem campo de assinatura: Tadeu Testemunha.']);

    SigningField::factory()->forRecipient($ctx['recipients']['tadeu@exemplo.test'], $ctx['versions'][0])->signature()->create();

    // O visualizador não conta na ordem nem precisa de campo: agora fica pronto.
    expect(app(DocumentReadiness::class)->recompute($envelope->fresh()))->toBe(EnvelopeStatus::Ready);

    // Aprovador com campo de assinatura e visualizador com qualquer campo: recusados no sync.
    $approverCtx = domainEnvelope(['Contrato'], [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test'],
        ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Victor Visualizador', 'email' => 'victor@exemplo.test', 'role' => RecipientRole::Viewer],
    ], organization: $organization, owner: $owner, sent: false);

    $r = $approverCtx['recipients'];

    $this->put(route('envelopes.fields.sync', ['envelope' => $approverCtx['envelope']->ulid]), [
        'fields' => [
            ['recipient_id' => $r['maria@exemplo.test']->ulid, 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.08],
            ['recipient_id' => $r['paula@exemplo.test']->ulid, 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.3, 'w' => 0.3, 'h' => 0.08],
            ['recipient_id' => $r['victor@exemplo.test']->ulid, 'type' => 'text', 'page' => 1, 'x' => 0.1, 'y' => 0.5, 'w' => 0.3, 'h' => 0.05],
        ],
    ])->assertSessionHasErrors(['fields.1.type', 'fields.2.recipient_id']);

    // Mesmo gravado por fora, o campo do aprovador impede `ready`, com pendência explícita.
    SigningField::factory()->forRecipient($r['maria@exemplo.test'], $approverCtx['versions'][0])->signature()->create();
    SigningField::factory()->forRecipient($r['paula@exemplo.test'], $approverCtx['versions'][0])->signature()->create();

    expect(app(DocumentReadiness::class)->recompute($approverCtx['envelope']->fresh()))->toBe(EnvelopeStatus::Draft)
        ->and(EnvelopeReadiness::issues($approverCtx['envelope']->fresh()))->toContain('Aprovador não recebe campo de assinatura ou rubrica: Paula Aprovadora.');

    // Sem nenhum signatário não há envelope.
    $noSigner = domainEnvelope(['Contrato'], [
        ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Victor Visualizador', 'email' => 'victor@exemplo.test', 'role' => RecipientRole::Viewer],
    ], organization: $organization, owner: $owner, sent: false);

    expect(EnvelopeReadiness::issues($noSigner['envelope']))->toContain('Adicione pelo menos um signatário.');
});

it('com a flag de papéis desligada, só aceita signatários (Fase 1)', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create();

    $this->put(route('envelopes.recipients.sync', ['envelope' => $envelope->ulid]), [
        'signing_order' => 'sequential',
        'recipients' => [
            ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test'],
            ['name' => 'Tadeu Testemunha', 'email' => 'tadeu@exemplo.test', 'participant_role' => 'witness'],
        ],
    ])->assertSessionHasErrors(['recipients.1.participant_role']);

    expect(Recipient::query()->where('envelope_id', $envelope->id)->count())->toBe(0);

    $this->put(route('envelopes.recipients.sync', ['envelope' => $envelope->ulid]), [
        'signing_order' => 'sequential',
        'recipients' => [
            ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test'],
            ['name' => 'Henrique Dias', 'email' => 'henrique@exemplo.test'],
        ],
    ])->assertSessionHasNoErrors();

    expect(Recipient::query()->where('envelope_id', $envelope->id)->orderBy('order_index')->get()->map(fn ($r) => [$r->role->value, $r->order_index])->all())
        ->toBe([['signer', 1], ['signer', 2]]);
});
