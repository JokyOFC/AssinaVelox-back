<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes do formulário público (C-FORM, Fase 2 §2.2)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Models\Organization;
use App\Models\PublicForm;
use App\Models\Template;
use App\Models\User;
use App\Services\PublicForms\Notifications\PublicFormConfirmationNotification;
use App\Services\PublicForms\PublicFormManager;
use App\Services\PublicForms\PublicFormSchema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Templates/Support/TemplateHelpers.php';
require_once __DIR__.'/../../../Sending/Support/SendingHelpers.php';

if (! function_exists('publicFormsEnable')) {
    /**
     * Liga `public_forms` (e `templates`, da qual depende): configuração global E plano.
     */
    function publicFormsEnable(Organization $organization, bool $participantRoles = false): void
    {
        // As páginas públicas renderizam a view raiz do Inertia; como no smoke de rotas, os
        // testes não dependem do manifesto do build conter as páginas novas.
        test()->withoutVite();

        templatesEnable($organization, $participantRoles);
        config()->set('assinavelox.features.public_forms', true);

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);
        $features['public_forms'] = true;
        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('publicFormFrom')) {
    /**
     * Formulário a partir do modelo, com a configuração inicial + `$config`, publicado.
     *
     * @param  array<string, mixed>  $config
     */
    function publicFormFrom(Organization $organization, User $user, Template $template, array $config = [], bool $activate = true): PublicForm
    {
        $manager = app(PublicFormManager::class);

        $form = $manager->create($organization, $user, $template->fresh());
        $form = $manager->update($form, $user, array_merge(app(PublicFormSchema::class)->configInput($form), $config));

        if ($activate) {
            $manager->activate($form);
        }

        return $form->fresh();
    }
}

if (! function_exists('publicFormTimer')) {
    /**
     * Carimbo de "página aberta há N segundos" (o mesmo formato do FillTimer).
     */
    function publicFormTimer(PublicForm $form, int $secondsAgo = 30): string
    {
        return Crypt::encryptString((string) json_encode(['f' => $form->ulid, 't' => now()->getTimestamp() - $secondsAgo]));
    }
}

if (! function_exists('publicFormPayload')) {
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function publicFormPayload(PublicForm $form, array $values = [], array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ana Souza',
            'email' => 'ana@example.com',
            'privacy' => '1',
            'values' => $values,
            'website' => '',
            'started' => publicFormTimer($form),
        ], $overrides);
    }
}

if (! function_exists('publicFormSubmit')) {
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $overrides
     */
    function publicFormSubmit(PublicForm $form, array $values = [], array $overrides = []): TestResponse
    {
        return test()->post(route('form_fill.submit', ['token' => $form->public_token]), publicFormPayload($form, $values, $overrides));
    }
}

if (! function_exists('publicFormConfirmationToken')) {
    /**
     * Token do ÚLTIMO link de confirmação enviado (Notification::fake()).
     */
    function publicFormConfirmationToken(): string
    {
        $url = null;

        Notification::assertSentOnDemand(PublicFormConfirmationNotification::class, function (PublicFormConfirmationNotification $notification) use (&$url): bool {
            $url = $notification->confirmationUrl;

            return true;
        });

        preg_match('#/confirmar/([A-Za-z0-9]{48})$#', (string) $url, $matches);

        expect($matches[1] ?? null)->not->toBeNull();

        return $matches[1];
    }
}

if (! function_exists('publicFormConfirm')) {
    function publicFormConfirm(PublicForm $form, string $token): TestResponse
    {
        return test()->post(route('form_fill.confirm', ['token' => $form->public_token, 'confirmation' => $token]));
    }
}

if (! function_exists('publicFormHtmlTemplate')) {
    function publicFormHtmlTemplate(Organization $organization, User $owner): Template
    {
        return templateHtml($organization, $owner, '<h1>Ficha</h1><p>Nome: {{nome}}</p><p>Observação: {{obs}}</p><p>Valor: {{valor}}</p>', [
            ['key' => 'nome', 'label' => 'Nome completo', 'type' => 'text', 'required' => true],
            ['key' => 'obs', 'label' => 'Observação', 'type' => 'long_text', 'required' => false],
            ['key' => 'valor', 'label' => 'Valor', 'type' => 'currency', 'required' => true],
        ], null, 'Ficha de cadastro');
    }
}

if (! function_exists('publicFormPdfTemplate')) {
    /**
     * PDF fixo com um signatário e campo de assinatura (pronto para envio automático).
     */
    function publicFormPdfTemplate(Organization $organization, User $owner, string $work): Template
    {
        return templatePdf($organization, $owner, $work, [
            ['ref' => 'cliente', 'name' => 'Cliente', 'participant_role' => 'signer'],
        ], [
            ['role_ref' => 'cliente', 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06],
        ], 'Termo de adesão');
    }
}
