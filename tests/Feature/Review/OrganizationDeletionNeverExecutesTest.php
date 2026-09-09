<?php

use App\Models\Organization;
use App\Support\OrganizationSettings;
use Illuminate\Console\Scheduling\Schedule;
use Symfony\Component\Finder\Finder;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão final (coerência) — a exclusão da conta é só uma data guardada
|--------------------------------------------------------------------------
| `/configuracoes` (Geral e segurança) tem a zona de perigo:
|
|   "Excluir conta — Remove todos os usuários e documentos após 30 dias.
|    Documentos assinados continuam válidos para quem os baixou."
|   [Solicitar exclusão]
|
| Clicar grava `settings.deletion_requested_at` e responde "Exclusão agendada para
| dd/mm/aaaa. Você pode cancelar até essa data." (GeneralController::requestDeletion,
| linha 111-117). É tudo. O comentário logo acima da linha 113 admite o buraco —
| "A exclusão efetiva (job após o período de carência) … pertencem ao incremento de
| cobrança" — e o incremento de cobrança foi entregue sem ele:
|
|  - não há comando em app/Console/Commands (`envelopes:expire`, `billing:dunning`,
|    `audit:checkpoint`, `assinavelox:health`, `storage:verify`, `assinavelox:doctor`,
|    `pdftool:selftest` — nenhum apaga organização);
|  - não há `Schedule::command(...)` em routes/console.php para isso;
|  - `deletion_requested_at` só é LIDO para desenhar a tela e a linha do painel interno.
|
| Passados os 30 dias, nada acontece: a organização, os documentos, os PDFs no disco e os
| dados pessoais dos signatários continuam no ar, e a tela continua exibindo a data que já
| passou.
|
| Isso não é uma tela de Fase 2 — é um compromisso assumido em três documentos:
|
|   ROUTES_AND_PAGES.md §2.12   "Marca `organizations.deletion_requested_at`;
|                                **job apaga após 30 dias**; cancela assinatura."
|   ROUTES_AND_PAGES.md Q23     "exclusão da org apaga após 30 dias"
|   docs/juridico/politica-de-privacidade.md §"Exclusão da Organização"
|                               "Ao término, os dados são apagados dos sistemas ativos"
|
| O último é uma promessa de eliminação feita ao titular dos dados na política de
| privacidade publicada — o produto não a cumpre.
*/

it('a organização é efetivamente excluída depois do prazo de carência', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    actingAsMember($owner, $organization);

    $this->withoutVite()
        ->withSession(confirmedPasswordSession($organization))
        ->post(route('settings.organization.destroy'))
        ->assertRedirect();

    $organization->refresh();

    $scheduled = OrganizationSettings::of($organization)->deletionScheduledFor();
    expect($scheduled)->not->toBeNull();

    // Passa o prazo prometido na tela.
    $this->travelTo($scheduled->copy()->addDay());

    /*
    | AJUSTE DE TESTE (revisão final): a asserção original era `$this->artisan('schedule:run')`.
    | `Schedule::command()` não roda o comando dentro do processo do agendador — o evento é
    | executado como `php artisan <comando>` em um PROCESSO SEPARADO
    | (Illuminate\Console\Scheduling\Event::execute). Esse processo não enxerga o SQLite
    | `:memory:` do teste, então a asserção nunca poderia passar, corrigido ou não o defeito.
    |
    | A garantia é a mesma, verificada nas duas metades que de fato existem: o comando está
    | agendado (o cron chega até ele) e o comando apaga (a promessa da tela e da política de
    | privacidade se cumpre).
    */
    $scheduledCommands = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)
        ->filter(fn (string $command): bool => str_contains($command, 'organizations:purge'))
        ->values()
        ->all();

    expect($scheduledCommands)->not->toBeEmpty('Nada no agendamento apaga a organização.');

    $this->artisan('organizations:purge')->assertExitCode(0);

    expect(Organization::withTrashed()->whereKey($organization->getKey())->exists())
        ->toBeFalse('A organização continua no banco 31 dias depois da exclusão solicitada.');
});

it('existe alguma rotina que consome deletion_requested_at para apagar dados', function () {
    $finder = Finder::create()
        ->files()
        ->in([app_path('Console'), app_path('Jobs'), app_path('Services'), base_path('routes')])
        ->name('*.php');

    $consumers = [];

    foreach ($finder as $file) {
        $code = $file->getContents();

        if (str_contains($code, 'deletion_requested_at') || str_contains($code, 'deletionScheduledFor')) {
            $consumers[] = str_replace('\\', '/', $file->getRelativePathname());
        }
    }

    expect($consumers)->not->toBe(
        [],
        'Nenhum comando, job ou serviço lê deletion_requested_at: a exclusão agendada nunca acontece.'
    );
});

it('a política de privacidade promete a eliminação — o controle do achado', function () {
    $policy = (string) file_get_contents(base_path('docs/juridico/politica-de-privacidade.md'));

    expect($policy)->toContain('os dados são apagados dos sistemas ativos');
});
