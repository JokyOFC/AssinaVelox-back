<?php

use App\Services\Organizations\NotificationPreferences;
use Symfony\Component\Finder\Finder;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão final (experiência) — metade dos interruptores de Notificações não liga nada
|--------------------------------------------------------------------------
| `/configuracoes/notificacoes` oferece sete eventos (ROUTES §2.14). Só três chegam a
| produzir notificação: `envelope_completed` (CompletionNotifier), `recipient_refused`
| (EnvelopeNotifications) e `envelope_expiring` (ExpireEnvelopes / Sender…Notification).
|
| Os outros quatro não têm produtor nenhum — nem Notification, nem serviço, nem comando
| agendado em routes/console.php:
|
|   recipient_signed     "Signatário assinou · A cada assinatura concluída"  (padrão: e-mail + app)
|   daily_digest         "Resumo diário de pendências · Um e-mail por dia útil" (padrão: e-mail)
|   invitation_accepted  "Convite de usuário aceito · Novo membro entrou na conta"
|   product_news         "Novidades do produto"
|
| O usuário liga o interruptor, recebe o toast "Preferências salvas." e nunca recebe nada.
| No caso do `daily_digest` a tela é ainda mais específica: o rodapé promete horário —
| "Resumo diário de pendências enviado às 08:00 (America/Sao_Paulo)" — para um e-mail que
| nenhum `Schedule::command` dispara.
|
| `recipient_signed` é o mais grave: é a notificação que o remetente mais espera do
| produto ("fulano assinou") e vem LIGADA por padrão nos dois canais.
|
| Isso não é omissão de Fase 2 declarada: o produto marca "Fase 2" explicitamente em toda
| funcionalidade adiada (Logo, SSO, restrição por IP, SMS, WhatsApp, lembretes
| automáticos, Modelos, API). Estes quatro aparecem como recursos vigentes.
*/

it('todo evento do catálogo de notificações tem quem o produza', function () {
    $finder = Finder::create()
        ->files()
        ->in([app_path(), base_path('routes')])
        ->name('*.php');

    $sources = [];

    foreach ($finder as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        // O próprio catálogo e o controller da tela não contam como produtor.
        if (in_array($relative, [
            'Services/Organizations/NotificationPreferences.php',
            'Http/Controllers/Settings/NotificationController.php',
            'Http/Requests/Settings/UpdateNotificationPreferencesRequest.php',
        ], true)) {
            continue;
        }

        $sources[$relative] = $file->getContents();
    }

    $orphans = [];

    foreach (NotificationPreferences::catalog() as $event => $definition) {
        $found = false;

        foreach ($sources as $code) {
            if (str_contains($code, "'".$event."'") || str_contains($code, '"'.$event.'"')) {
                $found = true;
                break;
            }
        }

        if (! $found) {
            $orphans[] = $event.' ("'.$definition['label'].'", padrão: '.implode('+', $definition['default']).')';
        }
    }

    expect($orphans)->toBe(
        [],
        "Eventos oferecidos na tela de Notificações que nada dispara:\n".implode("\n", $orphans)
    );
});

it('a tela promete horário para um resumo diário que nenhum agendamento envia', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    actingAsMember($owner, $organization);

    $props = $this->withoutVite()
        ->get(route('settings.notifications'))
        ->assertOk()
        ->viewData('page')['props'];

    // A promessa está na tela...
    expect($props['digest_time_label'])->toContain('08:00');

    // ...e o agendamento que a cumpriria não existe.
    $schedule = (string) file_get_contents(base_path('routes/console.php'));

    expect($schedule)->toContain('digest');
});
