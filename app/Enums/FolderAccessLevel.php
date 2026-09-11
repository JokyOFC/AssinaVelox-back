<?php

namespace App\Enums;

/**
 * Nível de acesso concedido a uma pasta (folder_permissions.level).
 *
 *  - view:   vê, baixa e duplica os documentos da pasta;
 *  - manage: também edita, envia, move, cancela e exclui os documentos da pasta.
 *
 * Acesso por pasta só amplia a visibilidade de quem NÃO tem `view_all_envelopes`; nunca
 * concede permissões de conta.
 */
enum FolderAccessLevel: string
{
    case View = 'view';
    case Manage = 'manage';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Visualizar',
            self::Manage => 'Gerenciar',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::View => 1,
            self::Manage => 2,
        };
    }

    public function covers(self $other): bool
    {
        return $this->weight() >= $other->weight();
    }

    public static function max(?self $a, ?self $b): ?self
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a->covers($b) ? $a : $b;
    }
}
