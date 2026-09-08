<?php

use App\Http\Controllers\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Admin\PlaceholderController as AdminPlaceholderController;
use App\Http\Controllers\Billing\BillingCheckoutController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Billing\PaymentReceiptController;
use App\Http\Controllers\Billing\PlanController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Envelopes\EnvelopeBulkController;
use App\Http\Controllers\Envelopes\EnvelopeController;
use App\Http\Controllers\Envelopes\EnvelopeDocumentController;
use App\Http\Controllers\Envelopes\EnvelopeDownloadController;
use App\Http\Controllers\Envelopes\EnvelopeEvidenceController;
use App\Http\Controllers\Envelopes\EnvelopeFieldController;
use App\Http\Controllers\Envelopes\EnvelopeRecipientController;
use App\Http\Controllers\Envelopes\EnvelopeSendController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\Members\InvitationAcceptController;
use App\Http\Controllers\Members\InvitationController;
use App\Http\Controllers\Members\MembershipController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Controllers\Organizations\OrganizationSwitchController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\LegalController;
use App\Http\Controllers\Public\VerificationController;
use App\Http\Controllers\RecipientController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Settings\GeneralController;
use App\Http\Controllers\Settings\NotificationController as NotificationSettingsController;
use App\Http\Controllers\Settings\SigningController;
use App\Http\Controllers\Sign\DocumentController as SignDocumentController;
use App\Http\Controllers\Sign\DownloadController as SignDownloadController;
use App\Http\Controllers\Sign\OtpController;
use App\Http\Controllers\Sign\RefusalController;
use App\Http\Controllers\Sign\SignatureController;
use App\Http\Controllers\Sign\SignerPageController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\Webhooks\MercadoPagoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas — docs/design/ROUTES_AND_PAGES.md §1 (nomes exatos)
|--------------------------------------------------------------------------
| Grupos: public (throttle:public) · signer (throttle:signer) · webhook · auth (sem org) ·
| app (auth + verified + org + org.2fa) · platform-admin (NÃO passa por org).
*/

// -- Público ------------------------------------------------------------------------------
Route::middleware('throttle:public')->group(function (): void {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('termos', [LegalController::class, 'terms'])->name('legal.terms');
    Route::get('privacidade', [LegalController::class, 'privacy'])->name('legal.privacy');

    Route::get('verificar', [VerificationController::class, 'index'])->name('verify.index');
    Route::get('verificar/{code}', [VerificationController::class, 'show'])
        ->middleware('throttle:20,1')
        ->where('code', '[A-Za-z0-9-]{12,24}')
        ->name('verify.show');
    Route::post('verificar/{code}/conferir', [VerificationController::class, 'checkFile'])
        ->middleware('throttle:10,1')
        ->where('code', '[A-Za-z0-9-]{12,24}')
        ->name('verify.check_file');
});

// -- Signatário (público, sem conta) -------------------------------------------------------
Route::prefix('assinar/{token}')
    ->where(['token' => '[A-Za-z0-9_-]{20,128}'])
    ->middleware('throttle:signer')
    ->name('sign.')
    ->group(function (): void {
        Route::get('/', [SignerPageController::class, 'show'])->name('show');
        Route::post('codigo', [OtpController::class, 'send'])->middleware('throttle:otp-send')->name('otp.send');
        Route::post('codigo/verificar', [OtpController::class, 'verify'])->middleware('throttle:otp-verify')->name('otp.verify');
        Route::get('documento', [SignDocumentController::class, 'show'])->name('document');
        Route::get('paginas/{page}.png', [SignDocumentController::class, 'page'])->whereNumber('page')->name('page');
        Route::post('assinar', [SignatureController::class, 'store'])->middleware('throttle:10,1')->name('complete');
        Route::post('recusar', [RefusalController::class, 'store'])->name('refuse');
        Route::get('download/{type}', [SignDownloadController::class, 'show'])->whereIn('type', ['signed', 'evidence'])->name('download');
    });

// -- Webhook Mercado Pago (sem CSRF — bootstrap/app.php) ---------------------------------
Route::post('webhooks/mercadopago', [MercadoPagoController::class, 'handle'])
    ->middleware('throttle:webhook')
    ->name('webhooks.mercadopago');

// -- Convites (guest ou autenticado) ------------------------------------------------------
Route::get('convites/{token}', [InvitationAcceptController::class, 'show'])
    ->middleware('throttle:public')
    ->name('invitations.accept');
Route::post('convites/{token}', [InvitationAcceptController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('invitations.accept.store');

// -- Autenticado, sem organização corrente -----------------------------------------------
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('organizacoes/nova', [OrganizationController::class, 'create'])->name('organizations.create');
    Route::post('organizacoes', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::post('organizacoes/{organization}/ativar', [OrganizationSwitchController::class, 'store'])->name('organizations.switch');
});

// -- Aplicação do cliente --------------------------------------------------------------------
Route::middleware(['auth', 'verified', 'org', 'org.2fa'])->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/exportar', [DashboardController::class, 'export'])->name('dashboard.export');
    Route::get('busca', [SearchController::class, 'index'])->middleware('throttle:search')->name('search.index');
    Route::get('notificacoes', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notificacoes/ler', [NotificationController::class, 'markRead'])->name('notifications.read');

    // Documentos (envelopes)
    Route::prefix('documentos')->name('envelopes.')->scopeBindings()->group(function (): void {
        Route::get('/', [EnvelopeController::class, 'index'])->name('index');
        Route::get('nova', [EnvelopeController::class, 'create'])->name('create');
        Route::post('lote/{action}', [EnvelopeBulkController::class, 'store'])->whereIn('action', ['move', 'resend', 'cancel'])->name('bulk');
        Route::get('{envelope}/editar', [EnvelopeController::class, 'edit'])->name('edit');
        Route::patch('{envelope}', [EnvelopeController::class, 'update'])->name('update');
        Route::post('{envelope}/documento', [EnvelopeDocumentController::class, 'store'])->name('document.store');
        Route::delete('{envelope}/documento', [EnvelopeDocumentController::class, 'destroy'])->name('document.destroy');
        Route::get('{envelope}/documento/status', [EnvelopeDocumentController::class, 'status'])->name('document.status');
        Route::get('{envelope}/documento/paginas/{page}.png', [EnvelopeDocumentController::class, 'page'])->whereNumber('page')->name('document.page');
        Route::put('{envelope}/destinatarios', [EnvelopeRecipientController::class, 'sync'])->name('recipients.sync');
        Route::put('{envelope}/campos', [EnvelopeFieldController::class, 'sync'])->name('fields.sync');
        Route::post('{envelope}/enviar', [EnvelopeSendController::class, 'store'])->name('send');
        Route::get('{envelope}', [EnvelopeController::class, 'show'])->name('show');
        Route::get('{envelope}/download/{type}', [EnvelopeDownloadController::class, 'show'])->whereIn('type', ['original', 'signed', 'evidence'])->name('download');
        Route::get('{envelope}/evidencias', [EnvelopeEvidenceController::class, 'show'])->name('evidence');
        Route::post('{envelope}/destinatarios/{recipient}/reenviar', [EnvelopeRecipientController::class, 'resend'])->name('recipients.resend');
        Route::post('{envelope}/reenviar', [EnvelopeRecipientController::class, 'resendAll'])->name('resend');
        Route::patch('{envelope}/destinatarios/{recipient}', [EnvelopeRecipientController::class, 'update'])->name('recipients.update');
        Route::post('{envelope}/cancelar', [EnvelopeController::class, 'cancel'])->name('cancel');
        Route::delete('{envelope}', [EnvelopeController::class, 'destroy'])->name('destroy');
        Route::post('{envelope}/duplicar', [EnvelopeController::class, 'duplicate'])->name('duplicate');
        Route::patch('{envelope}/pasta', [EnvelopeController::class, 'move'])->name('move');
    });

    // Pastas (owner/admin)
    Route::middleware('org.role:owner,admin')->prefix('pastas')->name('folders.')->group(function (): void {
        Route::post('/', [FolderController::class, 'store'])->name('store');
        Route::patch('{folder}', [FolderController::class, 'update'])->name('update');
        Route::delete('{folder}', [FolderController::class, 'destroy'])->name('destroy');
    });

    // Assinaturas
    Route::get('assinaturas', [RecipientController::class, 'index'])->name('recipients.index');
    Route::get('assinaturas/exportar', [RecipientController::class, 'export'])->name('recipients.export');
    Route::post('assinaturas/reenviar-pendentes', [RecipientController::class, 'resendPending'])
        ->middleware('org.role:owner,admin')
        ->name('recipients.resend_pending');

    // Fase 2 (placeholders)
    Route::get('modelos', [TemplateController::class, 'index'])->name('templates.index');
    Route::get('api-integracoes', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::get('api-integracoes/chaves', [IntegrationController::class, 'keys'])->middleware('org.role:owner,admin')->name('integrations.keys');
    Route::get('api-integracoes/logs', [IntegrationController::class, 'logs'])->middleware('org.role:owner,admin')->name('integrations.logs');

    // Usuários e convites (owner/admin)
    Route::middleware('org.role:owner,admin')->group(function (): void {
        Route::get('usuarios', [MembershipController::class, 'index'])->name('members.index');
        Route::patch('usuarios/{membership}', [MembershipController::class, 'update'])->name('members.update');
        Route::patch('usuarios/{membership}/status', [MembershipController::class, 'updateStatus'])->name('members.status');
        Route::delete('usuarios/{membership}', [MembershipController::class, 'destroy'])->name('members.destroy');
        Route::post('usuarios/{membership}/transferir-propriedade', [MembershipController::class, 'transferOwnership'])
            ->middleware('org.role:owner')
            ->name('members.transfer_ownership');

        Route::post('usuarios/convites', [InvitationController::class, 'store'])->name('invitations.store');
        Route::post('usuarios/convites/{invitation}/reenviar', [InvitationController::class, 'resend'])->name('invitations.resend');
        Route::delete('usuarios/convites/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
    });

    // Configurações
    Route::prefix('configuracoes')->group(function (): void {
        Route::middleware('org.role:owner,admin')->group(function (): void {
            Route::get('/', [GeneralController::class, 'edit'])->name('settings.general');
            Route::patch('empresa', [GeneralController::class, 'updateOrganization'])->name('settings.organization.update');
            Route::patch('seguranca', [GeneralController::class, 'updateSecurity'])->name('settings.security.update');
            Route::match(['post', 'delete'], 'excluir-conta', [GeneralController::class, 'deletion'])
                ->middleware(['org.role:owner', 'password.confirm'])
                ->name('settings.organization.destroy');

            Route::get('assinatura', [SigningController::class, 'edit'])->name('settings.signing');
            Route::patch('assinatura', [SigningController::class, 'update'])->name('settings.signing.update');

            // Plano e cobrança
            Route::get('plano', [BillingController::class, 'index'])->name('billing.index');
            Route::post('plano/checkout', [BillingCheckoutController::class, 'store'])->name('billing.checkout');
            Route::post('plano/cancelar', [BillingController::class, 'cancel'])
                ->middleware(['org.role:owner', 'password.confirm'])
                ->name('billing.cancel');
            Route::post('plano/reativar', [BillingController::class, 'resume'])->name('billing.resume');
            Route::patch('plano/faturamento', [BillingController::class, 'updateProfile'])->name('billing.profile.update');
            Route::get('plano/pagamentos/{payment}/recibo', [PaymentReceiptController::class, 'show'])->name('billing.payments.receipt');
        });

        Route::get('plano/retorno/{outcome}', [BillingCheckoutController::class, 'return'])
            ->whereIn('outcome', ['success', 'failure', 'pending'])
            ->name('billing.return');

        Route::get('notificacoes', [NotificationSettingsController::class, 'edit'])->name('settings.notifications');
        Route::patch('notificacoes', [NotificationSettingsController::class, 'update'])->name('settings.notifications.update');
    });

    Route::get('planos', [PlanController::class, 'index'])->middleware('org.role:owner,admin')->name('plans.index');
});

// -- Painel interno (platform-admin; NÃO passa por org) ------------------------------------
Route::middleware(['auth', 'verified', 'platform-admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('clientes', [AdminOrganizationController::class, 'index'])->name('organizations.index');
    Route::get('clientes/exportar', [AdminOrganizationController::class, 'export'])->name('organizations.export');
    Route::get('clientes/{organization}', [AdminOrganizationController::class, 'show'])->name('organizations.show');

    Route::get('faturamento', AdminPlaceholderController::class)->name('billing.index');
    Route::get('usuarios', AdminPlaceholderController::class)->name('users.index');
    Route::get('auditoria', AdminPlaceholderController::class)->name('audit.index');
    Route::get('configuracoes', AdminPlaceholderController::class)->name('settings.index');
});

require __DIR__.'/settings.php';
