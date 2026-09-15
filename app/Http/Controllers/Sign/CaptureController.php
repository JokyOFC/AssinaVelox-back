<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureSignerVerified;
use App\Http\Middleware\ResolveSignerToken;
use App\Models\SigningSession;
use App\Services\Identity\CaptureKind;
use App\Services\Identity\CaptureStep;
use App\Services\Identity\Exceptions\CaptureRejectedException;
use App\Services\Identity\IdentityCaptures;
use App\Services\Identity\IdentityVideos;
use App\Services\Identity\VideoStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Envio de uma foto da captura simples (Fase 2 §2.10) — `POST assinar/{token}/captura/{kind}`,
 * rota `sign.capture.store`, com sessão autenticada (`signer.verified`).
 *
 * Corpo multipart: `image` (JPEG/PNG até `assinavelox.capture.max_upload_kb`) e `source`
 * opcional (`camera` | `upload`). Quem decide o formato real é o normalizador, sobre os
 * bytes — a extensão e o `Content-Type` declarados são ignorados.
 *
 * 404 (sem distinguir motivos) quando a flag `identity_capture` está desligada, o papel não
 * registra aceite ou o tipo não foi exigido para esta pessoa: não se coleta imagem que
 * ninguém pediu. Resposta JSON para `fetch`/XHR; redirect para `sign.show` no Inertia.
 */
class CaptureController extends Controller
{
    public function __construct(
        private readonly IdentityCaptures $captures,
        private readonly CaptureStep $step,
    ) {}

    public function store(Request $request, string $token, string $kind): JsonResponse|RedirectResponse
    {
        $context = ResolveSignerToken::context($request);
        $captureKind = CaptureKind::tryFrom($kind);

        abort_if($captureKind === null || ! $context->isActive() || ! $this->captures->photoRequiredFor($context), 404);
        abort_unless(in_array($captureKind, $this->captures->requiredKinds($context->recipient), true), 404);

        // Esta é uma rota de captura: a Permissions-Policy desta resposta libera a câmera.
        $request->attributes->set(IdentityCaptures::CAMERA_ATTRIBUTE, true);

        /** @var SigningSession $session */
        $session = $request->attributes->get(EnsureSignerVerified::ATTRIBUTE);

        $maxKb = max(64, (int) config('assinavelox.capture.max_upload_kb', 8192));

        $request->validate([
            'image' => ['required', 'file', 'max:'.$maxKb],
            'source' => ['nullable', 'string', 'in:camera,upload'],
        ], [
            'image.required' => 'Tire ou escolha uma foto para enviar.',
            'image.file' => 'Tire ou escolha uma foto para enviar.',
            'image.uploaded' => 'A foto não pôde ser recebida. Tente de novo.',
            'image.max' => sprintf('A foto é maior que %d MB.', max(1, intdiv($maxKb, 1024))),
        ]);

        $file = $request->file('image');

        try {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw new CaptureRejectedException('invalid_image', 'A foto não pôde ser recebida. Tente de novo.');
            }

            $capture = $this->captures->store(
                $context,
                $session,
                $captureKind,
                (string) file_get_contents($file->getRealPath()),
                $request->string('source')->toString() ?: null,
            );
        } catch (CaptureRejectedException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => ['image' => [$exception->getMessage()]],
                    'code' => $exception->errorCode,
                ], $exception->status);
            }

            return back()->withErrors(['image' => $exception->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'capture' => [
                    'id' => $capture->ulid,
                    'kind' => $capture->kind->value,
                    'captured_at' => $capture->captured_at->toIso8601String(),
                    'width' => $capture->width,
                    'height' => $capture->height,
                ],
                'identity_capture' => $this->step->props($context, $session),
            ], 201);
        }

        return redirect()
            ->route('sign.show', ['token' => $token])
            ->with('success', $captureKind->label().' registrada.');
    }

    /**
     * Fase 3 §3.3 (F-VIDEO, docs/fase-3/captura-de-video.md §4) — envio do vídeo curto,
     * `POST assinar/{token}/captura-video`, rota `sign.capture.video.store`, com sessão
     * autenticada (`signer.verified`).
     *
     * Corpo multipart: `video` (WebM/Matroska ou MP4, até `capture_video.max_upload_kb`),
     * `consent` (obrigatório: marcado antes de ligar a câmera), `source` opcional
     * (`camera` | `upload`, declarado) e `duration_ms` opcional (medido pelo navegador). O
     * contêiner é decidido pelos bytes; extensão e `Content-Type` enviados são ignorados.
     *
     * 404 (sem distinguir motivos) com a flag `identity_video` desligada, papel sem aceite ou
     * vídeo não exigido desta pessoa.
     */
    public function storeVideo(Request $request, string $token): JsonResponse|RedirectResponse
    {
        $context = ResolveSignerToken::context($request);

        abort_if(! $context->isActive() || ! $this->captures->videoRequiredFor($context), 404);

        // Rota de captura: a Permissions-Policy desta resposta libera a câmera (nunca o microfone).
        $request->attributes->set(IdentityCaptures::CAMERA_ATTRIBUTE, true);

        /** @var SigningSession $session */
        $session = $request->attributes->get(EnsureSignerVerified::ATTRIBUTE);

        $maxKb = IdentityVideos::maxUploadKb();

        $request->validate([
            'video' => ['required', 'file', 'max:'.$maxKb],
            'consent' => ['accepted'],
            'source' => ['nullable', 'string', 'in:camera,upload'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
        ], [
            'video.required' => 'Grave ou escolha um vídeo para enviar.',
            'video.file' => 'Grave ou escolha um vídeo para enviar.',
            'video.uploaded' => 'O vídeo não pôde ser recebido. Tente de novo.',
            'video.max' => sprintf('O vídeo é maior que %s MB. Grave de novo, mais curto.', IdentityVideos::megabytes($maxKb)),
            'consent.accepted' => 'Para enviar o vídeo, marque a autorização de gravação.',
            'consent.required' => 'Para enviar o vídeo, marque a autorização de gravação.',
        ]);

        $file = $request->file('video');

        try {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw new CaptureRejectedException('invalid_video', 'O vídeo não pôde ser recebido. Tente de novo.');
            }

            $declared = $request->input('duration_ms');

            $capture = app(IdentityVideos::class)->store(
                $context,
                $session,
                (string) file_get_contents($file->getRealPath()),
                $request->string('source')->toString() ?: null,
                $declared === null || $declared === '' ? null : (int) $declared,
                VideoStep::consentVersion(),
            );
        } catch (CaptureRejectedException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => ['video' => [$exception->getMessage()]],
                    'code' => $exception->errorCode,
                ], $exception->status);
            }

            return back()->withErrors(['video' => $exception->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'capture' => [
                    'id' => $capture->ulid,
                    'kind' => $capture->kind->value,
                    'captured_at' => $capture->captured_at->toIso8601String(),
                    'container' => $capture->container,
                    'duration_ms' => $capture->duration_ms ?? $capture->declared_duration_ms,
                    'size_bytes' => $capture->size_bytes,
                ],
                'identity_video' => app(VideoStep::class)->props($context, $session),
            ], 201);
        }

        return redirect()
            ->route('sign.show', ['token' => $token])
            ->with('success', 'Vídeo curto registrado.');
    }
}
