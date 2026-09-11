<?php

use App\Enums\MembershipRole;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\PublicFormSubmission;
use App\Services\PublicForms\PublicFormManager;
use App\Services\PublicForms\PublicFormSchema;
use App\Services\PublicForms\SubmissionStatus;
use App\Services\Templates\TemplateManager;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/Support/PublicFormHelpers.php';

/*
| Formulário pausado, revogado, expirado, em rascunho ou com pendência recusa; cota esgotada
| recusa com mensagem clara (docs/fase-2/formulario-publico.md §3 e §5).
*/

beforeEach(function () {
    Notification::fake();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    publicFormsEnable($this->organization);
    $this->template = publicFormHtmlTemplate($this->organization, $this->owner);
    $this->form = publicFormFrom($this->organization, $this->owner, $this->template);
    $this->values = ['nome' => 'Ana', 'valor' => '10,00'];
});

function expectRefusedAt(object $test, string $screen, int $status): void
{
    $test->get(route('form_fill.show', ['token' => $test->form->public_token]))
        ->assertStatus($status)
        ->assertInertia(fn ($page) => $page->component('public-forms/fill')->where('screen', $screen)->missing('antiabuse'));

    $response = publicFormSubmit($test->form->fresh(), $test->values);

    $status === 404 ? $response->assertNotFound() : $response->assertSessionHasErrors('form');

    expect(PublicFormSubmission::withoutOrganizationScope()->count())->toBe(0);
    Notification::assertNothingSent();
}

test('pausado: a página diz que está pausado e não aceita envio', function () {
    app(PublicFormManager::class)->pause($this->form);

    expectRefusedAt($this, 'paused', 200);
});

test('revogado: responde como link inexistente (404)', function () {
    app(PublicFormManager::class)->revoke($this->form);

    expectRefusedAt($this, 'not_found', 404);
});

test('expirado: a página diz que foi encerrado', function () {
    $this->form->forceFill(['expires_at' => now()->subMinute()])->save();

    expectRefusedAt($this, 'expired', 200);
});

test('rascunho (nunca publicado): 404', function () {
    $this->form = publicFormFrom($this->organization, $this->owner, $this->template, activate: false);

    expectRefusedAt($this, 'not_found', 404);
});

test('modelo com versão nova: indisponível até alguém revisar o formulário', function () {
    app(TemplateManager::class)->update($this->template->fresh(), $this->owner, [
        'html_body' => '<p>Nova redação: {{nome}} {{valor}}</p>',
        'variables' => [
            ['key' => 'nome', 'label' => 'Nome completo', 'type' => 'text', 'required' => true],
            ['key' => 'valor', 'label' => 'Valor', 'type' => 'currency', 'required' => true],
        ],
        'roles' => [['ref' => 'r1', 'name' => 'Locatário', 'participant_role' => 'signer']],
        'fields' => [],
    ]);

    expectRefusedAt($this, 'unavailable', 200);
});

test('responsável que perdeu o acesso: indisponível', function () {
    $admin = attachMember($this->organization, MembershipRole::Admin);
    $form = app(PublicFormManager::class)->update($this->form, $admin, app(PublicFormSchema::class)->configInput($this->form));
    expect($form->responsible_user_id)->toBe($admin->id);

    Membership::query()->where('user_id', $admin->id)->delete();

    expectRefusedAt($this, 'unavailable', 200);
});

test('link pendente de formulário pausado não confirma; ao retomar, confirma', function () {
    templatesWorkspace();
    templatesRequirePdftool();

    publicFormSubmit($this->form, $this->values)->assertSessionHasNoErrors();
    $token = publicFormConfirmationToken();

    app(PublicFormManager::class)->pause($this->form);

    publicFormConfirm($this->form, $token)->assertSessionHasErrors('confirmation');
    expect(Envelope::withoutOrganizationScope()->count())->toBe(0)
        ->and(PublicFormSubmission::withoutOrganizationScope()->sole()->status)->toBe(SubmissionStatus::PendingConfirmation);

    app(PublicFormManager::class)->activate($this->form->fresh());

    publicFormConfirm($this->form, $token)->assertSessionHasNoErrors();
    expect(Envelope::withoutOrganizationScope()->count())->toBe(1);
});

test('revogar apaga os envios que aguardavam confirmação', function () {
    publicFormSubmit($this->form, $this->values)->assertSessionHasNoErrors();

    app(PublicFormManager::class)->revoke($this->form);

    expect(PublicFormSubmission::withoutOrganizationScope()->count())->toBe(0);
});

describe('cota do plano', function () {
    test('cota esgotada: o envio é recusado com mensagem clara, antes de gravar qualquer coisa', function () {
        setPlanQuota($this->organization, 1);
        subscriptionFor($this->organization)->forceFill(['envelopes_used' => 1])->save();

        publicFormSubmit($this->form, $this->values)->assertSessionHasErrors([
            'form' => 'Este formulário não está aceitando respostas no momento: a organização atingiu o limite de documentos do plano. Tente mais tarde ou avise quem enviou o link.',
        ]);

        expect(PublicFormSubmission::withoutOrganizationScope()->count())->toBe(0);
        Notification::assertNothingSent();
    });

    test('cota esgotada entre o envio e a confirmação: recusa clara, nenhum envelope, e o link continua valendo', function () {
        setPlanQuota($this->organization, 1);

        publicFormSubmit($this->form, $this->values)->assertSessionHasNoErrors();
        $token = publicFormConfirmationToken();

        subscriptionFor($this->organization)->forceFill(['envelopes_used' => 1])->save();

        publicFormConfirm($this->form, $token)->assertSessionHasErrors([
            'confirmation' => 'Este formulário não está aceitando respostas no momento: a organização atingiu o limite de documentos do plano. Tente mais tarde ou avise quem enviou o link.',
        ]);

        expect(Envelope::withoutOrganizationScope()->count())->toBe(0)
            ->and(PublicFormSubmission::withoutOrganizationScope()->sole()->status)->toBe(SubmissionStatus::PendingConfirmation);
    });
});
