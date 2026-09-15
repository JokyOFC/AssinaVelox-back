<?php

use App\Http\Controllers\Admin\AffiliateController;
use App\Http\Controllers\Admin\AffiliatePayoutController;
use App\Http\Controllers\Admin\AffiliateReferralController;
use App\Http\Controllers\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Admin\BillingActionController as AdminBillingActionController;
use App\Http\Controllers\Admin\BillingController as AdminBillingController;
use App\Http\Controllers\Admin\ImpersonationController as AdminImpersonationController;
use App\Http\Controllers\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Admin\PlaceholderController as AdminPlaceholderController;
use App\Http\Controllers\Admin\RiskAppealController;
use App\Http\Controllers\Admin\RiskReportController as AdminRiskReportController;
use App\Http\Controllers\Admin\RiskReviewController as AdminRiskReviewController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Affiliates\AffiliatePortalController;
use App\Http\Controllers\Affiliates\ReferralLinkController;
use App\Http\Controllers\Anchors\EnvelopeAnchorController;
use App\Http\Controllers\Anchors\TemplateAnchorRuleController;
use App\Http\Controllers\Batch\BatchLinkController;
use App\Http\Controllers\Batch\BatchSigningController;
use App\Http\Controllers\Billing\BillingCheckoutController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Billing\PaymentActionController;
use App\Http\Controllers\Billing\PaymentReceiptController;
use App\Http\Controllers\Billing\PlanController;
use App\Http\Controllers\BulkGenerations\BulkGenerationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dossier\DossierDownloadController;
use App\Http\Controllers\Dossier\DossierExportController;
use App\Http\Controllers\Envelopes\EnvelopeBulkController;
use App\Http\Controllers\Envelopes\EnvelopeController;
use App\Http\Controllers\Envelopes\EnvelopeDocumentController;
use App\Http\Controllers\Envelopes\EnvelopeDownloadController;
use App\Http\Controllers\Envelopes\EnvelopeEvidenceController;
use App\Http\Controllers\Envelopes\EnvelopeFieldController;
use App\Http\Controllers\Envelopes\EnvelopeFlowController;
use App\Http\Controllers\Envelopes\EnvelopeRecipientController;
use App\Http\Controllers\Envelopes\EnvelopeSendController;
use App\Http\Controllers\Envelopes\LegalHoldController;
use App\Http\Controllers\Envelopes\RecipientLocaleController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\Identity\CaptureRequirementController;
use App\Http\Controllers\Identity\CnpjLookupController;
use App\Http\Controllers\Identity\VideoPlaybackController;
use App\Http\Controllers\Identity\VideoRequirementController;
use App\Http\Controllers\InPerson\InPersonHostController;
use App\Http\Controllers\InPerson\KioskController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\Integrations\KeysController;
use App\Http\Controllers\Integrations\WebhookDeliveryController;
use App\Http\Controllers\Integrations\WebhookEndpointController;
use App\Http\Controllers\Members\InvitationAcceptController;
use App\Http\Controllers\Members\InvitationController;
use App\Http\Controllers\Members\MembershipController;
use App\Http\Controllers\Members\MembershipFolderAccessController;
use App\Http\Controllers\Members\RoleController;
use App\Http\Controllers\Members\TeamController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Controllers\Organizations\OrganizationSwitchController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\LegalController;
use App\Http\Controllers\Public\VerificationController;
use App\Http\Controllers\PublicForms\PublicFormConfirmationController;
use App\Http\Controllers\PublicForms\PublicFormController;
use App\Http\Controllers\PublicForms\PublicFormFillController;
use App\Http\Controllers\PublicForms\PublicFormSubmissionController;
use App\Http\Controllers\RecipientController;
use App\Http\Controllers\Reports\AuditLogController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Settings\BrandingController;
use App\Http\Controllers\Settings\BrandingLogoController;
use App\Http\Controllers\Settings\GeneralController;
use App\Http\Controllers\Settings\NotificationController as NotificationSettingsController;
use App\Http\Controllers\Settings\RetentionController;
use App\Http\Controllers\Settings\SigningController;
use App\Http\Controllers\Sign\CaptureController as SignCaptureController;
use App\Http\Controllers\Sign\CertificateController as SignCertificateController;
use App\Http\Controllers\Sign\DelegationController as SignDelegationController;
use App\Http\Controllers\Sign\DocumentController as SignDocumentController;
use App\Http\Controllers\Sign\DownloadController as SignDownloadController;
use App\Http\Controllers\Sign\ExternalSignatureController;
use App\Http\Controllers\Sign\ExternalSimulatorController;
use App\Http\Controllers\Sign\GovBrReturnController as SignGovBrReturnController;
use App\Http\Controllers\Sign\LocaleController as SignLocaleController;
use App\Http\Controllers\Sign\OtpController;
use App\Http\Controllers\Sign\RefusalController;
use App\Http\Controllers\Sign\SignatureController;
use App\Http\Controllers\Sign\SignerPageController;
use App\Http\Controllers\Tags\EnvelopeTagController;
use App\Http\Controllers\Tags\TagController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\Templates\TemplatePickerController;
use App\Http\Controllers\Templates\TemplateSourceController;
use App\Http\Controllers\Templates\TemplateUseController;
use App\Http\Controllers\Tsa\TsaController;
use App\Http\Controllers\Webhooks\MercadoPagoController;
use App\Http\Controllers\Webhooks\SmsStatusWebhookController;
use App\Http\Controllers\Webhooks\WhatsAppStatusWebhookController;
use App\Http\Middleware\ApplySignerLocale;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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

// Fase 2 §2.8 — logo da organização para e-mails e página pública (docs/fase-2/branding.md).
// Só o token opaco da versão do logo na URL; resposta idêntica (PNG transparente) para
// token desconhecido, logo removido ou marca desligada.
Route::get('marca/logo.png', [BrandingLogoController::class, 'show'])
    ->middleware('throttle:300,1')
    ->name('branding.logo');

// Fase 2 §2.11 (C-ID) — autopreenchimento por CNPJ no cadastro e em "Nova organização" (sem
// organização: vale o interruptor global `cnpj_lookup`). JSON; limite por usuário ou IP no
// serviço. Nunca bloqueia o formulário (docs/fase-2/identidade.md §3).
Route::post('cnpj/consulta', [CnpjLookupController::class, 'registration'])
    ->middleware('throttle:public')
    ->name('cnpj.lookup');

// Fase 2 §2.2 (C-FORM) — formulário público que gera envelope a partir de um modelo
// (docs/fase-2/formulario-publico.md). Sem login. Token de 40 caracteres aleatórios; token
// desconhecido, rascunho, revogado ou flag `public_forms` desligada: o mesmo 404. Os limites
// por IP e por formulário ficam no serviço (PublicFormIntake); `throttle:public` é o teto geral.
// A confirmação do e-mail é um POST: o GET só mostra a tela (pré-carregamento de link não confirma).
Route::prefix('formulario/{token}')
    ->where(['token' => '[A-Za-z0-9]{40}', 'confirmation' => '[A-Za-z0-9]{48}'])
    ->middleware('throttle:public')
    ->name('form_fill.')
    ->group(function (): void {
        Route::get('/', [PublicFormFillController::class, 'show'])->name('show');
        Route::post('/', [PublicFormFillController::class, 'store'])->name('submit');
        Route::get('confirmar/{confirmation}', [PublicFormConfirmationController::class, 'show'])->name('confirm.show');
        Route::post('confirmar/{confirmation}', [PublicFormConfirmationController::class, 'store'])->name('confirm');
    });

// -- Signatário (público, sem conta) -------------------------------------------------------
Route::prefix('assinar/{token}')
    ->where(['token' => '[A-Za-z0-9_-]{20,128}'])
    // `signer` resolve o token do convite e injeta o contexto; `signer.verified` exige a
    // sessão criada depois do código por e-mail (docs/fluxo-do-signatario.md).
    // F-I18N (Fase 3 §3.3): `ApplySignerLocale` depois de `signer` — idioma da página; sem a
    // flag `multilingual` não faz nada.
    ->middleware(['throttle:signer', 'signer', ApplySignerLocale::class])
    ->name('sign.')
    ->group(function (): void {
        Route::get('/', [SignerPageController::class, 'show'])->name('show');
        Route::post('codigo', [OtpController::class, 'send'])->middleware('throttle:otp-send')->name('otp.send');
        Route::post('codigo/verificar', [OtpController::class, 'verify'])->middleware('throttle:otp-verify')->name('otp.verify');
        // Fase 2 §2.9 (C-CAN): PIN do remetente, depois do código. O limite de tentativas de
        // verdade fica em `recipient_pins` (bloqueio temporário); este é o freio por IP.
        Route::post('pin', [OtpController::class, 'verifyPin'])->middleware('throttle:10,10,sign-pin')->name('pin.verify');
        Route::get('documento', [SignDocumentController::class, 'show'])->middleware('signer.verified')->name('document');
        Route::get('paginas/{page}.png', [SignDocumentController::class, 'page'])->middleware('signer.verified')->whereNumber('page')->name('page');
        Route::post('assinar', [SignatureController::class, 'store'])->middleware(['signer.verified', 'throttle:10,1'])->name('complete');
        Route::post('recusar', [RefusalController::class, 'store'])->middleware('signer.verified')->name('refuse');
        // Fase 2 §2.10 (C-ID, docs/fase-2/identidade.md §5): foto do rosto/documento exigida
        // pelo remetente. 404 com a flag `identity_capture` desligada ou tipo não exigido.
        Route::post('captura/{kind}', [SignCaptureController::class, 'store'])
            ->middleware('signer.verified')
            ->whereIn('kind', ['selfie', 'document_front', 'document_back'])
            ->name('capture.store');
        // Fase 3 §3.3 (F-VIDEO, docs/fase-3/captura-de-video.md): vídeo curto exigido pelo
        // remetente. 404 com a flag `identity_video` desligada ou vídeo não exigido.
        Route::post('captura-video', [SignCaptureController::class, 'storeVideo'])
            ->middleware('signer.verified')
            ->name('capture.video.store');
        // Sem `signer.verified`: o aceite consome a sessão e o comprovante é pedido logo
        // depois. A autorização é feita no controller (aceite registrado ou sessão viva).
        Route::get('download/{type}', [SignDownloadController::class, 'show'])->whereIn('type', ['signed', 'evidence'])->name('download');
        // Fase 2 §2.12 (K-A1, docs/fase-2/a1-do-participante.md): assinatura com o certificado A1
        // do PRÓPRIO participante. JSON; 404 com a flag `participant_a1` desligada. Autenticação no
        // serviço: sessão do código OU janela de download deste navegador (quem já aceitou).
        Route::get('certificado', [SignCertificateController::class, 'show'])->middleware('throttle:60,1,sign-certificate-show')->name('certificate.show');
        Route::post('certificado/intencao', [SignCertificateController::class, 'intent'])->middleware('throttle:20,10,sign-certificate-intent')->name('certificate.intent');
        Route::post('certificado/desistir', [SignCertificateController::class, 'withdraw'])->middleware('throttle:20,10,sign-certificate-withdraw')->name('certificate.withdraw');
        Route::post('certificado/conferir', [SignCertificateController::class, 'inspect'])->middleware('throttle:10,10,sign-certificate-inspect')->name('certificate.inspect');
        Route::post('certificado', [SignCertificateController::class, 'store'])->middleware('throttle:6,10,sign-certificate-store')->name('certificate.store');
        // Fase 3 §3.4 (P3-EXT, docs/fase-3/assinatura-externa-a3.md): assinatura do participante
        // por componente local (A3) — o servidor prepara a revisão e o digest, o componente assina,
        // o servidor incorpora e valida. JSON; 404 com a flag `a3_signing` desligada. O simulador
        // só existe em teste/local (FakeLocalSigner) e tudo o que assina é rotulado "simulado".
        Route::get('externa', [ExternalSignatureController::class, 'show'])->middleware('throttle:60,1,sign-external-show')->name('external.show');
        Route::post('externa/intencao', [ExternalSignatureController::class, 'intent'])->middleware('throttle:20,10,sign-external-intent')->name('external.intent');
        Route::post('externa/desistir', [ExternalSignatureController::class, 'withdraw'])->middleware('throttle:20,10,sign-external-withdraw')->name('external.withdraw');
        Route::post('externa/preparar', [ExternalSignatureController::class, 'prepare'])->middleware('throttle:30,10,sign-external-prepare')->name('external.prepare');
        Route::post('externa/assinatura', [ExternalSignatureController::class, 'submit'])->middleware('throttle:30,10,sign-external-submit')->name('external.submit');
        Route::get('externa/simulador/certificado', [ExternalSimulatorController::class, 'certificate'])->middleware('throttle:30,10,sign-external-simulator')->name('external.simulator.certificate');
        Route::post('externa/simulador/assinar', [ExternalSimulatorController::class, 'sign'])->middleware('throttle:30,10,sign-external-simulator-sign')->name('external.simulator.sign');
        // Fase 3 §3.5 (P3-GOV, docs/fase-3/gov-br.md): o participante assina no portal gov.br e
        // DEVOLVE o PDF. JSON (exceto o download da revisão reservada); 404 com a flag
        // `govbr_return` desligada. Autenticação no serviço, como no A1 do participante.
        Route::get('gov-br', [SignGovBrReturnController::class, 'show'])->middleware('throttle:60,1,sign-govbr-show')->name('govbr.show');
        Route::post('gov-br/intencao', [SignGovBrReturnController::class, 'intent'])->middleware('throttle:20,10,sign-govbr-intent')->name('govbr.intent');
        Route::post('gov-br/desistir', [SignGovBrReturnController::class, 'withdraw'])->middleware('throttle:20,10,sign-govbr-withdraw')->name('govbr.withdraw');
        Route::post('gov-br/reservar', [SignGovBrReturnController::class, 'reserve'])->middleware('throttle:20,10,sign-govbr-reserve')->name('govbr.reserve');
        Route::get('gov-br/{pedido}/revisao', [SignGovBrReturnController::class, 'download'])->where('pedido', '[0-9A-Za-z]{26}')->middleware('throttle:30,10,sign-govbr-download')->name('govbr.download');
        Route::post('gov-br/{pedido}/devolver', [SignGovBrReturnController::class, 'upload'])->where('pedido', '[0-9A-Za-z]{26}')->middleware('throttle:10,10,sign-govbr-upload')->name('govbr.upload');
        // Fase 3 §3.3 (F-FLOW, docs/fase-3/etapas-e-delegacao.md §5): delegação pelo participante.
        // JSON; 404 com a flag `delegation` desligada ou sem a sessão do código. As proibições e os
        // limites por participante e por organização ficam no serviço; este é o freio por IP.
        Route::get('delegar', [SignDelegationController::class, 'show'])->middleware('throttle:60,1,sign-delegation-show')->name('delegation.show');
        Route::post('delegar', [SignDelegationController::class, 'store'])->middleware('throttle:6,10,sign-delegation-store')->name('delegation.store');
        // Fase 3 §3.3 (F-I18N, docs/fase-3/multilingue.md §4): o participante troca o idioma de
        // EXIBIÇÃO (vale para a sessão e vai para a trilha). 404 com a flag `multilingual` desligada.
        Route::post('idioma', [SignLocaleController::class, 'update'])->middleware('throttle:20,10,sign-locale')->name('locale.update');
    });

// -- Assinatura em lote (Fase 2 §2.7, C-PRES — docs/fase-2/presencial-e-lote.md §3) -------
// Público, sem conta. `assinar/lote/{token}` troca o token do e-mail por uma entrada na sessão
// e redireciona para `assinar/lote`; o resto usa a sessão. "lote" não casa com o `{token}` do
// grupo `assinar/{token}` (20..128 caracteres). Autorização SEMPRE item a item.
Route::prefix('assinar/lote')
    ->middleware('throttle:signer')
    ->name('sign.batch.')
    ->group(function (): void {
        Route::get('{token?}', [BatchSigningController::class, 'show'])->where('token', '[A-Za-z0-9_-]{20,128}')->name('show');
        Route::post('codigo', [BatchSigningController::class, 'sendCode'])->middleware('throttle:10,10,batch-otp-send')->name('otp.send');
        Route::post('codigo/verificar', [BatchSigningController::class, 'verifyCode'])->middleware('throttle:15,10,batch-otp-verify')->name('otp.verify');
        Route::get('documento', [BatchSigningController::class, 'document'])->name('document');
        Route::post('itens/{item}/abrir', [BatchSigningController::class, 'open'])->where('item', '[A-Za-z0-9]{26}')->name('items.open');
        Route::post('itens/{item}/autorizar', [BatchSigningController::class, 'authorizeItem'])->where('item', '[A-Za-z0-9]{26}')->middleware('throttle:30,1,batch-authorize')->name('items.authorize');
        Route::post('sair', [BatchSigningController::class, 'leave'])->name('leave');
    });

// -- Dispositivo presencial (Fase 2 §2.6, C-PRES — docs/fase-2/presencial-e-lote.md §2) ----
// Público, sem conta: autoriza o segredo do dispositivo na sessão (posto pelo anfitrião) e,
// para o documento e o aceite, a sessão de assinatura do PRÓPRIO participante da vez.
Route::prefix('presencial')
    ->middleware('throttle:signer')
    ->name('in_person.kiosk.')
    ->group(function (): void {
        Route::get('/', [KioskController::class, 'show'])->name('show');
        Route::post('participante', [KioskController::class, 'select'])->middleware('throttle:60,1,in-person-select')->name('participant');
        Route::post('codigo', [KioskController::class, 'sendCode'])->middleware('throttle:20,10,in-person-otp-send')->name('otp.send');
        Route::post('codigo/verificar', [KioskController::class, 'verifyCode'])->middleware('throttle:30,10,in-person-otp-verify')->name('otp.verify');
        Route::post('pin', [KioskController::class, 'verifyPin'])->middleware('throttle:10,10,in-person-pin')->name('pin.verify');
        Route::get('documento', [KioskController::class, 'document'])->name('document');
        Route::post('captura/{kind}', [KioskController::class, 'capture'])
            ->whereIn('kind', ['selfie', 'document_front', 'document_back'])
            ->name('capture.store');
        Route::post('aceite', [KioskController::class, 'accept'])->middleware('throttle:10,1,in-person-accept')->name('complete');
        Route::post('bloquear', [KioskController::class, 'lock'])->name('lock');
        Route::post('encerrar', [KioskController::class, 'end'])->name('end');
    });

// -- Webhook Mercado Pago (sem CSRF — bootstrap/app.php) ---------------------------------
Route::post('webhooks/mercadopago', [MercadoPagoController::class, 'handle'])
    ->middleware('throttle:webhook')
    ->name('webhooks.mercadopago');

// -- Webhooks de status de SMS/WhatsApp (Fase 2 §2.9, C-CAN) ------------------------------
// Sem CSRF: autenticados por HMAC + carimbo de tempo (App\Services\Signing\Channels\StatusWebhooks).
// Com o provedor desabilitado (hoje, em produção) respondem 503 com o motivo.
Route::post('webhooks/sms/status', SmsStatusWebhookController::class)
    ->middleware('throttle:webhook')
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('webhooks.sms.status');
Route::post('webhooks/whatsapp/status', WhatsAppStatusWebhookController::class)
    ->middleware('throttle:webhook')
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('webhooks.whatsapp.status');

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
        // Stream do PDF da versão exibível (Content-Type: application/pdf, inline, sem cache):
        // fonte do visualizador PDF.js do editor de campos. Ver docs/preparacao-documental.md.
        Route::get('{envelope}/documento/preview', [EnvelopeDocumentController::class, 'preview'])->name('document.preview');
        Route::get('{envelope}/documento/paginas/{page}.png', [EnvelopeDocumentController::class, 'page'])->whereNumber('page')->name('document.page');
        Route::put('{envelope}/destinatarios', [EnvelopeRecipientController::class, 'sync'])->name('recipients.sync');
        Route::put('{envelope}/campos', [EnvelopeFieldController::class, 'sync'])->name('fields.sync');
        Route::post('{envelope}/enviar', [EnvelopeSendController::class, 'store'])->name('send');
        // Fase 2 §2.5 (docs/fase-2/lembretes-e-agendamento.md): 404 com a flag `reminders` desligada.
        Route::post('{envelope}/agendamento', [EnvelopeSendController::class, 'schedule'])->name('schedule');
        Route::delete('{envelope}/agendamento', [EnvelopeSendController::class, 'cancelSchedule'])->name('schedule.cancel');
        Route::put('{envelope}/lembretes', [EnvelopeSendController::class, 'reminders'])->name('reminders.update');
        Route::get('{envelope}', [EnvelopeController::class, 'show'])->name('show');
        Route::get('{envelope}/download/{type}', [EnvelopeDownloadController::class, 'show'])->whereIn('type', ['original', 'signed', 'evidence'])->name('download');
        Route::get('{envelope}/evidencias', [EnvelopeEvidenceController::class, 'show'])->name('evidence');
        Route::post('{envelope}/destinatarios/{recipient}/reenviar', [EnvelopeRecipientController::class, 'resend'])->name('recipients.resend');
        Route::post('{envelope}/reenviar', [EnvelopeRecipientController::class, 'resendAll'])->name('resend');
        Route::patch('{envelope}/destinatarios/{recipient}', [EnvelopeRecipientController::class, 'update'])->name('recipients.update');
        // Fase 2 §2.10 (C-ID): fotos exigidas do participante antes do aceite (só rascunho).
        Route::put('{envelope}/participantes/{recipient}/captura', [CaptureRequirementController::class, 'update'])->name('recipients.identity_capture');
        // Fase 3 §3.3 (F-VIDEO): exigência de vídeo curto (só rascunho) e reprodução por URL
        // assinada e curta para quem vê o envelope. 404 com a flag `identity_video` desligada.
        Route::put('{envelope}/participantes/{recipient}/video', [VideoRequirementController::class, 'update'])->name('recipients.identity_video');
        // Fase 3 §3.3 (F-I18N, docs/fase-3/multilingue.md §3): idioma e fuso de cada participante
        // (JSON; alteração só no rascunho). 404 com a flag `multilingual` desligada.
        Route::get('{envelope}/idiomas', [RecipientLocaleController::class, 'index'])->name('recipients.locales');
        Route::put('{envelope}/participantes/{recipient}/idioma', [RecipientLocaleController::class, 'update'])->name('recipients.locale');
        Route::get('{envelope}/videos', [VideoPlaybackController::class, 'index'])->name('identity_videos.index');
        Route::get('{envelope}/videos/{video}/arquivo', [VideoPlaybackController::class, 'file'])->name('identity_videos.file');
        Route::post('{envelope}/cancelar', [EnvelopeController::class, 'cancel'])->name('cancel');
        Route::delete('{envelope}', [EnvelopeController::class, 'destroy'])->name('destroy');
        Route::post('{envelope}/duplicar', [EnvelopeController::class, 'duplicate'])->name('duplicate');
        Route::patch('{envelope}/pasta', [EnvelopeController::class, 'move'])->name('move');
        // Fase 3 §3.3 (F-FLOW, docs/fase-3/etapas-e-delegacao.md §5): etapas condicionais e
        // delegação do lado de quem envia. JSON (exceto confirmar/recusar, que voltam com flash);
        // 404 com as flags `conditional_steps` e `delegation` desligadas.
        // Limites com prefixo próprio: o `throttle:N,M` genérico divide o contador com as outras
        // rotas do usuário (inclusive o link de verificação de e-mail, 6/min).
        Route::get('{envelope}/fluxo', [EnvelopeFlowController::class, 'show'])->middleware('throttle:120,1,envelope-flow-show')->name('flow.show');
        Route::put('{envelope}/etapas', [EnvelopeFlowController::class, 'updateSteps'])->middleware('throttle:60,1,envelope-steps-update')->name('steps.update');
        Route::put('{envelope}/delegacao', [EnvelopeFlowController::class, 'updateDelegation'])->middleware('throttle:60,1,envelope-delegation-update')->name('delegation.update');
        Route::post('{envelope}/delegacoes/{delegation}/aprovar', [EnvelopeFlowController::class, 'approve'])->where('delegation', '[0-9A-Za-z]{26}')->middleware('throttle:30,1,envelope-delegation-decide')->name('delegations.approve');
        Route::post('{envelope}/delegacoes/{delegation}/recusar', [EnvelopeFlowController::class, 'reject'])->where('delegation', '[0-9A-Za-z]{26}')->middleware('throttle:30,1,envelope-delegation-decide')->name('delegations.reject');
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

    // Fase 2 §2.11 (C-ID): autopreenchimento por CNPJ em Configurações › Geral (flag
    // `cnpj_lookup` da organização + `updateSettings`). JSON; nunca bloqueia o formulário.
    Route::post('configuracoes/organizacao/cnpj', [CnpjLookupController::class, 'organization'])->name('settings.organization.cnpj');

    // Fase 2 (placeholders)
    // Modelos (Fase 2 §2.1 — docs/fase-2/modelos.md). Flag `templates` desligada: `index` é o
    // placeholder da Fase 1 e as demais respondem 404 (middleware do próprio controller).
    Route::prefix('modelos')->name('templates.')->scopeBindings()->group(function (): void {
        Route::get('/', [TemplateController::class, 'index'])->name('index');
        Route::get('seletor', [TemplatePickerController::class, 'index'])->middleware('throttle:60,1')->name('picker');
        Route::post('/', [TemplateController::class, 'store'])->middleware('throttle:30,1')->name('store');
        Route::get('{template}/editar', [TemplateController::class, 'edit'])->name('edit');
        Route::put('{template}', [TemplateController::class, 'update'])->name('update');
        Route::post('{template}/duplicar', [TemplateController::class, 'duplicate'])->name('duplicate');
        Route::post('{template}/arquivar', [TemplateController::class, 'archive'])->name('archive');
        Route::post('{template}/restaurar', [TemplateController::class, 'restore'])->name('restore');
        Route::get('{template}/arquivo', [TemplateSourceController::class, 'show'])->name('source.show');
        Route::post('{template}/arquivo', [TemplateSourceController::class, 'update'])->middleware('throttle:30,1')->name('source.update');
        Route::get('{template}/pre-visualizacao', [TemplateSourceController::class, 'preview'])->middleware('throttle:30,1')->name('preview');
        Route::post('{template}/usar', [TemplateUseController::class, 'store'])->middleware('throttle:20,1')->name('use');
    });
    // Geração em lote (Fase 3 §3.1, F-BULK — docs/fase-3/geracao-em-lote.md). Flag
    // `bulk_generation` desligada: 404 em todas (middleware do controller).
    Route::get('modelos/{template}/lotes/novo', [BulkGenerationController::class, 'create'])->name('bulk_generations.create');
    Route::get('modelos/{template}/lotes/planilha-modelo', [BulkGenerationController::class, 'sample'])->name('bulk_generations.sample');
    // I-3F: limites com prefixo próprio (o `throttle:N,M` sem nome divide o contador por usuário
    // com as outras rotas, inclusive o link de verificação de e-mail, 6/min).
    Route::post('modelos/{template}/lotes', [BulkGenerationController::class, 'store'])->middleware('throttle:10,1,bulk-store')->name('bulk_generations.store');
    Route::prefix('lotes')->name('bulk_generations.')->group(function (): void {
        Route::get('/', [BulkGenerationController::class, 'index'])->name('index');
        Route::get('{bulkGeneration}', [BulkGenerationController::class, 'show'])->name('show');
        Route::put('{bulkGeneration}/mapeamento', [BulkGenerationController::class, 'mapping'])->middleware('throttle:20,1,bulk-mapping')->name('mapping');
        Route::post('{bulkGeneration}/confirmar', [BulkGenerationController::class, 'confirm'])->middleware('throttle:10,1,bulk-confirm')->name('confirm');
        Route::post('{bulkGeneration}/cancelar', [BulkGenerationController::class, 'cancel'])->name('cancel');
        Route::delete('{bulkGeneration}', [BulkGenerationController::class, 'destroy'])->name('destroy');
        Route::get('{bulkGeneration}/relatorio', [BulkGenerationController::class, 'report'])->name('report');
    });
    // Formulários públicos (Fase 2 §2.2, C-FORM — docs/fase-2/formulario-publico.md). Flag
    // `public_forms` desligada: 404 em todas (middleware dos controllers). Sem `org.role`:
    // `manage_templates` (e `send_envelopes` para aprovar) na PublicFormPolicy.
    Route::prefix('formularios')->name('public_forms.')->group(function (): void {
        Route::get('/', [PublicFormController::class, 'index'])->name('index');
        Route::post('/', [PublicFormController::class, 'store'])->middleware('throttle:30,1')->name('store');
        Route::get('{publicForm}/editar', [PublicFormController::class, 'edit'])->name('edit');
        Route::put('{publicForm}', [PublicFormController::class, 'update'])->name('update');
        Route::post('{publicForm}/publicar', [PublicFormController::class, 'activate'])->name('activate');
        Route::post('{publicForm}/pausar', [PublicFormController::class, 'pause'])->name('pause');
        Route::post('{publicForm}/revogar', [PublicFormController::class, 'revoke'])->name('revoke');
        Route::post('envios/{submission}/aprovar', [PublicFormSubmissionController::class, 'approve'])->name('submissions.approve');
        Route::post('envios/{submission}/recusar', [PublicFormSubmissionController::class, 'reject'])->name('submissions.reject');
    });
    Route::get('api-integracoes', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::get('api-integracoes/chaves', [IntegrationController::class, 'keys'])->middleware('org.role:owner,admin')->name('integrations.keys');
    Route::get('api-integracoes/logs', [IntegrationController::class, 'logs'])->middleware('org.role:owner,admin')->name('integrations.logs');
    // Fase 2 §2.15 (D-PLAT) — criar e revogar chaves da API pela tela. Flag `api_integrations`
    // desligada: 404. Sem `org.role`: `manage_integrations` conferida no controller e no
    // ApiTokenManager (anti-escalada). A criação responde com a página (token exibido uma vez).
    Route::post('api-integracoes/chaves', [KeysController::class, 'store'])->middleware('throttle:10,1')->name('integrations.keys.store');
    Route::delete('api-integracoes/chaves/{apiToken}', [KeysController::class, 'destroy'])->middleware('throttle:30,1')->name('integrations.keys.destroy');

    // Fase 2 §2.16 — webhooks de saída (D-HOOK, docs/fase-2/webhooks.md §9). Flag
    // `outbound_webhooks` desligada: 404 em todas (middleware dos controllers). Sem `org.role`:
    // `manage_integrations` na WebhookEndpointPolicy. A entrega é resolvida dentro do endpoint.
    Route::prefix('api-integracoes/webhooks')->name('integrations.webhooks.')->scopeBindings()->group(function (): void {
        Route::get('/', [WebhookEndpointController::class, 'index'])->name('index');
        Route::post('/', [WebhookEndpointController::class, 'store'])->middleware('throttle:30,1')->name('store');
        Route::get('{webhookEndpoint}', [WebhookEndpointController::class, 'show'])->name('show');
        Route::patch('{webhookEndpoint}', [WebhookEndpointController::class, 'update'])->middleware('throttle:30,1')->name('update');
        Route::delete('{webhookEndpoint}', [WebhookEndpointController::class, 'destroy'])->name('destroy');
        Route::post('{webhookEndpoint}/pausar', [WebhookEndpointController::class, 'pause'])->name('pause');
        Route::post('{webhookEndpoint}/reativar', [WebhookEndpointController::class, 'resume'])->middleware('throttle:30,1')->name('resume');
        Route::post('{webhookEndpoint}/segredo/rotacionar', [WebhookEndpointController::class, 'rotateSecret'])->middleware('throttle:10,1')->name('secret.rotate');
        Route::post('{webhookEndpoint}/segredo/encerrar-anterior', [WebhookEndpointController::class, 'expirePreviousSecret'])->name('secret.expire_previous');
        Route::post('{webhookEndpoint}/testar', [WebhookEndpointController::class, 'test'])->middleware('throttle:10,1')->name('test');
        Route::get('{webhookEndpoint}/entregas/{delivery}', [WebhookDeliveryController::class, 'show'])->name('deliveries.show');
        Route::post('{webhookEndpoint}/entregas/{delivery}/reenviar', [WebhookDeliveryController::class, 'resend'])->middleware('throttle:30,1')->name('deliveries.resend');
    });

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

    // Fase 2 — funções personalizadas, times e acesso por pasta (docs/fase-2/permissoes-e-times.md).
    // Sem `org.role`: cada ação é autorizada por permissão nas Policies (Role/Team/Membership)
    // e exige a flag `custom_roles` nos FormRequests.
    Route::post('usuarios/funcoes', [RoleController::class, 'store'])->name('roles.store');
    Route::patch('usuarios/funcoes/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('usuarios/funcoes/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    Route::put('usuarios/funcoes/{role}/pastas', [RoleController::class, 'folders'])->name('roles.folders');
    Route::post('usuarios/times', [TeamController::class, 'store'])->name('teams.store');
    Route::patch('usuarios/times/{team}', [TeamController::class, 'update'])->name('teams.update');
    Route::delete('usuarios/times/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
    Route::put('usuarios/{membership}/pastas', [MembershipFolderAccessController::class, 'update'])->name('members.folders');

    // Fase 2 — etiquetas, relatórios e registro de atividades (docs/fase-2/tags-relatorios-e-logs.md).
    // Sem `org.role`: flags `tags`/`reports`/`audit_log` + permissões verificadas nos controllers.
    // Com a flag desligada, os GETs mostram o estado "Fase 2" e os demais respondem 403.
    Route::get('configuracoes/etiquetas', [TagController::class, 'index'])->name('settings.tags');
    Route::post('etiquetas', [TagController::class, 'store'])->name('tags.store');
    Route::patch('etiquetas/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('etiquetas/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
    Route::post('documentos/etiquetas', [EnvelopeTagController::class, 'apply'])->name('envelopes.tags.apply');
    Route::delete('documentos/{envelope}/etiquetas/{tag}', [EnvelopeTagController::class, 'detach'])->name('envelopes.tags.detach');
    Route::get('relatorios', [ReportController::class, 'index'])->name('reports.index');
    Route::get('relatorios/exportar', [ReportController::class, 'export'])->middleware('throttle:export')->name('reports.export');
    Route::get('configuracoes/registro-de-atividades', [AuditLogController::class, 'index'])->name('settings.audit');

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

            // Fase 2 §2.8 — marca (flag `branding`; desligada, o GET mostra "Fase 2" e as
            // escritas respondem 403). docs/fase-2/branding.md.
            Route::get('marca', [BrandingController::class, 'edit'])->name('settings.branding');
            Route::patch('marca', [BrandingController::class, 'update'])->name('settings.branding.update');
            Route::post('marca/logo', [BrandingLogoController::class, 'store'])->name('settings.branding.logo.store');
            Route::delete('marca/logo', [BrandingLogoController::class, 'destroy'])->name('settings.branding.logo.destroy');

            // Plano e cobrança
            Route::get('plano', [BillingController::class, 'index'])->name('billing.index');
            Route::post('plano/checkout', [BillingCheckoutController::class, 'store'])->name('billing.checkout');
            Route::post('plano/cancelar', [BillingController::class, 'cancel'])
                ->middleware(['org.role:owner', 'password.confirm'])
                ->name('billing.cancel');
            Route::post('plano/reativar', [BillingController::class, 'resume'])->name('billing.resume');
            Route::patch('plano/faturamento', [BillingController::class, 'updateProfile'])->name('billing.profile.update');
            Route::get('plano/pagamentos/{payment}/recibo', [PaymentReceiptController::class, 'show'])->name('billing.payments.receipt');

            // Fase 2, onda D (D-PAY — docs/fase-2/pagamentos-e-fiscal.md). Flag `extended_payments`
            // desligada: 404. Estorno pelo proprietário só quando a política da instalação permite.
            Route::post('plano/pagamentos/{payment}/cancelar', [PaymentActionController::class, 'cancel'])
                ->middleware('throttle:20,1')
                ->name('billing.payments.cancel');
            // Só o proprietário: verificado por permissão no controller (`delete_organization`,
            // a mesma de `billing.cancel`), não por `org.role` — Fase 2 autoriza por permissão.
            Route::post('plano/pagamentos/{payment}/estorno', [PaymentActionController::class, 'refund'])
                ->middleware(['password.confirm', 'throttle:10,1'])
                ->name('billing.payments.refund');
        });

        Route::get('plano/retorno/{outcome}', [BillingCheckoutController::class, 'return'])
            ->whereIn('outcome', ['success', 'failure', 'pending'])
            ->name('billing.return');

        Route::get('notificacoes', [NotificationSettingsController::class, 'edit'])->name('settings.notifications');
        Route::patch('notificacoes', [NotificationSettingsController::class, 'update'])->name('settings.notifications.update');
    });

    Route::get('planos', [PlanController::class, 'index'])->middleware('org.role:owner,admin')->name('plans.index');

    // Fase 2 §2.6/§2.7 (C-PRES, docs/fase-2/presencial-e-lote.md). Sem `org.role`: permissões nas
    // policies (InPersonSessionPolicy, BatchSigningPolicy). Flag desligada: o GET mostra o estado
    // "Fase 2" e os POSTs respondem 404.
    Route::get('presencial/iniciar', [InPersonHostController::class, 'create'])->name('in_person.create');
    Route::post('presencial', [InPersonHostController::class, 'store'])->middleware('throttle:20,1,in-person-start')->name('in_person.store');
    Route::post('presencial/{session}/encerrar', [InPersonHostController::class, 'end'])->where('session', '[A-Za-z0-9]{26}')->name('in_person.end');
    Route::post('documentos/{envelope}/destinatarios/{recipient}/lote', [BatchLinkController::class, 'store'])
        ->middleware('throttle:10,1,batch-link')
        ->scopeBindings()
        ->name('envelopes.recipients.batch');

    // Fase 2 §2.19 (K-RET) — retenção configurável e preservação (docs/fase-2/retencao-e-preservacao.md).
    // Sem `org.role`: `updateSettings` na tela e `manage_legal_holds` (RetentionAuthorization) nos
    // bloqueios. Flag `retention_policies` desligada: a tela mostra "Fase 2", gravar e preservar
    // respondem 404; consultar e liberar um bloqueio já existente continuam possíveis.
    Route::get('configuracoes/retencao', [RetentionController::class, 'edit'])->name('settings.retention');
    Route::put('configuracoes/retencao', [RetentionController::class, 'update'])->middleware('throttle:20,1')->name('settings.retention.update');
    Route::get('configuracoes/retencao/previa', [RetentionController::class, 'preview'])->middleware('throttle:60,1')->name('settings.retention.preview');
    Route::post('configuracoes/retencao/preservacoes', [LegalHoldController::class, 'store'])->middleware('throttle:20,1')->name('settings.retention.holds.store');
    Route::post('preservacoes/{legalHold}/liberar', [LegalHoldController::class, 'release'])->middleware('throttle:20,1')->name('legal_holds.release');
    Route::get('documentos/{envelope}/preservacao', [LegalHoldController::class, 'show'])->middleware('throttle:60,1')->name('envelopes.legal_hold.show');
    Route::post('documentos/{envelope}/preservacao', [LegalHoldController::class, 'storeForEnvelope'])->middleware('throttle:20,1')->name('envelopes.legal_hold.store');
});

// -- Painel interno (platform-admin; NÃO passa por org) ------------------------------------
Route::middleware(['auth', 'verified', 'platform-admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('clientes', [AdminOrganizationController::class, 'index'])->name('organizations.index');
    Route::get('clientes/exportar', [AdminOrganizationController::class, 'export'])->name('organizations.export');
    Route::get('clientes/{organization}', [AdminOrganizationController::class, 'show'])->name('organizations.show');

    // Fase 2, onda D (D-PAY — docs/fase-2/pagamentos-e-fiscal.md): flag `extended_payments`;
    // desligada, a página é o placeholder da Fase 1 e as ações respondem 404. Leitura sem
    // documentos + operações financeiras explícitas (senha confirmada onde move dinheiro).
    Route::get('faturamento', [AdminBillingController::class, 'index'])->name('billing.index');
    Route::prefix('faturamento')->name('billing.')->group(function (): void {
        Route::post('pagamentos/{payment}/estorno', [AdminBillingActionController::class, 'refund'])
            ->where('payment', '[A-Za-z0-9]{26}')
            ->middleware(['password.confirm', 'throttle:20,1'])
            ->name('payments.refund');
        Route::post('pagamentos/{payment}/cancelar', [AdminBillingActionController::class, 'cancel'])
            ->where('payment', '[A-Za-z0-9]{26}')
            ->middleware(['password.confirm', 'throttle:20,1'])
            ->name('payments.cancel');
        Route::post('pagamentos/{payment}/reconsultar', [AdminBillingActionController::class, 'resync'])
            ->where('payment', '[A-Za-z0-9]{26}')
            ->middleware('throttle:30,1')
            ->name('payments.resync');
        Route::post('conciliar', [AdminBillingActionController::class, 'reconcile'])->middleware('throttle:6,1')->name('reconcile');
        Route::post('meios', [AdminBillingActionController::class, 'refreshMethods'])->middleware('throttle:6,1')->name('methods.refresh');
        Route::post('divergencias/{item}/revisar', [AdminBillingActionController::class, 'resolveDivergence'])
            ->whereNumber('item')
            ->middleware('throttle:60,1')
            ->name('divergences.resolve');
    });
    // Fase 2 (docs/fase-2/tags-relatorios-e-logs.md): flags `admin_users`, `admin_audit` e
    // `impersonation`; desligadas, as páginas continuam o placeholder da Fase 1.
    Route::get('usuarios', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('usuarios/{user}/bloquear', [AdminUserController::class, 'block'])->middleware('password.confirm')->name('users.block');
    Route::post('usuarios/{user}/desbloquear', [AdminUserController::class, 'unblock'])->middleware('password.confirm')->name('users.unblock');
    Route::get('auditoria', [AdminAuditController::class, 'index'])->name('audit.index');
    Route::post('clientes/{organization}/acessar-como', [AdminImpersonationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('organizations.impersonate');
    // Fase 3 §3.7 (P3-RISK — docs/fase-3/antifraude.md): fila de revisão humana do antifraude e
    // relatório de precisão. Flag `antifraud` desligada: 404.
    Route::get('antifraude', [AdminRiskReviewController::class, 'index'])->name('risk.index');
    Route::get('antifraude/precisao', [AdminRiskReportController::class, 'precision'])->name('risk.precision');
    Route::get('antifraude/casos/{review}', [AdminRiskReviewController::class, 'show'])
        ->where('review', '[A-Za-z0-9]{26}')
        ->name('risk.show');
    Route::post('antifraude/casos/{review}/decisao', [AdminRiskReviewController::class, 'decide'])
        ->where('review', '[A-Za-z0-9]{26}')
        ->middleware('throttle:30,1')
        ->name('risk.decide');
    Route::get('configuracoes', AdminPlaceholderController::class)->name('settings.index');
});

// Encerrar "acessar como": fora do grupo platform-admin (durante a sessão o usuário autenticado
// é o alvo). Só age sobre a impersonation da própria sessão.
Route::post('admin/acessar-como/encerrar', [AdminImpersonationController::class, 'stop'])
    ->middleware('auth')
    ->name('admin.impersonation.stop');

// -- Fase 2, onda C §2.13 (K-TSA) — TSA da operadora e dossiê ZIP (docs/fase-2/carimbo-e-dossie.md) --
// TSA RFC 3161 INTERNA: application/timestamp-query → application/timestamp-reply. Sem CSRF (cliente
// de máquina); Bearer + IPs permitidos + limite no controller/rota. Flag `operator_tsa` desligada: 404.
Route::post('tsa', TsaController::class)
    ->middleware('throttle:'.max(1, (int) config('assinavelox.tsa.http.rate_per_minute', 120)).',1,tsa-http')
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('tsa.timestamp');

// Dossiê ZIP (flag `dossier_export`; desligada: 404). Pedido idempotente em fila; status em JSON;
// download só autenticado + permissão sobre cada envelope + URL assinada com expiração.
Route::middleware(['auth', 'verified', 'org', 'org.2fa'])->group(function (): void {
    Route::post('documentos/{envelope}/dossie', [DossierExportController::class, 'store'])
        ->middleware('throttle:10,1,dossier-request')
        ->name('envelopes.dossier.store');
    Route::post('dossies/lote', [DossierExportController::class, 'storeBulk'])
        ->middleware('throttle:5,1,dossier-bulk')
        ->name('dossiers.bulk');
    Route::get('dossies/{dossierExport}', [DossierExportController::class, 'show'])
        ->middleware('throttle:120,1,dossier-status')
        ->name('dossiers.show');
    Route::get('dossies/{dossierExport}/baixar', [DossierDownloadController::class, 'show'])
        ->middleware('throttle:download')
        ->name('dossiers.download');
});

// -- Fase 3 §3.10 (P3-AFF) — programa de afiliados (docs/fase-3/afiliados.md) ---------------
// Flag `affiliates` (plataforma) desligada: todas respondem 404 e o cadastro não muda.
// O sistema CALCULA comissões e monta lotes; o repasse é manual, fora da plataforma.
Route::get('indicacao/{code}', [ReferralLinkController::class, 'show'])
    ->where('code', '[A-Za-z0-9]{6,16}')
    ->middleware('throttle:public')
    ->name('affiliates.link');

Route::middleware(['auth', 'verified'])->prefix('afiliados')->name('affiliates.')->group(function (): void {
    Route::get('/', [AffiliatePortalController::class, 'index'])->name('index');
    // Limites com prefixo próprio (3º parâmetro): o contador não é o mesmo do `throttle:N,M`
    // genérico das outras rotas (inclusive o link de verificação de e-mail, 6/min).
    Route::post('/', [AffiliatePortalController::class, 'apply'])
        ->middleware('throttle:10,1,affiliates-apply')
        ->name('apply');
    Route::put('repasse', [AffiliatePortalController::class, 'updatePayout'])
        ->middleware(['password.confirm', 'throttle:10,1,affiliates-payout'])
        ->name('payout.update');
    Route::post('indicacoes/{referral}/revisao', [AffiliatePortalController::class, 'requestReview'])
        ->where('referral', '[A-Za-z0-9]{26}')
        ->middleware('throttle:10,1,affiliates-review')
        ->name('referrals.review');
    Route::get('comissoes/exportar', [AffiliatePortalController::class, 'export'])
        ->middleware('throttle:10,1,affiliates-export')
        ->name('commissions.export');
});

Route::middleware(['auth', 'verified', 'platform-admin'])->prefix('admin/afiliados')->name('admin.affiliates.')->group(function (): void {
    Route::get('/', [AffiliateController::class, 'index'])->name('index');

    Route::get('lotes', [AffiliatePayoutController::class, 'index'])->name('payouts.index');
    Route::post('lotes', [AffiliatePayoutController::class, 'store'])
        ->middleware('throttle:10,1,admin-affiliates-batch')
        ->name('payouts.store');
    Route::get('lotes/{batch}', [AffiliatePayoutController::class, 'show'])
        ->where('batch', '[A-Za-z0-9]{26}')
        ->name('payouts.show');
    Route::post('lotes/{batch}/pago', [AffiliatePayoutController::class, 'markPaid'])
        ->where('batch', '[A-Za-z0-9]{26}')
        ->middleware(['password.confirm', 'throttle:10,1,admin-affiliates-batch'])
        ->name('payouts.paid');
    Route::post('lotes/{batch}/cancelar', [AffiliatePayoutController::class, 'cancel'])
        ->where('batch', '[A-Za-z0-9]{26}')
        ->middleware('throttle:10,1,admin-affiliates-batch')
        ->name('payouts.cancel');
    Route::get('lotes/{batch}/exportar', [AffiliatePayoutController::class, 'export'])
        ->where('batch', '[A-Za-z0-9]{26}')
        ->middleware('throttle:20,1,admin-affiliates-export')
        ->name('payouts.export');

    Route::post('indicacoes/{referral}/revisar', [AffiliateReferralController::class, 'review'])
        ->where('referral', '[A-Za-z0-9]{26}')
        ->middleware('throttle:30,1,admin-affiliates-review')
        ->name('referrals.review');

    Route::prefix('{affiliate}')->where(['affiliate' => '[A-Za-z0-9]{26}'])->group(function (): void {
        Route::get('/', [AffiliateController::class, 'show'])->name('show');
        Route::post('aprovar', [AffiliateController::class, 'approve'])
            ->middleware(['password.confirm', 'throttle:30,1'])
            ->name('approve');
        Route::post('recusar', [AffiliateController::class, 'reject'])
            ->middleware('throttle:30,1')
            ->name('reject');
        Route::post('suspender', [AffiliateController::class, 'suspend'])
            ->middleware(['password.confirm', 'throttle:30,1'])
            ->name('suspend');
        Route::post('reativar', [AffiliateController::class, 'reactivate'])
            ->middleware(['password.confirm', 'throttle:30,1'])
            ->name('reactivate');
        Route::put('taxa', [AffiliateController::class, 'updateRate'])
            ->middleware(['password.confirm', 'throttle:30,1'])
            ->name('rate.update');
    });
});

// -- Fase 3 §3.7 (P3-RISK) — revisão de segurança pela ORGANIZAÇÃO (LGPD art. 20) -------------
// Canal registrado para pedir revisão da observação/suspensão do antifraude. Ver o estado:
// qualquer membro; pedir: owner/admin (checado no controller — sem `org.role`, que exigiria
// uma permissão nova no catálogo de App\Support\Permissions). Flag `antifraud` desligada: 404.
Route::middleware(['auth', 'verified', 'org', 'org.2fa'])->group(function (): void {
    Route::get('revisao-de-seguranca', [RiskAppealController::class, 'show'])->name('risk.appeal.show');
    Route::post('revisao-de-seguranca', [RiskAppealController::class, 'store'])
        ->middleware('throttle:5,60')
        ->name('risk.appeal.store');
});

// -- Fase 3 §3.2 (F-ANCHOR) — âncoras e OCR (docs/fase-3/ancoras-e-ocr.md §8) -----------------
// JSON do editor de campos e da aba "Âncoras" do modelo. Flag `field_anchors` desligada (o
// padrão): 404 em todas (checado nos controllers, antes da Policy). Autorização: `update`.
Route::middleware(['auth', 'verified', 'org', 'org.2fa'])->name('anchors.')->group(function (): void {
    // Sem `throttle` nos GET: o limitador sem nome do Laravel conta por usuário em TODAS as rotas
    // com `throttle:X,1`, e a consulta periódica do painel esgotaria o limite de outras rotas.
    Route::get('documentos/{envelope}/ancoras', [EnvelopeAnchorController::class, 'index'])
        ->name('envelope.index');
    Route::post('documentos/{envelope}/ancoras/detectar', [EnvelopeAnchorController::class, 'detect'])
        ->middleware('throttle:10,1,anchors-detect')->name('envelope.detect');
    Route::post('documentos/{envelope}/sugestoes/confirmar-todas', [EnvelopeAnchorController::class, 'acceptAll'])
        ->middleware('throttle:30,1,anchors-accept-all')->name('suggestions.accept_all');
    Route::post('documentos/{envelope}/sugestoes/{suggestion}/confirmar', [EnvelopeAnchorController::class, 'accept'])
        ->whereUlid('suggestion')->middleware('throttle:120,1,anchors-suggestion')->name('suggestions.accept');
    Route::post('documentos/{envelope}/sugestoes/{suggestion}/descartar', [EnvelopeAnchorController::class, 'discard'])
        ->whereUlid('suggestion')->middleware('throttle:120,1,anchors-suggestion')->name('suggestions.discard');
    Route::get('modelos/{template}/ancoras', [TemplateAnchorRuleController::class, 'index'])
        ->name('template.index');
    // I-3F: limites com prefixo próprio (contador separado do `throttle:N,M` sem nome).
    Route::put('modelos/{template}/ancoras', [TemplateAnchorRuleController::class, 'update'])
        ->middleware('throttle:30,1,anchors-rules-update')->name('template.update');
    Route::post('modelos/{template}/ancoras/testar', [TemplateAnchorRuleController::class, 'test'])
        ->middleware('throttle:10,1,anchors-rules-test')->name('template.test');
});

require __DIR__.'/settings.php';
