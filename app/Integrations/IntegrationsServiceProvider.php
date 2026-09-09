<?php

namespace App\Integrations;

use App\Integrations\Contracts\EmailProvider;
use App\Integrations\Contracts\PaymentGateway;
use App\Integrations\Contracts\PdfConverter;
use App\Integrations\Contracts\PdfSigner;
use App\Integrations\Email\LaravelMailEmailProvider;
use App\Integrations\Email\LogEmailProvider;
use App\Integrations\Payments\CheckoutProGateway;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\MercadoPagoGateway;
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
 * Bindings dos adaptadores de App\Integrations (Fase 1: PDF e pagamento).
 *
 * Deve ser adicionado a bootstrap/providers.php.
 */
class IntegrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PdfToolClient::class);
        $this->app->singleton(ImageNormalizer::class);

        // E-mail transacional. `ASSINAVELOX_EMAIL_PROVIDER=log` troca por um fake explícito
        // de desenvolvimento, cujo recibo é sempre `unknown` (nunca `sent`).
        $this->app->singleton(LaravelMailEmailProvider::class);
        $this->app->singleton(LogEmailProvider::class);
        $this->app->bind(EmailProvider::class, function (Application $app): EmailProvider {
            return config('assinavelox.email.provider') === 'log'
                ? $app->make(LogEmailProvider::class)
                : $app->make(LaravelMailEmailProvider::class);
        });

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

        // Pagamento (Mercado Pago Checkout Pro).
        //
        // `driver=auto` (padrão): o adaptador real quando há access token; o dublê
        // explícito quando não há — assim o desenvolvimento funciona sem credencial e
        // sem que nada finja ser o Mercado Pago (o dublê grava `provider = fake` e o
        // seu init_point aponta para um domínio `.invalid`).
        //
        // `driver=mercadopago` força o real: sem credencial ele responde
        // `isConfigured() = false` e o checkout diz claramente que está desabilitado, em
        // vez de chamar endpoint nenhum.
        $this->app->singleton(MercadoPagoGateway::class);
        $this->app->singleton(FakePaymentGateway::class);

        $this->app->bind(CheckoutProGateway::class, function (Application $app): CheckoutProGateway {
            $driver = (string) config('assinavelox.mercadopago.driver', 'auto');

            if ($driver === 'fake') {
                return $app->make(FakePaymentGateway::class);
            }

            $gateway = $app->make(MercadoPagoGateway::class);

            if ($driver === 'mercadopago') {
                return $gateway;
            }

            return $gateway->isConfigured() ? $gateway : $app->make(FakePaymentGateway::class);
        });

        $this->app->bind(PaymentGateway::class, fn (Application $app): PaymentGateway => $app->make(CheckoutProGateway::class));
    }
}
