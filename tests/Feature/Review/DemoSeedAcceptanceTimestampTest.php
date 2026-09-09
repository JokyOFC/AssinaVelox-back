<?php

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\SignatureAcceptance;
use Database\Seeders\DemoOrganizationSeeder;
use Database\Seeders\PlanSeeder;

/*
|--------------------------------------------------------------------------
| Revisão de design — o mesmo aceite com dois horários na mesma página
|--------------------------------------------------------------------------
| `DemoOrganizationSeeder::sign()` monta o valor do campo `date` com
| `$signedAt->setTimezone($envelope->organization->timezone)`. Carbon é mutável:
| a chamada muda o próprio `$signedAt` para America/Sao_Paulo, e tudo o que vem
| DEPOIS na função (o `created_at` dos valores de campo, `consumed_at` da sessão,
| `last_used_at` do link e o evento `acceptance.recorded`) é gravado como se a
| hora local fosse UTC — três horas a menos.
|
| Isso não fica no banco: aparece lado a lado nas telas do incremento 4. Na
| página de evidências do AV-00006 o cartão do participante diz "Aceite
| registrado 29/07/2026 07:24" e a trilha de auditoria, na mesma rolagem, diz
| "Aceite registrado · Carla Mendes Furtado 29/07/2026 04:24"; no PDF de
| evidências a linha do tempo herda o mesmo desencontro e chega a listar o
| aceite ANTES da abertura do convite e da confirmação de identidade do mesmo
| participante.
|
| Um dossiê que se apresenta como evidência não pode carimbar o mesmo fato com
| dois horários.
*/

it('carimba o aceite e o evento de auditoria do aceite no mesmo instante', function () {
    $this->seed(PlanSeeder::class);
    $this->seed(DemoOrganizationSeeder::class);

    $acceptances = SignatureAcceptance::withoutOrganizationScope()->get();

    expect($acceptances)->not->toBeEmpty();

    $mismatched = [];

    foreach ($acceptances as $acceptance) {
        $event = AuditEvent::withoutOrganizationScope()
            ->where('envelope_id', $acceptance->envelope_id)
            ->where('recipient_id', $acceptance->recipient_id)
            ->where('event_type', AuditEventType::AcceptanceRecorded->value)
            ->first();

        if ($event === null) {
            continue;
        }

        $delta = abs($event->occurred_at->getTimestamp() - $acceptance->accepted_at->getTimestamp());

        if ($delta > 60) {
            $mismatched[] = sprintf(
                'envelope %d / recipient %d: aceite %s × trilha %s (%d s)',
                $acceptance->envelope_id,
                $acceptance->recipient_id,
                $acceptance->accepted_at->toIso8601String(),
                $event->occurred_at->toIso8601String(),
                $delta,
            );
        }
    }

    expect($mismatched)->toBe([], implode(' | ', $mismatched));
});
