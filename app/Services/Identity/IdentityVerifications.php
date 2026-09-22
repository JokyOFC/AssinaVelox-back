<?php

namespace App\Services\Identity;

use App\Enums\AuditEventType;
use App\Integrations\Contracts\IdentityVerificationProvider;
use App\Integrations\Dto\IdentityVerificationImage;
use App\Integrations\Identity\DisabledIdentityVerificationProvider;
use App\Integrations\Identity\FakeIdentityVerificationProvider;
use App\Integrations\Identity\Verifiky\VerifikyIdentityVerificationProvider;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningSession;
use App\Models\User;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Identity\Exceptions\CaptureRejectedException;
use App\Services\Identity\Jobs\SubmitIdentityVerification;
use App\Services\Identity\Models\IdentityCapture;
use App\Services\Identity\Models\IdentityVerification;
use App\Services\Identity\Models\IdentityVerificationRequirement;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerContext;
use App\Support\Correlation;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Verificação facial COM DOCUMENTO por um provedor externo (Fase 4 §4.1,
 * docs/fase-4/verificacao-facial.md). Flag `identity_verification`, que só vale junto com
 * `identity_capture`: as fotos que o provedor compara são as da captura simples.
 *
 * - O remetente exige a verificação por participante (`identity_verification_requirements`,
 *   só no rascunho). Ligar a exigência acrescenta `selfie`, `document_front` e `document_back`
 *   à exigência de fotos; desligar não mexe nas fotos.
 * - O participante tira as três fotos (rotas da captura), escolhe o tipo do documento, marca
 *   o consentimento e envia. A linha nasce `queued`; o job {@see SubmitIdentityVerification}
 *   lê as imagens cifradas do disco, manda ao provedor e grava a resposta. O resultado final
 *   chega na hora, pelo webhook da Verifiky ou por consulta ({@see self::refresh()}).
 * - O aceite só é gravado com a última tentativa APROVADA pelo provedor, feita sobre as fotos
 *   que estão nesta sessão ({@see self::assertApproved()}); o resumo vai para o
 *   `fields_snapshot` e a linha passa a pertencer ao aceite.
 *
 * Quem afirma é o provedor. Esta classe envia imagens e guarda a resposta; ela própria não
 * compara rostos nem lê documento, e todo texto que sai daqui repete o que o provedor disse,
 * nomeando-o. Erro, tempo esgotado ou resposta que não se entende viram `inconclusive` — nunca
 * aprovação (T5) e nunca consomem uma tentativa do participante.
 */
final class IdentityVerifications
{
    /** As três fotos que toda verificação exige, na ordem canônica. */
    public const CAPTURE_KINDS = [CaptureKind::Selfie, CaptureKind::DocumentFront, CaptureKind::DocumentBack];

    /** Intervalo mínimo entre duas consultas ao provedor pela mesma tentativa. */
    public const POLL_INTERVAL_SECONDS = 10;

    /** Código estável da recusa do aceite enquanto não há aprovação do provedor. */
    public const REJECTION_CODE = 'identity_verification_required';

    public function __construct(
        private readonly IdentityCaptures $captures,
        private readonly IdentityVerificationProvider $provider,
        private readonly LoggerInterface $logger,
    ) {}

    // -- Configuração ----------------------------------------------------------------------

    public static function maxAttempts(): int
    {
        return max(1, min(20, (int) config('assinavelox.identity_verification.max_attempts', 3)));
    }

    public static function pendingTimeoutMinutes(): int
    {
        return max(1, (int) config('assinavelox.identity_verification.pending_timeout_minutes', 20));
    }

    /**
     * Tipos de documento aceitos (os da rota de envio por foto do provedor).
     *
     * @return list<string>
     */
    public static function documentTypes(): array
    {
        $configured = config('assinavelox.identity_verification.document_types', ['rg', 'cnh', 'passaporte']);

        $types = array_values(array_filter(
            array_map(static fn (mixed $type): string => is_string($type) ? strtolower(trim($type)) : '', is_array($configured) ? $configured : []),
            static fn (string $type): bool => $type !== '',
        ));

        return $types === [] ? ['rg', 'cnh', 'passaporte'] : $types;
    }

    public static function documentTypeLabel(string $type): string
    {
        return match (strtolower($type)) {
            'rg' => 'RG',
            'cnh' => 'CNH',
            'passaporte' => 'Passaporte',
            default => strtoupper($type),
        };
    }

    /**
     * Nome do provedor para as pessoas, a partir do nome técnico gravado na linha. O adaptador
     * corrente responde pelo próprio nome; os demais vêm da lista fechada de adaptadores.
     */
    public function providerLabel(?string $name = null): string
    {
        if ($name === null || $name === $this->provider->name()) {
            return $this->provider->label();
        }

        return match ($name) {
            VerifikyIdentityVerificationProvider::NAME => 'Verifiky',
            FakeIdentityVerificationProvider::NAME => 'Simulador',
            DisabledIdentityVerificationProvider::NAME => 'Não configurado',
            default => $name,
        };
    }

    public function provider(): IdentityVerificationProvider
    {
        return $this->provider;
    }

    // -- Exigência (remetente) ---------------------------------------------------------------

    public function requirement(Recipient $recipient): ?IdentityVerificationRequirement
    {
        /** @var IdentityVerificationRequirement|null */
        return IdentityVerificationRequirement::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->where('organization_id', $recipient->organization_id)
            ->first();
    }

    /**
     * @return array<string, true> por ULID do destinatário
     */
    public function requirementsForEnvelope(Envelope $envelope): array
    {
        $recipientIds = IdentityVerificationRequirement::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('organization_id', $envelope->organization_id)
            ->pluck('recipient_id')
            ->all();

        $map = [];

        foreach (Recipient::withoutOrganizationScope()->whereIn('id', $recipientIds ?: [0])->pluck('ulid') as $ulid) {
            if (is_string($ulid)) {
                $map[$ulid] = true;
            }
        }

        return $map;
    }

    /**
     * Grava (ou remove) a exigência. Quem chama já autorizou usuário, flag e estado do envelope.
     * Ligar acrescenta as três fotos à exigência de captura (sem tirar o que já estava lá);
     * desligar deixa a exigência de fotos como está.
     */
    public function setRequirement(Envelope $envelope, Recipient $recipient, bool $required, ?User $user): bool
    {
        $current = $this->requirement($recipient);

        if ($required) {
            $kinds = array_values(array_unique(array_merge(
                array_map(static fn (CaptureKind $kind): string => $kind->value, $this->captures->requiredKinds($recipient)),
                CaptureKind::photoValues(),
            )));

            // Idempotente: só grava (e audita) quando a lista muda.
            $this->captures->setRequirement($envelope, $recipient, $kinds, $user);
        }

        if ($required === ($current !== null)) {
            return $required;
        }

        DB::transaction(function () use ($envelope, $recipient, $required, $user): void {
            if (! $required) {
                IdentityVerificationRequirement::withoutOrganizationScope()->where('recipient_id', $recipient->getKey())->delete();

                return;
            }

            $row = new IdentityVerificationRequirement;
            $row->forceFill([
                'organization_id' => $envelope->organization_id,
                'envelope_id' => $envelope->getKey(),
                'recipient_id' => $recipient->getKey(),
                'created_by_user_id' => $user?->getKey(),
            ])->save();
        });

        EnvelopeAudit::record($envelope, AuditEventType::IdentityVerificationRequirementUpdated, [
            'recipient_ulid' => $recipient->ulid,
            'required' => $required,
        ], $recipient);

        return $required;
    }

    /**
     * Flag `identity_verification` da organização ligada (com `identity_capture`), papel que
     * registra aceite e exigência gravada. A flag é conferida antes da consulta: desligada,
     * nenhuma consulta a mais e nada muda.
     */
    public function requiredFor(SignerContext $context): bool
    {
        return $context->action() !== null
            && IdentityFeatures::identityVerification($context->organization)
            && $this->requirement($context->recipient) !== null;
    }

    // -- Tentativas ---------------------------------------------------------------------------

    public function latestFor(Recipient $recipient): ?IdentityVerification
    {
        /** @var IdentityVerification|null */
        return IdentityVerification::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->where('organization_id', $recipient->organization_id)
            ->orderByDesc('id')
            ->first();
    }

    /** Tentativa na fila ou em análise, ou null. */
    public function inFlightFor(Recipient $recipient): ?IdentityVerification
    {
        /** @var IdentityVerification|null */
        return IdentityVerification::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->where('organization_id', $recipient->organization_id)
            ->whereIn('status', [VerificationStatus::Queued->value, VerificationStatus::Pending->value])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Tentativas já consumidas: todas menos as inconclusivas (falha técnica ou prazo não
     * custam ao participante).
     */
    public function attemptsUsed(Recipient $recipient): int
    {
        return IdentityVerification::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->where('organization_id', $recipient->organization_id)
            ->where('status', '!=', VerificationStatus::Inconclusive->value)
            ->count();
    }

    public function attemptsLeft(Recipient $recipient): int
    {
        return max(0, self::maxAttempts() - $this->attemptsUsed($recipient));
    }

    /**
     * Fotos que faltam NESTA sessão para enviar ao provedor (rosto, frente, verso).
     *
     * @return list<CaptureKind>
     */
    public function missingCaptures(SigningSession $session): array
    {
        $current = $this->captures->currentFor($session);

        return array_values(array_filter(
            self::CAPTURE_KINDS,
            static fn (CaptureKind $kind): bool => ! $current->has($kind->value),
        ));
    }

    /**
     * As fotos desta sessão são exatamente as que foram enviadas ao provedor nesta tentativa?
     * Refazer uma foto depois da resposta invalida a resposta: o provedor comparou outras imagens.
     */
    public function capturesMatch(IdentityVerification $verification, SigningSession $session): bool
    {
        $current = $this->captures->currentFor($session);
        $ulids = [];

        foreach (self::CAPTURE_KINDS as $kind) {
            $capture = $current->get($kind->value);

            if ($capture === null) {
                return false;
            }

            $ulids[] = $capture->ulid;
        }

        $sent = $verification->captureUlids();
        sort($ulids);
        sort($sent);

        return $ulids === $sent;
    }

    /**
     * Abre uma tentativa: confere tudo na ordem abaixo, grava a linha `queued` e enfileira o
     * envio depois do commit. As imagens nunca passam por aqui — só o job as lê.
     *
     * @throws CaptureRejectedException
     */
    public function submit(SignerContext $context, SigningSession $session, string $documentType, Request $request): IdentityVerification
    {
        // 1. Exigida para esta pessoa (flag, papel e linha): senão, o mesmo 404 da captura.
        if (! $this->requiredFor($context)) {
            throw new CaptureRejectedException('not_required', 'A verificação facial com documento não foi pedida para você.', 404);
        }

        // 2. Tipo do documento entre os aceitos pelo provedor.
        $documentType = strtolower(trim($documentType));

        if (! in_array($documentType, self::documentTypes(), true)) {
            throw new CaptureRejectedException('invalid_document_type', sprintf(
                'Informe o tipo do documento fotografado: %s.',
                implode(', ', array_map([self::class, 'documentTypeLabel'], self::documentTypes())),
            ));
        }

        // 3. As três fotos precisam existir NESTA sessão.
        $missing = $this->missingCaptures($session);

        if ($missing !== []) {
            throw new CaptureRejectedException('captures_missing', 'Antes de enviar para a verificação, tire: '.implode(', ', array_map(
                static fn (CaptureKind $kind): string => $kind->label(),
                $missing,
            )).'.');
        }

        $recipient = $context->recipient;

        // 4. Uma tentativa por vez.
        if ($this->inFlightFor($recipient) !== null) {
            throw new CaptureRejectedException('verification_in_progress', 'Já há uma verificação em andamento. Aguarde o resultado.', 409);
        }

        // 5. Tentativas esgotadas: só o remetente destrava (novo convite ou retirar a exigência).
        if ($this->attemptsLeft($recipient) <= 0) {
            throw new CaptureRejectedException('no_attempts_left', 'As tentativas de verificação acabaram. Fale com quem enviou o documento.', 409);
        }

        // 6. Consentimento explícito para as fotos saírem da plataforma.
        if (! $request->boolean('consent')) {
            throw new CaptureRejectedException('consent_required', 'Para enviar as fotos ao provedor, marque a autorização.');
        }

        $lock = Cache::lock('identity-verification:'.$recipient->getKey(), 15);

        if (! $lock->get()) {
            throw new CaptureRejectedException('verification_in_progress', 'Já há uma verificação em andamento. Aguarde o resultado.', 409);
        }

        try {
            // Dois cliques ao mesmo tempo: o segundo encontra a linha do primeiro sob o lock
            // (consulta nova, feita DEPOIS de obter o lock — a de cima foi antes dele).
            $inFlight = IdentityVerification::withoutOrganizationScope()
                ->where('recipient_id', $recipient->getKey())
                ->whereIn('status', [VerificationStatus::Queued->value, VerificationStatus::Pending->value])
                ->exists();

            if ($inFlight) {
                throw new CaptureRejectedException('verification_in_progress', 'Já há uma verificação em andamento. Aguarde o resultado.', 409);
            }

            $current = $this->captures->currentFor($session);
            $captures = [];

            foreach (self::CAPTURE_KINDS as $kind) {
                /** @var IdentityCapture $capture */
                $capture = $current->get($kind->value);
                $captures[] = ['capture_ulid' => $capture->ulid, 'kind' => $kind->value, 'sha256' => $capture->sha256];
            }

            $attempt = $this->attemptsUsed($recipient) + 1;
            $providerLabel = $this->provider->label();
            $correlationId = Correlation::id();
            $now = Carbon::now();

            return DB::transaction(function () use ($context, $session, $recipient, $documentType, $captures, $attempt, $providerLabel, $correlationId, $now): IdentityVerification {
                $verification = new IdentityVerification;
                $verification->forceFill([
                    'organization_id' => $context->envelope->organization_id,
                    'envelope_id' => $context->envelope->getKey(),
                    'recipient_id' => $recipient->getKey(),
                    'signing_session_id' => $session->getKey(),
                    'provider' => $this->provider->name(),
                    'reference' => (string) Str::ulid(),
                    'document_type' => $documentType,
                    'status' => VerificationStatus::Queued,
                    'captures' => $captures,
                    'attempt' => $attempt,
                    'consented_at' => $now,
                    'consent_version' => VerificationStep::consentVersion($providerLabel),
                    'correlation_id' => $correlationId,
                ])->save();

                // Só depois do commit: o worker não pode encontrar uma linha que ainda não existe.
                SubmitIdentityVerification::dispatch((int) $verification->getKey())->afterCommit();

                SignerAudit::record($context->envelope, $recipient, AuditEventType::IdentityVerificationSubmitted, [
                    'verification_ulid' => $verification->ulid,
                    'attempt' => $attempt,
                    'provider' => $verification->provider,
                    'document_type' => $documentType,
                    // ULID, tipo e resumo SHA-256 das fotos — nunca a imagem nem o caminho.
                    'captures' => $captures,
                    'consent_version' => $verification->consent_version,
                ], $correlationId);

                return $verification;
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Corpo do job: lê as três imagens cifradas, envia ao provedor e grava a resposta. Nada
     * lançado daqui deixa a tentativa presa em `queued`: qualquer falha vira `inconclusive`.
     */
    public function process(int $verificationId): void
    {
        /** @var IdentityVerification|null $verification */
        $verification = IdentityVerification::withoutOrganizationScope()->whereKey($verificationId)->first();

        if ($verification === null) {
            return;
        }

        if ($verification->status !== VerificationStatus::Queued) {
            $this->logger->debug('Verificação facial já enviada; job repetido ignorado.', [
                'verification_ulid' => $verification->ulid,
                'status' => $verification->status->value,
            ]);

            return;
        }

        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($verification->organization_id)->first();
        /** @var Recipient|null $recipient */
        $recipient = Recipient::withoutOrganizationScope()->whereKey($verification->recipient_id)->first();

        CurrentOrganization::instance()->runAs($organization, function () use ($verification, $recipient): void {
            try {
                $images = $this->images($verification);

                if ($recipient === null || $images === null) {
                    $this->applyResult($verification, $this->localResult(
                        $verification,
                        'missing_images',
                        'As fotos enviadas não estão mais disponíveis. Tire-as de novo e envie outra vez.',
                    ), 'submit');

                    return;
                }

                $verification->forceFill(['submitted_at' => Carbon::now()])->save();

                $result = $this->provider->start($recipient->ulid, [
                    'reference' => $verification->reference,
                    'document_type' => $verification->document_type,
                    'images' => $images,
                ], $verification->correlation_id);

                $this->applyResult($verification, $result, 'submit');
            } catch (Throwable $exception) {
                $this->logger->error('Falha inesperada ao enviar a verificação facial ao provedor.', [
                    'verification_ulid' => $verification->ulid,
                    'provider' => $verification->provider,
                    'exception' => $exception::class,
                ]);

                $this->applyResult($verification, $this->localResult(
                    $verification,
                    'provider_error',
                    'Não foi possível falar com o provedor de verificação. Isso não é uma reprovação: tente de novo em alguns instantes.',
                ), 'submit');
            }
        });
    }

    /**
     * Grava a resposta do provedor. IDEMPOTENTE: uma tentativa conclusiva (aprovada, reprovada
     * ou expirada) nunca muda — um webhook atrasado ou repetido não vira nada do avesso.
     * Inconclusiva ainda pode receber a resposta que chegou tarde.
     *
     * @param  array{verification_id?: string|null, provider?: string, status?: string, checked_at?: string, details?: array<string, mixed>}  $result
     * @param  'submit'|'webhook'|'poll'  $source
     */
    public function applyResult(IdentityVerification $verification, array $result, string $source): IdentityVerification
    {
        /** @var array{0: IdentityVerification, 1: bool} $outcome */
        $outcome = DB::transaction(function () use ($verification, $result, $source): array {
            /** @var IdentityVerification|null $fresh */
            $fresh = IdentityVerification::withoutOrganizationScope()->whereKey($verification->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return [$verification, false];
            }

            if ($fresh->isConclusive()) {
                $this->logger->debug('Resposta do provedor ignorada: a verificação já estava concluída.', [
                    'verification_ulid' => $fresh->ulid,
                    'status' => $fresh->status->value,
                    'source' => $source,
                ]);

                return [$fresh, false];
            }

            $status = VerificationStatus::fromProvider($result['status'] ?? null);
            $details = is_array($result['details'] ?? null) ? $result['details'] : [];

            // O simulador se identifica na primeira resposta; um prazo esgotado depois dela
            // não pode apagar essa marca da linha.
            if (($fresh->provider_result['simulated'] ?? false) === true && ! array_key_exists('simulated', $details)) {
                $details['simulated'] = true;
            }

            $providerId = is_string($result['verification_id'] ?? null) && trim($result['verification_id']) !== ''
                ? Str::limit(trim($result['verification_id']), 120, '')
                : $fresh->provider_verification_id;
            $reason = is_string($details['reason_code'] ?? null) ? Str::limit($details['reason_code'], 60, '') : null;

            $fresh->forceFill([
                'provider' => is_string($result['provider'] ?? null) && $result['provider'] !== '' ? Str::limit($result['provider'], 40, '') : $fresh->provider,
                'provider_verification_id' => $providerId,
                'status' => $status,
                'reason_code' => $reason,
                'provider_result' => $details,
                'completed_at' => $status === VerificationStatus::Pending ? null : Carbon::now(),
                'polled_at' => $source === 'poll' ? Carbon::now() : $fresh->polled_at,
            ])->save();

            return [$fresh, $status !== VerificationStatus::Pending];
        });

        [$fresh, $completed] = $outcome;

        if ($completed) {
            /** @var Envelope|null $envelope */
            $envelope = Envelope::withoutOrganizationScope()->whereKey($fresh->envelope_id)->first();
            /** @var Recipient|null $recipient */
            $recipient = Recipient::withoutOrganizationScope()->whereKey($fresh->recipient_id)->first();

            if ($envelope !== null) {
                // Ator `system`: quem afirma o resultado é o provedor. Payload mínimo, sem imagem.
                SignerAudit::system($envelope, AuditEventType::IdentityVerificationCompleted, [
                    'status' => $fresh->status->value,
                    'provider' => $fresh->provider,
                    'simulated' => $fresh->isSimulated(),
                    'provider_verification_id' => $fresh->provider_verification_id,
                    'document_type' => $fresh->document_type,
                    'reason_code' => $fresh->reason_code,
                    'source' => $source,
                ], $recipient, $fresh->correlation_id);
            }
        }

        return $fresh;
    }

    /**
     * Atualiza uma tentativa em andamento: prazo esgotado vira inconclusiva (também para a
     * linha `queued` que o worker nunca pegou); pendente com protocolo é consultada ao provedor
     * no máximo a cada {@see self::POLL_INTERVAL_SECONDS}. `$poll = false` só aplica o prazo —
     * para quem monta a página e não pode esperar uma chamada externa.
     */
    public function refresh(IdentityVerification $verification, bool $poll = true): IdentityVerification
    {
        if (! $verification->isInFlight()) {
            return $verification;
        }

        $since = $verification->submitted_at ?? $verification->created_at;

        if ($since->lte(Carbon::now()->subMinutes(self::pendingTimeoutMinutes()))) {
            return $this->applyResult($verification, $this->localResult(
                $verification,
                'timeout',
                $verification->status === VerificationStatus::Queued
                    ? 'O envio ao provedor não aconteceu no prazo. Isso não é uma reprovação: envie as fotos de novo.'
                    : 'O provedor não respondeu no prazo. Isso não é uma reprovação: envie as fotos de novo.',
            ), 'poll');
        }

        if (! $poll || $verification->status !== VerificationStatus::Pending || $verification->provider_verification_id === null) {
            return $verification;
        }

        if ($verification->polled_at !== null && $verification->polled_at->gt(Carbon::now()->subSeconds(self::POLL_INTERVAL_SECONDS))) {
            return $verification;
        }

        // Marcado ANTES da chamada: duas abas consultando ao mesmo tempo não dobram o pedido.
        $verification->forceFill(['polled_at' => Carbon::now()])->save();

        try {
            $result = $this->provider->result($verification->provider_verification_id, $verification->correlation_id);
        } catch (Throwable $exception) {
            $this->logger->warning('Consulta do resultado da verificação facial falhou; continua pendente.', [
                'verification_ulid' => $verification->ulid,
                'exception' => $exception::class,
            ]);

            return $verification;
        }

        $status = VerificationStatus::fromProvider($result['status']);

        // A consulta que falha (rede, 5xx, formato) não encerra uma análise que pode estar em
        // curso: a tentativa continua pendente até o webhook, a próxima consulta ou o prazo.
        if ($status === VerificationStatus::Pending || $status === VerificationStatus::Inconclusive) {
            return $verification;
        }

        return $this->applyResult($verification, $result, 'poll');
    }

    public function findByReference(string $reference): ?IdentityVerification
    {
        $reference = trim($reference);

        if ($reference === '' || strlen($reference) > 26) {
            return null;
        }

        /** @var IdentityVerification|null */
        return IdentityVerification::withoutOrganizationScope()->where('reference', $reference)->first();
    }

    public function findByProviderId(string $provider, string $providerVerificationId): ?IdentityVerification
    {
        $providerVerificationId = trim($providerVerificationId);

        if ($providerVerificationId === '' || strlen($providerVerificationId) > 120) {
            return null;
        }

        /** @var IdentityVerification|null */
        return IdentityVerification::withoutOrganizationScope()
            ->where('provider', $provider)
            ->where('provider_verification_id', $providerVerificationId)
            ->orderByDesc('id')
            ->first();
    }

    // -- Aceite --------------------------------------------------------------------------------

    /**
     * A tentativa que libera o aceite desta sessão: a última do participante, aprovada pelo
     * provedor, feita sobre as fotos que estão na sessão e ainda sem aceite. Null se não há.
     */
    public function approvedFor(SignerContext $context, SigningSession $session): ?IdentityVerification
    {
        $latest = $this->latestFor($context->recipient);

        if ($latest === null || ! $latest->isApproved() || $latest->signature_acceptance_id !== null) {
            return null;
        }

        return $this->capturesMatch($latest, $session) ? $latest : null;
    }

    /**
     * Recusa o aceite enquanto o provedor não aprovou (ou aprovou outras fotos).
     *
     * @throws SigningRejectedException
     */
    public function assertApproved(SignerContext $context, SigningSession $session): void
    {
        if (! $this->requiredFor($context)) {
            return;
        }

        $latest = $this->latestFor($context->recipient);

        // Só o prazo: nenhuma chamada externa dentro da requisição do aceite.
        $latest = $latest === null ? null : $this->refresh($latest, poll: false);
        $label = $this->providerLabel($latest?->provider);

        $message = match (true) {
            $latest === null => 'Antes de concluir, envie as fotos para a verificação facial com documento.',
            $latest->isInFlight() => 'A verificação facial com documento ainda está em andamento. Aguarde o resultado para concluir.',
            $latest->status === VerificationStatus::Rejected => sprintf('%s informou: reprovado. Refaça as fotos e envie de novo para a verificação; sem uma aprovação do provedor não é possível concluir.', $label),
            $latest->status === VerificationStatus::Expired => sprintf('%s informou: expirado — o prazo da análise terminou. Envie as fotos de novo.', $label),
            $latest->status === VerificationStatus::Inconclusive => 'A verificação facial com documento não foi concluída. Envie as fotos de novo.',
            $latest->signature_acceptance_id !== null => 'A verificação já foi usada em outro aceite. Envie as fotos de novo.',
            ! $this->capturesMatch($latest, $session) => 'As fotos foram refeitas depois da verificação: envie-as de novo para a verificação facial com documento.',
            default => null,
        };

        if ($message === null) {
            return;
        }

        throw new SigningRejectedException(
            self::REJECTION_CODE,
            $message,
            context: ['identity_verification' => $latest?->status->value ?? 'none'],
        );
    }

    /**
     * Resumo da verificação aprovada que o aceite vai referenciar (`fields_snapshot`
     * `identity_verification`). Só quando exigida; sem exigência, `[]` e o snapshot é o de antes.
     * Sem imagem, sem caminho e sem dado lido do documento.
     *
     * @return array<string, mixed>
     */
    public function snapshotFor(SignerContext $context, SigningSession $session): array
    {
        if (! $this->requiredFor($context)) {
            return [];
        }

        $approved = $this->approvedFor($context, $session);

        if ($approved === null) {
            return [];
        }

        $snapshot = [
            'verification_ulid' => $approved->ulid,
            'provider' => $approved->provider,
            'provider_label' => $this->providerLabel($approved->provider),
            'simulated' => $approved->isSimulated(),
            'provider_verification_id' => $approved->provider_verification_id,
            'document_type' => $approved->document_type,
            'status' => $approved->status->value,
        ];

        if ($approved->faceMatch() !== null) {
            $snapshot['face_match'] = $approved->faceMatch();
        }

        if ($approved->faceScore() !== null) {
            $snapshot['face_score'] = $approved->faceScore();
        }

        $snapshot['completed_at'] = $approved->completed_at?->toIso8601String();
        $snapshot['consent_version'] = $approved->consent_version;

        return $snapshot;
    }

    /**
     * Vincula ao aceite a verificação que o snapshot referenciou. Roda DENTRO da transação do
     * aceite (só UPDATE local).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function attachToAcceptance(SignatureAcceptance $acceptance, array $snapshot): int
    {
        $ulid = $snapshot['verification_ulid'] ?? null;

        if (! is_string($ulid) || $ulid === '') {
            return 0;
        }

        return IdentityVerification::withoutOrganizationScope()
            ->where('ulid', $ulid)
            ->where('recipient_id', $acceptance->recipient_id)
            ->where('organization_id', $acceptance->organization_id)
            ->where('status', VerificationStatus::Approved->value)
            ->whereNull('signature_acceptance_id')
            ->update(['signature_acceptance_id' => $acceptance->getKey(), 'updated_at' => Carbon::now()]);
    }

    // -- Internos --------------------------------------------------------------------------------

    /**
     * As três imagens em claro, ou null se alguma não está mais no disco (refeita, excluída
     * pela retenção ou ilegível). Vivem só na memória do job.
     *
     * @return array{selfie: IdentityVerificationImage, document_front: IdentityVerificationImage, document_back: IdentityVerificationImage}|null
     */
    private function images(IdentityVerification $verification): ?array
    {
        $rows = IdentityCapture::withoutOrganizationScope()
            ->whereIn('ulid', $verification->captureUlids() ?: [''])
            ->where('recipient_id', $verification->recipient_id)
            ->get()
            ->keyBy(fn (IdentityCapture $capture): string => $capture->kind->value);

        $images = [];

        foreach (self::CAPTURE_KINDS as $kind) {
            /** @var IdentityCapture|null $capture */
            $capture = $rows->get($kind->value);
            $bytes = $capture === null ? null : $this->captures->readImage($capture);

            if ($capture === null || $bytes === null) {
                return null;
            }

            $images[$kind->value] = new IdentityVerificationImage($bytes, $capture->mime_type, match ($kind) {
                CaptureKind::Selfie => 'selfie.jpg',
                CaptureKind::DocumentFront => 'documento-frente.jpg',
                default => 'documento-verso.jpg',
            });
        }

        /** @var array{selfie: IdentityVerificationImage, document_front: IdentityVerificationImage, document_back: IdentityVerificationImage} */
        return $images;
    }

    /**
     * Resultado "inconclusivo" decidido do nosso lado (imagem ausente, exceção, prazo): a
     * mesma forma da resposta do provedor, para passar por {@see self::applyResult()}.
     *
     * @return array{verification_id: string|null, provider: string, status: 'inconclusive', checked_at: string, details: array<string, mixed>}
     */
    private function localResult(IdentityVerification $verification, string $reasonCode, string $message): array
    {
        return [
            'verification_id' => $verification->provider_verification_id,
            'provider' => $verification->provider,
            'status' => VerificationStatus::Inconclusive->value,
            'checked_at' => Carbon::now()->toIso8601String(),
            'details' => ['reason_code' => $reasonCode, 'message' => $message],
        ];
    }
}
