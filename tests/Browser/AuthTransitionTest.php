<?php

require_once __DIR__.'/Support/BrowserHelpers.php';
require_once __DIR__.'/../Feature/Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Transição de entrada e saída (resources/js/components/auth-transition)
|--------------------------------------------------------------------------
| Entrar e sair trocam a página por baixo de uma cortina animada: o nome de quem entrou
| escrito à mão na entrada, o envelope lacrado na saída. O que só o navegador consegue
| afirmar:
|
|  - a cortina aparece JUNTO com a troca de página (o `AuthTransitionRoot` fica montado de uma
|    página para a outra e percebe `auth.user` mudar), com o texto certo para quem usa leitor
|    de tela;
|  - ela SAI sozinha e devolve a tela — uma cortina presa na frente do painel seria pior do
|    que não ter animação nenhuma;
|  - senha errada não é entrada: nada de cortina;
|  - com "reduzir movimento" ela continua existindo, só que parada e curta.
*/

const AUTH_CURTAIN = '[data-auth-transition-overlay]';

/** Tipo da cortina na tela (`login`, `logout`) ou null quando não há nenhuma. */
function authCurtainKind(object $page): ?string
{
    $kind = $page->script(
        '(() => document.querySelector("'.AUTH_CURTAIN.'")?.getAttribute("data-auth-transition-overlay") ?? null)()',
    );

    return is_string($kind) ? $kind : null;
}

function authCurtainAnnouncement(object $page): string
{
    return (string) $page->script('(() => document.querySelector("p[role=status]")?.textContent ?? "")()');
}

beforeEach(function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Consultoria']);

    $owner->forceFill(['name' => 'Marina Duarte Lopes', 'password' => bcrypt(browserPassword())])->save();

    $this->owner = $owner->fresh();
});

it('entra sob a cortina de assinatura, sai sob o envelope lacrado, e a tela volta nas duas vezes', function () {
    $page = visit('/login');

    browserLogin($page, $this->owner->email);

    browserWaitFor(fn (): bool => authCurtainKind($page) === 'login', 'a cortina de entrada aparecer', 15.0);

    expect(authCurtainAnnouncement($page))->toBe('Acesso autenticado. Boas-vindas, Marina.');

    // O nome escrito à mão é nome e último sobrenome; o painel já está montado por baixo.
    $page->assertSeeIn(AUTH_CURTAIN, 'Marina Lopes')
        ->assertSeeIn(AUTH_CURTAIN, $this->owner->email)
        ->assertPathIs('/dashboard');

    $page->wait(0.9)->screenshot(false, 'auth-transition-entrada');

    browserWaitFor(fn (): bool => authCurtainKind($page) === null, 'a cortina de entrada sair', 15.0);

    expect(authCurtainAnnouncement($page))->toBe('');

    // A tela voltou de verdade: o menu da conta abre e o "Sair" responde ao clique.
    $page->assertSee('Horizonte Consultoria')
        ->click('[data-test=sidebar-menu-button]')
        ->click('[data-test=logout-button]');

    browserWaitFor(fn (): bool => authCurtainKind($page) === 'logout', 'a cortina de saída aparecer', 15.0);

    expect(authCurtainAnnouncement($page))->toBe('Sessão encerrada. Até logo, Marina.');

    $page->wait(1.3)->screenshot(false, 'auth-transition-saida');

    browserWaitFor(fn (): bool => authCurtainKind($page) === null, 'a cortina de saída sair', 15.0);

    $page->assertPathIs('/login')
        ->assertSee('Acesse sua conta AssinaVelox.')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'login depois de sair');
});

it('não toca a cortina quando a senha está errada', function () {
    $page = visit('/login');

    browserLogin($page, $this->owner->email, 'senha-errada');

    $page->assertSee('Estas credenciais não correspondem aos nossos registros.');

    expect(authCurtainKind($page))->toBeNull()
        ->and(authCurtainAnnouncement($page))->toBe('');
});

it('com "reduzir movimento" a cena aparece pronta, sem escrita nem furo, e sai num fade curto', function () {
    $page = visit('/login', ['reducedMotion' => 'reduce']);

    browserLogin($page, $this->owner->email);

    browserWaitFor(fn (): bool => authCurtainKind($page) === 'login', 'a cortina de entrada aparecer', 15.0);

    // Pronta desde o primeiro quadro: tinta toda no papel, confirmação visível, nenhuma máscara.
    $state = browserWaitFor(function () use ($page): ?array {
        $state = $page->script('(() => {
            const curtain = document.querySelector("'.AUTH_CURTAIN.'");
            const flourish = curtain?.querySelector("path[style*=\"stroke-dasharray\"]");

            return curtain?.dataset.motion ? {
                motion: curtain.dataset.motion,
                inkLeft: flourish ? parseFloat(flourish.style.strokeDashoffset || "1") : null,
                mask: curtain.style.maskImage || "",
            } : null;
        })()');

        return is_array($state) ? $state : null;
    }, 'a cortina dizer em que modo está', 10.0);

    expect($state['motion'])->toBe('reduced')
        ->and($state['inkLeft'])->toEqual(0)
        ->and($state['mask'])->toBe('');

    $page->assertSeeIn(AUTH_CURTAIN, 'Acesso autenticado');

    browserWaitFor(fn (): bool => authCurtainKind($page) === null, 'a cortina sair', 15.0);

    $page->assertSee('Horizonte Consultoria')->assertNoJavascriptErrors();
});

it('um clique na cortina pula direto para a saída', function () {
    $page = visit('/login');

    browserLogin($page, $this->owner->email);

    browserWaitFor(fn (): bool => authCurtainKind($page) === 'login', 'a cortina de entrada aparecer', 15.0);

    $startedAt = microtime(true);

    $page->script('(() => document.querySelector("'.AUTH_CURTAIN.'").dispatchEvent(new PointerEvent("pointerdown", { bubbles: true })))()');

    browserWaitFor(fn (): bool => authCurtainKind($page) === null, 'a cortina sair depois do clique', 15.0);

    // Sem o pulo a cena leva ~2,3 s; com ele, só os 0,5 s da abertura (folga para a máquina).
    expect(microtime(true) - $startedAt)->toBeLessThan(1.6);

    $page->assertSee('Horizonte Consultoria')->assertNoJavascriptErrors();
});
