<?php

namespace App\Integrations;

use App\Integrations\Contracts\PdfConverter;
use App\Integrations\Contracts\PdfSigner;
use App\Integrations\Pdf\FakePdfConverter;
use App\Integrations\Pdf\ImageToPdfConverter;
use App\Integrations\Pdf\LibreOfficeConverter;
use App\Integrations\Pdf\NullPdfSigner;
use App\Integrations\Pdf\PassthroughPdfConverter;
use App\Integrations\Pdf\PdfConverterManager;
use App\Integrations\Pdf\PyHankoSigner;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\ImageNormalizer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Bindings dos adaptadores de App\Integrations (Fase 1: PDF).
 *
 * Deve ser adicionado a bootstrap/providers.php. Os contratos de e-mail e
 * pagamento (EmailProvider, PaymentGateway) ainda não têm implementação e não
 * são registrados aqui.
 */
class IntegrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PdfToolClient::class);
        $this->app->singleton(ImageNormalizer::class);

        $this->app->singleton(PassthroughPdfConverter::class);
        $this->app->singleton(ImageToPdfConverter::class);
        $this->app->singleton(LibreOfficeConverter::class);
        $this->app->singleton(FakePdfConverter::class);
        $this->app->singleton(PdfConverterManager::class);
        $this->app->bind(PdfConverter::class, PdfConverterManager::class);

        $this->app->singleton(PyHankoSigner::class);
        $this->app->singleton(NullPdfSigner::class);

        // Resolvido a cada make(): PyHanko quando há certificado + passphrase; Null caso contrário.
        $this->app->bind(PdfSigner::class, function (Application $app): PdfSigner {
            $signer = $app->make(PyHankoSigner::class);

            return $signer->isConfigured() ? $signer : $app->make(NullPdfSigner::class);
        });
    }
}
