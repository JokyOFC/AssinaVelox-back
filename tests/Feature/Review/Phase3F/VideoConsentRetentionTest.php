<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial onda F (produto/LGPD) — consentimento do vídeo sem o prazo à vista
|--------------------------------------------------------------------------
| Página pública, etapa "Vídeo curto para o registro do aceite", diálogo "Gravar vídeo".
| O consentimento marcado ANTES de ligar a câmera diz (VideoStep::CONSENT):
|   "Autorizo gravar e enviar um vídeo curto do meu rosto, sem som, para ficar guardado junto com o
|    registro do meu aceite PELO PRAZO INFORMADO, …"
| mas o diálogo em que a caixa é marcada mostra só `purpose` e `audience` — o prazo ("O vídeo fica
| guardado por até 180 dias", `video.retention`) e o aviso de que o vídeo não verifica identidade
| (`notice`) ficam no cartão atrás do diálogo, coberto pelo overlay. No celular (375 px, visto no
| QA) o cartão nem aparece junto: a pessoa autoriza um "prazo informado" que não está na tela.
|
| resources/js/components/identity/video-capture-step.tsx: o estágio `intro` do
| VideoRecorderDialog não renderiza `video.retention`/`video.retention_kept` nem `step.notice`.
*/

function reviewVideoRecorderDialogSource(): string
{
    $source = (string) file_get_contents(base_path('resources/js/components/identity/video-capture-step.tsx'));
    $start = strpos($source, 'function VideoRecorderDialog');

    expect($start)->not->toBeFalse('VideoRecorderDialog não encontrado.');

    return substr($source, (int) $start);
}

it('o diálogo em que se consente com o vídeo mostra por quanto tempo ele fica guardado', function () {
    $dialog = reviewVideoRecorderDialogSource();

    expect($dialog)->toContain('consent_label');

    expect(preg_match("/'video\\.retention(?:_kept)?'/", $dialog))->toBe(
        1,
        'O consentimento cita "o prazo informado", mas o diálogo onde ele é marcado não mostra o prazo de guarda do vídeo.',
    );
});

it('o diálogo em que se consente com o vídeo repete que ele não verifica identidade', function () {
    $dialog = reviewVideoRecorderDialogSource();

    // O aviso "o vídeo não é usado para verificar sua identidade" precisa estar no diálogo em que
    // a câmera é ligada. (O `toContain` do Pest é variádico: a mensagem que estava como segundo
    // argumento era tratada como outra agulha e fazia o teste falhar sempre.)
    expect($dialog)->toContain('step.notice');
});
