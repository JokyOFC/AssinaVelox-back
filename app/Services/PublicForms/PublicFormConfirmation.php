<?php

namespace App\Services\PublicForms;

use App\Enums\AuditEventType;
use App\Models\Envelope;
use App\Models\PublicForm;
use App\Models\PublicFormSubmission;
use App\Models\User;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Plans\Exceptions\SendingBlockedException;
use App\Services\Plans\PlanLedger;
use App\Services\Templates\CreateEnvelopeFromTemplate;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Confirmação do e-mail → geração do envelope (docs/fase-2/formulario-publico.md §5).
 *
 * É AQUI, e só aqui, que o envelope nasce:
 *
 *  1. o envio é REIVINDICADO por um UPDATE condicional (`pending_confirmation` e link dentro
 *     da validade → `processing`). Dois cliques, duas abas ou um link reutilizado: só um
 *     vence; os demais recebem "link já usado";
 *  2. formulário ainda disponível, limite do período e cota do plano são conferidos de novo
 *     (se algo impede, a reivindicação é desfeita e o link continua valendo até vencer);
 *  3. o envelope é gerado pelo serviço de modelos da onda A ({@see CreateEnvelopeFromTemplate}),
 *     sem nenhuma alteração nele: mesma validação tipada, mesma substituição restrita, mesmos
 *     serviços do wizard. O envelope é criado em nome do responsável pelo formulário;
 *  4. o payload cifrado é apagado (os valores já estão no documento);
 *  5. envio automático: {@see SendEnvelope} reserva e confirma a cota como qualquer envio.
 *     Se o envio não puder sair (cota, pendência), o rascunho vai para a fila de revisão com
 *     o motivo — nunca some. Fila de revisão: o rascunho espera uma pessoa da organização.
 */
final class PublicFormConfirmation
{
    public const OUTCOME_SENT = 'sent';

    public const OUTCOME_REVIEW = 'review';

    public const OUTCOME_FAILED = 'failed';

    public function __construct(
        private readonly PublicFormAvailability $availability,
        private readonly PlanLedger $ledger,
        private readonly CreateEnvelopeFromTemplate $creator,
        private readonly SendEnvelope $sender,
    ) {}

    public function find(PublicForm $form, string $token): ?PublicFormSubmission
    {
        if (preg_match('/^[A-Za-z0-9]{48}$/', $token) !== 1) {
            return null;
        }

        return PublicFormSubmission::withoutOrganizationScope()
            ->where('public_form_id', $form->getKey())
            ->where('confirmation_digest', PublicFormIntake::confirmationDigest($token))
            ->first();
    }

    /**
     * Estado do link para a tela (sem efeito colateral nenhum — clientes de e-mail que
     * pré-carregam links não confirmam nada; a confirmação é um POST).
     */
    public function state(?PublicFormSubmission $submission): string
    {
        if ($submission === null) {
            return PublicFormRefusal::INVALID;
        }

        if ($submission->status !== SubmissionStatus::PendingConfirmation) {
            return PublicFormRefusal::USED;
        }

        return $submission->confirmationExpired() ? PublicFormRefusal::EXPIRED : 'confirm';
    }

    /**
     * @return array{outcome: string, submission: PublicFormSubmission}
     *
     * @throws PublicFormRefusal
     */
    public function confirm(PublicForm $form, string $token): array
    {
        $submission = $this->find($form, $token);

        match ($this->state($submission)) {
            PublicFormRefusal::INVALID => throw PublicFormRefusal::invalid(),
            PublicFormRefusal::USED => throw PublicFormRefusal::used(),
            PublicFormRefusal::EXPIRED => throw PublicFormRefusal::expired(),
            default => null,
        };

        /** @var PublicFormSubmission $submission */
        $state = $this->availability->state($form);

        if ($state !== null) {
            throw PublicFormRefusal::unavailable($state);
        }

        $claimed = PublicFormSubmission::withoutOrganizationScope()
            ->whereKey($submission->getKey())
            ->where('status', SubmissionStatus::PendingConfirmation->value)
            ->where('confirmation_expires_at', '>', Carbon::now())
            ->update(['status' => SubmissionStatus::Processing->value, 'updated_at' => Carbon::now()]);

        if ($claimed === 0) {
            throw PublicFormRefusal::used();
        }

        $submission->refresh();

        // Limite do período e cota: conferidos DEPOIS de reivindicar (o envio concorrente que
        // venceu já conta). Se algo impede, o link volta a valer até vencer.
        if (PublicFormIntake::confirmedInPeriod($form) >= $form->submissionsLimit()) {
            $this->release($submission);

            throw PublicFormRefusal::limit();
        }

        $quota = PublicFormIntake::quotaMessage($this->ledger, $form);

        if ($quota !== null) {
            $this->release($submission);

            throw PublicFormRefusal::quota($quota);
        }

        $envelope = $this->generate($form, $submission);

        if ($envelope === null) {
            return ['outcome' => self::OUTCOME_FAILED, 'submission' => $submission->refresh()];
        }

        if ($form->destination !== PublicFormDestination::AutoSend) {
            return ['outcome' => self::OUTCOME_REVIEW, 'submission' => $submission->refresh()];
        }

        try {
            $this->sender->handle($envelope);
        } catch (SendingException|SendingBlockedException $exception) {
            $submission->forceFill([
                'status' => SubmissionStatus::PendingReview,
                'failure_reason' => $exception->errorCode,
            ])->save();

            return ['outcome' => self::OUTCOME_REVIEW, 'submission' => $submission];
        }

        $submission->forceFill(['status' => SubmissionStatus::Sent])->save();

        return ['outcome' => self::OUTCOME_SENT, 'submission' => $submission];
    }

    /**
     * Gera o envelope em rascunho. Devolve null (envio `failed`, motivo registrado) quando o
     * serviço de modelos recusa os dados — nunca deixa o envio preso em `processing`.
     */
    private function generate(PublicForm $form, PublicFormSubmission $submission): ?Envelope
    {
        $payload = $submission->payload ?? [];
        $name = (string) ($payload['name'] ?? '');
        $email = (string) ($payload['email'] ?? '');
        $values = is_array($payload['values'] ?? null) ? $payload['values'] : [];

        // Só as variáveis públicas vêm do payload; as demais, dos valores fixos.
        $input = [
            'title' => Str::limit($form->envelopeTitle().' — '.$name, 160, ''),
            'values' => $form->fixedValues() + array_intersect_key($values, array_flip($form->publicVariables())),
            'participants' => [(string) $form->fillerRole() => ['name' => $name, 'email' => $email]] + $form->fixedParticipants(),
        ];

        /** @var User|null $responsible */
        $responsible = $form->responsibleUser;

        try {
            if ($responsible === null) {
                throw ValidationException::withMessages(['form' => 'Formulário sem responsável.']);
            }

            $envelope = CurrentOrganization::instance()->runAs(
                $form->organization,
                fn (): Envelope => $this->creator->handle($form->template, $responsible, $input),
            );
        } catch (Throwable $exception) {
            $submission->forceFill([
                'status' => SubmissionStatus::Failed,
                'failure_reason' => $exception instanceof ValidationException ? 'generation_rejected' : 'generation_failed',
                'payload' => null,
            ])->save();

            // Sem PII: nem e-mail, nem valores, nem a mensagem de validação (que pode citá-los).
            Log::warning('Formulário público: não foi possível gerar o documento de um envio confirmado.', [
                'form' => $form->ulid,
                'submission' => $submission->ulid,
                'exception' => $exception::class,
                'fields' => $exception instanceof ValidationException ? array_keys($exception->errors()) : [],
            ]);

            return null;
        }

        $submission->forceFill([
            'status' => $form->destination === PublicFormDestination::AutoSend ? SubmissionStatus::Processing : SubmissionStatus::PendingReview,
            'envelope_id' => $envelope->getKey(),
            'organization_id' => $form->organization_id,
            'confirmed_at' => Carbon::now(),
            'payload' => null,
        ])->save();

        PublicFormAudit::record($form, AuditEventType::PublicFormSubmissionConfirmed, [
            'submission' => $submission->ulid,
            'destination' => $form->destination->value,
            'privacy_notice' => $submission->privacy_notice_version,
        ], $envelope);

        return $envelope;
    }

    private function release(PublicFormSubmission $submission): void
    {
        PublicFormSubmission::withoutOrganizationScope()
            ->whereKey($submission->getKey())
            ->where('status', SubmissionStatus::Processing->value)
            ->whereNull('envelope_id')
            ->update(['status' => SubmissionStatus::PendingConfirmation->value, 'updated_at' => Carbon::now()]);
    }
}
