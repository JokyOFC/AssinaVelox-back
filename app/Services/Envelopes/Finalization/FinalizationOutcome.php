<?php

namespace App\Services\Envelopes\Finalization;

use App\Enums\SignatureStatus;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\VerificationRecord;

/**
 * Resultado de uma execução da finalização. `$steps` diz o que foi **feito nesta execução**
 * (`created`) e o que foi **reaproveitado** de uma execução anterior (`reused`) — é o que
 * torna a idempotência observável em teste e em log.
 */
final readonly class FinalizationOutcome
{
    /**
     * @param  array<string, string>  $steps  etapa => created|reused|skipped
     */
    public function __construct(
        public string $status,
        public ?Envelope $envelope = null,
        public ?DocumentVersion $finalVersion = null,
        public ?VerificationRecord $record = null,
        public SignatureStatus $signatureStatus = SignatureStatus::None,
        public array $steps = [],
    ) {}

    public static function skipped(string $reason, ?Envelope $envelope = null): self
    {
        return new self($reason, $envelope);
    }

    public function completed(): bool
    {
        return $this->status === 'completed' || $this->status === 'already_completed';
    }
}
