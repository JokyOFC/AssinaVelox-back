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
 * - `participant_a3` (Fase 3 §3.4): ao menos um participante assinou com certificado A3
 *   (token/cartão) por um componente local REAL — nunca produzido pelo simulador.
 * - `participant_external` (Fase 3 §3.4): ao menos um participante assinou fora da plataforma
 *   por componente externo sem origem em token comprovada (inclusive o simulador).
 *
 * Nos dois valores da Fase 3 o arquivo pode conter também assinaturas A1 de outros
 * participantes e, por último, a da operadora (`certificate_reference_id` no registro); a
 * lista por assinatura mora em `participant_signatures.signature_status`.
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
    case ParticipantA3 = 'participant_a3';
    case ParticipantExternal = 'participant_external';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Aceite eletrônico com evidências (sem assinatura criptográfica)',
            self::CompanyA1 => 'Assinatura criptográfica da operadora (certificado A1, PAdES)',
            self::ParticipantsA1 => 'Assinaturas criptográficas de participantes com o próprio certificado A1 (PAdES)',
            self::Mixed => 'Assinaturas de participantes com o próprio certificado A1 e assinatura da operadora (PAdES)',
            self::ParticipantA3 => 'Assinatura criptográfica de participante com certificado A3 (token ou cartão) por componente local (PAdES)',
            self::ParticipantExternal => 'Assinatura criptográfica de participante feita fora da plataforma, por componente externo (PAdES)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::None => 'Sem certificado',
            self::CompanyA1 => 'Certificado A1 da operadora',
            self::ParticipantsA1 => 'Certificado A1 dos participantes',
            self::Mixed => 'Certificados A1 dos participantes e da operadora',
            self::ParticipantA3 => 'Certificado A3 de participante',
            self::ParticipantExternal => 'Certificado de participante (componente externo)',
        };
    }

    /** O arquivo final tem assinatura de participante com o próprio certificado? */
    public function hasParticipantSignatures(): bool
    {
        return $this === self::ParticipantsA1 || $this === self::Mixed || $this->hasExternalParticipantSignatures();
    }

    /** Fase 3 §3.4: há assinatura de participante feita fora da plataforma (A3 ou externa)? */
    public function hasExternalParticipantSignatures(): bool
    {
        return $this === self::ParticipantA3 || $this === self::ParticipantExternal;
    }

    /** O arquivo final tem a assinatura da operadora? */
    public function hasOperatorSignature(): bool
    {
        return $this === self::CompanyA1 || $this === self::Mixed;
    }
}
