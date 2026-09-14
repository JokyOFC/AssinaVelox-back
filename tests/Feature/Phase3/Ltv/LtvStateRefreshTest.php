<?php

use App\Models\VerificationRecord;
use App\Services\Ltv\LtvState;
use App\Services\Ltv\LtvStatus;
use Illuminate\Support\Carbon;

require_once __DIR__.'/Support/LtvHelpers.php';

/*
|--------------------------------------------------------------------------
| LtvState::apply com o relatório do re-carimbo num arquivo de VÁRIAS assinaturas (I-3A)
|--------------------------------------------------------------------------
| `ltv-refresh` devolve em `effective_level` o menor nível entre as assinaturas. Com assinaturas de
| participantes sem carimbo próprio ele é B-B, mas a camada de arquivamento da operadora continua
| existindo e precisa continuar sendo renovada — o estado não pode cair para `not_applicable`.
| Encontrado no ponta a ponta da Fase 3, parte 1 (tests/Feature/EndToEnd/Phase3PartOneTest.php).
*/

function ltvStateRecord(): VerificationRecord
{
    return VerificationRecord::factory()->create(['signature_profile' => 'PAdES-B-B']);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ltvRefreshReport(array $overrides = []): array
{
    return array_merge([
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
    ], $overrides);
}

it('re-carimbo com cadeia de carimbos válida agenda a próxima renovação, mas o nível do arquivo com assinaturas B-B continua B-B', function () {
    $record = ltvStateRecord();
    $now = Carbon::parse('2026-09-14T12:00:00Z');

    $status = app(LtvState::class)->apply($record, ltvRefreshReport(), $now);

    // Revisão adversarial I-3A (roadmap T2): o nível técnico gravado é o `effective_level` do
    // pdftool (B-B ⇒ not_applicable); a camada de arquivamento da operadora continua agendada.
    expect($status)->toBe(LtvStatus::NotApplicable)
        ->and(LtvState::status($record->refresh()))->toBe(LtvStatus::NotApplicable)
        ->and(LtvState::archiveLayer($record))->toBeTrue()
        ->and($record->getAttribute('ltv_next_refresh_at'))->not->toBeNull()
        ->and($record->getAttribute('ltv_revocation_embedded'))->toBeFalsy()
        ->and($record->signature_profile)->toBe('PAdES-B-B');
});

it('re-carimbo com cadeia de carimbos inválida não finge arquivamento', function () {
    $record = ltvStateRecord();

    $status = app(LtvState::class)->apply($record, ltvRefreshReport(['timestamp_chain_valid' => false]));

    expect($status)->toBe(LtvStatus::NotApplicable)
        ->and($record->refresh()->getAttribute('ltv_next_refresh_at'))->toBeNull();
});

it('relatório de assinatura (sem cadeia de carimbos) continua usando o nível efetivo', function () {
    $record = ltvStateRecord();

    $status = app(LtvState::class)->apply($record, ['effective_level' => 'B-T', 'dss' => ['present' => false]]);

    expect($status)->toBe(LtvStatus::BT);
});
