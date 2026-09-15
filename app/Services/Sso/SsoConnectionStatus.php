<?php

namespace App\Services\Sso;

/**
 * Situação da conexão (docs/fase-3/sso.md §3). Só `active` autentica; `draft` só aceita o
 * "Testar conexão" de quem administra; `disabled` não faz nada — e desligar nunca tranca a
 * organização: a exigência de SSO só vale com a conexão ativa.
 */
enum SsoConnectionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Em configuração',
            self::Active => 'Ativa',
            self::Disabled => 'Desativada',
        };
    }
}
