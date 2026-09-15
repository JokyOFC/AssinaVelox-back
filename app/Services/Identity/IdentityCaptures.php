<?php

namespace App\Services\Identity;

use App\Enums\AuditEventType;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningSession;
use App\Models\User;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Identity\Exceptions\CaptureRejectedException;
use App\Services\Identity\Models\IdentityCapture;
use App\Services\Identity\Models\IdentityCaptureRequirement;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Captura SIMPLES de foto do rosto e do documento (Fase 2 §2.10, docs/fase-2/identidade.md §5).
 *
 * - O remetente exige, por participante, `selfie`, `document_front` e/ou `document_back`
 *   (só com a flag `identity_capture` e só no rascunho).
 * - O participante envia cada foto depois do código (sessão autenticada). A foto é
 *   normalizada ({@see CaptureImageNormalizer}), CIFRADA com a chave da aplicação e gravada
 *   no disco privado da organização. Refazer substitui a anterior do mesmo tipo.
 * - O aceite só é gravado com todas as fotos exigidas presentes nesta sessão, e as vincula
 *   (`signature_acceptance_id` + resumo no `fields_snapshot`).
 *
 * Nada aqui compara rostos, verifica vivacidade ou lê o documento. A evidência diz
 * "imagem capturada pelo participante" e que não houve verificação de identidade.
 */
final class IdentityCaptures
{
    /** Atributo de requisição que libera `camera=(self)` na Permissions-Policy. */
    public const CAMERA_ATTRIBUTE = 'identity_capture.camera';

    public function __construct(
        private readonly CaptureImageNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {}

    // -- Exigência (remetente) ----------------------------------------------------------

    /**
     * @return list<CaptureKind> na ordem canônica (rosto, frente, verso)
     */
    public function requiredKinds(Recipient $recipient): array
    {
        /** @var IdentityCaptureRequirement|null $row */
        $row = IdentityCaptureRequirement::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->where('organization_id', $recipient->organization_id)
            ->first();

        return $row === null ? [] : self::normalizeKinds($row->kinds);
    }

    /**
     * Exigências do envelope por ULID do destinatário (props do editor/wizard).
     *
     * @return array<string, list<string>>
     */
    public function requirementsForEnvelope(Envelope $envelope): array
    {
        $rows = IdentityCaptureRequirement::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('organization_id', $envelope->organization_id)
            ->get(['recipient_id', 'kinds']);

        $ulids = Recipient::withoutOrganizationScope()
            ->whereIn('id', $rows->pluck('recipient_id')->all() ?: [0])
            ->pluck('ulid', 'id');

        $map = [];

        foreach ($rows as $row) {
            $ulid = $ulids[$row->recipient_id] ?? null;

            if (is_string($ulid)) {
                $map[$ulid] = array_map(static fn (CaptureKind $kind): string => $kind->value, self::normalizeKinds($row->kinds));
            }
        }

        return $map;
    }

    /**
     * Grava (ou remove, com lista vazia) a exigência. Quem chama já autorizou o usuário, a
     * flag e o estado do envelope.
     *
     * @param  list<string>  $kinds
     * @return list<string> a lista normalizada que ficou gravada
     */
    public function setRequirement(Envelope $envelope, Recipient $recipient, array $kinds, ?User $user): array
    {
        $normalized = array_map(static fn (CaptureKind $kind): string => $kind->value, self::normalizeKinds($kinds));
        $before = array_map(static fn (CaptureKind $kind): string => $kind->value, $this->requiredKinds($recipient));

        if ($normalized === $before) {
            return $normalized;
        }

        DB::transaction(function () use ($envelope, $recipient, $normalized, $user): void {
            $query = IdentityCaptureRequirement::withoutOrganizationScope()->where('recipient_id', $recipient->getKey());

            if ($normalized === []) {
                $query->delete();

                return;
            }

            $row = $query->first() ?? new IdentityCaptureRequirement;

            $row->forceFill([
                'organization_id' => $envelope->organization_id,
                'envelope_id' => $envelope->getKey(),
                'recipient_id' => $recipient->getKey(),
                'kinds' => $normalized,
                'updated_by_user_id' => $user?->getKey(),
            ])->save();
        });

        EnvelopeAudit::record($envelope, AuditEventType::IdentityCaptureRequirementUpdated, [
            'recipient_ulid' => $recipient->ulid,
            'kinds' => $normalized,
        ], $recipient);

        return $normalized;
    }

    // -- Participante --------------------------------------------------------------------

    /**
     * Alguma captura (foto OU vídeo) vale para este participante agora? Usado por quem precisa
     * saber se há etapa de captura: câmera na página pública, exclusão do lote, aceite.
     * Sem a flag `identity_video`, é exatamente a regra das fotos.
     */
    public function isRequiredFor(SignerContext $context): bool
    {
        return $this->photoRequiredFor($context) || $this->videoRequiredFor($context);
    }

    /**
     * Fotos (Fase 2 §2.10): flag `identity_capture` da organização ligada, papel que registra
     * aceite e exigência gravada.
     */
    public function photoRequiredFor(SignerContext $context): bool
    {
        return $context->action() !== null
            && IdentityFeatures::identityCapture($context->organization)
            && $this->requiredKinds($context->recipient) !== [];
    }

    /**
     * Vídeo curto (Fase 3 §3.3, F-VIDEO): flag `identity_video` ligada, papel que registra
     * aceite e exigência gravada em `identity_video_requirements`. A flag é conferida antes da
     * consulta: desligada, nenhuma consulta a mais.
     */
    public function videoRequiredFor(SignerContext $context): bool
    {
        return $context->action() !== null
            && IdentityFeatures::identityVideo($context->organization)
            && app(IdentityVideos::class)->requirement($context->recipient) !== null;
    }

    /**
     * Libera a câmera na página pública? Só para o convite ativo de quem tem captura exigida.
     */
    public function cameraAllowedFor(SignerContext $context): bool
    {
        return $context->isActive() && $this->isRequiredFor($context);
    }

    /**
     * Fotos desta sessão ainda não vinculadas a um aceite, por tipo (a mais recente).
     *
     * @return Collection<string, IdentityCapture>
     */
    public function currentFor(SigningSession $session): Collection
    {
        /** @var Collection<string, IdentityCapture> */
        return IdentityCapture::withoutOrganizationScope()
            ->where('signing_session_id', $session->getKey())
            ->where('recipient_id', $session->recipient_id)
            ->whereNull('signature_acceptance_id')
            ->whereNotNull('storage_path')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (IdentityCapture $capture): string => $capture->kind->value);
    }

    /**
     * @return list<CaptureKind>
     */
    public function missingKinds(SignerContext $context, SigningSession $session): array
    {
        $missing = [];

        if ($this->photoRequiredFor($context)) {
            $current = $this->currentFor($session);

            $missing = array_values(array_filter(
                $this->requiredKinds($context->recipient),
                static fn (CaptureKind $kind): bool => ! $current->has($kind->value),
            ));
        }

        // F-VIDEO: o vídeo exigido entra por último na lista ("Vídeo curto").
        if ($this->videoRequiredFor($context) && app(IdentityVideos::class)->currentFor($session) === null) {
            $missing[] = CaptureKind::Video;
        }

        return $missing;
    }

    /**
     * Recusa o aceite enquanto faltar foto exigida.
     *
     * @throws SigningRejectedException
     */
    public function assertComplete(SignerContext $context, SigningSession $session): void
    {
        $missing = $this->missingKinds($context, $session);

        if ($missing === []) {
            return;
        }

        $labels = array_map(static fn (CaptureKind $kind): string => $kind->label(), $missing);

        throw new SigningRejectedException(
            'identity_capture_missing',
            'Antes de concluir, envie: '.implode(', ', $labels).'.',
            context: ['capture' => array_map(static fn (CaptureKind $kind): string => $kind->value, $missing)],
        );
    }

    /**
     * Resumo das fotos exigidas que o aceite vai referenciar (vai para o `fields_snapshot`).
     * Sem imagem nem caminho: tipo, resumo SHA-256, dimensões, momento e a origem informada
     * pelo navegador (`camera` | `upload` | null — declarada, não verificada).
     *
     * F-VIDEO: o item de vídeo (último) traz também contêiner, MIME, tamanho, durações e a versão
     * do consentimento — chaves opcionais, ausentes nas fotos.
     *
     * @return list<array{capture_ulid: string, kind: string, sha256: string, width: int, height: int, captured_at: string, source: string|null, container?: string|null, mime_type?: string, size_bytes?: int, duration_ms?: int|null, declared_duration_ms?: int|null, consent_version?: string|null}>
     */
    public function snapshotFor(SignerContext $context, SigningSession $session): array
    {
        $items = [];

        // F-VIDEO: o vídeo exigido entra depois das fotos, com contêiner e duração (sem arquivo).
        $video = $this->videoRequiredFor($context) ? app(IdentityVideos::class)->currentFor($session) : null;

        if (! $this->photoRequiredFor($context)) {
            return $video === null ? [] : [self::videoSnapshot($video)];
        }

        $current = $this->currentFor($session);

        foreach ($this->requiredKinds($context->recipient) as $kind) {
            $capture = $current->get($kind->value);

            if ($capture === null) {
                continue;
            }

            $items[] = [
                'capture_ulid' => $capture->ulid,
                'kind' => $capture->kind->value,
                'sha256' => $capture->sha256,
                'width' => $capture->width,
                'height' => $capture->height,
                'captured_at' => $capture->captured_at->toIso8601String(),
                'source' => $capture->source,
            ];
        }

        if ($video !== null) {
            $items[] = self::videoSnapshot($video);
        }

        return $items;
    }

    /**
     * Resumo do vídeo no `fields_snapshot`: tipo, SHA-256, contêiner, duração (a lida do arquivo
     * e a informada pelo navegador), tamanho, momento, origem declarada e versão do consentimento.
     *
     * @return array{capture_ulid: string, kind: string, sha256: string, width: int, height: int, captured_at: string, source: string|null, container: string|null, mime_type: string, size_bytes: int, duration_ms: int|null, declared_duration_ms: int|null, consent_version: string|null}
     */
    private static function videoSnapshot(IdentityCapture $capture): array
    {
        return [
            'capture_ulid' => $capture->ulid,
            'kind' => CaptureKind::Video->value,
            'sha256' => $capture->sha256,
            'width' => $capture->width,
            'height' => $capture->height,
            'captured_at' => $capture->captured_at->toIso8601String(),
            'source' => $capture->source,
            'container' => $capture->container,
            'mime_type' => $capture->mime_type,
            'size_bytes' => $capture->size_bytes,
            'duration_ms' => $capture->duration_ms,
            'declared_duration_ms' => $capture->declared_duration_ms,
            'consent_version' => $capture->consent_version,
        ];
    }

    /**
     * Vincula ao aceite as fotos que o snapshot referenciou. Roda DENTRO da transação do
     * aceite (só UPDATE local, sem I/O de disco).
     *
     * @param  list<array{capture_ulid: string}>  $snapshot
     */
    public function attachToAcceptance(SignatureAcceptance $acceptance, array $snapshot): int
    {
        if ($snapshot === []) {
            return 0;
        }

        return IdentityCapture::withoutOrganizationScope()
            ->whereIn('ulid', array_column($snapshot, 'capture_ulid'))
            ->where('recipient_id', $acceptance->recipient_id)
            ->where('organization_id', $acceptance->organization_id)
            ->whereNull('signature_acceptance_id')
            ->update(['signature_acceptance_id' => $acceptance->getKey(), 'updated_at' => Carbon::now()]);
    }

    /**
     * Normaliza, cifra, grava e registra uma foto. Substitui a foto anterior do mesmo tipo
     * que ainda não pertence a aceite nenhum.
     *
     * @throws CaptureRejectedException
     */
    public function store(SignerContext $context, SigningSession $session, CaptureKind $kind, string $raw, ?string $source = null): IdentityCapture
    {
        $limitKey = 'identity-capture:'.$context->recipient->getKey();
        $perHour = max(1, (int) config('assinavelox.capture.max_uploads_per_hour', 30));

        if (RateLimiter::tooManyAttempts($limitKey, $perHour)) {
            throw new CaptureRejectedException('too_many_uploads', 'Muitas fotos enviadas em pouco tempo. Aguarde alguns minutos e tente de novo.', 429);
        }

        RateLimiter::hit($limitKey, 3600);

        $image = $this->normalizer->normalize($raw);
        $ulid = (string) Str::ulid();

        $path = sprintf(
            '%s/%s/envelopes/%s/identity/%s-%s.bin',
            trim((string) config('assinavelox.upload.path_prefix', 'orgs'), '/'),
            $context->organization->ulid,
            $context->envelope->ulid,
            $kind->value,
            $ulid,
        );

        // Cifrado com a chave da aplicação: um vazamento do disco não expõe rostos nem
        // documentos. O resumo SHA-256 é dos bytes em claro (é o que a evidência cita).
        Storage::disk('documents')->put($path, Crypt::encryptString($image['bytes']));

        try {
            [$capture, $replacedPaths] = DB::transaction(function () use ($context, $session, $kind, $image, $ulid, $path, $source): array {
                $previous = IdentityCapture::withoutOrganizationScope()
                    ->where('recipient_id', $context->recipient->getKey())
                    ->where('kind', $kind->value)
                    ->whereNull('signature_acceptance_id')
                    ->lockForUpdate()
                    ->get(['id', 'storage_path']);

                $paths = $previous->pluck('storage_path')->filter()->values()->all();

                if ($previous->isNotEmpty()) {
                    IdentityCapture::withoutOrganizationScope()->whereIn('id', $previous->pluck('id')->all())->delete();
                }

                $capture = new IdentityCapture;
                $capture->forceFill([
                    'ulid' => $ulid,
                    'organization_id' => $context->envelope->organization_id,
                    'envelope_id' => $context->envelope->getKey(),
                    'recipient_id' => $context->recipient->getKey(),
                    'signing_session_id' => $session->getKey(),
                    'kind' => $kind,
                    'storage_path' => $path,
                    'sha256' => $image['sha256'],
                    'mime_type' => $image['mime'],
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'size_bytes' => strlen($image['bytes']),
                    'source' => in_array($source, ['camera', 'upload'], true) ? $source : null,
                    'captured_at' => Carbon::now(),
                ])->save();

                return [$capture, $paths];
            });
        } catch (\Throwable $exception) {
            Storage::disk('documents')->delete($path);

            throw $exception;
        }

        // Só depois do commit: se a transação falhasse, a foto anterior continuaria valendo.
        foreach ($replacedPaths as $old) {
            Storage::disk('documents')->delete($old);
        }

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::IdentityCaptureRecorded, [
            'capture_ulid' => $capture->ulid,
            'kind' => $kind->value,
            'sha256' => $capture->sha256,
            'width' => $capture->width,
            'height' => $capture->height,
            'replaced' => count($replacedPaths),
            // Origem informada pelo navegador (declarada, não verificada).
            'source' => $capture->source,
        ]);

        return $capture;
    }

    /**
     * Bytes em claro da foto, ou null (excluída pela retenção, arquivo ausente ou ilegível).
     */
    public function readImage(IdentityCapture $capture): ?string
    {
        if (! $capture->isAvailable() || $capture->storage_path === null) {
            return null;
        }

        try {
            $payload = Storage::disk('documents')->get($capture->storage_path);

            if (! is_string($payload) || $payload === '') {
                return null;
            }

            return Crypt::decryptString($payload);
        } catch (\Throwable $exception) {
            $this->logger->warning('Imagem da captura ilegível ou ausente no disco.', [
                'capture_ulid' => $capture->ulid,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * @param  array<mixed>|null  $kinds
     * @return list<CaptureKind>
     */
    public static function normalizeKinds(?array $kinds): array
    {
        $wanted = array_map(static fn ($kind): string => is_string($kind) ? $kind : '', $kinds ?? []);

        // Só fotos: o vídeo (F-VIDEO) tem exigência própria e nunca entra nesta lista.
        return array_values(array_filter(
            CaptureKind::photoCases(),
            static fn (CaptureKind $kind): bool => in_array($kind->value, $wanted, true),
        ));
    }
}
