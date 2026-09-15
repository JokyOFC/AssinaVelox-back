<?php

namespace App\Services\CloudImport\Dto;

/**
 * Arquivo já baixado do provedor para um temporário local, ANTES da inspeção. Ninguém confia
 * no nome, no tipo ou no tamanho declarados: quem decide é o UploadInspector, pelo conteúdo.
 * O temporário é apagado por quem chamou (CloudImporter), com sucesso ou não.
 */
final class FetchedCloudFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly ?string $externalId,
        public readonly int $sizeBytes,
        public readonly bool $simulated = false,
    ) {}
}
