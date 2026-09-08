<?php

namespace App\Http\Resources;

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Recipient;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Recipient da página de detalhe do envelope (ROUTES §2.7 → types/models.ts `Recipient`).
 * Espera `envelope` e `acceptance` carregados. `viewed_at`/`last_resent_at` podem ser
 * injetados pelo controller (derivados da trilha de auditoria) via withTimeline().
 *
 * @mixin Recipient
 */
class RecipientResource extends JsonResource
{
    public static $wrap = null;

    protected int $colorIndex = 0;

    protected ?CarbonInterface $viewedAt = null;

    protected ?CarbonInterface $lastResentAt = null;

    public function withColorIndex(int $index): static
    {
        $this->colorIndex = $index % 4;

        return $this;
    }

    public function withTimeline(?CarbonInterface $viewedAt, ?CarbonInterface $lastResentAt): static
    {
        $this->viewedAt = $viewedAt;
        $this->lastResentAt = $lastResentAt;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $envelope = $this->envelope;
        $acceptance = $this->relationLoaded('acceptance') ? $this->acceptance : null;
        $inProgress = $envelope?->status === EnvelopeStatus::InProgress;
        $pendingSignature = $this->status->isPendingSignature();
        $throttle = (int) config('assinavelox.resend.throttle_minutes', 10);
        $maxResends = (int) ($envelope?->organization?->setting('max_resends', 5) ?? 5);

        $canResend = $inProgress
            && $pendingSignature
            && $this->status !== RecipientStatus::Pending
            && ($this->last_notified_at === null || $this->last_notified_at->lte(now()->subMinutes($throttle)))
            && $this->notification_count < ($maxResends + 1);

        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->roleLabel(),
            'order' => (int) $this->order_index,
            'initials' => $this->initials,
            'color_index' => $this->colorIndex,
            'status' => $this->status->value,
            'status_label' => $this->statusLabel(),
            'channel' => 'email',
            'auth_methods' => [$this->auth_method->value],
            'sent_at' => $this->status !== RecipientStatus::Pending ? $this->last_notified_at?->toIso8601String() : null,
            'viewed_at' => $this->viewedAt?->toIso8601String(),
            'signed_at' => $this->signed_at?->toIso8601String(),
            'refused_at' => $this->refused_at?->toIso8601String(),
            'refusal_reason' => $this->refusal_reason,
            'last_resent_at' => $this->lastResentAt?->toIso8601String()
                ?? ($this->notification_count > 1 ? $this->last_notified_at?->toIso8601String() : null),
            'can_resend' => $canResend,
            'can_edit' => $pendingSignature && ($inProgress || ($envelope?->status->isDraftLike() ?? false)),
            'evidence' => $acceptance ? [
                'ip' => (string) ($acceptance->ip_address ?? '—'),
                'user_agent_label' => self::userAgentLabel($acceptance->user_agent),
                'signature_kind' => $acceptance->signature_kind?->value,
            ] : null,
        ];
    }

    protected function roleLabel(): ?string
    {
        // Fase 1: recipients.role = signer; o "papel" livre do wizard (Locatário, Fiador…) é Wave B.
        return null;
    }

    protected function statusLabel(): string
    {
        $envelope = $this->envelope;

        if ($this->status === RecipientStatus::Pending && $envelope?->status === EnvelopeStatus::InProgress) {
            return 'Aguarda a vez';
        }

        return $this->status->label();
    }

    /**
     * Rótulo curto do dispositivo a partir do user-agent (sem dependências externas).
     */
    public static function userAgentLabel(?string $userAgent): string
    {
        if ($userAgent === null || $userAgent === '') {
            return 'Dispositivo desconhecido';
        }

        $device = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Dispositivo',
        };

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        return $browser ? "{$device} ({$browser})" : $device;
    }
}
