<?php

use App\Services\Signing\GovBr\GovBrTrustAnchors;

require_once __DIR__.'/../../Phase3/GovBr/Support/GovBrHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial I-3A — devolução assinada por OUTRA pessoa vira "Assinatura gov.br" do participante
|--------------------------------------------------------------------------
| GovBrReturnDecision::decide() só compara identidade quando o participante informou CPF no
| envelope (campo `cpf`). Sem esse campo — o caso comum —, nada é comparado: um arquivo assinado
| no portal por QUALQUER conta gov.br (cadeia validada até a âncora fixada) é aceito para este
| participante, entra na cadeia, conta como a assinatura dele e o registro vira
| `participant_govbr` ("Assinatura gov.br (avançada) de participante").
|
| docs/integracoes/gov-br-assinatura.md §6, item 3: "O certificado do signatário precisa
| corresponder ao participante: nome e CPF." docs/fase-3/gov-br.md §4 decidiu "sem CPF informado,
| nada é comparado (o nome só é exibido)", o que contradiz o brief e o rótulo (T1).
*/

beforeEach(fn () => govbrBoot($this));
afterEach(fn () => govbrTeardown($this));

it('sem CPF informado, uma devolução assinada por outra pessoa não é aceita como assinatura gov.br do participante', function () {
    $scenario = govbrScenario($this->work);
    $stranger = govbrCertificate($this->work, 'Pessoa Totalmente Diferente');
    config()->set('assinavelox.govbr.trust_roots', [$stranger['ca']]);
    config()->set('assinavelox.govbr.trust_root_fingerprints', GovBrTrustAnchors::fingerprintsOfFile($stranger['ca']));

    // Maria (sem campo CPF) reserva; quem assina no "portal" é outra pessoa.
    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');
    $returned = govbrSimulate('sign', govbrBaseFile($scenario['base'], $this->work.'/base.pdf'), $this->work.'/devolvido.pdf', $stranger);

    $response = govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $returned);

    expect($response->status())->toBe(422)
        ->and($response->json('documents.0.request.signature.kind'))->not->toBe('participant_govbr');
});
