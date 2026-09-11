<?php

namespace App\Enums;

/**
 * verification_records.signature_status — o que o arquivo final carrega de assinatura
 * criptográfica (arquitetura §2, roadmap T1).
 *
 * - `company_a1` identifica a empresa titular do certificado da OPERADORA; NÃO é assinatura
 *   pessoal ICP-Brasil de participante algum.
 * - `participants_a1` (Fase 2 §2.12): um ou mais participantes assinaram com o PRÓPRIO
 *   certificado A1, e a operadora não assinou (certificado dela não configurado).
 * - `mixed` (Fase 2 §2.12): participantes com o próprio certificado **e**, por último, a
 *   operadora.
 *
 * Nenhum desses valores é "aceite eletrônico": o aceite continua existindo para todos os
 * participantes, com ou sem certificado, e é registrado à parte.
 */
enum SignatureStatus: string
{
    case None = 'none';
    case CompanyA1 = 'company_a1';
    case ParticipantsA1 = 'participants_a1';
    case Mixed = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Aceite eletrônico com evidências (sem assinatura criptográfica)',
            self::CompanyA1 => 'Assinatura criptográfica da operadora (certificado A1, PAdES)',
            self::ParticipantsA1 => 'Assinaturas criptográficas de participantes com o próprio certificado A1 (PAdES)',
            self::Mixed => 'Assinaturas de participantes com o próprio certificado A1 e assinatura da operadora (PAdES)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::None => 'Sem certificado',
            self::CompanyA1 => 'Certificado A1 da operadora',
            self::ParticipantsA1 => 'Certificado A1 dos participantes',
            self::Mixed => 'Certificados A1 dos participantes e da operadora',
        };
    }

    /** O arquivo final tem assinatura de participante com o próprio certificado? */
    public function hasParticipantSignatures(): bool
    {
        return $this === self::ParticipantsA1 || $this === self::Mixed;
    }

    /** O arquivo final tem a assinatura da operadora? */
    public function hasOperatorSignature(): bool
    {
        return $this === self::CompanyA1 || $this === self::Mixed;
    }
}
