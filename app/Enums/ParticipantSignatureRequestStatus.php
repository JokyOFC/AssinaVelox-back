<?php

namespace App\Enums;

/**
 * participant_signature_requests.status — Fase 2 §2.12 (K-A1).
 *
 * | status      | significa                                                                   |
 * |-------------|-----------------------------------------------------------------------------|
 * | `requested` | o participante optou; o certificado ainda não foi enviado                   |
 * | `queued`    | certificado conferido, consentimento dado; material cifrado aguarda o worker |
 * | `applying`  | o worker está gravando a assinatura (sob o lock do envelope)                |
 * | `applied`   | assinatura gravada em todos os documentos do envelope                       |
 * | `failed`    | a última tentativa falhou; o material foi destruído; pode reenviar no prazo  |
 * | `expired`   | o prazo terminou (ou o arquivo final já foi gerado) sem a assinatura         |
 * | `withdrawn` | o participante desistiu antes da aplicação                                  |
 */
enum ParticipantSignatureRequestStatus: string
{
    case Requested = 'requested';
    case Queued = 'queued';
    case Applying = 'applying';
    case Applied = 'applied';
    case Failed = 'failed';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Aguardando o envio do certificado',
            self::Queued => 'Certificado conferido; assinatura na fila',
            self::Applying => 'Aplicando a assinatura',
            self::Applied => 'Assinatura com certificado aplicada',
            self::Failed => 'Falha ao aplicar; envie o certificado de novo',
            self::Expired => 'Prazo encerrado sem a assinatura com certificado',
            self::Withdrawn => 'Participante desistiu de assinar com certificado',
        };
    }

    /** Ainda pode resultar numa assinatura: a finalização espera por ela (dentro do prazo). */
    public function isPending(): bool
    {
        return in_array($this, [self::Requested, self::Queued, self::Applying, self::Failed], true);
    }

    /** Conta para o modo "assinaturas de participantes" do envelope. */
    public function isActive(): bool
    {
        return $this->isPending() || $this === self::Applied;
    }

    /**
     * @return list<string>
     */
    public static function pendingValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isPending()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isActive()),
        ));
    }
}
