<?php

use App\Jobs\Ltv\RefreshArchiveTimestamp;
use App\Jobs\Ltv\ScheduleArchiveTimestampRefreshes;
use App\Models\VerificationRecord;
use App\Services\Ltv\ArchiveTimestampRefresher;
use App\Services\Ltv\Exceptions\LtvException;
use App\Services\Ltv\LtvFeatures;
use App\Services\Ltv\LtvProfilePolicy;
use App\Services\Ltv\LtvSigner;
use App\Services\Ltv\LtvState;
use App\Services\Ltv\LtvStatus;
use App\Services\Ltv\Models\LtvOperation;
use App\Services\Ltv\VerificationHashHistory;
use App\Services\Timestamp\Models\OperatorTsaIssuance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| P3-LTV — flags, perfil exibido e estado técnico (sem pdftool)
|--------------------------------------------------------------------------
| Roadmap T2: nenhum perfil além de PAdES-B-B é exibido sem `pades_ltv_advertise`, que só pode
| ser ligada depois do checklist. T3: nada vira ICP-Brasil. T8: tudo nasce desligado.
*/

function ltvFlags(bool $tsa, bool $ltv, bool $advertise = false): void
{
    config()->set('assinavelox.features.operator_tsa', $tsa);
    config()->set('assinavelox.features.pades_ltv', $ltv);
    config()->set('assinavelox.features.pades_ltv_advertise', $advertise);
}

it('nasce desligada: pades_ltv e pades_ltv_advertise', function () {
    expect(config('assinavelox.features.pades_ltv'))->toBeFalse()
        ->and(config('assinavelox.features.pades_ltv_advertise'))->toBeFalse()
        ->and(LtvFeatures::enabled())->toBeFalse()
        ->and(LtvFeatures::advertise())->toBeFalse();
});

it('pades_ltv exige operator_tsa, e o anúncio exige pades_ltv', function () {
    ltvFlags(tsa: false, ltv: true, advertise: true);
    expect(LtvFeatures::enabled())->toBeFalse()->and(LtvFeatures::advertise())->toBeFalse();

    ltvFlags(tsa: true, ltv: false, advertise: true);
    expect(LtvFeatures::enabled())->toBeFalse()->and(LtvFeatures::advertise())->toBeFalse();

    ltvFlags(tsa: true, ltv: true, advertise: false);
    expect(LtvFeatures::enabled())->toBeTrue()->and(LtvFeatures::advertise())->toBeFalse();
});

it('com o anúncio desligado o perfil exibido continua PAdES-B-B para qualquer estado técnico', function () {
    ltvFlags(tsa: true, ltv: true, advertise: false);

    foreach (LtvStatus::cases() as $status) {
        expect(LtvProfilePolicy::displayProfile('PAdES-B-B', $status))->toBe('PAdES-B-B');
    }

    // Sem assinatura criptográfica não há perfil nenhum.
    expect(LtvProfilePolicy::displayProfile(null, LtvStatus::BLta))->toBeNull()
        ->and(LtvProfilePolicy::declaredProfile())->toBe('PAdES-B-B');
});

it('nem com o anúncio ligado algum perfil exibido fala em ICP-Brasil', function () {
    ltvFlags(tsa: true, ltv: true, advertise: true);

    expect(LtvProfilePolicy::displayProfile('PAdES-B-B', LtvStatus::BLta))->toBe('PAdES-B-LTA')
        ->and(LtvProfilePolicy::displayProfile('PAdES-B-B', LtvStatus::NotApplicable))->toBe('PAdES-B-B');

    foreach (LtvStatus::cases() as $status) {
        expect((string) LtvProfilePolicy::displayProfile('PAdES-B-B', $status))->not->toContain('ICP')
            ->and($status->label())->not->toContain('ICP-Brasil ');
    }

    $items = collect(LtvProfilePolicy::checklist());
    expect($items->pluck('item')->all())->toContain('Validação externa independente')
        ->and($items->firstWhere('item', 'Validação externa independente')['status'])->toBe('pendente')
        ->and($items->firstWhere('item', 'ACT ICP-Brasil, se o anúncio mencionar ICP-Brasil')['status'])->toBe('bloqueado');
});

it('com pades_ltv desligada o assinador de longo prazo recusa e não gasta serial', function () {
    ltvFlags(tsa: true, ltv: false);

    expect(fn () => app(LtvSigner::class)->sign('in.pdf', 'out.pdf', 'cert.pfx', 'QUALQUER_ENV'))
        ->toThrow(LtvException::class);

    expect(OperatorTsaIssuance::query()->count())->toBe(0)
        ->and(LtvOperation::query()->count())->toBe(0);
});

it('registros existentes nascem not_applicable e a visão interna não anuncia nada', function () {
    $record = VerificationRecord::factory()->create(['signature_profile' => 'PAdES-B-B']);
    $record->refresh();

    expect($record->getAttribute('ltv_status'))->toBe('not_applicable')
        ->and(LtvState::status($record))->toBe(LtvStatus::NotApplicable);

    $view = LtvState::view($record);
    expect($view['announced'])->toBeFalse()
        ->and($view['announced_profile'])->toBe('PAdES-B-B')
        ->and($view['tsa_kind'])->toBe('operator')
        ->and($view['notice'])->toContain('não é carimbo ICP-Brasil');

    // Um único resumo: nenhuma chave nova na resposta pública.
    expect(app(VerificationHashHistory::class)->publicProps($record))->toBe([])
        ->and(app(VerificationHashHistory::class)->match($record, $record->final_sha256))->toBeNull();
});

it('o estado técnico nunca mexe no perfil gravado nem no status da assinatura', function () {
    $record = VerificationRecord::factory()->create(['signature_profile' => 'PAdES-B-B']);
    $status = $record->signature_status;

    $applied = app(LtvState::class)->apply($record, [
        'effective_level' => 'B-LTA',
        'last_timestamp_at' => '2026-09-11T12:00:00+00:00',
        'archive_timestamp' => ['tsa_cert_not_after' => now()->addDays(90)->toIso8601String()],
        'revocation_embedded' => true,
    ]);

    $record->refresh();
    expect($applied)->toBe(LtvStatus::BLta)
        ->and($record->signature_profile)->toBe('PAdES-B-B')
        ->and($record->signature_status)->toBe($status)
        ->and($record->getAttribute('ltv_status'))->toBe('b_lta')
        ->and((bool) $record->getAttribute('ltv_revocation_embedded'))->toBeTrue()
        ->and($record->getAttribute('ltv_next_refresh_at'))->not->toBeNull();

    // Próximo re-carimbo = vencimento do certificado da TSA − margem (30 dias).
    $next = Carbon::parse((string) $record->getAttribute('ltv_next_refresh_at'));
    expect((int) round(now()->diffInDays($next)))->toBeGreaterThanOrEqual(59)->toBeLessThanOrEqual(60);
});

it('com pades_ltv desligada o job de re-carimbo não faz nada', function () {
    ltvFlags(tsa: true, ltv: false);
    $record = VerificationRecord::factory()->create();

    $job = new RefreshArchiveTimestamp((int) $record->getKey(), (int) $record->envelope_id, [(int) $record->final_document_version_id]);
    app()->call([$job, 'handle']);

    expect(app(ArchiveTimestampRefresher::class)->refresh((int) $record->getKey()))->toBe(ArchiveTimestampRefresher::DISABLED)
        ->and(LtvOperation::query()->count())->toBe(0)
        ->and(OperatorTsaIssuance::query()->count())->toBe(0)
        ->and($record->refresh()->getAttribute('ltv_status'))->toBe('not_applicable');
});

it('o agendador só despacha registros B-LTA vencidos, e só com a flag ligada', function () {
    Queue::fake();

    $due = VerificationRecord::factory()->create();
    $due->forceFill(['ltv_status' => 'b_lta', 'ltv_next_refresh_at' => now()->subDay()->format('Y-m-d H:i:s')])->save();
    $later = VerificationRecord::factory()->create();
    $later->forceFill(['ltv_status' => 'b_lta', 'ltv_next_refresh_at' => now()->addDays(10)->format('Y-m-d H:i:s')])->save();
    $notArchival = VerificationRecord::factory()->create();
    $notArchival->forceFill(['ltv_status' => 'b_lt', 'ltv_next_refresh_at' => now()->subDay()->format('Y-m-d H:i:s')])->save();

    ltvFlags(tsa: true, ltv: false);
    expect(app()->call([new ScheduleArchiveTimestampRefreshes, 'handle']))->toBe(0);
    Queue::assertNothingPushed();

    ltvFlags(tsa: true, ltv: true);
    expect(app()->call([new ScheduleArchiveTimestampRefreshes, 'handle']))->toBe(1);

    Queue::assertPushed(RefreshArchiveTimestamp::class, 1);
    Queue::assertPushed(RefreshArchiveTimestamp::class, fn (RefreshArchiveTimestamp $job): bool => $job->verificationRecordId === (int) $due->getKey()
        && $job->sourceVersionIds === array_values(array_filter([(int) $due->final_document_version_id])));
});
