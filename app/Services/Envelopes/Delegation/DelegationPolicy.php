<?php

namespace App\Services\Envelopes\Delegation;

use App\Enums\AuditEventType;
use App\Enums\AuthMethod;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Envelopes\PreparationGuard;
use App\Services\Identity\Models\IdentityCaptureRequirement;
use App\Services\Identity\Models\IdentityVideoRequirement;
use App\Services\Signing\Channels\SenderPins;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Política de delegação do REMETENTE, por envelope (docs/fase-3/etapas-e-delegacao.md §3.1).
 *
 * Gravada em `envelopes.settings` no preparo e congelada no envio:
 *
 * - `allow_delegation` (padrão **false**): o participante pode indicar outra pessoa;
 * - `delegation_requires_confirmation` (padrão **true**): a delegação só vale depois que o
 *   remetente confirma — e vale sempre, qualquer que seja esta chave, para participante com
 *   autenticação reforçada ou captura exigida (SMS, WhatsApp, PIN, fotos ou vídeo curto
 *   exigidos), porque o delegado entra com o código por e-mail e a exigência não se transfere
 *   para outra pessoa sem que o remetente saiba;
 * - `delegation_personal_recipients`: participantes cuja participação é PESSOAL (não pode ser
 *   delegada). É o "papel pessoal" do roadmap, marcado por participante.
 *
 * Limites da instalação (`assinavelox.delegation.*`): profundidade da cadeia (padrão 1 — quem
 * recebeu por delegação não delega de novo), pedidos por participante e por organização em 24 h.
 */
final class DelegationPolicy
{
    public const SETTING_ALLOW = 'allow_delegation';

    public const SETTING_CONFIRM = 'delegation_requires_confirmation';

    public const SETTING_PERSONAL = 'delegation_personal_recipients';

    public const REASON_DELEGATED = 'delegated';

    public static function allows(Envelope $envelope): bool
    {
        return $envelope->setting(self::SETTING_ALLOW, false) === true;
    }

    public static function requiresConfirmation(Envelope $envelope): bool
    {
        return $envelope->setting(self::SETTING_CONFIRM, true) !== false;
    }

    /**
     * @return list<string>
     */
    public static function personal(Envelope $envelope): array
    {
        $value = $envelope->setting(self::SETTING_PERSONAL, []);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    public static function isPersonal(Envelope $envelope, Recipient $recipient): bool
    {
        return in_array($recipient->ulid, self::personal($envelope), true);
    }

    /**
     * Quantas delegações levaram até esta pessoa (0 = participante original).
     */
    public static function chainDepth(Recipient $recipient): int
    {
        $depth = 0;
        $current = $recipient;

        while ($depth < 10 && $current->getAttribute('delegated_from_recipient_id') !== null) {
            $depth++;
            $current = Recipient::withoutOrganizationScope()
                ->whereKey((int) $current->getAttribute('delegated_from_recipient_id'))
                ->first();

            if ($current === null) {
                break;
            }
        }

        return $depth;
    }

    /**
     * O participante tem autenticação que o delegado (código por e-mail) não herdaria?
     */
    public static function hasStrongerAuthentication(Recipient $recipient): bool
    {
        return $recipient->auth_method !== AuthMethod::EmailOtp
            || app(SenderPins::class)->requiredFor($recipient)
            || IdentityCaptureRequirement::query()->withoutGlobalScopes()->where('recipient_id', $recipient->getKey())->exists()
            // Integração I-3F: o vídeo curto exigido (F-VIDEO) é captura como as fotos — a
            // exigência passa ao delegado, então quem enviou precisa saber de quem será o vídeo.
            || IdentityVideoRequirement::query()->withoutGlobalScopes()->where('recipient_id', $recipient->getKey())->exists();
    }

    /**
     * Chave da CAIXA DE CORREIO para as proibições "para si mesmo" e "quem já participa": minúsculas,
     * sem o sufixo `+…` da parte local (maria+x@… é a caixa da maria@…) e, no Gmail, sem os pontos
     * da parte local (`googlemail.com` = `gmail.com`). Só compara; o e-mail gravado é o digitado.
     */
    public static function mailboxKey(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');

        if ($at === false) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $plus = strpos($local, '+');

        if ($plus !== false && $plus > 0) {
            $local = substr($local, 0, $plus);
        }

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }

    /**
     * Alguém do envelope (qualquer papel, inclusive quem já delegou) usa esta caixa de correio?
     */
    public static function mailboxInEnvelope(int $envelopeId, string $email): bool
    {
        $key = self::mailboxKey($email);

        return Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelopeId)
            ->pluck('email')
            ->contains(fn (mixed $existing): bool => is_string($existing) && self::mailboxKey($existing) === $key);
    }

    /**
     * Tentativas RECUSADAS ("para si mesmo", "já participa") contam num limite próprio por
     * participante: sem ele, a resposta "já participa" permitiria testar endereços à vontade.
     */
    public static function maxRefusedAttemptsPerRecipient(): int
    {
        return max(2, (int) config('assinavelox.delegation.max_refused_attempts_per_recipient', 6));
    }

    public static function maxChainDepth(): int
    {
        return max(1, (int) config('assinavelox.delegation.max_chain_depth', 1));
    }

    public static function maxRequestsPerRecipient(): int
    {
        return max(1, (int) config('assinavelox.delegation.max_requests_per_recipient', 3));
    }

    public static function maxPerOrganizationPerDay(): int
    {
        return max(1, (int) config('assinavelox.delegation.max_per_organization_per_day', 50));
    }

    public static function reasonMin(): int
    {
        return max(1, (int) config('assinavelox.delegation.reason_min', 10));
    }

    public static function reasonMax(): int
    {
        return max(self::reasonMin(), (int) config('assinavelox.delegation.reason_max', 500));
    }

    /**
     * Grava a política no preparo (PUT `envelopes.delegation.update`).
     *
     * @param  array{allow: bool, requires_confirmation: bool, personal: list<string>}  $data
     *
     * @throws ValidationException
     */
    public static function update(Envelope $envelope, array $data): void
    {
        DB::transaction(function () use ($envelope, $data): void {
            $locked = PreparationGuard::lockForPreparation($envelope, 'delegation');

            $participants = Recipient::withoutOrganizationScope()
                ->where('envelope_id', $locked->getKey())
                ->get()
                ->filter(fn (Recipient $recipient): bool => $recipient->participates())
                ->pluck('ulid')
                ->all();

            $personal = array_values(array_unique($data['personal']));

            foreach ($personal as $ulid) {
                if (! in_array($ulid, $participants, true)) {
                    throw ValidationException::withMessages([
                        'personal' => 'Um dos participantes marcados como pessoais não pertence a este documento.',
                    ]);
                }
            }

            $settings = $locked->settings ?? [];
            $settings[self::SETTING_ALLOW] = $data['allow'];
            $settings[self::SETTING_CONFIRM] = $data['requires_confirmation'];
            $settings[self::SETTING_PERSONAL] = $personal;

            $locked->forceFill(['settings' => $settings])->save();

            EnvelopeAudit::record($locked, AuditEventType::DelegationPolicyUpdated, [
                'allow' => $data['allow'],
                'requires_confirmation' => $data['requires_confirmation'],
                'personal_recipients' => $personal,
            ]);
        });

        $envelope->refresh();
    }
}
