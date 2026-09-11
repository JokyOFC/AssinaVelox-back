<?php

namespace App\Services\Dossier;

/**
 * ZIP montado em disco local (diretório temporário do job), antes de ir para o disco privado.
 */
final readonly class BuiltDossier
{
    /**
     * @param  list<string>  $entries
     */
    public function __construct(
        public string $path,
        public string $sha256,
        public int $sizeBytes,
        public ?string $manifestSha256,
        public string $timestampStatus,
        public ?int $timestampTokenId,
        public int $envelopeCount,
        public array $entries,
    ) {}
}
