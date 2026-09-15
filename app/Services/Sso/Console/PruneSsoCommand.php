<?php

namespace App\Services\Sso\Console;

use App\Services\Sso\SamlLoginFlow;
use Illuminate\Console\Command;

/**
 * Limpa pedidos SAML vencidos e IDs de assertion cuja validade (NotOnOrAfter + margem) já
 * passou — depois disso o toolkit recusaria a assertion por tempo, e o ID não precisa mais
 * ficar guardado para a proteção contra replay.
 */
class PruneSsoCommand extends Command
{
    protected $signature = 'sso:prune';

    protected $description = 'Remove pedidos SAML vencidos e IDs de assertion expirados (login corporativo).';

    public function handle(): int
    {
        $removed = SamlLoginFlow::prune();

        $this->info('Pedidos SAML removidos: '.$removed['requests'].'; assertions expiradas removidas: '.$removed['assertions'].'.');

        return self::SUCCESS;
    }
}
