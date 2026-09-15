<?php

namespace App\Services\CloudImport;

/**
 * Provedores de nuvem aceitos na importação (Fase 3 §3.9, G-CONN). Lista FECHADA: o valor
 * vem de rota/formulário e nunca vira nome de classe, caminho ou URL.
 */
enum CloudProvider: string
{
    case GoogleDrive = 'google_drive';
    case Dropbox = 'dropbox';

    public function label(): string
    {
        return match ($this) {
            self::GoogleDrive => 'Google Drive',
            self::Dropbox => 'Dropbox',
        };
    }
}
