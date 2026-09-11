<?php

namespace App\Services\Verification;

use App\Models\Envelope;
use App\Models\RetentionDeletion;

/**
 * Envelope TRANSITÓRIO (nunca salvo) que representa, na verificação pública, o código de um
 * envelope já excluído pela política de retenção (Fase 2 §2.19 — decisão da §6 de
 * docs/fase-2/retencao-e-preservacao.md).
 *
 * Existe para que a verificação continue passando por {@see PublicVerification::lookup()} e
 * pelo VerificationResultResource sem mudar o controller: `result()` e `checkHash()`
 * reconhecem esta classe e respondem só o que {@see RetentionTombstones} permite.
 */
final class PurgedEnvelope extends Envelope
{
    protected $table = 'envelopes';

    public ?RetentionDeletion $retentionDeletion = null;

    public static function fromDeletion(RetentionDeletion $deletion): self
    {
        $envelope = new self;
        $envelope->retentionDeletion = $deletion;
        $envelope->setAttribute('verification_code', $deletion->verification_code);
        $envelope->exists = false;

        return $envelope;
    }

    public function save(array $options = []): bool
    {
        throw new \LogicException('PurgedEnvelope é só leitura.');
    }
}
