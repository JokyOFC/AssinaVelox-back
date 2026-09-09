<?php

/*
|--------------------------------------------------------------------------
| Revisão de design — "✓ Validade jurídica" contradiz os próprios termos
|--------------------------------------------------------------------------
| O aside de `layouts/auth-layout.tsx` (login, cadastro, recuperação de senha,
| 2FA, verificação de e-mail) e o hero de `pages/marketing/home.tsx` exibem
| quatro selos de confiança, sem qualquer ressalva:
|
|     ✓ Validade jurídica   ✓ Conforme LGPD
|     ✓ Certificado A1 da operadora   ✓ Trilha de auditoria
|
| Dois deles são afirmações que o produto não sustenta:
|
|  - **"Validade jurídica"** é exatamente o que os Termos de Uso da própria
|    plataforma negam. `docs/juridico/termos-de-uso.md` §3.5 chama-se "Sem
|    garantia de validade jurídica universal" e diz que a Operadora "não
|    garante" aceitação por autoridade, contraparte, cartório, órgão público ou
|    tribunal. A declaração de aceite assinada por cada participante repete a
|    ressalva no item 5, e o rodapé da página de evidências também. Só a porta
|    de entrada promete o contrário.
|
|  - **"Certificado A1 da operadora"** é anunciado como característica fixa,
|    independentemente de haver certificado configurado. Nesta instalação de
|    revisão não há: a finalização real do AV-00006 registrou
|    `signature: skipped` e concluiu com `signature_status = none`, e a mesma
|    tela de login segue prometendo o A1.
|
| arquitetura §2 obriga a interface a dizer exatamente o que acontece; a lista
| de selos diz o que soa melhor.
*/

it('não anuncia validade jurídica como característica garantida', function () {
    $sources = [
        resource_path('js/layouts/auth-layout.tsx'),
        resource_path('js/pages/marketing/home.tsx'),
    ];

    foreach ($sources as $path) {
        expect(file_exists($path))->toBeTrue($path);

        $source = (string) file_get_contents($path);

        expect($source)->not->toContain('Validade jurídica');
    }
});

it('os termos de uso continuam negando a garantia que a tela anuncia', function () {
    $terms = (string) file_get_contents(base_path('docs/juridico/termos-de-uso.md'));

    // Controle do achado: a contradição é entre estes dois textos.
    expect($terms)->toContain('Sem garantia de validade jurídica universal')
        ->and($terms)->toContain('não garante');
});
