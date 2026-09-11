<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Enums\PlanConsumptionStatus;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Models\PublicFormSubmission;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Services\PublicForms\SubmissionStatus;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/PublicFormHelpers.php';

/*
| Fila de revisão (docs/fase-2/formulario-publico.md §7): depois da confirmação o documento
| existe em rascunho, mas ninguém é convidado e nenhuma cota é usada até alguém da
| organização aprovar.
*/

beforeEach(function () {
    Notification::fake();
    $this->work = templatesWorkspace();
    templatesRequirePdftool();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    publicFormsEnable($this->organization);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

function reviewSubmission(object $test, string $kind = 'pdf'): PublicFormSubmission
{
    $template = $kind === 'pdf'
        ? publicFormPdfTemplate($test->organization, $test->owner, $test->work)
        : publicFormHtmlTemplate($test->organization, $test->owner);

    $test->form = publicFormFrom($test->organization, $test->owner, $template, ['destination' => 'review']);

    publicFormSubmit($test->form, $kind === 'pdf' ? [] : ['nome' => 'Ana', 'valor' => '10,00'])->assertSessionHasNoErrors();
    publicFormConfirm($test->form, publicFormConfirmationToken())->assertSessionHasNoErrors();

    return PublicFormSubmission::withoutOrganizationScope()->sole();
}

test('confirmado em modo revisão: rascunho criado, ninguém convidado, nenhuma cota usada', function () {
    $submission = reviewSubmission($this);

    $envelope = Envelope::withoutOrganizationScope()->sole();

    expect($submission->status)->toBe(SubmissionStatus::PendingReview)
        ->and($envelope->status->isDraftLike())->toBeTrue()
        ->and($envelope->sent_at)->toBeNull()
        ->and(PlanConsumption::withoutOrganizationScope()->count())->toBe(0);

    Notification::assertNotSentTo($envelope->recipients()->sole(), RecipientInvitationNotification::class);
    Notification::assertSentTimes(RecipientInvitationNotification::class, 0);

    $this->get(route('form_fill.confirm.show', ['token' => $this->form->public_token, 'confirmation' => str_repeat('x', 48)]))->assertNotFound();

    actingAsMember($this->owner, $this->organization);
    $this->get(route('public_forms.index'))->assertInertia(fn ($page) => $page
        ->has('queue', 1)
        ->where('queue.0.id', $submission->ulid)
        ->where('queue.0.filler.email', 'ana@example.com')
        ->where('queue.0.envelope.id', $envelope->ulid));
});

test('aprovar envia o documento e só então reserva/consome a cota', function () {
    $submission = reviewSubmission($this);

    actingAsMember($this->owner, $this->organization);
    $this->post(route('public_forms.submissions.approve', $submission))->assertSessionHasNoErrors()->assertRedirect();

    $envelope = Envelope::withoutOrganizationScope()->sole();

    expect($envelope->status)->toBe(EnvelopeStatus::InProgress)
        ->and($submission->fresh()->status)->toBe(SubmissionStatus::Sent)
        ->and($submission->fresh()->reviewed_by_user_id)->toBe($this->owner->id)
        ->and(PlanConsumption::withoutOrganizationScope()->sole()->status)->toBe(PlanConsumptionStatus::Committed)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::PublicFormSubmissionApproved->value)->where('envelope_id', $envelope->id)->exists())->toBeTrue();

    Notification::assertSentTimes(RecipientInvitationNotification::class, 1);

    // Aprovar de novo não envia outra vez.
    $this->post(route('public_forms.submissions.approve', $submission))->assertSessionHasErrors('submission');
    Notification::assertSentTimes(RecipientInvitationNotification::class, 1);
});

test('recusar exclui o rascunho sem convidar ninguém', function () {
    $submission = reviewSubmission($this);

    actingAsMember($this->owner, $this->organization);
    $this->post(route('public_forms.submissions.reject', $submission))->assertSessionHasNoErrors();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Rejected)
        ->and(Envelope::withoutOrganizationScope()->count())->toBe(0)
        ->and(Envelope::withoutOrganizationScope()->withTrashed()->sole()->trashed())->toBeTrue()
        ->and(PlanConsumption::withoutOrganizationScope()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::PublicFormSubmissionRejected->value)->exists())->toBeTrue();

    Notification::assertSentTimes(RecipientInvitationNotification::class, 0);
});

test('modelo HTML na fila: aprovar sem campos posicionados é recusado e aponta para o editor', function () {
    $submission = reviewSubmission($this, 'html');

    actingAsMember($this->owner, $this->organization);
    $this->post(route('public_forms.submissions.approve', $submission))
        ->assertSessionHasErrors(['submission' => 'O documento ainda tem pendências de preparo (por exemplo, campos de assinatura). Abra-o no editor, ajuste e envie por lá.']);

    expect($submission->fresh()->status)->toBe(SubmissionStatus::PendingReview)
        ->and(PlanConsumption::withoutOrganizationScope()->count())->toBe(0);
    Notification::assertSentTimes(RecipientInvitationNotification::class, 0);
});

test('operador (sem gerenciar modelos) não aprova nem recusa; aprovar também exige enviar documentos', function () {
    $submission = reviewSubmission($this);
    $operator = attachMember($this->organization, MembershipRole::Member);

    actingAsMember($operator, $this->organization);
    $this->post(route('public_forms.submissions.approve', $submission))->assertForbidden();
    $this->post(route('public_forms.submissions.reject', $submission))->assertForbidden();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::PendingReview);
});

test('rascunho enviado pelo editor sai da fila como enviado', function () {
    $submission = reviewSubmission($this);
    $envelope = Envelope::withoutOrganizationScope()->sole();

    actingAsMember($this->owner, $this->organization);
    $this->post(route('envelopes.send', $envelope))->assertSessionHasNoErrors();

    $this->get(route('public_forms.index'))->assertInertia(fn ($page) => $page->has('queue', 0));
    expect($submission->fresh()->status)->toBe(SubmissionStatus::Sent);
});
