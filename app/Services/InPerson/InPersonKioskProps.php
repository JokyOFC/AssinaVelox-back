<?php

namespace App\Services\InPerson;

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\SigningSession;
use App\Models\User;
use App\Services\Branding\BrandingPresenter;
use App\Services\Identity\CaptureStep;
use App\Services\Identity\IdentityCaptures;
use App\Services\InPerson\Models\InPersonSession;
use App\Services\InPerson\Models\InPersonTurn;
use App\Services\Signing\Challenges;
use App\Services\Signing\Channels\SenderPins;
use App\Services\Signing\Channels\SignerAuthProps;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerPageProps;
use App\Services\Signing\SignerPresentation;
use Illuminate\Http\Request;

/**
 * Props de `pages/in-person/kiosk.tsx` (docs/fase-2/presencial-e-lote.md §2.4).
 *
 * | screen        | quando                                             | o que vai                          |
 * |---------------|----------------------------------------------------|------------------------------------|
 * | `unavailable` | interruptor global desligado                       | nada                               |
 * | `none`        | nenhum dispositivo presencial ativo neste navegador| motivo do último encerramento      |
 * | `queue`       | sessão ativa, tela BLOQUEADA (ninguém na vez)      | fila: nome, papel e estado          |
 * | `participant` | vez aberta                                         | só os dados DESTE participante      |
 *
 * **Tela limpa entre participantes**: em `queue` não vai nenhum dado de participante além do
 * nome, do papel e do estado na fila — nada de e-mail, telefone, campos, valores, documento,
 * token de autorização ou comprovante. Em `participant` vão só os dados de quem está na vez,
 * montados a partir do contexto DELE; nada da vez anterior sobrevive porque o estado do
 * dispositivo é apagado em cada fronteira ({@see InPersonTurns}).
 */
final class InPersonKioskProps
{
    public function __construct(
        private readonly InPersonTurns $turns,
        private readonly InPersonSessions $sessions,
        private readonly ParticipantContexts $contexts,
        private readonly Challenges $challenges,
        private readonly SignerAuthProps $auth,
        private readonly SenderPins $pins,
        private readonly ParticipantSigningProps $signing,
        private readonly CaptureStep $captureStep,
        private readonly IdentityCaptures $captures,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function empty(Request $request, string $screen): array
    {
        $reason = $request->hasSession() ? $request->session()->get(InPersonSessions::ENDED_KEY) : null;

        return array_replace($this->base($request), [
            'screen' => $screen,
            'ended' => $screen === 'none' && is_string($reason)
                ? ['reason' => $reason, 'message' => InPersonSessions::endMessage($reason)]
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(InPersonSession $session, Request $request): array
    {
        /** @var Envelope $envelope */
        $envelope = Envelope::withoutOrganizationScope()->whereKey($session->envelope_id)->firstOrFail();
        /** @var Organization $organization */
        $organization = Organization::query()->whereKey($session->organization_id)->firstOrFail();
        /** @var User|null $host */
        $host = $session->host_user_id === null ? null : User::query()->whereKey($session->host_user_id)->first();

        // Integração (I-2B): a mesma marca da página pública (flag `branding` + marca salva).
        $brand = app(BrandingPresenter::class)->forSigner($organization);

        $props = array_replace($this->base($request), [
            'sender' => [
                'organization_name' => $organization->name,
                'organization_initials' => $organization->initials,
                'logo_url' => $brand['logo_url'] ?? null,
                'user_name' => $host->name ?? $organization->name,
                'brand' => $brand,
            ],
            'kiosk' => [
                'id' => $session->ulid,
                'device_label' => $session->device_label,
                'host_name' => $host?->name,
                'started_at' => $session->started_at->toIso8601String(),
                'last_activity_at' => $session->last_activity_at->toIso8601String(),
                'idle_minutes' => $this->sessions->idleMinutes(),
                'expires_at' => $session->expires_at->toIso8601String(),
                'envelope' => [
                    'title' => $envelope->title,
                    'display_code' => $envelope->display_code,
                    'signing_order' => $envelope->signing_order->value,
                    'expires_at' => $envelope->expires_at?->toIso8601String(),
                ],
            ],
        ]);

        $turn = $this->turns->current($session, $request);
        $context = $turn === null ? null : $this->turns->context($turn);

        if ($turn === null || $context === null || ! $context->isActive() || $context->action() === null) {
            if ($turn !== null) {
                // O participante deixou de poder registrar aceite (outro recusou, o remetente
                // cancelou): a vez termina e a tela volta a bloquear.
                $this->turns->close($turn, InPersonTurn::CLOSE_NOT_SIGNABLE, $request);
                $session->refresh();
            }

            $props['screen'] = 'queue';
            $props['queue'] = $this->queue($session, $envelope);

            return $props;
        }

        $signing = $this->turns->signingSession($turn, $context, $request);
        $step = $signing !== null ? 'sign' : ($this->pins->pendingGate($context, $request) !== null ? 'pin' : 'identify');

        $props['screen'] = 'participant';
        $props['participant'] = [
            'id' => $context->recipient->ulid,
            'name' => $context->recipient->name,
            'first_name' => ParticipantSigningProps::firstName($context->recipient->name),
            'role_label' => SignerPresentation::roleLabel($context->recipient),
            'participant_role' => $context->recipient->role->value,
            'participant_role_label' => $context->recipient->role->label(),
            'step' => $step,
            'action' => SignerPageProps::action($context),
            'auth' => $this->auth->for($context, $request),
            'otp' => $step === 'identify' ? $this->challenges->props($context) : null,
            'privacy' => ParticipantSigningProps::privacy($context),
            'signing' => $signing === null ? null : $this->signing->build(
                $context,
                $signing,
                static fn (Document $document): string => route('in_person.kiosk.document', ['document' => $document->ulid]),
            ),
            'identity_capture' => $signing === null ? null : $this->capture($context, $signing, $request),
        ];

        return $props;
    }

    /**
     * @return array<string, mixed>
     */
    private function base(Request $request): array
    {
        return [
            'screen' => 'none',
            'sender' => [
                'organization_name' => (string) config('app.name', 'AssinaVelox'),
                'organization_initials' => 'AV',
                'logo_url' => null,
                'user_name' => '',
            ],
            'kiosk' => null,
            'queue' => [],
            'participant' => null,
            'done' => $request->hasSession() && $request->session()->get('in_person.done') === true,
            'ended' => null,
            'legal' => ParticipantSigningProps::legal(),
            'limits' => ParticipantSigningProps::limits(),
        ];
    }

    /**
     * Captura simples de foto (C-ID) quando o remetente exigiu e a flag está ligada: mesmas
     * props de `CaptureStep`, com o envio pela rota do dispositivo presencial.
     *
     * @return array<string, mixed>|null
     */
    private function capture(SignerContext $context, SigningSession $session, Request $request): ?array
    {
        $props = $this->captureStep->props($context, $session);

        if ($props === null) {
            return null;
        }

        if ($this->captures->cameraAllowedFor($context)) {
            $request->attributes->set(IdentityCaptures::CAMERA_ATTRIBUTE, true);
        }

        $props['items'] = array_map(static fn (array $item): array => array_replace($item, [
            'upload_url' => route('in_person.kiosk.capture.store', ['kind' => $item['kind']]),
        ]), $props['items']);

        return $props;
    }

    /**
     * Fila do dispositivo: quem participa, na ordem, com o estado. Sem e-mail, telefone,
     * campos ou qualquer dado do aceite.
     *
     * @return list<array<string, mixed>>
     */
    private function queue(InPersonSession $session, Envelope $envelope): array
    {
        $recipients = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('organization_id', $envelope->organization_id)
            ->whereIn('role', RecipientRole::participatingValues())
            ->orderBy('order_index')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $acceptedHere = InPersonTurn::withoutOrganizationScope()
            ->where('in_person_session_id', $session->getKey())
            ->where('status', InPersonTurn::STATUS_ACCEPTED)
            ->pluck('recipient_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $queue = [];

        foreach ($recipients as $recipient) {
            [$state, $label] = $this->queueState($recipient, $envelope);
            $action = $recipient->role->acceptanceAction();

            $queue[] = [
                'id' => $recipient->ulid,
                'name' => $recipient->name,
                'first_name' => ParticipantSigningProps::firstName($recipient->name),
                'role_label' => SignerPresentation::roleLabel($recipient),
                'participant_role' => $recipient->role->value,
                'participant_role_label' => $recipient->role->label(),
                'order' => (int) $recipient->order_index,
                'state' => $state,
                'state_label' => $label,
                'action_label' => $action?->buttonLabel(),
                'accepted_here' => in_array((int) $recipient->getKey(), $acceptedHere, true),
            ];
        }

        return $queue;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function queueState(Recipient $recipient, Envelope $envelope): array
    {
        if ($recipient->status === RecipientStatus::Signed) {
            return ['done', $recipient->role === RecipientRole::Approver ? 'Aprovou' : 'Concluído'];
        }

        if ($recipient->status === RecipientStatus::Refused) {
            return ['closed', 'Recusou'];
        }

        // Fase 3 §3.3 (F-FLOW): quem delegou sai da fila; o delegado entra com vez própria.
        if ($recipient->status === RecipientStatus::Delegated) {
            return ['closed', 'Delegou'];
        }

        if (in_array($recipient->status, [RecipientStatus::Canceled, RecipientStatus::Expired], true)
            || $envelope->status !== EnvelopeStatus::InProgress) {
            return ['closed', 'Encerrado'];
        }

        // Fase 3 §3.3 (F-FLOW): com etapas a vez vale também no paralelo (sem etapas = sequencial).
        if ($envelope->hasTurns() && $recipient->order_index > $envelope->current_order) {
            return ['waiting', 'Aguardando a vez'];
        }

        $context = $this->contexts->for($recipient);

        if ($context === null || ! $context->isActive() || $context->action() === null) {
            return ['closed', 'Indisponível'];
        }

        return ['available', $recipient->role === RecipientRole::Approver ? 'Pronto para aprovar' : 'Pronto para assinar'];
    }
}
