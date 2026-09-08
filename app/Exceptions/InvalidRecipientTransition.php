<?php

namespace App\Exceptions;

use App\Enums\RecipientStatus;
use DomainException;

class InvalidRecipientTransition extends DomainException
{
    public function __construct(
        public readonly RecipientStatus $from,
        public readonly RecipientStatus $to,
        public readonly ?string $recipientUlid = null,
    ) {
        parent::__construct(sprintf(
            'Transição de signatário não permitida: %s → %s%s.',
            $from->value,
            $to->value,
            $recipientUlid ? " ({$recipientUlid})" : '',
        ));
    }
}
