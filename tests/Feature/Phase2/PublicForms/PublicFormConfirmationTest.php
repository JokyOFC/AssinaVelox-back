<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\PlanConsumptionStatus;
use App\Enums\RecipientRole;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Models\PublicFormSubmission;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Services\PublicForms\SubmissionPurge;
use App\Services\PublicForms\SubmissionStatus;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/PublicFormHelpers.php';

/*
| Confirmação do e-mail (docs/fase-2/formulario-publico.md §5): sem confirmação não há
| envelope nem consumo; o link vale uma vez e dentro do prazo; o envio automático gera o
| envelope `in_progress` com quem preencheu como signatário.
*/

beforeEach(function () {
    Notification::fake();
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner(['name' => 'Clínica Horizonte']);
    publicFormsEnable($this->organization);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('envio automático: confirmação gera o envelope, envia a quem preencheu e consome a cota só agora', function () {
    templatesRequirePdftool();

    $form = publicFormFrom($this->organization, $this->owner, publicFormPdfTemplate($this->organization, $this->owner, $this->work), ['destination' => 'auto_send']);

    publicFormSubmit($form)->assertSessionHasNoErrors();

    // Antes da confirmação: nenhum envelope, nenhum consumo, nenhum convite — só o link.
    expect(Envelope::withoutOrganizationScope()->count())->toBe(0)
        ->and(PlanConsumption::withoutOrganizationScope()->count())->toBe(0);
    Notification::assertSentTimes(RecipientInvitationNotification::class, 0);

    $token = publicFormConfirmationToken();

    // GET só mostra a tela: pré-carregar o link não confirma.
    $this->get(route('form_fill.confirm.show', ['token' => $form->public_token, 'confirmation' => $token]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('public-forms/confirm')->where('screen', 'confirm')->where('email_hint', 'an***@example.com'));
    expect(Envelope::withoutOrganizationScope()->count())->toBe(0);

    publicFormConfirm($form, $token)->assertSessionHasNoErrors()->assertRedirect();

    $envelope = Envelope::withoutOrganizationScope()->sole();
    $recipient = $envelope->recipients()->sole();
    $submission = PublicFormSubmission::withoutOrganizationScope()->sole();

    expect($envelope->status)->toBe(EnvelopeStatus::InProgress)
        ->and($envelope->organization_id)->toBe($this->organization->id)
        ->and($envelope->created_by_user_id)->toBe($this->owner->id)
        ->and($envelope->title)->toBe('Termo de adesão — Ana Souza')
        ->and($recipient->email)->toBe('ana@example.com')
        ->and($recipient->name)->toBe('Ana Souza')
        ->and($recipient->role)->toBe(RecipientRole::Signer)
        ->and($recipient->role_label)->toBe('Cliente')
        ->and($submission->status)->toBe(SubmissionStatus::Sent)
        ->and($submission->envelope_id)->toBe($envelope->id)
        ->and($submission->confirmed_at)->not->toBeNull()
        // O payload some depois que o envelope nasce.
        ->and($submission->payload)->toBeNull()
        ->and(PlanConsumption::withoutOrganizationScope()->sole()->status)->toBe(PlanConsumptionStatus::Committed);

    Notification::assertSentTimes(RecipientInvitationNotification::class, 1);

    $event = AuditEvent::query()->where('event_type', AuditEventType::PublicFormSubmissionConfirmed->value)->sole();
    expect($event->envelope_id)->toBe($envelope->id)
        ->and($event->payload)->toMatchArray(['form' => $form->ulid, 'submission' => $submission->ulid, 'destination' => 'auto_send'])
        ->and(json_encode($event->payload))->not->toContain('ana@example.com')->not->toContain('Ana Souza');

    $this->get(route('form_fill.confirm.show', ['token' => $form->public_token, 'confirmation' => $token]))
        ->assertInertia(fn ($page) => $page->where('screen', 'done')->where('outcome', 'sent'));
});

test('sem confirmação não há envelope nem consumo, e o envio vencido é apagado pela limpeza', function () {
    $form = publicFormFrom($this->organization, $this->owner, publicFormHtmlTemplate($this->organization, $this->owner));

    publicFormSubmit($form, ['nome' => 'Ana', 'valor' => '10,00'])->assertSessionHasNoErrors();

    $this->travel(61)->minutes();

    expect(app(SubmissionPurge::class)->run())->toBe(1)
        ->and(PublicFormSubmission::withoutOrganizationScope()->count())->toBe(0)
        ->and(Envelope::withoutOrganizationScope()->count())->toBe(0)
        ->and(PlanConsumption::withoutOrganizationScope()->count())->toBe(0);
});

test('confirmação vencida é recusada e não gera nada', function () {
    $form = publicFormFrom($this->organization, $this->owner, publicFormHtmlTemplate($this->organization, $this->owner));
    publicFormSubmit($form, ['nome' => 'Ana', 'valor' => '10,00']);
    $token = publicFormConfirmationToken();

    $this->travel(61)->minutes();

    $this->get(route('form_fill.confirm.show', ['token' => $form->public_token, 'confirmation' => $token]))
        ->assertInertia(fn ($page) => $page->where('screen', 'expired'));

    publicFormConfirm($form, $token)->assertSessionHasErrors(['confirmation' => 'Este link de confirmação venceu e os dados enviados foram descartados. Preencha o formulário de novo.']);

    expect(Envelope::withoutOrganizationScope()->count())->toBe(0)
        ->and(PublicFormSubmission::withoutOrganizationScope()->sole()->status)->toBe(SubmissionStatus::PendingConfirmation);
});

test('confirmação reutilizada é recusada: o segundo clique não gera outro envelope', function () {
    templatesRequirePdftool();

    $form = publicFormFrom($this->organization, $this->owner, publicFormHtmlTemplate($this->organization, $this->owner));
    publicFormSubmit($form, ['nome' => 'Ana', 'valor' => '10,00']);
    $token = publicFormConfirmationToken();

    publicFormConfirm($form, $token)->assertSessionHasNoErrors();
    publicFormConfirm($form, $token)->assertSessionHasErrors(['confirmation' => 'Este link de confirmação já foi usado. Cada link vale uma única vez.']);

    expect(Envelope::withoutOrganizationScope()->count())->toBe(1);

    // Sem a sessão da primeira confirmação, a tela diz "já usado".
    $this->flushSession();
    $this->get(route('form_fill.confirm.show', ['token' => $form->public_token, 'confirmation' => $token]))
        ->assertInertia(fn ($page) => $page->where('screen', 'used'));
});

test('token de confirmação desconhecido ou de outro formulário é recusado', function () {
    $form = publicFormFrom($this->organization, $this->owner, publicFormHtmlTemplate($this->organization, $this->owner));
    $other = publicFormFrom($this->organization, $this->owner, $form->template);

    publicFormSubmit($form, ['nome' => 'Ana', 'valor' => '10,00']);
    $token = publicFormConfirmationToken();

    $this->get(route('form_fill.confirm.show', ['token' => $other->public_token, 'confirmation' => $token]))
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page->where('screen', 'invalid'));
    publicFormConfirm($other, $token)->assertSessionHasErrors('confirmation');
    publicFormConfirm($form, str_repeat('Z', 48))->assertSessionHasErrors('confirmation');

    expect(Envelope::withoutOrganizationScope()->count())->toBe(0);
});

test('limite de respostas do período recusa na submissão e na confirmação', function () {
    $form = publicFormFrom($this->organization, $this->owner, publicFormHtmlTemplate($this->organization, $this->owner), ['submissions_limit' => 1]);

    publicFormSubmit($form, ['nome' => 'Ana', 'valor' => '10,00'])->assertSessionHasNoErrors();
    $token = publicFormConfirmationToken();

    // Outra resposta confirmada dentro da janela ocupa o limite.
    PublicFormSubmission::query()->create([
        'organization_id' => $this->organization->id,
        'public_form_id' => $form->id,
        'status' => SubmissionStatus::Sent,
        'email_digest' => str_repeat('0', 64),
        'confirmed_at' => now(),
    ]);

    publicFormConfirm($form, $token)->assertSessionHasErrors(['confirmation' => 'Este formulário atingiu o limite de respostas do período. Tente mais tarde ou fale com quem enviou o link.']);
    publicFormSubmit($form, ['nome' => 'Bia', 'valor' => '10,00'], ['email' => 'bia@example.com'])->assertSessionHasErrors('form');

    expect(Envelope::withoutOrganizationScope()->count())->toBe(0);

    // A janela é móvel: 24 horas depois o link original já venceu, mas uma nova resposta passa.
    $this->travel(25)->hours();
    publicFormSubmit($form, ['nome' => 'Bia', 'valor' => '10,00'], ['email' => 'bia@example.com', 'started' => publicFormTimer($form)])->assertSessionHasNoErrors();
});
