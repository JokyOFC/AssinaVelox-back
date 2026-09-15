<?php

namespace App\Integrations\Dropbox;

use App\Integrations\Contracts\CloudFileSource;
use App\Services\CloudImport\CloudImportRejected;
use App\Services\CloudImport\CloudProvider;
use App\Services\CloudImport\Dto\CloudFileSelection;
use App\Services\CloudImport\Dto\FetchedCloudFile;
use App\Services\CloudImport\Http\ConnectorFailure;
use App\Services\CloudImport\Http\ConnectorHttp;

/**
 * Dropbox (Fase 3 §3.9, G-CONN, docs/fase-3/conectores.md §4.2; pacotes-fase-2-3 §4.2).
 *
 * Importação SEM OAuth: o Dropbox Chooser (navegador, `linkType: "direct"`) devolve um link
 * direto temporário (vale 4 horas), e o servidor baixa na hora. O link é dado NÃO confiável
 * vindo do navegador, então:
 *
 *  - só `https`, só hosts da lista `cloud_import.dropbox_download_hosts` (padrão
 *    `dl.dropboxusercontent.com` e `*.dropboxusercontent.com` — o domínio exato do link direto
 *    está NÃO CONFIRMADO na documentação; conferir em sandbox antes de ligar);
 *  - a mesma proteção dos webhooks (DNS público, IP pinado, sem redirecionamento);
 *  - teto de bytes durante o download.
 *
 * Nenhum token, nenhuma conexão persistente. Classe B: sem `DROPBOX_APP_KEY` (app registrado
 * com os domínios do Chooser), `isConfigured()` é false.
 */
final class DropboxSource implements CloudFileSource
{
    public function __construct(private readonly ConnectorHttp $http) {}

    public function provider(): CloudProvider
    {
        return CloudProvider::Dropbox;
    }

    public function isConfigured(): bool
    {
        return $this->missingConfiguration() === [];
    }

    public function missingConfiguration(): array
    {
        return trim((string) config('services.dropbox.app_key', '')) === '' ? ['DROPBOX_APP_KEY'] : [];
    }

    public function isSimulated(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public static function downloadHosts(): array
    {
        return array_values(array_filter((array) config('assinavelox.cloud_import.dropbox_download_hosts', []), 'is_string'));
    }

    public static function validFileId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^id:[A-Za-z0-9_-]{1,128}$/', $id) === 1;
    }

    public function fetch(CloudFileSelection $selection, int $maxBytes): FetchedCloudFile
    {
        $link = $selection->link;

        if ($link === null || $link === '') {
            throw CloudImportRejected::make('invalid_selection', 'O arquivo escolhido no Dropbox não é válido. Escolha de novo.');
        }

        if ($selection->declaredBytes !== null && $selection->declaredBytes > $maxBytes) {
            throw CloudImportRejected::fromConnector(new ConnectorFailure(ConnectorFailure::TOO_LARGE), $maxBytes);
        }

        try {
            $downloaded = $this->http->download($link, self::downloadHosts(), [], $maxBytes);
        } catch (ConnectorFailure $failure) {
            throw CloudImportRejected::fromConnector($failure, $maxBytes);
        }

        $name = trim((string) $selection->name);

        return new FetchedCloudFile(
            $downloaded->path,
            $name === '' ? 'documento' : $name,
            self::validFileId($selection->externalId) ? $selection->externalId : null,
            $downloaded->sizeBytes,
        );
    }
}
