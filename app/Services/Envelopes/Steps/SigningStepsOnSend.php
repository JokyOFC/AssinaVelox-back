<?php

namespace App\Services\Envelopes\Steps;

use App\Enums\AuditEventType;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningStep;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Envelopes\RecipientSync;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use Illuminate\Support\Carbon;

/**
 * O envio de um envelope com etapas (chamado por SendEnvelope, sob o lock e na transação do envio).
 *
 * 1. Recalcula a vez de cada participante a partir da etapa ({@see StepTurns}).
 * 2. Revalida a definição gravada contra o envelope como está AGORA — participantes e campos
 *    podem ter mudado depois que as etapas foram salvas. Inválida: o envio para, com a mensagem.
 * 3. Abre a etapa 1 (a primeira nunca tem condição) e deixa as demais `pending`.
 *
 * Sem etapas não faz nada: o envio é exatamente o de antes.
 */
final class SigningStepsOnSend
{
    public function __construct(private readonly StepDefinitionValidator $validator) {}

    /**
     * @throws SendingException
     */
    public function handle(Envelope $locked): void
    {
        if (! $locked->usesSigningSteps()) {
            return;
        }

        // Rascunho cujas etapas foram gravadas com a flag ligada e que é enviado depois de a flag
        // ser desligada: o envio volta a ser exatamente o de antes (T8) — as etapas são desfeitas
        // como no "desligar etapas" do wizard, com registro na trilha. Envelope JÁ enviado não
        // passa por aqui e continua pelas etapas.
        if (! FlowFeatures::conditionalSteps($locked->organization)) {
            $this->discardForSend($locked);

            return;
        }

        StepTurns::apply($locked);

        $issues = $this->validator->storedIssues($locked);

        if ($issues !== []) {
            throw new SendingException('invalid_steps', $issues[0], ['issues' => $issues]);
        }

        $now = Carbon::now();

        SigningStep::withoutOrganizationScope()
            ->where('envelope_id', $locked->getKey())
            ->update(['status' => SigningStep::STATUS_PENDING, 'evaluated_at' => null, 'evaluation' => null, 'updated_at' => $now]);

        $first = (int) SigningStep::withoutOrganizationScope()
            ->where('envelope_id', $locked->getKey())
            ->min('step_index');

        SigningStep::withoutOrganizationScope()
            ->where('envelope_id', $locked->getKey())
            ->where('step_index', $first)
            ->update([
                'status' => SigningStep::STATUS_ACTIVE,
                'evaluated_at' => $now,
                'evaluation' => json_encode(['result' => true, 'match' => null, 'rules' => []], JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);
    }

    private function discardForSend(Envelope $locked): void
    {
        SigningStep::withoutOrganizationScope()->where('envelope_id', $locked->getKey())->delete();
        Recipient::withoutOrganizationScope()
            ->where('envelope_id', $locked->getKey())
            ->update(['signing_step_index' => null]);

        $locked->forceFill(['uses_signing_steps' => false])->save();

        RecipientSync::reindex($locked);
        $locked->forceFill(['current_order' => 1])->save();

        EnvelopeAudit::record($locked, AuditEventType::SigningStepsUpdated, [
            'enabled' => false,
            'reason' => 'feature_disabled_before_send',
        ]);
    }

    /**
     * Reversão de um envio cujo despacho falhou: as etapas voltam a "não alcançadas".
     */
    public function revert(Envelope $locked): void
    {
        if (! $locked->usesSigningSteps()) {
            return;
        }

        SigningStep::withoutOrganizationScope()
            ->where('envelope_id', $locked->getKey())
            ->update(['status' => SigningStep::STATUS_PENDING, 'evaluated_at' => null, 'evaluation' => null, 'updated_at' => Carbon::now()]);
    }
}
