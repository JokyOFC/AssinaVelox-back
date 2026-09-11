<?php

namespace App\Services\Signing;

use App\Enums\AuditEventType;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use App\Enums\DeliveryStatus;
use App\Models\AuthChallenge;
use App\Models\DeliveryAttempt;
use App\Notifications\Signing\SignerOtpNotification;
use App\Rules\PhoneE164;
use App\Services\Signing\Certificates\ParticipantCertificateService;
use App\Services\Signing\Channels\ChannelAvailability;
use App\Services\Signing\Channels\ChannelDelivery;
use App\Services\Signing\Channels\ChannelMessages;
use App\Services\Signing\Channels\SenderPins;
use App\Services\Signing\Exceptions\SigningRejectedException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Código de uso único enviado por e-mail (arquitetura §3.1 `auth_challenges`, §4.2/§4.3) —
 * e, na Fase 2 §2.9 (flag `sms_whatsapp`), por SMS ou WhatsApp, com as mesmas garantias. O
 * canal é o do método escolhido pelo remetente (`recipients.auth_method`). Com PIN do
 * remetente, o código confirmado abre a etapa do PIN (Channels\SenderPins) em vez de
 * autenticar a sessão. Semântica: o código prova a posse do canal, não identidade.
 *
 * ## Como o código é guardado
 *
 * Nunca em claro. `auth_challenges.code_hash` recebe
 * `HMAC-SHA256("{ulid}|{codigo}", segredo)`, com o segredo **derivado da APP_KEY** — não a
 * APP_KEY em si, para que um vazamento do banco não permita testar códigos sem também
 * conhecer a chave da aplicação, e para que essa derivação não se confunda com a chave usada
 * na criptografia de sessões e cookies. O `ulid` da linha entra no HMAC como sal: dois
 * desafios com o mesmo código de seis dígitos têm hashes diferentes, então uma consulta por
 * hash não agrupa códigos iguais.
 *
 * A conferência é `hash_equals` sobre o HMAC recalculado — nunca `===` sobre o código.
 *
 * ## Limites
 *
 * | Limite                        | Valor padrão | Chave                              |
 * |-------------------------------|--------------|------------------------------------|
 * | Intervalo entre envios        | 60 s         | `otp.resend_interval_seconds`      |
 * | Envios por link por hora      | 5            | `otp.resend_limit`                 |
 * | Envios por IP por hora        | 30           | `otp.ip_hourly_limit`              |
 * | Validade do código            | 10 min       | `otp.ttl_minutes`                  |
 * | Tentativas por código         | 5            | `otp.max_attempts`                 |
 *
 * Acima disso ainda existem os limitadores de rota (`throttle:otp-send` 3/10 min e
 * `throttle:otp-verify` 5/10 min, ambos por token) definidos em AppServiceProvider. Os daqui
 * são por **link e por IP** e sobrevivem a troca de rota; os da rota são a primeira barreira.
 *
 * Eventos gravados: `challenge.sent`, `challenge.verified`, `challenge.failed` — sempre sem
 * o código e sem o token.
 */
final class Challenges
{
    public function __construct(
        private readonly Repository $config,
        private readonly SignerSessions $sessions,
        private readonly ChannelAvailability $availability,
        private readonly ChannelDelivery $delivery,
        private readonly SenderPins $pins,
    ) {}

    // -- Envio ------------------------------------------------------------------------

    /**
     * Gera e envia um código. Devolve o desafio criado.
     *
     * @throws SigningRejectedException quando algum limite foi atingido
     */
    public function send(SignerContext $context, Request $request): AuthChallenge
    {
        $this->assertCanSend($context, $request);

        // Fase 2 §2.9: o canal do código é o do método escolhido pelo remetente. Todas as
        // garantias abaixo (CSPRNG, HMAC, validade, tentativas, consumo único, limites por link
        // e por IP) são as MESMAS para e-mail, SMS e WhatsApp.
        $channel = $context->recipient->auth_method->channel();
        $phone = $channel === DeliveryChannel::Email ? null : $this->assertChannelReady($context, $channel);

        $correlationId = SignerTokens::correlationId();
        $ttl = $this->ttlMinutes();
        $expiresAt = Carbon::now()->addMinutes($ttl);

        $session = $this->sessions->startPending($context, $request);

        // O código existe apenas nesta variável e no corpo do e-mail. Nada mais.
        $code = $this->generateCode();

        $challenge = DB::transaction(function () use ($context, $session, $expiresAt, $code, $channel): AuthChallenge {
            // Um código vivo por vez: pedir outro invalida o anterior, senão dois códigos
            // válidos ao mesmo tempo dobrariam a superfície de adivinhação.
            $this->invalidateLiveChallenges($context);

            /** @var AuthChallenge $challenge */
            $challenge = AuthChallenge::query()->create([
                'signing_session_id' => $session->getKey(),
                'recipient_id' => $context->recipient->getKey(),
                'envelope_id' => $context->envelope->getKey(),
                'organization_id' => $context->envelope->organization_id,
                'channel' => $channel,
                'code_hash' => 'pending',
                'attempts' => 0,
                'max_attempts' => $this->maxAttempts(),
                'expires_at' => $expiresAt,
            ]);

            // O HMAC depende do ULID, que só existe depois de criar a linha.
            $challenge->forceFill(['code_hash' => self::hashCode($challenge->ulid, $code)])->save();

            return $challenge;
        });

        if ($channel === DeliveryChannel::Email) {
            // Sai pelo canal rastreado (EmailProvider + delivery_attempts), como as demais
            // mensagens do envelope. O código existe apenas nesta variável e no corpo do e-mail.
            Notification::route('mail', $context->recipient->email)->notify(
                new SignerOtpNotification($context->recipient, $context->envelope, $code, $ttl, $correlationId),
            );

            $attempt = $this->deliveryAttemptFor($correlationId);
        } else {
            // SMS/WhatsApp: provedor do canal, uma linha em delivery_attempts por tentativa.
            // O código vai só no parâmetro `code` (marcado como sensível) e no texto — nunca
            // em meta, log ou trilha. Tempo esgotado = `unknown`, nunca `sent`.
            $attempt = $this->delivery->send(
                recipient: $context->recipient,
                channel: $channel,
                toE164: (string) $phone,
                purpose: DeliveryPurpose::Otp,
                template: ChannelMessages::template($channel, DeliveryPurpose::Otp),
                parameters: [
                    'code' => $code,
                    'ttl_minutes' => (string) $ttl,
                    'title' => ChannelMessages::plain($context->envelope->title, 40),
                ],
                text: ChannelMessages::otpText($code, $context->envelope->title, $ttl),
                sensitive: ['code'],
                correlationId: $correlationId,
                meta: ['envelope' => $context->envelope->display_code, 'ttl_minutes' => $ttl],
            );
        }

        if ($attempt !== null) {
            $challenge->forceFill(['delivery_attempt_id' => $attempt->getKey()])->save();
        }

        $this->hitSendLimiters($context, $request);

        $payload = [
            'challenge_ulid' => $challenge->ulid,
            'channel' => $channel->value,
            'expires_at' => $expiresAt->toIso8601String(),
            // "sent" nunca significa "entregue"; `unknown` (provedor fake ou resposta
            // inconclusiva) tampouco significa sucesso.
            'delivery_status' => ($attempt === null ? DeliveryStatus::Unknown : $attempt->status)->value,
        ];

        if ($channel !== DeliveryChannel::Email) {
            // Evidência honesta: um código "enviado" pelo simulador não chegou a celular nenhum.
            $payload['simulated'] = (bool) ($attempt->meta['simulated'] ?? false);
        }

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ChallengeSent, $payload, $correlationId);

        return $challenge;
    }

    /**
     * SMS/WhatsApp: provedor pronto, celular válido e limite diário da organização. A flag não é
     * conferida aqui — quem já tem o método escolhido continua atendido se ela for desligada.
     *
     * @throws SigningRejectedException
     */
    private function assertChannelReady(SignerContext $context, DeliveryChannel $channel): string
    {
        $describe = $this->availability->describe($channel, $context->organization, checkFeature: false);

        if (! $describe['available']) {
            throw SigningRejectedException::conflict(
                'channel_unavailable',
                sprintf('O envio do código por %s está indisponível no momento. Fale com quem enviou o documento.', $channel->label()),
            );
        }

        $phone = PhoneE164::normalize((string) $context->recipient->phone);

        if ($phone === null) {
            throw SigningRejectedException::conflict(
                'phone_missing',
                'Não há um celular válido cadastrado para você receber o código. Fale com quem enviou o documento.',
            );
        }

        $this->delivery->assertWithinOrganizationLimit((int) $context->envelope->organization_id);

        return $phone;
    }

    /**
     * @throws SigningRejectedException
     */
    private function assertCanSend(SignerContext $context, Request $request): void
    {
        if (! $context->isActive() && ! self::reopensCertificateStep($context)) {
            throw SigningRejectedException::conflict(
                'not_signable',
                'Este documento não está mais disponível para assinatura.',
            );
        }

        foreach ($this->sendLimiters($context, $request) as [$key, $max, , $message]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw SigningRejectedException::rateLimited(
                    $message(RateLimiter::availableIn($key)),
                    RateLimiter::availableIn($key),
                );
            }
        }
    }

    private function hitSendLimiters(SignerContext $context, Request $request): void
    {
        foreach ($this->sendLimiters($context, $request) as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: callable(int): string}>
     */
    private function sendLimiters(SignerContext $context, Request $request): array
    {
        $link = $context->link->ulid;
        $ip = SignerRequestFacts::ip($request) ?? 'unknown';

        return [
            [
                'signer:otp:interval:'.$link,
                1,
                max(1, (int) $this->config->get('assinavelox.otp.resend_interval_seconds', 60)),
                fn (int $seconds): string => sprintf('Aguarde %ds para reenviar o código.', max(1, $seconds)),
            ],
            [
                'signer:otp:hour:'.$link,
                max(1, (int) $this->config->get('assinavelox.otp.resend_limit', 5)),
                3600,
                fn (): string => 'Você pediu o código muitas vezes. Tente novamente daqui a pouco ou fale com quem enviou o documento.',
            ],
            [
                'signer:otp:ip:'.sha1($ip),
                max(1, (int) $this->config->get('assinavelox.otp.ip_hourly_limit', 30)),
                3600,
                fn (): string => 'Muitos pedidos de código a partir desta conexão. Tente novamente daqui a pouco.',
            ],
        ];
    }

    // -- Verificação ------------------------------------------------------------------

    /**
     * Confere o código e, se bater, autentica a sessão de assinatura.
     *
     * @throws SigningRejectedException com `errorCode`:
     *                                  `no_challenge` (nenhum código pedido / já consumido / expirado),
     *                                  `invalid_code` (código errado, com tentativas restantes),
     *                                  `attempts_exhausted` (5 erros: o código morre e é preciso pedir outro)
     */
    public function verify(SignerContext $context, Request $request, string $code): AuthChallenge
    {
        if (! $context->isActive() && ! self::reopensCertificateStep($context)) {
            throw SigningRejectedException::conflict(
                'not_signable',
                'Este documento não está mais disponível para assinatura.',
            );
        }

        $challenge = $this->latest($context);

        if ($challenge === null || ! $challenge->isVerifiable()) {
            $this->recordFailure($context, $challenge, 'no_live_challenge');

            throw new SigningRejectedException(
                'no_challenge',
                'O código expirou ou já foi usado. Peça um novo código.',
            );
        }

        $expected = self::hashCode($challenge->ulid, $code);

        if (! hash_equals($challenge->code_hash, $expected)) {
            $challenge->forceFill(['attempts' => $challenge->attempts + 1])->save();

            $left = max(0, $challenge->max_attempts - $challenge->attempts);

            if ($left === 0) {
                // Esgotou: o código morre agora, não na expiração.
                $challenge->forceFill(['consumed_at' => Carbon::now()])->save();

                $this->recordFailure($context, $challenge, 'attempts_exhausted');

                throw new SigningRejectedException(
                    'attempts_exhausted',
                    'Código inválido. As tentativas acabaram — peça um novo código.',
                );
            }

            $this->recordFailure($context, $challenge, 'invalid_code');

            throw new SigningRejectedException(
                'invalid_code',
                sprintf('Código inválido ou expirado (%d %s restantes).', $left, $left === 1 ? 'tentativa' : 'tentativas'),
                context: ['attempts_left' => $left],
            );
        }

        $challenge->forceFill(['consumed_at' => Carbon::now()])->save();

        $session = $challenge->signingSession()->withoutGlobalScopes()->first()
            ?? $this->sessions->startPending($context, $request);

        $correlationId = SignerTokens::correlationId();

        // Fase 2 §2.9: com PIN do remetente, o código abre só o "portão" do PIN. A sessão
        // continua `pending_auth` até o PIN conferir (SenderPins::verify).
        if ($this->pins->requiredFor($context->recipient)) {
            $this->pins->openGate($session, $context, $request);

            SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ChallengeVerified, [
                'challenge_ulid' => $challenge->ulid,
                'attempts_used' => $challenge->attempts,
                'next_step' => 'sender_pin',
            ], $correlationId);

            return $challenge;
        }

        // Fase 2 §2.12: quem já aceitou e voltou para enviar o certificado. O código só reabre
        // a janela de download (OtpController), nunca uma sessão de assinatura: nada de
        // `authenticate` nem `session.started` para quem não vai assinar de novo.
        if (! $context->isActive()) {
            SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ChallengeVerified, [
                'challenge_ulid' => $challenge->ulid,
                'attempts_used' => $challenge->attempts,
                'purpose' => 'participant_certificate',
            ], $correlationId);

            return $challenge;
        }

        $this->sessions->authenticate($session, $context, $request);

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ChallengeVerified, [
            'challenge_ulid' => $challenge->ulid,
            'attempts_used' => $challenge->attempts,
        ], $correlationId);

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::SessionStarted, [
            'session_ulid' => $session->ulid,
            'auth_method' => $context->recipient->auth_method->value,
            'expires_at' => $session->refresh()->expires_at->toIso8601String(),
        ], $correlationId);

        return $challenge;
    }

    private function recordFailure(SignerContext $context, ?AuthChallenge $challenge, string $reason): void
    {
        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ChallengeFailed, array_filter([
            'challenge_ulid' => $challenge?->ulid,
            'reason' => $reason,
            'attempts_used' => $challenge?->attempts,
        ], fn ($value) => $value !== null));
    }

    // -- Consulta ---------------------------------------------------------------------

    /**
     * Último desafio deste destinatário (para montar as props `otp`).
     */
    public function latest(SignerContext $context): ?AuthChallenge
    {
        /** @var AuthChallenge|null */
        return AuthChallenge::withoutOrganizationScope()
            ->where('recipient_id', $context->recipient->getKey())
            ->where('envelope_id', $context->envelope->getKey())
            ->latest('id')
            ->first();
    }

    /**
     * Props `otp` de ROUTES §2.18. `resend_available_at` é derivado do limitador, não de um
     * cálculo paralelo: o que a tela mostra é exatamente o que o servidor vai aplicar.
     *
     * @return array{sent_at: string|null, expires_at: string|null, resend_available_at: string|null, attempts_left: int}
     */
    public function props(SignerContext $context): array
    {
        $challenge = $this->latest($context);
        $intervalKey = 'signer:otp:interval:'.$context->link->ulid;
        $availableIn = RateLimiter::tooManyAttempts($intervalKey, 1) ? RateLimiter::availableIn($intervalKey) : 0;

        if ($challenge === null) {
            return [
                'sent_at' => null,
                'expires_at' => null,
                'resend_available_at' => $availableIn > 0 ? Carbon::now()->addSeconds($availableIn)->toIso8601String() : null,
                'attempts_left' => $this->maxAttempts(),
            ];
        }

        $live = $challenge->isVerifiable();

        return [
            'sent_at' => $challenge->created_at?->toIso8601String(),
            'expires_at' => $live ? $challenge->expires_at->toIso8601String() : null,
            'resend_available_at' => $availableIn > 0 ? Carbon::now()->addSeconds($availableIn)->toIso8601String() : null,
            'attempts_left' => $live ? max(0, $challenge->max_attempts - $challenge->attempts) : 0,
        ];
    }

    // -- Internos ---------------------------------------------------------------------

    /**
     * Linha de `delivery_attempts` criada pelo canal rastreado para este envio. Pode não
     * existir quando a fila é assíncrona e o job ainda não rodou — nesse caso o desafio fica
     * sem `delivery_attempt_id` e a trilha registra `unknown`, que é a verdade.
     */
    private function deliveryAttemptFor(string $correlationId): ?DeliveryAttempt
    {
        /** @var DeliveryAttempt|null */
        return DeliveryAttempt::withoutOrganizationScope()
            ->where('correlation_id', $correlationId)
            ->latest('id')
            ->first();
    }

    /**
     * HMAC-SHA256 sobre "{ulid}|{codigo}" com segredo derivado da APP_KEY.
     */
    public static function hashCode(string $challengeUlid, string $code): string
    {
        return hash_hmac('sha256', $challengeUlid.'|'.$code, self::secret());
    }

    /**
     * Segredo derivado da APP_KEY — nunca a própria chave.
     */
    private static function secret(): string
    {
        return hash('sha256', 'assinavelox:auth-challenge:v1|'.(string) config('app.key'), true);
    }

    /**
     * Código de 6 dígitos com `random_int` (CSPRNG). O intervalo começa em 0 e o valor é
     * preenchido com zeros à esquerda: sortear a partir de 100000 descartaria um décimo do
     * espaço e daria ao atacante um dígito de graça.
     */
    private function generateCode(): string
    {
        $length = max(4, (int) $this->config->get('assinavelox.otp.code_length', 6));
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function invalidateLiveChallenges(SignerContext $context): void
    {
        AuthChallenge::withoutOrganizationScope()
            ->where('recipient_id', $context->recipient->getKey())
            ->where('envelope_id', $context->envelope->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Carbon::now()]);
    }

    /**
     * Fase 2 §2.12: quem já aceitou pode pedir/conferir um código só para reabrir a janela de
     * download enquanto o envio do próprio certificado está aberto para ele (senão o cartão do
     * certificado seria inalcançável depois dos 30 min da janela).
     */
    public static function reopensCertificateStep(SignerContext $context): bool
    {
        return app(ParticipantCertificateService::class)->awaitsReturningSigner($context);
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) $this->config->get('assinavelox.otp.ttl_minutes', 10));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) $this->config->get('assinavelox.otp.max_attempts', AuthChallenge::DEFAULT_MAX_ATTEMPTS));
    }
}
