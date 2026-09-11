<?php

namespace App\Services\PublicForms;

use App\Models\PublicForm;
use App\Models\PublicFormSubmission;
use Illuminate\Support\Carbon;

/**
 * Limpeza dos envios não confirmados (docs/fase-2/formulario-publico.md §5).
 *
 * Envio cujo link de confirmação venceu é APAGADO — linha inteira, com o payload cifrado,
 * o IP e o navegador. Nunca gerou envelope nem consumo de cota, então não há nada a
 * preservar. `public_form_submissions` não é tabela de evidência (a trilha do que foi
 * confirmado está em `audit_events`).
 *
 * Roda de forma oportunista: a cada envio (só o formulário em questão) e ao abrir a tela
 * interna (a organização). O agendamento global (`routes/console.php`, fora da área deste
 * agente) é uma pendência registrada — ver o documento §10.
 */
final class SubmissionPurge
{
    public function run(?PublicForm $form = null, ?int $organizationId = null): int
    {
        $query = PublicFormSubmission::withoutOrganizationScope()
            ->where('status', SubmissionStatus::PendingConfirmation->value)
            ->where('confirmation_expires_at', '<', Carbon::now());

        if ($form !== null) {
            $query->where('public_form_id', $form->getKey());
        }

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        $ids = $query->orderBy('id')->limit(PublicFormsConfig::purgeBatch())->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return PublicFormSubmission::withoutOrganizationScope()
            ->whereIn('id', $ids->all())
            ->where('status', SubmissionStatus::PendingConfirmation->value)
            ->delete();
    }
}
