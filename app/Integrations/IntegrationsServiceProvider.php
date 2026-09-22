<?php

namespace App\Integrations;

use App\Integrations\Contracts\CpfVerificationProvider;
use App\Integrations\Contracts\EmailProvider;
use App\Integrations\Contracts\FiscalInvoiceProvider;
use App\Integrations\Contracts\IdentityVerificationProvider;
use App\Integrations\Contracts\PaymentGateway;
use App\Integrations\Contracts\PdfConverter;
use App\Integrations\Contracts\PdfSigner;
use App\Integrations\Contracts\SenderDomainVerifier;
use App\Integrations\Contracts\SmsProvider;
use App\Integrations\Contracts\TimestampProvider;
use App\Integrations\Contracts\WhatsAppProvider;
use App\Integrations\Cpf\CpfVerificationFactory;
use App\Integrations\Cpf\FakeCpfVerificationProvider;
use App\Integrations\Email\FakeSenderDomainVerifier;
use App\Integrations\Email\HttpSenderDomainVerifier;
use App\Integrations\Email\LaravelMailEmailProvider;
use App\Integrations\Email\LogEmailProvider;
use App\Integrations\Email\SenderDomainVerification;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Fiscal\FakeFiscalInvoiceProvider;
use App\Integrations\Identity\FakeIdentityVerificationProvider;
use App\Integrations\Identity\IdentityVerificationFactory;
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
use App\Integrations\Sms\FakeSmsProvider;
use App\Integrations\Sms\HttpSmsProvider;
use App\Integrations\Sms\SimulatedOutbox;
use App\Integrations\Timestamp\FakeTimestampProvider;
use App\Integrations\WhatsApp\FakeWhatsAppProvider;
use App\Integrations\WhatsApp\HttpWhatsAppProvider;
use App\Services\Branding\Contracts\VerifiedSenderDomains;
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

        $this->registerChannels();
        $this->registerReservedContracts();
    }

    /**
     * Fase 2 §2.9/§2.8 (C-CAN, docs/fase-2/canais-e-pin.md) — SMS, WhatsApp e domínios de envio.
     *
     * `fake` (padrão) = simulador identificado, que só funciona fora de produção
     * (`channels.allow_simulated`); qualquer outro valor = adaptador do serviço próprio,
     * DESABILITADO até existir documentação. Nunca um provedor de terceiro.
     */
    private function registerChannels(): void
    {
        $this->app->singleton(SimulatedOutbox::class);
        $this->app->singleton(FakeSmsProvider::class);
        $this->app->singleton(HttpSmsProvider::class);
        $this->app->singleton(FakeWhatsAppProvider::class);
        $this->app->singleton(HttpWhatsAppProvider::class);

        $this->app->bind(SmsProvider::class, fn (Application $app): SmsProvider => config('assinavelox.channels.sms.driver', 'fake') === 'fake'
            ? $app->make(FakeSmsProvider::class)
            : $app->make(HttpSmsProvider::class));

        $this->app->bind(WhatsAppProvider::class, fn (Application $app): WhatsAppProvider => config('assinavelox.channels.whatsapp.driver', 'fake') === 'fake'
            ? $app->make(FakeWhatsAppProvider::class)
            : $app->make(HttpWhatsAppProvider::class));

        $this->app->singleton(FakeSenderDomainVerifier::class);
        $this->app->singleton(HttpSenderDomainVerifier::class);

        $this->app->bind(SenderDomainVerifier::class, fn (Application $app): SenderDomainVerifier => config('assinavelox.sender_domains.verifier', 'fake') === 'fake'
            ? $app->make(FakeSenderDomainVerifier::class)
            : $app->make(HttpSenderDomainVerifier::class));

        // Contrato do C-BRAND: remetente próprio só com domínio verificado DE VERDADE.
        $this->app->bind(VerifiedSenderDomains::class, SenderDomainVerification::class);
    }

    /**
     * Contratos reservados com simulador identificado (C-CAN). Só existe o driver `fake`;
     * outro valor é erro de configuração explícito, nunca um adaptador inventado.
     */
    private function registerReservedContracts(): void
    {
        $this->app->singleton(FakeTimestampProvider::class);
        $this->app->bind(TimestampProvider::class, function (Application $app): TimestampProvider {
            $driver = (string) config('assinavelox.integrations.timestamp.driver', 'fake');

            if ($driver !== 'fake') {
                throw new IntegrationException(sprintf('Carimbo do tempo: o driver "%s" não existe; só há o simulador até a TSA própria (roadmap §2.13).', $driver));
            }

            return $app->make(FakeTimestampProvider::class);
        });

        $this->app->singleton(FakeFiscalInvoiceProvider::class);
        $this->app->bind(FiscalInvoiceProvider::class, function (Application $app): FiscalInvoiceProvider {
            $driver = (string) config('assinavelox.integrations.fiscal_invoice.driver', 'fake');

            if ($driver !== 'fake') {
                throw new IntegrationException(sprintf('NFS-e: o driver "%s" não existe; a emissão real está bloqueada (roadmap §2.21).', $driver));
            }

            return $app->make(FakeFiscalInvoiceProvider::class);
        });

        // CPF cadastral: quem escolhe o adaptador é a fábrica do C-ID (`cpf_lookup.driver`).
        $this->app->singleton(FakeCpfVerificationProvider::class);
        $this->app->bindIf(CpfVerificationProvider::class, fn (Application $app): CpfVerificationProvider => $app->make(CpfVerificationFactory::class)->make());

        // Fase 4 §4.1 — verificação facial com documento (Verifiky). Mesmo desenho do CPF: a
        // fábrica escolhe por `identity_verification.driver`; `bindIf` deixa o teste pôr um dublê.
        $this->app->singleton(FakeIdentityVerificationProvider::class);
        $this->app->bindIf(IdentityVerificationProvider::class, fn (Application $app): IdentityVerificationProvider => $app->make(IdentityVerificationFactory::class)->make());
    }
}
