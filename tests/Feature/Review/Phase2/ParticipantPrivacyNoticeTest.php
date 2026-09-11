<?php

use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\SigningOrder;
use App\Events\EnvelopeReadyForFinalization;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Domain/Support/DomainHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 2 onda A (produto/semântica) — aviso de privacidade por papel
|--------------------------------------------------------------------------
| A tela "Confirme sua identidade" do VISUALIZADOR (visto no navegador, 375 px) diz:
|   "Para registrar seu aceite, a AssinaVelox gravará data, IP, navegador, a versão
|    exata do documento e o código confirmado por e-mail."
| Mas o visualizador não registra aceite — a própria tela seguinte diz "Como visualizador,
| você não registra aceite nem recusa".
| O aviso completo ("Ver aviso completo") diz ao visualizador e ao APROVADOR que a remetente
| "decidiu solicitar a sua assinatura" e que gravamos "a imagem da sua assinatura
| (desenhada, digitada ou enviada)" — o aprovador aprova "sem representação visual de
| assinatura" (§2.4) e nenhuma imagem é gravada.
| Origem: SignerPageProps::build() usa ConsentText::privacySummary()/privacyNotice()
| (app/Services/Signing/ConsentText.php:418-452), que não variam por papel.
| Obs.: é texto jurídico — a correção precisa passar pela mesma revisão jurídica dos textos
| de testemunha/aprovador (pendência 1 do relatório I-2A), mas hoje o texto é falso.
*/

beforeEach(function () {
    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();

    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('não diz ao visualizador que a AssinaVelox vai "registrar seu aceite"', function () {
    $ctx = domainEnvelope(['Contrato'], [
        ['name' => 'Victor Visualizador', 'email' => 'victor@exemplo.test', 'role' => RecipientRole::Viewer],
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ], SigningOrder::Parallel);

    $props = $this->get(route('sign.show', ['token' => $ctx['tokens']['victor@exemplo.test']]))->viewData('page')['props'];

    expect($props['screen'])->toBe('identify')
        ->and($props['action']['type'])->toBe('view')
        ->and($props['privacy']['summary'])->not->toContain('registrar seu aceite')
        ->and($props['privacy']['notice'])->not->toContain('imagem da sua assinatura');
});

it('não diz ao aprovador que a AssinaVelox grava "a imagem da sua assinatura"', function () {
    $ctx = domainEnvelope(['Contrato'], [
        ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ], SigningOrder::Parallel);

    $props = $this->get(route('sign.show', ['token' => $ctx['tokens']['paula@exemplo.test']]))->viewData('page')['props'];

    expect($props['action']['type'])->toBe('approve')
        ->and($props['action']['requires_signature'])->toBeFalse()
        ->and($props['privacy']['notice'])->not->toContain('imagem da sua assinatura')
        ->and($props['privacy']['notice'])->not->toContain('solicitar a sua assinatura');
});
