<?php

namespace App\Enums;

/**
 * Certificados de teste são sempre rotulados e nunca exibidos como ICP-Brasil.
 */
enum CertificateEnvironment: string
{
    case Test = 'test';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Test => 'Teste',
            self::Production => 'Produção',
        };
    }
}
