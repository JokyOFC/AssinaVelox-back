<?php

namespace App\Enums;

/**
 * Representação visual da assinatura. Não prova nada por si (ver docs/arquitetura.md §2).
 */
enum SignatureKind: string
{
    case Drawn = 'drawn';
    case Typed = 'typed';
    case Uploaded = 'uploaded';

    public function label(): string
    {
        return match ($this) {
            self::Drawn => 'Desenhada',
            self::Typed => 'Digitada',
            self::Uploaded => 'Imagem enviada',
        };
    }
}
