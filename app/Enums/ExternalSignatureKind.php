<?php

namespace App\Enums;

/**
 * `participant_signatures.signature_status` para assinaturas feitas FORA do servidor
 * (Fase 3 §3.4, roadmap T1). Nulo na coluna = A1 por arquivo (Fase 2 §2.12).
 *
 * - `participant_a3`: certificado A3 (token/cartão) por um componente local REAL habilitado
 *   E o certificado declara o tipo A3 na política. Hoje nenhum componente real está
 *   habilitado (produção desligada), então este valor não é produzido.
 * - `participant_external`: a assinatura veio de fora do servidor, mas a origem em token
 *   não está comprovada — inclusive tudo o que o simulador produz. Nunca é chamado de A3.
 */
enum ExternalSignatureKind: string
{
    case ParticipantA3 = 'participant_a3';
    case ParticipantExternal = 'participant_external';

    public function label(): string
    {
        return match ($this) {
            self::ParticipantA3 => 'Assinatura com certificado A3 (token ou cartão) por componente local',
            self::ParticipantExternal => 'Assinatura com certificado do participante feita fora da plataforma, por componente externo',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::ParticipantA3 => 'Certificado A3 do participante',
            self::ParticipantExternal => 'Certificado do participante (componente externo)',
        };
    }

    public function envelopeStatus(): SignatureStatus
    {
        return match ($this) {
            self::ParticipantA3 => SignatureStatus::ParticipantA3,
            self::ParticipantExternal => SignatureStatus::ParticipantExternal,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
