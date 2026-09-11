<?php

namespace App\Enums;

/**
 * O que um `signature_acceptances` registra (coluna `action`, default `sign`).
 *
 * - `sign`: aceite eletrônico do signatário (Fase 1);
 * - `witness`: aceite eletrônico prestado **como testemunha** — mesma prova (OTP, snapshot,
 *   representação visual), declaração própria;
 * - `approve`: aprovação eletrônica do conteúdo, **sem** representação visual de assinatura.
 *
 * Nenhuma dessas ações é assinatura criptográfica (arquitetura §2).
 */
enum AcceptanceAction: string
{
    case Sign = 'sign';
    case Witness = 'witness';
    case Approve = 'approve';

    public function label(): string
    {
        return match ($this) {
            self::Sign => 'Aceite eletrônico',
            self::Witness => 'Aceite eletrônico como testemunha',
            self::Approve => 'Aprovação eletrônica',
        };
    }

    /**
     * Rótulo do botão principal da página pública.
     */
    public function buttonLabel(): string
    {
        return match ($this) {
            self::Sign => 'Assinar documento',
            self::Witness => 'Assinar como testemunha',
            self::Approve => 'Aprovar documento',
        };
    }

    /**
     * Rótulo do participante depois do aceite (badge "Assinado"/"Aprovado").
     */
    public function doneLabel(): string
    {
        return match ($this) {
            self::Sign, self::Witness => 'Assinado',
            self::Approve => 'Aprovado',
        };
    }

    public function requiresVisualSignature(): bool
    {
        return $this !== self::Approve;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
