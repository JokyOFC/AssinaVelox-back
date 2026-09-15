<?php

namespace App\Services\CloudImport;

use App\Integrations\Contracts\CloudFileSource;
use App\Services\CloudImport\Dto\CloudFileSelection;
use App\Services\CloudImport\Dto\FetchedCloudFile;

/**
 * SIMULADOR identificado de origem na nuvem (testes e desenvolvimento local). Não fala com
 * provedor nenhum: devolve os bytes recebidos no construtor. A importação fica marcada
 * `simulated = true` em `cloud_imports` e na trilha — nunca se passa por Google ou Dropbox.
 *
 * Recusado em produção. Só entra no fluxo quando registrado explicitamente no container
 * (`cloud_import.source.{provider}`, ver CloudFileSources).
 */
final class SimulatedCloudFileSource implements CloudFileSource
{
    public function __construct(
        private readonly CloudProvider $provider,
        private readonly string $bytes,
        private readonly string $name = 'arquivo-simulado.pdf',
    ) {}

    public function provider(): CloudProvider
    {
        return $this->provider;
    }

    public function isConfigured(): bool
    {
        return ! app()->environment('production');
    }

    public function missingConfiguration(): array
    {
        return $this->isConfigured() ? [] : ['SIMULADOR_RECUSADO_EM_PRODUCAO'];
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function fetch(CloudFileSelection $selection, int $maxBytes): FetchedCloudFile
    {
        if (! $this->isConfigured()) {
            throw CloudImportRejected::make('simulator_refused', 'Importação simulada não é aceita neste ambiente.');
        }

        if (strlen($this->bytes) > $maxBytes) {
            throw CloudImportRejected::make('file_too_large', 'O arquivo excede o limite de tamanho.');
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'av-cloud-sim-');
        file_put_contents($path, $this->bytes);

        return new FetchedCloudFile(
            $path,
            $selection->name ?? $this->name,
            $selection->externalId ?? 'simulado',
            strlen($this->bytes),
            true,
        );
    }
}
