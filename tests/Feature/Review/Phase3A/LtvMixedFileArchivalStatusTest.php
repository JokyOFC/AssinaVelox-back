<?php

use App\Models\VerificationRecord;
use App\Services\Ltv\LtvProfilePolicy;
use App\Services\Ltv\LtvState;
use App\Services\Ltv\LtvStatus;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Revisão adversarial I-3A — `LtvState::apply()` promove a B-LTA um arquivo cujo nível é B-B
|--------------------------------------------------------------------------
| A correção da integração (docs/fase-3/parte-1-relatorio.md §3.3) grava `ltv_status = b_lta`
| sempre que o relatório do `ltv-refresh` tem cadeia de carimbos válida, mesmo quando o próprio
| relatório diz `effective_level: B-B` (assinaturas de participante sem carimbo nem revogação).
|
| O estado técnico passa a ser a ÚNICA entrada de `LtvProfilePolicy::displayProfile()`: com
| `pades_ltv_advertise` ligada (o que o checklist permite depois da validação externa das
| fixtures), o arquivo misto é anunciado como "PAdES-B-LTA", embora o `ltv-validate` do mesmo
| arquivo diga B-B (roadmap T2). A visão interna já diz nível "B-LTA" e "revogação embutida".
*/

/**
 * Relatório do `ltv-refresh` de um arquivo com assinatura de participante sem carimbo: a camada
 * da operadora existe, mas o nível efetivo do ARQUIVO é B-B (formato de tools/pdftool/pdftool/ltv.py).
 *
 * @return array<string, mixed>
 */
$mixedRefreshReport = static fn (): array => [
    'ok' => true,
    'announced_profile' => 'PAdES-B-B',
    'level_before' => 'B-B',
    'effective_level' => 'B-B',
    'document_timestamps_before' => 1,
    'document_timestamps_after' => 2,
    'timestamp_chain_valid' => true,
    'last_timestamp_at' => '2026-09-14T12:00:00+00:00',
    'archive_timestamp' => ['gen_time' => '2026-09-14T12:00:00+00:00', 'tsa_cert_not_after' => '2027-09-14T12:00:00+00:00'],
    'dss' => ['present' => true],
];

it('com o anúncio ligado, um arquivo cujo nível efetivo é B-B nunca é exibido como PAdES-B-LTA', function () use ($mixedRefreshReport) {
    config()->set('assinavelox.features.operator_tsa', true);
    config()->set('assinavelox.features.pades_ltv', true);
    config()->set('assinavelox.features.pades_ltv_advertise', true);

    $record = VerificationRecord::factory()->create(['signature_profile' => 'PAdES-B-B']);
    $status = app(LtvState::class)->apply($record, $mixedRefreshReport(), Carbon::parse('2026-09-14T12:00:00Z'));

    // O pdftool disse B-B para o arquivo; o perfil exibido não pode passar disso.
    expect(LtvProfilePolicy::displayProfile('PAdES-B-B', $status))->toBe('PAdES-B-B');
});

it('a visão interna não afirma nível B-LTA nem revogação embutida para o arquivo cujo nível efetivo é B-B', function () use ($mixedRefreshReport) {
    $record = VerificationRecord::factory()->create(['signature_profile' => 'PAdES-B-B']);
    app(LtvState::class)->apply($record, $mixedRefreshReport(), Carbon::parse('2026-09-14T12:00:00Z'));

    $view = LtvState::view($record->refresh());

    expect($view['level'])->not->toBe(LtvStatus::BLta->level())
        ->and($view['revocation_embedded'])->toBeFalse();
});
