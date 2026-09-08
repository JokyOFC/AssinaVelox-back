<?php

namespace App\Enums;

enum CertificateKind: string
{
    case CompanyA1 = 'company_a1';

    public function label(): string
    {
        return match ($this) {
            self::CompanyA1 => 'Certificado A1 (empresa)',
        };
    }
}
