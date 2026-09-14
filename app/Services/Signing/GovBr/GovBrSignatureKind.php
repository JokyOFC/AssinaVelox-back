<?php

namespace App\Services\Signing\GovBr;

/**
 * Rótulo HONESTO de uma assinatura devolvida pelo participante (roadmap T1; P3-GOV).
 *
 * Os dois valores são candidatos a `verification_records.signature_status` (roadmap §3.5
 * prevê `participant_govbr`); entram em `App\Enums\SignatureStatus` quando a finalização for
 * integrada (docs/fase-3/gov-br.md §8). Até lá vivem em `external_signature_requests`.
 *
 * - `participant_govbr` SÓ quando a cadeia do certificado foi validada até uma âncora gov.br
 *   configurada por impressão digital e o vínculo com a revisão reservada conferiu. Nível:
 *   assinatura eletrônica do tipo "avançada" da Lei 14.063/2020 — o rótulo diz "(avançada)"
 *   e nunca "qualificada" nem "ICP-Brasil".
 * - `participant_external_unverified` em qualquer outro caso aceito: a assinatura é íntegra e
 *   cobre a revisão reservada, mas ninguém confirmou de quem é a cadeia. NUNCA diz "gov.br".
 */
enum GovBrSignatureKind: string
{
    case ParticipantGovBr = 'participant_govbr';
    case ParticipantExternalUnverified = 'participant_external_unverified';

    public function label(): string
    {
        return match ($this) {
            self::ParticipantGovBr => 'Assinatura gov.br (avançada)',
            self::ParticipantExternalUnverified => 'Assinatura digital de terceiro, cadeia não verificada',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ParticipantGovBr => 'Assinatura feita pelo participante no portal gov.br, conferida contra a cadeia gov.br fixada nesta plataforma. Não é assinatura com certificado ICP-Brasil.',
            self::ParticipantExternalUnverified => 'O participante devolveu o documento com uma assinatura digital íntegra sobre a versão entregue, mas a plataforma não verificou a cadeia do certificado. Não se afirma que seja uma assinatura gov.br.',
        };
    }

    public static function for(bool $anchorsConfigured, bool $trusted): self
    {
        return $anchorsConfigured && $trusted ? self::ParticipantGovBr : self::ParticipantExternalUnverified;
    }
}
