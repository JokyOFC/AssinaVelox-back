<?php

use App\Models\Envelope;
use App\Models\PublicFormSubmission;
use App\Models\RetentionEvent;
use App\Services\Retention\LegalHolds;
use App\Services\Retention\LegalHoldScope;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../Phase2/PublicForms/Support/PublicFormHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda C (ciclo de vida) — recusar envio de formulário x preservação
|--------------------------------------------------------------------------
| A exclusão manual do rascunho é barrada pela preservação (EnvelopeController::destroy →
| LegalHolds::guardEnvelope, com o autor na trilha — docs/fase-2/retencao-e-preservacao.md
| §5.2). Recusar um envio do formulário público (PublicFormReview::reject) exclui o MESMO
| rascunho (`$envelope->delete()`) por outro caminho, sem consultar LegalHolds e sem registrar
| a tentativa: o documento preservado some das telas e passa a contar como "rascunho excluído"
| para a categoria de rascunhos da retenção.
*/

beforeEach(function () {
    Notification::fake();
    $this->work = templatesWorkspace();
    templatesRequirePdftool();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    publicFormsEnable($this->organization);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

it('recusar o envio não exclui o rascunho preservado e registra a tentativa', function () {
    $template = publicFormPdfTemplate($this->organization, $this->owner, $this->work);
    $form = publicFormFrom($this->organization, $this->owner, $template, ['destination' => 'review']);

    publicFormSubmit($form, [])->assertSessionHasNoErrors();
    publicFormConfirm($form, publicFormConfirmationToken())->assertSessionHasNoErrors();

    $submission = PublicFormSubmission::withoutOrganizationScope()->sole();
    $envelope = Envelope::withoutOrganizationScope()->sole();

    app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Envelope, 'Contestação do preenchimento', envelope: $envelope);

    actingAsMember($this->owner, $this->organization);
    $this->from(route('public_forms.index'))->post(route('public_forms.submissions.reject', $submission));

    expect(Envelope::withoutOrganizationScope()->whereKey($envelope->id)->whereNull('deleted_at')->exists())->toBeTrue()
        ->and(RetentionEvent::withoutOrganizationScope()
            ->where('event_type', RetentionEvent::HOLD_BLOCKED_DELETION)
            ->where('envelope_id', $envelope->id)
            ->exists())->toBeTrue();
});
