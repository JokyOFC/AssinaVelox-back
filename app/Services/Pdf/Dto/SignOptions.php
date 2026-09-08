<?php

namespace App\Services\Pdf\Dto;

/**
 * Opções de `pdftool sign` (nunca inclui a passphrase — ela viaja apenas pelo
 * ambiente do processo filho sob o nome informado ao cliente).
 */
final readonly class SignOptions
{
    public function __construct(
        public ?string $fieldName = null,
        public ?string $reason = null,
        public ?string $location = null,
        public ?string $contact = null,
        public ?VisibleStamp $visible = null,
    ) {}

    /**
     * Argumentos de linha de comando correspondentes.
     *
     * @return list<string>
     */
    public function toArguments(): array
    {
        $args = [];
        if ($this->fieldName !== null && $this->fieldName !== '') {
            $args[] = '--field-name';
            $args[] = $this->fieldName;
        }
        if ($this->reason !== null && $this->reason !== '') {
            $args[] = '--reason';
            $args[] = $this->reason;
        }
        if ($this->location !== null && $this->location !== '') {
            $args[] = '--location';
            $args[] = $this->location;
        }
        if ($this->contact !== null && $this->contact !== '') {
            $args[] = '--contact';
            $args[] = $this->contact;
        }
        if ($this->visible !== null) {
            $args[] = '--visible';
            $args[] = $this->visible->toArgument();
        }

        return $args;
    }
}
