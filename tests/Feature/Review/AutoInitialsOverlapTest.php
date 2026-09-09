<?php

use App\Enums\FieldType;
use App\Models\SigningField;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Envelopes/WizardHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de design — rubricas automáticas empilhadas no mesmo retângulo
|--------------------------------------------------------------------------
| RECONCILIACAO §4 Q10 fixa UMA posição padrão para a rubrica automática
| (x 0.86, y 0.94, w 0.10, h 0.04) e `FieldSync::autoInitialsRows()` a aplica
| igual para todo destinatário, em toda página.
|
| Com dois signatários o resultado é um par de caixas exatamente sobrepostas em
| cada página: no editor do passo 3 elas nem aparecem (wizard-step-fields.tsx
| filtra `field.auto !== true`), no visualizador do detalhe e na página pública
| uma esconde a outra, e a composição do arquivo final vai carimbar as duas
| imagens no mesmo retângulo — a rubrica de um signatário por cima da do outro.
|
| O contrato não pede sobreposição em lugar nenhum: campos são retângulos
| distintos por destinatário. Este teste exige que duas rubricas automáticas da
| mesma página não ocupem a mesma área.
*/

it('não gera rubricas automáticas sobrepostas quando há mais de um signatário (Q10)', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = draftWithDocument($organization, $owner, pages: 3);
    $maria = addRecipient($envelope, 'Maria A. Souza', 'maria@exemplo.test', 1);
    $carlos = addRecipient($envelope, 'Carlos Mendes', 'carlos@exemplo.test', 2);

    actingAsMember($owner, $organization);

    $this->put(route('envelopes.fields.sync', $envelope), [
        'initials_on_all_pages' => true,
        'fields' => [
            fieldPayload($maria),
            fieldPayload($carlos, ['client_id' => 'tmp-carlos', 'y' => 0.3]),
        ],
    ])->assertRedirect();

    $auto = SigningField::query()
        ->where('envelope_id', $envelope->getKey())
        ->where('type', FieldType::Initials)
        ->orderBy('page')
        ->get();

    expect($auto)->toHaveCount(6); // 3 páginas × 2 signatários

    $collisions = [];

    foreach ($auto->groupBy('page') as $page => $onPage) {
        foreach ($onPage as $i => $a) {
            foreach ($onPage->slice($i + 1) as $b) {
                $overlapX = min((float) $a->x + (float) $a->width, (float) $b->x + (float) $b->width) - max((float) $a->x, (float) $b->x);
                $overlapY = min((float) $a->y + (float) $a->height, (float) $b->y + (float) $b->height) - max((float) $a->y, (float) $b->y);

                if ($overlapX > 0 && $overlapY > 0) {
                    $collisions[] = sprintf(
                        'pág. %s: %s e %s se sobrepõem (%.3f×%.3f) em x=%.3f y=%.3f',
                        $page,
                        $a->recipient->name,
                        $b->recipient->name,
                        $overlapX,
                        $overlapY,
                        (float) $a->x,
                        (float) $a->y,
                    );
                }
            }
        }
    }

    expect($collisions)->toBe([], "Rubricas automáticas empilhadas:\n".implode("\n", $collisions));
});
