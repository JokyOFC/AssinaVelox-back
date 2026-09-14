<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\RiskReview;
use App\Models\User;
use App\Services\Risk\RiskAppeals;
use App\Services\Risk\RiskException;
use App\Services\Risk\RiskFeature;
use App\Services\Risk\RiskReviewStatus;
use App\Services\Risk\RiskRule;
use App\Services\Risk\RiskStatus;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedido de revisão pela ORGANIZAÇÃO (LGPD art. 20) — lado do cliente, não do painel interno.
 * Fica no namespace Admin só porque é a área de arquivos do antifraude (P3-RISK); as rotas
 * passam por `auth`, `verified`, `org` e `org.2fa`, e o envio do pedido exige owner/admin.
 *
 * A página explica o estado da conta e os CRITÉRIOS que motivaram a observação ou a suspensão
 * (regra e explicação pública), sem limiares nem pontuações (segredo comercial ressalvado no
 * art. 20 §1º). Flag `antifraud` desligada: 404.
 */
class RiskAppealController extends Controller
{
    public function show(Request $request): Response
    {
        abort_unless(RiskFeature::enabled(), 404);

        $organization = $this->organization();
        $status = RiskStatus::fromStored($organization->getAttribute('risk_status'));

        /** @var RiskReview|null $review */
        $review = RiskReview::query()
            ->where('organization_id', $organization->getKey())
            ->latest('id')
            ->first();

        $rules = [];

        if ($review !== null && $status !== RiskStatus::Normal) {
            foreach ($review->signalsQuery()->distinct()->pluck('rule_code') as $code) {
                $rule = RiskRule::tryFrom((string) $code);

                if ($rule !== null) {
                    $rules[] = ['rule' => $rule->value, 'label' => $rule->label(), 'explanation' => $rule->publicExplanation()];
                }
            }
        }

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('admin/risk-appeal/show', [
            'status' => $status->value,
            'status_label' => $status->label(),
            'changed_at' => $this->iso($organization->getAttribute('risk_status_changed_at')),
            'rules' => $rules,
            'review' => $review !== null ? [
                'status' => $review->status->value,
                'status_label' => $review->status->label(),
                'appeal_requested_at' => $review->appeal_requested_at?->toIso8601String(),
                'decided_at' => $review->decided_at?->toIso8601String(),
                'decision_label' => $review->decision?->label(),
                'open' => $review->status === RiskReviewStatus::Open,
            ] : null,
            'can_request' => $status !== RiskStatus::Normal
                && ($review === null || ! $review->isOpen() || $review->appeal_requested_at === null)
                && self::canRequest($user, $organization),
            'max_message' => (int) config('assinavelox.risk.appeal.max_message', 2000),
            'response_days' => (int) config('assinavelox.risk.appeal.response_days', 5),
            'support_email' => (string) config('assinavelox.support_email'),
        ]);
    }

    public function store(Request $request, RiskAppeals $appeals): RedirectResponse
    {
        abort_unless(RiskFeature::enabled(), 404);

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:20', 'max:'.max(20, (int) config('assinavelox.risk.appeal.max_message', 2000))],
        ], [], ['message' => 'explicação']);

        /** @var User $user */
        $user = $request->user();
        $organization = $this->organization();

        abort_unless(self::canRequest($user, $organization), 403);

        try {
            $appeals->request($organization, $user, (string) $validated['message']);
        } catch (RiskException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Pedido de revisão registrado. A equipe AssinaVelox vai analisar e responder por e-mail.');
    }

    /**
     * Quem pode pedir: proprietário ou administrador ativo. Decide se o formulário aparece e,
     * no POST, é a própria autorização (403 para operador).
     */
    private static function canRequest(User $user, Organization $organization): bool
    {
        return Membership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
            ->exists();
    }

    private function organization(): Organization
    {
        $organization = CurrentOrganization::instance()->get();

        abort_if($organization === null, 404);

        return $organization;
    }

    private function iso(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? Carbon::parse($value)->toIso8601String() : null;
    }
}
