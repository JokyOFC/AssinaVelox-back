<?php

namespace App\Enums;

enum DocumentVersionKind: string
{
    case Original = 'original';
    case Converted = 'converted';
    case Consolidated = 'consolidated';
    case Evidence = 'evidence';
    case Final = 'final';
    // Fase 2 §2.12 (K-A1): base congelada (consolidado + evidências) sobre a qual os
    // participantes assinam com o próprio certificado, e cada revisão incremental assinada.
    case PreSignature = 'pre_signature';
    case SignedIncremental = 'signed_incremental';

    public function label(): string
    {
        return match ($this) {
            self::Original => 'Original',
            self::Converted => 'Convertido',
            self::Consolidated => 'Consolidado',
            self::Evidence => 'Página de evidências',
            self::Final => 'Final',
            self::PreSignature => 'Base para assinaturas com certificado',
            self::SignedIncremental => 'Revisão assinada por participante',
        };
    }
}
