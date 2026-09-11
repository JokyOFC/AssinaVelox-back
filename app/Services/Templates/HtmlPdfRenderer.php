<?php

namespace App\Services\Templates;

use Barryvdh\DomPDF\PDF;
use Dompdf\Options;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\File;

/**
 * HTML de modelo → PDF pelo DOMPDF, com o HTML tratado como dado NÃO confiável.
 *
 * Mesmo depois da sanitização ({@see HtmlSanitizer}) o DOMPDF roda "trancado":
 *  - `isRemoteEnabled = false` e protocolos permitidos reduzidos a `data://` — nenhum
 *    `http(s)://` e nenhum `file://` é buscado (sem SSRF, sem leitura de arquivo local);
 *  - `chroot` apontando para um diretório vazio e exclusivo;
 *  - `isPhpEnabled = false` e `isJavascriptEnabled = false`.
 *
 * As opções são aplicadas ANTES de o HTML ser carregado. O CSS de página é nosso
 * ({@see self::document()}); o do cliente só chega por `style` já filtrado.
 */
final class HtmlPdfRenderer
{
    public function __construct(private readonly Repository $config) {}

    /**
     * Documento HTML completo (nosso invólucro + corpo sanitizado e já preenchido).
     */
    public function document(string $body, string $title = 'Documento'): string
    {
        $font = (string) $this->config->get('assinavelox.evidence.font', 'DejaVu Sans');
        $title = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="pt-BR">
            <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
            <title>{$title}</title>
            <style>
            @page { margin: 2.2cm 2cm 2.4cm 2cm; }
            body { font-family: '{$font}', sans-serif; font-size: 10.5pt; line-height: 1.5; color: #111111; }
            h1 { font-size: 16pt; margin: 0 0 12pt 0; }
            h2 { font-size: 13pt; margin: 14pt 0 8pt 0; }
            h3 { font-size: 11.5pt; margin: 12pt 0 6pt 0; }
            p { margin: 0 0 8pt 0; }
            table { border-collapse: collapse; width: 100%; margin: 0 0 10pt 0; }
            td, th { border: 1px solid #9aa3b2; padding: 4pt 6pt; vertical-align: top; }
            </style>
            </head>
            <body>
            {$body}
            </body>
            </html>
            HTML;
    }

    /**
     * Bytes do PDF.
     */
    public function render(string $html): string
    {
        return $this->make($html)->output();
    }

    public function make(string $html): PDF
    {
        /** @var PDF $pdf */
        $pdf = app('dompdf.wrapper');

        $this->lockDown($pdf->getDomPDF()->getOptions());

        return $pdf->setPaper('a4')->loadHTML($html);
    }

    /**
     * Opções que tornam o DOMPDF seguro para HTML de cliente. Público para os testes
     * provarem a configuração efetiva.
     */
    public function lockDown(Options $options): Options
    {
        $deny = [self::class, 'denyUri'];

        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        // `file://`, `http://` e `https://` continuam como CHAVES, mas com uma regra que
        // recusa tudo: partes do DOMPDF (ex.: Css\Stylesheet) indexam o protocolo sem
        // conferir se ele existe, e a ausência da chave virava exceção em vez de recusa.
        $options->setAllowedProtocols([
            'data://' => ['rules' => []],
            'file://' => ['rules' => [$deny]],
            'http://' => ['rules' => [$deny]],
            'https://' => ['rules' => [$deny]],
        ]);
        $options->setChroot([$this->jail()]);
        $options->setDefaultFont((string) $this->config->get('assinavelox.evidence.font', 'DejaVu Sans'));

        return $options;
    }

    /**
     * Regra de protocolo do DOMPDF (`[permitido, mensagem]`): nenhum recurso externo ou
     * local é carregado a partir de HTML de modelo.
     *
     * @return array{0: false, 1: string}
     */
    public static function denyUri(string $uri): array
    {
        return [false, 'Recursos externos e arquivos locais não são carregados em modelos.'];
    }

    /**
     * Diretório vazio usado como `chroot`: nada nele pode ser lido.
     */
    private function jail(): string
    {
        $path = storage_path('app/tmp/template-html-jail');

        if (! is_dir($path)) {
            File::ensureDirectoryExists($path, 0700);
        }

        return $path;
    }
}
