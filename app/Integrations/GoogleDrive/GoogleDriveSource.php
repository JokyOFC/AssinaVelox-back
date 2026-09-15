<?php

namespace App\Integrations\GoogleDrive;

use App\Integrations\Contracts\CloudFileSource;
use App\Services\CloudImport\CloudImportRejected;
use App\Services\CloudImport\CloudProvider;
use App\Services\CloudImport\Dto\CloudFileSelection;
use App\Services\CloudImport\Dto\FetchedCloudFile;
use App\Services\CloudImport\Http\ConnectorFailure;
use App\Services\CloudImport\Http\ConnectorHttp;

/**
 * Google Drive (Fase 3 §3.9, G-CONN, docs/fase-3/conectores.md §4; pacotes-fase-2-3 §4.1).
 *
 * O usuário escolhe o arquivo no Google Picker (navegador, escopo `drive.file`); o navegador
 * manda só o `fileId`. O servidor:
 *
 *  1. lê os metadados (`files/{id}?fields=id,name,mimeType,size`) com o token da sessão;
 *  2. arquivo comum → `files/{id}?alt=media`; Documento/Planilha/Apresentação/Desenho do Google
 *     → `files/{id}/export?mimeType=application/pdf` (a API limita a exportação a 10 MB);
 *     pasta, atalho, formulário e demais tipos do Google → recusados;
 *  3. baixa com teto de bytes pelo ConnectorHttp (hosts `www.googleapis.com`).
 *
 * O tipo declarado pelo Google só serve para escolher entre download e exportação e para dar
 * extensão a um nome que não tem; quem decide se o arquivo entra é o UploadInspector.
 */
final class GoogleDriveSource implements CloudFileSource
{
    public const FILES_URL = 'https://www.googleapis.com/drive/v3/files/';

    /** @var list<string> */
    public const API_HOSTS = ['www.googleapis.com'];

    /** Limite da exportação de documentos do Google Workspace (documentação oficial). */
    public const EXPORT_MAX_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    private const EXPORTABLE = [
        'application/vnd.google-apps.document',
        'application/vnd.google-apps.spreadsheet',
        'application/vnd.google-apps.presentation',
        'application/vnd.google-apps.drawing',
    ];

    /** Extensão a partir do tipo DECLARADO, só para nomes sem extensão. */
    private const EXTENSION_BY_MIME = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly ConnectorHttp $http,
        private readonly GoogleOAuthClient $oauth,
    ) {}

    public function provider(): CloudProvider
    {
        return CloudProvider::GoogleDrive;
    }

    public function isConfigured(): bool
    {
        return $this->oauth->isConfigured();
    }

    public function missingConfiguration(): array
    {
        return $this->oauth->missingConfiguration();
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public static function validFileId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{10,256}$/', $id) === 1;
    }

    public function fetch(CloudFileSelection $selection, int $maxBytes): FetchedCloudFile
    {
        $token = $selection->accessToken();

        if ($token === null || $token === '') {
            throw CloudImportRejected::make('authorization_required', 'Autorize o acesso ao Google Drive antes de escolher o arquivo.');
        }

        $id = $selection->externalId;

        if (! self::validFileId($id)) {
            throw CloudImportRejected::make('invalid_selection', 'O arquivo escolhido no Google Drive não é válido. Escolha de novo.');
        }

        /** @var string $id */
        $headers = ['Authorization' => 'Bearer '.$token];

        try {
            $meta = $this->http->getJson(
                self::FILES_URL.rawurlencode($id).'?'.http_build_query(['fields' => 'id,name,mimeType,size', 'supportsAllDrives' => 'true']),
                self::API_HOSTS,
                $headers,
            );

            if (! $meta->successful()) {
                throw ConnectorFailure::http($meta->status());
            }

            $mime = (string) $meta->json('mimeType', '');
            $name = (string) $meta->json('name', '');
            $cap = $maxBytes;

            if (str_starts_with($mime, 'application/vnd.google-apps.')) {
                if (! in_array($mime, self::EXPORTABLE, true)) {
                    throw CloudImportRejected::make(
                        'unsupported_type',
                        'Este item do Google Drive não é um arquivo que possa ser importado (pastas, atalhos e formulários não são).',
                    );
                }

                $url = self::FILES_URL.rawurlencode($id).'/export?'.http_build_query(['mimeType' => 'application/pdf']);
                $name = self::withExtension(self::withoutExtension($name), 'pdf');
                $cap = min($maxBytes, self::EXPORT_MAX_BYTES);
            } else {
                $declared = $meta->json('size');

                if (is_numeric($declared) && (int) $declared > $maxBytes) {
                    throw CloudImportRejected::fromConnector(new ConnectorFailure(ConnectorFailure::TOO_LARGE), $maxBytes);
                }

                $url = self::FILES_URL.rawurlencode($id).'?'.http_build_query(['alt' => 'media', 'supportsAllDrives' => 'true']);

                if (pathinfo($name, PATHINFO_EXTENSION) === '' && isset(self::EXTENSION_BY_MIME[$mime])) {
                    $name = self::withExtension($name, self::EXTENSION_BY_MIME[$mime]);
                }
            }

            $downloaded = $this->http->download($url, self::API_HOSTS, $headers, $cap);
        } catch (ConnectorFailure $failure) {
            throw CloudImportRejected::fromConnector($failure, $maxBytes);
        }

        return new FetchedCloudFile($downloaded->path, $name === '' ? 'documento' : $name, $id, $downloaded->sizeBytes);
    }

    private static function withoutExtension(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);

        return $base === '' ? 'documento' : $base;
    }

    private static function withExtension(string $name, string $extension): string
    {
        return ($name === '' ? 'documento' : $name).'.'.$extension;
    }
}
