<?php

namespace App\Enums;

/**
 * verification_records.signature_status — assinatura criptográfica da empresa operadora.
 * `company_a1` identifica a empresa titular do certificado; NÃO é assinatura pessoal ICP-Brasil.
 */
enum SignatureStatus: string
{
    case None = 'none';
    case CompanyA1 = 'company_a1';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Aceite eletrônico com evidências (sem assinatura criptográfica)',
            self::CompanyA1 => 'Assinatura criptográfica da operadora (certificado A1, PAdES)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::None => 'Sem certificado',
            self::CompanyA1 => 'Certificado A1 da operadora',
        };
    }
}
