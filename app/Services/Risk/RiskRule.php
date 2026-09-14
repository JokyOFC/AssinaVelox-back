<?php

namespace App\Services\Risk;

/**
 * Catálogo FECHADO de regras do antifraude (roadmap §3.7). Nenhuma regra vem do usuário nem
 * do banco: o código define quais existem e que evidência cada uma pode guardar; a
 * configuração (`assinavelox.risk.rules.*`) só ajusta janela, limiar e pontuação.
 *
 * `maxStatus()` é o estado mais alto que a regra pode provocar SOZINHA: regras em que a
 * organização costuma ser a vítima (força bruta contra os links dela) ou em que o dano não é
 * o envio (autoindicação de afiliado) ficam limitadas a `watch` — nunca suspendem o envio.
 *
 * Valores estáveis — gravados em `risk_signals.rule_code`; nunca renomeie um caso.
 */
enum RiskRule: string
{
    case NewOrganizationSendSpike = 'new_org_send_spike';
    case DeliveryFailureRate = 'delivery_failure_rate';
    case CodeBruteForce = 'code_brute_force';
    case ExternalRecipientsBurst = 'external_recipients_burst';
    case PaymentChargeback = 'payment_chargeback';
    case SerialSignup = 'serial_signup';
    case AffiliateSelfReferral = 'affiliate_self_referral';

    public function label(): string
    {
        return match ($this) {
            self::NewOrganizationSendSpike => 'Pico de envios em conta nova',
            self::DeliveryFailureRate => 'Taxa alta de e-mails devolvidos ou não entregues',
            self::CodeBruteForce => 'Tentativas repetidas de código ou PIN',
            self::ExternalRecipientsBurst => 'Muitos destinatários externos em pouco tempo',
            self::PaymentChargeback => 'Pagamento contestado',
            self::SerialSignup => 'Cadastros em série da mesma origem',
            self::AffiliateSelfReferral => 'Possível autoindicação de afiliado',
        };
    }

    /**
     * Explicação mostrada à própria organização (LGPD art. 20 §1º): diz o critério, sem expor
     * limiares que permitiriam contornar a regra (segredo comercial ressalvado no mesmo §1º).
     */
    public function publicExplanation(): string
    {
        return match ($this) {
            self::NewOrganizationSendSpike => 'Volume de envios muito acima do esperado para uma conta criada há poucos dias.',
            self::DeliveryFailureRate => 'Proporção elevada de convites por e-mail devolvidos ou não entregues, comum em listas de endereços não confirmados.',
            self::CodeBruteForce => 'Muitas tentativas erradas de código de verificação ou PIN em links de assinatura desta conta. Pode indicar um ataque contra os seus links, e não uma ação sua.',
            self::ExternalRecipientsBurst => 'Grande número de destinatários diferentes, fora dos domínios da equipe, em um intervalo curto.',
            self::PaymentChargeback => 'Um pagamento desta conta foi contestado junto ao meio de pagamento.',
            self::SerialSignup => 'Vários cadastros de contas a partir da mesma origem de rede ou do mesmo dispositivo em pouco tempo.',
            self::AffiliateSelfReferral => 'A indicação que trouxe esta conta parece ter partido da própria conta ou de quem a administra.',
        };
    }

    public function maxStatus(): RiskStatus
    {
        return match ($this) {
            self::CodeBruteForce, self::AffiliateSelfReferral => RiskStatus::Watch,
            default => RiskStatus::Restricted,
        };
    }

    /**
     * Chaves de evidência aceitas para a regra (além de {@see RiskEvidence::COMMON_KEYS}).
     * Qualquer outra chave é descartada na gravação.
     *
     * @return list<string>
     */
    public function evidenceKeys(): array
    {
        return match ($this) {
            self::NewOrganizationSendSpike => ['sent_in_window', 'organization_age_days'],
            self::DeliveryFailureRate => ['attempts_in_window', 'failed_in_window', 'failure_rate'],
            self::CodeBruteForce => ['scope', 'failures_in_window', 'recipient', 'ip_prefix'],
            self::ExternalRecipientsBurst => ['distinct_external_recipients', 'envelopes_in_window'],
            self::PaymentChargeback => ['chargebacks_in_window', 'payment'],
            self::SerialSignup => ['scope', 'signups_in_window', 'ip_prefix'],
            self::AffiliateSelfReferral => ['affiliate', 'referral', 'match', 'same_user', 'same_ip', 'same_device', 'same_payment_method', 'same_email_domain'],
        };
    }

    public function score(): int
    {
        return max(0, min(1000, (int) $this->config('score', $this->defaultScore())));
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return config('assinavelox.risk.rules.'.$this->value.'.'.$key, $default);
    }

    /** Janela usada para contar e para deduplicar sinais do mesmo sujeito. */
    public function windowMinutes(): int
    {
        return max(1, (int) $this->config('window_minutes', 1440));
    }

    private function defaultScore(): int
    {
        return match ($this) {
            self::NewOrganizationSendSpike => 40,
            self::DeliveryFailureRate => 30,
            self::CodeBruteForce => 20,
            self::ExternalRecipientsBurst => 50,
            self::PaymentChargeback => 40,
            self::SerialSignup => 30,
            self::AffiliateSelfReferral => 50,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $rule): array => ['value' => $rule->value, 'label' => $rule->label()], self::cases());
    }
}
