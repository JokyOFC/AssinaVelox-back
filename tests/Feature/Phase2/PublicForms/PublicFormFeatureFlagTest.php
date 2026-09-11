<?php

use App\Models\PublicForm;
use App\Models\PublicFormSubmission;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/Support/PublicFormHelpers.php';

/*
| Flag `public_forms` desligada = 404 em TODAS as rotas do recurso, internas e públicas
| (docs/fase-2/formulario-publico.md §2). Um link já compartilhado vira link inexistente.
*/

beforeEach(function () {
    Notification::fake();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    publicFormsEnable($this->organization);
    $this->form = publicFormFrom($this->organization, $this->owner, publicFormHtmlTemplate($this->organization, $this->owner));
});

function assertEverythingIs404(object $test): void
{
    $form = $test->form;

    actingAsMember($test->owner, $test->organization);

    $test->get(route('public_forms.index'))->assertNotFound();
    $test->get(route('public_forms.edit', $form))->assertNotFound();
    $test->post(route('public_forms.store'), ['template' => $form->template->ulid])->assertNotFound();
    $test->put(route('public_forms.update', $form), [])->assertNotFound();
    $test->post(route('public_forms.pause', $form))->assertNotFound();
    $test->post(route('public_forms.revoke', $form))->assertNotFound();

    auth()->logout();

    $test->get(route('form_fill.show', ['token' => $form->public_token]))
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page->component('public-forms/fill')->where('screen', 'not_found'));

    publicFormSubmit($form, ['nome' => 'Ana', 'valor' => '10,00'])->assertNotFound();

    $test->get(route('form_fill.confirm.show', ['token' => $form->public_token, 'confirmation' => str_repeat('a', 48)]))->assertNotFound();
    publicFormConfirm($form, str_repeat('a', 48))->assertNotFound();

    expect(PublicFormSubmission::withoutOrganizationScope()->count())->toBe(0);
    expect(PublicForm::withoutOrganizationScope()->count())->toBe(1);
    Notification::assertNothingSent();
}

test('interruptor global desligado: tudo 404, mesmo com o plano ligado', function () {
    config()->set('assinavelox.features.public_forms', false);

    assertEverythingIs404($this);
});

test('plano sem o recurso: tudo 404, mesmo com o interruptor global ligado', function () {
    $plan = $this->organization->currentSubscription()->with('plan')->first()->plan;
    $plan->forceFill(['features' => array_merge((array) $plan->features, ['public_forms' => false])])->save();

    assertEverythingIs404($this);
});

test('flag `templates` desligada derruba o formulário (ele depende dos modelos)', function () {
    config()->set('assinavelox.features.templates', false);

    assertEverythingIs404($this);
});

test('com a flag ligada as rotas existem', function () {
    actingAsMember($this->owner, $this->organization);
    $this->get(route('public_forms.index'))->assertOk()->assertInertia(fn ($page) => $page->component('public-forms/index'));
    $this->get(route('public_forms.edit', $this->form))->assertOk()->assertInertia(fn ($page) => $page->component('public-forms/edit'));

    auth()->logout();
    $this->get(route('form_fill.show', ['token' => $this->form->public_token]))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertInertia(fn ($page) => $page->component('public-forms/fill')->where('screen', 'form'));
});
