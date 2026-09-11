<?php

use App\Models\PublicFormSubmission;
use App\Services\PublicForms\FillTimer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/Support/PublicFormHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2, onda C (K-RET, item 5) — marcas de uso único do carimbo no BANCO
|--------------------------------------------------------------------------
| Antes as marcas viviam no cache: `cache:clear` reabria os carimbos por até 6 h
| (docs/implantacao.md §5, ressalva removida). Agora é um INSERT com unicidade.
*/

beforeEach(function () {
    Notification::fake();
    RateLimiter::clear('public-form-ip:127.0.0.1');
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner(['name' => 'Imobiliária Aurora']);
    publicFormsEnable($this->organization);
    $this->form = publicFormFrom($this->organization, $this->owner, publicFormHtmlTemplate($this->organization, $this->owner));
    $this->values = ['nome' => 'Ana Souza', 'obs' => 'Apto 12', 'valor' => '1.500,00'];
});

test('esvaziar o cache não reabre o carimbo já usado', function () {
    $timer = $this->get(route('form_fill.show', ['token' => $this->form->public_token]))
        ->viewData('page')['props']['antiabuse']['timer'];

    $this->travel(5)->seconds();

    publicFormSubmit($this->form, $this->values, ['started' => $timer, 'email' => 'primeira@example.com'])
        ->assertSessionHasNoErrors();

    expect(DB::table(FillTimer::TABLE)->count())->toBe(1);

    Cache::flush();
    RateLimiter::clear('public-form-ip:127.0.0.1');

    publicFormSubmit($this->form, $this->values, ['started' => $timer, 'email' => 'segunda@example.com'])
        ->assertSessionHasErrors(['form' => 'Esta página já foi usada para um envio. Para mandar outra resposta, recarregue a página e preencha de novo.']);

    expect(PublicFormSubmission::withoutOrganizationScope()->count())->toBe(1);
});

test('o consumo é atômico e o banco guarda só o resumo, nunca o carimbo', function () {
    $timer = publicFormTimer($this->form, 30);
    $fillTimer = app(FillTimer::class);

    expect($fillTimer->check($this->form, $timer))->toBeNull()
        ->and($fillTimer->consume($this->form, $timer))->toBeTrue()
        ->and($fillTimer->consume($this->form, $timer))->toBeFalse()
        ->and($fillTimer->check($this->form, $timer))->toBe(FillTimer::USED);

    $row = (array) DB::table(FillTimer::TABLE)->first();

    expect($row['token_digest'])->toMatch('/^[a-f0-9]{64}$/')
        ->and(json_encode($row))->not->toContain($timer);

    // Carimbo inválido nunca grava marca.
    expect($fillTimer->consume($this->form, 'lixo'))->toBeFalse()
        ->and(DB::table(FillTimer::TABLE)->count())->toBe(1);
});

test('a limpeza agendada apaga só as marcas vencidas', function () {
    $fillTimer = app(FillTimer::class);
    $fillTimer->consume($this->form, publicFormTimer($this->form, 30));

    $this->artisan('public-forms:prune-timer-marks')->assertSuccessful();
    expect(DB::table(FillTimer::TABLE)->count())->toBe(1);

    $this->travel(7)->hours();

    $this->artisan('public-forms:prune-timer-marks')->expectsOutputToContain('1')->assertSuccessful();
    expect(DB::table(FillTimer::TABLE)->count())->toBe(0);
});
