<?php

namespace App\Services\CloudImport;

use App\Integrations\Contracts\CloudFileSource;
use App\Integrations\Dropbox\DropboxSource;
use App\Integrations\GoogleDrive\GoogleDriveSource;

/**
 * Qual CloudFileSource atende cada provedor. Padrão: os adaptadores reais (HTTP Client do
 * Laravel). Um teste (ou o ambiente local) pode registrar outro — em geral o simulador
 * identificado — em `cloud_import.source.{provider}` no container.
 */
final class CloudFileSources
{
    public static function for(CloudProvider $provider): CloudFileSource
    {
        $key = 'cloud_import.source.'.$provider->value;

        if (app()->bound($key)) {
            $source = app($key);

            if ($source instanceof CloudFileSource && $source->provider() === $provider) {
                return $source;
            }
        }

        return match ($provider) {
            CloudProvider::GoogleDrive => app(GoogleDriveSource::class),
            CloudProvider::Dropbox => app(DropboxSource::class),
        };
    }

    /**
     * Algum provedor tem o app registrado (ou o simulador identificado)? Sem nenhum, as telas não
     * oferecem a importação como se ela existisse (revisão adversarial da onda G).
     */
    public static function anyConfigured(): bool
    {
        foreach (CloudProvider::cases() as $provider) {
            if (self::for($provider)->isConfigured()) {
                return true;
            }
        }

        return false;
    }
}
