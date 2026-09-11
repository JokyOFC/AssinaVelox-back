<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 2 onda C (texto público) — marcador "(s)" e "PAdES-B-T" na verificação
|--------------------------------------------------------------------------
| 1. Verificação pública de um envelope `mixed` (visto no navegador, código YUBR-SPZQ-4EJD):
|    "Resultado técnico da validação no momento da conclusão: 2 assinatura(s) íntegra(s) e
|    válida(s) na conclusão…". A Fase 1 e a onda A já proibiram o marcador "(s)"
|    (SignerCopyAndLabelsTest, Review/Phase2/PluralMarkersPhase2Test); a frase nova está em
|    app/Services/Signing/Certificates/ParticipantSignatureNarrative.php:155.
|
| 2. O rótulo de finalidade do carimbo da assinatura é "Carimbo da assinatura (não anunciado como
|    PAdES-B-T)" (app/Services/Timestamp/TimestampEvidence.php:113), exibido como selo na página
|    pública e na de evidências. Roadmap T2: a interface continua dizendo B-B até o checklist; um
|    rótulo que cita "PAdES-B-T" para o público anuncia exatamente o que não pode ser anunciado — e é
|    jargão sem sentido para um leigo. Hoje nenhum código grava carimbo com finalidade `signature`
|    (a integração na finalização está pendente), então o defeito é latente: aparece no dia em que
|    PadesBtSigner for ligado.
*/

use App\Services\Timestamp\Models\TimestampToken;
use App\Services\Timestamp\TimestampEvidence;

it('não usa o marcador "(s)" no resumo público da validação das assinaturas de participante', function () {
    $relative = 'app/Services/Signing/Certificates/ParticipantSignatureNarrative.php';

    preg_match_all('/\p{L}+\(s\)/u', (string) file_get_contents(base_path($relative)), $matches);

    expect($matches[0])->toBe([], "{$relative} usa marcador de plural: ".implode(', ', $matches[0]));
});

it('o rótulo do carimbo da assinatura não cita PAdES-B-T na interface', function () {
    expect(TimestampEvidence::purposeLabel(TimestampToken::PURPOSE_SIGNATURE))
        ->not->toContain('B-T');
});
