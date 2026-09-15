<?php

namespace App\Services\CloudImport\Http;

use php_user_filter;

/**
 * Filtro de escrita do temporário do download (docs/fase-3/conectores.md §6).
 *
 * Conta os bytes que CHEGAM AO DISCO — já descomprimidos, quando o provedor manda um
 * Content-Encoding que não foi pedido (o cURL decodifica mesmo assim). O `progress` e o
 * `Content-Length` só enxergam os bytes da rede. Passado o teto, o resto é descartado e a
 * escrita falha: o cURL aborta a transferência na hora (revisão adversarial da onda G).
 */
final class BoundedWriteFilter extends php_user_filter
{
    public const NAME = 'assinavelox.bounded_write';

    private int $written = 0;

    private bool $over = false;

    public static function register(): void
    {
        if (! in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        $limit = $this->params;

        while ($bucket = stream_bucket_make_writeable($in)) {
            // No PHP 8.3 o balde é um objeto sem tipo: o tamanho vem de `datalen` quando é inteiro,
            // senão do próprio dado.
            $length = isset($bucket->datalen) && is_int($bucket->datalen)
                ? $bucket->datalen
                : strlen(is_string($bucket->data ?? null) ? $bucket->data : '');
            $consumed += $length;

            if ($this->over) {
                continue;
            }

            $this->written += $length;

            if ($limit instanceof DownloadLimit && $limit->exceededBy($this->written)) {
                $this->over = true;

                continue;
            }

            stream_bucket_append($out, $bucket);
        }

        return $this->over ? PSFS_ERR_FATAL : PSFS_PASS_ON;
    }
}
