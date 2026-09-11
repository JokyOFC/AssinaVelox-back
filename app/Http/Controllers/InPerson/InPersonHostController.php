<?php

namespace App\Http\Controllers\InPerson;

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\InPerson\StartInPersonRequest;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\User;
use App\Policies\InPersonSessionPolicy;
use App\Services\InPerson\InPersonSessions;
use App\Services\InPerson\Models\InPersonSession;
use App\Services\InPerson\PresenceFeatures;
use App\Services\Organizations\EnvelopeVisibility;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lado do ANFITRIÃO da sessão presencial (docs/fase-2/presencial-e-lote.md §2.1).
 *
 * - `GET presencial/iniciar` (`in_person.create`): escolher o documento enviado, dar nome ao
 *   dispositivo e abrir; lista as sessões ativas da conta. Flag desligada: estado "Fase 2".
 * - `POST presencial` (`in_person.store`): abre a sessão e liga ESTE navegador a ela. Por
 *   padrão o anfitrião é desconectado deste navegador antes de o dispositivo ir para as
 *   mãos dos participantes — assim ninguém na fila chega ao painel da conta.
 * - `POST presencial/{session}/encerrar` (`in_person.end`): encerra de qualquer dispositivo.
 */
class InPersonHostController extends Controller
{
    public function __construct(
        private readonly InPersonSessions $sessions,
        private readonly InPersonSessionPolicy $policy,
    ) {}

    public function create(Request $request): Response
    {
        $current = CurrentOrganization::instance();
        $organization = $current->get();
        $membership = $current->membership();
        /** @var User $user */
        $user = $request->user();

        if (! PresenceFeatures::inPerson($organization) || $membership === null) {
            return Inertia::render('in-person/start', ['enabled' => false]);
        }

        $canStart = $this->policy->viewAny($user);

        $envelopes = $canStart
            ? EnvelopeVisibility::envelopes($membership)
                ->where('status', EnvelopeStatus::InProgress->value)
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->filter(fn (Envelope $envelope): bool => $this->policy->start($user, $envelope))
                ->values()
            : collect();

        $counts = Recipient::query()
            ->whereIn('envelope_id', $envelopes->pluck('id')->all() ?: [0])
            ->whereIn('role', RecipientRole::participatingValues())
            ->get(['envelope_id', 'status'])
            ->groupBy('envelope_id');

        $sessions = InPersonSession::query()
            ->where('status', InPersonSession::STATUS_ACTIVE)
            ->orderByDesc('started_at')
            ->limit(50)
            ->get()
            ->filter(function (InPersonSession $session): bool {
                $reason = $this->sessions->expiryReason($session);

                if ($reason !== null) {
                    $this->sessions->end($session, $reason);

                    return false;
                }

                return true;
            })
            ->filter(fn (InPersonSession $session): bool => $this->policy->end($user, $session))
            ->values();

        $sessionEnvelopes = Envelope::query()->whereIn('id', $sessions->pluck('envelope_id')->all() ?: [0])->get()->keyBy('id');
        $hosts = User::query()->whereIn('id', $sessions->pluck('host_user_id')->filter()->all() ?: [0])->get()->keyBy('id');

        return Inertia::render('in-person/start', [
            'enabled' => true,
            'can_start' => $canStart,
            'selected' => is_string($request->query('documento')) ? strtoupper($request->query('documento')) : null,
            'idle_minutes' => $this->sessions->idleMinutes(),
            'max_hours' => $this->sessions->maxHours(),
            'envelopes' => $envelopes->map(function (Envelope $envelope) use ($counts): array {
                $rows = $counts->get($envelope->getKey(), collect());

                return [
                    'id' => $envelope->ulid,
                    'title' => $envelope->title,
                    'display_code' => $envelope->display_code,
                    'sent_at' => $envelope->sent_at?->toIso8601String(),
                    'expires_at' => $envelope->expires_at?->toIso8601String(),
                    'signing_order' => $envelope->signing_order->value,
                    'participants' => $rows->count(),
                    'pending' => $rows->filter(fn (Recipient $row): bool => $row->status->isPendingSignature())->count(),
                    'done' => $rows->where('status', RecipientStatus::Signed)->count(),
                ];
            })->all(),
            'sessions' => $sessions->map(fn (InPersonSession $session): array => [
                'id' => $session->ulid,
                'device_label' => $session->device_label,
                'host_name' => $hosts->get($session->host_user_id)?->name,
                'hosted_by_me' => $session->host_user_id === $user->getKey(),
                'started_at' => $session->started_at->toIso8601String(),
                'last_activity_at' => $session->last_activity_at->toIso8601String(),
                'envelope' => [
                    'id' => $sessionEnvelopes->get($session->envelope_id)?->ulid,
                    'title' => $sessionEnvelopes->get($session->envelope_id)?->title,
                    'display_code' => $sessionEnvelopes->get($session->envelope_id)?->display_code,
                ],
            ])->all(),
        ]);
    }

    public function store(StartInPersonRequest $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();

        abort_unless(PresenceFeatures::inPerson($organization), 404);

        /** @var User $user */
        $user = $request->user();

        // Escopo da organização corrente: envelope de outra conta = 404.
        /** @var Envelope|null $envelope */
        $envelope = Envelope::query()->where('ulid', $request->envelopeUlid())->first();

        abort_if($envelope === null, 404);
        abort_unless($this->policy->start($user, $envelope), 403);

        try {
            ['secret' => $secret] = $this->sessions->start($envelope, $user, $request->deviceLabel(), $request);
        } catch (SigningRejectedException $exception) {
            return back()->withErrors(['envelope' => $exception->getMessage()]);
        }

        if (! $request->boolean('keep_signed_in')) {
            // O dispositivo vai para as mãos dos participantes: a conta do anfitrião sai dele.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $this->sessions->attach($request, $secret);

        return redirect()
            ->route('in_person.kiosk.show')
            ->with('success', 'Sessão presencial aberta neste dispositivo. Entregue-o ao primeiro participante.');
    }

    public function end(Request $request, string $session): RedirectResponse
    {
        abort_unless(PresenceFeatures::inPerson(CurrentOrganization::instance()->get()), 404);

        /** @var User $user */
        $user = $request->user();

        // Escopo da organização corrente: sessão de outra conta = 404.
        /** @var InPersonSession|null $row */
        $row = InPersonSession::query()->where('ulid', strtoupper($session))->first();

        abort_if($row === null, 404);
        abort_unless($this->policy->end($user, $row), 403);

        $this->sessions->end($row, InPersonSession::END_HOST, $user);

        return back()->with('success', 'Sessão presencial encerrada. O dispositivo foi bloqueado.');
    }
}
