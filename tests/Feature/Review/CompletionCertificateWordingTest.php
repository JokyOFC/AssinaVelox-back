<?php

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Revisão final (semântica) — "certificado de conclusão" não existe no produto
|--------------------------------------------------------------------------
| Duas telas descrevem os dados da empresa dizendo onde eles aparecem:
|
|   resources/js/pages/settings/general.tsx:163
|     "Aparece nos convites, no certificado de conclusão e nos recibos."
|   resources/js/pages/organizations/create.tsx:43   (primeira tela após o cadastro)
|     "Aparecem nos convites e no certificado de conclusão."
|
| A expressão vem de DESIGN_SYSTEM.md §"Card Empresa", mas arquitetura.md §2 tem
| precedência (RECONCILIACAO.md) e é explícita: "Página de evidências ≠ certificado
| digital". O produto sustenta a distinção em toda parte — menos aqui:
|
|  - o artefato realmente gerado se chama **relatório de evidências**
|    (`document_versions.kind = evidence`; botões "Relatório de evidências",
|    "Baixar relatório (PDF)"; rota `envelopes.download/{type=evidence}`);
|  - a própria página de evidências fecha com "Esta página **não é um certificado
|    digital** nem é emitida por autoridade certificadora";
|  - a verificação pública repete "não é um certificado emitido por autoridade
|    certificadora".
|
| Ou seja: a tela de configurações promete um "certificado" que o resto do produto passa
| o tempo todo negando ser certificado — e que o usuário nunca encontra com esse nome em
| lugar nenhum da interface.
*/

it('nenhuma tela chama o relatório de evidências de "certificado de conclusão"', function () {
    $finder = Finder::create()
        ->files()
        ->in([resource_path('js/pages'), resource_path('js/components'), resource_path('js/layouts')])
        ->name('*.tsx');

    $offenders = [];

    foreach ($finder as $file) {
        $code = $file->getContents();

        if (preg_match_all('/certificado de conclus[ãa]o/iu', $code, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$text, $offset]) {
                $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                $offenders[] = str_replace('\\', '/', $file->getRelativePathname()).':'.$line.' → "'.$text.'"';
            }
        }
    }

    expect($offenders)->toBe(
        [],
        "Interface promete um \"certificado de conclusão\" que o produto não emite:\n".implode("\n", $offenders)
    );
});

it('o produto nega, nas telas de evidência e verificação, ser um certificado — o controle do achado', function () {
    $evidence = (string) file_get_contents(resource_path('js/pages/envelopes/evidence.tsx'));
    $verify = (string) file_get_contents(resource_path('js/pages/verify/show.tsx'));

    expect($evidence)->toContain('não é um certificado digital')
        ->and($verify)->toContain('não é um certificado emitido por autoridade');
});
