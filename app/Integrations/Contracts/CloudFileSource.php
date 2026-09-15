<?php

namespace App\Integrations\Contracts;

use App\Services\CloudImport\CloudImportRejected;
use App\Services\CloudImport\CloudProvider;
use App\Services\CloudImport\Dto\CloudFileSelection;
use App\Services\CloudImport\Dto\FetchedCloudFile;

/**
 * Origem de arquivos na nuvem para a importação (Fase 3 §3.9, G-CONN, docs/fase-3/conectores.md §2).
 *
 * Implementações: App\Integrations\GoogleDrive\GoogleDriveSource (Picker + `drive.file` +
 * `files/{id}?alt=media`), App\Integrations\Dropbox\DropboxSource (Chooser com link direto) e o
 * simulador identificado App\Services\CloudImport\SimulatedCloudFileSource (só testes/local).
 *
 * Contrato:
 *  - `fetch()` BAIXA o arquivo para um temporário e devolve o caminho. Não inspeciona o
 *    conteúdo: isso é do DocumentIntake/UploadInspector, o mesmo caminho de um upload.
 *  - Toda chamada de rede passa por App\Services\CloudImport\Http\ConnectorHttp (SSRF + hosts
 *    permitidos do provedor + teto de bytes).
 *  - Nenhum token é guardado pela implementação; o do Google chega na seleção e morre com ela.
 *  - Classe B: sem as credenciais do app registrado pelo proprietário, `isConfigured()` é
 *    false, `missingConfiguration()` lista o que falta e a tela diz "aguardando app registrado
 *    pelo proprietário". Nada é chamado.
 */
interface CloudFileSource
{
    public function provider(): CloudProvider;

    public function isConfigured(): bool;

    /**
     * Nomes das variáveis de ambiente que faltam (nunca valores).
     *
     * @return list<string>
     */
    public function missingConfiguration(): array;

    /** true só no simulador identificado; a importação fica marcada `simulated`. */
    public function isSimulated(): bool;

    /**
     * @throws CloudImportRejected
     */
    public function fetch(CloudFileSelection $selection, int $maxBytes): FetchedCloudFile;
}
