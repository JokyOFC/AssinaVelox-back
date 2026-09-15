<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da Fase 3 §3.3 — vídeo curto no aceite (F-VIDEO)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
|
| Os vídeos são SINTÉTICOS: só a estrutura do contêiner (cabeçalho EBML + Segment/Info/Tracks,
| ou ftyp + moov/mvhd/trak/hdlr) e bytes de enchimento no lugar da imagem. É exatamente o que o
| servidor lê — ele nunca decodifica o vídeo.
*/

use App\Enums\FieldType;
use App\Enums\SignatureStatus;
use App\Services\Envelopes\Finalization\EvidenceData;
use App\Services\Identity\IdentityVideos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

if (! function_exists('videoEbmlElement')) {
    function videoEbmlElement(int $id, string $payload): string
    {
        $size = strlen($payload);

        $sizeBytes = match (true) {
            $size < 0x7F => chr(0x80 | $size),
            $size < 0x3FFF => pack('n', 0x4000 | $size),
            default => pack('N', 0x10000000 | $size),
        };

        return ltrim(pack('N', $id), "\0").$sizeBytes.$payload;
    }
}

if (! function_exists('videoWebm')) {
    /**
     * WebM/Matroska sintético. `$durationMs = null` imita o MediaRecorder (sem `Duration`).
     */
    function videoWebm(?float $durationMs = 5000.0, bool $withVideoTrack = true, string $docType = 'webm', int $clusterBytes = 2048): string
    {
        $header = videoEbmlElement(0x1A45DFA3, videoEbmlElement(0x4286, "\x01").videoEbmlElement(0x4282, $docType));

        // TimecodeScale = 1 000 000 ns: `Duration` em milissegundos.
        $info = videoEbmlElement(0x1549A966,
            videoEbmlElement(0x2AD7B1, "\x0F\x42\x40")
            .($durationMs === null ? '' : videoEbmlElement(0x4489, pack('E', $durationMs)))
        );

        $track = videoEbmlElement(0xD7, "\x01")
            .videoEbmlElement(0x83, $withVideoTrack ? "\x01" : "\x02")
            .($withVideoTrack ? videoEbmlElement(0xE0, videoEbmlElement(0xB0, pack('n', 640)).videoEbmlElement(0xBA, pack('n', 480))) : '');

        $tracks = videoEbmlElement(0x1654AE6B, videoEbmlElement(0xAE, $track));

        // Cluster de tamanho desconhecido, como no gravador ao vivo.
        $cluster = "\x1F\x43\xB6\x75\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".str_repeat("\xA3", $clusterBytes);

        // Segment de tamanho desconhecido.
        return $header."\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".$info.$tracks.$cluster;
    }
}

if (! function_exists('videoMp4Box')) {
    function videoMp4Box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)).$type.$payload;
    }
}

if (! function_exists('videoMp4')) {
    /**
     * MP4 sintético: ftyp + moov (mvhd com timescale 1000, trak com tkhd e hdlr) + mdat.
     *
     * @param  list<string>  $compatible
     */
    function videoMp4(?int $durationMs = 5000, bool $video = true, string $brand = 'isom', array $compatible = ['isom', 'mp41'], int $mdatBytes = 2048): string
    {
        $ftyp = videoMp4Box('ftyp', $brand.pack('N', 512).implode('', $compatible));

        $mvhd = videoMp4Box('mvhd', "\0\0\0\0".pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', $durationMs ?? 0).str_repeat("\0", 80));
        $tkhd = videoMp4Box('tkhd', "\0\0\0\x07".str_repeat("\0", 72).pack('N', 640 << 16).pack('N', 480 << 16));
        $hdlr = videoMp4Box('hdlr', "\0\0\0\0\0\0\0\0".($video ? 'vide' : 'soun').str_repeat("\0", 12)."Handler\0");
        $trak = videoMp4Box('trak', $tkhd.videoMp4Box('mdia', $hdlr));

        return $ftyp.videoMp4Box('moov', $mvhd.$trak).videoMp4Box('mdat', str_repeat("\x11", $mdatBytes));
    }
}

if (! function_exists('videoEnvelope')) {
    /**
     * Envelope enviado, flag `identity_video` ligada e vídeo exigido da primeira pessoa.
     *
     * @return array<string, mixed>
     */
    function videoEnvelope(?int $maxSeconds = null): array
    {
        $ctx = signerEnvelope([
            ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]],
            ['name' => 'Henrique Dias', 'email' => 'henrique@exemplo.test', 'fields' => [FieldType::Signature], 'order' => 1],
        ]);

        identityEnableFlags($ctx['organization'], ['identity_video']);
        app(IdentityVideos::class)->setRequirement($ctx['envelope'], $ctx['recipients']['maria@exemplo.test'], true, $maxSeconds, $ctx['owner']);

        return $ctx + ['token' => $ctx['tokens']['maria@exemplo.test']];
    }
}

if (! function_exists('videoPost')) {
    /**
     * @param  array<string, mixed>  $extra
     */
    function videoPost(object $test, string $token, string $bytes, string $name = 'video.webm', array $extra = []): mixed
    {
        $payload = array_merge([
            'video' => UploadedFile::fake()->createWithContent($name, $bytes),
            'consent' => '1',
            'source' => 'camera',
        ], $extra);

        return $test->post(
            route('sign.capture.video.store', ['token' => $token]),
            array_filter($payload, fn ($value): bool => $value !== null),
            ['Accept' => 'application/json'],
        );
    }
}

if (! function_exists('videoEvidenceData')) {
    /**
     * Dados do relatório de evidências (a mesma entrada do PDF), sem assinatura da operadora.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    function videoEvidenceData(array $ctx): array
    {
        $sent = $ctx['version'];

        return app(EvidenceData::class)->build(
            $ctx['envelope']->fresh(),
            $sent,
            ['original' => null, 'sent' => $sent->sha256, 'consolidated' => null],
            SignatureStatus::None,
        );
    }
}

if (! function_exists('videoDiskFiles')) {
    /**
     * @return list<string>
     */
    function videoDiskFiles(): array
    {
        return array_values(array_filter(Storage::disk('documents')->allFiles(), fn (string $path): bool => str_contains($path, '/identity/video-')));
    }
}
