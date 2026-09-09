<?php

namespace App\Services\Envelopes\Finalization;

use App\Services\Envelopes\Finalization\Exceptions\FinalizationException;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Config\Repository;

/**
 * Etapa (b): a página de evidências, em Blade → DOMPDF.
 *
 * Gerada **antes** da assinatura, para que fique coberta por ela quando houver certificado.
 * O DOMPDF roda dentro do PHP (é a única biblioteca de PDF que roda aqui — o resto é o
 * pdftool), sem rede: `enable_remote` fica desligado e o QR code entra como `data:` URI.
 *
 * A fonte padrão é DejaVu Sans, que acompanha o dompdf e tem acentuação completa. Com a
 * fonte `serif` padrão do pacote, "ação" e "coração" saem certos, mas os traços tipográficos
 * e o `·` do rodapé variam por instalação; fixar a fonte remove essa loteria.
 */
class EvidenceRenderer
{
    public function __construct(private readonly Repository $config) {}

    /**
     * Renderiza e grava em `$outputPath`. Devolve o caminho.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(array $data, string $outputPath): string
    {
        $view = (string) $this->config->get('assinavelox.evidence.view', 'evidence.page');
        $paper = (string) $this->config->get('assinavelox.evidence.paper', 'a4');
        $font = (string) $this->config->get('assinavelox.evidence.font', 'DejaVu Sans');

        // setOption() (singular) muda a instância de Options já configurada pelo pacote;
        // setOptions() a SUBSTITUI inteira e derrubaria font_dir/font_cache/temp_dir/chroot,
        // o que quebra o cache de fontes e o `data:` URI do QR code.
        $pdf = Pdf::loadView($view, ['evidence' => $data])
            ->setPaper($paper)
            ->setOption('defaultFont', $font)
            ->setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', false)
            ->setOption('isJavascriptEnabled', false);

        $bytes = $pdf->output();

        if ($bytes === '' || file_put_contents($outputPath, $bytes, LOCK_EX) === false) {
            throw FinalizationException::writeFailed('evidence', ['output' => basename($outputPath)]);
        }

        return $outputPath;
    }
}
