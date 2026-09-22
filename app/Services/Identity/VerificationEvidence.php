<?php

namespace App\Services\Identity;

use App\Models\Envelope;
use App\Services\Identity\Models\IdentityVerification;

/**
 * Como a verificação facial com documento aparece para o REMETENTE (Fase 4 §4.1,
 * docs/fase-4/verificacao-facial.md):
 *
 * - {@see self::forEnvelope()}: bloco do detalhe do envelope (`GET envelopes.identity_verifications.index`)
 *   — por participante, cada tentativa com status, provedor, data e número; só para quem tem
 *   `view` no envelope, o mesmo público da página de evidências.
 * - {@see self::pdfLines()}: a linha do PDF de evidências, uma por participante com verificação
 *   vinculada ao aceite. Cita o que o PROVEDOR informou, quando, o identificador dele e o tipo
 *   do documento — nunca imagem, dado lido do documento ou pontuação.
 *
 * Vocabulário (T1): a plataforma enviou as fotos e registrou a resposta; quem comparou foi o
 * provedor, nomeado em toda frase. Resultado do simulador vem rotulado "(simulado)".
 */
final class VerificationEvidence
{
    public const LABEL = 'Verificação facial com documento';

    public const NOTICE = 'Quem comparou as imagens foi o provedor; a plataforma enviou as fotos da captura e registrou a resposta.';

    public function __construct(private readonly IdentityVerifications $verifications) {}

    /**
     * @return array{notice: string, provider_label: string, max_attempts: int, requirements: array<string, true>, items: list<array<string, mixed>>}
     */
    public function forEnvelope(Envelope $envelope): array
    {
        $timezone = $this->timezone($envelope);
        $items = [];

        foreach ($this->rows($envelope) as $verification) {
            $items[] = [
                'id' => $verification->ulid,
                'recipient_id' => $verification->recipient?->ulid,
                'recipient_name' => $verification->recipient?->name,
                'attempt' => $verification->attempt,
                'status' => $verification->status->value,
                'status_label' => $verification->status->label(),
                'provider' => $verification->provider,
                'provider_label' => $this->verifications->providerLabel($verification->provider),
                'simulated' => $verification->isSimulated(),
                'document_type' => $verification->document_type,
                'document_type_label' => IdentityVerifications::documentTypeLabel($verification->document_type),
                'reason_code' => $verification->reason_code,
                'provider_verification_id' => $verification->provider_verification_id,
                'message' => VerificationStep::message($verification, $this->verifications->providerLabel($verification->provider)),
                'submitted_at' => ($verification->submitted_at ?? $verification->created_at)->toIso8601String(),
                'completed_at' => $verification->completed_at?->toIso8601String(),
                'completed_at_local' => $verification->completed_at?->copy()->setTimezone($timezone)->format('d/m/Y H:i'),
                // Vinculada a um aceite gravado (é a que aparece no PDF de evidências).
                'attached' => $verification->signature_acceptance_id !== null,
                // Só tipo e resumo SHA-256 das fotos enviadas — nunca a imagem nem o caminho.
                'captures' => array_map(
                    static fn (array $capture): array => ['kind' => $capture['kind'], 'sha256' => $capture['sha256']],
                    $verification->captures ?? [],
                ),
            ];
        }

        return [
            'notice' => self::NOTICE,
            'provider_label' => $this->verifications->provider()->label(),
            'max_attempts' => IdentityVerifications::maxAttempts(),
            'requirements' => $this->verifications->requirementsForEnvelope($envelope),
            'items' => $items,
        ];
    }

    /**
     * Uma linha por participante com verificação vinculada ao aceite, para o PDF de evidências.
     *
     * @return array<string, string> por ULID do destinatário
     */
    public function pdfLines(Envelope $envelope, ?string $timezone = null): array
    {
        $timezone ??= $this->timezone($envelope);
        $lines = [];

        foreach ($this->rows($envelope, attachedOnly: true) as $verification) {
            $ulid = $verification->recipient?->ulid;

            if (! is_string($ulid)) {
                continue;
            }

            $lines[$ulid] = self::label($verification, $this->verifications->providerLabel($verification->provider), $timezone);
        }

        return $lines;
    }

    /**
     * "Verificação facial com documento: Verifiky informou aprovado em 21/09/2026 14:32
     * (identificador 4821; documento: CNH)".
     */
    public static function label(IdentityVerification $verification, string $providerLabel, string $timezone): string
    {
        $outcome = match ($verification->status) {
            VerificationStatus::Approved => 'aprovado',
            VerificationStatus::Rejected => 'reprovado',
            VerificationStatus::Expired => 'expirado',
            VerificationStatus::Inconclusive => 'inconclusivo',
            VerificationStatus::Pending => 'em análise',
            VerificationStatus::Queued => 'na fila',
        };

        $details = array_filter([
            $verification->provider_verification_id !== null ? 'identificador '.$verification->provider_verification_id : null,
            'documento: '.IdentityVerifications::documentTypeLabel($verification->document_type),
        ]);

        return sprintf(
            '%s: %s informou %s%s%s (%s)',
            self::LABEL,
            $providerLabel,
            $outcome,
            $verification->isSimulated() ? VerificationStep::SIMULATED_SUFFIX : '',
            $verification->completed_at !== null ? ' em '.$verification->completed_at->copy()->setTimezone($timezone)->format('d/m/Y H:i') : '',
            implode('; ', $details),
        );
    }

    /**
     * Tentativas deste envelope, presas ao envelope E à organização dele.
     *
     * @return iterable<IdentityVerification>
     */
    private function rows(Envelope $envelope, bool $attachedOnly = false): iterable
    {
        return IdentityVerification::withoutOrganizationScope()
            ->with('recipient:id,ulid,name')
            ->where('envelope_id', $envelope->getKey())
            ->where('organization_id', $envelope->organization_id)
            ->when($attachedOnly, fn ($query) => $query->whereNotNull('signature_acceptance_id'))
            ->orderBy('id')
            ->get();
    }

    private function timezone(Envelope $envelope): string
    {
        $timezone = (string) ($envelope->organization->timezone ?? '');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : (string) config('app.timezone', 'UTC');
    }
}
