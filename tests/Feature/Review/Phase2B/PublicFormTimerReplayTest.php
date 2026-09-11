<?php

use App\Models\PublicFormSubmission;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/../../Phase2/PublicForms/Support/PublicFormHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda B — formulário público: carimbo de tempo reutilizável
|--------------------------------------------------------------------------
| Sem CAPTCHA, o "tempo mínimo de preenchimento" é uma das duas barreiras contra robôs
| (docs/fase-2/formulario-publico.md §6). `FillTimer::check()`
| (app/Services/PublicForms/FillTimer.php:37–64) só confere que o carimbo cifrado é deste
| formulário e tem entre `min_fill_seconds` e `max_fill_minutes` (padrão 360 min). Nada o
| amarra a um envio: não há nonce, marca de uso nem vínculo com a sessão.
|
| Um robô faz UM GET, espera 3 s e reaproveita o mesmo carimbo em todos os envios das
| próximas 6 horas — a barreira de tempo deixa de existir depois do primeiro. Sobram só os
| limites por IP (contornáveis trocando de IP), e cada envio aceito dispara um e-mail de
| confirmação da plataforma para um endereço escolhido pelo robô.
*/

beforeEach(function () {
    Notification::fake();
    RateLimiter::clear('public-form-ip:127.0.0.1');
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner(['name' => 'Imobiliária Aurora']);
    publicFormsEnable($this->organization);
    $this->form = publicFormFrom($this->organization, $this->owner, publicFormHtmlTemplate($this->organization, $this->owner));
    $this->values = ['nome' => 'Ana Souza', 'obs' => 'Apto 12', 'valor' => '1.500,00'];
});

test('o carimbo de tempo mínimo emitido pela página vale para um único envio', function () {
    $timer = $this->get(route('form_fill.show', ['token' => $this->form->public_token]))
        ->viewData('page')['props']['antiabuse']['timer'];

    $this->travel(5)->seconds();

    publicFormSubmit($this->form, $this->values, ['started' => $timer, 'email' => 'vitima1@example.com'])
        ->assertSessionHasNoErrors();

    // Mesmo carimbo, outro envio, sem abrir a página de novo.
    publicFormSubmit($this->form, $this->values, ['started' => $timer, 'email' => 'vitima2@example.com'])
        ->assertSessionHasErrors('form');

    expect(PublicFormSubmission::withoutOrganizationScope()->count())->toBe(1);
});
