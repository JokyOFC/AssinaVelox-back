<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Notifications\Signing\SignerOtpNotification;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Documents/Support/helpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Ponta a ponta: preparação (incremento 2) + coleta (incremento 3)
|--------------------------------------------------------------------------
| Este arquivo NÃO usa atalhos de fábrica para montar o estado: cada passo passa
| pela rota HTTP real, exatamente como o navegador faz. É o único teste da suíte
| que prova que os cinco módulos entregues em paralelo (documento, campos, envio,
| assinatura e front) falam a mesma língua.
|
| O que ele deliberadamente NÃO afirma: que o envelope conclui. O escopo aqui
| termina onde o incremento 3 termina — no `finalizing`, com o gancho disparado —
| e por isso os cenários usam `Event::fake([EnvelopeReadyForFinalization::class])`
| para interceptar o disparo em vez de deixar a finalização rodar.
|
| A continuação (consolidação, evidências, assinatura da operadora, registro de
| verificação, verificação pública, download e cota) está em `FullLifecycleTest`,
| que percorre o mesmo caminho e vai até `completed` com PDF e certificado reais.
*/

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    fakeDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Consultoria']);
    $this->organization = $organization;
    $this->owner = $owner;

    // O plano free do seeder tem cota 5; os cenários enviam mais de um envelope.
    setPlanQuota($organization, null);

    // Convites e códigos só existem em claro dentro da notificação: capturamos os dois no
    // evento de envio, sem `Notification::fake()`, para que o canal rastreado rode de
    // verdade e as linhas de `delivery_attempts` continuem sendo criadas.
    $this->invites = new ArrayObject;
    $this->codes = new ArrayObject;

    Event::listen(NotificationSending::class, function (NotificationSending $event): void {
        if ($event->notification instanceof RecipientInvitationNotification) {
            $this->invites[$event->notification->recipient->email] = $event->notification->signingUrl;
        }

        if ($event->notification instanceof SignerOtpNotification) {
            $this->codes[] = $event->notification->code;
        }
    });

    actingAsMember($owner, $organization);
});

afterEach(function () {
    cleanupDocumentsWorkspace($this->work ?? null);
});

// -- Passos, cada um por HTTP ------------------------------------------------------

/**
 * "Nova solicitação" → Envelope(draft) criado pelo controller e o wizard aberto.
 */
function e2eCreateEnvelope(object $test): Envelope
{
    $test->get(route('envelopes.create'))->assertRedirect();

    /** @var Envelope $envelope */
    $envelope = Envelope::query()->latest('id')->firstOrFail();

    expect($envelope->status)->toBe(EnvelopeStatus::Draft);

    return $envelope;
}

/**
 * Passo 1 do wizard: envia um PDF real de duas páginas pela rota do wizard.
 * Com `QUEUE_CONNECTION=sync` o `ProcessDocumentUpload` roda dentro da requisição,
 * que é o mesmo efeito de um worker que já processou.
 */
function e2eUploadPdf(object $test, Envelope $envelope, string $name = 'contrato.pdf'): Document
{
    $source = PdfFixtures::twoPagePdf($test->work.DIRECTORY_SEPARATOR.$name);

    uploadDocument($envelope, $source, $name)->assertSessionHasNoErrors();

    /** @var Document $document */
    $document = Document::query()->where('envelope_id', $envelope->getKey())->firstOrFail();

    expect($document->processing_status)->toBe(DocumentProcessingStatus::Ready)
        ->and($document->current_version_id)->not->toBeNull()
        ->and((int) $document->page_count)->toBe(2);

    return $document->refresh();
}

/**
 * Passo 2: destinatários pela rota de sincronização.
 *
 * @param  list<array{name: string, email: string, role?: string}>  $rows
 */
function e2eSyncRecipients(object $test, Envelope $envelope, array $rows, SigningOrder $order): void
{
    $payload = [];

    foreach (array_values($rows) as $index => $row) {
        $payload[] = [
            'client_id' => 'tmp-'.$index,
            'name' => $row['name'],
            'email' => $row['email'],
            'role' => $row['role'] ?? null,
            'order' => $index + 1,
        ];
    }

    $test->put(route('envelopes.recipients.sync', ['envelope' => $envelope->ulid]), [
        'signing_order' => $order->value,
        'recipients' => $payload,
    ])->assertSessionHasNoErrors();
}

/**
 * Passo 3: um campo de assinatura por destinatário, na página 1, pela rota real.
 * A geometria vai em fração da página; o servidor a revalida contra `pages_meta`.
 */
function e2eSyncFields(object $test, Envelope $envelope): void
{
    $recipients = $envelope->recipients()->orderBy('order_index')->get();
    $fields = [];

    foreach ($recipients as $index => $recipient) {
        $fields[] = [
            'client_id' => 'field-'.$index,
            'recipient_id' => $recipient->ulid,
            'type' => 'signature',
            'page' => 1,
            'x' => 0.1,
            'y' => 0.55 + ($index * 0.12),
            'w' => 0.3,
            'h' => 0.07,
            'required' => true,
        ];
    }

    $test->put(route('envelopes.fields.sync', ['envelope' => $envelope->ulid]), [
        'initials_on_all_pages' => false,
        'fields' => $fields,
    ])->assertSessionHasNoErrors();
}

/**
 * Passo 4: envio. Depois dele o envelope está `in_progress` e os convites da vez saíram.
 */
function e2eSend(object $test, Envelope $envelope): Envelope
{
    $test->post(route('envelopes.send', ['envelope' => $envelope->ulid]))
        ->assertSessionHasNoErrors();

    $sent = $envelope->fresh();

    expect($sent->status)->toBe(EnvelopeStatus::InProgress)
        ->and($sent->sent_document_version_id)->not->toBeNull()
        ->and($sent->verification_code)->not->toBeNull()
        ->and($sent->expires_at)->not->toBeNull();

    return $sent;
}

/**
 * Extrai o token do convite capturado (a URL só existe dentro da notificação).
 */
function e2eTokenFor(object $test, string $email): string
{
    expect(isset($test->invites[$email]))->toBeTrue("Nenhum convite foi despachado para {$email}.");

    $path = (string) parse_url((string) $test->invites[$email], PHP_URL_PATH);
    $segments = array_values(array_filter(explode('/', $path)));

    return end($segments);
}

/**
 * Autentica o signatário pelo caminho real (pedir código → ler o código do e-mail →
 * verificar) e devolve as props da tela `sign`.
 *
 * @return array<string, mixed>
 */
function e2eAuthenticate(object $test, string $token): array
{
    $test->post(route('sign.otp.send', ['token' => $token]))->assertRedirect();

    $codes = $test->codes;
    $test->post(route('sign.otp.verify', ['token' => $token]), [
        'code' => $codes[count($codes) - 1],
    ])->assertRedirect();

    $props = $test->get(route('sign.show', ['token' => $token]))->viewData('page')['props'];

    expect($props['screen'])->toBe('sign');

    // O navegador do signatário busca o PDF assim que a tela carrega. É esse GET que marca
    // `signing_sessions.document_presented_at` e grava `document.presented` na trilha —
    // exigido por `RecordAcceptance` antes de gravar o aceite, porque a declaração afirma
    // que o conteúdo foi apresentado nesta tela.
    $test->get(route('sign.document', ['token' => $token]));

    return $props;
}

/**
 * Aceite com assinatura desenhada, usando o token de autorização da tela apresentada.
 *
 * @param  array<string, mixed>  $props
 */
function e2eAccept(object $test, string $token, array $props): void
{
    $test->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (E2E)'])->assertSessionHasNoErrors();
}

/**
 * Monta um envelope pronto para enviar percorrendo os quatro passos do wizard.
 *
 * @param  list<array{name: string, email: string, role?: string}>  $rows
 */
function e2ePrepared(object $test, array $rows, SigningOrder $order): Envelope
{
    $envelope = e2eCreateEnvelope($test);
    e2eUploadPdf($test, $envelope);
    e2eSyncRecipients($test, $envelope, $rows, $order);
    e2eSyncFields($test, $envelope->fresh());

    $ready = $envelope->fresh();

    expect($ready->status)->toBe(EnvelopeStatus::Ready);

    return $ready;
}

const E2E_ROWS = [
    ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'role' => 'Locatária'],
    ['name' => 'Henrique Dias', 'email' => 'henrique@exemplo.test', 'role' => 'Fiador'],
];

// -- Cenários ----------------------------------------------------------------------

it('percorre o fluxo sequencial do rascunho até finalizing, sem concluir', function () {
    $envelope = e2ePrepared($this, E2E_ROWS, SigningOrder::Sequential);
    $sentVersionId = null;

    // O papel livre chegou ao banco (coluna role_label da migration do incremento 2).
    expect($envelope->recipients()->orderBy('order_index')->pluck('role_label')->all())
        ->toBe(['Locatária', 'Fiador']);

    $envelope = e2eSend($this, $envelope);
    $sentVersionId = $envelope->sent_document_version_id;

    // Sequencial: só a ordem 1 foi convidada; a ordem 2 continua "Aguarda a vez".
    $maria = Recipient::query()->where('email', 'maria@exemplo.test')->sole();
    $henrique = Recipient::query()->where('email', 'henrique@exemplo.test')->sole();

    expect($maria->status)->toBe(RecipientStatus::Notified)
        ->and($henrique->status)->toBe(RecipientStatus::Pending)
        ->and(isset($this->invites['henrique@exemplo.test']))->toBeFalse();

    $mariaToken = e2eTokenFor($this, 'maria@exemplo.test');

    // Abrir o link NÃO consome nada e não autentica: a tela é a de identificação.
    $opened = $this->get(route('sign.show', ['token' => $mariaToken]));
    $opened->assertOk();
    expect($opened->viewData('page')['props']['screen'])->toBe('identify')
        ->and($opened->viewData('page')['props']['document'])->toBeNull()
        ->and($maria->fresh()->status)->toBe(RecipientStatus::Viewed);

    expect(AuditEvent::query()->where('event_type', AuditEventType::InvitationOpened->value)->exists())->toBeTrue();

    // Autenticação e aceite da primeira signatária.
    $props = e2eAuthenticate($this, $mariaToken);

    expect($props['document']['sha256'])->not->toBeEmpty()
        ->and($props['my_fields'])->toHaveCount(1)
        ->and($props['other_fields'])->toHaveCount(1)
        // O e-mail de terceiros nunca aparece na página pública.
        ->and(json_encode($props['others']))->not->toContain('henrique@exemplo.test');

    e2eAccept($this, $mariaToken, $props);

    expect($maria->fresh()->status)->toBe(RecipientStatus::Signed)
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::InProgress)
        ->and($envelope->fresh()->current_order)->toBe(2);

    // O aceite avançou a vez e o segundo foi notificado de verdade.
    expect($henrique->fresh()->status)->toBe(RecipientStatus::Notified)
        ->and(isset($this->invites['henrique@exemplo.test']))->toBeTrue();

    // Quem já assinou não assina de novo: a sessão foi consumida e a tela é terminal.
    $again = $this->get(route('sign.show', ['token' => $mariaToken]));
    expect($again->viewData('page')['props']['screen'])->toBe('already_signed_pending_others');

    // O aceite consumiu a sessão: o segundo POST nem chega ao serviço — `signer.verified`
    // devolve a pessoa para a tela pedindo o código de novo (e lá ela verá a tela terminal).
    $this->post(route('sign.complete', ['token' => $mariaToken]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect(route('sign.show', ['token' => $mariaToken]))
        ->assertSessionHas('error');

    expect(SignatureAcceptance::query()->count())->toBe(1);

    // Segundo signatário. O último aceite dispara a finalização — e só isso.
    Event::fake([EnvelopeReadyForFinalization::class]);

    $henriqueToken = e2eTokenFor($this, 'henrique@exemplo.test');
    $props2 = e2eAuthenticate($this, $henriqueToken);

    // A versão apresentada ao segundo é a MESMA congelada no envio.
    expect($props2['document']['sha256'])->toBe($props['document']['sha256']);

    e2eAccept($this, $henriqueToken, $props2);

    Event::assertDispatched(EnvelopeReadyForFinalization::class);

    $final = $envelope->fresh();

    expect($final->status)->toBe(EnvelopeStatus::Finalizing)
        ->and($final->completed_at)->toBeNull()
        ->and($final->final_document_version_id)->toBeNull()
        ->and($final->sent_document_version_id)->toBe($sentVersionId)
        ->and(SignatureAcceptance::query()->count())->toBe(2);

    // Os dois aceites apontam para a versão congelada, não para uma versão nova.
    expect(SignatureAcceptance::query()->pluck('document_version_id')->unique()->all())
        ->toBe([$sentVersionId]);

    // Trilha completa, na ordem do fluxo.
    $types = AuditEvent::query()
        ->where('envelope_id', $envelope->getKey())
        ->orderBy('occurred_at')->orderBy('id')
        ->pluck('event_type')
        ->map(fn (AuditEventType $type): string => $type->value)
        ->all();

    expect($types)->toContain(
        AuditEventType::EnvelopeCreated->value,
        AuditEventType::DocumentUploaded->value,
        AuditEventType::RecipientsUpdated->value,
        AuditEventType::FieldsUpdated->value,
        AuditEventType::EnvelopeSent->value,
        AuditEventType::InvitationSent->value,
        AuditEventType::InvitationOpened->value,
        AuditEventType::ChallengeSent->value,
        AuditEventType::ChallengeVerified->value,
        AuditEventType::SessionStarted->value,
        AuditEventType::AcceptanceRecorded->value,
        AuditEventType::EnvelopeFinalizing->value,
    );

    expect($types)->not->toContain(AuditEventType::EnvelopeCompleted->value);
});

it('não grava nenhum token nem código na trilha, no e-mail registrado ou no log', function () {
    $envelope = e2ePrepared($this, [E2E_ROWS[0]], SigningOrder::Sequential);
    $envelope = e2eSend($this, $envelope);

    $token = e2eTokenFor($this, 'maria@exemplo.test');
    $props = e2eAuthenticate($this, $token);
    e2eAccept($this, $token, $props);

    $code = (string) $this->codes[count($this->codes) - 1];
    $authorization = (string) $props['authorization']['token'];

    // Tudo que a aplicação persiste sobre este fluxo, concatenado.
    $persisted = json_encode([
        AuditEvent::query()->get()->toArray(),
        DB::table('delivery_attempts')->get()->toArray(),
        DB::table('signing_sessions')->get()->toArray(),
        DB::table('auth_challenges')->get()->toArray(),
        DB::table('recipient_access_links')->get()->toArray(),
        SignatureAcceptance::query()->get()->toArray(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect($persisted)->not->toContain($token)
        ->and($persisted)->not->toContain($code)
        ->and($persisted)->not->toContain($authorization)
        // O e-mail completo também não entra na trilha (só a forma mascarada).
        ->and(json_encode(AuditEvent::query()->pluck('payload')->all()))->not->toContain('maria@exemplo.test');
});

it('percorre a variante paralela: todos são convidados de uma vez e a ordem não importa', function () {
    $envelope = e2ePrepared($this, E2E_ROWS, SigningOrder::Parallel);
    $envelope = e2eSend($this, $envelope);

    expect(isset($this->invites['maria@exemplo.test']))->toBeTrue()
        ->and(isset($this->invites['henrique@exemplo.test']))->toBeTrue()
        ->and(Recipient::query()->pluck('status')->map(fn ($s) => $s->value)->unique()->all())
        ->toBe([RecipientStatus::Notified->value]);

    // O segundo da lista assina primeiro: no paralelo não há vez a respeitar.
    $henriqueToken = e2eTokenFor($this, 'henrique@exemplo.test');
    e2eAccept($this, $henriqueToken, e2eAuthenticate($this, $henriqueToken));

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::InProgress);

    Event::fake([EnvelopeReadyForFinalization::class]);

    $mariaToken = e2eTokenFor($this, 'maria@exemplo.test');
    e2eAccept($this, $mariaToken, e2eAuthenticate($this, $mariaToken));

    Event::assertDispatched(EnvelopeReadyForFinalization::class);

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Finalizing)
        ->and($envelope->fresh()->completed_at)->toBeNull();
});

it('percorre a variante com recusa: o envelope encerra e ninguém mais assina', function () {
    $envelope = e2ePrepared($this, E2E_ROWS, SigningOrder::Sequential);
    $envelope = e2eSend($this, $envelope);

    $token = e2eTokenFor($this, 'maria@exemplo.test');
    e2eAuthenticate($this, $token);

    $this->post(route('sign.refuse', ['token' => $token]), [
        'reason' => 'A cláusula 7 não corresponde ao que combinamos por e-mail.',
    ])->assertSessionHasNoErrors();

    $refused = $envelope->fresh();

    expect($refused->status)->toBe(EnvelopeStatus::Refused)
        ->and($refused->refused_at)->not->toBeNull()
        ->and(Recipient::query()->where('email', 'maria@exemplo.test')->sole()->status)
        ->toBe(RecipientStatus::Refused)
        ->and(SignatureAcceptance::query()->count())->toBe(0);

    // A tela da própria pessoa mostra a recusa; a do outro, o encerramento.
    $screen = $this->get(route('sign.show', ['token' => $token]))->viewData('page')['props'];
    expect($screen['screen'])->toBe('refused')
        ->and($screen['refusal']['reason'])->toContain('cláusula 7');

    $types = AuditEvent::query()
        ->where('envelope_id', $envelope->getKey())
        ->pluck('event_type')
        ->map(fn (AuditEventType $type): string => $type->value)
        ->all();

    expect($types)->toContain(AuditEventType::RecipientRefused->value, AuditEventType::EnvelopeRefused->value)
        ->and($types)->not->toContain(AuditEventType::EnvelopeFinalizing->value);
});
