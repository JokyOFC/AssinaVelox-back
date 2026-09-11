<?php

namespace App\Services\PublicForms;

use App\Enums\AuditEventType;
use App\Models\Envelope;
use App\Models\PublicFormSubmission;
use App\Models\User;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Plans\Exceptions\SendingBlockedException;
use App\Services\Retention\LegalHolds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Fila de revisão (docs/fase-2/formulario-publico.md §7): o rascunho gerado depois da
 * confirmação só é enviado por ação de alguém da organização.
 *
 *  - aprovar: envia pelo {@see SendEnvelope} (mesma reserva de cota, mesmas pendências de
 *    preparo). Modelo Word/HTML precisa dos campos posicionados antes — nesse caso a
 *    aprovação recusa e a tela aponta para o editor;
 *  - recusar: exclui o rascunho (soft delete, como "Excluir rascunho") e encerra o envio;
 *  - quem enviar pelo editor ou excluir o rascunho por outro caminho tem a fila acertada na
 *    próxima leitura ({@see self::reconcile()}).
 */
final class PublicFormReview
{
    public function __construct(private readonly SendEnvelope $sender) {}

    /**
     * @throws ValidationException
     */
    public function approve(PublicFormSubmission $submission, User $user): PublicFormSubmission
    {
        $envelope = $this->pendingEnvelope($submission);

        try {
            $this->sender->handle($envelope);
        } catch (SendingException|SendingBlockedException $exception) {
            $message = $exception instanceof SendingException && $exception->errorCode === 'incomplete'
                ? 'O documento ainda tem pendências de preparo (por exemplo, campos de assinatura). Abra-o no editor, ajuste e envie por lá.'
                : $exception->getMessage();

            $submission->forceFill(['failure_reason' => $exception->errorCode])->save();

            throw ValidationException::withMessages(['submission' => $message]);
        }

        $submission->forceFill([
            'status' => SubmissionStatus::Sent,
            'failure_reason' => null,
            'reviewed_by_user_id' => $user->getKey(),
            'reviewed_at' => Carbon::now(),
        ])->save();

        PublicFormAudit::record($submission->form, AuditEventType::PublicFormSubmissionApproved, [
            'submission' => $submission->ulid,
        ], $envelope);

        return $submission;
    }

    /**
     * @throws ValidationException
     */
    public function reject(PublicFormSubmission $submission, User $user): PublicFormSubmission
    {
        $envelope = $this->pendingEnvelope($submission);

        // Recusar exclui o rascunho — o mesmo efeito de "Excluir rascunho". A preservação legal
        // vence (retencao-e-preservacao.md §5.2, regra única): registra a tentativa com o autor
        // e volta com a mensagem (LegalHoldActiveException se renderiza); o envio continua em
        // revisão.
        app(LegalHolds::class)->guardEnvelope($envelope, 'public_form_reject', $user);

        $submission->forceFill([
            'status' => SubmissionStatus::Rejected,
            'reviewed_by_user_id' => $user->getKey(),
            'reviewed_at' => Carbon::now(),
        ])->save();

        PublicFormAudit::record($submission->form, AuditEventType::PublicFormSubmissionRejected, [
            'submission' => $submission->ulid,
        ], $envelope);

        // Rascunho nunca enviado: ninguém foi convidado e nenhuma cota foi usada.
        $envelope->delete();

        return $submission;
    }

    /**
     * Acerta a situação dos envios aguardando revisão cujo rascunho foi enviado pelo editor
     * ou excluído por outro caminho.
     *
     * @param  Collection<int, PublicFormSubmission>  $submissions
     */
    public function reconcile(Collection $submissions): void
    {
        foreach ($submissions as $submission) {
            if ($submission->status !== SubmissionStatus::PendingReview) {
                continue;
            }

            $envelope = $submission->envelope;

            if ($envelope === null || $envelope->trashed()) {
                $submission->forceFill(['status' => SubmissionStatus::Rejected, 'failure_reason' => 'envelope_deleted'])->save();
            } elseif ($envelope->sent_at !== null) {
                $submission->forceFill(['status' => SubmissionStatus::Sent, 'failure_reason' => null])->save();
            } elseif (! $envelope->status->isDraftLike()) {
                $submission->forceFill(['status' => SubmissionStatus::Rejected, 'failure_reason' => 'envelope_closed'])->save();
            }
        }
    }

    /**
     * @throws ValidationException
     */
    private function pendingEnvelope(PublicFormSubmission $submission): Envelope
    {
        $this->reconcile(collect([$submission]));

        $envelope = $submission->envelope;

        if ($submission->status !== SubmissionStatus::PendingReview || ! $envelope instanceof Envelope || $envelope->trashed()) {
            throw ValidationException::withMessages(['submission' => 'Este envio não está mais aguardando revisão.']);
        }

        return $envelope;
    }
}
