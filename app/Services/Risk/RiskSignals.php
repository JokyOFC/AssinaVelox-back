<?php

namespace App\Services\Risk;

use App\Models\Envelope;
use App\Models\Organization;
use App\Models\RiskSignal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Porta de entrada ÚNICA para gravar um sinal de risco (contrato da Fase 3 usado também pelo
 * programa de afiliados, §3.10):
 *
 *     RiskSignals::record(string $ruleCode, Organization $organization, array $evidence,
 *                         ?Envelope $envelope = null, ?string $subjectKey = null): RiskSignal
 *
 * - `$ruleCode` precisa existir em {@see RiskRule} (conjunto fechado); outro valor →
 *   InvalidArgumentException.
 * - `$evidence` é minimizada por {@see RiskEvidence::minimize()} (lista fechada de chaves por
 *   regra; CPF, e-mail, telefone, conteúdo de documento e textos longos são descartados).
 * - `$subjectKey` é o sujeito BRUTO (ex.: "user:12", um prefixo de rede): é gravado apenas o
 *   HMAC. Sinais da mesma regra, organização, sujeito e envelope na mesma janela são
 *   idempotentes (devolve o sinal já gravado).
 * - Flag `antifraud` desligada: nada é gravado e o retorno é um RiskSignal NÃO persistido
 *   (`$signal->exists === false`).
 *
 * Depois de gravar, reavalia o estado de risco da organização. Uma falha nessa reavaliação é
 * registrada em log e não chega a quem chamou: um sinal nunca desfaz a operação que o gerou.
 * Estático de propósito; também pode ser chamado por instância (`app(RiskSignals::class)`).
 */
final class RiskSignals
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function record(
        string $ruleCode,
        Organization $organization,
        array $evidence,
        ?Envelope $envelope = null,
        ?string $subjectKey = null,
        ?int $score = null,
    ): RiskSignal {
        $rule = RiskRule::tryFrom($ruleCode)
            ?? throw new InvalidArgumentException('Regra de risco desconhecida: '.$ruleCode);

        $now = Carbon::now();
        $subject = $subjectKey !== null && $subjectKey !== '' ? SubjectKeys::digest($subjectKey) : null;

        $signal = new RiskSignal;
        $signal->forceFill([
            'organization_id' => $organization->getKey(),
            'envelope_id' => $envelope?->getKey(),
            'rule_code' => $rule->value,
            // `$score` só REDUZ (nunca aumenta) a pontuação da regra: sinal informativo, sem
            // efeito de estado (ex.: indício que é sobre o afiliado, não sobre a organização).
            'score' => $score === null ? $rule->score() : max(0, min($score, $rule->score())),
            'subject_key' => $subject,
            'fingerprint' => self::fingerprint($rule, $organization, $subject, $now),
            'evidence' => RiskEvidence::minimize($rule, $evidence),
            'occurred_at' => $now,
        ]);

        if (! RiskFeature::enabled()) {
            return $signal;
        }

        $existing = RiskSignal::query()->where('fingerprint', $signal->fingerprint)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $signal->save();
        } catch (UniqueConstraintViolationException) {
            return RiskSignal::query()->where('fingerprint', $signal->fingerprint)->firstOrFail();
        }

        try {
            app(RiskAssessment::class)->evaluate($organization);
        } catch (Throwable $exception) {
            Log::error('risk.assessment_failed', [
                'organization_id' => $organization->getKey(),
                'signal' => $signal->ulid,
                'exception' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, 300),
            ]);
        }

        return $signal;
    }

    /**
     * Idempotência: regra + organização + sujeito + janela. O envelope é só CONTEXTO (o que
     * cruzou o limiar) e fica fora de propósito: senão uma regra agregada (pico de envios)
     * dispararia de novo a cada envelope enviado depois do limiar, somando pontos em cascata.
     */
    private static function fingerprint(RiskRule $rule, Organization $organization, ?string $subject, Carbon $now): string
    {
        $bucket = intdiv($now->getTimestamp(), $rule->windowMinutes() * 60);

        return hash('sha256', implode('|', [
            $rule->value,
            (string) $organization->getKey(),
            $subject ?? '-',
            (string) $bucket,
        ]));
    }
}
