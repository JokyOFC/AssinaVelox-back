<?php

namespace App\Exceptions;

use App\Enums\EnvelopeStatus;
use DomainException;

class InvalidEnvelopeTransition extends DomainException
{
    public function __construct(
        public readonly EnvelopeStatus $from,
        public readonly EnvelopeStatus $to,
        public readonly ?string $envelopeUlid = null,
    ) {
        parent::__construct(sprintf(
            'Transição de envelope não permitida: %s → %s%s.',
            $from->value,
            $to->value,
            $envelopeUlid ? " ({$envelopeUlid})" : '',
        ));
    }
}
