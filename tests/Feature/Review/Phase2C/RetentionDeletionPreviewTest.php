<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 2 onda C (produto) — ativar a retenção sem saber o que será apagado
|--------------------------------------------------------------------------
| Configurações › Retenção e preservação (resources/js/pages/settings/retention.tsx:409-439): ao
| ativar a exclusão automática ou reduzir um prazo, a confirmação diz apenas "o que já passou do novo
| prazo será apagado de forma definitiva na próxima execução diária" e lista as categorias alteradas.
| Ela não diz QUANTOS documentos saem, nem QUANDO é a próxima execução (routes/console.php agenda
| `retention:apply` às 04:25). As props da tela (App\Services\Retention\RetentionPresenter::settings,
| app/Services/Retention/RetentionPresenter.php:29) não trazem nenhuma prévia: quem digita a frase de
| confirmação não tem como saber se está apagando 0 ou 3.000 documentos concluídos. O detalhe do
| documento calcula `eligible_at` (RetentionPresenter::envelopeRetention), então o dado existe.
*/

use App\Models\Membership;
use App\Services\Retention\RetentionPresenter;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Phase2/Retention/Support/RetentionHelpers.php';

beforeEach(function () {
    Storage::fake('documents');
    config()->set('inertia.ssr.enabled', false);
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner(['name' => 'Imobiliária Aurora']);
    retentionEnable($this->organization);
});

/**
 * Procura, em qualquer nível das props, uma chave de prévia (quantos itens a próxima execução
 * apagaria) com valor numérico positivo.
 *
 * @param  array<mixed>  $props
 */
function reviewRetentionPreviewCount(array $props, bool $inPreview = false): int
{
    $found = 0;

    foreach ($props as $key => $value) {
        $here = $inPreview || (is_string($key) && preg_match('/eligible|impact|preview|would_delete|due_now|to_delete/i', $key) === 1);

        if (is_array($value)) {
            $found = max($found, reviewRetentionPreviewCount($value, $here));
        } elseif ($here && is_int($value)) {
            $found = max($found, $value);
        }
    }

    return $found;
}

it('a tela de retenção mostra quantos documentos a política apagaria antes de pedir a confirmação', function () {
    // Um documento concluído há 2.000 dias; a política (ainda desativada) guarda por 1.825.
    retentionFinishedEnvelope($this->organization, $this->owner, 2000);
    retentionPolicyFor($this->organization, ['completed' => 1825], active: false);

    $membership = Membership::query()
        ->where('organization_id', $this->organization->id)
        ->where('user_id', $this->owner->id)
        ->firstOrFail();

    $props = app(RetentionPresenter::class)->settings($this->organization, $membership);

    expect(reviewRetentionPreviewCount($props))
        ->toBeGreaterThanOrEqual(1, 'Nenhuma prop diz quantos documentos seriam apagados ao ativar a política.');
});
