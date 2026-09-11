<?php

namespace App\Services\PublicForms;

/**
 * Situação do formulário público (docs/fase-2/formulario-publico.md §3).
 *
 *   draft ──publicar──▶ active ◀──retomar── paused
 *     │                   │ └────pausar────▶ │
 *     └──────────revogar──┴──────────────────┴──▶ revoked (definitivo)
 */
enum PublicFormStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Active => 'Publicado',
            self::Paused => 'Pausado',
            self::Revoked => 'Revogado',
        };
    }
}
