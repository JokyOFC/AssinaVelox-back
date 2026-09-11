<?php

namespace App\Services\InPerson;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\User;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\InPerson\Models\InPersonSession;
use App\Services\InPerson\Models\InPersonTurn;
use App\Services\Signing\EnvelopeExpiration;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerRequestFacts;
use App\Services\Signing\SignerTokens;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ciclo de vida da sessão presencial (docs/fase-2/presencial-e-lote.md §2.2).
 *
 * - **Abrir**: o anfitrião (membro com permissão de enviar o envelope) abre a sessão para um
 *   envelope `in_progress`. O segredo do dispositivo é gerado aqui e guardado SÓ na sessão
 *   Laravel do navegador que vai ficar com os participantes; o banco guarda o digest.
 * - **Usar**: cada requisição do dispositivo resolve a sessão pelo segredo e renova a
 *   atividade. Ela expira sozinha por inatividade (`in_person.idle_minutes`), pelo teto
 *   absoluto (`in_person.max_hours`), quando o envelope sai de `in_progress` e quando a flag
 *   `in_person` é desligada.
 * - **Encerrar**: pelo anfitrião (de qualquer dispositivo dele) ou pelo próprio dispositivo.
 *   Encerrar fecha a vez aberta (revogando a sessão de assinatura do participante) e a sessão
 *   não reabre: para continuar, abre-se outra.
 */
final class InPersonSessions
{
    /** Segredo do dispositivo, na sessão Laravel. */
    public const DEVICE_KEY = 'in_person.device';

    /** Motivo do último encerramento visto por este dispositivo (para a tela explicar). */
    public const ENDED_KEY = 'in_person.ended';

    public function __construct(
        private readonly Repository $config,
        private readonly InPersonTurns $turns,
    ) {}

    public function idleMinutes(): int
    {
        return max(1, (int) $this->config->get('assinavelox.in_person.idle_minutes', 15));
    }

    public function maxHours(): int
    {
        return max(1, (int) $this->config->get('assinavelox.in_person.max_hours', 8));
    }

    /**
     * @return array{session: InPersonSession, secret: string}
     *
     * @throws SigningRejectedException
     */
    public function start(Envelope $envelope, User $host, string $deviceLabel, Request $request): array
    {
        $envelope = EnvelopeExpiration::revalidate($envelope);

        if ($envelope->status !== EnvelopeStatus::InProgress) {
            throw SigningRejectedException::conflict(
                'not_in_progress',
                'A sessão presencial só pode ser aberta para um documento enviado e ainda em andamento.',
            );
        }

        // Um dispositivo, uma sessão: a anterior deste navegador é encerrada.
        $previous = $this->current($request, touch: false);

        if ($previous !== null) {
            $this->end($previous, InPersonSession::END_REPLACED, $host, $request);
        }

        $raw = SignerTokens::generate();
        $now = Carbon::now();
        $label = Str::limit(trim($deviceLabel), 80, '');

        /** @var InPersonSession $session */
        $session = InPersonSession::query()->create([
            'organization_id' => $envelope->organization_id,
            'envelope_id' => $envelope->getKey(),
            'host_user_id' => $host->getKey(),
            'device_label' => $label === '' ? 'Dispositivo sem nome' : $label,
            'device_secret_digest' => SignerTokens::digest($raw),
            'status' => InPersonSession::STATUS_ACTIVE,
            'started_at' => $now,
            'last_activity_at' => $now,
            'expires_at' => $now->copy()->addHours($this->maxHours()),
            'ip_address' => SignerRequestFacts::ip($request),
            'user_agent' => SignerRequestFacts::userAgent($request),
        ]);

        EnvelopeAudit::record($envelope, AuditEventType::InPersonSessionStarted, [
            'in_person_session' => $session->ulid,
            'host_user_id' => $host->getKey(),
            'device_label' => $session->device_label,
            'expires_at' => $session->expires_at->toIso8601String(),
        ]);

        return ['session' => $session, 'secret' => $raw];
    }

    /**
     * Liga o navegador corrente à sessão (depois do login do anfitrião ter sido encerrado,
     * se ele escolheu isso). Nada do estado anterior de signatário sobrevive.
     */
    public function attach(Request $request, string $secret): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->forget(['signer', InPersonTurns::TURN_KEY, self::ENDED_KEY]);
        $request->session()->put(self::DEVICE_KEY, $secret);
        $request->session()->migrate(true);
    }

    /**
     * Sessão presencial ativa DESTE dispositivo, ou null. Expira aqui quando for a hora.
     */
    public function current(Request $request, bool $touch = true): ?InPersonSession
    {
        if (! $request->hasSession()) {
            return null;
        }

        $raw = $request->session()->get(self::DEVICE_KEY);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        /** @var InPersonSession|null $session */
        $session = InPersonSession::withoutOrganizationScope()
            ->where('device_secret_digest', SignerTokens::digest($raw))
            ->first();

        if ($session === null || ! SignerTokens::matches($session->device_secret_digest, $raw)) {
            $this->forgetDevice($request, null);

            return null;
        }

        if (! $session->isActive()) {
            // Encerrada em outro lugar (o anfitrião, a expiração): o dispositivo esquece tudo.
            $this->forgetDevice($request, $session->end_reason);

            return null;
        }

        $reason = $this->expiryReason($session);

        if ($reason !== null) {
            $this->end($session, $reason, null, $request);

            return null;
        }

        if ($touch) {
            $session->forceFill(['last_activity_at' => Carbon::now()])->save();
        }

        return $session;
    }

    /**
     * Motivo pelo qual a sessão não pode continuar, ou null.
     */
    public function expiryReason(InPersonSession $session): ?string
    {
        if ($session->expires_at->isPast()) {
            return InPersonSession::END_MAX_DURATION;
        }

        if ($session->last_activity_at->copy()->addMinutes($this->idleMinutes())->isPast()) {
            return InPersonSession::END_IDLE;
        }

        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->whereKey($session->envelope_id)->first();

        if ($envelope === null || EnvelopeExpiration::revalidate($envelope)->status !== EnvelopeStatus::InProgress) {
            return InPersonSession::END_ENVELOPE_CLOSED;
        }

        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($session->organization_id)->first();

        if (! PresenceFeatures::inPerson($organization)) {
            return 'disabled';
        }

        return null;
    }

    /**
     * Encerra a sessão (idempotente). `$deviceRequest` só quando a requisição vem do próprio
     * dispositivo presencial.
     */
    public function end(InPersonSession $session, string $reason, ?User $by = null, ?Request $deviceRequest = null): bool
    {
        /** @var InPersonSession|null $row */
        $row = DB::transaction(function () use ($session, $reason, $by): ?InPersonSession {
            /** @var InPersonSession|null $row */
            $row = InPersonSession::withoutOrganizationScope()->whereKey($session->getKey())->lockForUpdate()->first();

            if ($row === null || ! $row->isActive()) {
                return null;
            }

            $row->forceFill([
                'status' => in_array($reason, [InPersonSession::END_IDLE, InPersonSession::END_MAX_DURATION], true)
                    ? InPersonSession::STATUS_EXPIRED
                    : InPersonSession::STATUS_ENDED,
                'ended_at' => Carbon::now(),
                'end_reason' => $reason,
                'ended_by_user_id' => $by?->getKey(),
            ])->save();

            return $row;
        });

        if ($row === null) {
            if ($deviceRequest !== null) {
                $this->forgetDevice($deviceRequest, $session->end_reason ?? $reason);
            }

            return false;
        }

        $this->turns->closeActive($row, InPersonTurn::CLOSE_SESSION_ENDED, $deviceRequest);

        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->whereKey($row->envelope_id)->first();

        if ($envelope !== null) {
            $payload = [
                'in_person_session' => $row->ulid,
                'reason' => $reason,
                'accepted' => InPersonTurn::withoutOrganizationScope()
                    ->where('in_person_session_id', $row->getKey())
                    ->where('status', InPersonTurn::STATUS_ACCEPTED)
                    ->count(),
            ];

            if ($by !== null) {
                EnvelopeAudit::record($envelope, AuditEventType::InPersonSessionEnded, $payload);
            } else {
                SignerAudit::system($envelope, AuditEventType::InPersonSessionEnded, $payload);
            }
        }

        if ($deviceRequest !== null) {
            $this->forgetDevice($deviceRequest, $reason);
        }

        return true;
    }

    /**
     * O dispositivo esquece a sessão presencial e qualquer estado de signatário.
     */
    private function forgetDevice(Request $request, ?string $reason): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->forget(['signer', InPersonTurns::TURN_KEY, self::DEVICE_KEY]);

        if ($reason !== null) {
            $request->session()->put(self::ENDED_KEY, $reason);
        }

        $request->session()->migrate(true);
    }

    /**
     * Frase da tela para o motivo de encerramento.
     */
    public static function endMessage(?string $reason): string
    {
        return match ($reason) {
            InPersonSession::END_IDLE => 'A sessão presencial foi encerrada por inatividade.',
            InPersonSession::END_MAX_DURATION => 'A sessão presencial atingiu o tempo máximo e foi encerrada.',
            InPersonSession::END_ENVELOPE_CLOSED => 'O documento não está mais em andamento: todos concluíram ou a coleta foi encerrada.',
            InPersonSession::END_HOST => 'Quem conduz a sessão a encerrou.',
            InPersonSession::END_REPLACED => 'Uma nova sessão presencial foi aberta neste dispositivo.',
            'disabled' => 'A assinatura presencial não está disponível para esta conta.',
            default => 'A sessão presencial foi encerrada neste dispositivo.',
        };
    }
}
