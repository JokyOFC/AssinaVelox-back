<?php

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Notifications\Organizations\ProductNewsNotification;
use App\Services\Organizations\NotificationPreferences;
use Illuminate\Console\Command;

/**
 * Anúncio de "Novidades do produto" (ROUTES §2.14, evento `product_news`).
 *
 * É o único evento do catálogo que não tem — nem pode ter — gatilho automático: um
 * lançamento é uma decisão editorial. Sem este comando o interruptor da tela de
 * Notificações não ligava nada; com ele, a preferência de cada pessoa governa quem recebe.
 *
 * O canal `mail` é travado no catálogo (novidade de produto não é mensagem transacional e a
 * Fase 1 não coleta consentimento de marketing), então na prática o anúncio chega pelo sino
 * do app. `--dry-run` conta os destinatários sem enviar nada.
 */
class ProductNewsCommand extends Command
{
    protected $signature = 'notifications:product-news
        {title : título do anúncio}
        {body : texto do anúncio}
        {--url= : link opcional "Saiba mais"}
        {--dry-run : conta os destinatários sem enviar}';

    protected $description = 'Envia um anúncio de novidades a quem manteve a preferência ligada.';

    public function handle(NotificationPreferences $preferences): int
    {
        $title = trim((string) $this->argument('title'));
        $body = trim((string) $this->argument('body'));
        $urlOption = $this->option('url');
        $url = is_string($urlOption) && $urlOption !== '' ? $urlOption : null;

        if ($title === '' || $body === '') {
            $this->error('Título e texto são obrigatórios.');

            return self::FAILURE;
        }

        $sent = 0;

        foreach (Membership::query()->where('status', MembershipStatus::Active->value)->with('user')->cursor() as $membership) {
            $channels = array_values(array_filter([
                $preferences->wants($membership, 'product_news', 'mail') ? 'mail' : null,
                $preferences->wants($membership, 'product_news', 'database') ? 'database' : null,
            ]));

            if ($channels === []) {
                continue;
            }

            if (! $this->option('dry-run')) {
                $membership->user->notify(
                    (new ProductNewsNotification($title, $body, $url))->restrictChannels($channels),
                );
            }

            $sent++;
        }

        $this->info(sprintf(
            $this->option('dry-run') ? '%d destinatário(s) receberiam o anúncio.' : 'Anúncio enviado a %d destinatário(s).',
            $sent,
        ));

        return self::SUCCESS;
    }
}
