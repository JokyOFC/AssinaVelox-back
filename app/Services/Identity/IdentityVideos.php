<?php

namespace App\Services\Identity;

use App\Enums\AuditEventType;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningSession;
use App\Models\User;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Identity\Exceptions\CaptureRejectedException;
use App\Services\Identity\Models\IdentityCapture;
use App\Services\Identity\Models\IdentityVideoRequirement;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Aceite complementado por VÍDEO CURTO (Fase 3 §3.3, docs/fase-3/captura-de-video.md).
 * Flag `identity_video`, desligada até a decisão jurídica.
 *
 * - O remetente exige o vídeo por participante (`identity_video_requirements`, só no rascunho),
 *   com duração máxima própria ou a padrão (`assinavelox.capture_video.max_seconds`).
 * - O participante grava no navegador (MediaRecorder, sem som), marca o consentimento e envia
 *   depois do código. O servidor confere o contêiner pela assinatura de bytes
 *   ({@see VideoContainerInspector}), o tamanho e a duração (a lida do arquivo, quando legível, e a
 *   informada pelo navegador), guarda o arquivo COMO VEIO — sem transcodificar — cifrado no disco
 *   privado e grava a linha em `identity_captures` com `kind = video` e o SHA-256 dos bytes.
 * - O aceite só é gravado com o vídeo exigido presente nesta sessão ({@see IdentityCaptures}).
 *
 * O vídeo é captura, não verificação: nada compara rostos, analisa o conteúdo ou confere quem
 * aparece nele. Ele nunca é embutido no PDF.
 */
final class IdentityVideos
{
    public function __construct(
        private readonly VideoContainerInspector $inspector,
        private readonly IdentityCaptures $captures,
    ) {}

    // -- Limites ---------------------------------------------------------------------------

    /** Teto configurável da duração máxima (o remetente escolhe até ele). */
    public static function ceilingSeconds(): int
    {
        return max(3, min(120, (int) config('assinavelox.capture_video.max_seconds_ceiling', 30)));
    }

    /** Duração máxima padrão (10 s). */
    public static function defaultSeconds(): int
    {
        return max(3, min(self::ceilingSeconds(), (int) config('assinavelox.capture_video.max_seconds', 10)));
    }

    public static function maxUploadKb(): int
    {
        return max(256, (int) config('assinavelox.capture_video.max_upload_kb', 8192));
    }

    /** Folga entre a duração pedida e a aceita (o MediaRecorder para com atraso de milissegundos). */
    public static function toleranceMs(): int
    {
        return max(0, min(5000, (int) config('assinavelox.capture_video.duration_tolerance_ms', 1500)));
    }

    /**
     * Teto de bytes de um vídeo cuja duração ninguém declara: 2 × (duração pedida × taxa pedida
     * ao gravador) + 256 KB de cabeçalho, nunca acima do limite do envio.
     */
    public static function unknownDurationCeilingBytes(int $seconds): int
    {
        $bitsPerSecond = max(100_000, (int) config('assinavelox.capture_video.video_bits_per_second', 1_000_000));

        return min(self::maxUploadKb() * 1024, (int) (2 * $seconds * $bitsPerSecond / 8) + 256 * 1024);
    }

    public static function secondsFor(?IdentityVideoRequirement $requirement): int
    {
        $seconds = $requirement?->max_seconds;

        return $seconds === null ? self::defaultSeconds() : max(3, min(self::ceilingSeconds(), $seconds));
    }

    // -- Exigência (remetente) ---------------------------------------------------------------

    public function requirement(Recipient $recipient): ?IdentityVideoRequirement
    {
        /** @var IdentityVideoRequirement|null */
        return IdentityVideoRequirement::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->where('organization_id', $recipient->organization_id)
            ->first();
    }

    /**
     * @return array<string, array{max_seconds: int}> por ULID do destinatário
     */
    public function requirementsForEnvelope(Envelope $envelope): array
    {
        $rows = IdentityVideoRequirement::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('organization_id', $envelope->organization_id)
            ->get(['recipient_id', 'max_seconds']);

        $ulids = Recipient::withoutOrganizationScope()
            ->whereIn('id', $rows->pluck('recipient_id')->all() ?: [0])
            ->pluck('ulid', 'id');

        $map = [];

        foreach ($rows as $row) {
            $ulid = $ulids[$row->recipient_id] ?? null;

            if (is_string($ulid)) {
                $map[$ulid] = ['max_seconds' => self::secondsFor($row)];
            }
        }

        return $map;
    }

    /**
     * Grava (ou remove) a exigência. Quem chama já autorizou usuário, flag e estado do envelope.
     *
     * @return array{required: bool, max_seconds: int|null}
     */
    public function setRequirement(Envelope $envelope, Recipient $recipient, bool $required, ?int $maxSeconds, ?User $user): array
    {
        $current = $this->requirement($recipient);
        $seconds = $required ? ($maxSeconds === null ? null : max(3, min(self::ceilingSeconds(), $maxSeconds))) : null;

        if (! $required && $current === null) {
            return ['required' => false, 'max_seconds' => null];
        }

        if ($required && $current !== null && $current->max_seconds === $seconds) {
            return ['required' => true, 'max_seconds' => self::secondsFor($current)];
        }

        DB::transaction(function () use ($envelope, $recipient, $required, $seconds, $user, $current): void {
            if (! $required) {
                IdentityVideoRequirement::withoutOrganizationScope()->where('recipient_id', $recipient->getKey())->delete();

                return;
            }

            $row = $current ?? new IdentityVideoRequirement;
            $row->forceFill([
                'organization_id' => $envelope->organization_id,
                'envelope_id' => $envelope->getKey(),
                'recipient_id' => $recipient->getKey(),
                'max_seconds' => $seconds,
                'updated_by_user_id' => $user?->getKey(),
            ])->save();
        });

        $effective = $required ? self::secondsFor($this->requirement($recipient)) : null;

        EnvelopeAudit::record($envelope, AuditEventType::IdentityVideoRequirementUpdated, [
            'recipient_ulid' => $recipient->ulid,
            'required' => $required,
            'max_seconds' => $effective,
        ], $recipient);

        return ['required' => $required, 'max_seconds' => $effective];
    }

    // -- Participante ------------------------------------------------------------------------

    /**
     * O vídeo desta sessão ainda não vinculado a aceite (o mais recente), ou null.
     */
    public function currentFor(SigningSession $session): ?IdentityCapture
    {
        /** @var IdentityCapture|null */
        return IdentityCapture::withoutOrganizationScope()
            ->where('signing_session_id', $session->getKey())
            ->where('recipient_id', $session->recipient_id)
            ->where('kind', CaptureKind::Video->value)
            ->whereNull('signature_acceptance_id')
            ->whereNotNull('storage_path')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Confere, cifra, grava e registra o vídeo. Substitui o anterior ainda sem aceite.
     *
     * @throws CaptureRejectedException
     */
    public function store(SignerContext $context, SigningSession $session, string $raw, ?string $source, ?int $declaredMs, string $consentVersion): IdentityCapture
    {
        $limitKey = 'identity-video:'.$context->recipient->getKey();
        $perHour = max(1, (int) config('assinavelox.capture_video.max_uploads_per_hour', 10));

        if (RateLimiter::tooManyAttempts($limitKey, $perHour)) {
            throw new CaptureRejectedException('too_many_uploads', 'Muitos vídeos enviados em pouco tempo. Aguarde alguns minutos e tente de novo.', 429);
        }

        RateLimiter::hit($limitKey, 3600);

        $maxKb = self::maxUploadKb();

        if (strlen($raw) > $maxKb * 1024) {
            throw new CaptureRejectedException('video_too_large', sprintf('O vídeo é maior que %s MB. Grave de novo, mais curto.', self::megabytes($maxKb)));
        }

        $info = $this->inspector->inspect($raw);

        $seconds = self::secondsFor($this->requirement($context->recipient));
        $limitMs = $seconds * 1000 + self::toleranceMs();

        $durations = [$info['duration_ms'], $info['estimated_duration_ms'], $declaredMs];

        foreach ($durations as $duration) {
            if ($duration !== null && $duration > $limitMs) {
                throw new CaptureRejectedException('video_too_long', sprintf('O vídeo passa de %d segundos. Grave de novo, mais curto.', $seconds));
            }
        }

        // Nem o arquivo, nem os blocos, nem o navegador dizem a duração: o tamanho responde por
        // ela (LGPD, minimização) — no máximo o dobro do que a taxa pedida ao gravador produziria
        // na duração pedida, com folga fixa para o cabeçalho.
        if (array_filter($durations, static fn (?int $duration): bool => $duration !== null) === []
            && strlen($raw) > self::unknownDurationCeilingBytes($seconds)) {
            throw new CaptureRejectedException('video_too_long', sprintf('Não foi possível confirmar que o vídeo tem até %d segundos. Grave de novo pela câmera desta página.', $seconds));
        }

        $ulid = (string) Str::ulid();
        $sha256 = hash('sha256', $raw);

        $path = sprintf(
            '%s/%s/envelopes/%s/identity/video-%s.bin',
            trim((string) config('assinavelox.upload.path_prefix', 'orgs'), '/'),
            $context->organization->ulid,
            $context->envelope->ulid,
            $ulid,
        );

        // Cifrado com a chave da aplicação, como as fotos. O SHA-256 é dos bytes em claro.
        Storage::disk('documents')->put($path, Crypt::encryptString($raw));

        $source = in_array($source, ['camera', 'upload'], true) ? $source : null;

        try {
            [$capture, $replacedPaths] = DB::transaction(function () use ($context, $session, $info, $ulid, $path, $sha256, $raw, $source, $declaredMs, $consentVersion): array {
                $previous = IdentityCapture::withoutOrganizationScope()
                    ->where('recipient_id', $context->recipient->getKey())
                    ->where('kind', CaptureKind::Video->value)
                    ->whereNull('signature_acceptance_id')
                    ->lockForUpdate()
                    ->get(['id', 'storage_path']);

                $paths = $previous->pluck('storage_path')->filter()->values()->all();

                if ($previous->isNotEmpty()) {
                    IdentityCapture::withoutOrganizationScope()->whereIn('id', $previous->pluck('id')->all())->delete();
                }

                $now = Carbon::now();
                $capture = new IdentityCapture;
                $capture->forceFill([
                    'ulid' => $ulid,
                    'organization_id' => $context->envelope->organization_id,
                    'envelope_id' => $context->envelope->getKey(),
                    'recipient_id' => $context->recipient->getKey(),
                    'signing_session_id' => $session->getKey(),
                    'kind' => CaptureKind::Video,
                    'storage_path' => $path,
                    'sha256' => $sha256,
                    'mime_type' => $info['mime'],
                    'container' => $info['container'],
                    'width' => $info['width'],
                    'height' => $info['height'],
                    'size_bytes' => strlen($raw),
                    'duration_ms' => $info['duration_ms'],
                    'declared_duration_ms' => $declaredMs,
                    'source' => $source,
                    'consented_at' => $now,
                    'consent_version' => $consentVersion,
                    'captured_at' => $now,
                ])->save();

                return [$capture, $paths];
            });
        } catch (\Throwable $exception) {
            Storage::disk('documents')->delete($path);

            throw $exception;
        }

        foreach ($replacedPaths as $old) {
            Storage::disk('documents')->delete($old);
        }

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::IdentityVideoRecorded, [
            'capture_ulid' => $capture->ulid,
            'kind' => CaptureKind::Video->value,
            'sha256' => $capture->sha256,
            'container' => $capture->container,
            'size_bytes' => $capture->size_bytes,
            'duration_ms' => $capture->duration_ms,
            // Informada pelo navegador (não verificada), como a origem.
            'declared_duration_ms' => $capture->declared_duration_ms,
            'source' => $capture->source,
            'consent_version' => $capture->consent_version,
            'replaced' => count($replacedPaths),
        ]);

        return $capture;
    }

    /**
     * Bytes em claro do vídeo, ou null (excluído pela retenção, ausente ou ilegível).
     */
    public function read(IdentityCapture $capture): ?string
    {
        return $capture->isVideo() ? $this->captures->readImage($capture) : null;
    }

    public static function megabytes(int $kb): string
    {
        $mb = $kb / 1024;

        return floor($mb) === $mb ? (string) (int) $mb : number_format($mb, 1, ',', '');
    }
}
