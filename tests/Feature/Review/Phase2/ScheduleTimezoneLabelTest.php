<?php

use Illuminate\Support\Carbon;

require_once __DIR__.'/../../Phase2/Reminders/Support/ReminderHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 2 onda A (clareza) — fuso exibido como identificador IANA
|--------------------------------------------------------------------------
| O envio agendado e os lembretes explicam "quando" com o identificador técnico do fuso:
|   - flash ao agendar: "Envio agendado para 15/09/2026 às 09:00 (America/Sao_Paulo)."
|     (app/Http/Controllers/Envelopes/EnvelopeSendController.php:102);
|   - cartão do passo 4: "Escolha data e hora no fuso da organização (America/Sao_Paulo)."
|     e "Horário de America/Sao_Paulo." (resources/js/components/envelopes/schedule-send-card.tsx:120-121);
|   - lembretes: "Enviados entre 8h e 20h (America/Sao_Paulo)"
|     (resources/js/components/envelopes/reminders-control.tsx:143-145).
| O projeto já tem o rótulo PT-BR em App\Support\Timezones ("Brasília (GMT-3) — …"), usado em
| Configurações › Geral. Um leigo não lê "America/Sao_Paulo" como "horário de Brasília".
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->provider = fakeEmailProvider();
    remTravel('2026-09-14 13:00:00');
    remRegisterRoutes();
});

afterEach(fn () => Carbon::setTestNow());

it('confirma o agendamento com o fuso em PT-BR, não com o identificador IANA', function () {
    [$envelope, $organization, $owner] = remReadyForSchedule();
    actingAsMember($owner, $organization);

    $this->post(route('envelopes.schedule', $envelope), ['scheduled_for' => '2026-09-15T09:00'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $message = (string) session('success');

    expect(str_contains($message, '15/09/2026'))->toBeTrue("Flash inesperado: {$message}")
        ->and(str_contains($message, 'America/'))->toBeFalse("Flash exibido ao remetente: {$message}");
});

it('o front não mostra o identificador IANA cru no agendamento nem nos lembretes', function (string $relative, string $raw) {
    $source = (string) file_get_contents(base_path($relative));

    expect(str_contains($source, $raw))->toBeFalse("{$relative} interpola o identificador do fuso sem rótulo: {$raw}");
})->with([
    'agendamento (novo)' => ['resources/js/components/envelopes/schedule-send-card.tsx', 'fuso da organização (${timezone})'],
    'agendamento (marcado)' => ['resources/js/components/envelopes/schedule-send-card.tsx', 'Horário de ${timezone}'],
    'lembretes' => ['resources/js/components/envelopes/reminders-control.tsx', '{reminders.timezone}'],
]);
