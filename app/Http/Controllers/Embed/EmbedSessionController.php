<?php

namespace App\Http\Controllers\Embed;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Embed\Requests\EmbedAcceptanceRequest;
use App\Http\Middleware\ResolveSignerToken;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\EmbeddedSigningSession;
use App\Models\Organization;
use App\Models\SigningSession;
use App\Models\SigningSessionDocument;
use App\Services\Documents\DocumentStorage;
use App\Services\Embed\AllowedOrigins;
use App\Services\Embed\EmbeddedSessionExchange;
use App\Services\Embed\EmbedFeature;
use App\Services\Embed\EmbedRejected;
use App\Services\Embed\EmbedResponses;
use App\Services\Embed\EmbedScreenProps;
use App\Services\Embed\Http\AuthenticateEmbeddedSession;
use App\Services\Signing\Challenges;
use App\Services\Signing\Channels\SenderPins;
use App\Services\Signing\Channels\SignerAuthProps;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\RecordAcceptance;
use App\Services\Signing\RecordRefusal;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerSessions;
use App\Services\Signing\SignerTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ações do widget embutido (docs/fase-3/widget-embutido.md §3).
 *
 * Tudo em JSON, tudo pelos MESMOS serviços do fluxo público — código (`Challenges`), PIN
 * (`SenderPins`), sessão (`SignerSessions`), aceite (`RecordAcceptance`) e recusa
 * (`RecordRefusal`) —, com as mesmas regras de autenticação do participante. O que muda é só o
 * transporte: o estado que no fluxo por e-mail mora na sessão Laravel do navegador vem da sessão
 * isolada do widget (AuthenticateEmbeddedSession + EmbedSessionStore), e as respostas trazem o
 * estado novo da tela (`state`) em vez de um redirect.
 *
 * Escopo: só o participante e o envelope da sessão. O PDF servido é sempre a versão congelada
 * no envio de um documento DESTE envelope; não há download de comprovante nem de arquivo final
 * (a cópia chega por e-mail, como no fluxo normal).
 */
class EmbedSessionController extends Controller
{
    public function __construct(
        private readonly EmbeddedSessionExchange $exchanger,
        private readonly EmbedScreenProps $screens,
        private readonly SignerSessions $sessions,
        private readonly Challenges $challenges,
        private readonly SenderPins $pins,
        private readonly RecordAcceptance $acceptances,
        private readonly RecordRefusal $refusals,
        private readonly DocumentStorage $storage,
    ) {}

    /**
     * Troca a URL de uso único pelo token de execução (sem autenticação prévia: o token da URL
     * É a autenticação, e só serve uma vez).
     */
    public function exchange(Request $request, string $session): JsonResponse
    {
        if (! EmbedFeature::globallyEnabled()) {
            return EmbedResponses::notFound();
        }

        /** @var EmbeddedSigningSession|null $embedded */
        $embedded = EmbeddedSigningSession::withoutGlobalScopes()->where('ulid', $session)->first();
        /** @var Organization|null $organization */
        $organization = $embedded === null ? null : Organization::query()->whereKey($embedded->organization_id)->first();

        if ($embedded === null || $organization === null || ! EmbedFeature::enabled($organization)) {
            return EmbedResponses::notFound();
        }

        $raw = $request->input('token');

        if (! is_string($raw) || $raw === '' || strlen($raw) > 128 || ! SignerTokens::matches($embedded->token_digest, $raw)) {
            return EmbedResponses::error('invalid_link', 'Este link de assinatura não é válido.', 404);
        }

        if (! AllowedOrigins::allows($organization, $embedded->allowed_origin)) {
            return EmbedResponses::error('origin_removed', 'Este site não está mais autorizado a exibir o documento.', 410);
        }

        try {
            $result = $this->exchanger->exchange($embedded, $raw, $request);
        } catch (EmbedRejected $exception) {
            return EmbedResponses::rejected($exception);
        }

        return response()->json([
            'token' => $result['token'],
            'expires_at' => $result['expires_at']->toIso8601String(),
            'session_id' => $embedded->ulid,
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function state(Request $request, string $session): JsonResponse
    {
        $embedded = AuthenticateEmbeddedSession::session($request);

        return EmbedResponses::state($this->screens->build($embedded, $this->context($request), $request));
    }

    public function sendCode(Request $request, string $session): JsonResponse
    {
        $embedded = AuthenticateEmbeddedSession::session($request);
        $context = $this->activeContext($request, $embedded);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $this->challenges->send($context, $request);
        } catch (SigningRejectedException $exception) {
            return EmbedResponses::signing($exception);
        }

        return EmbedResponses::state(
            $this->screens->build($embedded, $context->refreshed(), $request),
            sprintf('Enviamos um código para %s.', SignerAuthProps::destination($context)),
        );
    }

    public function verifyCode(Request $request, string $session): JsonResponse
    {
        $embedded = AuthenticateEmbeddedSession::session($request);
        $context = $this->activeContext($request, $embedded);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{4,10}$/'],
        ], [
            'code.required' => 'Informe o código recebido.',
            'code.regex' => 'O código tem apenas números.',
        ]);

        try {
            $this->challenges->verify($context, $request, (string) $validated['code']);
        } catch (SigningRejectedException $exception) {
            return EmbedResponses::signing($exception);
        }

        $message = $this->pins->pendingGate($context, $request) !== null
            ? 'Código confirmado. Agora informe o PIN que quem enviou o documento combinou com você.'
            // O código prova a posse do canal, não a identidade (arquitetura §2, T1).
            : 'Código confirmado. Revise o documento e registre seu aceite.';

        return EmbedResponses::state($this->screens->build($embedded, $context->refreshed(), $request), $message);
    }

    public function verifyPin(Request $request, string $session): JsonResponse
    {
        $embedded = AuthenticateEmbeddedSession::session($request);
        $context = $this->activeContext($request, $embedded);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'max:64'],
        ], [
            'pin.required' => 'Informe o PIN.',
        ]);

        try {
            $this->pins->verify($context, $request, (string) $validated['pin']);
        } catch (SigningRejectedException $exception) {
            return EmbedResponses::signing($exception);
        }

        return EmbedResponses::state(
            $this->screens->build($embedded, $context->refreshed(), $request),
            'PIN confirmado. Revise o documento e registre seu aceite.',
        );
    }

    /**
     * PDF da versão congelada no envio de UM documento deste envelope — exige a sessão do
     * participante já autenticada (código e, se houver, PIN). Mesma marca de apresentação da
     * página pública (`document.presented`), que o aceite exige.
     */
    public function document(Request $request, string $session, string $document): Response
    {
        $embedded = AuthenticateEmbeddedSession::session($request);
        $context = $this->context($request);

        if ($context === null || ! $context->isActive()) {
            return EmbedResponses::error('document_unavailable', 'Documento indisponível.', 404);
        }

        $signing = $this->sessions->current($context, $request);

        if ($signing === null) {
            return EmbedResponses::error('document_unavailable', 'Confirme o código para ver o documento.', 404);
        }

        $sent = $context->sentDocuments();
        $row = collect($sent)->first(fn (array $item): bool => $item['document']->ulid === $document);

        if ($row === null || ! $this->storage->exists($row['version'])) {
            return EmbedResponses::error('document_unavailable', 'Documento indisponível.', 404);
        }

        $this->markPresented($context, $signing, $row['version'], $row['document'], count($sent) > 1, $embedded);

        $title = count($sent) > 1 && (int) $row['document']->position > 1
            ? $context->envelope->title.' — '.$row['document']->name
            : $context->envelope->title;

        $filename = $this->storage->downloadFilename($title, $context->envelope->display_code, 'pdf');

        return $this->storage->stream($row['version'], $filename, 'inline', 'application/pdf');
    }

    public function complete(EmbedAcceptanceRequest $request, string $session): JsonResponse
    {
        $embedded = AuthenticateEmbeddedSession::session($request);
        $context = $this->activeContext($request, $embedded);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $signing = $this->sessions->current($context, $request);

        if ($signing === null) {
            return EmbedResponses::error('code_required', 'Sua sessão expirou. Confirme o código enviado a você para continuar.', 409);
        }

        try {
            $this->acceptances->handle($context, $signing, $request, $request->payload());
        } catch (SigningRejectedException $exception) {
            return EmbedResponses::signing($exception);
        }

        $embedded->forceFill([
            'outcome' => EmbeddedSigningSession::OUTCOME_COMPLETED,
            'completed_at' => Carbon::now(),
        ])->save();

        return EmbedResponses::state($this->screens->build($embedded, $context->refreshed(), $request), 'Aceite registrado.');
    }

    public function refuse(Request $request, string $session): JsonResponse
    {
        $embedded = AuthenticateEmbeddedSession::session($request);
        $context = $this->activeContext($request, $embedded);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.RecordRefusal::MIN_REASON, 'max:'.RecordRefusal::MAX_REASON],
        ], [
            'reason.required' => 'Conte o motivo da recusa.',
            'reason.min' => sprintf('Descreva o motivo com pelo menos %d caracteres.', RecordRefusal::MIN_REASON),
            'reason.max' => sprintf('O motivo pode ter no máximo %d caracteres.', RecordRefusal::MAX_REASON),
        ]);

        if ($this->sessions->current($context, $request) === null) {
            return EmbedResponses::error('code_required', 'Sua sessão expirou. Confirme o código enviado a você para continuar.', 409);
        }

        try {
            $this->refusals->handle($context, trim((string) $validated['reason']));
        } catch (SigningRejectedException $exception) {
            return EmbedResponses::signing($exception);
        }

        $embedded->forceFill([
            'outcome' => EmbeddedSigningSession::OUTCOME_REFUSED,
            'completed_at' => Carbon::now(),
        ])->save();

        return EmbedResponses::state($this->screens->build($embedded, $context->refreshed(), $request), 'Recusa registrada.');
    }

    private function context(Request $request): ?SignerContext
    {
        $context = $request->attributes->get(ResolveSignerToken::ATTRIBUTE);

        return $context instanceof SignerContext ? $context : null;
    }

    private function activeContext(Request $request, EmbeddedSigningSession $embedded): SignerContext|JsonResponse
    {
        $context = $this->context($request);

        if ($context === null || $embedded->outcome !== null || ! $context->isActive()) {
            return EmbedResponses::error('not_signable', 'Este documento não está mais disponível para assinatura.', 409);
        }

        return $context;
    }

    /**
     * Igual a App\Http\Controllers\Sign\DocumentController::markPresented: a ENTREGA dos bytes a
     * esta sessão (não a leitura), uma marca por sessão e documento. O payload diz o canal.
     */
    private function markPresented(SignerContext $context, SigningSession $session, DocumentVersion $version, Document $document, bool $multi, EmbeddedSigningSession $embedded): void
    {
        $now = Carbon::now();
        $first = $session->document_presented_at === null;

        if ($first) {
            $session->forceFill(['document_presented_at' => $now])->save();
        }

        $recorded = false;

        $exists = SigningSessionDocument::withoutOrganizationScope()
            ->where('signing_session_id', $session->getKey())
            ->where('document_id', $document->getKey())
            ->exists();

        if (! $exists) {
            SigningSessionDocument::query()->create([
                'signing_session_id' => $session->getKey(),
                'document_id' => $document->getKey(),
                'document_version_id' => $version->getKey(),
                'organization_id' => $context->envelope->organization_id,
                'presented_at' => $now,
            ]);

            $recorded = true;
        }

        if ($multi ? ! $recorded : ! $first) {
            return;
        }

        $payload = [
            'document_version_ulid' => $version->ulid,
            'sha256' => $version->sha256,
            'session' => $session->ulid,
            'channel' => 'embedded',
            'embedded_session' => $embedded->ulid,
        ];

        if ($multi) {
            $payload['document_ulid'] = $document->ulid;
            $payload['position'] = (int) $document->position;
        }

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::DocumentPresented, $payload);
    }
}
