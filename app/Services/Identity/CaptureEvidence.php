<?php

namespace App\Services\Identity;

use App\Models\Envelope;
use App\Services\Identity\Models\IdentityCapture;

/**
 * Como a captura aparece na página de evidências do REMETENTE (`envelopes.evidence`).
 * Nunca na verificação pública nem no PDF de evidências.
 *
 * Contrato para a integração com `EvidenceDossier::recipients()` (fora da área C-ID): acrescentar
 * a cada participante `identity_captures` = `forEnvelope($envelope)['recipients'][$recipient->ulid] ?? []`
 * e à página `identity_capture_notice` = `forEnvelope($envelope)['notice']`. A página já exige
 * `view` no envelope; aqui a consulta é presa ao envelope E à organização dele.
 *
 * Vocabulário (T1): cada item é "imagem enviada pelo participante", com a ORIGEM que o
 * navegador informou (câmera ou arquivo do dispositivo) — declarada, não verificada. Não é
 * "capturada pelo participante": de um arquivo escolhido a plataforma só sabe que alguém, com a
 * sessão do participante, o enviou. Sem verificação de identidade, sem biometria e sem
 * comparação — porque nada disso aconteceu.
 */
final class CaptureEvidence
{
    public const LABEL = 'Imagem enviada pelo participante';

    public const NOTICE = 'As imagens abaixo foram enviadas pelo próprio participante durante o aceite e são guardadas '
        .'como registro. Não houve verificação de identidade: a plataforma não compara rostos, '
        .'não analisa a imagem e não lê o documento fotografado. A origem de cada imagem (câmera ou '
        .'arquivo do dispositivo) é a informada pelo navegador do participante e não é verificada.';

    /**
     * Fase 4 §4.1: para o participante com verificação facial com documento exigida, o aviso
     * acima seria falso — as imagens foram encaminhadas a um provedor externo, que as comparou.
     * Este é o aviso desse participante (`%s` = nome do provedor); a constante original fica
     * intacta para os demais. Quem comparou foi o provedor: a plataforma enviou e registrou.
     */
    public const VERIFICATION_NOTICE = 'As imagens abaixo foram enviadas pelo próprio participante durante o aceite e, por '
        .'exigência de quem enviou o documento, encaminhadas ao provedor externo %s para a verificação facial com '
        .'documento. Quem comparou as imagens foi o provedor; a plataforma enviou as fotos da captura e registrou a '
        .'resposta, sem comparar rostos, analisar a imagem ou ler o documento fotografado por conta própria. A origem '
        .'de cada imagem (câmera ou arquivo do dispositivo) é a informada pelo navegador do participante e não é verificada.';

    public static function verificationNotice(string $providerLabel): string
    {
        return sprintf(self::VERIFICATION_NOTICE, $providerLabel);
    }

    /**
     * Rótulo completo do item: o meio e a origem declarada pelo navegador.
     */
    public static function label(?string $source): string
    {
        return self::LABEL.' — '.match ($source) {
            'camera' => 'origem informada pelo navegador: câmera',
            'upload' => 'origem informada pelo navegador: arquivo do dispositivo',
            default => 'origem não informada pelo navegador',
        };
    }

    /**
     * Só a origem, para a legenda de cada miniatura.
     */
    public static function sourceLabel(?string $source): string
    {
        return match ($source) {
            'camera' => 'Origem informada pelo navegador: câmera (não verificada)',
            'upload' => 'Origem informada pelo navegador: arquivo do dispositivo (não verificada)',
            default => 'Origem não informada pelo navegador',
        };
    }

    public function __construct(
        private readonly IdentityCaptures $captures,
        private readonly CaptureImageNormalizer $normalizer,
    ) {}

    /**
     * @return array{notice: string, recipients: array<string, list<array<string, mixed>>>}
     */
    public function forEnvelope(Envelope $envelope, bool $withThumbnails = true): array
    {
        $rows = IdentityCapture::withoutOrganizationScope()
            ->with('recipient:id,ulid')
            ->where('envelope_id', $envelope->getKey())
            ->where('organization_id', $envelope->organization_id)
            ->whereNotNull('signature_acceptance_id')
            // Só fotos: o vídeo curto (F-VIDEO) tem bloco próprio (`VideoEvidence`), sem miniatura.
            ->where('kind', '!=', CaptureKind::Video->value)
            ->orderBy('id')
            ->get();

        $thumbSide = max(64, (int) config('assinavelox.capture.thumbnail_max_side', 320));
        $map = [];

        foreach ($rows as $capture) {
            $ulid = $capture->recipient?->ulid;

            if (! is_string($ulid)) {
                continue;
            }

            $thumbnail = null;

            if ($withThumbnails && $capture->isAvailable()) {
                $bytes = $this->captures->readImage($capture);
                $thumb = $bytes === null ? null : $this->normalizer->thumbnail($bytes, $thumbSide);
                $thumbnail = $thumb === null ? null : 'data:image/jpeg;base64,'.base64_encode($thumb);
            }

            $source = in_array($capture->source, ['camera', 'upload'], true) ? $capture->source : null;

            $map[$ulid][] = [
                'id' => $capture->ulid,
                'kind' => $capture->kind->value,
                'kind_label' => $capture->kind->label(),
                'label' => self::label($source),
                // Origem DECLARADA pelo navegador (`camera` | `upload` | null) — não verificada.
                'source' => $source,
                'source_label' => self::sourceLabel($source),
                'captured_at' => $capture->captured_at->toIso8601String(),
                'width' => $capture->width,
                'height' => $capture->height,
                'sha256' => $capture->sha256,
                'available' => $capture->isAvailable(),
                'purged_at' => $capture->purged_at?->toIso8601String(),
                // Miniatura só para o remetente, em data URI (sem rota de arquivo).
                'thumbnail' => $thumbnail,
            ];
        }

        return ['notice' => self::NOTICE, 'recipients' => $map];
    }
}
