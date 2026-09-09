<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial final — acesso do signatário à própria prova
|--------------------------------------------------------------------------
|
| DEFEITO (incoerência entre os três encerramentos). Quem já registrou o aceite perde o
| acesso ao próprio comprovante quando o envelope é CANCELADO ou RECUSADO por outro
| participante — mas não quando ele EXPIRA.
|
| O módulo de envio já reconheceu a regra e a implementou uma vez só. Em
| `ExpireEnvelopes::expire()` (app/Services/Envelopes/Sending/ExpireEnvelopes.php:164):
|
|     // Quem já assinou mantém o link: é por ele que a pessoa chega ao próprio
|     // comprovante de aceite, e o prazo do envelope não apaga o que ela fez.
|     $this->links->revokeForEnvelope($locked, exceptRecipientIds: $signed);
|
| e o docblock de `AccessLinks::revokeForEnvelope()` (AccessLinks.php:107) descreve
| `$exceptRecipientIds` exatamente assim: "revogá-lo apagava, para essa pessoa, a prova do
| que ela fez. O resolver já impede que um envelope fora de `in_progress` volte a ser
| assinado, então manter o link não reabre nada."
|
| Os outros dois caminhos não usam o parâmetro:
|
|  - `CancelEnvelope::handle()` (Sending/CancelEnvelope.php:84):
|        $this->links->revokeForEnvelope($locked);            // sem exceção nenhuma
|  - `RecordRefusal::closeEnvelope()` (Signing/RecordRefusal.php:136) preserva apenas o
|    link de QUEM RECUSOU e revoga todos os demais por SQL direto:
|        ->where('recipient_id', '!=', $refusedBy->getKey())
|
| Consequência: em um envelope paralelo em que Maria já assinou, basta o remetente
| cancelar (ou Carlos recusar) para o link de Maria virar 404 genérico —
| `SignerLinkResolver::resolve()` recusa link revogado antes de qualquer tela. Ela perde a
| única porta para o comprovante do aceite e para o documento que assinou (o download do
| signatário exige aceite OU sessão viva, e ambos passam pela resolução do token). O
| aceite continua no banco e continua sendo evidência contra ela; o acesso dela à prova é
| que some.
|
| Correção esperada: os três encerramentos preservarem o link de quem já assinou, como a
| expiração já faz.
*/

use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Models\RecipientAccessLink;
use App\Models\SignatureAcceptance;
use App\Services\Envelopes\Sending\CancelEnvelope;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';

beforeEach(function (): void {
    $this->work = storage_path('framework/testing/review-closure-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();

    $this->ctx = signerEnvelope([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.test', 'fields' => [FieldType::Signature]],
    ], SigningOrder::Parallel);

    // Maria registra o aceite pelo fluxo real: sessão, código por e-mail e POST do aceite.
    $token = $this->ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect(route('sign.show', ['token' => $token]));

    expect(SignatureAcceptance::query()->count())->toBe(1)
        ->and($this->ctx['recipients']['maria@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Signed);
});

afterEach(function (): void {
    File::deleteDirectory($this->work ?? '');
});

it('mantém o acesso de quem já assinou quando o remetente cancela o documento', function () {
    app(CancelEnvelope::class)->handle($this->ctx['envelope']->fresh(), 'Mudança de escopo.');

    $envelope = $this->ctx['envelope']->fresh();
    expect($envelope->status)->toBe(EnvelopeStatus::Canceled);

    $maria = $this->ctx['recipients']['maria@exemplo.test'];
    $link = RecipientAccessLink::withoutOrganizationScope()
        ->where('recipient_id', $maria->id)
        ->latest('id')
        ->firstOrFail();

    expect($link->revoked_at)->toBeNull('o link de quem já assinou foi revogado pelo cancelamento');

    // E a porta continua abrindo: a tela do comprovante, não o 404 genérico.
    $this->get(route('sign.show', ['token' => $this->ctx['tokens']['maria@exemplo.test']]))->assertOk();
});

it('mantém o acesso de quem já assinou quando outro participante recusa', function () {
    $carlosToken = $this->ctx['tokens']['carlos@exemplo.test'];
    authenticateSigner($this, $carlosToken);

    $this->post(route('sign.refuse', ['token' => $carlosToken]), [
        'reason' => 'Não concordo com a cláusula quinta do contrato.',
    ])->assertRedirect();

    $envelope = $this->ctx['envelope']->fresh();
    expect($envelope->status)->toBe(EnvelopeStatus::Refused);

    $maria = $this->ctx['recipients']['maria@exemplo.test'];
    $link = RecipientAccessLink::withoutOrganizationScope()
        ->where('recipient_id', $maria->id)
        ->latest('id')
        ->firstOrFail();

    expect($link->revoked_at)->toBeNull('o link de quem já assinou foi revogado pela recusa de outro participante');

    $this->get(route('sign.show', ['token' => $this->ctx['tokens']['maria@exemplo.test']]))->assertOk();
});
