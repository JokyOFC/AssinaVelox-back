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
 * - `participant_govbr` (Fase 3 §3.5): o participante devolveu o documento assinado no portal
 *   gov.br e a cadeia do certificado foi validada até uma âncora gov.br fixada por impressão
 *   digital — nível "avançada" da Lei 14.063/2020, nunca "qualificada" nem ICP-Brasil.
 * - `participant_external_unverified` (Fase 3 §3.5): documento devolvido com assinatura
 *   íntegra sobre a versão reservada, mas sem cadeia verificada — NUNCA dito gov.br.
 *
 * Com mais de um meio externo no mesmo arquivo (ex.: componente local E devolução gov.br), o
 * valor do registro é `participant_external` (o mais genérico — "feita fora da plataforma"),
 * e a lista por assinatura traz o meio de cada uma (ParticipantSignatureStage::statusForDocument).
 *
 * Nos valores da Fase 3 o arquivo pode conter também assinaturas A1 de outros
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
    case ParticipantGovBr = 'participant_govbr';
    case ParticipantExternalUnverified = 'participant_external_unverified';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Aceite eletrônico com evidências (sem assinatura criptográfica)',
            self::CompanyA1 => 'Assinatura criptográfica da operadora (certificado A1, PAdES)',
            self::ParticipantsA1 => 'Assinaturas criptográficas de participantes com o próprio certificado A1 (PAdES)',
            self::Mixed => 'Assinaturas de participantes com o próprio certificado A1 e assinatura da operadora (PAdES)',
            self::ParticipantA3 => 'Assinatura criptográfica de participante com certificado A3 (token ou cartão) por componente local (PAdES)',
            self::ParticipantExternal => 'Assinatura criptográfica de participante feita fora da plataforma, por componente externo (PAdES)',
            self::ParticipantGovBr => 'Assinatura gov.br (avançada) de participante, devolvida pelo portal e conferida contra a cadeia gov.br fixada (PAdES)',
            self::ParticipantExternalUnverified => 'Assinatura digital de terceiro devolvida pelo participante, cadeia não verificada (PAdES)',
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
            self::ParticipantGovBr => 'Assinatura gov.br (avançada) de participante',
            self::ParticipantExternalUnverified => 'Assinatura de terceiro, cadeia não verificada',
        };
    }

    /** O arquivo final tem assinatura de participante com o próprio certificado? */
    public function hasParticipantSignatures(): bool
    {
        return $this === self::ParticipantsA1 || $this === self::Mixed || $this->hasExternalParticipantSignatures();
    }

    /** Fase 3 §3.4/§3.5: há assinatura de participante feita fora da plataforma (A3, externa ou devolvida)? */
    public function hasExternalParticipantSignatures(): bool
    {
        return in_array($this, [self::ParticipantA3, self::ParticipantExternal, self::ParticipantGovBr, self::ParticipantExternalUnverified], true);
    }

    /** Fase 3 §3.5: há documento devolvido pelo portal gov.br (com ou sem cadeia verificada)? */
    public function isGovBrReturn(): bool
    {
        return $this === self::ParticipantGovBr || $this === self::ParticipantExternalUnverified;
    }

    /** O arquivo final tem a assinatura da operadora? */
    public function hasOperatorSignature(): bool
    {
        return $this === self::CompanyA1 || $this === self::Mixed;
    }
}
