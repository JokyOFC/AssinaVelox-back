<?php

namespace App\Services\Affiliates;

use App\Models\Affiliate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida do afiliado (Fase 3 §3.10): candidatura pelo portal → aprovação pela
 * operadora (painel interno) com código único e taxa em pontos-base → suspensão/reativação.
 * Toda ação da operadora vai para `affiliate_events` com quem, quando e motivo.
 */
final class AffiliateProgram
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 8;

    public function __construct(private readonly AffiliateSettings $settings) {}

    /**
     * @param  array<string, mixed>  $payoutInput
     */
    public function apply(User $user, array $payoutInput, ?string $ip): Affiliate
    {
        $details = PayoutDetails::validate($payoutInput);

        return DB::transaction(function () use ($user, $details, $ip): Affiliate {
            $existing = Affiliate::query()->where('user_id', $user->getKey())->lockForUpdate()->first();

            if ($existing !== null && $existing->status !== Affiliate::STATUS_REJECTED) {
                throw ValidationException::withMessages(['application' => 'Você já tem uma candidatura ao programa de afiliados.']);
            }

            $now = Carbon::now();
            $affiliate = $existing ?? new Affiliate(['user_id' => $user->getKey()]);

            $affiliate->fill([
                'status' => Affiliate::STATUS_PENDING,
                'commission_rate_bp' => $this->settings->defaultRateBp(),
                'payout_details' => $details,
                'terms_version' => $this->settings->termsVersion(),
                'terms_accepted_at' => $now,
                'application_ip_hash' => IpFingerprint::of($ip),
                'last_ip_hash' => IpFingerprint::of($ip),
                'last_ip_at' => $ip !== null ? $now : null,
                'rejected_at' => null,
                'status_reason' => null,
            ])->save();

            AffiliateTrail::record(AffiliateTrail::APPLIED, $user, $affiliate, payload: [
                'terms_version' => $affiliate->terms_version,
                'reapplication' => $existing !== null,
            ]);

            return $affiliate;
        });
    }

    public function approve(Affiliate $affiliate, User $actor, ?int $rateBp = null): void
    {
        DB::transaction(function () use ($affiliate, $actor, $rateBp): void {
            $locked = Affiliate::query()->whereKey($affiliate->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== Affiliate::STATUS_PENDING) {
                throw ValidationException::withMessages(['affiliate' => 'Só uma candidatura pendente pode ser aprovada.']);
            }

            $rate = $this->settings->clampRate($rateBp ?? $locked->commission_rate_bp);

            $locked->forceFill([
                'status' => Affiliate::STATUS_APPROVED,
                'code' => $locked->code ?? $this->uniqueCode(),
                'commission_rate_bp' => $rate,
                'approved_at' => Carbon::now(),
                'approved_by_user_id' => $actor->getKey(),
                'status_reason' => null,
            ])->save();

            AffiliateTrail::record(AffiliateTrail::APPROVED, $actor, $locked, payload: [
                'code' => $locked->code,
                'commission_rate_bp' => $rate,
            ]);

            $affiliate->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function reject(Affiliate $affiliate, User $actor, string $reason): void
    {
        $this->transition($affiliate, $actor, [Affiliate::STATUS_PENDING], Affiliate::STATUS_REJECTED, AffiliateTrail::REJECTED, $reason, [
            'rejected_at' => Carbon::now(),
        ]);
    }

    public function suspend(Affiliate $affiliate, User $actor, string $reason): void
    {
        $this->transition($affiliate, $actor, [Affiliate::STATUS_APPROVED], Affiliate::STATUS_SUSPENDED, AffiliateTrail::SUSPENDED, $reason, [
            'suspended_at' => Carbon::now(),
        ]);
    }

    public function reactivate(Affiliate $affiliate, User $actor, string $reason): void
    {
        $this->transition($affiliate, $actor, [Affiliate::STATUS_SUSPENDED], Affiliate::STATUS_APPROVED, AffiliateTrail::REACTIVATED, $reason, [
            'suspended_at' => null,
        ]);
    }

    /**
     * Nova taxa vale para os pagamentos APROVADOS DAQUI EM DIANTE; a comissão já calculada
     * guarda a taxa do momento (`commissions.rate_bp`). Trilha: antes → depois, motivo, quem.
     */
    public function changeRate(Affiliate $affiliate, User $actor, int $rateBp, string $reason): void
    {
        if ($rateBp < 0 || $rateBp > $this->settings->maxRateBp()) {
            throw ValidationException::withMessages(['commission_rate_bp' => 'A taxa deve ficar entre 0 e '.$this->settings->maxRateBp().' pontos-base.']);
        }

        DB::transaction(function () use ($affiliate, $actor, $rateBp, $reason): void {
            $locked = Affiliate::query()->whereKey($affiliate->getKey())->lockForUpdate()->firstOrFail();
            $previous = $locked->commission_rate_bp;

            if ($previous === $rateBp) {
                throw ValidationException::withMessages(['commission_rate_bp' => 'A taxa informada é igual à atual.']);
            }

            $locked->forceFill(['commission_rate_bp' => $rateBp])->save();

            AffiliateTrail::record(AffiliateTrail::RATE_CHANGED, $actor, $locked, payload: [
                'from_bp' => $previous,
                'to_bp' => $rateBp,
                'reason' => $reason,
            ]);

            $affiliate->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updatePayoutDetails(Affiliate $affiliate, User $actor, array $input): void
    {
        $details = PayoutDetails::validate($input);

        $affiliate->forceFill(['payout_details' => $details])->save();

        // Só o fato e o tipo da chave: nunca o valor.
        AffiliateTrail::record(AffiliateTrail::PAYOUT_DETAILS_UPDATED, $actor, $affiliate, payload: [
            'pix_key_type' => $details['pix_key_type'],
        ]);
    }

    /**
     * Guarda (como HMAC) o IP de uso do portal, para a regra "mesmo IP dentro da janela".
     */
    public function touchIp(Affiliate $affiliate, ?string $ip): void
    {
        $hash = IpFingerprint::of($ip);

        if ($hash === null) {
            return;
        }

        if ($affiliate->last_ip_hash === $hash && $affiliate->last_ip_at !== null && $affiliate->last_ip_at->gt(Carbon::now()->subHour())) {
            return;
        }

        $affiliate->forceFill(['last_ip_hash' => $hash, 'last_ip_at' => Carbon::now()])->save();
    }

    public function uniqueCode(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (Affiliate::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    private function transition(Affiliate $affiliate, User $actor, array $from, string $to, string $action, string $reason, array $extra): void
    {
        DB::transaction(function () use ($affiliate, $actor, $from, $to, $action, $reason, $extra): void {
            $locked = Affiliate::query()->whereKey($affiliate->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['affiliate' => 'Esta ação não se aplica ao estado atual do afiliado.']);
            }

            $previous = $locked->status;

            $locked->forceFill([...$extra, 'status' => $to, 'status_reason' => $reason])->save();

            AffiliateTrail::record($action, $actor, $locked, payload: [
                'from' => $previous,
                'to' => $to,
                'reason' => $reason,
            ]);

            $affiliate->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
