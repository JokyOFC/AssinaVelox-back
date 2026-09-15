<?php

namespace App\Services\Identity;

use App\Services\Identity\Exceptions\CaptureRejectedException;

/**
 * Confere, só lendo bytes, se o arquivo enviado é um vídeo WebM/Matroska ou MP4
 * (Fase 3 §3.3, docs/fase-3/captura-de-video.md §3). Nada é decodificado, transcodificado ou
 * executado: nenhum processo externo, nenhuma biblioteca de mídia.
 *
 * - WebM/Matroska: começa pelo cabeçalho EBML (`1A 45 DF A3`) com `DocType` `webm` ou
 *   `matroska`, seguido do `Segment`. De `Info` sai a duração (`Duration` × `TimecodeScale`),
 *   quando o arquivo a declara — o WebM do MediaRecorder costuma não declarar. De `Tracks`
 *   sai a presença de trilha de vídeo (`TrackType = 1`) e as dimensões declaradas.
 * - MP4: a primeira caixa é `ftyp` com uma marca ISO de vídeo; na `moov`, `mvhd` (ou `mehd`
 *   no MP4 fragmentado) dá a duração e um `trak` com `hdlr = vide` prova a trilha de vídeo.
 *
 * Arquivo sem trilha de vídeo (áudio puro, HEIC/AVIF que também usam `ftyp`), HTML ou qualquer
 * outro formato com extensão trocada é recusado com a mesma mensagem genérica: ela nunca ecoa
 * o conteúdo. A leitura tem limite de elementos (sem recursão aberta) e só roda sobre os bytes
 * já limitados pelo tamanho máximo do envio.
 */
final class VideoContainerInspector
{
    public const WEBM = 'webm';

    public const MATROSKA = 'matroska';

    public const MP4 = 'mp4';

    /** Limite de elementos/caixas percorridos por arquivo. */
    private const MAX_ELEMENTS = 4096;

    private const EBML_MAGIC = "\x1A\x45\xDF\xA3";

    private const EBML_HEADER = 0x1A45DFA3;

    private const EBML_DOCTYPE = 0x4282;

    private const MKV_SEGMENT = 0x18538067;

    private const MKV_INFO = 0x1549A966;

    private const MKV_TIMECODE_SCALE = 0x2AD7B1;

    private const MKV_DURATION = 0x4489;

    private const MKV_TRACKS = 0x1654AE6B;

    private const MKV_TRACK_ENTRY = 0xAE;

    private const MKV_TRACK_TYPE = 0x83;

    private const MKV_VIDEO = 0xE0;

    private const MKV_PIXEL_WIDTH = 0xB0;

    private const MKV_PIXEL_HEIGHT = 0xBA;

    private const MKV_CLUSTER = 0x1F43B675;

    private const MKV_CLUSTER_TIMECODE = 0xE7;

    private const MKV_SIMPLE_BLOCK = 0xA3;

    private const MKV_BLOCK_GROUP = 0xA0;

    private const MKV_BLOCK = 0xA1;

    /** Limite de elementos na estimativa de duração pelos blocos (arquivo já limitado em bytes). */
    private const MAX_ESTIMATE_ELEMENTS = 200_000;

    /** Marcas `ftyp` de contêiner ISO de vídeo. HEIC/AVIF (`heic`, `mif1`, `avif`) ficam de fora. */
    private const MP4_BRANDS = ['isom', 'iso2', 'iso3', 'iso4', 'iso5', 'iso6', 'iso8', 'iso9', 'mp41', 'mp42', 'avc1', 'dash', 'M4V ', 'mmp4', 'msnv'];

    /**
     * @return array{container: string, mime: string, extension: string, duration_ms: int|null, estimated_duration_ms: int|null, width: int, height: int}
     *
     * @throws CaptureRejectedException
     */
    public function inspect(string $bytes): array
    {
        if (strlen($bytes) < 16) {
            throw self::invalid();
        }

        if (str_starts_with($bytes, self::EBML_MAGIC)) {
            return $this->matroska($bytes);
        }

        if (substr($bytes, 4, 4) === 'ftyp') {
            return $this->mp4($bytes);
        }

        throw self::invalid();
    }

    /**
     * Content-Type fixo por contêiner (nunca o que o navegador declarou).
     */
    public static function mimeFor(?string $container): string
    {
        return match ($container) {
            self::MP4 => 'video/mp4',
            self::MATROSKA => 'video/x-matroska',
            default => 'video/webm',
        };
    }

    public static function extensionFor(?string $container): string
    {
        return match ($container) {
            self::MP4 => 'mp4',
            self::MATROSKA => 'mkv',
            default => 'webm',
        };
    }

    public static function labelFor(?string $container): string
    {
        return match ($container) {
            self::MP4 => 'MP4',
            self::MATROSKA => 'Matroska',
            self::WEBM => 'WebM',
            default => 'vídeo',
        };
    }

    public static function invalid(): CaptureRejectedException
    {
        return new CaptureRejectedException('invalid_video', 'O arquivo enviado não é um vídeo WebM ou MP4 válido. Grave de novo pela câmera desta página.');
    }

    // -- WebM / Matroska (EBML) ------------------------------------------------------------

    /**
     * @return array{container: string, mime: string, extension: string, duration_ms: int|null, estimated_duration_ms: int|null, width: int, height: int}
     */
    private function matroska(string $b): array
    {
        $length = strlen($b);
        $budget = self::MAX_ELEMENTS;

        $header = $this->element($b, 0, $length);

        if ($header === null || $header['id'] !== self::EBML_HEADER || $header['size'] < 0 || $length < $header['data'] + $header['size']) {
            throw self::invalid();
        }

        $headerEnd = $header['data'] + $header['size'];
        $docType = '';

        foreach ($this->children($b, $header['data'], $headerEnd, $budget) as $child) {
            if ($child['id'] === self::EBML_DOCTYPE) {
                $docType = rtrim(substr($b, $child['data'], $child['end'] - $child['data']), "\0");
            }
        }

        if (! in_array($docType, [self::WEBM, self::MATROSKA], true)) {
            throw self::invalid();
        }

        $segment = null;

        foreach ($this->children($b, $headerEnd, $length, $budget, true, [self::MKV_SEGMENT]) as $child) {
            if ($child['id'] === self::MKV_SEGMENT) {
                $segment = $child;
            }
        }

        if ($segment === null) {
            throw self::invalid();
        }

        $scale = 1_000_000;
        $duration = null;
        $hasVideo = false;
        $width = 0;
        $height = 0;
        $firstCluster = null;

        foreach ($this->children($b, $segment['data'], $segment['end'], $budget, true, [self::MKV_CLUSTER]) as $child) {
            if ($child['id'] === self::MKV_CLUSTER) {
                $firstCluster = $child;
            } elseif ($child['id'] === self::MKV_INFO) {
                foreach ($this->children($b, $child['data'], $child['end'], $budget) as $info) {
                    if ($info['id'] === self::MKV_TIMECODE_SCALE) {
                        $scale = $this->uint($b, $info['data'], $info['end'] - $info['data']) ?: 1_000_000;
                    } elseif ($info['id'] === self::MKV_DURATION) {
                        $duration = $this->float($b, $info['data'], $info['end'] - $info['data']);
                    }
                }
            } elseif ($child['id'] === self::MKV_TRACKS) {
                foreach ($this->children($b, $child['data'], $child['end'], $budget) as $entry) {
                    if ($entry['id'] !== self::MKV_TRACK_ENTRY) {
                        continue;
                    }

                    $type = null;
                    $trackWidth = 0;
                    $trackHeight = 0;

                    foreach ($this->children($b, $entry['data'], $entry['end'], $budget) as $field) {
                        if ($field['id'] === self::MKV_TRACK_TYPE) {
                            $type = $this->uint($b, $field['data'], $field['end'] - $field['data']);
                        } elseif ($field['id'] === self::MKV_VIDEO) {
                            foreach ($this->children($b, $field['data'], $field['end'], $budget) as $video) {
                                if ($video['id'] === self::MKV_PIXEL_WIDTH) {
                                    $trackWidth = (int) $this->uint($b, $video['data'], $video['end'] - $video['data']);
                                } elseif ($video['id'] === self::MKV_PIXEL_HEIGHT) {
                                    $trackHeight = (int) $this->uint($b, $video['data'], $video['end'] - $video['data']);
                                }
                            }
                        }
                    }

                    if ($type === 1 && ! $hasVideo) {
                        $hasVideo = true;
                        $width = $trackWidth;
                        $height = $trackHeight;
                    }
                }
            }
        }

        if (! $hasVideo) {
            throw self::invalid();
        }

        $durationMs = null;

        if ($duration !== null && is_finite($duration) && $duration >= 0) {
            $ms = $duration * $scale / 1_000_000;
            $durationMs = $ms < 2_000_000_000 ? (int) round($ms) : 2_000_000_000;
        }

        // Sem `Duration` (o WebM do MediaRecorder): a duração é ESTIMADA pelos tempos dos blocos
        // (Cluster/Timecode + tempo relativo de cada SimpleBlock/Block), sem decodificar nada. Só
        // serve para impor a duração máxima — nunca é gravada como duração do arquivo.
        $estimated = $durationMs === null && $firstCluster !== null
            ? $this->estimateDurationMs($b, $firstCluster['data'], $segment['end'], $scale)
            : null;

        return [
            'container' => $docType,
            'mime' => self::mimeFor($docType),
            'extension' => self::extensionFor($docType),
            'duration_ms' => $durationMs,
            'estimated_duration_ms' => $estimated,
            'width' => self::dimension($width),
            'height' => self::dimension($height),
        ];
    }

    /**
     * Varredura linear a partir do primeiro Cluster: entra nos mestres Cluster e BlockGroup
     * (tamanho conhecido ou desconhecido) e pula os demais elementos pelo tamanho. Limitada em
     * elementos; qualquer coisa ilegível encerra a estimativa com o que já foi lido.
     */
    private function estimateDurationMs(string $b, int $start, int $end, int $scale): ?int
    {
        $pos = $start;
        $budget = self::MAX_ESTIMATE_ELEMENTS;
        $clusterTime = null;
        $min = null;
        $max = null;

        while ($pos < $end && --$budget > 0) {
            $element = $this->element($b, $pos, $end);

            if ($element === null) {
                break;
            }

            if ($element['id'] === self::MKV_CLUSTER || $element['id'] === self::MKV_BLOCK_GROUP) {
                $pos = $element['data'];

                continue;
            }

            if ($element['size'] < 0 || $end < $element['data'] + $element['size']) {
                break;
            }

            $dataEnd = $element['data'] + $element['size'];

            if ($element['id'] === self::MKV_CLUSTER_TIMECODE) {
                $clusterTime = $this->uint($b, $element['data'], $element['size']);
            } elseif (($element['id'] === self::MKV_SIMPLE_BLOCK || $element['id'] === self::MKV_BLOCK) && $clusterTime !== null) {
                $track = $this->vint($b, $element['data'], $dataEnd, false);

                if ($track !== null && $dataEnd >= $element['data'] + $track[1] + 2) {
                    $relative = $this->uint($b, $element['data'] + $track[1], 2) ?? 0;
                    $time = $clusterTime + ($relative >= 0x8000 ? $relative - 0x10000 : $relative);
                    $min = $min === null ? $time : min($min, $time);
                    $max = $max === null ? $time : max($max, $time);
                }
            }

            $pos = $dataEnd;
        }

        if ($min === null || $max === null || $max < $min) {
            return null;
        }

        $ms = ($max - $min) * $scale / 1_000_000;

        return $ms < 2_000_000_000 ? (int) round($ms) : 2_000_000_000;
    }

    /**
     * Inteiro de tamanho variável do EBML. Com `$isId`, mantém o marcador de tamanho (IDs têm
     * até 4 bytes). Tamanho com todos os bits de valor em 1 = desconhecido (`-1`).
     *
     * @return array{0: int, 1: int}|null [valor, bytes lidos]
     */
    private function vint(string $b, int $pos, int $end, bool $isId): ?array
    {
        if ($pos >= $end) {
            return null;
        }

        $first = ord($b[$pos]);

        if ($first === 0) {
            return null;
        }

        $length = 1;
        $mask = 0x80;

        while (($first & $mask) === 0) {
            $length++;
            $mask >>= 1;
        }

        if ($pos + $length > $end || ($isId && $length > 4)) {
            return null;
        }

        $value = $isId ? $first : ($first & ($mask - 1));
        $allOnes = ! $isId && $value === $mask - 1;

        for ($i = 1; $i < $length; $i++) {
            $byte = ord($b[$pos + $i]);
            $value = ($value << 8) | $byte;
            $allOnes = $allOnes && $byte === 0xFF;
        }

        return [$allOnes ? -1 : $value, $length];
    }

    /**
     * @return array{id: int, size: int, data: int}|null
     */
    private function element(string $b, int $pos, int $end): ?array
    {
        $id = $this->vint($b, $pos, $end, true);

        if ($id === null) {
            return null;
        }

        $size = $this->vint($b, $pos + $id[1], $end, false);

        if ($size === null) {
            return null;
        }

        return ['id' => $id[0], 'size' => $size[0], 'data' => $pos + $id[1] + $size[1]];
    }

    /**
     * Filhos de um elemento mestre no intervalo [start, end). Com `$tolerant`, um elemento
     * cortado no fim do arquivo encerra a leitura em vez de recusar (o `Segment` do MediaRecorder
     * tem tamanho desconhecido). Para no primeiro elemento de `$stopAt` (inclusive).
     *
     * @param  list<int>  $stopAt
     * @return list<array{id: int, size: int, data: int, end: int}>
     */
    private function children(string $b, int $start, int $end, int &$budget, bool $tolerant = false, array $stopAt = []): array
    {
        $out = [];
        $pos = $start;

        while ($pos < $end) {
            if (--$budget < 0) {
                throw self::invalid();
            }

            $element = $this->element($b, $pos, $end);

            if ($element === null) {
                if ($tolerant) {
                    break;
                }

                throw self::invalid();
            }

            $elementEnd = $element['size'] < 0 ? $end : $element['data'] + $element['size'];

            if ($elementEnd > $end) {
                if (! $tolerant) {
                    throw self::invalid();
                }

                $elementEnd = $end;
            }

            $out[] = $element + ['end' => $elementEnd];

            if (in_array($element['id'], $stopAt, true) || $element['size'] < 0) {
                break;
            }

            $pos = $elementEnd;
        }

        return $out;
    }

    private function uint(string $b, int $pos, int $length): ?int
    {
        if ($length < 1 || $length > 8) {
            return null;
        }

        $value = 0;

        for ($i = 0; $i < $length; $i++) {
            $value = ($value << 8) | ord($b[$pos + $i]);
        }

        return $value;
    }

    private function float(string $b, int $pos, int $length): ?float
    {
        $raw = substr($b, $pos, $length);

        $value = match ($length) {
            4 => unpack('G', $raw),
            8 => unpack('E', $raw),
            default => false,
        };

        return is_array($value) && is_float($value[1]) ? $value[1] : null;
    }

    // -- MP4 (ISO BMFF) --------------------------------------------------------------------

    /**
     * @return array{container: string, mime: string, extension: string, duration_ms: int|null, estimated_duration_ms: int|null, width: int, height: int}
     */
    private function mp4(string $b): array
    {
        $length = strlen($b);
        $ftypSize = $this->u32($b, 0);

        if ($ftypSize < 16 || $ftypSize > 4096 || $ftypSize > $length) {
            throw self::invalid();
        }

        $brands = [substr($b, 8, 4)];

        for ($p = 16; $p + 4 <= $ftypSize; $p += 4) {
            $brands[] = substr($b, $p, 4);
        }

        if (array_intersect($brands, self::MP4_BRANDS) === []) {
            throw self::invalid();
        }

        $budget = self::MAX_ELEMENTS;
        $moov = null;

        foreach ($this->boxes($b, 0, $length, $budget, true) as $box) {
            if ($box['type'] === 'moov') {
                $moov = $box;
                break;
            }
        }

        if ($moov === null) {
            throw self::invalid();
        }

        $timescale = 0;
        $duration = 0;
        $fragmentDuration = 0;
        $hasVideo = false;
        $width = 0;
        $height = 0;

        foreach ($this->boxes($b, $moov['data'], $moov['end'], $budget) as $box) {
            if ($box['type'] === 'mvhd') {
                [$timescale, $duration] = $this->mvhd($b, $box);
            } elseif ($box['type'] === 'mvex') {
                foreach ($this->boxes($b, $box['data'], $box['end'], $budget) as $inner) {
                    if ($inner['type'] === 'mehd') {
                        $fragmentDuration = $this->fullBoxValue($b, $inner, 4);
                    }
                }
            } elseif ($box['type'] === 'trak') {
                $isVideo = false;
                $trackWidth = 0;
                $trackHeight = 0;

                foreach ($this->boxes($b, $box['data'], $box['end'], $budget) as $inner) {
                    if ($inner['type'] === 'tkhd' && $inner['end'] - $inner['data'] >= 84) {
                        $trackWidth = $this->u32($b, $inner['end'] - 8) >> 16;
                        $trackHeight = $this->u32($b, $inner['end'] - 4) >> 16;
                    } elseif ($inner['type'] === 'mdia') {
                        foreach ($this->boxes($b, $inner['data'], $inner['end'], $budget) as $media) {
                            if ($media['type'] === 'hdlr' && $media['end'] - $media['data'] >= 12) {
                                $isVideo = $isVideo || substr($b, $media['data'] + 8, 4) === 'vide';
                            }
                        }
                    }
                }

                if ($isVideo && ! $hasVideo) {
                    $hasVideo = true;
                    $width = $trackWidth;
                    $height = $trackHeight;
                }
            }
        }

        if (! $hasVideo) {
            throw self::invalid();
        }

        $units = $duration > 0 && $duration !== 0xFFFFFFFF ? $duration : $fragmentDuration;
        $durationMs = null;

        if ($timescale > 0 && $units > 0) {
            $ms = $units / $timescale * 1000;
            $durationMs = $ms < 2_000_000_000 ? (int) round($ms) : 2_000_000_000;
        }

        return [
            'container' => self::MP4,
            'mime' => self::mimeFor(self::MP4),
            'extension' => self::extensionFor(self::MP4),
            'duration_ms' => $durationMs,
            'estimated_duration_ms' => null,
            'width' => self::dimension($width),
            'height' => self::dimension($height),
        ];
    }

    /**
     * @param  array{type: string, data: int, end: int}  $box
     * @return array{0: int, 1: int} [timescale, duração em unidades]
     */
    private function mvhd(string $b, array $box): array
    {
        $size = $box['end'] - $box['data'];
        $version = ord($b[$box['data']] ?? "\0");

        if ($version === 1 && $size >= 32) {
            return [$this->u32($b, $box['data'] + 20), $this->u64($b, $box['data'] + 24)];
        }

        if ($size >= 20) {
            return [$this->u32($b, $box['data'] + 12), $this->u32($b, $box['data'] + 16)];
        }

        return [0, 0];
    }

    /**
     * Valor logo depois de versão+flags (caixa "full"): 32 bits na versão 0, 64 na 1.
     *
     * @param  array{type: string, data: int, end: int}  $box
     */
    private function fullBoxValue(string $b, array $box, int $offset): int
    {
        $size = $box['end'] - $box['data'];
        $version = ord($b[$box['data']] ?? "\0");

        if ($version === 1) {
            return $size >= $offset + 8 ? $this->u64($b, $box['data'] + $offset) : 0;
        }

        return $size >= $offset + 4 ? $this->u32($b, $box['data'] + $offset) : 0;
    }

    /**
     * Caixas no intervalo [start, end). Com `$tolerant` (nível de cima), uma caixa que passa do
     * fim encerra a leitura; nas caixas internas, recusa.
     *
     * @return list<array{type: string, data: int, end: int}>
     */
    private function boxes(string $b, int $start, int $end, int &$budget, bool $tolerant = false): array
    {
        $out = [];
        $pos = $start;

        while ($pos + 8 <= $end) {
            if (--$budget < 0) {
                throw self::invalid();
            }

            $size = $this->u32($b, $pos);
            $type = substr($b, $pos + 4, 4);
            $header = 8;

            if ($size === 1) {
                if ($pos + 16 > $end) {
                    throw self::invalid();
                }

                $size = $this->u64($b, $pos + 8);
                $header = 16;
            } elseif ($size === 0) {
                $size = $end - $pos;
            }

            if ($size < $header || $pos + $size > $end) {
                if ($tolerant && $size >= $header) {
                    $out[] = ['type' => $type, 'data' => $pos + $header, 'end' => $end];
                    break;
                }

                throw self::invalid();
            }

            $out[] = ['type' => $type, 'data' => $pos + $header, 'end' => $pos + $size];
            $pos += $size;
        }

        return $out;
    }

    private function u32(string $b, int $pos): int
    {
        $raw = substr($b, $pos, 4);
        $value = strlen($raw) === 4 ? unpack('N', $raw) : false;

        return is_array($value) && is_int($value[1]) ? $value[1] : 0;
    }

    private function u64(string $b, int $pos): int
    {
        $raw = substr($b, $pos, 8);

        $unpacked = strlen($raw) === 8 ? unpack('J', $raw) : false;
        $value = is_array($unpacked) && is_int($unpacked[1]) ? $unpacked[1] : 0;

        // Acima de 2^63 o PHP devolve negativo: tratamos como ilegível.
        return $value < 0 ? 0 : $value;
    }

    private static function dimension(int $value): int
    {
        return $value > 0 && $value <= 16_384 ? $value : 0;
    }
}
