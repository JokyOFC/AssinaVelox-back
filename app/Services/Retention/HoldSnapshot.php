<?php

namespace App\Services\Retention;

use App\Models\LegalHold;

/**
 * Bloqueios ATIVOS de uma organização num instante, pré-carregados para avaliar muitos
 * envelopes sem uma consulta por envelope. A cobertura por pasta já inclui as subpastas.
 */
final class HoldSnapshot
{
    /**
     * @param  array<int, LegalHold>  $byEnvelope  envelope_id => bloqueio
     * @param  array<int, LegalHold>  $byFolder  folder_id (inclusive subpastas) => bloqueio
     */
    public function __construct(
        public readonly ?LegalHold $organizationHold,
        private readonly array $byEnvelope,
        private readonly array $byFolder,
    ) {}

    public function covering(int $envelopeId, ?int $folderId): ?LegalHold
    {
        return $this->organizationHold
            ?? $this->byEnvelope[$envelopeId]
            ?? ($folderId !== null ? ($this->byFolder[$folderId] ?? null) : null);
    }

    /**
     * @return list<int>
     */
    public function envelopeIds(): array
    {
        return array_keys($this->byEnvelope);
    }

    /**
     * @return list<int>
     */
    public function folderIds(): array
    {
        return array_keys($this->byFolder);
    }

    public function isEmpty(): bool
    {
        return $this->organizationHold === null && $this->byEnvelope === [] && $this->byFolder === [];
    }
}
